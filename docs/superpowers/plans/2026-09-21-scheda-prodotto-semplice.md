# La scheda prodotto semplice — Piano di lavoro

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Far sparire modelli, varianti e livelli dal pannello: una parola sola
("prodotto"), sette riquadri invece di dieci, righe che dicono chi sono, e un
generatore di combinazioni senza regole da spiegare.

**Architecture:** Nessuna tabella nuova e nessuna tabella tolta. Cambiano le
etichette, l'ordine e il numero dei riquadri della scheda, il campo che
raccoglie le spunte (uno invece di due), e `Combinations::plan()` che passa da
due liste a N assi. La generazione esce dalla Resource e diventa
`Support\Catalog\Generator`, così la scheda torna a occuparsi solo del form.

**Tech Stack:** PHP 8.2, `wonder-image/app` 2.2.12 (installato) / main (locale),
harness di test del modulo (`tests/harness.php`, `php tests/run.php`), MySQL,
sito di prova `boilerplates/ecommerce-site` su `https://ecommerce.test/backend/`.

**Spec:** [2026-09-21-scheda-prodotto-semplice-design.md](../specs/2026-09-21-scheda-prodotto-semplice-design.md)

## Global Constraints

- Lingua: **italiano** in etichette, commenti, messaggi ed errori. **Inglese**
  per nomi di classi, metodi, variabili e colonne.
- I rifiuti all'utente si lanciano **solo** con
  `Wonder\Plugin\Gestionale\Support\Errors\UserError` (estende
  `InvalidArgumentException`): è l'unico tipo che il core trasforma in avviso
  invece che in pagina 500.
- Form compatti: **tooltip** (`SectionTitle::make(...)->tooltip(...)`) al posto
  dei testi d'aiuto lunghi; campi correlati vicini; niente campi automatici
  (slug, position) nel form.
- Un metodo che torna un campo del form si tipizza
  `Wonder\App\ResourceSchema\Input`, mai `FormField`: i builder tornano
  `Inputs\Input*`.
- Un campo non stampato non viene postato, e `syncRepeaterRelations()` cancella
  le righe che non ritrova: un repeater si dichiara **solo** quando il suo
  riquadro è visibile.
- `formSchema()` è valutato all'avvio del sito: nei test che creano attributi
  serve `ProductModelResource::forgetCatalogCache()` prima di rileggere.
- `Model::update()` e `Model::create()` non passano dal `prepare()` dei form:
  i decimali scritti da lì passano da `Support\Numbers::fromForm()`.
- Ogni commit finisce con `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- Il ramo di lavoro è `feature/scheda-prodotto-semplice`, creato da `main`.

## Struttura dei file

**Nel modulo `packages/gestionale`:**

| File | Responsabilità | Stato |
|---|---|---|
| `src/Support/Catalog/Combinations.php` | Pura: da N assi di valori alle combinazioni che mancano | riscritto (Task 2) |
| `src/Support/Catalog/VersionName.php` | Pura: da una lista di etichette al nome della versione | nuovo (Task 3) |
| `src/Support/Catalog/Generator.php` | Scrive varianti e prodotti mancanti: legge l'esistente, chiama `Combinations`, crea le righe e i collegamenti | nuovo (Task 5) |
| `src/Support/Catalog/Attributes.php` | Etichette dei livelli in parole da negoziante | modificato (Task 4) |
| `src/Models/Catalog/Product.php` | Colonna `name` | modificato (Task 3) |
| `src/Resources/Catalog/AttributeResource.php` | "Come si usa" al posto di "Livello" | modificato (Task 4) |
| `src/Resources/Catalog/ProductModelResource.php` | Solo il form: campi, layout, lettura e scrittura del posted | alleggerito (Task 5, 6, 7, 8, 9) |
| `src/Resources/Catalog/ProductResource.php` | La singola versione, fuori dal menu e filtrabile per articolo | modificato (Task 7) |
| `src/Resources/GestionaleResource.php` | `foldable()`: il riquadro che si chiude | modificato (Task 1) |

**Nel core `packages/app`:**

| File | Responsabilità | Stato |
|---|---|---|
| `class/Backend/Support/ResourcePagePresenter.php` | `redirectUrl($action, $id)` con il caso `edit` | modificato (Task 9) |
| `class/Backend/Support/ResourcePageController.php` | Passa l'id della riga al redirect | modificato (Task 9) |

`ProductModelResource` oggi è 1342 righe. Il Task 5 ne porta via ~200 dentro
`Generator`; non si fanno altri spostamenti, perché `ProductResource` estende
`ProductModelResource` per riusarne le letture del catalogo e muoverle
significherebbe toccare due file per ogni lettura.

---

### Task 1: Il riquadro che si chiude

`Elements\Components\Accordion` esiste nel core ma non risulta mai usato dentro
un form: è l'unica incognita tecnica del piano, quindi si prova per prima.
Attenzione: `Accordion` **non** ha `columns()` (usa `CanSpanColumn`, non
`IsContainer`), quindi la griglia a 12 colonne va messa dentro con un
`Container`.

**Files:**
- Modify: `src/Resources/GestionaleResource.php`
- Modify: `src/Resources/Catalog/ProductModelResource.php` (riquadro "Spedizione")
- Test: `tests/FoldableTest.php`

**Interfaces:**
- Produces: `GestionaleResource::foldable(string $title, array $components, string $tooltip = ''): object`
  — torna un componente pronto da mettere nella lista dei riquadri di
  `formLayoutSchema()`, già con `columnSpan(12)`.

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/FoldableTest.php`:

```php
<?php
/** php tests/FoldableTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Elements\Components\Container;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;

final class RiquadroDiProva extends \Wonder\Plugin\Gestionale\Resources\GestionaleResource
{
    public static string $model = \Wonder\Plugin\Gestionale\Models\Catalog\ProductModel::class;

    public static function apri(string $title, array $components): object
    {
        return static::foldable($title, $components, 'Un aiuto breve');
    }
}

check('il riquadro che si chiude tiene dentro i suoi componenti', function () {
    $riquadro = RiquadroDiProva::apri('Spedizione e fisco', []);

    return isset($riquadro->components[0])
        && $riquadro->components[0] instanceof Container;
});

check('la griglia a dodici colonne resta dentro', function () {
    $riquadro = RiquadroDiProva::apri('Spedizione e fisco', []);
    $dentro = $riquadro->components[0];

    return ($dentro->getSchema('columns') ?? null) !== null;
});

check('il riquadro occupa tutta la larghezza', function () {
    $riquadro = RiquadroDiProva::apri('Spedizione e fisco', []);

    return (int) ($riquadro->getSchema('column_span') ?? 0) === 12;
});

summary();
```

- [ ] **Step 2: Lancialo e guardalo fallire**

Run: `php tests/FoldableTest.php`
Atteso: FAIL — `Call to undefined method ... ::foldable()`.

Se `getSchema('columns')` o `getSchema('column_span')` non esistono con quei
nomi, leggi `class/Elements/Concerns/HasColumns.php` e
`class/Elements/Concerns/CanSpanColumn.php` nel core e correggi i nomi nel
test prima di andare avanti.

- [ ] **Step 3: Scrivi il metodo**

In `src/Resources/GestionaleResource.php`, con gli `use` di
`Wonder\Elements\Components\Accordion` e `Wonder\Elements\Components\Container`:

```php
    /**
     * Un riquadro che nasce chiuso: dentro ci va quello che si tocca di rado.
     *
     * `Accordion` non ha `columns()` — usa `CanSpanColumn`, non `IsContainer` —
     * quindi la griglia a dodici colonne la mette un `Container` interno,
     * altrimenti i campi si impilano uno sotto l'altro.
     */
    protected static function foldable(string $title, array $components, string $tooltip = ''): object
    {
        $accordion = Accordion::make($title)->columnSpan(12);

        if ($tooltip !== '') {
            $accordion->description($tooltip);
        }

        return $accordion->components([
            (new Container)->components($components)->columns(12)->columnSpan(12),
        ]);
    }
```

- [ ] **Step 4: Lancia il test**

Run: `php tests/FoldableTest.php`
Atteso: PASS, 3 test.

- [ ] **Step 5: Mettilo in pagina per guardarlo**

In `ProductModelResource::formLayoutSchema()`, sostituisci il riquadro
"Spedizione" (oggi una `Card` con `SectionTitle::make('Spedizione')`) con:

```php
        $cards[] = static::foldable('Spedizione', [
            static::getInput('weight')->columnSpan(3),
            static::getInput('length')->columnSpan(3),
            static::getInput('width')->columnSpan(3),
            static::getInput('height')->columnSpan(3),
            static::getInput('returnable')->columnSpan(6),
            static::getInput('requires_shipping')->columnSpan(6),
        ], 'Peso e misure dell\'articolo. Un prodotto che ha misure sue le usa al posto di queste.');
```

- [ ] **Step 6: Guardalo nel browser**

Apri `https://ecommerce.test/backend/app/gestionale/modelli`, crea o apri un
modello e controlla tre cose: il riquadro compare **chiuso**, cliccandolo si
apre, e dentro i campi stanno **affiancati** (peso, lunghezza, larghezza,
altezza su una riga).

**Se una delle tre non succede**, cambia il corpo di `foldable()` in modo che
torni una `Card` normale e lascia il resto del piano intatto:

```php
        return (new Card)->components([
            SectionTitle::make($title)->tooltip($tooltip)->columnSpan(12),
            ...$components,
        ])->columns(12)->columnSpan(12);
    }
```

In quel caso aggiorna il primo test così: `$riquadro->components[0]` è un
`SectionTitle`, non un `Container`. E scrivi nel messaggio del commit che
l'`Accordion` nel form non funziona, perché serve al Task 6.

- [ ] **Step 7: Lancia tutti i test del modulo**

Run: `php tests/run.php`
Atteso: nessun test fallito.

- [ ] **Step 8: Commit**

```bash
git add src/Resources/GestionaleResource.php src/Resources/Catalog/ProductModelResource.php tests/FoldableTest.php
git commit -m "$(cat <<'MSG'
Give the card that nobody opens a lid

Weight and dimensions are touched once a year and sit open all the same.
Accordion has no columns() of its own, so the twelve-column grid goes in
a Container inside it.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 2: Combinazioni a N assi

Oggi `plan()` ragiona su due liste piatte: l'asse "variante" per l'asse
"prodotto". Spuntare due opzioni dello stesso livello (Vita e Lunghezza di un
jeans) produce righe sbagliate in silenzio. Diventa: un asse facoltativo con
pagina propria, più quanti assi si vuole.

**Files:**
- Modify: `src/Support/Catalog/Combinations.php`
- Test: `tests/CombinationsTest.php` (riscritto)

**Interfaces:**
- Produces:
  - `Combinations::plan(array $variantValues, array $axes, array $existing): array`
    dove `$variantValues` è `list<array{id:int,label:string}>` (vuoto se
    l'articolo non usa l'opzione con pagina propria), `$axes` è
    `list<list<array{id:int,label:string}>>` (un elemento per opzione spuntata)
    e `$existing` è `array{variants: array<int,int>, products: array<string,bool>}`.
    Torna `array{variants: list<array{value_id:int,label:string}>, products:
    list<array{variant_value_id:int, value_ids:list<int>, labels:list<string>}>}`.
  - `Combinations::key(int $variantValueId, array $valueIds): string` — la
    chiave di una combinazione, con gli id **ordinati**, così l'ordine delle
    spunte non genera doppioni.

- [ ] **Step 1: Riscrivi il test**

Sostituisci **tutto** `tests/CombinationsTest.php` con:

```php
<?php
/** php tests/CombinationsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Catalog\Combinations;

$colori = [
    ['id' => 10, 'label' => 'Blu'],
    ['id' => 11, 'label' => 'Rosso'],
];
$taglie = [
    ['id' => 20, 'label' => 'S'],
    ['id' => 21, 'label' => 'M'],
    ['id' => 22, 'label' => 'L'],
];
$lunghezze = [
    ['id' => 30, 'label' => 'Corta'],
    ['id' => 31, 'label' => 'Lunga'],
];
$vuoto = ['variants' => [], 'products' => []];

check('due colori e tre taglie fanno due varianti e sei prodotti', function () use ($colori, $taglie, $vuoto) {
    $piano = Combinations::plan($colori, [$taglie], $vuoto);

    return count($piano['variants']) === 2 && count($piano['products']) === 6;
});

check('ogni prodotto sa da quali valori viene', function () use ($colori, $taglie, $vuoto) {
    $piano = Combinations::plan($colori, [$taglie], $vuoto);
    $primo = $piano['products'][0];

    return $primo['variant_value_id'] === 10
        && $primo['value_ids'] === [20]
        && $primo['labels'] === ['Blu', 'S'];
});

check('tre assi si moltiplicano', function () use ($colori, $taglie, $lunghezze, $vuoto) {
    $piano = Combinations::plan($colori, [$taglie, $lunghezze], $vuoto);

    return count($piano['variants']) === 2
        && count($piano['products']) === 12
        && $piano['products'][0]['value_ids'] === [20, 30]
        && $piano['products'][0]['labels'] === ['Blu', 'S', 'Corta'];
});

check('due assi senza opzione con pagina propria', function () use ($taglie, $lunghezze, $vuoto) {
    $piano = Combinations::plan([], [$taglie, $lunghezze], $vuoto);

    return $piano['variants'] === []
        && count($piano['products']) === 6
        && $piano['products'][0]['variant_value_id'] === 0;
});

check('quello che c\'è già non si rifà', function () use ($colori, $taglie) {
    $esistenti = ['variants' => [10 => 101, 11 => 102], 'products' => []];

    foreach ([10, 11] as $colore) {
        foreach ([20, 21, 22] as $taglia) {
            $esistenti['products'][Combinations::key($colore, [$taglia])] = true;
        }
    }

    $piano = Combinations::plan($colori, [$taglie], $esistenti);

    return $piano['variants'] === [] && $piano['products'] === [];
});

check('una taglia in più fa solo i due prodotti che mancano', function () use ($colori, $taglie) {
    $esistenti = ['variants' => [10 => 101, 11 => 102], 'products' => []];

    foreach ([10, 11] as $colore) {
        foreach ([20, 21] as $taglia) {
            $esistenti['products'][Combinations::key($colore, [$taglia])] = true;
        }
    }

    $piano = Combinations::plan($colori, [$taglie], $esistenti);

    return $piano['variants'] === []
        && count($piano['products']) === 2
        && $piano['products'][0]['value_ids'] === [22];
});

check('l\'ordine delle spunte non crea doppioni', function () use ($colori, $taglie, $lunghezze) {
    $esistenti = ['variants' => [10 => 101, 11 => 102], 'products' => []];

    // La riga esistente è stata salvata con gli id nell'ordine opposto.
    foreach ([10, 11] as $colore) {
        foreach ([20, 21, 22] as $taglia) {
            foreach ([30, 31] as $lunghezza) {
                $esistenti['products'][Combinations::key($colore, [$lunghezza, $taglia])] = true;
            }
        }
    }

    $piano = Combinations::plan($colori, [$taglie, $lunghezze], $esistenti);

    return $piano['products'] === [];
});

check('una variante che c\'è già si riusa per i prodotti nuovi', function () use ($colori, $taglie) {
    $piano = Combinations::plan($colori, [$taglie], ['variants' => [10 => 101], 'products' => []]);

    return count($piano['variants']) === 1
        && $piano['variants'][0]['value_id'] === 11
        && count($piano['products']) === 6;
});

check('senza colori i prodotti nascono sulla variante che c\'è', function () use ($taglie, $vuoto) {
    $piano = Combinations::plan([], [$taglie], $vuoto);

    return $piano['variants'] === []
        && count($piano['products']) === 3
        && $piano['products'][0]['variant_value_id'] === 0
        && $piano['products'][0]['labels'] === ['S'];
});

check('senza taglie nasce un prodotto per variante', function () use ($colori, $vuoto) {
    $piano = Combinations::plan($colori, [], $vuoto);

    return count($piano['variants']) === 2
        && count($piano['products']) === 2
        && $piano['products'][0]['value_ids'] === []
        && $piano['products'][0]['labels'] === ['Blu'];
});

check('un asse vuoto non conta', function () use ($colori, $vuoto) {
    $piano = Combinations::plan($colori, [[], []], $vuoto);

    return count($piano['products']) === 2;
});

check('senza niente da spuntare non si fa niente', function () use ($vuoto) {
    return Combinations::plan([], [], $vuoto) === ['variants' => [], 'products' => []];
});

check('la chiave ordina gli id', fn () =>
    Combinations::key(10, [31, 20]) === Combinations::key(10, [20, 31])
    && Combinations::key(0, []) === '0:'
);

summary();
```

- [ ] **Step 2: Lancialo e guardalo fallire**

Run: `php tests/CombinationsTest.php`
Atteso: FAIL — `Combinations::key()` non esiste e i prodotti non hanno
`value_ids`.

- [ ] **Step 3: Riscrivi la classe**

Sostituisci il corpo di `src/Support/Catalog/Combinations.php` (lascia il
`namespace` e l'intestazione del file) con:

```php
/**
 * Quali varianti e quali prodotti mancano, dati i valori spuntati.
 *
 * Un asse può avere pagina e foto proprie — è quello che diventa una variante;
 * gli altri si moltiplicano fra loro. Due colori, tre taglie e due lunghezze
 * sono due varianti e dodici prodotti. Rifarlo con le stesse spunte non deve
 * creare niente: si guarda cosa c'è già e si dice solo cosa manca.
 *
 * Classe pura: riceve i valori e l'esistente, non tocca il database.
 */
final class Combinations
{
    /**
     * @param list<array{id: int, label: string}> $variantValues l'asse con
     *        pagina propria; vuoto se l'articolo non ne usa
     * @param list<list<array{id: int, label: string}>> $axes gli altri assi,
     *        uno per opzione spuntata
     * @param array{variants: array<int, int>, products: array<string, bool>} $existing
     *        `variants`: id del valore => id della variante che lo usa già;
     *        `products`: chiavi già presenti, nella forma di `key()`
     * @return array{
     *     variants: list<array{value_id: int, label: string}>,
     *     products: list<array{variant_value_id: int, value_ids: list<int>, labels: list<string>}>
     * }
     */
    public static function plan(array $variantValues, array $axes, array $existing): array
    {
        $axes = array_values(array_filter($axes, static fn (array $axis): bool => $axis !== []));

        if ($variantValues === [] && $axes === []) {
            return ['variants' => [], 'products' => []];
        }

        $knownVariants = (array) ($existing['variants'] ?? []);
        $knownProducts = (array) ($existing['products'] ?? []);

        $variants = [];

        foreach ($variantValues as $value) {
            $id = (int) ($value['id'] ?? 0);

            if ($id === 0 || isset($knownVariants[$id])) {
                continue;
            }

            $variants[] = ['value_id' => $id, 'label' => (string) ($value['label'] ?? '')];
        }

        // Nessun colore spuntato: si lavora sulla variante che c'è già, che qui
        // vale zero perché il suo id lo conosce solo chi scrive le righe.
        $variantSide = $variantValues === []
            ? [['id' => 0, 'label' => '']]
            : $variantValues;

        $combinations = self::cartesian($axes);
        $products = [];

        foreach ($variantSide as $variant) {
            $variantId = (int) ($variant['id'] ?? 0);

            foreach ($combinations as $combination) {
                $valueIds = array_map(
                    static fn (array $value): int => (int) ($value['id'] ?? 0),
                    $combination
                );

                if (isset($knownProducts[self::key($variantId, $valueIds)])) {
                    continue;
                }

                $labels = [];

                foreach ([$variant, ...$combination] as $value) {
                    $label = (string) ($value['label'] ?? '');

                    if ($label !== '') {
                        $labels[] = $label;
                    }
                }

                $products[] = [
                    'variant_value_id' => $variantId,
                    'value_ids' => $valueIds,
                    'labels' => $labels,
                ];
            }
        }

        return ['variants' => $variants, 'products' => $products];
    }

    /**
     * La chiave di una combinazione.
     *
     * Gli id si ordinano: chi ha spuntato prima la lunghezza e poi la taglia
     * deve ritrovare la stessa riga di chi ha fatto il contrario, altrimenti
     * risalvare crea doppioni.
     *
     * @param list<int> $valueIds
     */
    public static function key(int $variantValueId, array $valueIds): string
    {
        sort($valueIds);

        return $variantValueId.':'.implode('-', $valueIds);
    }

    /**
     * Il prodotto cartesiano degli assi.
     *
     * Senza assi torna **una** combinazione vuota, non zero: vuol dire "un
     * prodotto per variante, senza altre scelte".
     *
     * @param list<list<array{id: int, label: string}>> $axes
     * @return list<list<array{id: int, label: string}>>
     */
    private static function cartesian(array $axes): array
    {
        $rows = [[]];

        foreach ($axes as $axis) {
            $next = [];

            foreach ($rows as $row) {
                foreach ($axis as $value) {
                    $next[] = [...$row, $value];
                }
            }

            $rows = $next;
        }

        return $rows;
    }
}
```

- [ ] **Step 4: Lancia il test**

Run: `php tests/CombinationsTest.php`
Atteso: PASS, 13 test.

- [ ] **Step 5: Lascia il resto rotto, ma sappilo**

Run: `php tests/run.php`
Atteso: `ProductModelResourceTest` e `integrazione/CombinazioniTest` **falliscono**,
perché `ProductModelResource::generateCombinations()` chiama ancora la vecchia
firma. Li sistema il Task 5. Non aggiustarli qui.

- [ ] **Step 6: Commit**

```bash
git add src/Support/Catalog/Combinations.php tests/CombinationsTest.php
git commit -m "$(cat <<'MSG'
Multiply as many options as the shop ticks

Two flat lists meant a jeans with a waist and a length quietly produced
the wrong rows. Now one axis may carry its own page and the rest are a
cartesian product, so there is no rule left to explain.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 3: Il nome della versione

Dodici righe indistinguibili nella griglia sono il difetto più grave di oggi.
Il nome diventa una colonna vera: la scrive il generatore e la può correggere
chi vende.

**Files:**
- Create: `src/Support/Catalog/VersionName.php`
- Modify: `src/Models/Catalog/Product.php`
- Test: `tests/VersionNameTest.php`
- Test: `tests/ProductModelsTest.php` (una check in più)
- Modify: `src/Resources/Catalog/ProductModelResource.php` (`productsField()`)

**Interfaces:**
- Consumes: niente dai task precedenti.
- Produces: `VersionName::from(array $labels, string $fallback = ''): string`
  — `['Blu','M']` → `"Blu / M"`; lista vuota → `$fallback` ripulito.
  E la colonna `name` di `gst_products`.

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/VersionNameTest.php`:

```php
<?php
/** php tests/VersionNameTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Catalog\VersionName;

check('due valori diventano un nome leggibile', fn () =>
    VersionName::from(['Blu', 'M']) === 'Blu / M'
);

check('un valore solo resta se stesso', fn () =>
    VersionName::from(['M']) === 'M'
);

check('tre valori si incolonnano nell\'ordine dato', fn () =>
    VersionName::from(['Blu', 'M', 'Corta']) === 'Blu / M / Corta'
);

check('i vuoti non lasciano separatori appesi', fn () =>
    VersionName::from(['Blu', '', '  ', 'M']) === 'Blu / M'
);

check('senza valori si tiene il ripiego', fn () =>
    VersionName::from([], 'TSH-1') === 'TSH-1'
    && VersionName::from(['', ''], ' TSH-1 ') === 'TSH-1'
);

check('senza valori e senza ripiego si torna vuoti', fn () =>
    VersionName::from([]) === ''
);

summary();
```

- [ ] **Step 2: Lancialo e guardalo fallire**

Run: `php tests/VersionNameTest.php`
Atteso: FAIL — la classe non esiste.

- [ ] **Step 3: Scrivi la classe**

`src/Support/Catalog/VersionName.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

/**
 * Il nome di una versione in vendita: "Blu / M".
 *
 * Lo scrive il generatore quando crea la riga, e chi vende lo può correggere:
 * è una fotografia, non un calcolo. Rinominare un valore ("Blu" → "Blu notte")
 * non riscrive i nomi già generati, di proposito — quello che il cliente ha
 * letto ieri non deve cambiare da sé.
 *
 * Classe pura: la usano il generatore, i dati di prova e i test.
 */
final class VersionName
{
    /** @param list<string> $labels */
    public static function from(array $labels, string $fallback = ''): string
    {
        $parts = [];

        foreach ($labels as $label) {
            $label = trim((string) $label);

            if ($label !== '') {
                $parts[] = $label;
            }
        }

        return $parts === [] ? trim($fallback) : implode(' / ', $parts);
    }
}
```

- [ ] **Step 4: Lancia il test**

Run: `php tests/VersionNameTest.php`
Atteso: PASS, 6 test.

- [ ] **Step 5: Aggiungi la colonna al prodotto**

In `src/Models/Catalog/Product.php`, dentro `tableSchema()`, subito dopo la
riga di `product_variant_id`:

```php
            Column::key('name'),
```

e dentro `dataSchema()`, subito dopo `Field::key('product_variant_id')...`:

```php
            // Niente `sanitizeFirst()`: "XL" deve restare "XL".
            Field::key('name')->text(),
```

Aggiorna anche il docblock della classe, aggiungendo dopo il primo paragrafo:

```php
 * `name` è l'etichetta della combinazione ("Blu / M"): la scrive il generatore
 * e la può correggere chi vende. Serve alla griglia della scheda, al selettore
 * della vetrina, alla riga dell'ordine e all'elenco delle giacenze, che
 * altrimenti dovrebbero ricomporla ogni volta dagli attributi.
```

- [ ] **Step 6: Metti il nome per primo nella griglia**

In `ProductModelResource::productsField()`, la colonna `name` va **prima** di
tutte, perché è l'unica che dice di quale riga si tratti:

```php
                RepeaterColumn::key('id')->hidden(),
                RepeaterColumn::key('name')->text()->label('Versione')->columnSpan(3),
                RepeaterColumn::key('sku')->text()->label('SKU')->columnSpan(2),
                RepeaterColumn::key('ean')->text()->label('EAN')->columnSpan(2),
                RepeaterColumn::key('price')->number()->decimal(2)->label('Prezzo')->columnSpan(2),
                RepeaterColumn::key('sale_price')->number()->decimal(2)->label('Scontato')->columnSpan(2),
                RepeaterColumn::key('active')
                    ->select(['true' => 'Attivo', 'false' => 'Fermo'])
                    ->label('Stato')
                    ->columnSpan(1),
```

Le righe nate prima di oggi hanno il nome vuoto e la casella si vede vuota:
si scrive e si salva. Non c'è nessun riempimento automatico, perché non ci sono
installazioni in produzione da riempire.

- [ ] **Step 7: Aggiungi la check al test dei modelli**

In `tests/ProductModelsTest.php`, aggiungi prima di `summary();`:

```php
check('il prodotto ha il nome della sua combinazione', function () {
    $schema = Product::tableSchema();
    $nomi = array_map(static fn (object $colonna): string => (string) $colonna->getSchema('key'), $schema);

    return in_array('name', $nomi, true);
});
```

Se `getSchema('key')` non è il nome giusto, apri
`packages/app/class/Sql/TableSchema.php` e usa quello che c'è: la check deve
provare che la colonna esiste, non come si legge.

- [ ] **Step 8: Lancia i test**

Run: `php tests/VersionNameTest.php && php tests/ProductModelsTest.php`
Atteso: PASS entrambi.

- [ ] **Step 9: Crea la colonna sul sito di prova**

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update
```

Atteso: nessun errore, e `gst_products` ha la colonna `name`. Controllala:

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge db:columns gst_products 2>/dev/null || echo "comando assente: guarda la tabella con il tuo client MySQL"
```

- [ ] **Step 10: Commit**

```bash
git add src/Support/Catalog/VersionName.php src/Models/Catalog/Product.php tests/VersionNameTest.php tests/ProductModelsTest.php
git commit -m "$(cat <<'MSG'
Let a sellable row say which one it is

Twelve rows of SKU, price and state look identical. The combination
label becomes a real column the shopkeeper can correct, and the storefront
selector and order lines get it for free.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 4: "Come si usa" al posto del livello

`Modello / Variante / Prodotto` sono parole interne. Stesse tre risposte nel
database, parole che un negoziante può scegliere senza sbagliare.

**Files:**
- Modify: `src/Support/Catalog/Attributes.php`
- Modify: `src/Resources/Catalog/AttributeResource.php`
- Test: `tests/AttributesTest.php`

**Interfaces:**
- Produces: `Attributes::LEVELS` con le etichette nuove (chiavi invariate:
  `model`, `variant`, `product`), e `Attributes::createsVersions(string $level): bool`
  — vero per `variant` e `product`.

- [ ] **Step 1: Scrivi i test che falliscono**

In `tests/AttributesTest.php`, sostituisci la check che oggi dice
`Attributes::levels()['variant'] === 'Variante'` con:

```php
check('i livelli si chiamano come li capisce un negoziante', fn () =>
    Attributes::levels()['model'] === 'Descrive l\'articolo'
    && Attributes::levels()['variant'] === 'Crea versioni con pagina e foto proprie'
    && Attributes::levels()['product'] === 'Crea versioni da scegliere nel carrello'
);

check('le chiavi salvate non cambiano', fn () =>
    array_keys(Attributes::levels()) === ['model', 'variant', 'product']
);

check('due livelli su tre creano versioni', fn () =>
    Attributes::createsVersions('variant') === true
    && Attributes::createsVersions('product') === true
    && Attributes::createsVersions('model') === false
    && Attributes::createsVersions('') === false
);
```

- [ ] **Step 2: Lancialo e guardalo fallire**

Run: `php tests/AttributesTest.php`
Atteso: FAIL su tutte e tre.

- [ ] **Step 3: Cambia le etichette e aggiungi il metodo**

In `src/Support/Catalog/Attributes.php`:

```php
    /**
     * A cosa serve un attributo, detto come lo capisce chi vende.
     *
     * Le chiavi sono quelle di sempre — il database non cambia — ma nessuno
     * deve più indovinare cosa sia un "livello". La scelta si fa una volta per
     * negozio: dentro un sito la stessa opzione si comporta sempre allo stesso
     * modo.
     */
    public const LEVELS = [
        'model' => 'Descrive l\'articolo',
        'variant' => 'Crea versioni con pagina e foto proprie',
        'product' => 'Crea versioni da scegliere nel carrello',
    ];
```

e, dopo `usesValues()`:

```php
    /** Gli attributi che fanno nascere righe da vendere. */
    public static function createsVersions(string $level): bool
    {
        return $level === 'variant' || $level === 'product';
    }
```

- [ ] **Step 4: Cambia l'etichetta nella scheda dell'attributo**

In `src/Resources/Catalog/AttributeResource.php`:

- in `labelSchema()`: `'level' => 'Come si usa',`
- nel campo (riga ~94): `->label('Come si usa')`
- nel layout (riga ~151), il `SectionTitle` del riquadro che contiene il campo
  prende il tooltip con gli esempi:

```php
                SectionTitle::make('Attributo')
                    ->tooltip('«Descrive l\'articolo» finisce nella scheda tecnica: Materiale, Composizione. «Crea versioni con pagina e foto proprie» è il Colore nei negozi dove ogni colore è un articolo a sé. «Crea versioni da scegliere nel carrello» è la Taglia.')
                    ->columnSpan(12),
```

Se il `SectionTitle` di quel riquadro ha già un testo diverso da "Attributo",
tienilo com'è e aggiungi solo il `->tooltip(...)`.

- [ ] **Step 5: Lancia i test**

Run: `php tests/AttributesTest.php && php tests/run.php`
Atteso: `AttributesTest` passa. Restano rossi `ProductModelResourceTest` e
`integrazione/CombinazioniTest` dal Task 2.

- [ ] **Step 6: Guardalo nel browser**

`https://ecommerce.test/backend/app/gestionale/attributi` → apri un attributo:
il menu a tendina dice "Come si usa" e offre le tre frasi. Salva e riapri: il
valore è rimasto.

- [ ] **Step 7: Commit**

```bash
git add src/Support/Catalog/Attributes.php src/Resources/Catalog/AttributeResource.php tests/AttributesTest.php
git commit -m "$(cat <<'MSG'
Ask what an attribute is for, not which level it lives on

Same three values in the database, three sentences a shopkeeper can pick
between without knowing what a variant is.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 5: Un solo elenco di opzioni, e il generatore fuori dalla scheda

Due alberi affiancati diventano un campo solo. La generazione esce dalla
Resource: `Generator` legge l'esistente, chiama `Combinations` e scrive le
righe con il loro nome.

**Files:**
- Create: `src/Support/Catalog/Generator.php`
- Modify: `src/Resources/Catalog/ProductModelResource.php`
- Modify: `src/Support/Errors/UserError.php` (un messaggio in più, se i
  messaggi stanno lì; vedi Step 4)
- Test: `tests/OptionsTest.php`
- Test: `tests/ProductModelResourceTest.php`
- Test: `tests/integrazione/CombinazioniTest.php`

**Interfaces:**
- Consumes: `Combinations::plan(array $variantValues, array $axes, array $existing): array`,
  `Combinations::key(int $variantValueId, array $valueIds): string`,
  `VersionName::from(array $labels, string $fallback = ''): string`.
- Produces:
  - `ProductModelResource::optionTree(): array` — l'albero da spuntare, un nodo
    `attr_<id>` per ogni opzione (livelli `variant` e `product`) con i suoi valori.
  - `ProductModelResource::chosenAxes(mixed $chosen): array` — torna
    `array{variant: list<array{id:int,label:string}>, axes: list<list<array{id:int,label:string}>>}`;
    lancia `UserError` se le spunte toccano più di un'opzione con pagina propria.
  - `Generator::run(int $modelId, array $variantValues, array $axes, string $modelSku): void`
  - `Generator::existing(int $modelId): array` — nella forma che `Combinations` legge.

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/OptionsTest.php` — prova lo smistamento senza database, con una
sottoclasse anonima che finge il catalogo:

```php
<?php
/** php tests/OptionsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/** Un catalogo finto: Colore ha pagina propria, Taglia e Lunghezza no. */
$scheda = new class extends ProductModelResource {
    public static function attributes(): array
    {
        return [
            ['id' => 1, 'name' => 'Colore', 'type' => 'select', 'level' => 'variant', 'unit' => '', 'position' => 1],
            ['id' => 2, 'name' => 'Taglia', 'type' => 'select', 'level' => 'product', 'unit' => '', 'position' => 2],
            ['id' => 3, 'name' => 'Lunghezza', 'type' => 'select', 'level' => 'product', 'unit' => '', 'position' => 3],
            ['id' => 4, 'name' => 'Materiale', 'type' => 'select', 'level' => 'model', 'unit' => '', 'position' => 4],
            ['id' => 5, 'name' => 'Gusto', 'type' => 'select', 'level' => 'variant', 'unit' => '', 'position' => 5],
        ];
    }

    public static function attributeValues(): array
    {
        return [
            10 => ['id' => 10, 'attribute_id' => 1, 'label' => 'Blu'],
            11 => ['id' => 11, 'attribute_id' => 1, 'label' => 'Rosso'],
            20 => ['id' => 20, 'attribute_id' => 2, 'label' => 'S'],
            21 => ['id' => 21, 'attribute_id' => 2, 'label' => 'M'],
            30 => ['id' => 30, 'attribute_id' => 3, 'label' => 'Corta'],
            40 => ['id' => 40, 'attribute_id' => 4, 'label' => 'Cotone'],
            50 => ['id' => 50, 'attribute_id' => 5, 'label' => 'Fragola'],
        ];
    }
};

check('l\'albero mostra solo le opzioni, non le caratteristiche', function () use ($scheda) {
    $albero = $scheda::optionTree();

    return isset($albero['attr_1'], $albero['attr_2'], $albero['attr_3'], $albero['attr_5'])
        && !isset($albero['attr_4'])
        && $albero['attr_1']['name'] === 'Colore'
        && array_keys($albero['attr_1']['child']) === ['10', '11'];
});

check('le spunte si dividono fra pagina propria e resto', function () use ($scheda) {
    $scelte = $scheda::chosenAxes(['attr_1', '10', '11', '20', '21', '30']);

    return count($scelte['variant']) === 2
        && $scelte['variant'][0] === ['id' => 10, 'label' => 'Blu']
        && count($scelte['axes']) === 2;
});

check('ogni opzione è un asse a sé', function () use ($scheda) {
    $scelte = $scheda::chosenAxes(['20', '21', '30']);
    $misure = array_map('count', $scelte['axes']);
    sort($misure);

    return $scelte['variant'] === [] && $misure === [1, 2];
});

check('le caratteristiche spuntate per sbaglio non contano', function () use ($scheda) {
    $scelte = $scheda::chosenAxes(['40', '20']);

    return $scelte['variant'] === [] && count($scelte['axes']) === 1;
});

check('due opzioni con pagina propria sono un rifiuto', function () use ($scheda) {
    try {
        $scheda::chosenAxes(['10', '50']);
    } catch (UserError $errore) {
        return str_contains($errore->getMessage(), 'una sola opzione');
    }

    return false;
});

check('niente spuntato, niente assi', function () use ($scheda) {
    $scelte = $scheda::chosenAxes([]);

    return $scelte === ['variant' => [], 'axes' => []];
});

summary();
```

- [ ] **Step 2: Lancialo e guardalo fallire**

Run: `php tests/OptionsTest.php`
Atteso: FAIL — `optionTree()` e `chosenAxes()` non esistono.

- [ ] **Step 3: Scrivi `optionTree()` e `chosenAxes()`**

In `ProductModelResource`, **al posto** di `valueTree(string $level)` (che
sparisce):

```php
    /**
     * L'albero delle opzioni da spuntare: un nodo per opzione, i valori sotto.
     *
     * Uno solo, non due: chi compila non deve sapere quali opzioni abbiano
     * pagina propria e quali no. Lo smistamento lo fa `chosenAxes()`.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function optionTree(): array
    {
        $tree = [];

        foreach (static::attributes() as $attribute) {
            if (!Attributes::createsVersions((string) ($attribute['level'] ?? ''))) {
                continue;
            }

            if (!Attributes::usesValues((string) ($attribute['type'] ?? ''))) {
                continue;
            }

            $id = (int) $attribute['id'];
            $children = [];

            foreach (static::attributeValues() as $value) {
                if ((int) ($value['attribute_id'] ?? 0) === $id) {
                    $children[(string) $value['id']] = [
                        'name' => (string) ($value['label'] ?? ''),
                        'child' => [],
                    ];
                }
            }

            if ($children !== []) {
                // La chiave dell'opzione non è un valore: chi legge le spunte
                // tiene solo gli id che sono davvero dei valori.
                $tree['attr_'.$id] = [
                    'name' => (string) ($attribute['name'] ?? ''),
                    'child' => $children,
                ];
            }
        }

        return $tree;
    }

    /**
     * Le spunte, divise in assi.
     *
     * `variant` è l'asse con pagina propria — al massimo uno, altrimenti non si
     * saprebbe quale colore sia la pagina. Gli altri finiscono in `axes`, un
     * elemento per opzione, e si moltiplicano fra loro.
     *
     * @return array{variant: list<array{id: int, label: string}>, axes: list<list<array{id: int, label: string}>>}
     */
    public static function chosenAxes(mixed $chosen): array
    {
        $ids = array_filter(array_map('intval', (array) $chosen), static fn (int $id): bool => $id > 0);

        if ($ids === []) {
            return ['variant' => [], 'axes' => []];
        }

        $levels = [];

        foreach (static::attributes() as $attribute) {
            $levels[(int) $attribute['id']] = (string) ($attribute['level'] ?? '');
        }

        $variant = [];
        $variantAttributes = [];
        $byAttribute = [];

        foreach (static::attributeValues() as $value) {
            $id = (int) ($value['id'] ?? 0);

            if (!in_array($id, $ids, true)) {
                continue;
            }

            $attributeId = (int) ($value['attribute_id'] ?? 0);
            $level = $levels[$attributeId] ?? '';
            $entry = ['id' => $id, 'label' => (string) ($value['label'] ?? '')];

            if ($level === 'variant') {
                $variantAttributes[$attributeId] = true;
                $variant[] = $entry;
                continue;
            }

            if ($level === 'product') {
                $byAttribute[$attributeId][] = $entry;
            }
        }

        if (count($variantAttributes) > 1) {
            throw UserError::make('Un articolo può avere una sola opzione con pagina e foto proprie.');
        }

        return ['variant' => $variant, 'axes' => array_values($byAttribute)];
    }
```

- [ ] **Step 4: Controlla come si scrive un rifiuto**

Apri `src/Support/Errors/UserError.php` e guarda cosa accetta `make()`: se
prende una **chiave** (come `'attribute.type_locked'`) e non una frase, aggiungi
la voce nuova dove stanno le altre, con il testo
`Un articolo può avere una sola opzione con pagina e foto proprie.`, e usa
quella chiave nello Step 3 al posto della frase. Poi allinea il test dello
Step 1, che cerca `'una sola opzione'` nel messaggio.

- [ ] **Step 5: Lancia il test**

Run: `php tests/OptionsTest.php`
Atteso: PASS, 6 test.

- [ ] **Step 6: Scrivi il generatore**

`src/Support/Catalog/Generator.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Support\Codes;

/**
 * Crea le varianti e i prodotti che mancano, dati gli assi spuntati.
 *
 * Sta fuori dalla scheda perché la scheda ha già il suo lavoro — campi,
 * layout, lettura del posted — e questo è un lavoro diverso: leggere cosa c'è,
 * chiedere a `Combinations` cosa manca, scrivere le righe con il loro nome e i
 * loro collegamenti.
 *
 * Quando il modello ha ancora solo lo scheletro — una variante e un prodotto
 * senza attributi — la prima combinazione lo riusa, invece di lasciare in giro
 * una variante vuota.
 */
final class Generator
{
    /**
     * @param list<array{id: int, label: string}> $variantValues
     * @param list<list<array{id: int, label: string}>> $axes
     */
    public static function run(int $modelId, array $variantValues, array $axes, string $modelSku = ''): void
    {
        if ($modelId <= 0 || ($variantValues === [] && $axes === [])) {
            return;
        }

        $plan = Combinations::plan($variantValues, $axes, self::existing($modelId));

        if ($plan['variants'] === [] && $plan['products'] === []) {
            return;
        }

        if ($modelSku === '') {
            $model = ProductModel::find(['id' => $modelId], 1);
            $modelSku = is_array($model) ? (string) ($model['sku'] ?? '') : '';
        }

        $attributeOf = self::attributeOfValues();
        $reuse = self::skeletonToReuse($modelId);
        $variantIds = self::existing($modelId)['variants'];
        $position = count(self::variants($modelId));

        foreach ($plan['variants'] as $variant) {
            $valueId = (int) $variant['value_id'];

            if ($reuse !== null && $reuse['variant_id'] > 0) {
                $variantId = $reuse['variant_id'];
                ProductVariant::update(['name' => $variant['label']], $variantId);
                $reuse['variant_id'] = 0;
            } else {
                $created = ProductVariant::create([
                    'code' => Code::make(ProductVariant::class, Codes::VARIANT),
                    'product_model_id' => $modelId,
                    'name' => $variant['label'],
                    'slug' => Slug::make($variant['label'].'-'.$modelId.'-'.$valueId),
                    'position' => ++$position,
                    'visible' => 'true',
                ]);
                $variantId = (int) ($created->insert_id ?? 0);
            }

            if ($variantId === 0) {
                continue;
            }

            $variantIds[$valueId] = $variantId;
            $attribute = $attributeOf[$valueId] ?? null;

            if ($attribute !== null) {
                ProductAttributes::save('variant', $variantId, [$attribute], [
                    (int) $attribute['id'] => (string) $valueId,
                ]);
            }
        }

        $productPosition = count(self::products($modelId));

        foreach ($plan['products'] as $product) {
            $valueId = (int) $product['variant_value_id'];
            $variantId = $valueId > 0
                ? ($variantIds[$valueId] ?? 0)
                : (int) (self::variants($modelId)[0]['id'] ?? 0);

            if ($variantId === 0) {
                continue;
            }

            $sku = Sku::propose($modelSku, $product['labels']);
            $name = VersionName::from($product['labels'], $sku);

            if ($reuse !== null && $reuse['product_id'] > 0) {
                $productId = $reuse['product_id'];
                Product::update([
                    'product_variant_id' => $variantId,
                    'sku' => $sku,
                    'name' => $name,
                ], $productId);
                $reuse['product_id'] = 0;
            } else {
                $created = Product::create([
                    'code' => Code::make(Product::class, Codes::PRODUCT),
                    'product_model_id' => $modelId,
                    'product_variant_id' => $variantId,
                    'sku' => $sku,
                    'name' => $name,
                    'position' => ++$productPosition,
                    'active' => 'true',
                ]);
                $productId = (int) ($created->insert_id ?? 0);
            }

            if ($productId === 0) {
                continue;
            }

            // Un collegamento per asse: con tre opzioni spuntate il prodotto
            // ne ha tre, non uno.
            foreach ($product['value_ids'] as $productValueId) {
                $attribute = $attributeOf[(int) $productValueId] ?? null;

                if ($attribute === null) {
                    continue;
                }

                ProductAttributes::save('product', $productId, [$attribute], [
                    (int) $attribute['id'] => (string) $productValueId,
                ]);
            }
        }
    }

    /**
     * Varianti e prodotti già presenti, nella forma che `Combinations` legge.
     *
     * @return array{variants: array<int, int>, products: array<string, bool>}
     */
    public static function existing(int $modelId): array
    {
        $variants = [];
        $variantValueOf = [];

        foreach (self::variants($modelId) as $variant) {
            $variantId = (int) $variant['id'];

            foreach (ProductAttributes::read('variant', $variantId) as $link) {
                $valueId = (int) ($link['attribute_value_id'] ?? 0);

                if ($valueId > 0) {
                    $variants[$valueId] = $variantId;
                    $variantValueOf[$variantId] = $valueId;
                }
            }
        }

        $products = [];

        foreach (self::products($modelId) as $product) {
            $variantValue = $variantValueOf[(int) ($product['product_variant_id'] ?? 0)] ?? 0;
            $valueIds = [];

            foreach (ProductAttributes::read('product', (int) $product['id']) as $link) {
                $valueId = (int) ($link['attribute_value_id'] ?? 0);

                if ($valueId > 0) {
                    $valueIds[] = $valueId;
                }
            }

            $products[Combinations::key($variantValue, $valueIds)] = true;
        }

        return ['variants' => $variants, 'products' => $products];
    }

    /**
     * Lo scheletro da riusare: una variante e un prodotto, senza attributi.
     *
     * @return array{variant_id: int, product_id: int}|null
     */
    private static function skeletonToReuse(int $modelId): ?array
    {
        $variants = self::variants($modelId);
        $products = self::products($modelId);

        if (count($variants) !== 1 || count($products) !== 1) {
            return null;
        }

        if (ProductAttributes::read('variant', (int) $variants[0]['id']) !== []
            || ProductAttributes::read('product', (int) $products[0]['id']) !== []) {
            return null;
        }

        return ['variant_id' => (int) $variants[0]['id'], 'product_id' => (int) $products[0]['id']];
    }

    /** L'attributo di ogni valore, per id del valore. @return array<int, array<string, mixed>> */
    private static function attributeOfValues(): array
    {
        $attributes = [];

        foreach (Attributes::all() as $attribute) {
            $attributes[(int) $attribute['id']] = $attribute;
        }

        $byValue = [];

        foreach (Attributes::allValues() as $value) {
            $attributeId = (int) ($value['attribute_id'] ?? 0);

            if (isset($attributes[$attributeId])) {
                $byValue[(int) $value['id']] = $attributes[$attributeId];
            }
        }

        return $byValue;
    }

    /** @return list<array<string, mixed>> */
    private static function variants(int $modelId): array
    {
        return self::rows(ProductVariant::class, $modelId);
    }

    /** @return list<array<string, mixed>> */
    private static function products(int $modelId): array
    {
        return self::rows(Product::class, $modelId);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(string $modelClass, int $modelId): array
    {
        $rows = $modelClass::find(['product_model_id' => $modelId, 'deleted' => 'false'], null, 'position', 'ASC');

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
```

**Attenzione:** `Attributes::all()` e `Attributes::allValues()` non esistono —
`Attributes` è una classe pura senza database, e le letture stanno in
`ProductModelResource::attributes()` / `::attributeValues()`. Sostituiscili con
la lettura diretta dei Model, con lo stesso `self::rows()` ma senza il filtro
sul modello:

```php
    /** L'attributo di ogni valore, per id del valore. @return array<int, array<string, mixed>> */
    private static function attributeOfValues(): array
    {
        $attributes = [];

        foreach (self::allRows(Attribute::class) as $attribute) {
            $attributes[(int) $attribute['id']] = $attribute;
        }

        $byValue = [];

        foreach (self::allRows(AttributeValue::class) as $value) {
            $attributeId = (int) ($value['attribute_id'] ?? 0);

            if (isset($attributes[$attributeId])) {
                $byValue[(int) $value['id']] = $attributes[$attributeId];
            }
        }

        return $byValue;
    }

    /** @return list<array<string, mixed>> */
    private static function allRows(string $modelClass): array
    {
        $rows = $modelClass::find(['deleted' => 'false'], null, 'position', 'ASC');

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
```

con gli `use` di `Wonder\Plugin\Gestionale\Models\Catalog\Attribute` e
`...\AttributeValue`. Controlla la firma di `Model::find()` in
`packages/app/class/App/Model.php` e adattala se ordine e direzione si passano
diversamente: `GestionaleResource::rowsOf()` fa la stessa cosa e può servirti
da esempio.

- [ ] **Step 7: Attacca la scheda al generatore**

In `ProductModelResource`:

- togli i metodi `generateCombinations()`, `existingCombinations()`,
  `skeletonToReuse()`, `attributeOfValues()`, `chosenValues()` e
  `valueTree()`;
- in `formSchema()`, al posto dei due blocchi `variant_values` /
  `product_values`:

```php
        if (static::optionTree() !== []) {
            $fields[] = FormField::key('option_values')
                ->checkTree(static::optionTree(), true)
                ->label('In quali versioni si vende');
        }
```

- in `withoutExtras()`, al posto di `$values['variant_values']` e
  `$values['product_values']`: `$values['option_values']`;
- in `saveExtras()`, al posto di `static::generateCombinations($modelId, $post);`:

```php
            $chosen = static::chosenAxes($post['option_values'] ?? []);
            Generator::run($modelId, $chosen['variant'], $chosen['axes'], $fallbackSku);
```

- in `mutateRequestValues()`, prima del `return`, aggiungi il controllo che
  rifiuta subito, prima ancora di salvare il modello:

```php
        // Meglio rifiutare qui che dopo: `saveExtras()` gira dentro una
        // transazione e un rifiuto lì lascerebbe il modello salvato a metà.
        static::chosenAxes($_POST['option_values'] ?? []);
```

con l'`use` di `Wonder\Plugin\Gestionale\Support\Catalog\Generator`.

- [ ] **Step 8: Aggiorna il test della scheda**

In `tests/ProductModelResourceTest.php` cerca ogni riferimento a
`variant_values`, `product_values` e `valueTree` e sostituiscilo con
`option_values` e `optionTree`. Aggiungi:

```php
check('le spunte delle versioni stanno in un campo solo', function () use ($campi) {
    return !isset($campi()['variant_values']) && !isset($campi()['product_values']);
});
```

- [ ] **Step 9: Aggiorna il test d'integrazione**

In `tests/integrazione/CombinazioniTest.php`, sostituisci le chiamate a
`ProductModelResource::saveExtras(...)` che passano `variant_values` e
`product_values` con un unico `option_values` che contiene tutti gli id
spuntati, e aggiungi in fondo, prima di `throw new Annulla`:

```php
    check('ogni prodotto nasce con il suo nome', function () use ($modelId) {
        foreach (ProductModelResource::products($modelId) as $prodotto) {
            if (trim((string) ($prodotto['name'] ?? '')) === '') {
                return false;
            }
        }

        return true;
    });

    check('due opzioni con pagina propria vengono rifiutate', function () use ($colore, $gusto) {
        try {
            ProductModelResource::chosenAxes([
                (string) $colore['values'][0],
                (string) $gusto['values'][0],
            ]);
        } catch (\Wonder\Plugin\Gestionale\Support\Errors\UserError $errore) {
            return true;
        }

        return false;
    });
```

dove `$gusto` è un secondo attributo di livello `variant` creato con la closure
`$attributo` che il file ha già in cima:

```php
    $gusto = $attributo('Gusto di prova', 'variant', 'select', ['Fragola']);
```

Ricorda `ProductModelResource::forgetCatalogCache();` dopo aver creato attributi
e valori, altrimenti la scheda legge la cache calda del boot.

- [ ] **Step 10: Rifiuta di restare senza versioni**

La griglia lascia eliminare le righe una per una, fino all'ultima: un articolo
senza niente da vendere non esiste, e il database non lo direbbe mai.

In `ProductModelResource::mutateRequestValues()`, accanto al controllo delle
opzioni:

```php
        // Il repeater dei prodotti esiste solo quando le righe sono più di
        // una: se c'è e torna vuoto, vuol dire che le hanno cancellate tutte.
        if ($id > 0 && static::productCount($id) > 1 && isset($_POST['products'])) {
            $righe = array_filter((array) $_POST['products'], 'is_array');

            if ($righe === []) {
                throw UserError::make('Un articolo deve avere almeno una versione in vendita.');
            }
        }
```

(Se `UserError::make()` vuole una chiave invece di una frase, aggiungi la voce
dove stanno le altre, come hai fatto allo Step 4.)

E il test, in `tests/ProductModelResourceTest.php`:

```php
check('un articolo non può restare senza versioni', function () {
    $scheda = new class extends ProductModelResource {
        public static function productCount(int $modelId): int
        {
            return 6;
        }
    };

    $_POST['products'] = [];

    try {
        $scheda::mutateRequestValues(['name' => 'Maglietta'], 'update', 'backend', ['id' => 1]);
    } catch (UserError $errore) {
        unset($_POST['products']);

        return str_contains($errore->getMessage(), 'almeno una versione');
    }

    unset($_POST['products']);

    return false;
});
```

Se `mutateRequestValues()` inciampa prima, su un'altra regola (SKU, EAN), passa
nei valori quello che serve a farla arrivare fin lì: guarda cosa controlla, in
ordine, e aggiungi il minimo.

- [ ] **Step 11: Lancia tutto**

Run: `php tests/run.php`
Atteso: verde, compresi `CombinationsTest`, `OptionsTest`,
`ProductModelResourceTest` e `integrazione/CombinazioniTest`.

- [ ] **Step 12: Provalo nel browser**

Su `https://ecommerce.test/backend/`:

1. `php forge gestionale:demo --fresh` dalla cartella del sito, per avere
   attributi e valori puliti.
2. Apri un modello, spunta due colori e tre taglie nel riquadro unico, salva.
3. Devono nascere **sei righe**, ognuna con il suo nome ("Blu / S", "Blu / M"…).
4. Risalva senza cambiare niente: **non deve nascere niente**.
5. Spunta anche un valore di una seconda opzione con pagina propria: deve
   comparire l'avviso, non una pagina 500.
6. Elimina tutte le righe della griglia e salva: deve comparire l'avviso che
   serve almeno una versione.

- [ ] **Step 13: Commit**

```bash
git add src/Support/Catalog/Generator.php src/Resources/Catalog/ProductModelResource.php tests/OptionsTest.php tests/ProductModelResourceTest.php tests/integrazione/CombinazioniTest.php
git commit -m "$(cat <<'MSG'
Tick options in one list, and move the generator out of the form

The card asked where to tick by showing two trees named after internal
levels. Now there is one list, the level is resolved on save, and the
row-writing lives in its own class with the naming and the per-axis links.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 6: La scheda a sette riquadri

Dieci riquadri aperti diventano sette, di cui due chiusi. Nessun campo nuovo:
cambiano ordine, raggruppamento e titoli.

**Files:**
- Modify: `src/Resources/Catalog/ProductModelResource.php` (`formLayoutSchema()`)
- Test: `tests/ProductModelResourceTest.php`

**Interfaces:**
- Consumes: `GestionaleResource::foldable(string $title, array $components, string $tooltip = ''): object`.

- [ ] **Step 1: Scrivi il test che fallisce**

In `tests/ProductModelResourceTest.php`, aggiungi:

```php
/** I titoli dei riquadri, nell'ordine in cui la scheda li mette. */
$riquadri = static function (): array {
    $form = ProductModelResource::formLayoutSchema();
    $contenitore = $form->components[0] ?? null;
    $titoli = [];

    foreach ($contenitore->components ?? [] as $riquadro) {
        // Una Card mette il titolo in un SectionTitle; un Accordion ce l'ha suo.
        $testo = method_exists($riquadro, 'getText') ? (string) $riquadro->getText() : '';

        if ($testo === '') {
            foreach ($riquadro->components ?? [] as $dentro) {
                if ($dentro instanceof \Wonder\Elements\Components\SectionTitle) {
                    $testo = (string) $dentro->getText();
                    break;
                }
            }
        }

        if ($testo !== '') {
            $titoli[] = $testo;
        }
    }

    return $titoli;
};

check('la scheda di un articolo nuovo ha pochi riquadri e nell\'ordine giusto', function () use ($riquadri) {
    $titoli = $riquadri();

    return array_slice($titoli, 0, 4) === ['Prodotto', 'Descrizione', 'Dove si trova', 'Spedizione e fisco'];
});

check('le vecchie parole non compaiono più nei titoli', function () use ($riquadri) {
    foreach ($riquadri() as $titolo) {
        foreach (['Articolo', 'Varianti', 'Genera varianti e prodotti', 'Categorie e tag'] as $vecchio) {
            if ($titolo === $vecchio) {
                return false;
            }
        }
    }

    return true;
});
```

Se `getText()` non è il metodo giusto per leggere il testo di un
`SectionTitle` o di un `Accordion`, apri
`packages/app/class/Elements/Concerns/HasText.php` e usa quello che c'è.

- [ ] **Step 2: Lancialo e guardalo fallire**

Run: `php tests/ProductModelResourceTest.php`
Atteso: FAIL sui due nuovi.

- [ ] **Step 3: Riscrivi `formLayoutSchema()`**

Sostituisci il corpo del metodo con questo, tenendo le condizioni che già
c'erano (`$modelId`, `productCount`, `variantCount`):

```php
    public static function formLayoutSchema(): ?Form
    {
        $modelId = static::currentId();
        $unaVersione = $modelId === null || static::productCount($modelId) <= 1;

        $prodotto = [
            SectionTitle::make('Prodotto')
                ->tooltip('Lo SKU è il codice di famiglia: da lì il pannello propone quello delle singole versioni. L\'url pubblico nasce dal nome alla creazione e non cambia più.')
                ->columnSpan(12),
            static::getInput('name')->columnSpan(6),
            static::getInput('sku')->columnSpan(3),
            static::getInput('visible')->columnSpan(3),
        ];

        if ($unaVersione) {
            $prodotto[] = static::getInput('product_price')->columnSpan(3);
            $prodotto[] = static::getInput('product_sale_price')->columnSpan(3);
            $prodotto[] = static::getInput('product_sku')->columnSpan(3);
            $prodotto[] = static::getInput('product_ean')->columnSpan(3);
        }

        $cards = [(new Card)->components($prodotto)->columns(12)->columnSpan(12)];

        if (static::optionTree() !== []) {
            $versioni = [static::getInput('option_values')->columnSpan(12)];

            if ($modelId !== null && static::productCount($modelId) > 1) {
                $versioni[] = static::getInput('products')->columnSpan(12);
            }

            if ($modelId !== null && static::variantCount($modelId) > 1) {
                $versioni[] = static::getInput('variants')->columnSpan(12);
            }

            $aiuto = 'Spunta i valori e salva: nascono le righe che mancano, con il nome e lo SKU proposti. Togliere una spunta non cancella niente; per eliminare una versione si elimina la sua riga.';

            // Finché la versione è una sola il blocco non serve a nessuno:
            // sta chiuso, e chi ne ha bisogno lo apre.
            $cards[] = $unaVersione
                ? static::foldable('Si vende in più versioni? (colori, taglie…)', $versioni, $aiuto)
                : (new Card)->components([
                    SectionTitle::make('Versioni in vendita')->tooltip($aiuto)->columnSpan(12),
                    ...$versioni,
                ])->columns(12)->columnSpan(12);
        }

        if ($modelId !== null) {
            $cards[] = (new Card)->components([
                SectionTitle::make('Foto')
                    ->tooltip('Carica e salva: le foto si vedono subito, le misure per il sito arrivano poco dopo. Una foto senza versione vale per tutto l\'articolo.')
                    ->columnSpan(12),
                static::getInput('images')->columnSpan(12),
            ])->columns(12)->columnSpan(12);
        }

        $cards[] = (new Card)->components([
            SectionTitle::make('Descrizione')->columnSpan(12),
            static::getInput('short_description')->columnSpan(12),
            static::getInput('description')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        $cards[] = (new Card)->components([
            SectionTitle::make('Dove si trova')
                ->tooltip('La categoria principale è quella che la vetrina userà per l\'indirizzo della pagina: se la scegli e non l\'hai spuntata, viene aggiunta da sé.')
                ->columnSpan(12),
            static::getInput('brand_id')->columnSpan(4),
            static::getInput('main_category')->columnSpan(4),
            static::getInput('tags')->columnSpan(4),
            static::getInput('categories')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        $attributeInputs = [];

        foreach (Attributes::byLevel(static::attributes(), 'model') as $attribute) {
            $attributeInputs[] = static::getInput('attribute_'.(int) $attribute['id'])->columnSpan(4);
        }

        if ($attributeInputs !== []) {
            $cards[] = static::foldable(
                'Scheda tecnica',
                $attributeInputs,
                'Quello che descrive l\'articolo e non fa nascere versioni: materiale, composizione, paese.'
            );
        }

        $cards[] = static::foldable('Spedizione e fisco', [
            static::getInput('unit')->columnSpan(3),
            static::getInput('tax_category_id')->columnSpan(3),
            static::getInput('visible_online')->columnSpan(3),
            static::getInput('weight')->columnSpan(3),
            static::getInput('length')->columnSpan(3),
            static::getInput('width')->columnSpan(3),
            static::getInput('height')->columnSpan(3),
            static::getInput('returnable')->columnSpan(6),
            static::getInput('requires_shipping')->columnSpan(6),
        ], 'Peso e misure dell\'articolo, unità di vendita e tipo fiscale. Una versione con misure sue le usa al posto di queste.');

        return (new Form)->components([
            (new Container)->components($cards)->columns(12)->columnSpan(12),
        ]);
    }
```

Nota: i due repeater (`products`, `variants`) restano dichiarati in
`formSchema()` **solo** quando le righe sono più di una — non toccare quelle
condizioni, perché un repeater non stampato fa cancellare le sue righe.

- [ ] **Step 4: Lancia i test**

Run: `php tests/run.php`
Atteso: verde.

- [ ] **Step 5: Contali nel browser**

Apri la scheda di un articolo semplice (il cappello dei dati di prova): devono
esserci **quattro** riquadri aperti — Prodotto, Foto, Descrizione, Dove si
trova — più due chiusi e il blocco delle versioni chiuso. Apri la maglietta con
dodici versioni: il riquadro "Versioni in vendita" è aperto e contiene la
griglia.

- [ ] **Step 6: Commit**

```bash
git add src/Resources/Catalog/ProductModelResource.php tests/ProductModelResourceTest.php
git commit -m "$(cat <<'MSG'
Cut the product card from ten boxes to seven

Ten equally loud sections have no hierarchy, so everything looks
mandatory. A woollen hat now shows four; the two nobody opens are folded
and the sales grid only appears once there is more than one row.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 7: Vocabolario, indirizzi e navigazione

Una parola sola nel pannello, e l'elenco piatto fuori dal menu.

**Files:**
- Modify: `src/Resources/Catalog/ProductModelResource.php`
- Modify: `src/Resources/Catalog/ProductResource.php`
- Test: `tests/ProductModelResourceTest.php`
- Test: `tests/ProductResourceTest.php`

**Interfaces:**
- Produces: `ProductModelResource::path()` torna `'app/gestionale/prodotti'`;
  `ProductResource::path()` torna `'app/gestionale/versioni'` ed è fuori dal
  menu; `ProductResource::querySchema()` filtra su `?prodotto=<id>`.

- [ ] **Step 1: Scrivi i test che falliscono**

In `tests/ProductModelResourceTest.php`, cambia la prima check in:

```php
check('la pagina dei prodotti sta nel catalogo', fn () =>
    ProductModelResource::$model === ProductModel::class
    && ProductModelResource::path() === 'app/gestionale/prodotti'
    && ProductModelResource::titleLabel() === 'Prodotti'
    && (ProductModelResource::navigationSchema()->toArray()['section_key'] ?? '') === 'catalogo'
);
```

In `tests/ProductResourceTest.php`, aggiungi:

```php
check('la singola versione non sta nel menu', fn () =>
    ProductResource::path() === 'app/gestionale/versioni'
    && (ProductResource::navigationSchema()->toArray()['enabled'] ?? true) === false
);

check('l\'elenco si filtra sull\'articolo', function () {
    $_GET['prodotto'] = '7';
    $condizione = ProductResource::querySchema()['condition'] ?? [];
    unset($_GET['prodotto']);

    return ($condizione['product_model_id'] ?? null) === 7;
});

check('senza articolo nell\'indirizzo si vede tutto', function () {
    unset($_GET['prodotto']);

    return !isset(ProductResource::querySchema()['condition']['product_model_id']);
});
```

Controlla in `packages/app/class/App/ResourceSchema/NavigationSchema.php` come
si chiama la chiave di `enabled()` in `toArray()` e usa quella.

- [ ] **Step 2: Lancialo e guardalo fallire**

Run: `php tests/ProductModelResourceTest.php && php tests/ProductResourceTest.php`
Atteso: FAIL.

- [ ] **Step 3: Rinomina la pagina dei modelli**

In `ProductModelResource`:

```php
    public static function path(): string
    {
        return 'app/gestionale/prodotti';
    }

    public static function titleLabel(): string
    {
        return 'Prodotti';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'prodotto',
            'plural_label' => 'prodotti',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'full' => 'visibile',
            'empty' => 'nascosto',
            'this' => 'questo',
        ];
    }
```

in `labelSchema()`: `'sku' => 'SKU'` resta, ma il titolo delle pagine cambia in
`pageSchema()`:

```php
            ->titles([
                'list' => 'Prodotti',
                'create' => 'Nuovo prodotto',
                'edit' => 'Modifica prodotto',
            ]);
```

e in `navigationSchema()`: `->title('Prodotti')`.

Aggiorna anche il docblock della classe: la scheda non è più "il modello", è
"il prodotto", e le tre parole restano solo nel database.

- [ ] **Step 4: Sposta la singola versione fuori dal menu**

In `ProductResource`:

```php
    public static function path(): string
    {
        return 'app/gestionale/versioni';
    }

    public static function titleLabel(): string
    {
        return 'Versioni in vendita';
    }

    public static function navigationSchema(): NavigationSchema
    {
        // Fuori dal menu: una versione si apre dalla scheda del suo prodotto.
        // Un elenco piatto di articoli in vendita avrà senso con le giacenze.
        return parent::navigationSchema()->enabled(false);
    }

    /**
     * L'elenco, filtrato sull'articolo quando l'indirizzo lo dice.
     *
     * Il core rivaluta `querySchema()` a ogni richiesta, quindi leggere la
     * query string qui è sicuro: è la stessa strada di `currentId()`.
     */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        $modelId = (int) ($_GET['prodotto'] ?? 0);

        if ($modelId > 0) {
            $schema['condition'] = array_merge(
                (array) ($schema['condition'] ?? []),
                ['product_model_id' => $modelId]
            );
        }

        return $schema;
    }
```

Sostituisci anche le etichette `'product_model_id' => 'Modello'` e
`'product_variant_id' => 'Variante'` con `'Prodotto'` e `'Versione'`, e i
titoli delle pagine con "Versione in vendita".

- [ ] **Step 5: Metti il pulsante nella scheda**

In `ProductModelResource::pageSchema()`, aggiungi le azioni della pagina di
modifica:

```php
    public static function pageSchema(): PageSchema
    {
        $modelId = static::currentId();
        $schema = parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Prodotti',
                'create' => 'Nuovo prodotto',
                'edit' => 'Modifica prodotto',
            ]);

        // I campi rari di una singola versione — codice del produttore, misure
        // proprie, ordinabile su richiesta — non stanno in griglia.
        if ($modelId !== null && static::productCount($modelId) > 1) {
            $schema->actions('edit', [[
                'href' => '/backend/'.ProductResource::path().'?prodotto='.$modelId,
                'label' => 'Dettagli delle versioni',
                'icon' => 'bi-upc-scan',
            ]]);
        }

        return $schema;
    }
```

Controlla in `packages/app/class/App/ResourceSchema/PageSchema.php` (metodo
`actions()`) e in chi lo consuma quali chiavi servono davvero nel descrittore,
e come si compone l'indirizzo di una Resource (cerca `listUrl()` in
`ResourcePagePresenter`): se il prefisso `/backend/` è già aggiunto altrove,
toglilo da qui.

- [ ] **Step 6: Lancia i test**

Run: `php tests/run.php`
Atteso: verde. Se `DocsPagesTest` si lamenta, è il Task 10: per ora lascia
`$docsPage` com'è.

- [ ] **Step 7: Guardalo nel browser**

- `https://ecommerce.test/backend/app/gestionale/prodotti` risponde e mostra
  l'elenco.
- Nel menu Catalogo ci sono `Prodotti · Categorie · Tag · Marchi · Attributi`,
  e **non** c'è più la vecchia voce dei prodotti piatti.
- Dalla scheda di un articolo con più versioni, il pulsante "Dettagli delle
  versioni" apre l'elenco **filtrato** su quell'articolo.

- [ ] **Step 8: Commit**

```bash
git add src/Resources/Catalog/ProductModelResource.php src/Resources/Catalog/ProductResource.php tests/ProductModelResourceTest.php tests/ProductResourceTest.php
git commit -m "$(cat <<'MSG'
Call it a product, everywhere a person can read

Models become Products at app/gestionale/prodotti. The flat list leaves
the menu and lives at app/gestionale/versioni, filtered by article and
reached with a button from the card, because a few rare fields do not fit
a grid row.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 8: L'elenco che si riconosce a colpo d'occhio

**Files:**
- Modify: `src/Resources/Catalog/ProductModelResource.php` (`tableSchema()` e due letture)
- Test: `tests/ProductModelResourceTest.php`

**Interfaces:**
- Produces: `ProductModelResource::priceRange(int $modelId): string` — `"19,90"`
  con un prezzo solo, `"da 19,90"` con prezzi diversi, `""` senza prezzi.

- [ ] **Step 1: Scrivi il test che fallisce**

In `tests/ProductModelResourceTest.php`:

```php
check('l\'elenco dice foto, prezzo e quante versioni', function () {
    $colonne = [];

    foreach (ProductModelResource::tableSchema() as $colonna) {
        $colonne[] = (string) $colonna->name;
    }

    return in_array('photo', $colonne, true)
        && in_array('price', $colonne, true)
        && in_array('versions', $colonne, true);
});

check('il prezzo si legge come intervallo solo quando serve', function () {
    $scheda = new class extends ProductModelResource {
        public static array $finti = [];

        public static function products(int $modelId): array
        {
            return static::$finti;
        }
    };

    $scheda::$finti = [['price' => '19.90'], ['price' => '19.90']];
    $uguali = $scheda::priceRange(1);

    $scheda::$finti = [['price' => '19.90'], ['price' => '24.50']];
    $diversi = $scheda::priceRange(1);

    $scheda::$finti = [];
    $nessuno = $scheda::priceRange(1);

    return $uguali === '19,90' && $diversi === 'da 19,90' && $nessuno === '';
});
```

Se `$colonna->name` non è il modo giusto di leggere la chiave di una
`TableColumn`, guarda come lo fa già `$campi()` in cima al file per i campi del
form e usa lo stesso.

- [ ] **Step 2: Lancialo e guardalo fallire**

Run: `php tests/ProductModelResourceTest.php`
Atteso: FAIL.

- [ ] **Step 3: Scrivi le letture e le colonne**

In `ProductModelResource`:

```php
    /** Il prezzo dell'articolo, come lo legge chi scorre l'elenco. */
    public static function priceRange(int $modelId): string
    {
        $prices = [];

        foreach (static::products($modelId) as $product) {
            $price = (float) ($product['price'] ?? 0);

            if ($price > 0) {
                $prices[] = $price;
            }
        }

        if ($prices === []) {
            return '';
        }

        $min = number_format(min($prices), 2, ',', '.');

        return min($prices) === max($prices) ? $min : 'da '.$min;
    }

    /** La prima foto pronta dell'articolo, per la miniatura dell'elenco. */
    public static function firstImage(int $modelId): string
    {
        foreach (static::rowsOf(ProductImage::class, ['product_model_id' => $modelId], 'position') as $row) {
            $url = ProductImages::url($row);

            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }
```

e in `tableSchema()`:

```php
    public static function tableSchema(): array
    {
        return [
            TableColumn::key('photo')
                ->image()
                ->size('little')
                ->formatter(static fn (array $row): string => static::firstImage((int) ($row['id'] ?? 0))),
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('sku')->text()->size('little'),
            TableColumn::key('price')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(
                    static::priceRange((int) ($row['id'] ?? 0))
                )),
            TableColumn::key('versions')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => (string) static::productCount((int) ($row['id'] ?? 0))),
            TableColumn::key('brand_id')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(
                    static::brandOptions()[(string) ($row['brand_id'] ?? '')] ?? ''
                )),
            TableColumn::key('visible')->visibleBadge()->size('little'),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }
```

e in `labelSchema()` aggiungi:

```php
            'photo' => 'Foto',
            'price' => 'Prezzo',
            'versions' => 'Versioni',
```

Se `ProductImages::url()` non esiste con quella firma, apri
`src/Support/Catalog/ProductImages.php` e usa il metodo che compone
l'indirizzo pubblico di una foto.

- [ ] **Step 4: Lancia i test**

Run: `php tests/run.php`
Atteso: verde.

- [ ] **Step 5: Guardalo nel browser**

`https://ecommerce.test/backend/app/gestionale/prodotti` con i dati di prova
caricati: la miniatura si vede, il prezzo della maglietta dice "da …", e la
colonna Versioni dice 1, 6 e 12 per i tre articoli.

**Se la miniatura non si vede**, controlla cosa si aspetta `TableColumn::image()`
— un percorso o un indirizzo completo — leggendo chi la disegna in
`packages/app/class/Backend/`. Se non regge un `formatter`, togli la colonna
`photo` e la sua etichetta, lascia le altre due e scrivilo nel commit: una
miniatura in meno non vale una modifica al core.

- [ ] **Step 6: Commit**

```bash
git add src/Resources/Catalog/ProductModelResource.php tests/ProductModelResourceTest.php
git commit -m "$(cat <<'MSG'
Make the product list answer which blue jumper this is

A thumbnail, the price (a range when the versions differ) and how many
versions there are, so the list is readable without opening anything.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 9: Creazione a quattro campi, e l'atterraggio sulla scheda

Due pezzi che vanno insieme: la creazione corta non serve a niente se poi si
torna all'elenco.

**Files:**
- Modify: `packages/app/class/Backend/Support/ResourcePagePresenter.php`
- Modify: `packages/app/class/Backend/Support/ResourcePageController.php`
- Test: `packages/app/tests/App/RedirectAfterStoreTest.php`
- Modify: `packages/gestionale/src/Resources/Catalog/ProductModelResource.php`
- Test: `packages/gestionale/tests/ProductModelResourceTest.php`

**Interfaces:**
- Produces: `ResourcePagePresenter::redirectUrl(string $action, int|string|null $id = null): string`
  con il caso `'edit'`; `ProductModelResource::createFields(): array` — le
  chiavi dei campi che la creazione mostra.

- [ ] **Step 1: Scrivi il test del core**

`packages/app/tests/App/RedirectAfterStoreTest.php`:

```php
<?php // tests/App/RedirectAfterStoreTest.php
declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../harness.php';

use Wonder\App\ResourceSchema\PageSchema;

check('di suo un salvataggio torna all\'elenco', function () {
    $schema = PageSchema::for(ProvaResource::class);

    return ($schema->get('redirects')['store'] ?? '') === 'list';
});

check('si può chiedere di atterrare sulla scheda', function () {
    $schema = PageSchema::for(ProvaResource::class)->redirect('store', 'edit');

    return ($schema->get('redirects')['store'] ?? '') === 'edit';
});

summary();
```

`ProvaResource` va definita in cima al file come una `Resource` minima: copia
la forma da un test già presente in `packages/app/tests/` che istanzia una
Resource finta. Se non ne esiste uno, definisci:

```php
final class ProvaResource extends \Wonder\App\Resource
{
    public static string $model = \Wonder\App\Models\Config\Society::class;
}
```

e aggiusta il Model con uno che esista davvero nel core.

Il test del redirect vero (`redirectUrl`) ha bisogno di un presenter costruito,
che nel core si costruisce con la classe della Resource: se costruirlo fuori da
una richiesta è complicato, **salta la seconda check** e prova `redirectUrl()`
dal browser allo Step 6 — ma tieni la prima, che è quella che protegge il
default.

- [ ] **Step 2: Lancialo e guardalo fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/app && php tests/App/RedirectAfterStoreTest.php`
Atteso: la seconda check FAIL se `redirect()` non accetta `'edit'`; se passa
già (il metodo scrive quello che gli dai), va bene lo stesso: il buco è nel
presenter, e lo chiude lo Step 3.

- [ ] **Step 3: Aggiungi il caso `edit` al presenter**

In `class/Backend/Support/ResourcePagePresenter.php`:

```php
    public function redirectUrl(string $action, int|string|null $id = null): string
    {
        $redirects = (array) $this->resourceClass::pageSchema()->get('redirects');
        $page = (string) ($redirects[$action] ?? 'list');

        return match ($page) {
            'create' => $this->createPageUrl(),
            // Dopo aver creato una riga si può atterrare sulla sua scheda,
            // invece che su un elenco dove bisogna ritrovarla. Senza id non
            // c'è scheda: si torna all'elenco.
            'edit' => $id === null || (int) $id <= 0 ? $this->listUrl() : $this->editUrl((int) $id),
            default => $this->listUrl(),
        };
    }
```

- [ ] **Step 4: Passa l'id al redirect**

In `class/Backend/Support/ResourcePageController.php`:

```php
    private function redirectToConfiguredPage(string $action, int|string|null $id = null): never
    {
        // Salvataggio o eliminazione andati a buon fine: il toast lo mostra
        // la pagina dove arriviamo dopo il redirect.
        FlashAlert::code(650);

        header('Location: '.$this->presenter->redirectUrl($action, $id));
        exit();
    }
```

e nel metodo `store()`, dove oggi c'è `$this->redirectToConfiguredPage('store');`
(riga ~129), passa la riga appena scritta. `$insertId` esiste solo nel ramo
`else`, quindi dichiaralo prima del `if` così:

```php
        $recordId = 0;

        if (!empty($result->success)) {
            if ($targetId > 0) {
                $recordId = $targetId;
                // …resto invariato…
            } else {
                $insertId = (int) ($result->insert_id ?? 0);
                $recordId = $insertId;
                // …resto invariato…
            }

            $this->resourceClass::exportSyncData();
            $this->redirectToConfiguredPage('store', $recordId);
        }
```

Lascia `update` e `delete` come sono: chiamano `redirectToConfiguredPage()` con
un argomento solo e il secondo ha il valore di riposo.

- [ ] **Step 5: Lancia i test del core**

Run: `cd /Users/andreamarinoni/Developer/packages/app && php tests/run.php`
Atteso: verde. Se il file `tests/run.php` non esiste nel core, lancia a mano i
file sotto `tests/App/` che hai toccato.

- [ ] **Step 6: Committa nel core**

```bash
cd /Users/andreamarinoni/Developer/packages/app
git add -f tests/App/RedirectAfterStoreTest.php
git add class/Backend/Support/ResourcePagePresenter.php class/Backend/Support/ResourcePageController.php
git commit -m "$(cat <<'MSG'
Let a resource land on the card it just created

After saving, a resource could only go back to the list or to an empty
form. A short create form is pointless if the person then has to find the
row again: 'edit' now redirects to the new record.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

**Non rilasciare.** Il commit si somma a `7d6df162` e `81e1323f`, in attesa che
l'utente pubblichi. Fino ad allora il sito di prova torna all'elenco dopo la
creazione, che è fastidioso e basta.

- [ ] **Step 7: Accorcia la creazione**

In `packages/gestionale/src/Resources/Catalog/ProductModelResource.php`:

```php
    /** I quattro campi della creazione: il resto si compila nella scheda. */
    public static function createFields(): array
    {
        return ['name', 'main_category', 'product_price', 'sku'];
    }
```

e in `formLayoutSchema()`, come primissima cosa:

```php
        if (static::currentId() === null) {
            return (new Form)->components([
                (new Container)->components([
                    (new Card)->components([
                        SectionTitle::make('Nuovo prodotto')
                            ->tooltip('Bastano queste quattro cose. Foto, descrizioni, taglie e colori si aggiungono subito dopo, nella scheda.')
                            ->columnSpan(12),
                        static::getInput('name')->columnSpan(6),
                        static::getInput('main_category')->columnSpan(6),
                        static::getInput('product_price')->columnSpan(6),
                        static::getInput('sku')->columnSpan(6),
                    ])->columns(12)->columnSpan(12),
                ])->columns(12)->columnSpan(12),
            ]);
        }
```

e in `pageSchema()`, aggiungi al concatenamento:

```php
            ->redirect('store', 'edit')
```

- [ ] **Step 8: Scrivi il test della creazione**

In `tests/ProductModelResourceTest.php`:

```php
check('la creazione chiede quattro cose e poi porta sulla scheda', function () {
    $schema = ProductModelResource::pageSchema();

    return ProductModelResource::createFields() === ['name', 'main_category', 'product_price', 'sku']
        && ($schema->get('redirects')['store'] ?? '') === 'edit';
});
```

- [ ] **Step 9: Lancia i test**

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/run.php`
Atteso: verde.

- [ ] **Step 10: Provalo nel browser**

`https://ecommerce.test/backend/app/gestionale/prodotti` → "Aggiungi prodotto":
si vedono **quattro** campi e nient'altro. Compila nome e prezzo e salva.

Con il core installato ancora alla 2.2.12 **tornerai all'elenco**: è atteso.
Verifica allora che l'articolo ci sia, aprilo, e controlla che prezzo e SKU
siano finiti sulla sua versione unica.

- [ ] **Step 11: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale
git add src/Resources/Catalog/ProductModelResource.php tests/ProductModelResourceTest.php
git commit -m "$(cat <<'MSG'
Ask four things to create a product

Nothing to scroll before deciding what the article even is: name,
category, price, code. Everything else belongs on the card you land on.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 10: Le guide, i dati di prova e la chiusura

**Files:**
- Rename: `docs/user/catalogo-modelli.md` → `docs/user/catalogo-prodotti.md`
- Modify: `docs/user/catalogo-attributi.md`, `docs/user/catalogo-immagini.md`,
  `docs/user/SUMMARY.md`, `docs/dev/concetti/catalogo.md`
- Modify: `src/Resources/Catalog/ProductModelResource.php` (`$docsPage`)
- Modify: `src/Seeding/CatalogDemo.php`
- Modify: `TODO.md`

- [ ] **Step 1: Rinomina e riscrivi la guida**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale
git mv docs/user/catalogo-modelli.md docs/user/catalogo-prodotti.md
```

Riscrivi il file. Deve sparire la tabella "Modello / Variante / Prodotto" che
oggi lo apre: è esattamente ciò che vogliamo smettere di far leggere. La nuova
struttura:

- titolo `# I prodotti`, icona `box`;
- *Un articolo semplice, in un minuto*: Catalogo → Prodotti → Aggiungi
  prodotto, quattro campi, salva, e sei nella scheda;
- *Quando servono colori e taglie*: il riquadro "Si vende in più versioni?",
  spunta e salva, nascono le righe con il loro nome; togliere una spunta non
  cancella; per eliminare si elimina la riga; una sola opzione può avere pagina
  e foto proprie;
- *Il nome di una versione*: nasce da sé ("Blu / M") e lo puoi correggere;
- *SKU ed EAN*: come nella guida di oggi, invariato;
- *I campi rari di una versione*: il pulsante "Dettagli delle versioni";
- *Eliminare un articolo*: come oggi, ma senza la parola "modello".

- [ ] **Step 2: Aggiorna gli altri documenti**

- `docs/user/SUMMARY.md`: la voce diventa `* [I prodotti](catalogo-prodotti.md)`;
- `docs/user/catalogo-attributi.md`: la sezione del livello diventa la domanda
  "Come si usa" con le tre risposte e i loro esempi;
- `docs/user/catalogo-immagini.md`: sostituisci "variante" con "versione" dove
  parla della colonna "Vale per";
- `docs/dev/concetti/catalogo.md`: **tiene** modello/variante/prodotto, perché
  chi scrive codice deve sapere che sotto ci sono tre tabelle; aggiungi un
  paragrafo che spiega che il pannello non li nomina e perché, più la colonna
  `name`, `Generator` e gli assi di `Combinations`;
- in `ProductModelResource`: `public static string $docsPage = 'catalogo/catalogo-prodotti';`.

- [ ] **Step 3: Lancia il test delle guide**

Run: `php tests/DocsPagesTest.php`
Atteso: PASS. Il test compone i percorsi GitBook dal SUMMARY: se fallisce,
il nome del file e la voce del SUMMARY non combaciano.

- [ ] **Step 4: Aggiorna i dati di prova**

In `src/Seeding/CatalogDemo.php`, i tre articoli restano quelli. Due cose:

- i prodotti creati devono avere il `name` — se il seeder chiama
  `Generator::run()` non serve fare niente, se crea le righe a mano aggiungi
  `'name' => VersionName::from($labels, $sku),`;
- il ciclo `foreach (Attributes::LEVELS as $level => $ignored)` continua a
  funzionare (le chiavi non sono cambiate): controlla solo che non stampi da
  nessuna parte l'etichetta vecchia.

Poi rigenera e guarda:

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge gestionale:demo --fresh
```

- [ ] **Step 5: Il giro completo nel browser**

Con i dati di prova caricati, su `https://ecommerce.test/backend/`:

1. l'elenco Prodotti mostra tre articoli con miniatura, prezzo e versioni;
2. il cappello ha quattro riquadri aperti;
3. la maglietta ha sei righe con i nomi giusti;
4. la felpa ha dodici righe;
5. il pulsante "Dettagli delle versioni" apre l'elenco filtrato;
6. spuntare due opzioni con pagina propria dà l'avviso, non un 500;
7. eliminare un articolo porta via versioni e foto.

Poi **rimetti pulito il sito**:

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge gestionale:demo --fresh --clear 2>/dev/null || php forge gestionale:demo --clear
```

(usa l'opzione che il comando ha davvero: leggi `src/Console/DemoCommand.php`.)

- [ ] **Step 6: Spunta il TODO**

In `TODO.md`, sotto **G2a-bis**, spunta la voce del piano e scrivi in una riga
cosa è stato fatto e cosa resta all'utente (il rilascio del core con le tre
aggiunte: `7d6df162`, `81e1323f` e quella di oggi).

- [ ] **Step 7: Lancia tutto un'ultima volta**

Run: `php tests/run.php`
Atteso: verde, integrazione compresa.

- [ ] **Step 8: Commit**

```bash
git add docs TODO.md src/Resources/Catalog/ProductModelResource.php src/Seeding/CatalogDemo.php
git commit -m "$(cat <<'MSG'
Rewrite the guides without the three words

The shopkeeper's guide opened with a Model / Variant / Product table,
which is the very thing we stopped making people read. The developer
guide keeps it, because the three tables are still down there.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
MSG
)"
```

---

## Alla fine

Unisci il ramo in `main` con un avanzamento diretto, spingi, e controlla che la
GitHub Action sia verde. Poi riporta all'utente:

- cosa cambia per chi usa il pannello;
- che il core ha **tre** commit da rilasciare (`7d6df162` decimali,
  `81e1323f` immagini, quello di oggi sul redirect) e che finché non li
  pubblica la creazione riporta all'elenco;
- se l'`Accordion` nel form ha funzionato o se i due riquadri sono rimasti
  aperti in fondo.
