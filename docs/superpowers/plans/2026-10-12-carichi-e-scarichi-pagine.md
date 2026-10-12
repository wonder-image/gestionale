# Carichi e scarichi, pagine (G2b-bis piano 5) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Le pagine *Carichi* e *Scarichi* di *Magazzino*: elenchi, bozza con «Cerca o scansiona» e la tabella delle righe, conferma, pagina in sola lettura con *Annulla* e *Duplica*, il documento nei Movimenti e un carico e uno scarico nei dati di prova.

**Architecture:** Due Resource (`StockReceiptResource`, `StockIssueResource`) su una base astratta `StockDocumentResource`, tutte sul Model `StockDocument` del piano 4; il servizio `StockDocuments` resta l'unico che scrive. La bozza usa un template suo (`PageSchema::view('form', …)`) con due bottoni, *Salva bozza* e *Conferma*, che postano alle rotte `store`/`update` del core: il core le chiede per disegnare il form, e lasciarle aperte sarebbe un buco (un POST diretto scriverebbe colonne grezze). Per questo `mutateRequestValues()` non restituisce mai i valori al core: controlla il CSRF, passa tutto a `StockDocumentForm::submit()`, mette il messaggio e fa il redirect con `exit`. È una deviazione dichiarata dalla spec §4.2, che parla di `afterStore()`/`afterUpdate()`: dopo lo store del core la bozza sarebbe già scritta con le colonne del form, senza righe né controlli del servizio. *Annulla* e *Duplica* sono form nascosti inviati a una Resource di sole azioni (`StockDocumentActionResource`, come `OrderActionResource`); *Elimina* è quella del core, con `assertDeletable()` e `deleteRecord()` che passano dal servizio. Le versioni arrivano da una rotta JSON del backend (`StockVersionSearch`).

**Tech Stack:** PHP 8.2, framework Wonder Image (`wonder-image/app`), modulo `wonder-image/gestionale`, MySQL; test con l'harness del modulo (`check()`, `summary()`)

**Spec:** `docs/superpowers/specs/2026-09-23-giacenze-movimenti-documenti-design.md` — §4.2 (pagine), §5.1 (guide), §5.2 (dati di prova), §5.3 (test), §5.4 (prove nel browser 3, 4, 6, 7), §5.5 piano 5. Il piano 4 (`docs/superpowers/plans/2026-10-12-documenti-di-magazzino-dati-e-servizio.md`) ha scritto Model, regole e servizio.

## Global Constraints

- Indirizzi `app/gestionale/carichi` e `app/gestionale/scarichi`; permessi `admin`, `administrator`; niente API (`apiSchema()->enabled(false)`).
- Menu *Magazzino*: Giacenze 10 · Carichi 20 · Scarichi 30 · (Inventari 40, piano 6) · Movimenti 50.
- Bottoni «Nuovo carico», «Nuovo scarico»; «Conferma carico», «Conferma scarico»; «Salva bozza».
- Un documento tiene al massimo 500 righe (`StockDocumentRules::MAX_LINES`); messaggio «Un documento tiene al massimo 500 righe».
- La ricerca dà al più 20 versioni; i multiprodotti (`gst_product_models.type = 'bundle'`) non compaiono mai.
- Invio nella casella «Cerca o scansiona» non prende mai il primo risultato a caso: aggiunge solo con un codice esatto di una versione sola.
- Messaggi del lettore: «Più versioni con questo codice: scegline una», «Nessuna versione con questo codice».
- Le righe viaggiano in un solo campo JSON (`lines`), mai un campo per numero (`max_input_vars`).
- Domande con `wi.confirm()` del core (conferma della bozza, `?duplica=1`) e `data-wi-confirm` (*Annulla*): carico «{pezzi} pezzi in {righe} righe entrano in magazzino. Dopo non si modifica più: si può solo annullare.»; scarico «{pezzi} pezzi in {righe} righe escono dal magazzino. Dopo non si modifica più: si può solo annullare.»; annullamento «Il carico {numero} si annulla: i suoi movimenti si stornano e il documento resta, annullato.» (*Lo scarico …* per lo scarico).
- Una query per pagina, mai una per riga: righe, pezzi, chi e data nella `select()` dell'elenco; il documento dei Movimenti letto in blocco.
- Le giacenze si scrivono solo con `StockDocuments` (che usa `Stock::apply()`); le pagine non scrivono mai `gst_stock_documents` o `gst_stock_document_items` direttamente.
- Form compatti del backend: tooltip al posto dei testi d'aiuto; Sede · Causale · Nota su una riga.
- JS in ES5 dentro una IIFE, come `ProductModelResource::optionsPicker()`; nessuna libreria nuova.
- Test: niente email (`WONDER_NO_MAIL`); prima di un test d'integrazione il controllo `ps` della Preparazione.
- Commit con il trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; niente push.

## Review Focus

1. **Doppio clic su *Conferma*** (o due schede aperte): una sola conferma, un solo numero, nessun movimento doppio. Il server è già coperto dal `FOR UPDATE` di `StockDocuments::confirm()` (piano 4, la seconda trova `not_draft`); la pagina spegne i bottoni all'invio. Coperto nel Task 4 (la seconda conferma porta alla scheda con il messaggio, nessun movimento in più) e nel Task 5 (bottoni spenti all'invio).
2. **Conferma fallita su un documento nuovo** (scarico sotto zero alla prima pressione): la bozza nasce comunque, una sola, con le righe scritte, e la pagina riapre quella bozza con l'errore che dice quale versione; un secondo invio non crea una seconda bozza. Coperto nel Task 4.
3. **Pagina rimasta aperta su un documento già confermato o annullato** da un'altra scheda: *Salva bozza* e *Conferma* non scrivono niente e portano alla scheda in sola lettura con un messaggio; *Annulla* due volte dà il rifiuto `cannot_cancel`. Coperto nel Task 4 e nel Task 6.
4. **SKU o EAN condiviso da più versioni**: il lettore non aggiunge niente e mostra le versioni con «Più versioni con questo codice: scegline una». Coperto nel Task 2 (`scan()` → `many`) e nel Task 5 (il JS non aggiunge con `many`).
5. **`%`, `_` e apici nella ricerca**: si cercano come caratteri, senza errori SQL e senza trovare tutto. Coperto nel Task 2.

---

## Preparazione (una volta, prima del Task 1)

Il worktree è `/Users/andreamarinoni/Developer/worktrees/g3-carichi-scarichi/gestionale`, ramo `g3-carichi-scarichi`, base `3ae99c2`. Un'altra chat lavora in `packages/gestionale` (reCAPTCHA): lì non si scrive niente.

- [ ] **Passo 1: vendor del worktree**

```bash
P=/Users/andreamarinoni/Developer/packages; W=/Users/andreamarinoni/Developer/worktrees/g3-carichi-scarichi
cp -R $P/gestionale/vendor $W/gestionale/ && ln -sfn $P/app $W/gestionale/vendor/wonder-image/app
php $W/gestionale/tests/StockDocumentRulesTest.php
```

Expected: tutti i check passano (`summary()` senza errori).

- [ ] **Passo 2: sito di prova `ecommerce-carichi`**

```bash
W=/Users/andreamarinoni/Developer/worktrees/g3-carichi-scarichi
B=/Users/andreamarinoni/Developer/boilerplates/ecommerce-site; S=/Users/andreamarinoni/Developer/boilerplates/ecommerce-site-carichi
git -C $B worktree add --detach $S
for f in .env .htaccess assets/0.0/css/set-up assets/lib assets/upload/app bin demo handler robots.txt storage vendor custom/config/modules.php custom/config/modules shared/sync-data.json; do mkdir -p "$S/$(dirname $f)"; rsync -a "$B/$f" "$S/$(dirname $f)/"; done
rm $S/vendor/wonder-image/gestionale && ln -s $W/gestionale $S/vendor/wonder-image/gestionale
sed -i '' 's#^APP_URL=.*#APP_URL=https://ecommerce-carichi.test#' $S/.env
cd $S && herd link ecommerce-carichi && herd secure ecommerce-carichi
export WI_TEST_SITE=$S WI_TEST_URL=https://ecommerce-carichi.test
php $W/gestionale/tests/integrazione/StockDocumentsTest.php
```

Expected: tutti i check passano. Il sito di prova condivide il DB `ecommerce_site` con il boilerplate.

- [ ] **Passo 3: la regola prima di ogni test d'integrazione**

Il DB `ecommerce_site` è condiviso con le altre chat: due suite insieme danno «SAVEPOINT wi_sp_1 does not exist». Prima di ogni `php tests/integrazione/…` e di `php tests/run.php`:

```bash
ps aux | grep "php .*Test.php" | grep -v grep
```

Expected: nessuna riga. Se ce n'è una, aspettare che finisca. In tutti i comandi qui sotto `W` e `S` sono quelli del Passo 2, e `WI_TEST_SITE`/`WI_TEST_URL` restano esportate.

---

### Task 1: Ritocchi di regole e servizio

I ritocchi minori rimandati dalla revisione finale del piano 4 (`TODO.md`, sotto il piano 4) che toccano regole e servizio: il limite di 500 righe controllato prima di leggere le righe, `product_id` letto in modo rigoroso, conferma e annullamento che applicano i movimenti in ordine di versione (due conferme sugli stessi articoli non si bloccano a vicenda), *Duplica* senza `FOR UPDATE` sul documento d'origine. Più le due chiavi di lingua che servono alle pagine.

**Files:**
- Modify: `src/Support/Stock/StockDocumentRules.php:79-114`
- Modify: `src/Support/Stock/StockDocuments.php:123` (confirm), `:165-170` (cancel), `:198` (duplicate)
- Modify: `lang/it/gestionale.json:28`
- Test: `tests/StockDocumentRulesTest.php`, `tests/integrazione/StockDocumentsTest.php`

**Interfaces:**
- Consumes: niente di nuovo.
- Produces: `StockDocumentRules::lines()` lancia `stock_document.too_many_rows` prima di leggere le righe e `stock_document.invalid_lines` per un `product_id` che non è un intero positivo o per una riga che non è un array; chiavi di lingua `gestionale.errors.stock_document.invalid_lines` e `gestionale.errors.stock_document.expired` (Task 4 le usa).

- [ ] **Passo 1: i test unitari che falliscono**

In `tests/StockDocumentRulesTest.php`, subito dopo il check «righe: 500 passano, 501 no»:

```php
check('righe: oltre 500 si rifiuta prima di leggerle', fn () =>
    $rifiuto(fn () => Regole::lines(array_fill(0, 501, ['product_id' => 'abc', 'quantity' => '1']))) === 'stock_document.too_many_rows'
);

check('righe: un product_id che non è un intero positivo è un rifiuto', fn () =>
    $rifiuto(fn () => Regole::lines([['product_id' => '12abc', 'quantity' => '1']])) === 'stock_document.invalid_lines'
    && $rifiuto(fn () => Regole::lines([['product_id' => '-3', 'quantity' => '1']])) === 'stock_document.invalid_lines'
    && $rifiuto(fn () => Regole::lines([['product_id' => -3, 'quantity' => '1']])) === 'stock_document.invalid_lines'
    && $rifiuto(fn () => Regole::lines([['product_id' => [4], 'quantity' => '1']])) === 'stock_document.invalid_lines'
    && $rifiuto(fn () => Regole::lines(['4'])) === 'stock_document.invalid_lines'
);

check('righe: product_id vuoto o zero si salta, le cifre in testo valgono', fn () =>
    Regole::lines([
        ['product_id' => '', 'quantity' => ''],
        ['product_id' => 0, 'quantity' => '1'],
        ['product_id' => '0', 'quantity' => '1'],
        ['product_id' => null, 'quantity' => '1'],
        ['product_id' => ' 7 ', 'quantity' => '2'],
        ['product_id' => 9, 'quantity' => ''],
    ]) === [['product_id' => 7, 'quantity' => 2.0], ['product_id' => 9, 'quantity' => null]]
);
```

- [ ] **Passo 2: i test d'integrazione che falliscono**

In `tests/integrazione/StockDocumentsTest.php`, subito dopo il check «annullare: uno storno per movimento, segno opposto, con il suo riferimento»:

```php
check('confermare: i movimenti seguono l\'ordine delle versioni, non quello delle righe', function () {
    return prova(static function (): bool {
        [$a, $b] = [articolo(10), articolo(10)];
        $id = StockDocuments::create('receipt', [], [
            ['product_id' => $b, 'quantity' => '1'],
            ['product_id' => $a, 'quantity' => '2'],
        ]);
        StockDocuments::confirm($id);
        $mov = righe(StockMovement::class, "reference_type = 'stock_document' AND reference_id = ".$id);

        return array_map('intval', array_column($mov, 'product_id')) === [$a, $b];
    });
});

check('annullare: gli storni seguono l\'ordine delle versioni', function () {
    return prova(static function (): bool {
        [$a, $b] = [articolo(10), articolo(10)];
        $id = StockDocuments::create('receipt', [], [
            ['product_id' => $b, 'quantity' => '1'],
            ['product_id' => $a, 'quantity' => '2'],
        ]);
        StockDocuments::confirm($id);
        StockDocuments::cancel($id);
        $storni = righe(StockMovement::class, "reference_type = 'stock_document_cancel' AND reference_id = ".$id);

        return array_map('intval', array_column($storni, 'product_id')) === [$a, $b];
    });
});
```

`articolo()` crea le versioni in ordine, quindi `$a < $b`. Il secondo check fallisce oggi perché la conferma scrive `$b` prima di `$a` e l'annullamento segue l'ordine dei movimenti; dopo il Passo 6 la conferma li scrive già in ordine, e il check resta come guardia del risultato.

- [ ] **Passo 3: eseguirli e vederli fallire**

```bash
cd $W/gestionale && php tests/StockDocumentRulesTest.php
ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentsTest.php
```

Expected: falliscono «oltre 500 si rifiuta prima di leggerle» (oggi `'abc'` diventa 0 e la riga si salta: nessun rifiuto), «un product_id che non è un intero positivo» e «product_id vuoto o zero si salta» (`' 7 '` oggi passa, ma `['4']` dà un errore PHP o nessun rifiuto); in integrazione falliscono i due check nuovi. Tutti gli altri passano.

- [ ] **Passo 4: `StockDocumentRules::lines()`**

Sostituire le righe 79-114 (docblock e metodo `lines()`) con:

```php
    /**
     * Le righe del form, controllate. Una riga senza versione si ignora (la
     * pagina può lasciarne una vuota); la casella vuota resta `null`, che non
     * vuol dire zero. `{{row}}` è la riga come la conta chi legge, da 1.
     *
     * Il limite si controlla prima di leggere: 10.000 righe non si leggono
     * per poi rifiutarle. Un `product_id` che non è un intero positivo è un
     * rifiuto, non uno zero: «12abc» non diventa 12.
     *
     * @param list<mixed> $input
     * @return list<array{product_id: int, quantity: ?float}>
     */
    public static function lines(array $input): array
    {
        if (count($input) > self::MAX_LINES) {
            throw UserError::make('stock_document.too_many_rows');
        }

        $lines = [];
        $seen = [];
        $row = 0;

        foreach ($input as $line) {
            $row++;

            if (!is_array($line)) {
                throw UserError::make('stock_document.invalid_lines');
            }

            $productId = self::productId($line['product_id'] ?? null);

            if ($productId === 0) {
                continue;
            }

            if (isset($seen[$productId])) {
                throw UserError::make('stock_document.duplicate_product', ['row' => $row]);
            }

            $seen[$productId] = true;
            $lines[] = ['product_id' => $productId, 'quantity' => self::quantity($line['quantity'] ?? null, $row)];
        }

        return $lines;
    }
```

E, fra `difference()` e `quantity()` in fondo alla classe:

```php
    /** La versione di una riga: 0 se la riga è vuota, un rifiuto se non è un intero positivo. */
    private static function productId(mixed $raw): int
    {
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return 0;
        }

        if (is_int($raw) && $raw > 0) {
            return $raw;
        }

        if (is_string($raw) && preg_match('/^[1-9]\d{0,9}$/D', trim($raw)) === 1) {
            return (int) trim($raw);
        }

        throw UserError::make('stock_document.invalid_lines');
    }
```

- [ ] **Passo 5: l'annullamento in ordine di versione**

In `src/Support/Stock/StockDocuments.php`, nel metodo `cancel()`, subito dopo la lettura di `$movements` (la `self::rows(StockMovement::find(…))` che finisce con `'id', 'ASC'\n            ));`) e prima del `foreach ($movements as $movement)`:

```php
            // In ordine di versione, come la conferma: due operazioni sugli
            // stessi articoli bloccano le righe di giacenza nello stesso
            // ordine e si mettono in fila invece di bloccarsi a vicenda.
            usort($movements, static fn (array $x, array $y): int =>
                [(int) $x['product_id'], (int) $x['id']] <=> [(int) $y['product_id'], (int) $y['id']]);
```

- [ ] **Passo 6: la conferma in ordine di versione**

Nel metodo `confirm()`, sostituire `foreach ($items as $item) {` (riga 123) con:

```php
            // In ordine di versione, non di riga: vedi `cancel()`.
            $ordered = $items;
            usort($ordered, static fn (array $x, array $y): int => $x['product_id'] <=> $y['product_id']);

            foreach ($ordered as $item) {
```

- [ ] **Passo 7: *Duplica* senza `FOR UPDATE`**

Nel metodo `duplicate()`, sostituire `$document = self::locked($id);` (riga 198) con:

```php
            // L'originale si legge e basta: bloccarlo fermerebbe per niente
            // una conferma o un annullamento dello stesso documento.
            $document = StockDocument::findById($id);

            if (!is_array($document) || !isset($document['id']) || (string) ($document['deleted'] ?? 'false') === 'true') {
                throw UserError::make('stock_document.not_found');
            }
```

- [ ] **Passo 8: le due chiavi di lingua**

In `lang/it/gestionale.json`, dentro `errors.stock_document`, sostituire la riga 28:

```json
                "too_many_rows": "Un documento tiene al massimo 500 righe: dividilo in due.",
                "invalid_lines": "Le righe non si sono lette: ricarica la pagina e riprova.",
                "expired": "La pagina è scaduta: ricaricala e riprova.",
```

- [ ] **Passo 9: eseguire i test e vederli passare**

```bash
cd $W/gestionale && php tests/StockDocumentRulesTest.php
ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentsTest.php
php -r 'json_decode(file_get_contents("lang/it/gestionale.json"), flags: JSON_THROW_ON_ERROR); echo "ok\n";'
```

Expected: tutti i check passano, compresi quelli di *Duplica* che c'erano già; il JSON stampa `ok`.

- [ ] **Passo 10: commit**

```bash
cd $W/gestionale && git add src/Support/Stock/StockDocumentRules.php src/Support/Stock/StockDocuments.php lang/it/gestionale.json tests/StockDocumentRulesTest.php tests/integrazione/StockDocumentsTest.php
git commit -m "$(cat <<'EOF'
Documenti di magazzino: limite e versioni delle righe rigorosi, movimenti in ordine di versione

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Ricerca delle versioni e rotta JSON

La casella «Cerca o scansiona» chiede le versioni a una rotta JSON del backend. La ricerca va parola per parola su nome della versione, SKU, EAN e nome dell'articolo; il codice del lettore cerca SKU o EAN esatti e dice se ha trovato una versione, più versioni o nessuna. `gst_products` riceve l'indice su `ean`.

**Files:**
- Create: `src/Support/Stock/StockVersionSearch.php`
- Create: `http/api/stock/versions.php`
- Modify: `config/routes/route.api.php` (in fondo)
- Modify: `src/Models/Catalog/Product.php:65-71`
- Test: `tests/StockVersionSearchTest.php`, `tests/integrazione/StockVersionSearchTest.php`

**Interfaces:**
- Consumes: `ProductNames::models(array $products): array<int,string>`, `ProductNames::full(array $product, array $modelNames): string`, `ProductPhotos::forModel(int $modelId, int $variantId, int $productId): string`, `Levels::byLocationForProducts(list<int>): array<int, array<int, array{quantity: float, reserved: float, available: float}>>`, `Locations::mainId(): int`.
- Produces:
  - `StockVersionSearch::LIMIT = 20`
  - `StockVersionSearch::outcome(array $matches): string` — `'one'|'many'|'none'`
  - `StockVersionSearch::search(string $q, int $locationId): list<Version>`
  - `StockVersionSearch::scan(string $code, int $locationId): array{result: string, versions: list<Version>}`
  - `StockVersionSearch::describe(array $ids, int $locationId): array<int, Version>` (per id; l'ordine lo decide chi chiama)
  - `Version` = `array{id: int, label: string, sku: string, ean: string, photo: string, stock: float, inactive: bool}`
  - rotta `api.gestionale.backend.stock.versions`, GET `/api/gestionale/backend/magazzino/versioni/` con `q`, `code` o `ids` (id separati da virgola), più `location`; risponde `{versions}` (per `q` e `ids`) o `{result, versions}` (per `code`).

- [ ] **Passo 1: i test unitari che falliscono**

Create `tests/StockVersionSearchTest.php`:

```php
<?php
/** php tests/StockVersionSearchTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Stock\StockVersionSearch as Ricerca;

check('lettore: una versione, più versioni, nessuna', fn () =>
    Ricerca::outcome([['id' => 4]]) === 'one'
    && Ricerca::outcome([['id' => 4], ['id' => 5]]) === 'many'
    && Ricerca::outcome([]) === 'none'
);

check('al più 20 versioni', fn () => Ricerca::LIMIT === 20);

check('la rotta delle versioni c\'è, con il suo file', function () {
    $rotte = (string) file_get_contents(__DIR__.'/../config/routes/route.api.php');

    return str_contains($rotte, "->name('stock.versions')")
        && str_contains($rotte, "->permit(['admin', 'administrator'])")
        && is_file(Gestionale::handlerPath('api/stock/versions.php'));
});

summary();
```

- [ ] **Passo 2: i test d'integrazione che falliscono**

Create `tests/integrazione/StockVersionSearchTest.php`:

```php
<?php
/** php tests/integrazione/StockVersionSearchTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\StockVersionSearch as Ricerca;
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

/** Un codice che nessun'altra versione ha. */
function codice(string $prefisso = 'TST-VER-'): string
{
    return $prefisso.strtoupper(substr(uniqid(), -7)).random_int(10, 99);
}

check('lettore: lo SKU esatto di una versione dà quella, con giacenza nella sede e foto', function () {
    return prova(static function (): bool {
        $sku = codice();
        $id = articoloConGiacenza(7, $sku);
        $esito = Ricerca::scan($sku, 0);
        $v = $esito['versions'][0] ?? [];

        return $esito['result'] === 'one'
            && count($esito['versions']) === 1
            && $v['id'] === $id
            && $v['sku'] === $sku
            && $v['stock'] === 7.0
            && $v['inactive'] === false
            && is_string($v['photo'])
            && str_contains($v['label'], 'Prova vendite '.$sku);
    });
});

check('lettore: l\'EAN esatto vale come lo SKU', function () {
    return prova(static function (): bool {
        $id = articoloConGiacenza(1, codice());
        $ean = '80'.random_int(10000000000, 99999999999);
        Product::update(['ean' => $ean], $id);
        $esito = Ricerca::scan($ean, 0);

        return $esito['result'] === 'one' && ($esito['versions'][0]['id'] ?? 0) === $id;
    });
});

check('lettore: un codice di due versioni dà «many» e le due versioni', function () {
    return prova(static function (): bool {
        $ean = '80'.random_int(10000000000, 99999999999);
        $a = articoloConGiacenza(1, codice());
        $b = articoloConGiacenza(1, codice());
        Product::update(['ean' => $ean], $a);
        Product::update(['ean' => $ean], $b);
        $esito = Ricerca::scan($ean, 0);
        $ids = array_column($esito['versions'], 'id');
        sort($ids);

        return $esito['result'] === 'many' && $ids === [$a, $b];
    });
});

check('lettore: un codice sconosciuto o vuoto dà «none»', fn () =>
    Ricerca::scan(codice('NESSUNO-'), 0) === ['result' => 'none', 'versions' => []]
    && Ricerca::scan('   ', 0) === ['result' => 'none', 'versions' => []]
);

check('lettore: lo SKU è esatto, non un pezzo', function () {
    return prova(static function (): bool {
        $sku = codice();
        articoloConGiacenza(1, $sku);

        return Ricerca::scan(substr($sku, 0, -1), 0)['result'] === 'none';
    });
});

check('ricerca: parola per parola su nome dell\'articolo e SKU', function () {
    return prova(static function (): bool {
        $sku = codice();
        $id = articoloConGiacenza(1, $sku);
        $trovate = array_column(Ricerca::search('vendite '.substr($sku, 4), 0), 'id');

        return $trovate === [$id];
    });
});

check('ricerca: niente multiprodotti, niente versioni cancellate', function () {
    return prova(static function (): bool {
        $sku = codice();
        $a = articoloConGiacenza(1, $sku);
        $bundle = multiprodottoDiProva('fixed', [['product_id' => $a, 'quantity' => 1]], []);
        Product::update(['sku' => $sku.'-B'], $bundle);
        $cancellata = articoloConGiacenza(1, $sku.'-C');
        Product::update(['deleted' => 'true'], $cancellata);

        return array_column(Ricerca::search($sku, 0), 'id') === [$a]
            && Ricerca::scan($sku.'-B', 0)['result'] === 'none'
            && Ricerca::scan($sku.'-C', 0)['result'] === 'none'
            && Ricerca::describe([$bundle, $cancellata], 0) === [];
    });
});

check('ricerca: %, _ e apici si cercano come caratteri', function () {
    return prova(static function (): bool {
        $sku = codice();
        articoloConGiacenza(1, $sku);

        return Ricerca::search('%', 0) === array_values(array_filter(Ricerca::search('%', 0), static fn (array $v): bool => str_contains($v['label'].$v['sku'].$v['ean'], '%')))
            && Ricerca::search('_'.substr($sku, 1), 0) === []
            && Ricerca::search($sku."'", 0) === []
            && Ricerca::search("' OR '1'='1", 0) === []
            && Ricerca::scan("' OR '1'='1", 0)['result'] === 'none';
    });
});

check('ricerca: al più 20 versioni', function () {
    return prova(static function (): bool {
        $prefisso = codice('TST-MOLTE-');

        for ($i = 0; $i < 21; $i++) {
            articoloConGiacenza(0, $prefisso.'-'.$i);
        }

        return count(Ricerca::search($prefisso, 0)) === 20;
    });
});

check('ricerca: una versione ferma ha il segno', function () {
    return prova(static function (): bool {
        $sku = codice();
        $id = articoloConGiacenza(1, $sku);
        Product::update(['active' => 'false'], $id);

        return (Ricerca::search($sku, 0)[0]['inactive'] ?? false) === true;
    });
});

summary();
```

- [ ] **Passo 3: eseguirli e vederli fallire**

```bash
cd $W/gestionale && php tests/StockVersionSearchTest.php
ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockVersionSearchTest.php
```

Expected: errore fatale «Class "Wonder\Plugin\Gestionale\Support\Stock\StockVersionSearch" not found».

- [ ] **Passo 4: `StockVersionSearch`**

Create `src/Support/Stock/StockVersionSearch.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductPhotos;

/**
 * Le versioni per la casella «Cerca o scansiona» dei documenti di magazzino
 * (G2b-bis 4.2). G3 la riuserà per gli ordini ai fornitori.
 *
 * Mai i multiprodotti (arrivano con G5) né le versioni cancellate; le ferme
 * sì, con il segno. SKU ed EAN non sono unici: il lettore dice se il codice è
 * di una versione, di più versioni o di nessuna, e la pagina aggiunge la riga
 * solo nel primo caso.
 *
 * @phpstan-type Version array{id: int, label: string, sku: string, ean: string, photo: string, stock: float, inactive: bool}
 */
final class StockVersionSearch
{
    public const LIMIT = 20;

    /** Quante parole della ricerca contano: le altre si ignorano. */
    private const WORDS = 5;

    /** @param list<mixed> $matches */
    public static function outcome(array $matches): string
    {
        return match (count($matches)) {
            0 => 'none',
            1 => 'one',
            default => 'many',
        };
    }

    /**
     * Al più 20 versioni che contengono ogni parola nel nome, nello SKU,
     * nell'EAN o nel nome dell'articolo.
     *
     * @return list<Version>
     */
    public static function search(string $q, int $locationId): array
    {
        $words = array_slice(preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, self::WORDS);

        if ($words === []) {
            return [];
        }

        $models = '`'.ProductModel::$table.'`';
        $conditions = ["`deleted` = 'false'", self::notBundle()];

        foreach ($words as $word) {
            $like = self::like($word);
            $conditions[] = '(`name` LIKE '.$like.' OR `sku` LIKE '.$like.' OR `ean` LIKE '.$like
                .' OR `product_model_id` IN (SELECT m.`id` FROM '.$models.' m WHERE m.`name` LIKE '.$like." AND m.`deleted` = 'false'))";
        }

        $ids = array_map('intval', array_column(self::rows(Product::find(implode(' AND ', $conditions), self::LIMIT, 'id', 'ASC')), 'id'));
        $found = self::describe($ids, $locationId);

        return array_values(array_filter(array_map(static fn (int $id): ?array => $found[$id] ?? null, $ids)));
    }

    /**
     * Il codice del lettore: SKU o EAN esattamente uguale.
     *
     * @return array{result: string, versions: list<Version>}
     */
    public static function scan(string $code, int $locationId): array
    {
        $code = trim($code);

        if ($code === '') {
            return ['result' => 'none', 'versions' => []];
        }

        $ids = [];

        foreach (['sku', 'ean'] as $column) {
            foreach (self::rows(Product::find([$column => $code, 'deleted' => 'false'])) as $row) {
                $ids[(int) $row['id']] = (int) $row['id'];
            }
        }

        $versions = array_values(self::describe(array_values($ids), $locationId));

        return ['result' => self::outcome($versions), 'versions' => $versions];
    }

    /**
     * Le versioni di questi id, pronte per la pagina; quelle che non ci sono
     * più, i multiprodotti e gli id non validi mancano.
     *
     * @param list<mixed> $ids
     * @return array<int, Version>
     */
    public static function describe(array $ids, int $locationId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $products = self::rows(Product::find(
            '`id` IN ('.implode(', ', $ids).") AND `deleted` = 'false' AND ".self::notBundle(),
            null, 'id', 'ASC'
        ));
        $location = $locationId > 0 ? $locationId : Locations::mainId();
        $modelNames = ProductNames::models($products);
        $levels = Levels::byLocationForProducts(array_map('intval', array_column($products, 'id')));
        $versions = [];

        foreach ($products as $product) {
            $id = (int) $product['id'];
            $versions[$id] = [
                'id' => $id,
                'label' => ProductNames::full($product, $modelNames),
                'sku' => (string) ($product['sku'] ?? ''),
                'ean' => (string) ($product['ean'] ?? ''),
                'photo' => ProductPhotos::forModel((int) $product['product_model_id'], (int) ($product['product_variant_id'] ?? 0), $id),
                'stock' => round((float) ($levels[$id][$location]['quantity'] ?? 0), 3),
                'inactive' => (string) ($product['active'] ?? 'true') === 'false',
            ];
        }

        return $versions;
    }

    /** `'%parola%'` per LIKE: l'apice è escapato dal driver, `%` e `_` restano caratteri. */
    private static function like(string $word): string
    {
        return "'%".addcslashes(Product::connection()->real_escape_string($word), '%_')."%'";
    }

    private static function notBundle(): string
    {
        return '`product_model_id` NOT IN (SELECT b.`id` FROM `'.ProductModel::$table."` b WHERE b.`type` = 'bundle')";
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

- [ ] **Passo 5: l'handler e la rotta**

Create `http/api/stock/versions.php`:

```php
<?php

use Wonder\App\Support\ApiRequest;
use Wonder\Plugin\Gestionale\Support\Stock\StockVersionSearch;

// «Cerca o scansiona» dei documenti di magazzino: `code` è il lettore (SKU o
// EAN esatto), `q` la ricerca, `ids` le righe già nella pagina; `location` la
// sede del documento per la giacenza.
$location = ApiRequest::int('location');
$code = ApiRequest::string('code');
$ids = ApiRequest::string('ids');

if ($ids !== '') {
    // Cambiando la sede, la pagina rilegge la giacenza delle sue righe.
    ApiRequest::json(['versions' => array_values(StockVersionSearch::describe(explode(',', $ids), $location))]);
}

if ($code !== '') {
    ApiRequest::json(StockVersionSearch::scan($code, $location));
}

ApiRequest::json(['versions' => StockVersionSearch::search(ApiRequest::string('q'), $location)]);
```

In fondo a `config/routes/route.api.php`:

```php

// Il backend del gestionale: con la sessione e i permessi delle pagine.
Route::area('api')->response('json')->backend()->translatable(false)
    ->name('api.gestionale.backend.')->prefix('/gestionale/backend')->group(function () {
        Route::get('/magazzino/versioni/', Gestionale::handlerPath('api/stock/versions.php'))
            ->name('stock.versions')->guarded()->permit(['admin', 'administrator']);
    });
```

Se nel file mancano gli `use` di `Route` e `Gestionale`, copiarli dal gruppo del webhook Stripe che c'è già sopra.

- [ ] **Passo 6: l'indice su `ean`**

In `src/Models/Catalog/Product.php`, dentro `tablePseudos()`, dopo `'ind_sku' => ['index' => 'sku'],`:

```php
            'ind_ean' => ['index' => 'ean'],
```

- [ ] **Passo 7: eseguire i test e vederli passare**

```bash
cd $W/gestionale && php tests/StockVersionSearchTest.php
ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockVersionSearchTest.php
cd $S && php forge update --local && cd $W/gestionale
MYSQL_PWD=$(grep '^DB_PASSWORD=' $S/.env | cut -d= -f2-) mysql -u $(grep '^DB_USERNAME=' $S/.env | cut -d= -f2-) $(grep '^DB_DATABASE=' $S/.env | cut -d= -f2-) -e "SHOW INDEX FROM gst_products WHERE Key_name = 'ind_ean'"
curl -s -o /dev/null -w '%{http_code}\n' "$WI_TEST_URL/api/gestionale/backend/magazzino/versioni/?q=a"
```

Expected: i check passano; `SHOW INDEX` stampa una riga; `curl` senza sessione non dà 200 (401, 403 o un redirect al login): la rotta chiede i permessi.

- [ ] **Passo 8: commit**

```bash
cd $W/gestionale && git add src/Support/Stock/StockVersionSearch.php http/api/stock/versions.php config/routes/route.api.php src/Models/Catalog/Product.php tests/StockVersionSearchTest.php tests/integrazione/StockVersionSearchTest.php
git commit -m "$(cat <<'EOF'
Magazzino: ricerca delle versioni e lettore di codici, rotta JSON del backend, indice su ean

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Le Resource ed elenchi di Carichi e Scarichi

Una base astratta con tutto quello che carichi, scarichi e (piano 6) inventari hanno in comune, e due Resource che dicono solo il tipo, i nomi e il posto nel menu. Qui nascono l'elenco, il menu ⋯, l'eliminazione via servizio e gli indirizzi delle pagine; le pagine della bozza e della scheda arrivano nei Task 5 e 6.

**Files:**
- Create: `src/Resources/Stock/StockDocumentResource.php`
- Create: `src/Resources/Stock/StockReceiptResource.php`
- Create: `src/Resources/Stock/StockIssueResource.php`
- Modify: `tests/ConventionsTest.php:55-56` (`$sempreAttive`)
- Test: `tests/integrazione/StockDocumentListTest.php`

**Interfaces:**
- Consumes: `StockDocuments::assertDeletable(int)`, `StockDocuments::delete(int)` (piano 4); `MovementPeriod::range(string, DateTimeImmutable): ?array{from, to}`; `Reasons::forDocument('issue')`, `Reasons::label(string)`; `Locations::shown()`.
- Produces:
  - `abstract StockDocumentResource::type(): string` — `'receipt'|'issue'`
  - `StockDocumentResource::select(): string` — alias `sort_date`, `row_count`, `piece_count`, `who_name`
  - `StockDocumentResource::periodCondition(array $values, DateTimeImmutable $now): string`
  - `StockDocumentResource::classFor(string $type): ?string` — la Resource del tipo, `null` se non ha pagine
  - `StockDocumentResource::viewUrl(int $id): string`, `editUrl(int $id): string`, `createUrl(): string`, `listUrl(): string`
  - `StockDocumentResource::pageUrl(array $document): string` — edit per una bozza, view per gli altri stati, sulla Resource del suo tipo; `listUrl()` se il tipo non ha pagine
  - `StockDocumentResource::newLabel(): string` («Nuovo carico»), `confirmLabel(): string` («Conferma carico»), `singular(): string` («Carico»)
  - `StockDocumentResource::showsLocation(): bool`, `locationOptions(): array<string,string>` (per il form del Task 5)

- [ ] **Passo 1: il test d'integrazione che fallisce**

Create `tests/integrazione/StockDocumentListTest.php`:

```php
<?php
/** php tests/integrazione/StockDocumentListTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\App\Models\User\User;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Resources\Stock\StockDocumentResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockIssueResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockReceiptResource;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocuments;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

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

function articolo(float $pezzi = 10): int
{
    return articoloConGiacenza($pezzi, 'TST-ELE-'.substr((string) microtime(true), -6).random_int(10, 99));
}

/** La riga dell'elenco come la legge il core: le colonne del documento più la `select()`. */
function rigaElenco(int $id): array
{
    $t = '`'.StockDocument::$table.'`';
    $r = StockDocument::connection()->query('SELECT '.$t.'.*, '.StockReceiptResource::select().' FROM '.$t.' WHERE '.$t.'.`id` = '.$id);

    return $r ? (array) $r->fetch_assoc() : [];
}

function nomeUtente(int $id): string
{
    $u = User::findById($id);
    $nome = trim(trim((string) ($u['name'] ?? '')).' '.trim((string) ($u['surname'] ?? '')));

    return $nome !== '' ? $nome : (string) ($u['username'] ?? '');
}

$utente = 1;
$altro = (int) (User::find("`id` <> 1 AND `deleted` = 'false'", 1)['id'] ?? 0);

check('elenco: righe, pezzi, data della bozza e chi l\'ha creata', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        [$a, $b] = [articolo(), articolo()];
        $id = StockDocuments::create('receipt', [], [
            ['product_id' => $a, 'quantity' => '2,5'],
            ['product_id' => $b, 'quantity' => '3'],
        ], ['user_id' => $utente]);
        $r = rigaElenco($id);

        return (int) $r['row_count'] === 2
            && (float) $r['piece_count'] === 5.5
            && str_starts_with((string) $r['sort_date'], date('Y-m-d').' ')
            && $r['who_name'] === nomeUtente($utente);
    });
});

check('elenco: una bozza senza righe ha 0 righe e 0 pezzi', function () {
    return prova(static function (): bool {
        $r = rigaElenco(StockDocuments::create('receipt', [], []));

        return (int) $r['row_count'] === 0 && (float) $r['piece_count'] === 0.0 && $r['who_name'] === null;
    });
});

check('elenco: un confermato ha chi l\'ha confermato, non chi l\'ha creato', function () use ($utente, $altro) {
    if ($altro === 0) {
        return true; // Un solo utente nel DB di prova: il caso non si costruisce.
    }

    return prova(static function () use ($utente, $altro): bool {
        $id = StockDocuments::create('receipt', [], [['product_id' => articolo(), 'quantity' => '1']], ['user_id' => $utente]);
        StockDocuments::confirm($id, ['user_id' => $altro]);

        return rigaElenco($id)['who_name'] === nomeUtente($altro);
    });
});

check('elenco: l\'ordine va per data e poi per documento, i più recenti in cima', function () {
    return prova(static function (): bool {
        $primo = StockDocuments::create('receipt', [], []);
        $secondo = StockDocuments::create('receipt', [], []);

        return strcmp((string) rigaElenco($secondo)['sort_date'], (string) rigaElenco($primo)['sort_date']) > 0;
    });
});

check('periodo: «Oggi» prende la bozza di oggi, «Mese scorso» no; vuoto non filtra', function () {
    return prova(static function (): bool {
        $id = StockDocuments::create('receipt', [], []);
        $adesso = new DateTimeImmutable('now');
        $conta = static function (string $chiave) use ($id, $adesso): int {
            $condizione = StockReceiptResource::periodCondition([$chiave], $adesso);
            $r = StockDocument::connection()->query('SELECT COUNT(*) AS n FROM `'.StockDocument::$table.'` WHERE `id` = '.$id.($condizione !== '' ? ' AND '.$condizione : ''));

            return (int) ($r ? $r->fetch_assoc()['n'] : -1);
        };

        return $conta('oggi') === 1
            && $conta('mese-scorso') === 0
            && $conta('') === 1
            && StockReceiptResource::periodCondition(["x' OR '1'='1"], $adesso) === '';
    });
});

check('elimina: una bozza sì, con le righe; un confermato è un rifiuto', function () {
    return prova(static function (): bool {
        $bozza = StockDocuments::create('receipt', [], [['product_id' => articolo(), 'quantity' => '1']]);
        $esito = StockReceiptResource::deleteRecord($bozza);
        $confermato = StockDocuments::create('receipt', [], [['product_id' => articolo(), 'quantity' => '1']]);
        StockDocuments::confirm($confermato);

        try {
            StockReceiptResource::deleteRecord($confermato);
            $rifiutato = false;
        } catch (RuntimeException) {
            $rifiutato = true;
        }

        return !empty($esito->success)
            && StockDocument::findById($bozza) === []
            && StockDocuments::items($bozza) === []
            && $rifiutato
            && StockDocument::findById($confermato)['status'] === 'completed';
    });
});

check('tipi: ogni Resource vede il suo, la pagina giusta per lo stato', function () {
    $carico = ['id' => 7, 'type' => 'receipt', 'status' => 'draft'];
    $scarico = ['id' => 8, 'type' => 'issue', 'status' => 'completed'];

    return StockReceiptResource::type() === 'receipt'
        && StockIssueResource::type() === 'issue'
        && StockDocumentResource::classFor('receipt') === StockReceiptResource::class
        && StockDocumentResource::classFor('issue') === StockIssueResource::class
        && StockDocumentResource::classFor('stocktake') === null
        && StockDocumentResource::pageUrl($carico) === StockReceiptResource::editUrl(7)
        && StockDocumentResource::pageUrl($scarico) === StockIssueResource::viewUrl(8)
        && str_contains(StockReceiptResource::editUrl(7), '/7/edit')
        && str_contains(StockIssueResource::viewUrl(8), '/8')
        && StockReceiptResource::newLabel() === 'Nuovo carico'
        && StockIssueResource::confirmLabel() === 'Conferma scarico'
        && str_contains(json_encode(StockReceiptResource::querySchema()), "receipt")
        && str_contains(json_encode(StockIssueResource::querySchema()), "issue");
});

summary();
```

`findById()` di un record che non c'è torna `[]`: se nel sito di prova torna `null`, cambiare i due confronti in `in_array(…, [[], null], true)` e scriverlo come ruling.

- [ ] **Passo 2: eseguirlo e vederlo fallire**

```bash
cd $W/gestionale && ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentListTest.php
```

Expected: errore fatale «Class "Wonder\Plugin\Gestionale\Resources\Stock\StockReceiptResource" not found».

- [ ] **Passo 3: la base astratta**

Create `src/Resources/Stock/StockDocumentResource.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

use DateTimeImmutable;
use Throwable;
use Wonder\App\Models\User\User;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentStatusLog;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\MovementPeriod;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocuments;
use Wonder\Sql\Query;

/**
 * Carichi, Scarichi e (piano 6) Inventari: tre Resource sullo stesso Model
 * `StockDocument`, come Clienti e Fornitori su `Contact` (G2b-bis 4.2). Ognuna
 * vede solo il suo tipo; questa base tiene tutto il resto.
 *
 * Le pagine non scrivono mai il documento: lo fa `StockDocuments`. Righe,
 * pezzi, chi e data dell'elenco nascono nella `select()`, mai una query per
 * riga.
 */
abstract class StockDocumentResource extends GestionaleResource
{
    public static string $model = StockDocument::class;
    public static string $orderColumn = 'sort_date';
    public static string $orderDirection = 'DESC';
    public static string $docsPage = 'magazzino/magazzino-carichi-scarichi';

    /** Il tipo del documento: `receipt`, `issue` (`stocktake` nel piano 6). */
    abstract public static function type(): string;

    /** «Carico», «Scarico»: il titolo della scheda e delle domande. */
    abstract public static function singular(): string;

    /** «Nuovo carico». */
    abstract public static function newLabel(): string;

    /** «Conferma carico». */
    abstract public static function confirmLabel(): string;

    public static function labelSchema(): array
    {
        return [
            'number' => 'Numero',
            'sort_date' => 'Data',
            'location_id' => 'Sede',
            'reason' => 'Causale',
            'row_count' => 'Righe',
            'piece_count' => 'Pezzi',
            'note' => 'Nota',
            'who_name' => 'Chi',
        ];
    }

    public static function tableSchema(): array
    {
        $columns = [
            TableColumn::key('number')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::numberCell($row)),
            TableColumn::key('sort_date')
                ->text()
                ->size('little')
                ->sortable()
                ->formatter(static fn (array $row): string => static::dateCell($row)),
        ];

        if (static::showsLocation()) {
            $columns[] = TableColumn::key('location_id')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(static::locationOptions()[(string) ($row['location_id'] ?? '')] ?? ''));
        }

        if (static::type() === 'issue') {
            $columns[] = TableColumn::key('reason')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(static::reasonLabel((string) ($row['reason'] ?? ''))));
        }

        foreach (['row_count', 'piece_count'] as $key) {
            $columns[] = TableColumn::key($key)
                ->text()
                ->size('little')
                ->sortable()
                ->formatter(static fn (array $row): string => static::number($row[$key] ?? 0));
        }

        $columns[] = TableColumn::key('note')
            ->text()
            ->formatter(static fn (array $row): string => static::escapeStored((string) ($row['note'] ?? '')));
        $columns[] = TableColumn::key('who_name')
            ->text()
            ->size('little')
            ->formatter(static fn (array $row): string => static::escape((string) ($row['who_name'] ?? '')));
        $columns[] = TableColumn::key('actions')->button()->actions(static::rowActions());

        return $columns;
    }

    /** Solo il tipo di questa Resource. */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        $base = Query::Conditions($schema['condition'] ?? [], false);
        $type = '`'.StockDocument::$table."`.`type` = '".static::type()."'";

        $schema['condition'] = ($base !== '' ? $base.' AND ' : '').$type;

        return $schema;
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        $schema = TableLayoutSchema::for(static::class)
            ->title(static::titleLabel())
            ->results()
            ->buttonAdd(true, static::newLabel())
            ->select(static::select())
            ->filterSearch()
            ->searchFields(['number', 'note'])
            ->filterCustom('Stato', 'status', ['' => 'Tutti', 'draft' => 'Bozze', 'completed' => 'Confermati', 'cancelled' => 'Annullati']);

        if (static::showsLocation()) {
            $schema->filterQuery('Sede', 'sede', ['' => 'Tutte'] + static::locationOptions(), static fn (array $values): string => StockMovementResource::locationCondition($values));
        }

        $schema->filterQuery(
            'Periodo',
            'periodo',
            MovementPeriod::filterOptions(),
            static fn (array $values): string => static::periodCondition($values, new DateTimeImmutable('now'))
        );

        if (static::type() === 'issue') {
            $schema->filterCustom('Causale', 'reason', ['' => 'Tutte'] + Reasons::forDocument('issue'));
        }

        return $schema;
    }

    /**
     * Le colonne calcolate dell'elenco.
     *
     * - `sort_date`: il giorno del documento (della conferma; della creazione
     *   per una bozza) e l'id, così a parità di giorno il più recente sta in
     *   cima;
     * - `row_count`, `piece_count`: righe e pezzi;
     * - `who_name`: chi ha confermato, dal log degli stati; per una bozza chi
     *   l'ha creata.
     */
    public static function select(): string
    {
        $doc = '`'.StockDocument::$table.'`';
        $items = '`'.StockDocumentItem::$table.'`';
        $logs = '`'.StockDocumentStatusLog::$table.'`';
        $users = '`'.User::$table.'`';
        $whoId = 'IF('.$doc.".`status` = 'draft', ".$doc.'.`user_id`, (SELECT l.`user_id` FROM '.$logs.' l'
            .' WHERE l.`stock_document_id` = '.$doc.".`id` AND l.`to_value` = 'completed' AND l.`deleted` = 'false'"
            .' ORDER BY l.`id` DESC LIMIT 1))';

        return implode(', ', [
            'CONCAT(COALESCE('.$doc.'.`date`, DATE('.$doc.".`creation`)), ' ', LPAD(".$doc.".`id`, 10, '0')) AS sort_date",
            '(SELECT COUNT(*) FROM '.$items.' i WHERE i.`stock_document_id` = '.$doc.".`id` AND i.`deleted` = 'false') AS row_count",
            '(SELECT COALESCE(SUM(i.`quantity`), 0) FROM '.$items.' i WHERE i.`stock_document_id` = '.$doc.".`id` AND i.`deleted` = 'false') AS piece_count",
            "(SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.`name`, u.`surname`)), ''), u.`username`) FROM ".$users.' u WHERE u.`id` = '.$whoId.' LIMIT 1) AS who_name',
        ]);
    }

    /**
     * Il filtro *Periodo* sulla *Data* dell'elenco. La chiave scelta non entra
     * mai nell'SQL: solo le date di `MovementPeriod::range()`.
     *
     * @param list<string> $values
     */
    public static function periodCondition(array $values, DateTimeImmutable $now): string
    {
        $range = MovementPeriod::range((string) ($values[0] ?? ''), $now);

        if ($range === null) {
            return '';
        }

        $doc = '`'.StockDocument::$table.'`';

        return 'COALESCE('.$doc.'.`date`, DATE('.$doc.'.`creation`)) BETWEEN \''.substr($range['from'], 0, 10).'\' AND \''.substr($range['to'], 0, 10).'\'';
    }

    /** La colonna e il filtro *Sede* servono da due sedi in su. */
    public static function showsLocation(): bool
    {
        return count(Locations::shown()) >= 2;
    }

    /** @return array<string, string> id della sede → nome */
    public static function locationOptions(): array
    {
        $options = [];

        foreach (Locations::shown() as $location) {
            $options[(string) $location['id']] = $location['label'];
        }

        return $options;
    }

    /**
     * La Resource che ha le pagine di questo tipo, o null.
     *
     * @return class-string<StockDocumentResource>|null
     */
    public static function classFor(string $type): ?string
    {
        return [
            'receipt' => StockReceiptResource::class,
            'issue' => StockIssueResource::class,
        ][$type] ?? null;
    }

    /**
     * La pagina giusta per il documento: la bozza si apre in modifica, gli
     * altri stati in sola lettura.
     *
     * @param array<string, mixed> $document una riga di `gst_stock_documents`
     */
    public static function pageUrl(array $document): string
    {
        $class = static::classFor((string) ($document['type'] ?? ''));

        if ($class === null) {
            return StockMovementResource::listUrl();
        }

        $id = (int) ($document['id'] ?? 0);

        return (string) ($document['status'] ?? '') === 'draft' ? $class::editUrl($id) : $class::viewUrl($id);
    }

    public static function viewUrl(int $id): string
    {
        return static::routeUrl('view', '/backend/'.static::path().'/'.$id.'/', ['id' => $id]);
    }

    public static function editUrl(int $id): string
    {
        return static::routeUrl('edit', '/backend/'.static::path().'/'.$id.'/edit/', ['id' => $id]);
    }

    public static function createUrl(): string
    {
        return static::routeUrl('create', '/backend/'.static::path().'/create/');
    }

    public static function listUrl(): string
    {
        return static::routeUrl('list', '/backend/'.static::path().'/');
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only(['list', 'delete'])
            ->titles(['list' => static::titleLabel()]);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('magazzino', 'Magazzino', 'bi-boxes', 400, ['admin', 'administrator'])
            ->title(static::titleLabel())
            ->order(static::navigationOrder())
            ->authority(['admin', 'administrator']);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)
            ->backend(['list', 'create', 'store', 'view', 'edit', 'update', 'delete'], ['admin', 'administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /**
     * Si eliminano solo le bozze: vale per la pagina e per *Elimina* del menu
     * ⋯ (`api/backend/delete`), che trasforma il rifiuto nel messaggio.
     */
    public static function assertDeletable(int|string $id): void
    {
        StockDocuments::assertDeletable((int) $id);
    }

    /** Il servizio toglie anche le righe: il `delete()` del Model lascerebbe le righe orfane. */
    public static function deleteRecord(int|string $id): object
    {
        static::assertDeletable($id);
        StockDocuments::delete((int) $id);

        return (object) ['success' => true, 'table' => StockDocument::$table, 'id' => $id];
    }

    /** Il posto nel menu *Magazzino*: Giacenze 10, Carichi 20, Scarichi 30, Inventari 40, Movimenti 50. */
    abstract protected static function navigationOrder(): int;

    /**
     * Il menu ⋯: *Modifica* ed *Elimina* sulle bozze, *Visualizza* sugli
     * altri, *Duplica* su tutti. *Duplica* apre la pagina del documento con
     * `?duplica=1`, che invia il form nascosto della pagina (Task 5 e 6).
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function rowActions(): array
    {
        $draft = ['row' => ['status' => 'draft']];
        $closed = ['row' => ['status' => ['completed', 'cancelled']]];

        return [
            'modify' => ['filter' => $draft],
            'view' => ['filter' => $closed],
            'duplica_bozza' => ['label' => 'Duplica', 'href' => static::rowUrl('edit', '/edit/').'?duplica=1', 'filter' => $draft],
            'duplica' => ['label' => 'Duplica', 'href' => static::rowUrl('view', '/').'?duplica=1', 'filter' => $closed],
            'delete' => ['filter' => $draft],
        ];
    }

    /** Numero, o il badge *Bozza*; *Annullato* accanto al numero. */
    protected static function numberCell(array $row): string
    {
        $number = static::escape((string) ($row['number'] ?? ''));

        return match ((string) ($row['status'] ?? '')) {
            'draft' => '<span class="badge text-bg-secondary">Bozza</span>',
            'cancelled' => $number.' <span class="badge text-bg-danger">Annullato</span>',
            default => $number,
        };
    }

    /** Il giorno della conferma; per una bozza, in grigio, quello della creazione. */
    protected static function dateCell(array $row): string
    {
        $draft = (string) ($row['status'] ?? '') === 'draft';
        $raw = $draft ? substr((string) ($row['creation'] ?? ''), 0, 10) : (string) ($row['date'] ?? '');
        $time = $raw !== '' ? strtotime($raw) : false;
        $text = $time === false ? '' : date('d/m/Y', $time);

        return $draft ? '<span class="text-muted">'.static::escape($text).'</span>' : static::escape($text);
    }

    protected static function reasonLabel(string $reason): string
    {
        return $reason === '' ? '' : Reasons::label($reason);
    }

    /** Un numero di pezzi in cella: a destra, cifre in colonna, interi senza decimali. */
    protected static function number(mixed $value): string
    {
        $number = round((float) $value, 3) + 0.0;
        $decimals = round($number, 3) === round($number, 0) ? 0 : 3;

        return '<span class="d-block text-end" style="font-variant-numeric: tabular-nums">'
            .static::escape(number_format($number, $decimals, ',', '.')).'</span>';
    }

    /** L'indirizzo di una pagina del documento con `{id}` al posto dell'id, per il menu ⋯. */
    private static function rowUrl(string $action, string $suffix): string
    {
        $url = static::routeUrl($action, '/backend/'.static::path().'/__ROW_ID__'.$suffix, ['id' => '__ROW_ID__']);

        return str_replace('__ROW_ID__', '{id}', $url);
    }

    /** @param array<string, mixed> $params */
    protected static function routeUrl(string $action, string $fallback, array $params = []): string
    {
        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.static::slug().'.'.$action, $params);
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
    }
}
```

- [ ] **Passo 4: Carichi e Scarichi**

Create `src/Resources/Stock/StockReceiptResource.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

/** *Carichi*: la merce che entra, dal fornitore o dal primo carico (G2b-bis 4.2). */
final class StockReceiptResource extends StockDocumentResource
{
    public static function type(): string
    {
        return 'receipt';
    }

    public static function path(): string
    {
        return 'app/gestionale/carichi';
    }

    public static function icon(): string
    {
        return 'bi-box-arrow-in-down';
    }

    public static function titleLabel(): string
    {
        return 'Carichi';
    }

    public static function singular(): string
    {
        return 'Carico';
    }

    public static function newLabel(): string
    {
        return 'Nuovo carico';
    }

    public static function confirmLabel(): string
    {
        return 'Conferma carico';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'carico',
            'plural_label' => 'carichi',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'il',
            'this' => 'questo',
        ];
    }

    protected static function navigationOrder(): int
    {
        return 20;
    }
}
```

Create `src/Resources/Stock/StockIssueResource.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

/** *Scarichi*: la merce che esce senza un ordine — rotta, scaduta, regalata, usata (G2b-bis 4.2). */
final class StockIssueResource extends StockDocumentResource
{
    public static function type(): string
    {
        return 'issue';
    }

    public static function path(): string
    {
        return 'app/gestionale/scarichi';
    }

    public static function icon(): string
    {
        return 'bi-box-arrow-up';
    }

    public static function titleLabel(): string
    {
        return 'Scarichi';
    }

    public static function singular(): string
    {
        return 'Scarico';
    }

    public static function newLabel(): string
    {
        return 'Nuovo scarico';
    }

    public static function confirmLabel(): string
    {
        return 'Conferma scarico';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'scarico',
            'plural_label' => 'scarichi',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'lo',
            'this' => 'questo',
        ];
    }

    protected static function navigationOrder(): int
    {
        return 30;
    }
}
```

- [ ] **Passo 5: le convenzioni**

In `tests/ConventionsTest.php`, nell'elenco `$sempreAttive`, dopo la riga di `StockMovementResource`:

```php
        'Wonder\\Plugin\\Gestionale\\Resources\\Stock\\StockDocumentResource',
        'Wonder\\Plugin\\Gestionale\\Resources\\Stock\\StockReceiptResource',
        'Wonder\\Plugin\\Gestionale\\Resources\\Stock\\StockIssueResource',
```

- [ ] **Passo 6: eseguire i test e vederli passare**

```bash
cd $W/gestionale && php tests/ConventionsTest.php
ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentListTest.php
```

Expected: tutti i check passano. Se ConventionsTest rifiuta la classe astratta per un altro controllo (base, prefisso, id), leggere il messaggio: l'astratta va trattata come le altre Resource del modulo, non tolta dal controllo.

- [ ] **Passo 7: l'elenco nel browser**

```bash
cd $S && php forge update --local
```

Aprire `https://ecommerce-carichi.test/backend/app/gestionale/carichi/` (il login lo fa l'utente). Expected: il menu *Magazzino* mostra Giacenze · Carichi · Scarichi · Movimenti; l'elenco Carichi è vuoto, con i filtri *Stato* e *Periodo* (e *Sede* con più sedi); Scarichi ha anche *Causale*. Il bottone «Nuovo carico» non porta ancora a una pagina: arriva nel Task 5.

- [ ] **Passo 8: commit**

```bash
cd $W/gestionale && git add src/Resources/Stock/StockDocumentResource.php src/Resources/Stock/StockReceiptResource.php src/Resources/Stock/StockIssueResource.php tests/ConventionsTest.php tests/integrazione/StockDocumentListTest.php
git commit -m "$(cat <<'EOF'
Carichi e Scarichi: Resource, elenchi con righe, pezzi, chi e data, menu ⋯ ed eliminazione via servizio

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: L'invio della bozza: *Salva bozza* e *Conferma*

Il form della bozza posta alle rotte `store` (documento nuovo) e `update` (bozza esistente) del core. `mutateRequestValues()` non restituisce mai i valori al core: controlla il CSRF, passa il POST a `StockDocumentForm::submit()` — che chiama `StockDocuments` — e chiude con un messaggio e un redirect. Il template arriva nel Task 5: qui c'è tutto quello che si prova senza browser.

Le regole dell'invio:
- `lines` è un solo campo JSON, una lista di `{product_id, quantity}`. Se manca, è vuoto o non si legge, la risposta è `invalid_lines` e non si scrive niente: un campo perso non cancella mai le righe di una bozza.
- Un documento nuovo nasce bozza anche quando la conferma si ferma, per esempio con uno scarico sotto zero. La pagina riapre quella bozza con l'errore, e un secondo invio la aggiorna invece di crearne un'altra.
- Su un documento già confermato o annullato, *Salva bozza* e *Conferma* non scrivono niente: la risposta è `not_draft` e si va alla scheda in sola lettura.
- Un id di un altro tipo (un carico inviato agli scarichi) è `not_found`.

**Files:**
- Create: `src/Support/Stock/StockDocumentForm.php`
- Modify: `src/Resources/Stock/StockDocumentResource.php` (import, metodi nuovi dopo `deleteRecord()`)
- Test: `tests/integrazione/StockDocumentFormTest.php`

**Interfaces:**
- Consumes: `StockDocuments::create/saveDraft/confirm/items` (piano 4); `StockVersionSearch::describe(array $ids, int $locationId): array<int, Version>` (Task 2); chiavi `stock_document.invalid_lines`, `stock_document.expired` (Task 1); `StockDocumentResource::pageUrl/editUrl/viewUrl/createUrl/listUrl/singular/type` (Task 3).
- Produces:
  - `StockDocumentForm::submit(string $type, int $id, array $post, int $userId): array{id: int, number: string, error: string}`
  - `StockDocumentForm::decode(mixed $raw): list<array<string, mixed>>` — lancia `stock_document.invalid_lines`
  - `StockDocumentForm::editorLines(list<array{product_id: int, quantity: ?float}> $items, int $locationId): array{lines: list<Version + {quantity: string}>, dropped: int}`
  - `StockDocumentForm::quantityText(?float $quantity): string` — `''`, `'3'`, `'2,5'`
  - `StockDocumentForm::respond(array{ok: bool, message: string, url: string} $result): never` — messaggio, `Location`, `exit` (lo usa anche il Task 6)
  - `StockDocumentResource::handleSubmit(array $post, int $id, int $userId): array{ok: bool, message: string, url: string}`
  - `StockDocumentResource::redirectFor(int $id): string`
  - `StockDocumentResource::closedRedirect(array $values, string $mode): ?string`
  - `StockDocumentResource::currentUserId(): int` (protetto; lo usa anche il Task 6 attraverso la sua copia in `StockDocumentActionResource`)
  - `$VALUES['editor']` nel form = il risultato di `editorLines()` (lo legge il template del Task 5)

- [ ] **Passo 1: il test d'integrazione che fallisce**

Create `tests/integrazione/StockDocumentFormTest.php`:

```php
<?php
/** php tests/integrazione/StockDocumentFormTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Stock\StockIssueResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockReceiptResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentForm;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocuments;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

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

function articolo(float $pezzi = 10): int
{
    return articoloConGiacenza($pezzi, 'TST-FRM-'.substr((string) microtime(true), -6).random_int(10, 99));
}

function conta(string $tabella, string $where): int
{
    $r = StockDocument::connection()->query('SELECT COUNT(*) AS n FROM `'.$tabella.'` WHERE '.$where);

    return (int) ($r ? $r->fetch_assoc()['n'] : -1);
}

function movimenti(int $id): int
{
    return conta(StockMovement::$table, "`reference_type` = 'stock_document' AND `reference_id` = ".$id." AND `deleted` = 'false'");
}

function messaggio(string $chiave): string
{
    return UserError::make($chiave)->getMessage();
}

/** @param list<array{0: int, 1: string}> $righe versione e pezzi */
function post(array $righe, array $altro = []): array
{
    return ['lines' => json_encode(array_map(static fn (array $r): array => ['product_id' => $r[0], 'quantity' => $r[1]], $righe))] + $altro;
}

$utente = 1;
$causale = (string) array_key_first(Reasons::forDocument('issue'));

check('nuovo, Salva bozza: nasce la bozza con le righe, la nota e chi l\'ha creata', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        [$a, $b] = [articolo(), articolo()];
        $esito = StockDocumentForm::submit('receipt', 0, post([[$a, '2,5'], [$b, '']], ['note' => 'Fattura 12', 'intent' => 'save']), $utente);
        $doc = StockDocument::findById($esito['id']);
        $righe = StockDocuments::items($esito['id']);

        return $esito['id'] > 0 && $esito['number'] === '' && $esito['error'] === ''
            && $doc['status'] === 'draft' && $doc['type'] === 'receipt'
            && $doc['note'] === 'Fattura 12' && (int) $doc['user_id'] === $utente
            && array_column($righe, 'product_id') === [$a, $b]
            && array_column($righe, 'quantity') === [2.5, null];
    });
});

check('nuovo, Conferma: un numero e un movimento per riga', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        [$a, $b] = [articolo(), articolo()];
        $esito = StockDocumentForm::submit('receipt', 0, post([[$a, '2'], [$b, '3']], ['intent' => 'confirm']), $utente);

        return $esito['error'] === '' && $esito['number'] !== ''
            && StockDocument::findById($esito['id'])['status'] === 'completed'
            && movimenti($esito['id']) === 2;
    });
});

check('nuovo scarico sotto zero: una bozza sola, con le righe e l\'errore; il secondo invio non ne crea un\'altra', function () use ($utente, $causale) {
    return prova(static function () use ($utente, $causale): bool {
        $a = articolo(1);
        $prima = conta(StockDocument::$table, "`type` = 'issue'");
        $primo = StockDocumentForm::submit('issue', 0, post([[$a, '5']], ['reason' => $causale, 'intent' => 'confirm']), $utente);
        $dopo = conta(StockDocument::$table, "`type` = 'issue'");
        $secondo = StockDocumentForm::submit('issue', $primo['id'], post([[$a, '5']], ['reason' => $causale, 'intent' => 'confirm']), $utente);

        return $primo['id'] > 0 && $primo['number'] === ''
            && str_contains($primo['error'], 'abbastanza giacenza')
            && $dopo === $prima + 1
            && StockDocument::findById($primo['id'])['status'] === 'draft'
            && count(StockDocuments::items($primo['id'])) === 1
            && movimenti($primo['id']) === 0
            && $secondo['id'] === $primo['id'] && $secondo['error'] !== ''
            && conta(StockDocument::$table, "`type` = 'issue'") === $dopo;
    });
});

check('seconda conferma (doppio clic, due schede): nessun movimento in più, il messaggio, la scheda in sola lettura', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        $a = articolo();
        $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '2']]);
        $uno = StockReceiptResource::handleSubmit(post([[$a, '2']], ['intent' => 'confirm']), $id, $utente);
        $due = StockReceiptResource::handleSubmit(post([[$a, '2']], ['intent' => 'confirm']), $id, $utente);
        $numero = StockDocument::findById($id)['number'];

        return $uno['ok'] && $uno['message'] === 'Carico '.$numero.' confermato.'
            && $uno['url'] === StockReceiptResource::viewUrl($id)
            && !$due['ok'] && $due['message'] === messaggio('stock_document.not_draft')
            && $due['url'] === StockReceiptResource::viewUrl($id)
            && movimenti($id) === 1;
    });
});

check('Salva bozza su un documento confermato non scrive niente', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        [$a, $b] = [articolo(), articolo()];
        $id = StockDocuments::create('receipt', ['note' => 'Prima'], [['product_id' => $a, 'quantity' => '2']]);
        StockDocuments::confirm($id);
        $esito = StockReceiptResource::handleSubmit(post([[$b, '9']], ['note' => 'Dopo', 'intent' => 'save']), $id, $utente);

        return !$esito['ok'] && $esito['message'] === messaggio('stock_document.not_draft')
            && StockDocument::findById($id)['note'] === 'Prima'
            && array_column(StockDocuments::items($id), 'product_id') === [$a];
    });
});

check('righe che mancano o non si leggono: invalid_lines, niente creato e niente cancellato', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        $a = articolo();
        $id = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '2']]);
        $prima = conta(StockDocument::$table, "`type` = 'receipt'");
        $atteso = messaggio('stock_document.invalid_lines');

        foreach ([[], ['lines' => ''], ['lines' => 'abc'], ['lines' => '{"product_id":1}'], ['lines' => ['x']], ['lines' => '[[[[[[[[[[1]]]]]]]]]]']] as $post) {
            $nuovo = StockDocumentForm::submit('receipt', 0, $post + ['intent' => 'confirm'], $utente);
            $vecchio = StockDocumentForm::submit('receipt', $id, $post + ['intent' => 'save'], $utente);

            if ($nuovo['error'] !== $atteso || $nuovo['id'] !== 0 || $vecchio['error'] !== $atteso) {
                return false;
            }
        }

        return conta(StockDocument::$table, "`type` = 'receipt'") === $prima
            && array_column(StockDocuments::items($id), 'product_id') === [$a];
    });
});

check('una lista vuota è una bozza senza righe, non un errore', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        $esito = StockDocumentForm::submit('receipt', 0, ['lines' => '[]', 'intent' => 'save'], $utente);

        return $esito['error'] === '' && $esito['id'] > 0 && StockDocuments::items($esito['id']) === [];
    });
});

check('un carico inviato agli scarichi è not_found e resta com\'era', function () use ($utente, $causale) {
    return prova(static function () use ($utente, $causale): bool {
        $a = articolo();
        $id = StockDocuments::create('receipt', ['note' => 'Carico'], [['product_id' => $a, 'quantity' => '2']]);
        $esito = StockIssueResource::handleSubmit(post([], ['note' => 'Altro', 'reason' => $causale, 'intent' => 'save']), $id, $utente);

        return !$esito['ok'] && $esito['message'] === messaggio('stock_document.not_found')
            && $esito['url'] === StockIssueResource::listUrl()
            && StockDocument::findById($id)['note'] === 'Carico'
            && count(StockDocuments::items($id)) === 1;
    });
});

check('handleSubmit: bozza salvata torna alla bozza, un nuovo fallito senza bozza torna a «Nuovo»', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        $a = articolo();
        $id = StockDocuments::create('receipt', [], []);
        $salva = StockReceiptResource::handleSubmit(post([[$a, '1']], ['intent' => 'save']), $id, $utente);
        $rotto = StockReceiptResource::handleSubmit(['lines' => 'x', 'intent' => 'save'], 0, $utente);

        return $salva['ok'] && $salva['message'] === 'Bozza salvata.'
            && $salva['url'] === StockReceiptResource::editUrl($id)
            && StockReceiptResource::redirectFor(999999999) === StockReceiptResource::listUrl()
            && !$rotto['ok'] && $rotto['url'] === StockReceiptResource::createUrl();
    });
});

check('editorLines: l\'ordine del documento, i pezzi in testo, la versione eliminata tolta e contata', function () {
    return prova(static function (): bool {
        [$a, $b, $c] = [articolo(4), articolo(), articolo()];
        $id = StockDocuments::create('receipt', [], [
            ['product_id' => $c, 'quantity' => '2,5'],
            ['product_id' => $b, 'quantity' => '1'],
            ['product_id' => $a, 'quantity' => ''],
        ]);
        Product::connection()->query('UPDATE `'.Product::$table."` SET `deleted` = 'true' WHERE `id` = ".$b);
        $editor = StockDocumentForm::editorLines(StockDocuments::items($id), Locations::mainId());

        return $editor['dropped'] === 1
            && array_column($editor['lines'], 'id') === [$c, $a]
            && array_column($editor['lines'], 'quantity') === ['2,5', '']
            && (float) $editor['lines'][1]['stock'] === 4.0
            && $editor['lines'][0]['label'] !== '';
    });
});

check('quantityText: vuoto, interi senza decimali, virgola', fn () =>
    StockDocumentForm::quantityText(null) === ''
    && StockDocumentForm::quantityText(3.0) === '3'
    && StockDocumentForm::quantityText(2.5) === '2,5'
    && StockDocumentForm::quantityText(0.125) === '0,125'
    && StockDocumentForm::quantityText(1234.0) === '1234'
);

check('closedRedirect: la bozza si modifica, un confermato o annullato va in sola lettura', fn () =>
    StockReceiptResource::closedRedirect(['id' => 5, 'status' => 'draft'], 'edit') === null
    && StockReceiptResource::closedRedirect([], 'create') === null
    && StockReceiptResource::closedRedirect(['id' => 5, 'status' => 'completed'], 'edit') === StockReceiptResource::viewUrl(5)
    && StockIssueResource::closedRedirect(['id' => 6, 'status' => 'cancelled'], 'edit') === StockIssueResource::viewUrl(6)
);

summary();
```

- [ ] **Passo 2: eseguirlo e vederlo fallire**

```bash
cd $W/gestionale && ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentFormTest.php
```

Expected: errore fatale `Class "Wonder\Plugin\Gestionale\Support\Stock\StockDocumentForm" not found`.

- [ ] **Passo 3: `StockDocumentForm`**

Create `src/Support/Stock/StockDocumentForm.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use JsonException;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * L'invio della bozza di carico e scarico (G2b-bis 4.2), senza HTML: legge il
 * POST, chiama `StockDocuments` e dice com'è andata. Le pagine non scrivono
 * mai il documento.
 *
 * Le righe arrivano in un solo campo JSON (`lines`), mai un campo per numero:
 * 500 righe da due campi supererebbero `max_input_vars`.
 */
final class StockDocumentForm
{
    /**
     * Salva la bozza e, con `intent = confirm`, la conferma.
     *
     * Un documento nuovo nasce bozza anche quando la conferma poi si ferma:
     * `id` torna sempre, così la pagina riapre quella bozza con l'errore e un
     * secondo invio la aggiorna invece di crearne un'altra.
     *
     * @param array<string, mixed> $post
     * @return array{id: int, number: string, error: string}
     */
    public static function submit(string $type, int $id, array $post, int $userId): array
    {
        $result = ['id' => $id, 'number' => '', 'error' => ''];

        try {
            if ($id > 0) {
                self::assertType($id, $type);
            }

            $lines = self::decode($post['lines'] ?? null);
            $header = self::header($post);

            if ($id > 0) {
                StockDocuments::saveDraft($id, $header, $lines);
            } else {
                $id = StockDocuments::create($type, $header, $lines, ['user_id' => $userId]);
                $result['id'] = $id;
            }

            if ((string) ($post['intent'] ?? '') === 'confirm') {
                $result['number'] = StockDocuments::confirm($id, ['user_id' => $userId, 'source' => 'user']);
            }
        } catch (UserError $error) {
            $result['error'] = $error->getMessage();
        }

        return $result;
    }

    /**
     * Il campo `lines`: una lista JSON. Tutto il resto è un rifiuto, anche il
     * campo che manca: una pagina rotta non deve cancellare le righe salvate.
     *
     * @return list<array<string, mixed>>
     */
    public static function decode(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            throw UserError::make('stock_document.invalid_lines');
        }

        try {
            $lines = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw UserError::make('stock_document.invalid_lines');
        }

        if (!is_array($lines) || !array_is_list($lines)) {
            throw UserError::make('stock_document.invalid_lines');
        }

        return $lines;
    }

    /**
     * Le righe salvate come le disegna l'editor: la versione descritta (nome,
     * SKU, foto, giacenza della sede) più i pezzi in testo. Una riga la cui
     * versione non c'è più si toglie e si conta: la pagina lo dice.
     *
     * @param list<array{product_id: int, quantity: ?float}> $items
     * @return array{lines: list<array<string, mixed>>, dropped: int}
     */
    public static function editorLines(array $items, int $locationId): array
    {
        $ids = array_map(static fn (array $item): int => (int) $item['product_id'], $items);
        $versions = $ids === [] ? [] : StockVersionSearch::describe($ids, $locationId);
        $lines = [];
        $dropped = 0;

        foreach ($items as $item) {
            $version = $versions[(int) $item['product_id']] ?? null;

            if ($version === null) {
                $dropped++;

                continue;
            }

            $lines[] = $version + ['quantity' => self::quantityText($item['quantity'])];
        }

        return ['lines' => $lines, 'dropped' => $dropped];
    }

    /** I pezzi come si scrivono nella casella: vuoto, `3`, `2,5`. */
    public static function quantityText(?float $quantity): string
    {
        if ($quantity === null) {
            return '';
        }

        $quantity = round($quantity, 3) + 0.0;

        if ($quantity === round($quantity)) {
            return (string) (int) $quantity;
        }

        return rtrim(rtrim(number_format($quantity, 3, ',', ''), '0'), ',');
    }

    /**
     * Il messaggio e il redirect dopo un invio. Un rifiuto è una frase, mai
     * una pagina d'errore.
     *
     * @param array{ok: bool, message: string, url: string} $result
     */
    public static function respond(array $result): never
    {
        if ($result['ok']) {
            FlashAlert::saved($result['message']);
        } else {
            FlashAlert::custom('Attenzione', $result['message'], 'warning');
        }

        if (!headers_sent()) {
            header('Location: '.$result['url']);
        }

        exit();
    }

    /**
     * Sede, causale e nota: solo i campi inviati, così una chiave che manca
     * tiene il valore di prima (`saveDraft()`). La causale del carico la
     * ignorano le regole.
     *
     * @param array<string, mixed> $post
     * @return array{location_id?: int, reason?: string, note?: string}
     */
    private static function header(array $post): array
    {
        $header = [];

        if (array_key_exists('location_id', $post)) {
            $header['location_id'] = (int) $post['location_id'];
        }

        foreach (['reason', 'note'] as $key) {
            if (array_key_exists($key, $post) && is_scalar($post[$key])) {
                $header[$key] = (string) $post[$key];
            }
        }

        return $header;
    }

    private static function assertType(int $id, string $type): void
    {
        $document = StockDocument::findById($id);

        if (!is_array($document) || $document === []
            || (string) ($document['deleted'] ?? 'false') === 'true'
            || (string) ($document['type'] ?? '') !== $type) {
            throw UserError::make('stock_document.not_found');
        }
    }
}
```

- [ ] **Passo 4: l'invio nella Resource**

In `src/Resources/Stock/StockDocumentResource.php`, negli import:

```php
use Wonder\App\LegacyGlobals;
use Wonder\Http\Csrf;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentForm;
```

Subito dopo `deleteRecord()`:

```php
    /**
     * Il POST di *Salva bozza* e *Conferma* (rotte `store` e `update` del
     * core). Non restituisce mai i valori: il core scriverebbe le colonne del
     * form senza righe né controlli del servizio. Qui si controlla il CSRF, si
     * passa a `StockDocumentForm` e si chiude con messaggio e redirect.
     */
    public static function mutateRequestValues(array $values, string $action, string $context = 'backend', ?array $oldValues = null): array
    {
        $id = (int) ($oldValues['id'] ?? 0);
        $result = Csrf::verify()
            ? static::handleSubmit($values, $id, static::currentUserId())
            : [
                'ok' => false,
                'message' => UserError::make('stock_document.expired')->getMessage(),
                'url' => $id > 0 ? static::editUrl($id) : static::createUrl(),
            ];

        StockDocumentForm::respond($result);
    }

    /**
     * L'invio senza HTTP, con la frase da mostrare e la pagina dove tornare.
     *
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string, url: string}
     */
    public static function handleSubmit(array $post, int $id, int $userId): array
    {
        $result = StockDocumentForm::submit(static::type(), $id, $post, $userId);

        if ($result['error'] !== '') {
            $message = $result['error'];
        } elseif ($result['number'] !== '') {
            $message = static::singular().' '.$result['number'].' confermato.';
        } else {
            $message = 'Bozza salvata.';
        }

        return ['ok' => $result['error'] === '', 'message' => $message, 'url' => static::redirectFor($result['id'])];
    }

    /**
     * Dove si torna dopo un invio: la pagina del documento per il suo stato
     * (la bozza, o la scheda di un confermato); l'elenco se non c'è più o è di
     * un altro tipo; «Nuovo» se il documento non è nato.
     */
    public static function redirectFor(int $id): string
    {
        if ($id <= 0) {
            return static::createUrl();
        }

        $document = StockDocument::findById($id);

        if (!is_array($document) || $document === []
            || (string) ($document['deleted'] ?? 'false') === 'true'
            || (string) ($document['type'] ?? '') !== static::type()) {
            return static::listUrl();
        }

        return static::pageUrl($document);
    }

    /** La bozza si modifica; un documento confermato o annullato si apre in sola lettura. */
    public static function closedRedirect(array $values, string $mode): ?string
    {
        if ($mode !== 'edit' || (string) ($values['status'] ?? 'draft') === 'draft') {
            return null;
        }

        return static::viewUrl((int) ($values['id'] ?? 0));
    }

    /**
     * Il form della bozza: un documento chiuso aperto in modifica (link
     * vecchio, altra scheda) va alla sua scheda; le righe arrivano descritte
     * per l'editor in `editor`.
     */
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        $closed = static::closedRedirect($values, $mode);

        if ($closed !== null) {
            if (!headers_sent()) {
                header('Location: '.$closed);
            }

            exit();
        }

        $locationId = (int) ($values['location_id'] ?? 0);
        $values['editor'] = StockDocumentForm::editorLines(
            $mode === 'edit' ? StockDocuments::items((int) ($values['id'] ?? 0)) : [],
            $locationId > 0 ? $locationId : Locations::mainId()
        );

        return $values;
    }

    protected static function currentUserId(): int
    {
        $user = LegacyGlobals::get('USER');

        return is_object($user) ? (int) ($user->id ?? 0) : 0;
    }
```

- [ ] **Passo 5: eseguire il test e vederlo passare**

```bash
cd $W/gestionale && ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentFormTest.php
ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentListTest.php
```

Expected: tutti i check passano nei due file. Se «editorLines» fallisce solo su `stock`, controllare che `articoloConGiacenza()` carichi nella sede principale (`Locations::mainId()`): se carica altrove, usare quella sede nel test e scriverlo come ruling.

- [ ] **Passo 6: commit**

```bash
cd $W/gestionale && git add src/Support/Stock/StockDocumentForm.php src/Resources/Stock/StockDocumentResource.php tests/integrazione/StockDocumentFormTest.php
git commit -m "$(cat <<'EOF'
Carichi e Scarichi: invio della bozza con Salva e Conferma via servizio, CSRF e redirect per stato

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 5: La pagina della bozza: «Cerca o scansiona», righe e conferma

Il template della bozza (`PageSchema::view('form', …)`) e l'editor delle righe. Lo scheletro lo disegna il PHP. Le righe le costruisce il JS da un JSON con `createElement`/`textContent`, così il nome di una versione non diventa mai HTML. Il lettore di codici a barre è una tastiera che scrive e preme Invio: Invio cerca il codice esatto, e aggiunge una riga solo se trova una versione sola.

Le regole della pagina (spec §4.2):
- **Ricerca.** Si cerca dopo 250 ms dall'ultimo tasto e da 2 caratteri in su. Una risposta vecchia non copre mai una nuova (contatore `seq`).
- **Invio nella casella:**
  - una versione → aggiunge 1 pezzo, o +1 se la riga c'è già, e il fuoco resta nella casella per il pezzo dopo;
  - più versioni → mostra la lista con «Più versioni con questo codice: scegline una» e non aggiunge niente;
  - nessuna → «Nessuna versione con questo codice», con il testo selezionato.
- **Clic su un risultato.** Aggiunge la riga con 1 pezzo, o va alla riga che c'è già; il fuoco passa ai pezzi, con il testo selezionato.
- **Invio nei pezzi** torna alla casella; Esc o un clic fuori chiude la lista.
- **Togliere una riga** chiede «Togliere la riga «…»?».
- **Cambiando la sede** si rilegge la giacenza delle righe (`ids=`).
- **Al telefono** si vedono solo Articolo e Pezzi.
- **Pezzi:**
  - si leggono come `Numbers::fromForm()`;
  - una casella che non si legge, sotto zero o oltre `MAX_QUANTITY` diventa rossa e blocca l'invio;
  - le caselle vuote non contano nel totale.
- **All'invio** i bottoni si spengono (Review Focus 1). *Conferma* chiede prima la domanda della spec, con i numeri della tabella.

**Files:**
- Create: `src/Support/Stock/StockDocumentEditor.php`
- Create: `view/pages/stock-document-form.php`
- Modify: `src/Resources/Stock/StockDocumentResource.php` (import, `pageSchema()`, metodi nuovi dopo `closedRedirect()`)
- Test: `tests/integrazione/StockDocumentEditorTest.php`

**Interfaces:**
- Consumes:
  - `StockDocumentForm::editorLines()` → `$VALUES['editor']` = `{lines: list<Version + {quantity: string}>, dropped: int}` (Task 4);
  - rotta `api.gestionale.backend.stock.versions` con `q`, `code`, `ids` e `location`, che risponde `{versions}` o `{result, versions}` (Task 2);
  - `StockDocumentResource::showsLocation()`, `locationOptions()`, `confirmLabel()`, `newLabel()`, `singular()`, `type()` (Task 3);
  - `Stocktake::quantity(mixed): ?float`; `StockDocumentRules::MAX_LINES`, `MAX_QUANTITY`; `Reasons::forDocument('issue')`.
- Produces:
  - `StockDocumentEditor::render(array $lines, string $searchUrl): string`
  - `StockDocumentEditor::totals(int $rows, float $pieces): string` — «12 righe · 34 pezzi»
  - `StockDocumentEditor::searchUrl(): string`
  - `StockDocumentEditor::script(): string` — definisce `window.wiStockEditor = {init, parse, format, totals}`; ogni `[data-wi-stock-editor]` riceve `.wiStockEditor = {validate(): bool, totals(): {rows, pieces}, serialize(): string}`
  - `StockDocumentResource::confirmQuestion(): string` — con `{pezzi}` e `{righe}` da riempire
  - `StockDocumentResource::formNotices(array $values, string $mode): array` — l'avviso delle righe perse
  - `view/pages/stock-document-form.php`: form `#wi-doc-form`, campi `intent` e `lines`, bottone `[data-wi-doc-confirm]`. Il Task 6 aggiunge qui i form nascosti di *Duplica*.

- [ ] **Passo 1: il test d'integrazione che fallisce**

Create `tests/integrazione/StockDocumentEditorTest.php`:

```php
<?php
/** php tests/integrazione/StockDocumentEditorTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\Stock\StockIssueResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockReceiptResource;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentEditor;
use Wonder\Plugin\Gestionale\Support\Stock\Stocktake;

$riga = ['id' => 7, 'label' => 'Maglia </script><b>rossa</b>', 'sku' => 'M-1', 'ean' => '', 'photo' => '', 'stock' => 3.0, 'inactive' => false, 'quantity' => '2,5'];
$html = StockDocumentEditor::render([$riga], '/cerca/?a=1&b=2');

check('totals: righe e pezzi, singolare e plurale, virgola e migliaia', fn () =>
    StockDocumentEditor::totals(12, 34) === '12 righe · 34 pezzi'
    && StockDocumentEditor::totals(1, 1) === '1 riga · 1 pezzo'
    && StockDocumentEditor::totals(2, 2.5) === '2 righe · 2,5 pezzi'
    && StockDocumentEditor::totals(0, 0) === '0 righe · 0 pezzi'
    && StockDocumentEditor::totals(3, 1234.125) === '3 righe · 1.234,125 pezzi'
);

check('render: le righe stanno nel JSON, mai come HTML', fn () =>
    !str_contains($html, '</script><b>')
    && !str_contains($html, '<b>rossa')
    && str_contains($html, '<\/script>')
);

check('render: dal JSON le righe tornano uguali', function () use ($html, $riga) {
    preg_match('#<script type="application/json" data-wi-stock-lines>(.*?)</script>#s', $html, $m);

    return isset($m[1]) && json_decode($m[1], true) === [$riga];
});

check('render: indirizzo escapato, limite di 500 righe, casella, vuoto e totale', fn () =>
    str_contains($html, 'data-search-url="/cerca/?a=1&amp;b=2"')
    && str_contains($html, 'data-max-lines="500"')
    && str_contains($html, 'data-max-message="Un documento tiene al massimo 500 righe"')
    && str_contains($html, 'placeholder="Cerca o scansiona"')
    && str_contains($html, 'Nessuna riga: cerca una versione o scansiona un codice.')
    && str_contains($html, '1 riga · 2,5 pezzi')
);

check('render: senza righe il messaggio del vuoto si vede', fn () =>
    str_contains(StockDocumentEditor::render([], '/x/'), 'class="text-body-secondary my-3" data-wi-stock-empty')
);

check('searchUrl: la rotta delle versioni', fn () =>
    str_contains(StockDocumentEditor::searchUrl(), '/gestionale/backend/magazzino/versioni/')
);

check('script: i messaggi del lettore e della ricerca', function () {
    $js = StockDocumentEditor::script();

    return str_contains($js, 'Più versioni con questo codice: scegline una')
        && str_contains($js, 'Nessuna versione con questo codice')
        && str_contains($js, 'Nessuna versione trovata')
        && str_contains($js, 'La ricerca non risponde: riprova.')
        && !str_contains($js, '</script');
});

check('script: il JS legge i pezzi come il server e scrive il totale come il PHP (node)', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    if ($node === '') {
        echo "  (salto: node non c'è in PATH)\n";

        return true;
    }

    $casi = ['2,5', '1.234,5', '3 pz', '€5', '5€', 'abc', '', '  ', '-1', '1e3', '0', '7.5', '1 000', '--3'];
    $base = (string) tempnam(sys_get_temp_dir(), 'wi-stock');
    $file = $base.'.js';
    file_put_contents($file, "var window = {}; var document = {querySelectorAll: function () { return []; }};\n"
        .StockDocumentEditor::script()."\n"
        .'console.log(JSON.stringify({parse: '.json_encode($casi).'.map(function (c) {'
        .' var v = window.wiStockEditor.parse(c); return v === null ? null : (isNaN(v) ? "x" : v); }),'
        .' totals: window.wiStockEditor.totals(3, 1234.125)}));');
    $js = json_decode((string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file)), true);
    @unlink($file);
    @unlink($base);

    $php = array_map(static function (string $caso): float|string|null {
        if (trim($caso) === '') {
            return null;
        }

        return Stocktake::quantity($caso) ?? 'x';
    }, $casi);

    return is_array($js)
        && array_map(static fn (mixed $v): mixed => is_int($v) || is_float($v) ? (float) $v : $v, $js['parse']) === $php
        && $js['totals'] === StockDocumentEditor::totals(3, 1234.125);
});

check('confirmQuestion: la domanda della spec, con {pezzi} e {righe} da riempire', fn () =>
    StockReceiptResource::confirmQuestion() === '{pezzi} pezzi in {righe} righe entrano in magazzino. Dopo non si modifica più: si può solo annullare.'
    && StockIssueResource::confirmQuestion() === '{pezzi} pezzi in {righe} righe escono dal magazzino. Dopo non si modifica più: si può solo annullare.'
);

check('formNotices: niente senza righe perse, una frase per una e per più', fn () =>
    StockReceiptResource::formNotices(['editor' => ['lines' => [], 'dropped' => 0]], 'edit') === []
    && StockReceiptResource::formNotices([], 'create') === []
    && StockReceiptResource::formNotices(['editor' => ['lines' => [], 'dropped' => 1]], 'edit') === [[
        'level' => 'warning',
        'message' => "Una riga non c'è più: la sua versione è stata eliminata. Salvando la bozza esce anche dal documento.",
    ]]
    && StockIssueResource::formNotices(['editor' => ['lines' => [], 'dropped' => 2]], 'edit') === [[
        'level' => 'warning',
        'message' => '2 righe non ci sono più: le loro versioni sono state eliminate. Salvando la bozza escono anche dal documento.',
    ]]
);

check('pageSchema: nuovo e bozza con il template della bozza', function () {
    $schema = StockReceiptResource::pageSchema();
    $pages = (array) $schema->get('pages');
    $template = Gestionale::viewPath('pages/stock-document-form.php');

    foreach (['list', 'create', 'store', 'edit', 'update', 'delete'] as $page) {
        if (empty($pages[$page])) {
            return false;
        }
    }

    return ((array) $schema->get('views'))['form'] === $template
        && is_file($template)
        && ((array) $schema->get('titles'))['create'] === 'Nuovo carico'
        && ((array) $schema->get('titles'))['edit'] === 'Bozza di carico'
        && ((array) StockIssueResource::pageSchema()->get('titles'))['edit'] === 'Bozza di scarico';
});

summary();
```

- [ ] **Passo 2: eseguirlo e vederlo fallire**

```bash
cd $W/gestionale && ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentEditorTest.php
```

Expected: errore fatale `Class "Wonder\Plugin\Gestionale\Support\Stock\StockDocumentEditor" not found`.

- [ ] **Passo 3: `StockDocumentEditor`**

Create `src/Support/Stock/StockDocumentEditor.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;

/**
 * La tabella delle righe della bozza con la casella «Cerca o scansiona»
 * (G2b-bis 4.2). Il PHP disegna lo scheletro; le righe le costruisce il JS da
 * un JSON con `textContent`: il nome di una versione non diventa mai HTML.
 *
 * Il lettore di codici a barre è una tastiera che scrive e preme Invio: Invio
 * cerca il codice esatto e aggiunge solo con una versione sola. Il server
 * resta l'arbitro dei pezzi; il JS li legge allo stesso modo per segnare in
 * rosso subito una casella sbagliata.
 */
final class StockDocumentEditor
{
    /**
     * @param list<array<string, mixed>> $lines Version + `quantity` in testo (`StockDocumentForm::editorLines()`)
     */
    public static function render(array $lines, string $searchUrl): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $lines = array_values($lines);
        $pieces = 0.0;

        foreach ($lines as $line) {
            $quantity = Stocktake::quantity($line['quantity'] ?? null);
            $pieces += $quantity !== null && $quantity > 0 ? $quantity : 0.0;
        }

        $json = (string) json_encode($lines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);

        return '<div class="col-12" data-wi-stock-editor'
            .' data-search-url="'.$e($searchUrl).'"'
            .' data-max-lines="'.StockDocumentRules::MAX_LINES.'"'
            .' data-max-quantity="'.StockDocumentRules::MAX_QUANTITY.'"'
            .' data-max-message="'.$e('Un documento tiene al massimo '.StockDocumentRules::MAX_LINES.' righe').'">'
            .'<div class="position-relative">'
            .'<div class="input-group">'
            .'<span class="input-group-text"><i class="bi bi-upc-scan" aria-hidden="true"></i></span>'
            .'<input type="search" class="form-control" data-wi-stock-search placeholder="Cerca o scansiona"'
            .' aria-label="Cerca una versione o scansiona un codice" autocomplete="off" enterkeyhint="search">'
            .'</div>'
            .'<div class="list-group position-absolute w-100 shadow d-none" style="z-index: 1050; max-height: 20rem; overflow-y: auto" data-wi-stock-results></div>'
            .'</div>'
            .'<div class="small mt-1" role="status" aria-live="polite" data-wi-stock-message></div>'
            .'<div class="table-responsive mt-3">'
            .'<table class="table table-sm align-middle mb-0">'
            .'<thead><tr>'
            .'<th>Articolo</th>'
            .'<th class="d-none d-md-table-cell text-end">Giacenza</th>'
            .'<th class="text-end" style="width: 8rem">Pezzi</th>'
            .'<th style="width: 3rem"><span class="visually-hidden">Togli</span></th>'
            .'</tr></thead>'
            .'<tbody data-wi-stock-rows></tbody>'
            .'</table>'
            .'</div>'
            .'<p class="text-body-secondary my-3'.($lines === [] ? '' : ' d-none').'" data-wi-stock-empty>Nessuna riga: cerca una versione o scansiona un codice.</p>'
            .'<div class="text-end fw-semibold mt-2" data-wi-stock-totals>'.$e(self::totals(count($lines), $pieces)).'</div>'
            .'<script type="application/json" data-wi-stock-lines>'.$json.'</script>'
            .'<script>'.self::script().'</script>'
            .'</div>';
    }

    /** «12 righe · 34 pezzi», «1 riga · 1 pezzo», «2 righe · 2,5 pezzi». */
    public static function totals(int $rows, float $pieces): string
    {
        $pieces = round($pieces, 3) + 0.0;
        $text = $pieces === round($pieces)
            ? number_format($pieces, 0, ',', '.')
            : rtrim(rtrim(number_format($pieces, 3, ',', '.'), '0'), ',');

        return $rows.' '.($rows === 1 ? 'riga' : 'righe').' · '.$text.' '.($pieces === 1.0 ? 'pezzo' : 'pezzi');
    }

    /** La rotta JSON delle versioni (Task 2). */
    public static function searchUrl(): string
    {
        $fallback = '/api/gestionale/backend/magazzino/versioni/';

        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $url = (string) __r('api.gestionale.backend.stock.versions');
        } catch (Throwable) {
            return $fallback;
        }

        return $url !== '' ? $url : $fallback;
    }

    /**
     * Il JS dell'editor, ES5 in una IIFE. `window.wiStockEditor` si definisce
     * una volta sola; `init()` lega ogni `[data-wi-stock-editor]` non ancora
     * legato.
     */
    public static function script(): string
    {
        return <<<'JS'
window.wiStockEditor = window.wiStockEditor || (function () {
    'use strict';

    var MANY = 'Più versioni con questo codice: scegline una';
    var NONE = 'Nessuna versione con questo codice';
    var EMPTY = 'Nessuna versione trovata';
    var DOWN = 'La ricerca non risponde: riprova.';
    var BAD = 'Controlla i pezzi della riga in rosso.';
    // Come Numbers::fromForm(): lettere e valute solo in coda («3 pz», «5€»).
    // ES5 non ha \p{L}: bastano latino, greco, cirillico e i simboli di valuta.
    var TRAILING = /[A-Za-zªµºÀ-ɏͰ-ϿЀ-ӿ$¢-¥₠-⃏]+$/;
    var NUMERIC = /^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/;

    function trim(text) {
        return String(text == null ? '' : text).replace(/^\s+|\s+$/g, '');
    }

    // null = casella vuota, NaN = non si legge; altrimenti i pezzi a tre decimali.
    function parse(raw) {
        var text = trim(raw);

        if (text === '') {
            return null;
        }

        text = text.replace(/[   ]/g, '').replace(TRAILING, '');

        if (text.indexOf(',') !== -1) {
            text = text.replace(/\./g, '');
        }

        text = text.replace(/,/g, '.');

        return NUMERIC.test(text) ? Math.round(parseFloat(text) * 1000) / 1000 : NaN;
    }

    // «2,5», «1.234»; grouped === false per le caselle, che rileggono il punto come decimale.
    function format(number, grouped) {
        var parts = (Math.round(number * 1000) / 1000).toFixed(3).split('.');
        var decimals = parts[1].replace(/0+$/, '');
        var whole = grouped === false ? parts[0] : parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');

        return decimals === '' ? whole : whole + ',' + decimals;
    }

    function totals(rows, pieces) {
        return rows + ' ' + (rows === 1 ? 'riga' : 'righe') + ' · ' + format(pieces) + ' ' + (pieces === 1 ? 'pezzo' : 'pezzi');
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text != null) {
            node.textContent = String(text);
        }

        return node;
    }

    function ask(options) {
        if (window.wi && typeof window.wi.confirm === 'function') {
            return window.wi.confirm(options);
        }

        return Promise.resolve(window.confirm(options.text));
    }

    function list(data) {
        var versions = data && data.versions ? data.versions : [];

        return Object.prototype.toString.call(versions) === '[object Array]' ? versions : [];
    }

    function codes(version) {
        var parts = [];

        if (version.sku) {
            parts.push(version.sku);
        }

        if (version.ean && version.ean !== version.sku) {
            parts.push(version.ean);
        }

        return parts.join(' · ');
    }

    function editor(root) {
        var search = root.querySelector('[data-wi-stock-search]');
        var results = root.querySelector('[data-wi-stock-results]');
        var message = root.querySelector('[data-wi-stock-message]');
        var body = root.querySelector('[data-wi-stock-rows]');
        var empty = root.querySelector('[data-wi-stock-empty]');
        var summary = root.querySelector('[data-wi-stock-totals]');
        var url = root.getAttribute('data-search-url');
        var maxLines = parseInt(root.getAttribute('data-max-lines'), 10) || 500;
        var maxQuantity = parseFloat(root.getAttribute('data-max-quantity')) || 9999999.999;
        var form = root.closest('form');
        var place = form ? form.querySelector('[data-wi-stock-location]') : null;
        var rows = [];
        var timer = null;
        var seq = 0;
        var initial = [];
        var k;

        function say(text, tone) {
            message.textContent = text || '';
            message.className = 'small mt-1 ' + (tone === 'error' ? 'text-danger' : 'text-body-secondary');
        }

        function request(params, done, fail) {
            var where = place ? '&location=' + encodeURIComponent(place.value) : '';

            window.fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + params + where, {
                credentials: 'same-origin',
                headers: {Accept: 'application/json'}
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.json();
            }).then(done, fail);
        }

        // Ricerca e lettore: conta solo l'ultima domanda.
        function lookup(params, done) {
            var mine = ++seq;

            request(params, function (data) {
                if (mine === seq) {
                    done(data);
                }
            }, function () {
                if (mine === seq) {
                    close();
                    say(DOWN, 'error');
                }
            });
        }

        function close() {
            results.classList.add('d-none');

            while (results.firstChild) {
                results.removeChild(results.firstChild);
            }
        }

        function option(version) {
            var item = el('button', 'list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2');
            var text = el('div', 'min-w-0');
            var side = el('div', 'small text-nowrap text-body-secondary', format(Number(version.stock) || 0));

            item.type = 'button';
            text.appendChild(el('div', 'text-truncate', version.label));

            if (codes(version)) {
                text.appendChild(el('div', 'small text-body-secondary', codes(version)));
            }

            if (version.inactive) {
                side.insertBefore(el('span', 'badge text-bg-warning me-1', 'ferma'), side.firstChild);
            }

            item.appendChild(text);
            item.appendChild(side);
            item.addEventListener('click', function () {
                picked(version);
            });

            return item;
        }

        function show(versions, text, tone) {
            var i;

            close();
            say(text, tone);

            for (i = 0; i < versions.length; i++) {
                results.appendChild(option(versions[i]));
            }

            if (versions.length) {
                results.classList.remove('d-none');
            }
        }

        function byId(id) {
            var i;

            for (i = 0; i < rows.length; i++) {
                if (rows[i].id === Number(id)) {
                    return rows[i];
                }
            }

            return null;
        }

        function check(row) {
            var value = parse(row.input.value);
            var bad = value !== null && !(value >= 0 && value <= maxQuantity);

            if (bad) {
                row.input.classList.add('is-invalid');
            } else {
                row.input.classList.remove('is-invalid');
            }

            return !bad;
        }

        function count() {
            var pieces = 0;
            var i;
            var value;

            for (i = 0; i < rows.length; i++) {
                value = parse(rows[i].input.value);

                if (value > 0) {
                    pieces += value;
                }
            }

            return {rows: rows.length, pieces: Math.round(pieces * 1000) / 1000};
        }

        function refresh() {
            var current = count();

            summary.textContent = totals(current.rows, current.pieces);

            if (rows.length) {
                empty.classList.add('d-none');
            } else {
                empty.classList.remove('d-none');
            }
        }

        function drop(row) {
            row.tr.parentNode.removeChild(row.tr);
            rows.splice(rows.indexOf(row), 1);
            refresh();
            search.focus();
        }

        function append(version, quantity) {
            var row;
            var tr;
            var article;
            var wrap;
            var text;
            var photo;
            var cell;
            var input;
            var tools;
            var remove;

            if (rows.length >= maxLines) {
                say(root.getAttribute('data-max-message'), 'error');

                return null;
            }

            row = {id: Number(version.id), label: String(version.label || '')};
            tr = el('tr');
            article = el('td');
            wrap = el('div', 'd-flex align-items-center gap-2');
            text = el('div', 'min-w-0');
            cell = el('td', 'text-end');
            input = el('input', 'form-control form-control-sm text-end');
            tools = el('td', 'text-end');
            remove = el('button', 'btn btn-sm btn-link text-danger p-1');

            if (version.photo) {
                photo = el('img', 'rounded flex-shrink-0');
                photo.src = version.photo;
                photo.alt = '';
                photo.width = 32;
                photo.height = 32;
                photo.style.objectFit = 'cover';
                wrap.appendChild(photo);
            }

            text.appendChild(el('div', 'text-truncate', row.label));

            if (codes(version)) {
                text.appendChild(el('div', 'small text-body-secondary', codes(version)));
            }

            if (version.inactive) {
                text.appendChild(el('span', 'badge text-bg-warning', 'ferma'));
            }

            wrap.appendChild(text);
            article.appendChild(wrap);

            input.type = 'text';
            input.inputMode = 'decimal';
            input.value = quantity == null ? '' : String(quantity);
            input.setAttribute('aria-label', 'Pezzi di ' + row.label);
            input.addEventListener('input', function () {
                check(row);
                refresh();
            });
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    search.focus();
                }
            });
            cell.appendChild(input);

            remove.type = 'button';
            remove.setAttribute('aria-label', 'Togli la riga');
            remove.appendChild(el('i', 'bi bi-trash'));
            remove.addEventListener('click', function () {
                ask({text: 'Togliere la riga «' + row.label + '»?', ok: 'Togli', cancel: 'Tieni', variant: 'danger'}).then(function (ok) {
                    if (ok) {
                        drop(row);
                    }
                });
            });
            tools.appendChild(remove);

            row.tr = tr;
            row.input = input;
            row.stock = el('td', 'd-none d-md-table-cell text-end text-nowrap', format(Number(version.stock) || 0));
            tr.appendChild(article);
            tr.appendChild(row.stock);
            tr.appendChild(cell);
            tr.appendChild(tools);
            body.appendChild(tr);
            rows.push(row);
            check(row);
            refresh();

            return row;
        }

        // Il lettore: +1 sulla riga che c'è, o una riga nuova; il fuoco resta nella casella.
        function scanned(version) {
            var row = byId(version.id);
            var current;

            if (row) {
                current = parse(row.input.value);

                if (!(current === null || current >= 0)) {
                    check(row);
                    say(BAD, 'error');

                    return;
                }

                row.input.value = format((current || 0) + 1, false);
                check(row);
                refresh();
            } else if (!append(version, '1')) {
                return;
            }

            search.value = '';
            say('');
            search.focus();
        }

        // Un clic sulla lista: la riga (nuova con 1 pezzo, o quella che c'è) e il fuoco sui pezzi.
        function picked(version) {
            var row = byId(version.id) || append(version, '1');

            close();

            if (row) {
                search.value = '';
                say('');
                row.input.focus();
                row.input.select();
            }
        }

        function validate() {
            var first = null;
            var i;

            for (i = 0; i < rows.length; i++) {
                if (!check(rows[i]) && first === null) {
                    first = rows[i];
                }
            }

            if (first !== null) {
                say(BAD, 'error');
                first.input.focus();

                return false;
            }

            return true;
        }

        function serialize() {
            var out = [];
            var i;

            for (i = 0; i < rows.length; i++) {
                out.push({product_id: rows[i].id, quantity: rows[i].input.value});
            }

            return JSON.stringify(out);
        }

        search.addEventListener('input', function () {
            var q = trim(search.value);

            clearTimeout(timer);

            if (q.length < 2) {
                seq++;
                close();

                return;
            }

            timer = setTimeout(function () {
                lookup('q=' + encodeURIComponent(q), function (data) {
                    var versions = list(data);

                    show(versions, versions.length ? '' : EMPTY);
                });
            }, 250);
        });

        // Invio non prende mai il primo risultato: cerca il codice esatto.
        search.addEventListener('keydown', function (event) {
            var code;

            if (event.key === 'Escape') {
                close();

                return;
            }

            if (event.key !== 'Enter') {
                return;
            }

            event.preventDefault();
            clearTimeout(timer);
            code = trim(search.value);

            if (code === '') {
                return;
            }

            lookup('code=' + encodeURIComponent(code), function (data) {
                var versions = list(data);

                if (data.result === 'one' && versions.length === 1) {
                    close();
                    scanned(versions[0]);
                } else if (data.result === 'many') {
                    show(versions, MANY);
                } else {
                    close();
                    say(NONE, 'error');
                    search.select();
                }
            });
        });

        document.addEventListener('click', function (event) {
            if (!root.contains(event.target)) {
                close();
            }
        });

        // Cambiando la sede, la giacenza delle righe si rilegge.
        if (place) {
            place.addEventListener('change', function () {
                var ids = [];
                var i;

                for (i = 0; i < rows.length; i++) {
                    ids.push(rows[i].id);
                }

                if (!ids.length) {
                    return;
                }

                request('ids=' + ids.join(','), function (data) {
                    var versions = list(data);
                    var j;
                    var row;

                    for (j = 0; j < versions.length; j++) {
                        row = byId(versions[j].id);

                        if (row) {
                            row.stock.textContent = format(Number(versions[j].stock) || 0);
                        }
                    }
                }, function () {
                    say(DOWN, 'error');
                });
            });
        }

        try {
            initial = JSON.parse(root.querySelector('[data-wi-stock-lines]').textContent) || [];
        } catch (error) {
            initial = [];
        }

        for (k = 0; k < initial.length; k++) {
            append(initial[k], initial[k].quantity);
        }

        refresh();

        return {validate: validate, totals: count, serialize: serialize};
    }

    function init() {
        var roots = document.querySelectorAll('[data-wi-stock-editor]');
        var i;

        for (i = 0; i < roots.length; i++) {
            if (!roots[i].wiStockEditor) {
                roots[i].wiStockEditor = editor(roots[i]);
            }
        }
    }

    return {init: init, parse: parse, format: format, totals: totals};
})();
window.wiStockEditor.init();
JS;
    }
}
```

- [ ] **Passo 4: il template della bozza**

Create `view/pages/stock-document-form.php`:

```php
<?php

use Wonder\Elements\Components\Tooltip;
use Wonder\Http\Csrf;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentEditor;

/*
 * La bozza di carico o scarico (G2b-bis 4.2): Sede · Causale · Nota, le righe
 * e due bottoni. Posta alle rotte `store`/`update` del core, che la Resource
 * intercetta in `mutateRequestValues()`: le righe viaggiano nel solo campo
 * `lines`, scritto dal JS all'invio.
 */

/** @var class-string<\Wonder\Plugin\Gestionale\Resources\Stock\StockDocumentResource> $resource */
$resource = $RESOURCE_CLASS;
$values = (array) ($VALUES ?? []);
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$json = static fn (mixed $value): string => (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
$locationId = (int) ($values['location_id'] ?? 0) ?: Locations::mainId();
$reason = (string) ($values['reason'] ?? '');
$note = html_entity_decode((string) ($values['note'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$editor = (array) ($values['editor'] ?? []);
$noteHelp = $resource::type() === 'issue'
    ? 'Per esempio a chi è andata la merce o perché.'
    : 'Per esempio il numero della bolla o della fattura del fornitore.';

\Wonder\View\View::layout('backend.form');
?>
<form id="wi-doc-form" method="POST" action="<?=$e($FORM_ACTION ?? '')?>" autocomplete="off">
    <?=Csrf::fieldFor('post')?>
    <input type="hidden" name="intent" value="save">
    <input type="hidden" name="lines" value="">
    <div class="row g-3">
        <wi-card class="col-12">
            <?php if ($resource::showsLocation()) { ?>
            <div class="col-12 col-md-3">
                <label class="form-label" for="wi-doc-location">Sede</label>
                <select class="form-select" id="wi-doc-location" name="location_id" data-wi-stock-location>
                    <?php foreach ($resource::locationOptions() as $id => $label) { ?>
                    <option value="<?=$e($id)?>"<?=(int) $id === $locationId ? ' selected' : ''?>><?=$e($label)?></option>
                    <?php } ?>
                </select>
            </div>
            <?php } ?>
            <?php if ($resource::type() === 'issue') { ?>
            <div class="col-12 col-md-3">
                <label class="form-label" for="wi-doc-reason">Causale</label>
                <select class="form-select" id="wi-doc-reason" name="reason">
                    <option value="">Scegli…</option>
                    <?php foreach (Reasons::forDocument('issue') as $key => $label) { ?>
                    <option value="<?=$e($key)?>"<?=(string) $key === $reason ? ' selected' : ''?>><?=$e($label)?></option>
                    <?php } ?>
                </select>
            </div>
            <?php } ?>
            <div class="col-12 col-md">
                <label class="form-label" for="wi-doc-note">Nota <?=Tooltip::make($noteHelp)->render()?></label>
                <input type="text" class="form-control" id="wi-doc-note" name="note" maxlength="500" value="<?=$e($note)?>">
            </div>
        </wi-card>
        <wi-card class="col-12">
            <?=StockDocumentEditor::render((array) ($editor['lines'] ?? []), StockDocumentEditor::searchUrl())?>
        </wi-card>
        <wi-card class="col-12">
            <div class="col-12 d-flex flex-wrap justify-content-end gap-2">
                <button type="submit" class="btn btn-outline-dark">Salva bozza</button>
                <button type="button" class="btn btn-dark" data-wi-doc-confirm><?=$e($resource::confirmLabel())?></button>
            </div>
        </wi-card>
    </div>
</form>
<script>
(function () {
    'use strict';

    var form = document.getElementById('wi-doc-form');
    var intent = form.querySelector('input[name="intent"]');
    var lines = form.querySelector('input[name="lines"]');
    var buttons = form.querySelectorAll('button[type="submit"], [data-wi-doc-confirm]');
    var confirmButton = form.querySelector('[data-wi-doc-confirm]');
    var question = <?=$json($resource::confirmQuestion())?>;
    var label = <?=$json($resource::confirmLabel())?>;
    var sending = false;

    function editor() {
        var root = form.querySelector('[data-wi-stock-editor]');

        return root ? root.wiStockEditor : null;
    }

    function lock(on) {
        var i;

        sending = on;

        for (i = 0; i < buttons.length; i++) {
            buttons[i].disabled = on;
        }
    }

    function ask(options) {
        if (window.wi && typeof window.wi.confirm === 'function') {
            return window.wi.confirm(options);
        }

        return Promise.resolve(window.confirm(options.text));
    }

    // Una sola partenza: all'invio le righe vanno in `lines` e i bottoni si spengono.
    function prepare() {
        var current = editor();

        if (sending || !current || !current.validate()) {
            return false;
        }

        lines.value = current.serialize();
        lock(true);

        if (typeof window.loadingSpinner === 'function') {
            window.loadingSpinner();
        }

        return true;
    }

    function send() {
        intent.value = 'confirm';

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else if (prepare()) {
            form.submit();
        } else {
            intent.value = 'save';
        }
    }

    form.addEventListener('submit', function (event) {
        if (!prepare()) {
            event.preventDefault();
            intent.value = 'save';
        }
    });

    confirmButton.addEventListener('click', function () {
        var current = editor();
        var count;

        if (sending || !current || !current.validate()) {
            return;
        }

        count = current.totals();

        // Senza righe niente domanda: il servizio risponde che non ce ne sono.
        if (count.rows === 0) {
            send();

            return;
        }

        ask({
            title: label,
            text: question.replace('{pezzi}', window.wiStockEditor.format(count.pieces)).replace('{righe}', String(count.rows)),
            ok: label,
            cancel: 'Torna alla bozza'
        }).then(function (ok) {
            if (ok) {
                send();
            }
        });
    });

    // Tornando indietro alla pagina dalla cache del browser i bottoni si riaccendono.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            lock(false);
            intent.value = 'save';

            if (typeof window.loadingSpinner === 'function') {
                window.loadingSpinner(false);
            }
        }
    });
})();
</script>
<?php \Wonder\View\View::end(); ?>
```

- [ ] **Passo 5: la Resource usa il template**

In `src/Resources/Stock/StockDocumentResource.php`, negli import:

```php
use Wonder\Plugin\Gestionale\Gestionale;
```

Sostituire `pageSchema()`:

```php
    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only(['list', 'create', 'store', 'edit', 'update', 'delete'])
            ->titles([
                'list' => static::titleLabel(),
                'create' => static::newLabel(),
                'edit' => 'Bozza di '.mb_strtolower(static::singular()),
            ])
            ->view('form', Gestionale::viewPath('pages/stock-document-form.php'));
    }
```

Subito dopo `closedRedirect()`:

```php
    /**
     * La domanda prima della conferma (spec §4.2). `{pezzi}` e `{righe}` li
     * riempie la pagina con i numeri della tabella.
     */
    public static function confirmQuestion(): string
    {
        $where = static::type() === 'issue' ? 'escono dal' : 'entrano in';

        return '{pezzi} pezzi in {righe} righe '.$where.' magazzino. Dopo non si modifica più: si può solo annullare.';
    }

    /**
     * Le righe la cui versione è stata eliminata non si disegnano
     * (`StockDocumentForm::editorLines()`): la pagina lo dice, perché salvando
     * escono anche dal documento.
     */
    public static function formNotices(array $values, string $mode): array
    {
        $notices = parent::formNotices($values, $mode);
        $dropped = (int) ($values['editor']['dropped'] ?? 0);

        if ($dropped === 1) {
            $notices[] = ['level' => 'warning', 'message' => "Una riga non c'è più: la sua versione è stata eliminata. Salvando la bozza esce anche dal documento."];
        } elseif ($dropped > 1) {
            $notices[] = ['level' => 'warning', 'message' => $dropped.' righe non ci sono più: le loro versioni sono state eliminate. Salvando la bozza escono anche dal documento.'];
        }

        return $notices;
    }
```

- [ ] **Passo 6: eseguire i test e vederli passare**

```bash
cd $W/gestionale && ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentEditorTest.php
ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentFormTest.php
ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentListTest.php
php tests/ConventionsTest.php
```

Expected:
- tutti i check passano nei quattro file;
- il check «(node)» passa, oppure stampa «salto: node non c'è in PATH». Il `node` di Herd sta in `~/.config/herd/bin/nvm`: se manca in PATH, lanciare il test con quel PATH, perché il check va visto passare almeno una volta;
- se il check «(node)» fallisce su un solo caso, il JS legge diverso dal server. Si corregge il JS e non il test, perché l'arbitro è `Numbers::fromForm()`.

- [ ] **Passo 7: la bozza nel browser**

Su `https://ecommerce-carichi.test/backend/app/gestionale/carichi/create/` (login fatto dall'utente). Il form deve postare alla pagina e nessuna richiesta deve finire in errore (`read_network_requests`):
- **Ricerca.** Scrivere due lettere del nome di un articolo del catalogo di prova: compare la lista, al più 20 versioni, senza multiprodotti. Un clic aggiunge la riga con 1 pezzo e mette il fuoco sui pezzi.
- **Lettore.** Incollare nella casella lo SKU di una versione e premere Invio due volte: una riga sola, con 2 pezzi. Uno SKU inventato dà «Nessuna versione con questo codice». Un codice condiviso, se il catalogo di prova ne ha uno, mostra la lista e non aggiunge niente.
- **Pezzi.** Scrivere «abc» nei pezzi: la casella diventa rossa, *Salva bozza* non parte e compare «Controlla i pezzi della riga in rosso.». Con «2,5» il totale dice «… · 3,5 pezzi».
- **Salva bozza.** Il messaggio è «Bozza salvata.» e la pagina riapre la bozza (titolo «Bozza di carico») con le stesse righe e gli stessi pezzi.
- ***Conferma carico*** apre la domanda con i numeri della tabella. «Torna alla bozza» non invia niente; confermando arrivano il messaggio «Carico N confermato.» e la scheda. Fino al Task 6 la scheda è la pagina `view` del core, oppure un 404: va bene, il Task 6 la scrive.
- **Doppio clic** veloce su *Salva bozza*: una sola richiesta POST.
- **Scarichi.** Su `/backend/app/gestionale/scarichi/create/` c'è la *Causale*. Confermare senza sceglierla dà l'errore del servizio e riapre la bozza.
- **Telefono** (`resize_window` mobile): si vedono Articolo e Pezzi, la Giacenza no. Poi tornare a `desktop`.

Se il core chiede un `formSchema()` o un `FORM_LAYOUT` per disegnare `create`/`edit` (pagina bianca o errore nel log), aggiungere alla base un `formSchema()` che restituisce `[]` e scriverlo come ruling.

- [ ] **Passo 8: commit**

```bash
cd $W/gestionale && git add src/Support/Stock/StockDocumentEditor.php view/pages/stock-document-form.php src/Resources/Stock/StockDocumentResource.php tests/integrazione/StockDocumentEditorTest.php
git commit -m "$(cat <<'EOF'
Carichi e Scarichi: la bozza con Cerca o scansiona, righe in JSON, domanda prima della conferma

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 6: La scheda in sola lettura, *Annulla* e *Duplica*

Un documento confermato o annullato si apre nella pagina `view` del core, con un template suo (`PageSchema::view('show', …)`). *Annulla* e *Duplica* stanno nell'intestazione (`PageSchema::actions('view', …)`) e inviano due form nascosti a una Resource di sole azioni, `StockDocumentActionResource`, come fa `OrderActionResource` per l'ordine.

Le regole della scheda (spec §4.2):
- **Titolo.** «Carico 2026/090003». Il layout `backend.show` escapa `TITLE`, quindi il badge *Annullato* non può stare nel titolo: sta in cima al primo riquadro. È un ruling di questo piano.
- **In alto.** Sede (da due sedi in su) · Causale (scarico) · Nota. Sotto, «Confermato da Mario il 24/09/2026» e, se c'è, «Annullato da Luca il 25/09/2026». Chi e quando vengono dal log degli stati, letto con una query sola. Senza utente la frase resta senza «da …».
- **Righe.** Articolo — Versione con lo SKU sotto, e i Pezzi. In fondo il totale, scritto come nella bozza («12 righe · 34 pezzi»). Le versioni si leggono una volta sola, eliminate comprese: il documento è storia. Una versione che non c'è più nel database si legge «Versione eliminata».
- **Bozza.** Una bozza aperta all'indirizzo della scheda (link vecchio) va alla sua pagina di modifica. Il core disegna il template dentro `ob_start`, quindi `header('Location')` più `exit` funzionano.
- ***Annulla*** c'è solo sui confermati. Chiede conferma con l'attributo `data-wi-confirm` del core (`global/js/confirm.js`): il `submit` del form passa prima dalla domanda, anche quando parte da `requestSubmit()`. Nessun JS nuovo. Il testo è «Il carico {numero} si annulla: i suoi movimenti si stornano e il documento resta, annullato.» (*Lo scarico …*). Se lo storno si ferma per pezzi insufficienti, torna alla scheda con l'errore del servizio e il documento resta confermato.
- ***Annulla* due volte** (due schede aperte): la seconda dà «Si annulla solo un documento confermato.» (Review Focus 3).
- ***Duplica*** c'è su tutti. Crea la bozza nuova con `StockDocuments::duplicate()` e la apre con «Copia creata: questa è una bozza nuova.». Il form ignora un secondo invio, così un doppio clic non fa due bozze.
- **`?duplica=1`** è l'indirizzo che il menu ⋯ dell'elenco apre (Task 3), su scheda e bozza. La pagina toglie il parametro dall'indirizzo e chiede «Duplica» prima di inviare. Senza la domanda, un link a `…?duplica=1` mandato da altri creerebbe una bozza a chi lo apre, perché il form porta il CSRF della pagina. È un ruling di questo piano.

**Files:**
- Create: `src/Support/Stock/StockDocumentSheet.php`
- Create: `src/Resources/Stock/StockDocumentActionResource.php`
- Create: `view/pages/stock-document-show.php`
- Modify: `src/Resources/Stock/StockDocumentResource.php` (`pageSchema()`, metodi nuovi dopo `formNotices()`)
- Modify: `view/pages/stock-document-form.php` (i form nascosti dopo lo `<script>`)
- Test: `tests/integrazione/StockDocumentActionTest.php`

**Interfaces:**
- Consumes:
  - `StockDocuments::cancel(int $id, array $options): void`, `duplicate(int $id, array $options): int`, `items(int $id)` (piano 4); chiavi `stock_document.cannot_cancel`, `stock_document.expired`;
  - `StockDocumentForm::respond(array{ok, message, url}): never`, `StockDocumentForm::quantityText(?float): string` (Task 4);
  - `StockDocumentEditor::totals(int $rows, float $pieces): string` (Task 5);
  - `StockDocumentResource::classFor/pageUrl/viewUrl/editUrl/listUrl/singular/type` (Task 3); `StockMovementResource::listUrl()`;
  - `ProductNames::models(array): array`, `ProductNames::full(array, array): string`; `Reasons::label(string)`; `Locations::shown()`.
- Produces:
  - `StockDocumentSheet::for(array $document): array{lines: list<array{label: string, sku: string, quantity: string}>, total: string, confirmed: string, cancelled: string, location: string, reason: string, note: string}`
  - `StockDocumentActionResource::run(string $action, int $documentId, int $userId): array{ok: bool, message: string, url: string}`; costanti `CANCEL = 'annulla'`, `DUPLICATE = 'duplica'`
  - `StockDocumentActionResource::submitUrl(): string`
  - `StockDocumentResource::named(): string` — «Il carico», «Lo scarico» (e «L'inventario» per il piano 6)
  - `StockDocumentResource::cancelQuestion(): string` — con `{numero}` da riempire
  - `StockDocumentResource::hiddenForms(array $document): string` — `#wi-doc-duplicate` sempre, `#wi-doc-cancel` solo sui confermati, più lo script di `?duplica=1`
  - `StockDocumentResource::headerActions(array $item): list<array<string, string>>`

- [ ] **Passo 1: il test d'integrazione che fallisce**

Create `tests/integrazione/StockDocumentActionTest.php`:

```php
<?php
/** php tests/integrazione/StockDocumentActionTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\App\Models\User\User;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Stock\StockDocumentActionResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockIssueResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockMovementResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockReceiptResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\ProductNames;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentSheet;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocuments;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

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

function articolo(float $pezzi = 10): int
{
    return articoloConGiacenza($pezzi, 'TST-AZN-'.substr((string) microtime(true), -6).random_int(10, 99));
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

function storni(int $documento): int
{
    return count(righe(StockMovement::class, "`reference_type` = 'stock_document_cancel' AND `reference_id` = ".$documento." AND `deleted` = 'false'"));
}

function nomeUtente(int $id): string
{
    $u = User::findById($id);
    $nome = trim(trim((string) ($u['name'] ?? '')).' '.trim((string) ($u['surname'] ?? '')));

    return $nome !== '' ? $nome : (string) ($u['username'] ?? '');
}

function messaggio(string $chiave): string
{
    return UserError::make($chiave)->getMessage();
}

/** Un carico confermato con una riga; restituisce id e numero. */
function caricoConfermato(int $articolo, string $pezzi, int $utente): array
{
    $id = StockDocuments::create('receipt', ['note' => 'Bolla 7'], [['product_id' => $articolo, 'quantity' => $pezzi]], ['user_id' => $utente]);

    return [$id, StockDocuments::confirm($id, ['user_id' => $utente])];
}

$utente = 1;
$altro = (int) (User::find("`id` <> 1 AND `deleted` = 'false'", 1)['id'] ?? 0);
$oggi = date('d/m/Y');
$annulla = StockDocumentActionResource::CANCEL;
$duplica = StockDocumentActionResource::DUPLICATE;

check('annulla: il carico confermato diventa annullato, con gli storni, e si torna alla scheda', function () use ($utente, $annulla) {
    return prova(static function () use ($utente, $annulla): bool {
        [$id, $numero] = caricoConfermato(articolo(0), '3', $utente);
        $esito = StockDocumentActionResource::run($annulla, $id, $utente);

        return $esito === ['ok' => true, 'message' => 'Il carico '.$numero.' è annullato.', 'url' => StockReceiptResource::viewUrl($id)]
            && StockDocument::findById($id)['status'] === 'cancelled'
            && storni($id) === 1;
    });
});

check('annulla: lo scarico dice «Lo scarico … è annullato.»', function () use ($utente, $annulla) {
    return prova(static function () use ($utente, $annulla): bool {
        $id = StockDocuments::create('issue', ['reason' => 'internal_use'], [['product_id' => articolo(5), 'quantity' => '2']]);
        $numero = StockDocuments::confirm($id, ['user_id' => $utente]);
        $esito = StockDocumentActionResource::run($annulla, $id, $utente);

        return $esito['ok'] && $esito['message'] === 'Lo scarico '.$numero.' è annullato.'
            && $esito['url'] === StockIssueResource::viewUrl($id);
    });
});

check('annulla due volte (due schede): la seconda è il rifiuto cannot_cancel, niente storni in più', function () use ($utente, $annulla) {
    return prova(static function () use ($utente, $annulla): bool {
        [$id] = caricoConfermato(articolo(0), '3', $utente);
        StockDocumentActionResource::run($annulla, $id, $utente);
        $seconda = StockDocumentActionResource::run($annulla, $id, $utente);

        return $seconda === ['ok' => false, 'message' => messaggio('stock_document.cannot_cancel'), 'url' => StockReceiptResource::viewUrl($id)]
            && storni($id) === 1;
    });
});

check('annulla su una bozza: cannot_cancel e si torna alla bozza', function () use ($utente, $annulla) {
    return prova(static function () use ($utente, $annulla): bool {
        $id = StockDocuments::create('receipt', [], [['product_id' => articolo(), 'quantity' => '1']]);

        return StockDocumentActionResource::run($annulla, $id, $utente)
            === ['ok' => false, 'message' => messaggio('stock_document.cannot_cancel'), 'url' => StockReceiptResource::editUrl($id)];
    });
});

check('annulla fermato dai pezzi già venduti: l\'errore del servizio, il documento resta confermato', function () use ($utente, $annulla) {
    return prova(static function () use ($utente, $annulla): bool {
        $a = articolo(0);
        [$id] = caricoConfermato($a, '5', $utente);
        Stock::apply(['product_id' => $a, 'quantity' => -4, 'type' => 'sale']);

        try {
            StockDocuments::cancel($id);
            $atteso = '';
        } catch (UserError $errore) {
            $atteso = $errore->getMessage();
        }

        $esito = StockDocumentActionResource::run($annulla, $id, $utente);

        return $atteso !== ''
            && $esito === ['ok' => false, 'message' => $atteso, 'url' => StockReceiptResource::viewUrl($id)]
            && StockDocument::findById($id)['status'] === 'completed';
    });
});

check('duplica: una bozza nuova con le stesse righe, e si apre', function () use ($utente, $duplica) {
    return prova(static function () use ($utente, $duplica): bool {
        $a = articolo(0);
        [$id] = caricoConfermato($a, '2,5', $utente);
        $esito = StockDocumentActionResource::run($duplica, $id, $utente);
        $bozze = righe(StockDocument::class, "`type` = 'receipt' AND `status` = 'draft' AND `deleted` = 'false'");
        $nuovo = (int) (end($bozze)['id'] ?? 0);
        $copia = StockDocument::findById($nuovo);
        $righe = StockDocuments::items($nuovo);

        return $esito['ok'] && $esito['message'] === 'Copia creata: questa è una bozza nuova.'
            && $nuovo !== $id
            && $esito['url'] === StockReceiptResource::editUrl($nuovo)
            && $copia['status'] === 'draft' && $copia['type'] === 'receipt'
            && count($righe) === 1 && $righe[0]['product_id'] === $a && $righe[0]['quantity'] === 2.5;
    });
});

check('azione sconosciuta e documento che non c\'è', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        [$id] = caricoConfermato(articolo(0), '1', $utente);

        return StockDocumentActionResource::run('cancella-tutto', $id, $utente)
                === ['ok' => false, 'message' => 'Azione non riconosciuta.', 'url' => StockReceiptResource::viewUrl($id)]
            && StockDocumentActionResource::run(StockDocumentActionResource::CANCEL, 0, $utente)
                === ['ok' => false, 'message' => 'Documento non trovato.', 'url' => StockMovementResource::listUrl()];
    });
});

check('cancelQuestion e named: le frasi della spec', fn () =>
    StockReceiptResource::named() === 'Il carico'
    && StockIssueResource::named() === 'Lo scarico'
    && StockReceiptResource::cancelQuestion() === 'Il carico {numero} si annulla: i suoi movimenti si stornano e il documento resta, annullato.'
    && StockIssueResource::cancelQuestion() === 'Lo scarico {numero} si annulla: i suoi movimenti si stornano e il documento resta, annullato.'
);

check('scheda: righe con nome e SKU, totale, nota, chi ha confermato e chi ha annullato', function () use ($utente, $altro, $oggi, $annulla) {
    return prova(static function () use ($utente, $altro, $oggi, $annulla): bool {
        $a = articolo(0);
        [$id] = caricoConfermato($a, '2,5', $utente);
        StockDocumentActionResource::run($annulla, $id, $altro);
        $p = Product::findById($a);
        $scheda = StockDocumentSheet::for(StockDocument::findById($id));

        return $scheda['lines'] === [[
                'label' => html_entity_decode(ProductNames::full($p, ProductNames::models([$p])), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'sku' => (string) $p['sku'],
                'quantity' => '2,5',
            ]]
            && $scheda['total'] === '1 riga · 2,5 pezzi'
            && $scheda['note'] === 'Bolla 7'
            && $scheda['reason'] === ''
            && $scheda['confirmed'] === 'Confermato da '.nomeUtente($utente).' il '.$oggi
            && $scheda['cancelled'] === 'Annullato'.($altro > 0 ? ' da '.nomeUtente($altro) : '').' il '.$oggi;
    });
});

check('scheda: una versione eliminata dopo la conferma tiene il suo nome; la causale dello scarico', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        $a = articolo(5);
        $causale = 'internal_use';
        $id = StockDocuments::create('issue', ['reason' => $causale], [['product_id' => $a, 'quantity' => '1']]);
        StockDocuments::confirm($id, ['user_id' => $utente]);
        sqlModify(Product::$table, ['deleted' => 'true'], 'id', $a);
        $scheda = StockDocumentSheet::for(StockDocument::findById($id));

        return $scheda['lines'][0]['label'] !== '' && $scheda['lines'][0]['label'] !== 'Versione eliminata'
            && $scheda['reason'] === Reasons::label($causale)
            && $scheda['cancelled'] === '';
    });
});

check('pageSchema: la scheda con il suo template, titolo «Carico»', function () {
    $schema = StockReceiptResource::pageSchema();
    $template = Gestionale::viewPath('pages/stock-document-show.php');

    return !empty(((array) $schema->get('pages'))['view'])
        && (((array) $schema->get('views'))['show'] ?? '') === $template
        && is_file($template)
        && (((array) $schema->get('titles'))['view'] ?? '') === 'Carico'
        && ((array) StockIssueResource::pageSchema()->get('titles'))['view'] === 'Scarico';
});

check('intestazione: Duplica e Annulla sul confermato, solo Duplica sull\'annullato', function () {
    $azioni = ((array) StockReceiptResource::pageSchema()->get('actions'))['view'] ?? null;
    $etichette = static fn (array $item): array => array_column(is_callable($azioni) ? $azioni($item) : (array) $azioni, 'label');

    return $etichette(['id' => 7, 'status' => 'completed']) === ['Duplica', 'Annulla']
        && $etichette(['id' => 7, 'status' => 'cancelled']) === ['Duplica'];
});

check('hiddenForms: il form di Annulla con la domanda solo sul confermato; Duplica sempre', function () {
    $confermato = StockReceiptResource::hiddenForms(['id' => 7, 'status' => 'completed', 'number' => '2026/090003']);
    $annullato = StockIssueResource::hiddenForms(['id' => 8, 'status' => 'cancelled', 'number' => '2026/100001']);
    $azione = htmlspecialchars(StockDocumentActionResource::submitUrl(), ENT_QUOTES, 'UTF-8');

    return str_contains($confermato, 'id="wi-doc-cancel"')
        && str_contains($confermato, 'data-wi-confirm="Il carico 2026/090003 si annulla: i suoi movimenti si stornano e il documento resta, annullato."')
        && str_contains($confermato, 'data-wi-confirm-ok="Annulla il carico"')
        && str_contains($confermato, 'data-wi-confirm-variant="danger"')
        && str_contains($confermato, 'action="'.$azione.'"')
        && str_contains($confermato, 'name="action" value="annulla"')
        && str_contains($confermato, 'name="document_id" value="7"')
        && str_contains($confermato, 'id="wi-doc-duplicate"')
        && !str_contains($annullato, 'wi-doc-cancel')
        && str_contains($annullato, 'id="wi-doc-duplicate"')
        && str_contains($annullato, 'name="action" value="duplica"')
        && StockReceiptResource::hiddenForms([]) === '';
});

check('azioni: indirizzo e permessi di admin e administrator', function () {
    $permessi = (array) (StockDocumentActionResource::permissionSchema()->toArray()['backend'] ?? []);

    return str_contains(StockDocumentActionResource::submitUrl(), 'documento-magazzino-azione')
        && ($permessi['edit'] ?? []) === ['admin', 'administrator']
        && ($permessi['update'] ?? []) === ['admin', 'administrator'];
});

summary();
```

- [ ] **Passo 2: eseguirlo e vederlo fallire**

```bash
cd $W/gestionale && ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentActionTest.php
```

Expected: errore fatale `Class "Wonder\Plugin\Gestionale\Resources\Stock\StockDocumentActionResource" not found`.

- [ ] **Passo 3: `StockDocumentSheet`**

Create `src/Support/Stock/StockDocumentSheet.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\App\Models\User\User;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentStatusLog;

/**
 * Quello che mostra la scheda di un documento confermato o annullato
 * (G2b-bis 4.2), già pronto da scrivere: il template non legge il database.
 *
 * Le versioni si leggono una volta sola, eliminate comprese, perché il
 * documento è storia; chi e quando vengono dal log degli stati.
 */
final class StockDocumentSheet
{
    /**
     * @param array<string, mixed> $document una riga di `gst_stock_documents`
     * @return array{lines: list<array{label: string, sku: string, quantity: string}>, total: string, confirmed: string, cancelled: string, location: string, reason: string, note: string}
     */
    public static function for(array $document): array
    {
        $id = (int) ($document['id'] ?? 0);
        $items = $id > 0 ? StockDocuments::items($id) : [];
        $pieces = 0.0;

        foreach ($items as $item) {
            $pieces += (float) ($item['quantity'] ?? 0);
        }

        $people = self::people($id);

        return [
            'lines' => self::lines($items),
            'total' => StockDocumentEditor::totals(count($items), $pieces),
            'confirmed' => $people['completed'] ?? '',
            'cancelled' => $people['cancelled'] ?? '',
            'location' => self::location((int) ($document['location_id'] ?? 0)),
            'reason' => (string) ($document['type'] ?? '') === 'issue' ? Reasons::label((string) ($document['reason'] ?? '')) : '',
            'note' => self::text((string) ($document['note'] ?? '')),
        ];
    }

    /**
     * @param list<array{product_id: int, quantity: ?float}> $items
     * @return list<array{label: string, sku: string, quantity: string}>
     */
    private static function lines(array $items): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $item): int => (int) $item['product_id'], $items)));
        $products = [];

        if ($ids !== []) {
            foreach (self::rows(Product::find('`id` IN ('.implode(', ', $ids).')')) as $product) {
                $products[(int) $product['id']] = $product;
            }
        }

        $models = ProductNames::models($products);

        return array_map(static function (array $item) use ($products, $models): array {
            $product = $products[(int) $item['product_id']] ?? null;

            return [
                'label' => $product === null ? 'Versione eliminata' : self::text(ProductNames::full($product, $models)),
                'sku' => $product === null ? '' : self::text((string) ($product['sku'] ?? '')),
                'quantity' => StockDocumentForm::quantityText($item['quantity']),
            ];
        }, $items);
    }

    /**
     * «Confermato da Mario il 24/09/2026», «Annullato da Luca il 25/09/2026»:
     * una query sul log e una sugli utenti. Senza utente, senza «da».
     *
     * @return array<string, string> stato → frase
     */
    private static function people(int $id): array
    {
        if ($id <= 0) {
            return [];
        }

        $logs = self::rows(StockDocumentStatusLog::find(
            '`stock_document_id` = '.$id." AND `to_value` IN ('completed', 'cancelled') AND `deleted` = 'false'",
            null,
            'id',
            'ASC'
        ));
        $userIds = array_values(array_unique(array_filter(array_map(static fn (array $log): int => (int) ($log['user_id'] ?? 0), $logs))));
        $names = [];

        if ($userIds !== []) {
            foreach (self::rows(User::find('`id` IN ('.implode(', ', $userIds).')')) as $user) {
                $name = trim(trim((string) ($user['name'] ?? '')).' '.trim((string) ($user['surname'] ?? '')));
                $names[(int) $user['id']] = self::text($name !== '' ? $name : (string) ($user['username'] ?? ''));
            }
        }

        $people = [];

        foreach ($logs as $log) {
            $status = (string) $log['to_value'];
            $who = $names[(int) ($log['user_id'] ?? 0)] ?? '';
            $time = strtotime((string) ($log['creation'] ?? ''));

            $people[$status] = ($status === 'completed' ? 'Confermato' : 'Annullato')
                .($who !== '' ? ' da '.$who : '')
                .($time !== false ? ' il '.date('d/m/Y', $time) : '');
        }

        return $people;
    }

    /** Il nome della sede, solo da due sedi in su (come la colonna dell'elenco). */
    private static function location(int $locationId): string
    {
        $shown = Locations::shown();

        if (count($shown) < 2) {
            return '';
        }

        foreach ($shown as $location) {
            if ((int) $location['id'] === $locationId) {
                return $location['label'];
            }
        }

        return '';
    }

    /** Il testo salvato com'era scritto: il template lo escapa una volta sola. */
    private static function text(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
```

- [ ] **Passo 4: `StockDocumentActionResource`**

Create `src/Resources/Stock/StockDocumentActionResource.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

use Throwable;
use Wonder\App\LegacyGlobals;
use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Http\Csrf;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentForm;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocuments;

/**
 * *Annulla* e *Duplica* dalla scheda di un documento di magazzino (G2b-bis 4.2).
 *
 * Non ha una pagina sua: riceve il POST dei form nascosti della scheda e
 * della bozza, fa passare l'azione da `StockDocuments` e torna alla pagina
 * giusta con una frase. Un rifiuto è una frase, mai una pagina d'errore.
 */
final class StockDocumentActionResource extends NavigationOnlyResource
{
    public const CANCEL = 'annulla';
    public const DUPLICATE = 'duplica';

    public static function path(): string
    {
        return 'app/gestionale/documento-magazzino-azione';
    }

    public static function icon(): string
    {
        return 'bi-file-earmark-text';
    }

    public static function titleLabel(): string
    {
        return 'Azione sul documento di magazzino';
    }

    public static function isFormPage(): bool
    {
        return true;
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()->only([])->titles(['form' => 'Azione sul documento di magazzino']);
    }

    /** Le pagine-form leggono `edit` (apertura) e `update` (invio): come Carichi e Scarichi. */
    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backend(['edit', 'update'], ['admin', 'administrator']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        // Fuori dal menu: ci si arriva dai bottoni della scheda.
        return NavigationSchema::for(static::class)
            ->inSection('magazzino')
            ->title('Azione sul documento di magazzino')
            ->authority(['admin', 'administrator'])
            ->enabled(false);
    }

    /** Dove postano i form nascosti: la rotta di questa pagina, in POST. */
    public static function submitUrl(): string
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

    /**
     * Esegue l'azione e dice com'è andata, con la pagina dove tornare.
     *
     * @return array{ok: bool, message: string, url: string}
     */
    public static function run(string $action, int $documentId, int $userId): array
    {
        $document = static::document($documentId);
        $class = $document === null ? null : StockDocumentResource::classFor((string) $document['type']);

        if ($document === null || $class === null) {
            return ['ok' => false, 'message' => 'Documento non trovato.', 'url' => StockMovementResource::listUrl()];
        }

        try {
            if ($action === self::CANCEL) {
                StockDocuments::cancel($documentId, ['user_id' => $userId, 'source' => 'user']);

                return ['ok' => true, 'message' => $class::named().' '.(string) $document['number'].' è annullato.', 'url' => $class::viewUrl($documentId)];
            }

            if ($action === self::DUPLICATE) {
                $copy = StockDocuments::duplicate($documentId, ['user_id' => $userId]);

                return ['ok' => true, 'message' => 'Copia creata: questa è una bozza nuova.', 'url' => $class::editUrl($copy)];
            }

            return ['ok' => false, 'message' => 'Azione non riconosciuta.', 'url' => $class::pageUrl($document)];
        } catch (UserError $error) {
            $fresh = static::document($documentId);

            return ['ok' => false, 'message' => $error->getMessage(), 'url' => $fresh === null ? $class::listUrl() : $class::pageUrl($fresh)];
        }
    }

    /**
     * Riceve il POST: `action`, `document_id`. Il CSRF si controlla qui: il
     * core non lo fa per le pagine-form.
     */
    public static function submitFormPage(array $values): string
    {
        $documentId = (int) ($values['document_id'] ?? 0);

        if (Csrf::verify()) {
            $result = static::run((string) ($values['action'] ?? ''), $documentId, static::currentUserId());
        } else {
            $document = static::document($documentId);
            $class = $document === null ? null : StockDocumentResource::classFor((string) $document['type']);
            $result = [
                'ok' => false,
                'message' => UserError::make('stock_document.expired')->getMessage(),
                'url' => $document === null || $class === null ? StockMovementResource::listUrl() : $class::pageUrl($document),
            ];
        }

        if (headers_sent()) {
            return $result['message'];
        }

        StockDocumentForm::respond($result);
    }

    /** @return array<string, mixed>|null il documento, se c'è e non è eliminato */
    private static function document(int $id): ?array
    {
        $document = $id > 0 ? StockDocument::findById($id) : null;

        return is_array($document) && $document !== [] && (string) ($document['deleted'] ?? 'false') !== 'true' ? $document : null;
    }

    private static function currentUserId(): int
    {
        $user = LegacyGlobals::get('USER');

        return is_object($user) ? (int) ($user->id ?? 0) : 0;
    }
}
```

- [ ] **Passo 5: la Resource apre la scheda, con i bottoni e i form nascosti**

In `src/Resources/Stock/StockDocumentResource.php`, sostituire `pageSchema()`:

```php
    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only(['list', 'create', 'store', 'edit', 'update', 'view', 'delete'])
            ->titles([
                'list' => static::titleLabel(),
                'create' => static::newLabel(),
                'edit' => 'Bozza di '.mb_strtolower(static::singular()),
                'view' => static::singular(),
            ])
            ->view('form', Gestionale::viewPath('pages/stock-document-form.php'))
            ->view('show', Gestionale::viewPath('pages/stock-document-show.php'))
            ->actions('view', static fn (array $item): array => static::headerActions($item));
    }
```

Subito dopo `formNotices()`:

```php
    /** «Il carico», «Lo scarico»: il soggetto delle frasi su un documento. */
    public static function named(): string
    {
        return match (static::type()) {
            'issue' => 'Lo scarico',
            'stocktake' => 'L\'inventario',
            default => 'Il carico',
        };
    }

    /** La domanda prima di *Annulla* (spec §4.2); `{numero}` lo riempie `hiddenForms()`. */
    public static function cancelQuestion(): string
    {
        return static::named().' {numero} si annulla: i suoi movimenti si stornano e il documento resta, annullato.';
    }

    /**
     * I bottoni dell'intestazione della scheda: *Duplica* sempre, *Annulla*
     * solo sui confermati. Inviano i form di `hiddenForms()`.
     *
     * @param array<string, mixed> $item
     * @return list<array<string, string>>
     */
    public static function headerActions(array $item): array
    {
        $actions = [[
            'label' => 'Duplica',
            'icon' => 'bi bi-files',
            'class' => 'btn-outline-dark btn-sm',
            'onclick' => "document.getElementById('wi-doc-duplicate').requestSubmit()",
        ]];

        if ((string) ($item['status'] ?? '') === 'completed') {
            $actions[] = [
                'label' => 'Annulla',
                'icon' => 'bi bi-x-circle',
                'class' => 'btn-outline-danger btn-sm',
                'onclick' => "document.getElementById('wi-doc-cancel').requestSubmit()",
            ];
        }

        return $actions;
    }

    /**
     * I form nascosti di *Duplica* e *Annulla*, fuori da ogni altro form, e
     * lo script di `?duplica=1` (il menu ⋯ dell'elenco). *Annulla* chiede
     * conferma con `data-wi-confirm` del core; `?duplica=1` chiede con
     * `wi.confirm()` prima di inviare, così un link mandato da altri non crea
     * bozze da solo. Un secondo invio di *Duplica* si ignora.
     *
     * @param array<string, mixed> $document
     */
    public static function hiddenForms(array $document): string
    {
        $id = (int) ($document['id'] ?? 0);

        if ($id <= 0) {
            return '';
        }

        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $json = static fn (string $value): string => (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $action = $e(StockDocumentActionResource::submitUrl());
        $fields = static fn (string $what): string => Csrf::fieldFor('post')
            .'<input type="hidden" name="action" value="'.$what.'">'
            .'<input type="hidden" name="document_id" value="'.$id.'">';
        $html = '<form id="wi-doc-duplicate" method="POST" action="'.$action.'" hidden>'
            .$fields(StockDocumentActionResource::DUPLICATE).'</form>';

        if ((string) ($document['status'] ?? '') === 'completed') {
            $number = html_entity_decode((string) ($document['number'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = 'Annulla '.mb_strtolower(static::named());

            $html .= '<form id="wi-doc-cancel" method="POST" action="'.$action.'" hidden'
                .' data-wi-confirm="'.$e(str_replace('{numero}', $number, static::cancelQuestion())).'"'
                .' data-wi-confirm-title="'.$e('Annulla '.mb_strtolower(static::singular())).'"'
                .' data-wi-confirm-ok="'.$e($label).'"'
                .' data-wi-confirm-cancel="Torna indietro"'
                .' data-wi-confirm-variant="danger">'
                .$fields(StockDocumentActionResource::CANCEL).'</form>';
        }

        return $html.'<script>
(function () {
    \'use strict\';

    var form = document.getElementById(\'wi-doc-duplicate\');
    var text = '.$json('Nasce una bozza nuova con la stessa sede, causale, nota e righe.').';
    var params = new URLSearchParams(window.location.search);
    var rest;

    form.addEventListener(\'submit\', function (event) {
        if (form.getAttribute(\'data-sent\') === \'1\') {
            event.preventDefault();

            return;
        }

        form.setAttribute(\'data-sent\', \'1\');
    });

    if (params.get(\'duplica\') !== \'1\') {
        return;
    }

    // Il parametro esce subito: ricaricando la pagina non si chiede di nuovo.
    params.delete(\'duplica\');
    rest = params.toString();
    window.history.replaceState(null, \'\', window.location.pathname + (rest !== \'\' ? \'?\' + rest : \'\') + window.location.hash);

    window.addEventListener(\'load\', function () {
        var ask = window.wi && typeof window.wi.confirm === \'function\'
            ? window.wi.confirm({title: \'Duplica\', text: text, ok: \'Duplica\', cancel: \'Torna indietro\'})
            : Promise.resolve(window.confirm(text));

        ask.then(function (ok) {
            if (ok) {
                form.requestSubmit();
            }
        });
    });
})();
</script>';
    }
```

- [ ] **Passo 6: il template della scheda**

Create `view/pages/stock-document-show.php`:

```php
<?php

/**
 * La scheda di un carico o di uno scarico confermato o annullato (G2b-bis 4.2).
 *
 * Si legge e basta: *Duplica* e *Annulla* stanno nell'intestazione e inviano
 * i form nascosti di `hiddenForms()`. Il badge *Annullato* sta nel riquadro,
 * perché il layout escapa il titolo.
 *
 * @var array $ITEM
 */

use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentSheet;
use Wonder\View\View;

/** @var class-string<\Wonder\Plugin\Gestionale\Resources\Stock\StockDocumentResource> $resource */
$resource = $RESOURCE_CLASS;
$document = (array) ($ITEM ?? []);
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

// Una bozza si modifica: un link vecchio porta alla sua pagina.
if ((string) ($document['status'] ?? '') === 'draft') {
    header('Location: '.$resource::editUrl((int) ($document['id'] ?? 0)));
    exit();
}

$sheet = StockDocumentSheet::for($document);
$number = html_entity_decode((string) ($document['number'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$info = array_values(array_filter([$sheet['location'], $sheet['reason'], $sheet['note']], static fn (string $value): bool => $value !== ''));

View::layout('backend.show', ['TITLE' => $resource::singular().' '.$number]);
?>
<div class="row g-3">
    <wi-card class="col-12">
        <div class="col-12">
            <?php if ((string) ($document['status'] ?? '') === 'cancelled') { ?>
            <span class="badge text-bg-danger mb-2">Annullato</span>
            <?php } ?>
            <?php if ($info !== []) { ?>
            <p class="mb-2"><?=implode(' · ', array_map($e, $info))?></p>
            <?php } ?>
            <?php foreach ([$sheet['confirmed'], $sheet['cancelled']] as $line) { ?>
            <?php if ($line !== '') { ?>
            <div class="small text-body-secondary"><?=$e($line)?></div>
            <?php } ?>
            <?php } ?>
        </div>
    </wi-card>
    <wi-card class="col-12">
        <div class="col-12 table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Articolo</th>
                        <th class="text-end">Pezzi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sheet['lines'] as $line) { ?>
                    <tr>
                        <td>
                            <?=$e($line['label'])?>
                            <?php if ($line['sku'] !== '') { ?>
                            <div class="small text-body-secondary"><?=$e($line['sku'])?></div>
                            <?php } ?>
                        </td>
                        <td class="text-end" style="font-variant-numeric: tabular-nums"><?=$e($line['quantity'])?></td>
                    </tr>
                    <?php } ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" class="text-end text-body-secondary"><?=$e($sheet['total'])?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </wi-card>
</div>
<?=$resource::hiddenForms($document)?>
<?php View::end(); ?>
```

- [ ] **Passo 7: i form nascosti anche nella bozza**

Il menu ⋯ apre `…/edit/?duplica=1` sulle bozze (Task 3). In `view/pages/stock-document-form.php`, fra `</script>` e `<?php \Wonder\View\View::end(); ?>`, fuori da `#wi-doc-form`:

```php
<?=$resource::hiddenForms($values)?>
```

Su «Nuovo» `$values` non ha `id` e `hiddenForms()` restituisce `''`.

- [ ] **Passo 8: eseguire i test e vederli passare**

```bash
cd $W/gestionale && ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentActionTest.php
ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentEditorTest.php
ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentFormTest.php
ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentListTest.php
php tests/ConventionsTest.php
```

Expected:
- tutti i check passano nei cinque file;
- `ConventionsTest` non chiede niente per `StockDocumentActionResource`: la regola delle feature guarda solo le sottoclassi di `GestionaleResource`;
- se «scheda: righe con nome e SKU…» fallisce solo su `confirmed`, controllare in `StockDocumentStatusLog` che la conferma scriva `user_id`. Se il log non lo scrive, il problema è del servizio (piano 4): va corretto lì, e il test non si tocca.

- [ ] **Passo 9: la scheda nel browser**

Su `https://ecommerce-carichi.test/backend/app/gestionale/carichi/` (login fatto dall'utente), con un carico confermato del Task 5 o uno nuovo. Nessuna richiesta deve finire in errore (`read_network_requests`):
- **Scheda.** ⋯ → *Visualizza* apre «Carico N». Si vedono Nota, «Confermato da … il …», la tabella con nome e SKU, il totale e in alto *Duplica* e *Annulla*.
- **Annulla.** Si apre la finestra con «Il carico N si annulla: …». «Torna indietro» non invia niente. Confermando arrivano «Il carico N è annullato.», il badge *Annullato* e «Annullato da … il …», e resta solo *Duplica*. Nei Movimenti ci sono gli storni (prova 6 della spec §5.4: la voce ⋯ verso il documento arriva nel Task 7).
- **Due schede.** Aprire un altro carico confermato in due schede e annullarlo in tutte e due. La seconda dà «Si annulla solo un documento confermato.».
- **Duplica.** Il bottone porta alla bozza nuova con «Copia creata: questa è una bozza nuova.» e le stesse righe. Un doppio clic veloce fa una bozza sola: contare le bozze nell'elenco prima e dopo.
- **Dal menu ⋯.** *Duplica* su un confermato apre la scheda e chiede «Duplica». «Torna indietro» lascia l'indirizzo senza `?duplica=1`, e ricaricando non chiede più. Confermando nasce la bozza. Lo stesso su una bozza (`…/edit/?duplica=1`).
- **Bozza dalla scheda.** L'indirizzo `…/carichi/{id}/` di una bozza porta a `…/{id}/edit/`.
- **Scarico.** Su uno scarico confermato la scheda mostra la Causale, e *Annulla* dice «Lo scarico N si annulla: …».

Se il layout `backend.show` non prende `TITLE` da `View::layout()` (titolo «Carico» senza numero), passare il titolo come fa `view/pages/shipment-show.php` e scriverlo come ruling.

- [ ] **Passo 10: commit**

```bash
cd $W/gestionale && git add src/Support/Stock/StockDocumentSheet.php src/Resources/Stock/StockDocumentActionResource.php view/pages/stock-document-show.php src/Resources/Stock/StockDocumentResource.php view/pages/stock-document-form.php tests/integrazione/StockDocumentActionTest.php
git commit -m "$(cat <<'EOF'
Carichi e Scarichi: la scheda in sola lettura con Annulla (data-wi-confirm) e Duplica, anche dal menu ⋯

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 7: Il documento nei Movimenti

Lo spec (§3) vuole il documento dentro *Tipo* come testo e la sua pagina nel menu ⋯: «*Visualizza carico*», «*Visualizza scarico*» (e «*Visualizza inventario*» col piano 6), solo sulle righe nate da un documento, storni compresi. La voce usa le azioni-array del core (`docs/app/concetti/tabelle/tablecolumn.md`): la chiave della voce è `document_type`, una colonna calcolata. L'etichetta è una mappa sul suo valore: su una riga senza documento il valore manca e la voce non compare. Il tipo che non ha ancora una pagina, `stocktake`, non è nella mappa. Anche l'indirizzo è una colonna calcolata (`document_url`), perché carico e scarico hanno indirizzi diversi e `href` prende un solo modello.

Tipo, numero e indirizzo arrivano con `select()`, come chiede lo spec («Mai una query per riga»). `documentOf()` li legge dalla riga quando ci sono. La lettura con `one()` resta solo per chi passa righe grezze (i test con `StockMovement::find`).

**Files:**
- Modify: `src/Resources/Stock/StockMovementResource.php` (`tableSchema()` colonna `actions`, `tableLayoutSchema()`, `documentOf()`, `navigationSchema()`, nuovi `select()` e `documentUrlSql()`)
- Modify: `src/Resources/Stock/StockDocumentResource.php` (nuovo `viewUrlPattern()`)
- Test: `tests/StockMovementResourceTest.php`, `tests/integrazione/StockMovementBackendTest.php`

**Interfaces:**
- Consumes:
  - `StockDocumentResource::classFor(string $type): ?string`, `routeUrl()`, `path()`, `viewUrl(int $id)` (Task 3);
  - `StockDocumentRules::TYPES`, `label()`, `cancelLabel()` (piano 4);
  - `StockDocuments::create(string $type, array $header, array $lines): int`, `confirm(int $id): string` (il numero), `cancel(int $id): void` (piano 4).
- Produces:
  - `StockDocumentResource::viewUrlPattern(string $placeholder): string` — l'indirizzo della scheda con `$placeholder` al posto dell'id.
  - `StockMovementResource::select(): string` — alias `document_type`, `document_number`, `document_url`. Valgono `NULL` sulle righe senza documento; `document_url` è `''` per un tipo senza pagina.
  - Voce ⋯ `document_type` (etichette `receipt` → «Visualizza carico», `issue` → «Visualizza scarico», `href` `{document_url}`); *Movimenti* a 50 nel menu *Magazzino*.

- [ ] **Passo 1: i test unitari che falliscono**

In `tests/StockMovementResourceTest.php`, aggiungere fra gli `use`:

```php
use Wonder\Backend\Support\ResourceTableRenderer;
use Wonder\Plugin\Gestionale\Resources\Stock\StockIssueResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockReceiptResource;
```

Sostituire il check «il menu ⋯ ha «Apri la versione» e «Visualizza ordine» solo per le righe di un ordine» (righe 165-184) con:

```php
check('il menu ⋯: documento, ordine, versione; il documento solo sulle righe che ne hanno uno', function () {
    foreach (StockMovementResource::tableSchema() as $colonna) {
        if ((string) $colonna->name !== 'actions') {
            continue;
        }

        $azioni = (array) $colonna->getSchema('actions');
        $documento = (array) ($azioni['document_type'] ?? []);
        $versione = (array) ($azioni['versione'] ?? []);
        $ordine = (array) ($azioni['ordine'] ?? []);

        return array_keys($azioni) === ['document_type', 'ordine', 'versione']
            && ($documento['label'] ?? null) === ['receipt' => 'Visualizza carico', 'issue' => 'Visualizza scarico']
            && ($documento['href'] ?? '') === '{document_url}'
            && !isset($documento['filter'])
            && ($versione['label'] ?? '') === 'Apri la versione'
            && str_contains((string) ($versione['href'] ?? ''), '{product_id}')
            && !isset($versione['filter'])
            && ($ordine['label'] ?? '') === 'Visualizza ordine'
            && str_contains((string) ($ordine['href'] ?? ''), '{reference_id}')
            && ($ordine['filter']['row']['reference_type'] ?? '') === 'order';
    }

    return false;
});
```

Prima di `summary();` aggiungere:

```php
check('l\'elenco legge tipo, numero e indirizzo del documento con select()', function () use ($layout) {
    $select = StockMovementResource::select();

    return ($layout()['select'] ?? '') === $select
        && ResourceTableRenderer::selectAliases($select) === ['document_type', 'document_number', 'document_url']
        && str_contains($select, "IN ('stock_document', 'stock_document_cancel')");
});

check('l\'indirizzo calcolato copre i tipi con una pagina, non l\'inventario', function () {
    $select = StockMovementResource::select();

    return str_contains($select, "WHEN 'receipt' THEN")
        && str_contains($select, "WHEN 'issue' THEN")
        && !str_contains($select, "'stocktake'");
});

check('il modello dell\'indirizzo della scheda tiene il segnaposto al posto dell\'id', fn () =>
    StockReceiptResource::viewUrlPattern('{x}') === str_replace('/8/', '/{x}/', StockReceiptResource::viewUrl(8))
    && StockIssueResource::viewUrlPattern('__ID__') === str_replace('/8/', '/__ID__/', StockIssueResource::viewUrl(8))
);

check('Tipo legge il documento dalla riga, senza database', fn () =>
    StockMovementResource::typeCellOf([
        'type' => 'adjustment', 'reference_type' => 'stock_document', 'reference_id' => 5,
        'document_type' => 'issue', 'document_number' => '2026/100003',
    ]) === 'Scarico · 2026/100003'
    && StockMovementResource::typeCellOf([
        'type' => 'purchase', 'reference_type' => 'stock_document_cancel', 'reference_id' => 4,
        'document_type' => 'receipt', 'document_number' => '2026/100001',
    ]) === 'Annullamento del carico · 2026/100001'
);

check('Tipo con le colonne del documento vuote resta l\'etichetta del tipo', fn () =>
    StockMovementResource::typeCellOf([
        'type' => 'adjustment', 'reference_type' => '', 'reference_id' => 0,
        'document_type' => null, 'document_number' => null,
    ]) === 'Rettifica'
);

check('Movimenti sta dopo Carichi e Scarichi nel menu Magazzino', fn () =>
    (StockMovementResource::navigationSchema()->toArray()['order'] ?? null) === 50
);
```

`typeCellOf()` è pubblico (riga 356). Il test unitario non ha database: se `documentOf()` chiamasse `one()` sulle righe con le colonne calcolate, il check fallirebbe o si fermerebbe sulla connessione.

- [ ] **Passo 2: il test d'integrazione che fallisce**

In `tests/integrazione/StockMovementBackendTest.php`, aggiungere fra gli `use`:

```php
use Wonder\Plugin\Gestionale\Resources\Stock\StockIssueResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockReceiptResource;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocuments;
```

Dopo la funzione `celleDi()` aggiungere:

```php
/**
 * I movimenti di una versione come li legge l'elenco: la riga e le colonne
 * calcolate di `select()`. Una query diretta, come fa SSP: `Model::find` non
 * aggiunge il suo WHERE a una condizione che contiene già una sottoquery.
 */
function movimentiConDocumento(int $prodotto): array
{
    $tabella = '`'.StockMovement::$table.'`';

    return StockMovement::query()->mysqli->query(
        'SELECT '.$tabella.'.*, '.StockMovementResource::select().' FROM '.$tabella
        .' WHERE `product_id` = '.$prodotto." AND `deleted` = 'false' ORDER BY `id`"
    )->fetch_all(MYSQLI_ASSOC);
}
```

Prima di `summary();` aggiungere:

```php
check('carico e suo storno: tipo, numero e indirizzo della scheda dalle colonne calcolate', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(3, 'TST-MOV-C'.substr((string) microtime(true), -6));
        $id = StockDocuments::create('receipt', [], [['product_id' => $prodotto, 'quantity' => '2']]);
        $numero = StockDocuments::confirm($id);
        StockDocuments::cancel($id);
        [$iniziale, $carico, $storno] = movimentiConDocumento($prodotto) + [[], [], []];
        $url = StockReceiptResource::viewUrl($id);

        return $iniziale['document_type'] === null && $iniziale['document_url'] === null
            && $carico['document_type'] === 'receipt' && $carico['document_number'] === $numero && $carico['document_url'] === $url
            && $storno['document_type'] === 'receipt' && $storno['document_number'] === $numero && $storno['document_url'] === $url
            && celleDi($carico)['type'] === 'Carico · '.htmlspecialchars($numero, ENT_QUOTES)
            && celleDi($storno)['type'] === 'Annullamento del carico · '.htmlspecialchars($numero, ENT_QUOTES)
            && !str_contains(celleDi($carico)['type'], '<a');
    });
});

check('uno scarico porta alla scheda dello scarico', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(5, 'TST-MOV-S'.substr((string) microtime(true), -6));
        $id = StockDocuments::create('issue', ['reason' => 'internal_use'], [['product_id' => $prodotto, 'quantity' => '1']]);
        $numero = StockDocuments::confirm($id);
        $scarico = movimentiConDocumento($prodotto)[1] ?? [];

        return ($scarico['document_type'] ?? '') === 'issue'
            && ($scarico['document_url'] ?? '') === StockIssueResource::viewUrl($id)
            && celleDi($scarico)['type'] === 'Scarico · '.htmlspecialchars($numero, ENT_QUOTES);
    });
});

check('un inventario non ha ancora una pagina: indirizzo vuoto, la voce ⋯ non compare', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(5, 'TST-MOV-I'.substr((string) microtime(true), -6));
        StockDocuments::confirm(StockDocuments::create('stocktake', [], [['product_id' => $prodotto, 'quantity' => '4']]));
        $riga = movimentiConDocumento($prodotto)[1] ?? [];

        return ($riga['document_type'] ?? '') === 'stocktake' && ($riga['document_url'] ?? null) === '';
    });
});
```

Il primo movimento di `articoloConGiacenza()` è un carico senza documento: le tre colonne sono `NULL`, e il core nasconde la voce (`$this->row['document_type']` è `null`, che non è nella mappa delle etichette).

- [ ] **Passo 3: farli fallire**

```bash
cd $W/gestionale && php tests/StockMovementResourceTest.php
ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockMovementBackendTest.php
```

Expected:
- nel test unitario falliscono il menu ⋯ (chiavi `['versione', 'ordine']`), `select()` e `viewUrlPattern()` (metodi che non esistono: «Call to undefined method», che ferma il file; vanno bene anche così) e l'ordine (20);
- nel test d'integrazione si ferma sulla prima chiamata a `StockMovementResource::select()`, che non esiste.

- [ ] **Passo 4: `viewUrlPattern()` in `StockDocumentResource`**

In `src/Resources/Stock/StockDocumentResource.php`, subito dopo `viewUrl()`:

```php
    /**
     * L'indirizzo della scheda con un segnaposto al posto dell'id: per le
     * azioni-array (`{colonna}`) e per l'indirizzo calcolato dei Movimenti.
     */
    public static function viewUrlPattern(string $placeholder): string
    {
        $url = static::routeUrl('view', '/backend/'.static::path().'/__ROW_ID__/', ['id' => '__ROW_ID__']);

        return str_replace('__ROW_ID__', $placeholder, $url);
    }
```

È lo stesso modo di `OrderResource::detailUrlPattern()`: la rotta con nome accetta un id non numerico.

- [ ] **Passo 5: `select()`, la voce ⋯, `documentOf()` e l'ordine in `StockMovementResource`**

`StockDocumentResource` sta nello stesso namespace (`Wonder\Plugin\Gestionale\Resources\Stock`): si chiama per nome corto, senza `use`.

Nella colonna `actions` di `tableSchema()`, sostituire l'array con:

```php
        $columns[] = TableColumn::key('actions')->button()->actions([
            // La chiave è la colonna calcolata di select(): l'etichetta si
            // sceglie sul suo valore, e senza documento (NULL) o con un tipo
            // senza pagina la voce non compare.
            'document_type' => [
                'label' => ['receipt' => 'Visualizza carico', 'issue' => 'Visualizza scarico'],
                'href' => '{document_url}',
            ],
            'ordine' => [
                'label' => 'Visualizza ordine',
                'href' => OrderResource::detailUrlPattern('{reference_id}'),
                'filter' => ['row' => ['reference_type' => 'order']],
            ],
            'versione' => ['label' => 'Apri la versione', 'href' => static::productUrl()],
        ]);
```

In `tableLayoutSchema()`, subito dopo `->results()`:

```php
            ->select(static::select())
```

Dopo `tableLayoutSchema()` aggiungere:

```php
    /**
     * Le colonne calcolate dell'elenco: tipo, numero e indirizzo della scheda
     * del documento di magazzino che ha causato il movimento, storni compresi.
     * Valgono NULL sulle altre righe. Una sottoquery per colonna nella stessa
     * query della pagina, mai una lettura per riga.
     */
    public static function select(): string
    {
        $movements = '`'.StockMovement::$table.'`';
        $of = static fn (string $expression, string $alias): string => '(SELECT '.$expression
            .' FROM `'.StockDocument::$table.'` d WHERE d.`id` = '.$movements.'.`reference_id`'
            .' AND '.$movements.'.`reference_type` IN ('.self::DOCUMENT_REFERENCES.') LIMIT 1) AS '.$alias;

        return implode(', ', [
            $of('d.`type`', 'document_type'),
            $of('d.`number`', 'document_number'),
            $of(static::documentUrlSql(), 'document_url'),
        ]);
    }

    /**
     * L'indirizzo della scheda in SQL, sul tipo del documento `d`: un ramo per
     * ogni tipo che ha una pagina (`StockDocumentResource::classFor()`), stringa
     * vuota per gli altri. I pezzi dell'indirizzo vengono dalle rotte, non
     * dall'utente; si quotano lo stesso.
     */
    private static function documentUrlSql(): string
    {
        $quote = static fn (string $text): string => "'".addcslashes($text, "\\'")."'";
        $cases = '';

        foreach (StockDocumentRules::TYPES as $type) {
            $class = StockDocumentResource::classFor($type);

            if ($class === null) {
                continue;
            }

            [$before, $after] = explode('__DOC_ID__', $class::viewUrlPattern('__DOC_ID__'), 2) + ['', ''];
            $cases .= ' WHEN '.$quote($type).' THEN CONCAT('.$quote($before).', d.`id`, '.$quote($after).')';
        }

        return $cases === '' ? "''" : 'CASE d.`type`'.$cases." ELSE '' END";
    }
```

Sostituire `documentOf()` (docblock compreso) con:

```php
    /**
     * Il numero, il link e l'etichetta del documento che ha causato il
     * movimento. Il numero di un documento di magazzino resta testo: la sua
     * scheda si apre dal menu ⋯ (spec §3). Tipo e numero arrivano da select();
     * una riga senza quelle colonne (letta fuori dall'elenco) li legge dal
     * database.
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

        $document = array_key_exists('document_type', $row)
            ? ['type' => $row['document_type'], 'number' => $row['document_number'] ?? '']
            : static::one(StockDocument::class, $id);
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

In `navigationSchema()`: `->order(20)` diventa `->order(50)`.

- [ ] **Passo 6: farli passare**

```bash
cd $W/gestionale && php tests/StockMovementResourceTest.php
ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockMovementBackendTest.php
```

Expected: tutti i check passano nei due file, compresi quelli che c'erano: «un movimento senza documento non ha né numero né link» legge una riga grezza senza le colonne calcolate e passa da `one()`.

- [ ] **Passo 7: le altre suite che leggono i Movimenti**

```bash
cd $W/gestionale && ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentsTest.php
ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockPagesTest.php
php tests/StockPagesTest.php
php tests/ConventionsTest.php
```

Expected:
- tutti i check passano;
- «la tabella della scheda della versione si costruisce con le colonne scelte» (in `StockMovementBackendTest`) è già passato al Passo 6. `backendTable()` applica la stessa `select()` alla cronologia della scheda della versione, e la sottoquery usa il nome della tabella senza alias, come fa il renderer.

- [ ] **Passo 8: nel browser**

Su `https://ecommerce-carichi.test/backend/app/gestionale/movimenti/`, con il carico annullato e lo scarico del Task 6. Nessuna richiesta deve finire in errore (`read_network_requests`):
- **Menu.** Nel menu *Magazzino* l'ordine è Giacenze, Carichi, Scarichi, Movimenti.
- **Carico.** Sulla riga del carico, *Tipo* dice «Carico · N» senza link, e ⋯ ha *Visualizza carico*, che apre la sua scheda, e *Apri la versione*.
- **Storno.** La riga dello storno dice «Annullamento del carico · N», e ⋯ → *Visualizza carico* apre lo stesso carico (prova 6 della spec §5.4).
- **Scarico.** Sulla riga dello scarico, ⋯ → *Visualizza scarico*.
- **Senza documento.** Una rettifica fatta da Giacenze ha in ⋯ solo *Apri la versione*. Una vendita ha *Visualizza ordine* e *Apri la versione*.
- **Ricerca e ordinamento.** La ricerca per SKU funziona ancora: gli alias di `select()` restano fuori dal WHERE. L'ordinamento per data non dà errori.
- **Scheda della versione.** La cronologia in fondo alla scheda della versione si carica senza errori.

- [ ] **Passo 9: commit**

```bash
cd $W/gestionale && git add src/Resources/Stock/StockMovementResource.php src/Resources/Stock/StockDocumentResource.php tests/StockMovementResourceTest.php tests/integrazione/StockMovementBackendTest.php
git commit -m "$(cat <<'EOF'
Movimenti: tipo, numero e scheda del documento con select(), voce «Visualizza carico/scarico» nel menu ⋯, Movimenti a 50

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 8: Un carico e uno scarico nei dati di prova

Spec §5.2: `gestionale:demo` aggiunge un carico confermato e uno scarico confermato, così le pagine nuove non sono vuote; `--fresh` li toglie con i loro movimenti. I documenti nascono dal servizio vero (`StockDocuments`), con un codice di prova (`stk_demo-carico`, `stk_demo-scarico`) per ritrovarli: per questo `create()` accetta un codice dato.

**Files:**
- Modify: `src/Support/Stock/StockDocuments.php` (`create`: opzione `code`)
- Create: `src/Seeding/StockDocumentsDemo.php`
- Modify: `src/Seeding/Demo.php` (`PROVIDERS`: in fondo)
- Test: `tests/integrazione/StockDocumentsTest.php` (un check), `tests/integrazione/StockDocumentsDemoTest.php` (nuovo)

**Interfaces:**
- Consumes: `StockDocuments::create(string $type, array $header, array $lines, array $options = []): int`, `StockDocuments::confirm(int $id, array $options = []): string`, `StockDocuments::cancel(int $id, array $options = []): void` (piano 4); `DemoData::register/note`, `DemoCode::forModel/prefixOf`, `Levels::of(int): array{quantity: float, …}`.
- Produces: `StockDocuments::create(..., ['code' => string])` (vuoto = codice nuovo); `StockDocumentsDemo::KEY = 'documenti-magazzino'`, `StockDocumentsDemo::create(): int` (documenti creati), `StockDocumentsDemo::clear(): int` (documenti tolti). Il Task 9 cita la chiave nella guida dello sviluppatore.

- [ ] **Passo 1: il test del codice dato**

In `tests/integrazione/StockDocumentsTest.php`, dopo il check «creare: la bozza non muove la giacenza»:

```php
check('creare: un codice dato resta quello, senza codice ne nasce uno nuovo', function () {
    return prova(function (): bool {
        $prodotto = articolo();
        $dato = StockDocuments::create('receipt', [], [['product_id' => $prodotto, 'quantity' => 1]], ['code' => 'stk_prova-codice-dato']);
        $nuovo = StockDocuments::create('receipt', [], [['product_id' => $prodotto, 'quantity' => 1]]);

        return (string) StockDocument::find(['id' => $dato], 1)['code'] === 'stk_prova-codice-dato'
            && str_starts_with((string) StockDocument::find(['id' => $nuovo], 1)['code'], 'stk_')
            && (string) StockDocument::find(['id' => $nuovo], 1)['code'] !== 'stk_prova-codice-dato';
    });
});
```

- [ ] **Passo 2: il test fallisce**

```bash
cd $W/gestionale && ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentsTest.php
```

Expected: FAIL solo su «creare: un codice dato resta quello…» (il codice è uno nuovo `stk_…`, non quello dato).

- [ ] **Passo 3: l'opzione `code`**

In `src/Support/Stock/StockDocuments.php`, nel docblock di `create()`:

```php
     * @param array{user_id?: int, code?: string} $options `code` vuoto = un codice nuovo
```

e nella riga del codice:

```php
                'code' => trim((string) ($options['code'] ?? '')) ?: Code::make(StockDocument::class, Codes::STOCK_DOCUMENT),
```

- [ ] **Passo 4: il test passa**

```bash
cd $W/gestionale && ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentsTest.php
```

Expected: tutti i check passano.

- [ ] **Passo 5: il test dei dati di prova**

Crea `tests/integrazione/StockDocumentsDemoTest.php`:

```php
<?php
/** php tests/integrazione/StockDocumentsDemoTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';
require __DIR__ . '/supporto/compra.php';
require __DIR__ . '/supporto/dns-fixture.php';

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Seeding\CatalogDemo;
use Wonder\Plugin\Gestionale\Seeding\ContactsDemo;
use Wonder\Plugin\Gestionale\Seeding\Demo;
use Wonder\Plugin\Gestionale\Seeding\DemoCode;
use Wonder\Plugin\Gestionale\Seeding\StockDocumentsDemo;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** @return list<array<string, mixed>> */
$righe = static function (string $model, string|array $dove): array {
    $rows = $model::find($dove, null, 'id', 'ASC');

    return !is_array($rows) || $rows === [] ? [] : (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array')));
};

/** La giacenza di ogni versione di prova, per id. */
$giacenze = static function () use ($righe): array {
    $out = [];
    $prefisso = DemoCode::prefixOf(ProductModel::class);

    foreach ($righe(ProductModel::class, "code LIKE '".$prefisso."demo-%' AND deleted = 'false'") as $modello) {
        foreach ($righe(Product::class, ['product_model_id' => (int) $modello['id'], 'deleted' => 'false']) as $versione) {
            $out[(int) $versione['id']] = round(Levels::of((int) $versione['id'])['quantity'], 3);
        }
    }

    return $out;
};

// `CatalogDemo` scrive foto sul disco e la transazione non le rimette: l'istantanea sì.
$foto = Istantanea::di(rtrim((string) ($GLOBALS['ROOT'] ?? ''), '/').'/assets/upload'.ProductImages::folder());
$esito = [];

try {
    Transaction::run(static function () use (&$esito, $righe, $giacenze): void {
        DemoData::reset();
        Demo::registerAll();
        $esito['registrato'] = array_keys(DemoData::all());

        // Si parte puliti, come `--fresh`: prima l'ultimo registrato.
        foreach (DemoData::inClearOrder() as $dati) {
            ($dati['clear'])();
        }

        ContactsDemo::create();
        CatalogDemo::create();
        $esito['prima'] = $giacenze();

        $esito['create'] = StockDocumentsDemo::create();
        $esito['secondo'] = StockDocumentsDemo::create();
        $esito['dopo_create'] = $giacenze();

        $documenti = $righe(StockDocument::class, "code IN ('".DemoCode::forModel(StockDocument::class, 'carico')."', '".DemoCode::forModel(StockDocument::class, 'scarico')."')");
        $esito['documenti'] = array_map(static fn (array $d): array => [
            'type' => (string) $d['type'],
            'status' => (string) $d['status'],
            'number' => (string) $d['number'],
            'reason' => (string) $d['reason'],
        ], $documenti);
        $ids = array_map(static fn (array $d): int => (int) $d['id'], $documenti);
        $in = $ids === [] ? '0' : implode(',', $ids);
        $esito['movimenti'] = count($righe(StockMovement::class, "reference_type = 'stock_document' AND reference_id IN (".$in.')'));

        // La pulizia toglie documenti, righe, storia e movimenti, e la giacenza torna com'era.
        $esito['pulite'] = StockDocumentsDemo::clear();
        $esito['dopo_pulizia'] = $giacenze();
        $esito['restano'] = count($righe(StockDocument::class, 'id IN ('.$in.')'))
            + count($righe(StockDocumentItem::class, 'stock_document_id IN ('.$in.')'))
            + count($righe(StockDocumentStatusLog::class, 'stock_document_id IN ('.$in.')'))
            + count($righe(StockMovement::class, "reference_type IN ('stock_document', 'stock_document_cancel') AND reference_id IN (".$in.')'));
        $esito['seconda_pulizia'] = StockDocumentsDemo::clear();

        // Senza articoli di prova non si crea niente e il comando lo dice.
        CatalogDemo::clear();
        DemoData::notes();
        $esito['senza_catalogo'] = StockDocumentsDemo::create();
        $esito['nota'] = DemoData::notes();

        throw new Annulla();
    });
} catch (Annulla) {
} finally {
    Wonder\Plugin\Gestionale\Gestionale::reset();
    $foto->ripristina();
}

$variazioni = static function (array $prima, array $dopo): array {
    $out = [];

    foreach ($dopo as $id => $quantita) {
        $differenza = round($quantita - ($prima[$id] ?? 0.0), 3);

        if ($differenza !== 0.0) {
            $out[] = $differenza;
        }
    }

    return $out;
};

check('i documenti di prova sono nel registro, dopo il catalogo', function () use ($esito) {
    $chiavi = $esito['registrato'] ?? [];

    return in_array(StockDocumentsDemo::KEY, $chiavi, true)
        && array_search(StockDocumentsDemo::KEY, $chiavi, true) > array_search(CatalogDemo::KEY, $chiavi, true);
});

check('create fa un carico e uno scarico, confermati e numerati, e un secondo giro non ne duplica', function () use ($esito) {
    $tipi = array_column($esito['documenti'] ?? [], 'type');
    sort($tipi);

    return ($esito['create'] ?? 0) === 2 && ($esito['secondo'] ?? -1) === 0
        && $tipi === ['issue', 'receipt']
        && array_unique(array_column($esito['documenti'], 'status')) === ['completed']
        && !in_array('', array_column($esito['documenti'], 'number'), true);
});

check('lo scarico ha la causale «danneggiato»', function () use ($esito) {
    foreach ($esito['documenti'] ?? [] as $documento) {
        if ($documento['type'] === 'issue') {
            return $documento['reason'] === 'damaged';
        }
    }

    return false;
});

check('la giacenza si muove: +8 su una versione (10 caricati, 2 scaricati) e +5 su un\'altra, con tre movimenti', function () use ($esito, $variazioni) {
    $mosse = $variazioni($esito['prima'] ?? [], $esito['dopo_create'] ?? []);
    sort($mosse);

    return $mosse === [5.0, 8.0] && ($esito['movimenti'] ?? 0) === 3;
});

check('la pulizia toglie i due documenti con righe, storia e movimenti, e la giacenza torna com\'era', fn () =>
    ($esito['pulite'] ?? 0) === 2 && ($esito['restano'] ?? -1) === 0
    && $variazioni($esito['prima'] ?? [], $esito['dopo_pulizia'] ?? []) === []
    && ($esito['seconda_pulizia'] ?? -1) === 0
);

check('senza articoli di prova non crea niente e lascia una nota', fn () =>
    ($esito['senza_catalogo'] ?? -1) === 0 && ($esito['nota'] ?? []) !== []
);

summary();
```

`DemoData::notes()` legge le note e le svuota: la prima chiamata parte da zero, la seconda prende quella di `create()`.

- [ ] **Passo 6: il test fallisce**

```bash
cd $W/gestionale && ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentsDemoTest.php
```

Expected: errore fatale `Class "Wonder\Plugin\Gestionale\Seeding\StockDocumentsDemo" not found`.

- [ ] **Passo 7: `StockDocumentsDemo`**

Crea `src/Seeding/StockDocumentsDemo.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocuments;
use Wonder\Sql\Transaction;

/**
 * Un carico e uno scarico di prova, confermati, sugli articoli di
 * `CatalogDemo`: così Carichi e scarichi e i Movimenti non partono vuoti.
 *
 * Nascono dal servizio vero (`StockDocuments`), quindi numero, movimenti e
 * giacenza sono quelli di un documento fatto a mano. Il codice di prova li
 * ritrova: se ci sono già, un secondo giro non li duplica.
 *
 * La pulizia annulla il documento (gli storni rimettono la giacenza) e poi
 * toglie documento, righe, storia e movimenti. Se l'annullamento non si può
 * fare (la merce caricata è già uscita), il documento resta e il comando lo
 * dice: toglierlo lascerebbe una giacenza che non torna.
 */
final class StockDocumentsDemo
{
    /** Chiave nel registro dei dati di prova. */
    public const KEY = 'documenti-magazzino';

    public static function register(): void
    {
        DemoData::register(
            self::KEY,
            'Documenti di magazzino: un carico e uno scarico confermati',
            static fn (): int => self::create(),
            static fn (): int => self::clear()
        );
    }

    /** @return int documenti creati */
    public static function create(): int
    {
        $versions = self::versions();

        if (count($versions) < 2) {
            DemoData::note('Il carico e lo scarico di prova usano gli articoli di prova: lancia il comando con il catalogo di prova.');

            return 0;
        }

        $created = 0;
        $system = ['source' => 'system'];

        if (self::document('carico') === []) {
            $id = StockDocuments::create('receipt', ['note' => 'Dati di prova'], [
                ['product_id' => $versions[0], 'quantity' => 10],
                ['product_id' => $versions[1], 'quantity' => 5],
            ], ['code' => DemoCode::forModel(StockDocument::class, 'carico')]);
            StockDocuments::confirm($id, $system);
            $created++;
        }

        if (self::document('scarico') === []) {
            $id = StockDocuments::create('issue', ['reason' => 'damaged', 'note' => 'Dati di prova'], [
                ['product_id' => $versions[0], 'quantity' => 2],
            ], ['code' => DemoCode::forModel(StockDocument::class, 'scarico')]);
            StockDocuments::confirm($id, $system);
            $created++;
        }

        return $created;
    }

    /**
     * Toglie i documenti di prova, l'ultimo per primo: lo scarico si annulla
     * prima del carico che gli ha dato la merce.
     *
     * @return int documenti tolti
     */
    public static function clear(): int
    {
        $removed = 0;
        $prefix = str_replace('_', '\\_', DemoCode::prefixOf(StockDocument::class));

        foreach (self::rows(StockDocument::find("code LIKE '".$prefix."demo-%'", null, 'id', 'DESC')) as $document) {
            $id = (int) $document['id'];

            if ((string) $document['status'] === 'completed') {
                try {
                    StockDocuments::cancel($id, ['source' => 'system']);
                } catch (UserError) {
                    DemoData::note('Il documento di prova '.(string) $document['number'].' resta: la merce caricata è già uscita. Annullalo a mano quando torna.');
                    continue;
                }
            }

            Transaction::run(static function () use ($id): void {
                sqlDelete(StockMovement::$table, "reference_type IN ('stock_document', 'stock_document_cancel') AND reference_id = ".$id);
                sqlDelete(StockDocumentStatusLog::$table, 'stock_document_id = '.$id);
                sqlDelete(StockDocumentItem::$table, 'stock_document_id = '.$id);
                sqlDelete(StockDocument::$table, 'id = '.$id);
            });
            $removed++;
        }

        return $removed;
    }

    /**
     * Le prime due versioni degli articoli di prova che non sono multiprodotti:
     * un multiprodotto non ha giacenza sua.
     *
     * @return list<int>
     */
    private static function versions(): array
    {
        $out = [];
        $prefix = DemoCode::prefixOf(ProductModel::class);

        foreach (self::rows(ProductModel::find("code LIKE '".$prefix."demo-%' AND deleted = 'false' AND type <> 'bundle'", null, 'id', 'ASC')) as $model) {
            foreach (self::rows(Product::find(['product_model_id' => (int) $model['id'], 'deleted' => 'false'], null, 'id', 'ASC')) as $product) {
                $out[] = (int) $product['id'];

                if (count($out) === 2) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function document(string $ref): array
    {
        $row = StockDocument::find(['code' => DemoCode::forModel(StockDocument::class, $ref)], 1);

        return is_array($row) && isset($row['id']) ? $row : [];
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

In `src/Seeding/Demo.php` aggiungi `StockDocumentsDemo::class` in fondo a `PROVIDERS`, dopo `ShipmentsDemo::class`. In fondo vuol dire che `--fresh` lo pulisce per primo (`DemoData::inClearOrder()` parte dall'ultimo registrato): i documenti se ne vanno prima che `CatalogDemo` provi a togliere gli articoli, che le righe dei documenti tengono con una chiave esterna.

- [ ] **Passo 8: i test passano**

```bash
cd $W/gestionale && ps aux | grep "php .*Test.php" | grep -v grep; php tests/integrazione/StockDocumentsDemoTest.php && php tests/integrazione/ShipmentsDemoTest.php && php tests/integrazione/StockDocumentsTest.php
```

Expected: tutti i check passano nei tre file. `ShipmentsDemoTest` resta verde: il registro ha una chiave in più, dopo le spedizioni.

- [ ] **Passo 9: il comando sul sito di prova**

```bash
cd $S && ps aux | grep "php .*Test.php" | grep -v grep; php forge gestionale:demo --fresh
```

Expected: fra le righe dei dati di prova compare «Documenti di magazzino: un carico e uno scarico confermati» con 2 creati. In `https://ecommerce-carichi.test` (login dell'utente) *Magazzino → Carichi* mostra il carico confermato e *Magazzino → Scarichi* lo scarico, e nei Movimenti le loro righe hanno «Carico · N» e «Scarico · N» con la voce ⋯ verso il documento. Un secondo `php forge gestionale:demo --fresh` toglie e rifà: niente errori, ancora due documenti.

- [ ] **Passo 10: commit**

```bash
cd $W/gestionale && git add src/Support/Stock/StockDocuments.php src/Seeding/StockDocumentsDemo.php src/Seeding/Demo.php tests/integrazione/StockDocumentsTest.php tests/integrazione/StockDocumentsDemoTest.php && git commit -F - <<'EOF'
Dati di prova: un carico e uno scarico confermati

StockDocuments::create accetta un codice dato; --fresh li annulla e li
toglie con righe, storia e movimenti.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

Expected: un commit con cinque file.


---

### Task 9: Guide, CHANGELOG, suite completa e prove nel browser

Spec §5.1: guide *Carichi e scarichi* (nuova), *Giacenze e rettifiche* (il primo carico), *Movimenti* (il documento e la voce ⋯), *Funzionalità* con `config/features.php`; per lo sviluppatore *Magazzino* e *Codici, numerazione e log degli stati*. Più gli ultimi ritocchi del piano 4 che sono solo parole (un docblock e una nota nella guida), la suite completa e le prove 3, 4, 6, 7 di §5.4.

**Files:**
- Create: `docs/user/magazzino-carichi-scarichi.md`
- Modify: `docs/user/SUMMARY.md:22-24`, `docs/user/magazzino-giacenze.md` (*Il primo carico*), `docs/user/magazzino-movimenti.md` (riga *Tipo*, sezione nuova), `docs/user/funzionalita.md` (*Cosa accendono oggi*), `config/features.php:75`
- Modify: `docs/dev/concetti/magazzino.md` (*Le pagine*, *Le classi*, *I documenti*), `docs/dev/concetti/documenti.md` (*Numerazione dei documenti*)
- Modify: `src/Resources/Stock/StockMovementResource.php` (docblock di `typeCondition`)
- Modify: `CHANGELOG.md`, `TODO.md:220-229`

**Interfaces:**
- Consumes: i nomi dei Task 1-8 (`StockReceiptResource`, `StockIssueResource`, `StockDocumentResource`, `StockDocumentForm`, `StockDocumentActionResource`, `StockVersionSearch`, rotta `api.gestionale.backend.stock.versions`, `StockDocumentResource::viewUrlPattern()`, `StockDocumentsDemo::KEY`).
- Produces: niente codice nuovo.

- [ ] **Passo 1: la guida *Carichi e scarichi***

Crea `docs/user/magazzino-carichi-scarichi.md`:

```markdown
---
icon: truck-ramp-box
---

# Carichi e scarichi

> **Inclusa.** Fa parte del gestionale, non c'è niente da attivare.

Un **carico** registra la merce che entra: un corriere che consegna, un
fornitore che porta il riassortimento. Uno **scarico** quella che esce senza
essere venduta: pezzi rotti, scaduti, regalati, usati in negozio. Tutti e due
sono documenti con le loro righe, uno per ogni versione che si muove, e
toccano la giacenza solo quando li **confermi**.

Li trovi in **Magazzino → Carichi** e **Magazzino → Scarichi**.

## L'elenco

Ogni riga è un documento: **Numero**, **Data**, **Sede** (con più sedi),
**Causale** (negli scarichi), **Righe**, **Pezzi**, **Nota** e **Chi** l'ha
fatto. In alto i filtri *Stato* (*Bozze*, *Confermati*, *Annullati*),
*Periodo*, *Sede* e, negli scarichi, *Causale*.

Una bozza non ha ancora numero e la sua data è quella in cui l'hai creata:
numero e data del documento arrivano con la conferma. I tre
puntini in fondo alla riga aprono il documento (*Modifica* per una bozza,
*Visualizza* per gli altri), lo duplicano o, solo per una bozza, lo
eliminano.

## Fare un carico

1. **Nuovo carico**.
2. In alto **Sede** (con più sedi; parte dalla principale) e **Nota**: chi ha
   consegnato, il numero della bolla, quello che fra sei mesi ti servirà
   sapere.
3. Nella casella **Cerca o scansiona** scrivi il nome, lo SKU o l'EAN della
   versione: compaiono fino a 20 risultati con foto e giacenza nella sede
   scelta. Un clic la aggiunge alle righe. I multiprodotti non ci sono: la
   loro giacenza è quella dei pezzi che li compongono.
4. Scrivi i **pezzi** di ogni riga. Una versione sta una volta sola: se la
   aggiungi di nuovo, la riga che c'è già prende un pezzo in più.
5. **Salva bozza** se devi interrompere, **Conferma carico** quando hai
   finito. La conferma chiede «{pezzi} pezzi in {righe} righe entrano in
   magazzino. Dopo non si modifica più: si può solo annullare.».

Confermato, il carico prende numero e data, e nei
[Movimenti](magazzino-movimenti.md) compare una riga per versione.

## Con il lettore di codici a barre

Il lettore scrive il codice nella casella **Cerca o scansiona** e preme
Invio da solo. Con un codice che corrisponde **esattamente** allo SKU o
all'EAN di una versione sola, la riga si aggiunge (o prende un pezzo in più)
e la casella si svuota per il codice dopo. Così si carica un cartone
passando i pezzi uno a uno.

Invio non sceglie mai a caso:

- **Più versioni con questo codice: scegline una** — due versioni hanno lo
  stesso codice; clicca quella giusta.
- **Nessuna versione con questo codice** — il codice non è di nessuna
  versione: controllalo, o scrivilo nella scheda della versione.

## Fare uno scarico

Come il carico, da **Nuovo scarico**, con in più la **Causale**:
*Danneggiato*, *Scaduto*, *Regalo*, *Uso interno*, *Altro*. Si può lasciare vuota
nella bozza, ma serve per confermare.

Uno scarico che porterebbe una versione **sotto zero** non si conferma: il
messaggio dice quale versione, e la bozza resta com'era, con le modifiche
salvate. Correggi i pezzi, o fai prima il carico che manca. Fanno eccezione
gli articoli con **Vendita senza giacenza** accesa (vedi [Sotto
zero](magazzino-giacenze.md#sotto-zero)).

## Un documento confermato

Si apre in sola lettura: righe, numero, data, chi l'ha fatto. Non si
modifica più. Se è sbagliato:

- **Annulla** lo annulla: ogni suo movimento ne ha uno uguale e contrario,
  lo **storno**, e la giacenza torna com'era. Il documento resta, annullato,
  e nei Movimenti si leggono tutte e due le righe: *Carico · 2026/100003* e
  *Annullamento del carico · 2026/100003*. Un carico la cui merce è già
  stata venduta non si annulla se la giacenza andrebbe sotto zero.
- **Duplica** crea una bozza nuova con le stesse righe, da correggere e
  confermare. È anche il modo veloce per rifare un carico che arriva uguale
  ogni mese.

Una **bozza** invece si modifica quante volte vuoi e si elimina dai tre
puntini: non ha ancora mosso niente.

## Il primo carico

Appena installato il gestionale, i pezzi che hai si caricano con un
**carico**: uno per sede, con la nota «Giacenza di partenza». Con il
lettore fai prima: passi gli articoli scaffale per scaffale, e se un pezzo
ha già la sua riga il numero sale da solo.

## Da telefono

La bozza funziona anche sul telefono: le righe si leggono una sotto l'altra
e la casella **Cerca o scansiona** prende i codici di un lettore Bluetooth.
```

- [ ] **Passo 2: il sommario**

In `docs/user/SUMMARY.md`, sotto `## Magazzino`, l'elenco diventa:

```markdown
* [Giacenze e rettifiche](magazzino-giacenze.md)
* [Carichi e scarichi](magazzino-carichi-scarichi.md)
* [Movimenti](magazzino-movimenti.md)
* [Avvisi di scorta minima](magazzino-avvisi.md)
```

- [ ] **Passo 3: *Giacenze e rettifiche***

In `docs/user/magazzino-giacenze.md` la sezione `## Il primo carico` diventa:

```markdown
## Il primo carico

I pezzi che hai già si caricano con un [carico](magazzino-carichi-scarichi.md#il-primo-carico),
da **Magazzino → Carichi**: una riga per versione, anche con il lettore di
codici a barre. La colonna **Giacenza** della scheda dell'articolo resta
comoda per un articolo nuovo, quando crei le opzioni (una riga appena nata
parte con un movimento di *Giacenza iniziale*); per una versione sola che
esiste già c'è la [rettifica](#rettificare-una-riga-sola) con la causale
**Giacenza iniziale**.
```

Nella sezione `## Rettificare una riga sola`, dopo il paragrafo che comincia con «È la strada da fare quando la causale **non** è l'inventario», aggiungi:

```markdown
Per la merce che arriva o che esce in più righe insieme — una consegna, un
cartone di pezzi rotti — c'è un [carico o uno scarico](magazzino-carichi-scarichi.md):
un documento solo, con il numero, invece di tante rettifiche.
```

- [ ] **Passo 4: *Movimenti***

In `docs/user/magazzino-movimenti.md` la riga **Tipo** della tabella diventa:

```markdown
| **Tipo** | rettifica, vendita, reso, carico… Per le vendite e i resi dice anche il **documento**: *Vendita · 2026/0012*, cliccabile, apre l'ordine. Per carichi e scarichi il documento è nel testo, *Carico · 2026/100003*; gli storni di un documento annullato si leggono *Annullamento del carico · 2026/100003* |
```

e dopo la sezione `## Non si modifica e non si cancella` aggiungi:

```markdown
## Dal movimento al documento

I tre puntini in fondo alla riga hanno, secondo il movimento, **Visualizza
carico** o **Visualizza scarico** (anche sugli storni: aprono il documento
annullato), **Visualizza ordine** per le vendite e **Apri la versione**.
```

- [ ] **Passo 5: *Funzionalità***

In `config/features.php`, `purchasing` → `description`:

```php
        'description' => 'Fornitori e costi d\'acquisto nei prodotti e nei carichi, valore del magazzino.',
```

In `docs/user/funzionalita.md`, sotto **Acquisti**, il paragrafo che comincia con «Documenti di carico e valore del magazzino arriveranno dopo» diventa:

```markdown
I [carichi](magazzino-carichi-scarichi.md) non chiedono Acquisti: ci sono
sempre. Fornitore e costi sui carichi, e il valore del magazzino, arriveranno
dopo e partiranno da questi costi. Spegnendola, fornitori e costi spariscono
dalle pagine ma restano salvati: un fornitore che ha dei costi, per esempio,
non si elimina lo stesso.
```

Poi rigenera la tabella:

```bash
cd $S && php forge gestionale:features-doc && cd $W/gestionale && git diff --stat docs/user/funzionalita.md config/features.php
```

Expected: `docs/user/funzionalita.md` cambia solo nella riga di *Acquisti* della tabella e nel paragrafo del passo; `config/features.php` di una riga.

- [ ] **Passo 6: la guida *Magazzino* dello sviluppatore**

In `docs/dev/concetti/magazzino.md`, nella tabella di `## Le pagine`, dopo la riga *Rettifica*:

```markdown
| Carichi | `StockReceiptResource` | elenco, bozza e scheda in sola lettura dei carichi (`app/gestionale/carichi`) |
| Scarichi | `StockIssueResource` | lo stesso per gli scarichi (`app/gestionale/scarichi`), con la causale |
| Azioni sul documento | `StockDocumentActionResource` | riceve *Annulla* e *Duplica* (`app/gestionale/documento-magazzino-azione`) |
```

e in fondo alla sezione, prima di `## I parametri`:

```markdown
**Carichi e scarichi.** Le due Resource dicono solo tipo, nomi e posto nel
menu (Giacenze 10 · Carichi 20 · Scarichi 30 · Movimenti 50); il resto sta in
`StockDocumentResource`, che il piano 6 userà per l'inventario. Le pagine non
scrivono mai sulle tabelle dei documenti: passa tutto da `StockDocuments`.

1. **La bozza non passa dallo store del core.** Il form posta alle rotte
   `store`/`update`, che il core vuole per disegnarlo, ma
   `mutateRequestValues()` non restituisce mai i valori: controlla il CSRF,
   dà tutto a `StockDocumentForm::submit()`, mette il messaggio e fa il
   redirect. Lasciare lavorare il core scriverebbe colonne grezze senza le
   righe e senza i controlli del servizio.
2. **Le righe viaggiano in un campo solo**, `lines`, in JSON: un campo per
   numero supererebbe `max_input_vars` con un documento lungo.
3. **Gli id delle righe cambiano a ogni salvataggio della bozza**: il
   servizio le riscrive tutte. Chi le collega da fuori usa
   `stock_document_id` e `product_id`, non l'id. E un validatore o un
   `required` aggiunto nei `dataSchema` di `StockDocument` o
   `StockDocumentItem` va accompagnato dal controllo di `success` sulle
   scritture del servizio, che oggi non lo legge.
4. **La ricerca delle versioni** è `StockVersionSearch`, dietro la rotta
   `api.gestionale.backend.stock.versions`
   (`/api/gestionale/backend/magazzino/versioni/`, con `q`, `code` o `ids`
   più `location`): al più 20 versioni, mai i multiprodotti, e per il lettore
   `scan()` dice `one`, `many` o `none`. `gst_products` ha l'indice su `ean`.
5. **Dai Movimenti al documento.** `StockMovementResource::select()` legge
   tipo, numero e indirizzo del documento nella stessa query dell'elenco
   (`document_type`, `document_number`, `document_url`); l'indirizzo lo
   compone `StockDocumentResource::viewUrlPattern()`. Un tipo senza pagine
   (l'inventario fino al piano 6) ha l'indirizzo vuoto e la voce ⋯ non c'è.
6. **Dati di prova.** `StockDocumentsDemo` (chiave
   `documenti-magazzino`) crea un carico e uno scarico confermati con i
   codici `stk_demo-carico` e `stk_demo-scarico`; `--fresh` li annulla e li
   toglie con righe, storia e movimenti. È registrato per ultimo, così si
   pulisce prima del catalogo.
```

Nella tabella di `## Le classi`, dopo la riga di `StockDocuments`:

```markdown
| `StockDocumentForm` | no | l'invio della bozza: *Salva bozza* e *Conferma*, con il messaggio e l'indirizzo dove tornare |
| `StockVersionSearch` | in parte | le versioni per «Cerca o scansiona»: `outcome()` è pura |
```

Nella sezione `## I documenti`, il paragrafo che comincia con «Nei Movimenti *Tipo* legge» diventa:

```markdown
Nei Movimenti *Tipo* legge «Scarico · 2026/100003» o «Annullamento del
carico · 2026/100003», e il filtro *Tipo* ha *Carico*, *Scarico* e
*Inventario* al posto del carico registrato. Il menu ⋯ porta al documento
con *Visualizza carico* o *Visualizza scarico*. Le pagine sono in
[Le pagine](#le-pagine).
```

- [ ] **Passo 7: *Codici, numerazione e log degli stati***

In `docs/dev/concetti/documenti.md`, in fondo alla sezione `## Numerazione dei documenti`, aggiungi:

```markdown
Il codice di un documento di magazzino (`stk_…`) nasce invece alla
creazione, come quello di ogni record: `StockDocuments::create()` accetta un
`code` dato nelle opzioni (lo usano i dati di prova per ritrovare i loro) e,
senza, ne fa uno nuovo.
```

- [ ] **Passo 8: il docblock di `typeCondition`**

In `src/Resources/Stock/StockMovementResource.php`, nel docblock di `typeCondition()`, prima di `@param`:

```php
     *
     * La condizione è per l'elenco (SSP), che la mette in una WHERE sua: non
     * va passata a `Model::find()`, dove la sottoquery rompe l'SQL.
```

- [ ] **Passo 9: CHANGELOG e TODO**

In `CHANGELOG.md`, in cima a `### Aggiunto` di `0.1.0`:

```markdown
- Carichi e scarichi, pagine: *Magazzino → Carichi* e *Magazzino → Scarichi*
  (`StockReceiptResource`, `StockIssueResource` su `StockDocumentResource`) con
  elenco, bozza con «Cerca o scansiona» e lettore di codici a barre, *Salva
  bozza* e *Conferma*, scheda in sola lettura con *Annulla* e *Duplica*
  (`StockDocumentActionResource`). Ricerca delle versioni `StockVersionSearch`
  dietro `api.gestionale.backend.stock.versions`, indice su `gst_products.ean`.
  Nei Movimenti *Visualizza carico* e *Visualizza scarico* nel menu ⋯, con il
  documento letto nella query dell'elenco. `gestionale:demo` crea un carico e
  uno scarico confermati (`StockDocumentsDemo`). `StockDocuments::create()`
  accetta un `code`; `StockDocumentRules::lines()` controlla il limite di righe
  prima di leggerle e rifiuta un `product_id` che non è un intero positivo;
  conferma e annullamento applicano i movimenti in ordine di versione;
  *Duplica* non blocca più il documento d'origine.
```

In `TODO.md`, sotto il piano 4, segna `[x]` i primi sette ritocchi minori rimandati dalla revisione del piano 4 (li chiudono i Task 1, 7 e questo); l'ottavo, «Manca una prova vera di concorrenza…», resta aperto fino al Passo 11 e, sulla riga «Piano 5 Carichi e scarichi, pagine», aggiungi in fondo: ` — eseguito sul ramo g3-carichi-scarichi (2026-10-1x), da unire`. La data è quella del giorno in cui il passo si fa.

- [ ] **Passo 10: la suite completa**

```bash
cd $S && php forge update --local && cd $W/gestionale && ps aux | grep "php .*Test.php" | grep -v grep; php tests/run.php > /tmp/suite-piano5.txt 2>&1; tail -20 /tmp/suite-piano5.txt
```

Expected: `forge update --local` senza errori; la suite finisce senza file falliti. Un file rosso si legge in `/tmp/suite-piano5.txt` e si sistema prima di andare avanti.

- [ ] **Passo 11: le prove nel browser**

Sul sito di prova (`https://ecommerce-carichi.test`, con il login dell'utente), dopo `cd $S && php forge gestionale:demo --fresh`:

1. **Carico con il lettore** (§5.4, prova 3). *Nuovo carico*, poi nella casella «Cerca o scansiona» si scrive l'EAN di una versione di prova e si preme Invio: la riga entra con 1 pezzo e la casella si svuota; di nuovo lo stesso codice e Invio: la riga passa a 2. Un codice inesistente dà «Nessuna versione con questo codice». *Conferma carico*, la domanda dice «2 pezzi in 1 righe…», e la scheda in sola lettura ha il numero.
2. **Scarico sotto zero** (prova 4). *Nuovo scarico*, causale *Danneggiato*, una versione con più pezzi di quanti ce ne sono: *Conferma scarico* si ferma con il nome della versione, e la bozza riaperta ha le righe e la causale scritte.
3. **Annullamento** (prova 6). Sul carico del punto 1, *Annulla*: nei Movimenti ci sono la riga *Carico · N* e quella *Annullamento del carico · N*, tutte e due con *Visualizza carico* nel menu ⋯, che apre il carico annullato.
4. **Da telefono** (prova 7). Con la finestra stretta (vista mobile, 375 px): la bozza si legge senza scorrere di lato, la casella e i bottoni si usano, le righe si aggiungono e si confermano.
5. **Doppio clic e filtro *Tipo*** (ultimo ritocco del piano 4). Su una bozza, doppio clic veloce su *Conferma carico*: un solo numero e un solo movimento per riga. Nei Movimenti il filtro *Tipo* = *Carico* mostra i carichi (con i loro storni) e nessuno scarico.

Expected: tutte e cinque come descritte. Un difetto si corregge con un test che lo riproduce prima della correzione; poi si segna `[x]` l'ultimo ritocco del piano 4 in `TODO.md`.

- [ ] **Passo 12: commit**

```bash
cd $W/gestionale && git add docs/user/magazzino-carichi-scarichi.md docs/user/SUMMARY.md docs/user/magazzino-giacenze.md docs/user/magazzino-movimenti.md docs/user/funzionalita.md config/features.php docs/dev/concetti/magazzino.md docs/dev/concetti/documenti.md src/Resources/Stock/StockMovementResource.php CHANGELOG.md TODO.md && git commit -F - <<'EOF'
Carichi e scarichi: guide, CHANGELOG e TODO

Guida nuova Carichi e scarichi; Giacenze, Movimenti e Funzionalità
aggiornate, con la descrizione di Acquisti in config/features.php; guide
dello sviluppatore Magazzino e Codici.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

Expected: un commit; `git status` pulito.

