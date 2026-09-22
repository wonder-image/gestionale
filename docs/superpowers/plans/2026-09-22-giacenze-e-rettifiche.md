# Giacenze e rettifiche — Piano 2 di 4 di G2b

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** dare al commerciante le due pagine con cui tocca la giacenza — un
elenco *Giacenze* dove le quantità si scrivono riga per riga e si salvano in
blocco, e una *Rettifica* per il caso singolo con la sua nota — e far vedere la
giacenza dentro la scheda del prodotto.

**Architecture:** nessuna delle due pagine scrive sul database: compongono un
movimento e lo passano a `Stock::apply()` (piano 1), dentro una transazione
sola. *Giacenze* è una pagina-form (`isFormPage()`) con un campo numerico per
riga, ricerca, filtri e paginazione scritti nel modulo; *Rettifica* è una
seconda pagina-form fuori dal menu che legge `?versione=`. La differenza fra
quello che c'era e quello che è stato scritto la calcola una classe pura,
`Stocktake`.

**Tech Stack:** PHP 8.2, `wonder-image/app` 2.3.0 (`Resource::isFormPage()`,
`submitFormPage()`, `FlashAlert`, `Elements\Components`), harness di test del
modulo, MySQL del sito di prova `ecommerce-site`.

**Spec:** [G2b — Magazzino base e anagrafiche](../specs/2026-09-21-magazzino-e-anagrafiche-design.md)

## Global Constraints

- Lingua: **italiano** nei commenti, nei testi e nei nomi dei test; **inglese**
  per classi, tabelle e colonne.
- **Nessun codice di questo piano scrive su `gst_stock` o
  `gst_stock_movements`:** si passa sempre da `Stock::apply()` (G2b.1).
- Un rifiuto che deve leggere una persona è `UserError::make('chiave')` dentro
  un form, `UserError::refusal('chiave')` dove il core intercetta
  `RuntimeException` (cancellazioni).
- Le due pagine nuove estendono `NavigationOnlyResource` del core (come il
  pannello Funzionalità di G1), non `GestionaleResource`: `ConventionsTest` e
  `DocsPagesTest` guardano solo le seconde, quindi niente `$feature` e niente
  `$docsPage` — il pulsante Guida si mette con `->docs(...)` nel `pageSchema()`.
- Ogni Resource che dichiara `$docsPage` deve puntare a una pagina che esiste
  nel `SUMMARY.md` della sua guida (`tests/DocsPagesTest.php`).
- Le colonne decimali si dichiarano con `Support\Columns::decimal()`: il core
  genera `DECIMAL(10,2)` e ignora `decimals()`.
- Testo che finisce nell'HTML passa da `static::escape()`
  (`GestionaleResource`).
- **Prenotato e disponibile si mostrano solo con la funzionalità `orders`
  sbloccata** (G2b.4): in G2b sarebbero colonne sempre a zero.
- Ogni task finisce con un commit sul ramo `feature/giacenze-e-rettifiche` di
  `packages/gestionale`; `php tests/run.php` deve restare verde.
- I test d'integrazione annullano sempre quello che scrivono (transazione +
  eccezione, come `tests/integrazione/StockTest.php`).

---

## File Structure

**Da creare**

| File | Responsabilità |
|---|---|
| `src/Support/Stock/Stocktake.php` | **pura** — dalle quantità scritte alle righe davvero cambiate |
| `src/Resources/Stock/StockLevelResource.php` | pagina-form *Giacenze*: righe, filtri, paginazione, salvataggio in blocco |
| `src/Resources/Stock/StockAdjustmentResource.php` | pagina-form *Rettifica*: una versione, una causale, una nota |
| `docs/user/magazzino-giacenze.md` | guida commerciante |
| `tests/StocktakeTest.php`, `tests/StockPagesTest.php` | unitari |
| `tests/integrazione/StockPagesTest.php` | integrazione delle due pagine |

**Da modificare**

| File | Modifica |
|---|---|
| `src/Support/Stock/StockHistory.php` | `latest()`: gli ultimi movimenti di una versione |
| `src/Resources/Catalog/ProductModelResource.php` | colonna *Giacenza* nella griglia delle versioni, casella *Giacenza* nel riquadro Prodotto quando la versione è una sola |
| `src/Resources/Catalog/ProductResource.php` | riquadro *Magazzino* nella scheda della versione |
| `src/Seeding/CatalogDemo.php` | giacenza iniziale dei tre articoli di prova |
| `docs/user/SUMMARY.md`, `docs/dev/concetti/magazzino.md` | la pagina nuova e le due pagine nel capitolo |

---

## Task 1: Dalla quantità scritta alle righe cambiate

**Files:**
- Create: `src/Support/Stock/Stocktake.php`
- Modify: `src/Support/Stock/StockHistory.php`
- Test: `tests/StocktakeTest.php`

**Interfaces:**
- Consumes: `Adjustment::fromTarget()` (piano 1).
- Produces:
  - `Stocktake::changes(array $current, array $posted): array<int, array{delta: float, before: float, after: float}>`
    — `$current` e `$posted` sono `[productId => quantità]`; torna solo le righe
    davvero cambiate.
  - `Stocktake::quantity(mixed $value): ?float` — il numero scritto da una
    persona (virgola, punto, spazi) o `null` se la casella è vuota o non è un
    numero.
  - `StockHistory::latest(int $productId, int $limit = 10): list<array<string, mixed>>`

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/StocktakeTest.php`:

```php
<?php
/** php tests/StocktakeTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\Stocktake;

check('una casella vuota non è uno zero', fn () =>
    Stocktake::quantity('') === null
    && Stocktake::quantity(null) === null
    && Stocktake::quantity('   ') === null
);

check('la virgola è un decimale, come la scrivono tutti', fn () =>
    Stocktake::quantity('2,5') === 2.5
    && Stocktake::quantity('2.5') === 2.5
    && Stocktake::quantity('1.234,5') === 1234.5
);

check('quello che non è un numero non diventa zero', fn () =>
    Stocktake::quantity('abc') === null
    && Stocktake::quantity('--3') === null
);

check('lo zero scritto è uno zero vero', fn () =>
    Stocktake::quantity('0') === 0.0
);

check('cambia solo quello che è stato cambiato', fn () =>
    Stocktake::changes([1 => 10.0, 2 => 5.0], [1 => 10.0, 2 => 5.0]) === []
);

check('una riga scritta diversa diventa una differenza', function () {
    $cambi = Stocktake::changes([1 => 10.0, 2 => 5.0], [1 => 12.0]);

    return array_keys($cambi) === [1]
        && $cambi[1] === ['delta' => 2.0, 'before' => 10.0, 'after' => 12.0];
});

check('una riga non spedita resta com\'è', fn () =>
    Stocktake::changes([1 => 10.0, 2 => 5.0], [2 => 5.0]) === []
);

check('una riga senza giacenza parte da zero', function () {
    $cambi = Stocktake::changes([], [7 => 3.0]);

    return $cambi[7] === ['delta' => 3.0, 'before' => 0.0, 'after' => 3.0];
});

check('scrivere zero dove c\'era qualcosa svuota davvero', function () {
    $cambi = Stocktake::changes([1 => 4.0], [1 => 0.0]);

    return $cambi[1]['delta'] === -4.0 && $cambi[1]['after'] === 0.0;
});

check('i decimali non lasciano briciole', function () {
    $cambi = Stocktake::changes([1 => 0.1], [1 => 0.3]);

    return $cambi[1]['delta'] === 0.2;
});

summary();
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `php tests/StocktakeTest.php`
Expected: FAIL — `Class "…\Support\Stock\Stocktake" not found`.

- [ ] **Step 3: Scrivi `src/Support/Stock/Stocktake.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

/**
 * L'inventario: le quantità scritte a mano, riga per riga, e la differenza
 * con quelle che c'erano.
 *
 * Due regole che vengono da come si lavora davvero:
 *
 * - **una casella vuota non è uno zero.** Chi conta lo scaffale scrive solo le
 *   righe che ha contato; le altre non le ha viste, e non vanno toccate.
 * - **uno zero scritto è uno zero vero.** "Non ce n'è più" è un'informazione,
 *   e deve diventare un movimento.
 *
 * Pura: prende array, torna array, non sa cosa sia un database.
 */
final class Stocktake
{
    /**
     * Le righe davvero cambiate, con la differenza da registrare.
     *
     * @param array<int, float> $current quantità di adesso, `[productId => q]`
     * @param array<int, float> $posted quantità scritte, `[productId => q]`
     * @return array<int, array{delta: float, before: float, after: float}>
     */
    public static function changes(array $current, array $posted): array
    {
        $changes = [];

        foreach ($posted as $productId => $target) {
            $id = (int) $productId;
            $change = Adjustment::fromTarget((float) ($current[$id] ?? 0), (float) $target);

            if ($change['delta'] !== 0.0) {
                $changes[$id] = $change;
            }
        }

        return $changes;
    }

    /**
     * Il numero scritto in una casella, o `null` se non c'è.
     *
     * In Italia i decimali si scrivono con la virgola e le migliaia con il
     * punto: `1.234,5` è milleduecentotrentaquattro e mezzo.
     */
    public static function quantity(mixed $value): ?float
    {
        if (is_array($value)) {
            return null;
        }

        $text = str_replace([' ', "\u{a0}"], '', trim((string) ($value ?? '')));

        if ($text === '') {
            return null;
        }

        if (str_contains($text, ',')) {
            $text = str_replace('.', '', $text);
        }

        $text = str_replace(',', '.', $text);

        return is_numeric($text) ? round((float) $text, 3) : null;
    }
}
```

- [ ] **Step 4: Aggiungi `latest()` a `src/Support/Stock/StockHistory.php`**

Dopo `hasMovements()`:

```php
    /**
     * Gli ultimi movimenti di una versione, dal più recente.
     *
     * Li mostra la scheda: è lì che si risponde a "perché qui c'è scritto 3?"
     * senza andare in Movimenti.
     *
     * @return list<array<string, mixed>>
     */
    public static function latest(int $productId, int $limit = 10): array
    {
        if ($productId <= 0) {
            return [];
        }

        try {
            $rows = StockMovement::find(
                ['product_id' => $productId, 'deleted' => 'false'],
                max(1, $limit),
                'id',
                'DESC'
            );
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
```

- [ ] **Step 5: Esegui il test e verifica che passi**

Run: `php tests/StocktakeTest.php`
Expected: `10 test, 0 falliti`

- [ ] **Step 6: Commit**

```bash
git add src/Support/Stock/Stocktake.php src/Support/Stock/StockHistory.php tests/StocktakeTest.php
git commit -m "Magazzino: la differenza fra quello che c'era e quello che è stato scritto"
```

---

## Task 2: La pagina Rettifica

**Files:**
- Create: `src/Resources/Stock/StockAdjustmentResource.php`
- Modify: `tests/ConventionsTest.php`, `docs/user/SUMMARY.md`, `docs/user/magazzino-giacenze.md` (creata nel task 5 — qui basta il rinvio, vedi Step 5)
- Test: `tests/StockPagesTest.php`

**Interfaces:**
- Consumes: `Stock::apply()`, `Reasons::all()`, `Reasons::DEFAULT`,
  `Levels::of()`, `Stocktake::quantity()`, `Adjustment::fromTarget()`,
  `Adjustment::fromDelta()`, `UserError::make()`.
- Produces:
  - `StockAdjustmentResource::path()` = `app/gestionale/rettifica`
  - `StockAdjustmentResource::urlFor(int $productId, string $back = ''): string`
    — il link che apre la pagina su una versione; `$back` è l'indirizzo a cui
    tornare dopo il salvataggio.

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/StockPagesTest.php`:

```php
<?php
/** php tests/StockPagesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;

$campi = static function (string $resource): array {
    $keys = [];

    foreach ($resource::formSchema() as $field) {
        $keys[] = (string) $field->name;
    }

    return $keys;
};

check('la rettifica ha il suo indirizzo ed è una pagina-form', fn () =>
    StockAdjustmentResource::path() === 'app/gestionale/rettifica'
    && StockAdjustmentResource::isFormPage() === true
);

check('la rettifica non sta nel menu: ci si arriva da una riga', fn () =>
    StockAdjustmentResource::navigationSchema()->toArray()['enabled'] === false
);

check('chiede quantità, causale e nota, e come leggerla', function () use ($campi) {
    $keys = $campi(StockAdjustmentResource::class);

    return in_array('mode', $keys, true)
        && in_array('quantity', $keys, true)
        && in_array('reason', $keys, true)
        && in_array('note', $keys, true);
});

check('le causali sono quelle vere, con l\'inventario già scelto', function () {
    foreach (StockAdjustmentResource::formSchema() as $field) {
        if ((string) $field->name === 'reason') {
            // Un select tiene le voci sotto `options`.
            return array_keys((array) $field->get('options')) === array_keys(Reasons::all())
                && $field->get('value') === Reasons::DEFAULT;
        }
    }

    return false;
});

check('il link porta la versione e la strada del ritorno', function () {
    $url = StockAdjustmentResource::urlFor(7, '/backend/app/gestionale/giacenze/?p=2');

    return str_contains($url, 'versione=7')
        && str_contains($url, 'torna=');
});

check('la strada del ritorno accetta solo indirizzi di questo backend', fn () =>
    // Un `torna=https://altrove.example` sarebbe un redirect aperto.
    StockAdjustmentResource::backUrlFrom('https://altrove.example/x') === ''
    && StockAdjustmentResource::backUrlFrom('/backend/app/gestionale/giacenze/?p=2')
        === '/backend/app/gestionale/giacenze/?p=2'
    && StockAdjustmentResource::backUrlFrom('//altrove.example') === ''
);

summary();
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `php tests/StockPagesTest.php`
Expected: FAIL — `Class "…\Resources\Stock\StockAdjustmentResource" not found`.

- [ ] **Step 3: Scrivi `src/Resources/Stock/StockAdjustmentResource.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

use Throwable;
use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\App\LegacyGlobals;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Adjustment;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Plugin\Gestionale\Support\Stock\Stocktake;

/**
 * "Rettifica": cambiare la giacenza di **una** versione, lasciando scritto il
 * perché.
 *
 * È la porta del caso singolo — il pezzo rotto, il regalo, l'errore di conta —
 * e l'unica che chiede una nota. L'elenco *Giacenze* serve invece a sistemare
 * molte righe insieme, e lì la causale è una sola per tutta la schermata.
 *
 * Non è un elenco CRUD: non c'è niente da elencare, la riga da cambiare arriva
 * dall'indirizzo (`?versione=`). Per questo è una pagina-form.
 */
final class StockAdjustmentResource extends NavigationOnlyResource
{
    public static function path(): string
    {
        return 'app/gestionale/rettifica';
    }

    public static function icon(): string
    {
        return 'bi-pencil-square';
    }

    public static function titleLabel(): string
    {
        return 'Rettifica';
    }

    public static function isFormPage(): bool
    {
        return true;
    }

    /** Il link che apre la pagina su una versione, con la strada del ritorno. */
    public static function urlFor(int $productId, string $back = ''): string
    {
        $base = '/backend/'.static::path();

        if (function_exists('__r')) {
            try {
                $named = (string) __r('backend.resource.'.static::slug().'.form');
                $base = $named !== '' ? $named : $base;
            } catch (Throwable) {
                // Rotta non registrata (test, comandi): resta il percorso.
            }
        }

        $url = $base.'?versione='.$productId;

        return $back === '' ? $url : $url.'&torna='.rawurlencode($back);
    }

    /**
     * L'indirizzo del ritorno, se è di questo backend.
     *
     * Un `torna=` che arriva dalla query string è testo di chiunque: accettare
     * un indirizzo esterno vorrebbe dire spedire il commerciante altrove dopo
     * un salvataggio andato a buon fine.
     */
    public static function backUrlFrom(mixed $value): string
    {
        $url = trim((string) ($value ?? ''));

        if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return '';
        }

        return $url;
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('mode')
                ->select([
                    'target' => 'Adesso ce ne sono',
                    'delta' => 'Aggiungi o togli',
                ])
                ->value('target')
                ->label('Come la scrivi')
                ->required(),
            FormField::key('quantity')
                ->number()
                ->decimal(3)
                ->label('Quantità')
                ->required(),
            FormField::key('reason')
                ->select(Reasons::all())
                ->value(Reasons::DEFAULT)
                ->label('Causale')
                ->required(),
            FormField::key('note')->textarea()->label('Nota'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make(static::productTitle())
                        ->tooltip('Ogni rettifica lascia un movimento con la sua causale: è quello che poi spiega la giacenza di oggi.')
                        ->columnSpan(12),
                    RichText::make(static::currentLine())->columnSpan(12),
                    static::getInput('mode')->columnSpan(4),
                    static::getInput('quantity')->columnSpan(4),
                    static::getInput('reason')->columnSpan(4),
                    static::getInput('note')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ])->columns(12);
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only([])
            ->titles(['form' => 'Rettifica la giacenza'])
            ->subtitles(['form' => 'Scrivi quanti pezzi ci sono adesso, oppure quanti ne aggiungi o ne togli. La causale e la nota restano scritte nei movimenti.'])
            ->docs(Gestionale::docsUrl('magazzino/magazzino-giacenze'), 'form');
    }

    public static function navigationSchema(): NavigationSchema
    {
        // Fuori dal menu: ci si arriva dalla riga di una versione.
        return NavigationSchema::for(static::class)
            ->inSection('magazzino')
            ->title('Rettifica')
            ->authority(['admin', 'administrator'])
            ->enabled(false);
    }

    /** La versione su cui si sta lavorando, `0` se l'indirizzo non la dice. */
    public static function productId(): int
    {
        return (int) ($_GET['versione'] ?? 0);
    }

    public static function submitFormPage(array $values): string
    {
        $productId = static::productId();
        $product = $productId > 0 ? Product::findById($productId) : null;

        if (!is_array($product) || $product === []) {
            throw UserError::make('stock.product_missing');
        }

        $quantity = Stocktake::quantity($values['quantity'] ?? null);

        if ($quantity === null) {
            throw UserError::make('stock.quantity_missing');
        }

        $current = Levels::of($productId)['quantity'];
        $change = ($values['mode'] ?? 'target') === 'delta'
            ? Adjustment::fromDelta($current, $quantity)
            : Adjustment::fromTarget($current, $quantity);

        if ($change['delta'] === 0.0) {
            throw UserError::make('stock.zero_quantity');
        }

        $user = LegacyGlobals::get('USER');

        Stock::apply([
            'product_id' => $productId,
            'quantity' => $change['delta'],
            'reason' => (string) ($values['reason'] ?? Reasons::DEFAULT),
            'note' => (string) ($values['note'] ?? ''),
            'user_id' => is_object($user) ? (int) ($user->id ?? 0) : 0,
        ]);

        $message = 'Giacenza di '.static::productName($product).': da '
            .static::number($change['before']).' a '.static::number($change['after']).'.';

        static::goBack($message);

        return $message;
    }

    /**
     * Torna da dove si era arrivati.
     *
     * Il core, dopo una pagina-form, rimanda sempre alla pagina stessa: qui
     * vorrebbe dire restare su una rettifica già fatta. Con `torna=` si torna
     * invece all'elenco o alla scheda, nel punto in cui si era.
     */
    private static function goBack(string $message): void
    {
        $back = static::backUrlFrom($_GET['torna'] ?? '');

        if ($back === '' || headers_sent()) {
            return;
        }

        FlashAlert::saved($message);
        header('Location: '.$back);
        exit();
    }

    private static function productTitle(): string
    {
        $product = Product::findById(static::productId());

        return is_array($product) && $product !== []
            ? 'Rettifica: '.static::productName($product)
            : 'Rettifica';
    }

    /** La riga che dice cosa c'è adesso, sopra le caselle. */
    private static function currentLine(): string
    {
        $productId = static::productId();

        if ($productId <= 0) {
            return '<span class="text-danger">Apri questa pagina dalla riga di una versione.</span>';
        }

        $levels = Levels::of($productId);
        $parts = ['<b>Adesso:</b> '.static::escape(static::number($levels['quantity'])).' pezzi'];

        if (Gestionale::feature('orders')) {
            $parts[] = 'impegnati '.static::escape(static::number($levels['reserved']));
            $parts[] = 'disponibili '.static::escape(static::number($levels['available']));
        }

        return implode(' · ', $parts);
    }

    /** @param array<string, mixed> $product */
    private static function productName(array $product): string
    {
        $model = ProductModel::findById((int) ($product['product_model_id'] ?? 0));
        $article = is_array($model) ? trim((string) ($model['name'] ?? '')) : '';
        $version = trim((string) ($product['name'] ?? ''));

        if ($article === '') {
            $article = trim((string) ($product['sku'] ?? ''));
        }

        return $version === '' ? $article : $article.' — '.$version;
    }

    /** I pezzi interi si scrivono interi: "3", non "3,000". */
    private static function number(float $value): string
    {
        $decimals = round($value, 3) === round($value, 0) ? 0 : 3;

        return number_format($value, $decimals, ',', '.');
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
```

- [ ] **Step 4: Aggiungi la chiave di lingua mancante**

In `lang/it/gestionale.json`, dentro `gestionale.errors.stock`, aggiungi:

```json
"quantity_missing": "Scrivi quanti pezzi: la casella è vuota."
```

- [ ] **Step 5: Scrivi la guida**

`tests/ConventionsTest.php` **non va toccato**: guarda solo le Resource che
estendono `GestionaleResource`, e queste due pagine-form estendono
`NavigationOnlyResource` del core, come il pannello Funzionalità. Per lo stesso
motivo non dichiarano `$docsPage` (che serve a `GestionaleResource::withDocs()`):
il pulsante Guida lo mettono da sé, con `->docs(...)` nel `pageSchema()`.

Crea `docs/user/magazzino-giacenze.md` con la prima parte della guida (il resto
lo completa il task 5):

```markdown
---
icon: boxes
---

# Giacenze e rettifiche

> **Inclusa.** Fa parte del gestionale, non c'è niente da attivare.

## Rettificare una riga sola

Dalla riga di una versione — nell'elenco *Giacenze*, nella scheda del prodotto
o in quella della versione — il pulsante **Rettifica** apre una pagina che
chiede tre cose:

- **Come la scrivi:** *Adesso ce ne sono* (scrivi il totale che hai contato)
  oppure *Aggiungi o togli* (scrivi `-2` se ne hai buttati due).
- **Causale:** inventario, danneggiato, regalo, uso interno, scaduto, giacenza
  iniziale, altro.
- **Nota:** facoltativa, ma è quella che fra sei mesi spiega cosa era successo.

Salvando torni da dove eri arrivato, e nei *Movimenti* compare la riga nuova.
```

In `docs/user/SUMMARY.md`, sotto `## Magazzino`, **prima** di Movimenti:

```markdown
* [Giacenze e rettifiche](magazzino-giacenze.md)
```

- [ ] **Step 6: Esegui i test e verifica che passino**

Run: `php tests/StockPagesTest.php && php tests/DocsPagesTest.php`
Expected: tutti e due `0 falliti`.

- [ ] **Step 7: Commit**

```bash
git add src/Resources/Stock/StockAdjustmentResource.php tests/StockPagesTest.php lang/it/gestionale.json docs/user
git commit -m "Magazzino: la pagina Rettifica"
```

---

## Task 3: La pagina Giacenze

**Files:**
- Create: `src/Resources/Stock/StockLevelResource.php`
- Modify: `tests/StockPagesTest.php`
- Test: `tests/integrazione/StockPagesTest.php`

**Interfaces:**
- Consumes: `Levels::forProducts()`, `Stocktake::changes()`,
  `Stocktake::quantity()`, `Stock::apply()`, `Reasons::all()`,
  `StockAdjustmentResource::urlFor()`, `Alerts::openRow()`.
- Produces:
  - `StockLevelResource::path()` = `app/gestionale/giacenze`
  - `StockLevelResource::rows(): list<array<string, mixed>>` — le righe della
    pagina corrente, già filtrate
  - `StockLevelResource::PER_PAGE` = `50`

- [ ] **Step 1: Aggiungi al test unitario i controlli della pagina**

In `tests/StockPagesTest.php`, prima di `summary();`:

```php
check('le giacenze hanno il loro indirizzo e sono una pagina-form', fn () =>
    StockLevelResource::path() === 'app/gestionale/giacenze'
    && StockLevelResource::isFormPage() === true
);

check('le giacenze stanno nel menu Magazzino, prima dei movimenti', function () {
    $nav = StockLevelResource::navigationSchema()->toArray();

    return ($nav['enabled'] ?? true) !== false
        && ($nav['section_key'] ?? '') === 'magazzino'
        && (int) ($nav['order'] ?? 0) < 20;
});

check('si lavora cinquanta righe per volta', fn () =>
    StockLevelResource::PER_PAGE === 50
);

check('la causale della schermata parte dall\'inventario', function () {
    foreach (StockLevelResource::formSchema() as $field) {
        if ((string) $field->name === 'reason') {
            return $field->get('value') === Reasons::DEFAULT;
        }
    }

    return false;
});

check('la ricerca non entra nella query così com\'è', fn () =>
    // Il testo arriva dall'indirizzo: dentro la condizione ci va solo quello
    // che resta dopo la pulizia.
    StockLevelResource::searchTerm("Rosso'; DROP TABLE gst_stock; --") === 'Rosso DROP TABLE gst_stock '
);

check('una pagina fuori scala torna alla prima', fn () =>
    StockLevelResource::pageNumber('0') === 1
    && StockLevelResource::pageNumber('-4') === 1
    && StockLevelResource::pageNumber('abc') === 1
    && StockLevelResource::pageNumber('3') === 3
);
```

E aggiungi in cima al file, con gli altri `use`:

```php
use Wonder\Plugin\Gestionale\Resources\Stock\StockLevelResource;
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `php tests/StockPagesTest.php`
Expected: FAIL — `Class "…\Resources\Stock\StockLevelResource" not found`.

- [ ] **Step 3: Scrivi `src/Resources/Stock/StockLevelResource.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

use Throwable;
use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\App\LegacyGlobals;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Plugin\Gestionale\Support\Stock\Stocktake;
use Wonder\Sql\Transaction;

/**
 * "Giacenze": quante ne hai di ogni versione in vendita, e la casella per
 * scriverlo.
 *
 * Non è un elenco CRUD ma una **pagina-form**: è l'unico modo per scrivere
 * cinquanta quantità e salvarle in un colpo solo, che è come si carica un
 * catalogo la prima volta e come si chiude un inventario.
 *
 * Il prezzo di questa scelta è che ricerca, filtri e paginazione non arrivano
 * dall'elenco del core: sono scritti qui, e passano dall'indirizzo. I filtri
 * sono link perché un modulo GET dentro il form del salvataggio non si può
 * annidare.
 *
 * Nessuna riga viene scritta direttamente: le differenze diventano movimenti
 * con `Stock::apply()`, tutte dentro una transazione sola.
 */
final class StockLevelResource extends NavigationOnlyResource
{
    public const PER_PAGE = 50;

    /** @var list<array<string, mixed>>|null */
    private static ?array $rows = null;

    public static function path(): string
    {
        return 'app/gestionale/giacenze';
    }

    public static function icon(): string
    {
        return 'bi-boxes';
    }

    public static function titleLabel(): string
    {
        return 'Giacenze';
    }

    public static function isFormPage(): bool
    {
        return true;
    }

    public static function formSchema(): array
    {
        $fields = [
            FormField::key('reason')
                ->select(Reasons::all())
                ->value(Reasons::DEFAULT)
                ->label('Causale di questa schermata')
                ->required(),
            // I filtri viaggiano con il form: la rotta del salvataggio non ha
            // query string, e senza questo si tornerebbe alla prima pagina.
            FormField::key('back')->hidden()->value(static::currentUrl()),
        ];

        foreach (static::rows() as $row) {
            $fields[] = FormField::key('quantity_'.(int) $row['id'])
                ->number()
                ->decimal(3)
                ->label('Giacenza')
                ->value(static::plain((float) $row['quantity']));
        }

        return $fields;
    }

    public static function formLayoutSchema(): ?Form
    {
        $rows = static::rows();
        $components = [
            SectionTitle::make('Giacenze')
                ->tooltip('Scrivi quante ne hai e salva: ogni riga cambiata diventa un movimento con la causale qui sopra. Le righe che non tocchi restano come sono.')
                ->columnSpan(12),
            RichText::make(static::filtersBar())->columnSpan(12),
            static::getInput('reason')->columnSpan(4),
        ];

        if ($rows === []) {
            $components[] = RichText::make(
                '<p class="mb-0">Nessuna versione in vendita con questi filtri.</p>'
            )->columnSpan(12);
        }

        foreach ($rows as $row) {
            $components[] = RichText::make(static::rowLabel($row))->columnSpan(7);
            $components[] = static::getInput('quantity_'.(int) $row['id'])->columnSpan(2);
            $components[] = RichText::make(static::rowSide($row))->columnSpan(3);
        }

        $components[] = RichText::make(static::pagination())->columnSpan(12);

        return (new Form)->components([
            (new Container)->components([
                (new Card)->components($components)->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ])->columns(12);
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only([])
            ->titles(['form' => 'Giacenze'])
            ->subtitles(['form' => 'Quante ne hai di ogni versione in vendita. Scrivi le quantità che hai contato e salva: nascono i movimenti, con la causale scelta qui sopra.'])
            ->docs(Gestionale::docsUrl('magazzino/magazzino-giacenze'), 'form');
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('magazzino', 'Magazzino', 'bi-boxes', 400, ['admin', 'administrator'])
            ->title('Giacenze')
            ->order(10)
            ->authority(['admin', 'administrator']);
    }

    /**
     * Salva: ogni riga cambiata diventa un movimento.
     *
     * Tutte dentro una transazione sola, così un rifiuto a metà elenco non
     * lascia venti righe sistemate e trenta no.
     */
    public static function submitFormPage(array $values): string
    {
        // Le righe da salvare arrivano da **quello che è stato spedito**, non
        // da `rows()`: il form si posta su una rotta senza query string, e lì
        // i filtri e la pagina non ci sono più. Chiederli di nuovo a `rows()`
        // vorrebbe dire salvare la prima pagina invece di quella che si stava
        // guardando.
        $posted = [];

        foreach ($values as $key => $value) {
            if (!str_starts_with((string) $key, 'quantity_')) {
                continue;
            }

            $id = (int) substr((string) $key, strlen('quantity_'));
            $quantity = Stocktake::quantity($value);

            if ($id > 0 && $quantity !== null) {
                $posted[$id] = $quantity;
            }
        }

        if ($posted === []) {
            return 'Nessuna giacenza cambiata.';
        }

        $current = array_map(
            static fn (array $level): float => $level['quantity'],
            Levels::forProducts(array_keys($posted))
        );

        $changes = Stocktake::changes($current, $posted);

        if ($changes === []) {
            return 'Nessuna giacenza cambiata.';
        }

        $reason = (string) ($values['reason'] ?? Reasons::DEFAULT);
        $user = LegacyGlobals::get('USER');
        $userId = is_object($user) ? (int) ($user->id ?? 0) : 0;

        Transaction::run(static function () use ($changes, $reason, $userId): void {
            foreach ($changes as $productId => $change) {
                Stock::apply([
                    'product_id' => $productId,
                    'quantity' => $change['delta'],
                    'reason' => $reason,
                    'user_id' => $userId,
                ]);
            }
        });

        $count = count($changes);
        $message = $count === 1
            ? 'Una giacenza aggiornata.'
            : $count.' giacenze aggiornate.';

        static::goBack($values['back'] ?? '', $message);

        return $message;
    }

    /**
     * Torna all'elenco com'era: stessi filtri, stessa pagina.
     *
     * Il core, dopo una pagina-form, rimanda alla pagina nuda. Chi stava
     * sistemando la pagina tre si ritroverebbe sulla uno, senza filtri.
     */
    private static function goBack(mixed $back, string $message): void
    {
        $url = StockAdjustmentResource::backUrlFrom($back);

        if ($url === '' || headers_sent()) {
            return;
        }

        FlashAlert::saved($message);
        header('Location: '.$url);
        exit();
    }

    /**
     * Le righe della pagina: le versioni in vendita con la loro giacenza.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }

        $products = static::products();
        $levels = Levels::forProducts(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $products
        ));
        $names = static::modelNames();
        $rows = [];

        foreach ($products as $product) {
            $id = (int) ($product['id'] ?? 0);
            $level = $levels[$id] ?? ['quantity' => 0.0, 'reserved' => 0.0, 'available' => 0.0];

            $rows[] = [
                'id' => $id,
                'article' => $names[(int) ($product['product_model_id'] ?? 0)] ?? '—',
                'version' => trim((string) ($product['name'] ?? '')),
                'sku' => (string) ($product['sku'] ?? ''),
                'threshold' => round((float) ($product['min_stock_quantity'] ?? 0), 3),
                'quantity' => $level['quantity'],
                'reserved' => $level['reserved'],
                'available' => $level['available'],
            ];
        }

        return self::$rows = $rows;
    }

    /** Svuota la cache delle righe: serve ai test, che cambiano i filtri. */
    public static function forget(): void
    {
        self::$rows = null;
    }

    /** Il testo cercato, ripulito di tutto quello che non è testo. */
    public static function searchTerm(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));

        // Solo lettere, numeri, spazi e i segni che stanno negli SKU.
        return trim((string) preg_replace('/[^\p{L}\p{N} _.\-]+/u', '', $text));
    }

    /** La pagina chiesta, mai sotto la prima. */
    public static function pageNumber(mixed $value): int
    {
        $page = (int) trim((string) ($value ?? ''));

        return $page > 0 ? $page : 1;
    }

    /**
     * Le versioni in vendita di questa pagina, già filtrate.
     *
     * @return list<array<string, mixed>>
     */
    private static function products(): array
    {
        $parts = ["deleted = 'false'"];
        $search = static::searchTerm($_GET['cerca'] ?? '');

        if ($search !== '') {
            $like = str_replace(['%', '_'], ['\%', '\_'], $search);
            $parts[] = "(name LIKE '%".$like."%' OR sku LIKE '%".$like."%' OR ean LIKE '%".$like."%')";
        }

        $modelIds = static::filteredModelIds();

        if ($modelIds !== null) {
            $parts[] = $modelIds === []
                ? '1 = 0'
                : 'product_model_id IN ('.implode(',', $modelIds).')';
        }

        if (($_GET['sotto'] ?? '') === '1') {
            $alerted = static::alertedProductIds();
            $parts[] = $alerted === [] ? '1 = 0' : 'id IN ('.implode(',', $alerted).')';
        }

        $offset = (static::pageNumber($_GET['p'] ?? 1) - 1) * self::PER_PAGE;

        try {
            $rows = Product::find(
                implode(' AND ', $parts),
                $offset.','.self::PER_PAGE,
                'sku',
                'ASC'
            );
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /**
     * Gli id degli articoli che passano i filtri di categoria e marchio,
     * `null` quando non c'è nessun filtro.
     *
     * @return list<int>|null
     */
    private static function filteredModelIds(): ?array
    {
        $brandId = (int) ($_GET['marchio'] ?? 0);
        $categoryId = (int) ($_GET['categoria'] ?? 0);

        if ($brandId <= 0 && $categoryId <= 0) {
            return null;
        }

        $ids = null;

        if ($brandId > 0) {
            $ids = array_map(
                static fn (array $row): int => (int) $row['id'],
                static::rowsOf(ProductModel::class, ['brand_id' => $brandId])
            );
        }

        if ($categoryId > 0) {
            $inCategory = array_map(
                static fn (array $row): int => (int) $row['product_model_id'],
                static::rowsOf(
                    \Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory::class,
                    ['category_id' => $categoryId]
                )
            );

            $ids = $ids === null ? $inCategory : array_intersect($ids, $inCategory);
        }

        return array_values(array_unique(array_map('intval', (array) $ids)));
    }

    /** @return list<int> */
    private static function alertedProductIds(): array
    {
        try {
            $rows = StockAlert::find("deleted = 'false' AND resolved_at IS NULL");
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $rows = isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));

        return array_values(array_unique(array_map(
            static fn (array $row): int => (int) ($row['product_id'] ?? 0),
            $rows
        )));
    }

    /** @return array<int, string> */
    private static function modelNames(): array
    {
        $names = [];

        foreach (static::rowsOf(ProductModel::class) as $row) {
            $names[(int) $row['id']] = trim((string) ($row['name'] ?? ''));
        }

        return $names;
    }

    /** @param array<string, mixed> $row */
    private static function rowLabel(array $row): string
    {
        $label = '<b>'.static::escape((string) $row['article']).'</b>';

        if ($row['version'] !== '') {
            $label .= ' — '.static::escape((string) $row['version']);
        }

        if ($row['sku'] !== '') {
            $label .= ' <span class="text-muted">'.static::escape((string) $row['sku']).'</span>';
        }

        return $label;
    }

    /** @param array<string, mixed> $row */
    private static function rowSide(array $row): string
    {
        $parts = [];

        if (Gestionale::feature('orders')) {
            $parts[] = 'impegnati '.static::escape(static::plain((float) $row['reserved']));
            $parts[] = 'disponibili '.static::escape(static::plain((float) $row['available']));
        }

        if ((float) $row['threshold'] > 0 && Gestionale::feature('low_stock_alerts')) {
            $parts[] = 'scorta minima '.static::escape(static::plain((float) $row['threshold']));
        }

        $parts[] = '<a href="'.static::escape(
            StockAdjustmentResource::urlFor((int) $row['id'], static::currentUrl())
        ).'">Rettifica</a>';

        return implode(' · ', $parts);
    }

    /** La barra dei filtri: link, perché un form dentro un form non si annida. */
    private static function filtersBar(): string
    {
        $search = static::searchTerm($_GET['cerca'] ?? '');
        $sotto = ($_GET['sotto'] ?? '') === '1';
        $base = static::pageUrl([]);

        $html = '<div class="d-flex flex-wrap gap-2 align-items-center mb-2">';
        $html .= '<input type="text" class="form-control form-control-sm w-auto"'
            .' id="gst-stock-search" placeholder="Nome, SKU o EAN"'
            .' value="'.static::escape($search).'">';
        $html .= '<a class="btn btn-sm btn-secondary" href="'
            .static::escape(static::pageUrl(['sotto' => $sotto ? null : '1', 'p' => null])).'">'
            .($sotto ? 'Tutte le versioni' : 'Solo sotto scorta').'</a>';
        $html .= '<a class="btn btn-sm btn-light" href="'.static::escape($base).'">Azzera i filtri</a>';
        $html .= '</div>';

        // La casella di ricerca naviga: il form della pagina serve a salvare le
        // quantità, e un secondo form dentro non si può annidare.
        $html .= '<script>(function(){var b='.json_encode(static::pageUrl(['cerca' => null, 'p' => null]))
            .',i=document.getElementById("gst-stock-search");'
            .'if(!i)return;i.addEventListener("keydown",function(e){if(e.key!=="Enter")return;'
            .'e.preventDefault();window.location.href=b+(b.indexOf("?")>-1?"&":"?")+"cerca="'
            .'+encodeURIComponent(i.value);});})();</script>';

        return $html;
    }

    private static function pagination(): string
    {
        $page = static::pageNumber($_GET['p'] ?? 1);
        $full = count(static::rows()) === self::PER_PAGE;

        if ($page === 1 && !$full) {
            return '';
        }

        $html = '<div class="d-flex gap-2 mt-2">';

        if ($page > 1) {
            $html .= '<a class="btn btn-sm btn-secondary" href="'
                .static::escape(static::pageUrl(['p' => $page - 1])).'">Indietro</a>';
        }

        if ($full) {
            $html .= '<a class="btn btn-sm btn-secondary" href="'
                .static::escape(static::pageUrl(['p' => $page + 1])).'">Avanti</a>';
        }

        return $html.'<span class="align-self-center text-muted">Pagina '.$page.'</span></div>';
    }

    /** L'indirizzo di questa pagina con i filtri di adesso più quelli passati. */
    private static function pageUrl(array $changes): string
    {
        $query = [];

        foreach (['cerca', 'categoria', 'marchio', 'sotto', 'p'] as $key) {
            $value = trim((string) ($_GET[$key] ?? ''));

            if ($value !== '') {
                $query[$key] = $value;
            }
        }

        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($query[$key]);
                continue;
            }

            $query[$key] = (string) $value;
        }

        $base = '/backend/'.static::path();

        if (function_exists('__r')) {
            try {
                $named = (string) __r('backend.resource.'.static::slug().'.form');
                $base = $named !== '' ? $named : $base;
            } catch (Throwable) {
                // Rotta non registrata (test, comandi): resta il percorso.
            }
        }

        return $query === [] ? $base : $base.'?'.http_build_query($query);
    }

    private static function currentUrl(): string
    {
        return static::pageUrl([]);
    }

    /** I pezzi interi si scrivono interi: "3", non "3,000". */
    private static function plain(float $value): string
    {
        $decimals = round($value, 3) === round($value, 0) ? 0 : 3;

        return number_format($value, $decimals, ',', '');
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Le righe vive di un Model, sempre come lista.
     *
     * `NavigationOnlyResource` non è una `GestionaleResource`: l'aiuto che sta
     * lì non arriva fin qui.
     *
     * @param class-string<\Wonder\App\Model> $modelClass
     * @param array<string, mixed> $where
     * @return list<array<string, mixed>>
     */
    private static function rowsOf(string $modelClass, array $where = []): array
    {
        try {
            $rows = $modelClass::find(array_merge(['deleted' => 'false'], $where));
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

- [ ] **Step 4: Esegui il test unitario**

Run: `php tests/StockPagesTest.php`
Expected: `12 test, 0 falliti`

- [ ] **Step 5: Scrivi il test d'integrazione**

`tests/integrazione/StockPagesTest.php`:

```php
<?php
/** php tests/integrazione/StockPagesTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Stock\StockLevelResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

try {
    Transaction::run(static function (): void {
        $modello = ProductModel::create([
            'code' => Code::make(ProductModel::class, Codes::MODEL),
            'name' => 'Prova giacenze',
            'slug' => Slug::make('prova-giacenze-'.uniqid()),
            'sku' => 'TST-LVL',
            'unit' => 'pz',
            'type' => 'simple',
            'visible' => 'true',
            'position' => 1,
        ]);
        $scheletro = Skeleton::forModel((int) ($modello->insert_id ?? 0), 'Prova giacenze', 'TST-LVL');
        $productId = $scheletro['product_id'];

        $_GET['cerca'] = 'TST-LVL';
        StockLevelResource::forget();

        check('la ricerca trova la versione di prova', function () use ($productId) {
            foreach (StockLevelResource::rows() as $row) {
                if ((int) $row['id'] === $productId) {
                    // Il nome dell'articolo lo scrive il framework con le
                    // iniziali maiuscole: "Prova Giacenze".
                    return $row['quantity'] === 0.0
                        && strtolower((string) $row['article']) === 'prova giacenze';
                }
            }

            return false;
        });

        check('scrivere una quantità crea il movimento', function () use ($productId) {
            $messaggio = StockLevelResource::submitFormPage([
                'reason' => 'initial_stock',
                'quantity_'.$productId => '8',
            ]);

            return $messaggio === 'Una giacenza aggiornata.'
                && Levels::of($productId)['quantity'] === 8.0;
        });

        check('il movimento porta la causale della schermata', function () use ($productId) {
            $riga = StockMovement::find(['product_id' => $productId], 1, 'id', 'DESC');

            return ($riga['reason'] ?? '') === 'initial_stock'
                && (float) ($riga['quantity'] ?? 0) === 8.0;
        });

        check('salvare senza cambiare niente non scrive movimenti', function () use ($productId) {
            StockLevelResource::forget();
            $prima = count(StockMovement::find(['product_id' => $productId]) ?: []);
            $messaggio = StockLevelResource::submitFormPage([
                'reason' => 'inventory',
                'quantity_'.$productId => '8',
            ]);
            $dopo = count(StockMovement::find(['product_id' => $productId]) ?: []);

            return $messaggio === 'Nessuna giacenza cambiata.' && $prima === $dopo;
        });

        check('una casella vuota lascia la riga com\'è', function () use ($productId) {
            StockLevelResource::forget();
            StockLevelResource::submitFormPage([
                'reason' => 'inventory',
                'quantity_'.$productId => '',
            ]);

            return Levels::of($productId)['quantity'] === 8.0;
        });

        check('scrivere zero svuota davvero', function () use ($productId) {
            StockLevelResource::forget();
            StockLevelResource::submitFormPage([
                'reason' => 'inventory',
                'quantity_'.$productId => '0',
            ]);

            return Levels::of($productId)['quantity'] === 0.0;
        });

        unset($_GET['cerca']);
        StockLevelResource::forget();

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento non resta niente', function () {
    $riga = ProductModel::find(['sku' => 'TST-LVL', 'deleted' => 'false'], 1);

    return !is_array($riga) || $riga === [];
});

summary();
```

- [ ] **Step 6: Esegui il test d'integrazione**

Run: `php tests/integrazione/StockPagesTest.php`
Expected: `7 test, 0 falliti`

- [ ] **Step 7: Esegui tutta la suite**

Run: `php tests/run.php`
Expected: "Tutti i test del gestionale passano."

- [ ] **Step 8: Commit**

```bash
git add src/Resources/Stock/StockLevelResource.php tests/StockPagesTest.php tests/integrazione/StockPagesTest.php
git commit -m "Magazzino: la pagina Giacenze, con la modifica in massa"
```

---

## Task 4: La giacenza dentro il catalogo

**Files:**
- Modify: `src/Resources/Catalog/ProductModelResource.php`,
  `src/Resources/Catalog/ProductResource.php`
- Test: `tests/StockInCatalogTest.php`

**Interfaces:**
- Consumes: `Levels::forProducts()`, `Levels::of()`, `StockHistory::latest()`,
  `StockAdjustmentResource::urlFor()`, `StockMovementResource::listUrlFor()`,
  `Reasons::label()`, `StockMovement::typeLabels()`.
- Produces: niente di nuovo per gli altri task.

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/StockInCatalogTest.php`:

```php
<?php
/** php tests/StockInCatalogTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;

$colonne = static function (): array {
    foreach (ProductModelResource::formSchema() as $field) {
        if ((string) $field->name === 'products') {
            $keys = [];

            foreach ((array) $field->get('repeater') as $column) {
                $keys[] = (string) ($column->name ?? '');
            }

            return $keys;
        }
    }

    return [];
};

check('la griglia delle versioni dice anche quante ne hai', fn () =>
    in_array('stock', $colonne(), true)
);

check('la giacenza nella griglia non si scrive lì', function () use ($colonne) {
    foreach (ProductModelResource::formSchema() as $field) {
        if ((string) $field->name !== 'products') {
            continue;
        }

        foreach ((array) $field->get('repeater') as $column) {
            if ((string) ($column->name ?? '') === 'stock') {
                // Si cambia dalla rettifica, che chiede la causale.
                return (bool) $column->get('readonly') === true;
            }
        }
    }

    return false;
});

check('le colonne della griglia stanno in dodici', function () use ($colonne) {
    $totale = 0;

    foreach (ProductModelResource::formSchema() as $field) {
        if ((string) $field->name !== 'products') {
            continue;
        }

        foreach ((array) $field->get('repeater') as $column) {
            $totale += (int) ($column->get('column_span') ?? 0);
        }
    }

    return $totale === 12;
});

check('l\'articolo a versione unica ha la sua casella di giacenza', function () {
    foreach (ProductModelResource::formSchema() as $field) {
        if ((string) $field->name === 'product_stock') {
            return (bool) $field->get('readonly') === true;
        }
    }

    return false;
});

check('la scheda della versione ha il riquadro del magazzino', fn () =>
    str_contains(strtolower(ProductResource::stockCardTitle()), 'magazzino')
);

summary();
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `php tests/StockInCatalogTest.php`
Expected: FAIL — la colonna `stock` non c'è.

- [ ] **Step 3: Aggiungi la colonna alla griglia delle versioni**

In `src/Resources/Catalog/ProductModelResource.php`, dentro `productsField()`,
rifai le larghezze e aggiungi la colonna (le colonne devono sommare 12):

```php
                RepeaterColumn::key('name')->text()->label('Versione')->columnSpan(3),
                RepeaterColumn::key('sku')->text()->label('SKU')->columnSpan(2),
                RepeaterColumn::key('ean')->text()->label('EAN')->columnSpan(2),
                RepeaterColumn::key('price')->number()->decimal(2)->label('Prezzo')->columnSpan(2),
                RepeaterColumn::key('sale_price')->number()->decimal(2)->label('Scontato')->columnSpan(1),
                // Sola lettura: la giacenza si cambia dalla rettifica, che
                // chiede la causale e lascia un movimento. Qui è un numero da
                // leggere mentre si sistemano prezzi e codici.
                RepeaterColumn::key('stock')->text()->label('Giacenza')->readonly()->columnSpan(1),
                RepeaterColumn::key('active')
                    ->select(['true' => 'Attivo', 'false' => 'Fermo'])
                    ->label('Stato')
                    ->columnSpan(1),
```

- [ ] **Step 4: Riempi la colonna quando la scheda si apre**

In `mutateFormValues()` di `ProductModelResource`, subito prima di
`return $values;`, aggiungi:

```php
        // Le righe del repeater le ha già caricate il core
        // (`hydrateRepeaterFormValues()` gira prima di qui): la colonna
        // calcolata si aggiunge sopra. Al salvataggio `Model::prepare()`
        // butta via la chiave, che non è una colonna di `gst_products`.
        if (is_array($values['products'] ?? null)) {
            $levels = Levels::forProducts(array_map(
                static fn ($row): int => (int) (is_array($row) ? ($row['id'] ?? 0) : 0),
                $values['products']
            ));

            foreach ($values['products'] as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $level = $levels[(int) ($row['id'] ?? 0)] ?? null;
                $values['products'][$index]['stock'] = $level === null
                    ? '0'
                    : static::plainNumber($level['quantity']);
            }
        }

        $product = static::soleProduct($modelId);

        if ($product !== null) {
            $values['product_stock'] = static::plainNumber(
                Levels::of((int) $product['id'])['quantity']
            );
        }
```

E aggiungi in fondo alla classe, accanto agli altri aiuti:

```php
    /** I pezzi interi si scrivono interi: "3", non "3,000". */
    protected static function plainNumber(float $value): string
    {
        $decimals = round($value, 3) === round($value, 0) ? 0 : 3;

        return number_format($value, $decimals, ',', '');
    }
```

con gli `use` che servono:

```php
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
```

- [ ] **Step 5: Aggiungi la casella dell'articolo a versione unica**

In `formSchema()` di `ProductModelResource`, accanto a `product_price`:

```php
            FormField::key('product_stock')
                ->text()
                ->readonly()
                ->label('Giacenza')
                ->description('Si cambia dalla rettifica, che chiede la causale.'),
```

e nel riquadro *Prodotto* di `mainColumn()`, quando `$unaVersione` è vero,
dopo `product_sale_price`:

```php
        if ($unaVersione) {
            $cards[0] = (new Card)->components([
                SectionTitle::make('Prodotto')
                    ->tooltip('Il prezzo di questo articolo. Il tipo fiscale decide l\'IVA che gli si applica.')
                    ->columnSpan(12),
                static::getInput('name')->columnSpan(12),
                static::getInput('product_price')->columnSpan(3),
                static::getInput('product_sale_price')->columnSpan(3),
                static::getInput('tax_category_id')->columnSpan(3),
                static::getInput('product_stock')->columnSpan(3),
                RichText::make(static::adjustLink($modelId))->columnSpan(12),
            ])->columns(12)->columnSpan(12);
        }
```

con l'aiuto che compone il link:

```php
    /** Il link alla rettifica della versione unica; vuoto se le versioni sono tante. */
    protected static function adjustLink(int $modelId): string
    {
        $product = static::soleProduct($modelId);

        if ($product === null) {
            return '';
        }

        return '<a href="'.static::escape(
            StockAdjustmentResource::urlFor((int) $product['id'])
        ).'">Rettifica la giacenza</a>';
    }
```

- [ ] **Step 6: Togli `product_stock` da quello che si salva**

In `mutateRequestValues()` di `ProductModelResource`, dove già si tolgono i
campi che non sono colonne, aggiungi `product_stock` all'elenco. Cerca la riga
che toglie `product_price` e mettilo accanto: senza, il campo arriverebbe a
`Model::prepare()` — che lo butterebbe via comunque, ma è meglio dirlo qui.

- [ ] **Step 7: Aggiungi il riquadro Magazzino alla scheda della versione**

In `src/Resources/Catalog/ProductResource.php`, aggiungi il riquadro in
`formLayoutSchema()` (dopo il riquadro principale) e i due aiuti:

```php
        $cards[] = (new Card)->components([
            SectionTitle::make(static::stockCardTitle())
                ->tooltip('La giacenza si cambia solo con una rettifica: così resta scritto il perché.')
                ->columnSpan(12),
            RichText::make(static::stockSummary())->columnSpan(12),
            RichText::make(static::stockHistoryTable())->columnSpan(12),
        ])->columns(12)->columnSpan(12);
```

```php
    public static function stockCardTitle(): string
    {
        return 'Magazzino';
    }

    /** Quanti pezzi, con il link alla rettifica. */
    protected static function stockSummary(): string
    {
        $productId = static::currentId() ?? 0;

        if ($productId <= 0) {
            return '';
        }

        $levels = Levels::of($productId);
        $parts = ['<b>Giacenza:</b> '.static::escape(static::plainNumber($levels['quantity'])).' pezzi'];

        if (Gestionale::feature('orders')) {
            $parts[] = 'impegnati '.static::escape(static::plainNumber($levels['reserved']));
            $parts[] = 'disponibili '.static::escape(static::plainNumber($levels['available']));
        }

        $parts[] = '<a href="'.static::escape(StockAdjustmentResource::urlFor($productId)).'">Rettifica</a>';

        return implode(' · ', $parts);
    }

    /** Gli ultimi dieci movimenti di questa versione. */
    protected static function stockHistoryTable(): string
    {
        $productId = static::currentId() ?? 0;
        $rows = $productId > 0 ? StockHistory::latest($productId, 10) : [];

        if ($rows === []) {
            return '<p class="text-muted mb-0">Nessun movimento: la giacenza di questa versione non è mai cambiata.</p>';
        }

        $html = '<table class="table table-sm mb-2"><thead><tr>'
            .'<th>Quando</th><th>Tipo</th><th>Causale</th><th>Pezzi</th><th>Dopo</th><th>Nota</th>'
            .'</tr></thead><tbody>';

        foreach ($rows as $row) {
            $quantity = (float) ($row['quantity'] ?? 0);
            $html .= '<tr>'
                .'<td>'.static::escape((string) ($row['creation'] ?? '')).'</td>'
                .'<td>'.static::escape(StockMovement::typeLabels()[(string) ($row['type'] ?? '')] ?? '').'</td>'
                .'<td>'.static::escape(Reasons::label((string) ($row['reason'] ?? ''))).'</td>'
                .'<td>'.static::escape(($quantity > 0 ? '+' : '').static::plainNumber($quantity)).'</td>'
                .'<td>'.static::escape(static::plainNumber((float) ($row['quantity_after'] ?? 0))).'</td>'
                .'<td>'.static::escape((string) ($row['note'] ?? '')).'</td>'
                .'</tr>';
        }

        $html .= '</tbody></table><a href="'
            .static::escape(StockMovementResource::listUrlFor($productId))
            .'">Vedi tutti i movimenti</a>';

        return $html;
    }
```

con gli `use` che servono:

```php
use Wonder\Elements\Components\RichText;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockMovementResource;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\StockHistory;
```

- [ ] **Step 8: Esegui i test**

Run: `php tests/StockInCatalogTest.php && php tests/run.php`
Expected: `5 test, 0 falliti` e "Tutti i test del gestionale passano."

- [ ] **Step 9: Commit**

```bash
git add src/Resources/Catalog tests/StockInCatalogTest.php
git commit -m "Magazzino: la giacenza dentro la scheda del prodotto"
```

---

## Task 5: Dati di prova, guida e verifica sul sito

**Files:**
- Modify: `src/Seeding/CatalogDemo.php`, `docs/user/magazzino-giacenze.md`,
  `docs/dev/concetti/magazzino.md`, `TODO.md`
- Test: `tests/integrazione/CatalogDemoTest.php` (già esistente: deve restare verde)

**Interfaces:**
- Consumes: `Stock::apply()`, `Levels::of()`.
- Produces: niente di nuovo.

- [ ] **Step 1: Dai una giacenza agli articoli di prova**

In `src/Seeding/CatalogDemo.php`, dopo la creazione delle versioni di un
articolo, carica la giacenza iniziale:

```php
    /**
     * La giacenza iniziale degli articoli di prova.
     *
     * Serve a vedere il magazzino pieno appena installato, e a far comparire
     * un avviso di scorta: l'ultima versione nasce **sotto** la sua soglia.
     */
    private static function seedStock(int $modelId): int
    {
        $products = self::rowsOfModel(Product::class, $modelId);
        $created = 0;
        $last = count($products) - 1;

        foreach (array_values($products) as $index => $product) {
            $productId = (int) ($product['id'] ?? 0);

            if ($productId <= 0) {
                continue;
            }

            if ($index === $last) {
                // Una versione sotto scorta: è il caso che si vuole provare.
                Product::update(['min_stock_quantity' => '5'], $productId);
            }

            Stock::apply([
                'product_id' => $productId,
                'quantity' => $index === $last ? 2 : 20,
                'reason' => 'initial_stock',
                'source' => 'import',
            ]);

            $created++;
        }

        return $created;
    }
```

Chiamala dove l'articolo è finito (dopo le versioni e le foto), sommando il
risultato al conteggio delle righe create, e aggiungi l'`use`:

```php
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
```

- [ ] **Step 2: Prova il comando sul sito**

Run dalla cartella del sito:

```bash
php forge gestionale:demo --fresh && php forge gestionale:demo
```

Expected: il comando dice quante righe ha creato, senza errori; le righe di
magazzino compaiono.

- [ ] **Step 3: Completa la guida del commerciante**

Aggiungi a `docs/user/magazzino-giacenze.md`, prima della sezione
"Rettificare una riga sola":

```markdown
## L'elenco Giacenze

**Magazzino → Giacenze** è l'elenco di tutte le versioni in vendita con la
quantità che hai. La quantità si scrive **lì dentro**, riga per riga: scrivi
quello che hai contato e salva. Ogni riga cambiata diventa un movimento con la
causale scelta in cima alla schermata (di solito *Inventario*).

Due regole che fanno la differenza:

- **una casella lasciata vuota non tocca niente.** Puoi contare tre scaffali
  oggi e il resto domani.
- **uno zero scritto è uno zero vero:** diventa un movimento che svuota la
  riga.

In alto trovi la ricerca (nome, SKU o EAN) e il pulsante *Solo sotto scorta*.
Si lavora cinquanta righe per volta: in fondo ci sono *Indietro* e *Avanti*.

## Il primo carico

Appena installato il gestionale, il modo più veloce per caricare il magazzino è
proprio questo: apri *Giacenze*, lascia la causale su **Giacenza iniziale**,
scrivi le quantità e salva.
```

- [ ] **Step 4: Aggiungi le due pagine alla guida sviluppatore**

In `docs/dev/concetti/magazzino.md`, dopo la sezione "Una sola porta":

```markdown
## Le due pagine

| Pagina | Classe | Cosa fa |
|---|---|---|
| Giacenze | `StockLevelResource` | pagina-form: una casella per riga, salvataggio in blocco dentro una transazione |
| Rettifica | `StockAdjustmentResource` | pagina-form su `?versione=`, con causale e nota |

Nessuna delle due scrive sul database: compongono un movimento e chiamano
`Stock::apply()`. La differenza fra quello che c'era e quello che è stato
scritto la calcola `Stocktake`, che è pura — una casella vuota non è uno zero,
uno zero scritto sì.

Le due pagine sono `isFormPage()`: il core non le tratta come elenchi CRUD, e
ricerca, filtri e paginazione di *Giacenze* sono scritti nel modulo. I filtri
sono link perché un modulo GET dentro il form del salvataggio non si può
annidare.
```

- [ ] **Step 5: Verifica nel browser**

Apri `https://ecommerce.test/backend/app/gestionale/giacenze/` e controlla:

1. nel menu **Magazzino** ci sono *Giacenze* e *Movimenti*, in quest'ordine;
2. le righe degli articoli di prova hanno le loro quantità;
3. scrivendo una quantità diversa in due righe e salvando, il messaggio dice
   "2 giacenze aggiornate" e i *Movimenti* hanno due righe nuove con la causale
   della schermata;
4. lasciando tutte le caselle come stanno e salvando, il messaggio dice
   "Nessuna giacenza cambiata" e non nasce nessun movimento;
5. la ricerca per SKU filtra l'elenco, e *Azzera i filtri* lo rimette a posto;
6. il link **Rettifica** di una riga apre la pagina con il nome giusto, e
   salvando una nota si torna all'elenco con il messaggio;
7. nella scheda di un articolo con più versioni la griglia mostra la colonna
   *Giacenza*, e salvando la scheda le giacenze **non cambiano**;
8. nella scheda di un articolo a versione unica c'è la casella *Giacenza* con
   il link alla rettifica;
9. aprendo una versione da *Versioni in vendita* c'è il riquadro *Magazzino*
   con gli ultimi movimenti e il link a Movimenti.

- [ ] **Step 6: Ripulisci il sito di prova**

Run dalla cartella del sito:

```bash
php forge gestionale:demo --fresh
```

Expected: il comando toglie articoli, foto e la loro storia di magazzino;
l'elenco *Giacenze* resta vuoto.

- [ ] **Step 7: Spunta il piano nel TODO**

In `TODO.md`, sotto **G2b**, sostituisci la riga del piano 2 con una voce
`[x]` che racconta cosa è stato fatto e cosa è emerso, sullo stile delle altre.

- [ ] **Step 8: Esegui tutta la suite e unisci**

```bash
php tests/run.php
git add -- TODO.md docs src tests
git commit -m "G2b: piano 2 eseguito, giacenze e rettifiche"
git checkout main
git merge --no-ff feature/giacenze-e-rettifiche -m "Giacenze e rettifiche (piano 2 di G2b)"
git branch -d feature/giacenze-e-rettifiche
git push
```

Expected: suite verde, push riuscito, CI verde.

> **Nota sui commit:** in questo repository si aggiungono **percorsi
> espliciti**, mai `git add -A`: nella cartella ci sono anche modifiche non
> committate di chi ci lavora.
