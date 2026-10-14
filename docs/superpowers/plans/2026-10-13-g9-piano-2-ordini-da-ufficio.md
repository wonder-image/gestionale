# G9 piano 2 — Ordini da ufficio

Spec: [vendite da ufficio](../specs/2026-10-13-vendite-da-ufficio-design.md) §2
(G9.4–G9.8). Ramo `g9-piano-2`, sopra `g9-piano-1`. Si unisce su `main` dopo la
`1.0.0` (G9.1).

## Cosa fa

La bozza d'ordine da ufficio (`stage` `order`, `status` `draft`, `channel`
`office`, senza numero), composta e salvata da una pagina sola; la conferma che
scarica la merce senza prenotazione e crea le rate; *Annulla righe* dopo la
conferma. Il motore dei prezzi è quello del carrello.

## Decisioni del piano

- **Ricalcolo:** `Cart::recalculate()` accetta anche le bozze (`status`
  `draft`), e lascia a zero le righe di solo testo. Niente secondo motore: il
  carrello sa già del prezzo a mano (`price_source` `manual`), dello sconto di
  riga, dello sconto sulla testata e della spedizione a mano.
- **Righe libere:** `type` `custom` con descrizione, prezzo (`manual`) e
  categoria IVA; **di testo:** `type` `text`, quantità e prezzo zero.
- **Conferma:** `OfficeOrders::confirm()` controlla e poi passa da
  `Lifecycle::confirm()` con `payment => false` (le rate prendono il posto del
  pagamento) e `Allocation::commit()` riceve lotto e fornitore della riga. Prima
  dello scarico un controllo della giacenza per riga dà il rifiuto col nome
  della versione (G9.7) invece di `reservation_lost`.
- **Annulla righe:** la quantità annullata scende da `quantity` e si somma in
  `quantity_cancelled` (colonna nuova), così spedizioni, resi e PDF leggono la
  quantità vera. Si annulla al massimo quanto non è ancora assegnato a una
  spedizione (`Shipments::remaining()`); il piano 4 passerà a `FulfilledLines`.
  I totali si rifanno **con i prezzi scritti** (non si riprezza un ordine
  confermato), poi `Installments::recalculate()`. Tutte le righe = l'ordine si
  annulla con `Lifecycle::cancel()`.
- **Fuori da questo piano** (nel TODO, «Dopo G9»): le personalizzazioni e le
  scelte dei multiprodotti nell'editor dell'ufficio. Un multiprodotto si
  aggiunge solo se si risolve senza scelte; una personalizzazione obbligatoria
  blocca la versione con la sua frase.

## Task

1. **Dati e regole pure.** `gst_order_items`: `batch_id`, `supplier_id`,
   `quantity_cancelled`. `OfficeOrderRules` (pura): righe (tipo, quantità,
   prezzo, sconto, massimo 300), testata (consegna, indirizzo se si spedisce),
   quantità annullabili. Test unitari.
2. **Servizio `OfficeOrders`.** `save()` (bozza nuova o esistente, testata e
   righe dal JSON, ricalcolo), `delete()` (solo bozze), `confirm()`,
   `cancelLines()`. `Cart::recalculate()` sulle bozze; `Lifecycle::confirm()`
   passa lotto e fornitore. Test d'integrazione `OfficeOrdersTest`.
3. **Pagina *Vendite → Nuovo ordine*** (`OfficeOrderResource`, `office_sales`):
   testata (cliente con i predefiniti di pagamento e indirizzo, consegna, sede
   con *Più sedi*, metodo e condizione, sconto sul totale, note), editor delle
   righe (`OfficeOrderEditor`: «Cerca o scansiona», righe libere e di testo,
   prezzo e sconto modificabili, giacenza della sede con avviso), spedizione
   modificabile, totali dal server. *Salva bozza* e *Conferma*, con le righe in
   JSON come i documenti di magazzino.
4. **Ordini:** le bozze nell'elenco col badge *Bozza* e il filtro, fuori dai
   conteggi «da evadere»; scheda della bozza con *Modifica*, *Conferma*,
   *Elimina bozza*. **Annulla righe** sulla scheda di un ordine confermato.
5. **Contorno:** dati di prova (una bozza e l'ordine a rate del piano 1 rifatto
   dal flusso vero), guida `vendite-ordini-da-ufficio.md`, guida sviluppatori,
   CHANGELOG, TODO, PR.

## Test d'integrazione (sul sito di prova)

- bozza salvata senza numero, con prezzo a mano, sconto di riga, riga libera e
  riga di testo; totali dal motore del carrello;
- conferma: numero, scarico, rate; rifiuto se la merce manca (con il nome) e
  niente cambia; con la vendita senza giacenza va sotto zero;
- lotto scelto a mano sulla riga che esce da quel lotto;
- annulla righe: merce che rientra, totali e rate ricalcolati, tutte = ordine
  annullato; oltre lo spedito rifiutato;
- la bozza si elimina, un confermato no.
