# G3 piano 3 — Lotti e scadenze

> Piano breve: spec approvata (`docs/superpowers/specs/2026-10-12-magazzino-avanzato-design.md`, §4, decisioni G3.9–G3.14, G3.20; G3.12 anche per il fornitore, rimandata dal piano 2). Si appoggia sui piani 1 e 2: secchi, `StockPicking`, `StockOut`, compensazione, colonne `batch_number`/`batch_expiry`/`batch_id`/`supplier_id` delle righe, tabella `gst_batches`. **Nessuna tabella né colonna nuova.**

**Due rami in fila, un piano solo:**

- **3a** `g3-piano-3a` (da `g3-piano-2`): servizio, scaduti, interruttore dell'articolo, editor di carico e scarico. Tutto inerte: `batch_tracking` resta non costruita.
- **3b** `g3-piano-3b` (da 3a): pagina *Lotti*, riquadro, impostazione, colonna *Lotto* nei Movimenti, dati di prova, guide. **L'ultimo commit** toglie `created => false` da `batch_tracking`: è lui che rende visibile tutto, e arriva dopo la suite verde.

**Due livelli d'inerzia, tutti e due con i loro test:** con la funzionalità spenta niente cambia; con la funzionalità accesa ma nessun articolo con «Gestisci lotti e scadenze», niente cambia lo stesso (è lo stato appena dopo l'accensione).

## 3a — Servizio e pagine dei documenti

### 1. Regole e servizio
- `StockDocumentRules::lines(array $input, array $batchProducts = [])`: le versioni in `$batchProducts` stanno su più righe purché con lotti diversi (`stock_document.duplicate_batch`); le altre una volta sola come oggi. Le chiavi `batch_number` (50, tagliato), `batch_expiry` (`Y-m-d` o vuota, `stock_document.invalid_expiry`), `batch_id` e `supplier_id` (interi) escono solo se la riga le porta: senza, l'uscita è quella di oggi.
- `assertConfirmable()`: nel carico una riga di versione con i lotti senza `batch_number` = `stock_document.batch_required` con la riga.
- `Batches::resolve(int $productId, string $number, ?string $expiry, int $supplierId): int` — trova o crea il lotto (`bat_`). Due conferme sullo stesso lotto nuovo: l'indice unico `(product_id, number)` fa fallire la seconda `create()`, che allora rilegge. Scadenza diversa da quella del lotto = `batch.expiry_mismatch` con la riga; vuota non contraddice niente; scritta su un lotto senza scadenza la riempie.
- `StockDocuments`: `writeItems()` salva le quattro chiavi; `items()` le torna. `confirm()` del carico: per le versioni con i lotti `Batches::resolve()`, `batch_id` sulla riga, `purchase` nel secchio `(lotto, fornitore)`, compensazione con quel lotto. `confirm()` dello scarico: `batch_id`/`supplier_id` scelti passano a `StockOut::take()`; un lotto che non è della versione = `batch.wrong_product`.
- `Batches::trackingProducts(array $productIds): list<int>` — le versioni i cui articoli hanno `track_batches` (vuota senza `batch_tracking`).

### 2. Scaduti fuori dal disponibile (G3.11)
- `Levels::perLocation(array $stockRows, array $reservationRows, ?string $now, array $expiries = [])` (resta pura): toglie dal disponibile i pezzi dei lotti scaduti e torna anche `expired`. `forProducts()` e `byLocation*()` passano le scadenze solo con `batch_tracking`: `Alerts::refresh()` prende la regola da sé.
- `LevelsSql::expired()` e `available()` meno gli scaduti; colonna **Scaduti** nelle *Giacenze* con la funzionalità.
- Effetto da dire: `Levels::of()['available']` cambia per chiunque lo legga (badge della disponibilità, multiprodotti, scheda), coerente con G3.11.

### 3. L'articolo
- Campo **Gestisci lotti e scadenze** (`track_batches`) nella scheda dell'articolo, con la funzionalità, mai per i multiprodotti. Spegnerlo con pezzi in un lotto (`batch_id > 0`, `quantity <> 0`) = `catalog.batches_in_stock`.

### 4. Editor
- `describe()` porta `tracks_batches` e, per lo scarico, `buckets` della sede: lotti (numero, scadenza, pezzi, scaduto) e fornitori con pezzi.
- **Carico**, versione con i lotti: caselle **Lotto** e **Scadenza**; bottone **+ lotto** che apre una riga nuova della stessa versione sotto; il lettore aggiunge un pezzo all'ultima riga della versione e, se ha i lotti, porta il cursore sul lotto.
- **Scarico**: tendina **Lotto** (con la funzionalità e una versione con lotti) e **Fornitore** (con *Acquisti*) per riga, vuota = automatico; i lotti scaduti in rosso.
- `byId()` diventa «l'ultima riga di quella versione»; il cambio di sede aggiorna tutte le righe della versione; `serialize()` porta le quattro chiavi.
- `StockDocumentSheet`: lotto, scadenza e fornitore scelto sulle righe.

## 3b — Pagine dei lotti e accensione

### 5. Pagina *Lotti* (G3.20)
- `BatchResource` su `Batch`, *Magazzino → Lotti* (ordine 45), con `batch_tracking`: Versione · Lotto · Scadenza (rossa scaduto, gialla entro `batch_expiry_days`) · Fornitore (con *Acquisti*) · Pezzi (per sede con più sedi). Filtri *Stato* (*Con pezzi* predefinito, *In scadenza*, *Scaduti*, *Esauriti*, *Tutti*) e *Sede*; ricerca su numero, nome e SKU.
- Scheda del lotto: dati (scadenza e nota modificabili), pezzi per sede, movimenti del lotto, **clienti che l'hanno ricevuto**: `StockMovements::net()` sui `sale`/`sale_cancel`/`return` del lotto per riga d'ordine, poi ordine e cliente; **Scarica i clienti** (CSV).
- Un lotto con movimenti non si elimina.

### 6. Riquadro, impostazione, Movimenti
- `ExpiringBatchesWidget`: lotti con pezzi scaduti o in scadenza entro `batch_expiry_days`, i primi dieci.
- `batch_expiry_days` nelle Impostazioni di Set Up, card *Magazzino* con la funzionalità.
- *Movimenti*: colonna **Lotto** e ricerca sul numero del lotto (`searchFields` annidato su `gst_batches`).

### 7. Chiusura
- Dati di prova con la funzionalità: un articolo con i lotti, un carico con due lotti (uno in scadenza fra dieci giorni, uno scaduto).
- Guide: `magazzino-lotti.md` (richiamo passo per passo), ritocchi a carichi e scarichi, giacenze, movimenti, inventario, funzionalità; guida sviluppatori; CHANGELOG; TODO.
- **Ultimo commit:** `batch_tracking` costruita; `FeatureCatalogTest` aggiornato.

## Test
- Puri: `StockDocumentRulesTest` (lotti, doppioni per lotto, scadenza), `LevelsTest`/`perLocation` con scaduti, `StockPickingTest` già copre l'ordine.
- Integrazione: carico con due lotti della stessa versione; lotto esistente con scadenza diversa; due conferme sullo stesso lotto nuovo; scarico con lotto e fornitore scelti e con lotto di un'altra versione; scaduti fuori dal disponibile e avviso di scorta; interruttore che non si spegne con pezzi; elenco dei clienti di un lotto al netto dei resi.
- Inerzia: suite intera verde con la funzionalità spenta, e con la funzionalità accesa senza articoli con i lotti.

## Note per l'unione
- `ritocchi-carichi-scarichi`: `restorable()` deve tenere anche `cost` e le quattro chiavi del lotto.
- `StockDocumentsDemoTest`: il numero di documenti di prova cresce con le funzionalità accese.
- L'ecommerce non cambia: la vetrina legge già `Levels::forSale()`, che toglie gli scaduti.
