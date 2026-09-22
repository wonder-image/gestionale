# Fondamenta del magazzino — Piano 1 di 4 di G2b

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** dare al gestionale la giacenza: quattro tabelle, una sola porta di
scrittura (`Stock::apply()`) e l'elenco dei movimenti, con tutta la logica
nelle classi pure che G3 e G4 riuseranno.

**Architecture:** i Model dichiarano le tabelle e basta. La logica sta in
classi piccole di `Support\Stock\`, ognuna con un lavoro: le pure (`Reasons`,
`Availability`, `Adjustment`, `LowStock`) decidono senza toccare il database e
si provano con gli array; quelle che leggono (`Locations`, `Levels`) e quelle
che scrivono (`Alerts`, `Stock`) stanno sopra. `Stock::apply()` è l'unico
codice che scrive su `gst_stock` e su `gst_stock_movements`: apre una
transazione, legge la riga con `FOR UPDATE`, scrive il movimento, aggiorna la
giacenza e rinfresca l'avviso di scorta.

**Tech Stack:** PHP 8.2, `wonder-image/app` ^2.2.12 (`Model`, `Resource`,
`Transaction`, `TableSchema`, `UploadSchema`), harness di test del modulo
(`tests/harness.php`), MySQL del sito di prova `ecommerce-site`.

**Spec:** [G2b — Magazzino base e anagrafiche](../specs/2026-09-21-magazzino-e-anagrafiche-design.md)

## Global Constraints

- Lingua: **italiano** nei commenti, nei testi e nei nomi dei test; **inglese**
  per classi, tabelle e colonne.
- Prefisso delle tabelle: **`gst_`** (lo controlla `tests/ConventionsTest.php`).
- Nessuna tabella di questo piano si sincronizza: `syncSchema(): ?SyncSchema`
  torna **`null`** (G2b.12).
- `code` con prefisso da `Support\Codes` — qui solo `Codes::STOCK_MOVEMENT`
  (`mov_`). Generato all'inserimento, mai modificato.
- Un rifiuto che deve leggere una persona è **`UserError::make('chiave')`**, con
  il testo in `lang/it/gestionale.json` sotto `gestionale.errors`. È l'unico
  tipo che il backend trasforma in messaggio: qualsiasi altra eccezione diventa
  una pagina 500.
- Le colonne che valgono zero (`batch_id`, `supplier_id`, `stock_alerts.location_id`,
  `reference_id`, `order_id`) **non hanno chiave esterna**: MySQL rifiuterebbe
  lo zero.
- Ogni tabella nasce già con `id`, `deleted`, `creation` e `last_modified` dal
  core: non si dichiarano.
- Le quantità sono `DECIMAL(10,3)` e si scrivono sempre come stringa canonica
  (`number_format($v, 3, '.', '')`).
- **Difetto noto del core installato (2.2.12):** il `prepare()` dei **form**
  arrotonda i decimali dei campi numerici del backend (`2,5` diventa `3`). La
  correzione è già in `packages/app` (`7d6df162`) ma **non rilasciata**.
  Riguarda i valori battuti in una casella, quindi la pagina *Giacenze* del
  piano 2, non questo piano: `Stock::apply()` scrive numeri canonici
  (`number_format($v, 3, '.', '')`) che `Model::prepare()` lascia passare
  intatti — verificato sul sito di prova. Le verifiche nel browser di questo
  piano si fanno comunque con **quantità intere**.
- Ogni task finisce con un commit sul ramo `feature/fondamenta-del-magazzino`
  di `packages/gestionale`.
- Test: `php tests/run.php` deve restare verde. I test d'integrazione girano
  solo se esiste `/Users/andreamarinoni/Developer/boilerplates/ecommerce-site` e
  **annullano sempre** quello che scrivono (transazione + eccezione, come
  `tests/integrazione/StatusLoggerTest.php`).

---

## File Structure

**Da creare**

| File | Responsabilità |
|---|---|
| `src/Models/Stock/Stock.php` | tabella `gst_stock`: la giacenza per prodotto/sede/lotto/fornitore |
| `src/Models/Stock/StockMovement.php` | tabella `gst_stock_movements`: la storia, immutabile |
| `src/Models/Stock/StockReservation.php` | tabella `gst_stock_reservations`: la riempirà il carrello (G4) |
| `src/Models/Stock/StockAlert.php` | tabella `gst_stock_alerts`: un avviso aperto per prodotto |
| `src/Support/Stock/Reasons.php` | **pura** — causali ed etichette |
| `src/Support/Stock/Availability.php` | **pura** — disponibile = giacenza − prenotazioni attive |
| `src/Support/Stock/Adjustment.php` | **pura** — dalla quantità scritta al movimento |
| `src/Support/Stock/LowStock.php` | **pura** — quale avviso aprire, chiudere, lasciare stare |
| `src/Support/Stock/Locations.php` | legge la sede principale del magazzino |
| `src/Support/Stock/Levels.php` | legge giacenza, prenotato e disponibile di uno o più prodotti |
| `src/Support/Stock/Alerts.php` | scrive gli avvisi, usando `LowStock` |
| `src/Support/Stock/Stock.php` | **la porta unica di scrittura**: `apply()` |
| `src/Resources/Stock/StockMovementResource.php` | elenco *Movimenti* in sola lettura |
| `docs/dev/concetti/magazzino.md` | guida sviluppatore |
| `docs/user/magazzino-movimenti.md` | guida commerciante |
| `tests/StockModelsTest.php`, `tests/StockReasonsTest.php`, `tests/StockRulesTest.php`, `tests/StockMovementResourceTest.php` | unitari |
| `tests/integrazione/StockLevelsTest.php`, `tests/integrazione/StockTest.php` | integrazione |

**Da modificare**

| File | Modifica |
|---|---|
| `lang/it/gestionale.json` | nuove chiavi sotto `gestionale.errors.stock` |
| `tests/ConventionsTest.php` | `StockMovementResource` tra le pagine sempre attive |
| `docs/dev/SUMMARY.md`, `docs/user/SUMMARY.md` | le due pagine nuove |

**Nota sui due `Stock`.** Il Model si chiama `Models\Stock\Stock` (la tabella è
`gst_stock`) e la porta di scrittura `Support\Stock\Stock` (come la spec).
Nei file che usano tutti e due il Model si importa con un alias:
`use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;`.

---

## Task 1: Le quattro tabelle

**Files:**
- Create: `src/Models/Stock/Stock.php`, `src/Models/Stock/StockMovement.php`,
  `src/Models/Stock/StockReservation.php`, `src/Models/Stock/StockAlert.php`
- Test: `tests/StockModelsTest.php`

**Interfaces:**
- Consumes: `Wonder\Plugin\Gestionale\Models\Catalog\Product::$table`,
  `Wonder\Plugin\Gestionale\Models\Locations\Location::$table`,
  `Support\Codes::STOCK_MOVEMENT`.
- Produces: `Stock::$table = 'gst_stock'`, `StockMovement::$table = 'gst_stock_movements'`,
  `StockMovement::TYPES` (list<string>), `StockMovement::typeLabels(): array<string, string>`,
  `StockReservation::$table = 'gst_stock_reservations'`,
  `StockAlert::$table = 'gst_stock_alerts'`.

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/StockModelsTest.php`:

```php
<?php
/** php tests/StockModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Stock\Stock;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Codes;

$modelli = [Stock::class, StockMovement::class, StockReservation::class, StockAlert::class];

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

check('le quattro tabelle hanno il prefisso del gestionale', fn () =>
    Stock::$table === 'gst_stock'
    && StockMovement::$table === 'gst_stock_movements'
    && StockReservation::$table === 'gst_stock_reservations'
    && StockAlert::$table === 'gst_stock_alerts'
);

check('il magazzino non viaggia con il deploy', function () use ($modelli) {
    foreach ($modelli as $modello) {
        if ($modello::syncSchema() !== null) {
            return false;
        }
    }

    return true;
});

check('una giacenza è una sola riga per prodotto, sede, lotto e fornitore', function () {
    $unico = Stock::tablePseudos()['uni_stock']['unique'] ?? [];

    return $unico === ['product_id', 'location_id', 'batch_id', 'supplier_id'];
});

check('il movimento ha il suo prefisso', function () use ($campo) {
    return ($campo(StockMovement::class, 'code')?->getSchema('unique_code')['prefix'] ?? null)
        === Codes::STOCK_MOVEMENT;
});

check('l\'enum dei tipi nasce completo, anche per G3 e G4', fn () =>
    StockMovement::TYPES === [
        'sale', 'sale_cancel', 'return', 'purchase',
        'adjustment', 'transfer_in', 'transfer_out',
    ]
);

check('ogni tipo ha un\'etichetta in italiano', function () {
    $etichette = StockMovement::typeLabels();

    foreach (StockMovement::TYPES as $tipo) {
        if (trim((string) ($etichette[$tipo] ?? '')) === '') {
            return false;
        }
    }

    return count($etichette) === count(StockMovement::TYPES);
});

check('il movimento tiene prima, dopo e il segno', function () use ($colonne) {
    $c = $colonne(StockMovement::class);

    return isset($c['quantity'], $c['quantity_before'], $c['quantity_after']);
});

check('le colonne che valgono zero non hanno chiave esterna', function () use ($colonne) {
    // MySQL rifiuterebbe lo zero: batch e fornitore restano vuoti fino a G3,
    // e la soglia di scorta vale sul totale, non su una sede.
    foreach ([
        [Stock::class, 'batch_id'], [Stock::class, 'supplier_id'],
        [StockMovement::class, 'batch_id'], [StockMovement::class, 'supplier_id'],
        [StockAlert::class, 'location_id'],
        [StockReservation::class, 'order_id'], [StockReservation::class, 'order_item_id'],
    ] as [$modello, $nome]) {
        if (($colonne($modello)[$nome] ?? null)?->getSchema('foreign_table') !== null) {
            return false;
        }
    }

    return true;
});

check('prodotto e sede invece sono legati alle loro tabelle', function () use ($colonne) {
    $stock = $colonne(Stock::class);

    return $stock['product_id']->getSchema('foreign_table') === 'gst_products'
        && $stock['location_id']->getSchema('foreign_table') === 'gst_locations';
});

check('le quantità hanno tre decimali', function () use ($campo) {
    foreach ([
        [Stock::class, 'quantity'],
        [StockMovement::class, 'quantity'],
        [StockReservation::class, 'quantity'],
    ] as [$modello, $nome]) {
        if ((int) ($campo($modello, $nome)?->getSchema('decimals') ?? 0) !== 3) {
            return false;
        }
    }

    return true;
});

check('nessuna colonna usa una parola riservata di MySQL', function () use ($modelli, $colonne) {
    $riservate = ['key', 'group', 'order', 'index', 'default'];

    foreach ($modelli as $modello) {
        foreach (array_keys($colonne($modello)) as $nome) {
            if (in_array(strtolower((string) $nome), $riservate, true)) {
                return false;
            }
        }
    }

    return true;
});

summary();
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `php tests/StockModelsTest.php`
Expected: FAIL — `Class "…\Models\Stock\Stock" not found`.

- [ ] **Step 3: Scrivi `src/Models/Stock/Stock.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Stock;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Sql\TableSchema as Column;

/**
 * La giacenza: quanti pezzi ci sono di una versione in vendita, in una sede.
 *
 * Una riga per combinazione prodotto/sede/lotto/fornitore, con l'indice unico
 * che lo garantisce. `batch_id` e `supplier_id` restano a zero fino a G3, ma
 * stanno nell'indice da subito: aggiungerli dopo vorrebbe dire rifare l'indice
 * e tutte le letture che ci passano.
 *
 * Nessun codice scrive qui direttamente: la porta è
 * `Support\Stock\Stock::apply()`, che tiene insieme giacenza e movimenti.
 */
final class Stock extends Model
{
    public static string $table = 'gst_stock';
    public static string $folder = 'gestionale/stock';
    public static string $icon = 'bi bi-boxes';

    /** La giacenza è la storia di questo ambiente, non configurazione. */
    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('product_id')->int()->null(false)->foreign(Product::$table),
            Column::key('location_id')->int()->null(false)->foreign(Location::$table),
            // Niente chiave esterna: zero vuol dire "nessun lotto", "nessun
            // fornitore", e MySQL non accetta uno zero che punta a niente.
            Column::key('batch_id')->int()->default(0),
            Column::key('supplier_id')->int()->default(0),
            ...static::sqlColumnsFromDataSchema(['quantity']),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'uni_stock' => ['unique' => ['product_id', 'location_id', 'batch_id', 'supplier_id']],
            'ind_product' => ['index' => 'product_id'],
            'ind_location' => ['index' => 'location_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_id')->number()->decimals(0),
            Field::key('location_id')->number()->decimals(0),
            Field::key('batch_id')->number()->decimals(0),
            Field::key('supplier_id')->number()->decimals(0),
            Field::key('quantity')->number()->decimals(3),
        ];
    }
}
```

- [ ] **Step 4: Scrivi `src/Models/Stock/StockMovement.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Stock;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * La storia del magazzino: una riga per ogni pezzo entrato o uscito.
 *
 * Non si modifica e non si cancella mai: una rettifica sbagliata si corregge
 * con un'altra rettifica. È l'unica risposta possibile alla domanda "perché
 * qui c'è scritto 3?".
 *
 * L'enum dei tipi nasce completo anche se in G2b l'unico prodotto è
 * `adjustment`: cambiare un enum con dentro dei dati è la migrazione che si
 * rimanda sempre. Vendite e resi arrivano con G4, carichi e trasferimenti con
 * G3.
 *
 * `reference_type` + `reference_id` legano il movimento al documento che l'ha
 * causato; in G2b restano vuoti.
 */
final class StockMovement extends Model
{
    public static string $table = 'gst_stock_movements';
    public static string $folder = 'gestionale/stock';
    public static string $icon = 'bi bi-arrow-left-right';

    public const TYPES = [
        'sale', 'sale_cancel', 'return', 'purchase',
        'adjustment', 'transfer_in', 'transfer_out',
    ];

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    /** @return array<string, string> */
    public static function typeLabels(): array
    {
        return [
            'sale' => 'Vendita',
            'sale_cancel' => 'Vendita annullata',
            'return' => 'Reso',
            'purchase' => 'Carico',
            'adjustment' => 'Rettifica',
            'transfer_in' => 'Trasferimento in entrata',
            'transfer_out' => 'Trasferimento in uscita',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema([
                'code', 'quantity', 'quantity_before', 'quantity_after', 'unit_cost',
            ]),
            Column::key('product_id')->int()->null(false)->foreign(Product::$table),
            Column::key('location_id')->int()->null(false)->foreign(Location::$table),
            Column::key('batch_id')->int()->default(0),
            Column::key('supplier_id')->int()->default(0),
            Column::key('type')->enum(self::TYPES)->default('adjustment'),
            Column::key('reason')->length(30),
            Column::key('reference_type')->length(30),
            Column::key('reference_id')->int(),
            Column::key('source')->length(20),
            Column::key('user_id')->int(),
            Column::key('note')->type('TEXT'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_product' => ['index' => 'product_id'],
            'ind_location' => ['index' => 'location_id'],
            'ind_reference' => ['index' => 'reference_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::STOCK_MOVEMENT),
            Field::key('product_id')->number()->decimals(0),
            Field::key('location_id')->number()->decimals(0),
            Field::key('batch_id')->number()->decimals(0),
            Field::key('supplier_id')->number()->decimals(0),
            Field::key('type')->text()->sanitize(false),
            Field::key('reason')->text()->sanitize(false),
            Field::key('quantity')->number()->decimals(3),
            Field::key('quantity_before')->number()->decimals(3),
            Field::key('quantity_after')->number()->decimals(3),
            // Quattro decimali: un imballo da mille pezzi ha un costo unitario
            // con le frazioni, e arrotondarlo qui falserebbe il valore del
            // magazzino di G3.
            Field::key('unit_cost')->number()->decimals(4),
            Field::key('reference_type')->text()->sanitize(false),
            Field::key('reference_id')->number()->decimals(0),
            Field::key('source')->text()->sanitize(false),
            Field::key('user_id')->number()->decimals(0),
            Field::key('note')->text(),
        ];
    }
}
```

- [ ] **Step 5: Scrivi `src/Models/Stock/StockReservation.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Stock;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Sql\TableSchema as Column;

/**
 * La merce impegnata da un carrello o da un ordine non ancora confermato.
 *
 * In G2b la tabella **nasce vuota**: la riempirà il checkout in G4. Esiste già
 * perché "disponibile" — giacenza meno prenotazioni attive — è la parola che
 * useranno l'elenco delle giacenze, la scheda e la vetrina, e deve voler dire
 * la stessa cosa da subito (G2b.4).
 *
 * `order_id` e `order_item_id` non hanno chiave esterna: le tabelle degli
 * ordini non esistono ancora.
 */
final class StockReservation extends Model
{
    public static string $table = 'gst_stock_reservations';
    public static string $folder = 'gestionale/stock';
    public static string $icon = 'bi bi-hourglass-split';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('product_id')->int()->null(false)->foreign(Product::$table),
            Column::key('location_id')->int()->null(false)->foreign(Location::$table),
            ...static::sqlColumnsFromDataSchema(['quantity']),
            Column::key('order_id')->int(),
            Column::key('order_item_id')->int(),
            Column::key('expires_at')->datetime(),
            Column::key('released_at')->datetime(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_product' => ['index' => 'product_id'],
            'ind_order' => ['index' => 'order_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_id')->number()->decimals(0),
            Field::key('location_id')->number()->decimals(0),
            Field::key('quantity')->number()->decimals(3),
            Field::key('order_id')->number()->decimals(0),
            Field::key('order_item_id')->number()->decimals(0),
            Field::key('expires_at')->date(),
            Field::key('released_at')->date(),
        ];
    }
}
```

- [ ] **Step 6: Scrivi `src/Models/Stock/StockAlert.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Stock;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Sql\TableSchema as Column;

/**
 * L'avviso di scorta minima: una riga aperta per prodotto sceso sotto soglia.
 *
 * `resolved_at` vuoto vuol dire aperto; finché lo è, l'avviso **non si
 * ripete**. `notified_at` vuoto vuol dire che l'email non è ancora partita: la
 * manda l'attività dello scheduler del piano 4, mai il salvataggio.
 *
 * `location_id` resta a zero perché la soglia vale sul disponibile totale del
 * prodotto, somma di tutte le sedi: per questo non ha chiave esterna.
 */
final class StockAlert extends Model
{
    public static string $table = 'gst_stock_alerts';
    public static string $folder = 'gestionale/stock';
    public static string $icon = 'bi bi-exclamation-triangle';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('product_id')->int()->null(false)->foreign(Product::$table),
            Column::key('location_id')->int()->default(0),
            ...static::sqlColumnsFromDataSchema(['threshold', 'quantity_at_alert']),
            Column::key('notified_at')->datetime(),
            Column::key('resolved_at')->datetime(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_product' => ['index' => 'product_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('product_id')->number()->decimals(0),
            Field::key('location_id')->number()->decimals(0),
            Field::key('threshold')->number()->decimals(3),
            Field::key('quantity_at_alert')->number()->decimals(3),
            Field::key('notified_at')->date(),
            Field::key('resolved_at')->date(),
        ];
    }
}
```

- [ ] **Step 7: Esegui il test e verifica che passi**

Run: `php tests/StockModelsTest.php`
Expected: `11 test, 0 falliti`

- [ ] **Step 8: Esegui tutta la suite**

Run: `php tests/run.php`
Expected: "Tutti i test del gestionale passano."

- [ ] **Step 9: Commit**

```bash
git add src/Models/Stock tests/StockModelsTest.php
git commit -m "Magazzino: le quattro tabelle di G2b"
```

---

## Task 2: Causali e disponibile (classi pure)

**Files:**
- Create: `src/Support/Stock/Reasons.php`, `src/Support/Stock/Availability.php`
- Test: `tests/StockReasonsTest.php`

**Interfaces:**
- Consumes: niente (sono pure).
- Produces:
  - `Reasons::all(): array<string, string>` (chiave => etichetta, `inventory` per primo),
    `Reasons::DEFAULT` (`'inventory'`), `Reasons::exists(string): bool`,
    `Reasons::label(string): string` (`''` se sconosciuta).
  - `Availability::reserved(array $reservations, ?string $now = null): float`,
    `Availability::of(float $quantity, array $reservations, ?string $now = null): float`,
    `Availability::isActive(array $reservation, string $now): bool`.
    Una riga di prenotazione è un array con `quantity`, `expires_at`,
    `released_at`; `$now` è `'Y-m-d H:i:s'`.

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/StockReasonsTest.php`:

```php
<?php
/** php tests/StockReasonsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\Availability;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;

check('le causali della spec ci sono tutte', fn () =>
    array_keys(Reasons::all()) === [
        'inventory', 'initial_stock', 'damaged', 'expired',
        'gift', 'internal_use', 'other',
    ]
);

check('la causale predefinita è l\'inventario, la prima dell\'elenco', fn () =>
    Reasons::DEFAULT === 'inventory'
    && array_key_first(Reasons::all()) === 'inventory'
);

check('ogni causale ha un\'etichetta che una persona capisce', function () {
    foreach (Reasons::all() as $chiave => $etichetta) {
        if (trim($etichetta) === '' || $etichetta === $chiave) {
            return false;
        }
    }

    return true;
});

check('una causale inventata non esiste', fn () =>
    Reasons::exists('inventory') === true
    && Reasons::exists('marziana') === false
    && Reasons::label('marziana') === ''
);

check('senza prenotazioni il disponibile è la giacenza', fn () =>
    Availability::of(10.0, []) === 10.0
);

check('una prenotazione attiva toglie pezzi al disponibile', fn () =>
    Availability::of(10.0, [
        ['quantity' => '3.000', 'expires_at' => '2026-12-31 23:59:59', 'released_at' => ''],
    ], '2026-09-22 10:00:00') === 7.0
);

check('una prenotazione scaduta non conta più', fn () =>
    Availability::of(10.0, [
        ['quantity' => '3.000', 'expires_at' => '2026-09-01 00:00:00', 'released_at' => ''],
    ], '2026-09-22 10:00:00') === 10.0
);

check('una prenotazione rilasciata non conta più', fn () =>
    Availability::of(10.0, [
        ['quantity' => '3.000', 'expires_at' => '', 'released_at' => '2026-09-20 10:00:00'],
    ], '2026-09-22 10:00:00') === 10.0
);

check('una prenotazione senza scadenza resta attiva', fn () =>
    Availability::reserved([
        ['quantity' => '2.500', 'expires_at' => '', 'released_at' => ''],
    ], '2026-09-22 10:00:00') === 2.5
);

check('le date vuote del database non ingannano nessuno', fn () =>
    // MySQL scrive gli zero, il framework a volte la stringa vuota: valgono
    // tutte e due "mai".
    Availability::reserved([
        ['quantity' => '1.000', 'expires_at' => '0000-00-00 00:00:00', 'released_at' => null],
    ], '2026-09-22 10:00:00') === 1.0
);

check('il disponibile può andare sotto zero e lo dice', fn () =>
    Availability::of(1.0, [
        ['quantity' => '3.000', 'expires_at' => '', 'released_at' => ''],
    ], '2026-09-22 10:00:00') === -2.0
);

summary();
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `php tests/StockReasonsTest.php`
Expected: FAIL — `Class "…\Support\Stock\Reasons" not found`.

- [ ] **Step 3: Scrivi `src/Support/Stock/Reasons.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

/**
 * Le causali di una rettifica: perché quei pezzi sono entrati o usciti.
 *
 * Sono poche apposta. Una tendina con venti voci non la legge nessuno, e la
 * causale serve a rispondere a una domanda sola: "cosa è successo qui?".
 *
 * L'ordine è quello della tendina: `inventory` per primo perché è il caso di
 * gran lunga più frequente — si conta lo scaffale e si allinea il gestionale.
 */
final class Reasons
{
    public const DEFAULT = 'inventory';

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            'inventory' => 'Inventario',
            'initial_stock' => 'Giacenza iniziale',
            'damaged' => 'Danneggiato',
            'expired' => 'Scaduto',
            'gift' => 'Regalo',
            'internal_use' => 'Uso interno',
            'other' => 'Altro',
        ];
    }

    public static function exists(string $reason): bool
    {
        return array_key_exists($reason, self::all());
    }

    /** L'etichetta, o stringa vuota se la causale non esiste. */
    public static function label(string $reason): string
    {
        return self::all()[$reason] ?? '';
    }
}
```

- [ ] **Step 4: Scrivi `src/Support/Stock/Availability.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

/**
 * Disponibile = giacenza − prenotazioni attive.
 *
 * È la differenza che evita l'overselling: due clienti che mettono nel
 * carrello l'ultimo pezzo non possono comprarlo tutti e due. In G2b nessuno
 * scrive prenotazioni — lo farà il checkout in G4 — ma la regola vive qui da
 * subito, così backend e vetrina non la scriveranno ognuno a modo suo.
 *
 * Una prenotazione conta finché non è stata rilasciata e non è scaduta.
 * Pura: prende array, non tocca il database.
 */
final class Availability
{
    /**
     * @param list<array<string, mixed>> $reservations
     */
    public static function of(float $quantity, array $reservations, ?string $now = null): float
    {
        return round($quantity - self::reserved($reservations, $now), 3);
    }

    /**
     * @param list<array<string, mixed>> $reservations
     */
    public static function reserved(array $reservations, ?string $now = null): float
    {
        $now ??= date('Y-m-d H:i:s');
        $total = 0.0;

        foreach ($reservations as $reservation) {
            if (is_array($reservation) && self::isActive($reservation, $now)) {
                $total += (float) ($reservation['quantity'] ?? 0);
            }
        }

        return round($total, 3);
    }

    /** @param array<string, mixed> $reservation */
    public static function isActive(array $reservation, string $now): bool
    {
        if (!self::isEmptyDate($reservation['released_at'] ?? null)) {
            return false;
        }

        $expires = $reservation['expires_at'] ?? null;

        return self::isEmptyDate($expires) || (string) $expires > $now;
    }

    /**
     * Le date "mai" arrivano in tre forme: `null` da una colonna vuota, la
     * stringa vuota da chi scrive dai form, gli zeri da MySQL.
     */
    private static function isEmptyDate(mixed $value): bool
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' || str_starts_with($text, '0000-00-00');
    }
}
```

- [ ] **Step 5: Esegui il test e verifica che passi**

Run: `php tests/StockReasonsTest.php`
Expected: `11 test, 0 falliti`

- [ ] **Step 6: Commit**

```bash
git add src/Support/Stock/Reasons.php src/Support/Stock/Availability.php tests/StockReasonsTest.php
git commit -m "Magazzino: causali e calcolo del disponibile"
```

---

## Task 3: Rettifica e scorta minima (classi pure)

**Files:**
- Create: `src/Support/Stock/Adjustment.php`, `src/Support/Stock/LowStock.php`
- Test: `tests/StockRulesTest.php`

**Interfaces:**
- Consumes: niente (sono pure).
- Produces:
  - `Adjustment::fromTarget(float $current, float $target): array{delta: float, before: float, after: float}`
  - `Adjustment::fromDelta(float $current, float $delta): array{delta: float, before: float, after: float}`
  - `LowStock::OPEN` (`'open'`), `LowStock::CLOSE` (`'close'`), `LowStock::NONE` (`'none'`)
  - `LowStock::decide(float $threshold, float $available, bool $alertOpen): string`

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/StockRulesTest.php`:

```php
<?php
/** php tests/StockRulesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\Adjustment;
use Wonder\Plugin\Gestionale\Support\Stock\LowStock;

check('scrivere una quantità nuova diventa la differenza', fn () =>
    Adjustment::fromTarget(4.0, 10.0) === ['delta' => 6.0, 'before' => 4.0, 'after' => 10.0]
);

check('scrivere meno di quello che c\'è toglie pezzi', fn () =>
    Adjustment::fromTarget(10.0, 4.0) === ['delta' => -6.0, 'before' => 10.0, 'after' => 4.0]
);

check('scrivere la stessa quantità non muove niente', fn () =>
    Adjustment::fromTarget(7.0, 7.0)['delta'] === 0.0
);

check('il più e il meno partono da quello che c\'è', fn () =>
    Adjustment::fromDelta(7.0, -2.0) === ['delta' => -2.0, 'before' => 7.0, 'after' => 5.0]
);

check('i terzi decimali non lasciano briciole', fn () =>
    // 0.1 + 0.2 in virgola mobile fa 0.30000000000000004.
    Adjustment::fromDelta(0.1, 0.2)['after'] === 0.3
);

check('una quantità può scendere sotto zero: il rifiuto è di chi scrive', fn () =>
    Adjustment::fromDelta(1.0, -3.0)['after'] === -2.0
);

check('senza soglia non c\'è nessun avviso', fn () =>
    LowStock::decide(0.0, -5.0, false) === LowStock::NONE
);

check('togliere la soglia chiude un avviso già aperto', fn () =>
    LowStock::decide(0.0, -5.0, true) === LowStock::CLOSE
);

check('scendere sotto soglia apre l\'avviso', fn () =>
    LowStock::decide(5.0, 4.0, false) === LowStock::OPEN
);

check('la soglia esatta conta come sotto', fn () =>
    // "Scorta minima 5" vuol dire "sotto i cinque pezzi riordina": a cinque
    // siamo già al limite.
    LowStock::decide(5.0, 5.0, false) === LowStock::OPEN
);

check('un avviso aperto non si ripete', fn () =>
    LowStock::decide(5.0, 3.0, true) === LowStock::NONE
);

check('risalire sopra soglia chiude l\'avviso', fn () =>
    LowStock::decide(5.0, 6.0, true) === LowStock::CLOSE
);

check('sopra soglia senza avviso non succede niente', fn () =>
    LowStock::decide(5.0, 6.0, false) === LowStock::NONE
);

summary();
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `php tests/StockRulesTest.php`
Expected: FAIL — `Class "…\Support\Stock\Adjustment" not found`.

- [ ] **Step 3: Scrivi `src/Support/Stock/Adjustment.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

/**
 * Dalla quantità scritta da una persona al movimento da registrare.
 *
 * Chi rettifica ragiona in due modi: "sullo scaffale ce ne sono dieci"
 * (`fromTarget`) oppure "ne ho buttati due" (`fromDelta`). Il magazzino
 * registra sempre la differenza, con la giacenza prima e dopo.
 *
 * Tutto arrotondato al terzo decimale, quanti ne ha la colonna: senza, una
 * somma in virgola mobile lascia briciole (`0.1 + 0.2` fa
 * `0.30000000000000004`) che finirebbero nel database.
 */
final class Adjustment
{
    /** @return array{delta: float, before: float, after: float} */
    public static function fromTarget(float $current, float $target): array
    {
        $before = round($current, 3);
        $after = round($target, 3);

        return [
            'delta' => round($after - $before, 3),
            'before' => $before,
            'after' => $after,
        ];
    }

    /** @return array{delta: float, before: float, after: float} */
    public static function fromDelta(float $current, float $delta): array
    {
        $before = round($current, 3);

        return [
            'delta' => round($delta, 3),
            'before' => $before,
            'after' => round($before + $delta, 3),
        ];
    }
}
```

- [ ] **Step 4: Scrivi `src/Support/Stock/LowStock.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

/**
 * Quando aprire e quando chiudere un avviso di scorta minima.
 *
 * Tre regole sole, tutte pensate per **non** ripetere l'avviso: chi riceve
 * dieci email per lo stesso prodotto smette di leggerle. L'avviso resta aperto
 * finché il prodotto non risale sopra soglia; solo allora, se ricade, ne nasce
 * uno nuovo.
 *
 * Una soglia a zero vuol dire "non avvisarmi": se c'era un avviso aperto si
 * chiude, perché il commerciante ha appena detto che non gli interessa più.
 */
final class LowStock
{
    public const OPEN = 'open';
    public const CLOSE = 'close';
    public const NONE = 'none';

    /** @return self::OPEN|self::CLOSE|self::NONE */
    public static function decide(float $threshold, float $available, bool $alertOpen): string
    {
        if (round($threshold, 3) <= 0.0) {
            return $alertOpen ? self::CLOSE : self::NONE;
        }

        if (round($available, 3) <= round($threshold, 3)) {
            return $alertOpen ? self::NONE : self::OPEN;
        }

        return $alertOpen ? self::CLOSE : self::NONE;
    }
}
```

- [ ] **Step 5: Esegui il test e verifica che passi**

Run: `php tests/StockRulesTest.php`
Expected: `14 test, 0 falliti`

- [ ] **Step 6: Commit**

```bash
git add src/Support/Stock/Adjustment.php src/Support/Stock/LowStock.php tests/StockRulesTest.php
git commit -m "Magazzino: regole della rettifica e della scorta minima"
```

---

## Task 4: Le letture — sede principale e livelli

**Files:**
- Create: `src/Support/Stock/Locations.php`, `src/Support/Stock/Levels.php`
- Test: `tests/integrazione/StockLevelsTest.php`

**Interfaces:**
- Consumes: `Models\Stock\Stock` (alias `StockRow`), `Models\Stock\StockReservation`,
  `Models\Locations\Location::forSocietyLocation()`,
  `Wonder\App\Support\SocietyLocations::default()`,
  `Availability::of()` / `Availability::reserved()`.
- Produces:
  - `Locations::mainId(): int` — l'id di `gst_locations` della sede principale, `0` se non c'è.
  - `Locations::reset(): void` — svuota la cache statica (serve ai test).
  - `Levels::of(int $productId): array{quantity: float, reserved: float, available: float}`
  - `Levels::forProducts(array $productIds): array<int, array{quantity: float, reserved: float, available: float}>`

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/integrazione/StockLevelsTest.php`:

```php
<?php
/** php tests/integrazione/StockLevelsTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

check('la sede principale del magazzino esiste', fn () => Locations::mainId() > 0);

$locationId = Locations::mainId();

try {
    Transaction::run(static function () use ($locationId): void {
        // Un articolo di prova, cancellato con la transazione. Modello e
        // variante servono davvero: le colonne del prodotto sono legate alle
        // loro tabelle, e uno zero non passerebbe.
        $modello = ProductModel::create([
            'code' => Code::make(ProductModel::class, Codes::MODEL),
            'name' => 'Prova livelli',
            'slug' => Slug::make('prova-livelli-'.uniqid()),
            'sku' => 'TST-LEV',
            'unit' => 'pz',
            'type' => 'simple',
            'visible' => 'true',
            'position' => 1,
        ]);
        $scheletro = Skeleton::forModel((int) ($modello->insert_id ?? 0), 'Prova livelli', 'TST-LEV');
        $productId = $scheletro['product_id'];

        check('l\'articolo di prova è nato con la sua versione', fn () => $productId > 0);

        check('senza righe di giacenza i livelli sono a zero', fn () =>
            Levels::of($productId) === ['quantity' => 0.0, 'reserved' => 0.0, 'available' => 0.0]
        );

        StockRow::create([
            'product_id' => $productId,
            'location_id' => $locationId,
            'batch_id' => 0,
            'supplier_id' => 0,
            'quantity' => '12.000',
        ]);

        check('la giacenza è la somma delle righe del prodotto', fn () =>
            Levels::of($productId)['quantity'] === 12.0
        );

        StockReservation::create([
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity' => '2.000',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour')),
        ]);

        check('una prenotazione attiva abbassa il disponibile ma non la giacenza', function () use ($productId) {
            $livelli = Levels::of($productId);

            return $livelli['quantity'] === 12.0
                && $livelli['reserved'] === 2.0
                && $livelli['available'] === 10.0;
        });

        StockReservation::create([
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity' => '5.000',
            'expires_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
        ]);

        check('una prenotazione scaduta non toglie niente', fn () =>
            Levels::of($productId)['available'] === 10.0
        );

        check('più prodotti si leggono in un colpo solo', function () use ($productId) {
            $livelli = Levels::forProducts([$productId, 0]);

            return ($livelli[$productId]['available'] ?? null) === 10.0
                && array_key_exists(0, $livelli)
                && $livelli[0]['quantity'] === 0.0;
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento non resta niente dell\'articolo di prova', function () {
    $riga = ProductModel::find(['sku' => 'TST-LEV', 'deleted' => 'false'], 1);
    $versione = Product::find(['sku' => 'TST-LEV', 'deleted' => 'false'], 1);

    return (!is_array($riga) || $riga === []) && (!is_array($versione) || $versione === []);
});

summary();
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `php tests/integrazione/StockLevelsTest.php`
Expected: FAIL — `Class "…\Support\Stock\Locations" not found`.

- [ ] **Step 3: Scrivi `src/Support/Stock/Locations.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\App\Support\SocietyLocations;
use Wonder\Plugin\Gestionale\Models\Locations\Location;

/**
 * La sede del magazzino, quando nessuno l'ha scelta.
 *
 * Finché la funzionalità "Più sedi" è bloccata la sede è una sola e non
 * compare in nessun campo (D20): chi scrive un movimento non la passa, e la
 * chiede qui. La sede principale è quella predefinita del core, con la sua
 * riga in `gst_locations`.
 *
 * Il valore si tiene per tutta la richiesta: è una lettura che tornerebbe
 * altrimenti a ogni riga di un salvataggio in blocco.
 */
final class Locations
{
    private static ?int $mainId = null;

    /** L'id della riga di `gst_locations` della sede principale; `0` se manca. */
    public static function mainId(): int
    {
        if (self::$mainId !== null) {
            return self::$mainId;
        }

        return self::$mainId = self::resolve();
    }

    public static function reset(): void
    {
        self::$mainId = null;
    }

    private static function resolve(): int
    {
        try {
            $society = SocietyLocations::default();
            $row = Location::forSocietyLocation((int) ($society->id ?? 0));

            if (($id = (int) ($row['id'] ?? 0)) > 0) {
                return $id;
            }
        } catch (Throwable) {
            // Sito non avviato (comandi, test degli schemi): resta il ripiego.
        }

        // Ripiego: la prima sede che tiene merce. Un sito appena installato ne
        // ha una sola, creata dalle righe precaricate del modulo.
        try {
            $row = Location::find(['has_stock' => 'true', 'deleted' => 'false'], 1, 'id', 'ASC');
        } catch (Throwable) {
            return 0;
        }

        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }
}
```

- [ ] **Step 4: Scrivi `src/Support/Stock/Levels.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;

/**
 * Quanti pezzi ci sono, quanti sono impegnati, quanti se ne possono vendere.
 *
 * Somma tutte le sedi: è il numero che interessa a chi guarda un articolo.
 * La regola di cosa conta come impegnato sta in `Availability`, che è pura;
 * qui c'è solo la lettura.
 *
 * `forProducts()` esiste per l'elenco delle giacenze, che altrimenti farebbe
 * due query per riga.
 */
final class Levels
{
    private const EMPTY = ['quantity' => 0.0, 'reserved' => 0.0, 'available' => 0.0];

    /** @return array{quantity: float, reserved: float, available: float} */
    public static function of(int $productId): array
    {
        return self::forProducts([$productId])[$productId] ?? self::EMPTY;
    }

    /**
     * @param list<int> $productIds
     * @return array<int, array{quantity: float, reserved: float, available: float}>
     */
    public static function forProducts(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        $levels = [];

        foreach ($ids as $id) {
            $levels[$id] = self::EMPTY;
        }

        if ($ids === []) {
            return $levels;
        }

        $now = date('Y-m-d H:i:s');

        foreach (self::rows(StockRow::class, $ids) as $row) {
            $id = (int) ($row['product_id'] ?? 0);

            if (isset($levels[$id])) {
                $levels[$id]['quantity'] = round(
                    $levels[$id]['quantity'] + (float) ($row['quantity'] ?? 0),
                    3
                );
            }
        }

        $reservations = [];

        foreach (self::rows(StockReservation::class, $ids) as $row) {
            $reservations[(int) ($row['product_id'] ?? 0)][] = $row;
        }

        foreach ($levels as $id => $level) {
            $rows = $reservations[$id] ?? [];
            $levels[$id]['reserved'] = Availability::reserved($rows, $now);
            $levels[$id]['available'] = Availability::of($level['quantity'], $rows, $now);
        }

        return $levels;
    }

    /**
     * Le righe vive di un Model per un elenco di prodotti.
     *
     * Senza database (test degli schemi, comandi) torna vuoto invece di far
     * esplodere chi legge.
     *
     * @param class-string<\Wonder\App\Model> $modelClass
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private static function rows(string $modelClass, array $ids): array
    {
        try {
            $rows = $modelClass::find(
                'product_id IN ('.implode(',', $ids).") AND deleted = 'false'"
            );
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
```

- [ ] **Step 5: Crea le tabelle sul sito di prova**

Le tabelle nuove non esistono ancora nel database del sito: il test
d'integrazione le usa.

Run: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update --local`
Expected: crea `gst_stock`, `gst_stock_movements`, `gst_stock_reservations`,
`gst_stock_alerts` senza errori.

- [ ] **Step 6: Esegui il test e verifica che passi**

Run: `php tests/integrazione/StockLevelsTest.php`
Expected: `8 test, 0 falliti`

- [ ] **Step 7: Commit**

```bash
git add src/Support/Stock/Locations.php src/Support/Stock/Levels.php tests/integrazione/StockLevelsTest.php
git commit -m "Magazzino: sede principale e livelli di giacenza"
```

---

## Task 5: `Stock::apply()`, la porta unica di scrittura

**Files:**
- Create: `src/Support/Stock/Alerts.php`, `src/Support/Stock/Stock.php`
- Modify: `lang/it/gestionale.json` (chiavi sotto `gestionale.errors.stock`)
- Test: `tests/integrazione/StockTest.php`

**Interfaces:**
- Consumes: `Reasons::exists()`, `Reasons::DEFAULT`, `LowStock::decide()`,
  `Levels::of()`, `Locations::mainId()`, `Code::make()`, `Codes::STOCK_MOVEMENT`,
  `UserError::make()`, `Gestionale::feature()`, `Transaction::run()`.
- Produces:
  - `Alerts::refresh(int $productId): string` — torna `LowStock::OPEN`,
    `LowStock::CLOSE` o `LowStock::NONE`, dopo aver scritto.
  - `Stock::apply(array $movement): array{movement_id: int, before: float, after: float, alert: string}`

  `$movement` accetta: `product_id` (int, obbligatorio), `quantity` (float, obbligatorio,
  con segno, diverso da zero), `location_id`, `batch_id`, `supplier_id`, `type`
  (default `adjustment`), `reason`, `unit_cost`, `reference_type`, `reference_id`,
  `source` (default `backend`), `user_id`, `note`.

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/integrazione/StockTest.php`:

```php
<?php
/** php tests/integrazione/StockTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\LowStock;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

try {
    Transaction::run(static function (): void {
        $modello = ProductModel::create([
            'code' => Code::make(ProductModel::class, Codes::MODEL),
            'name' => 'Prova rettifica',
            'slug' => Slug::make('prova-rettifica-'.uniqid()),
            'sku' => 'TST-APP',
            'unit' => 'pz',
            'type' => 'simple',
            'visible' => 'true',
            'position' => 1,
        ]);
        $scheletro = Skeleton::forModel((int) ($modello->insert_id ?? 0), 'Prova rettifica', 'TST-APP');
        $productId = $scheletro['product_id'];

        // La scorta minima si scrive dopo: la crea il generatore dello
        // scheletro, che non la conosce.
        Product::update(['min_stock_quantity' => '5'], $productId);

        check('l\'articolo di prova ha la sua scorta minima', fn () =>
            (float) (Product::findById($productId)['min_stock_quantity'] ?? 0) === 5.0
        );

        check('il primo carico crea giacenza e movimento', function () use ($productId) {
            $esito = Stock::apply([
                'product_id' => $productId,
                'quantity' => 10,
                'reason' => 'initial_stock',
            ]);

            return $esito['before'] === 0.0
                && $esito['after'] === 10.0
                && $esito['movement_id'] > 0
                && Levels::of($productId)['quantity'] === 10.0;
        });

        check('il movimento racconta prima, dopo e chi', function () use ($productId) {
            $riga = StockMovement::find(['product_id' => $productId], 1, 'id', 'DESC');

            return ($riga['type'] ?? '') === 'adjustment'
                && ($riga['reason'] ?? '') === 'initial_stock'
                && (float) ($riga['quantity'] ?? 0) === 10.0
                && (float) ($riga['quantity_before'] ?? -1) === 0.0
                && (float) ($riga['quantity_after'] ?? 0) === 10.0
                && ($riga['source'] ?? '') === 'backend'
                && str_starts_with((string) ($riga['code'] ?? ''), 'mov_');
        });

        check('un secondo movimento parte da dove era rimasto', function () use ($productId) {
            $esito = Stock::apply([
                'product_id' => $productId,
                'quantity' => -3,
                'reason' => 'damaged',
            ]);

            return $esito['before'] === 10.0 && $esito['after'] === 7.0;
        });

        check('la giacenza non si sdoppia: resta una riga sola', function () use ($productId) {
            $righe = StockRow::find([
                'product_id' => $productId,
                'deleted' => 'false',
            ]);
            $righe = isset($righe['id']) ? [$righe] : (array) $righe;

            return count(array_filter($righe, 'is_array')) === 1;
        });

        check('scendere sotto la scorta minima apre l\'avviso', function () use ($productId) {
            $esito = Stock::apply([
                'product_id' => $productId,
                'quantity' => -3,
                'reason' => 'inventory',
            ]);
            $avviso = StockAlert::find(['product_id' => $productId, 'deleted' => 'false'], 1, 'id', 'DESC');

            return $esito['alert'] === LowStock::OPEN
                && (float) ($avviso['threshold'] ?? 0) === 5.0
                && (float) ($avviso['quantity_at_alert'] ?? -1) === 4.0
                && trim((string) ($avviso['resolved_at'] ?? '')) === ''
                && trim((string) ($avviso['notified_at'] ?? '')) === '';
        });

        check('restando sotto soglia l\'avviso non si ripete', function () use ($productId) {
            $esito = Stock::apply(['product_id' => $productId, 'quantity' => -1, 'reason' => 'inventory']);
            $righe = StockAlert::find(['product_id' => $productId, 'deleted' => 'false']);
            $righe = isset($righe['id']) ? [$righe] : (array) $righe;

            return $esito['alert'] === LowStock::NONE
                && count(array_filter($righe, 'is_array')) === 1;
        });

        check('risalire sopra soglia chiude l\'avviso', function () use ($productId) {
            $esito = Stock::apply(['product_id' => $productId, 'quantity' => 20, 'reason' => 'inventory']);
            $avviso = StockAlert::find(['product_id' => $productId, 'deleted' => 'false'], 1, 'id', 'DESC');

            return $esito['alert'] === LowStock::CLOSE
                && trim((string) ($avviso['resolved_at'] ?? '')) !== '';
        });

        check('una rettifica da zero pezzi viene rifiutata con un messaggio', function () use ($productId) {
            try {
                Stock::apply(['product_id' => $productId, 'quantity' => 0]);
            } catch (UserError $e) {
                return $e->key() === 'stock.zero_quantity';
            }

            return false;
        });

        check('una causale inventata viene rifiutata', function () use ($productId) {
            try {
                Stock::apply(['product_id' => $productId, 'quantity' => 1, 'reason' => 'marziana']);
            } catch (UserError $e) {
                return $e->key() === 'stock.unknown_reason';
            }

            return false;
        });

        check('un prodotto che non esiste viene rifiutato', function () {
            try {
                Stock::apply(['product_id' => 0, 'quantity' => 1]);
            } catch (UserError $e) {
                return $e->key() === 'stock.product_missing';
            }

            return false;
        });

        check('senza vendita sotto zero la giacenza non va in negativo', function () use ($productId) {
            $prima = Levels::of($productId)['quantity'];

            try {
                Stock::apply(['product_id' => $productId, 'quantity' => -1000, 'reason' => 'inventory']);
            } catch (UserError $e) {
                return $e->key() === 'stock.insufficient'
                    && Levels::of($productId)['quantity'] === $prima;
            }

            return false;
        });

        check('un rifiuto non lascia movimenti a metà', function () use ($productId) {
            $ultimo = StockMovement::find(['product_id' => $productId], 1, 'id', 'DESC');

            // L'ultimo movimento è ancora il +20 che ha chiuso l'avviso.
            return (float) ($ultimo['quantity'] ?? 0) === 20.0;
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento non resta nessun movimento di prova', function () {
    $articolo = ProductModel::find(['sku' => 'TST-APP', 'deleted' => 'false'], 1);
    $versione = Product::find(['sku' => 'TST-APP', 'deleted' => 'false'], 1);

    // Sparito l'articolo sono spariti anche i suoi movimenti: la transazione
    // annullata riporta indietro tutto quello che c'era dentro.
    return (!is_array($articolo) || $articolo === [])
        && (!is_array($versione) || $versione === []);
});

summary();
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `php tests/integrazione/StockTest.php`
Expected: FAIL — `Class "…\Support\Stock\Stock" not found`.

- [ ] **Step 3: Aggiungi le chiavi di lingua**

In `lang/it/gestionale.json`, sostituisci il nodo `gestionale.errors.stock` con:

```json
"stock": {
    "insufficient": "Non c'è abbastanza giacenza di {{product}}.",
    "zero_quantity": "Questa rettifica non cambia niente: scrivi una quantità diversa da quella di adesso.",
    "unknown_reason": "Scegli una causale per questo movimento.",
    "product_missing": "Questo articolo non esiste più: ricarica la pagina e riprova.",
    "no_location": "Manca la sede del magazzino: apri Set Up → Sedi e salva la sede principale."
}
```

- [ ] **Step 4: Scrivi `src/Support/Stock/Alerts.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;

/**
 * Apre e chiude gli avvisi di scorta minima.
 *
 * La decisione è di `LowStock`, che è pura e si prova con gli array; qui c'è
 * solo la scrittura. Gira dentro la transazione di `Stock::apply()`: un avviso
 * aperto da un movimento annullato deve sparire con lui.
 *
 * L'email non parte da qui. La riga nasce con `notified_at` vuoto e la manda
 * l'attività dello scheduler, raggruppata: dieci rettifiche di fila non devono
 * fare dieci email, e il salvataggio non deve dipendere dal server di posta.
 */
final class Alerts
{
    /** @return LowStock::OPEN|LowStock::CLOSE|LowStock::NONE */
    public static function refresh(int $productId): string
    {
        if ($productId <= 0) {
            return LowStock::NONE;
        }

        try {
            $product = Product::findById($productId);
        } catch (Throwable) {
            return LowStock::NONE;
        }

        if (!is_array($product) || $product === []) {
            return LowStock::NONE;
        }

        $threshold = round((float) ($product['min_stock_quantity'] ?? 0), 3);
        $available = Levels::of($productId)['available'];
        $open = self::openRow($productId);
        $decision = LowStock::decide($threshold, $available, $open !== []);

        if ($decision === LowStock::OPEN) {
            StockAlert::create([
                'product_id' => $productId,
                'location_id' => 0,
                'threshold' => number_format($threshold, 3, '.', ''),
                'quantity_at_alert' => number_format($available, 3, '.', ''),
            ]);
        }

        if ($decision === LowStock::CLOSE && $open !== []) {
            StockAlert::update(
                ['resolved_at' => date('Y-m-d H:i:s')],
                (int) $open['id']
            );
        }

        return $decision;
    }

    /** L'avviso ancora aperto di un prodotto, `[]` se non ce n'è. @return array<string, mixed> */
    public static function openRow(int $productId): array
    {
        // "Non ancora risolto" arriva come NULL da una colonna mai scritta,
        // come stringa vuota da chi scrive dai form e come zeri da MySQL:
        // la condizione le accetta tutte e tre.
        $condition = 'product_id = '.$productId
            ." AND deleted = 'false'"
            ." AND (resolved_at IS NULL OR resolved_at = '' OR resolved_at = '0000-00-00 00:00:00')";

        try {
            $row = StockAlert::find($condition, 1, 'id', 'DESC');
        } catch (Throwable) {
            return [];
        }

        return is_array($row) ? $row : [];
    }
}
```

- [ ] **Step 5: Scrivi `src/Support/Stock/Stock.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use RuntimeException;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Sql\Transaction;

/**
 * **L'unica porta di scrittura del magazzino.**
 *
 * Nessun altro codice tocca `gst_stock` o scrive su `gst_stock_movements`: né
 * le pagine, né i comandi, né i sotto-progetti che verranno. Carichi e
 * trasferimenti (G3), vendite, annullamenti e resi (G4) passano tutti da qui,
 * cambiando `type` e `reference_*`.
 *
 * È l'unico modo perché "giacenza" e "somma dei movimenti" non divergano: la
 * riga si legge con `FOR UPDATE` dentro la transazione, così due ordini
 * contemporanei non prendono lo stesso ultimo pezzo, e il movimento e la nuova
 * quantità si scrivono insieme o non si scrivono affatto.
 *
 * `Transaction::run()` si annida: chi chiama `apply()` da dentro una
 * transazione sua — l'elenco delle giacenze che salva venti righe — ottiene un
 * savepoint, non una seconda transazione.
 */
final class Stock
{
    /**
     * @param array<string, mixed> $movement
     * @return array{movement_id: int, before: float, after: float, alert: string}
     */
    public static function apply(array $movement): array
    {
        $productId = (int) ($movement['product_id'] ?? 0);
        $quantity = round((float) ($movement['quantity'] ?? 0), 3);
        $reason = trim((string) ($movement['reason'] ?? ''));

        if ($quantity === 0.0) {
            throw UserError::make('stock.zero_quantity');
        }

        if ($reason !== '' && !Reasons::exists($reason)) {
            throw UserError::make('stock.unknown_reason');
        }

        $product = $productId > 0 ? Product::findById($productId) : null;

        if (!is_array($product) || $product === []) {
            throw UserError::make('stock.product_missing');
        }

        $keys = [
            'product_id' => $productId,
            'location_id' => (int) ($movement['location_id'] ?? 0) ?: Locations::mainId(),
            'batch_id' => (int) ($movement['batch_id'] ?? 0),
            'supplier_id' => (int) ($movement['supplier_id'] ?? 0),
        ];

        if ($keys['location_id'] <= 0) {
            throw UserError::make('stock.no_location');
        }

        return Transaction::run(static function () use ($keys, $quantity, $movement, $product): array {
            $row = StockRow::findForUpdate(array_merge($keys, ['deleted' => 'false']), 1);
            $row = is_array($row) ? $row : [];
            $before = round((float) ($row['quantity'] ?? 0), 3);
            $after = round($before + $quantity, 3);

            // La vendita sotto zero è una funzionalità di G4: finché è bloccata
            // la giacenza è un muro.
            if ($after < 0 && !Gestionale::feature('backorders')) {
                throw UserError::make('stock.insufficient', [
                    'product' => trim((string) ($product['name'] ?? '')) !== ''
                        ? (string) $product['name']
                        : (string) ($product['sku'] ?? ''),
                ]);
            }

            $created = StockMovement::create(array_merge($keys, [
                'code' => Code::make(StockMovement::class, Codes::STOCK_MOVEMENT),
                'type' => trim((string) ($movement['type'] ?? '')) !== ''
                    ? (string) $movement['type']
                    : 'adjustment',
                'reason' => trim((string) ($movement['reason'] ?? '')),
                'quantity' => self::number($quantity),
                'quantity_before' => self::number($before),
                'quantity_after' => self::number($after),
                'unit_cost' => self::number((float) ($movement['unit_cost'] ?? 0), 4),
                'reference_type' => trim((string) ($movement['reference_type'] ?? '')),
                'reference_id' => (int) ($movement['reference_id'] ?? 0),
                'source' => trim((string) ($movement['source'] ?? '')) !== ''
                    ? (string) $movement['source']
                    : 'backend',
                'user_id' => (int) ($movement['user_id'] ?? 0),
                'note' => (string) ($movement['note'] ?? ''),
            ]));

            if (($created->success ?? false) !== true) {
                // Non è un errore da far leggere: è un guasto, e la transazione
                // riporta indietro tutto.
                throw new RuntimeException('Movimento di magazzino non scritto.');
            }

            if ((int) ($row['id'] ?? 0) > 0) {
                StockRow::update(['quantity' => self::number($after)], (int) $row['id']);
            } else {
                StockRow::create(array_merge($keys, ['quantity' => self::number($after)]));
            }

            return [
                'movement_id' => (int) ($created->insert_id ?? 0),
                'before' => $before,
                'after' => $after,
                'alert' => Alerts::refresh($keys['product_id']),
            ];
        });
    }

    /**
     * La forma che il database accetta sempre.
     *
     * I campi numerici del framework passano per `prepare()`, che con la
     * virgola combina guai: qui i numeri arrivano dal codice, e si scrivono
     * con il punto e i decimali della colonna.
     */
    private static function number(float $value, int $decimals = 3): string
    {
        return number_format($value, $decimals, '.', '');
    }
}
```

- [ ] **Step 6: Esegui il test e verifica che passi**

Run: `php tests/integrazione/StockTest.php`
Expected: `13 test, 0 falliti`

- [ ] **Step 7: Esegui tutta la suite**

Run: `php tests/run.php`
Expected: "Tutti i test del gestionale passano."

- [ ] **Step 8: Commit**

```bash
git add src/Support/Stock/Stock.php src/Support/Stock/Alerts.php lang/it/gestionale.json tests/integrazione/StockTest.php
git commit -m "Magazzino: Stock::apply(), l'unica porta di scrittura"
```

---

## Task 6: L'elenco dei movimenti

**Files:**
- Create: `src/Resources/Stock/StockMovementResource.php`
- Modify: `tests/ConventionsTest.php` (elenco `$sempreAttive`)
- Test: `tests/StockMovementResourceTest.php`

**Interfaces:**
- Consumes: `StockMovement::TYPES`, `StockMovement::typeLabels()`, `Reasons::all()`,
  `Models\Catalog\Product`, `GestionaleResource` (base, `rowsOf()`).
- Produces: `StockMovementResource::path()` = `app/gestionale/movimenti`,
  `StockMovementResource::listUrlFor(int $productId): string` (link dalla scheda
  della versione, usato dal piano 2).

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/StockMovementResourceTest.php`:

```php
<?php
/** php tests/StockMovementResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Stock\StockMovementResource;

$pagine = StockMovementResource::pageSchema()->toArray();
$colonne = [];

foreach (StockMovementResource::tableSchema() as $colonna) {
    $colonne[] = (string) $colonna->name;
}

check('l\'elenco dei movimenti ha il suo indirizzo', fn () =>
    StockMovementResource::path() === 'app/gestionale/movimenti'
);

check('il model dell\'elenco è quello dei movimenti', fn () =>
    StockMovementResource::$model === StockMovement::class
);

check('i movimenti si leggono e basta: niente aggiungi, modifica o elimina', function () use ($pagine) {
    $attive = array_keys(array_filter((array) ($pagine['pages'] ?? [])));

    return $attive === ['list'];
});

check('la riga racconta tutto quello che serve', function () use ($colonne) {
    foreach (['creation', 'product_id', 'type', 'reason', 'quantity', 'quantity_after'] as $nome) {
        if (!in_array($nome, $colonne, true)) {
            return false;
        }
    }

    return true;
});

check('l\'ultimo movimento sta in cima', fn () =>
    StockMovementResource::$orderColumn === 'id'
    && StockMovementResource::$orderDirection === 'DESC'
);

check('il filtro per causale offre le causali vere', function () {
    $filtri = StockMovementResource::tableLayoutSchema()->toArray()['custom_filters'] ?? [];
    $causali = [];

    foreach ($filtri as $filtro) {
        if (($filtro['column'] ?? '') === 'reason') {
            $causali = array_keys((array) ($filtro['array'] ?? []));
        }
    }

    return $causali === array_keys(\Wonder\Plugin\Gestionale\Support\Stock\Reasons::all());
});

check('il filtro per tipo offre i tipi veri', function () {
    $filtri = StockMovementResource::tableLayoutSchema()->toArray()['custom_filters'] ?? [];
    $tipi = [];

    foreach ($filtri as $filtro) {
        if (($filtro['column'] ?? '') === 'type') {
            $tipi = array_keys((array) ($filtro['array'] ?? []));
        }
    }

    return $tipi === StockMovement::TYPES;
});

check('l\'elenco si filtra su una versione dall\'indirizzo', function () {
    $_GET['versione'] = '42';
    $schema = StockMovementResource::querySchema();
    unset($_GET['versione']);

    return str_contains((string) ($schema['condition'] ?? ''), 'product_id = 42');
});

check('un periodo scritto male non arriva al database', function () {
    $_GET['dal'] = "2026-01-01'; DROP TABLE gst_stock; --";
    $schema = StockMovementResource::querySchema();
    unset($_GET['dal']);

    return !str_contains((string) ($schema['condition'] ?? ''), 'DROP');
});

check('un periodo scritto bene diventa una condizione', function () {
    $_GET['dal'] = '2026-09-01';
    $_GET['al'] = '2026-09-30';
    $schema = StockMovementResource::querySchema();
    unset($_GET['dal'], $_GET['al']);

    $condizione = (string) ($schema['condition'] ?? '');

    return str_contains($condizione, "creation >= '2026-09-01 00:00:00'")
        && str_contains($condizione, "creation <= '2026-09-30 23:59:59'");
});

check('il link dalla scheda porta all\'elenco filtrato', fn () =>
    str_ends_with(StockMovementResource::listUrlFor(7), '?versione=7')
);

summary();
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `php tests/StockMovementResourceTest.php`
Expected: FAIL — `Class "…\Resources\Stock\StockMovementResource" not found`.

- [ ] **Step 3: Scrivi `src/Resources/Stock/StockMovementResource.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;

/**
 * "Movimenti": la storia del magazzino, in sola lettura.
 *
 * Risponde a una domanda sola — "perché qui c'è scritto 3?" — e per farlo non
 * serve nessun pulsante: un movimento non si modifica e non si cancella, si
 * corregge con un'altra rettifica. Per questo la pagina ha il solo elenco.
 *
 * I filtri per tipo e causale sono quelli del core; il periodo e la versione
 * arrivano dall'indirizzo, perché la scheda della versione linka qui già
 * filtrata.
 */
final class StockMovementResource extends GestionaleResource
{
    public static string $model = StockMovement::class;
    public static string $orderColumn = 'id';
    public static string $orderDirection = 'DESC';
    public static string $docsPage = 'magazzino/magazzino-movimenti';

    /** @var array<int, string>|null */
    private static ?array $productNames = null;

    public static function path(): string
    {
        return 'app/gestionale/movimenti';
    }

    public static function icon(): string
    {
        return 'bi-arrow-left-right';
    }

    public static function titleLabel(): string
    {
        return 'Movimenti';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'movimento',
            'plural_label' => 'movimenti',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'creation' => 'Quando',
            'product_id' => 'Articolo',
            'type' => 'Tipo',
            'reason' => 'Causale',
            'quantity' => 'Pezzi',
            'quantity_after' => 'Giacenza dopo',
            'note' => 'Nota',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('creation')->text()->size('little'),
            TableColumn::key('product_id')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(
                    static::productNames()[(int) ($row['product_id'] ?? 0)] ?? '—'
                )),
            TableColumn::key('type')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(
                    StockMovement::typeLabels()[(string) ($row['type'] ?? '')] ?? ''
                )),
            TableColumn::key('reason')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(
                    Reasons::label((string) ($row['reason'] ?? ''))
                )),
            // Il segno è l'informazione: "+10" e "−3" si leggono al volo.
            TableColumn::key('quantity')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(
                    static::signed((float) ($row['quantity'] ?? 0))
                )),
            TableColumn::key('quantity_after')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(
                    static::plain((float) ($row['quantity_after'] ?? 0))
                )),
            TableColumn::key('note')->text(),
        ];
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        return TableLayoutSchema::for(static::class)
            ->title('Movimenti')
            ->results()
            ->hideButtonAdd()
            ->filterSearch()
            ->searchFields(['code', 'note'])
            ->filterCustom('Tipo', 'type', StockMovement::typeLabels())
            ->filterCustom('Causale', 'reason', Reasons::all());
    }

    public static function pageSchema(): PageSchema
    {
        // Un movimento non si modifica e non si cancella: si corregge con
        // un'altra rettifica.
        return parent::pageSchema()
            ->only(['list'])
            ->titles(['list' => 'Movimenti'])
            ->subtitles(['list' => 'Ogni pezzo entrato o uscito, con la sua causale. Si legge e basta: una quantità sbagliata si corregge con una rettifica.']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('magazzino', 'Magazzino', 'bi-boxes', 400, ['admin', 'administrator'])
            ->title('Movimenti')
            ->order(20)
            ->authority(['admin', 'administrator']);
    }

    public static function permissionSchema(): PermissionSchema
    {
        // Solo `list`: le altre azioni non esistono nemmeno come rotta.
        return PermissionSchema::for(static::class)->backend(['list'], ['admin', 'administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /**
     * L'elenco filtrato su una versione e su un periodo.
     *
     * Il core rivaluta `querySchema()` a ogni richiesta, quindi leggere la
     * query string qui è sicuro. Le date si controllano con una regex prima di
     * entrare nella condizione: è testo che arriva dall'indirizzo.
     */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        $parts = [];
        $productId = (int) ($_GET['versione'] ?? 0);

        if ($productId > 0) {
            $parts[] = 'product_id = '.$productId;
        }

        if (($from = static::date($_GET['dal'] ?? '')) !== '') {
            $parts[] = "creation >= '".$from." 00:00:00'";
        }

        if (($to = static::date($_GET['al'] ?? '')) !== '') {
            $parts[] = "creation <= '".$to." 23:59:59'";
        }

        if ($parts !== []) {
            $schema['condition'] = implode(' AND ', $parts);
        }

        return $schema;
    }

    /** L'indirizzo dell'elenco filtrato su una versione in vendita. */
    public static function listUrlFor(int $productId): string
    {
        $base = '/backend/'.static::path();

        if (function_exists('__r')) {
            try {
                $named = (string) __r('backend.resource.'.static::slug().'.list');
                $base = $named !== '' ? $named : $base;
            } catch (\Throwable) {
                // Rotta non registrata (test, comandi): resta il percorso.
            }
        }

        return $base.'?versione='.$productId;
    }

    /** Una data `YYYY-MM-DD`, o stringa vuota: niente altro entra in una query. */
    private static function date(mixed $value): string
    {
        $text = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $text) === 1 ? $text : '';
    }

    private static function signed(float $value): string
    {
        return ($value > 0 ? '+' : '').static::plain($value);
    }

    /** I pezzi interi si scrivono interi: "3", non "3,000". */
    private static function plain(float $value): string
    {
        $decimals = round($value, 3) === round($value, 0) ? 0 : 3;

        return number_format($value, $decimals, ',', '.');
    }

    /** @return array<int, string> */
    private static function productNames(): array
    {
        if (self::$productNames !== null) {
            return self::$productNames;
        }

        $names = [];

        foreach (static::rowsOf(Product::class) as $row) {
            $id = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            $names[$id] = $name !== '' ? $name : (string) ($row['sku'] ?? '');
        }

        return self::$productNames = $names;
    }
}
```

- [ ] **Step 4: Aggiungi la Resource alle pagine sempre attive**

In `tests/ConventionsTest.php`, dentro l'array `$sempreAttive`, dopo la riga di
`Catalog\TagResource`, aggiungi:

```php
        // Il magazzino base è sempre attivo: le funzionalità sbloccano le sedi
        // in più, gli acquisti e i lotti, non la giacenza.
        'Wonder\\Plugin\\Gestionale\\Resources\\Stock\\StockMovementResource',
```

- [ ] **Step 5: Scrivi le due pagine di guida**

`docs/user/magazzino-movimenti.md`:

```markdown
---
icon: arrow-left-right
---

# Movimenti

> **Inclusa.** Fa parte del gestionale, non c'è niente da attivare.

## A cosa serve

Ogni volta che la giacenza di un articolo cambia, il gestionale scrive una
riga: quanti pezzi, in che verso, con che causale, chi l'ha fatto e quando.
Questa pagina è l'elenco di quelle righe.

Serve a rispondere alla domanda che prima o poi arriva sempre: **"perché qui
c'è scritto 3?"**.

## Come si legge una riga

| Colonna | Cosa dice |
|---|---|
| **Quando** | data e ora |
| **Articolo** | la versione in vendita che si è mossa |
| **Tipo** | rettifica, vendita, reso, carico… |
| **Causale** | il perché: inventario, danneggiato, regalo… |
| **Pezzi** | `+10` sono entrati, `−3` sono usciti |
| **Giacenza dopo** | quanti ne restavano dopo quel movimento |

## Non si modifica e non si cancella

Un movimento è storia: resta com'è. Se hai sbagliato una rettifica **ne fai
un'altra** che rimette a posto la quantità — così resta scritto anche
l'errore, ed è giusto che sia così.

## Trovare quello che cerchi

In alto ci sono i filtri per **tipo** e **causale**, e la ricerca per codice
del movimento o per nota. Dalla scheda di una versione il pulsante *Vedi tutti
i movimenti* apre questa pagina già filtrata su quell'articolo.
```

`docs/dev/concetti/magazzino.md`:

```markdown
# Magazzino

## Una sola porta

`Support\Stock\Stock::apply()` è **l'unico codice che scrive** su `gst_stock` e
`gst_stock_movements`. Nessuna Resource, nessun comando e nessun sotto-progetto
scrive direttamente: chi deve muovere merce chiama `apply()`.

```php
$esito = Stock::apply([
    'product_id' => 42,
    'quantity' => -3,          // con il segno: negativo esce
    'reason' => 'damaged',     // una chiave di Reasons::all()
    'note' => 'Caduta dallo scaffale',
]);
// ['movement_id' => 1234, 'before' => 10.0, 'after' => 7.0, 'alert' => 'open']
```

Cosa fa, in ordine, dentro `Transaction::run()`:

1. legge la riga di `gst_stock` con `FOR UPDATE`;
2. calcola `quantity_before` e `quantity_after`;
3. scrive il movimento;
4. aggiorna la giacenza;
5. rinfresca l'avviso di scorta (`Alerts::refresh()`).

Il `FOR UPDATE` è il motivo per cui due ordini contemporanei non possono
prendere lo stesso ultimo pezzo. Le transazioni si annidano: chiamare `apply()`
dentro una transazione propria (l'elenco delle giacenze che salva venti righe)
apre un savepoint, non una seconda transazione.

## I parametri

| Chiave | Obbligatoria | Note |
|---|---|---|
| `product_id` | sì | una riga di `gst_products` |
| `quantity` | sì | con il segno, diversa da zero |
| `type` | no | `adjustment` se non la passi; l'enum completo è in `StockMovement::TYPES` |
| `reason` | no | una chiave di `Reasons::all()`; una sconosciuta è un rifiuto |
| `location_id` | no | la sede principale se non la passi (`Locations::mainId()`) |
| `batch_id`, `supplier_id` | no | zero fino a G3 |
| `reference_type`, `reference_id` | no | il documento che ha causato il movimento |
| `source` | no | `backend` se non lo passi; `online`, `import` |
| `user_id`, `note`, `unit_cost` | no | |

## I rifiuti

`apply()` rifiuta con `UserError` — che il backend trasforma in messaggio sul
form, non in una pagina 500:

| Chiave | Quando |
|---|---|
| `stock.zero_quantity` | quantità zero |
| `stock.unknown_reason` | causale che non esiste |
| `stock.product_missing` | prodotto cancellato o inesistente |
| `stock.no_location` | nessuna sede con magazzino |
| `stock.insufficient` | la giacenza andrebbe sotto zero e `backorders` è bloccata |

## Le classi

| Classe | Pura | Cosa fa |
|---|---|---|
| `Reasons` | sì | le causali e le loro etichette |
| `Availability` | sì | disponibile = giacenza − prenotazioni attive |
| `Adjustment` | sì | dalla quantità scritta al movimento (`fromTarget`, `fromDelta`) |
| `LowStock` | sì | `open`, `close` o `none` per l'avviso di scorta |
| `Locations` | no | la sede principale, con cache per richiesta |
| `Levels` | no | giacenza, prenotato e disponibile di uno o più prodotti |
| `Alerts` | no | scrive gli avvisi decisi da `LowStock` |
| `Stock` | no | la porta di scrittura |

Le pure si provano con gli array e senza database: è lì che deve stare la
logica che G3 e G4 rileggeranno.

## Le prenotazioni

`gst_stock_reservations` esiste ma **nasce vuota**: la riempirà il checkout in
G4. `Availability` la legge già, così "disponibile" vuol dire la stessa cosa in
backend e in vetrina fin da ora.
```

- [ ] **Step 6: Aggiungi le due pagine ai sommari**

In `docs/user/SUMMARY.md`, dopo il gruppo `## Catalogo`, aggiungi:

```markdown
## Magazzino

* [Movimenti](magazzino-movimenti.md)
```

In `docs/dev/SUMMARY.md`, dentro `## Concetti`, dopo la riga del catalogo:

```markdown
* [Magazzino](concetti/magazzino.md)
```

- [ ] **Step 7: Esegui i test e verifica che passino**

Run: `php tests/StockMovementResourceTest.php && php tests/ConventionsTest.php && php tests/DocsPagesTest.php`
Expected: tutti e tre `0 falliti`.

- [ ] **Step 8: Esegui tutta la suite**

Run: `php tests/run.php`
Expected: "Tutti i test del gestionale passano."

- [ ] **Step 9: Commit**

```bash
git add src/Resources/Stock tests/StockMovementResourceTest.php tests/ConventionsTest.php docs/user docs/dev
git commit -m "Magazzino: elenco dei movimenti e guide"
```

---

## Task 7: Verifica sul sito di prova e chiusura del piano

**Files:**
- Modify: `TODO.md` (spunta il piano 1)

**Interfaces:**
- Consumes: tutto quello che hanno prodotto i task 1–6.
- Produces: niente codice nuovo.

- [ ] **Step 1: Aggiorna il database del sito di prova**

Run: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update --local`
Expected: nessun errore; le quattro tabelle ci sono già dal task 4, la seconda
esecuzione non deve cambiare niente.

- [ ] **Step 2: Controlla che una seconda esecuzione sia davvero inerte**

Run: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update --local`
Expected: stesso esito, nessuna ALTER TABLE.

- [ ] **Step 3: Prepara qualcosa da guardare nel browser**

Run dalla cartella del sito:

```bash
php forge gestionale:demo
```

Expected: i tre articoli di prova del catalogo. Poi, sempre dalla cartella del
sito, un movimento vero da riga di comando:

```bash
php -r '
$root = getcwd();
$GLOBALS["ROOT"] = $root;
require $root."/vendor/autoload.php";
require $root."/vendor/wonder-image/app/wonder-image.php";
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
$p = Product::find(["deleted" => "false"], 1, "id", "ASC");
print_r(Stock::apply(["product_id" => (int) $p["id"], "quantity" => 12, "reason" => "initial_stock"]));
'
```

Expected: stampa `before => 0`, `after => 12`, un `movement_id` maggiore di
zero. (Quantità intere: il core installato arrotonda i decimali, vedi i vincoli
globali.)

- [ ] **Step 4: Verifica nel browser**

Apri `https://ecommerce.test/backend/app/gestionale/movimenti/` e controlla:

1. nel menu c'è la sezione **Magazzino** con la voce **Movimenti**;
2. la riga mostra data, articolo, "Rettifica", "Giacenza iniziale", `+12`, `12`;
3. **non ci sono** i pulsanti Aggiungi, Modifica ed Elimina;
4. i filtri *Tipo* e *Causale* filtrano davvero;
5. `…/movimenti/?versione=<id del prodotto>` mostra solo i suoi movimenti;
6. `…/movimenti/?dal=2020-01-01&al=2020-12-31` mostra un elenco vuoto;
7. il pulsante **Guida** apre la pagina dei movimenti.

- [ ] **Step 5: Ripulisci il sito di prova**

Run dalla cartella del sito:

```bash
php forge gestionale:demo --fresh
```

Poi cancella il movimento e la giacenza rimasti (i dati di prova del magazzino
arrivano con il piano 2):

```bash
php -r '
$root = getcwd();
$GLOBALS["ROOT"] = $root;
require $root."/vendor/autoload.php";
require $root."/vendor/wonder-image/app/wonder-image.php";
use Wonder\Sql\Connection;
$db = Connection::Connect("main");
foreach (["gst_stock_movements", "gst_stock", "gst_stock_alerts"] as $t) {
    $db->query("DELETE FROM `".$t."`");
}
echo "pulito\n";
'
```

Expected: `pulito`, e l'elenco Movimenti nel browser torna vuoto.

- [ ] **Step 6: Spunta il piano nel TODO**

In `TODO.md`, sotto **G2b**, sostituisci la riga del piano 1 con:

```markdown
    - [x] Piano 1 scritto ed eseguito (2026-09-22): `docs/superpowers/plans/2026-09-22-fondamenta-del-magazzino.md` — le quattro tabelle del magazzino, `Stock::apply()` come unica porta di scrittura (transazione + `FOR UPDATE` + avviso di scorta), le classi di `Support\Stock` (quattro pure: `Reasons`, `Availability`, `Adjustment`, `LowStock`; tre di servizio: `Locations`, `Levels`, `Alerts`), elenco *Movimenti* in sola lettura con i filtri, guida sviluppatore e guida commerciante. Verificato nel browser sul sito di prova.
```

- [ ] **Step 7: Esegui tutta la suite un'ultima volta**

Run: `php tests/run.php`
Expected: "Tutti i test del gestionale passano."

- [ ] **Step 8: Commit e merge**

```bash
git add TODO.md
git commit -m "G2b: piano 1 eseguito, fondamenta del magazzino"
git checkout main
git merge --no-ff feature/fondamenta-del-magazzino -m "Fondamenta del magazzino (piano 1 di G2b)"
git branch -d feature/fondamenta-del-magazzino
git push
```

Expected: push riuscito; la CI di GitHub Actions resta verde.
