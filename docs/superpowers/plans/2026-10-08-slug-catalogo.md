# Slug del catalogo — piano di implementazione

> **Per chi esegue:** SOTTO-SKILL RICHIESTA: superpowers:subagent-driven-development (consigliata) oppure superpowers:executing-plans, un compito alla volta. I passi usano le caselle (`- [ ]`).

**Obiettivo:** schede del negozio su `/prodotto/{modello}/` e `/prodotto/{modello}/{variante}/`, con la query che sceglie l'opzione (`?taglia=s&materiale=cotone`).

**Architettura:** nel gestionale lo slug della variante nasce dal suo valore ed è unico nel modello (`Slug::uniqueWithin`); `VariantSlugs` e il comando `gestionale:variant-slugs` rifanno quelli già salvati. Nell'ecommerce un solo costruttore di indirizzi (`ProductUrl`), `ProductCatalog::resolve()` decide scheda, 301 o 404, e `OptionQuery` traduce la query nell'opzione scelta.

**Tecnologie:** PHP 8, Model del framework Wonder Image (`find`/`create`/`update`), Symfony Console per `forge`, test con l'harness `check()`/`summary()`.

**Spec:** `docs/superpowers/specs/2026-10-08-slug-catalogo-design.md`

## Vincoli globali

- Indirizzi: `/prodotto/{slug}/` (route `ecommerce.catalog.product`, invariata) e `/prodotto/{slug}/{variante}/` (route nuova `ecommerce.catalog.product.variant`, stesso handler).
- Lo slug del modello non cambia. Quello della variante si scrive alla creazione e poi lo cambia solo `gestionale:variant-slugs`.
- La variante scheletro ha lo slug vuoto.
- La query non entra mai nel canonical e non arriva mai in SQL.
- Variante sconosciuta, nascosta, o modello con una sola variante visibile: 301 al modello, query conservata. Modello sconosciuto o fuori vetrina: 404.
- Query sconosciuta o impossibile: si ignora, niente 404 né rimandi. Chiave ripetuta o array: conta il primo valore.
- Nessun rimando dai vecchi indirizzi.
- Il ramo `slug-catalogo` del gestionale e quello dell'ecommerce si uniscono insieme: con il solo gestionale una variante scheletro nuova avrebbe un link `/prodotto//` nel vecchio ecommerce.

## Dove si lavora

- **Parte A (compiti 1-4), gestionale:** questo worktree, `/Users/andreamarinoni/Developer/packages/gestionale/.claude/worktrees/lista-disponibilita`, ramo `slug-catalogo`. Test: `php tests/<Nome>Test.php`, tutto con `php tests/run.php`.
- **Parte B (compiti 5-9), ecommerce:** un worktree nuovo dal `main` locale (`c51c4c0`, non ancora spinto), aperto da una sessione con cartella di lavoro nell'ecommerce:
  ```bash
  git -C /Users/andreamarinoni/Developer/packages/ecommerce worktree add .claude/worktrees/slug-catalogo -b slug-catalogo main
  ```
  La `vendor` va **copiata**, non collegata: l'autoload di Composer risolve `src/` partendo dal percorso reale della `vendor`, e con un symlink caricherebbe le classi del checkout principale. I due symlink interni sono relativi e dalla copia puntano nel vuoto: vanno rifatti assoluti.
  ```bash
  cp -R /Users/andreamarinoni/Developer/packages/ecommerce/vendor /Users/andreamarinoni/Developer/packages/ecommerce/.claude/worktrees/slug-catalogo/vendor
  ```
  ```bash
  ln -sfn /Users/andreamarinoni/Developer/packages/app /Users/andreamarinoni/Developer/packages/ecommerce/.claude/worktrees/slug-catalogo/vendor/wonder-image/app
  ```
  ```bash
  ln -sfn /Users/andreamarinoni/Developer/packages/gestionale /Users/andreamarinoni/Developer/packages/ecommerce/.claude/worktrees/slug-catalogo/vendor/wonder-image/gestionale
  ```
  I test unitari della parte B non usano niente della parte A: le due parti si possono fare in parallelo.
- **Compito 10:** sul sito di prova, con entrambe le parti finite.

## Attenzione in revisione

1. **Variante scheletro in un modello con più varianti:** ha lo slug vuoto; nessun link deve diventare `/prodotto/modello//`. Lo fissa il test di `ProductUrl` (compito 5).
2. **Etichette con entità HTML** (`Novit&agrave;`): devono dare `novita` sia nello slug della variante sia nella query. Lo fissano i test di `Slug::base` (compito 1) e di `OptionQuery::slug` (compito 6).
3. **Comando lanciato due volte, o varianti con lo stesso valore:** la seconda volta non cambia nulla e non scambia `blu` con `blu-2`. Lo fissa il test di `VariantSlugs::compute` (compito 2).
4. **Rimando con i parametri di tracciamento** (`?utm_source=…`): il 301 dalla variante sconosciuta li conserva. Lo fissa il test HTTP (compito 10).
5. **Query scritta a mano** (`?Taglia=S`, `?taglia[]=m`, `?taglia=`): maiuscole accettate, primo valore della lista, valore vuoto ignorato. Lo fissano i test di `ProductUrl::query` e `OptionQuery` (compiti 5 e 6).

---

## Parte A — Gestionale

### Compito 1: `Slug::base`, `firstFree`, `uniqueWithin`

**File:**
- Modifica: `src/Support/Catalog/Slug.php`
- Crea: `tests/CatalogSlugTest.php`

**Interfacce:**
- Produce:
  - `Slug::base(string $name): string` (`Blu Notte` → `blu-notte`; entità decodificate; `''` se non resta niente);
  - `Slug::firstFree(string $base, callable $taken): string` (`$taken(string $slug): bool`; `base`, poi `base-2`, `base-3`…);
  - `Slug::uniqueWithin(string $name, string $modelClass, array $scope, string $column = 'slug'): string` (`''` per un nome vuoto, `variante` se il nome è fatto solo di simboli).
  - `Slug::unique()` resta com'è nel comportamento.

- [ ] **Passo 1: scrivi il test che fallisce**

`tests/CatalogSlugTest.php`:

```php
<?php
/** php tests/CatalogSlugTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Catalog\Slug;

/** Un Model finto che rispetta tutte le condizioni di `find()`, come la tabella. */
final class VariantiFinte
{
    /** @var list<array<string, string|int>> */
    public static array $righe = [];

    public static function find(array $condition, int $limit): mixed
    {
        foreach (self::$righe as $riga) {
            if (array_intersect_assoc($condition, $riga) === $condition) {
                return $riga;
            }
        }

        return [];
    }
}

check('la base è lo slug che scriverebbe il Model, entità comprese', fn () =>
    Slug::base('Blu Notte') === 'blu-notte'
    && Slug::base('Novit&agrave;') === 'novita'
    && Slug::base('a__b') === 'a-b'
    && Slug::base('★') === ''
);

check('il primo slug libero salta quelli presi', fn () =>
    Slug::firstFree('blu', static fn (string $slug): bool => in_array($slug, ['blu', 'blu-2'], true)) === 'blu-3'
    && Slug::firstFree('blu', static fn (string $slug): bool => false) === 'blu'
);

check('lo slug è unico nel modello, non nella tabella, e conta le righe cancellate', function () {
    VariantiFinte::$righe = [
        ['product_model_id' => 1, 'slug' => 'blu', 'deleted' => 'false'],
        ['product_model_id' => 1, 'slug' => 'blu-2', 'deleted' => 'true'],
        ['product_model_id' => 2, 'slug' => 'rosso', 'deleted' => 'false'],
    ];

    return Slug::uniqueWithin('Blu', VariantiFinte::class, ['product_model_id' => 1]) === 'blu-3'
        && Slug::uniqueWithin('Rosso', VariantiFinte::class, ['product_model_id' => 1]) === 'rosso'
        && Slug::uniqueWithin('Blu', VariantiFinte::class, ['product_model_id' => 2]) === 'blu';
});

check('un valore fatto solo di simboli resta raggiungibile, un nome vuoto no', function () {
    VariantiFinte::$righe = [
        ['product_model_id' => 1, 'slug' => 'variante', 'deleted' => 'false'],
    ];

    return Slug::uniqueWithin('★', VariantiFinte::class, ['product_model_id' => 1]) === 'variante-2'
        && Slug::uniqueWithin('★', VariantiFinte::class, ['product_model_id' => 3]) === 'variante'
        && Slug::uniqueWithin('  ', VariantiFinte::class, ['product_model_id' => 1]) === '';
});

summary();
```

- [ ] **Passo 2: lancia il test e guardalo fallire**

Run: `php tests/CatalogSlugTest.php`
Atteso: errore `Call to undefined method …Slug::base()`.

- [ ] **Passo 3: scrivi il codice**

In `src/Support/Catalog/Slug.php` sostituisci `unique()` e `taken()` con questo blocco (`make()` resta com'è):

```php
    /**
     * Uno slug libero nella tabella di quel Model, anche fuori dal sito
     * avviato: `accessori`, e se è già preso `accessori-2`, `accessori-3`.
     *
     * Parte dallo slug che il Model stesso scriverebbe, così quello salvato è
     * quello controllato. Conta anche le righe cancellate: l'indice unico
     * della colonna le vede ancora.
     *
     * @param class-string<\Wonder\App\Model> $modelClass
     */
    public static function unique(string $name, string $modelClass, string $column = 'slug'): string
    {
        $base = self::base($name);

        if ($base === '') {
            return '';
        }

        return self::firstFree($base, static fn (string $slug): bool => self::taken($modelClass, [$column => $slug]));
    }

    /**
     * Uno slug libero solo tra le righe di `$scope`: lo slug della variante
     * è unico nel suo modello, non nella tabella (`blu`, poi `blu-2`). Se il
     * nome è fatto solo di simboli parte da `variante`, così la riga resta
     * raggiungibile. Conta anche le righe cancellate, come `unique()`.
     *
     * @param class-string<\Wonder\App\Model> $modelClass
     * @param array<string, mixed> $scope
     */
    public static function uniqueWithin(string $name, string $modelClass, array $scope, string $column = 'slug'): string
    {
        if (trim($name) === '') {
            return '';
        }

        $base = self::base($name);

        if ($base === '') {
            $base = 'variante';
        }

        return self::firstFree($base, static fn (string $slug): bool => self::taken($modelClass, [$column => $slug] + $scope));
    }

    /** Lo slug che scriverebbe il Model: `Blu Notte` → `blu-notte`. */
    public static function base(string $name): string
    {
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $base = str_replace('_', '-', TextSlug::make($name));
        $base = trim((string) preg_replace('/-+/', '-', $base), '-');

        return $base !== '' ? $base : self::make($name);
    }

    /**
     * `base`, e se è preso `base-2`, `base-3`…
     *
     * @param callable(string): bool $taken
     */
    public static function firstFree(string $base, callable $taken): string
    {
        $slug = $base;

        for ($n = 2; $n < 1000 && $taken($slug); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    /** @param array<string, mixed> $where */
    private static function taken(string $modelClass, array $where): bool
    {
        foreach (['false', 'true'] as $deleted) {
            try {
                $row = $modelClass::find($where + ['deleted' => $deleted], 1);
            } catch (Throwable) {
                // Senza database non c'è niente con cui scontrarsi.
                return false;
            }

            if (is_array($row) && $row !== []) {
                return true;
            }
        }

        return false;
    }
```

- [ ] **Passo 4: lancia i test**

Run: `php tests/CatalogSlugTest.php` e `php tests/DemoCodeTest.php` (prova `unique()`, che non deve cambiare).
Atteso: tutti verdi.

- [ ] **Passo 5: commit**

```bash
git add src/Support/Catalog/Slug.php tests/CatalogSlugTest.php
git commit -m "Slug unico dentro il modello: uniqueWithin, base e firstFree"
```

---

### Compito 2: `VariantSlugs`

**File:**
- Crea: `src/Support/Catalog/VariantSlugs.php`
- Crea: `tests/VariantSlugsTest.php`

**Interfacce:**
- Usa: `Slug::base()`, `Slug::firstFree()` (compito 1).
- Produce:
  - `VariantSlugs::compute(array $variants, array $reserved = []): array` — pura; `$variants` = `list<array{id: int, label: string, slug: string}>` per posizione, `$reserved` = slug già presi; restituisce `[variantId => slug]` nello stesso ordine;
  - `VariantSlugs::plan(int $modelId): array` — `[variantId => slugNuovo]`, solo le varianti il cui slug cambia;
  - `VariantSlugs::apply(array $plan): int` — scrive e conta;
  - `VariantSlugs::modelIds(): list<int>` — i modelli non cancellati.

Regola di `compute`: chi ha già uno slug giusto (la base del suo valore, oppure base-N, non preso da altri) lo tiene; gli altri prendono il primo libero per posizione; una variante senza valore ha lo slug vuoto.

- [ ] **Passo 1: scrivi il test che fallisce**

`tests/VariantSlugsTest.php`:

```php
<?php
/** php tests/VariantSlugsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Catalog\VariantSlugs;

check('gli slug in stile vecchio diventano il valore della variante', fn () =>
    VariantSlugs::compute([
        ['id' => 10, 'label' => 'Blu', 'slug' => 'blu-51167-3'],
        ['id' => 11, 'label' => 'Rosso', 'slug' => 'rosso-51167-4'],
    ]) === [10 => 'blu', 11 => 'rosso']
);

check('due valori uguali nello stesso modello: il secondo per posizione prende -2', fn () =>
    VariantSlugs::compute([
        ['id' => 10, 'label' => 'Blu', 'slug' => ''],
        ['id' => 11, 'label' => 'Blu', 'slug' => ''],
    ]) === [10 => 'blu', 11 => 'blu-2']
);

check('chi ha già uno slug giusto lo tiene: niente scambi tra blu e blu-2', fn () =>
    VariantSlugs::compute([
        ['id' => 10, 'label' => 'Blu', 'slug' => 'blu-2'],
        ['id' => 11, 'label' => 'Blu', 'slug' => 'blu'],
    ]) === [10 => 'blu-2', 11 => 'blu']
);

check('lo scheletro senza valore resta vuoto, i simboli diventano variante', fn () =>
    VariantSlugs::compute([
        ['id' => 10, 'label' => '', 'slug' => 'maglietta-51167'],
        ['id' => 11, 'label' => '★', 'slug' => ''],
    ]) === [10 => '', 11 => 'variante']
);

check('gli slug delle varianti cancellate restano presi', fn () =>
    VariantSlugs::compute([['id' => 10, 'label' => 'Blu', 'slug' => 'blu']], ['blu']) === [10 => 'blu-2']
);

check('rifatto sul suo risultato non cambia nulla', function () {
    $varianti = [
        ['id' => 10, 'label' => 'Blu', 'slug' => 'x'],
        ['id' => 11, 'label' => 'Blu', 'slug' => 'y'],
        ['id' => 12, 'label' => 'Novit&agrave;', 'slug' => 'z'],
    ];
    $primo = VariantSlugs::compute($varianti, ['blu-2']);

    foreach ($varianti as $i => $variante) {
        $varianti[$i]['slug'] = $primo[$variante['id']];
    }

    return $primo === [10 => 'blu', 11 => 'blu-3', 12 => 'novita']
        && VariantSlugs::compute($varianti, ['blu-2']) === $primo;
});

summary();
```

- [ ] **Passo 2: lancia il test e guardalo fallire**

Run: `php tests/VariantSlugsTest.php`
Atteso: errore `Class "…VariantSlugs" not found`.

- [ ] **Passo 3: scrivi il codice**

`src/Support/Catalog/VariantSlugs.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;

/**
 * Gli slug delle varianti già salvate, rifatti dal loro valore: `blu`,
 * `rosso`, `blu-2`. Lo usa il comando `gestionale:variant-slugs`, l'unico
 * punto che riscrive uno slug già salvato; le varianti nuove lo prendono dal
 * `Generator`.
 *
 * La regola (`compute`) è pura; le letture stanno in `plan()`.
 */
final class VariantSlugs
{
    /**
     * Chi ha già uno slug giusto lo tiene, così il comando non sposta
     * indirizzi buoni; gli altri prendono il primo libero, per posizione.
     *
     * @param list<array{id: int, label: string, slug: string}> $variants per posizione
     * @param list<string> $reserved slug già presi (le varianti cancellate)
     * @return array<int, string> [variantId => slug]
     */
    public static function compute(array $variants, array $reserved = []): array
    {
        $taken = array_fill_keys(array_filter($reserved), true);
        $bases = [];
        $slugs = [];

        foreach ($variants as $variant) {
            $id = (int) $variant['id'];
            $bases[$id] = self::baseFor((string) $variant['label']);
            $current = (string) $variant['slug'];

            if ($bases[$id] !== '' && $current !== '' && !isset($taken[$current])
                && preg_match('/^'.preg_quote($bases[$id], '/').'(-\d+)?$/', $current) === 1) {
                $slugs[$id] = $current;
                $taken[$current] = true;
            }
        }

        $result = [];

        foreach ($variants as $variant) {
            $id = (int) $variant['id'];

            if (!isset($slugs[$id]) && $bases[$id] !== '') {
                $slugs[$id] = Slug::firstFree($bases[$id], static fn (string $slug): bool => isset($taken[$slug]));
                $taken[$slugs[$id]] = true;
            }

            $result[$id] = $slugs[$id] ?? '';
        }

        return $result;
    }

    /**
     * Le varianti del modello il cui slug cambia.
     *
     * @return array<int, string> [variantId => slugNuovo]
     */
    public static function plan(int $modelId): array
    {
        $variants = [];
        $current = [];

        foreach (self::rows(ProductVariant::find(['product_model_id' => $modelId, 'deleted' => 'false'], null, 'position', 'ASC')) as $row) {
            $id = (int) $row['id'];
            $current[$id] = (string) ($row['slug'] ?? '');
            $variants[] = ['id' => $id, 'label' => self::label($id), 'slug' => $current[$id]];
        }

        $reserved = array_map(
            static fn (array $row): string => (string) ($row['slug'] ?? ''),
            self::rows(ProductVariant::find(['product_model_id' => $modelId, 'deleted' => 'true']))
        );
        $changes = [];

        foreach (self::compute($variants, $reserved) as $id => $slug) {
            if ($slug !== $current[$id]) {
                $changes[$id] = $slug;
            }
        }

        return $changes;
    }

    /** @param array<int, string> $plan @return int le varianti scritte */
    public static function apply(array $plan): int
    {
        foreach ($plan as $variantId => $slug) {
            ProductVariant::update(['slug' => $slug], (int) $variantId);
        }

        return count($plan);
    }

    /** @return list<int> */
    public static function modelIds(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            self::rows(ProductModel::find(['deleted' => 'false'], null, 'id', 'ASC'))
        );
    }

    private static function baseFor(string $label): string
    {
        if (trim($label) === '') {
            return '';
        }

        $base = Slug::base($label);

        return $base !== '' ? $base : 'variante';
    }

    /** L'etichetta del valore della variante; vuota per lo scheletro. */
    private static function label(int $variantId): string
    {
        foreach (ProductAttributes::read('variant', $variantId) as $link) {
            $valueId = (int) ($link['attribute_value_id'] ?? 0);

            if ($valueId > 0) {
                $value = AttributeValue::find(['id' => $valueId], 1);

                return is_array($value) ? trim((string) ($value['label'] ?? '')) : '';
            }
        }

        return '';
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return array_is_list($rows) ? array_values(array_filter($rows, 'is_array')) : [$rows];
    }
}
```

- [ ] **Passo 4: lancia il test**

Run: `php tests/VariantSlugsTest.php`
Atteso: tutti verdi. (`plan` e `apply` si provano sul database nel compito 3.)

- [ ] **Passo 5: commit**

```bash
git add src/Support/Catalog/VariantSlugs.php tests/VariantSlugsTest.php
git commit -m "VariantSlugs: gli slug delle varianti rifatti dal loro valore"
```

---

### Compito 3: slug giusti alla creazione

**File:**
- Modifica: `src/Support/Catalog/Generator.php:66-80`
- Modifica: `src/Support/Catalog/Skeleton.php:41`
- Test: `tests/integrazione/CombinazioniTest.php` (dopo il check «lo scheletro è stato riusato», riga ~149)

**Interfacce:**
- Usa: `Slug::uniqueWithin()` (compito 1), `VariantSlugs::plan()`/`apply()` (compito 2).

- [ ] **Passo 1: scrivi i test che falliscono**

In `CombinazioniTest.php` aggiungi `use Wonder\Plugin\Gestionale\Support\Catalog\VariantSlugs;` tra gli `use`, e dopo il check «lo scheletro è stato riusato, non lasciato in giro»:

```php
        check('le varianti prendono lo slug dal loro valore, unico nel modello', function () use ($modelId) {
            $slug = array_column(ProductModelResource::variants($modelId), 'slug');
            sort($slug);

            return $slug === ['blu', 'rosso'];
        });

        check('con gli slug già giusti il comando non ha niente da fare', fn () =>
            VariantSlugs::plan($modelId) === []
        );

        check('il comando rifà gli slug in stile vecchio, e la seconda volta non cambia nulla', function () use ($modelId) {
            foreach (ProductModelResource::variants($modelId) as $variante) {
                ProductVariant::update(['slug' => 'vecchio-'.$variante['id']], (int) $variante['id']);
            }

            $scritte = VariantSlugs::apply(VariantSlugs::plan($modelId));
            $slug = array_column(ProductModelResource::variants($modelId), 'slug');
            sort($slug);

            return $scritte === 2 && $slug === ['blu', 'rosso'] && VariantSlugs::plan($modelId) === [];
        });

        check('lo scheletro nasce senza slug: non ha una pagina sua', function () {
            $altro = ProductModel::create([
                'code' => Code::make(ProductModel::class, Codes::MODEL),
                'name' => 'Prova scheletro',
                'slug' => Slug::make('prova-scheletro-'.uniqid()),
                'sku' => 'SCH-1',
                'unit' => 'pz',
                'type' => 'simple',
                'visible' => 'true',
                'visible_online' => 'true',
                'position' => 1,
            ]);
            $creato = Skeleton::forModel((int) ($altro->insert_id ?? 0), 'Prova scheletro', 'SCH-1');
            $variante = ProductVariant::find(['id' => $creato['variant_id']], 1);

            return is_array($variante) && (string) ($variante['slug'] ?? '') === '';
        });
```

- [ ] **Passo 2: lancia il test e guardalo fallire**

Run: `php tests/integrazione/CombinazioniTest.php`
Atteso: falliscono «le varianti prendono lo slug…» (oggi `blu-<id>-<id>`) e «lo scheletro nasce senza slug»; gli altri verdi.

- [ ] **Passo 3: scrivi il codice**

`Generator.php`, nel ramo che riusa lo scheletro:

```php
            if ($reuse !== null && $reuse['variant_id'] > 0) {
                $variantId = $reuse['variant_id'];
                // Lo scheletro non aveva uno slug: prende quello del suo valore.
                ProductVariant::update([
                    'name' => $variant['label'],
                    'slug' => Slug::uniqueWithin($variant['label'], ProductVariant::class, ['product_model_id' => $modelId]),
                ], $variantId);
                $reuse['variant_id'] = 0;
            } else {
```

e nella `create` sotto:

```php
                    'slug' => Slug::uniqueWithin($variant['label'], ProductVariant::class, ['product_model_id' => $modelId]),
```

`Skeleton.php:41`:

```php
                'slug' => '',
```

- [ ] **Passo 4: lancia i test**

Run: `php tests/integrazione/CombinazioniTest.php`, poi `php tests/run.php`.
Atteso: tutti verdi.

- [ ] **Passo 5: commit**

```bash
git add src/Support/Catalog/Generator.php src/Support/Catalog/Skeleton.php tests/integrazione/CombinazioniTest.php
git commit -m "Varianti nuove con lo slug del loro valore, scheletro senza slug"
```

---

### Compito 4: comando `gestionale:variant-slugs`

**File:**
- Crea: `src/Console/VariantSlugsCommand.php`
- Modifica: `module.json` (`console.commands`)
- Test: `tests/CommandsTest.php`

**Interfacce:**
- Usa: `VariantSlugs::modelIds()`, `plan()`, `apply()` (compito 2).

- [ ] **Passo 1: scrivi il test che fallisce**

In `tests/CommandsTest.php`: aggiungi `use Wonder\Plugin\Gestionale\Console\VariantSlugsCommand;`; nel primo check aggiungi `&& is_subclass_of(VariantSlugsCommand::class, Command::class)`; nel secondo `&& in_array(VariantSlugsCommand::class, $dichiarati, true)` e `&& (new VariantSlugsCommand)->getName() === 'gestionale:variant-slugs'`. Poi, dopo il check su `limit`:

```php
check('gli slug delle varianti si possono prima solo elencare', function () {
    $opzione = (new VariantSlugsCommand)->getDefinition()->getOption('dry-run');

    return !$opzione->acceptValue();
});
```

- [ ] **Passo 2: lancia il test e guardalo fallire**

Run: `php tests/CommandsTest.php`
Atteso: falliscono i tre check che nominano `VariantSlugsCommand`.

- [ ] **Passo 3: scrivi il codice**

`src/Console/VariantSlugsCommand.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wonder\Plugin\Gestionale\Support\Catalog\VariantSlugs;

/**
 * `php forge gestionale:variant-slugs` — rifà gli slug delle varianti dai loro
 * valori: `/prodotto/maglietta/blu/` al posto di `/prodotto/maglietta-51167/`.
 *
 * Lanciato una seconda volta non cambia nulla. `--dry-run` elenca i cambi
 * senza scriverli.
 */
final class VariantSlugsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('gestionale:variant-slugs')
            ->setDescription('Rifà gli slug delle varianti dai loro valori: blu, rosso, blu-2')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Elenca i cambi senza scriverli');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $changed = 0;

        foreach (VariantSlugs::modelIds() as $modelId) {
            $plan = VariantSlugs::plan($modelId);

            foreach ($plan as $variantId => $slug) {
                $output->writeln("Modello {$modelId}, variante {$variantId}: «{$slug}»");
            }

            $changed += $dryRun ? count($plan) : VariantSlugs::apply($plan);
        }

        $output->writeln($dryRun
            ? "<comment>Da cambiare: {$changed} varianti. Non ho scritto niente.</comment>"
            : "<info>Slug cambiati: {$changed}.</info>");

        return Command::SUCCESS;
    }
}
```

In `module.json`, dopo `StockAlertsCommand`:

```json
            "Wonder\\Plugin\\Gestionale\\Console\\StockAlertsCommand",
            "Wonder\\Plugin\\Gestionale\\Console\\VariantSlugsCommand"
```

- [ ] **Passo 4: lancia i test**

Run: `php tests/CommandsTest.php`, poi `php tests/run.php`.
Atteso: tutti verdi.

- [ ] **Passo 5: commit**

```bash
git add src/Console/VariantSlugsCommand.php module.json tests/CommandsTest.php
git commit -m "Comando gestionale:variant-slugs, con --dry-run"
```

---

## Parte B — Ecommerce

Percorsi relativi al worktree dell'ecommerce. Test: `php tests/<Nome>Test.php`, tutto con `php tests/run.php`.

### Compito 5: `ProductUrl`

**File:**
- Crea: `src/Frontend/Catalog/ProductUrl.php`
- Crea: `tests/ProductUrlTest.php`

**Interfacce:**
- Produce:
  - `ProductUrl::make(string $modelSlug, string $variantSlug = '', array $query = []): string` — percorso relativo (o quello che dà la route), query solo se non vuota, codifica RFC 3986;
  - `ProductUrl::variantSlugFor(array $variant, int $visibleVariants): string` — `''` con meno di 2 varianti visibili o slug vuoto;
  - `ProductUrl::query(array $query): array<string, string>` — tiene i valori semplici non vuoti; di una lista il primo.

- [ ] **Passo 1: scrivi il test che fallisce**

`tests/ProductUrlTest.php`:

```php
<?php
/** php tests/ProductUrlTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductUrl;

check('modello, variante sotto il modello, query in coda', fn () =>
    ProductUrl::make('maglietta-girocollo') === '/prodotto/maglietta-girocollo/'
    && ProductUrl::make('maglietta-girocollo', 'blu') === '/prodotto/maglietta-girocollo/blu/'
    && ProductUrl::make('maglietta-girocollo', 'blu', ['taglia' => 's', 'materiale' => 'cotone'])
        === '/prodotto/maglietta-girocollo/blu/?taglia=s&materiale=cotone'
);

check('la variante scheletro non lascia una barra doppia', fn () =>
    ProductUrl::make('maglietta', '') === '/prodotto/maglietta/'
    && ProductUrl::make('maglietta', '  ') === '/prodotto/maglietta/'
);

check('della query restano i valori semplici non vuoti, di una lista il primo', fn () =>
    ProductUrl::query(['taglia' => '', 'colore' => ['m', 's'], 'x' => ['a' => ['b']], 'q' => ' a b ', 3 => 'z'])
        === ['colore' => 'm', 'q' => 'a b']
    && ProductUrl::make('m', '', ['q' => 'a b']) === '/prodotto/m/?q=a%20b'
    && ProductUrl::make('m', '', ['taglia' => '']) === '/prodotto/m/'
);

check('lo slug della variante va nell\'indirizzo solo se le varianti visibili sono più di una', fn () =>
    ProductUrl::variantSlugFor(['slug' => 'blu'], 1) === ''
    && ProductUrl::variantSlugFor(['slug' => 'blu'], 2) === 'blu'
    && ProductUrl::variantSlugFor(['slug' => ''], 3) === ''
    && ProductUrl::variantSlugFor([], 3) === ''
);

summary();
```

- [ ] **Passo 2: lancia il test e guardalo fallire**

Run: `php tests/ProductUrlTest.php`
Atteso: errore `Class "…ProductUrl" not found`.

- [ ] **Passo 3: scrivi il codice**

`src/Frontend/Catalog/ProductUrl.php`:

```php
<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

/**
 * L'indirizzo di una scheda: `/prodotto/{modello}/`, la variante sotto il
 * modello, e la query che sceglie l'opzione. È l'unico posto che lo
 * costruisce. Usa le route con nome; senza il sito avviato ricade sui
 * percorsi fissi.
 */
final class ProductUrl
{
    /** @param array<string, mixed> $query */
    public static function make(string $modelSlug, string $variantSlug = '', array $query = []): string
    {
        $modelSlug = trim($modelSlug);
        $variantSlug = trim($variantSlug);

        if ($variantSlug === '') {
            $path = self::route('ecommerce.catalog.product', ['slug' => $modelSlug]);
            if ($path === '') {
                $path = '/prodotto/'.rawurlencode($modelSlug).'/';
            }
        } else {
            $path = self::route('ecommerce.catalog.product.variant', ['slug' => $modelSlug, 'variante' => $variantSlug]);
            if ($path === '') {
                $path = '/prodotto/'.rawurlencode($modelSlug).'/'.rawurlencode($variantSlug).'/';
            }
        }

        $query = self::query($query);

        return $query === [] ? $path : $path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** Lo slug da mettere nell'indirizzo: nessuno se la variante è l'unica visibile. */
    public static function variantSlugFor(array $variant, int $visibleVariants): string
    {
        if ($visibleVariants < 2) {
            return '';
        }

        return trim((string) ($variant['slug'] ?? ''));
    }

    /**
     * I valori semplici e non vuoti; di una lista (`?taglia[]=s`) conta il primo.
     *
     * @param array<mixed> $query
     * @return array<string, string>
     */
    public static function query(array $query): array
    {
        $clean = [];

        foreach ($query as $key => $value) {
            if (is_array($value)) {
                $value = reset($value);
            }

            if (!is_string($key) || $key === '' || !is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /** @param array<string, string> $parameters */
    private static function route(string $name, array $parameters): string
    {
        if (!function_exists('__r')) {
            return '';
        }

        return (string) __r($name, $parameters);
    }
}
```

- [ ] **Passo 4: lancia il test**

Run: `php tests/ProductUrlTest.php`
Atteso: tutti verdi.

- [ ] **Passo 5: commit**

```bash
git add src/Frontend/Catalog/ProductUrl.php tests/ProductUrlTest.php
git commit -m "ProductUrl: un solo costruttore per gli indirizzi delle schede"
```

---

### Compito 6: `OptionQuery`

**File:**
- Crea: `src/Frontend/Catalog/OptionQuery.php`
- Crea: `tests/OptionQueryTest.php`

**Interfacce:**
- Usa: `ProductUrl::query()` (compito 5).
- Produce:
  - `OptionQuery::match(array $groups, array $offers, array $query): ?int` — `product_id` della prima offerta disponibile che ha tutti i valori chiesti, altrimenti la prima che li ha, altrimenti `null`;
  - `OptionQuery::wanted(array $groups, array $query): array<string, string>` — `[id del gruppo => id del valore]`;
  - `OptionQuery::slug(string $label): string` — lo slug di un'etichetta (`Blu Notte` → `blu-notte`, entità decodificate).
- Forme: un gruppo è `['id' => int, 'slug' => string, 'values' => list<['id' => string, 'label' => string, 'slug'?: string]>]`; un'offerta è `['product_id' => int, 'available' => bool, 'attributes' => ['<id gruppo>' => '<id valore>']]`, come in `ProductCatalog`.

- [ ] **Passo 1: scrivi il test che fallisce**

`tests/OptionQueryTest.php`:

```php
<?php
/** php tests/OptionQueryTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Catalog\OptionQuery;

$gruppi = [
    ['id' => 3, 'slug' => 'taglia', 'values' => [['id' => '31', 'label' => 'S'], ['id' => '32', 'label' => 'M']]],
    ['id' => 4, 'slug' => 'materiale', 'values' => [['id' => '41', 'label' => 'Cotone'], ['id' => '42', 'label' => 'Lino']]],
];
$offerte = [
    ['product_id' => 101, 'available' => true, 'attributes' => ['3' => '31', '4' => '41']],
    ['product_id' => 102, 'available' => false, 'attributes' => ['3' => '31', '4' => '42']],
    ['product_id' => 103, 'available' => false, 'attributes' => ['3' => '32', '4' => '41']],
    ['product_id' => 104, 'available' => true, 'attributes' => ['3' => '32', '4' => '42']],
];

check('la query completa sceglie quell\'opzione, anche se non è disponibile', fn () =>
    OptionQuery::match($gruppi, $offerte, ['taglia' => 's', 'materiale' => 'lino']) === 102
);

check('la query parziale sceglie la prima disponibile che combacia', fn () =>
    OptionQuery::match($gruppi, $offerte, ['taglia' => 'm']) === 104
);

check('chiavi e valori sconosciuti si ignorano', fn () =>
    OptionQuery::match($gruppi, $offerte, ['colore' => 'blu']) === null
    && OptionQuery::match($gruppi, $offerte, ['taglia' => 's', 'colore' => 'blu']) === 101
    && OptionQuery::match($gruppi, $offerte, ['taglia' => 'xl']) === null
    && OptionQuery::match($gruppi, $offerte, []) === null
);

check('una combinazione che non esiste non sceglie niente', fn () =>
    OptionQuery::match($gruppi, [$offerte[0]], ['taglia' => 'm']) === null
);

check('si accetta l\'id del valore al posto dello slug', fn () =>
    OptionQuery::match($gruppi, $offerte, ['taglia' => '32', 'materiale' => '41']) === 103
);

check('maiuscole, liste e valori vuoti come li scrive una persona', fn () =>
    OptionQuery::match($gruppi, $offerte, ['Taglia' => 'S']) === 101
    && OptionQuery::match($gruppi, $offerte, ['taglia' => ['m', 's']]) === 104
    && OptionQuery::match($gruppi, $offerte, ['taglia' => '']) === null
);

check('lo slug dell\'etichetta è quello degli altri slug, entità comprese', fn () =>
    OptionQuery::slug('Blu Notte') === 'blu-notte'
    && OptionQuery::slug('Novit&agrave;') === 'novita'
    && OptionQuery::slug('XL_2') === 'xl-2'
);

summary();
```

- [ ] **Passo 2: lancia il test e guardalo fallire**

Run: `php tests/OptionQueryTest.php`
Atteso: errore `Class "…OptionQuery" not found`.

- [ ] **Passo 3: scrivi il codice**

`src/Frontend/Catalog/OptionQuery.php`:

```php
<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Catalog;

use Wonder\Support\Text\Slug as TextSlug;

/**
 * Quale opzione è già scelta quando la scheda si apre con
 * `?taglia=s&materiale=cotone`. La chiave è lo slug dell'attributo, il valore
 * lo slug dell'etichetta oppure l'id del valore.
 *
 * Pura: confronta la query con i gruppi e le offerte che la scheda ha già
 * letto, e la query non arriva mai in SQL. Quello che non combacia si ignora.
 */
final class OptionQuery
{
    /**
     * @param list<array<string, mixed>> $groups
     * @param list<array<string, mixed>> $offers
     * @param array<mixed> $query
     */
    public static function match(array $groups, array $offers, array $query): ?int
    {
        $wanted = self::wanted($groups, $query);

        if ($wanted === []) {
            return null;
        }

        $first = null;

        foreach ($offers as $offer) {
            $attributes = (array) ($offer['attributes'] ?? []);

            foreach ($wanted as $groupId => $valueId) {
                if ((string) ($attributes[$groupId] ?? '') !== $valueId) {
                    continue 2;
                }
            }

            if (!empty($offer['available'])) {
                return (int) $offer['product_id'];
            }

            $first ??= (int) $offer['product_id'];
        }

        return $first;
    }

    /**
     * @param list<array<string, mixed>> $groups
     * @param array<mixed> $query
     * @return array<string, string> [id del gruppo => id del valore]
     */
    public static function wanted(array $groups, array $query): array
    {
        $query = array_change_key_case(ProductUrl::query($query), CASE_LOWER);
        $wanted = [];

        foreach ($groups as $group) {
            $key = strtolower(trim((string) ($group['slug'] ?? '')));

            if ($key === '' || !isset($query[$key])) {
                continue;
            }

            $asked = strtolower($query[$key]);

            foreach ((array) ($group['values'] ?? []) as $value) {
                $id = (string) ($value['id'] ?? '');
                $slug = (string) ($value['slug'] ?? self::slug((string) ($value['label'] ?? '')));

                if ($asked === strtolower($id) || ($slug !== '' && $asked === $slug)) {
                    $wanted[(string) $group['id']] = $id;
                    break;
                }
            }
        }

        return $wanted;
    }

    /** `Blu Notte` → `blu-notte`: la stessa regola degli slug del gestionale. */
    public static function slug(string $label): string
    {
        $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $slug = str_replace('_', '-', TextSlug::make($label));

        return trim((string) preg_replace('/-+/', '-', $slug), '-');
    }
}
```

- [ ] **Passo 4: lancia il test**

Run: `php tests/OptionQueryTest.php`
Atteso: tutti verdi.

- [ ] **Passo 5: commit**

```bash
git add src/Frontend/Catalog/OptionQuery.php tests/OptionQueryTest.php
git commit -m "OptionQuery: la query della scheda sceglie l'opzione"
```

---

### Compito 7: `ProductDetail` accetta l'opzione preferita

**File:**
- Modifica: `src/Frontend/Catalog/ProductDetail.php:189-197`
- Test: `tests/ProductPageTest.php` (prima di `summary()`)

**Interfacce:**
- Produce: chiave d'ingresso `'preferred_product_id' => int` in `ProductDetail::make()`. Se è un'offerta della scheda diventa `selected_product_id`; altrimenti vale la regola di oggi (prima disponibile, poi prima).

- [ ] **Passo 1: scrivi il test che fallisce**

In `tests/ProductPageTest.php`, prima di `summary()`:

```php
check('l\'opzione chiesta dalla query vince sulla prima disponibile, se esiste', function () {
    $scheda = static fn (int $preferita): int => ProductDetail::make([
        'id' => 1,
        'name' => 'Maglietta',
        'url' => 'https://shop.test/prodotto/maglietta/',
        'stock_managed' => true,
        'offers' => [
            ['product_id' => 42, 'item_id' => '42', 'name' => 'S', 'sku' => 'M-S', 'regular_price' => 10, 'stock_managed' => true, 'available' => true],
            ['product_id' => 43, 'item_id' => '43', 'name' => 'M', 'sku' => 'M-M', 'regular_price' => 10, 'stock_managed' => true, 'available' => false],
        ],
        'preferred_product_id' => $preferita,
    ])->data()['selected_product_id'];

    return $scheda(43) === 43 && $scheda(99) === 42 && $scheda(0) === 42;
});
```

- [ ] **Passo 2: lancia il test e guardalo fallire**

Run: `php tests/ProductPageTest.php`
Atteso: fallisce solo il check nuovo (`$scheda(43)` dà 42).

- [ ] **Passo 3: scrivi il codice**

In `ProductDetail.php` sostituisci la scelta di `$selected` (il ciclo sulle offerte disponibili, righe ~189-196; il `$selected ??= $offers[0] ?? [...]` che segue resta):

```php
        // L'opzione chiesta dalla query, se è una di queste; poi la prima disponibile.
        $selected = null;
        $preferred = (int) ($row['preferred_product_id'] ?? 0);
        foreach ($offers as $offer) {
            if ($preferred > 0 && $offer['product_id'] === $preferred) {
                $selected = $offer;
                break;
            }
        }
        if ($selected === null) {
            foreach ($offers as $offer) {
                if ($offer['available']) {
                    $selected = $offer;
                    break;
                }
            }
        }
```

- [ ] **Passo 4: lancia i test**

Run: `php tests/ProductPageTest.php`
Atteso: tutti verdi.

- [ ] **Passo 5: commit**

```bash
git add src/Frontend/Catalog/ProductDetail.php tests/ProductPageTest.php
git commit -m "Scheda: l'opzione preferita diventa quella scelta"
```

---

### Compito 8: indirizzi annidati nel catalogo, nella route e negli elenchi

**File:**
- Modifica: `src/Frontend/Catalog/ProductCatalog.php` (`find` → `resolve` + `build`, `optionGroups`, via `productUrl`)
- Modifica: `src/Frontend/Catalog/ProductController.php` (`show`)
- Modifica: `http/frontend/product.php`
- Modifica: `config/routes/route.frontend.php:34-37`
- Modifica: `src/Frontend/Catalog/ProductListing.php` (`card`, via `productUrl`)
- Test: `tests/ProductPageTest.php`

**Interfacce:**
- Usa: `ProductUrl::make()`, `ProductUrl::variantSlugFor()` (compito 5), `OptionQuery::match()`, `OptionQuery::slug()` (compito 6), `'preferred_product_id'` (compito 7).
- Produce:
  - `ProductCatalog::resolve(string $modelSlug, string $variantSlug = '', array $query = []): array{detail: ?ProductDetail, redirect: ?string}`;
  - `ProductCatalog::find(string $slug): ?ProductDetail` (solo lo slug del modello);
  - `ProductController::show(string $slug, string $variante = ''): void`;
  - route `ecommerce.catalog.product.variant`;
  - nei gruppi delle opzioni ogni valore ha anche `'slug'`.

Il dietro le quinte (resolve, 301, 404) si prova sul sito nel compito 10; qui si fissano i contratti nel testo, come fa già `ProductPageTest`.

- [ ] **Passo 1: scrivi il test che fallisce**

In `tests/ProductPageTest.php`, prima di `summary()`:

```php
check('la variante ha la sua route, il controller rimanda con un 301, gli indirizzi hanno un solo costruttore', function () {
    $root = dirname(__DIR__);
    $route = (string) file_get_contents($root.'/config/routes/route.frontend.php');
    $controller = (string) file_get_contents($root.'/src/Frontend/Catalog/ProductController.php');
    $handler = (string) file_get_contents($root.'/http/frontend/product.php');
    $catalog = (string) file_get_contents($root.'/src/Frontend/Catalog/ProductCatalog.php');
    $listing = (string) file_get_contents($root.'/src/Frontend/Catalog/ProductListing.php');

    return str_contains($route, "'/prodotto/{slug}/{variante}/'")
        && str_contains($route, "name('ecommerce.catalog.product.variant')")
        && str_contains($controller, 'ProductCatalog::resolve(')
        && str_contains($controller, ', 301)')
        && str_contains($handler, "\$ROUTE_PARAMETERS['variante']")
        && !str_contains($catalog, 'function productUrl')
        && !str_contains($listing, 'function productUrl')
        && str_contains($catalog, 'OptionQuery::match(');
});
```

- [ ] **Passo 2: lancia il test e guardalo fallire**

Run: `php tests/ProductPageTest.php`
Atteso: fallisce solo il check nuovo.

- [ ] **Passo 3: route e handler**

`config/routes/route.frontend.php`, subito dopo la route `ecommerce.catalog.product`:

```php
        Route::get(
            '/prodotto/{slug}/{variante}/',
            Ecommerce::handlerPath('frontend/product.php')
        )->name('ecommerce.catalog.product.variant');
```

`http/frontend/product.php`:

```php
<?php

use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductController;

ProductController::show(
    trim((string) ($ROUTE_PARAMETERS['slug'] ?? '')),
    trim((string) ($ROUTE_PARAMETERS['variante'] ?? ''))
);
```

- [ ] **Passo 4: controller**

In `ProductController.php` sostituisci l'inizio di `show()` fino al 404 compreso:

```php
    public static function show(string $slug, string $variante = ''): void
    {
        $resolved = ProductCatalog::resolve($slug, $variante, $_GET);

        if ($resolved['redirect'] !== null) {
            header('Location: '.$resolved['redirect'], true, 301);
            exit;
        }

        $product = $resolved['detail'];

        if ($product === null) {
            http_response_code(404);
            exit;
        }
```

Il resto del metodo non cambia.

- [ ] **Passo 5: `ProductCatalog` — `resolve` e `find`**

Sostituisci la parte di `find()` che va dall'inizio fino a `$variantId = (int) $variant['id'];` esclusa con:

```php
    /**
     * La scheda di `/prodotto/{modello}/` o `/prodotto/{modello}/{variante}/`,
     * oppure dove rimandare. Tutti e due null: il modello non c'è, è un 404.
     *
     * @param array<mixed> $query la query della richiesta: sceglie l'opzione
     * @return array{detail: ?ProductDetail, redirect: ?string}
     */
    public static function resolve(string $modelSlug, string $variantSlug = '', array $query = []): array
    {
        $none = ['detail' => null, 'redirect' => null];
        $modelSlug = trim($modelSlug);
        $variantSlug = trim($variantSlug);

        if ($modelSlug === '') {
            return $none;
        }

        $model = ProductModel::find([
            'slug' => $modelSlug,
            'visible' => 'true',
            'visible_online' => 'true',
            'deleted' => 'false',
        ], 1);

        if (!is_array($model) || (int) ($model['id'] ?? 0) <= 0) {
            return $none;
        }

        $variants = self::rows(ProductVariant::find(
            ['product_model_id' => (int) $model['id'], 'visible' => 'true', 'deleted' => 'false'],
            null,
            'position',
            'ASC'
        ));
        $variant = $variants[0] ?? null;

        if ($variantSlug !== '') {
            $variant = null;

            foreach ($variants as $row) {
                if ((string) ($row['slug'] ?? '') === $variantSlug) {
                    $variant = $row;
                    break;
                }
            }

            // Variante sconosciuta o nascosta, o modello con una sola
            // variante: l'indirizzo giusto è quello del modello.
            if ($variant === null || count($variants) < 2) {
                return ['detail' => null, 'redirect' => ProductUrl::make($modelSlug, '', $query)];
            }
        }

        if (!is_array($variant) || (int) ($variant['id'] ?? 0) <= 0) {
            return $none;
        }

        return ['detail' => self::build($model, $variant, $variants, $query), 'redirect' => null];
    }

    /** Solo la scheda del modello, senza rimandi. */
    public static function find(string $slug): ?ProductDetail
    {
        return self::resolve($slug)['detail'];
    }

    /**
     * @param list<array<string, mixed>> $variants le varianti visibili, per posizione
     * @param array<mixed> $query
     */
    private static function build(array $model, array $variant, array $variants, array $query): ?ProductDetail
    {
        $modelId = (int) $model['id'];
```

Il resto del vecchio corpo, da `$variantId = (int) $variant['id'];` in giù, diventa il corpo di `build()` così com'è, salvo queste righe:

- subito dopo `$variantId = …` aggiungi `$visible = count($variants);` e `$modelSlug = (string) ($model['slug'] ?? '');`;
- `$url = self::absolute(ProductUrl::make($modelSlug, ProductUrl::variantSlugFor($variant, $visible)));`
- nelle `$variantCards`: `'url' => ProductUrl::make($modelSlug, ProductUrl::variantSlugFor($row, $visible)),`
- in `ProductDetail::make([...])`:
  - `'slug' => ProductUrl::variantSlugFor($variant, $visible) ?: $modelSlug,`
  - aggiungi `'preferred_product_id' => OptionQuery::match($optionGroups, $offers, $query) ?? 0,`

Togli il metodo privato `productUrl()`.

In `optionGroups()` il valore prende anche lo slug:

```php
                $values[$key] = ['id' => $key, 'label' => $label, 'slug' => OptionQuery::slug($label)] + $visual;
```

- [ ] **Passo 6: `ProductListing::card`**

Nelle `$variantCards`:

```php
                'url' => ProductUrl::make((string) ($model['slug'] ?? ''), ProductUrl::variantSlugFor($variant, count($variants))),
```

Nel `return` della card (il modello porta al modello; la card di una variante mostrata a parte porta alla variante):

```php
            'url' => ProductUrl::make(
                (string) ($model['slug'] ?? ''),
                is_array($selectedVariant) ? ProductUrl::variantSlugFor($selectedVariant, count($variants)) : ''
            ),
```

Togli il metodo privato `productUrl()`.

- [ ] **Passo 7: lancia i test**

Run: `php -l` su ognuno dei cinque file toccati, poi `php tests/run.php`.
Atteso: nessun errore di sintassi; i test unitari verdi. Gli integrativi girano sul sito, che punta ancora ai checkout principali: si guardano nel compito 10.

- [ ] **Passo 8: commit**

```bash
git add src/Frontend/Catalog/ProductCatalog.php src/Frontend/Catalog/ProductController.php src/Frontend/Catalog/ProductListing.php http/frontend/product.php config/routes/route.frontend.php tests/ProductPageTest.php
git commit -m "Schede su /prodotto/modello/variante/, con 301 e opzione dalla query"
```

---

### Compito 9: la barra segue l'opzione scelta

**File:**
- Modifica: `view/pages/frontend/product.php` (script in fondo, righe ~225-271)
- Test: `tests/ProductPageTest.php`

**Interfacce:**
- Usa: `'slug'` dei gruppi e dei valori in `option_groups` (compito 8).

- [ ] **Passo 1: scrivi il test che fallisce**

In `tests/ProductPageTest.php`, prima di `summary()`:

```php
check('il cambio d\'opzione riscrive la query senza aggiungere cronologia', function () {
    $view = (string) file_get_contents(dirname(__DIR__).'/view/pages/frontend/product.php');

    return str_contains($view, 'history.replaceState(')
        && !str_contains($view, 'history.pushState(')
        && str_contains($view, 'remember()');
});
```

- [ ] **Passo 2: lancia il test e guardalo fallire**

Run: `php tests/ProductPageTest.php`
Atteso: fallisce solo il check nuovo.

- [ ] **Passo 3: scrivi il codice**

Subito dopo `<?php if (count($data['offers']) > 1 && $data['option_groups'] !== []): ?>`:

```php
<?php
$optionSlugs = [];
foreach ($data['option_groups'] as $group) {
    $optionSlugs[(string) ($group['id'] ?? '')] = [
        'slug' => (string) ($group['slug'] ?? ''),
        'values' => array_column((array) ($group['values'] ?? []), 'slug', 'id'),
    ];
}
?>
```

Nello script, dopo `const offers = …;`:

```js
    const slugs = <?=json_encode($optionSlugs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_FORCE_OBJECT)?>;
    // Solo dopo una scelta del cliente: all'apertura la barra resta com'è.
    const remember = function () {
        const url = new URL(window.location.href);
        Object.entries(slugs).forEach(([id, group]) => {
            if (!group.slug) return;
            const value = selected[id] ? (group.values[selected[id]] || selected[id]) : '';
            if (value) url.searchParams.set(group.slug, value);
            else url.searchParams.delete(group.slug);
        });
        history.replaceState(history.state, '', url.pathname + url.search + url.hash);
    };
```

E i due ascolti in fondo diventano:

```js
    form.querySelectorAll('select').forEach(select => select.addEventListener('change', function () {
        sync();
        remember();
    }));
    form.querySelectorAll('[data-option-value]').forEach(button => button.addEventListener('click', function () {
        selected[button.closest('[data-option-group]').dataset.optionGroup] = button.dataset.optionValue;
        sync();
        remember();
    }));
```

Il `sync()` iniziale resta senza `remember()`.

- [ ] **Passo 4: lancia i test**

Run: `php -l view/pages/frontend/product.php`, poi `php tests/run.php`.
Atteso: verdi (gli integrativi come nel compito 8).

- [ ] **Passo 5: commit**

```bash
git add view/pages/frontend/product.php tests/ProductPageTest.php
git commit -m "Scheda: la query segue l'opzione scelta, con replaceState"
```

---

## Compito 10: prova sul sito, browser, unione

**File:**
- Crea (ecommerce): `tests/integrazione/ProductUrlHttpTest.php`
- Modifica (gestionale): `TODO.md`

- [ ] **Passo 1: il sito usa i due worktree**

```bash
ln -sfn /Users/andreamarinoni/Developer/packages/gestionale/.claude/worktrees/lista-disponibilita /Users/andreamarinoni/Developer/boilerplates/ecommerce-site/vendor/wonder-image/gestionale
```
```bash
ln -sfn /Users/andreamarinoni/Developer/packages/ecommerce/.claude/worktrees/slug-catalogo /Users/andreamarinoni/Developer/boilerplates/ecommerce-site/vendor/wonder-image/ecommerce
```

Se l'isolamento della sessione rifiuta i comandi, passali all'utente.

- [ ] **Passo 2: rifai gli slug del sito di prova**

```bash
php /Users/andreamarinoni/Developer/boilerplates/ecommerce-site/forge gestionale:variant-slugs --dry-run
```
Atteso: l'elenco dei cambi, `Non ho scritto niente`. Poi senza `--dry-run`, e una seconda volta: atteso `Slug cambiati: 0.`

- [ ] **Passo 3: scrivi il test HTTP**

`tests/integrazione/ProductUrlHttpTest.php` (ecommerce):

```php
<?php
/** php tests/integrazione/ProductUrlHttpTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Catalog\ProductCatalog;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;

$request = static function (string $path): array {
    $curl = curl_init('https://ecommerce.test'.$path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
    $response = curl_exec($curl);
    if (!is_string($response)) throw new RuntimeException(curl_error($curl));
    $size = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    preg_match('~^location:\s*(\S+)~mi', substr($response, 0, $size), $location);
    return [(int) curl_getinfo($curl, CURLINFO_HTTP_CODE), $location[1] ?? '', substr($response, $size)];
};
$canonical = static function (string $html): string {
    preg_match('~<link rel="canonical" href="([^"]*)"~', $html, $match);
    return html_entity_decode($match[1] ?? '');
};
$rows = static fn (mixed $rows): array => !is_array($rows) || $rows === []
    ? []
    : (array_is_list($rows) ? array_values(array_filter($rows, 'is_array')) : [$rows]);

// Dal catalogo di prova: un modello con più varianti visibili con slug e
// opzioni, e uno con una variante sola.
$conPiu = null;
$conUna = null;
foreach ($rows(ProductModel::find(['visible' => 'true', 'visible_online' => 'true', 'deleted' => 'false'])) as $modello) {
    $slug = (string) ($modello['slug'] ?? '');
    $varianti = $rows(ProductVariant::find(
        ['product_model_id' => (int) $modello['id'], 'visible' => 'true', 'deleted' => 'false'], null, 'position', 'ASC'
    ));
    $scheda = $slug !== '' ? ProductCatalog::find($slug) : null;
    if ($scheda === null) continue;

    if ($conPiu === null && count($varianti) > 1 && ($varianti[0]['slug'] ?? '') !== '' && ($varianti[1]['slug'] ?? '') !== ''
        && count($scheda->data()['offers']) > 1 && $scheda->data()['option_groups'] !== []) {
        $conPiu = ['slug' => $slug, 'prima' => (string) $varianti[0]['slug'], 'seconda' => (string) $varianti[1]['slug']];
    }
    if ($conUna === null && count($varianti) === 1) {
        $conUna = ['slug' => $slug, 'variante' => (string) ($varianti[0]['slug'] ?? '') ?: 'variante'];
    }
}

if ($conPiu === null || $conUna === null) {
    check('il catalogo di prova ha i modelli che servono (lancia gestionale:demo e gestionale:variant-slugs)', fn () => false);
    summary();
}

$base = 'https://ecommerce.test/prodotto/';

check('il modello con più varianti mostra la prima e la dichiara canonical', function () use ($request, $canonical, $conPiu, $base) {
    [$status, , $html] = $request('/prodotto/'.$conPiu['slug'].'/');
    return $status === 200 && $canonical($html) === $base.$conPiu['slug'].'/'.$conPiu['prima'].'/';
});

check('ogni variante è canonical di sé stessa, e la query non entra nel canonical', function () use ($request, $canonical, $conPiu, $base) {
    [$status, , $html] = $request('/prodotto/'.$conPiu['slug'].'/'.$conPiu['seconda'].'/');
    [$conQuery, , $htmlQuery] = $request('/prodotto/'.$conPiu['slug'].'/'.$conPiu['seconda'].'/?taglia=zz&x=1');
    $atteso = $base.$conPiu['slug'].'/'.$conPiu['seconda'].'/';
    return $status === 200 && $canonical($html) === $atteso && $conQuery === 200 && $canonical($htmlQuery) === $atteso;
});

check('il modello con una variante sola ha un solo indirizzo', function () use ($request, $conUna) {
    [$status, $location] = $request('/prodotto/'.$conUna['slug'].'/'.$conUna['variante'].'/');
    return $status === 301 && str_ends_with($location, '/prodotto/'.$conUna['slug'].'/');
});

check('la variante sconosciuta rimanda al modello e tiene la query', function () use ($request, $conPiu) {
    [$status, $location] = $request('/prodotto/'.$conPiu['slug'].'/non-esiste/?utm_source=prova');
    return $status === 301 && str_ends_with($location, '/prodotto/'.$conPiu['slug'].'/?utm_source=prova');
});

check('il modello sconosciuto è un 404, con o senza variante', function () use ($request) {
    return $request('/prodotto/non-esiste-davvero/')[0] === 404
        && $request('/prodotto/non-esiste-davvero/blu/')[0] === 404;
});

check('la query sceglie l\'opzione', function () use ($conPiu) {
    $data = ProductCatalog::find($conPiu['slug'])->data();
    $offerta = $data['offers'][array_key_last($data['offers'])];
    $query = [];
    foreach ($data['option_groups'] as $gruppo) {
        foreach ($gruppo['values'] as $valore) {
            if ((string) $valore['id'] === (string) ($offerta['attributes'][(string) $gruppo['id']] ?? '')) {
                $query[$gruppo['slug']] = $valore['slug'];
            }
        }
    }
    $scelta = ProductCatalog::resolve($conPiu['slug'], '', $query)['detail']->data()['selected_product_id'];
    return $query !== [] && $scelta === $offerta['product_id'];
});

summary();
```

- [ ] **Passo 4: lancia le due suite**

Run (ecommerce): `php tests/integrazione/ProductUrlHttpTest.php`, poi `php tests/run.php`.
Run (gestionale): `php tests/run.php`.
Atteso: tutto verde. Se un check fallisce, si corregge nel compito che possiede quel codice e si rilancia.

- [ ] **Passo 5: prova nel browser** (https://ecommerce.test)

- elenco `/prodotti/`: le card dei modelli portano a `/prodotto/{modello}/`, i pallini delle varianti a `/prodotto/{modello}/{variante}/`;
- su una scheda con taglie, il cambio d'opzione riscrive `?taglia=…` senza ricaricare, e «Indietro» del browser non ripassa per ogni scelta;
- `/prodotto/{modello}/{variante}/?taglia=…` apre con quella taglia già scelta, prezzo e disponibilità compresi;
- nel backend, «Vedi sul sito» di un modello apre la scheda.

- [ ] **Passo 6: rimetti il sito com'era**

```bash
ln -sfn ../../../../packages/gestionale/ /Users/andreamarinoni/Developer/boilerplates/ecommerce-site/vendor/wonder-image/gestionale
```
```bash
ln -sfn ../../../../packages/ecommerce/ /Users/andreamarinoni/Developer/boilerplates/ecommerce-site/vendor/wonder-image/ecommerce
```

- [ ] **Passo 7: TODO e commit**

In `TODO.md` del gestionale la riga «Slug del catalogo» diventa: fatto sui rami `slug-catalogo` (gestionale ed ecommerce), da unire insieme; sul sito va lanciato `php forge gestionale:variant-slugs`.

```bash
git add TODO.md
git commit -m "TODO: slug del catalogo fatti"
```

Nell'ecommerce:

```bash
git add tests/integrazione/ProductUrlHttpTest.php
git commit -m "Prova HTTP degli indirizzi delle schede"
```

- [ ] **Passo 8: unione**

Le due PR (gestionale ed ecommerce) si aprono e si uniscono insieme, con merge commit, chiedendo prima all'utente. Il `main` dell'ecommerce ha commit locali non spinti (`c51c4c0`): vanno spinti prima della PR, sempre con il suo via.
