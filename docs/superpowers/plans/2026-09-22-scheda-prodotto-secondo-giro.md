# G2c — La scheda prodotto, secondo giro — Piano di lavoro

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Che caricare una maglietta in cinque taglie e tre colori sia una
schermata, un salvataggio e tre prezzi scritti — senza uscire dalla scheda per
creare un colore — e che quello che si spedisce sia un dato completo.

**Architecture:** Niente cambia nel modello dati del catalogo. Nasce
`AttributeValueResource`, che esiste solo per esporre lo store API e fare da
bersaglio al `quickCreate` del core; i gruppi di spunte e quattro select
prendono il "+". La schermata di creazione guadagna un blocco richiudibile con
le opzioni, e il salvataggio non cambia: `afterStore` chiama già
`chosenAxes()` + `Generator::run()`. La griglia delle versioni prende la
colonna del colore e il raggruppamento del core. Una tabella nuova,
`gst_packages`, con la sua paginetta e un campo sulla scheda.

**Tech Stack:** PHP 8.2, `wonder-image/app` **^2.3.0** (raggruppamento del
repeater, Accordion corretto, quick-create FK), harness di test del modulo
(`tests/harness.php`, `php tests/run.php`), MySQL, sito di prova
`boilerplates/ecommerce-site` su `https://ecommerce.test/backend/`.

**Spec:** [2026-09-22-scheda-prodotto-secondo-giro-design.md](../specs/2026-09-22-scheda-prodotto-secondo-giro-design.md)

> **Stato al 2026-09-22.** I task 1-6 sono stati eseguiti, ma non alla lettera:
> dopo ogni prova in pannello sono arrivate correzioni che hanno cambiato il
> disegno, e la spec è stata riscritta di conseguenza (decisioni P14-P25). Quello
> che è stato fatto davvero, e perché, si legge lì e nei commit; le caselle qui
> sotto restano come traccia di partenza. Ancora aperti: la giacenza
> rettificabile dalla scheda di un prodotto che esiste già (§9, da coordinare
> con G2b) e il caricamento di più foto in un colpo solo.

## Global Constraints

- **Dipende da C1 rilasciato.** Il piano parte dopo il tag `v.2.3.0` di
  `wonder-image/app`. Primo passo del Task 1: `composer require
  "wonder-image/app:^2.3.0"` nel modulo e `composer update wonder-image/app` nel
  sito di prova.
- Lingua: **italiano** in etichette, commenti, messaggi ed errori. **Inglese**
  per nomi di classi, metodi, variabili e colonne.
- I rifiuti all'utente si lanciano **solo** con
  `Wonder\Plugin\Gestionale\Support\Errors\UserError`: è l'unico tipo che il
  core trasforma in avviso invece che in pagina 500.
- Form compatti: **tooltip** al posto dei testi d'aiuto; campi correlati
  vicini; niente campi automatici (slug, position) nel form.
- Un metodo che torna un campo si tipizza `Wonder\App\ResourceSchema\Input`,
  mai `FormField`.
- **Un campo non stampato non viene postato**, e `syncRepeaterRelations()`
  cancella le righe che non ritrova: un repeater si dichiara solo quando il suo
  riquadro è visibile.
- `formSchema()` è valutato all'avvio del sito: nei test che creano attributi
  serve `ProductModelResource::forgetCatalogCache()` prima di rileggere.
- I test d'integrazione girano dentro `Transaction::run()` che fa sempre
  rollback: **le cancellazioni di file non sono transazionali**, quindi chi
  tocca foto le salva prima e le rimette dopo.
- La parola "variante" non compare in nessuna etichetta: si usa
  `pageOptionName()` ("Colore", "Gusto"…).

---

### Task 1: `AttributeValueResource` e il "+" sulle opzioni

Il pezzo con l'unica incognita, quindi per primo. Il meccanismo è già
verificato lato core: `QuickCreateController::payload()` toglie solo
`resource`, `quick_label` e `quick_fields` e passa il resto allo store, e il
modal invia con `new FormData(form)`, che include gli input nascosti. Serve
solo che `apiSchema('store')` del bersaglio accetti `attribute_id`.

**Files:**
- Create: `src/Resources/Catalog/AttributeValueResource.php`
- Modify: `src/Resources/Catalog/ProductModelResource.php` (`optionFields()`)
- Modify: `composer.json` (pavimento `^2.3.0`)
- Test: `tests/AttributeValueResourceTest.php`

**Interfaces:**
- Produces: `AttributeValueResource` con `formSchema()` (`attribute_id`,
  `label`), `apiSchema()` che espone `store` con `['attribute_id', 'label']`,
  `navigationSchema()->enabled(false)`, `mutateRequestValues()` che mette
  `position` e `slug`.
- Consumes (Task 2, 4): niente.

- [ ] **Step 1: Alza il pavimento e aggiorna il sito**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && composer require "wonder-image/app:^2.3.0" --no-update && composer update wonder-image/app
```

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && composer update wonder-image/app && php forge update
```

Verifica che il core installato abbia il raggruppamento:

```bash
grep -c "repeaterGroupBy" /Users/andreamarinoni/Developer/boilerplates/ecommerce-site/vendor/wonder-image/app/class/App/ResourceSchema/Inputs/InputRepeater.php
```

Atteso: `1` o più. Se è `0`, C1 non è rilasciato: fermati.

- [ ] **Step 2: Scrivi il test che fallisce**

`tests/AttributeValueResourceTest.php`:

```php
<?php
/** php tests/AttributeValueResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Catalog\AttributeValueResource;

check('lo store API accetta attributo e valore', function () {
    $schema = AttributeValueResource::apiSchema()->toArray();

    return ($schema['enabled'] ?? false) === true
        && in_array('store', (array) ($schema['actions'] ?? []), true)
        && ($schema['fields']['store'] ?? []) === ['attribute_id', 'label'];
});

check('la pagina non sta nel menu', function () {
    return AttributeValueResource::navigationSchema()->toArray()['enabled'] === false;
});

check('il form ha i due campi che servono al modal', function () {
    $chiavi = array_map(
        static fn ($campo) => (string) $campo->name,
        AttributeValueResource::formSchema()
    );

    return $chiavi === ['attribute_id', 'label'];
});

check('alla creazione arrivano posizione e slug', function () {
    $valori = AttributeValueResource::mutateRequestValues(
        ['attribute_id' => '3', 'label' => 'Blu notte'],
        'store'
    );

    return isset($valori['position'])
        && ($valori['slug'] ?? '') !== ''
        && $valori['label'] === 'Blu notte';
});

check('in aggiornamento posizione e slug non si toccano', function () {
    $valori = AttributeValueResource::mutateRequestValues(
        ['label' => 'Blu notte'],
        'update'
    );

    return !isset($valori['position']) && !isset($valori['slug']);
});

summary();
```

> Se `apiSchema()`/`navigationSchema()` non espongono `toArray()`, leggi le
> firme in `vendor/wonder-image/app/class/App/ResourceSchema/ApiSchema.php` e
> adatta le asserzioni: quello che conta è che `store` ci sia con quei due
> campi e che il menu sia spento.

- [ ] **Step 3: Esegui il test e verifica che fallisca**

```bash
php tests/AttributeValueResourceTest.php
```

Atteso: `Class "...AttributeValueResource" not found`.

- [ ] **Step 4: Scrivi la Resource**

`src/Resources/Catalog/AttributeValueResource.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Positions;

/**
 * I valori di un'opzione, come risorsa a sé.
 *
 * Non è una pagina: i valori si riordinano e si cancellano dove stanno da
 * sempre, nel repeater dentro la scheda dell'attributo. Questa classe esiste
 * perché il "+ Aggiungi colore" della scheda prodotto ha bisogno di uno store
 * API a cui parlare, e il quick-create del core lo cerca su una Resource.
 */
final class AttributeValueResource extends GestionaleResource
{
    public static string $model = AttributeValue::class;

    public static function path(): string
    {
        return 'app/gestionale/valori-opzione';
    }

    public static function icon(): string
    {
        return 'bi-palette';
    }

    public static function titleLabel(): string
    {
        return 'Valori delle opzioni';
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('attribute_id')->hidden(),
            FormField::key('label')->text()->label('Valore')->required(),
        ];
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)
            ->only(['store'])
            ->fields('store', ['attribute_id', 'label']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)->enabled(false);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'manager']);
    }

    /** Posizione e slug li mette il pannello, come nel repeater degli attributi. */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        if ($action === 'store') {
            $values['position'] = Positions::next(AttributeValue::$table);
            $values['slug'] = Slug::make((string) ($values['label'] ?? ''), AttributeValue::$table);
        } else {
            unset($values['position'], $values['slug']);
        }

        return $values;
    }
}
```

> Controlla in `src/Resources/Catalog/AttributeResource.php` come vengono
> scritti `position` e `slug` per le righe del repeater e usa **le stesse**
> chiamate: se lì lo slug non esiste, toglilo anche qui e dal test.

- [ ] **Step 5: Esegui il test e verifica che passi**

```bash
php tests/AttributeValueResourceTest.php
```

Atteso: `5 test, 0 falliti`.

- [ ] **Step 6: Metti il "+" sui gruppi di opzioni**

In `ProductModelResource::optionFields()`:

```php
    public static function optionFields(): array
    {
        $fields = [];

        foreach (static::optionAttributes() as $attribute) {
            $id = (int) $attribute['id'];
            $name = (string) ($attribute['name'] ?? '');

            $fields[] = FormField::key('option_'.$id)
                ->checkbox()
                ->options(static::valuesOf($id))
                ->label($name)
                // Il valore nuovo entra nell'anagrafica condivisa, non in
                // questo prodotto: per questo il bottone dice "aggiungi
                // colore" e non "aggiungi colore a questo articolo".
                ->quickCreate(
                    AttributeValueResource::class,
                    label: 'label',
                    layout: fn () => (new Form)->components([
                        (new Container)->components([
                            FormField::key('attribute_id')->hidden()->value((string) $id),
                            AttributeValueResource::getInput('label'),
                        ])->columns(12)->columnSpan(12),
                    ])->columns(12),
                );
        }

        return $fields;
    }
```

Aggiungi in testa al file gli `use` che mancano (`Wonder\Elements\Form\Form`,
`Wonder\Elements\Components\Container` — controlla quelli già presenti) e
l'`use` di `AttributeValueResource`.

- [ ] **Step 7: Prova nel browser**

Apri `https://ecommerce.test/backend/app/gestionale/prodotti/` → un prodotto →
riquadro delle opzioni. Verifica:

1. accanto a "Colore" c'è il "+";
2. il modal ha **una** casella, *Valore*;
3. salvando "Blu notte" l'opzione compare **spuntata** nel gruppo;
4. in `Attributi → Colore` il valore c'è, **una volta sola**, in fondo;
5. salvando il prodotto nasce la versione del colore nuovo.

Se al punto 3 lo store rifiuta, leggi l'alert del modal: quasi certamente
`attribute_id` non è fra i campi di `apiSchema('store')`.

- [ ] **Step 8: Commit**

```bash
git add src/Resources/Catalog/AttributeValueResource.php src/Resources/Catalog/ProductModelResource.php tests/AttributeValueResourceTest.php composer.json composer.lock
git commit -m "Options: create a missing value without leaving the card"
```

---

### Task 2: Il "+" sugli altri quattro select

**Files:**
- Modify: `src/Resources/Catalog/ProductModelResource.php` (`formSchema()`)
- Modify: `src/Resources/Tax/TaxCategoryResource.php` (`apiSchema()`)
- Modify: `src/Resources/Catalog/BrandResource.php`, `CategoryResource.php`
  (`apiSchema()`, solo se oggi è spento)
- Test: `tests/QuickCreateTargetsTest.php`

**Interfaces:**
- Consumes: `AttributeValueResource` (Task 1) come modello di `apiSchema()`.
- Produces: `brand_id`, `main_category`, `tax_category_id` con `quickCreate`.
  (`package_id` arriva nel Task 6.)

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/QuickCreateTargetsTest.php`:

```php
<?php
/** php tests/QuickCreateTargetsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Catalog\BrandResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\CategoryResource;
use Wonder\Plugin\Gestionale\Resources\Tax\TaxCategoryResource;

$store = static function (string $resource): array {
    $schema = $resource::apiSchema()->toArray();

    return [
        'enabled' => (bool) ($schema['enabled'] ?? false),
        'actions' => (array) ($schema['actions'] ?? []),
        'fields' => (array) ($schema['fields']['store'] ?? []),
    ];
};

foreach ([
    'marchi' => BrandResource::class,
    'categorie' => CategoryResource::class,
    'tipi fiscali' => TaxCategoryResource::class,
] as $nome => $resource) {
    check("lo store API dei {$nome} è aperto al quick-create", function () use ($store, $resource) {
        $schema = $store($resource);

        return $schema['enabled']
            && in_array('store', $schema['actions'], true)
            && in_array('name', $schema['fields'], true);
    });
}

summary();
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

```bash
php tests/QuickCreateTargetsTest.php
```

Atteso: almeno il tipo fiscale fallisce (`apiSchema()->enabled(false)`).

- [ ] **Step 3: Apri lo store sui bersagli**

In `src/Resources/Tax/TaxCategoryResource.php` (e, se spenti, nei due del
catalogo):

```php
    /**
     * Solo `store`, e solo per il "+ Aggiungi" della scheda prodotto: la
     * chiamata parte lato server come `@system`, il bottone lo vede solo chi
     * può creare questa risorsa.
     */
    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)
            ->only(['store'])
            ->fields('store', ['name']);
    }
```

- [ ] **Step 4: Esegui il test e verifica che passi**

```bash
php tests/QuickCreateTargetsTest.php
```

Atteso: `3 test, 0 falliti`.

- [ ] **Step 5: Metti il "+" sui campi**

In `ProductModelResource::formSchema()`:

```php
            FormField::key('brand_id')
                ->select(static::brandOptions())
                ->label('Marchio')
                ->quickCreate(BrandResource::class),
            FormField::key('tax_category_id')
                ->select(static::taxCategoryOptions())
                ->label('Tipo fiscale')
                ->required()
                ->quickCreate(TaxCategoryResource::class),
```

e, più sotto:

```php
            FormField::key('main_category')
                ->select(static::categoryOptions())
                ->label('Categoria principale')
                ->quickCreate(CategoryResource::class),
```

`tags` e `categories` **non** si toccano: sono alberi e ricerche multiple, e
gli adattatori del core non sono ancora stati provati in un sito.

- [ ] **Step 6: Prova nel browser**

Su un prodotto: crea un marchio, una categoria e un tipo fiscale dal "+" e
verifica che ognuno compaia **selezionato** nel suo campo e che salvando resti.
Poi controlla che la categoria nuova esista anche in `Categorie`.

- [ ] **Step 7: Commit**

```bash
git add src/Resources tests/QuickCreateTargetsTest.php
git commit -m "Quick create on brand, category and tax type"
```

---

### Task 3: Le opzioni in creazione, e i blocchi che si chiudono davvero

**Files:**
- Modify: `src/Resources/GestionaleResource.php` (`foldable()`)
- Modify: `src/Resources/Catalog/ProductModelResource.php`
  (`createFields()`, `createLayout()`)
- Test: `tests/FoldableTest.php` (esiste: va aggiornato),
  `tests/ProductModelResourceTest.php` (esiste: due check nuovi)

**Interfaces:**
- Consumes: l'Accordion corretto in C1.
- Produces: `foldable()` torna un `Accordion` (chiuso di default);
  `createFields()` comprende le chiavi `option_<id>`.

- [ ] **Step 1: Aggiorna il test del riquadro richiudibile**

In `tests/FoldableTest.php`, sostituisci le asserzioni che si aspettano una
`Card` con queste:

```php
check('il riquadro che si chiude è un Accordion', function () {
    $riquadro = RiquadroDiProva::apri('Scheda tecnica', []);

    return $riquadro instanceof \Wonder\Elements\Components\Accordion;
});

check('nasce chiuso', function () {
    $riquadro = RiquadroDiProva::apri('Scheda tecnica', []);

    return ($riquadro->getSchema()['expanded'] ?? false) === false;
});

check('tiene la griglia a dodici colonne e tutta la larghezza', function () {
    $riquadro = RiquadroDiProva::apri('Scheda tecnica', []);

    return ($riquadro->columns['default'] ?? null) == 12
        && ($riquadro->columnSpan['default'] ?? null) == 12;
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

```bash
php tests/FoldableTest.php
```

Atteso: il primo check fallisce (oggi torna una `Card`).

- [ ] **Step 3: Riscrivi `foldable()`**

In `src/Resources/GestionaleResource.php`:

```php
    /**
     * Un riquadro che si chiude.
     *
     * Era una Card travestita: l'Accordion del core, dentro un form, non dava
     * al proprio corpo la griglia che la Card mette sul `card-body`, e i campi
     * si schiacciavano in una striscia. Corretto nel core con la v.2.3.0.
     */
    protected static function foldable(string $title, array $components, string $tooltip = ''): object
    {
        $accordion = (new Accordion)
            ->text($title)
            ->columns(12)
            ->columnSpan(12)
            ->components($components);

        return $tooltip === '' ? $accordion : $accordion->description($tooltip);
    }
```

> Verifica in `vendor/wonder-image/app/class/Elements/Components/Accordion.php`
> i nomi veri dei metodi (`text()`, `description()`, `expanded()`): se
> `description()` non esiste, tieni il tooltip su un `SectionTitle` dentro i
> componenti, come adesso.

- [ ] **Step 4: Esegui i test e verifica che passino**

```bash
php tests/FoldableTest.php
```

Atteso: `0 falliti`.

- [ ] **Step 5: Porta le opzioni nella creazione**

In `ProductModelResource`:

```php
    public static function createFields(): array
    {
        $fields = ['name', 'tax_category_id', 'product_price', 'main_category', 'sku'];

        foreach (static::optionFields() as $field) {
            $fields[] = (string) $field->name;
        }

        return $fields;
    }

    /** La schermata di creazione: cinque campi, e le opzioni per chi le usa. */
    protected static function createLayout(): Form
    {
        $base = ['name', 'tax_category_id', 'product_price', 'main_category', 'sku'];

        $campi = [
            SectionTitle::make('Nuovo prodotto')
                ->tooltip('Bastano queste cinque cose. Foto, descrizioni e scheda tecnica si aggiungono subito dopo.')
                ->columnSpan(12),
        ];

        foreach ($base as $chiave) {
            $campi[] = static::getInput($chiave)->columnSpan(6);
        }

        $blocchi = [(new Card)->components($campi)->columns(12)->columnSpan(12)];
        $opzioni = [];

        foreach (static::optionFields() as $field) {
            $opzioni[] = static::getInput((string) $field->name)->columnSpan(6);
        }

        // Chiuso: chi vende un cappello non deve nemmeno incontrarlo, e chi
        // vende magliette arriva alla scheda con le versioni già fatte.
        if ($opzioni !== []) {
            $blocchi[] = static::foldable(
                'Si vende in più versioni? (colori, taglie…)',
                $opzioni,
                'Spunta i colori e le taglie e salva: le versioni nascono da sole, con nome e SKU proposti.'
            );
        }

        return (new Form)->components([
            (new Container)->components($blocchi)->columns(12)->columnSpan(12),
        ])->columns(12);
    }
```

Il salvataggio **non si tocca**: `afterStore()` chiama già `saveExtras()`, che
legge `$_POST` con `chosenAxes()` e passa al `Generator`.

- [ ] **Step 6: Aggiungi i due check alla suite della scheda**

In `tests/ProductModelResourceTest.php`:

```php
check('la creazione conosce anche le opzioni', function () {
    $chiavi = ProductModelResource::createFields();

    return array_slice($chiavi, 0, 5) === ['name', 'tax_category_id', 'product_price', 'main_category', 'sku']
        && count($chiavi) > 5;
});

check('le opzioni della creazione sono quelle della scheda', function () {
    $dalle_opzioni = array_map(
        static fn ($campo) => (string) $campo->name,
        ProductModelResource::optionFields()
    );

    return array_slice(ProductModelResource::createFields(), 5) === $dalle_opzioni;
});
```

- [ ] **Step 7: Esegui i test**

```bash
php tests/FoldableTest.php && php tests/ProductModelResourceTest.php
```

Atteso: due volte `0 falliti`.

- [ ] **Step 8: Prova nel browser**

`Prodotti → Aggiungi prodotto`. Verifica: il blocco è **chiuso**; aprendolo i
campi stanno su due colonne e **non** sono schiacciati; spuntando due colori e
tre taglie e salvando si atterra sulla scheda con **sei** versioni, con nome e
SKU. Poi ripeti senza spuntare niente: nasce un prodotto a versione unica,
come prima.

- [ ] **Step 9: Commit**

```bash
git add src/Resources tests/FoldableTest.php tests/ProductModelResourceTest.php
git commit -m "Create products with their versions in one screen"
```

---

### Task 4: La griglia delle versioni

**Files:**
- Modify: `src/Resources/Catalog/ProductModelResource.php`
  (`productsField()`, la chiamata in `formSchema()`)
- Test: `tests/ProductModelResourceTest.php`

**Interfaces:**
- Consumes: `repeaterGroupBy()`, `repeaterGroupCommand()`,
  `repeaterGroupCountLabel()` (C1); `variantOptions(int $modelId)` che già
  esiste.
- Produces: `productsField(int $modelId): Input`.

- [ ] **Step 1: Scrivi i check che falliscono**

In `tests/ProductModelResourceTest.php`:

```php
check('la griglia mostra il colore quando i colori sono più di uno', function () {
    $colonne = array_map(
        static fn ($colonna) => (string) $colonna->name,
        (array) ProductModelResource::productsGridColumns(2)
    );

    return $colonne === ['id', 'product_variant_id', 'name', 'sku', 'ean', 'price', 'active'];
});

check('con un colore solo la colonna non c\'è', function () {
    $colonne = array_map(
        static fn ($colonna) => (string) $colonna->name,
        (array) ProductModelResource::productsGridColumns(1)
    );

    return $colonne === ['id', 'name', 'sku', 'ean', 'price', 'active'];
});

check('le larghezze stanno in dodici, azioni comprese', function () {
    $totale = 0;

    foreach (ProductModelResource::productsGridColumns(2) as $colonna) {
        $totale += (int) ($colonna->columnSpan['default'] ?? 0);
    }

    // 11 di colonne + 1 di azioni: il riordino è spento, quindi le azioni
    // valgono una colonna sola.
    return $totale === 11;
});
```

- [ ] **Step 2: Esegui e verifica che fallisca**

```bash
php tests/ProductModelResourceTest.php
```

Atteso: `Call to undefined method ...::productsGridColumns()`.

- [ ] **Step 3: Riscrivi il repeater**

```php
    /**
     * Le colonne della griglia delle versioni.
     *
     * Fuori dal repeater perché i conti delle larghezze si possano provare:
     * dodici è il totale, e la colonna delle azioni ne vale una — il riordino
     * è spento di proposito (l'ordine lo decide il generatore, e trascinare la
     * quinta riga sopra la terza non significa niente per chi compra).
     *
     * Lo scontato non c'è: uno sconto su una taglia sola è raro, quello
     * dell'articolo si scrive nella casella in alto. Resta l'EAN, perché i
     * codici a barre si scrivono uno dopo l'altro guardando la griglia.
     *
     * @return list<Input>
     */
    public static function productsGridColumns(int $variantCount, int $modelId = 0): array
    {
        $colonne = [RepeaterColumn::key('id')->hidden()];

        if ($variantCount > 1) {
            $colonne[] = RepeaterColumn::key('product_variant_id')
                ->select(static::variantOptions($modelId))
                ->label(static::pageOptionName())
                ->columnSpan(2);
        }

        $colonne[] = RepeaterColumn::key('name')->text()->label('Versione')
            ->columnSpan($variantCount > 1 ? 2 : 3);
        $colonne[] = RepeaterColumn::key('sku')->text()->label('SKU')->columnSpan(2);
        $colonne[] = RepeaterColumn::key('ean')->text()->label('EAN')->columnSpan(2);
        $colonne[] = RepeaterColumn::key('price')->number()->decimal(2)->label('Prezzo')->columnSpan(2);
        $colonne[] = RepeaterColumn::key('active')
            ->select(['true' => 'Attivo', 'false' => 'Fermo'])
            ->label('Stato')
            ->columnSpan(1);

        return $colonne;
    }

    protected static function productsField(int $modelId): Input
    {
        $varianti = static::variantCount($modelId);

        $field = FormField::key('products')
            ->repeater(static::productsGridColumns($varianti, $modelId))
            ->relation(
                RepeaterRelation::make(Product::$table, 'product_model_id')
                    ->model(Product::class)
                    ->positionKey('position')
            )
            ->nested()
            ->repeaterAddLabel('Aggiungi versione')
            ->repeaterDeleteTitle('Elimina versione')
            ->repeaterDeleteText('Confermi l\'eliminazione di questa versione?')
            ->repeaterDeleteCancelLabel('Annulla')
            ->repeaterDeleteConfirmLabel('Elimina')
            ->repeaterDeleteConfirmClass('btn btn-danger')
            ->label('Quello che si vende');

        if ($varianti > 1) {
            $field->repeaterGroupBy('product_variant_id')
                ->repeaterGroupCommand('price', 'Prezzo del gruppo')
                ->repeaterGroupCountLabel('versione', 'versioni');
        }

        return $field;
    }
```

In `formSchema()`, la chiamata diventa `static::productsField($modelId)`.

- [ ] **Step 4: Esegui i test**

```bash
php tests/ProductModelResourceTest.php
```

Atteso: `0 falliti`.

- [ ] **Step 5: Verifica che lo scontato non si perda**

Questo è il rischio del task: una colonna tolta dal repeater potrebbe essere
azzerata al salvataggio.

Nel sito di prova, su un prodotto con più versioni: metti uno sconto su una
versione dalla sua pagina (`app/gestionale/versioni`), torna alla scheda del
prodotto, **salva senza toccare niente**, e ricontrolla lo sconto.

Se è sparito, dichiara la colonna nascosta in `productsGridColumns()`, prima
di `name`, e rilancia la prova:

```php
        $colonne[] = RepeaterColumn::key('sale_price')->hidden();
```

- [ ] **Step 6: Prova il raggruppamento nel browser**

Su un prodotto con tre colori e quattro taglie: raggruppa per colore, chiudi
due gruppi, scrivi `24,90` nella casella di un gruppo e verifica che cambino
**solo** le righe di quel gruppo, salva, ricarica, e controlla che i prezzi
salvati siano quelli visti e che i decimali siano rimasti (`24,90`, non
`25,00`).

- [ ] **Step 7: Commit**

```bash
git add src/Resources/Catalog/ProductModelResource.php tests/ProductModelResourceTest.php
git commit -m "Versions grid: colour column, grouping and group price"
```

---

### Task 5: Gli imballaggi

**Files:**
- Create: `src/Models/Catalog/Package.php`
- Create: `src/Resources/Catalog/PackageResource.php`
- Modify: `src/Support/Codes.php`
- Modify: il registro dei modelli/risorse del modulo (`src/Module.php` o
  `module.json`: cerca dove sono elencati `ProductModel` e `TaxCategory`)
- Test: `tests/PackagesTest.php`

**Interfaces:**
- Produces: `Package` (tabella `gst_packages`), `PackageResource`
  (`app/gestionale/imballaggi`), `Codes::PACKAGE = 'pkg_'`.
- Consumes (Task 6): `Packages::shippingWeight()` arriva lì.

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/PackagesTest.php`:

```php
<?php
/** php tests/PackagesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Package;
use Wonder\Plugin\Gestionale\Resources\Catalog\PackageResource;
use Wonder\Plugin\Gestionale\Support\Codes;

check('la tabella si chiama gst_packages', fn () => Package::$table === 'gst_packages');

check('le colonne ci sono tutte', function () {
    $chiavi = array_map(
        static fn ($colonna) => (string) $colonna->name,
        Package::tableSchema()
    );

    foreach (['code', 'name', 'length', 'width', 'height', 'weight', 'is_default', 'position', 'active'] as $attesa) {
        if (!in_array($attesa, $chiavi, true)) {
            return false;
        }
    }

    return true;
});

check('il codice ha il suo prefisso', fn () => Codes::PACKAGE === 'pkg_');

check('la pagina sta in Set up e la vede solo admin', function () {
    $navigazione = PackageResource::navigationSchema()->toArray();

    return $navigazione['section'] === 'set-up'
        && $navigazione['title'] === 'Imballaggi';
});

check('lo store API è aperto al quick-create', function () {
    $schema = PackageResource::apiSchema()->toArray();

    return in_array('store', (array) ($schema['actions'] ?? []), true)
        && in_array('name', (array) ($schema['fields']['store'] ?? []), true);
});

summary();
```

> Se `tableSchema()` torna oggetti senza `name` pubblico, leggi come lo fa
> `tests/` per un altro modello del modulo e adatta la lettura.

- [ ] **Step 2: Esegui e verifica che fallisca**

```bash
php tests/PackagesTest.php
```

- [ ] **Step 3: Scrivi il modello**

`src/Models/Catalog/Package.php`, sul calco di `Models/Tax/TaxCategory.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Una scatola del negozio: quanto è grande dentro e quanto pesa vuota.
 *
 * Il peso che parte è prodotto più tara, e la tara è una cosa del negozio, non
 * dell'articolo: due o tre imballaggi buoni descrivono tutto quello che si
 * spedisce. Le misure servono ai corrieri che tariffano a volume (G4).
 */
final class Package extends Model
{
    public static string $table = 'gst_packages';
    public static string $folder = 'gestionale/packages';
    public static string $icon = 'bi bi-box-seam';

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::multiRow()->keepIds()->localOnly();
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('code')->length(100)->null(false)->unique(),
            Column::key('name'),
            Column::key('length')->decimal(10, 2),
            Column::key('width')->decimal(10, 2),
            Column::key('height')->decimal(10, 2),
            Column::key('weight')->decimal(10, 3),
            Column::key('is_default')->enum(['true', 'false'])->default('false'),
            Column::key('position')->int(),
            Column::key('active')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('length')->number()->decimals(2),
            Field::key('width')->number()->decimals(2),
            Field::key('height')->number()->decimals(2),
            Field::key('weight')->number()->decimals(3),
            Field::key('is_default')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
            Field::key('active')->text()->sanitize(false),
        ];
    }
}
```

> Copia da un altro modello del modulo la forma esatta di `decimal()` /
> `decimals()`: se `Column::key()->decimal(10, 2)` non esiste, usa quella che
> usano `ProductModel::tableSchema()` per `weight`.

- [ ] **Step 4: Aggiungi il prefisso del codice**

In `src/Support/Codes.php`, accanto alle costanti del catalogo:

```php
    public const PACKAGE = 'pkg_';
```

- [ ] **Step 5: Scrivi la Resource**

`src/Resources/Catalog/PackageResource.php`, sul calco di
`Resources/Tax/TaxCategoryResource.php`: `path()` →
`app/gestionale/imballaggi`, `icon()` → `bi-box-seam`, `titleLabel()` →
`Imballaggi`.

```php
    public static function formSchema(): array
    {
        return [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('weight')->number()->decimal(3)->label('Peso a vuoto (kg)'),
            FormField::key('length')->number()->decimal(2)->label('Lunghezza (cm)'),
            FormField::key('width')->number()->decimal(2)->label('Larghezza (cm)'),
            FormField::key('height')->number()->decimal(2)->label('Altezza (cm)'),
            FormField::key('is_default')
                ->select(['false' => 'No', 'true' => 'Sì'])
                ->value('false')
                ->label('Predefinito'),
            FormField::key('active')
                ->select(['true' => 'Sì', 'false' => 'No'])
                ->value('true')
                ->label('In uso'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Card)->components([
                SectionTitle::make('Imballaggio')
                    ->tooltip('Il peso a vuoto è la tara: si somma al peso del prodotto per sapere quanto parte davvero.')
                    ->columnSpan(12),
                static::getInput('name')->columnSpan(6),
                static::getInput('weight')->columnSpan(6),
                static::getInput('length')->columnSpan(4),
                static::getInput('width')->columnSpan(4),
                static::getInput('height')->columnSpan(4),
                static::getInput('is_default')->columnSpan(6),
                static::getInput('active')->columnSpan(6),
            ])->columns(12)->columnSpan(12),
        ])->columns(12);
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('name')->label('Nome'),
            Column::key('weight')->label('Tara (kg)'),
            Column::key('is_default')->label('Predefinito'),
            Column::key('active')->label('In uso'),
        ];
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->only(['store'])->fields('store', ['name']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('set-up')
            ->title('Imballaggi')
            ->order(62)
            ->authority(['admin']);
    }

    /** Codice e posizione li mette il pannello; il predefinito è uno solo. */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        if ($action === 'store') {
            $values['code'] = Code::make(Package::class, Codes::PACKAGE);
            $values['position'] = Positions::next(Package::$table);
        } else {
            unset($values['position']);
        }

        return $values;
    }

    /** Un predefinito solo: sceglierne uno nuovo toglie il segno al vecchio. */
    public static function afterStore(object $result, array $values = []): void
    {
        static::keepSingleDefault((int) ($result->insert_id ?? 0), $values);
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        static::keepSingleDefault((int) $id, $values);
    }

    protected static function keepSingleDefault(int $id, array $values): void
    {
        if ($id <= 0 || ($values['is_default'] ?? 'false') !== 'true') {
            return;
        }

        foreach (static::rowsOf(Package::class) as $row) {
            if ((int) $row['id'] !== $id && ($row['is_default'] ?? 'false') === 'true') {
                Package::update(['is_default' => 'false'], (int) $row['id']);
            }
        }
    }
```

- [ ] **Step 6: Registra modello e risorsa**

Trova dove il modulo elenca modelli e risorse (cerca `TaxCategory::class` con
`grep -rn "TaxCategory::class" src/`) e aggiungi `Package` e `PackageResource`
negli stessi posti.

- [ ] **Step 7: Crea la tabella e verifica**

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update
```

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/PackagesTest.php
```

Atteso: `5 test, 0 falliti`. Poi apri
`https://ecommerce.test/backend/app/gestionale/imballaggi/`, crea "Scatola
media" con tara 0,2 e segnala predefinita; creane una seconda predefinita e
verifica che la prima perda il segno.

- [ ] **Step 8: Commit**

```bash
git add src tests/PackagesTest.php
git commit -m "Packages: the boxes the shop ships in"
```

---

### Task 6: Il riquadro Spedizione

**Files:**
- Create: `src/Support/Catalog/Packages.php`
- Modify: `src/Models/Catalog/ProductModel.php` (colonna `package_id`)
- Modify: `src/Resources/Catalog/ProductModelResource.php`
  (`formSchema()`, `sideColumn()`, `mutateFormValues()`, `withoutExtras()`)
- Test: `tests/PackagesTest.php` (si allunga)

**Interfaces:**
- Consumes: `Package` (Task 5).
- Produces: `Packages::shippingWeight(float $product, ?array $package): float`,
  `Packages::options(): array`, `Packages::describe(float $product, ?array $package): string`.

- [ ] **Step 1: Scrivi i check che falliscono**

In `tests/PackagesTest.php`:

```php
use Wonder\Plugin\Gestionale\Support\Catalog\Packages;

check('il peso spedito è prodotto più tara', fn () =>
    Packages::shippingWeight(1.2, ['weight' => '0.2']) === 1.4
);

check('senza imballaggio vale il solo prodotto', fn () =>
    Packages::shippingWeight(1.2, null) === 1.2
    && Packages::shippingWeight(1.2, ['weight' => '']) === 1.2
);

check('senza peso del prodotto resta la sola scatola', fn () =>
    Packages::shippingWeight(0.0, ['weight' => '0.2']) === 0.2
);

check('la frase dice da dove viene il numero', fn () =>
    Packages::describe(1.2, ['weight' => '0.2']) === '1,4 kg — 1,2 di prodotto e 0,2 di scatola'
);

check('senza scatola la frase non la nomina', fn () =>
    Packages::describe(1.2, null) === '1,2 kg — senza imballaggio scelto'
);
```

- [ ] **Step 2: Esegui e verifica che fallisca**

```bash
php tests/PackagesTest.php
```

- [ ] **Step 3: Scrivi la classe pura**

`src/Support/Catalog/Packages.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\Plugin\Gestionale\Models\Catalog\Package;

/**
 * Quello che parte davvero: prodotto più scatola.
 *
 * Il peso del prodotto da solo è il dato sbagliato — nessuno spedisce una
 * maglietta nuda — e chi compila se ne accorge solo se glielo si fa vedere.
 * Per questo la scheda mostra la somma, scritta a parole.
 */
final class Packages
{
    /** @param array<string, mixed>|null $package */
    public static function shippingWeight(float $product, ?array $package): float
    {
        $tare = $package === null ? 0.0 : (float) ($package['weight'] ?? 0);

        return round(max(0.0, $product) + max(0.0, $tare), 3);
    }

    /** @param array<string, mixed>|null $package */
    public static function describe(float $product, ?array $package): string
    {
        $total = self::number(self::shippingWeight($product, $package));

        if ($package === null || (float) ($package['weight'] ?? 0) <= 0.0) {
            return $package === null
                ? $total.' kg — senza imballaggio scelto'
                : $total.' kg — la scatola scelta non ha una tara';
        }

        return $total.' kg — '.self::number($product).' di prodotto e '
            .self::number((float) $package['weight']).' di scatola';
    }

    /** Le scatole in uso, per il select: la vuota vuol dire "predefinita". */
    public static function options(): array
    {
        $options = ['' => 'Imballaggio predefinito'];

        foreach (self::rows() as $row) {
            $options[(string) $row['id']] = (string) ($row['name'] ?? '');
        }

        return $options;
    }

    /** La scatola di un prodotto, o quella predefinita. @return array<string, mixed>|null */
    public static function forModel(int $packageId): ?array
    {
        $default = null;

        foreach (self::rows() as $row) {
            if ((int) $row['id'] === $packageId) {
                return $row;
            }

            if (($row['is_default'] ?? 'false') === 'true') {
                $default = $row;
            }
        }

        return $default;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', ''), '0'), ',');
    }

    /** @return list<array<string, mixed>> */
    private static function rows(): array
    {
        $rows = Package::find(['deleted' => 'false', 'active' => 'true'], null, 'position', 'ASC');

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
```

- [ ] **Step 4: Esegui e verifica che passi**

```bash
php tests/PackagesTest.php
```

Atteso: `10 test, 0 falliti`.

- [ ] **Step 5: Aggiungi la colonna al prodotto**

In `src/Models/Catalog/ProductModel.php`, accanto alle altre colonne intere:

```php
            Column::key('package_id')->int(),
```

e nel `dataSchema()`:

```php
            Field::key('package_id')->number()->decimals(0),
```

- [ ] **Step 6: Il riquadro nella scheda**

In `formSchema()`, accanto a peso e misure:

```php
            FormField::key('package_id')
                ->select(Packages::options())
                ->label('Imballaggio')
                ->quickCreate(PackageResource::class, ['name', 'weight']),
            FormField::key('shipping_weight')->text()->label('Spedito')->readonly(),
```

In `sideColumn()`, al posto del riquadro "Peso e misure":

```php
        $cards = [ /* Pubblicazione, Codici, Dove si trova… come adesso */ ];

        // Un articolo che non si spedisce non ha niente da dire qui.
        if (static::valueOf($modelId, 'requires_shipping') !== 'false') {
            $cards[] = (new Card)->components([
                SectionTitle::make('Spedizione')
                    ->tooltip('Quello che parte è prodotto più scatola. Le misure del prodotto servono solo ai fuori misura, che nella scatola scelta non ci stanno.')
                    ->columnSpan(12),
                static::getInput('package_id')->columnSpan(12),
                static::getInput('weight')->columnSpan(6),
                static::getInput('shipping_weight')->columnSpan(6),
                static::getInput('unit')->columnSpan(12),
                static::getInput('length')->columnSpan(4),
                static::getInput('width')->columnSpan(4),
                static::getInput('height')->columnSpan(4),
            ])->columns(12)->columnSpan(12);
        }

        return $cards;
```

> `valueOf()` non esiste: aggiungilo come helper protetto che legge una
> colonna del modello corrente con `rowsOf(ProductModel::class, ['id' => $modelId])`,
> oppure riusa un lettore già presente nella classe.

In `mutateFormValues()`, accanto agli altri riempimenti:

```php
        $values['shipping_weight'] = Packages::describe(
            (float) ($values['weight'] ?? 0),
            Packages::forModel((int) ($values['package_id'] ?? 0))
        );
```

E in `withoutExtras()` aggiungi `shipping_weight` alle chiavi da togliere: è
una frase da leggere, non una colonna.

- [ ] **Step 7: Prova nel browser**

Su un prodotto: scegli "Scatola media", metti peso 1,2 e salva. Atteso: la
riga *Spedito* dice `1,4 kg — 1,2 di prodotto e 0,2 di scatola`. Crea una
scatola dal "+" e verifica che compaia selezionata. Metti "Si spedisce" a No,
salva: il riquadro sparisce.

- [ ] **Step 8: Commit**

```bash
git add src tests/PackagesTest.php
git commit -m "Shipping box: the package, the tare and what actually ships"
```

---

### Task 7: Dati di prova

**Files:**
- Modify: `src/Seeding/CatalogDemo.php`
- Test: `tests/integrazione/CatalogDemoTest.php`

- [ ] **Step 1: Aggiungi il check che fallisce**

In `tests/integrazione/CatalogDemoTest.php`:

```php
check('i dati di prova portano due imballaggi, uno predefinito', function () {
    $righe = Package::find(['deleted' => 'false'], null, 'position', 'ASC');
    $righe = isset($righe['id']) ? [$righe] : (array) $righe;
    $predefiniti = array_filter($righe, static fn ($r) => ($r['is_default'] ?? '') === 'true');

    return count($righe) >= 2 && count($predefiniti) === 1;
});

check('gli articoli di prova hanno una scatola', function () {
    foreach (CatalogDemo::modelliDiProva() as $modello) {
        if ((int) ($modello['package_id'] ?? 0) === 0) {
            return false;
        }
    }

    return true;
});
```

> Se `modelliDiProva()` non esiste, leggi i modelli con il prefisso `Prova `
> come fanno gli altri check del file.

- [ ] **Step 2: Esegui e verifica che fallisca**

```bash
php tests/integrazione/CatalogDemoTest.php
```

- [ ] **Step 3: Semina gli imballaggi**

`prefixOf()` deve conoscere la scatola: aggiungi al suo `match`/array
`Package::class => Codes::PACKAGE`.

In `CatalogDemo`, un metodo nuovo chiamato da `create()` **prima** dei modelli:

```php
    /** Due scatole: quella che basta quasi sempre e una busta per le cose piatte. */
    private static function packages(): int
    {
        $created = self::ensure(Package::class, self::PREFIX.'Busta imbottita', [
            'weight' => '0.050',
            'length' => '35.00',
            'width' => '25.00',
            'height' => '3.00',
            'is_default' => 'false',
            'position' => 1,
            'active' => 'true',
        ]);

        $created += self::ensure(Package::class, self::PREFIX.'Scatola media', [
            'weight' => '0.200',
            'length' => '40.00',
            'width' => '30.00',
            'height' => '20.00',
            'is_default' => 'true',
            'position' => 2,
            'active' => 'true',
        ]);

        return $created;
    }
```

In `create()`, accanto alle altre righe di preparazione:

```php
        $created += self::packages();
```

In `model()`, dove nasce `ProductModel::create([...])`, una riga in più:

```php
            'package_id' => self::idOf(Package::class, self::PREFIX.'Scatola media'),
```

In `clear()`, accanto agli altri modelli da ripulire, aggiungi `Package::class`
all'elenco di quelli che si cancellano per prefisso.

E l'articolo con le opzioni nasce con **tre colori e quattro taglie**, così la
griglia raggruppata si vede senza costruirla a mano: nella chiamata a
`self::model(...)` che passa `$variantValues` e `$productValues`, porta i
colori a tre (`Blu`, `Rosso`, `Nero`) e le taglie a quattro (`S`, `M`, `L`,
`XL`), aggiungendo i valori mancanti anche alla definizione dei due attributi
di prova.

- [ ] **Step 4: Rifai i dati e verifica**

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge gestionale:demo --fresh
```

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/integrazione/CatalogDemoTest.php
```

Atteso: `0 falliti`. Nel browser, l'articolo con le opzioni ha **dodici**
versioni e la griglia si raggruppa in tre blocchi.

- [ ] **Step 5: Commit**

```bash
git add src/Seeding/CatalogDemo.php tests/integrazione/CatalogDemoTest.php
git commit -m "Demo data: two packages and a twelve-version article"
```

---

### Task 8: Documentazione e chiusura

**Files:**
- Modify: `docs/user/catalogo-prodotti.md`
- Create: `docs/user/imballaggi.md`
- Modify: `docs/user/SUMMARY.md`
- Modify: `docs/dev/concetti/catalogo.md`

- [ ] **Step 1: Scrivi la documentazione**

`docs/user/catalogo-prodotti.md`: le opzioni nella schermata di creazione, il
"+" che aggiunge un colore all'elenco del negozio, la griglia che si raggruppa
e la casella del prezzo di gruppo, il riquadro Spedizione. Togli il riferimento
allo scontato in griglia.

`docs/user/imballaggi.md`, corto: cos'è la tara, perché due o tre scatole buone
bastano, e che la scatola predefinita è quella che vale per gli articoli che
non ne scelgono una. Aggiungilo a `SUMMARY.md` sotto le pagine di Set up.

`docs/dev/concetti/catalogo.md`: `AttributeValueResource` e perché esiste
(store API per il quick-create, non una pagina), `gst_packages`, il
raggruppamento della griglia e il fatto che è una vista (il DOM non si sposta,
le posizioni non cambiano). Togli la nota sul `foldable()` che era una Card
travestita e mettici il pavimento `^2.3.0`.

- [ ] **Step 2: Esegui tutta la suite**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/run.php
```

Atteso: nessun file con `falliti` diverso da zero.

- [ ] **Step 3: Il giro completo nel browser**

Su `https://ecommerce.test/backend/`, in un colpo solo:

1. `Prodotti → Aggiungi prodotto`: nome, tipo fiscale, prezzo, categoria, SKU;
2. apri il blocco delle versioni, crea un colore nuovo con il "+", spunta tre
   colori e quattro taglie, salva;
3. atterri sulla scheda con dodici versioni;
4. raggruppa per colore, chiudi due gruppi, dai un prezzo a un gruppo, salva;
5. scegli un imballaggio e leggi il peso spedito;
6. ricarica: prezzi, decimali, raggruppamento e imballaggio sono al loro posto.

- [ ] **Step 4: Commit e merge**

```bash
git add docs
git commit -m "Docs: options on creation, grouped grid, packages"
```

Poi la procedura di chiusura del ramo di sviluppo: test verdi, merge su `main`,
push, e controllo che la CI sia verde.
