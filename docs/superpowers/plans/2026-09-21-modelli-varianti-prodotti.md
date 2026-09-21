# Piano 3 di 4 (G2a) — Modelli, varianti e prodotti

> **Per chi esegue:** SKILL RICHIESTA: usare superpowers:subagent-driven-development
> (consigliata) o superpowers:executing-plans, un task alla volta. I passi usano le
> caselle `- [ ]` per il tracciamento.

**Obiettivo:** il cuore del catalogo. Il commerciante crea un articolo senza
varianti in meno di un minuto, e quando gli servono due colori e tre taglie ottiene
i sei prodotti con gli SKU già proposti.

**Architettura:** cinque tabelle nuove più tre di collegamento degli attributi. La
scheda del modello è **l'unico posto dove si lavora**: varianti e prodotti stanno
lì dentro come repeater. L'elenco "Prodotti" serve a cercare uno SKU, non a
modificare in massa. Tutto ciò che si può decidere senza database — lo SKU
proposto, la validità di un EAN, le combinazioni da creare — sta in classi pure
sotto `Support\Catalog`.

**Stack:** PHP 8.2, `wonder-image/app` `^2.2.12`, MySQL, test come script PHP con
l'harness del modulo.

**Spec:** `docs/superpowers/specs/2026-09-21-catalogo-design.md` (§1, §2, §3, §4, §6,
§7, decisioni G2a.2, G2a.3, G2a.5, G2a.6, G2a.7).

## Vincoli globali

- **Lingua:** testi, commenti e messaggi in italiano; nomi in inglese nel codice.
- **Tabelle:** prefisso `gst_`, `syncSchema(): null` (il catalogo non si sincronizza).
- **Codici:** `code` con i prefissi di `Support\Codes` (`mod_`, `var_`, `pro_`);
  `slug` generato dal nome alla creazione e poi fisso.
- **Niente colonne SEO** (G2a.9) e **niente eliminazione di ciò che è usato** (G2a.7).
- **Colonne che nascono nascoste** (G2a.6): `min_stock_quantity`, `allow_backorder`,
  `backorder_lead_days` esistono ma non si vedono finché non arrivano G2b e G4.
- **Rifiutare un salvataggio:** `throw UserError::make('chiave')`, con il testo in
  `lang/it/gestionale.json`. È l'unico modo che torna sul form invece di dare 500.
- **Unicità di SKU ed EAN:** controllata nel codice, non con un indice UNIQUE. Il
  framework scrive stringhe vuote e non NULL: due articoli senza SKU si
  scontrerebbero sull'indice.
- **Menu:** sezione `catalogo`, per `admin` e `administrator`.
- **Test d'integrazione:** database `ecommerce_site` dentro `Transaction::run()` che
  annulla sempre.
- **Ramo:** `feature/modelli-e-prodotti` in `packages/gestionale`.
- **Commit:** uno per task, messaggio in inglese, con la riga
  `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.

## Struttura dei file

| File | Responsabilità |
|---|---|
| `src/Models/Catalog/ProductModel.php` | `gst_product_models` |
| `src/Models/Catalog/ProductModelCategory.php` | `gst_product_model_categories` |
| `src/Models/Catalog/ProductModelTag.php` | `gst_product_model_tags` |
| `src/Models/Catalog/ProductVariant.php` | `gst_product_variants` |
| `src/Models/Catalog/Product.php` | `gst_products` |
| `src/Models/Catalog/ProductModelAttribute.php` | attributi di modello |
| `src/Models/Catalog/ProductVariantAttribute.php` | attributi di variante |
| `src/Models/Catalog/ProductAttribute.php` | attributi di prodotto |
| `src/Support/Catalog/ProductAttributes.php` | leggere e scrivere i collegamenti |
| `src/Support/Catalog/Sku.php` | proporre lo SKU, controllarne l'unicità |
| `src/Support/Catalog/Ean.php` | 8 o 13 cifre, unico se compilato |
| `src/Support/Catalog/Skeleton.php` | un modello nuovo nasce con variante e prodotto |
| `src/Support/Catalog/Combinations.php` | quali variante+prodotto mancano (puro) |
| `src/Resources/Catalog/ProductModelResource.php` | pagina "Modelli" |
| `src/Resources/Catalog/ProductResource.php` | pagina "Prodotti" |

---

### Task 1: Il modello e i suoi collegamenti

**File:**
- Creare: `src/Models/Catalog/ProductModel.php`,
  `src/Models/Catalog/ProductModelCategory.php`,
  `src/Models/Catalog/ProductModelTag.php`
- Test: `tests/ProductModelsTest.php`

**Colonne**

| Tabella | Colonne |
|---|---|
| `gst_product_models` | `code` (`mod_`), `brand_id` INT→`gst_brands`, `type` enum(`simple`,`bundle`) default `simple`, `tax_category_id` INT→`gst_tax_categories`, `sku` (100), `unit` (10, default `pz`), `name`, `slug` (150, unico), `short_description` TEXT, `description` TEXT, `weight`/`length`/`width`/`height` DECIMAL, `returnable` enum, `requires_shipping` enum, `visible` enum, `visible_online` enum |
| `gst_product_model_categories` | `product_model_id` INT→`gst_product_models`, `category_id` INT→`gst_categories`, `is_main` enum, `position` INT |
| `gst_product_model_tags` | `product_model_id` INT→`gst_product_models`, `tag_id` INT→`gst_tags` |

- [ ] **Passo 1: scrivere il test** — `tests/ProductModelsTest.php`, sulla falsariga di
  `tests/CatalogAttributeModelsTest.php` (stessi helper `$colonne` e `$campo`):
  le tre tabelle hanno il prefisso `gst_` e non si sincronizzano; `code` usa
  `Codes::MODEL`; `code` e `slug` sono `immutable_on_update`; `slug` è unico;
  `type` è l'enum `['simple','bundle']` con default `simple`; `unit` ha default `pz`;
  `sku` **non** ha un indice unico (il commento dice perché); i collegamenti puntano
  a `gst_product_models`, `gst_categories` e `gst_tags`.
- [ ] **Passo 2: eseguirlo** — fallisce con "Class not found".
- [ ] **Passo 3: scrivere i tre Model.** `ProductModel` come `Attribute`:
  `sqlColumnsFromDataSchema(['code', 'weight', 'length', 'width', 'height'])` per le
  colonne che nascono dai campi, il resto con `Column::key(...)`. Docblock: perché
  `type` esiste già ma in G2a vale solo `simple`, e perché lo SKU non ha un indice
  unico.
- [ ] **Passo 4: rieseguire** — verde.
- [ ] **Passo 5: `php forge update`** dal sito di prova: le tre tabelle nascono.
- [ ] **Passo 6: commit.**

---

### Task 2: Varianti e prodotti

**File:**
- Creare: `src/Models/Catalog/ProductVariant.php`, `src/Models/Catalog/Product.php`
- Test: `tests/ProductModelsTest.php` (si aggiunge)

**Colonne**

| Tabella | Colonne |
|---|---|
| `gst_product_variants` | `code` (`var_`), `product_model_id` INT→`gst_product_models`, `name`, `slug` (150, unico), `position` INT, `visible` enum |
| `gst_products` | `code` (`pro_`), `product_model_id` INT→`gst_product_models`, `product_variant_id` INT→`gst_product_variants`, `sku` (100), `ean` (13), `mpn` (100), `price` DECIMAL, `sale_price` DECIMAL, `min_stock_quantity` DECIMAL, `allow_backorder` enum, `backorder_lead_days` INT, `weight`/`length`/`width`/`height` DECIMAL, `position` INT, `active` enum |

Indici: `ind_model` su `product_model_id` in tutte e due, `ind_variant` su
`product_variant_id` nei prodotti.

- [ ] **Passo 1: scrivere il test** — prefissi `var_` e `pro_`; nessuna delle due si
  sincronizza; un prodotto punta sia al modello sia alla variante (senza variante
  non esiste, G2a.2); peso e misure sono DECIMAL; `min_stock_quantity`,
  `allow_backorder` e `backorder_lead_days` **esistono** (G2a.6).
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere i due Model,** con il docblock che spiega la regola G2a.2:
  la variante esiste sempre, anche quando è una sola, e il pannello non la nomina.
  Le misure vuote del prodotto valgono quelle del modello: la regola sta nella
  scheda, non nel Model.
- [ ] **Passo 4: rieseguire** — verde.
- [ ] **Passo 5: `php forge update`.**
- [ ] **Passo 6: commit.**

---

### Task 3: Gli attributi appesi alle righe

**File:**
- Creare: `src/Models/Catalog/ProductModelAttribute.php`,
  `src/Models/Catalog/ProductVariantAttribute.php`,
  `src/Models/Catalog/ProductAttribute.php`,
  `src/Support/Catalog/ProductAttributes.php`
- Test: `tests/ProductAttributesTest.php`

Le tre tabelle hanno le stesse colonne, cambia solo la chiave del padre:
`<padre>_id`, `attribute_id` INT→`gst_attributes`, `attribute_value_id` INT,
`value_text`, `value_number` DECIMAL.

**Interfacce prodotte:**

```php
ProductAttributes::table(string $level): string;        // 'model' => gst_product_model_attributes
ProductAttributes::modelClass(string $level): string;
ProductAttributes::parentKey(string $level): string;    // 'model' => product_model_id
ProductAttributes::save(string $level, int $parentId, array $attributes, array $input): int;
ProductAttributes::read(string $level, int $parentId): array;  // attribute_id => riga
ProductAttributes::describe(array $attributes, array $links, array $values): array; // attributo => testo
```

`save()` usa `Attributes::assignment()` per ogni attributo del livello: scrive la
riga se il valore c'è, la toglie se è stato svuotato. `describe()` usa
`Attributes::format()`.

- [ ] **Passo 1: scrivere il test** — i nomi delle tabelle e delle chiavi per i tre
  livelli; un livello sconosciuto lancia un errore invece di indovinare;
  `describe()` mette insieme nome dell'attributo e valore leggibile ("Colore: Blu",
  "Peso: 1,5 g"). `save()` e `read()` si provano nel test d'integrazione del task 5.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere i tre Model e il supporto.** Una sola mappa
  livello → [tabella, classe, chiave], così aggiungere un livello è una riga.
- [ ] **Passo 4: rieseguire** — verde.
- [ ] **Passo 5: `php forge update`.**
- [ ] **Passo 6: commit.**

---

### Task 4: SKU, EAN e la nascita di variante e prodotto

**File:**
- Creare: `src/Support/Catalog/Sku.php`, `src/Support/Catalog/Ean.php`,
  `src/Support/Catalog/Skeleton.php`
- Modificare: `lang/it/gestionale.json`
- Test: `tests/SkuEanTest.php`

**Interfacce prodotte:**

```php
Sku::propose(string $modelSku, array $parts): string;   // 'TSH-1', ['Blu','M'] => 'TSH-1-BLU-M'
Sku::part(string $label): string;                       // 'Blu scuro' => 'BLUSCURO'
Sku::isFree(string $modelClass, string $sku, ?int $exceptId = null): bool;
Ean::isValid(string $ean): bool;                        // 8 o 13 cifre, solo cifre
Ean::isFree(string $ean, ?int $exceptId = null): bool;
Skeleton::forModel(int $modelId, string $modelName): array; // ['variant_id' => …, 'product_id' => …]
```

- [ ] **Passo 1: scrivere il test** (puro, senza database, per `Sku::propose`,
  `Sku::part` ed `Ean::isValid`):

```php
check('lo SKU proposto mette insieme modello e valori', fn () =>
    Sku::propose('TSH-1', ['Blu', 'M']) === 'TSH-1-BLU-M'
);

check('senza SKU del modello non si propone niente', fn () =>
    Sku::propose('', ['Blu', 'M']) === ''
);

check('lo SKU non porta spazi né accenti', fn () =>
    Sku::part('Blu scuro') === 'BLUSCURO'
    && Sku::part('Città') === 'CITTA'
);

check('un EAN è di 8 o 13 cifre', fn () =>
    Ean::isValid('12345678')
    && Ean::isValid('1234567890123')
    && !Ean::isValid('123456789012')
    && !Ean::isValid('1234567a')
);

check('un EAN vuoto va bene: è facoltativo', fn () => Ean::isValid(''));
```

- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere `Sku` ed `Ean`.** `Ean::isValid()` accetta il vuoto (è
  facoltativo) e rifiuta tutto ciò che non è 8 o 13 cifre; **non** controlla la
  cifra di controllo: gli EAN interni dei negozi spesso non ce l'hanno, e rifiutare
  un codice che il fornitore usa davvero sarebbe peggio di accettarlo.
- [ ] **Passo 4: scrivere `Skeleton::forModel()`** — crea la variante e il prodotto
  di un modello appena nato, dentro `Transaction::run()`: la variante prende il nome
  del modello, il prodotto eredita lo SKU del modello. È la regola G2a.2, in un solo
  posto perché la useranno la scheda e i dati di prova.
- [ ] **Passo 5: le due chiavi di lingua** in `lang/it/gestionale.json`, sotto
  `gestionale.errors.product`: `sku_taken` ("Questo SKU è già di un altro
  prodotto.") e `ean_invalid` ("L'EAN deve avere 8 o 13 cifre.").
- [ ] **Passo 6: rieseguire** — verde.
- [ ] **Passo 7: commit.**

---

### Task 5: La scheda del modello

**File:**
- Creare: `src/Resources/Catalog/ProductModelResource.php`
- Modificare: `tests/ConventionsTest.php` (pagina sempre attiva)
- Test: `tests/ProductModelResourceTest.php`,
  `tests/integrazione/ProductModelTest.php`

**La scheda, riquadro per riquadro**

| Riquadro | Campi |
|---|---|
| Dati | nome, marchio, tipo fiscale, SKU, unità, stato (`visible`), online (`visible_online`) |
| Descrizioni | descrizione breve, descrizione |
| Categorie e tag | albero delle categorie (checkbox), categoria principale, tag |
| Spedizione | peso, misure, reso, spedibile |
| Attributi | un campo per ogni attributo di livello `model`, raggruppato con `Attributes::grouped()` |
| Varianti | repeater su `gst_product_variants` (nome, stato) — **solo se le varianti sono più di una** |
| Prodotti | repeater su `gst_products` (SKU, EAN, prezzo, prezzo scontato, stato) — **solo se i prodotti sono più di uno** |
| Prodotto | SKU, EAN, prezzo, prezzo scontato — **solo quando il prodotto è uno solo** |

Le tre regole di G2a.2 stanno tutte in `formLayoutSchema()`, che legge la riga
aperta con `currentId()` e conta varianti e prodotti.

**Interfacce prodotte:**

```php
ProductModelResource::variantCount(int $modelId): int;
ProductModelResource::productCount(int $modelId): int;
ProductModelResource::soleProduct(int $modelId): ?array;
```

- [ ] **Passo 1: scrivere i test degli schemi** — `tests/ProductModelResourceTest.php`:
  percorso `app/gestionale/modelli` e sezione `catalogo`; `mutateRequestValues`
  genera slug e posizione solo alla creazione e li toglie in modifica; un EAN
  storto si ferma con `UserError`; uno SKU già preso pure; i campi di magazzino
  (`min_stock_quantity`, `allow_backorder`, `backorder_lead_days`) **non** sono nel
  form (G2a.6).
- [ ] **Passo 2: eseguirli** — falliscono.
- [ ] **Passo 3: scrivere la Resource,** con le stesse sezioni di
  `AttributeResource` (percorso, icona `bi-box`, `textSchema`, `labelSchema`,
  `pageSchema` senza `view`, `permissionSchema` per i due ruoli, `apiSchema`
  disabilitata, `navigationSchema` in `catalogo` con `order(44)`).
  - `formSchema()`: i campi della tabella, più `categories` (checkTree),
    `main_category` (select), `tags` (selectSearch multiplo), i due repeater e i
    campi del prodotto singolo. Gli attributi di modello si aggiungono in coda con
    un ciclo su `Attributes::byLevel($attributi, 'model')`, un campo per attributo,
    con chiave `attribute_<id>`.
  - `mutateRequestValues()`: slug e posizione alla creazione; toglie dai valori
    tutto ciò che non è una colonna (`categories`, `tags`, `main_category`, i
    campi `attribute_*`, i campi del prodotto singolo); controlla EAN e SKU.
  - `afterStore()`: `Skeleton::forModel()` — il modello nuovo nasce con la sua
    variante e il suo prodotto, senza chiederlo.
  - `afterStore()`/`afterUpdate()`: scrivono categorie, tag, attributi di modello e,
    quando il prodotto è uno solo, SKU/EAN/prezzi su quel prodotto.
  - `mutateFormValues()`: rilegge quelle stesse cose per riempire il form.
  - `assertDeletable()`: un modello si elimina solo con i suoi prodotti; il
    messaggio dice di nasconderlo.
- [ ] **Passo 4: rieseguire i test degli schemi** — verdi.
- [ ] **Passo 5: il test d'integrazione** — `tests/integrazione/ProductModelTest.php`,
  dentro `Transaction::run()` che annulla: creare un modello con
  `ProductModel::create()` + `Skeleton::forModel()` e controllare che nascano una
  variante e un prodotto; scrivere un attributo di modello con
  `ProductAttributes::save()` e rileggerlo con `read()`; `describe()` restituisce il
  testo giusto.
- [ ] **Passo 6: `php forge update`** e prova nel browser su
  `https://ecommerce.test/backend/app/gestionale/modelli/`:
  1. Nuovo modello "Prova maglietta", marchio e tipo fiscale scelti, SKU `TSH-1`.
     Salvare: toast, e la scheda **non nomina la variante**; SKU, EAN e prezzo si
     vedono nel riquadro "Prodotto".
  2. Scrivere prezzo 19,90 e salvare: il prezzo finisce sul prodotto unico.
  3. Un EAN di 12 cifre viene rifiutato con la frase, non con un 500.
- [ ] **Passo 7: commit.**

---

### Task 6: Le combinazioni

**File:**
- Creare: `src/Support/Catalog/Combinations.php`
- Modificare: `src/Resources/Catalog/ProductModelResource.php`
- Test: `tests/CombinationsTest.php`, `tests/integrazione/ProductModelTest.php`

**Come si usa.** Nella scheda del modello c'è il riquadro **"Genera varianti e
prodotti"**: due alberi da spuntare, uno con i valori degli attributi di variante e
uno con quelli di prodotto (attributo come nodo, valori come foglie). Si spunta
Blu e Rosso sotto Colore, S, M e L sotto Taglia, si salva, e nascono due varianti e
sei prodotti con lo SKU proposto. Niente bottoni speciali: si spunta e si salva.

Rilanciarlo non duplica niente: si creano solo le combinazioni che mancano.

**Interfacce prodotte:**

```php
Combinations::plan(array $variantValues, array $productValues, array $existing): array;
// => ['variants' => [['value_id' => 3, 'label' => 'Blu'], …],
//     'products' => [['variant_value_id' => 3, 'product_value_id' => 7, 'labels' => ['Blu','M']], …]
```

`plan()` è pura: riceve i valori spuntati e quello che c'è già, e dice cosa manca.
Senza valori di variante spuntati, le combinazioni si appendono alla variante che
c'è (l'articolo che ha solo le taglie). Senza valori di prodotto, nasce un prodotto
per variante.

- [ ] **Passo 1: scrivere il test puro** — `tests/CombinationsTest.php`:
  due colori e tre taglie fanno due varianti e sei prodotti; rilanciando con le
  stesse scelte non manca più niente; aggiungendo una taglia mancano solo due
  prodotti; senza colori spuntati i prodotti nascono sulla variante esistente.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere `Combinations::plan()`.**
- [ ] **Passo 4: rieseguire** — verde.
- [ ] **Passo 5: attaccarlo alla scheda** — i due campi `checkTree` e, in
  `afterUpdate()`, la creazione dentro `Transaction::run()`: variante con il nome
  proposto dal valore, prodotto con lo SKU proposto da `Sku::propose()`, attributi
  scritti con `ProductAttributes::save()`. Le caselle si svuotano dopo la
  generazione: hanno fatto il loro lavoro.
- [ ] **Passo 6: allargare il test d'integrazione** — due valori di colore e tre di
  taglia creano 2 varianti e 6 prodotti, con SKU diversi; una seconda passata non
  crea niente.
- [ ] **Passo 7: prova nel browser** — sul modello del task 5: spuntare due colori e
  tre taglie, salvare, e trovare due varianti e sei prodotti con gli SKU
  `TSH-1-BLU-S` e compagnia; la scheda ora mostra i due repeater e non più il
  riquadro "Prodotto".
- [ ] **Passo 8: commit.**

---

### Task 7: L'elenco dei prodotti e la scheda del singolo

**File:**
- Creare: `src/Resources/Catalog/ProductResource.php`
- Modificare: `tests/ConventionsTest.php`, `docs/dev/concetti/catalogo.md`
- Test: `tests/ProductResourceTest.php`

La pagina "Prodotti" è un elenco piatto per **trovare**: ricerca per SKU ed EAN,
colonne modello, variante, SKU, EAN, prezzo, stato. Dalla riga si apre la scheda
del prodotto: SKU, EAN, MPN, prezzi, peso e misure proprie, e gli attributi di
livello `product`. Niente creazione da qui — un prodotto nasce dentro il suo
modello.

- [ ] **Passo 1: scrivere il test** — percorso `app/gestionale/prodotti`, sezione
  `catalogo`, `pageSchema()->only(['list','edit','update'])` (niente `create`);
  le colonne di magazzino non sono nel form (G2a.6); modello e variante nell'elenco
  passano da un formatter, non da un id.
- [ ] **Passo 2: eseguirlo** — fallisce.
- [ ] **Passo 3: scrivere la Resource.**
- [ ] **Passo 4: rieseguire** — verde, e `php tests/run.php` tutto verde.
- [ ] **Passo 5: prova nel browser** — cercare `TSH-1-BLU-M` nell'elenco, aprire la
  scheda, cambiare il prezzo e salvare; tornare al modello e vedere il nuovo prezzo
  nella riga del repeater.
- [ ] **Passo 6: la guida dello sviluppatore** — in `docs/dev/concetti/catalogo.md`,
  sezione "Modelli, varianti e prodotti": le cinque tabelle, la regola G2a.2 con i
  tre punti in cui si vede, dove sta ogni pezzo (`Skeleton`, `Sku`, `Ean`,
  `Combinations`, `ProductAttributes`) e perché SKU ed EAN non hanno un indice
  UNIQUE.
- [ ] **Passo 7: commit.**

---

## Chiusura

- [ ] `php tests/run.php` verde, test d'integrazione verdi.
- [ ] Ramo unito in `main` e spinto; CI verde sul commit unito.
- [ ] Sito di prova ripulito dalle righe di prova.
- [ ] `TODO.md`: spuntato il piano 3 di G2a con una riga che dice cosa contiene.
- [ ] La guida del commerciante aspetta il piano 4: senza immagini la scheda non è
      ancora tutta da raccontare.
