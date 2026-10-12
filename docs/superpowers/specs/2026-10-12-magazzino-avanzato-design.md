# G3 — Magazzino avanzato

**Stato:** decisioni G3.1–G3.22 approvate il 2026-10-12 (la G3.15 con la strada
A). Design scritto lo stesso giorno, da rivedere sezione per sezione.

Riferimenti: [architettura](2026-09-11-gestionale-ecommerce-architettura-design.md)
§3.4, §4.3, §10.3 (D27, D29, D39, D60, D61, D63);
[G2b-bis](2026-09-23-giacenze-movimenti-documenti-design.md) §4 (documenti di
magazzino).

## Contesto

G3 aggiunge al magazzino tre funzionalità sbloccabili: **Più sedi**
(`multi_location`) con i trasferimenti, **Acquisti** (`purchasing`) con
fornitore e costi sui carichi, giacenza per fornitore e valore del magazzino, e
**Lotti e scadenze** (`batch_tracking`). Più lo **scarico automatico**: prima il
lotto che scade prima, poi il fornitore che costa meno.

Con D63 si lavora in parallelo alla consegna del 29 ottobre: quello che si unisce
su `main` prima della `1.0.0` resta **inerte a interruttori spenti**, e nel flusso
di vendita G3 tocca un punto solo, `Allocation` con `Stock::apply()` (D61).

### Cosa c'è già nel codice

- **I «secchi» della giacenza.** `gst_stock` ha una riga per
  `(product_id, location_id, batch_id, supplier_id)`; `gst_stock_movements` porta
  le stesse quattro chiavi più `unit_cost`. Oggi lotto e fornitore sono sempre 0.
- **Una porta sola.** `Stock::apply()` scrive giacenza e movimento insieme, con
  `FOR UPDATE` sul secchio. Riceve già `batch_id` e `supplier_id`.
- **Un punto solo per la vendita.** `Allocation` (`reserve`, `release`,
  `commit`, `restore`, `returnGoods`, `expire`) prende già sede, lotto e
  fornitore e li passa a `Stock::apply()`. Le prenotazioni
  (`gst_stock_reservations`) sono per prodotto e sede, senza lotto né fornitore.
- **Documenti di magazzino** (G2b-bis piani 4-6): Carico, Scarico, Inventario,
  con `StockDocuments` come servizio e `StockDocumentRules` pura. Nei dati ci
  sono già, vuoti e nascosti: `to_location_id`, `supplier_id`,
  `supplier_document_number`, `supplier_document_date`, `total_cost` sul
  documento; `batch_id`, `supplier_id`, `unit_cost`, `line_total` sulle righe.
  `transfer` è già nell'enum dei tipi; **`in_transit` non è negli stati**
  (`StockDocument::STATUSES` = `draft`, `completed`, `cancelled`).
- **Movimenti:** i tipi `transfer_in` e `transfer_out` ci sono già; nessun
  movimento sa da quale **riga** d'ordine o di documento viene (solo
  `reference_type` e `reference_id`).
- **Sedi:** la pagina *Sedi* del core, estesa dal modulo con *Giacenza*,
  *Punto di ritiro*, *Banco*, *Attiva* (`LocationResource`). `Locations::shown()`
  dà solo la principale con `multi_location` spenta; con la funzionalità accesa
  le sedi con giacenza più ogni sede che ha ancora pezzi. Le colonne per sede
  nelle *Giacenze*, la *Sede* nei Movimenti e nei documenti, `Levels::byLocation()`
  ci sono già.
- **Scorta minima per sede:** `gst_stock_thresholds` e `Alerts::refresh()` sono
  già per sede (G2c, tredicesimo giro).
- **Fornitori dell'opzione:** `gst_product_suppliers` (`product_id`,
  `supplier_id`, `supplier_sku`, `cost`, `position`), già dietro `purchasing`
  nella scheda dell'articolo (P107–P115).
- **Funzionalità:** le tre chiavi sono in `config/features.php`;
  `batch_tracking` ha `created => false` (nel pannello risulta «non ancora
  costruita»): G3 la accende.
- **Sede dell'ordine:** `gst_orders.location_id` è il punto di ritiro scelto, o
  0 per la spedizione (vale la principale). `Checkout` e `Lifecycle` la passano
  ad `Allocation`.
- **Resi:** `Returns` chiama `Allocation::returnGoods()` per ogni pezzo che
  rientra; la riga d'ordine ce l'ha (`$good['order_item_id']`) ma oggi non la
  passa.
- **Disponibilità per la vendita:** il carrello (`Cart`) legge
  `Levels::of()`, cioè il disponibile **di tutte le sedi**. La vetrina
  dell'ecommerce non passa da `Levels`: somma le righe di `gst_stock` del
  prodotto (`ProductCatalog.php:144`, giacenza e non disponibile). A
  interruttori spenti i due numeri parlano della sola principale; con più sedi o
  lotti scaduti non più (§2.2).

### Un difetto di oggi, corretto prima della consegna

Con `multi_location` **spenta**, un ordine con ritiro in una sede che non è la
principale prenota e scarica **da quella sede** (`Allocation::context()` usa
`location_id` della riga, cioè il punto di ritiro). Ma con la funzionalità
spenta l'unica sede del magazzino è la principale (architettura §4.3): la
prenotazione legge una giacenza vuota e il checkout risponde «non disponibile»,
oppure, con la vendita senza giacenza, la sede di ritiro va sotto zero.

Nessuna spec l'aveva deciso: è nato nei test del piano D5 del checkout
(`giacenzaIn()` nella sede di ritiro), mentre G7 e la spec del checkout ne
tacciono. Vale l'architettura. Corretto (G3.1) sul ramo `fix-sede-ritiro`,
indipendente da G3: `Locations::forSale()` decide la sede della vendita e
`Allocation::context()` la usa; con `multi_location` spenta è sempre la
principale. I test d'integrazione del ritiro sono allineati.

### Già deciso nell'architettura (§4.3), non si riapre

- Chiave del secchio `(prodotto, sede, lotto, fornitore)`; disponibilità per
  fornitore, quella del prodotto è la somma.
- Movimenti `transfer_out` alla partenza e `transfer_in` all'arrivo; stato
  `in_transit` per i trasferimenti; storno all'annullamento con
  `stock_document_cancel`.
- **Scarico automatico:** prima il lotto che scade prima (con
  `batch_tracking`), poi il fornitore con il costo più basso. Negli ordini da
  ufficio (G9) e nei documenti di magazzino lotto e fornitore si possono
  scegliere a mano.
- **Valore del magazzino** = quantità × costo del fornitore (non lo storico
  `unit_cost` dei movimenti). Il carico aggiorna il costo del fornitore; lo
  storico resta nei movimenti.
- `gst_batches`: codice `bat_`, `product_id`, `number`, `expiry_date`,
  `supplier_id`, `note`. Il lotto nasce al carico. I lotti scaduti non sono
  disponibili online né per lo scarico automatico. Dai movimenti si risale ai
  clienti che hanno ricevuto un lotto (richiami). Riquadro dei lotti in scadenza
  entro N giorni (impostazione del commerciante, predefinita 30).
- Il trasferimento richiede `multi_location`; con `purchasing` il carico riceve
  fornitore, documento del fornitore e costi.
- *Scartati:* costo medio ponderato; strati di carico FIFO.

## Decisioni (approvate il 2026-10-12)

Citate nel resto della spec come G3.1–G3.22.

| # | Tema | Scelta | Alternative scartate |
|---|---|---|---|
| 1 | Sede della vendita con `multi_location` spenta | **sempre la principale** in `Allocation`, qualunque sia il punto di ritiro; correzione separata, prima della consegna | lasciare com'è (il ritiro in una seconda sede non funziona) |
| 2 | Sede della vendita con `multi_location` accesa | **spedizione: la principale; ritiro: la sede di ritiro se ha *Giacenza* accesa nella pagina *Sedi* (`has_stock`), altrimenti la principale** — conta l'interruttore della sede, non i pezzi che ha in quel momento. Nessun trasferimento automatico | un'impostazione «sede delle spedizioni online»; la sede con più merce; prenotare un po' da una e un po' dall'altra |
| 3 | Come si divide uno scarico fra i secchi | **una classe pura, `StockPicking::split()`**: riceve i secchi di prodotto e sede (con scadenza del lotto e costo del fornitore), la quantità e le funzionalità accese; risponde con la lista `(lotto, fornitore, pezzi)`. A interruttori spenti risponde sempre `[(0, 0, pezzi)]`: è il test che prova che il negozio non cambia. La usano vendita, scarico, rettifica in meno, trasferimento e inventario | la scelta dentro `Stock::apply()` (che resterebbe una porta, ma con dentro le regole); una query SQL ordinata |
| 4 | Prenotazioni | **restano per prodotto e sede**, senza lotto né fornitore: la ripartizione si fa alla conferma, con le scadenze di quel giorno | prenotare già il lotto (un lotto che scade fra prenotazione e conferma resterebbe bloccato) |
| 5 | Annullo e reso di una vendita divisa su più secchi | **colonna `reference_item_id` su `gst_stock_movements`** (la riga d'ordine o del documento). L'annullo rimette in ogni secchio quello che la riga ne aveva tolto; il reso parziale rientra nei secchi in ordine inverso a quello di uscita. Fuori da `Allocation` cambia una chiave sola: `Returns` passa `order_item_id` a `returnGoods()` (oggi non lo passa) | tabella `gst_stock_allocations` con la ripartizione; rientro sempre nel secchio 0 (si perderebbe il lotto, e il richiamo) |
| 6 | Il secchio 0 dopo l'accensione | la merce che c'era prima (lotto 0, fornitore 0) **resta dov'è, nessuna migrazione**. Nello scarico automatico esce **per prima** (è la più vecchia). Nel valore conta al **costo più basso fra i fornitori dell'opzione**; senza nessun costo conta zero e il riquadro lo dice («N pezzi senza costo») | chiedere al commerciante di assegnarla a mano all'accensione; uscire per ultima |
| 7 | Fornitore sul carico con `purchasing` accesa | **sulla testata, facoltativo**: vale per tutte le righe (le righe lo copiano); senza fornitore la merce entra nel secchio 0. Con il fornitore: numero e data del suo documento, costo per riga | fornitore per riga; fornitore obbligatorio |
| 8 | Costo del fornitore alla conferma del carico | **aggiorna `gst_product_suppliers.cost`**, e **crea il collegamento** opzione-fornitore se manca | aggiornare solo se c'è già; non toccare mai la scheda |
| 9 | Quali articoli hanno i lotti | **un interruttore sull'articolo, «Gestisci lotti e scadenze»** (con `batch_tracking` accesa), spento di serie. Solo i suoi carichi chiedono lotto e scadenza (la scadenza resta facoltativa: ci sono lotti senza) | tutti gli articoli con la funzionalità accesa |
| 10 | Numero del lotto | **unico per opzione**: lo stesso numero in un altro carico è lo stesso lotto, e la scadenza deve coincidere (altrimenti il carico non si conferma) | unico per opzione e fornitore; un lotto nuovo a ogni carico |
| 11 | Lotti scaduti | **fuori dal disponibile** in vetrina, nel checkout e nelle *Giacenze* (colonna *Scaduti* con la funzionalità accesa); restano in giacenza finché uno scarico con causale *Scaduto* non li toglie. Scaduto = data di scadenza **prima di oggi**: il giorno della scadenza si vende ancora. Uscendo dal disponibile, un lotto che scade può aprire un **avviso di scorta minima** (`Alerts::refresh()` legge il disponibile): è voluto | toglierli da soli con un'attività notturna; contarli disponibili fino allo scarico |
| 12 | Lotto e fornitore nello scarico | **scelta a mano facoltativa sulla riga** (con le funzionalità accese); vuota = scarico automatico. Serve per buttare un lotto scaduto, che lo scarico automatico non prende mai | solo automatico |
| 13 | Rettifica rapida con lotti e fornitori | in meno: **scarico automatico**; in più: **secchio 0** (lotto e fornitore sconosciuti) | chiedere lotto e fornitore nella finestra della rettifica |
| 14 | Inventario con lotti e fornitori | **resta per opzione e sede** (piano 6 com'è). Differenza in meno: scarico automatico; in più: secchio 0. La conta per lotto è il «conteggio guidato», funzionalità futura | una riga per lotto nell'inventario (riapre l'editor del piano 6) |
| 15 | Vendita senza giacenza con lotti e fornitori (D60: «dettaglio in G3») | il pezzo venduto scoperto va sotto zero nel **secchio 0**; il carico dopo lo **compensa** (strada A, sotto la tabella) | B: lasciare il secchio 0 sotto zero e segnalarlo in *Da controllare*; riscrivere il movimento di vendita (vietato) |
| 16 | Trasferimento | **quarto documento, *Trasferimenti***, in due passi: **Invia** (`transfer_out` dalla sede di partenza, stato `in_transit`) e **Ricevi** (`transfer_in` nella sede d'arrivo, stato `completed`). Partenza e arrivo devono essere sedi con *Giacenza* accesa, diverse fra loro. Lotto e fornitore passano uguali. Arrivo sempre per intero. Annullabile da *in viaggio* (storno della partenza) e da *completato* (storno di tutti e due, se la merce è ancora lì). Pezzi *In viaggio* come colonna delle *Giacenze* | un passo solo (partenza e arrivo insieme); arrivi parziali |
| 17 | Pezzi prenotati e trasferimento | stessa regola dello scarico di oggi: **controlla la giacenza, non le prenotazioni** | rifiutare se tocca merce prenotata |
| 18 | Spegnere una funzionalità con dei dati | il pannello **rifiuta** di spegnere `multi_location` con pezzi fuori dalla principale o trasferimenti in viaggio; `purchasing` e `batch_tracking` si spengono sempre (i dati restano, nascosti, e tornano alla riaccensione) | spegnere sempre; spostare la merce da sola nella principale |
| 19 | Valore del magazzino | **riquadro della home** e **colonna *Valore*** nelle *Giacenze*, con `purchasing` accesa; giacenze sotto zero escluse | solo il riquadro; una pagina a parte |
| 20 | Pagina dei lotti | ***Magazzino → Lotti***: elenco con opzione, numero, scadenza, pezzi per sede, filtro *In scadenza* e *Scaduti*; scheda del lotto con giacenze, movimenti e **clienti che l'hanno ricevuto** (per il richiamo) | solo il riquadro in home e il filtro nei Movimenti |
| 21 | Costi | **IVA esclusa**, sia il costo del fornitore sia il costo del carico (D19 parla solo dei prezzi di vendita) | IVA inclusa come i prezzi del negozio |
| 22 | Costo sui movimenti che escono e rientrano | **`unit_cost` = il costo del fornitore del secchio in quel momento** su `sale`, `return`, `sale_cancel`, `transfer_*`, scarichi e inventari: dà il costo del venduto per le statistiche | lasciarlo a zero e rimandare il costo del venduto |

### La decisione 15, in dettaglio

Un pezzo venduto scoperto esce dal secchio 0 prima che il lotto esista: chi l'ha
comprato non è legato a nessun lotto, e **resta non rintracciabile in tutte e due
le strade**.

- **A. Compensazione al carico.** Alla conferma del carico dopo, se il secchio 0
  di quell'opzione e sede è sotto zero, due movimenti con una causale interna
  (*Assegnazione lotto*): il lotto appena caricato cede i pezzi al secchio 0, che
  torna a zero. Il lotto mostra i pezzi che ha davvero e il valore torna giusto;
  ma il richiamo, seguendo i movimenti del lotto, trova «N pezzi al secchio 0»,
  non dei clienti.
- **B. Lasciare com'è.** Il secchio 0 resta sotto zero e il lotto pieno; le somme
  per opzione e sede tornano, ma il lotto dichiara più pezzi di quelli che ha
  finché un inventario non lo corregge. Il riquadro *Da controllare* segnala le
  coppie «secchio 0 sotto zero + lotto sopra zero».

Scelta: **A**, perché lotto e valore restano veri senza lavoro a mano. Vale
anche senza lotti, per il fornitore: il carico da un fornitore compensa il
secchio 0 sotto zero allo stesso modo (§3.3).


## Design

Regola che vale per tutte le sezioni: **a interruttori spenti niente cambia**.
Ogni campo, colonna, pagina e riquadro nuovo compare solo con la sua
funzionalità; ogni regola nuova, applicata ai soli secchi 0 di una sola sede,
dà il risultato di oggi. È la prova chiesta da D63, e ha i suoi test (§6.3).

### 1. Dati e ripartizione

#### 1.1 Tabelle e colonne

| Dove | Cosa | Perché |
|---|---|---|
| `gst_batches` (nuova, `Batch`) | `code` (`bat_`), `product_id` (FK), `number` (50), `expiry_date` (DATE, vuota = senza scadenza), `supplier_id` (0 = sconosciuto), `note`; indice unico `(product_id, number)`, indice su `expiry_date` | il lotto (G3.10); dati del commerciante, non sincronizzati |
| `gst_stock_movements` | `reference_item_id` INT, predefinito 0; indice `(reference_type, reference_id, reference_item_id)` | la riga d'ordine o di documento che ha mosso la merce (G3.5) |
| `gst_stock_document_items` | `batch_number` (50) e `batch_expiry` (DATE) | lotto e scadenza scritti nella bozza del carico, prima che il lotto esista; `batch_id` si riempie alla conferma |
| `gst_product_models` | `track_batches` (`true`/`false`, predefinito `false`) | «Gestisci lotti e scadenze» sull'articolo (G3.9) |
| `gst_settings` | `batch_expiry_days` INT, predefinito 30, fuori dal sync | i giorni del riquadro dei lotti in scadenza |
| `StockDocument::STATUSES` | più `in_transit` | il trasferimento in viaggio (G3.16) |
| `Reasons` | causale interna `batch_assignment` («Assegnazione lotto») | i movimenti di compensazione (G3.15): ha l'etichetta nei Movimenti e nel filtro, non compare fra le scelte di rettifica e scarico |
| `config/features.php` | `batch_tracking` senza `created => false` | la funzionalità è costruita |
| `Codes` | prefisso `bat_` | come le altre entità (D25) |

Chi aggiorna lancia `php forge update`. Nessuna migrazione dei dati: le righe
che ci sono hanno lotto, fornitore e riga di riferimento a 0, che è il loro
significato giusto (G3.6).

#### 1.2 Il secchio e il secchio 0

Un **secchio** è una riga di `gst_stock`: prodotto, sede, lotto, fornitore.
Lotto 0 vuol dire «lotto sconosciuto», fornitore 0 «fornitore sconosciuto».
Il **secchio 0** di un prodotto in una sede è quello con lotto e fornitore a
0: tiene la merce di prima dell'accensione, quella entrata con la rettifica o
con un inventario in più (G3.13, G3.14) e i buchi della vendita scoperta
(G3.15).

#### 1.3 `StockPicking`, la ripartizione (pura)

```php
StockPicking::split(array $buckets, float $quantity, array $options): array
// $buckets: list<array{batch_id: int, supplier_id: int, quantity: float, expiry_date: ?string, cost: ?float}>
// $options: today (Y-m-d), use_batches, use_suppliers, batch_id, supplier_id (scelta a mano), include_expired
// ritorna: array{parts: list<array{batch_id: int, supplier_id: int, quantity: float}>, missing: float}
```

- **Ordine di uscita:** prima i secchi con lotto 0 (la merce più vecchia,
  G3.6); poi i lotti per scadenza crescente, quelli senza scadenza in fondo;
  dentro lo stesso lotto prima il fornitore 0, poi il costo crescente, i costi
  sconosciuti in fondo; a parità, `batch_id` e `supplier_id` crescenti, così il
  risultato è sempre lo stesso.
- Con `use_batches` spento l'ordine ignora le scadenze, con `use_suppliers`
  spento i costi: i secchi già scritti si consumano comunque (una funzionalità
  spenta nasconde i dati, non li cancella, G3.18), nell'ordine degli id.
- Si prendono solo i secchi sopra zero. I lotti scaduti (scadenza prima di
  `today`) restano fuori, salvo `include_expired` o un lotto scelto a mano.
- `batch_id`/`supplier_id` scelti a mano restringono ai soli secchi che li
  hanno (G3.12).
- Quello che non basta torna in `missing`: decide chi chiama (la vendita lo
  manda sotto zero nel secchio 0, §2.4; uno scarico si rifiuta con
  `stock.insufficient`).
- **La prova dell'inerzia:** con i soli secchi 0 il risultato è sempre
  `parts = [(0, 0, quantità)]`, con `missing` = quello che manca.

`StockBuckets` (non pura) legge i secchi di un prodotto in una sede con il
lock (`FOR UPDATE`, come oggi `Allocation::lockedQuantity()`), con la scadenza
del lotto e il costo del secchio.

#### 1.4 Il costo di un secchio

`StockCosts::of()` (pura sulle righe lette): fornitore noto → il suo `cost` in
`gst_product_suppliers` per quell'opzione; fornitore 0 → il costo più basso
fra i fornitori dell'opzione (G3.6); nessun costo → `null`. Tutti IVA esclusa
(G3.21). Lo usano lo scarico automatico (ordine per costo), il valore del
magazzino (§3.4) e `unit_cost` dei movimenti (G3.22: `null` diventa 0).

### 2. Vendita

Nel flusso di vendita si toccano `Allocation` e, per la disponibilità, `Levels`
più chi la legge (§2.2). Ordini, checkout ed ecommerce non cambiano firma.

#### 2.1 La sede

`Locations::forSale()` (già sul ramo `fix-sede-ritiro`, G3.1) diventa, con
`multi_location` accesa: la sede chiesta se è attiva e ha *Giacenza* accesa,
altrimenti la principale (G3.2). La spedizione chiede 0, quindi la principale.
La regola pura `Locations::serving()` prende anche «la sede chiesta tiene
merce».

#### 2.2 Il disponibile per la vendita

`Levels::forSale(array $productIds, int $locationId)`: giacenza, impegnati e
disponibile della **sede della vendita**, senza i lotti scaduti (G3.11) quando
`batch_tracking` è accesa. Lo leggono:

- `Cart` al posto di `Levels::of()` (aggiunta e controllo dei pezzi), con la
  sede di `Locations::forSale()` per il carrello;
- `Allocation::reserve()`, che oggi somma i secchi letti con il lock: toglie
  quelli scaduti;
- la vetrina dell'ecommerce (`ProductCatalog`), che smette di sommare
  `gst_stock` da sé e chiede `Levels::forSale(...)['quantity']` della
  principale: è l'unico ritocco nell'ecommerce, una riga, con la stessa
  regola di oggi (giacenza sopra zero).

A interruttori spenti la sede è la principale, l'unica con merce, e nessun
lotto scade: i numeri sono quelli di oggi. Le *Giacenze* del backend
continuano a mostrare tutte le sedi (`Levels::of()` resta per il totale).

#### 2.3 Prenotare

Come oggi, per prodotto e sede (G3.4), contro il disponibile di §2.2.

#### 2.4 Scaricare

`Allocation::commit()`:

1. lock dei secchi (`StockBuckets`), prenotazione della riga come oggi;
2. `StockPicking::split()` sulla quantità della riga, con le funzionalità
   accese;
3. un `Stock::apply()` per ogni parte: tipo `sale`, `reference_item_id` = la
   riga d'ordine, `unit_cost` = il costo del secchio (G3.22);
4. `missing` maggiore di zero (prenotazione persa nel frattempo, lotto scaduto
   fra prenotazione e conferma, vendita scoperta): un ultimo `Stock::apply()`
   sul secchio 0 con `allow_negative` alle stesse condizioni di oggi
   (prenotazione, backorder, pagato), e l'avviso al commerciante come oggi.

Risposta come oggi (`before`/`after` sono della sede intera), più `parts`.

#### 2.5 Annullare e rendere

`restore()` e `returnGoods()` ricevono `order_item_id` (G3.5; `Lifecycle` lo
passa già, `Returns` lo passa da G3). `StockMovements::netOut($orderId,
$itemId)` (nuova, in lettura) somma per secchio i `sale` della riga meno i
`sale_cancel` e `return` già fatti con lo stesso `reference_item_id`; il rientro
riempie i secchi **in ordine inverso a quello di uscita** fino alla quantità,
con un `Stock::apply()` per secchio. I movimenti di prima di G3 (senza
`reference_item_id`) non trovano secchi: si rientra nel secchio 0, che era
l'unico.

Il reso porta `reference_type = sales_return`, `reference_id` = il reso e
`reference_item_id` = la riga d'ordine: è la riga d'ordine che serve per
ritrovare i secchi.

#### 2.6 Rilascio e scadenza

`release()` ed `expire()` non cambiano: le prenotazioni non hanno secchi.

### 3. Acquisti (`purchasing`)

#### 3.1 Il carico

- Testata: **Fornitore** (select dei fornitori, facoltativo), **Documento del
  fornitore** (numero) e **Data del documento**, oltre a Sede e Nota (G3.7).
- Righe: **Costo** (IVA esclusa, G3.21) accanto ai pezzi, già riempito dal
  costo dell'opzione per quel fornitore quando si sceglie la versione o il
  fornitore; **Totale** della riga (pezzi × costo) e del documento in fondo.
  Un costo vuoto conta zero.
- Senza fornitore la merce entra nel secchio 0 con il costo scritto.

#### 3.2 La conferma

Per ogni riga un `purchase` nel secchio `(lotto, fornitore della testata)`, con
`unit_cost` e `reference_item_id`. Poi, con un fornitore e un costo maggiore di
zero, `gst_product_suppliers` di quell'opzione e fornitore prende il nuovo
costo; se il collegamento non c'è si crea, in fondo alla lista (G3.8).
`total_cost` del documento = somma delle righe.

#### 3.3 La compensazione della vendita scoperta (G3.15)

Dopo il `purchase` di una riga in un secchio diverso dal secchio 0, se il
secchio 0 di quell'opzione e sede è sotto zero di *n* pezzi: due movimenti
`adjustment` con causale `batch_assignment` e lo stesso riferimento della riga,
`-min(n, pezzi caricati)` nel secchio appena caricato e lo stesso in più nel
secchio 0. La giacenza della sede non cambia; il lotto (o il fornitore) risulta
uscito. L'annullamento del carico storna anche questi due, come tutti i
movimenti del documento. Vale per i carichi da `StockDocuments::confirm()`, non
per la rettifica (che entra nel secchio 0).

#### 3.4 Valore del magazzino (G3.19)

- `StockValue::of()` (pura): somma di pezzi × costo del secchio sui secchi sopra
  zero; i pezzi senza costo si contano a parte.
- Riquadro della home **«Valore del magazzino»**: totale, per sede con più sedi,
  e «N pezzi senza costo» con il link all'elenco *Giacenze* filtrato.
- Colonna **Valore** nelle *Giacenze*, ordinabile: una sottoquery in
  `LevelsSql::value()`, confrontata con `StockValue` da un test come le altre.
- La merce in viaggio (§5.3) non entra nel valore di nessuna sede.

#### 3.5 Dove si vede il fornitore

- *Carichi*: colonna e filtro **Fornitore**; la scheda mostra fornitore,
  documento, costi e totale.
- *Movimenti*: colonna e filtro **Fornitore**, costo nella scheda del
  movimento.
- Scheda della versione, riquadro *Magazzino*: la giacenza **per fornitore**
  sotto quella per sede.
- Scarico (G3.12): sulla riga, select facoltativa del fornitore fra quelli che
  hanno pezzi in quella sede.

### 4. Lotti e scadenze (`batch_tracking`)

#### 4.1 L'articolo

Nella scheda dell'articolo, con la funzionalità accesa, l'interruttore
**«Gestisci lotti e scadenze»** (`track_batches`, G3.9). Si spegne solo se
nessuna sua versione ha pezzi in un lotto: altrimenti la scheda lo dice e
rifiuta. Il multiprodotto non ce l'ha (non ha giacenza sua).

#### 4.2 Il carico

Sulle righe delle versioni con i lotti, due caselle in più: **Lotto**
(obbligatorio alla conferma, non nella bozza) e **Scadenza** (facoltativa).
Il lettore di codici aggiunge la riga senza lotto e porta il cursore sul lotto.
Alla conferma, per ogni riga:

- il lotto `(product_id, number)` si trova o si crea (codice `bat_`,
  `supplier_id` della testata, scadenza della riga);
- se esiste con un'altra scadenza la conferma si ferma con `batch.expiry_mismatch`
  e il numero della riga (G3.10); una scadenza vuota non contraddice niente,
  e una scadenza scritta riempie quella vuota del lotto;
- `batch_id` della riga si riempie, e il `purchase` va nel secchio del lotto.

Nel carico una versione con i lotti può stare su **più righe, con lotti
diversi**: un fornitore consegna spesso due lotti dello stesso prodotto. La
regola di G2b-bis «una versione una volta sola» diventa, solo lì, «una volta
per lotto» (`stock_document.duplicate_batch`); il lettore aggiunge un pezzo
all'ultima riga di quella versione, e *Aggiungi riga* sotto una riga ne apre
un'altra della stessa versione per il lotto successivo. Per le versioni senza
lotti e negli altri documenti la regola non cambia.

#### 4.3 Lo scarico, la rettifica, l'inventario

- **Scarico:** sulla riga, select facoltativa **Lotto** con i lotti che hanno
  pezzi in quella sede, scaduti compresi («L240312 · scade 12/03/2027 · 5 pz»,
  i scaduti in rosso). Vuota: scarico automatico. Con il lotto scelto si
  scarica solo da lì, e pezzi insufficienti nel lotto fermano la conferma
  (G3.12). La causale *Scaduto* con un lotto non scaduto è permessa.
- **Rettifica rapida:** in meno scarico automatico, in più secchio 0 (G3.13).
- **Inventario:** come il piano 6, per versione e sede. Gli attesi sono la
  giacenza della sede (lotti scaduti compresi: la merce c'è). Differenza in
  meno: scarico automatico **con** i lotti scaduti (la merce che manca è
  quella che manca); in più: secchio 0 (G3.14).

#### 4.4 Scaduti

Scaduto = `expiry_date` prima di oggi (data del sito). Fuori dal disponibile
in `Levels::forSale()`, in `Levels` per le *Giacenze* e in `LevelsSql`
(G3.11), e quindi dagli avvisi di scorta minima, che leggono il disponibile.
Nelle *Giacenze* la colonna **Scaduti** (pezzi nei lotti scaduti) compare con
la funzionalità accesa.

#### 4.5 La pagina *Lotti* (G3.20)

*Magazzino → Lotti*, fra *Inventari* e *Movimenti*, solo con la funzionalità.

- **Elenco** (Resource su `Batch`): Versione (nome e SKU) · Lotto · Scadenza
  (rossa se scaduto, gialla entro `batch_expiry_days`) · Fornitore (con
  `purchasing`) · Pezzi (una colonna per sede con più sedi) · ⋯. Filtri
  *Stato* (*Con pezzi*, *In scadenza*, *Scaduti*, *Esauriti*, *Tutti*;
  predefinito *Con pezzi*) e *Sede*; ricerca su numero del lotto, nome e SKU.
- **Scheda del lotto** in sola lettura: dati del lotto (scadenza e nota si
  correggono da lì, il numero no), pezzi per sede, **Movimenti** del lotto
  (lo schema dei *Movimenti* filtrato) e **Clienti che l'hanno ricevuto**:
  dai `sale` del lotto agli ordini, con cliente, email, telefono, numero e
  data dell'ordine e pezzi al netto di resi e annulli. Pulsante **Scarica i
  clienti** (CSV) per il richiamo.
- Un lotto con movimenti non si elimina; senza movimenti sì.

#### 4.6 Riquadro e impostazione

- Riquadro della home **«Lotti in scadenza»**: lotti con pezzi scaduti o che
  scadono entro `batch_expiry_days`, i primi dieci per scadenza, link alla
  pagina *Lotti* col filtro.
- `batch_expiry_days` in *Set Up → Impostazioni*, card **Magazzino** (nuova,
  solo con `batch_tracking`), del commerciante.

#### 4.7 Movimenti e documenti

Colonna **Lotto** e ricerca per numero del lotto nei *Movimenti*; lotto e
scadenza nelle righe delle schede di carichi e scarichi.

### 5. Più sedi e trasferimenti (`multi_location`)

#### 5.1 Quello che c'è già

Pagina *Sedi*, colonne per sede in *Giacenze*, *Sede* nei Movimenti e nei
documenti, scorta minima per sede: con la funzionalità accesa funzionano già.
G3 aggiunge la sede della vendita (§2.1), i trasferimenti e la regola di
spegnimento.

#### 5.2 Il documento *Trasferimenti* (G3.16)

*Magazzino → Trasferimenti*, fra *Inventari* e *Lotti*, solo con
`multi_location`. Stessa struttura di carichi e scarichi
(`StockTransferResource` su `StockDocumentResource`, tipo `transfer`).

- **Bozza:** **Da** (`location_id`) · **A** (`to_location_id`) · Nota; righe con
  i pezzi e, con i lotti, il lotto facoltativo come nello scarico. Da e A sono
  sedi con *Giacenza* accesa e diverse fra loro
  (`stock_document.transfer_same_location`). Bottoni *Salva bozza* e **Invia**.
- **Invia** (bozza → `in_transit`): numero dalla sequenza dei trasferimenti,
  data del documento = oggi; per ogni riga `StockPicking` sulla sede di
  partenza (scaduti compresi solo con il lotto scelto) e un `transfer_out` per
  parte, con `reference_item_id` = la riga e `unit_cost` del secchio. Pezzi
  insufficienti: rifiuto, come lo scarico; le prenotazioni non contano
  (G3.17).
- **Ricevi** (`in_transit` → `completed`): per ogni `transfer_out` del
  documento un `transfer_in` nella sede d'arrivo, stesso lotto, fornitore,
  pezzi e costo. Sempre tutto.
- **Annulla** da *in viaggio*: storno dei `transfer_out`, la merce torna alla
  partenza. Da *completato*: storno dei `transfer_in` (se all'arrivo i pezzi non
  ci sono più, rifiuto «la merce non è più tutta nella sede d'arrivo») e poi
  dei `transfer_out`. Tutto in una transazione, con il lock del documento.
- Elenco: Numero · Data · Da · A · Righe · Pezzi · Stato (*Bozza*, *In
  viaggio*, *Ricevuto*, *Annullato*) · Chi · ⋯. Scheda in sola lettura con
  *Ricevi*, *Annulla*, *Duplica*. Nei Movimenti *Visualizza trasferimento*.

#### 5.3 In viaggio

Colonna **In viaggio** nelle *Giacenze* con la funzionalità accesa: per
versione, la somma dei `transfer_out` dei trasferimenti `in_transit`. Non è
in nessuna sede, non è disponibile, non entra nel valore; il *Totale* resta la
somma delle sedi.

#### 5.4 Spegnere le funzionalità (G3.18)

`FeatureGuards::refusal(string $key): ?string`, chiamata dal pannello prima di
salvare un blocco: per `multi_location` rifiuta con pezzi diversi da zero in
una sede che non è la principale, o con trasferimenti in viaggio, e dice quali.
Un blocco a catena che porterebbe giù `multi_location` si ferma allo stesso
modo. `purchasing` e `batch_tracking` non hanno guardie.

### 6. Documentazione, dati di prova, test, piani

#### 6.1 Guide

- Commerciante: **Acquisti e valore del magazzino**
  (`magazzino-acquisti.md`), **Lotti e scadenze** (`magazzino-lotti.md`, con il
  richiamo passo per passo), **Trasferimenti** (`magazzino-trasferimenti.md`);
  ritocchi a *Carichi e scarichi*, *Giacenze*, *Movimenti*, *Inventario*,
  *Funzionalità*, *Sedi* (la sede da cui escono gli ordini).
- Sviluppatore (`concetti/magazzino.md`): secchi e secchio 0, `StockPicking`,
  `StockCosts`, `reference_item_id`, compensazione, sede della vendita,
  `Levels::forSale()`, `FeatureGuards`.
- CHANGELOG con «chi aggiorna lancia `php forge update`».

#### 6.2 Dati di prova

`StockDocumentsDemo` aggiunge, **solo con le funzionalità accese** (altrimenti
lo dice con una nota): un fornitore di prova con un carico con costi; un lotto
che scade fra dieci giorni e uno scaduto (con `batch_tracking`); una seconda
sede con un trasferimento in viaggio (con `multi_location`). La pulizia annulla
e toglie come oggi, lotti compresi.

#### 6.3 Test

- **Puri:** `StockPicking` (ordine, scaduti, scelta a mano, `missing`, e la
  prova dell'inerzia sui soli secchi 0), `StockCosts`, `StockValue`,
  `Locations::serving()`, le regole nuove di `StockDocumentRules` (lotto
  obbligatorio, scadenza in contrasto, sedi del trasferimento).
- **Integrazione:** vendita divisa su due lotti e suo annullo; reso parziale
  in ordine inverso; compensazione al carico e suo annullo; trasferimento
  inviato, ricevuto e annullato dai due stati; `LevelsSql` contro `Levels`
  con lotti scaduti, valore e in viaggio; la guardia del pannello.
- **Due processi:** due vendite contemporanee sugli ultimi pezzi di due lotti
  (come i test di contemporaneità di G4).
- **Inerzia:** tutta la suite di oggi verde con le funzionalità spente, senza
  toccare i test.

#### 6.4 Prova nel browser

Le funzionalità accese una alla volta su `ecommerce.test`: carico con fornitore
e costi e valore che cambia; carico con lotti, vendita online che prende il
lotto che scade prima, annullo che lo rimette; lotto scaduto fuori dalla
vetrina e scaricato a mano; scheda del lotto con i clienti; trasferimento
inviato e ricevuto con le colonne *In viaggio*; ritiro in una seconda sede con
*Giacenza* accesa e spenta; il pannello che rifiuta di spegnere *Più sedi*.

#### 6.5 Piani

Ognuno sul suo ramo, unito su `main` a interruttori spenti (D63):

1. **Dati, ripartizione e vendita** (§1, §2): tabelle, `StockPicking`,
   `StockBuckets`, `StockCosts`, `Allocation`, `Returns`, `Levels::forSale()`
   con `Cart` e la vetrina, `Locations::forSale()` completa. Dopo questo
   piano la suite intera deve essere verde senza ritocchi.
2. **Acquisti** (§3): carico con fornitore e costi, compensazione, valore,
   riquadro, colonne.
3. **Lotti e scadenze** (§4): interruttore dell'articolo, carico con i lotti,
   scarico con la scelta, scaduti, pagina *Lotti*, riquadro, impostazione.
4. **Più sedi e trasferimenti** (§5): documento in due passi, *In viaggio*,
   guardia del pannello.
