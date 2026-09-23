# Avvisi di scorta minima — piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** chiudere G2b con gli avvisi di scorta minima: la soglia nella scheda, una email raggruppata mandata dallo scheduler, il riquadro *Sotto scorta* nella home e le giacenze negative in *Da controllare*.

**Architecture:** le righe di `gst_stock_alerts` le apre e le chiude già `Stock::apply()` tramite `Alerts::refresh()` (piano 1). Questo piano aggiunge chi scrive la soglia (le due schede), chi legge gli avvisi (`LowStockReport`, puro nella parte che conta), chi compone l'email (`LowStockEmail` più una view sovrascrivibile) e chi la manda (`LowStockNotifier`, chiamato dall'attività `gestionale.stock_alerts`). L'invio passa da un `Mailer` del modulo che applica l'hook `beforeEmailSend` e protegge il corpo dallo "sgrassaggio" di `sanitizeEcho()` del core.

**Tech Stack:** PHP 8.2, Wonder Image App `^2.4.0-beta.1` (Resource, Model, Scheduler, `sendMail`), Symfony Console per `forge`, harness di test del modulo (`check()` / `summary()`).

**Spec:** `docs/superpowers/specs/2026-09-21-magazzino-e-anagrafiche-design.md` — §6 (scheda della versione), §8 (avvisi di scorta minima), punti di validazione 6 e 9, decisione G2b.9.

## Prerequisito

Prima di cominciare, `git status --short` in `packages/gestionale` **non** deve mostrare modifiche in questi file, che il piano tocca e su cui l'utente sta lavorando (settimo giro della scheda prodotto):

- `src/Resources/Catalog/ProductModelResource.php`
- `lang/it/gestionale.json`
- `tests/ProductModelResourceTest.php`

Se uno di questi è ancora modificato, **fermarsi e chiedere** all'utente di fare il suo commit: aggiungere al commit un file con modifiche altrui mescolerebbe il suo lavoro con il nostro. Le altre modifiche non committate del checkout (`composer.lock`, le altre Resource, i test del catalogo) non si toccano e non si aggiungono mai.

## Global Constraints

- Lingua dell'interfaccia, dei commenti e delle guide: italiano. Nomi nel codice: inglese.
- Funzionalità: `low_stock_alerts` ("Avvisi di scorta minima", area Magazzino, release G2). A funzionalità bloccata non si vede **niente**: né il campo, né il filtro *Solo sotto scorta*, né il riquadro, né il campo dei destinatari; l'attività gira ma non manda.
- Spec §8: "La soglia è il campo *Scorta minima* sulla versione… visibile solo a funzionalità sbloccata. Vale sul disponibile totale del prodotto".
- Spec §8: "L'email non parte mai dentro il salvataggio". La manda solo l'attività `gestionale.stock_alerts`, `*/15 * * * *`, **nata spenta**.
- Una email sola per giro, a tutti i *Destinatari degli avvisi* (impostazione nuova). Campo vuoto: nessuna email, e gli avvisi restano da mandare.
- La view dell'email sta in `view/emails/low-stock.php` e si sovrascrive da `custom/modules/gestionale/view/emails/low-stock.php` (`Gestionale::viewPath()`).
- Chiave dell'hook per questa email: `stock.low_stock`. Forma del messaggio: `['to' => list<string>, 'subject' => string, 'body' => string]`; un `to` vuoto ferma l'invio.
- Form compatti: niente testi d'aiuto sotto i campi, le spiegazioni vanno nel tooltip del `SectionTitle`.
- Test: `php tests/<File>.php` per un file, `php tests/run.php` per tutto (i test in `tests/integrazione/` girano solo dove c'è il sito di prova `/Users/andreamarinoni/Developer/boilerplates/ecommerce-site`).
- Commit: mai `git add -A` né `git add .`; sempre percorsi espliciti, dopo `git status --short`. Ogni messaggio finisce con la riga `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Nessuna email vera dal sito di prova senza il permesso esplicito dell'utente: le prove usano il trasporto finto del `Mailer` o l'anteprima del comando.
- In zsh i glob non trovati fanno fallire il comando: usare percorsi espliciti o virgolette.

## Review Focus

- **Soglia cambiata senza movimenti** — alzare la soglia sopra il disponibile deve aprire l'avviso subito, abbassarla sotto deve chiuderlo; non solo alla prossima vendita. (Task 1, test d'integrazione.)
- **Destinatari scritti male** — spazi, punto e virgola, doppioni, un indirizzo storto: il salvataggio rifiuta quello storto nominandolo e normalizza gli altri a `a@x.it, b@y.it`. (Task 3.)
- **Server di posta giù** — se nessun invio riesce, nessun avviso viene segnato come mandato e il guasto finisce in `error_reports`; il giro dopo ci riprova. (Task 6.)
- **Prodotto con avviso aperto e nessun movimento, eliminato** — la chiave esterna di `gst_stock_alerts` non deve trasformare l'eliminazione in una pagina di errore. (Task 2.)
- **Nomi con `&`, accenti, HTML e barre rovesciate** — nell'email arrivano come scritti, senza diventare markup: `sanitizeEcho()` del core decodifica le entità e toglie le barre. (Task 4.)

---

## File Structure

**Nuovi**

| File | Responsabilità |
|---|---|
| `src/Support/Mail/Recipients.php` | da una stringa di indirizzi a validi e non validi, senza doppioni |
| `src/Support/Mail/Mailer.php` | hook `beforeEmailSend`, un invio per indirizzo, protezione del corpo, trasporto finto per i test |
| `src/Support/Stock/ProductNames.php` | articolo e opzione di una versione come nella griglia (`of()` pura), per l'email, il riquadro e *Da controllare* |
| `src/Support/Stock/LowStockReport.php` | legge gli avvisi da mandare o aperti e li trasforma in righe (`build()` pura) |
| `src/Support/Stock/LowStockEmail.php` | oggetto e corpo dell'email dalle righe, con la view |
| `src/Support/Stock/LowStockNotifier.php` | un giro: legge, compone, manda, segna |
| `src/Support/Stock/NegativeStock.php` | i prodotti con giacenza sotto zero (`group()` pura) |
| `src/Scheduler/StockAlertsTask.php` | l'attività `gestionale.stock_alerts` |
| `src/Console/StockAlertsCommand.php` | `php forge gestionale:stock-alerts`: anteprima, non manda |
| `src/Backend/Widgets/LowStockWidget.php` | il riquadro *Sotto scorta* della home |
| `view/emails/low-stock.php` | il corpo dell'email |
| `docs/user/magazzino-avvisi.md` | la guida del commerciante |
| `tests/MinStockTest.php`, `tests/RecipientsTest.php`, `tests/MailerTest.php`, `tests/LowStockEmailTest.php`, `tests/NegativeStockTest.php`, `tests/integrazione/LowStockTest.php` | test |

**Modificati**

| File | Cosa cambia |
|---|---|
| `src/Resources/Catalog/ProductModelResource.php` | `minStockValue()`, campo `product_min_stock` nella scheda dell'articolo senza varianti, `saveSingleMinStock()`, `dropAlerts` in `deleteRecord()` |
| `src/Resources/Catalog/ProductResource.php` | campo `min_stock_quantity` nel riquadro *Magazzino*, `Alerts::refresh()` dopo il salvataggio, `dropAlerts` in `deleteRecord()` |
| `src/Support/Stock/StockHistory.php` | `dropAlerts()` |
| `src/Resources/Stock/StockLevelResource.php` | `lowStockUrl()`, filtro *Solo sotto scorta* solo a funzionalità sbloccata |
| `src/Models/System/MerchantSetting.php`, `src/Resources/System/MerchantSettingResource.php` | colonna e campo `low_stock_emails`, validazione, link della guida corretto |
| `src/Backend/Widgets/AttentionWidget.php` | giacenze negative, viste da tutti e due i ruoli |
| `src/Gestionale.php`, `config/module.php`, `module.json` | attività, riquadro, comando |
| `lang/it/gestionale.json` | `product.min_stock_invalid`, `settings.email_invalid` |
| `tests/StockPagesTest.php`, `tests/SettingsTest.php`, `tests/TasksTest.php`, `tests/CommandsTest.php`, `tests/ManifestTest.php`, `tests/WidgetsTest.php`, `tests/DocsPagesTest.php` | nuovi controlli |
| `docs/user/SUMMARY.md`, `docs/user/magazzino-giacenze.md`, `docs/user/da-controllare.md`, `docs/user/impostazioni.md`, `docs/dev/concetti/magazzino.md`, `docs/dev/concetti/hook.md`, `CHANGELOG.md` | guide |
| `TODO.md`, `docs/superpowers/specs/2026-09-21-magazzino-e-anagrafiche-design.md` | chiusura di G2b |

---

### Task 1: il campo *Scorta minima* nelle schede

**Files:**
- Modify: `src/Resources/Catalog/ProductModelResource.php` (import, `minStockValue()`, `priceFields()`, `mainColumn()`, `withoutExtras()`, `mutateFormValues()`, `mutateRequestValues()`, `saveExtras()`, nuovo `saveSingleMinStock()`)
- Modify: `src/Resources/Catalog/ProductResource.php` (import, `formSchema()`, `formLayoutSchema()`, `mutateRequestValues()`, `afterUpdate()`)
- Modify: `lang/it/gestionale.json`
- Create: `tests/MinStockTest.php`
- Create: `tests/integrazione/LowStockTest.php`

**Interfaces:**
- Consumes: `Stocktake::quantity(mixed): ?float`, `Alerts::refresh(int): string`, `Alerts::openRow(int): array`, `Gestionale::feature(string): bool`, `UserError::make(string, array = [])`.
- Produces: `ProductModelResource::minStockValue(mixed $raw): string` (pubblica, statica: `'0.000'` per vuoto, altrimenti il numero con tre decimali e il punto; `InvalidArgumentException` con il testo di `product.min_stock_invalid` per negativi, testo, array); il campo `product_min_stock` nella scheda dell'articolo senza varianti; il campo `min_stock_quantity` nella scheda della versione; `tests/integrazione/LowStockTest.php` con le funzioni `articoloDiProva(string $sku, string $giacenza = ''): array{0: int, 1: int}` e `annullando(callable $prova): bool`, che i task 2 e 6 riusano.

La soglia dell'avviso sta su `gst_products.min_stock_quantity` (colonna già creata nel piano 1). Si scrive in due posti: nella scheda dell'articolo senza varianti, accanto a prezzo e giacenza, e nella scheda della versione (`app/gestionale/versioni`), nel riquadro *Magazzino*. Senza la funzionalità `low_stock_alerts` il campo non esiste e la colonna non si tocca: un form vecchio non deve azzerarla.

Cambiare la soglia è già una notizia: alzarla sopra il disponibile deve aprire l'avviso **adesso**, non alla prossima vendita. Per questo dopo la scrittura si chiama `Alerts::refresh()` anche senza movimenti.

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `tests/MinStockTest.php`:

```php
<?php
/** php tests/MinStockTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;

// Lo stato delle funzionalità si forza senza database: `features()` è
// memoizzato. `null` torna a leggerlo (e senza database è tutto spento).
$forza = static function (?array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato);
};

$chiavi = static function (string $resource): array {
    $nomi = [];

    foreach ($resource::formSchema() as $field) {
        $nomi[] = (string) $field->name;
    }

    return $nomi;
};

$rifiutata = static function (mixed $valore): bool {
    try {
        ProductModelResource::minStockValue($valore);
    } catch (InvalidArgumentException $errore) {
        return str_contains($errore->getMessage(), 'scorta minima');
    }

    return false;
};

check('una scorta minima vuota vale zero, cioè nessun avviso', fn () =>
    ProductModelResource::minStockValue('') === '0.000'
    && ProductModelResource::minStockValue(null) === '0.000'
    && ProductModelResource::minStockValue('0') === '0.000'
);

check('la scorta minima si legge come la giacenza', fn () =>
    ProductModelResource::minStockValue('5') === '5.000'
    && ProductModelResource::minStockValue('2,5') === '2.500'
    && ProductModelResource::minStockValue('20.000') === '20.000'
    && ProductModelResource::minStockValue('1.234,5') === '1234.500'
    && ProductModelResource::minStockValue(' 3 ') === '3.000'
);

check('negativi, testo e liste non passano, e il messaggio dice cosa scrivere', fn () =>
    $rifiutata('-1') && $rifiutata('abc') && $rifiutata(['5'])
);

check('con gli avvisi bloccati la scorta minima non compare in nessuna scheda', function () use ($forza, $chiavi) {
    $forza(['low_stock_alerts' => false]);

    return !in_array('product_min_stock', $chiavi(ProductModelResource::class), true)
        && !in_array('min_stock_quantity', $chiavi(ProductResource::class), true);
});

check('con gli avvisi sbloccati la scorta minima c\'è in tutte e due', function () use ($forza, $chiavi) {
    $forza(['low_stock_alerts' => true]);

    return in_array('product_min_stock', $chiavi(ProductModelResource::class), true)
        && in_array('min_stock_quantity', $chiavi(ProductResource::class), true);
});

check('la scheda della versione salva la soglia col punto', function () use ($forza) {
    $forza(['low_stock_alerts' => true]);
    $valori = ProductResource::mutateRequestValues(['min_stock_quantity' => '2,5'], 'update', 'backend', ['id' => 1]);

    return ($valori['min_stock_quantity'] ?? null) === '2.500';
});

check('con gli avvisi bloccati la soglia non si scrive, nemmeno da un form vecchio', function () use ($forza) {
    $forza(['low_stock_alerts' => false]);
    $valori = ProductResource::mutateRequestValues(['min_stock_quantity' => '9'], 'update', 'backend', ['id' => 1]);

    return !array_key_exists('min_stock_quantity', $valori);
});

check('la scheda della versione rifiuta una soglia negativa', function () use ($forza) {
    $forza(['low_stock_alerts' => true]);

    try {
        ProductResource::mutateRequestValues(['min_stock_quantity' => '-2'], 'update', 'backend', ['id' => 1]);
    } catch (InvalidArgumentException $errore) {
        return str_contains($errore->getMessage(), 'scorta minima');
    }

    return false;
});

$forza(null);

summary();
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/MinStockTest.php`
Expected: FAIL — `Call to undefined method ...ProductModelResource::minStockValue()`.

- [ ] **Step 3: Aggiungi il testo dell'errore**

In `lang/it/gestionale.json`, nel gruppo `gestionale.errors.product`, dopo la chiave `stock_negative` (ricorda la virgola sulla riga prima):

```json
            "min_stock_invalid": "La scorta minima è un numero di pezzi, zero o più: con zero non arriva nessun avviso."
```

Verifica che il file sia ancora JSON valido:

Run: `php -r 'json_decode(file_get_contents("lang/it/gestionale.json"), true, 512, JSON_THROW_ON_ERROR); echo "ok\n";'`
Expected: `ok`

- [ ] **Step 4: `minStockValue()` e il campo nella scheda dell'articolo**

In `src/Resources/Catalog/ProductModelResource.php` aggiungi fra gli `use`, in ordine con gli altri:

```php
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Stock\Alerts;
```

Subito dopo il metodo `assertStockWritable()` aggiungi:

```php
    /**
     * La scorta minima scritta nella scheda, pronta per la colonna.
     *
     * Vuota vale zero, che vuol dire "non avvisarmi". Il numero si legge come
     * la giacenza (`Stocktake::quantity()`): `2,5` e `2.5` sono la stessa
     * cosa, e `20.000` sono venti pezzi, come li mostra il campo del backend.
     */
    public static function minStockValue(mixed $raw): string
    {
        if (is_array($raw)) {
            throw UserError::make('product.min_stock_invalid');
        }

        if (trim((string) ($raw ?? '')) === '') {
            return '0.000';
        }

        $quantity = Stocktake::quantity($raw);

        if ($quantity === null || $quantity < 0) {
            throw UserError::make('product.min_stock_invalid');
        }

        return number_format($quantity, 3, '.', '');
    }
```

In `priceFields()`, fra il campo `product_stock` e il campo `product_ean`, aggiungi:

```php
        // La soglia dell'avviso: vale sul disponibile di tutte le sedi, e con
        // zero non arriva niente. Senza la funzionalità non esiste.
        if (Gestionale::feature('low_stock_alerts')) {
            $fields[] = FormField::key('product_min_stock')
                ->number()
                ->decimal(3)
                ->label('Scorta minima')
                ->hiddenWhen('has_variants', 'true');
        }
```

In `withoutExtras()`, nella lista di `unset(...)`, dopo `$values['product_stock'],` aggiungi:

```php
            $values['product_min_stock'],
```

- [ ] **Step 5: Esegui il test**

Run: `php tests/MinStockTest.php`
Expected: passano i controlli su `minStockValue()` e sulla scheda dell'articolo; falliscono ancora quelli su `ProductResource` (campo `min_stock_quantity` assente, chiave non convertita).

- [ ] **Step 6: Il campo nella scheda della versione**

In `src/Resources/Catalog/ProductResource.php` aggiungi fra gli `use`:

```php
use Wonder\Plugin\Gestionale\Support\Stock\Alerts;
```

In `formSchema()`, subito prima del `foreach (Attributes::byLevel(static::attributes(), 'product') ...)`:

```php
        if (Gestionale::feature('low_stock_alerts')) {
            $fields[] = FormField::key('min_stock_quantity')->number()->decimal(3)->label('Scorta minima');
        }
```

In `formLayoutSchema()` sostituisci il riquadro del magazzino:

```php
        $cards[] = (new Card)->components([
            SectionTitle::make(static::stockCardTitle())
                ->tooltip('Qui la giacenza si legge. Si scrive nella riga della griglia delle opzioni in vendita, oppure con una rettifica quando serve lasciare una causale e una nota.')
                ->columnSpan(12),
            RichText::make(static::stockSummary())->columnSpan(12),
            RichText::make(static::stockHistoryTable())->columnSpan(12),
        ])->columns(12)->columnSpan(12);
```

con:

```php
        $soglia = Gestionale::feature('low_stock_alerts');

        $cards[] = (new Card)->components([
            SectionTitle::make(static::stockCardTitle())
                ->tooltip('Qui la giacenza si legge. Si scrive nella riga della griglia delle opzioni in vendita, oppure con una rettifica quando serve lasciare una causale e una nota.'
                    .($soglia ? ' La scorta minima è la soglia sotto cui arriva l\'avviso: vale sul disponibile di tutte le sedi, e con zero non arriva niente.' : ''))
                ->columnSpan(12),
            ...($soglia ? [static::getInput('min_stock_quantity')->columnSpan(4)] : []),
            RichText::make(static::stockSummary())->columnSpan(12),
            RichText::make(static::stockHistoryTable())->columnSpan(12),
        ])->columns(12)->columnSpan(12);
```

In `mutateRequestValues()`, subito prima del `return $values;` finale:

```php
        // Senza la funzionalità la colonna non si scrive: un form aperto prima
        // di bloccarla non deve azzerare la soglia.
        if (!Gestionale::feature('low_stock_alerts')) {
            unset($values['min_stock_quantity']);
        } elseif (array_key_exists('min_stock_quantity', $values)) {
            $values['min_stock_quantity'] = static::minStockValue($values['min_stock_quantity']);
        }
```

In `afterUpdate()`, in fondo al metodo, dopo `ProductAttributes::save(...)`:

```php
        // La soglia può essere appena cambiata senza nessun movimento:
        // l'avviso si apre o si chiude adesso, non alla prossima vendita.
        if (Gestionale::feature('low_stock_alerts')) {
            Alerts::refresh((int) $id);
        }
```

- [ ] **Step 7: Esegui il test e verifica che passi**

Run: `php tests/MinStockTest.php && php tests/ProductResourceTest.php && php tests/ProductModelResourceTest.php`
Expected: PASS su tutti e tre (il controllo "il magazzino non si vede ancora" di `ProductResourceTest` resta verde: senza database le funzionalità sono spente).

- [ ] **Step 8: Il campo accanto a prezzo e giacenza, e il salvataggio**

In `ProductModelResource::mainColumn()`, subito prima di `$cards = [`, aggiungi:

```php
        // Con la scorta minima la riga del prezzo ha quattro caselle invece
        // di tre.
        $soglia = Gestionale::feature('low_stock_alerts');
        $larghezza = $soglia ? 3 : 4;
```

Nel tooltip del `SectionTitle::make('Prodotto')`, dopo la riga che aggiunge la frase della giacenza iniziale (`.($modelId > 0 ? '' : ' La giacenza scritta alla creazione ...')`), aggiungi:

```php
                            .($soglia ? ' La scorta minima è la soglia sotto cui arriva l\'avviso: vale sul disponibile di tutte le sedi, e con zero non arriva niente.' : '')
```

Sostituisci le tre righe

```php
                static::getInput('product_price')->columnSpan(4),
                static::getInput('product_sale_price')->columnSpan(4),
```

e

```php
                static::getInput('product_stock')->columnSpan(4),
```

con

```php
                static::getInput('product_price')->columnSpan($larghezza),
                static::getInput('product_sale_price')->columnSpan($larghezza),
```

e

```php
                static::getInput('product_stock')->columnSpan($larghezza),
                ...($soglia ? [static::getInput('product_min_stock')->columnSpan(3)] : []),
```

lasciando in mezzo il commento che c'è già.

In `mutateFormValues()`, dentro `if ($unaVersione) { ... }`, dopo l'assegnazione di `$values['product_stock']`:

```php
            // Col punto, come la colonna: il campo del backend mostra il
            // punto come separatore dei decimali.
            $values['product_min_stock'] = number_format(
                (float) ($product['min_stock_quantity'] ?? 0),
                3,
                '.',
                ''
            );
```

In `mutateRequestValues()`, subito prima di `return static::withoutExtras($values);`:

```php
        // La scorta minima si controlla adesso, come la giacenza: dopo
        // l'insert un rifiuto lascerebbe l'articolo scritto a metà.
        if (
            Gestionale::feature('low_stock_alerts')
            && $values['has_variants'] !== 'true'
            && array_key_exists('product_min_stock', $_POST)
        ) {
            static::minStockValue($_POST['product_min_stock']);
        }
```

Subito dopo il metodo `saveSingleStock()` aggiungi:

```php
    /**
     * La scorta minima dell'articolo senza varianti.
     *
     * Si scrive dopo la giacenza: alla creazione il carico iniziale ha già
     * rinfrescato l'avviso con la soglia a zero, e qui lo si rinfresca con
     * quella vera. Il rinfresco c'è anche quando nessun pezzo si è mosso:
     * alzare la soglia sopra il disponibile è già una notizia.
     */
    protected static function saveSingleMinStock(int $modelId, array $post, bool $conVarianti): void
    {
        if (
            $conVarianti
            || !Gestionale::feature('low_stock_alerts')
            || !array_key_exists('product_min_stock', $post)
        ) {
            return;
        }

        $product = static::soleProduct($modelId);

        if (!is_array($product)) {
            return;
        }

        $productId = (int) $product['id'];
        $soglia = static::minStockValue($post['product_min_stock']);

        if (abs((float) $soglia - (float) ($product['min_stock_quantity'] ?? 0)) > 0.0005) {
            Product::update(['min_stock_quantity' => $soglia], $productId);
        }

        Alerts::refresh($productId);
    }
```

In `saveExtras()`, subito dopo `static::saveSingleStock($modelId, $post, $conVarianti, $appenaNato);`:

```php
            static::saveSingleMinStock($modelId, $post, $conVarianti);
```

- [ ] **Step 9: Scrivi il test d'integrazione**

Crea `tests/integrazione/LowStockTest.php`:

```php
<?php
/** php tests/integrazione/LowStockTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Stock\Alerts;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

// Le funzionalità si leggono una volta per richiesta: si accende solo quella
// che serve, lasciando le altre come le ha il sito.
Gestionale::feature('low_stock_alerts');
$stato = new ReflectionProperty(Gestionale::class, 'features');
$stato->setValue(null, array_merge((array) $stato->getValue(), ['low_stock_alerts' => true]));

/**
 * Un articolo senza varianti. Con una giacenza scritta nasce col suo carico
 * iniziale; senza, non ha nessun movimento e si può ancora eliminare.
 *
 * @return array{0: int, 1: int} id del modello e dell'unico prodotto
 */
function articoloDiProva(string $sku, string $giacenza = ''): array
{
    $modello = ProductModel::create([
        'code' => Code::make(ProductModel::class, Codes::MODEL),
        'name' => 'Prova scorta '.$sku,
        'slug' => Slug::make('prova-scorta-'.uniqid()),
        'sku' => $sku,
        'unit' => 'pz',
        'type' => 'simple',
        'visible' => 'true',
        'visible_online' => 'true',
        'position' => 1,
    ]);
    $modelId = (int) ($modello->insert_id ?? 0);
    $scheletro = Skeleton::forModel($modelId, 'Prova scorta '.$sku, $sku);

    ProductModelResource::forgetCatalogCache();
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_stock' => $giacenza], $sku, [], true);

    return [$modelId, (int) $scheletro['product_id']];
}

/** Un controllo dentro una transazione che poi si annulla: il sito resta com'era. */
function annullando(callable $prova): bool
{
    $esito = false;

    try {
        Transaction::run(static function () use ($prova, &$esito): void {
            $esito = (bool) $prova();

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    return $esito;
}

check('alzare la soglia sopra il disponibile apre l\'avviso subito, senza movimenti', fn () => annullando(function (): bool {
    [$modelId, $productId] = articoloDiProva('LOW-1', '3');
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_min_stock' => '5'], 'LOW-1');
    $avviso = Alerts::openRow($productId);
    $prodotto = Product::findById($productId);

    return $avviso !== []
        && (float) $avviso['threshold'] === 5.0
        && (float) $avviso['quantity_at_alert'] === 3.0
        && (float) ($prodotto['min_stock_quantity'] ?? 0) === 5.0;
}));

check('abbassarla sotto il disponibile chiude l\'avviso', fn () => annullando(function (): bool {
    [$modelId, $productId] = articoloDiProva('LOW-2', '3');
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_min_stock' => '5'], 'LOW-2');
    $aperto = Alerts::openRow($productId) !== [];
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_min_stock' => '2'], 'LOW-2');

    return $aperto && Alerts::openRow($productId) === [];
}));

check('con la soglia a zero l\'avviso aperto si chiude', fn () => annullando(function (): bool {
    [$modelId, $productId] = articoloDiProva('LOW-3', '1');
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_min_stock' => '4'], 'LOW-3');
    $aperto = Alerts::openRow($productId) !== [];
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_min_stock' => ''], 'LOW-3');

    return $aperto && Alerts::openRow($productId) === [];
}));

check('un articolo che nasce già sotto la sua scorta minima ha l\'avviso', fn () => annullando(function (): bool {
    $modello = ProductModel::create([
        'code' => Code::make(ProductModel::class, Codes::MODEL),
        'name' => 'Prova scorta LOW-4',
        'slug' => Slug::make('prova-scorta-'.uniqid()),
        'sku' => 'LOW-4',
        'unit' => 'pz',
        'type' => 'simple',
        'visible' => 'true',
        'visible_online' => 'true',
        'position' => 1,
    ]);
    $modelId = (int) ($modello->insert_id ?? 0);
    $scheletro = Skeleton::forModel($modelId, 'Prova scorta LOW-4', 'LOW-4');
    ProductModelResource::forgetCatalogCache();
    ProductModelResource::saveExtras(
        $modelId,
        ['has_variants' => 'false', 'product_stock' => '2', 'product_min_stock' => '4'],
        'LOW-4',
        [],
        true
    );

    return Alerts::openRow((int) $scheletro['product_id']) !== [];
}));

summary();
```

- [ ] **Step 10: Esegui il test d'integrazione**

Run: `php tests/integrazione/LowStockTest.php`
Expected: PASS, 4 controlli.

- [ ] **Step 11: Commit**

```bash
git status --short
git add lang/it/gestionale.json src/Resources/Catalog/ProductModelResource.php src/Resources/Catalog/ProductResource.php tests/MinStockTest.php tests/integrazione/LowStockTest.php
git commit -m "$(cat <<'MSG'
feat(magazzino): campo Scorta minima nelle schede dell'articolo e della versione

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 2: eliminare un prodotto con l'avviso aperto, e il filtro delle giacenze

**Files:**
- Modify: `src/Support/Stock/StockHistory.php` (nuovo `dropAlerts()`)
- Modify: `src/Resources/Catalog/ProductModelResource.php` (`deleteRecord()`)
- Modify: `src/Resources/Catalog/ProductResource.php` (`deleteRecord()`)
- Modify: `src/Resources/Stock/StockLevelResource.php` (`baseUrl()`, `lowStockUrl()`, `products()`, `filtersBar()`, `pageUrl()`)
- Modify: `tests/StockPagesTest.php`, `tests/integrazione/LowStockTest.php`

**Interfaces:**
- Consumes: `articoloDiProva()` e `annullando()` del task 1; `Alerts::refresh()`.
- Produces: `StockHistory::dropAlerts(list<int> $productIds): int`; `StockLevelResource::lowStockUrl(): string` (l'elenco Giacenze filtrato sulle sole righe sotto scorta, `…/giacenze?sotto=1`), che usa il riquadro del task 8.

`gst_stock_alerts.product_id` ha una chiave esterna verso `gst_products`. Un articolo con la soglia scritta e **nessun** movimento si può eliminare (`assertDeletable()` guarda solo i movimenti), ma la sua riga d'avviso aperta blocca il `DELETE` e il commerciante vede una pagina di errore. Un avviso non è storia: di un prodotto che sparisce non c'è niente da dire, e si cancella con lui.

Le righe tolte dalla griglia invece restano (`deleted = 'true'`, niente chiave esterna violata): i loro avvisi li chiude il giro dell'attività (task 6).

- [ ] **Step 1: Scrivi i test che falliscono**

In `tests/integrazione/LowStockTest.php` aggiungi prima di `summary();` (e `use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;` e `use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;` fra gli `use`):

```php
check('un articolo con l\'avviso aperto e nessun movimento si elimina', fn () => annullando(function (): bool {
    [$modelId, $productId] = articoloDiProva('LOW-5');
    Product::update(['min_stock_quantity' => '5.000'], $productId);
    $aperto = Alerts::refresh($productId) === 'open';
    $esito = ProductModelResource::deleteRecord($modelId);
    $rimasti = StockAlert::find(['product_id' => $productId]);

    return $aperto && !empty($esito->success) && (!is_array($rimasti) || $rimasti === []);
}));

check('anche una versione con l\'avviso aperto si elimina dalla sua scheda', fn () => annullando(function (): bool {
    [, $productId] = articoloDiProva('LOW-6');
    Product::update(['min_stock_quantity' => '5.000'], $productId);
    Alerts::refresh($productId);
    $esito = ProductResource::deleteRecord($productId);

    return !empty($esito->success) && Product::findById($productId) === [];
}));
```

In `tests/StockPagesTest.php` aggiungi prima di `summary();`:

```php
check('il riquadro della home porta alle sole righe sotto scorta', fn () =>
    str_ends_with(StockLevelResource::lowStockUrl(), 'giacenze?sotto=1')
);
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `php tests/StockPagesTest.php; php tests/integrazione/LowStockTest.php`
Expected: FAIL — `Call to undefined method ...StockLevelResource::lowStockUrl()`; nel test d'integrazione i due controlli nuovi falliscono con l'errore della chiave esterna (`a foreign key constraint fails`).

`Product::findById()` su una riga che non c'è restituisce `[]` (mai `null`): per questo il controllo confronta con `[]`.

- [ ] **Step 3: `dropAlerts()`**

In `src/Support/Stock/StockHistory.php`, dopo `purge()`:

```php
    /**
     * Cancella gli avvisi di scorta di prodotti che stanno per sparire.
     *
     * Un avviso non è storia: dice "adesso questo prodotto è sotto scorta", e
     * di un prodotto eliminato non c'è più niente da dire. Ma punta al
     * prodotto con una chiave esterna: senza toglierlo prima, eliminare un
     * articolo con la soglia scritta e nessun movimento finirebbe in una
     * pagina di errore.
     *
     * @param list<int> $productIds
     * @return int quanti avvisi se ne sono andati
     */
    public static function dropAlerts(array $productIds): int
    {
        $ids = array_values(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return 0;
        }

        $condition = 'product_id IN ('.implode(',', $ids).')';

        try {
            $rows = StockAlert::find($condition);
            $rows = isset($rows['id']) ? [$rows] : (array) $rows;
            $removed = count(array_filter($rows, 'is_array'));

            StockAlert::query()->Delete(StockAlert::$table, $condition);
        } catch (Throwable) {
            // Tabella non ancora creata: non c'è niente da togliere.
            return 0;
        }

        return $removed;
    }
```

Nel docblock della classe, dopo "o cancellare anche la storia (`purge()`)." aggiungi la riga:

```php
 * Gli avvisi di scorta invece se ne vanno col prodotto (`dropAlerts()`).
```

- [ ] **Step 4: Chiamala dove si elimina davvero**

In `ProductModelResource::deleteRecord()`, dentro il `foreach (static::products($modelId) as $product)`, subito prima di `Product::delete((int) $product['id']);`:

```php
                StockHistory::dropAlerts([(int) $product['id']]);
```

In `ProductResource::deleteRecord()`, subito prima di `return Product::delete($id);`:

```php
        StockHistory::dropAlerts([(int) $id]);
```

- [ ] **Step 5: Il filtro *Solo sotto scorta* solo a funzionalità sbloccata**

In `src/Resources/Stock/StockLevelResource.php`, in `pageUrl()`, sostituisci il blocco

```php
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
```

con

```php
        $base = self::baseUrl();

        return $query === [] ? $base : $base.'?'.http_build_query($query);
```

e aggiungi dopo `pageUrl()`:

```php
    /** L'elenco con le sole righe sotto la scorta minima: ci porta la home. */
    public static function lowStockUrl(): string
    {
        return self::baseUrl().'?sotto=1';
    }

    /** L'indirizzo della pagina, senza filtri. */
    private static function baseUrl(): string
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

        return $base;
    }
```

In `products()` sostituisci `if (($_GET['sotto'] ?? '') === '1') {` con:

```php
        // Senza la funzionalità il filtro non c'è: un vecchio link con
        // `sotto=1` mostra tutte le righe invece di una pagina vuota.
        if (($_GET['sotto'] ?? '') === '1' && Gestionale::feature('low_stock_alerts')) {
```

In `filtersBar()` sostituisci

```php
        $html .= '<a class="btn btn-sm btn-secondary" href="'
            .static::escape(static::pageUrl(['sotto' => $sotto ? null : '1', 'p' => null])).'">'
            .($sotto ? 'Tutte le opzioni' : 'Solo sotto scorta').'</a>';
```

con

```php
        if (Gestionale::feature('low_stock_alerts')) {
            $html .= '<a class="btn btn-sm btn-secondary" href="'
                .static::escape(static::pageUrl(['sotto' => $sotto ? null : '1', 'p' => null])).'">'
                .($sotto ? 'Tutte le opzioni' : 'Solo sotto scorta').'</a>';
        }
```

- [ ] **Step 6: Esegui i test e verifica che passino**

Run: `php tests/StockPagesTest.php && php tests/integrazione/LowStockTest.php && php tests/integrazione/StockPagesTest.php && php tests/integrazione/StockTest.php`
Expected: PASS su tutti.

- [ ] **Step 7: Commit**

```bash
git status --short
git add src/Support/Stock/StockHistory.php src/Resources/Catalog/ProductModelResource.php src/Resources/Catalog/ProductResource.php src/Resources/Stock/StockLevelResource.php tests/StockPagesTest.php tests/integrazione/LowStockTest.php
git commit -m "$(cat <<'MSG'
fix(magazzino): gli avvisi se ne vanno col prodotto eliminato; filtro sotto scorta solo con la funzionalità

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 3: i *Destinatari degli avvisi*

**Files:**
- Create: `src/Support/Mail/Recipients.php`
- Modify: `src/Models/System/MerchantSetting.php`
- Modify: `src/Resources/System/MerchantSettingResource.php`
- Modify: `lang/it/gestionale.json`
- Create: `tests/RecipientsTest.php`
- Modify: `tests/SettingsTest.php`, `tests/DocsPagesTest.php`

**Interfaces:**
- Consumes: `UserError::make()`, `Gestionale::feature()`, `Gestionale::docsUrl()`.
- Produces: `Recipients::parse(string $value): array{valid: list<string>, invalid: list<string>}`; `Recipients::join(list<string> $addresses): string` (`a@x.it, b@y.it`); la colonna `gst_merchant_settings.low_stock_emails` (TEXT); `MerchantSettingResource::DOCS_PAGE` (`'ogni-giorno/impostazioni'`).

Oggi il link della guida delle impostazioni punta a `impostazioni/negozio`, che non esiste: la pagina è `ogni-giorno/impostazioni`. `DocsPagesTest` non se n'è accorto perché `MerchantSettingResource` non estende `GestionaleResource`; qui il link diventa una costante e il test la controlla.

- [ ] **Step 1: Scrivi i test che falliscono**

Crea `tests/RecipientsTest.php`:

```php
<?php
/** php tests/RecipientsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Mail\Recipients;

check('virgola, punto e virgola, spazi e a capo separano tutti', fn () =>
    Recipients::parse("a@x.it; b@y.it,c@z.it\nd@w.it  e@v.it")['valid']
        === ['a@x.it', 'b@y.it', 'c@z.it', 'd@w.it', 'e@v.it']
);

check('i doppioni si tolgono senza guardare le maiuscole', fn () =>
    Recipients::parse('Anna@X.it, anna@x.it, b@y.it')['valid'] === ['Anna@X.it', 'b@y.it']
);

check('un indirizzo storto finisce fra i non validi, gli altri restano', function () {
    $esito = Recipients::parse('a@x.it, anna.x.it, b@y.it');

    return $esito['valid'] === ['a@x.it', 'b@y.it'] && $esito['invalid'] === ['anna.x.it'];
});

check('una casella vuota non ha destinatari', fn () =>
    Recipients::parse('  , ; ') === ['valid' => [], 'invalid' => []]
);

check('si salvano separati da virgola e spazio', fn () =>
    Recipients::join(['a@x.it', 'b@y.it']) === 'a@x.it, b@y.it'
);

summary();
```

In `tests/SettingsTest.php` aggiungi `use Wonder\Plugin\Gestionale\Gestionale;` fra gli `use` e, prima di `summary();`:

```php
$forza = static function (?array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato);
};

check('il commerciante ha la colonna dei destinatari degli avvisi', fn () =>
    in_array('low_stock_emails', $colonne(MerchantSetting::class), true)
);

check('con gli avvisi bloccati i destinatari non si chiedono e non si scrivono', function () use ($forza) {
    $forza(['low_stock_alerts' => false]);
    $campi = array_map(static fn ($field): string => (string) $field->name, MerchantSettingResource::formSchema());
    $valori = MerchantSettingResource::mutateRequestValues(['low_stock_emails' => 'a@x.it'], 'update');

    return !in_array('low_stock_emails', $campi, true) && !array_key_exists('low_stock_emails', $valori);
});

check('con gli avvisi sbloccati i destinatari si salvano puliti', function () use ($forza) {
    $forza(['low_stock_alerts' => true]);
    $campi = array_map(static fn ($field): string => (string) $field->name, MerchantSettingResource::formSchema());
    $valori = MerchantSettingResource::mutateRequestValues(['low_stock_emails' => 'a@x.it; A@x.it  b@y.it'], 'update');

    return in_array('low_stock_emails', $campi, true)
        && $valori['low_stock_emails'] === 'a@x.it, b@y.it';
});

check('un destinatario scritto male si rifiuta, nominandolo', function () use ($forza) {
    $forza(['low_stock_alerts' => true]);

    try {
        MerchantSettingResource::mutateRequestValues(['low_stock_emails' => 'a@x.it, anna.x.it'], 'update');
    } catch (InvalidArgumentException $errore) {
        return str_contains($errore->getMessage(), 'anna.x.it');
    }

    return false;
});

check('un campo vuoto si salva vuoto: vuol dire nessuna email', function () use ($forza) {
    $forza(['low_stock_alerts' => true]);

    return MerchantSettingResource::mutateRequestValues(['low_stock_emails' => ' '], 'update')['low_stock_emails'] === '';
});

$forza(null);
```

In `tests/DocsPagesTest.php` aggiungi `use Wonder\Plugin\Gestionale\Resources\System\MerchantSettingResource;` fra gli `use` e, prima di `summary();`:

```php
check('anche le impostazioni del commerciante puntano a una guida che esiste', fn () =>
    in_array(MerchantSettingResource::DOCS_PAGE, $pagine('user'), true)
);
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `php tests/RecipientsTest.php; php tests/SettingsTest.php; php tests/DocsPagesTest.php`
Expected: FAIL — `Class "...Support\Mail\Recipients" not found`; in `SettingsTest` manca la colonna; in `DocsPagesTest` `Undefined constant ...MerchantSettingResource::DOCS_PAGE`.

- [ ] **Step 3: `Recipients`**

Crea `src/Support/Mail/Recipients.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Mail;

/**
 * Da una casella di indirizzi scritta a mano a un elenco su cui contare.
 *
 * Chi compila separa con la virgola, col punto e virgola, a capo o con uno
 * spazio: vanno bene tutti. I doppioni si tolgono senza guardare le
 * maiuscole, perché nessuno vuole la stessa email due volte; resta la prima
 * scrittura.
 */
final class Recipients
{
    /** @return array{valid: list<string>, invalid: list<string>} */
    public static function parse(string $value): array
    {
        $valid = [];
        $invalid = [];
        $seen = [];

        foreach (preg_split('/[,;\s]+/u', $value) ?: [] as $address) {
            $address = trim($address);

            if ($address === '') {
                continue;
            }

            $key = mb_strtolower($address, 'UTF-8');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $invalid[] = $address;
                continue;
            }

            $valid[] = $address;
        }

        return ['valid' => $valid, 'invalid' => $invalid];
    }

    /**
     * Gli indirizzi come si salvano e si rileggono nella casella.
     *
     * @param list<string> $addresses
     */
    public static function join(array $addresses): string
    {
        return implode(', ', $addresses);
    }
}
```

- [ ] **Step 4: La colonna**

In `src/Models/System/MerchantSetting.php`, in `tableSchema()` dopo la colonna esistente:

```php
            Column::key('low_stock_emails')->type('TEXT'),
```

in `dataSchema()` dopo il campo esistente:

```php
            Field::key('low_stock_emails')->text(),
```

e nel docblock della classe, dopo il paragrafo su `merchant_notification_emails`:

```php
 *
 * `low_stock_emails` sono i destinatari dell'email dei prodotti sotto scorta
 * minima: vuoto, l'email non parte e gli avvisi aspettano.
```

- [ ] **Step 5: Il campo, la validazione e il link della guida**

In `lang/it/gestionale.json`, dentro `gestionale.errors`, aggiungi un gruppo nuovo dopo `contact` (virgola sulla chiusura del gruppo prima):

```json
            "settings": {
                "email_invalid": "«{{email}}» non è un indirizzo email: correggilo o toglilo."
            }
```

Run: `php -r 'json_decode(file_get_contents("lang/it/gestionale.json"), true, 512, JSON_THROW_ON_ERROR); echo "ok\n";'`
Expected: `ok`

In `src/Resources/System/MerchantSettingResource.php` aggiungi fra gli `use`:

```php
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Recipients;
```

subito dopo `public static string $model = MerchantSetting::class;`:

```php

    /** La pagina della guida commercianti: `gruppo/file` del SUMMARY. */
    public const DOCS_PAGE = 'ogni-giorno/impostazioni';
```

Sostituisci `labelSchema()`, `formSchema()` e `formLayoutSchema()` con:

```php
    public static function labelSchema(): array
    {
        return [
            'merchant_notification_emails' => 'Email di chi riceve le notifiche',
            'low_stock_emails' => 'Destinatari degli avvisi',
        ];
    }

    public static function formSchema(): array
    {
        $fields = [
            FormField::key('merchant_notification_emails')->text()->label('Email di chi riceve le notifiche'),
        ];

        if (Gestionale::feature('low_stock_alerts')) {
            $fields[] = FormField::key('low_stock_emails')->text()->label('Destinatari degli avvisi');
        }

        return $fields;
    }

    public static function formLayoutSchema(): ?Form
    {
        $cards = [
            (new Card)->components([
                SectionTitle::make('Notifiche')
                    ->tooltip('Più indirizzi separati da virgola. Arrivano le notifiche che riguardano il negozio; i guasti tecnici vanno a chi ti segue.')
                    ->columnSpan(12),
                static::getInput('merchant_notification_emails')->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ];

        if (Gestionale::feature('low_stock_alerts')) {
            $cards[] = (new Card)->components([
                SectionTitle::make('Avvisi di scorta minima')
                    ->tooltip('Chi riceve l\'email dei prodotti sotto la scorta minima: più indirizzi separati da virgola. Vuoto, l\'email non parte e gli avvisi aspettano.')
                    ->columnSpan(12),
                static::getInput('low_stock_emails')->columnSpan(12),
            ])->columns(12)->columnSpan(12);
        }

        return (new Form)->components([
            (new Container)->components($cards)->columns(12)->columnSpan(12),
        ]);
    }

    /**
     * I destinatari si salvano puliti: un indirizzo storto si rifiuta adesso,
     * nominandolo, invece di scoprirlo il giorno che l'email non arriva.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        if (!array_key_exists('low_stock_emails', $values)) {
            return $values;
        }

        if (!Gestionale::feature('low_stock_alerts')) {
            unset($values['low_stock_emails']);

            return $values;
        }

        $parsed = Recipients::parse((string) ($values['low_stock_emails'] ?? ''));

        if ($parsed['invalid'] !== []) {
            throw UserError::make('settings.email_invalid', ['email' => $parsed['invalid'][0]]);
        }

        $values['low_stock_emails'] = Recipients::join($parsed['valid']);

        return $values;
    }
```

In `pageSchema()` sostituisci `Gestionale::docsUrl('impostazioni/negozio')` con `Gestionale::docsUrl(self::DOCS_PAGE)`.

- [ ] **Step 6: Esegui i test e verifica che passino**

Run: `php tests/RecipientsTest.php && php tests/SettingsTest.php && php tests/DocsPagesTest.php`
Expected: PASS su tutti.

- [ ] **Step 7: Crea la colonna sul sito di prova**

Run: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update --local`
Expected: il comando finisce senza errori e `gst_merchant_settings` ha la colonna `low_stock_emails`. Verificalo con:

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale && php -r 'chdir("/Users/andreamarinoni/Developer/boilerplates/ecommerce-site"); $GLOBALS["ROOT"]=getcwd(); require "vendor/autoload.php"; require "vendor/wonder-image/app/wonder-image.php"; var_dump(array_key_exists("low_stock_emails", Wonder\Plugin\Gestionale\Models\System\MerchantSetting::current()));'`
Expected: `bool(true)`

- [ ] **Step 8: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale
git status --short
git add src/Support/Mail/Recipients.php src/Models/System/MerchantSetting.php src/Resources/System/MerchantSettingResource.php lang/it/gestionale.json tests/RecipientsTest.php tests/SettingsTest.php tests/DocsPagesTest.php
git commit -m "$(cat <<'MSG'
feat(impostazioni): Destinatari degli avvisi di scorta, validati; link della guida corretto

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 4: `Mailer`, l'invio che passa dall'hook

**Files:**
- Create: `src/Support/Mail/Mailer.php`
- Create: `tests/MailerTest.php`

**Interfaces:**
- Consumes: `Recipients::parse()` (task 3); `Extensions::filter(string $hook, mixed $value, mixed ...$arguments)` (`Wonder\Plugin\Gestionale\Extensions\Extensions`, chiama `beforeEmailSend(string $key, array $message): array`); `Errors::internal(Throwable, string, array)`.
- Produces: `Mailer::send(string $key, list<string> $to, string $subject, string $body): array{status: 'sent'|'cancelled'|'failed', to: list<string>, sent: list<string>, failed: list<string>}`; le costanti `Mailer::SENT`, `Mailer::CANCELLED`, `Mailer::FAILED`; `Mailer::useTransport(?callable $transport): void` con `$transport(string $to, string $subject, string $body): bool`; `Mailer::shield(string $html): string`.

Tre cose da sapere prima di scrivere:

1. `sendMail()` del core manda a **un** destinatario per chiamata e torna `true`/`false` senza lanciare: il server di posta giù è un `false`.
2. `emailTemplate()` passa il corpo da `sanitizeEcho()`, che toglie le barre (`stripslashes`) e **decodifica le entità**: un `&lt;b&gt;` scritto con cura torna `<b>` e diventa HTML vero. Il nome di un articolo come `Tè <b>verde</b> & co.` arriverebbe grassetto, e un `\` sparirebbe. `shield()` prepara il corpo perché, dopo `sanitizeEcho()`, resti esattamente quello scritto: ogni entità diventa numerica e doppiamente codificata, ogni carattere non ASCII anche (la decodifica del mojibake non ha niente da toccare), ogni barra raddoppia.
3. L'hook `beforeEmailSend` è l'ultima parola del sito: può cambiare oggetto, corpo e destinatari, o fermare l'invio togliendo tutti i destinatari. Un'estensione che lancia viene saltata (`Extensions` la mette nel log) e il messaggio resta quello di prima.

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `tests/MailerTest.php`:

```php
<?php
/** php tests/MailerTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
// Il vero `sanitizeEcho()` del core: `shield()` si misura contro di lui.
require __DIR__ . '/../vendor/wonder-image/app/app/function/string/sanitize.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Extensions\Extensions;
use Wonder\Plugin\Gestionale\Extensions\GestionaleExtension;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;

final class OggettoConChiave extends GestionaleExtension
{
    public function beforeEmailSend(string $key, array $message): array
    {
        $message['subject'] = '['.$key.'] '.$message['subject'];

        return $message;
    }
}

final class NessunDestinatario extends GestionaleExtension
{
    public function beforeEmailSend(string $key, array $message): array
    {
        $message['to'] = [];

        return $message;
    }
}

final class DestinatariAMano extends GestionaleExtension
{
    public function beforeEmailSend(string $key, array $message): array
    {
        $message['to'] = 'capo@negozio.test; scritto-male';

        return $message;
    }
}

final class EstensioneRotta extends GestionaleExtension
{
    public function beforeEmailSend(string $key, array $message): array
    {
        throw new RuntimeException('guasto del sito');
    }
}

/** @var list<array{0: string, 1: string, 2: string}> */
$inviate = [];

$postino = static function (bool $esito = true) use (&$inviate): callable {
    return static function (string $to, string $subject, string $body) use (&$inviate, $esito): bool {
        $inviate[] = [$to, $subject, $body];

        return $esito;
    };
};

$pulisci = static function () use (&$inviate): void {
    $inviate = [];
    Extensions::use([]);
    Mailer::useTransport(null);
};

check('dopo sanitizeEcho il corpo resta quello scritto', fn () =>
    sanitizeEcho(Mailer::shield('<p>a &lt;b&gt; \ &amp; Tè</p>')) === '<p>a &#60;b&#62; \ &#38; T&#232;</p>'
);

check('una & da sola non si mangia il testo che segue', fn () =>
    sanitizeEcho(Mailer::shield('A & B')) === 'A &#38; B'
    && sanitizeEcho(Mailer::shield('&foo; x')) === '&#38;foo; x'
);

check('accenti ed emoji arrivano interi', fn () =>
    sanitizeEcho(Mailer::shield('già è Ü 😀')) === 'gi&#224; &#232; &#220; &#128512;'
);

check('le barre non spariscono', fn () =>
    sanitizeEcho(Mailer::shield('C:\cartella\file')) === 'C:\cartella\file'
);

check('una email per destinatario, con oggetto e corpo', function () use ($postino, $pulisci, &$inviate) {
    $pulisci();
    Mailer::useTransport($postino());
    $esito = Mailer::send('prova', ['a@x.it', 'b@y.it'], 'Oggetto', '<p>Corpo</p>');

    return $esito === ['status' => Mailer::SENT, 'to' => ['a@x.it', 'b@y.it'], 'sent' => ['a@x.it', 'b@y.it'], 'failed' => []]
        && $inviate === [['a@x.it', 'Oggetto', '<p>Corpo</p>'], ['b@y.it', 'Oggetto', '<p>Corpo</p>']];
});

check('l\'hook riceve la chiave dell\'email e può cambiare il messaggio', function () use ($postino, $pulisci, &$inviate) {
    $pulisci();
    Extensions::use([OggettoConChiave::class]);
    Mailer::useTransport($postino());
    Mailer::send('stock.low_stock', ['a@x.it'], '2 prodotti sotto scorta', 'x');

    return ($inviate[0][1] ?? '') === '[stock.low_stock] 2 prodotti sotto scorta';
});

check('un hook che toglie i destinatari ferma l\'invio', function () use ($postino, $pulisci, &$inviate) {
    $pulisci();
    Extensions::use([NessunDestinatario::class]);
    Mailer::useTransport($postino());
    $esito = Mailer::send('prova', ['a@x.it'], 'Oggetto', 'x');

    return $esito['status'] === Mailer::CANCELLED && $esito['to'] === [] && $inviate === [];
});

check('i destinatari scelti dall\'hook si ripuliscono come quelli delle impostazioni', function () use ($postino, $pulisci, &$inviate) {
    $pulisci();
    Extensions::use([DestinatariAMano::class]);
    Mailer::useTransport($postino());
    $esito = Mailer::send('prova', ['a@x.it'], 'Oggetto', 'x');

    return $esito['to'] === ['capo@negozio.test'] && count($inviate) === 1;
});

check('un\'estensione rotta non ferma l\'email', function () use ($postino, $pulisci, &$inviate) {
    $pulisci();
    Extensions::use([EstensioneRotta::class]);
    Mailer::useTransport($postino());
    $esito = Mailer::send('prova', ['a@x.it'], 'Oggetto', 'x');

    return $esito['status'] === Mailer::SENT && ($inviate[0][1] ?? '') === 'Oggetto';
});

check('se il server di posta rifiuta tutto, l\'invio è fallito', function () use ($postino, $pulisci) {
    $pulisci();
    Mailer::useTransport($postino(false));
    $esito = Mailer::send('prova', ['a@x.it', 'b@y.it'], 'Oggetto', 'x');

    return $esito['status'] === Mailer::FAILED && $esito['sent'] === [] && $esito['failed'] === ['a@x.it', 'b@y.it'];
});

check('un destinatario che salta non ferma gli altri', function () use ($pulisci) {
    $pulisci();
    Mailer::useTransport(static function (string $to): bool {
        if ($to === 'a@x.it') {
            throw new RuntimeException('casella piena');
        }

        return true;
    });
    $esito = Mailer::send('prova', ['a@x.it', 'b@y.it'], 'Oggetto', 'x');

    return $esito['status'] === Mailer::SENT && $esito['sent'] === ['b@y.it'] && $esito['failed'] === ['a@x.it'];
});

check('senza il sito avviato non parte niente, e lo dice', function () use ($pulisci) {
    $pulisci();
    $esito = Mailer::send('prova', ['a@x.it'], 'Oggetto', 'x');

    // Qui `sendMail()` non c'è: il test non deve mai mandare email vere.
    return !function_exists('sendMail') && $esito['status'] === Mailer::FAILED;
});

$pulisci();
Extensions::use(null);

summary();
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/MailerTest.php`
Expected: FAIL — `Class "...Support\Mail\Mailer" not found`.

- [ ] **Step 3: Scrivi `Mailer`**

Crea `src/Support/Mail/Mailer.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Mail;

use RuntimeException;
use Throwable;
use Wonder\Plugin\Gestionale\Extensions\Extensions;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;

/**
 * Le email del gestionale, tutte da qui.
 *
 * Prima di partire il messaggio passa dall'hook `beforeEmailSend`: il sito
 * può cambiare oggetto, corpo e destinatari, o fermare l'invio togliendo
 * tutti i destinatari. Poi parte un'email per destinatario, perché così la
 * manda il core; un indirizzo che salta non ferma gli altri.
 *
 * Chi chiama decide cosa fare di un invio fallito: qui si mette solo nel log,
 * indirizzo per indirizzo.
 */
final class Mailer
{
    public const SENT = 'sent';
    public const CANCELLED = 'cancelled';
    public const FAILED = 'failed';

    /** @var (callable(string, string, string): bool)|null */
    private static $transport = null;

    /**
     * @param list<string> $to
     * @return array{status: string, to: list<string>, sent: list<string>, failed: list<string>}
     */
    public static function send(string $key, array $to, string $subject, string $body): array
    {
        $original = ['to' => $to, 'subject' => $subject, 'body' => $body];
        $message = Extensions::filter('beforeEmailSend', $original, $key);
        $message = is_array($message) ? $message + $original : $original;

        $recipients = Recipients::parse(is_array($message['to'])
            ? implode(',', array_map('strval', $message['to']))
            : (string) $message['to'])['valid'];

        if ($recipients === []) {
            return ['status' => self::CANCELLED, 'to' => [], 'sent' => [], 'failed' => []];
        }

        $subject = (string) $message['subject'];
        $body = (string) $message['body'];
        $transport = self::$transport ?? self::defaultTransport();
        $sent = [];
        $failed = [];

        foreach ($recipients as $address) {
            try {
                if ($transport($address, $subject, $body) !== true) {
                    throw new RuntimeException('Il server di posta non ha accettato l\'email.');
                }

                $sent[] = $address;
            } catch (Throwable $error) {
                $failed[] = $address;
                Errors::internal($error, 'mail.'.$key, ['to' => $address]);
            }
        }

        return [
            'status' => $sent === [] ? self::FAILED : self::SENT,
            'to' => $recipients,
            'sent' => $sent,
            'failed' => $failed,
        ];
    }

    /** Cambia il modo di spedire; `null` torna a `sendMail()` del core. Serve ai test. */
    public static function useTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /**
     * Prepara il corpo per `sanitizeEcho()`, che `emailTemplate()` gli passa
     * sopra: toglie le barre e decodifica le entità, e senza questa difesa un
     * `&lt;b&gt;` scritto apposta tornerebbe `<b>`.
     *
     * Dopo `sanitizeEcho()` il corpo resta quello scritto: ogni entità diventa
     * numerica (`&amp;#60;` → `&#60;`), ogni carattere non ASCII anche, ogni
     * barra raddoppia (`stripslashes` ne toglie una).
     */
    public static function shield(string $html): string
    {
        $html = mb_scrub($html, 'UTF-8');
        $html = str_replace('\\', '\\\\', $html);
        $html = (string) preg_replace_callback(
            '/&(#[0-9]+|#[xX][0-9a-fA-F]+|[A-Za-z][A-Za-z0-9]*);|&/',
            static function (array $m): string {
                if ($m[0] === '&') {
                    return '&amp;#38;';
                }

                $decoded = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // Un'entità che nessuno conosce è testo: resta com'è scritta.
                if ($decoded === $m[0]) {
                    return '&amp;#38;'.substr($m[0], 1);
                }

                $out = '';

                foreach (mb_str_split($decoded, 1, 'UTF-8') as $char) {
                    $out .= '&amp;#'.mb_ord($char, 'UTF-8').';';
                }

                return $out;
            },
            $html
        );

        return (string) preg_replace_callback(
            '/[^\x00-\x7F]/u',
            static fn (array $m): string => '&amp;#'.mb_ord($m[0], 'UTF-8').';',
            $html
        );
    }

    /** @return callable(string, string, string): bool */
    private static function defaultTransport(): callable
    {
        return static function (string $to, string $subject, string $body): bool {
            // Le funzioni globali del core ci sono solo nel sito avviato:
            // non in un comando `forge`, non nei test.
            if (!function_exists('sendMail')) {
                throw new RuntimeException('Le email partono solo dal sito avviato: qui manca sendMail().');
            }

            return (bool) sendMail('', $to, $subject, self::shield($body));
        };
    }
}
```

- [ ] **Step 4: Esegui il test e verifica che passi**

Run: `php tests/MailerTest.php && php tests/ExtensionsTest.php`
Expected: PASS su tutti e due.

- [ ] **Step 5: Commit**

```bash
git status --short
git add src/Support/Mail/Mailer.php tests/MailerTest.php
git commit -m "$(cat <<'MSG'
feat(email): Mailer con l'hook beforeEmailSend e la difesa da sanitizeEcho

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 5: l'elenco sotto scorta e la sua email

**Files:**
- Create: `src/Support/Stock/ProductNames.php`
- Create: `src/Support/Stock/LowStockReport.php`
- Create: `src/Support/Stock/LowStockEmail.php`
- Create: `view/emails/low-stock.php`
- Create: `tests/LowStockEmailTest.php`

**Interfaces:**
- Consumes: `Levels::forProducts(list<int>)`, `Gestionale::viewPath(string)`, la costante `APP_URL` del core (se c'è).
- Produces:
  - `ProductNames::models(array<int, array> $products): array<int, string>` (i nomi degli articoli di questi prodotti, per id del modello) e `ProductNames::of(array $product, array<int, string> $modelNames): array{article: string, option: string}` (pura: `'—'` se l'articolo non c'è, opzione vuota se ripete il nome dell'articolo) — il task 9 li riusa;
  - `LowStockReport::pending(): list<array>` (avvisi aperti mai mandati) e `LowStockReport::open(): list<array>` (tutti gli aperti);
  - `LowStockReport::products(list<array> $alerts): array<int, array>` (le righe dei prodotti, per id; quelle tolte dalla griglia il core le tiene fuori da sé);
  - `LowStockReport::items(list<array> $alerts, array<int, array> $products): list<item>`;
  - `LowStockReport::build(list<array> $alerts, array<int, array> $products, array<int, string> $modelNames, array<int, array{available: float}> $levels): list<item>` (pura);
  - `LowStockReport::orphans(list<array> $alerts, array<int, array> $products): list<int>` (pura: gli id degli avvisi di prodotti spariti o tolti dalla griglia);
  - dove `item` è `array{product_id: int, article: string, option: string, sku: string, threshold: float, available: float}`;
  - `LowStockEmail::VIEW` (`'emails/low-stock.php'`), `LowStockEmail::subject(int $count): string`, `LowStockEmail::compose(list<item> $items, string $url): array{subject: string, body: string}`, `LowStockEmail::absoluteUrl(string $path): string`, `LowStockEmail::quantity(float $value): string`.

`build()` tiene solo quello che è vero adesso: un prodotto sparito o tolto dalla griglia non si segnala, e nemmeno uno che nel frattempo è tornato sopra la soglia (scadenze degli impegni, soglia portata a zero dal database). Un prodotto con due avvisi aperti (non dovrebbe succedere, ma la tabella non lo vieta) compare una volta. La soglia è quella di adesso sul prodotto, il disponibile quello di adesso: l'email dice come stanno le cose quando parte, non quando l'avviso è nato.

La vista vive nel modulo e il sito la sostituisce copiandola in `custom/modules/gestionale/view/emails/low-stock.php`: riceve le righe già pronte e due funzioni, `$e()` per il testo nell'HTML e `$qty()` per i pezzi.

- [ ] **Step 1: Scrivi il test che fallisce**

Crea `tests/LowStockEmailTest.php`:

```php
<?php
/** php tests/LowStockEmailTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\LowStockEmail;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockReport;
use Wonder\Plugin\Gestionale\Support\Stock\ProductNames;

// Il sito avviato la definisce da `.env`: qui la mettiamo noi.
define('APP_URL', 'https://negozio.test/');

$avvisi = [
    ['id' => 11, 'product_id' => 1, 'threshold' => '5.000'],
    ['id' => 12, 'product_id' => 2, 'threshold' => '3.000'],
    ['id' => 13, 'product_id' => 3, 'threshold' => '2.000'],
    ['id' => 14, 'product_id' => 4, 'threshold' => '2.000'],
    ['id' => 15, 'product_id' => 1, 'threshold' => '5.000'],
    ['id' => 16, 'product_id' => 5, 'threshold' => '4.000'],
];
$prodotti = [
    1 => ['id' => 1, 'product_model_id' => 10, 'name' => 'Rossa, M', 'sku' => 'MAG-R-M', 'min_stock_quantity' => '6.000', 'deleted' => 'false'],
    2 => ['id' => 2, 'product_model_id' => 20, 'name' => 'Borraccia', 'sku' => 'BOR', 'min_stock_quantity' => '3.000', 'deleted' => 'false'],
    3 => ['id' => 3, 'product_model_id' => 10, 'name' => 'Blu, S', 'sku' => 'MAG-B-S', 'min_stock_quantity' => '2.000', 'deleted' => 'true'],
    5 => ['id' => 5, 'product_model_id' => 20, 'name' => 'Tappo', 'sku' => 'TAP', 'min_stock_quantity' => '4.000', 'deleted' => 'false'],
];
$nomi = [10 => 'Maglia', 20 => 'Borraccia'];
$livelli = [1 => ['available' => 2.0], 2 => ['available' => 2.5], 5 => ['available' => 9.0]];

check('nell\'email solo i prodotti che ci sono e sono ancora sotto la soglia, una volta', fn () =>
    array_column(LowStockReport::build($avvisi, $prodotti, $nomi, $livelli), 'product_id') === [2, 1]
);

check('ogni riga dice articolo, opzione, SKU, soglia e disponibile di adesso', function () use ($avvisi, $prodotti, $nomi, $livelli) {
    $righe = LowStockReport::build($avvisi, $prodotti, $nomi, $livelli);

    return $righe[1] === [
        'product_id' => 1,
        'article' => 'Maglia',
        'option' => 'Rossa, M',
        'sku' => 'MAG-R-M',
        'threshold' => 6.0,
        'available' => 2.0,
    ];
});

check('un articolo senza varianti non ripete il nome come opzione', function () use ($avvisi, $prodotti, $nomi, $livelli) {
    $righe = LowStockReport::build($avvisi, $prodotti, $nomi, $livelli);

    return $righe[0]['article'] === 'Borraccia' && $righe[0]['option'] === '';
});

check('articolo e opzione si leggono come nella griglia', fn () =>
    ProductNames::of(['product_model_id' => 10, 'name' => 'Rossa, M'], [10 => 'Maglia']) === ['article' => 'Maglia', 'option' => 'Rossa, M']
    && ProductNames::of(['product_model_id' => 20, 'name' => 'borraccia'], [20 => 'Borraccia']) === ['article' => 'Borraccia', 'option' => '']
    && ProductNames::of(['product_model_id' => 99, 'name' => 'Sola'], []) === ['article' => '—', 'option' => 'Sola']
);

check('gli avvisi di prodotti spariti o tolti dalla griglia sono da chiudere', fn () =>
    LowStockReport::orphans($avvisi, $prodotti) === [13, 14]
);

check('l\'oggetto conta i prodotti', fn () =>
    LowStockEmail::subject(1) === '1 prodotto sotto scorta'
    && LowStockEmail::subject(3) === '3 prodotti sotto scorta'
);

check('i pezzi si scrivono come li legge una persona', fn () =>
    LowStockEmail::quantity(3.0) === '3'
    && LowStockEmail::quantity(2.5) === '2,5'
    && LowStockEmail::quantity(-1.0) === '-1'
    && LowStockEmail::quantity(20.0) === '20'
    && LowStockEmail::quantity(1.25) === '1,25'
);

check('il link dell\'email porta al sito, non a un percorso', fn () =>
    LowStockEmail::absoluteUrl('/backend/giacenze?sotto=1') === 'https://negozio.test/backend/giacenze?sotto=1'
    && LowStockEmail::absoluteUrl('https://altro.test/x') === 'https://altro.test/x'
);

$riga = [
    'product_id' => 7,
    'article' => 'Tè <b>verde</b> & co.',
    'option' => '',
    'sku' => 'TE-1',
    'threshold' => 5.0,
    'available' => 2.5,
];

check('l\'email ha oggetto, righe e link', function () use ($riga) {
    $email = LowStockEmail::compose([$riga], 'https://negozio.test/backend/giacenze?sotto=1');

    return $email['subject'] === '1 prodotto sotto scorta'
        && str_contains($email['body'], 'TE-1')
        && str_contains($email['body'], '2,5')
        && str_contains($email['body'], 'href="https://negozio.test/backend/giacenze?sotto=1"');
});

check('i nomi degli articoli arrivano come testo, non come HTML', function () use ($riga) {
    $body = LowStockEmail::compose([$riga], 'https://negozio.test/x')['body'];

    return str_contains($body, 'Tè &lt;b&gt;verde&lt;/b&gt; &amp; co.') && !str_contains($body, '<b>verde</b>');
});

check('il sito può riscrivere l\'email copiando la vista', function () use ($riga) {
    $root = sys_get_temp_dir().'/gst-email-'.uniqid();
    $vista = $root.'/custom/modules/gestionale/view/'.LowStockEmail::VIEW;
    mkdir(dirname($vista), 0777, true);
    file_put_contents($vista, '<p>Personalizzata: <?= $count ?> <?= $e($items[0][\'sku\']) ?></p>');

    $prima = $GLOBALS['ROOT'] ?? null;
    $GLOBALS['ROOT'] = $root;

    try {
        $body = LowStockEmail::compose([$riga], 'https://negozio.test/x')['body'];
    } finally {
        $GLOBALS['ROOT'] = $prima;
        unlink($vista);
    }

    return $body === '<p>Personalizzata: 1 TE-1</p>';
});

summary();
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `php tests/LowStockEmailTest.php`
Expected: FAIL — `Class "...Support\Stock\LowStockReport" not found`.

- [ ] **Step 3: Scrivi `ProductNames` e `LowStockReport`**

Crea `src/Support/Stock/ProductNames.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;

/**
 * Come si chiama un prodotto fuori dalla sua scheda: l'articolo e l'opzione.
 *
 * L'email della scorta, la home e *Da controllare* lo scrivono tutti allo
 * stesso modo, "Maglia — Rossa, M", e un articolo senza varianti non ripete
 * il suo nome due volte.
 */
final class ProductNames
{
    /**
     * I nomi degli articoli di questi prodotti, per id del modello.
     *
     * @param array<int, array<string, mixed>> $products
     * @return array<int, string>
     */
    public static function models(array $products): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['product_model_id'] ?? 0),
            $products
        ))));

        if ($ids === []) {
            return [];
        }

        try {
            $rows = ProductModel::find('id IN ('.implode(',', $ids).')');
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $names = [];

        foreach (isset($rows['id']) ? [$rows] : array_filter($rows, 'is_array') as $row) {
            $names[(int) $row['id']] = trim((string) ($row['name'] ?? ''));
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, string> $modelNames
     * @return array{article: string, option: string}
     */
    public static function of(array $product, array $modelNames): array
    {
        $article = $modelNames[(int) ($product['product_model_id'] ?? 0)] ?? '';
        $article = $article !== '' ? $article : '—';
        $option = trim((string) ($product['name'] ?? ''));

        return [
            'article' => $article,
            // L'unica versione di un articolo senza varianti ha il suo nome.
            'option' => mb_strtolower($option, 'UTF-8') === mb_strtolower($article, 'UTF-8') ? '' : $option,
        ];
    }
}
```

Crea `src/Support/Stock/LowStockReport.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;

/**
 * I prodotti sotto scorta minima, come li leggono l'email e la home.
 *
 * Gli avvisi dicono chi è sceso sotto la soglia; le righe dicono come stanno
 * le cose adesso. `build()` tiene solo quello che è ancora vero: un prodotto
 * sparito, tolto dalla griglia o tornato sopra la soglia non si segnala.
 */
final class LowStockReport
{
    /** Gli avvisi aperti che nessuno ha ancora mandato. @return list<array<string, mixed>> */
    public static function pending(): array
    {
        return self::rows(StockAlert::class, "deleted = 'false' AND resolved_at IS NULL AND notified_at IS NULL");
    }

    /** Tutti gli avvisi aperti, mandati o no. @return list<array<string, mixed>> */
    public static function open(): array
    {
        return self::rows(StockAlert::class, "deleted = 'false' AND resolved_at IS NULL");
    }

    /**
     * I prodotti di questi avvisi, per id.
     *
     * Il core aggiunge da sé `deleted = 'false'` a ogni lettura che non nomina
     * `deleted`: un prodotto tolto dalla griglia qui non c'è, e per
     * `orphans()` è sparito. Va bene così: il suo avviso si chiude.
     *
     * @param list<array<string, mixed>> $alerts
     * @return array<int, array<string, mixed>>
     */
    public static function products(array $alerts): array
    {
        $ids = self::productIds($alerts);

        if ($ids === []) {
            return [];
        }

        $products = [];

        foreach (self::rows(Product::class, 'id IN ('.implode(',', $ids).')') as $row) {
            $products[(int) $row['id']] = $row;
        }

        return $products;
    }

    /**
     * @param list<array<string, mixed>> $alerts
     * @param array<int, array<string, mixed>> $products
     * @return list<array{product_id: int, article: string, option: string, sku: string, threshold: float, available: float}>
     */
    public static function items(array $alerts, array $products): array
    {
        return self::build(
            $alerts,
            $products,
            ProductNames::models($products),
            Levels::forProducts(array_keys($products))
        );
    }

    /**
     * Le righe da mostrare, pure: nessuna lettura qui dentro.
     *
     * @param list<array<string, mixed>> $alerts
     * @param array<int, array<string, mixed>> $products
     * @param array<int, string> $modelNames
     * @param array<int, array{available: float}> $levels
     * @return list<array{product_id: int, article: string, option: string, sku: string, threshold: float, available: float}>
     */
    public static function build(array $alerts, array $products, array $modelNames, array $levels): array
    {
        $items = [];

        foreach ($alerts as $alert) {
            $id = (int) ($alert['product_id'] ?? 0);
            $product = $products[$id] ?? null;

            if (isset($items[$id]) || !is_array($product) || ($product['deleted'] ?? 'false') === 'true') {
                continue;
            }

            $threshold = round((float) ($product['min_stock_quantity'] ?? 0), 3);
            $available = round((float) ($levels[$id]['available'] ?? 0), 3);

            // Tornato sopra la soglia, o soglia tolta: non è più una notizia.
            if (LowStock::decide($threshold, $available, false) !== LowStock::OPEN) {
                continue;
            }

            $names = ProductNames::of($product, $modelNames);

            $items[$id] = [
                'product_id' => $id,
                'article' => $names['article'],
                'option' => $names['option'],
                'sku' => (string) ($product['sku'] ?? ''),
                'threshold' => $threshold,
                'available' => $available,
            ];
        }

        usort($items, static fn (array $a, array $b): int =>
            [mb_strtolower($a['article'], 'UTF-8'), mb_strtolower($a['option'], 'UTF-8'), $a['sku']]
            <=> [mb_strtolower($b['article'], 'UTF-8'), mb_strtolower($b['option'], 'UTF-8'), $b['sku']]
        );

        return array_values($items);
    }

    /**
     * Gli avvisi di prodotti che non ci sono più, o che sono stati tolti dalla
     * griglia: nessuna email li deve nominare, e restano aperti per sempre se
     * nessuno li chiude.
     *
     * @param list<array<string, mixed>> $alerts
     * @param array<int, array<string, mixed>> $products
     * @return list<int>
     */
    public static function orphans(array $alerts, array $products): array
    {
        $ids = [];

        foreach ($alerts as $alert) {
            $product = $products[(int) ($alert['product_id'] ?? 0)] ?? null;

            if (!is_array($product) || ($product['deleted'] ?? 'false') === 'true') {
                $ids[] = (int) $alert['id'];
            }
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $alerts
     * @return list<int>
     */
    private static function productIds(array $alerts): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['product_id'] ?? 0),
            $alerts
        ))));
    }

    /**
     * @param class-string<\Wonder\App\Model> $modelClass
     * @return list<array<string, mixed>>
     */
    private static function rows(string $modelClass, string $condition): array
    {
        try {
            $rows = $modelClass::find($condition);
        } catch (Throwable) {
            // Tabelle non ancora create: niente da segnalare.
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
```

- [ ] **Step 4: Scrivi `LowStockEmail` e la vista**

Crea `src/Support/Stock/LowStockEmail.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * L'email dei prodotti sotto scorta minima: oggetto e corpo.
 *
 * Il corpo viene dalla vista `view/emails/low-stock.php`, che il sito
 * sostituisce copiandola in `custom/modules/gestionale/view/`. Qui si
 * preparano le righe e il link; la vista decide solo come mostrarli.
 */
final class LowStockEmail
{
    public const VIEW = 'emails/low-stock.php';

    public static function subject(int $count): string
    {
        return $count === 1 ? '1 prodotto sotto scorta' : $count.' prodotti sotto scorta';
    }

    /**
     * @param list<array{product_id: int, article: string, option: string, sku: string, threshold: float, available: float}> $items
     * @return array{subject: string, body: string}
     */
    public static function compose(array $items, string $url): array
    {
        return [
            'subject' => self::subject(count($items)),
            'body' => self::render(Gestionale::viewPath(self::VIEW), [
                'items' => $items,
                'count' => count($items),
                'url' => $url,
                'e' => static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
                'qty' => static fn (mixed $value): string => self::quantity((float) $value),
            ]),
        ];
    }

    /** Un'email si apre fuori dal sito: il link deve avere il dominio. */
    public static function absoluteUrl(string $path): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        $base = defined('APP_URL') ? rtrim((string) constant('APP_URL'), '/') : '';

        return $base.'/'.ltrim($path, '/');
    }

    /** I pezzi interi si scrivono interi: "3", non "3,000"; gli altri senza zeri in coda. */
    public static function quantity(float $value): string
    {
        if (round($value, 3) === round($value, 0)) {
            return number_format($value, 0, ',', '');
        }

        return rtrim(rtrim(number_format($value, 3, ',', ''), '0'), ',');
    }

    /** @param array<string, mixed> $variables */
    private static function render(string $file, array $variables): string
    {
        extract($variables, EXTR_SKIP);
        ob_start();

        try {
            require $file;
        } catch (Throwable $error) {
            ob_end_clean();

            throw $error;
        }

        return (string) ob_get_clean();
    }
}
```

Crea `view/emails/low-stock.php`:

```php
<?php
/**
 * L'email dei prodotti sotto scorta minima.
 *
 * Per cambiarla, copia questo file in
 * `custom/modules/gestionale/view/emails/low-stock.php` e riscrivilo: il
 * gestionale usa la copia del sito se c'è.
 *
 * Variabili:
 * - `$items`: le righe, ognuna con `product_id`, `article`, `option` (vuota
 *   per gli articoli senza varianti), `sku`, `threshold`, `available`;
 * - `$count`: quante sono;
 * - `$url`: l'indirizzo completo dell'elenco Giacenze, già filtrato sui
 *   prodotti sotto scorta;
 * - `$e($testo)`: il testo pronto per stare nell'HTML — usalo su tutto quello
 *   che viene dal catalogo;
 * - `$qty($numero)`: i pezzi come li legge una persona ("3", "2,5").
 *
 * @var list<array{product_id: int, article: string, option: string, sku: string, threshold: float, available: float}> $items
 * @var int $count
 * @var string $url
 * @var callable(mixed): string $e
 * @var callable(mixed): string $qty
 */
?>
<p><?= $count === 1 ? 'Questo prodotto è sceso' : 'Questi prodotti sono scesi' ?> sotto la scorta minima.</p>
<table cellpadding="6" cellspacing="0" border="0" style="width: 100%; border-collapse: collapse;">
    <?php foreach ($items as $item) { ?>
    <tr style="border-bottom: 1px solid #e5e5e5;">
        <td>
            <b><?= $e($item['article']) ?></b><?php if ($item['option'] !== '') { ?> — <?= $e($item['option']) ?><?php } ?>
            <?php if ($item['sku'] !== '') { ?><br><small style="color: #777;"><?= $e($item['sku']) ?></small><?php } ?>
        </td>
        <td style="text-align: right; white-space: nowrap;">
            disponibili <b><?= $e($qty($item['available'])) ?></b><br>
            <small style="color: #777;">scorta minima <?= $e($qty($item['threshold'])) ?></small>
        </td>
    </tr>
    <?php } ?>
</table>
<p><a href="<?= $e($url) ?>">Apri le giacenze sotto scorta</a></p>
<p style="color: #777;"><small>Per ogni prodotto l'avviso arriva una volta sola: torna solo se il prodotto risale sopra la soglia e poi ci ricade.</small></p>
```

- [ ] **Step 5: Esegui il test e verifica che passi**

Run: `php tests/LowStockEmailTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git status --short
git add src/Support/Stock/ProductNames.php src/Support/Stock/LowStockReport.php src/Support/Stock/LowStockEmail.php view/emails/low-stock.php tests/LowStockEmailTest.php
git commit -m "$(cat <<'MSG'
feat(magazzino): elenco dei prodotti sotto scorta e la sua email, con vista sostituibile

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 6: `LowStockNotifier`, il giro che manda l'email

**Files:**
- Create: `src/Support/Stock/LowStockNotifier.php`
- Modify: `tests/integrazione/LowStockTest.php`

**Interfaces:**
- Consumes: `LowStockReport::open()`, `::pending()`, `::products()`, `::orphans()`, `::items()` e `LowStockEmail::compose()`, `::absoluteUrl()` (task 5); `Mailer::send()`, `Mailer::useTransport()`, `Mailer::FAILED` (task 4); `Recipients::parse()` e la colonna `low_stock_emails` (task 3); `StockLevelResource::lowStockUrl()` (task 2); `Alerts::refresh(int)`; `Gestionale::feature('low_stock_alerts')`; `Errors::report(string $service, string $action, Throwable|string $error, array $context = []): bool`.
- Produces: `LowStockNotifier::run(bool $preview = false): array{status: string, items: list<item>, resolved: int, closed: int, to: list<string>, sent: list<string>, failed: list<string>, subject: string}` con `status` fra `LowStockNotifier::DISABLED` (`'disabled'`), `::NOTHING` (`'nothing'`), `::NO_RECIPIENTS` (`'no_recipients'`), `::PREVIEW` (`'preview'`) e i tre di `Mailer`; `LowStockNotifier::KEY` (`'stock.low_stock'`, la chiave che riceve `beforeEmailSend`); `LowStockNotifier::reportUsing(?callable $reporter): void` con `$reporter(string $message, array $context): void`. Il task 7 li usa.

Un giro, nell'ordine:

1. funzionalità spenta → `disabled`, non si tocca niente;
2. si leggono **tutti** gli avvisi aperti, non solo quelli da mandare;
3. gli avvisi di prodotti spariti o tolti dalla griglia si chiudono (`resolved_at`): nessuno li chiuderebbe più;
4. gli avvisi aperti di prodotti tornati sopra la soglia senza un movimento (un impegno scaduto, la soglia cambiata dal database) passano da `Alerts::refresh()`, che li chiude. Senza questo passo l'avviso resterebbe aperto e, alla prossima discesa, `Stock::apply()` non ne aprirebbe uno nuovo: il commerciante non saprebbe niente;
5. nell'email vanno solo i prodotti ancora sotto la soglia **con un avviso mai mandato**;
6. nessuno → `nothing`; nessun destinatario valido → `no_recipients`, e gli avvisi aspettano: appena qualcuno scrive un indirizzo nelle impostazioni, l'email parte al giro dopo;
7. l'anteprima si ferma qui: dice cosa partirebbe e a chi, e non scrive niente — nemmeno i passi 3 e 4;
8. `sent` o `cancelled` (l'hook ha fermato l'invio: è una scelta del sito, non un guasto) → gli avvisi mandati prendono `notified_at` e non tornano più;
9. `failed` → gli avvisi restano da mandare e si riprova al giro dopo; la segnalazione va a chi sviluppa con un **messaggio fisso**, così il registro degli errori del core conta le ripetizioni sulla stessa riga invece di aprirne una ogni quarto d'ora;
10. qualche destinatario sì e qualcuno no → gli avvisi si segnano come mandati (ripetere l'email a chi l'ha già ricevuta sarebbe peggio) e si segnala chi non l'ha ricevuta.

Attenzione al punto 9: il registro del core, a ogni ripetizione, scrive di nuovo a chi sviluppa. Se il server di posta è giù non parte nemmeno quella; se invece rifiuta sempre lo stesso indirizzo, arriva un'email tecnica ogni quarto d'ora finché qualcuno non corregge. Va scritto nella guida per chi sviluppa (task 10).

- [ ] **Step 1: Scrivi i test che falliscono**

In `tests/integrazione/LowStockTest.php` aggiungi fra gli `use`:

```php
use Wonder\Plugin\Gestionale\Extensions\Extensions;
use Wonder\Plugin\Gestionale\Extensions\GestionaleExtension;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockNotifier;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockReport;
```

subito dopo `final class Annulla extends RuntimeException {}`:

```php
final class FermaLaScorta extends GestionaleExtension
{
    public function beforeEmailSend(string $key, array $message): array
    {
        if ($key === LowStockNotifier::KEY) {
            $message['to'] = [];
        }

        return $message;
    }
}

// Qui il sito è avviato e `sendMail()` c'è: senza un postino finto partirebbero
// email vere. Chi dimentica di metterne uno lo scopre subito.
Mailer::useTransport(static fn (): bool => throw new LogicException('Nessuna email vera dai test.'));
// Le estensioni del sito non devono cambiare le email dei test.
Extensions::use([]);

/** @var list<array{0: string, 1: string}> */
$posta = [];
/** @var list<string> */
$segnalazioni = [];

/** Un postino finto che risponde sempre così. */
function postino(bool $esito): void
{
    Mailer::useTransport(static function (string $to, string $subject) use ($esito): bool {
        $GLOBALS['posta'][] = [$to, $subject];

        return $esito;
    });
}

LowStockNotifier::reportUsing(static function (string $message): void {
    $GLOBALS['segnalazioni'][] = $message;
});

/**
 * Prepara un giro pulito dentro la transazione: gli avvisi del sito già da
 * mandare si segnano come mandati, così nell'email ci sono solo quelli della
 * prova; e i destinatari sono quelli chiesti.
 */
function giroDiProva(string $destinatari): void
{
    foreach (LowStockReport::pending() as $avviso) {
        StockAlert::update(['notified_at' => date('Y-m-d H:i:s')], (int) $avviso['id']);
    }

    // Dritto sulla tabella: la riga delle impostazioni è una sola, la 1.
    MerchantSetting::query()->Update(MerchantSetting::$table, ['low_stock_emails' => $destinatari], 'id', 1);
    $GLOBALS['posta'] = [];
    $GLOBALS['segnalazioni'] = [];
}

/** L'articolo di prova con la giacenza sotto la scorta: l'avviso è aperto. */
function sottoScorta(string $sku): int
{
    [$modelId, $productId] = articoloDiProva($sku, '3');
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_min_stock' => '5'], $sku);

    return $productId;
}
```

e prima di `summary();` (in fondo, dopo tutti gli altri controlli):

```php
check('una email sola per i prodotti sotto scorta, poi più niente', fn () => annullando(function (): bool {
    giroDiProva('magazzino@negozio.test, capo@negozio.test');
    postino(true);
    sottoScorta('LOW-10');
    sottoScorta('LOW-11');

    $primo = LowStockNotifier::run();
    $email = $GLOBALS['posta'];
    $secondo = LowStockNotifier::run();

    return $primo['status'] === Mailer::SENT
        && array_column($primo['items'], 'sku') === ['LOW-10', 'LOW-11']
        && $email === [
            ['magazzino@negozio.test', '2 prodotti sotto scorta'],
            ['capo@negozio.test', '2 prodotti sotto scorta'],
        ]
        && $secondo['status'] === LowStockNotifier::NOTHING
        && count($GLOBALS['posta']) === 2;
}));

check('senza destinatari l\'avviso aspetta, e parte appena ce n\'è uno', fn () => annullando(function (): bool {
    giroDiProva('');
    postino(true);
    $productId = sottoScorta('LOW-12');

    $senza = LowStockNotifier::run();
    $aspetta = trim((string) (Alerts::openRow($productId)['notified_at'] ?? '')) === '';
    MerchantSetting::query()->Update(MerchantSetting::$table, ['low_stock_emails' => 'magazzino@negozio.test'], 'id', 1);
    $con = LowStockNotifier::run();

    return $senza['status'] === LowStockNotifier::NO_RECIPIENTS
        && $aspetta
        && $GLOBALS['posta'] === [['magazzino@negozio.test', '1 prodotto sotto scorta']]
        && $con['status'] === Mailer::SENT;
}));

check('se la posta non parte si riprova al giro dopo, e lo sa chi sviluppa', fn () => annullando(function (): bool {
    giroDiProva('magazzino@negozio.test');
    postino(false);
    $productId = sottoScorta('LOW-13');

    $fallito = LowStockNotifier::run();
    $aspetta = trim((string) (Alerts::openRow($productId)['notified_at'] ?? '')) === '';
    postino(true);
    $riuscito = LowStockNotifier::run();

    return $fallito['status'] === Mailer::FAILED
        && $fallito['failed'] === ['magazzino@negozio.test']
        && count($GLOBALS['segnalazioni']) === 1
        && $aspetta
        && $riuscito['status'] === Mailer::SENT
        && trim((string) (Alerts::openRow($productId)['notified_at'] ?? '')) !== '';
}));

check('se il sito ferma l\'email con l\'hook, l\'avviso non torna a ogni giro', fn () => annullando(function (): bool {
    giroDiProva('magazzino@negozio.test');
    postino(true);
    Extensions::use([FermaLaScorta::class]);

    try {
        $productId = sottoScorta('LOW-14');
        $esito = LowStockNotifier::run();
    } finally {
        Extensions::use([]);
    }

    return $esito['status'] === Mailer::CANCELLED
        && $GLOBALS['posta'] === []
        && trim((string) (Alerts::openRow($productId)['notified_at'] ?? '')) !== '';
}));

check('l\'avviso di un prodotto tolto dalla griglia si chiude e non si manda', fn () => annullando(function (): bool {
    giroDiProva('magazzino@negozio.test');
    postino(true);
    $productId = sottoScorta('LOW-15');
    $avviso = Alerts::openRow($productId);
    // Come lo toglie il backend: `Resource` scrive `deleted = 'true'` sulla riga.
    Product::query()->Update(Product::$table, ['deleted' => 'true'], 'id', $productId);

    $esito = LowStockNotifier::run();
    $riga = StockAlert::findById((int) $avviso['id']);

    return $esito['status'] === LowStockNotifier::NOTHING
        && $esito['resolved'] >= 1
        && $GLOBALS['posta'] === []
        && trim((string) ($riga['resolved_at'] ?? '')) !== '';
}));

check('un prodotto tornato sopra la soglia senza movimenti non si segnala, e l\'avviso si chiude', fn () => annullando(function (): bool {
    giroDiProva('magazzino@negozio.test');
    postino(true);
    $productId = sottoScorta('LOW-16');
    // La soglia cambiata senza passare dalla scheda: nessuno chiude l'avviso.
    Product::query()->Update(Product::$table, ['min_stock_quantity' => '1.000'], 'id', $productId);

    $esito = LowStockNotifier::run();

    return $esito['status'] === LowStockNotifier::NOTHING
        && $esito['closed'] >= 1
        && $GLOBALS['posta'] === []
        && Alerts::openRow($productId) === [];
}));

check('l\'anteprima dice cosa partirebbe e non manda né scrive niente', fn () => annullando(function (): bool {
    giroDiProva('magazzino@negozio.test');
    postino(true);
    $productId = sottoScorta('LOW-17');

    $esito = LowStockNotifier::run(true);

    return $esito['status'] === LowStockNotifier::PREVIEW
        && array_column($esito['items'], 'sku') === ['LOW-17']
        && $esito['to'] === ['magazzino@negozio.test']
        && $esito['subject'] === '1 prodotto sotto scorta'
        && $GLOBALS['posta'] === []
        && trim((string) (Alerts::openRow($productId)['notified_at'] ?? '')) === '';
}));

check('a funzionalità spenta non parte niente', fn () => annullando(function () use ($stato): bool {
    giroDiProva('magazzino@negozio.test');
    postino(true);
    sottoScorta('LOW-18');
    $stato->setValue(null, array_merge((array) $stato->getValue(), ['low_stock_alerts' => false]));

    try {
        $esito = LowStockNotifier::run();
    } finally {
        $stato->setValue(null, array_merge((array) $stato->getValue(), ['low_stock_alerts' => true]));
    }

    return $esito['status'] === LowStockNotifier::DISABLED && $GLOBALS['posta'] === [];
}));

Extensions::use(null);
Mailer::useTransport(null);
LowStockNotifier::reportUsing(null);
```

Un dettaglio sul primo controllo: gli articoli di prova si chiamano `Prova scorta LOW-10` e `Prova scorta LOW-11`, e l'elenco è in ordine di articolo: `LOW-10` viene prima.

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `php tests/integrazione/LowStockTest.php`
Expected: FAIL — `Class "...Support\Stock\LowStockNotifier" not found`.

- [ ] **Step 3: Scrivi `LowStockNotifier`**

Crea `src/Support/Stock/LowStockNotifier.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\Plugin\Gestionale\Resources\Stock\StockLevelResource;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Mail\Recipients;

/**
 * Il giro degli avvisi di scorta minima: una email sola per tutti i prodotti
 * scesi sotto la soglia dall'ultimo giro.
 *
 * Lo fa girare l'attività `gestionale.stock_alerts` dello scheduler, mai il
 * salvataggio: dieci rettifiche di fila fanno un'email, e il magazzino non
 * aspetta il server di posta.
 *
 * Ogni avviso si manda una volta. Se la posta non parte si riprova al giro
 * dopo; se il sito ferma l'email con l'hook, è una scelta sua e l'avviso si
 * considera mandato.
 */
final class LowStockNotifier
{
    /** La chiave che l'hook `beforeEmailSend` riceve per questa email. */
    public const KEY = 'stock.low_stock';

    public const DISABLED = 'disabled';
    public const NOTHING = 'nothing';
    public const NO_RECIPIENTS = 'no_recipients';
    public const PREVIEW = 'preview';

    /** Sempre lo stesso: il registro del core conta le ripetizioni sulla stessa riga. */
    private const FAILURE = 'L\'email degli avvisi di scorta minima non è partita.';

    /** @var (callable(string, array): void)|null */
    private static $reporter = null;

    /**
     * @return array{status: string, items: list<array>, resolved: int, closed: int, to: list<string>, sent: list<string>, failed: list<string>, subject: string}
     */
    public static function run(bool $preview = false): array
    {
        $result = [
            'status' => self::NOTHING,
            'items' => [],
            'resolved' => 0,
            'closed' => 0,
            'to' => [],
            'sent' => [],
            'failed' => [],
            'subject' => '',
        ];

        if (!Gestionale::feature('low_stock_alerts')) {
            return ['status' => self::DISABLED] + $result;
        }

        $open = LowStockReport::open();

        if ($open === []) {
            return $result;
        }

        $products = LowStockReport::products($open);
        $orphans = LowStockReport::orphans($open, $products);
        $current = LowStockReport::items($open, $products);
        $low = array_column($current, 'product_id');

        /** @var array<int, list<int>> $pending avvisi mai mandati, per prodotto */
        $pending = [];
        /** @var array<int, true> $stale prodotti con l'avviso aperto ma non più sotto la soglia */
        $stale = [];

        foreach ($open as $alert) {
            $alertId = (int) $alert['id'];
            $productId = (int) ($alert['product_id'] ?? 0);

            if (in_array($alertId, $orphans, true)) {
                continue;
            }

            if (!in_array($productId, $low, true)) {
                $stale[$productId] = true;

                continue;
            }

            if (trim((string) ($alert['notified_at'] ?? '')) === '') {
                $pending[$productId][] = $alertId;
            }
        }

        $result['resolved'] = count($orphans);
        $result['closed'] = count($stale);

        if (!$preview) {
            $now = date('Y-m-d H:i:s');

            foreach ($orphans as $alertId) {
                StockAlert::update(['resolved_at' => $now], $alertId);
            }

            foreach (array_keys($stale) as $productId) {
                Alerts::refresh($productId);
            }
        }

        $items = array_values(array_filter(
            $current,
            static fn (array $item): bool => isset($pending[$item['product_id']])
        ));

        if ($items === []) {
            return $result;
        }

        $result['items'] = $items;
        $result['to'] = Recipients::parse((string) (MerchantSetting::current()['low_stock_emails'] ?? ''))['valid'];

        if ($result['to'] === []) {
            return ['status' => self::NO_RECIPIENTS] + $result;
        }

        $email = LowStockEmail::compose($items, LowStockEmail::absoluteUrl(StockLevelResource::lowStockUrl()));
        $result['subject'] = $email['subject'];

        if ($preview) {
            return ['status' => self::PREVIEW] + $result;
        }

        $sent = Mailer::send(self::KEY, $result['to'], $email['subject'], $email['body']);
        $result = array_merge($result, [
            'status' => $sent['status'],
            'to' => $sent['to'],
            'sent' => $sent['sent'],
            'failed' => $sent['failed'],
        ]);

        if ($sent['status'] !== Mailer::FAILED) {
            $now = date('Y-m-d H:i:s');

            foreach ($items as $item) {
                foreach ($pending[$item['product_id']] as $alertId) {
                    StockAlert::update(['notified_at' => $now], $alertId);
                }
            }
        }

        if ($sent['failed'] !== []) {
            (self::$reporter ?? self::defaultReporter())(self::FAILURE, [
                'to' => $sent['failed'],
                'products' => count($items),
                'retry' => $sent['status'] === Mailer::FAILED,
            ]);
        }

        return $result;
    }

    /** Cambia chi riceve i guasti; `null` torna al registro del core. Serve ai test. */
    public static function reportUsing(?callable $reporter): void
    {
        self::$reporter = $reporter;
    }

    /** @return callable(string, array): void */
    private static function defaultReporter(): callable
    {
        return static function (string $message, array $context): void {
            Errors::report('gestionale', self::KEY, $message, $context);
        };
    }
}
```

- [ ] **Step 4: Esegui i test e verifica che passino**

Run: `php tests/integrazione/LowStockTest.php && php tests/MailerTest.php && php tests/LowStockEmailTest.php`
Expected: PASS su tutti e tre.

- [ ] **Step 5: Commit**

```bash
git status --short
git add src/Support/Stock/LowStockNotifier.php tests/integrazione/LowStockTest.php
git commit -m "$(cat <<'MSG'
feat(magazzino): il giro degli avvisi di scorta minima, una email raggruppata

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 7: l'attività `gestionale.stock_alerts` e il comando d'anteprima

**Files:**
- Create: `src/Scheduler/StockAlertsTask.php`
- Create: `src/Console/StockAlertsCommand.php`
- Modify: `src/Gestionale.php:8` (import) e `:52-60` (`tasks()`)
- Modify: `module.json` (`console.commands`)
- Modify: `tests/TasksTest.php`, `tests/CommandsTest.php`, `tests/ManifestTest.php`

**Interfaces:**
- Consumes: `LowStockNotifier::run(bool $preview)` e le sue costanti (task 6); `LowStockEmail::quantity()` (task 5); `Mailer::SENT`, `::CANCELLED`, `::FAILED` (task 4).
- Produces: l'attività `gestionale.stock_alerts` (ogni quarto d'ora, nata spenta, `run()` torna `status`, `items`, `resolved`, `closed`, `sent`, `failed`, tutti scalari); il comando `php forge gestionale:stock-alerts`, che mostra cosa partirebbe e non spedisce mai.

Il comando è solo un'anteprima per una ragione pratica: `forge` carica l'autoload ma non le funzioni globali del core, quindi `sendMail()` lì non c'è. Mandare davvero da un comando vorrebbe dire aggirare il core; l'attività dello scheduler invece gira con il sito avviato. Il comando serve a chi configura: "cosa arriverebbe, e a chi, se lo accendessi adesso?".

L'attività nasce spenta come `gestionale.images`: si accende dal pannello dello scheduler del core, dopo aver sbloccato la funzionalità e scritto i destinatari. Il core tiene già un blocco per attività: due giri non si accavallano.

- [ ] **Step 1: Scrivi i test che falliscono**

In `tests/TasksTest.php` aggiungi fra gli `use`:

```php
use Wonder\Plugin\Gestionale\Scheduler\StockAlertsTask;
```

e prima di `summary();`:

```php
check('anche gli avvisi di scorta minima sono un\'attività del modulo', function () {
    foreach (Gestionale::tasks() as $task) {
        if ($task instanceof StockAlertsTask) {
            return $task instanceof TaskInterface;
        }
    }

    return false;
});

check('gli avvisi hanno una chiave che il core accetta', function () {
    $task = new StockAlertsTask();

    return preg_match('/^[a-z0-9][a-z0-9_.-]{0,119}$/D', $task->key()) === 1
        && $task->key() === 'gestionale.stock_alerts';
});

check('gli avvisi girano ogni quarto d\'ora e non tengono occupato il processo', function () {
    $task = new StockAlertsTask();

    return $task->expression() === '*/15 * * * *'
        && $task->timeout() > 0
        && $task->timeout() <= 3600;
});

check('gli avvisi nascono spenti: prima si scelgono i destinatari', fn () =>
    (new StockAlertsTask())->enabled() === false
    && (new StockAlertsTask())->defaultParameters() === []
    && (new StockAlertsTask())->validate([]) === []
);

check('le due attività hanno chiavi diverse', function () {
    $chiavi = [];

    foreach (Gestionale::tasks() as $task) {
        $chiavi[] = $task->key();
    }

    return $chiavi === array_values(array_unique($chiavi)) && count($chiavi) === 2;
});
```

In `tests/CommandsTest.php` aggiungi fra gli `use`:

```php
use Wonder\Plugin\Gestionale\Console\StockAlertsCommand;
```

sostituisci il primo controllo con:

```php
check('i comandi del modulo sono comandi di forge', fn () =>
    is_subclass_of(DemoCommand::class, Command::class)
    && is_subclass_of(FeaturesDocCommand::class, Command::class)
    && is_subclass_of(ImagesCommand::class, Command::class)
    && is_subclass_of(StockAlertsCommand::class, Command::class)
);
```

e nel secondo aggiungi `&& in_array(StockAlertsCommand::class, $dichiarati, true)` dopo quello di `ImagesCommand`, e `&& (new StockAlertsCommand)->getName() === 'gestionale:stock-alerts'` dopo `gestionale:images`.

In `tests/ManifestTest.php` sostituisci la lista dei comandi con:

```php
    $manifest->consoleCommands() === [
        'Wonder\\Plugin\\Gestionale\\Console\\DemoCommand',
        'Wonder\\Plugin\\Gestionale\\Console\\ImagesCommand',
        'Wonder\\Plugin\\Gestionale\\Console\\FeaturesDocCommand',
        'Wonder\\Plugin\\Gestionale\\Console\\StockAlertsCommand',
    ]
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `php tests/TasksTest.php; php tests/CommandsTest.php; php tests/ManifestTest.php`
Expected: FAIL — `Class "...Scheduler\StockAlertsTask" not found`, `Class "...Console\StockAlertsCommand" not found`, e in `ManifestTest` il controllo dei comandi.

- [ ] **Step 3: Scrivi l'attività**

Crea `src/Scheduler/StockAlertsTask.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Scheduler;

use Wonder\App\Scheduler\AbstractTask;
use Wonder\App\Scheduler\Context;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockNotifier;

/**
 * Gli avvisi di scorta minima, come attività dello scheduler del core.
 *
 * Ogni quarto d'ora raccoglie i prodotti scesi sotto la soglia e li manda in
 * un'email sola ai *Destinatari degli avvisi*. Nasce **spenta**: prima si
 * sblocca la funzionalità e si scrivono i destinatari, poi la si accende.
 *
 * Il salvataggio non manda mai email: apre l'avviso e basta. Così dieci
 * rettifiche di fila fanno un'email, e il magazzino non aspetta la posta.
 */
final class StockAlertsTask extends AbstractTask
{
    public function key(): string
    {
        return 'gestionale.stock_alerts';
    }

    public function label(): string
    {
        return 'Gestionale: avvisi di scorta minima';
    }

    public function expression(): string
    {
        return '*/15 * * * *';
    }

    public function enabled(): bool
    {
        return false;
    }

    public function timeout(): int
    {
        return 300;
    }

    public function run(Context $context): array
    {
        $result = LowStockNotifier::run();

        return [
            'status' => $result['status'],
            'items' => count($result['items']),
            'resolved' => $result['resolved'],
            'closed' => $result['closed'],
            'sent' => count($result['sent']),
            'failed' => count($result['failed']),
        ];
    }
}
```

In `src/Gestionale.php` aggiungi l'import dopo quello di `ImagesTask`:

```php
use Wonder\Plugin\Gestionale\Scheduler\StockAlertsTask;
```

e sostituisci il corpo di `tasks()`:

```php
        return [new ImagesTask(), new StockAlertsTask()];
```

Aggiorna anche il docblock di `tasks()` se nomina solo la coda delle immagini: "La coda delle immagini e gli avvisi di scorta minima, tutte e due spente finché non le accende qualcuno."

- [ ] **Step 4: Scrivi il comando**

Crea `src/Console/StockAlertsCommand.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockEmail;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockNotifier;

/**
 * `php forge gestionale:stock-alerts` — cosa partirebbe adesso, e a chi.
 *
 * È solo un'anteprima: non manda email e non tocca gli avvisi. L'email la
 * manda l'attività `gestionale.stock_alerts` dello scheduler, che gira con il
 * sito avviato; `forge` non carica le funzioni del core, e `sendMail()` qui
 * non c'è.
 */
final class StockAlertsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('gestionale:stock-alerts')
            ->setDescription('Mostra l\'email degli avvisi di scorta minima che partirebbe adesso, senza mandarla');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = LowStockNotifier::run(true);

        if ($result['status'] === LowStockNotifier::DISABLED) {
            $output->writeln('Gli avvisi di scorta minima non sono sbloccati.');

            return Command::SUCCESS;
        }

        if ($result['resolved'] > 0 || $result['closed'] > 0) {
            $output->writeln("<comment>Al prossimo giro si chiudono {$result['resolved']} avvisi di prodotti tolti e {$result['closed']} di prodotti tornati sopra la soglia.</comment>");
        }

        if ($result['items'] === []) {
            $output->writeln('Nessun prodotto sotto scorta da segnalare.');

            return Command::SUCCESS;
        }

        if ($result['status'] === LowStockNotifier::NO_RECIPIENTS) {
            $output->writeln('<comment>Mancano i Destinatari degli avvisi nelle impostazioni: l\'email aspetta.</comment>');
        } else {
            $output->writeln('Oggetto: '.$result['subject']);
            $output->writeln('A: '.implode(', ', $result['to']));
        }

        foreach ($result['items'] as $item) {
            $name = $item['article'].($item['option'] !== '' ? ' — '.$item['option'] : '');
            $sku = $item['sku'] !== '' ? ' ('.$item['sku'].')' : '';

            $output->writeln(sprintf(
                '- %s%s: disponibili %s, scorta minima %s',
                $name,
                $sku,
                LowStockEmail::quantity($item['available']),
                LowStockEmail::quantity($item['threshold'])
            ));
        }

        $output->writeln('L\'email la manda l\'attività gestionale.stock_alerts dello scheduler: questo comando non spedisce niente.');

        return Command::SUCCESS;
    }
}
```

In `module.json` aggiungi il comando in fondo a `console.commands`:

```json
        "commands": [
            "Wonder\\Plugin\\Gestionale\\Console\\DemoCommand",
            "Wonder\\Plugin\\Gestionale\\Console\\ImagesCommand",
            "Wonder\\Plugin\\Gestionale\\Console\\FeaturesDocCommand",
            "Wonder\\Plugin\\Gestionale\\Console\\StockAlertsCommand"
        ]
```

- [ ] **Step 5: Esegui i test e verifica che passino**

Run: `php tests/TasksTest.php && php tests/CommandsTest.php && php tests/ManifestTest.php`
Expected: PASS su tutti e tre.

- [ ] **Step 6: Prova il comando sul sito di prova**

Run: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge gestionale:stock-alerts`
Expected: una delle risposte del comando ("non sono sbloccati", "Nessun prodotto sotto scorta da segnalare", oppure l'elenco con l'ultima riga "questo comando non spedisce niente"), nessun errore. Controlla anche che il comando compaia in `php forge list gestionale`.

- [ ] **Step 7: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale
git status --short
git add src/Scheduler/StockAlertsTask.php src/Console/StockAlertsCommand.php src/Gestionale.php module.json tests/TasksTest.php tests/CommandsTest.php tests/ManifestTest.php
git commit -m "$(cat <<'MSG'
feat(magazzino): attività gestionale.stock_alerts e comando d'anteprima

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 8: il riquadro *Sotto scorta* nella home

**Files:**
- Create: `src/Backend/Widgets/LowStockWidget.php`
- Modify: `config/module.php` (`backend.home_widgets`)
- Modify: `tests/WidgetsTest.php`

**Interfaces:**
- Consumes: `LowStockReport::open(): list<array>`, `LowStockReport::products(list<array> $alerts): array<int, array>`, `LowStockReport::items(list<array> $alerts, array<int, array> $products): list<array{product_id: int, article: string, option: string, sku: string, threshold: float, available: float}>` e `LowStockEmail::quantity(float): string` (task 5); `Recipients::parse(string): array{valid: list<string>, invalid: list<string>}` (task 3); `MerchantSetting::current(): array`; `StockLevelResource::lowStockUrl(): string` (task 2); `MerchantSettingResource::path(): string` (`'app/gestionale/impostazioni-negozio'`); `Gestionale::feature(string): bool`; `Errors::internal(Throwable $error, string $action, array $context = []): void` (`Wonder\Plugin\Gestionale\Support\Errors\Errors`).
- Produces: `LowStockWidget::LIMIT` (`10`); `LowStockWidget::markup(list<array{product_id: int, article: string, option: string, sku: string, threshold: float, available: float}> $items, bool $hasRecipients): string` (pura).

Il riquadro mostra gli stessi prodotti dell'email, **letti adesso**: `LowStockReport::build()` scarta da sé chi è tornato sopra la soglia, anche se il suo avviso si chiuderà solo al prossimo giro dell'attività. Dieci righe al massimo, poi "e altri N" e il pulsante che apre le Giacenze filtrate sulle sole righe sotto scorta.

Se nessuno riceve l'email (*Destinatari degli avvisi* vuoto), il riquadro lo dice con il link alle impostazioni del negozio: è l'unico posto in cui il commerciante se ne accorge, perché l'attività in quel caso tace. L'avviso compare anche col riquadro vuoto, perché la prima email mancata sarebbe già tardi.

A funzionalità bloccata `render()` restituisce una stringa vuota e il core non disegna il riquadro (come i *Primi passi* senza passi aperti).

- [ ] **Step 1: Scrivi i test che falliscono**

In `tests/WidgetsTest.php` aggiungi agli `use` in cima:

```php
use Wonder\Plugin\Gestionale\Backend\Widgets\LowStockWidget;
use Wonder\Plugin\Gestionale\Gestionale;
```

e, subito prima di `summary();`, questi controlli:

```php
// Una riga come la dà `LowStockReport::items()`.
$sottoScorta = static fn (string $article, string $option, float $available, float $threshold, int $id = 1): array => [
    'product_id' => $id,
    'article' => $article,
    'option' => $option,
    'sku' => 'SKU-'.$id,
    'threshold' => $threshold,
    'available' => $available,
];

check('il riquadro Sotto scorta sta fra Da controllare e Anagrafiche', fn () =>
    is_subclass_of(LowStockWidget::class, HomeWidget::class)
    && (new LowStockWidget)->title() === 'Sotto scorta'
    && (new LowStockWidget)->authorities() === ['admin', 'administrator']
    && (new LowStockWidget)->order() > (new AttentionWidget)->order()
    && (new LowStockWidget)->order() < (new ContactsWidget)->order()
);

check('il modulo lo registra subito dopo Da controllare', function () {
    $widgets = (require __DIR__.'/../config/module.php')['backend']['home_widgets'];
    $posto = array_search(LowStockWidget::class, $widgets, true);

    return $posto !== false && $posto === array_search(AttentionWidget::class, $widgets, true) + 1;
});

check('con gli avvisi bloccati il riquadro non disegna niente', function () {
    // `render()` deve fermarsi prima di qualunque lettura: qui non c'è database.
    $stato = new ReflectionProperty(Gestionale::class, 'features');
    $prima = $stato->getValue();
    $stato->setValue(null, ['low_stock_alerts' => false]);

    try {
        return (new LowStockWidget)->render() === '';
    } finally {
        $stato->setValue(null, $prima);
    }
});

check('senza prodotti sotto scorta lo dice, senza allarmare', function () {
    $html = LowStockWidget::markup([], true);

    return str_contains($html, 'Nessun prodotto sotto la scorta minima')
        && !str_contains($html, 'giacenze?sotto=1');
});

check('ogni riga dice cosa riordinare, e il pulsante apre le giacenze filtrate', function () use ($sottoScorta) {
    $html = LowStockWidget::markup([$sottoScorta('Maglia', 'Rossa, M', 1.25, 5.0)], true);

    return str_contains($html, 'Maglia — Rossa, M')
        && str_contains($html, 'SKU-1')
        && str_contains($html, 'Disponibili 1,25')
        && str_contains($html, 'scorta minima 5')
        && str_contains($html, 'giacenze?sotto=1');
});

check('un articolo senza varianti non si porta dietro il trattino', function () use ($sottoScorta) {
    $html = LowStockWidget::markup([$sottoScorta('Borraccia', '', 0.0, 3.0)], true);

    return str_contains($html, 'Borraccia') && !str_contains($html, 'Borraccia —');
});

check('oltre le dieci righe il resto si conta, al singolare e al plurale', function () use ($sottoScorta) {
    $righe = static function (int $quante) use ($sottoScorta): array {
        $items = [];

        for ($i = 1; $i <= $quante; $i++) {
            $items[] = $sottoScorta('Articolo '.$i, '', 0.0, 1.0, $i);
        }

        return $items;
    };
    $dodici = LowStockWidget::markup($righe(12), true);
    $undici = LowStockWidget::markup($righe(11), true);
    $dieci = LowStockWidget::markup($righe(10), true);

    return substr_count($dodici, '<li') === LowStockWidget::LIMIT
        && str_contains($dodici, 'e altri 2')
        && str_contains($undici, 'e un altro')
        && !str_contains($dieci, 'e altri') && !str_contains($dieci, 'e un altro');
});

check('senza destinatari il riquadro avvisa che l\'email non parte, anche vuoto', function () use ($sottoScorta) {
    $con = LowStockWidget::markup([$sottoScorta('Maglia', 'M', 0.0, 2.0)], true);
    $senza = LowStockWidget::markup([$sottoScorta('Maglia', 'M', 0.0, 2.0)], false);
    $vuoto = LowStockWidget::markup([], false);

    return !str_contains($con, 'impostazioni-negozio')
        && str_contains($senza, 'Nessuno riceve l\'email')
        && str_contains($senza, '/backend/app/gestionale/impostazioni-negozio')
        && str_contains($vuoto, 'Nessuno riceve l\'email');
});

check('i nomi dei prodotti non possono iniettare markup', function () use ($sottoScorta) {
    $html = LowStockWidget::markup([$sottoScorta('<script>alert(1)</script>', '<b>x</b>', 0.0, 1.0)], true);

    return !str_contains($html, '<script>')
        && !str_contains($html, '<b>x</b>')
        && str_contains($html, '&lt;script&gt;');
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `php tests/WidgetsTest.php`
Expected: FAIL — `Class "Wonder\Plugin\Gestionale\Backend\Widgets\LowStockWidget" not found`.

- [ ] **Step 3: Scrivi il riquadro**

Crea `src/Backend/Widgets/LowStockWidget.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Throwable;
use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\Plugin\Gestionale\Resources\Stock\StockLevelResource;
use Wonder\Plugin\Gestionale\Resources\System\MerchantSettingResource;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Mail\Recipients;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockEmail;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockReport;

/**
 * "Sotto scorta": i prodotti da riordinare.
 *
 * Gli stessi dell'email, letti adesso: un prodotto tornato sopra la soglia
 * sparisce subito, anche se il suo avviso si chiude solo al prossimo giro
 * dell'attività. Dieci righe al massimo; il resto si conta e si apre nelle
 * Giacenze filtrate.
 *
 * A funzionalità bloccata il riquadro non esiste.
 */
final class LowStockWidget implements HomeWidget
{
    /** Le righe che si vedono prima di "e altri N". */
    public const LIMIT = 10;

    public function title(): string
    {
        return 'Sotto scorta';
    }

    public function authorities(): array
    {
        return ['admin', 'administrator'];
    }

    public function order(): int
    {
        return 25;
    }

    public function render(): string
    {
        if (!Gestionale::feature('low_stock_alerts')) {
            return '';
        }

        try {
            $alerts = LowStockReport::open();
            $items = LowStockReport::items($alerts, LowStockReport::products($alerts));
            $recipients = Recipients::parse((string) (MerchantSetting::current()['low_stock_emails'] ?? ''))['valid'];
        } catch (Throwable $error) {
            // La home si apre lo stesso: il guasto va nel log, non in pagina.
            Errors::internal($error, 'widget.low_stock');

            return '';
        }

        return self::markup($items, $recipients !== []);
    }

    /**
     * @param list<array{product_id: int, article: string, option: string, sku: string, threshold: float, available: float}> $items
     */
    public static function markup(array $items, bool $hasRecipients): string
    {
        $warning = $hasRecipients ? '' : self::noRecipients();

        if ($items === []) {
            return <<<HTML
<wi-card class="col-12">
    <h5 class="mb-1"><i class="bi bi-box-seam"></i> Sotto scorta</h5>
    <p class="text-body-secondary small mb-0">Nessun prodotto sotto la scorta minima.</p>
    {$warning}
</wi-card>
HTML;
        }

        $rows = '';

        foreach (array_slice($items, 0, self::LIMIT) as $item) {
            $name = htmlspecialchars(
                $item['option'] !== '' ? $item['article'].' — '.$item['option'] : $item['article'],
                ENT_QUOTES,
                'UTF-8'
            );
            $sku = htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8');
            $available = LowStockEmail::quantity((float) $item['available']);
            $threshold = LowStockEmail::quantity((float) $item['threshold']);

            $rows .= <<<HTML
<li class="list-group-item bg-transparent d-flex justify-content-between align-items-center gap-3">
    <span>
        <strong>{$name}</strong>
        <span class="d-block text-body-secondary small">{$sku}</span>
    </span>
    <span class="small text-nowrap">Disponibili {$available} · scorta minima {$threshold}</span>
</li>
HTML;
        }

        $others = count($items) - self::LIMIT;
        $more = match (true) {
            $others === 1 => '<p class="text-body-secondary small mt-2 mb-0">e un altro</p>',
            $others > 1 => '<p class="text-body-secondary small mt-2 mb-0">e altri '.$others.'</p>',
            default => '',
        };
        $url = htmlspecialchars(StockLevelResource::lowStockUrl(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<wi-card class="col-12">
    <h5 class="mb-1 d-flex justify-content-between align-items-center gap-3">
        <span><i class="bi bi-box-seam"></i> Sotto scorta</span>
        <a class="btn btn-sm btn-secondary" href="{$url}">Apri le giacenze</a>
    </h5>
    <ul class="list-group list-group-flush">{$rows}</ul>
    {$more}
    {$warning}
</wi-card>
HTML;
    }

    /** L'attività tace se nessuno riceve l'email: qui è l'unico posto dove si vede. */
    private static function noRecipients(): string
    {
        $url = htmlspecialchars('/backend/'.MerchantSettingResource::path(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<p class="small mt-2 mb-0"><i class="bi bi-envelope-exclamation"></i> Nessuno riceve l'email degli avvisi. <a href="{$url}">Aggiungi i destinatari</a></p>
HTML;
    }
}
```

- [ ] **Step 4: Registra il riquadro**

In `config/module.php`, dentro `backend.home_widgets`, aggiungi la riga dopo `AttentionWidget`:

```php
        'home_widgets' => [
            \Wonder\Plugin\Gestionale\Backend\Widgets\SetupWidget::class,
            \Wonder\Plugin\Gestionale\Backend\Widgets\AttentionWidget::class,
            \Wonder\Plugin\Gestionale\Backend\Widgets\LowStockWidget::class,
            \Wonder\Plugin\Gestionale\Backend\Widgets\ContactsWidget::class,
        ],
```

- [ ] **Step 5: Esegui i test e verifica che passino**

Run: `php tests/WidgetsTest.php`
Expected: PASS, compresi i nove controlli nuovi.

- [ ] **Step 6: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale
git status --short
git add src/Backend/Widgets/LowStockWidget.php config/module.php tests/WidgetsTest.php
git commit -m "$(cat <<'MSG'
feat(magazzino): riquadro Sotto scorta nella home

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 9: le giacenze negative in *Da controllare*

**Files:**
- Create: `src/Support/Stock/NegativeStock.php`
- Create: `tests/NegativeStockTest.php`
- Modify: `src/Backend/Widgets/AttentionWidget.php` (docblock, import, `LIMIT`, `render()`, `markup()`, nuovi `negatives()` e `negativeLine()`)
- Modify: `tests/WidgetsTest.php`
- Modify: `tests/integrazione/LowStockTest.php`

**Interfaces:**
- Consumes: `ProductNames::models(array<int, array> $products): array<int, string>` e `ProductNames::of(array $product, array<int, string> $modelNames): array{article: string, option: string}` e `LowStockEmail::quantity(float): string` (task 5); `StockAdjustmentResource::urlFor(int $productId, string $back = ''): string` (esiste, piano 3); il modello `Models\Stock\Stock` (`gst_stock`: `product_id`, `location_id`, `quantity`, `deleted`); `ErrorReporter::open()` del core; `Stock::apply()` e `Reasons::DEFAULT` (piano 1) nel test d'integrazione; `articoloDiProva()` e `annullando()` (task 1) e `$stato` (la `ReflectionProperty` su `Gestionale::$features`, in cima al test d'integrazione).
- Produces: `NegativeStock::items(): list<array{product_id: int, article: string, option: string, sku: string, quantity: float, locations: int}>`; `NegativeStock::group(list<array> $rows, array<int, array> $products, array<int, string> $modelNames): list<…>` (pura, stessa forma); `AttentionWidget::LIMIT` (`10`); `AttentionWidget::markup(list<array> $errors, list<array> $negatives = []): string`.

Sotto zero si va solo con *Vendita senza giacenza* (`backorders`) sbloccata, o con dati arrivati da fuori: `Stock::apply()` lo controlla **riga per riga**, cioè sede per sede, e allo stesso modo si leggono qui. Un prodotto con due sedi in negativo è una riga sola, con la somma dei negativi e quante sono le sedi; "in N sedi" compare solo da due in su, quindi con *Più sedi* bloccata la parola "sede" non si vede mai (D20).

Le giacenze negative sono dati del negozio: le vedono **tutti e due** i ruoli, mentre gli errori tecnici restano a chi sviluppa. Ogni riga ha il pulsante *Rettifica*, che apre la rettifica della versione e poi torna alla home (`torna=/backend/`, la rotta `backend.home` del core).

Un prodotto eliminato o tolto dalla griglia non si segnala: il core lo esclude già dalla lettura dei prodotti, e non c'è niente da rettificare.

- [ ] **Step 1: Scrivi i test puri che falliscono**

Crea `tests/NegativeStockTest.php`:

```php
<?php
/** php tests/NegativeStockTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Stock\NegativeStock;

$prodotti = [
    1 => ['id' => 1, 'product_model_id' => 10, 'name' => 'Rossa, M', 'sku' => 'MAG-R-M'],
    2 => ['id' => 2, 'product_model_id' => 20, 'name' => 'Borraccia', 'sku' => 'BOR'],
    3 => ['id' => 3, 'product_model_id' => 10, 'name' => 'Blu, L', 'sku' => 'MAG-B-L'],
];
$articoli = [10 => 'Maglia', 20 => 'Borraccia'];

check('una riga negativa diventa un prodotto da controllare', function () use ($prodotti, $articoli) {
    $items = NegativeStock::group([['product_id' => 1, 'location_id' => 1, 'quantity' => '-3.000']], $prodotti, $articoli);

    return $items === [[
        'product_id' => 1,
        'article' => 'Maglia',
        'option' => 'Rossa, M',
        'sku' => 'MAG-R-M',
        'quantity' => -3.0,
        'locations' => 1,
    ]];
});

check('due sedi in negativo fanno una riga sola, con la somma e il numero delle sedi', function () use ($prodotti, $articoli) {
    $items = NegativeStock::group([
        ['product_id' => 1, 'location_id' => 1, 'quantity' => '-3.000'],
        ['product_id' => 1, 'location_id' => 2, 'quantity' => '-1.250'],
    ], $prodotti, $articoli);

    return count($items) === 1 && $items[0]['quantity'] === -4.25 && $items[0]['locations'] === 2;
});

check('le righe a zero o sopra non contano, anche se arrivano', fn () =>
    NegativeStock::group([
        ['product_id' => 1, 'location_id' => 1, 'quantity' => '0.000'],
        ['product_id' => 3, 'location_id' => 1, 'quantity' => '4.000'],
    ], $prodotti, $articoli) === []
);

check('un prodotto sparito o tolto dalla griglia non si segnala', fn () =>
    NegativeStock::group([
        ['product_id' => 99, 'location_id' => 1, 'quantity' => '-2.000'],
        ['product_id' => 2, 'location_id' => 1, 'quantity' => '-2.000'],
    ], [2 => array_merge($prodotti[2], ['deleted' => 'true'])], $articoli) === []
);

check('l\'articolo senza varianti non ripete il nome, e l\'elenco è in ordine di articolo', function () use ($prodotti, $articoli) {
    $items = NegativeStock::group([
        ['product_id' => 3, 'location_id' => 1, 'quantity' => '-1.000'],
        ['product_id' => 2, 'location_id' => 1, 'quantity' => '-1.000'],
        ['product_id' => 1, 'location_id' => 1, 'quantity' => '-1.000'],
    ], $prodotti, $articoli);

    return array_column($items, 'sku') === ['BOR', 'MAG-B-L', 'MAG-R-M']
        && $items[0]['option'] === '';
});

summary();
```

In `tests/WidgetsTest.php`, subito prima di `summary();`, aggiungi:

```php
// Una riga come la dà `NegativeStock::items()`.
$negativa = static fn (int $id, float $quantity, int $locations = 1, string $option = 'Rossa, M'): array => [
    'product_id' => $id,
    'article' => 'Maglia',
    'option' => $option,
    'sku' => 'MAG-'.$id,
    'quantity' => $quantity,
    'locations' => $locations,
];

check('una giacenza sotto zero si vede, con il pulsante per rettificarla', function () use ($negativa) {
    $html = AttentionWidget::markup([], [$negativa(7, -3.0)]);

    return str_contains($html, 'Maglia — Rossa, M')
        && str_contains($html, 'MAG-7')
        && str_contains($html, 'Giacenza -3')
        && str_contains($html, 'rettifica?versione=7&amp;torna=%2Fbackend%2F')
        && str_contains($html, 'Rettifica')
        && !str_contains($html, 'Non c\'è niente da controllare');
});

check('le sedi si nominano solo quando sono più di una', function () use ($negativa) {
    $una = AttentionWidget::markup([], [$negativa(7, -3.0)]);
    $due = AttentionWidget::markup([], [$negativa(7, -4.25, 2)]);

    return !str_contains($una, ' sedi')
        && str_contains($due, 'Giacenza -4,25 in 2 sedi');
});

check('giacenze negative ed errori stanno nello stesso riquadro, prima le giacenze', function () use ($negativa) {
    $html = AttentionWidget::markup([[
        'id' => 3,
        'service' => 'fatture-in-cloud',
        'action' => 'invoice.send',
        'message' => 'Timeout',
        'occurrences' => 1,
        'last_seen_at' => '',
    ]], [$negativa(7, -1.0)]);

    return str_contains($html, 'fatture-in-cloud')
        && str_contains($html, 'MAG-7')
        && strpos($html, 'MAG-7') < strpos($html, 'fatture-in-cloud');
});

check('oltre le dieci giacenze negative il resto si conta', function () use ($negativa) {
    $righe = [];

    for ($i = 1; $i <= 12; $i++) {
        $righe[] = $negativa($i, -1.0);
    }

    $html = AttentionWidget::markup([], $righe);

    return substr_count($html, 'Rettifica</a>') === AttentionWidget::LIMIT
        && str_contains($html, 'e altre 2 giacenze sotto zero');
});

check('i nomi delle versioni non possono iniettare markup', function () use ($negativa) {
    $html = AttentionWidget::markup([], [$negativa(7, -1.0, 1, '<img src=x onerror=alert(1)>')]);

    return !str_contains($html, '<img') && str_contains($html, '&lt;img');
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `php tests/NegativeStockTest.php`
Expected: FAIL — `Class "Wonder\Plugin\Gestionale\Support\Stock\NegativeStock" not found`.

Run: `php tests/WidgetsTest.php`
Expected: FAIL sui cinque controlli nuovi (`markup()` ignora il secondo argomento e mostra "Non c'è niente da controllare"; `AttentionWidget::LIMIT` non esiste). I controlli già esistenti passano.

- [ ] **Step 3: Scrivi `NegativeStock`**

Crea `src/Support/Stock/NegativeStock.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;

/**
 * I prodotti con la giacenza sotto zero, per *Da controllare*.
 *
 * Sotto zero si va solo con *Vendita senza giacenza* sbloccata, o con dati
 * arrivati da fuori. `Stock::apply()` lo controlla riga per riga, cioè sede
 * per sede, e allo stesso modo si leggono qui: un prodotto con due sedi in
 * negativo è una riga sola, con la somma dei negativi e il numero delle sedi.
 *
 * Un prodotto eliminato o tolto dalla griglia non si segnala: il core lo
 * esclude già dalla lettura, e non c'è niente da rettificare.
 */
final class NegativeStock
{
    /** @return list<array{product_id: int, article: string, option: string, sku: string, quantity: float, locations: int}> */
    public static function items(): array
    {
        $rows = self::rows(StockRow::class, "deleted = 'false' AND quantity < 0");

        if ($rows === []) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['product_id'] ?? 0),
            $rows
        ))));

        if ($ids === []) {
            return [];
        }

        $products = [];

        foreach (self::rows(Product::class, 'id IN ('.implode(',', $ids).')') as $product) {
            $products[(int) $product['id']] = $product;
        }

        return self::group($rows, $products, ProductNames::models($products));
    }

    /**
     * Pura: dalle righe di giacenza ai prodotti da controllare.
     *
     * @param list<array<string, mixed>> $rows righe di `gst_stock`
     * @param array<int, array<string, mixed>> $products per id
     * @param array<int, string> $modelNames nomi degli articoli, per id del modello
     * @return list<array{product_id: int, article: string, option: string, sku: string, quantity: float, locations: int}>
     */
    public static function group(array $rows, array $products, array $modelNames): array
    {
        $items = [];

        foreach ($rows as $row) {
            $id = (int) ($row['product_id'] ?? 0);
            $quantity = round((float) ($row['quantity'] ?? 0), 3);
            $product = $products[$id] ?? null;

            if ($quantity >= 0 || !is_array($product) || ($product['deleted'] ?? 'false') === 'true') {
                continue;
            }

            if (!isset($items[$id])) {
                $names = ProductNames::of($product, $modelNames);
                $items[$id] = [
                    'product_id' => $id,
                    'article' => $names['article'],
                    'option' => $names['option'],
                    'sku' => (string) ($product['sku'] ?? ''),
                    'quantity' => 0.0,
                    'locations' => 0,
                ];
            }

            $items[$id]['quantity'] = round($items[$id]['quantity'] + $quantity, 3);
            $items[$id]['locations']++;
        }

        usort($items, static fn (array $a, array $b): int =>
            [mb_strtolower($a['article'], 'UTF-8'), mb_strtolower($a['option'], 'UTF-8'), $a['sku']]
            <=> [mb_strtolower($b['article'], 'UTF-8'), mb_strtolower($b['option'], 'UTF-8'), $b['sku']]
        );

        return array_values($items);
    }

    /**
     * @param class-string<\Wonder\App\Model> $modelClass
     * @return list<array<string, mixed>>
     */
    private static function rows(string $modelClass, string $condition): array
    {
        try {
            $rows = $modelClass::find($condition);
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

- [ ] **Step 4: Esegui il test puro e verifica che passi**

Run: `php tests/NegativeStockTest.php`
Expected: PASS, 5 controlli.

- [ ] **Step 5: Porta le giacenze negative in *Da controllare***

In `src/Backend/Widgets/AttentionWidget.php`:

1. Sostituisci il docblock della classe con:

```php
/**
 * "Da controllare": le cose ferme che qualcuno deve guardare.
 *
 * Le giacenze sotto zero sono dati del negozio: le vedono tutti e due i ruoli,
 * ciascuna con il pulsante per rettificarla. Gli errori tecnici di
 * `error_reports` li vede solo chi installa: per il commerciante sono
 * notifiche, non errori, e arriveranno dal sotto-progetto che le genera (un
 * ordine fermo, una spedizione senza tracking).
 */
```

2. Sostituisci gli `use` in cima con:

```php
use Throwable;
use Wonder\App\LegacyGlobals;
use Wonder\App\Support\Errors\ErrorReporter;
use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockEmail;
use Wonder\Plugin\Gestionale\Support\Stock\NegativeStock;
```

3. Subito dopo `final class AttentionWidget implements HomeWidget {` aggiungi:

```php
    /** Le giacenze negative che si vedono prima di "e altre N". */
    public const LIMIT = 10;

```

4. Sostituisci `render()` e l'inizio di `markup()` (dalla riga `public function render(): string` fino a `$items = '';` compreso) con:

```php
    public function render(): string
    {
        return self::markup(
            self::isDeveloper() ? ErrorReporter::open() : [],
            self::negatives()
        );
    }

    /**
     * @param list<array<string, mixed>> $errors
     * @param list<array{product_id: int, article: string, option: string, sku: string, quantity: float, locations: int}> $negatives
     */
    public static function markup(array $errors, array $negatives = []): string
    {
        if ($errors === [] && $negatives === []) {
            return <<<HTML
<wi-card class="col-12">
    <h5 class="mb-1"><i class="bi bi-check-circle"></i> Da controllare</h5>
    <p class="text-body-secondary small mb-0">Non c'è niente da controllare.</p>
</wi-card>
HTML;
        }

        $items = '';

        foreach (array_slice($negatives, 0, self::LIMIT) as $negative) {
            $items .= self::negativeLine($negative);
        }

        $others = count($negatives) - self::LIMIT;

        if ($others > 0) {
            $items .= '<li class="list-group-item bg-transparent text-body-secondary small">'
                .($others === 1 ? 'e un\'altra giacenza sotto zero' : 'e altre '.$others.' giacenze sotto zero')
                .'</li>';
        }
```

Il ciclo `foreach ($errors as $error)` e la chiusura del riquadro restano come sono.

5. Prima di `private static function isDeveloper(): bool` aggiungi:

```php
    /** @param array{product_id: int, article: string, option: string, sku: string, quantity: float, locations: int} $negative */
    private static function negativeLine(array $negative): string
    {
        $name = htmlspecialchars(
            $negative['option'] !== '' ? $negative['article'].' — '.$negative['option'] : $negative['article'],
            ENT_QUOTES,
            'UTF-8'
        );
        $sku = htmlspecialchars($negative['sku'], ENT_QUOTES, 'UTF-8');
        $quantity = LowStockEmail::quantity((float) $negative['quantity']);
        // Con *Più sedi* bloccata le sedi sono una: la parola non compare.
        $where = $negative['locations'] > 1 ? ' in '.$negative['locations'].' sedi' : '';
        // Dopo il salvataggio si torna qui, alla home (`backend.home`).
        $url = htmlspecialchars(StockAdjustmentResource::urlFor($negative['product_id'], '/backend/'), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<li class="list-group-item bg-transparent d-flex justify-content-between align-items-center gap-3">
    <span>
        <strong>{$name}</strong>
        <span class="d-block text-body-secondary small">{$sku} · Giacenza {$quantity}{$where}</span>
    </span>
    <a class="btn btn-info btn-sm text-nowrap" href="{$url}">Rettifica</a>
</li>
HTML;
    }

    /** Le giacenze negative; nessuna se il database non risponde. */
    private static function negatives(): array
    {
        try {
            return NegativeStock::items();
        } catch (Throwable) {
            return [];
        }
    }

```

- [ ] **Step 6: Esegui i test dei riquadri e verifica che passino**

Run: `php tests/WidgetsTest.php`
Expected: PASS, compresi i cinque controlli nuovi e quelli vecchi di `AttentionWidget::markup([])` e degli errori.

- [ ] **Step 7: Aggiungi il controllo d'integrazione**

In `tests/integrazione/LowStockTest.php` aggiungi agli `use` in cima:

```php
use Wonder\Plugin\Gestionale\Support\Stock\NegativeStock;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
```

(se il task 6 ne ha già aggiunto qualcuno, non duplicarlo) e, subito prima di `summary();`:

```php
check('una giacenza sotto zero finisce fra le cose da controllare', function () use ($stato): bool {
    // Sotto zero si va solo con la vendita senza giacenza: la si accende per
    // questo controllo e poi si rimette come l'ha il sito.
    $prima = $stato->getValue();
    $stato->setValue(null, array_merge((array) $prima, ['backorders' => true]));

    try {
        return annullando(function (): bool {
            [, $productId] = articoloDiProva('NEG-1', '2');
            Stock::apply(['product_id' => $productId, 'quantity' => -5, 'reason' => Reasons::DEFAULT]);
            $trovati = array_values(array_filter(
                NegativeStock::items(),
                static fn (array $item): bool => $item['product_id'] === $productId
            ));

            return count($trovati) === 1
                && $trovati[0]['quantity'] === -3.0
                && $trovati[0]['locations'] === 1
                && $trovati[0]['sku'] === 'NEG-1';
        });
    } finally {
        $stato->setValue(null, $prima);
    }
});
```

- [ ] **Step 8: Esegui il test d'integrazione**

Run: `php tests/integrazione/LowStockTest.php`
Expected: PASS, tutti i controlli dei task 1, 2, 6 e questo.

- [ ] **Step 9: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale
git status --short
git add src/Support/Stock/NegativeStock.php src/Backend/Widgets/AttentionWidget.php tests/NegativeStockTest.php tests/WidgetsTest.php tests/integrazione/LowStockTest.php
git commit -m "$(cat <<'MSG'
feat(magazzino): giacenze negative nel riquadro Da controllare

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 10: le guide e il CHANGELOG

**Files:**
- Create: `docs/user/magazzino-avvisi.md`
- Modify: `docs/user/SUMMARY.md` (sezione *Magazzino*), `docs/user/magazzino-giacenze.md:41-44` e `:67-77`, `docs/user/da-controllare.md:9-27`, `docs/user/impostazioni.md:18-27`, `docs/dev/concetti/magazzino.md` (tabella delle classi, sezione nuova, trappole), `docs/dev/concetti/hook.md:29-36`, `CHANGELOG.md`
- Test: `tests/DocsPagesTest.php`

**Interfaces:**
- Consumes: le parole che l'interfaccia usa già, da scrivere **identiche** nelle guide — campo *Scorta minima* (task 1), filtro *Solo sotto scorta* (task 2), impostazione *Destinatari degli avvisi* (task 3), oggetto "N prodotti sotto scorta" e view `view/emails/low-stock.php` (task 5), chiave `stock.low_stock` e stati del giro (task 6), attività `gestionale.stock_alerts` "Gestionale: avvisi di scorta minima" e comando `php forge gestionale:stock-alerts` (task 7), riquadro *Sotto scorta* con "Apri le giacenze" (task 8), riga con **Rettifica** in *Da controllare* (task 9).
- Produces: la pagina `magazzino/magazzino-avvisi` della guida del commerciante.

Le guide dicono quello che il codice fa, con le parole dell'interfaccia. Due cose da non sbagliare:

- l'avviso si apre quando i disponibili **arrivano** alla scorta minima, non solo quando la superano (`LowStock::decide()`: `available <= threshold`);
- la sede compare solo quando conta (D20): la guida del commerciante la nomina con un "se hai più sedi".

- [ ] **Step 1: Scrivi i test che falliscono**

In `tests/DocsPagesTest.php`, subito prima di `summary();`:

```php
check('la guida degli avvisi di scorta è nel SUMMARY', fn () =>
    in_array('magazzino/magazzino-avvisi', $pagine('user'), true)
);

check('i link fra le pagine delle guide portano a file che esistono', function (): bool {
    // Un link a una pagina rinominata non si vede finché qualcuno non ci
    // clicca: GitBook lo pubblica lo stesso.
    $rotti = [];

    foreach (['user', 'dev'] as $space) {
        $root = dirname(__DIR__).'/docs/'.$space;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }

            preg_match_all('/\]\(([^)#\s]+\.md)(?:#[^)]*)?\)/', (string) file_get_contents($file->getPathname()), $links);

            foreach ($links[1] as $link) {
                if (preg_match('#^[a-z]+://#i', $link) === 1) {
                    continue;
                }

                if (!is_file($file->getPath().'/'.$link)) {
                    $rotti[] = $space.':'.substr($file->getPathname(), strlen($root) + 1).' → '.$link;
                }
            }
        }
    }

    if ($rotti !== []) {
        echo '    '.implode("\n    ", $rotti)."\n";
    }

    return $rotti === [];
});
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `php tests/DocsPagesTest.php`
Expected: FAIL su "la guida degli avvisi di scorta è nel SUMMARY"; il controllo dei link **passa** già (oggi i 33 link fra pagine sono tutti buoni) e deve continuare a passare dopo i passi seguenti.

- [ ] **Step 3: La guida del commerciante**

Crea `docs/user/magazzino-avvisi.md`:

```markdown
---
icon: arrow-trend-down
---

# Avvisi di scorta minima

> **Da attivare.** Funzionalità *Avvisi di scorta minima*. Finché è spenta non
> vedi né la casella, né il riquadro, né l'email.

## A cosa serve

Ti dice quando un prodotto sta finendo, prima che finisca. Scegli tu a quanti
pezzi vuoi saperlo; il gestionale te lo scrive una volta sola, senza che tu
debba controllare le giacenze ogni giorno.

## La scorta minima

È la casella **Scorta minima** nella scheda del prodotto:

- per un articolo che si vende in **un'unica opzione**, nella scheda
  dell'articolo, accanto alla giacenza;
- per un articolo **con più opzioni**, nel riquadro *Magazzino* della scheda di
  ogni opzione in vendita: ogni taglia o colore ha la sua.

Scrivi a quanti pezzi vuoi essere avvisato. Con **5**, l'avviso nasce quando ne
restano cinque. **Zero**, o la casella vuota, vuol dire nessun avviso.

Contano i pezzi **disponibili**: se hai più sedi, tutte insieme.

La soglia vale da subito: se la alzi sopra i pezzi che hai, il prodotto è già
sotto scorta; se la abbassi sotto i pezzi che hai, l'avviso si chiude.

## L'email

Ogni quarto d'ora il gestionale guarda se qualche prodotto è sceso sotto la sua
scorta minima e, se sì, manda **un'email sola** con l'elenco: nome, opzione,
SKU, quanti ne restano e la soglia. In fondo c'è il link che apre le giacenze
già filtrate.

- **Per ogni prodotto l'email arriva una volta.** Torna solo se il prodotto
  risale sopra la soglia e poi ci ricade.
- Dieci rettifiche di fila non fanno dieci email: l'elenco parte al giro
  successivo, tutto insieme.
- L'email arriva agli indirizzi scritti in **Destinatari degli avvisi**, nelle
  [impostazioni del negozio](impostazioni.md). Se il campo è vuoto l'email non
  parte: gli avvisi aspettano, e arrivano al primo giro dopo che hai scritto un
  indirizzo.

Il giro ogni quarto d'ora lo accende chi ti segue, insieme alla funzionalità.

## Nella home

Il riquadro **Sotto scorta** elenca i prodotti che sono sotto la scorta minima
**adesso**: dieci al massimo, poi quanti altri ce ne sono e il pulsante **Apri
le giacenze**. Un prodotto che hai appena ricaricato sparisce subito, senza
aspettare il giro dell'email.

Se nessuno riceve l'email, il riquadro te lo ricorda con il link per aggiungere
i destinatari.

## Nell'elenco Giacenze

In **Magazzino → Giacenze** il pulsante **Solo sotto scorta** mostra le sole
righe da riordinare. È il posto giusto per caricare la merce quando arriva:
scrivi la quantità nuova, salva, e l'avviso si chiude da sé.

Come si caricano e si correggono le quantità lo trovi in
[Giacenze e rettifiche](magazzino-giacenze.md).
```

- [ ] **Step 4: Il SUMMARY**

In `docs/user/SUMMARY.md`, nella sezione `## Magazzino`, dopo la riga dei *Movimenti*:

```markdown
* [Avvisi di scorta minima](magazzino-avvisi.md)
```

- [ ] **Step 5: *Giacenze e rettifiche***

In `docs/user/magazzino-giacenze.md` sostituisci:

```markdown
In alto trovi la ricerca (nome, SKU o EAN), il pulsante *Solo sotto scorta* e
*Azzera i filtri*. Si lavora cinquanta righe per volta, con *Indietro* e
*Avanti* in fondo. Dopo il salvataggio torni esattamente dov'eri, filtri
compresi.
```

con:

```markdown
In alto trovi la ricerca (nome, SKU o EAN) e *Azzera i filtri*; con gli
[avvisi di scorta minima](magazzino-avvisi.md) c'è anche il pulsante *Solo
sotto scorta*. Si lavora cinquanta righe per volta, con *Indietro* e *Avanti*
in fondo. Dopo il salvataggio torni esattamente dov'eri, filtri compresi.
```

e sostituisci:

```markdown
- Nella scheda di una **singola opzione in vendita**: il riquadro *Magazzino*,
  con quanti pezzi ci sono e gli ultimi dieci movimenti.
```

con:

```markdown
- Nella scheda di una **singola opzione in vendita**: il riquadro *Magazzino*,
  con quanti pezzi ci sono e gli ultimi dieci movimenti, e la *Scorta minima*
  se hai gli [avvisi](magazzino-avvisi.md).
```

Il resto della pagina non si tocca: la parte sulla casella *Giacenza* della scheda a un'unica opzione è dell'utente (commit `7e2a621`).

- [ ] **Step 6: *Da controllare***

In `docs/user/da-controllare.md` sostituisci:

```markdown
Se è tutto a posto leggi "Non c'è niente da controllare": è la risposta che
vuoi vedere.

## Cosa mostra
```

con:

```markdown
Ci trovi anche le **giacenze andate sotto zero**: merce venduta che in
magazzino non c'era.

Se è tutto a posto leggi "Non c'è niente da controllare": è la risposta che
vuoi vedere.

## Le giacenze sotto zero

Una giacenza va sotto zero solo con la *Vendita senza giacenza* (vedi
[Funzionalità](funzionalita.md)): hai venduto pezzi che non avevi ancora. Le
trovi in cima al riquadro, una riga per prodotto, con quanti pezzi mancano e il
pulsante **Rettifica**.

- **La merce è arrivata:** rettifica con la quantità vera, e la riga sparisce.
- **La aspetti ancora:** lasciala lì, è il promemoria.

Se hai più sedi, accanto al numero leggi in quante è sotto zero: il numero è la
somma di quelle.

## Quando un servizio non funziona
```

Il vecchio `## Cosa mostra` parlava solo dei servizi: il titolo nuovo lo dice, e le righe sotto restano com'erano.

- [ ] **Step 7: Le impostazioni**

In `docs/user/impostazioni.md`, subito dopo la riga `I guasti tecnici non passano di qui: arrivano a chi ti segue, senza disturbarti.`:

```markdown

**Destinatari degli avvisi.** Chi riceve l'email dei prodotti sotto la scorta
minima; il campo c'è se hai gli [avvisi di scorta minima](magazzino-avvisi.md).
Più indirizzi si separano con una virgola. Se uno è scritto male il salvataggio
si ferma e ti dice quale.

Se lo lasci vuoto l'email non parte: gli avvisi aspettano e arrivano appena
scrivi un indirizzo.
```

- [ ] **Step 8: La guida di chi sviluppa — *Magazzino***

In `docs/dev/concetti/magazzino.md`, nella tabella di `## Le classi`, dopo la riga di `Stock`:

```markdown
| `ProductNames` | in parte | articolo e opzione come li legge la griglia: `of()` è pura, `models()` legge i nomi |
| `LowStockReport` | in parte | gli avvisi aperti o da mandare, fatti righe: `build()` è pura e scarta chi è tornato sopra |
| `LowStockEmail` | no | oggetto e corpo dell'email, con la view sovrascrivibile |
| `LowStockNotifier` | no | un giro dell'attività: legge, chiude i vecchi, manda, segna |
| `NegativeStock` | in parte | le giacenze sotto zero per prodotto: `group()` è pura |
```

Subito prima di `## Due trappole del framework` aggiungi:

````markdown
## Gli avvisi di scorta minima

Funzionalità `low_stock_alerts`. Bloccata, non si vede niente — né la
*Scorta minima*, né il filtro *Solo sotto scorta*, né il riquadro *Sotto
scorta*, né i *Destinatari degli avvisi* — e l'attività gira senza mandare.

| Chi | Cosa |
|---|---|
| `Stock::apply()` | apre e chiude la riga di `gst_stock_alerts` a ogni movimento |
| le due schede | salvando la soglia chiamano `Alerts::refresh()`: l'avviso si apre o si chiude subito |
| attività `gestionale.stock_alerts` | ogni quarto d'ora, **nata spenta**: una email sola con i prodotti ancora sotto soglia e un avviso mai mandato |
| `php forge gestionale:stock-alerts` | l'anteprima: cosa partirebbe e a chi; non manda e non scrive |
| riquadro *Sotto scorta* | gli stessi prodotti dell'email, letti adesso |

L'attività si accende in **Dev → Pianificazioni** quando si sblocca la
funzionalità.

**Il salvataggio non manda email** (G2b.9): dieci rettifiche di fila non fanno
dieci email, e salvare non dipende dal server di posta. Varrà anche quando a
scaricare sarà un ordine online.

**Il comando è solo un'anteprima.** `forge` carica l'autoload ma non le
funzioni globali del core: `sendMail()` lì non esiste. L'attività invece gira
dentro `bin/scheduler.php`, che avvia il sito.

**Due pulizie a ogni giro**, che nessun altro farebbe:

1. gli avvisi di prodotti eliminati o tolti dalla griglia si chiudono;
2. gli avvisi aperti di prodotti tornati sopra la soglia senza un movimento (la
   soglia cambiata dal database, domani una prenotazione scaduta) passano da
   `Alerts::refresh()`. Senza, alla prossima discesa `Stock::apply()`
   troverebbe l'avviso ancora aperto e non ne aprirebbe uno nuovo.

L'anteprima non fa nemmeno queste: dice cosa succederebbe.

**Eliminare un prodotto con l'avviso aperto.** Anche `gst_stock_alerts` punta al
prodotto con una chiave esterna: `StockHistory::dropAlerts()` toglie gli avvisi
prima dell'eliminazione, dalla scheda dell'articolo e da quella della versione.

**Quando la posta non va.**

| Esito | Cosa succede agli avvisi |
|---|---|
| mandata a tutti | `notified_at`: non tornano più |
| fermata dall'hook | `notified_at`: è una scelta del sito, non un guasto |
| mandata solo ad alcuni | `notified_at`, e si segnala chi non l'ha ricevuta |
| non partita per nessuno | restano da mandare: si riprova al giro dopo |

Le segnalazioni vanno in `error_reports` con un **messaggio fisso**, così le
ripetizioni si contano sulla stessa riga. Attenzione: il registro del core, a
ogni ripetizione, scrive di nuovo a chi sviluppa. Se il server di posta è giù
non parte neanche quella; se invece rifiuta sempre lo stesso indirizzo, arriva
un'email tecnica ogni quarto d'ora finché qualcuno non corregge i destinatari.

**L'email.** L'oggetto è "N prodotti sotto scorta", il corpo viene da
`view/emails/low-stock.php`. Per cambiarlo si copia il file in
`custom/modules/gestionale/view/emails/low-stock.php`: la view riceve
`$items`, `$count`, `$url`, `$e()` per il testo e `$qty()` per i pezzi.

```php
LowStockNotifier::run(true);   // l'anteprima, come il comando
LowStockNotifier::run();       // un giro vero, come l'attività
```

Si manda con `Support\Mail\Mailer::send($key, $to, $subject, $body)`: applica
l'hook `beforeEmailSend` con la chiave `stock.low_stock` (vedi
[Hook del sito](hook.md)), manda un'email per indirizzo e torna `sent`,
`cancelled` o `failed`. `Support\Mail\Recipients::parse()` legge gli indirizzi
dell'impostazione: validi, non validi, senza doppioni.
````

Poi sostituisci il titolo `## Due trappole del framework` con `## Tre trappole del framework` e aggiungi in fondo alla sezione:

```markdown
**`sendMail()` "sgrassa" il corpo.** Il core passa il corpo da
`sanitizeEcho()`, che toglie le barre rovesciate e decodifica le entità: un
`&lt;b&gt;` scritto con cura nel nome di un prodotto torna `<b>` e diventa
grassetto vero, e `C:\cartella` perde la barra. `Mailer::shield()` prepara il
corpo perché dopo quel passaggio resti esattamente quello scritto. Chi manda
un'email dal modulo passa da `Mailer`, mai da `sendMail()` diretto.
```

- [ ] **Step 9: La guida di chi sviluppa — *Hook del sito***

In `docs/dev/concetti/hook.md` sostituisci:

```markdown
Gli altri hook nascono con il sotto-progetto che li usa.
```

con:

````markdown
Gli altri hook nascono con il sotto-progetto che li usa.

### Le email che passano da `beforeEmailSend`

| Chiave | Email | Messaggio |
|---|---|---|
| `stock.low_stock` | i prodotti sotto la scorta minima | `['to' => list<string>, 'subject' => string, 'body' => string]` |

- `to` può tornare come lista o come stringa separata da virgole: il gestionale
  lo rilegge e scarta gli indirizzi non validi.
- Un `to` vuoto, o fatto solo di indirizzi non validi, **ferma l'invio**, e
  gli avvisi contano come mandati: è una scelta del sito, non un guasto.
- Un'estensione che solleva un'eccezione finisce nel log e l'email parte con il
  messaggio com'era prima di lei.

```php
public function beforeEmailSend(string $key, array $message): array
{
    if ($key === 'stock.low_stock') {
        $message['to'][] = 'magazzino@example.com';
    }

    return $message;
}
```
````

- [ ] **Step 10: Il CHANGELOG**

In `CHANGELOG.md`, in fondo a `### Aggiunto` (dopo la voce della documentazione):

```markdown
- Avvisi di scorta minima (`low_stock_alerts`): campo *Scorta minima* nelle due
  schede, avviso aperto e chiuso da `Stock::apply()` e dal salvataggio della
  soglia, attività `gestionale.stock_alerts` (ogni quarto d'ora, nata spenta)
  che manda una email raggruppata ai *Destinatari degli avvisi* con la view
  sovrascrivibile e l'hook `beforeEmailSend` (`stock.low_stock`), anteprima
  `php forge gestionale:stock-alerts`, riquadro *Sotto scorta* nella home e
  giacenze negative in *Da controllare*.
```

- [ ] **Step 11: Esegui il test e verifica che passi**

Run: `php tests/DocsPagesTest.php`
Expected: PASS, compresi i due controlli nuovi.

- [ ] **Step 12: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale
git status --short
git add docs/user/magazzino-avvisi.md docs/user/SUMMARY.md docs/user/magazzino-giacenze.md docs/user/da-controllare.md docs/user/impostazioni.md docs/dev/concetti/magazzino.md docs/dev/concetti/hook.md CHANGELOG.md tests/DocsPagesTest.php
git commit -m "$(cat <<'MSG'
docs(magazzino): guide degli avvisi di scorta minima

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

### Task 11: verifica sul sito di prova e chiusura di G2b

**Files:**
- Modify: `TODO.md` (riga di G2b e riga del piano 4)
- Modify: `docs/superpowers/specs/2026-09-21-magazzino-e-anagrafiche-design.md` (riga **Stato**)

**Interfaces:**
- Consumes: tutto quello che i task 1-10 hanno prodotto; `php forge gestionale:demo` (una versione per articolo nasce con *Scorta minima* 5 e giacenza sotto, quindi con l'avviso aperto: `src/Seeding/CatalogDemo.php`); `php forge gestionale:stock-alerts` (task 7); lo scheduler del core, `php forge schedule:run`, che carica `wonder-image.php` e quindi ha `sendMail()`.
- Produces: G2b chiuso nel TODO e nella spec.

Il lavoro è su `main`, nello stesso checkout in cui lavora l'utente: si aggiungono **solo** i file nominati, dopo `git status --short`. Il push lo decide l'utente.

- [ ] **Step 1: La suite completa**

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/run.php`
Expected: tutto verde, compresi i test di `tests/integrazione/` (girano sul sito di prova).

- [ ] **Step 2: `forge update` due volte (validazione 2)**

Run: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update --local`
Expected: finisce senza errori; `gst_merchant_settings.low_stock_emails` c'è (task 3) e fra le pianificazioni compare `gestionale.stock_alerts`, **spenta**.

Run: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update --local`
Expected: finisce senza errori e non crea né modifica niente.

- [ ] **Step 3: Dati di prova da capo**

Run: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge gestionale:demo --fresh && php forge gestionale:demo`
Expected: i tre articoli di prova tornano, con una versione ciascuno sotto la scorta minima.

- [ ] **Step 4: L'anteprima a funzionalità bloccata (validazione 9)**

Run: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge gestionale:stock-alerts`
Expected: `Gli avvisi di scorta minima non sono sbloccati.` e nient'altro.

- [ ] **Step 5: Nel browser, a funzionalità bloccata (validazione 9)**

Su `https://ecommerce.test/backend/` (l'accesso lo fa l'utente):

1. nella scheda di un articolo senza varianti e in quella di una versione **non** c'è il campo *Scorta minima*;
2. in **Magazzino → Giacenze** non c'è il pulsante *Solo sotto scorta*;
3. nella home non c'è il riquadro *Sotto scorta*;
4. nelle impostazioni del negozio non c'è *Destinatari degli avvisi*.

- [ ] **Step 6: Nel browser, a funzionalità sbloccata (validazione 6, prima metà)**

In **Set Up → Funzionalità** sblocca *Avvisi di scorta minima*, poi:

1. il campo *Scorta minima* compare nella scheda dell'articolo senza varianti e nel riquadro *Magazzino* della versione;
2. la home mostra *Sotto scorta* con le versioni dei dati di prova, **Apri le giacenze** porta a *Giacenze* con il solo filtro *Solo sotto scorta* acceso;
3. con *Destinatari degli avvisi* vuoto il riquadro lo dice e il link apre le impostazioni del negozio;
4. su una versione sopra la soglia alza la *Scorta minima* sopra i pezzi disponibili e salva: la versione compare **subito** in *Sotto scorta*; riabbassala sotto i pezzi e salva: sparisce subito;
5. nelle impostazioni del negozio scrivi in *Destinatari degli avvisi* `prova@example.com, sbagliato`: il salvataggio si ferma e nomina `sbagliato`; con `prova@example.com; PROVA@example.com ,seconda@example.com` salva, e rileggendo il campo trovi `prova@example.com, seconda@example.com` (il doppione si toglie senza guardare le maiuscole, resta la prima scrittura);
6. torna nel terminale:

Run: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge gestionale:stock-alerts`
Expected: l'oggetto "N prodotti sotto scorta", la riga `A:` con i due indirizzi, l'elenco con disponibili e scorta minima di ogni versione, e l'ultima riga che dice che il comando non spedisce niente. Nessuna email parte.

- [ ] **Step 7: Nel browser, le giacenze sotto zero**

In **Set Up → Funzionalità** sblocca *Ordini* e *Vendita senza giacenza*, poi:

1. fai una **Rettifica** di una versione che la porti sotto zero;
2. nella home, *Da controllare* mostra la riga della versione con la quantità negativa e il pulsante **Rettifica**;
3. **Rettifica** apre la rettifica di quella versione; salvando una quantità positiva si torna a `/backend/` e la riga non c'è più.

Quando hai finito rimetti su **bloccate** *Vendita senza giacenza*, *Ordini* e *Avvisi di scorta minima*, e svuota *Destinatari degli avvisi*.

- [ ] **Step 8: L'email vera (validazione 6, seconda metà) — solo con il permesso dell'utente**

Mandare un'email dal sito di prova è mandarla a nome dell'utente: **chiedi prima**. Se l'utente è d'accordo, è lui a fare i passi nel backend:

1. sblocca *Avvisi di scorta minima* e scrive **il proprio** indirizzo in *Destinatari degli avvisi*;
2. accende `gestionale.stock_alerts` in **Dev → Pianificazioni**;
3. salvando, l'attività si mette in coda per il prossimo quarto d'ora: per farla girare subito apre `/backend/app/scheduler/`, sceglie *Gestionale: avvisi di scorta minima* in *Attività da eseguire* e preme **Richiedi esecuzione** (oppure aspetta il quarto d'ora).

Run: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge schedule:run`
Expected: il comando riporta `{"executed":1}` e arriva **una** email con l'elenco delle versioni sotto scorta. Rilanciando subito `php forge gestionale:stock-alerts` risponde `Nessun prodotto sotto scorta da segnalare.`: ogni prodotto si segnala una volta sola.

Poi l'utente spegne l'attività in **Dev → Pianificazioni**, svuota *Destinatari degli avvisi* e riblocca la funzionalità. Senza permesso il passo si salta, e la validazione 6 resta coperta dai test d'integrazione del task 6 con il postino finto: dillo nel resoconto.

- [ ] **Step 9: Ripulisci il sito di prova**

Run: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge gestionale:demo --fresh && php forge gestionale:demo`
Expected: i dati di prova tornano quelli di partenza, senza le rettifiche delle prove.

- [ ] **Step 10: Spunta il piano e chiudi G2b nel TODO**

In `TODO.md`, sotto **G2b**, sostituisci la riga

```markdown
    - [ ] Piano 4: avvisi di scorta minima (campo, avvisi, attività ed email raggruppata, riquadro, guide)
```

con una voce `[x]` sullo stile dei piani 1-3: data, percorso del piano, cosa è stato fatto, "Verificato nel browser" (o cosa non si è potuto verificare, per esempio l'email vera), e sotto i punti emersi. Quelli già noti mentre si scriveva il piano:

- **La chiave esterna degli avvisi**: eliminare un prodotto con l'avviso aperto andava in errore; `StockHistory::dropAlerts()` li toglie prima.
- **Avvisi orfani e avvisi vecchi**: prodotti eliminati o tolti dalla griglia, e prodotti tornati sopra la soglia senza movimenti; il giro dell'attività li chiude, l'anteprima no.
- **`sanitizeEcho()` del core rovina il corpo delle email** (toglie le barre rovesciate e decodifica le entità): `Mailer::shield()` lo protegge.
- **I comandi di `forge` non hanno `sendMail()`**: `gestionale:stock-alerts` è solo un'anteprima; l'invio vero lo fa lo scheduler, che carica `wonder-image.php`.
- **L'`ErrorReporter` del core riscrive a chi sviluppa a ogni occorrenza**: l'invio fallito si segnala con un messaggio fisso, così le ripetizioni si contano sulla stessa riga, ma un indirizzo rifiutato per sempre manda un'email tecnica ogni quarto d'ora finché qualcuno non corregge i destinatari (scritto nella guida sviluppatori).
- **Il link della guida di `MerchantSettingResource` era rotto** (`impostazioni/negozio`): corretto, e `DocsPagesTest` ora controlla anche i link fra le pagine delle guide.

Aggiungi quelli che sono emersi eseguendo i task. Poi cambia la riga di G2b da

```markdown
  - [ ] **G2b Magazzino base e anagrafiche** — spec: `docs/superpowers/specs/2026-09-21-magazzino-e-anagrafiche-design.md` (decisioni G2b.1–G2b.12)
```

a

```markdown
  - [x] **G2b Magazzino base e anagrafiche** — spec: `docs/superpowers/specs/2026-09-21-magazzino-e-anagrafiche-design.md` (decisioni G2b.1–G2b.12). **G2b chiuso il 2026-09-23.**
```

(la data è quella del giorno in cui si chiude davvero). La riga di **G2** resta `[ ]`: aspetta ancora G2a-bis.

- [ ] **Step 11: Lo stato della spec**

In `docs/superpowers/specs/2026-09-21-magazzino-e-anagrafiche-design.md` sostituisci

```markdown
- **Stato:** da approvare
```

con

```markdown
- **Stato:** approvata ed eseguita (quattro piani, G2b chiuso il 2026-09-23)
```

- [ ] **Step 12: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale
php tests/run.php
git status --short
git add TODO.md docs/superpowers/specs/2026-09-21-magazzino-e-anagrafiche-design.md
git commit -m "$(cat <<'MSG'
G2b: piano 4 eseguito, avvisi di scorta minima; G2b chiuso

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

Expected: suite verde, commit fatto. **Niente push**: i commit restano su `main` in locale finché l'utente non decide.

- [ ] **Step 13: La memoria**

Fuori dal repo, in `/Users/andreamarinoni/.claude/projects/-Users-andreamarinoni-Developer-packages-ecommerce/memory/project_gestionale_ecommerce_design.md` scrivi che G2b è chiuso (descrizione e testo), e aggiorna la riga corrispondente di `MEMORY.md`.
