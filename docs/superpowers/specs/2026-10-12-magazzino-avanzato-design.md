# G3 — Magazzino avanzato

**Stato:** bozza del 2026-10-12. Contesto e decisioni da approvare; le sezioni del
design si scrivono dopo l'approvazione della tabella, una alla volta, come per
G2b-bis.

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

### Un difetto di oggi, da correggere prima della consegna

Con `multi_location` **spenta**, un ordine con ritiro in una sede che non è la
principale prenota e scarica **da quella sede** (`Allocation::context()` usa
`location_id` della riga, cioè il punto di ritiro). Ma con la funzionalità
spenta l'unica sede del magazzino è la principale (architettura §4.3): la
prenotazione legge una giacenza vuota e il checkout risponde «non disponibile»,
oppure, con la vendita senza giacenza, la sede di ritiro va sotto zero.

Correzione proposta, piccola e indipendente da G3: con `multi_location` spenta
`Allocation` usa sempre `Locations::mainId()`, qualunque sia la sede dell'ordine.
Va in un ramo a parte, prima del 29 ottobre (decisione 1 qui sotto).

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

## Decisioni da prendere

Ogni riga è una **proposta**, con le alternative che scarterei. Le numero per
poterle approvare o cambiare una per una.

| # | Tema | Proposta | Alternative scartate |
|---|---|---|---|
| 1 | Sede della vendita con `multi_location` spenta | **sempre la principale** in `Allocation`, qualunque sia il punto di ritiro; correzione separata, prima della consegna | lasciare com'è (il ritiro in una seconda sede non funziona) |
| 2 | Sede della vendita con `multi_location` accesa | **spedizione: la principale; ritiro: la sede di ritiro se ha giacenza, altrimenti la principale.** Nessun trasferimento automatico | un'impostazione «sede delle spedizioni online»; la sede con più merce; prenotare un po' da una e un po' dall'altra |
| 3 | Come si divide uno scarico fra i secchi | **una classe pura, `StockPicking::split()`**: riceve i secchi di prodotto e sede (con scadenza del lotto e costo del fornitore), la quantità e le funzionalità accese; risponde con la lista `(lotto, fornitore, pezzi)`. A interruttori spenti risponde sempre `[(0, 0, pezzi)]`: è il test che prova che il negozio non cambia. La usano vendita, scarico, rettifica in meno, trasferimento e inventario | la scelta dentro `Stock::apply()` (che resterebbe una porta, ma con dentro le regole); una query SQL ordinata |
| 4 | Prenotazioni | **restano per prodotto e sede**, senza lotto né fornitore: la ripartizione si fa alla conferma, con le scadenze di quel giorno | prenotare già il lotto (un lotto che scade fra prenotazione e conferma resterebbe bloccato) |
| 5 | Annullo e reso di una vendita divisa su più secchi | **colonna `reference_item_id` su `gst_stock_movements`** (la riga d'ordine o del documento). L'annullo rimette in ogni secchio quello che la riga ne aveva tolto; il reso parziale rientra nei secchi in ordine inverso a quello di uscita | tabella `gst_stock_allocations` con la ripartizione; rientro sempre nel secchio 0 (si perderebbe il lotto, e il richiamo) |
| 6 | Il secchio 0 dopo l'accensione | la merce che c'era prima (lotto 0, fornitore 0) **resta dov'è, nessuna migrazione**. Nello scarico automatico esce **per prima** (è la più vecchia). Nel valore conta al **costo più basso fra i fornitori dell'opzione**; senza nessun costo conta zero e il riquadro lo dice («N pezzi senza costo») | chiedere al commerciante di assegnarla a mano all'accensione; uscire per ultima |
| 7 | Fornitore sul carico con `purchasing` accesa | **sulla testata, facoltativo**: vale per tutte le righe (le righe lo copiano); senza fornitore la merce entra nel secchio 0. Con il fornitore: numero e data del suo documento, costo per riga | fornitore per riga; fornitore obbligatorio |
| 8 | Costo del fornitore alla conferma del carico | **aggiorna `gst_product_suppliers.cost`**, e **crea il collegamento** opzione-fornitore se manca | aggiornare solo se c'è già; non toccare mai la scheda |
| 9 | Quali articoli hanno i lotti | **un interruttore sull'articolo, «Gestisci lotti e scadenze»** (con `batch_tracking` accesa), spento di serie. Solo i suoi carichi chiedono lotto e scadenza (la scadenza resta facoltativa: ci sono lotti senza) | tutti gli articoli con la funzionalità accesa |
| 10 | Numero del lotto | **unico per opzione**: lo stesso numero in un altro carico è lo stesso lotto, e la scadenza deve coincidere (altrimenti il carico non si conferma) | unico per opzione e fornitore; un lotto nuovo a ogni carico |
| 11 | Lotti scaduti | **fuori dal disponibile** in vetrina, nel checkout e nelle *Giacenze* (colonna *Scaduti* con la funzionalità accesa); restano in giacenza finché uno scarico con causale *Scaduto* non li toglie. Scaduto = data di scadenza prima di oggi | toglierli da soli con un'attività notturna; contarli disponibili fino allo scarico |
| 12 | Lotto e fornitore nello scarico | **scelta a mano facoltativa sulla riga** (con le funzionalità accese); vuota = scarico automatico. Serve per buttare un lotto scaduto, che lo scarico automatico non prende mai | solo automatico |
| 13 | Rettifica rapida con lotti e fornitori | in meno: **scarico automatico**; in più: **secchio 0** (lotto e fornitore sconosciuti) | chiedere lotto e fornitore nella finestra della rettifica |
| 14 | Inventario con lotti e fornitori | **resta per opzione e sede** (piano 6 com'è). Differenza in meno: scarico automatico; in più: secchio 0. La conta per lotto è il «conteggio guidato», funzionalità futura | una riga per lotto nell'inventario (riapre l'editor del piano 6) |
| 15 | Vendita senza giacenza con i lotti (D60: «dettaglio in G3») | il pezzo venduto scoperto va sotto zero nel **secchio 0**. Alla conferma del carico dopo, se il secchio 0 di quell'opzione e sede è sotto zero, **due movimenti di compensazione** (causale interna *Assegnazione lotto*): il lotto appena caricato cede i pezzi al secchio 0, che torna a zero. Così il lotto risulta uscito e il richiamo lo trova | lasciare il secchio 0 negativo e il lotto pieno (le somme tornano, ma il lotto mente e il valore pure); riscrivere il movimento di vendita (vietato) |
| 16 | Trasferimento | **quarto documento, *Trasferimenti***, in due passi: **Invia** (`transfer_out` dalla sede di partenza, stato `in_transit`) e **Ricevi** (`transfer_in` nella sede d'arrivo, stato `completed`). Lotto e fornitore passano uguali. Arrivo sempre per intero. Annullabile da *in viaggio* (storno della partenza) e da *completato* (storno di tutti e due, se la merce è ancora lì). Pezzi *In viaggio* come colonna delle *Giacenze* | un passo solo (partenza e arrivo insieme); arrivi parziali |
| 17 | Pezzi prenotati e trasferimento | stessa regola dello scarico di oggi: **controlla la giacenza, non le prenotazioni** | rifiutare se tocca merce prenotata |
| 18 | Spegnere una funzionalità con dei dati | il pannello **rifiuta** di spegnere `multi_location` con pezzi fuori dalla principale o trasferimenti in viaggio; `purchasing` e `batch_tracking` si spengono sempre (i dati restano, nascosti, e tornano alla riaccensione) | spegnere sempre; spostare la merce da sola nella principale |
| 19 | Valore del magazzino | **riquadro della home** e **colonna *Valore*** nelle *Giacenze*, con `purchasing` accesa; giacenze sotto zero escluse | solo il riquadro; una pagina a parte |
| 20 | Pagina dei lotti | ***Magazzino → Lotti***: elenco con opzione, numero, scadenza, pezzi per sede, filtro *In scadenza* e *Scaduti*; scheda del lotto con giacenze, movimenti e **clienti che l'hanno ricevuto** (per il richiamo) | solo il riquadro in home e il filtro nei Movimenti |

## Design (da scrivere dopo l'approvazione)

Le sezioni previste, ognuna approvata a parte:

1. **Dati e ripartizione** — `gst_batches`, `reference_item_id` sui movimenti,
   `in_transit` negli stati, interruttore dei lotti sull'articolo, causale
   *Assegnazione lotto*, impostazione dei giorni di preavviso; `StockPicking`
   pura con i suoi test; `batch_tracking` costruita. Chi aggiorna lancia
   `php forge update`.
2. **Vendita** — `Allocation` con la sede giusta (1, 2), la ripartizione alla
   conferma (3, 4), annulli e resi per riga (5), scaduti fuori dal disponibile
   (11), vendita scoperta (15). Unico punto toccato nel flusso di vendita.
3. **Acquisti** — carico con fornitore, documento e costi (7, 8), giacenza per
   fornitore, valore del magazzino (6, 19), colonna *Fornitore* nei Movimenti.
4. **Lotti e scadenze** — carico con lotto e scadenza (9, 10), scarico con
   scelta del lotto (12), rettifica e inventario (13, 14), pagina *Lotti* e
   richiami (20), riquadro dei lotti in scadenza, colonna *Lotto* nei Movimenti.
5. **Più sedi e trasferimenti** — documento *Trasferimenti* in due passi (16,
   17), colonna *In viaggio*, regola sullo spegnimento (18).
6. **Documentazione, dati di prova, test, piani** — guide (commerciante:
   *Lotti e scadenze*, *Trasferimenti*, *Acquisti e valore*; sviluppatore:
   magazzino), dati di prova a interruttori accesi, test puri di `StockPicking`,
   test a due processi sui secchi, prova nel browser; piani nell'ordine
   1 → 2 → 3 → 4 → 5.
