# G3 piano 1 — Dati, ripartizione e vendita

> Piano breve: la spec è approvata (`docs/superpowers/specs/2026-10-12-magazzino-avanzato-design.md`, §1 e §2, decisioni G3.1–G3.6, G3.11, G3.22). Qui si scrive solo come si arriva dal codice di oggi a quello della spec.

**Ramo:** `g3-piano-1`, da `g3-magazzino-avanzato` con dentro `fix-sede-ritiro` (`Locations::forSale()`/`forReturn()`). Nell'ecommerce un ramo con lo stesso nome per la sola riga della vetrina.

**Vincolo che decide tutto (D63):** a interruttori spenti niente cambia. `batch_tracking` resta `created => false` fino al piano 3, quindi nessun lotto può esistere né scadere; `purchasing` spenta vuol dire costi a zero sui movimenti. Con i soli secchi 0 ogni scarico è un movimento solo, come oggi.

## Cosa c'è già

- `Stock::apply()` scrive un secchio `(prodotto, sede, lotto, fornitore)`; `Allocation` passa già lotto e fornitore (sempre 0).
- `Locations::forSale()` (la principale) e `forReturn()` (sede del reso) dal ramo `fix-sede-ritiro`.
- `Codes::BATCH` = `bat_` c'è già. I Model si registrano da soli da `src/Models` (`module.json`).
- `Lifecycle` passa `order_item_id` a `commit()` e `restore()`; `Returns` lo ha (`$good['order_item_id']`) ma non lo passa a `returnGoods()`.

## Task

### 1. Dati
- `Models\Stock\Batch` (`gst_batches`): `code`, `product_id` (FK), `number` (50), `expiry_date` (DATE, NULL), `supplier_id` (0), `note`; unico `(product_id, number)`, indice su `expiry_date`; `syncSchema()` null.
- `StockMovement`: `reference_item_id` INT 0, indice `(reference_type, reference_id, reference_item_id)`. `Stock::apply()` lo scrive se arriva.
- `StockDocumentItem`: `batch_number` (50), `batch_expiry` (DATE).
- `ProductModel`: `track_batches` enum `true`/`false`, predefinito `false` (nessun campo nel form fino al piano 3).
- `Setting`: `batch_expiry_days` INT 30, nell'`exclude` del sync come gli altri dati del commerciante.
- `StockDocument::STATUSES` con `in_transit` (etichetta *In viaggio*).
- `Reasons`: `batch_assignment` («Assegnazione lotto») in `all()`; `Reasons::internal()` la elenca, e le scelte di rettifica e scarico (`forDocument()`, select della rettifica) non la mostrano.
- `StockDocuments::confirm()` passa `reference_item_id` = id della riga a ogni movimento.

### 2. `StockPicking` (pura)
`split(array $buckets, float $quantity, array $options): array{parts, missing}` come spec §1.3: secchi sopra zero; lotto 0 prima, poi scadenza crescente (senza scadenza in fondo), dentro il lotto fornitore 0, poi costo crescente (sconosciuto in fondo), poi `batch_id`, `supplier_id`. `use_batches` e `use_suppliers` spenti ignorano scadenze e costi; scaduti fuori salvo `include_expired` o lotto scelto; `batch_id`/`supplier_id` restringono. Quantità a tre decimali.

### 3. `StockCosts` (pura) e `StockBuckets`
- `StockCosts::of(int $supplierId, array $supplierCosts): ?float` (costo del fornitore, o il più basso col fornitore 0, o null); `StockCosts::forProduct(int $productId): array<int supplier, float>` legge `gst_product_suppliers`.
- `StockBuckets::locked(int $productId, int $locationId, ?string $today = null): array{buckets, quantity, sellable}`: righe di `gst_stock` con `FOR UPDATE`, scadenza dai lotti (solo `batch_id > 0` e solo con `batch_tracking`), costo con `purchasing` accesa; `quantity` = somma della sede, `sellable` = senza i lotti scaduti.

### 4. Vendita in `Allocation`
- `commit()`: pre-controllo `order.reservation_lost` come oggi ma su `sellable`; poi `StockPicking::split()` e un `Stock::apply()` per parte (`sale`, `reference_item_id`, `unit_cost` del secchio o 0); `missing` in un ultimo `apply()` sul secchio 0 con `allow_negative` alle condizioni di oggi. `before`/`after` della risposta = sede intera (prima e dopo); `oversold` e avviso come oggi; in più `parts`.
- `reserve()`: disponibile su `sellable` invece della somma.
- `restore()` e `returnGoods()`: `StockMovements::netOut(int $orderItemId)` somma per secchio `sale` meno `sale_cancel` e `return` con quel `reference_item_id`, in ordine di uscita; il rientro riempie dal secchio uscito per ultimo; quello che resta (movimenti di prima di G3, o senza riga) va nel secchio 0. Un `apply()` per secchio, con `reference_item_id`.
- `Returns::register()` passa `order_item_id` a `returnGoods()`.

### 5. Disponibile per la vendita
- `Levels::forSale(array $productIds, int $locationId): array<int, {quantity, expired, reserved, available}>` sulla sola sede; `expired` dai lotti scaduti (zero senza `batch_tracking`); `available` = `Availability::of(quantity − expired, prenotazioni della sede)`.
- `Levels::perLocation()` e `forProducts()` non cambiano (le *Giacenze* restano su tutte le sedi; `LevelsSql` con gli scaduti arriva col piano 3).
- `Cart`: le tre letture (`Levels::of()` due volte, `forProducts()` nei multiprodotti) passano a `Levels::forSale(..., Locations::forSale())`.
- Ecommerce, `ProductCatalog`: la somma di `gst_stock` diventa `Levels::forSale([$id], Locations::forSale())[$id]`, con `quantity − expired > 0` al posto di `quantity > 0`.

### 6. Guide e chiusura
- `docs/dev/concetti/magazzino.md`: secchi e secchio 0, `StockPicking`, `StockBuckets`, `StockCosts`, `reference_item_id`, `Levels::forSale()`.
- CHANGELOG (con «chi aggiorna lancia `php forge update`»); TODO: piano 1 fatto.

## Test
- Nuovi, puri: `StockPickingTest` (ordine, lotto 0 prima, scadenze, costi, scaduti, scelta a mano, `missing`, interruttori spenti, **inerzia**: soli secchi 0 → una parte sola), `StockCostsTest`.
- `StockModelsTest`: `gst_batches`, `reference_item_id`, colonne delle righe; `StockReasonsTest`: `batch_assignment` interna; `FeatureCatalogTest` invariato (`batch_tracking` ancora non costruita).
- Integrazione (sul sito di prova): `AllocationTest` — vendita divisa su due fornitori con `purchasing` accesa e il suo annullo per secchio; reso parziale in ordine inverso; movimenti senza `reference_item_id` che rientrano nel secchio 0; `ReturnsTest` con `order_item_id`; `CartTest` — una seconda sede con merce non rende disponibile l'articolo online. **Tutta la suite di oggi verde a interruttori spenti, senza ritoccare i test.**

## Review Focus
1. Inerzia: con i soli secchi 0, `commit()` scrive un movimento solo con gli stessi `before`/`after` di oggi e `unit_cost` 0.
2. Lock: `StockBuckets::locked()` blocca tutte le righe di prodotto e sede **prima** delle prenotazioni, come `lockedQuantity()` oggi.
3. Reso di un ordine diviso: la somma rientrata non supera mai quello che la riga aveva tolto da ciascun secchio.
4. `missing` mai scritto in un secchio con lotto: il buco va sempre nel secchio 0.

## Fine
PR del gestionale e dell'ecommerce su `main`, a interruttori spenti.
