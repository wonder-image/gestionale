# G2b-bis piano 6 — Inventario, pagine

> Piano breve: la spec è già approvata (`docs/superpowers/specs/2026-09-23-giacenze-movimenti-documenti-design.md`, §4.2 *Inventario*, §5) e il servizio dell'inventario c'è dal piano 4. Si appoggia su tutto quello che il piano 5 ha fatto per carichi e scarichi: qui si scrive solo la differenza.

**Ramo:** `g3-inventario`, worktree `worktrees/g3-inventario`. Sito di prova `boilerplates/ecommerce-site-inventario` (come nel piano 5, Passi 1-3: vendor copiato, worktree del boilerplate, link Herd `ecommerce-inventario`, `WI_TEST_SITE`/`WI_TEST_URL`; prima di ogni suite d'integrazione controllare che non ne giri un'altra).

## Cosa c'è già

- `StockDocuments` crea, salva, conferma, annulla e duplica l'inventario; `expected_quantity` si legge al salvataggio del contato e si rilegge cambiando sede (test del piano 4, compresa la vendita fra salvataggio e conferma).
- `StockDocumentRules`: conferma con almeno una riga contata, movimento = contati − attesi, limite 500.
- Rotta `stock.versions`, editor `wiStockEditor`, pagine `form`/`show`, filtro *Tipo* dei Movimenti con `doc_stocktake`.
- In Giacenze la vecchia modifica in blocco non c'è più: niente da togliere.

## Task

### 1. Resource *Inventari*
- `src/Resources/Stock/StockStocktakeResource.php` come `StockIssueResource`: `stocktake`, `app/gestionale/inventari`, ordine 40, *Inventari* / *Inventario* / «Nuovo inventario» / «Conferma inventario», guida `magazzino/magazzino-inventario`.
- `StockDocumentResource::classFor()` con `stocktake` (così `documentUrlSql()` dei Movimenti trova la scheda).
- Elenco: negli inventari *Contate* e *Con differenza* al posto di *Pezzi* (sottoquery in `select()`: `quantity IS NOT NULL`; `quantity IS NOT NULL AND ROUND(quantity, 3) <> ROUND(expected_quantity, 3)`).
- `confirmQuestion()` dell'inventario con segnaposti `{contate}` e `{differenza}`; la pagina sostituisce ogni `{chiave}` con quello che le dà l'editor. Singolare/plurale: «1 riga contata», «1 con differenza», «quella versione»; senza differenze: «12 righe contate, nessuna differenza: la giacenza non cambia. Dopo non si modifica più: si può solo annullare.»
- `StockMovementResource`: `'stocktake' => 'Visualizza inventario'` nel menu ⋯.
- `ConventionsTest`: la classe nuova nell'elenco.

### 2. Editor in modo inventario
- `StockDocumentForm::editorLines()` passa anche `expected` (l'atteso salvato) e `counted` (il contato salvato, testo) delle righe.
- `StockDocumentEditor::render($lines, $searchUrl, $type)`: con `stocktake` le colonne Articolo · Attesi · Contati · Differenza · cestino; su telefono restano Articolo e Contati.
- JS, stesse regole di `StockDocumentRules::expected()`: *Attesi* è quello salvato finché la casella ha il contato salvato e la sede è la stessa; altrimenti la giacenza di adesso (`stock`). *Differenza* = contati − attesi, verde/rossa/«—». Cambiando sede tutte le righe ripartono dalla giacenza nuova.
- Lettore: +1 su *Contati* (vuoto → 1). Clic su un risultato: riga con *Contati* vuoto. Lo zero scritto è valido.
- Totale: «40 righe · 12 contate · 3 con differenza»; `count()` dà `rows`, `counted`, `differing` per la domanda.

### 3. *Aggiungi versioni*
- `StockVersionSearch::scope(string $scope, int $id, int $locationId)`: `all`, `category` (sottocategorie con `CategoryTree::descendants()`, via `ProductModelCategory`) o `brand` (`brand_id` del modello). Entrano le versioni in vendita e le ferme con pezzi nella sede; mai multiprodotti né cancellate. Prima conta: oltre 500 torna `{count, ids, versions: []}`, altrimenti `{count, ids, versions}`.
- Rotta: parametri `scope` e `scope_id` sulla stessa `api/stock/versions.php`.
- Pagina: sotto la casella, una riga compatta con un `<select>` (Tutte le versioni · Categorie · Marchi, categorie indentate) e il bottone **Aggiungi versioni**. Righe nuove con *Contati* vuoto, mai due volte la stessa versione. Se pagina + nuove > 500 non si aggiunge niente: «Sarebbero 812 righe: un documento ne tiene al massimo 500. Conta per categorie o per marchi.» Se non ce n'è nessuna nuova lo dice.
- Costo noto: le foto si leggono una query per modello (`ProductPhotos`, con cache per richiesta).

### 4. Scheda in sola lettura
- `StockDocumentSheet::for()` per l'inventario: righe con `expected`, `counted`, `difference` (testo e segno) e totale come l'editor; riga non contata: «—» nelle tre celle.
- `view/pages/stock-document-show.php`: colonne Attesi · Contati · Differenza per l'inventario.

### 5. Dati di prova
- `StockDocumentsDemo::create()`: dopo carico e scarico, un inventario in bozza (codice `inventario`) con due righe: una contata con una differenza, una non contata. `clear()` lo toglie già (bozza: niente annullamento).

### 6. Guide e chiusura
- `docs/user/magazzino-inventario.md` (nuova, in `SUMMARY.md` dopo *Carichi e scarichi*): contare, *Aggiungi versioni*, attesi e differenza, un magazzino grande per categorie, salvare la bozza mentre si conta, conferma e annullamento. Link da `magazzino-giacenze.md` dove serve.
- `docs/dev/concetti/magazzino.md`: le tre Resource, il modo inventario dell'editor, `scope`.
- CHANGELOG; TODO: piano 5 unito (#27), piano 6 fatto, G2b-bis chiuso.

## Test
- `StockDocumentListTest`: colonne *Contate* / *Con differenza*.
- `StockDocumentEditorTest`: colonne dell'inventario, righe con `expected`/`counted`, totale dell'inventario.
- `StockDocumentFormTest`: `editorLines()` con l'atteso; invio e conferma di un inventario.
- `StockVersionSearchTest`: `scope` — sottocategorie, marchio, ferme con pezzi sì e senza pezzi no, multiprodotti no, oltre 500 solo il conteggio.
- `StockDocumentsDemoTest`: la bozza d'inventario nasce e se ne va.
- `StockMovementResourceTest` / `StockMovementBackendTest`: *Visualizza inventario*.
- Prova nel browser 5 della spec (all'utente): un inventario di una categoria muove solo le righe contate con una differenza.

## Fine
PR con merge commit su `main`, poi ramo, worktree e sito di prova eliminati.
