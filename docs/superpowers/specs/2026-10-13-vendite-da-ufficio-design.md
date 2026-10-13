# G9 — Vendite da ufficio (senza la parte fiscale)

**Stato:** decisioni G9.1–G9.18 approvate il 2026-10-13, tutte come consigliate.
Design scritto lo stesso giorno.

Riferimenti: [architettura](2026-09-11-gestionale-ecommerce-architettura-design.md)
§4.7 (preventivi e ordini), §4.8 (pagamenti, condizioni a rate), §4.9 (DDT),
§4.11 (righe evase), §5.4 (vendita da ufficio), §5.6 (annullamenti), §10.3 (D63);
[ordini e pagamenti](2026-09-29-ordini-e-pagamenti-design.md) (G4, che rimandava
qui la composizione delle righe, i preventivi e le rate);
[sconti e coupon](2026-10-05-sconti-e-coupon-design.md) (lo sconto di riga «da
schermata» è di G9); [G3](2026-10-12-magazzino-avanzato-design.md) (lotto e
fornitore scelti a mano).

## Contesto

G9 porta la vendita fatta **dal gestionale**: l'operatore compone un ordine per un
cliente, o prima un preventivo che il cliente accetta; lo conferma, la merce esce,
nascono le rate da incassare; la consegna si accompagna con un DDT. Quattro pezzi:

1. **condizioni di pagamento a rate** e **scadenzario**;
2. **ordini da ufficio**: composizione, conferma, annullo delle righe non evase;
3. **preventivi**, con revisioni, PDF ed email;
4. **DDT**.

**Senza la parte fiscale (D63):** la fattura differita TD24
(`deferred_invoicing`) e la fattura della merce consegnata prima del pagamento
(architettura §5.3, punto 2) restano a **G8**. G9 non crea fatture.

**Quando si unisce (G9.1).** La creazione degli ordini da ufficio dipende da
*Vendita in ufficio* (`office_sales`), che è già una funzionalità costruita e può
essere accesa nel negozio della consegna del 29 ottobre. Per questo **G9 si unisce
su `main` dopo la `1.0.0`**: fino ad allora vive sui rami. Preventivi e DDT stanno
dietro `quotes` e `delivery_notes`, spente di serie: con gli interruttori spenti il
negozio si comporta come prima (vincolo di D63).

### Cosa c'è già nel codice

- **Ordini (`gst_orders`).** Le colonne di §4.7 ci sono già tutte, anche quelle
  che servono qui: `stage` (`cart`, `quote`, `order`), `channel` (`office`,
  `online`, `b2b_portal`, `pos`), `quote_number`, `quote_revision`,
  `quote_sent_at`, `quote_expires_at`, `order_number`, `ordered_at`, `user_id`
  (operatore), `payment_term_id`, `price_list_id`, `manual_discount_type` e
  `manual_discount_value`, `document_note`, `internal_note`. `Order::STATUSES`
  ha già `draft`, `sent`, `rejected`, `expired` accanto a quelli dell'ordine.
- **Righe (`gst_order_items`).** Tipi `product`, `custom`, `text`, `shipping`,
  `fee`; `discount_type` e `discount_value` per lo sconto di riga; righe figlie
  per i multiprodotti.
- **Prezzi.** `LinePrice::of()` calcola la riga **con lo sconto manuale** (il
  motore c'è, mancava la schermata); `OrderTotals::of()` i totali; campagne e
  coupon passano da `Campaigns` e `Coupons`, che con `office_sales` valgono anche
  per l'ufficio. `Cart::recalculate()` mette tutto insieme per il carrello.
- **Conferma.** `Lifecycle::confirm()` accetta `draft` e `pending`, scarica con
  `Allocation::commit()` riga per riga e scrive `confirmed`. `Allocation::commit()`
  **scarica anche senza prenotazione** se la merce c'è, se l'articolo si vende
  senza giacenza o se il pagamento è già a posto; prende già `batch_id` e
  `supplier_id` della riga (G3). Oggi `confirm()` registra anche il pagamento
  intero quando il metodo lo prevede: per l'ufficio non deve (§2.3).
- **Numerazione.** `DocumentSequences::next($tipo)` dà `{YYYY}/{mm}{nnnn}`; oggi
  `order`, `sales_return` e i documenti di magazzino. Prefisso `del_` per i DDT
  già in `Codes`.
- **Pagamenti (`gst_payments`).** Ogni movimento di denaro è una riga, con
  `due_date` (già indicizzata), `status` (`pending`, `paid`, `failed`,
  `cancelled`), `order_id` **e `subscription_id`**. `PaymentStatus::of()` calcola
  lo stato dell'ordine dalle righe: con sole righe in attesa è `pending`.
  `Ledger` registra, rimborsa e risincronizza. Sull'ordine c'è già *Registra
  pagamento* (`OrderPaymentResource`), libero nell'importo.
- **Metodi di pagamento.** `applies_office` c'è già; `timing` (`immediate`,
  `deferred`, `on_delivery`).
- **Anagrafiche.** `gst_contacts.payment_method_id` e `payment_term_id`, i
  predefiniti del cliente, ci sono già e sono vuoti.
- **Evasione.** `ShippableLines` (pura) dice quanto resta da spedire per riga, dalle
  spedizioni vive; `Shipments` aggiorna `fulfillment_status`; `Lifecycle::fulfill()`
  lo segna a mano.
- **Scadenze.** `Expiry` annulla solo gli ordini `pending` con un pagamento in
  attesa: le bozze (`draft`) e gli ordini da ufficio confermati non li tocca.
- **Email.** `OrderNotifier::send($chiave, $orderId)` con i testi in `OrderEmail`.
- **PDF.** Nel core c'è `Wonder\Pdf` (FPDF); nel modulo nessun PDF.
- **Funzionalità.** `quotes` e `delivery_notes` sono in `config/features.php` con
  `created => false`; `office_sales` è costruita (G6) e oggi fa valere coupon,
  campagne e metodi di pagamento per l'ufficio, ma non c'è una schermata per fare
  un ordine.

## Decisioni

| # | Decisione |
|---|---|
| G9.1 | La creazione degli ordini da ufficio dipende da `office_sales`; G9 si unisce su `main` **dopo la `1.0.0`**. |
| G9.2 | Condizioni di pagamento con le loro rate (due tabelle), in Set Up; alla conferma di un ordine da ufficio una riga di pagamento in attesa per rata; online resta una riga sola; predefinito dalla scheda del cliente; con una sola condizione, o nessuna, il campo non compare (D20). Le condizioni **non sono legate all'ordine**: le riusa G10. |
| G9.3 | Pagina *Scadenzario* e riquadro della home dei pagamenti in scadenza e scaduti; *Registra pagamento* su una rata, anche con un importo diverso (la differenza resta da pagare); resta il registro libero sull'ordine. |
| G9.4 | L'ordine da ufficio nasce in **bozza** (`draft`, senza numero) e si modifica liberamente; *Conferma* dà numero, scarico, rate ed email. Negli *Ordini* la bozza ha badge e filtro. |
| G9.5 | Editor delle righe come quello di carichi e scarichi: versione cercata o letta, quantità, prezzo modificabile, sconto di riga in % o €; righe libere e di solo testo; sconto sul totale; spedizione calcolata e modificabile; coupon e campagne come per l'ufficio. Senza *Listini cliente* (non costruiti) il prezzo parte dal prezzo base. |
| G9.6 | Con *Lotti* o *Acquisti*, lotto e fornitore si scelgono a mano sulla riga, come nello scarico; vuoto = automatico. |
| G9.7 | Merce che manca alla conferma: si rifiuta, salvo gli articoli che si vendono senza giacenza. |
| G9.8 | Dopo la conferma le righe non si modificano; si **annullano le righe non evase** (la merce rientra, le rate in attesa si ricalcolano); le evase passano dal reso. |
| G9.9 | Preventivo: bozza → inviato (numero, PDF archiviato) → accettato, rifiutato o scaduto; ogni modifica dopo l'invio è una revisione con il suo PDF; un'attività segna gli scaduti. |
| G9.10 | *Accetta* trasforma lo stesso documento in un **ordine in bozza** compilato; poi si conferma. |
| G9.11 | L'accettazione dal cliente (link nell'email, area cliente) è fuori da G9: voce nel TODO dell'ecommerce. |
| G9.12 | PDF con `Wonder\Pdf` del core (FPDF), classi sostituibili dal sito (D52): preventivo, conferma d'ordine, DDT. Il proforma degli abbonamenti è di G10 sulla stessa base. |
| G9.13 | Email del preventivo e della conferma d'ordine da ufficio, con il PDF, dal sistema di email degli ordini. |
| G9.14 | DDT solo **da ordine**: righe non ancora consegnate, più DDT per ordine, lotti stampati. DDT da trasferimento e correzione del lotto in bozza: dopo, nel TODO. |
| G9.15 | Una regola sola per le righe evase: DDT emesso, spedizione partita, ritiro completato, contati una volta. DDT e spedizione restano separati. |
| G9.16 | DDT: bozza modificabile, numero e PDF all'emissione, poi fermo; annullabile; dati di trasporto con i predefiniti nelle impostazioni; prezzi non stampati; invio al cliente per email facoltativo. |
| G9.17 | `quotes` e `delivery_notes` diventano costruite; spente non compare niente di loro. |
| G9.18 | Quattro piani, nell'ordine di questo documento; test d'integrazione di ognuno da lanciare sul sito di prova. |

## 1. Condizioni di pagamento e scadenzario (piano 1)

### 1.1 Dati

| Tabella | Colonne |
|---|---|
| `gst_payment_terms` | code `ptm_`, name (es. «Bonifico 30 gg fine mese»), sdi_condition (`TP01` a rate, `TP02` completo, `TP03` anticipo), active, position, deleted |
| `gst_payment_term_installments` | payment_term_id, days, end_of_month (`true`/`false`), percentage, position |

- Configurazione di `admin` in **Set Up → Condizioni di pagamento**, con le rate in
  un repeater; sincronizzata fra ambienti come metodi e conti (8.2). Righe
  precaricate: «Rimessa diretta» (una rata, 0 giorni, 100%, `TP02`), «Bonifico 30
  gg fine mese» (30 giorni, fine mese, 100%, `TP02`), «RiBa 30/60 gg fine mese» (due
  rate al 50%, `TP01`).
- **Regola pura** `PaymentTermRules`: le percentuali sommano a 100 (altrimenti la
  condizione non si salva), almeno una rata, giorni da 0 a 365.
- Una condizione usata da un ordine non si elimina (si nasconde con `active`).

### 1.2 Il piano delle rate

`PaymentSchedule::of(array $installments, float $total, DateTimeImmutable $from):
list<array{due_date, amount}>`, **pura**:

- scadenza = `$from` + giorni; con `end_of_month` si va all'ultimo giorno di quel
  mese («30 gg fine mese» dal 10 ottobre = 30 novembre);
- importo = totale × percentuale, arrotondato al centesimo; **l'ultima rata prende
  il resto**, così la somma torna sempre;
- una condizione senza rate, o nessuna condizione, è una rata sola al 100% a 0
  giorni.

Non sa niente di ordini: G10 la usa per gli abbonamenti da ufficio (§5.5
dell'architettura) con la data d'inizio del periodo.

### 1.3 Le rate di un ordine

`Installments` (servizio, nel modulo):

- `create(int $orderId)`: alla conferma di un ordine da ufficio scrive una riga di
  `gst_payments` per rata — `type` `payment`, `status` `pending`, `due_date`,
  `amount`, `order_id`, `customer_id`, `payment_method_id` e
  `payment_account_id` dell'ordine, `provider` `manual`, `note` «Rata 1 di 2». Lo
  stato di pagamento dell'ordine passa da `PaymentStatus` come oggi (`pending`).
- `register(int $paymentId, float $amount, array $options)`: *Registra pagamento*
  su una rata.
  - Importo uguale: la rata diventa `paid` con `paid_at`.
  - Importo minore: la rata diventa `paid` per quanto incassato e nasce una riga
    `pending` col resto, **stessa scadenza**.
  - Importo maggiore: la rata diventa `paid` per l'importo intero; l'eccedenza
    riduce le rate `pending` successive in ordine di scadenza (una rata azzerata
    passa a `cancelled`). Oltre il totale dell'ordine è un rifiuto.
  - Poi `Ledger::sync()` ricalcola lo stato dell'ordine.
- `recalculate(int $orderId)`: dopo l'annullo di righe (§2.5) il «da pagare» (totale
  meno pagato) si ridistribuisce sulle rate `pending` in proporzione ai loro
  importi; se il pagato supera il nuovo totale, le rate `pending` si annullano e il
  rimborso si fa come oggi (§5.6 dell'architettura).
- Il registro libero di *Registra pagamento* sull'ordine resta: un pagamento libero
  su un ordine con rate chiude prima le rate in scadenza più vicina (stessa logica
  dell'importo maggiore).

### 1.4 Scadenzario

- **Vendite → Scadenzario** (`ScheduleResource`, con `office_sales`): elenco delle
  righe di `gst_payments` `pending` con `due_date`. Colonne: scadenza (rossa se
  passata), cliente, documento (link all'ordine; per G10 l'abbonamento), rata,
  importo, metodo. Filtri: *Scaduti*, *In scadenza* (entro i giorni delle
  impostazioni, predefinito 7), *Tutti in attesa*, *Pagati* (ultimi 90 giorni);
  cliente. Azione di riga *Registra pagamento* (§1.3).
- **Riquadro della home «Pagamenti in scadenza»** (`DuePaymentsWidget`): scaduti
  e in scadenza, i primi dieci, il totale da incassare; spento senza
  `office_sales` o senza rate in attesa.
- **Impostazioni:** «Giorni di preavviso per le scadenze» (predefinito 7), fra le
  impostazioni del commerciante.

### 1.5 Scheda cliente

Metodo e condizione di pagamento predefiniti sulla scheda del contatto (colonne già
presenti), proposti nell'ordine da ufficio. La condizione compare solo se ce n'è più
di una attiva (D20).

## 2. Ordini da ufficio (piano 2)

### 2.1 Stati

| Momento | `stage` | `status` | Numero |
|---|---|---|---|
| Bozza | `order` | `draft` | nessuno |
| Confermato | `order` | `confirmed` → `processing` → `completed` | `order_number` alla conferma |
| Annullato | `order` | `cancelled` | resta, se c'era |

- Gli **Ordini** già elencano `stage = 'order'`: le bozze ci sono, con il badge
  *Bozza*, il filtro *Bozze* e fuori dai conteggi «da evadere» e dal riquadro
  della home. `channel` = `office`, `user_id` = l'operatore.
- La bozza si **elimina** (non si annulla): non ha numero né movimenti.

### 2.2 Composizione

Pagina **Vendite → Nuovo ordine** (`OfficeOrderResource`, con `office_sales`), con
la bozza in modifica sulla stessa pagina.

- **Testata:** cliente (ricerca nelle anagrafiche clienti, o *Nuovo cliente* con il
  quick-create del core); indirizzi di fatturazione e consegna copiati dalla
  scheda e modificabili; consegna (spedizione, ritiro in una sede, nessuna); sede
  che evade (con *Più sedi*); metodo e condizione di pagamento (predefiniti dal
  cliente, solo i metodi con `applies_office`); coupon; sconto sul totale (% o €);
  note (cliente, interna, stampata).
- **Righe:** un editor sul modello di `StockDocumentEditor` — «Cerca o scansiona»,
  quantità, **prezzo unitario modificabile**, **sconto di riga** (% o €), IVA della
  versione, totale di riga; righe libere (`custom`, descrizione, prezzo, aliquota)
  e di solo testo (`text`); multiprodotti e personalizzazioni come nel carrello.
- **Spedizione:** costo da `ShippingRates` per metodo e peso, **modificabile**
  (§4.11 dell'architettura); diventa la riga `shipping` come online.
- **Ricalcolo:** lo stesso motore del carrello (`LinePrice`, `OrderTotals`,
  `Campaigns`, `Coupons`). Il piano decide se `Cart::recalculate()` si generalizza
  alle bozze o se nasce `OfficeOrder::recalculate()` che ne riusa i pezzi; il
  prezzo scritto a mano vince sulla campagna (`price_source` `manual`).
- **Disponibilità:** l'editor mostra la giacenza disponibile della sede per riga
  (come lo scarico) e avvisa, senza bloccare, quando non basta.
- **Salvataggio** della bozza con la stessa forma dei documenti di magazzino
  (POST con le righe in JSON, rifiuti per riga, ripristino se la bozza nuova non
  nasce).

### 2.3 Conferma

`OfficeOrders::confirm(int $orderId, array $options)`, in una transazione:

1. ricalcolo e controlli (cliente, almeno una riga, indirizzo se si spedisce);
2. **scarico** con `Allocation::commit()` per riga, con lotto e fornitore scelti
   (§2.4) e `payment_ok` falso: se la merce manca e l'articolo non si vende senza
   giacenza, **rifiuto** con il nome della versione (G9.7) e niente cambia;
3. `order_number` (`DocumentSequences::next('order')`), `ordered_at`, `status`
   `confirmed`, log;
4. **rate** (`Installments::create`, §1.3);
5. dopo la transazione, email di conferma al cliente (§3.5).

`Lifecycle::confirm()` oggi registra il pagamento intero se il metodo lo prevede:
per gli ordini da ufficio **non lo fa** (le rate sostituiscono quel pagamento). Il
piano lo separa con un'opzione esplicita, senza cambiare il percorso online.

### 2.4 Lotto e fornitore a mano

Con `batch_tracking` o `purchasing`, sulla riga di prodotto le tendine *Lotto* e
*Fornitore* dello scarico (G3, piano 3a): vuote = automatico. Si salvano sulla
riga (`batch_id`, `supplier_id` di `gst_order_items`, colonne nuove che il piano
aggiunge) e passano ad `Allocation::commit()`.

### 2.5 Dopo la conferma

- Le righe **non si modificano**.
- *Annulla righe* (azione dell'ordine): si scelgono righe e quantità **non ancora
  evase** (§5.4); la merce rientra con `sale_cancel` sullo stesso secchio
  (`Allocation::restore()`), le righe annullate restano visibili con la quantità
  annullata, i totali si ricalcolano, le rate si ricalcolano (`Installments::recalculate`).
  Tutte le righe annullate = ordine annullato, come oggi.
- Per le righe evase: il reso, come oggi.

## 3. Preventivi, PDF ed email (piano 3)

### 3.1 Stati

| Momento | `stage` | `status` | Numero |
|---|---|---|---|
| Bozza | `quote` | `draft` | nessuno |
| Inviato | `quote` | `sent` | `quote_number` al primo invio, `quote_revision` |
| Rifiutato, scaduto | `quote` | `rejected`, `expired` | resta |
| Accettato | `order` | `draft` (bozza d'ordine) | `quote_number` resta; `order_number` alla conferma |

- **Vendite → Preventivi** (`QuoteResource`, con `quotes`): elenco `stage = 'quote'`
  con stato, cliente, totale, scadenza; *Nuovo preventivo* con lo stesso editor
  dell'ordine (§2.2), più la **validità** (`quote_expires_at`, predefinita dalle
  impostazioni, 30 giorni).
- **Invia:** al primo invio `quote_number` (`DocumentSequences::next('quote')`),
  `quote_revision` 1, PDF archiviato, email al cliente. Una modifica dopo l'invio
  porta la revisione a +1 e il preventivo torna da inviare; ogni invio archivia il
  suo PDF.
- **Accetta** (G9.10): lo stesso documento passa a `stage` `order`, `status`
  `draft`; si apre la bozza d'ordine per controllare consegna e pagamento e
  confermare (§2.3). **Rifiuta**: `rejected`. Nessun effetto sul magazzino in
  nessuno stato del preventivo (non prenota).
- **Scadenza:** un'attività giornaliera (scheduler del core, come `Expiry`) porta a
  `expired` i preventivi `sent` oltre `quote_expires_at`.
- L'accettazione dal cliente (link nell'email, area cliente) è fuori (G9.11).

### 3.2 Revisioni

`gst_order_quote_revisions` (order_id, revision, pdf_file, sent_to, sent_at,
user_id), come §4.7 dell'architettura. Nella scheda del preventivo l'elenco delle
revisioni con il loro PDF.

### 3.3 PDF

- Base `DocumentPdf` sopra `Wonder\Pdf` (FPDF): intestazione con i dati della
  società e il logo (`$SOCIETY` del core), dati del cliente, tabella delle righe,
  totali e riepilogo IVA, note stampate, piè di pagina con conto e IBAN del
  metodo di pagamento.
- Tre documenti: `QuotePdf`, `OrderConfirmationPdf`, `DeliveryNotePdf` (§4). **Le
  classi sono sostituibili dal sito** con un filtro (`GestionaleExtension`, D52):
  il sito può dare la sua classe per ciascuno.
- I file stanno in una cartella **non pubblica**; si scaricano da una rotta del
  backend che controlla i permessi (come il CSV dei clienti del lotto).

### 3.4 Email

Con `OrderNotifier` e testi in `OrderEmail`, modificabili come gli altri:

- `quote_sent`: preventivo con il PDF allegato, validità, contatti;
- `office_confirmed`: conferma dell'ordine da ufficio con il PDF, rate e scadenze.

Se il sistema di email del core non allega file, il piano lo aggiunge nel core
prima (lavoro preparatorio, D58).

## 4. DDT (piano 4)

### 4.1 Dati

Come §4.9 dell'architettura:

| Tabella | Colonne |
|---|---|
| `gst_delivery_notes` | code `del_`, number, date, status (`draft`, `issued`, `cancelled`), reason (`sale`, `transfer`), order_id, stock_document_id, customer_id, from_location_id, dati `billing_` e `shipping_` copiati, transport_by (`sender`, `recipient`, `carrier`), carrier_id e dati del vettore copiati, transport_started_at, goods_appearance, packages_count, gross_weight, net_weight, freight_terms (`prepaid`, `collect`), document_note, pdf_file, user_id |
| `gst_delivery_note_items` | delivery_note_id, order_item_id, product_id, sku, description, quantity, unit, batch_id, supplier_id, position |
| `gst_delivery_note_status_logs` | come gli altri log di stato (4.1) |

`reason` `transfer` e `stock_document_id` nascono ora ma restano inutilizzati
(G9.14): li userà il DDT da trasferimento.

### 4.2 Da ordine

- Nella scheda di un ordine confermato, *Crea DDT* (con `delivery_notes`): la bozza
  propone, per riga di prodotto, la quantità **non ancora in un DDT emesso**; le
  quantità si abbassano o la riga si toglie. Più DDT per lo stesso ordine.
- I **lotti** stampati sono quelli dei movimenti di vendita della riga (G3): una
  riga d'ordine uscita da due lotti diventa due righe del DDT.
- **Bozza:** si modificano righe, quantità, dati di trasporto. **Emetti:** numero
  (`DocumentSequences::next('delivery_note')`), data, PDF; poi non si modifica.
  **Annulla:** `cancelled`, le sue righe non contano più come consegnate.
- **Dati di trasporto predefiniti** nelle impostazioni (a cura di, vettore, aspetto
  dei beni, porto); il vettore dalla tabella dei corrieri (G7).
- **Prezzi** non stampati.
- *Invia per email* dopo l'emissione, facoltativo (`delivery_note_sent`).
- Elenco **Vendite → DDT** (`DeliveryNoteResource`) con numero, data, cliente,
  ordine, stato.

### 4.3 Il DDT non muove il magazzino

La merce è uscita alla conferma (architettura §4.3). La correzione del lotto nel
DDT in bozza (due movimenti) è dopo, nel TODO.

## 5. Righe evase (G9.15)

Una riga è evasa per la quantità che sta in **un DDT emesso**, in **una spedizione
partita** o in **un ritiro completato**. DDT e spedizione di solito descrivono la
stessa merce (il DDT accompagna il pacco), quindi per riga si prende il
**massimo** fra quantità nei DDT emessi e quantità spedite o ritirate, non la
somma: nessuna merce si conta due volte.

- `ShippableLines` e il calcolo di `fulfillment_status` (`Shipments`) passano a una
  regola pura sola, `FulfilledLines::of($items, $inNotes, $shipped)`, usata da
  spedizioni, DDT e scheda dell'ordine.
- Limite noto, scritto nella guida: se per lo stesso ordine una parte va con un
  DDT senza spedizione e un'altra con una spedizione senza DDT, il massimo conta
  meno del vero. Si usa lo stesso tipo di documento per tutto l'ordine.
- La spedizione creata da un DDT (con le righe del DDT) è dopo, nel TODO.

## 6. Funzionalità e navigazione

| Funzionalità | Cosa accende |
|---|---|
| `office_sales` (già costruita) | *Nuovo ordine*, bozze negli *Ordini*, *Annulla righe*, condizioni di pagamento nell'ordine, *Scadenzario* e riquadro |
| `quotes` (diventa costruita, richiede `orders`) | *Preventivi*, email e PDF del preventivo |
| `delivery_notes` (diventa costruita, richiede `orders`) | *DDT*, *Crea DDT* nell'ordine, dati di trasporto nelle impostazioni |

- Le **condizioni di pagamento** in Set Up sono di `admin` e non dipendono da una
  funzionalità (le userà anche G10).
- Spente, nessuna voce di menu, pulsante, colonna o riquadro. La guardia del
  pannello (G3.18) non spegne `delivery_notes` con DDT in bozza (si emettono o si
  annullano prima) né `quotes` con preventivi inviati e non scaduti.

## 7. Piani e prove

| Piano | Contenuto | Test d'integrazione |
|---|---|---|
| 1 | Condizioni di pagamento, `PaymentSchedule`, `Installments`, Scadenzario, riquadro, predefiniti del cliente | rate di un ordine confermato, pagamento minore e maggiore, ricalcolo |
| 2 | Ordini da ufficio: bozza, editor, conferma, lotto e fornitore a mano, annullo righe non evase | conferma con scarico e rate, rifiuto per merce che manca, annullo righe con rientro e ricalcolo |
| 3 | `DocumentPdf` e PDF, preventivi con revisioni e scadenza, email di preventivo e conferma | invio e revisione, scadenza, accettazione in bozza d'ordine |
| 4 | DDT, `FulfilledLines`, PDF del DDT | DDT parziali, annullo, evasione con DDT e spedizioni |

- I test d'integrazione vogliono il database del sito di prova: si lanciano sul Mac
  dell'utente, come per il G3.
- **Prove nel browser** per piano: composizione di un ordine con sconti e
  spedizione; conferma e scadenzario; preventivo inviato, modificato e accettato;
  DDT parziale e stampato.
- Ogni piano aggiorna guide (commercianti e sviluppatori), dati di prova (un
  ordine da ufficio confermato con rate, un preventivo inviato, un DDT), CHANGELOG
  e TODO.

## 8. Fuori da G9

- Fattura differita TD24 e fattura della merce consegnata prima del pagamento: G8.
- Accettazione del preventivo dal cliente (link, area cliente): ecommerce.
- DDT da trasferimento fra sedi; correzione del lotto nel DDT in bozza; spedizione
  creata da un DDT.
- Listini cliente (`customer_price_lists`, G6, non costruiti).
- Promemoria email delle rate scadute (per gli ordini; per gli abbonamenti è G10).
