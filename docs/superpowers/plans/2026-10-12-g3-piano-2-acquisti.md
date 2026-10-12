# G3 piano 2 — Acquisti

> Piano breve: spec approvata (`docs/superpowers/specs/2026-10-12-magazzino-avanzato-design.md`, §3, decisioni G3.6–G3.8, G3.13–G3.15, G3.19, G3.21–G3.22). Si appoggia sul piano 1 (`StockPicking`, `StockBuckets`, `StockCosts`, `reference_item_id`).

**Ramo:** `g3-piano-2`, da `g3-piano-1`. Unione dopo il piano 1.

**Vincolo (D63):** con `purchasing` spenta niente cambia. I campi del fornitore non si stampano e il servizio li ignora; con i soli secchi 0 scarichi, inventari e rettifiche in meno scrivono un movimento solo, come oggi.

## Task

### 1. Servizio
- `StockDocumentRules::lines()`: se la riga porta `cost`, torna anche `unit_cost` (`?float`, IVA esclusa, «2,50» si legge, negativo o storto = `stock_document.invalid_cost` con la riga). Senza `cost` l'uscita è quella di oggi.
- `StockDocumentRules::supplierHeader()` (pura): `supplier_id`, `supplier_document_number` (50), `supplier_document_date` (`Y-m-d` valida o vuota, altrimenti `stock_document.invalid_date`).
- `StockDocuments::create()`/`saveDraft()`: per il carico con `purchasing` accesa scrivono fornitore e documento in testata (fornitore che non è un contatto fornitore = `stock_document.supplier_unknown`), `unit_cost` e `line_total` sulle righe, `total_cost` sul documento. Spenta, le chiavi si ignorano.
- `confirm()`:
  - carico: `purchase` nel secchio `(0, fornitore della testata)` con `unit_cost` della riga; poi la **compensazione** (spec §3.3): secchio 0 sotto zero di *n* → due `adjustment` con causale `batch_assignment` e la stessa riga, `−min(n, pezzi)` nel secchio caricato e `+` nel secchio 0; poi `gst_product_suppliers.cost` aggiornato o il legame creato in fondo (costo maggiore di zero).
  - scarico e inventario in meno: `StockPicking` sui secchi della sede (inventario con i lotti scaduti compresi), un movimento per parte con la stessa riga e il costo del secchio; quello che manca nel secchio 0 (lo scarico lo rifiuta con `stock.insufficient` come oggi). Inventario in più: secchio 0.
- `cancel()`: dentro la stessa versione dall'ultimo movimento al primo (ordinamento `product_id` crescente, `id` decrescente).
- Rettifica (`StockAdjustmentResource`): in meno con `StockPicking`, in più nel secchio 0.
- Scelta a mano del fornitore sulla riga dello scarico (G3.12): **col piano 3**, insieme alla scelta del lotto, nello stesso componente della riga.

### 2. Valore del magazzino
- `StockValue::of(array $buckets): array{value, pieces, uncosted}` pura: secchi sopra zero, pezzi × costo; i pezzi senza costo a parte.
- `LevelsSql::value()`: la stessa regola in SQL per la colonna **Valore** delle *Giacenze* (con `purchasing`), ordinabile.
- Riquadro della home **Valore del magazzino** (`StockValueWidget`, con `purchasing`): totale, per sede con più sedi, «N pezzi senza costo».

### 3. Pagine
- Bozza del carico: **Fornitore** (tendina `Contacts::supplierOptions()`), **Documento del fornitore** e **Data**; nell'editor la colonna **Costo** (riempita dal costo dell'opzione per quel fornitore, quando c'è) e il totale del documento.
- Scheda del carico: fornitore, documento, costo e totale per riga, totale del documento.
- *Carichi*: colonna e filtro **Fornitore**.
- *Movimenti*: colonna e filtro **Fornitore**, costo nella scheda del movimento.
- Scheda della versione, riquadro *Magazzino*: giacenza per fornitore.

### 4. Chiusura
- Guida commerciante `magazzino-acquisti.md` (in `SUMMARY.md`), ritocchi a *Carichi e scarichi*, *Giacenze*, *Movimenti*; guida sviluppatori; CHANGELOG; TODO.
- Dati di prova: con `purchasing` accesa un carico da un fornitore di prova con i costi.

## Test
- Puri: `StockDocumentRulesTest` (costo e testata), `StockValueTest`.
- Integrazione: carico con fornitore e costi (secchio, costo sul movimento, legame creato/aggiornato, totale); compensazione e annullo del carico che la contiene; scarico diviso fra due fornitori e annullo; rettifica in meno ripartita; a `purchasing` spenta tutto come prima.

## Review Focus
1. Annullo di un carico compensato: gli storni vanno in ordine inverso, altrimenti il `purchase` stornato non trova i pezzi.
2. Costo vuoto ≠ costo zero: un costo non scritto non aggiorna la scheda dell'opzione.
3. Inerzia a `purchasing` spenta, anche con dati di fornitore già nel database.
