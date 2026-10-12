# Documenti di magazzino, dati e servizio (G2b-bis piano 4) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Carico, Scarico e Inventario come documenti con bozza, conferma, annullamento e duplica, senza pagine; nei Movimenti il documento e i suoi storni dentro *Tipo* e nel filtro *Tipo*.

**Architecture:** tre tabelle nuove (`gst_stock_documents`, `gst_stock_document_items`, `gst_stock_document_status_logs`) con i loro Model; le regole pure in `Support\Stock\StockDocumentRules` (provate senza database); il servizio `Support\Stock\StockDocuments` fa tutto dentro `Transaction::run` e scrive le giacenze solo con `Stock::apply()`. La Resource dei Movimenti legge il documento con `one()` (una lettura per documento per richiesta, come per l'ordine).

**Tech Stack:** PHP 8.2, framework Wonder Image (`wonder-image/app`), modulo `wonder-image/gestionale`, MySQL; test con l'harness del modulo (`check()`, `summary()`).

**Spec:** `docs/superpowers/specs/2026-09-23-giacenze-movimenti-documenti-design.md` — §3 (Movimenti, *Tipo* e filtro *Tipo*), §4.1 (dati, stati e movimenti), §5.1 (guide per lo sviluppatore), §5.3 (test), §5.5 piano 4.

## Global Constraints

- Tabelle con prefisso del modulo: `gst_stock_documents`, `gst_stock_document_items`, `gst_stock_document_status_logs`; Model in `src/Models/Stock/`, `$folder = 'gestionale/stock'`, `syncSchema()` torna `null`.
- Tipi: `receipt` (*Carico*), `issue` (*Scarico*), `stocktake` (*Inventario*); l'enum della colonna nasce con `transfer`, che arriva con G3 e il servizio rifiuta.
- Stati: `draft` (*Bozza*), `completed` (*Confermato*), `cancelled` (*Annullato*); ogni passaggio scrive una riga nel log con `StatusLogger`.
- Colonne di G3 nate subito e lasciate vuote: `to_location_id`, `supplier_id`, `supplier_document_number`, `supplier_document_date`, `total_cost` sul documento; `batch_id`, `supplier_id`, `unit_cost`, `line_total` sulle righe.
- `quantity` è sempre il numero scritto sulla riga, senza segno; in un inventario una riga non contata ha `quantity` **e** `expected_quantity` vuote (NULL, non zero).
- Una versione compare una volta sola per documento; al massimo **500 righe**.
- Causale solo sullo scarico, obbligatoria alla conferma: *Danneggiato*, *Scaduto*, *Regalo*, *Uso interno*, *Altro*. Il carico non ne ha; l'inventario usa sempre `inventory`.
- Il numero arriva alla conferma: `DocumentSequences::next()` con le sequenze `stock_receipt`, `stock_issue`, `stock_stocktake`; `date` è il giorno della conferma.
- Movimenti: Carico → `purchase`, pezzi in più; Scarico → `adjustment` con la causale del documento, pezzi in meno; Inventario → `adjustment` con `inventory`, *contati − attesi*, nessun movimento se zero o non contato. Tutti con `reference_type = 'stock_document'`, `reference_id` = documento, la sede del documento, `user_id` = chi conferma.
- Annullamento solo da `completed`: uno storno per movimento, stesso tipo e causale, segno opposto, `reference_type = 'stock_document_cancel'`. Se lo storno andrebbe sotto zero si ferma con `stock.insufficient` e il documento resta confermato.
- `user_id` del documento = chi lo crea; chi conferma e chi annulla stanno nel log.
- Nei Movimenti *Tipo* legge «Scarico 2026/090003» o «Annullamento del carico 2026/090003». Il filtro *Tipo*: *Carico*, *Scarico*, *Inventario* guardano il documento (storni compresi); *Rettifica* = `adjustment` senza documento.
- Niente pagine in questo piano: le voci ⋯ *Visualizza carico/scarico/inventario* e i link al documento arrivano con i piani 5 e 6.
- Testi d'errore in `lang/it/gestionale.json` → `gestionale.errors.stock_document.*`; mai testo scritto nel codice.
- Lingua dei commenti, dei test e dei messaggi di commit: italiano. Trailer dei commit: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Non spingere** niente e non toccare `packages/gestionale` (lì un'altra chat lavora a reCAPTCHA): si lavora solo nel worktree `/Users/andreamarinoni/Developer/worktrees/g3-documenti-magazzino`.

## Review Focus

1. **Bozza salvata due volte con lo stesso numero contato** — l'atteso non deve rileggersi: una vendita fatta fra il salvataggio e la conferma resta. Coperto nel Task 3 («l'atteso resta quello del primo salvataggio») e nel Task 4 («una vendita fra salvataggio e conferma resta»).
2. **Quantità scritte alla maniera italiana o sporche** («2,5», «1.234,5», «12 pz», «abc», «-3», un array) — le prime tre si leggono, le altre sono `invalid_quantity` con il numero di riga. Coperto nel Task 2.
3. **Due invii della stessa azione** (conferma o annullamento cliccati due volte) — il secondo è un rifiuto chiaro, nessun numero né movimento in più. Coperto nei Task 4 e 5.
4. **Riga senza prodotto in mezzo al form** (riga vuota lasciata dalla pagina) — si ignora, non diventa un errore né una riga salvata. Coperto nel Task 2.
5. **Movimenti vecchi `purchase` senza documento** (dati di prova, test, rettifiche passate) — nel filtro *Tipo* stanno sotto *Carico*, come dice la loro cella. Coperto nel Task 7.

---

## Preparazione (una volta, prima del Task 1)

Il worktree esiste già sul ramo `g3-documenti-magazzino`. I test d'integrazione girano contro un sito di prova parallelo che usa il gestionale del worktree; `app` ed `ecommerce` restano quelli di `packages`.

- [ ] **Passo 1: vendor del worktree**

```bash
P=/Users/andreamarinoni/Developer/packages; W=/Users/andreamarinoni/Developer/worktrees/g3-documenti-magazzino
cp -R $P/gestionale/vendor $W/gestionale/ && ln -sfn $P/app $W/gestionale/vendor/wonder-image/app
php $W/gestionale/tests/StockReasonsTest.php
```

Il collegamento si rifà perché quello copiato è relativo (`../../../app/`) e dal worktree non punta più al core. Atteso: test verdi.

- [ ] **Passo 2: sito di prova parallelo**

Segui la ricetta di `docs/superpowers/plans/2026-10-08-pannello-account-1-core.md` (righe 99-112) con questi nomi:

```bash
B=/Users/andreamarinoni/Developer/boilerplates/ecommerce-site; S=/Users/andreamarinoni/Developer/boilerplates/ecommerce-site-g3
git -C $B worktree add --detach $S
```

poi l'`rsync` della ricetta (`.env`, `.htaccess`, `assets/…`, `bin`, `demo`, `handler`, `storage`, `vendor`, `custom/config/modules.php`, `custom/config/modules`, `shared/sync-data.json`) da `$B` a `$S`, e infine:

```bash
rm $S/vendor/wonder-image/gestionale && ln -s $W/gestionale $S/vendor/wonder-image/gestionale
export WI_TEST_SITE=$S
php $W/gestionale/tests/integrazione/StockTest.php
```

Atteso: test verdi. Herd e `APP_URL` non servono: questo piano non ha pagine.

- [ ] **Passo 3: regola per tutto il piano**

Il database `ecommerce_site` è condiviso con il sito dell'altra chat. Prima di ogni test d'integrazione:

```bash
ps aux | grep "php .*Test.php" | grep -v grep
```

Se c'è un'altra suite in corsa, aspetta: due suite insieme si rompono a vicenda («SAVEPOINT wi_sp_1 does not exist»).

---

### Task 1: Model e tabelle

**Files:**
- Create: `src/Models/Stock/StockDocument.php`
- Create: `src/Models/Stock/StockDocumentItem.php`
- Create: `src/Models/Stock/StockDocumentStatusLog.php`
- Create: `tests/StockDocumentModelsTest.php`
- Modify: `tests/integrazione/DefaultsTest.php:55-57` (svuotare le tabelle nuove prima delle sedi)

**Interfaces:**
- Produces: `StockDocument::$table = 'gst_stock_documents'`, `StockDocument::TYPES = ['receipt', 'issue', 'stocktake', 'transfer']`, `StockDocument::STATUSES = ['draft', 'completed', 'cancelled']`; `StockDocumentItem::$table = 'gst_stock_document_items'`; `StockDocumentStatusLog` con `entityColumn() === 'stock_document_id'`.

- [ ] **Passo 1: il test che fallisce**

`tests/StockDocumentModelsTest.php`:

```php
<?php
/** php tests/StockDocumentModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentStatusLog;
use Wonder\Plugin\Gestionale\Support\Codes;

$colonne = static function (string $model): array {
    $colonne = [];

    foreach ($model::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

$campo = static function (string $model, string $key): ?object {
    foreach ($model::dataSchema() as $field) {
        if ((string) $field->key === $key) {
            return $field;
        }
    }

    return null;
};

check('le tre tabelle dei documenti hanno il prefisso del gestionale', fn () =>
    StockDocument::$table === 'gst_stock_documents'
    && StockDocumentItem::$table === 'gst_stock_document_items'
    && StockDocumentStatusLog::$table === 'gst_stock_document_status_logs'
);

check('i documenti non viaggiano con il deploy', fn () =>
    StockDocument::syncSchema() === null
    && StockDocumentItem::syncSchema() === null
    && StockDocumentStatusLog::syncSchema() === null
);

check('il documento ha il suo prefisso di codice', fn () =>
    ($campo(StockDocument::class, 'code')?->getSchema('unique_code')['prefix'] ?? null) === Codes::STOCK_DOCUMENT
);

check('tipi e stati nascono completi, trasferimento compreso', fn () =>
    StockDocument::TYPES === ['receipt', 'issue', 'stocktake', 'transfer']
    && StockDocument::STATUSES === ['draft', 'completed', 'cancelled']
);

check('la testata tiene tutte le colonne di 4.1, anche quelle di G3', function () use ($colonne) {
    $attese = [
        'code', 'type', 'number', 'date', 'location_id', 'to_location_id',
        'supplier_id', 'supplier_document_number', 'supplier_document_date',
        'reason', 'status', 'total_cost', 'note', 'user_id',
    ];

    return array_diff($attese, array_keys($colonne(StockDocument::class))) === [];
});

check('la sede è obbligatoria e legata alle sedi; sede d\'arrivo e fornitore no', function () use ($colonne) {
    $c = $colonne(StockDocument::class);

    return $c['location_id']->getSchema('foreign_table') === 'gst_locations'
        && $c['location_id']->getSchema('null') === false
        && $c['to_location_id']->getSchema('foreign_table') === null
        && $c['supplier_id']->getSchema('foreign_table') === null
        && $c['user_id']->getSchema('foreign_table') === null;
});

check('la riga tiene quantità, atteso, lotto, fornitore, costi e posizione', function () use ($colonne) {
    $attese = [
        'stock_document_id', 'product_id', 'batch_id', 'supplier_id',
        'quantity', 'expected_quantity', 'unit_cost', 'line_total', 'position',
    ];

    return array_diff($attese, array_keys($colonne(StockDocumentItem::class))) === [];
});

check('quantità e atteso hanno tre decimali e possono restare vuoti', function () use ($colonne) {
    $c = $colonne(StockDocumentItem::class);

    return $c['quantity']->getSchema('length') === '10,3'
        && $c['quantity']->getSchema('null') === true
        && $c['expected_quantity']->getSchema('length') === '10,3'
        && $c['expected_quantity']->getSchema('null') === true
        && $c['unit_cost']->getSchema('length') === '10,4';
});

check('la riga è legata al documento e alla versione', function () use ($colonne) {
    $c = $colonne(StockDocumentItem::class);

    return $c['stock_document_id']->getSchema('foreign_table') === 'gst_stock_documents'
        && $c['product_id']->getSchema('foreign_table') === 'gst_products'
        && $c['batch_id']->getSchema('foreign_table') === null;
});

check('il log punta al documento e porta le colonne comuni', function () use ($colonne) {
    $c = $colonne(StockDocumentStatusLog::class);

    return StockDocumentStatusLog::entityColumn() === 'stock_document_id'
        && StockDocumentStatusLog::entityTable() === 'gst_stock_documents'
        && $c['stock_document_id']->getSchema('foreign_table') === 'gst_stock_documents'
        && isset($c['field'], $c['from_value'], $c['to_value'], $c['source'], $c['response']);
});

summary();
```

- [ ] **Passo 2: verifica che fallisca**

Run: `php tests/StockDocumentModelsTest.php`
Expected: FAIL con `Class "Wonder\Plugin\Gestionale\Models\Stock\StockDocument" not found`.

- [ ] **Passo 3: i tre Model**

`src/Models/Stock/StockDocument.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Stock;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Il documento di magazzino: carico, scarico, inventario (G2b-bis 4.1).
 *
 * L'enum dei tipi nasce con `transfer`, che arriva con G3: così G3 non tocca
 * una tabella piena. Anche le colonne di G3 nascono subito e restano vuote —
 * sede d'arrivo, fornitore, documento del fornitore, costo totale.
 *
 * `number` e `date` arrivano alla conferma: una bozza eliminata non lascia
 * buchi nella numerazione. `user_id` è chi ha creato il documento; chi lo
 * conferma e chi lo annulla stanno nel log degli stati.
 */
final class StockDocument extends Model
{
    public static string $table = 'gst_stock_documents';
    public static string $folder = 'gestionale/stock';
    public static string $icon = 'bi bi-file-earmark-text';

    public const TYPES = ['receipt', 'issue', 'stocktake', 'transfer'];

    public const STATUSES = ['draft', 'completed', 'cancelled'];

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('type')->enum(static::TYPES)->default('receipt'),
            Column::key('number')->length(20),
            Column::key('date')->date(),
            Column::key('location_id')->int()->null(false)->foreign(Location::$table),
            // G3: sede d'arrivo del trasferimento e fornitore del carico, senza chiave esterna.
            Column::key('to_location_id')->int()->default(0),
            Column::key('supplier_id')->int()->default(0),
            Column::key('supplier_document_number')->length(50),
            Column::key('supplier_document_date')->date(),
            Column::key('reason')->length(30),
            Column::key('status')->enum(static::STATUSES)->default('draft'),
            Columns::decimal('total_cost', '10,2'),
            Column::key('note')->type('TEXT'),
            Column::key('user_id')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_type' => ['index' => 'type'],
            'ind_status' => ['index' => 'status'],
            'ind_number' => ['index' => 'number'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::STOCK_DOCUMENT),
            Field::key('type')->text()->sanitize(false),
            Field::key('number')->text()->sanitize(false),
            Field::key('date')->date(),
            Field::key('location_id')->number()->decimals(0),
            Field::key('to_location_id')->number()->decimals(0),
            Field::key('supplier_id')->number()->decimals(0),
            Field::key('supplier_document_number')->text(),
            Field::key('supplier_document_date')->date(),
            Field::key('reason')->text()->sanitize(false),
            Field::key('status')->text()->sanitize(false),
            Field::key('total_cost')->number()->decimals(2),
            Field::key('note')->text(),
            Field::key('user_id')->number()->decimals(0),
        ];
    }
}
```

`src/Models/Stock/StockDocumentItem.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Stock;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Le righe del documento di magazzino (G2b-bis 4.1).
 *
 * `quantity` è il numero scritto sulla riga — pezzi caricati, scaricati o
 * contati — senza segno: il verso lo dà il tipo di documento. In un
 * inventario una riga non ancora contata ha `quantity` vuota, e
 * `expected_quantity` pure: casella vuota non vuol dire zero.
 *
 * Lotto, fornitore, costo e totale di riga restano vuoti finché G3 non li usa.
 */
final class StockDocumentItem extends Model
{
    public static string $table = 'gst_stock_document_items';
    public static string $folder = 'gestionale/stock';
    public static string $icon = 'bi bi-list-ul';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('stock_document_id')->int()->null(false)->foreign(StockDocument::$table),
            Column::key('product_id')->int()->null(false)->foreign(Product::$table),
            Column::key('batch_id')->int()->default(0),
            Column::key('supplier_id')->int()->default(0),
            Columns::decimal('quantity')->null(true),
            Columns::decimal('expected_quantity')->null(true),
            Columns::decimal('unit_cost', '10,4'),
            Columns::decimal('line_total', '10,2'),
            Column::key('position')->int()->default(0),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_document' => ['index' => 'stock_document_id'],
            'ind_product' => ['index' => 'product_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('stock_document_id')->number()->decimals(0),
            Field::key('product_id')->number()->decimals(0),
            Field::key('batch_id')->number()->decimals(0),
            Field::key('supplier_id')->number()->decimals(0),
            Field::key('quantity')->number()->decimals(3),
            Field::key('expected_quantity')->number()->decimals(3),
            Field::key('unit_cost')->number()->decimals(4),
            Field::key('line_total')->number()->decimals(2),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
```

`src/Models/Stock/StockDocumentStatusLog.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Stock;

use Wonder\Plugin\Gestionale\Models\System\StatusLog;

/** La storia degli stati di un documento di magazzino (4.1). */
final class StockDocumentStatusLog extends StatusLog
{
    public static string $table = 'gst_stock_document_status_logs';
    public static string $folder = 'gestionale/stock';

    public static function entityColumn(): string
    {
        return 'stock_document_id';
    }

    public static function entityTable(): string
    {
        return StockDocument::$table;
    }
}
```

- [ ] **Passo 4: verifica che passi**

Run: `php tests/StockDocumentModelsTest.php && php tests/ConventionsTest.php`
Expected: PASS tutti e due (`ConventionsTest` controlla il prefisso `gst_` dei Model proprietari).

- [ ] **Passo 5: DefaultsTest svuota anche i documenti**

In `tests/integrazione/DefaultsTest.php`, subito dopo le tre righe dei resi (righe 55-57) e prima di `StockThreshold`, aggiungi:

```php
        // E per i documenti di magazzino, che hanno la loro sede.
        sqlDelete(\Wonder\Plugin\Gestionale\Models\Stock\StockDocumentStatusLog::$table);
        sqlDelete(\Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem::$table);
        sqlDelete(\Wonder\Plugin\Gestionale\Models\Stock\StockDocument::$table);
```

- [ ] **Passo 6: le tabelle nel sito di prova**

```bash
cd $S && php forge update --local
```

Expected: crea `gst_stock_documents`, `gst_stock_document_items`, `gst_stock_document_status_logs`. Poi `php $W/gestionale/tests/integrazione/DefaultsTest.php` verde.

- [ ] **Passo 7: commit**

```bash
git add src/Models/Stock/StockDocument.php src/Models/Stock/StockDocumentItem.php src/Models/Stock/StockDocumentStatusLog.php tests/StockDocumentModelsTest.php tests/integrazione/DefaultsTest.php
git commit -m "Documenti di magazzino: tabelle, Model e log degli stati

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Regole pure e testi d'errore

**Files:**
- Modify: `src/Support/Stock/Reasons.php` (aggiungere `forDocument()`)
- Create: `src/Support/Stock/StockDocumentRules.php`
- Modify: `lang/it/gestionale.json` (gruppo `stock_document` dopo `stock`)
- Modify: `tests/StockReasonsTest.php` (prove di `forDocument`, prima di `summary();`)
- Create: `tests/StockDocumentRulesTest.php`

**Interfaces:**
- Consumes: `Reasons::all()`, `Reasons::DEFAULT`, `Stocktake::quantity(mixed): ?float`, `UserError::make()`.
- Produces:
  - `Reasons::forDocument(string $type): array<string, string>`
  - `StockDocumentRules::MAX_LINES = 500`, `TYPES = ['receipt', 'issue', 'stocktake']`
  - `labels(): array<string, string>`, `exists(string $type): bool`, `label(string $type): string`, `cancelLabel(string $type): string`, `sequence(string $type): string`
  - `reason(string $type, string $reason): string` — la causale da salvare; lancia `stock_document.unknown_reason`
  - `lines(array $input): list<array{product_id: int, quantity: ?float}>` — lancia `invalid_quantity`, `duplicate_product`, `too_many_rows`
  - `assertConfirmable(string $type, string $reason, array $lines): void` — lancia `reason_required`, `no_rows`, `nothing_counted`, `quantity_required`
  - `movement(string $type, string $reason, array $line): ?array{type: string, reason: string, quantity: float}`
  - `reversal(array $movement): array{type: string, reason: string, quantity: float}`
  - `expected(?array $old, ?float $counted, bool $locationChanged, float $current): ?float`

- [ ] **Passo 1: i test che falliscono**

In fondo a `tests/StockReasonsTest.php`, prima di `summary();`:

```php
check('lo scarico sceglie fra cinque causali, nell\'ordine della tendina', fn () =>
    Reasons::forDocument('issue') === [
        'damaged' => 'Danneggiato',
        'expired' => 'Scaduto',
        'gift' => 'Regalo',
        'internal_use' => 'Uso interno',
        'other' => 'Altro',
    ]
);

check('l\'inventario ha solo la sua causale, il carico nessuna', fn () =>
    Reasons::forDocument('stocktake') === ['inventory' => 'Inventario']
    && Reasons::forDocument('receipt') === []
    && Reasons::forDocument('transfer') === []
);
```

`tests/StockDocumentRulesTest.php`:

```php
<?php
/** php tests/StockDocumentRulesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentRules as Regole;

/** La chiave del rifiuto, o null se non c'è stato. */
$rifiuto = static function (callable $fn): ?string {
    try {
        $fn();
    } catch (UserError $errore) {
        return $errore->key();
    }

    return null;
};

$riga = static fn (int $prodotto, mixed $pezzi): array => ['product_id' => $prodotto, 'quantity' => $pezzi];

check('tre tipi, con etichetta, storno e sequenza', fn () =>
    Regole::TYPES === ['receipt', 'issue', 'stocktake']
    && Regole::label('receipt') === 'Carico'
    && Regole::label('issue') === 'Scarico'
    && Regole::label('stocktake') === 'Inventario'
    && Regole::cancelLabel('receipt') === 'Annullamento del carico'
    && Regole::cancelLabel('issue') === 'Annullamento dello scarico'
    && Regole::cancelLabel('stocktake') === "Annullamento dell'inventario"
    && Regole::sequence('issue') === 'stock_issue'
    && !Regole::exists('transfer')
    && Regole::label('transfer') === ''
);

check('causale: lo scarico tiene la sua, vuota finché è bozza', fn () =>
    Regole::reason('issue', 'damaged') === 'damaged'
    && Regole::reason('issue', '  ') === ''
);

check('causale: l\'inventario usa sempre «inventory», il carico nessuna', fn () =>
    Regole::reason('stocktake', '') === 'inventory'
    && Regole::reason('stocktake', 'gift') === 'inventory'
    && Regole::reason('receipt', 'damaged') === ''
);

check('causale: una che non va con lo scarico è un rifiuto', fn () =>
    $rifiuto(fn () => Regole::reason('issue', 'initial_stock')) === 'stock_document.unknown_reason'
    && $rifiuto(fn () => Regole::reason('issue', 'inventory')) === 'stock_document.unknown_reason'
);

check('righe: i numeri all\'italiana si leggono, la casella vuota resta vuota', fn () =>
    Regole::lines([$riga(4, '2,5'), $riga(5, '1.234,5'), $riga(6, '12 pz'), $riga(7, ''), $riga(8, null), $riga(9, 3)]) === [
        ['product_id' => 4, 'quantity' => 2.5],
        ['product_id' => 5, 'quantity' => 1234.5],
        ['product_id' => 6, 'quantity' => 12.0],
        ['product_id' => 7, 'quantity' => null],
        ['product_id' => 8, 'quantity' => null],
        ['product_id' => 9, 'quantity' => 3.0],
    ]
);

check('righe: una riga senza versione si ignora', fn () =>
    Regole::lines([$riga(0, '3'), ['quantity' => '2'], $riga(4, '1')]) === [['product_id' => 4, 'quantity' => 1.0]]
);

check('righe: un numero che non si legge o negativo è un rifiuto con la riga', function () use ($rifiuto, $riga) {
    foreach (['abc', '-3', '--3', [1]] as $brutto) {
        if ($rifiuto(fn () => Regole::lines([$riga(4, '1'), $riga(5, $brutto)])) !== 'stock_document.invalid_quantity') {
            return false;
        }
    }

    try {
        Regole::lines([$riga(4, '1'), $riga(5, 'abc')]);
    } catch (UserError $errore) {
        return str_contains($errore->getMessage(), '2');
    }

    return false;
});

check('righe: la stessa versione due volte è un rifiuto', fn () =>
    $rifiuto(fn () => Regole::lines([$riga(4, '1'), $riga(5, '1'), $riga(4, '2')])) === 'stock_document.duplicate_product'
);

check('righe: 500 passano, 501 no', function () use ($rifiuto, $riga) {
    $righe = array_map(static fn (int $i): array => $riga($i, '1'), range(1, 500));

    return count(Regole::lines($righe)) === 500
        && $rifiuto(fn () => Regole::lines([...$righe, $riga(501, '1')])) === 'stock_document.too_many_rows';
});

check('conferma: lo scarico chiede la causale', fn () =>
    $rifiuto(fn () => Regole::assertConfirmable('issue', '', [$riga(4, 1.0)])) === 'stock_document.reason_required'
    && $rifiuto(fn () => Regole::assertConfirmable('issue', 'gift', [$riga(4, 1.0)])) === null
);

check('conferma: almeno una riga', fn () =>
    $rifiuto(fn () => Regole::assertConfirmable('receipt', '', [])) === 'stock_document.no_rows'
    && $rifiuto(fn () => Regole::assertConfirmable('stocktake', 'inventory', [])) === 'stock_document.no_rows'
);

check('conferma: carico e scarico vogliono pezzi maggiori di zero su ogni riga', fn () =>
    $rifiuto(fn () => Regole::assertConfirmable('receipt', '', [$riga(4, 2.0), $riga(5, 0.0)])) === 'stock_document.quantity_required'
    && $rifiuto(fn () => Regole::assertConfirmable('receipt', '', [$riga(4, 2.0), $riga(5, null)])) === 'stock_document.quantity_required'
    && $rifiuto(fn () => Regole::assertConfirmable('receipt', '', [$riga(4, 2.0)])) === null
);

check('conferma: l\'inventario vuole almeno una riga contata, anche a zero', fn () =>
    $rifiuto(fn () => Regole::assertConfirmable('stocktake', 'inventory', [$riga(4, null), $riga(5, null)])) === 'stock_document.nothing_counted'
    && $rifiuto(fn () => Regole::assertConfirmable('stocktake', 'inventory', [$riga(4, null), $riga(5, 0.0)])) === null
);

check('movimento: il carico aggiunge, lo scarico toglie con la sua causale', fn () =>
    Regole::movement('receipt', '', ['quantity' => 5.0]) === ['type' => 'purchase', 'reason' => '', 'quantity' => 5.0]
    && Regole::movement('issue', 'damaged', ['quantity' => 2.5]) === ['type' => 'adjustment', 'reason' => 'damaged', 'quantity' => -2.5]
);

check('movimento: l\'inventario muove contati − attesi, in tutte e due le direzioni', fn () =>
    Regole::movement('stocktake', 'inventory', ['quantity' => 7.0, 'expected_quantity' => 10.0]) === ['type' => 'adjustment', 'reason' => 'inventory', 'quantity' => -3.0]
    && Regole::movement('stocktake', 'inventory', ['quantity' => 12.5, 'expected_quantity' => 10.0]) === ['type' => 'adjustment', 'reason' => 'inventory', 'quantity' => 2.5]
);

check('movimento: riga non contata o senza differenza non muove niente', fn () =>
    Regole::movement('stocktake', 'inventory', ['quantity' => null, 'expected_quantity' => null]) === null
    && Regole::movement('stocktake', 'inventory', ['quantity' => 10.0, 'expected_quantity' => 10.0]) === null
    && Regole::movement('stocktake', 'inventory', ['quantity' => 0.1 + 0.2, 'expected_quantity' => 0.3]) === null
);

check('storno: stesso tipo e causale, segno opposto', fn () =>
    Regole::reversal(['type' => 'purchase', 'reason' => '', 'quantity' => '5.000']) === ['type' => 'purchase', 'reason' => '', 'quantity' => -5.0]
    && Regole::reversal(['type' => 'adjustment', 'reason' => 'inventory', 'quantity' => '-3.000']) === ['type' => 'adjustment', 'reason' => 'inventory', 'quantity' => 3.0]
    && Regole::reversal(['type' => 'adjustment', 'reason' => null, 'quantity' => '-1']) === ['type' => 'adjustment', 'reason' => '', 'quantity' => 1.0]
);

check('atteso: si legge quando il numero contato è nuovo o cambia', fn () =>
    Regole::expected(null, 5.0, false, 8.0) === 8.0
    && Regole::expected(['quantity' => null, 'expected_quantity' => null], 5.0, false, 8.0) === 8.0
    && Regole::expected(['quantity' => 4.0, 'expected_quantity' => 9.0], 5.0, false, 8.0) === 8.0
);

check('atteso: lo stesso numero tiene l\'atteso di prima', fn () =>
    Regole::expected(['quantity' => 5.0, 'expected_quantity' => 9.0], 5.0, false, 8.0) === 9.0
);

check('atteso: si rilegge quando cambia la sede', fn () =>
    Regole::expected(['quantity' => 5.0, 'expected_quantity' => 9.0], 5.0, true, 8.0) === 8.0
);

check('atteso: una riga non contata non ha atteso', fn () =>
    Regole::expected(['quantity' => 5.0, 'expected_quantity' => 9.0], null, false, 8.0) === null
);

summary();
```

- [ ] **Passo 2: verifica che falliscano**

Run: `php tests/StockReasonsTest.php; php tests/StockDocumentRulesTest.php`
Expected: FAIL con `Call to undefined method …Reasons::forDocument()` e `Class "…StockDocumentRules" not found`.

- [ ] **Passo 3: `Reasons::forDocument()`**

In `src/Support/Stock/Reasons.php`, dopo `label()`:

```php
    /**
     * Le causali che vanno con un documento di magazzino: lo scarico ne
     * sceglie una, l'inventario usa sempre la sua, il carico nessuna.
     *
     * @return array<string, string> nell'ordine della tendina
     */
    public static function forDocument(string $type): array
    {
        $keys = match ($type) {
            'issue' => ['damaged', 'expired', 'gift', 'internal_use', 'other'],
            'stocktake' => [self::DEFAULT],
            default => [],
        };

        return array_intersect_key(self::all(), array_flip($keys));
    }
```

- [ ] **Passo 4: `StockDocumentRules`**

`src/Support/Stock/StockDocumentRules.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * Le regole dei documenti di magazzino, senza database (G2b-bis 4.1).
 *
 * Il servizio `StockDocuments` legge e scrive; qui si decide: quali righe
 * sono buone, quando un documento si può confermare, quale movimento nasce da
 * ogni riga e quale storno lo annulla, quando l'atteso di un inventario si
 * rilegge.
 */
final class StockDocumentRules
{
    public const MAX_LINES = 500;

    /** I tipi che si creano oggi: `transfer` arriva con G3. */
    public const TYPES = ['receipt', 'issue', 'stocktake'];

    /** @return array<string, string> */
    public static function labels(): array
    {
        return ['receipt' => 'Carico', 'issue' => 'Scarico', 'stocktake' => 'Inventario'];
    }

    public static function exists(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /** L'etichetta, o stringa vuota per un tipo che non c'è. */
    public static function label(string $type): string
    {
        return self::labels()[$type] ?? '';
    }

    /** Come si legge nei Movimenti lo storno di un documento annullato. */
    public static function cancelLabel(string $type): string
    {
        return [
            'receipt' => 'Annullamento del carico',
            'issue' => 'Annullamento dello scarico',
            'stocktake' => "Annullamento dell'inventario",
        ][$type] ?? '';
    }

    /** La sequenza di `DocumentSequences`: una per tipo. */
    public static function sequence(string $type): string
    {
        return 'stock_'.$type;
    }

    /**
     * La causale da salvare sul documento. L'inventario ha sempre la sua, il
     * carico nessuna; lo scarico quella scelta, che può mancare finché è bozza.
     */
    public static function reason(string $type, string $reason): string
    {
        $allowed = Reasons::forDocument($type);

        if ($type !== 'issue') {
            return (string) array_key_first($allowed);
        }

        $reason = trim($reason);

        if ($reason !== '' && !array_key_exists($reason, $allowed)) {
            throw UserError::make('stock_document.unknown_reason');
        }

        return $reason;
    }

    /**
     * Le righe del form, controllate. Una riga senza versione si ignora (la
     * pagina può lasciarne una vuota); la casella vuota resta `null`, che non
     * vuol dire zero. `{{row}}` è la riga come la conta chi legge, da 1.
     *
     * @param list<array<string, mixed>> $input
     * @return list<array{product_id: int, quantity: ?float}>
     */
    public static function lines(array $input): array
    {
        $lines = [];
        $seen = [];
        $row = 0;

        foreach ($input as $line) {
            $row++;
            $productId = (int) ($line['product_id'] ?? 0);

            if ($productId <= 0) {
                continue;
            }

            if (isset($seen[$productId])) {
                throw UserError::make('stock_document.duplicate_product', ['row' => $row]);
            }

            $seen[$productId] = true;
            $lines[] = ['product_id' => $productId, 'quantity' => self::quantity($line['quantity'] ?? null, $row)];
        }

        if (count($lines) > self::MAX_LINES) {
            throw UserError::make('stock_document.too_many_rows');
        }

        return $lines;
    }

    /**
     * Quello che la conferma chiede. Righe numerate nell'ordine del documento.
     *
     * @param list<array{quantity: ?float}> $lines
     */
    public static function assertConfirmable(string $type, string $reason, array $lines): void
    {
        if ($type === 'issue' && trim($reason) === '') {
            throw UserError::make('stock_document.reason_required');
        }

        if ($lines === []) {
            throw UserError::make('stock_document.no_rows');
        }

        if ($type === 'stocktake') {
            foreach ($lines as $line) {
                if ($line['quantity'] !== null) {
                    return;
                }
            }

            throw UserError::make('stock_document.nothing_counted');
        }

        foreach (array_values($lines) as $i => $line) {
            if ($line['quantity'] === null || $line['quantity'] <= 0) {
                throw UserError::make('stock_document.quantity_required', ['row' => $i + 1]);
            }
        }
    }

    /**
     * Il movimento che nasce da una riga, o null se la riga non muove niente.
     * Per l'inventario `expected_quantity` c'è sempre quando c'è il contato:
     * lo garantisce il servizio.
     *
     * @param array{quantity: ?float, expected_quantity?: ?float} $line
     * @return array{type: string, reason: string, quantity: float}|null
     */
    public static function movement(string $type, string $reason, array $line): ?array
    {
        $quantity = $line['quantity'];

        if ($quantity === null) {
            return null;
        }

        $quantity = round($quantity, 3);

        return match ($type) {
            'receipt' => $quantity > 0 ? ['type' => 'purchase', 'reason' => '', 'quantity' => $quantity] : null,
            'issue' => $quantity > 0 ? ['type' => 'adjustment', 'reason' => $reason, 'quantity' => -$quantity] : null,
            'stocktake' => self::difference($quantity, (float) ($line['expected_quantity'] ?? 0)),
            default => null,
        };
    }

    /**
     * Lo storno di un movimento: stesso tipo, stessa causale, segno opposto.
     *
     * @param array<string, mixed> $movement una riga di `gst_stock_movements`
     * @return array{type: string, reason: string, quantity: float}
     */
    public static function reversal(array $movement): array
    {
        return [
            'type' => (string) $movement['type'],
            'reason' => (string) ($movement['reason'] ?? ''),
            'quantity' => -round((float) $movement['quantity'], 3),
        ];
    }

    /**
     * L'atteso di una riga d'inventario. Si legge la giacenza di adesso quando
     * il numero contato è nuovo o cambia, o quando cambia la sede; con lo
     * stesso numero resta quello di prima, così una vendita fatta fra il
     * salvataggio e la conferma resta.
     *
     * @param array{quantity: ?float, expected_quantity: ?float}|null $old la riga salvata prima
     */
    public static function expected(?array $old, ?float $counted, bool $locationChanged, float $current): ?float
    {
        if ($counted === null) {
            return null;
        }

        if ($old === null || $locationChanged || $old['quantity'] === null || $old['expected_quantity'] === null
            || round((float) $old['quantity'], 3) !== round($counted, 3)) {
            return round($current, 3);
        }

        return round((float) $old['expected_quantity'], 3);
    }

    /** @return array{type: string, reason: string, quantity: float}|null */
    private static function difference(float $counted, float $expected): ?array
    {
        $difference = round($counted - $expected, 3);

        return $difference === 0.0 || $difference === -0.0
            ? null
            : ['type' => 'adjustment', 'reason' => Reasons::DEFAULT, 'quantity' => $difference];
    }

    private static function quantity(mixed $value, int $row): ?float
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $quantity = Stocktake::quantity($value);

        if ($quantity === null || $quantity < 0) {
            throw UserError::make('stock_document.invalid_quantity', ['row' => $row]);
        }

        return $quantity;
    }
}
```

- [ ] **Passo 5: i testi d'errore**

In `lang/it/gestionale.json`, dentro `gestionale.errors`, subito dopo il gruppo `"stock": { … },`:

```json
            "stock_document": {
                "not_found": "Questo documento di magazzino non esiste più.",
                "not_draft": "Questo documento è già stato confermato o annullato: non si modifica più. Per correggerlo annullalo e duplicalo.",
                "cannot_cancel": "Si annulla solo un documento confermato.",
                "cannot_delete": "Si elimina solo una bozza: un documento confermato si annulla.",
                "unknown_type": "Questo tipo di documento non c'è.",
                "reason_required": "Scegli la causale dello scarico.",
                "unknown_reason": "Questa causale non va con questo documento.",
                "no_rows": "Aggiungi almeno una riga.",
                "nothing_counted": "Scrivi il numero contato di almeno una riga.",
                "quantity_required": "La riga {{row}} non ha pezzi: scrivi un numero maggiore di zero.",
                "invalid_quantity": "La riga {{row}} ha un numero che non si legge: scrivi solo cifre, zero o più.",
                "duplicate_product": "La stessa versione compare due volte (riga {{row}}): tienine una sola.",
                "too_many_rows": "Un documento tiene al massimo 500 righe: dividilo in due.",
                "location_unknown": "La sede scelta non è più del magazzino: ricarica la pagina e riprova."
            },
```

Controlla che il file resti JSON valido: `php -r 'json_decode(file_get_contents("lang/it/gestionale.json"), flags: JSON_THROW_ON_ERROR); echo "ok\n";'`.

- [ ] **Passo 6: verifica che passino**

Run: `php tests/StockReasonsTest.php && php tests/StockDocumentRulesTest.php && php tests/ErrorKeysTest.php`
Expected: PASS. Se il controllo «numero sporco» con `0.1 + 0.2` fallisce, il motivo è il confronto fra float: la differenza va arrotondata prima del confronto, come fa `difference()`.

- [ ] **Passo 7: commit**

```bash
git add src/Support/Stock/Reasons.php src/Support/Stock/StockDocumentRules.php lang/it/gestionale.json tests/StockReasonsTest.php tests/StockDocumentRulesTest.php
git commit -m "Documenti di magazzino: regole pure, causali per documento e testi d'errore

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Il servizio — creare e salvare la bozza

**Files:**
- Create: `src/Support/Stock/StockDocuments.php`
- Create: `tests/integrazione/StockDocumentsTest.php`

**Interfaces:**
- Consumes: `StockDocumentRules::*` (Task 2), i Model del Task 1, `Levels::byLocationForProducts(array $ids): array<pid, array<locId, array{quantity: float, …}>>`, `Locations::mainId()`, `Code::make()`, `Codes::STOCK_DOCUMENT`.
- Produces:
  - `StockDocuments::create(string $type, array $header, array $lines, array $options = []): int` — `$header`: `location_id`, `reason`, `note`; `$options`: `user_id`
  - `StockDocuments::saveDraft(int $id, array $header, array $lines): void` — una chiave che manca in `$header` tiene il valore di prima; le righe si riscrivono tutte
  - `StockDocuments::items(int $id): list<array{id: int, product_id: int, quantity: ?float, expected_quantity: ?float, position: int}>`
  - Helper privati che i Task 4-6 riusano: `locked(int $id): array`, `draft(int $id): array`, `writeItems(...)`, `isStockLocation(int $id): bool`, `log(...)`, `rows(mixed): list<array>`.

- [ ] **Passo 1: il test che fallisce**

`tests/integrazione/StockDocumentsTest.php`:

```php
<?php
/** php tests/integrazione/StockDocumentsTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\App\Models\Config\SocietyLocation;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocuments;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** Esegue `$corpo` in una transazione che poi si annulla: il database resta com'era. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    Locations::reset();

    return $esito;
}

/** La chiave del rifiuto, o null se non c'è stato. */
function rifiuto(callable $fn): ?string
{
    try {
        $fn();
    } catch (UserError $errore) {
        return $errore->key();
    }

    return null;
}

/** @return list<array<string, mixed>> */
function righe(string $classe, string $where): array
{
    $r = $classe::find($where, null, 'id', 'ASC');

    if (!is_array($r) || $r === []) {
        return [];
    }

    return isset($r['id']) ? [$r] : array_values(array_filter($r, 'is_array'));
}

function articolo(float $pezzi = 10): int
{
    return articoloConGiacenza($pezzi, 'TST-DOC-'.substr((string) microtime(true), -6).random_int(10, 99));
}

function giacenza(int $prodotto, int $sede = 0): float
{
    $sede = $sede ?: Locations::mainId();

    return (float) (Levels::byLocationForProducts([$prodotto])[$prodotto][$sede]['quantity'] ?? 0);
}

/** Una seconda sede che tiene merce: la riga del core e quella del gestionale. */
function secondaSede(): int
{
    $core = SocietyLocation::create([
        'label' => 'Magazzino prova documenti',
        'slug' => Slug::make('magazzino-prova-documenti-'.uniqid()),
        'position' => 99,
        'visible' => 'true',
    ]);
    $riga = Location::create(['society_location_id' => (int) ($core->insert_id ?? 0), 'has_stock' => 'true', 'active' => 'true']);

    return (int) ($riga->insert_id ?? 0);
}

$utente = 1;

check('creare: la bozza nasce senza numero né data, con le sue righe in ordine', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        [$a, $b] = [articolo(), articolo()];
        $id = StockDocuments::create('receipt', ['note' => ' Merce di ottobre '], [
            ['product_id' => $b, 'quantity' => '3'],
            ['product_id' => 0, 'quantity' => ''],
            ['product_id' => $a, 'quantity' => '2,5'],
        ], ['user_id' => $utente]);
        $doc = StockDocument::findById($id);
        $righe = StockDocuments::items($id);

        return $doc['status'] === 'draft'
            && in_array($doc['number'] ?? null, [null, ''], true)
            && in_array($doc['date'] ?? null, [null, ''], true)
            && (int) $doc['location_id'] === Locations::mainId()
            && (int) $doc['user_id'] === $utente
            && $doc['note'] === 'Merce di ottobre'
            && str_starts_with((string) $doc['code'], 'stk_')
            && count($righe) === 2
            && $righe[0]['product_id'] === $b && $righe[0]['quantity'] === 3.0 && $righe[0]['position'] === 1
            && $righe[1]['product_id'] === $a && $righe[1]['quantity'] === 2.5 && $righe[1]['position'] === 2
            && $righe[0]['expected_quantity'] === null;
    });
});

check('creare: la bozza non muove la giacenza', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        StockDocuments::create('issue', ['reason' => 'damaged'], [['product_id' => $a, 'quantity' => '4']]);

        return giacenza($a) === 10.0;
    });
});

check('creare: un tipo che non c\'è, una sede sconosciuta, una versione sparita sono rifiuti', function () {
    return prova(static function (): bool {
        $a = articolo();

        return rifiuto(fn () => StockDocuments::create('transfer', [], [])) === 'stock_document.unknown_type'
            && rifiuto(fn () => StockDocuments::create('receipt', ['location_id' => 999999], [])) === 'stock_document.location_unknown'
            && rifiuto(fn () => StockDocuments::create('receipt', [], [['product_id' => 999999, 'quantity' => '1']])) === 'stock.product_missing'
            && rifiuto(fn () => StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '1'], ['product_id' => $a, 'quantity' => '2']])) === 'stock_document.duplicate_product';
    });
});

check('inventario: la riga non contata resta vuota, NULL e non zero, anche l\'atteso', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('stocktake', [], [['product_id' => $a, 'quantity' => '']]);
        $riga = righe(StockDocumentItem::class, 'stock_document_id = '.$id)[0] ?? [];
        $doc = StockDocument::findById($id);

        return array_key_exists('quantity', $riga) && $riga['quantity'] === null
            && $riga['expected_quantity'] === null
            && $doc['reason'] === 'inventory';
    });
});

check('inventario: l\'atteso è la giacenza della sede quando si salva il contato', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('stocktake', [], [['product_id' => $a, 'quantity' => '8']]);

        return StockDocuments::items($id)[0]['expected_quantity'] === 10.0;
    });
});

check('inventario: lo stesso contato salvato di nuovo tiene l\'atteso di prima', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('stocktake', [], [['product_id' => $a, 'quantity' => '8']]);
        Stock::apply(['product_id' => $a, 'quantity' => -2, 'type' => 'sale']);
        StockDocuments::saveDraft($id, [], [['product_id' => $a, 'quantity' => '8']]);
        $tenuto = StockDocuments::items($id)[0]['expected_quantity'];
        StockDocuments::saveDraft($id, [], [['product_id' => $a, 'quantity' => '7']]);

        return $tenuto === 10.0 && StockDocuments::items($id)[0]['expected_quantity'] === 8.0;
    });
});

check('inventario: cambiando sede l\'atteso si rilegge da quella sede', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $altra = secondaSede();
        Stock::apply(['product_id' => $a, 'quantity' => 4, 'type' => 'purchase', 'location_id' => $altra]);
        $id = StockDocuments::create('stocktake', [], [['product_id' => $a, 'quantity' => '9']]);
        $prima = StockDocuments::items($id)[0]['expected_quantity'];
        StockDocuments::saveDraft($id, ['location_id' => $altra], [['product_id' => $a, 'quantity' => '9']]);

        return $prima === 10.0
            && StockDocuments::items($id)[0]['expected_quantity'] === 4.0
            && (int) StockDocument::findById($id)['location_id'] === $altra;
    });
});

check('salvare: le chiavi che mancano tengono il valore di prima, le righe si riscrivono', function () {
    return prova(static function (): bool {
        [$a, $b] = [articolo(), articolo()];
        $id = StockDocuments::create('issue', ['reason' => 'gift', 'note' => 'Omaggi'], [['product_id' => $a, 'quantity' => '1']]);
        StockDocuments::saveDraft($id, ['note' => 'Omaggi fiera'], [['product_id' => $b, 'quantity' => '2']]);
        $doc = StockDocument::findById($id);
        $righe = StockDocuments::items($id);

        return $doc['reason'] === 'gift'
            && $doc['note'] === 'Omaggi fiera'
            && count($righe) === 1 && $righe[0]['product_id'] === $b && $righe[0]['quantity'] === 2.0;
    });
});

check('salvare: una causale che non va con lo scarico è un rifiuto e la bozza resta com\'era', function () {
    return prova(static function (): bool {
        $a = articolo();
        $id = StockDocuments::create('issue', ['reason' => 'gift'], [['product_id' => $a, 'quantity' => '1']]);

        return rifiuto(fn () => StockDocuments::saveDraft($id, ['reason' => 'initial_stock'], [])) === 'stock_document.unknown_reason'
            && StockDocument::findById($id)['reason'] === 'gift'
            && count(StockDocuments::items($id)) === 1;
    });
});

check('salvare: 501 righe sono un rifiuto', function () {
    return prova(static function (): bool {
        $righe = array_map(static fn (int $i): array => ['product_id' => $i, 'quantity' => '1'], range(1, 501));

        return rifiuto(fn () => StockDocuments::create('receipt', [], $righe)) === 'stock_document.too_many_rows';
    });
});

check('salvare: un documento che non c\'è è un rifiuto', fn () =>
    rifiuto(fn () => StockDocuments::saveDraft(999999999, [], [])) === 'stock_document.not_found'
);

summary();
```

- [ ] **Passo 2: verifica che fallisca**

Run: `php tests/integrazione/StockDocumentsTest.php`
Expected: FAIL con `Class "…StockDocuments" not found`.

- [ ] **Passo 3: il servizio, prima parte**

`src/Support/Stock/StockDocuments.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use RuntimeException;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentStatusLog;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Sql\Transaction;

/**
 * I documenti di magazzino: creare e salvare la bozza, confermare, annullare,
 * duplicare, eliminare (G2b-bis 4.1).
 *
 * Tutto dentro una transazione, con il documento bloccato: due conferme
 * contemporanee si mettono in fila e la seconda trova il documento già
 * confermato. Le giacenze si scrivono solo con `Stock::apply()`, una riga alla
 * volta: o entra tutto o niente. Le regole stanno in `StockDocumentRules`.
 */
final class StockDocuments
{
    /**
     * Crea una bozza.
     *
     * @param array{location_id?: int, reason?: string, note?: string} $header sede vuota = la principale
     * @param list<array{product_id: int, quantity: mixed}> $lines
     * @param array{user_id?: int} $options
     */
    public static function create(string $type, array $header, array $lines, array $options = []): int
    {
        if (!StockDocumentRules::exists($type)) {
            throw UserError::make('stock_document.unknown_type');
        }

        return Transaction::run(static function () use ($type, $header, $lines, $options): int {
            $locationId = self::location((int) ($header['location_id'] ?? 0));
            $reason = StockDocumentRules::reason($type, (string) ($header['reason'] ?? ''));
            $wanted = StockDocumentRules::lines($lines);

            $created = StockDocument::create([
                'code' => Code::make(StockDocument::class, Codes::STOCK_DOCUMENT),
                'type' => $type,
                'status' => 'draft',
                'location_id' => $locationId,
                'reason' => $reason,
                'note' => trim((string) ($header['note'] ?? '')),
                'user_id' => (int) ($options['user_id'] ?? 0),
            ]);
            $id = (int) ($created->insert_id ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('Documento di magazzino non creato: '.json_encode($created));
            }

            self::writeItems($id, $type, $locationId, $wanted, [], false);

            return $id;
        });
    }

    /**
     * Salva la bozza. Una chiave che manca in `$header` tiene il valore di
     * prima; le righe invece si riscrivono tutte, nell'ordine dato.
     *
     * @param array{location_id?: int, reason?: string, note?: string} $header
     * @param list<array{product_id: int, quantity: mixed}> $lines
     */
    public static function saveDraft(int $id, array $header, array $lines): void
    {
        Transaction::run(static function () use ($id, $header, $lines): void {
            $document = self::draft($id);
            $type = (string) $document['type'];
            $before = (int) $document['location_id'];
            $locationId = array_key_exists('location_id', $header) ? self::location((int) $header['location_id']) : $before;
            $reason = array_key_exists('reason', $header)
                ? StockDocumentRules::reason($type, (string) $header['reason'])
                : (string) ($document['reason'] ?? '');
            $note = array_key_exists('note', $header) ? trim((string) $header['note']) : (string) ($document['note'] ?? '');
            $wanted = StockDocumentRules::lines($lines);
            $old = [];

            foreach (self::items($id) as $item) {
                $old[$item['product_id']] = $item;
            }

            StockDocument::update(['location_id' => $locationId, 'reason' => $reason, 'note' => $note], $id);
            self::writeItems($id, $type, $locationId, $wanted, $old, $locationId !== $before);
        });
    }

    /**
     * Le righe del documento, nell'ordine.
     *
     * @return list<array{id: int, product_id: int, quantity: ?float, expected_quantity: ?float, position: int}>
     */
    public static function items(int $id): array
    {
        $rows = self::rows(StockDocumentItem::find(['stock_document_id' => $id, 'deleted' => 'false'], null, 'position', 'ASC'));

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'product_id' => (int) $row['product_id'],
            'quantity' => self::nullable($row['quantity'] ?? null),
            'expected_quantity' => self::nullable($row['expected_quantity'] ?? null),
            'position' => (int) $row['position'],
        ], $rows);
    }

    /**
     * Riscrive le righe. In un inventario l'atteso viene da `expected()`, con
     * la giacenza di adesso letta una volta sola per tutte le righe.
     *
     * @param list<array{product_id: int, quantity: ?float}> $lines già passate da `StockDocumentRules::lines()`
     * @param array<int, array{quantity: ?float, expected_quantity: ?float}> $old le righe di prima, per versione
     */
    private static function writeItems(int $id, string $type, int $locationId, array $lines, array $old, bool $locationChanged): void
    {
        $productIds = array_column($lines, 'product_id');
        self::assertProducts($productIds);
        $levels = $type === 'stocktake' ? Levels::byLocationForProducts($productIds) : [];

        sqlDelete(StockDocumentItem::$table, '`stock_document_id` = '.$id);

        foreach ($lines as $i => $line) {
            $expected = $type === 'stocktake'
                ? StockDocumentRules::expected(
                    $old[$line['product_id']] ?? null,
                    $line['quantity'],
                    $locationChanged,
                    (float) ($levels[$line['product_id']][$locationId]['quantity'] ?? 0)
                )
                : null;

            StockDocumentItem::create([
                'stock_document_id' => $id,
                'product_id' => $line['product_id'],
                'quantity' => self::decimal($line['quantity']),
                'expected_quantity' => self::decimal($expected),
                'position' => $i + 1,
            ]);
        }
    }

    /** @param list<int> $productIds */
    private static function assertProducts(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        $found = self::rows(Product::find(
            '`id` IN ('.implode(', ', array_map('intval', $productIds)).") AND `deleted` = 'false'",
            null, null, null, 'id'
        ));
        $known = array_map('intval', array_column($found, 'id'));

        foreach ($productIds as $productId) {
            if (!in_array($productId, $known, true)) {
                throw UserError::make('stock.product_missing');
            }
        }
    }

    /** La sede del documento: vuota = la principale; deve tenere merce. */
    private static function location(int $locationId): int
    {
        $locationId = $locationId ?: Locations::mainId();

        if (!self::isStockLocation($locationId)) {
            throw UserError::make('stock_document.location_unknown');
        }

        return $locationId;
    }

    private static function isStockLocation(int $locationId): bool
    {
        return $locationId > 0
            && self::rows(Location::find(['id' => $locationId, 'has_stock' => 'true', 'deleted' => 'false'], 1)) !== [];
    }

    /** Il documento, bloccato fino alla fine della transazione. */
    private static function locked(int $id): array
    {
        $document = StockDocument::findForUpdate(['id' => $id], 1);

        if (!is_array($document) || $document === [] || (string) ($document['deleted'] ?? 'false') === 'true') {
            throw UserError::make('stock_document.not_found');
        }

        return $document;
    }

    /** Il documento bloccato, purché sia ancora una bozza. */
    private static function draft(int $id): array
    {
        $document = self::locked($id);

        if ((string) $document['status'] !== 'draft') {
            throw UserError::make('stock_document.not_draft');
        }

        return $document;
    }

    private static function log(int $id, string $from, string $to, string $source, int $userId): void
    {
        StatusLogger::record(StockDocumentStatusLog::class, $id, 'status', $from, $to, $source, $userId ?: null);
    }

    private static function decimal(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 3, '.', '');
    }

    private static function nullable(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : round((float) $value, 3);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $result): array
    {
        if (!is_array($result) || $result === []) {
            return [];
        }

        return isset($result['id']) ? [$result] : array_values(array_filter($result, 'is_array'));
    }
}
```

`log()` e `isStockLocation()` si usano dai Task 4-6; finché non servono, un analizzatore può segnalarli come inutilizzati.

- [ ] **Passo 4: verifica che passi**

Run (controlla prima che non giri un'altra suite): `php tests/integrazione/StockDocumentsTest.php`
Expected: PASS. Se «la riga non contata resta vuota» fallisce con `quantity` = `0.000`, il Model trasforma `null` in zero: in `writeItems()` togli dalla chiamata a `create()` le chiavi che valgono `null` (la colonna nasce NULL) invece di passarle.

- [ ] **Passo 5: commit**

```bash
git add src/Support/Stock/StockDocuments.php tests/integrazione/StockDocumentsTest.php
git commit -m "Documenti di magazzino: creare e salvare la bozza, con gli attesi dell'inventario

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Conferma

**Files:**
- Modify: `src/Support/Stock/StockDocuments.php` (metodo `confirm`)
- Modify: `tests/integrazione/StockDocumentsTest.php` (prove prima di `summary();`)

**Interfaces:**
- Consumes: `StockDocumentRules::assertConfirmable()`, `movement()`, `sequence()`; `DocumentSequences::next(string $type, ?DateTimeInterface $at)`; `Stock::apply()`; helper del Task 3.
- Produces: `StockDocuments::confirm(int $id, array $options = []): string` — `$options`: `user_id`, `source` (default `user`); torna il numero.

- [ ] **Passo 1: i test che falliscono**

In `tests/integrazione/StockDocumentsTest.php`, prima di `summary();`:

```php
/** Il numero che viene dopo `$numero` nella stessa sequenza: «2026/100007» → «2026/100008». */
function seguente(string $numero): string
{
    return substr($numero, 0, 7).str_pad((string) ((int) substr($numero, 7) + 1), 4, '0', STR_PAD_LEFT);
}

check('confermare: numero, data, stato, log e movimenti con il documento e chi conferma', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        $a = articolo(10);
        $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '5']], ['user_id' => 0]);
        $numero = StockDocuments::confirm($id, ['user_id' => $utente]);
        $doc = StockDocument::findById($id);
        $mov = righe(StockMovement::class, "reference_type = 'stock_document' AND reference_id = ".$id);
        $log = righe(StockDocumentStatusLog::class, 'stock_document_id = '.$id);

        return preg_match('#^\d{4}/\d{6,}$#', $numero) === 1
            && $doc['number'] === $numero
            && $doc['status'] === 'completed'
            && $doc['date'] === date('Y-m-d')
            && count($mov) === 1
            && $mov[0]['type'] === 'purchase'
            && (float) $mov[0]['quantity'] === 5.0
            && (int) $mov[0]['location_id'] === Locations::mainId()
            && (int) $mov[0]['user_id'] === $utente
            && giacenza($a) === 15.0
            && count($log) === 1
            && $log[0]['from_value'] === 'draft' && $log[0]['to_value'] === 'completed'
            && (int) $log[0]['user_id'] === $utente;
    });
});

check('confermare: lo scarico toglie con la sua causale', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('issue', ['reason' => 'expired'], [['product_id' => $a, 'quantity' => '3']]);
        StockDocuments::confirm($id);
        $mov = righe(StockMovement::class, "reference_type = 'stock_document' AND reference_id = ".$id);

        return count($mov) === 1
            && $mov[0]['type'] === 'adjustment'
            && $mov[0]['reason'] === 'expired'
            && (float) $mov[0]['quantity'] === -3.0
            && giacenza($a) === 7.0;
    });
});

check('confermare: ogni tipo ha la sua sequenza, senza buchi dopo una conferma fermata', function () {
    return prova(static function (): bool {
        [$a, $b] = [articolo(10), articolo(1)];
        $primo = StockDocuments::confirm(StockDocuments::create('issue', ['reason' => 'gift'], [['product_id' => $a, 'quantity' => '1']]));
        $fermo = StockDocuments::create('issue', ['reason' => 'gift'], [['product_id' => $b, 'quantity' => '5']]);
        $errore = rifiuto(fn () => StockDocuments::confirm($fermo));
        $secondo = StockDocuments::confirm(StockDocuments::create('issue', ['reason' => 'gift'], [['product_id' => $a, 'quantity' => '1']]));
        $carico = StockDocuments::confirm(StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '1']]));

        return $errore === 'stock.insufficient'
            && $secondo === seguente($primo)
            && $carico !== '';
    });
});

check('confermare: fermata da stock.insufficient non lascia numero, movimenti né log', function () {
    return prova(static function (): bool {
        [$a, $b] = [articolo(10), articolo(1)];
        $id = StockDocuments::create('issue', ['reason' => 'damaged'], [
            ['product_id' => $a, 'quantity' => '2'],
            ['product_id' => $b, 'quantity' => '5'],
        ]);
        $errore = rifiuto(fn () => StockDocuments::confirm($id));
        $doc = StockDocument::findById($id);

        return $errore === 'stock.insufficient'
            && $doc['status'] === 'draft'
            && in_array($doc['number'] ?? null, [null, ''], true)
            && righe(StockMovement::class, "reference_type = 'stock_document' AND reference_id = ".$id) === []
            && righe(StockDocumentStatusLog::class, 'stock_document_id = '.$id) === []
            && count(StockDocuments::items($id)) === 2
            && giacenza($a) === 10.0;
    });
});

check('confermare: quello che manca è un rifiuto, il documento resta bozza', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $senzaCausale = StockDocuments::create('issue', [], [['product_id' => $a, 'quantity' => '1']]);
        $vuoto = StockDocuments::create('receipt', [], []);
        $aZero = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '0']]);
        $nonContato = StockDocuments::create('stocktake', [], [['product_id' => $a, 'quantity' => '']]);

        return rifiuto(fn () => StockDocuments::confirm($senzaCausale)) === 'stock_document.reason_required'
            && rifiuto(fn () => StockDocuments::confirm($vuoto)) === 'stock_document.no_rows'
            && rifiuto(fn () => StockDocuments::confirm($aZero)) === 'stock_document.quantity_required'
            && rifiuto(fn () => StockDocuments::confirm($nonContato)) === 'stock_document.nothing_counted'
            && StockDocument::findById($aZero)['status'] === 'draft';
    });
});

check('confermare due volte: la seconda è un rifiuto e non muove niente', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '5']]);
        $numero = StockDocuments::confirm($id);

        return rifiuto(fn () => StockDocuments::confirm($id)) === 'stock_document.not_draft'
            && StockDocument::findById($id)['number'] === $numero
            && count(righe(StockMovement::class, "reference_type = 'stock_document' AND reference_id = ".$id)) === 1
            && giacenza($a) === 15.0;
    });
});

check('confermato: non si salva più', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '5']]);
        StockDocuments::confirm($id);

        return rifiuto(fn () => StockDocuments::saveDraft($id, [], [['product_id' => $a, 'quantity' => '9']])) === 'stock_document.not_draft';
    });
});

check('inventario: muove solo le righe contate con una differenza', function () {
    return prova(static function (): bool {
        [$a, $b, $c] = [articolo(10), articolo(5), articolo(3)];
        $id = StockDocuments::create('stocktake', [], [
            ['product_id' => $a, 'quantity' => '7'],
            ['product_id' => $b, 'quantity' => '5'],
            ['product_id' => $c, 'quantity' => ''],
        ]);
        StockDocuments::confirm($id);
        $mov = righe(StockMovement::class, "reference_type = 'stock_document' AND reference_id = ".$id);

        return count($mov) === 1
            && (int) $mov[0]['product_id'] === $a
            && $mov[0]['reason'] === 'inventory'
            && (float) $mov[0]['quantity'] === -3.0
            && giacenza($a) === 7.0 && giacenza($b) === 5.0 && giacenza($c) === 3.0;
    });
});

check('inventario: una vendita fra il salvataggio della conta e la conferma resta', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('stocktake', [], [['product_id' => $a, 'quantity' => '8']]);
        Stock::apply(['product_id' => $a, 'quantity' => -2, 'type' => 'sale']);
        StockDocuments::confirm($id);

        // Contati 8 su attesi 10: −2 sopra la giacenza di adesso (8) → 6.
        return giacenza($a) === 6.0;
    });
});
```

- [ ] **Passo 2: verifica che falliscano**

Run: `php tests/integrazione/StockDocumentsTest.php`
Expected: FAIL con `Call to undefined method …StockDocuments::confirm()`.

- [ ] **Passo 3: `confirm()`**

In `src/Support/Stock/StockDocuments.php`, aggiungi `use DateTimeImmutable;` e `use Wonder\Plugin\Gestionale\Support\Documents\DocumentSequences;`, poi dopo `saveDraft()`:

```php
    /**
     * Conferma la bozza: numero, data, stato, log e un movimento per riga, in
     * una transazione sola. Uno scarico che porterebbe sotto zero si ferma con
     * `stock.insufficient` e la bozza resta com'era, senza numero.
     *
     * @param array{user_id?: int, source?: string} $options
     * @return string il numero del documento
     */
    public static function confirm(int $id, array $options = []): string
    {
        return Transaction::run(static function () use ($id, $options): string {
            $document = self::draft($id);
            $type = (string) $document['type'];
            $reason = (string) ($document['reason'] ?? '');
            $items = self::items($id);

            StockDocumentRules::assertConfirmable($type, $reason, $items);

            $userId = (int) ($options['user_id'] ?? 0);
            $source = StatusLogger::normalizeSource((string) ($options['source'] ?? 'user'));
            $now = new DateTimeImmutable();
            $number = DocumentSequences::next(StockDocumentRules::sequence($type), $now);

            foreach ($items as $item) {
                $movement = StockDocumentRules::movement($type, $reason, $item);

                if ($movement === null) {
                    continue;
                }

                Stock::apply($movement + [
                    'product_id' => $item['product_id'],
                    'location_id' => (int) $document['location_id'],
                    'reference_type' => 'stock_document',
                    'reference_id' => $id,
                    'user_id' => $userId,
                ]);
            }

            StockDocument::update(['status' => 'completed', 'number' => $number, 'date' => $now->format('Y-m-d')], $id);
            self::log($id, 'draft', 'completed', $source, $userId);

            return $number;
        });
    }
```

- [ ] **Passo 4: verifica che passino**

Run: `php tests/integrazione/StockDocumentsTest.php`
Expected: PASS. Se `$doc['date']` torna con l'ora («2026-10-12 00:00:00»), il campo `date()` del `dataSchema` scrive un DATETIME: controlla che la colonna sia `DATE` nel database (`SHOW COLUMNS FROM gst_stock_documents`) e, se sì, confronta con `substr($doc['date'], 0, 10)`.

- [ ] **Passo 5: commit**

```bash
git add src/Support/Stock/StockDocuments.php tests/integrazione/StockDocumentsTest.php
git commit -m "Documenti di magazzino: conferma con numero, log e movimenti

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Annullamento

**Files:**
- Modify: `src/Support/Stock/StockDocuments.php` (metodo `cancel`)
- Modify: `tests/integrazione/StockDocumentsTest.php`

**Interfaces:**
- Consumes: `StockDocumentRules::reversal()`, `Stock::apply()`, `locked()`, `log()`.
- Produces: `StockDocuments::cancel(int $id, array $options = []): void` — `$options`: `user_id`, `source`.

- [ ] **Passo 1: i test che falliscono**

Prima di `summary();`:

```php
check('annullare: uno storno per movimento, segno opposto, con il suo riferimento', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        [$a, $b] = [articolo(10), articolo(5)];
        $id = StockDocuments::create('stocktake', [], [
            ['product_id' => $a, 'quantity' => '7'],
            ['product_id' => $b, 'quantity' => '8'],
        ]);
        StockDocuments::confirm($id);
        StockDocuments::cancel($id, ['user_id' => $utente]);
        $storni = righe(StockMovement::class, "reference_type = 'stock_document_cancel' AND reference_id = ".$id);
        $log = righe(StockDocumentStatusLog::class, 'stock_document_id = '.$id);
        $perProdotto = array_column($storni, 'quantity', 'product_id');

        return count($storni) === 2
            && (float) $perProdotto[$a] === 3.0
            && (float) $perProdotto[$b] === -3.0
            && $storni[0]['type'] === 'adjustment' && $storni[0]['reason'] === 'inventory'
            && (int) $storni[0]['user_id'] === $utente
            && giacenza($a) === 10.0 && giacenza($b) === 5.0
            && StockDocument::findById($id)['status'] === 'cancelled'
            && end($log)['from_value'] === 'completed' && end($log)['to_value'] === 'cancelled';
    });
});

check('annullare: lo storno di un carico già venduto si ferma e il documento resta confermato', function () {
    return prova(static function (): bool {
        $a = articolo(0);
        $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '5']]);
        StockDocuments::confirm($id);
        Stock::apply(['product_id' => $a, 'quantity' => -4, 'type' => 'sale']);
        $errore = rifiuto(fn () => StockDocuments::cancel($id));

        return $errore === 'stock.insufficient'
            && StockDocument::findById($id)['status'] === 'completed'
            && righe(StockMovement::class, "reference_type = 'stock_document_cancel' AND reference_id = ".$id) === []
            && count(righe(StockDocumentStatusLog::class, 'stock_document_id = '.$id)) === 1
            && giacenza($a) === 1.0;
    });
});

check('annullare: solo un confermato, e una volta sola', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $bozza = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '5']]);
        $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '5']]);
        StockDocuments::confirm($id);
        StockDocuments::cancel($id);

        return rifiuto(fn () => StockDocuments::cancel($bozza)) === 'stock_document.cannot_cancel'
            && rifiuto(fn () => StockDocuments::cancel($id)) === 'stock_document.cannot_cancel'
            && count(righe(StockMovement::class, "reference_type = 'stock_document_cancel' AND reference_id = ".$id)) === 1
            && giacenza($a) === 10.0;
    });
});
```

- [ ] **Passo 2: verifica che falliscano**

Run: `php tests/integrazione/StockDocumentsTest.php`
Expected: FAIL con `Call to undefined method …StockDocuments::cancel()`.

- [ ] **Passo 3: `cancel()`**

Aggiungi `use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;`, poi dopo `confirm()`:

```php
    /**
     * Annulla un documento confermato: per ogni suo movimento uno storno con lo
     * stesso tipo e la stessa causale, segno opposto. Se uno storno porterebbe
     * sotto zero (i pezzi caricati sono già venduti) si ferma con
     * `stock.insufficient` e il documento resta confermato.
     *
     * @param array{user_id?: int, source?: string} $options
     */
    public static function cancel(int $id, array $options = []): void
    {
        Transaction::run(static function () use ($id, $options): void {
            $document = self::locked($id);

            if ((string) $document['status'] !== 'completed') {
                throw UserError::make('stock_document.cannot_cancel');
            }

            $userId = (int) ($options['user_id'] ?? 0);
            $source = StatusLogger::normalizeSource((string) ($options['source'] ?? 'user'));
            $movements = self::rows(StockMovement::find(
                "`reference_type` = 'stock_document' AND `reference_id` = ".$id." AND `deleted` = 'false'",
                null, 'id', 'ASC'
            ));

            foreach ($movements as $movement) {
                Stock::apply(StockDocumentRules::reversal($movement) + [
                    'product_id' => (int) $movement['product_id'],
                    'location_id' => (int) $movement['location_id'],
                    'batch_id' => (int) ($movement['batch_id'] ?? 0),
                    'supplier_id' => (int) ($movement['supplier_id'] ?? 0),
                    'reference_type' => 'stock_document_cancel',
                    'reference_id' => $id,
                    'user_id' => $userId,
                ]);
            }

            StockDocument::update(['status' => 'cancelled'], $id);
            self::log($id, 'completed', 'cancelled', $source, $userId);
        });
    }
```

- [ ] **Passo 4: verifica che passino**

Run: `php tests/integrazione/StockDocumentsTest.php`
Expected: PASS.

- [ ] **Passo 5: commit**

```bash
git add src/Support/Stock/StockDocuments.php tests/integrazione/StockDocumentsTest.php
git commit -m "Documenti di magazzino: annullamento con gli storni

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Duplica ed elimina

**Files:**
- Modify: `src/Support/Stock/StockDocuments.php` (metodi `duplicate`, `assertDeletable`, `delete`)
- Modify: `tests/integrazione/StockDocumentsTest.php`

**Interfaces:**
- Consumes: `writeItems()`, `isStockLocation()`, `locked()`, `items()`.
- Produces:
  - `StockDocuments::duplicate(int $id, array $options = []): int` — una bozza nuova dello stesso tipo; `$options`: `user_id`
  - `StockDocuments::assertDeletable(int $id): void` — lancia `UserError::refusal()` (un `RuntimeException`, per `api/backend/delete` del piano 5)
  - `StockDocuments::delete(int $id): void` — elimina davvero righe e documento

- [ ] **Passo 1: i test che falliscono**

Prima di `summary();`:

```php
/** Il messaggio del rifiuto per l'endpoint che cancella, o null se non c'è stato. */
function rifiutoDiCancellazione(callable $fn): ?string
{
    try {
        $fn();
    } catch (RuntimeException $errore) {
        return $errore->getMessage();
    }

    return null;
}

check('duplicare: una bozza nuova con sede, causale, nota e righe; senza numero', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        [$a, $b] = [articolo(10), articolo(10)];
        $id = StockDocuments::create('issue', ['reason' => 'internal_use', 'note' => 'Vetrina'], [
            ['product_id' => $a, 'quantity' => '2'],
            ['product_id' => $b, 'quantity' => '1'],
        ]);
        StockDocuments::confirm($id);
        StockDocuments::cancel($id);
        $copia = StockDocuments::duplicate($id, ['user_id' => $utente]);
        $doc = StockDocument::findById($copia);
        $righe = StockDocuments::items($copia);

        return $copia !== $id
            && $doc['type'] === 'issue' && $doc['status'] === 'draft'
            && in_array($doc['number'] ?? null, [null, ''], true)
            && $doc['reason'] === 'internal_use' && $doc['note'] === 'Vetrina'
            && (int) $doc['user_id'] === $utente
            && array_column($righe, 'product_id') === [$a, $b]
            && array_column($righe, 'quantity') === [2.0, 1.0];
    });
});

check('duplicare: l\'inventario rilegge gli attesi di adesso', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('stocktake', [], [['product_id' => $a, 'quantity' => '8']]);
        Stock::apply(['product_id' => $a, 'quantity' => -3, 'type' => 'sale']);
        $copia = StockDocuments::duplicate($id);

        return StockDocuments::items($id)[0]['expected_quantity'] === 10.0
            && StockDocuments::items($copia)[0]['expected_quantity'] === 7.0
            && StockDocuments::items($copia)[0]['quantity'] === 8.0;
    });
});

check('duplicare: le versioni sparite si saltano', function () {
    return prova(static function (): bool {
        [$a, $b] = [articolo(10), articolo(10)];
        $id = StockDocuments::create('receipt', [], [
            ['product_id' => $a, 'quantity' => '2'],
            ['product_id' => $b, 'quantity' => '1'],
        ]);
        \Wonder\Plugin\Gestionale\Models\Catalog\Product::update(['deleted' => 'true'], $b);
        $copia = StockDocuments::duplicate($id);

        return array_column(StockDocuments::items($copia), 'product_id') === [$a];
    });
});

check('eliminare: una bozza sparisce con le sue righe', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '2']]);
        StockDocuments::assertDeletable($id);
        StockDocuments::delete($id);

        return StockDocument::findById($id) === [] || StockDocument::findById($id) === null
            ? righe(StockDocumentItem::class, 'stock_document_id = '.$id) === []
            : false;
    });
});

check('eliminare: un confermato o un annullato no, e lo dice', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '2']]);
        StockDocuments::confirm($id);
        $testo = UserError::make('stock_document.cannot_delete')->getMessage();
        $primo = rifiutoDiCancellazione(fn () => StockDocuments::assertDeletable($id));
        StockDocuments::cancel($id);

        return $primo === $testo
            && rifiutoDiCancellazione(fn () => StockDocuments::assertDeletable($id)) === $testo
            && rifiuto(fn () => StockDocuments::delete($id)) === 'stock_document.cannot_delete'
            && StockDocument::findById($id)['status'] === 'cancelled';
    });
});

check('eliminare: un documento che non c\'è è un rifiuto', fn () =>
    rifiutoDiCancellazione(fn () => StockDocuments::assertDeletable(999999999))
        === UserError::make('stock_document.not_found')->getMessage()
);
```

Se `Product::update(['deleted' => 'true'], …)` viene rifiutato dalla validazione del Model, usa `sqlModify(Product::$table, ['deleted' => 'true'], 'id', $b)`.

- [ ] **Passo 2: verifica che falliscano**

Run: `php tests/integrazione/StockDocumentsTest.php`
Expected: FAIL con `Call to undefined method …StockDocuments::duplicate()`.

- [ ] **Passo 3: i tre metodi**

Dopo `cancel()`:

```php
    /**
     * Una bozza nuova dello stesso tipo, da un documento in qualsiasi stato:
     * sede, causale, nota e righe. Le versioni sparite si saltano; la sede, se
     * non tiene più merce, diventa la principale. In un inventario gli attesi
     * si rileggono adesso.
     *
     * @param array{user_id?: int} $options
     */
    public static function duplicate(int $id, array $options = []): int
    {
        return Transaction::run(static function () use ($id, $options): int {
            $document = self::locked($id);
            $type = (string) $document['type'];
            $items = self::items($id);
            $alive = self::rows(Product::find(
                '`id` IN ('.implode(', ', array_merge([0], array_column($items, 'product_id'))).") AND `deleted` = 'false'",
                null, null, null, 'id'
            ));
            $kept = array_map('intval', array_column($alive, 'id'));
            $lines = [];

            foreach ($items as $item) {
                if (in_array($item['product_id'], $kept, true)) {
                    $lines[] = ['product_id' => $item['product_id'], 'quantity' => $item['quantity']];
                }
            }

            $locationId = (int) $document['location_id'];

            return self::create($type, [
                'location_id' => self::isStockLocation($locationId) ? $locationId : 0,
                'reason' => (string) ($document['reason'] ?? ''),
                'note' => (string) ($document['note'] ?? ''),
            ], $lines, $options);
        });
    }

    /**
     * Il controllo dell'endpoint che cancella (piano 5): un `RuntimeException`,
     * che `api/backend/delete` trasforma nel messaggio.
     */
    public static function assertDeletable(int $id): void
    {
        $document = StockDocument::findById($id);

        if (!is_array($document) || !isset($document['id']) || (string) ($document['deleted'] ?? 'false') === 'true') {
            throw UserError::refusal('stock_document.not_found');
        }

        if ((string) $document['status'] !== 'draft') {
            throw UserError::refusal('stock_document.cannot_delete');
        }
    }

    /** Elimina una bozza con le sue righe. Una bozza non ha log: la creazione non si registra. */
    public static function delete(int $id): void
    {
        Transaction::run(static function () use ($id): void {
            $document = self::locked($id);

            if ((string) $document['status'] !== 'draft') {
                throw UserError::make('stock_document.cannot_delete');
            }

            sqlDelete(StockDocumentItem::$table, '`stock_document_id` = '.$id);
            StockDocument::delete($id);
        });
    }
```

`StockDocumentRules::lines()` accetta `quantity` già numerica (`2.0`) o `null`: la duplica passa le righe salvate così come sono.

- [ ] **Passo 4: verifica che passino**

Run: `php tests/integrazione/StockDocumentsTest.php`
Expected: PASS.

- [ ] **Passo 5: commit**

```bash
git add src/Support/Stock/StockDocuments.php tests/integrazione/StockDocumentsTest.php
git commit -m "Documenti di magazzino: duplica ed elimina la bozza

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Movimenti — documento e storni dentro *Tipo*, filtro *Tipo*

**Files:**
- Modify: `src/Resources/Stock/StockMovementResource.php` — riga 112 (formatter di *Tipo*), riga 199 (filtro), `typeCondition()` (244-249), `typeCell()` (307-318), `documentOf()` (325-336)
- Modify: `tests/StockMovementResourceTest.php` — prove del filtro alle righe 208-220
- Modify: `tests/integrazione/StockDocumentsTest.php` — la condizione contro il database e la cella

**Interfaces:**
- Consumes: `StockDocumentRules::labels()`, `label()`, `cancelLabel()`, `exists()`; `StockDocument::$table`; `StockDocuments` (per le prove).
- Produces:
  - `StockMovementResource::typeCell(array $row, string $number, string $url, string $label = ''): string` — `$label` vuota = l'etichetta del tipo
  - `StockMovementResource::typeCellOf(array $row): string` — la cella con il documento letto dal database (la usa il formatter)
  - `StockMovementResource::typeOptions(): array<string, string>`
  - `StockMovementResource::typeCondition(array $values): string`

- [ ] **Passo 1: i test che falliscono**

In `tests/StockMovementResourceTest.php` sostituisci le due prove del filtro *Tipo* (righe 208-220) con:

```php
check('il filtro Tipo offre i documenti al posto del carico, con «Tutti»', function () use ($filtro) {
    $f = $filtro('tipo');

    return $f !== null
        && array_keys((array) $f['array']) === [
            '', 'sale', 'sale_cancel', 'return', 'doc_receipt', 'doc_issue', 'doc_stocktake',
            'adjustment', 'transfer_in', 'transfer_out',
        ]
        && $f['array']['doc_receipt'] === 'Carico'
        && $f['array']['doc_issue'] === 'Scarico'
        && $f['array']['doc_stocktake'] === 'Inventario'
        && is_callable($f['where']);
});

check('il filtro Tipo: un tipo semplice è il suo tipo', function () use ($filtro) {
    return $filtro('tipo')['where'](['sale']) === "`type` = 'sale'";
});

check('il filtro Tipo: Scarico guarda il documento, storni compresi', function () use ($filtro) {
    $sql = $filtro('tipo')['where'](['doc_issue']);

    return str_contains($sql, "`reference_type` IN ('stock_document', 'stock_document_cancel')")
        && str_contains($sql, "FROM `gst_stock_documents` WHERE `type` = 'issue'");
});

check('il filtro Tipo: Carico prende anche i carichi senza documento', function () use ($filtro) {
    $sql = $filtro('tipo')['where'](['doc_receipt']);

    return str_contains($sql, "`type` = 'receipt'") && str_contains($sql, "`type` = 'purchase' AND");
});

check('il filtro Tipo: Rettifica è una rettifica senza documento', function () use ($filtro) {
    $sql = $filtro('tipo')['where'](['adjustment']);

    return str_starts_with($sql, "(`type` = 'adjustment' AND (`reference_type` IS NULL OR `reference_type` NOT IN");
});

check('il filtro Tipo: più voci vanno in OR fra parentesi', function () use ($filtro) {
    $sql = $filtro('tipo')['where'](['sale', 'doc_issue']);

    return str_starts_with($sql, "(`type` = 'sale' OR (") && str_ends_with($sql, ')');
});

check('il filtro Tipo non fa passare niente che non sia una voce', function () use ($filtro) {
    $dove = $filtro('tipo')['where'];

    return $dove(["sale') OR 1=1 --"]) === ''
        && $dove(["doc_issue') OR 1=1 --"]) === ''
        && $dove(['doc_transfer']) === ''
        && $dove(['purchase']) === ''
        && $dove([]) === ''
        && $dove(['']) === '';
});

check('Tipo con un documento: l\'etichetta del documento, non quella del tipo registrato', function () {
    $html = StockMovementResource::typeCell(['type' => 'adjustment'], '2026/100003', '', 'Scarico');

    return $html === 'Scarico · 2026/100003';
});

check('Tipo con uno storno: «Annullamento del carico» col numero', function () {
    $html = StockMovementResource::typeCell(['type' => 'purchase'], '2026/100001', '', 'Annullamento del carico');

    return $html === 'Annullamento del carico · 2026/100001';
});
```

Le prove di `typeCell` alle righe 146-164 restano com'erano: il quarto parametro è facoltativo.

In `tests/integrazione/StockDocumentsTest.php` aggiungi `use Wonder\Plugin\Gestionale\Resources\Stock\StockMovementResource;` e, prima di `summary();`:

```php
check('Movimenti: la cella Tipo legge il documento e lo storno', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $id = StockDocuments::create('issue', ['reason' => 'damaged'], [['product_id' => $a, 'quantity' => '1']]);
        $numero = StockDocuments::confirm($id);
        StockDocuments::cancel($id);
        $mov = righe(StockMovement::class, "reference_type = 'stock_document' AND reference_id = ".$id)[0];
        $storno = righe(StockMovement::class, "reference_type = 'stock_document_cancel' AND reference_id = ".$id)[0];

        return StockMovementResource::typeCellOf($mov) === 'Scarico · '.$numero
            && StockMovementResource::typeCellOf($storno) === 'Annullamento dello scarico · '.$numero;
    });
});

check('Movimenti: il filtro Tipo trova nel database le righe giuste', function () {
    return prova(static function (): bool {
        $a = articolo(10);
        $scarico = StockDocuments::create('issue', ['reason' => 'gift'], [['product_id' => $a, 'quantity' => '1']]);
        StockDocuments::confirm($scarico);
        StockDocuments::cancel($scarico);
        StockDocuments::confirm(StockDocuments::create('stocktake', [], [['product_id' => $a, 'quantity' => '4']]));
        Stock::apply(['product_id' => $a, 'quantity' => 1, 'type' => 'adjustment', 'reason' => 'other']);
        $trova = static fn (string $voce): array => array_map(
            static fn (array $r): string => $r['type'].'|'.($r['reference_type'] ?? ''),
            righe(StockMovement::class, StockMovementResource::typeCondition([$voce]).' AND `product_id` = '.$a)
        );

        return $trova('doc_issue') === ['adjustment|stock_document', 'adjustment|stock_document_cancel']
            && $trova('doc_stocktake') === ['adjustment|stock_document']
            && $trova('doc_receipt') === ['purchase|']
            && $trova('adjustment') === ['adjustment|'];
    });
});
```

`articolo()` carica la giacenza iniziale con un `purchase` senza documento: è la riga che *Carico* deve trovare. `Stock::apply()` senza `reference_type` scrive NULL, per questo la condizione della *Rettifica* ha `IS NULL OR`.

- [ ] **Passo 2: verifica che falliscano**

Run: `php tests/StockMovementResourceTest.php`
Expected: FAIL sulle prove nuove del filtro (le voci sono ancora i tipi registrati).

- [ ] **Passo 3: la Resource**

In `src/Resources/Stock/StockMovementResource.php` aggiungi gli `use`:

```php
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentRules;
```

Riga 112, il formatter di *Tipo*:

```php
            ->formatter(static fn (array $row): string => static::typeCellOf($row));
```

Riga 199, il filtro:

```php
            ->filterQuery('Tipo', 'tipo', static::typeOptions(), static fn (array $values): string => static::typeCondition($values))
```

Al posto di `typeCondition()` (righe 240-249, docblock compreso):

```php
    /** I movimenti che nascono da un documento di magazzino o dal suo annullamento. */
    private const DOCUMENT_REFERENCES = "'stock_document', 'stock_document_cancel'";

    /**
     * Le voci del filtro *Tipo*: al posto del carico registrato ci sono i tre
     * documenti, perché uno scarico è registrato come rettifica.
     *
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        $options = ['' => 'Tutti'];

        foreach (StockMovement::typeLabels() as $type => $label) {
            if ($type !== 'purchase') {
                $options[$type] = $label;

                continue;
            }

            foreach (StockDocumentRules::labels() as $document => $documentLabel) {
                $options['doc_'.$document] = $documentLabel;
            }
        }

        return $options;
    }

    /**
     * La condizione del filtro *Tipo*, con la stessa regola della colonna:
     * *Carico*, *Scarico* e *Inventario* guardano il documento, storni
     * compresi (*Carico* anche i carichi senza documento, che la colonna
     * chiama così); *Rettifica* è una rettifica senza documento. Solo voci
     * che esistono: è testo che arriva dall'utente.
     *
     * @param list<string> $values
     */
    public static function typeCondition(array $values): string
    {
        $withoutDocument = '(`reference_type` IS NULL OR `reference_type` NOT IN ('.self::DOCUMENT_REFERENCES.'))';
        $parts = [];

        foreach (array_unique(array_map('strval', $values)) as $value) {
            $document = str_starts_with($value, 'doc_') ? substr($value, 4) : '';

            if ($document !== '' && StockDocumentRules::exists($document)) {
                $part = '(`reference_type` IN ('.self::DOCUMENT_REFERENCES.') AND `reference_id` IN (SELECT `id` FROM `'
                    .StockDocument::$table."` WHERE `type` = '".$document."'))";
                $parts[] = $document === 'receipt' ? '('.$part." OR (`type` = 'purchase' AND ".$withoutDocument.'))' : $part;
            } elseif ($value === 'adjustment') {
                $parts[] = "(`type` = 'adjustment' AND ".$withoutDocument.')';
            } elseif ($value !== 'purchase' && in_array($value, StockMovement::TYPES, true)) {
                $parts[] = "`type` = '".$value."'";
            }
        }

        return match (count($parts)) {
            0 => '',
            1 => $parts[0],
            default => '('.implode(' OR ', $parts).')',
        };
    }
```

Al posto di `typeCell()` (righe 307-318):

```php
    /** La cella *Tipo* di una riga, con il documento letto dal database. */
    public static function typeCellOf(array $row): string
    {
        return static::typeCell($row, ...static::documentOf($row));
    }

    /**
     * La cella *Tipo*: l'etichetta (quella del documento, se c'è, altrimenti
     * quella del tipo registrato) e il numero, con il link quando c'è.
     */
    public static function typeCell(array $row, string $number, string $url, string $label = ''): string
    {
        $label = static::escape($label !== '' ? $label : (StockMovement::typeLabels()[(string) ($row['type'] ?? '')] ?? ''));

        if ($number === '') {
            return $label;
        }

        $number = static::escape($number);

        return $label.' · '.($url === '' ? $number : '<a href="'.static::escape($url).'">'.$number.'</a>');
    }
```

Al posto di `documentOf()` (righe 320-336, docblock compreso):

```php
    /**
     * Il numero, il link e l'etichetta del documento che ha causato il
     * movimento. Un documento di magazzino non ha ancora una pagina (piani
     * 5-6): niente link.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function documentOf(array $row): array
    {
        $id = (int) ($row['reference_id'] ?? 0);
        $reference = (string) ($row['reference_type'] ?? '');

        if ($id <= 0) {
            return ['', '', ''];
        }

        if ($reference === 'order') {
            $number = trim((string) (static::one(Order::class, $id)['order_number'] ?? ''));

            return $number === '' ? ['', '', ''] : [$number, OrderResource::detailUrl($id), ''];
        }

        if ($reference !== 'stock_document' && $reference !== 'stock_document_cancel') {
            return ['', '', ''];
        }

        $document = static::one(StockDocument::class, $id);
        $number = trim((string) ($document['number'] ?? ''));
        $type = (string) ($document['type'] ?? '');

        if ($number === '') {
            return ['', '', ''];
        }

        return [
            $number,
            '',
            $reference === 'stock_document' ? StockDocumentRules::label($type) : StockDocumentRules::cancelLabel($type),
        ];
    }
```

- [ ] **Passo 4: verifica che passino**

Run: `php tests/StockMovementResourceTest.php && php tests/integrazione/StockDocumentsTest.php && php tests/integrazione/StockMovementBackendTest.php`
Expected: PASS. Se `StockMovementBackendTest` controlla le voci del filtro o la cella *Tipo* con il carico registrato, aggiorna quelle prove alle voci nuove (`doc_receipt` al posto di `purchase`).

- [ ] **Passo 5: commit**

```bash
git add src/Resources/Stock/StockMovementResource.php tests/StockMovementResourceTest.php tests/integrazione/StockDocumentsTest.php
git commit -m "Movimenti: documento e storni dentro Tipo, filtro Tipo sui documenti

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Guide, CHANGELOG, TODO e verifica finale

**Files:**
- Modify: `docs/dev/concetti/magazzino.md` (tabella *Le classi* e sezione nuova *I documenti* prima di *Le prenotazioni*)
- Modify: `docs/dev/concetti/documenti.md` (sezioni *Numerazione* e *Log degli stati*)
- Modify: `CHANGELOG.md` (sotto `### Aggiunto`)
- Modify: `TODO.md` (riga 219, piano 4 di G2b-bis)

- [ ] **Passo 1: la guida *Magazzino***

In `docs/dev/concetti/magazzino.md`, nella tabella *Le classi* (dopo la riga di `Stock`):

```markdown
| `StockDocumentRules` | sì | le regole dei documenti: righe, conferma, movimento di ogni riga, storno, atteso dell'inventario |
| `StockDocuments` | no | creare, salvare, confermare, annullare, duplicare ed eliminare un documento |
```

Prima di `## Le prenotazioni`, la sezione nuova:

````markdown
## I documenti

Carico (`receipt`), Scarico (`issue`) e Inventario (`stocktake`) sono
documenti con le loro righe: `gst_stock_documents`,
`gst_stock_document_items` e il log `gst_stock_document_status_logs`.
`transfer` è già nell'enum e arriva con G3, come le colonne vuote di lotto,
fornitore e costi.

```php
$id = StockDocuments::create('issue', ['reason' => 'damaged', 'note' => 'Caduti'], [
    ['product_id' => 12, 'quantity' => '2'],
], ['user_id' => $utente]);
StockDocuments::saveDraft($id, ['note' => 'Caduti dallo scaffale'], $righe);
$numero = StockDocuments::confirm($id, ['user_id' => $utente]);
StockDocuments::cancel($id, ['user_id' => $utente]);
$copia = StockDocuments::duplicate($id, ['user_id' => $utente]);
```

**Stati.** `draft` → `completed` → `cancelled`. La bozza non muove niente,
si modifica e si elimina (`delete()`; per l'endpoint del core
`assertDeletable()`). Un documento confermato non si modifica: si annulla e
se ne fa un duplicato. Ogni passaggio scrive una riga nel log; `user_id` del
documento è chi l'ha creato.

**Conferma.** Una transazione sola: numero (`DocumentSequences`, una
sequenza per tipo), `date` = il giorno della conferma, stato, log e un
`Stock::apply()` per riga. Uno scarico che porterebbe sotto zero si ferma con
`stock.insufficient` e la bozza resta senza numero.

| Documento | Movimento |
|---|---|
| Carico | `purchase`, pezzi in più |
| Scarico | `adjustment` con la causale del documento (`Reasons::forDocument('issue')`), pezzi in meno |
| Inventario | `adjustment` con `inventory`: *contati − attesi*; nessun movimento se è zero o la riga non è contata |

I movimenti hanno `reference_type` = `stock_document`, `reference_id` = il
documento, la sua sede e, in `user_id`, chi conferma.

**Annullamento.** Solo di un confermato: uno storno per movimento, stesso
tipo e causale, segno opposto, `reference_type` = `stock_document_cancel`.
Se lo storno di un carico già venduto andrebbe sotto zero si ferma con
`stock.insufficient` e il documento resta confermato.

**Attesi dell'inventario.** `expected_quantity` è la giacenza della versione
in quella sede quando la bozza salva un numero contato nuovo o cambiato, o
quando cambia la sede; lo stesso numero salvato di nuovo tiene l'atteso di
prima. Una riga non contata ha `quantity` ed `expected_quantity` NULL.

**Limiti.** Al massimo `StockDocumentRules::MAX_LINES` (500) righe, una
versione una volta sola, quantità non negative nella bozza.

Nei Movimenti *Tipo* legge «Scarico 2026/100003» o «Annullamento del carico
2026/100003», e il filtro *Tipo* ha *Carico*, *Scarico* e *Inventario* al
posto del carico registrato.

**Rifiuti.** Le chiavi `stock_document.*` in `lang/it/gestionale.json`, più
`stock.product_missing` e `stock.insufficient` di `Stock::apply()`.
````

- [ ] **Passo 2: la guida *Codici, numerazione e log degli stati***

In `docs/dev/concetti/documenti.md`, in fondo a *Numerazione dei documenti* (prima di `## Log degli stati`):

```markdown
I documenti di magazzino hanno una sequenza per tipo — `stock_receipt`,
`stock_issue`, `stock_stocktake` — e prendono il numero alla conferma, non
alla creazione: eliminare una bozza non lascia buchi. «Carico 2026/100001» e
«Scarico 2026/100001» convivono, perché il tipo accompagna sempre il numero.
```

In *Log degli stati*, dopo la frase «Ogni documento con stati ha la sua tabella di log…», aggiungi:

```markdown
Oggi sono `gst_order_status_logs`, `gst_payment_status_logs`,
`gst_sales_return_status_logs` e `gst_stock_document_status_logs`.
```

Se la guida elenca già i log in un altro punto, aggiungi lì `gst_stock_document_status_logs` invece di scrivere la frase.

- [ ] **Passo 3: CHANGELOG**

In `CHANGELOG.md`, prima voce sotto `### Aggiunto`:

```markdown
- Documenti di magazzino, dati e servizio: `gst_stock_documents`,
  `gst_stock_document_items`, `gst_stock_document_status_logs`; Carico, Scarico e
  Inventario con bozza, conferma (numero per tipo, un `Stock::apply()` per riga),
  annullamento con gli storni (`stock_document_cancel`), duplica ed eliminazione della
  bozza (`Support\Stock\StockDocuments`, regole pure in `StockDocumentRules`);
  `Reasons::forDocument()`. Nei Movimenti *Tipo* mostra il documento e i suoi storni, e
  il filtro *Tipo* ha *Carico*, *Scarico* e *Inventario*. Senza pagine: arrivano con i
  piani 5 e 6.
```

- [ ] **Passo 4: TODO**

In `TODO.md` riga 219 cambia `- [ ] Piano 4 Documenti, dati e servizio: …` in:

```markdown
    - [x] Piano 4 Documenti, dati e servizio scritto ed eseguito (2026-10-12): `docs/superpowers/plans/2026-10-12-documenti-di-magazzino-dati-e-servizio.md` — tabelle e Model, `StockDocumentRules` (pura), `StockDocuments` (bozza, conferma, annullamento, duplica, elimina), attesi dell'inventario, documento e storni dentro *Tipo* e filtro *Tipo* nei Movimenti, guide dello sviluppatore
```

- [ ] **Passo 5: `forge update` due volte**

```bash
cd $S && php forge update --local && php forge update --local
```

Expected: la seconda volta non crea né modifica niente (nessuna tabella, colonna o indice nell'uscita). Se cambia qualcosa, è una colonna dichiarata in modo che il core la rilegge diversa (di solito un `default` o una lunghezza): sistemala nel Model e rilancia.

- [ ] **Passo 6: suite completa**

Controlla che non giri un'altra suite (`ps aux | grep "php .*Test.php" | grep -v grep`), poi:

```bash
cd $W/gestionale && php tests/run.php
```

Expected: tutti verdi, unitari e d'integrazione. Un rosso fuori da questo piano va guardato prima di andare avanti: con il sito di prova parallelo la causa più probabile è un'altra suite in corsa sullo stesso database.

- [ ] **Passo 7: commit**

```bash
git add docs/dev/concetti/magazzino.md docs/dev/concetti/documenti.md CHANGELOG.md TODO.md
git commit -m "Documenti di magazzino: guide dello sviluppatore, CHANGELOG e TODO

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Niente `git push`: l'unione su `main` (PR con merge commit) e la pulizia del worktree e del sito parallelo si decidono con l'utente.
