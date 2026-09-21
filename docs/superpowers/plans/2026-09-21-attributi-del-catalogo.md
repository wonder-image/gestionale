# Piano 2 di 4 (G2a) — Attributi

> **Per chi esegue:** SKILL RICHIESTA: usare superpowers:subagent-driven-development
> (consigliata) o superpowers:executing-plans, un task alla volta. I passi usano le
> caselle `- [ ]` per il tracciamento.

**Obiettivo:** gli attributi del catalogo — colore, taglia, materiale, peso — con i
loro valori, il livello a cui vivono e la pagina per gestirli, pronti a essere
appesi a modelli, varianti e prodotti nel piano 3.

**Architettura:** due Model (`Attribute`, `AttributeValue`), una classe pura
(`Support\Catalog\Attributes`) che sa livelli, tipi e come si legge un valore, e una
Resource con i valori dentro la scheda come repeater. Le tre tabelle di collegamento
(`product_model_attributes`, `product_variant_attributes`, `product_attributes`)
**non** nascono qui: puntano a tabelle che il piano 3 deve ancora creare.

**Stack:** PHP 8.2, `wonder-image/app` `^2.2.12`, MySQL, test come script PHP con
l'harness del modulo.

**Spec:** `docs/superpowers/specs/2026-09-21-catalogo-design.md` (§2, §4, §6, §8,
G2a.4, G2a.7).

## Vincoli globali

- **Lingua:** testi, commenti e messaggi in italiano; nomi in inglese nel codice.
- **Tabelle:** prefisso `gst_`, `syncSchema(): null` (il catalogo non si sincronizza,
  G2a.1).
- **Codici:** `code` con `uniqueCode(Codes::ATTRIBUTE)`; `slug` generato dal nome alla
  creazione e poi fisso, come nelle tassonomie.
- **Niente eliminazione quando si è usati** (G2a.7): `assertDeletable()` spiega e
  propone di nascondere.
- **Menu:** sezione `catalogo` del backend, per `admin` e `administrator`.
- **Test d'integrazione:** database `ecommerce_site` dentro `Transaction::run()` che
  annulla sempre.
- **Ramo:** `feature/attributi` in `packages/gestionale`.
- **Commit:** uno per task, messaggio in inglese, con la riga
  `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.

### Due nomi di colonna diversi dalla spec

La spec scrive `key` e `group`. Sono **due parole riservate di MySQL**, e il
costruttore di query del core mette le virgolette sui nomi solo in `INSERT`,
`UPDATE` e `WHERE`: `ORDER BY group` arriverebbe al database così com'è e lo
farebbe sbagliare. Quindi:

| Spec | Qui | Perché |
|---|---|---|
| `key` | `slug` | parola riservata, ed è lo stesso nome macchina di marchi, categorie e tag: lo useranno i filtri della vetrina (E1) |
| `group` | `group_name` | parola riservata |

La spec va aggiornata nello stesso commit del Task 1.

## Struttura dei file

| File | Responsabilità |
|---|---|
| `src/Support/Codes.php` | aggiunge il prefisso `att_` |
| `src/Models/Catalog/Attribute.php` | tabella `gst_attributes` |
| `src/Models/Catalog/AttributeValue.php` | tabella `gst_attribute_values` |
| `src/Support/Catalog/Attributes.php` | livelli, tipi, normalizzazione e lettura di un valore |
| `src/Resources/GestionaleResource.php` | `currentId()` e `rowsOf()` condivisi |
| `src/Resources/Catalog/AttributeResource.php` | pagina "Attributi" con i valori nella scheda |
| `src/Resources/Catalog/CategoryResource.php` | usa gli helper della base invece dei suoi |
| `src/Seeding/CatalogDemo.php` | due attributi di prova con i loro valori |
| `docs/dev/concetti/catalogo.md` | gli attributi per lo sviluppatore |
| `docs/user/catalogo-attributi.md` | gli attributi per il commerciante |

---

### Task 1: I due Model

**File:**
- Modificare: `src/Support/Codes.php`
- Creare: `src/Models/Catalog/Attribute.php`, `src/Models/Catalog/AttributeValue.php`
- Test: `tests/CatalogAttributeModelsTest.php`
- Documenti: `docs/superpowers/specs/2026-09-21-catalogo-design.md` (§2: `key` → `slug`,
  `group` → `group_name`, con la nota sulle parole riservate)

**Colonne**

| Tabella | Colonne |
|---|---|
| `gst_attributes` | `code` (`att_`), `slug` (unico, 100), `name`, `type` enum `select`/`color`/`text`/`number`, `level` enum `model`/`variant`/`product`, `unit` (20), `group_name`, `is_filterable` enum, `is_visible` enum, `position` INT |
| `gst_attribute_values` | `attribute_id` INT (chiave verso `gst_attributes`), `label`, `color` (20), `image` JSON, `position` INT |

**Interfacce:**
- Produce: `Attribute::$table = 'gst_attributes'`, `AttributeValue::$table = 'gst_attribute_values'`,
  `Codes::ATTRIBUTE = 'att_'`.
- Consuma: `Wonder\Plugin\Gestionale\Support\Codes` (G1).

- [ ] **Passo 1: scrivere il test** — `tests/CatalogAttributeModelsTest.php`:

```php
<?php
/** php tests/CatalogAttributeModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
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

check('le due tabelle hanno il prefisso del gestionale', fn () =>
    Attribute::$table === 'gst_attributes'
    && AttributeValue::$table === 'gst_attribute_values'
);

check('gli attributi non viaggiano con il deploy', fn () =>
    Attribute::syncSchema() === null && AttributeValue::syncSchema() === null
);

check('il codice dell\'attributo ha il suo prefisso', function () use ($campo) {
    return ($campo(Attribute::class, 'code')?->getSchema('unique_code')['prefix'] ?? null)
        === Codes::ATTRIBUTE;
});

check('codice e nome macchina non si cambiano dopo la creazione', function () use ($campo) {
    foreach (['code', 'slug'] as $key) {
        if (empty($campo(Attribute::class, $key)?->getSchema('immutable_on_update'))) {
            return false;
        }
    }

    return true;
});

check('nessuna colonna usa una parola riservata di MySQL', function () use ($colonne) {
    // `key` e `group` romperebbero un ORDER BY: il costruttore di query mette
    // le virgolette solo su INSERT, UPDATE e WHERE.
    $riservate = ['key', 'group', 'order', 'index', 'default'];

    foreach ([Attribute::class, AttributeValue::class] as $model) {
        foreach (array_keys($colonne($model)) as $nome) {
            if (in_array(strtolower((string) $nome), $riservate, true)) {
                return false;
            }
        }
    }

    return true;
});

check('il nome macchina è unico', function () use ($colonne) {
    return !empty($colonne(Attribute::class)['slug']->toArray()['unique'] ?? null);
});

check('i valori appartengono a un attributo', function () use ($colonne) {
    $foreign = $colonne(AttributeValue::class)['attribute_id']->toArray()['foreign'] ?? null;

    return is_array($foreign)
        ? ($foreign['table'] ?? null) === Attribute::$table
        : $foreign === Attribute::$table;
});

check('tipo e livello sono elenchi chiusi', function () use ($colonne) {
    $tipo = $colonne(Attribute::class)['type']->toArray();
    $livello = $colonne(Attribute::class)['level']->toArray();

    return ($tipo['enum'] ?? []) === ['select', 'color', 'text', 'number']
        && ($livello['enum'] ?? []) === ['model', 'variant', 'product'];
});

summary();
```

- [ ] **Passo 2: eseguirlo** — `php tests/CatalogAttributeModelsTest.php`.
  Atteso: FALLISCE con "Class ... not found".

- [ ] **Passo 3: il prefisso** — in `src/Support/Codes.php`, nel blocco `// Catalogo`,
  sotto `TAG`:

```php
    public const ATTRIBUTE = 'att_';
```

- [ ] **Passo 4: scrivere `src/Models/Catalog/Attribute.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Attributo del catalogo: colore, taglia, materiale, peso di spedizione.
 *
 * `level` dice dove vive l'attributo e decide tutto il resto: `model` descrive
 * l'articolo, `variant` distingue le varianti, `product` distingue i prodotti
 * dentro una variante. `type` dice come si scrive il valore: `select` e `color`
 * pescano da `gst_attribute_values`, `text` e `number` scrivono sul
 * collegamento.
 *
 * Il nome macchina sta in `slug` e non in `key`, e il gruppo in `group_name` e
 * non in `group`: sono parole riservate di MySQL e il costruttore di query del
 * core non mette le virgolette nell'ORDER BY.
 *
 * Il catalogo non si sincronizza: è lavoro del commerciante.
 */
final class Attribute extends Model
{
    public static string $table = 'gst_attributes';
    public static string $folder = 'gestionale/attributes';
    public static string $icon = 'bi bi-sliders';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('slug')->length(100)->unique(),
            Column::key('name'),
            Column::key('type')->enum(['select', 'color', 'text', 'number'])->default('select'),
            Column::key('level')->enum(['model', 'variant', 'product'])->default('product'),
            Column::key('unit')->length(20),
            Column::key('group_name'),
            Column::key('is_filterable')->enum(['true', 'false'])->default('true'),
            Column::key('is_visible')->enum(['true', 'false'])->default('true'),
            Column::key('position')->int(),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::ATTRIBUTE),
            Field::key('slug')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('type')->text()->sanitize(false),
            Field::key('level')->text()->sanitize(false),
            Field::key('unit')->text(),
            Field::key('group_name')->text(),
            Field::key('is_filterable')->text()->sanitize(false),
            Field::key('is_visible')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
```

- [ ] **Passo 5: scrivere `src/Models/Catalog/AttributeValue.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Valore di un attributo a elenco: "Blu", "M", "Cotone".
 *
 * Esiste solo per i tipi `select` e `color`; `text` e `number` scrivono il
 * valore sul collegamento del prodotto. `color` tiene il codice esadecimale
 * per il pallino in vetrina, `image` la fantasia quando il colore non basta.
 */
final class AttributeValue extends Model
{
    public static string $table = 'gst_attribute_values';
    public static string $folder = 'gestionale/attributes';
    public static string $icon = 'bi bi-palette';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('attribute_id')->int()->null(false)->foreign(Attribute::$table),
            Column::key('label'),
            Column::key('color')->length(20),
            Column::key('image')->json(),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_attribute' => ['index' => 'attribute_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('attribute_id')->number()->decimals(0),
            Field::key('label')->text()->sanitizeFirst(),
            Field::key('color')->text(),
            Field::key('image')
                ->image()
                ->extensions(['png', 'jpg', 'jpeg', 'webp'])
                ->maxSize(2)
                ->maxFile(1)
                ->dir('/catalogo/attributi/')
                ->name('{label}'),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
```

- [ ] **Passo 6: rieseguire il test** — `php tests/CatalogAttributeModelsTest.php`.
  Atteso: 8 test, 0 falliti. Se `foreign` o `enum` hanno una forma diversa da quella
  ipotizzata, **correggere il test leggendo `TableSchema`**, non il Model.

- [ ] **Passo 7: aggiornare la spec** — in
  `docs/superpowers/specs/2026-09-21-catalogo-design.md`, tabella §2, riga
  `attributes`: `key` diventa `slug`, `group` diventa `group_name`; sotto la tabella
  aggiungere:

```markdown
> `key` e `group` sono parole riservate di MySQL e il costruttore di query del
> core mette le virgolette ai nomi solo in `INSERT`, `UPDATE` e `WHERE`: le due
> colonne si chiamano `slug` (lo stesso nome macchina delle altre tabelle del
> catalogo) e `group_name`.
```

- [ ] **Passo 8: creare le tabelle** — dal sito di prova:

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update
```

Atteso: `gst_attributes` e `gst_attribute_values` create, nessun errore.

- [ ] **Passo 9: commit**

```bash
git add src/Support/Codes.php src/Models/Catalog/Attribute.php src/Models/Catalog/AttributeValue.php tests/CatalogAttributeModelsTest.php docs/superpowers/specs/2026-09-21-catalogo-design.md
git commit -m "Add attributes and their values"
```

---

### Task 2: Le regole degli attributi

**File:**
- Creare: `src/Support/Catalog/Attributes.php`
- Test: `tests/AttributesTest.php`

**Interfacce:**
- Consuma: niente (classe pura, nessun database).
- Produce, per il piano 3 e per la Resource:
  - `Attributes::levels(): array<string,string>`
  - `Attributes::types(): array<string,string>`
  - `Attributes::usesValues(string $type): bool`
  - `Attributes::byLevel(array $attributes, string $level): list<array>`
  - `Attributes::grouped(array $attributes): array<string, list<array>>`
  - `Attributes::assignment(array $attribute, mixed $value): array{attribute_value_id: ?int, value_text: string, value_number: ?float}`
  - `Attributes::format(array $attribute, array $link, array $values = []): string`

- [ ] **Passo 1: scrivere il test** — `tests/AttributesTest.php`:

```php
<?php
/** php tests/AttributesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;

$colore = ['id' => 1, 'name' => 'Colore', 'type' => 'color', 'level' => 'variant', 'unit' => '', 'group_name' => ''];
$taglia = ['id' => 2, 'name' => 'Taglia', 'type' => 'select', 'level' => 'product', 'unit' => '', 'group_name' => 'Misure'];
$peso = ['id' => 3, 'name' => 'Peso', 'type' => 'number', 'level' => 'model', 'unit' => 'g', 'group_name' => 'Misure'];
$materiale = ['id' => 4, 'name' => 'Materiale', 'type' => 'text', 'level' => 'model', 'unit' => '', 'group_name' => ''];

check('solo elenco e colore hanno dei valori', fn () =>
    Attributes::usesValues('select')
    && Attributes::usesValues('color')
    && !Attributes::usesValues('text')
    && !Attributes::usesValues('number')
);

check('i livelli e i tipi hanno un nome da leggere', fn () =>
    Attributes::levels()['variant'] === 'Variante'
    && Attributes::types()['select'] === 'Elenco'
);

check('ogni livello vede solo i suoi attributi', function () use ($colore, $taglia, $peso, $materiale) {
    $modello = Attributes::byLevel([$colore, $taglia, $peso, $materiale], 'model');

    return count($modello) === 2
        && array_column($modello, 'name') === ['Peso', 'Materiale'];
});

check('senza gruppo si finisce in Generale', function () use ($colore, $taglia) {
    $gruppi = Attributes::grouped([$colore, $taglia]);

    return array_keys($gruppi) === ['Generale', 'Misure']
        && count($gruppi['Generale']) === 1;
});

check('un elenco scrive l\'id del valore e basta', function () use ($taglia) {
    $riga = Attributes::assignment($taglia, '7');

    return $riga['attribute_value_id'] === 7
        && $riga['value_text'] === ''
        && $riga['value_number'] === null;
});

check('un numero accetta la virgola', function () use ($peso) {
    $riga = Attributes::assignment($peso, '1,5');

    return $riga['value_number'] === 1.5
        && $riga['attribute_value_id'] === null
        && $riga['value_text'] === '';
});

check('un testo resta un testo', function () use ($materiale) {
    return Attributes::assignment($materiale, ' Cotone ')['value_text'] === 'Cotone';
});

check('un valore vuoto non scrive niente', function () use ($taglia, $peso) {
    $vuotoElenco = Attributes::assignment($taglia, '');
    $vuotoNumero = Attributes::assignment($peso, '');

    return $vuotoElenco['attribute_value_id'] === null
        && $vuotoNumero['value_number'] === null;
});

check('un elenco si legge con l\'etichetta del valore', function () use ($taglia) {
    $valori = [7 => ['id' => 7, 'label' => 'M']];

    return Attributes::format($taglia, ['attribute_value_id' => 7], $valori) === 'M';
});

check('un numero si legge con la sua unità', function () use ($peso) {
    return Attributes::format($peso, ['value_number' => 1.5]) === '1,5 g';
});

check('un valore che non c\'è più non inventa niente', function () use ($taglia) {
    return Attributes::format($taglia, ['attribute_value_id' => 999], []) === '';
});

summary();
```

- [ ] **Passo 2: eseguirlo** — `php tests/AttributesTest.php`.
  Atteso: FALLISCE con "Class ... Attributes not found".

- [ ] **Passo 3: scrivere `src/Support/Catalog/Attributes.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

/**
 * Livelli, tipi e valori degli attributi, senza database.
 *
 * Chi assegna un attributo passa di qui: il tipo decide quale colonna del
 * collegamento si riempie, e le altre restano vuote. Così il pannello, la
 * vetrina e l'import scrivono le righe nello stesso modo, e la regola si prova
 * senza accendere mezzo sito.
 */
final class Attributes
{
    /** Dove vive un attributo. */
    public const LEVELS = [
        'model' => 'Modello',
        'variant' => 'Variante',
        'product' => 'Prodotto',
    ];

    /** Come si scrive il suo valore. */
    public const TYPES = [
        'select' => 'Elenco',
        'color' => 'Colore',
        'text' => 'Testo',
        'number' => 'Numero',
    ];

    /** Gruppo di chi non ne dichiara uno. */
    public const DEFAULT_GROUP = 'Generale';

    /** @return array<string, string> */
    public static function levels(): array
    {
        return self::LEVELS;
    }

    /** @return array<string, string> */
    public static function types(): array
    {
        return self::TYPES;
    }

    /** I tipi che pescano da `gst_attribute_values`. */
    public static function usesValues(string $type): bool
    {
        return $type === 'select' || $type === 'color';
    }

    /**
     * Gli attributi di un livello, nell'ordine in cui arrivano.
     *
     * @param list<array<string, mixed>> $attributes
     * @return list<array<string, mixed>>
     */
    public static function byLevel(array $attributes, string $level): array
    {
        return array_values(array_filter(
            $attributes,
            static fn (array $attribute): bool => (string) ($attribute['level'] ?? '') === $level
        ));
    }

    /**
     * Gli attributi divisi per gruppo, per i riquadri della scheda.
     *
     * @param list<array<string, mixed>> $attributes
     * @return array<string, list<array<string, mixed>>>
     */
    public static function grouped(array $attributes): array
    {
        $groups = [];

        foreach ($attributes as $attribute) {
            $group = trim((string) ($attribute['group_name'] ?? ''));
            $groups[$group === '' ? self::DEFAULT_GROUP : $group][] = $attribute;
        }

        return $groups;
    }

    /**
     * La riga di collegamento per un valore scelto: una sola colonna piena.
     *
     * @param array<string, mixed> $attribute
     * @return array{attribute_value_id: int|null, value_text: string, value_number: float|null}
     */
    public static function assignment(array $attribute, mixed $value): array
    {
        $empty = ['attribute_value_id' => null, 'value_text' => '', 'value_number' => null];
        $type = (string) ($attribute['type'] ?? '');
        $raw = is_string($value) ? trim($value) : $value;

        if ($raw === '' || $raw === null) {
            return $empty;
        }

        if (self::usesValues($type)) {
            $id = (int) $raw;

            return $id > 0 ? ['attribute_value_id' => $id] + $empty : $empty;
        }

        if ($type === 'number') {
            $number = str_replace(',', '.', (string) $raw);

            return is_numeric($number)
                ? ['value_number' => (float) $number] + $empty
                : $empty;
        }

        return ['value_text' => (string) $raw] + $empty;
    }

    /**
     * Il valore da far leggere, con l'unità quando c'è.
     *
     * @param array<string, mixed> $attribute
     * @param array<string, mixed> $link riga di collegamento
     * @param array<int, array<string, mixed>> $values valori dell'attributo, per id
     */
    public static function format(array $attribute, array $link, array $values = []): string
    {
        $type = (string) ($attribute['type'] ?? '');
        $unit = trim((string) ($attribute['unit'] ?? ''));

        if (self::usesValues($type)) {
            $id = (int) ($link['attribute_value_id'] ?? 0);

            return (string) ($values[$id]['label'] ?? '');
        }

        if ($type === 'number') {
            $number = $link['value_number'] ?? null;

            if ($number === null || $number === '') {
                return '';
            }

            // Numero all'italiana, senza zeri inutili in coda.
            $text = rtrim(rtrim(number_format((float) $number, 3, ',', ''), '0'), ',');

            return $unit === '' ? $text : $text.' '.$unit;
        }

        $text = trim((string) ($link['value_text'] ?? ''));

        if ($text === '') {
            return '';
        }

        return $unit === '' ? $text : $text.' '.$unit;
    }
}
```

- [ ] **Passo 4: rieseguire** — `php tests/AttributesTest.php`. Atteso: 11 test, 0 falliti.

- [ ] **Passo 5: commit**

```bash
git add src/Support/Catalog/Attributes.php tests/AttributesTest.php
git commit -m "Teach the module how an attribute value is written and read"
```

---

### Task 3: La pagina "Attributi"

**File:**
- Modificare: `src/Resources/GestionaleResource.php` (helper condivisi),
  `src/Resources/Catalog/CategoryResource.php` (usa gli helper),
  `tests/ConventionsTest.php` (elenco delle pagine sempre attive)
- Creare: `src/Resources/Catalog/AttributeResource.php`
- Test: `tests/CatalogResourcesTest.php` (si aggiunge in coda, prima di `summary()`)

**Interfacce:**
- Consuma: `Attributes::levels()/types()/usesValues()`, `Slug::make()`,
  `Positions::next()`, `UserError::make()`.
- Produce: pagina `app/gestionale/attributi`, e
  `AttributeResource::usesValues(): bool` che il layout usa per mostrare o no il
  riquadro dei valori.

**Come si comporta la scheda**

- Nome, livello, tipo, gruppo, unità, filtro e stato in un riquadro.
- I valori sono un repeater collegato a `gst_attribute_values`, e il riquadro
  **compare solo quando il tipo li usa**: su un attributo "Testo" non c'è niente da
  elencare. In creazione il tipo non è ancora scelto, quindi il riquadro non c'è: si
  salva e si aggiungono i valori subito dopo.
- Il tipo si può cambiare, **ma non mentre ci sono dei valori**: cambiarlo in "Testo"
  li butterebbe via in silenzio. Il salvataggio si ferma e lo dice.

- [ ] **Passo 1: scrivere i test** — in coda a `tests/CatalogResourcesTest.php`, prima
  di `summary()`, aggiungendo gli `use` in testa al file:

```php
check('la pagina degli attributi sta nel catalogo', fn () =>
    AttributeResource::path() === 'app/gestionale/attributi'
    && AttributeResource::navigationSchema()->toArray()['section'] === 'catalogo'
);

check('il riquadro dei valori c\'è solo per elenco e colore', fn () =>
    AttributeResource::usesValues(['type' => 'select'])
    && AttributeResource::usesValues(['type' => 'color'])
    && !AttributeResource::usesValues(['type' => 'text'])
    && !AttributeResource::usesValues([])
);

check('i valori si salvano nella loro tabella', function () {
    foreach (AttributeResource::formSchema() as $field) {
        if ((string) $field->key !== 'values') {
            continue;
        }

        $relation = $field->get('relation');

        return $relation !== null
            && $relation->table === AttributeValue::$table
            && $relation->parentKey === 'attribute_id'
            && $relation->positionKey === 'position';
    }

    return false;
});

check('il nome macchina nasce dal nome e non si tocca più', function () {
    $nuovo = AttributeResource::mutateRequestValues(['name' => 'Colore'], 'store');
    $modifica = AttributeResource::mutateRequestValues(['name' => 'Colore', 'slug' => 'altro'], 'update', 'backend', ['id' => 1]);

    return ($nuovo['slug'] ?? '') !== '' && !isset($modifica['slug']);
});

check('il tipo non cambia mentre ci sono dei valori', function () {
    // `valueCount()` è sovrascritto nel test: qui conta la regola, non il database.
    $resource = new class extends AttributeResource {
        public static function valueCount(int $id): int { return 3; }
    };

    try {
        $resource::mutateRequestValues(
            ['name' => 'Colore', 'type' => 'text'],
            'update',
            'backend',
            ['id' => 1, 'type' => 'select']
        );
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'valori');
    }

    return false;
});

check('senza valori il tipo si cambia', function () {
    $resource = new class extends AttributeResource {
        public static function valueCount(int $id): int { return 0; }
    };

    $valori = $resource::mutateRequestValues(
        ['name' => 'Colore', 'type' => 'text'],
        'update',
        'backend',
        ['id' => 1, 'type' => 'select']
    );

    return ($valori['type'] ?? '') === 'text';
});
```

> Nota: `AttributeResource` è `final` nelle altre Resource del modulo, ma qui i test
> la estendono con una classe anonima: **non dichiararla `final`** e scriverlo nel
> docblock, come si è fatto nel core con `SocietyLocationResource`.

- [ ] **Passo 2: eseguirlo** — `php tests/CatalogResourcesTest.php`.
  Atteso: FALLISCE con "Class ... AttributeResource not found".

- [ ] **Passo 3: gli helper condivisi** — in `src/Resources/GestionaleResource.php`,
  aggiungere (con `use Throwable;` in testa):

```php
    /**
     * L'id della riga aperta, quando la pagina ne ha una.
     *
     * Il form si dichiara con metodi statici, che non ricevono la riga: per
     * sapere cosa si sta modificando resta l'indirizzo. L'ultimo pezzo del
     * percorso della Resource identifica la rotta (`.../attributi/12/edit/`).
     */
    protected static function currentId(): ?int
    {
        $id = (int) ($_GET['id'] ?? 0);

        if ($id > 0) {
            return $id;
        }

        $segment = basename(static::path());
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        if (preg_match('#/'.preg_quote($segment, '#').'/(\d+)/#', $uri, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Le righe vive di un Model, sempre come lista.
     *
     * Senza database (test degli schemi, convenzioni) torna vuoto invece di far
     * esplodere il form: lì servono le voci di un select, non i dati.
     *
     * @param class-string<\Wonder\App\Model> $modelClass
     * @return list<array<string, mixed>>
     */
    protected static function rowsOf(
        string $modelClass,
        array $where = [],
        ?string $order = null,
        string $direction = 'ASC'
    ): array {
        try {
            $rows = $modelClass::find(array_merge(['deleted' => 'false'], $where), null, $order, $order === null ? null : $direction);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
```

- [ ] **Passo 4: `CategoryResource` usa gli helper** — togliere i suoi `rows()` e
  `currentId()` privati e sostituire le chiamate con
  `static::rowsOf(Category::class, [], 'position')` e `static::currentId()`; togliere
  l'`use Throwable;` rimasto senza usi.

- [ ] **Passo 5: rieseguire i test delle tassonomie** —
  `php tests/CatalogResourcesTest.php` e `php tests/CategoryTreeTest.php`: le prove
  delle categorie restano verdi (le nuove falliscono ancora).

- [ ] **Passo 6: scrivere `src/Resources/Catalog/AttributeResource.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use RuntimeException;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Positions;

/**
 * "Attributi": cosa distingue un articolo, una variante o un prodotto.
 *
 * Il livello si sceglie qui e decide dove finirà il valore nel piano 3. I
 * valori di un attributo a elenco si scrivono nella scheda, come righe: il
 * riquadro compare solo quando il tipo li usa.
 *
 * Non è `final`: i test la estendono con una classe anonima per provare la
 * regola sul cambio di tipo senza database.
 */
class AttributeResource extends GestionaleResource
{
    public static string $model = Attribute::class;
    public static string $orderColumn = 'position';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'catalogo/attributi';

    public static function path(): string
    {
        return 'app/gestionale/attributi';
    }

    public static function icon(): string
    {
        return 'bi-sliders';
    }

    public static function titleLabel(): string
    {
        return 'Attributi';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'attributo',
            'plural_label' => 'attributi',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'gli',
            'full' => 'visibile',
            'empty' => 'nascosto',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'name' => 'Nome',
            'slug' => 'Nome macchina',
            'level' => 'Livello',
            'type' => 'Tipo',
            'group_name' => 'Gruppo',
            'unit' => 'Unità di misura',
            'is_filterable' => 'Filtro',
            'is_visible' => 'Stato',
            'values' => 'Valori',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('level')
                ->select(Attributes::levels())
                ->value('product')
                ->label('Livello')
                ->required(),
            FormField::key('type')
                ->select(Attributes::types())
                ->value('select')
                ->label('Tipo')
                ->required(),
            FormField::key('group_name')->text()->label('Gruppo'),
            FormField::key('unit')->text()->label('Unità di misura'),
            FormField::key('is_filterable')
                ->select(['true' => 'Sì', 'false' => 'No'])
                ->value('true')
                ->label('Filtro')
                ->required(),
            FormField::key('is_visible')
                ->select(['true' => 'Visibile', 'false' => 'Nascosto'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('values')
                ->repeater([
                    RepeaterColumn::key('id')->hidden(),
                    RepeaterColumn::key('label')->text()->label('Valore')->columnSpan(5),
                    RepeaterColumn::key('color')->color()->label('Colore')->columnSpan(3),
                    RepeaterColumn::key('image')->fileDragDrop('image')->label('Fantasia')->columnSpan(3),
                ])
                ->relation(
                    RepeaterRelation::make(AttributeValue::$table, 'attribute_id')
                        ->model(AttributeValue::class)
                        ->positionKey('position')
                )
                ->nested()
                ->repeaterSortable()
                ->repeaterAddLabel('Aggiungi valore')
                ->repeaterDeleteTitle('Elimina valore')
                ->repeaterDeleteText('Confermi l\'eliminazione di questo valore?')
                ->repeaterDeleteCancelLabel('Annulla')
                ->repeaterDeleteConfirmLabel('Elimina')
                ->repeaterDeleteConfirmClass('btn btn-danger')
                ->label('Valori'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        $cards = [
            (new Card)->components([
                SectionTitle::make('Attributo')
                    ->tooltip('Il livello dice dove vive l\'attributo: il modello descrive l\'articolo, la variante ne cambia l\'aspetto, il prodotto è quello che si vende. L\'unità di misura serve ai tipi "Numero".')
                    ->columnSpan(12),
                static::getInput('name')->columnSpan(6),
                static::getInput('group_name')->columnSpan(6),
                static::getInput('level')->columnSpan(4),
                static::getInput('type')->columnSpan(4),
                static::getInput('unit')->columnSpan(4),
                static::getInput('is_filterable')->columnSpan(6),
                static::getInput('is_visible')->columnSpan(6),
            ])->columns(12)->columnSpan(12),
        ];

        // Il riquadro dei valori solo dove serve: un attributo "Testo" non ha
        // niente da elencare, e in creazione il tipo non è ancora scelto.
        if (static::usesValues(static::currentRow())) {
            $cards[] = (new Card)->components([
                SectionTitle::make('Valori')
                    ->tooltip('L\'ordine è quello che vedrà il cliente. Il colore serve al pallino in vetrina, la fantasia quando un colore non basta.')
                    ->columnSpan(12),
                static::getInput('values')->columnSpan(12),
            ])->columns(12)->columnSpan(12);
        }

        return (new Form)->components([
            (new Container)->components($cards)->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('level')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => htmlspecialchars(
                    Attributes::levels()[(string) ($row['level'] ?? '')] ?? '',
                    ENT_QUOTES,
                    'UTF-8'
                )),
            TableColumn::key('type')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => htmlspecialchars(
                    Attributes::types()[(string) ($row['type'] ?? '')] ?? '',
                    ENT_QUOTES,
                    'UTF-8'
                )),
            TableColumn::key('group_name')->text()->size('little'),
            TableColumn::key('is_visible')->visibleBadge()->size('little'),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Attributi',
                'create' => 'Nuovo attributo',
                'edit' => 'Modifica attributo',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('catalogo')
            ->title('Attributi')
            ->order(43)
            ->authority(['admin', 'administrator']);
    }

    /** Vero quando quell'attributo si sceglie da un elenco di valori. */
    public static function usesValues(?array $row): bool
    {
        return $row !== null && Attributes::usesValues((string) ($row['type'] ?? ''));
    }

    /** Nome macchina alla creazione, e nessun tipo cambiato sotto ai valori. */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        if ($action === 'store') {
            $values['slug'] = Slug::make((string) ($values['name'] ?? ''), Attribute::$table);
            $values['position'] = Positions::next(Attribute::$table);
        } else {
            unset($values['slug'], $values['position']);
        }

        $id = (int) ($oldValues['id'] ?? 0);
        $from = (string) ($oldValues['type'] ?? '');
        $to = (string) ($values['type'] ?? $from);

        if ($id > 0 && $to !== $from && Attributes::usesValues($from) && !Attributes::usesValues($to)) {
            static::assertNoValues($id);
        }

        return $values;
    }

    /** Cambiare tipo con dei valori dentro li butterebbe via in silenzio. */
    public static function assertNoValues(int $id): void
    {
        if (static::valueCount($id) > 0) {
            throw UserError::make(
                'attribute.type_locked',
                'Questo attributo ha dei valori: eliminali prima di cambiarne il tipo.'
            );
        }
    }

    /** Quanti valori ha quell'attributo. */
    public static function valueCount(int $id): int
    {
        return count(static::rowsOf(AttributeValue::class, ['attribute_id' => $id]));
    }

    /** Un attributo usato da un prodotto resta, nascosto. */
    public static function assertDeletable(int|string $id): void
    {
        if (static::isUsed((int) $id)) {
            throw new RuntimeException(
                'Questo attributo è usato da qualche prodotto: nascondilo invece di eliminarlo.'
            );
        }
    }

    /** In G2a i prodotti non esistono ancora: lo saprà il piano 3. */
    protected static function isUsed(int $id): bool
    {
        return false;
    }

    /** La riga aperta, quando si sta modificando. */
    protected static function currentRow(): ?array
    {
        $id = static::currentId();

        if ($id === null) {
            return null;
        }

        $rows = static::rowsOf(Attribute::class, ['id' => $id]);

        return $rows[0] ?? null;
    }
}
```

- [ ] **Passo 7: `UserError::make()` accetta un testo?** — leggere
  `src/Support/Errors/UserError.php`. Se la firma è `make(string $code)` senza testo
  di riserva, **aggiungere il codice** `attribute.type_locked` dove stanno gli altri
  (accanto a `category.loop`) e chiamare `UserError::make('attribute.type_locked')`,
  correggendo anche il codice qui sopra.

- [ ] **Passo 8: la pagina è sempre attiva** — in `tests/ConventionsTest.php`,
  aggiungere `'Wonder\\Plugin\\Gestionale\\Resources\\Catalog\\AttributeResource'`
  all'elenco `$sempreAttive`.

- [ ] **Passo 9: rieseguire tutti i test** — `php tests/run.php`. Atteso: tutto verde.

- [ ] **Passo 10: provare nel browser** su `https://ecommerce.test`, entrando con
  l'utente amministratore (il login lo fa l'utente):
  1. Catalogo → Attributi → Nuovo attributo: "Colore", livello Variante, tipo Colore.
     Salvare: arriva il toast e il riquadro "Valori" compare nella scheda.
  2. Aggiungere tre valori (Blu `#1f4ed8`, Rosso, Nero), riordinarli, salvare,
     ricaricare: restano nell'ordine scelto.
  3. Cambiare il tipo in "Testo" e salvare: il salvataggio si ferma con il messaggio.
  4. Nuovo attributo "Materiale", tipo Testo: il riquadro dei valori non c'è.
  5. Elenco: livello e tipo si leggono in italiano.

- [ ] **Passo 11: commit**

```bash
git add src/Resources/ tests/CatalogResourcesTest.php tests/ConventionsTest.php
git commit -m "Add the attributes page with its values"
```

---

### Task 4: Dati di prova e guide

**File:**
- Modificare: `src/Seeding/CatalogDemo.php`, `tests/integrazione/CatalogDemoTest.php`,
  `docs/dev/concetti/catalogo.md`, `docs/user/SUMMARY.md`
- Creare: `docs/user/catalogo-attributi.md`

**Interfacce:**
- Consuma: `DemoData::register()` (G1), `Code::make()`, `Slug::make()`.
- Produce: due attributi di prova (`Prova Colore` con tre valori, `Prova Taglia` con
  quattro) dentro la stessa chiave `catalogo-tassonomie`, rinominata
  `catalogo-base`.

- [ ] **Passo 1: aggiornare il test d'integrazione** —
  `tests/integrazione/CatalogDemoTest.php`: dopo `CatalogDemo::create()` controllare
  che esistano i due attributi e i loro sette valori, e che `clear()` li tolga
  insieme al resto. Il test gira dentro `Transaction::run()` che annulla sempre.

- [ ] **Passo 2: eseguirlo** — `php tests/integrazione/CatalogDemoTest.php`.
  Atteso: FALLISCE (gli attributi non vengono creati).

- [ ] **Passo 3: creare gli attributi in `CatalogDemo`** — aggiungere a `create()`,
  dopo i tag:

```php
        $created += self::attribute('Colore', [
            'slug' => 'prova-colore',
            'type' => 'color',
            'level' => 'variant',
            'group_name' => '',
            'position' => 1,
        ], [
            ['label' => 'Blu', 'color' => '#1f4ed8'],
            ['label' => 'Rosso', 'color' => '#c1121f'],
            ['label' => 'Nero', 'color' => '#111111'],
        ]);

        $created += self::attribute('Taglia', [
            'slug' => 'prova-taglia',
            'type' => 'select',
            'level' => 'product',
            'group_name' => 'Misure',
            'position' => 2,
        ], [
            ['label' => 'S'],
            ['label' => 'M'],
            ['label' => 'L'],
            ['label' => 'XL'],
        ]);
```

  e i due metodi:

```php
    /**
     * Un attributo di prova con i suoi valori.
     *
     * @param array<string, mixed> $values
     * @param list<array<string, mixed>> $rows
     * @return int righe create, attributo e valori insieme
     */
    private static function attribute(string $name, array $values, array $rows): int
    {
        $name = self::PREFIX.$name;

        if (self::idOf(Attribute::class, $name) > 0) {
            return 0;
        }

        $result = Attribute::create(array_merge([
            'code' => Code::make(Attribute::class, Codes::ATTRIBUTE),
            'name' => $name,
            'unit' => '',
            'is_filterable' => 'true',
            'is_visible' => 'true',
        ], $values));

        if (empty($result->success)) {
            return 0;
        }

        $created = 1;
        $attributeId = self::idOf(Attribute::class, $name);
        $position = 1;

        foreach ($rows as $row) {
            $value = AttributeValue::create(array_merge([
                'attribute_id' => $attributeId,
                'color' => '',
                'position' => $position++,
            ], $row));

            $created += !empty($value->success) ? 1 : 0;
        }

        return $created;
    }
```

  In `clear()`, prima dei tag, togliere i valori e poi gli attributi:

```php
        foreach (self::rows(Attribute::class) as $row) {
            if (!str_starts_with((string) ($row['name'] ?? ''), self::PREFIX)) {
                continue;
            }

            // Prima i valori: la chiave esterna non lascia andare l'attributo.
            foreach (self::rows(AttributeValue::class) as $value) {
                if ((int) ($value['attribute_id'] ?? 0) !== (int) $row['id']) {
                    continue;
                }

                $removed += !empty(AttributeValue::delete((int) $value['id'])->success) ? 1 : 0;
            }

            $removed += !empty(Attribute::delete((int) $row['id'])->success) ? 1 : 0;
        }
```

  Aggiornare il docblock della classe e l'etichetta del registro
  (`'Catalogo: marchi, categorie, tag e attributi'`).

- [ ] **Passo 4: rieseguire il test d'integrazione** — verde.

- [ ] **Passo 5: provare il comando** dal sito di prova:

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge gestionale:demo
```

Atteso: 13 righe create (6 tassonomie + 2 attributi + 7 valori). Poi
`php forge gestionale:demo --fresh` le toglie tutte e le rifà. Alla fine
**svuotare il sito di prova**: `php forge gestionale:demo --clear` (o l'opzione che
il comando espone per il solo svuotamento).

- [ ] **Passo 6: la guida dello sviluppatore** — in `docs/dev/concetti/catalogo.md`,
  sezione "Attributi": le due tabelle, i tre livelli con la tabella dei casi d'uso,
  i quattro tipi, perché `slug` e `group_name` e non `key` e `group`, e la firma di
  `Attributes::assignment()`/`format()` per chi scriverà i collegamenti nel piano 3.

- [ ] **Passo 7: la guida del commerciante** — `docs/user/catalogo-attributi.md`:
  cos'è un attributo con esempi da negozio (colore sulla variante, taglia sul
  prodotto, materiale sul modello), come si aggiungono i valori, perché il tipo non
  si cambia quando ci sono già dei valori, e che un attributo usato si nasconde
  invece di eliminarlo. Aggiungere la voce in `docs/user/SUMMARY.md` sotto
  "Catalogo", dopo "Marchi, categorie e tag".

- [ ] **Passo 8: tutti i test** — `php tests/run.php` e i test d'integrazione.

- [ ] **Passo 9: commit**

```bash
git add src/Seeding/CatalogDemo.php tests/integrazione/CatalogDemoTest.php docs/
git commit -m "Seed two attributes and write their guides"
```

---

## Chiusura

- [ ] `php tests/run.php` verde, test d'integrazione verdi.
- [ ] Ramo unito in `main` e spinto; CI verde sul commit unito.
- [ ] `TODO.md`: spuntato il piano 2 di G2a con una riga che dice cosa contiene.
