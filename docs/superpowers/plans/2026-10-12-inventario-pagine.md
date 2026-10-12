# Inventario, pagine (G2b-bis piano 6) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Le pagine degli inventari (elenco con *Contate* e *Con differenza*, bozza con Attesi · Contati · Differenza e *Aggiungi versioni*, scheda, dati di prova, guida) sopra il servizio del piano 4: con questo piano G2b-bis è chiuso.

**Architecture:** Una terza Resource, `StockStocktakeResource`, sopra `StockDocumentResource` come carichi e scarichi. L'editor del piano 5 prende una modalità `stocktake` (`data-mode`): il JS mostra attesi e differenza e il totale «righe · contate · con differenza». Un servizio piccolo, `StockVersionScope`, dà all'API delle versioni le versioni di una categoria o di un marchio. Il servizio `StockDocuments` non cambia: salva gli attesi quando si salva il numero contato e alla conferma scrive contati − attesi.

**Tech Stack:** PHP 8.2, framework Wonder Image (`wonder-image/app`), modulo `wonder-image/gestionale`, MySQL; test con l'harness del modulo (`check()`, `summary()`); JS ES5 provato con node.

**Spec:** `docs/superpowers/specs/2026-09-23-giacenze-movimenti-documenti-design.md` (§4.1 attesi, §4.2 pagine, §5.1-5.5). Piani precedenti: `docs/superpowers/plans/2026-10-12-documenti-di-magazzino-dati-e-servizio.md` (piano 4, servizio) e `docs/superpowers/plans/2026-10-12-carichi-e-scarichi-pagine.md` (piano 5, pagine di carichi e scarichi).

## Global Constraints

- Permessi: `admin` e `administrator`, come carichi e scarichi; niente API pubbliche.
- Menu Magazzino: Giacenze 10 · Carichi 20 · Scarichi 30 · **Inventari 40** · Movimenti 50.
- I testi della spec si copiano alla lettera:
  - domanda di conferma: «{contate} righe contate, {differenze} con differenza: la giacenza di quelle {differenze} versioni si corregge. Dopo non si modifica più: si può solo annullare.»
  - senza differenze: «{contate} righe contate, nessuna differenza: la giacenza non cambia. Dopo non si modifica più: si può solo annullare.»
  - colonne dell'elenco: *Contate* e *Con differenza*; bottoni: *Salva bozza* e *Conferma inventario*.
- Un documento tiene al massimo 500 righe (`StockDocumentRules::MAX_LINES`); oltre, il messaggio dice quante sarebbero.
- Mai multiprodotti fra le righe: le ricerche passano da `StockVersionSearch::notBundle()`.
- Le righe viaggiano solo nel campo `lines`. `serialize()` non cambia: una riga non contata viaggia con `quantity: ''` e si salva NULL. NULL vuol dire «non contata»: non conta e non muove la giacenza. «0» è un numero contato valido.
- Le domande passano da `wi.confirm()` (con il ripiego su `window.confirm` che c'è già).
- Una query per pagina: `describe()` precarica le foto con `ProductPhotos::preload`.
- Le giacenze si scrivono solo con `StockDocuments`; mai `connection()->query` in `src/`.
- Form compatti: tooltip al posto dei testi d'aiuto.
- JS ES5 dentro l'IIFE `window.wiStockEditor` che c'è già; i colori si scrivono su `className`, perché nel DOM finto dei test `classList` non fa niente.
- Test: `WONDER_NO_MAIL=1` sempre. Prima di ogni test d'integrazione, `ps aux | grep "php .*Test.php" | grep -v grep` deve essere vuoto: il DB `ecommerce_site` è condiviso fra le sessioni.
- Commit in italiano, con il trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Niente push.
- Mai `composer` nel sito di prova; non toccare i symlink `vendor/wonder-image/*` del sito principale.

## Review Focus

1. **Una categoria con più di 500 versioni.** *Aggiungi versioni* non deve aggiungerne una parte né rompere la pagina: dice quante righe sarebbero e consiglia di contare una categoria alla volta. `StockVersionScope::find(..., $max)` dà solo gli id oltre il limite. Coperto nel Task 4 (test con `max = 1`) e nel Task 3 (node, con `data-max-lines` = 5).
2. **Cambio di sede dopo una conta.** Gli attesi passano alla giacenza della nuova sede. Tornando alla sede della bozza si rivedono gli attesi salvati. Coperto nel Task 3 (node).
3. **Una vendita fra il salvataggio e la conferma.** La conferma usa gli attesi salvati: il test c'è già, `tests/integrazione/StockDocumentsTest.php:385`. La pagina mostra quelli salvati finché numero e sede non cambiano. Coperto nel Task 2 (`expected` nelle righe) e nel Task 3 (node).
4. **Una riga non contata.** Si vede «—» e non entra né in *Contate* né in *Con differenza*. Con zero righe contate la pagina non fa domande e il servizio risponde `nothing_counted`. «0» è un numero valido. Coperto nel Task 2 (form) e nel Task 3 (node).
5. **Doppioni e nessuna differenza.** Una versione già in lista non si aggiunge due volte: lettore, ricerca e *Aggiungi versioni* sommano o saltano. Con zero differenze la domanda è quella «nessuna differenza». Coperto nel Task 3 (node) e nel Task 4.

---

## Preparazione

Worktree: `/Users/andreamarinoni/Developer/worktrees/g3-inventario` (il repo `gestionale`, senza sottocartella), ramo `g3-inventario`.

- [ ] **Vendor e test unitario di prova**

```bash
P=/Users/andreamarinoni/Developer/packages; W=/Users/andreamarinoni/Developer/worktrees/g3-inventario
cp -R $P/gestionale/vendor $W/ && ln -sfn $P/app $W/vendor/wonder-image/app
php $W/tests/StockDocumentRulesTest.php
```
Expected: tutti i check verdi.

- [ ] **Sito di prova `ecommerce-inventario.test`**

```bash
B=/Users/andreamarinoni/Developer/boilerplates/ecommerce-site; S=/Users/andreamarinoni/Developer/boilerplates/ecommerce-site-inventario; W=/Users/andreamarinoni/Developer/worktrees/g3-inventario
git -C $B worktree add --detach $S
for f in .env .htaccess assets/0.0/css/set-up assets/lib assets/upload/app bin demo handler robots.txt storage vendor custom/config/modules.php custom/config/modules shared/sync-data.json; do mkdir -p "$S/$(dirname $f)"; rsync -a "$B/$f" "$S/$(dirname $f)/"; done
rm $S/vendor/wonder-image/gestionale && ln -s $W $S/vendor/wonder-image/gestionale
sed -i '' 's#^APP_URL=.*#APP_URL=https://ecommerce-inventario.test#' $S/.env
cd $S && herd link ecommerce-inventario && herd secure ecommerce-inventario
```

- [ ] **Prova dell'integrazione**

```bash
ps aux | grep "php .*Test.php" | grep -v grep
cd /Users/andreamarinoni/Developer/worktrees/g3-inventario && WI_TEST_SITE=/Users/andreamarinoni/Developer/boilerplates/ecommerce-site-inventario WI_TEST_URL=https://ecommerce-inventario.test WONDER_NO_MAIL=1 php tests/integrazione/StockDocumentsTest.php
```
Expected: `ps` vuoto; tutti i check verdi.

**Come si lanciano i test, in tutto il piano.** `INT tests/integrazione/X.php` sta per:

```bash
cd /Users/andreamarinoni/Developer/worktrees/g3-inventario && WI_TEST_SITE=/Users/andreamarinoni/Developer/boilerplates/ecommerce-site-inventario WI_TEST_URL=https://ecommerce-inventario.test WONDER_NO_MAIL=1 php tests/integrazione/X.php
```

Prima va sempre il controllo `ps`. I test unitari si lanciano con `cd /Users/andreamarinoni/Developer/worktrees/g3-inventario && php tests/X.php`.

**Gli helper che ci sono già nei test.** Si usano così come sono:
- `tests/integrazione/supporto/compra.php`: `articoloConGiacenza(float $pezzi, string $sku): int`, `modelloDi(int $prodotto): int`, `multiprodottoDiProva('fixed', [['product_id' => $x, 'quantity' => 1]], []): int` (dà l'id del prodotto).
- In ogni file d'integrazione: `prova(callable $corpo): mixed`, che lancia il corpo in `Transaction::run` e poi annulla con `Annulla`.
- `StockDocumentListTest`: `articolo(float $pezzi = 10)` e `rigaElenco(int $id)`, che fa `SELECT t.*` più la `select()` della Resource sul documento.
- `StockDocumentFormTest`: `articolo`, `conta`, `movimenti(int $id)`, `messaggio(string $chiave)` e `post(array $righe, array $altro = [])`. `$righe` è una lista di `[id, quantità]`.
- `StockDocumentActionTest`: `articolo`, `caricoConfermato(int $articolo, string $pezzi, int $utente): array` (dà `[$id, …]`), `nomeUtente`, `messaggio` e le variabili `$utente`, `$altro`, `$oggi`, `$annulla`.

---

### Task 1: `StockStocktakeResource`, colonne dell'elenco, Movimenti e guida

**Files:**
- Create: `src/Resources/Stock/StockStocktakeResource.php`
- Modify: `src/Resources/Stock/StockDocumentResource.php` (`labelSchema()` ~57-69, `tableSchema()` ~99, `select()`, `classFor()`, `confirmQuestion()` ~414-423, metodo nuovo `confirmQuestionSame()`)
- Modify: `src/Resources/Stock/StockMovementResource.php:153-159`
- Create: `docs/user/magazzino-inventario.md`
- Modify: `docs/user/SUMMARY.md`
- Test: `tests/ConventionsTest.php`, `tests/StockMovementResourceTest.php:430-436`, `tests/integrazione/StockMovementBackendTest.php:251-259`, `tests/integrazione/StockDocumentListTest.php`, `tests/integrazione/StockDocumentEditorTest.php`, `tests/DocsPagesTest.php`

**Interfaces:**
- Consumes: `StockDocumentResource` (piano 5), `StockDocuments::create/confirm` (piano 4).
- Produces:
  - `StockStocktakeResource` con `type() === 'stocktake'`, `path() === 'app/gestionale/inventari'`, `confirmLabel() === 'Conferma inventario'`;
  - le colonne `counted_count` e `different_count` nella `select()` comune;
  - `StockDocumentResource::classFor('stocktake') === StockStocktakeResource::class`;
  - `StockStocktakeResource::confirmQuestion()` e `StockStocktakeResource::confirmQuestionSame(): string`.
  - La view (Task 3) usa le due domande; la scheda (Task 5) usa `viewUrl`.

- [ ] **Step 1: I test che falliscono**

`tests/ConventionsTest.php`: dopo la riga 60 (la lista delle Resource) aggiungi
```php
        'Wonder\\Plugin\\Gestionale\\Resources\\Stock\\StockStocktakeResource',
```

`tests/StockMovementResourceTest.php:430-436`: il check diventa
```php
check('l\'indirizzo calcolato copre carichi, scarichi e inventari', function () {
    $select = StockMovementResource::select();

    return str_contains($select, "WHEN 'receipt' THEN")
        && str_contains($select, "WHEN 'issue' THEN")
        && str_contains($select, "WHEN 'stocktake' THEN");
});
```

`tests/integrazione/StockMovementBackendTest.php:251-259`: il check «un inventario non ha ancora una pagina…» diventa
```php
check('un inventario porta alla sua scheda', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(5, 'TST-MOV-I'.substr((string) microtime(true), -6));
        $id = StockDocuments::create('stocktake', [], [['product_id' => $prodotto, 'quantity' => '4']]);
        StockDocuments::confirm($id);
        $riga = movimentiConDocumento($prodotto)[1] ?? [];

        return ($riga['document_type'] ?? '') === 'stocktake'
            && ($riga['document_url'] ?? '') === StockStocktakeResource::viewUrl($id);
    });
});
```
e fra gli `use` (righe 22-30) aggiungi `use Wonder\Plugin\Gestionale\Resources\Stock\StockStocktakeResource;`.

`tests/integrazione/StockDocumentListTest.php`:
- fra gli `use` (righe 14-21) aggiungi `use Wonder\Plugin\Gestionale\Resources\Stock\StockStocktakeResource;`;
- nel check `tipi` (righe 154-171) sostituisci `&& StockDocumentResource::classFor('stocktake') === null` con
```php
        && StockDocumentResource::classFor('stocktake') === StockStocktakeResource::class
        && StockStocktakeResource::type() === 'stocktake'
        && StockStocktakeResource::newLabel() === 'Nuovo inventario'
        && StockStocktakeResource::confirmLabel() === 'Conferma inventario'
        && StockDocumentResource::pageUrl(['id' => 9, 'type' => 'stocktake', 'status' => 'draft']) === StockStocktakeResource::editUrl(9)
```
- prima di `summary();` aggiungi
```php
check('inventari: righe, contate e con differenza nell\'elenco', function () {
    return prova(static function (): bool {
        $a = articolo(5);
        $b = articolo(5);
        $c = articolo();
        $id = StockDocuments::create('stocktake', [], [
            ['product_id' => $a, 'quantity' => '5'],
            ['product_id' => $b, 'quantity' => '3'],
            ['product_id' => $c, 'quantity' => ''],
        ]);
        $riga = rigaElenco($id);

        return (int) $riga['row_count'] === 3
            && (int) $riga['counted_count'] === 2
            && (int) $riga['different_count'] === 1;
    });
});

check('colonne: l\'inventario mostra contate e con differenza, il carico i pezzi', function () {
    $nomi = static function (string $resource): array {
        $out = [];

        foreach ($resource::tableSchema() as $column) {
            $out[] = (string) $column->name;
        }

        return $out;
    };
    $inventario = $nomi(StockStocktakeResource::class);
    $carico = $nomi(StockReceiptResource::class);

    return in_array('counted_count', $inventario, true)
        && in_array('different_count', $inventario, true)
        && !in_array('piece_count', $inventario, true)
        && in_array('piece_count', $carico, true)
        && !in_array('counted_count', $carico, true)
        && StockStocktakeResource::labelSchema()['counted_count'] === 'Contate'
        && StockStocktakeResource::labelSchema()['different_count'] === 'Con differenza';
});
```
Se `StockDocuments` non è fra gli `use` del file, aggiungilo.

`tests/integrazione/StockDocumentEditorTest.php`: fra gli `use` aggiungi `use Wonder\Plugin\Gestionale\Resources\Stock\StockStocktakeResource;`, e dopo il check `confirmQuestion` (righe 103-106) aggiungi
```php
check('confirmQuestion: le due domande dell\'inventario, con {contate} e {differenze}', fn () =>
    StockStocktakeResource::confirmQuestion() === '{contate} righe contate, {differenze} con differenza: la giacenza di quelle {differenze} versioni si corregge. Dopo non si modifica più: si può solo annullare.'
    && StockStocktakeResource::confirmQuestionSame() === '{contate} righe contate, nessuna differenza: la giacenza non cambia. Dopo non si modifica più: si può solo annullare.'
    && StockReceiptResource::confirmQuestionSame() === StockReceiptResource::confirmQuestion()
);
```

`tests/DocsPagesTest.php`: accanto al check della pagina dei carichi aggiungi
```php
check('la guida dell\'inventario è nel sommario', fn () =>
    in_array('magazzino/magazzino-inventario', $pagine('user'), true)
    && is_file(dirname(__DIR__).'/docs/user/magazzino-inventario.md')
);
```
Se il percorso dei file nel test è scritto in un altro modo, copia quello del check dei carichi.

- [ ] **Step 2: Lancia i test e guardali fallire**

Run: `php tests/ConventionsTest.php; php tests/StockMovementResourceTest.php; php tests/DocsPagesTest.php`, poi, dopo il controllo `ps`, `INT tests/integrazione/StockDocumentListTest.php`, `INT tests/integrazione/StockMovementBackendTest.php` e `INT tests/integrazione/StockDocumentEditorTest.php`.
Expected: FAIL. I motivi: «class StockStocktakeResource not found» (o il check di ConventionsTest rosso), `WHEN 'stocktake'` assente, la guida assente.

- [ ] **Step 3: La Resource**

`src/Resources/Stock/StockStocktakeResource.php`:
```php
<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

/** *Inventari*: la conta di una sede, che corregge la giacenza solo dove contati e attesi non tornano (G2b-bis 4.2). */
final class StockStocktakeResource extends StockDocumentResource
{
    public static string $docsPage = 'magazzino/magazzino-inventario';

    public static function type(): string
    {
        return 'stocktake';
    }

    public static function path(): string
    {
        return 'app/gestionale/inventari';
    }

    public static function icon(): string
    {
        return 'bi-clipboard-check';
    }

    public static function titleLabel(): string
    {
        return 'Inventari';
    }

    public static function singular(): string
    {
        return 'Inventario';
    }

    public static function newLabel(): string
    {
        return 'Nuovo inventario';
    }

    public static function confirmLabel(): string
    {
        return 'Conferma inventario';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'inventario',
            'plural_label' => 'inventari',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => "l'",
            'this' => 'questo',
        ];
    }

    protected static function navigationOrder(): int
    {
        return 40;
    }
}
```
Controlla come `StockReceiptResource` dichiara `$docsPage`. Se lo dichiara così, la riga resta; se usa un altro meccanismo, segui quello.

- [ ] **Step 4: `StockDocumentResource`**

- In `labelSchema()` (~57-69) aggiungi `'counted_count' => 'Contate',` e `'different_count' => 'Con differenza',`.
- In `tableSchema()` (~99), il ciclo che oggi aggiunge `row_count` e `piece_count` diventa
```php
        foreach (static::type() === 'stocktake' ? ['row_count', 'counted_count', 'different_count'] : ['row_count', 'piece_count'] as $key) {
```
  Il corpo resta quello di adesso.
- In `select()`, dopo l'espressione di `piece_count`, aggiungi
```php
            '(SELECT COUNT(*) FROM '.$items.' i WHERE i.`stock_document_id` = '.$doc.".`id` AND i.`deleted` = 'false' AND i.`quantity` IS NOT NULL) AS counted_count",
            '(SELECT COUNT(*) FROM '.$items.' i WHERE i.`stock_document_id` = '.$doc.".`id` AND i.`deleted` = 'false' AND i.`quantity` IS NOT NULL AND i.`expected_quantity` IS NOT NULL AND ROUND(i.`quantity` - i.`expected_quantity`, 3) <> 0) AS different_count",
```
  `$items` e `$doc` sono le variabili che l'espressione di `piece_count` usa già. Nel docblock di `select()` aggiungi: «`counted_count` e `different_count` per l'inventario: le righe con un numero contato e, fra queste, quelle dove contati e attesi non tornano».
- In `classFor()` aggiungi `'stocktake' => StockStocktakeResource::class,`.
- In testa a `confirmQuestion()` (~414-423):
```php
        if (static::type() === 'stocktake') {
            return '{contate} righe contate, {differenze} con differenza: la giacenza di quelle {differenze} versioni si corregge. Dopo non si modifica più: si può solo annullare.';
        }
```
- Subito dopo `confirmQuestion()`:
```php
    /** La domanda dell'inventario quando nessuna riga contata ha differenza (spec §4.2). */
    public static function confirmQuestionSame(): string
    {
        return static::type() === 'stocktake'
            ? '{contate} righe contate, nessuna differenza: la giacenza non cambia. Dopo non si modifica più: si può solo annullare.'
            : static::confirmQuestion();
    }
```

- [ ] **Step 5: Movimenti**

In `src/Resources/Stock/StockMovementResource.php:159` la mappa delle voci diventa
```php
                'label' => ['receipt' => 'Visualizza carico', 'issue' => 'Visualizza scarico', 'stocktake' => 'Visualizza inventario'],
```
Il commento alle righe 153-157 va riscritto: ora ogni tipo di documento ha la sua pagina e la voce ⋯ c'è sempre. Se l'indirizzo calcolato (`CASE … WHEN`) si costruisce da una lista scritta a mano e non da `classFor()`, aggiungi lì anche `stocktake`.

- [ ] **Step 6: La guida**

`docs/user/magazzino-inventario.md`, nello stile di `docs/user/magazzino-carichi-scarichi.md` (frasi brevi, grassetto per i bottoni, niente gergo):
```markdown
---
icon: clipboard-list
---

# Inventario

> **Inclusa.** Fa parte del gestionale, non c'è niente da attivare.

Si apre da **Magazzino → Inventari**. Un inventario è la conta di una sede: scrivi quanti pezzi trovi e il gestionale corregge la giacenza solo dove il numero non torna.

## L'elenco

Ogni riga è un inventario: numero, data, sede, stato, quante righe ha, quante sono **Contate** e quante **Con differenza**. Le bozze si riaprono e si modificano; gli inventari confermati si guardano soltanto.

## Fare un inventario

Premi **Nuovo inventario**, scegli la sede e aggiungi le versioni da contare. Nella colonna **Contati** scrivi i pezzi che trovi. Una riga lasciata vuota non è contata: la sua giacenza resta com'è. Zero è un numero vero: vuol dire che non ne hai trovato nessuno.

Premi **Salva bozza** quando vuoi: puoi riprendere la conta più tardi.

## Aggiungi versioni

Sopra le righe scegli una categoria (con le sue sottocategorie), un marchio o *Tutte le versioni* e premi **Aggiungi versioni**. Entrano le versioni che si vendono e quelle ferme che hanno ancora pezzi in quella sede; quelle già in lista non si ripetono. Le righe nuove partono vuote, da contare. Un inventario tiene al massimo 500 righe: se una categoria ne ha di più, contala a pezzi.

## Con il lettore

Con il lettore di codici a barre, scansiona un pezzo alla volta: ogni lettura aggiunge 1 ai **Contati** della sua versione. Se la versione non è in lista, entra con 1.

## Attesi e differenza

**Attesi** è la giacenza che il gestionale si aspetta nella sede; **Differenza** è contati meno attesi, in verde se ne hai trovati di più, in rosso se di meno. Gli attesi si fissano quando salvi il numero contato: se nel frattempo si vende qualcosa, la conferma tiene conto di quello che c'era quando hai contato.

## Confermare

Premi **Conferma inventario**: il gestionale ti dice quante righe hai contato e quante hanno una differenza, e corregge la giacenza solo di quelle. Senza nessuna riga contata non si conferma.

## Da telefono

Sul telefono le colonne Attesi e Differenza si nascondono: resta la conta, con il lettore o a mano. Il totale in fondo dice sempre quante righe sono contate e quante con differenza.

## Un inventario confermato

Dopo la conferma l'inventario non si modifica più: si può solo annullare, e l'annullamento rimette la giacenza com'era. La scheda mostra per ogni riga attesi, contati e differenza; da **Magazzino → Movimenti** ogni correzione porta al suo inventario.
```
In `docs/user/SUMMARY.md`, dopo `* [Carichi e scarichi](magazzino-carichi-scarichi.md)`, aggiungi `* [Inventario](magazzino-inventario.md)` con lo stesso rientro.

- [ ] **Step 7: Lancia i test e guardali passare**

Run: gli stessi dello Step 2.
Expected: PASS. Rilancia anche `php tests/StockDocumentResourceTest.php` se c'è, e `INT tests/integrazione/StockDocumentFormTest.php`: devono restare verdi.

- [ ] **Step 8: Commit**

```bash
cd /Users/andreamarinoni/Developer/worktrees/g3-inventario
git add src/Resources/Stock/StockStocktakeResource.php src/Resources/Stock/StockDocumentResource.php src/Resources/Stock/StockMovementResource.php docs/user/magazzino-inventario.md docs/user/SUMMARY.md tests/ConventionsTest.php tests/StockMovementResourceTest.php tests/DocsPagesTest.php tests/integrazione/StockMovementBackendTest.php tests/integrazione/StockDocumentListTest.php tests/integrazione/StockDocumentEditorTest.php
git commit -m "Inventari: Resource, colonne Contate e Con differenza, voce dei Movimenti e guida

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Le righe dell'editor portano gli attesi salvati

**Files:**
- Modify: `src/Support/Stock/StockDocumentForm.php:83-111` (`editorLines`)
- Test: `tests/integrazione/StockDocumentFormTest.php`

**Interfaces:**
- Consumes: `StockDocuments::items($id)`, che dà `list<{id, product_id, quantity: ?float, expected_quantity: ?float, position}>`.
- Produces: ogni riga di `StockDocumentForm::editorLines(...)['lines']` ha la chiave `expected: ?float`. Vale l'atteso salvato quando la riga è contata e l'atteso c'è; altrimenti `null`. La legge il JS del Task 3 (`version.expected`).

- [ ] **Step 1: I test**

In `tests/integrazione/StockDocumentFormTest.php`, prima di `summary();`. Usa `StockDocumentForm`, `StockDocuments` e `Locations`; aggiungi gli `use` che mancano.
```php
check('editorLines: l\'inventario porta gli attesi salvati, solo sulle righe contate', function () {
    return prova(static function (): bool {
        $a = articolo(5);
        $b = articolo(5);
        $inventario = StockDocuments::create('stocktake', [], [
            ['product_id' => $a, 'quantity' => '3'],
            ['product_id' => $b, 'quantity' => ''],
        ]);
        $carico = StockDocuments::create('receipt', [], [['product_id' => $a, 'quantity' => '2']]);
        $righe = array_column(StockDocumentForm::editorLines(StockDocuments::items($inventario), Locations::mainId())['lines'], null, 'id');
        $righeCarico = StockDocumentForm::editorLines(StockDocuments::items($carico), Locations::mainId())['lines'];

        return $righe[$a]['expected'] === 5.0
            && $righe[$a]['quantity'] === '3'
            && array_key_exists('expected', $righe[$b])
            && $righe[$b]['expected'] === null
            && $righeCarico[0]['expected'] === null;
    });
});

check('submit: inventario senza righe contate, bozza con una non contata, conferma', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        [$a, $b, $c] = [articolo(5), articolo(5), articolo(5)];
        $vuota = StockDocumentForm::submit('stocktake', 0, post([[$a, ''], [$b, '']], ['intent' => 'confirm']), $utente);
        $bozza = StockDocumentForm::submit('stocktake', 0, post([[$a, '5'], [$b, '3'], [$c, '']], ['intent' => 'save']), $utente);
        $righe = StockDocuments::items($bozza['id']);
        $fatto = StockDocumentForm::submit('stocktake', $bozza['id'], post([[$a, '5'], [$b, '3'], [$c, '']], ['intent' => 'confirm']), $utente);

        return $vuota['error'] === messaggio('stock_document.nothing_counted')
            && array_column($righe, 'expected_quantity') === [5.0, 5.0, null]
            && $fatto['error'] === ''
            && $fatto['number'] !== ''
            && movimenti($bozza['id']) === 1;
    });
});
```
Se nel file la variabile dell'utente non si chiama `$utente`, usa quella del file. Il secondo check è di caratterizzazione: passa già, perché il form e il servizio del piano 4 trattano l'inventario. Fissa il caso n.4 del Review Focus.

- [ ] **Step 2: Lancia il test e guarda fallire il primo**

Run: controllo `ps`, poi `INT tests/integrazione/StockDocumentFormTest.php`.
Expected: FAIL solo su «editorLines: l'inventario porta gli attesi salvati…» (chiave `expected` assente). Il check `submit` è già verde.

- [ ] **Step 3: Il codice**

In `editorLines` (`src/Support/Stock/StockDocumentForm.php:83-111`), dove oggi la riga si compone con `$version + ['quantity' => …]`:
```php
            $lines[] = $version + [
                'quantity' => self::quantityText($item['quantity']),
                'expected' => $item['quantity'] !== null && ($item['expected_quantity'] ?? null) !== null ? (float) $item['expected_quantity'] : null,
            ];
```
Nel docblock il `@param` delle righe diventa `list<array{product_id: int, quantity: ?float, expected_quantity?: ?float}>`.

- [ ] **Step 4: Lancia i test e guardali passare**

Run: controllo `ps`, poi `INT tests/integrazione/StockDocumentFormTest.php` e `INT tests/integrazione/StockDocumentEditorTest.php`.
Expected: PASS. Il check «dal JSON le righe tornano uguali» di EditorTest costruisce la riga a mano e non passa da `editorLines`: resta verde.

- [ ] **Step 5: Commit**

```bash
cd /Users/andreamarinoni/Developer/worktrees/g3-inventario
git add src/Support/Stock/StockDocumentForm.php tests/integrazione/StockDocumentFormTest.php
git commit -m "Inventario: le righe dell'editor portano gli attesi salvati

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: L'editor in modalità inventario

**Files:**
- Modify: `src/Support/Stock/StockDocumentEditor.php` (`render` da riga 22; JS di `script()`: dopo `totals` ~149, `editor(root)` ~195-206, `count()` ~323-337, `refresh()` ~339, `append()` ~358-450, `scanned` ~453, `picked` ~515, cambio di sede ~615-645, return ~672)
- Modify: `view/pages/stock-document-form.php`
- Test: `tests/integrazione/StockDocumentEditorTest.php`

**Interfaces:**
- Consumes:
  - dal Task 1, `StockStocktakeResource::confirmQuestion()` e `confirmQuestionSame()`;
  - dal Task 2, `expected` nelle righe;
  - dal Task 4, `StockVersionScope::options(): array<string, array<string, string>>` per la select. La chiave `''` è il gruppo senza titolo; le altre sono le etichette degli optgroup. Il Task 4 viene dopo, quindi qui la view si prova con `php -l` e il resto nel browser al Task 7. L'API `scope=` del Task 4 risponde `{ids: list<int>, versions: list<Version>}`.
- Produces:
  - `StockDocumentEditor::render(array $lines, string $searchUrl, string $type = 'receipt', array $scopes = []): string`;
  - `StockDocumentEditor::stocktakeTotals(int $rows, int $counted, int $different): string`;
  - in JS, `window.wiStockEditor.stocktakeTotals`;
  - `root.wiStockEditor.totals()`, che dà `{rows, pieces, counted, different}`.

- [ ] **Step 1: I test PHP**

In `tests/integrazione/StockDocumentEditorTest.php`, dopo il check `totals` (riga 28):
```php
check('stocktakeTotals: righe, contate e con differenza, singolare e plurale', fn () =>
    StockDocumentEditor::stocktakeTotals(2, 1, 0) === '2 righe · 1 contata · 0 con differenza'
    && StockDocumentEditor::stocktakeTotals(1, 0, 0) === '1 riga · 0 contate · 0 con differenza'
    && StockDocumentEditor::stocktakeTotals(4, 3, 3) === '4 righe · 3 contate · 3 con differenza'
);

check('render: l\'inventario ha la sua testata, la scelta delle versioni e il totale delle conte', function () {
    $righe = [
        ['id' => 1, 'label' => 'A', 'sku' => '', 'ean' => '', 'photo' => '', 'stock' => 4.0, 'inactive' => false, 'quantity' => '5', 'expected' => 5.0],
        ['id' => 2, 'label' => 'B', 'sku' => '', 'ean' => '', 'photo' => '', 'stock' => 3.0, 'inactive' => false, 'quantity' => '', 'expected' => null],
        ['id' => 3, 'label' => 'C', 'sku' => '', 'ean' => '', 'photo' => '', 'stock' => 3.0, 'inactive' => false, 'quantity' => '0', 'expected' => 3.0],
    ];
    $html = StockDocumentEditor::render($righe, '/x/', 'stocktake', [
        '' => ['all' => 'Tutte le versioni'],
        'Marchi' => ['brand:1' => 'Acme & figli'],
    ]);
    $carico = StockDocumentEditor::render([], '/x/');

    return str_contains($html, 'data-mode="stocktake"')
        && str_contains($html, '<th class="d-none d-md-table-cell text-end">Attesi</th>')
        && str_contains($html, '>Contati</th>')
        && str_contains($html, '<th class="d-none d-md-table-cell text-end">Differenza</th>')
        && str_contains($html, 'data-wi-stock-scope')
        && str_contains($html, '<option value="all">Tutte le versioni</option>')
        && str_contains($html, '<optgroup label="Marchi"><option value="brand:1">Acme &amp; figli</option></optgroup>')
        && str_contains($html, '>Aggiungi versioni</button>')
        && str_contains($html, '3 righe · 2 contate · 1 con differenza')
        && !str_contains($carico, 'data-mode=')
        && !str_contains($carico, 'data-wi-stock-scope')
        && str_contains($carico, '>Pezzi</th>');
});
```
Nel check node che c'è già (righe 69-101), il `console.log` passa anche `stocktake: window.wiStockEditor.stocktakeTotals(4, 3, 1)`. Il return aggiunge `&& $js['stocktake'] === StockDocumentEditor::stocktakeTotals(4, 3, 1)`.

- [ ] **Step 2: Il test node dell'inventario**

Prima di `summary();`:
```php
check('script: l\'inventario mostra attesi e differenza, cambia sede, aggiunge versioni (node)', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    if ($node === '') {
        echo "  (salto: node non c'è in PATH)\n";

        return true;
    }

    $harness = <<<'JS'
function El(tag) { this.tagName = tag; this.children = []; this.attrs = {}; this.listeners = {}; this.value = ''; this.textContent = ''; this.className = ''; this.style = {}; this.parentNode = null; this.firstChild = null; this.disabled = false; this.classList = {add: function () {}, remove: function () {}}; }
El.prototype.appendChild = function (c) { c.parentNode = this; this.children.push(c); this.firstChild = this.children[0]; return c; };
El.prototype.removeChild = function (c) { this.children.splice(this.children.indexOf(c), 1); this.firstChild = this.children[0] || null; return c; };
El.prototype.insertBefore = function (c) { return this.appendChild(c); };
El.prototype.setAttribute = function (k, v) { this.attrs[k] = String(v); };
El.prototype.getAttribute = function (k) { return k in this.attrs ? this.attrs[k] : null; };
El.prototype.addEventListener = function (t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); };
El.prototype.fire = function (t, e) { (this.listeners[t] || []).forEach(function (f) { f(e); }); };
El.prototype.focus = function () {};
El.prototype.select = function () {};
El.prototype.contains = function () { return true; };
El.prototype.closest = function () { return null; };
var parts = {search: new El('input'), results: new El('div'), message: new El('div'), rows: new El('tbody'), empty: new El('div'), totals: new El('div'), lines: new El('script'), scope: new El('select'), add: new El('button')};
parts.lines.textContent = JSON.stringify([{id: 1, label: 'A', stock: 4, expected: 5, quantity: '5'}, {id: 2, label: 'B', stock: 3, expected: null, quantity: ''}]);
var place = new El('select');
place.value = '1';
var form = {querySelector: function () { return place; }};
var root = new El('div');
root.setAttribute('data-search-url', '/cerca/');
root.setAttribute('data-mode', 'stocktake');
root.setAttribute('data-max-lines', '5');
root.querySelector = function (sel) { return parts[sel.replace('[data-wi-stock-', '').replace(']', '')]; };
root.closest = function () { return form; };
var window = {};
var document = {createElement: function (t) { return new El(t); }, querySelectorAll: function () { return [root]; }, addEventListener: function () {}};
var stock = {'1': {1: 4, 2: 3, 3: 7, 4: 0, 5: 1, 6: 2}, '2': {1: 10, 2: 0, 3: 0, 4: 0, 5: 0, 6: 0}};
var scopes = {'brand:1': [3, 4, 5], 'brand:2': [3, 4, 5, 6]};
window.fetch = function (url) {
    var at = (/location=([^&]*)/.exec(url) || [])[1] || '1';
    var arg = function (k) { var m = new RegExp(k + '=([^&]*)').exec(url); return m ? decodeURIComponent(m[1]) : null; };
    var describe = function (id) { return {id: id, label: 'V' + id, stock: stock[at][id]}; };
    var body;
    if (arg('scope') !== null) {
        body = {ids: scopes[arg('scope')], versions: scopes[arg('scope')].map(describe)};
    } else if (arg('ids') !== null) {
        body = {versions: arg('ids').split(',').map(Number).map(describe)};
    } else if (arg('code') !== null) {
        body = {result: 'one', versions: [describe(Number(arg('code')))]};
    } else {
        body = {versions: [describe(4)]};
    }
    return Promise.resolve({ok: true, json: function () { return body; }});
};
function row(i) { var tr = parts.rows.children[i]; var input = tr.children[2].children[0]; return [input.getAttribute('aria-label'), tr.children[1].textContent, input.value, tr.children[3].textContent, (/text-(success|danger|body-secondary)/.exec(tr.children[3].className) || [])[1]]; }
function type(i, v) { var input = parts.rows.children[i].children[2].children[0]; input.value = v; input.fire('input', {}); }
function scan(code) { parts.search.value = code; parts.search.fire('keydown', {key: 'Enter', preventDefault: function () {}}); }
function later(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
var out = {};
JS;
    $run = <<<'JS'
Promise.resolve().then(function () {
    out.start = [row(0), row(1), parts.totals.textContent];
    type(1, '0'); out.zero = [row(1), parts.totals.textContent];
    type(0, '6'); out.more = row(0);
    type(0, '5'); out.back = row(0);
    type(1, ''); out.empty = row(1);
    type(1, 'abc'); out.text = row(1);
    type(1, '3');
    scan('3'); return later(20);
}).then(function () {
    scan('3'); return later(20);
}).then(function () {
    out.scan = row(2);
    parts.search.value = 'vers'; parts.search.fire('input', {}); return later(400);
}).then(function () {
    parts.results.children[0].fire('click', {preventDefault: function () {}}); return later(20);
}).then(function () {
    out.picked = row(3);
    place.value = '2'; place.fire('change', {}); return later(20);
}).then(function () {
    out.place2 = [row(0), row(1), row(2), row(3), parts.totals.textContent];
    place.value = '1'; place.fire('change', {}); return later(20);
}).then(function () {
    out.place1 = [row(0), row(2), parts.totals.textContent];
    parts.scope.value = 'brand:1'; parts.add.fire('click', {}); return later(20);
}).then(function () {
    out.add1 = [parts.rows.children.length, parts.message.textContent];
    parts.add.fire('click', {}); return later(20);
}).then(function () {
    out.add2 = parts.message.textContent;
    parts.scope.value = 'brand:2'; parts.add.fire('click', {}); return later(20);
}).then(function () {
    out.add3 = [parts.rows.children.length, parts.message.textContent, parts.add.disabled];
    out.lines = JSON.parse(root.wiStockEditor.serialize()).map(function (l) { return l.quantity; });
    console.log(JSON.stringify(out));
});
JS;
    $base = (string) tempnam(sys_get_temp_dir(), 'wi-stock');
    $file = $base.'.js';
    file_put_contents($file, $harness."\n".StockDocumentEditor::script()."\n".$run);
    $out = (string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file).' 2>&1');
    $js = json_decode($out, true);
    @unlink($file);
    @unlink($base);

    if (!is_array($js)) {
        echo '  node: '.$out."\n";

        return false;
    }

    $atteso = [
        'start' => [['Contati di A', '5', '5', '0', 'body-secondary'], ['Contati di B', '3', '', '—', 'body-secondary'], '2 righe · 1 contata · 0 con differenza'],
        'zero' => [['Contati di B', '3', '0', '−3', 'danger'], '2 righe · 2 contate · 1 con differenza'],
        'more' => ['Contati di A', '4', '6', '+2', 'success'],
        'back' => ['Contati di A', '5', '5', '0', 'body-secondary'],
        'empty' => ['Contati di B', '3', '', '—', 'body-secondary'],
        'text' => ['Contati di B', '3', 'abc', '—', 'body-secondary'],
        'scan' => ['Contati di V3', '7', '2', '−5', 'danger'],
        'picked' => ['Contati di V4', '0', '', '—', 'body-secondary'],
        'place2' => [
            ['Contati di A', '10', '5', '−5', 'danger'],
            ['Contati di B', '0', '3', '+3', 'success'],
            ['Contati di V3', '0', '2', '+2', 'success'],
            ['Contati di V4', '0', '', '—', 'body-secondary'],
            '4 righe · 3 contate · 3 con differenza',
        ],
        'place1' => [['Contati di A', '5', '5', '0', 'body-secondary'], ['Contati di V3', '7', '2', '−5', 'danger'], '4 righe · 3 contate · 1 con differenza'],
        'add1' => [5, 'Aggiunta 1 riga da contare.'],
        'add2' => 'Nessuna versione nuova da aggiungere.',
        'add3' => [5, 'Le righe sarebbero 6: un documento tiene al massimo 5 righe. Conta una categoria alla volta.', false],
        'lines' => ['5', '3', '2', '', ''],
    ];

    if ($js !== $atteso) {
        echo '  '.json_encode($js, JSON_UNESCAPED_UNICODE)."\n";
    }

    return $js === $atteso;
});
```

Come leggere le tracce:
- **B (riga 2)** non ha attesi salvati (`expected: null`), quindi usa la giacenza viva: 3 alla sede 1, 0 alla sede 2.
- **A** ha gli attesi salvati 5 con il numero salvato '5' alla sede 1. Mostra 5 finché numero e sede sono quelli; scrivendo '6' passa alla giacenza viva, 4.
- **Riga contata.** In `refresh()` conta come contata quando `parse(value) !== null && value >= 0`, quindi 'abc' non è contata.
- **Scansione '3' due volte.** Crea V3 con '1', poi sale a '2'.
- **Ricerca e clic.** V4 entra vuota, perché nell'inventario `picked` non scrive '1'.

Se la ricerca nel JS reale vuole altri campi nell'evento click o un'altra attesa, adegua l'harness, non le attese. Fai lo stesso se `say()` scrive il testo su un altro elemento.

- [ ] **Step 3: Lancia i test e guardali fallire**

Run: controllo `ps`, poi `INT tests/integrazione/StockDocumentEditorTest.php`.
Expected: FAIL su `stocktakeTotals` (metodo assente), su `render: l'inventario…` e sul check node dell'inventario. Tutti gli altri restano verdi.

- [ ] **Step 4: `render` in PHP**

In `src/Support/Stock/StockDocumentEditor.php`:
```php
    /**
     * @param list<array<string, mixed>> $lines Version + `quantity` in testo (+ `expected` nell'inventario, `StockDocumentForm::editorLines()`)
     * @param array<string, array<string, string>> $scopes le scelte di *Aggiungi versioni* (`StockVersionScope::options()`), solo nell'inventario
     */
    public static function render(array $lines, string $searchUrl, string $type = 'receipt', array $scopes = []): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $lines = array_values($lines);
        $stocktake = $type === 'stocktake';
        $pieces = 0.0;
        $counted = 0;
        $different = 0;

        foreach ($lines as $line) {
            $quantity = Stocktake::quantity($line['quantity'] ?? null);
            $pieces += $quantity !== null && $quantity > 0 ? $quantity : 0.0;

            if ($quantity !== null && $quantity >= 0) {
                $counted++;

                if (round($quantity - (float) ($line['expected'] ?? $line['stock'] ?? 0), 3) != 0) {
                    $different++;
                }
            }
        }

        $scope = '';

        if ($stocktake && $scopes !== []) {
            $options = '';

            foreach ($scopes as $group => $choices) {
                $items = '';

                foreach ($choices as $value => $label) {
                    $items .= '<option value="'.$e((string) $value).'">'.$e((string) $label).'</option>';
                }

                $options .= $group === '' ? $items : '<optgroup label="'.$e((string) $group).'">'.$items.'</optgroup>';
            }

            $scope = '<div class="input-group mb-2">'
                .'<select class="form-select" data-wi-stock-scope aria-label="Versioni da aggiungere">'.$options.'</select>'
                .'<button type="button" class="btn btn-outline-dark" data-wi-stock-add>Aggiungi versioni</button>'
                .'</div>';
        }

        $head = $stocktake
            ? '<th>Articolo</th>'
                .'<th class="d-none d-md-table-cell text-end">Attesi</th>'
                .'<th class="text-end" style="width: 8rem">Contati</th>'
                .'<th class="d-none d-md-table-cell text-end">Differenza</th>'
                .'<th style="width: 3rem"><span class="visually-hidden">Togli</span></th>'
            : '<th>Articolo</th>'
                .'<th class="d-none d-md-table-cell text-end">Giacenza</th>'
                .'<th class="text-end" style="width: 8rem">Pezzi</th>'
                .'<th style="width: 3rem"><span class="visually-hidden">Togli</span></th>';
        $total = $stocktake ? self::stocktakeTotals(count($lines), $counted, $different) : self::totals(count($lines), $pieces);
```
Poi il return di adesso, con quattro differenze:
- dopo `data-wi-stock-editor` va `.($stocktake ? ' data-mode="stocktake"' : '')`;
- `$scope` va subito prima di `'<div class="position-relative">'`;
- `$head` va al posto delle quattro `<th>`;
- `$e($total)` va al posto di `$e(self::totals(count($lines), $pieces))`.

Dopo `totals()`:
```php
    /** «2 righe · 1 contata · 0 con differenza»: il totale dell'inventario. */
    public static function stocktakeTotals(int $rows, int $counted, int $different): string
    {
        return $rows.' '.($rows === 1 ? 'riga' : 'righe')
            .' · '.$counted.' '.($counted === 1 ? 'contata' : 'contate')
            .' · '.$different.' con differenza';
    }
```

- [ ] **Step 5: Il JS**

Tutto dentro `script()`, nell'IIFE che c'è già.

**1.** Dopo `function totals(...)` (~149):
```js
    function stocktakeTotals(rows, counted, different) {
        return rows + ' ' + (rows === 1 ? 'riga' : 'righe') + ' · ' + counted + ' ' + (counted === 1 ? 'contata' : 'contate') + ' · ' + different + ' con differenza';
    }
```
Il separatore va scritto come lo scrive `totals` nel JS: se lì è un `·` letterale, usa il letterale.

**2.** In `editor(root)`, dopo `var place = …` (~206):
```js
        var stocktake = root.getAttribute('data-mode') === 'stocktake';
        var scope = root.querySelector('[data-wi-stock-scope]');
        var add = root.querySelector('[data-wi-stock-add]');
        var origin = place ? place.value : '';
        var DIFF = 'd-none d-md-table-cell text-end text-nowrap';
```

**3.** Funzioni nuove dentro `editor(root)`, prima di `count()`:
```js
        // Gli attesi: quelli salvati finché il numero e la sede sono quelli della bozza, poi la giacenza di adesso.
        function expected(row) {
            var value = parse(row.input.value);

            if (row.remembered !== null && value !== null && value === row.saved && (!place || place.value === origin)) {
                return row.remembered;
            }

            return row.live;
        }

        function difference(row) {
            var value = parse(row.input.value);
            var text = '—';
            var tone = 'body-secondary';
            var diff;

            if (value !== null && value >= 0) {
                diff = Math.round((value - expected(row)) * 1000) / 1000;
                text = diff > 0 ? '+' + format(diff) : (diff < 0 ? '−' + format(-diff) : '0');
                tone = diff > 0 ? 'success' : (diff < 0 ? 'danger' : 'body-secondary');
            }

            row.diff.textContent = text;
            // className, non classList: il classList del DOM finto dei test non fa niente.
            row.diff.className = DIFF + ' text-' + tone;
        }

        // La giacenza della riga; nell'inventario gli attesi e la differenza.
        function paint(row) {
            if (stocktake) {
                row.stock.textContent = format(expected(row));
                difference(row);
            } else {
                row.stock.textContent = format(row.live);
            }
        }
```

**4.** `count()` (~323-337) conta anche `counted` e `different`. Nel ciclo sulle righe, dove oggi somma i pezzi:
```js
                if (stocktake && value !== null && value >= 0) {
                    counted++;

                    if (Math.round((value - expected(rows[i])) * 1000) !== 0) {
                        different++;
                    }
                }
```
`counted` e `different` partono da 0 accanto a `pieces`. Il return diventa `{rows: rows.length, pieces: pieces, counted: counted, different: different}`, con le chiavi che il return ha già.

**5.** In `refresh()` (~339) la riga del totale diventa
```js
            summary.textContent = stocktake ? stocktakeTotals(current.rows, current.counted, current.different) : totals(current.rows, current.pieces);
```
`summary` e `current` sono i nomi che la funzione usa già.

**6.** In `append(version, quantity)` (~358-450):
- l'oggetto della riga diventa
```js
            row = {id: Number(version.id), label: String(version.label || ''), live: Number(version.stock) || 0, remembered: version.expected == null ? null : Number(version.expected), saved: parse(quantity == null ? '' : String(quantity))};
```
  Le altre proprietà che l'oggetto ha già restano.
- L'aria-label della casella diventa `(stocktake ? 'Contati di ' : 'Pezzi di ') + row.label`.
- L'ascolto `input` della casella chiama `check(row); paint(row); refresh();`.
- La cella della giacenza diventa `row.stock = el('td', DIFF);`, senza testo: lo scrive `paint`.
- L'ordine delle celle è articolo, `row.stock`, casella; poi `if (stocktake) { row.diff = el('td', DIFF); tr.appendChild(row.diff); }`; poi il bottone Togli.
- In fondo, prima di `return row;`, va `check(row); paint(row); refresh();`, al posto della scrittura della giacenza e del refresh che ci sono già.
- Se oggi la casella prende il valore con `quantity` e questo può essere `null`, scrivi `input.value = quantity == null ? '' : String(quantity);`.

**7.** In `scanned` (~453), nel ramo in cui la riga c'è già, dopo aver sommato 1: `check(row); paint(row); refresh();`.

**8.** In `picked` (~515): `byId(version.id) || append(version, stocktake ? null : '1')`. Il commento sopra diventa: «Nell'inventario la riga nuova parte vuota: il numero lo scrive chi conta.»

**9.** Nel cambio di sede (~615-645), nel ciclo che aggiorna le righe: `row.live = Number(versions[j].stock) || 0; paint(row);`. Dopo il ciclo va `refresh();`, se non c'è già.

**10.** Il clic su *Aggiungi*, prima del parse delle righe iniziali:
```js
        if (scope && add) {
            add.addEventListener('click', function () {
                add.disabled = true;
                request('scope=' + encodeURIComponent(scope.value), function (data) {
                    var ids = data && Array.isArray(data.ids) ? data.ids : [];
                    var versions = list(data);
                    var fresh = 0;
                    var added = 0;
                    var i;

                    add.disabled = false;

                    for (i = 0; i < ids.length; i++) {
                        if (!byId(ids[i])) {
                            fresh++;
                        }
                    }

                    if (rows.length + fresh > maxLines) {
                        say('Le righe sarebbero ' + (rows.length + fresh) + ': un documento tiene al massimo ' + maxLines + ' righe. Conta una categoria alla volta.', 'error');

                        return;
                    }

                    for (i = 0; i < versions.length; i++) {
                        if (!byId(versions[i].id) && append(versions[i], null)) {
                            added++;
                        }
                    }

                    refresh();
                    say(added === 0 ? 'Nessuna versione nuova da aggiungere.' : (added === 1 ? 'Aggiunta 1 riga da contare.' : 'Aggiunte ' + added + ' righe da contare.'));
                }, function () {
                    add.disabled = false;
                    say(DOWN, 'error');
                });
            });
        }
```
`request(params, done, fail)` aggiunge già `&location=`. `say`, `list`, `byId`, `maxLines`, `rows` e `DOWN` sono i nomi che il JS usa già; se uno ha un altro nome, usa quello.

**11.** Il return finale (~672) diventa `{init: init, parse: parse, format: format, totals: totals, stocktakeTotals: stocktakeTotals}`.

- [ ] **Step 6: La view**

In `view/pages/stock-document-form.php`:
- `use Wonder\Plugin\Gestionale\Support\Stock\StockVersionScope;` va fra gli `use`.
- Il commento alle righe 9-14 comincia con «La bozza di carico, scarico o inventario (G2b-bis 4.2)».
- `$noteHelp` (righe 25-27) diventa
```php
$noteHelp = match ($resource::type()) {
    'issue' => 'Per esempio a chi è andata la merce o perché.',
    'stocktake' => 'Per esempio il reparto o lo scaffale contato.',
    default => 'Per esempio il numero della bolla o della fattura del fornitore.',
};
```
- La riga 64 diventa
```php
            <?=StockDocumentEditor::render((array) ($editor['lines'] ?? []), StockDocumentEditor::searchUrl(), $resource::type(), $resource::type() === 'stocktake' ? StockVersionScope::options() : [])?>
```
- Dopo la riga 84 (`var label = …`):
```js
    var stocktake = <?=$json($resource::type() === 'stocktake')?>;
    var same = <?=$json($resource::type() === 'stocktake' ? $resource::confirmQuestionSame() : '')?>;
```
- Nel click di conferma (righe 158-174):
```js
        // Senza righe, o nell'inventario senza righe contate, niente domanda: il servizio risponde con nothing_counted.
        if (count.rows === 0 || (stocktake && count.counted === 0)) {
            send();

            return;
        }

        ask({
            title: label,
            text: stocktake
                ? (count.different === 0 ? same : question).split('{contate}').join(String(count.counted)).split('{differenze}').join(String(count.different))
                : question.replace('{pezzi}', window.wiStockEditor.format(count.pieces)).replace('{righe}', String(count.rows)),
            ok: label,
            cancel: 'Torna alla bozza'
        }).then(function (ok) {
            if (ok) {
                send();
            }
        });
```
`StockVersionScope` nasce nel Task 4: fino ad allora la pagina dell'inventario non si apre nel browser, ma `php -l` passa. La view si prova nel browser al Task 7.

- [ ] **Step 7: Lancia i test e guardali passare**

Run: `php -l view/pages/stock-document-form.php`, poi controllo `ps` e `INT tests/integrazione/StockDocumentEditorTest.php`.
Expected: «No syntax errors»; tutti i check PASS, compresi il lettore veloce ('Pezzi di A', con la casella in `tr.children[2]`) e il confronto JS/PHP.

- [ ] **Step 8: Commit**

```bash
cd /Users/andreamarinoni/Developer/worktrees/g3-inventario
git add src/Support/Stock/StockDocumentEditor.php view/pages/stock-document-form.php tests/integrazione/StockDocumentEditorTest.php
git commit -m "Inventario: l'editor mostra attesi e differenza, cambia sede e aggiunge versioni

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: *Aggiungi versioni* (`StockVersionScope`) e foto in una query

**Files:**
- Create: `src/Support/Stock/StockVersionScope.php`
- Modify: `src/Support/Stock/StockVersionSearch.php` (`notBundle()` ~138 diventa public; `describe()` ~99 precarica le foto)
- Modify: `src/Support/Catalog/ProductPhotos.php` (metodo nuovo `preload`)
- Modify: `http/api/stock/versions.php`
- Test: `tests/integrazione/StockVersionScopeTest.php` (nuovo)

**Interfaces:**
- Consumes:
  - `StockVersionSearch::describe(list<int> $ids, int $locationId): array<int, Version>`;
  - `LevelsSql::quantityAt(int $location, string $productIdSql): string`;
  - `CategoryTree::descendants(array $rows, int $id): list<int>`;
  - `CategoryTree::options(array $rows, ?int $exclude = null): array<string, string>`. La chiave `''` vale «Nessuna, sta in cima»; le altre sono `(string) id => str_repeat('— ', depth).name`.
  - `Locations::mainId()` e `StockDocumentRules::MAX_LINES`.
- Produces:
  - `StockVersionScope::options(): array<string, array<string, string>>`, con i gruppi `''` (`all`), `Categorie` (`category:N`) e `Marchi` (`brand:N`);
  - `StockVersionScope::find(string $scope, int $locationId, int $max = StockDocumentRules::MAX_LINES): array{ids: list<int>, versions: list<array<string, mixed>>}`;
  - `ProductPhotos::preload(list<int> $modelIds): void`;
  - `StockVersionSearch::notBundle()` public;
  - l'API `GET …/versioni/?scope=…&location=…` → `{ids, versions}`, che il JS del Task 3 legge.

- [ ] **Step 1: Il test**

`tests/integrazione/StockVersionScopeTest.php`:
```php
<?php
/** php tests/integrazione/StockVersionScopeTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductPhotos;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\StockVersionScope;
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

    return $esito;
}

function categoriaDiProva(string $nome, int $padre = 0): int
{
    $riga = Category::create([
        'code' => Code::make(Category::class, Codes::CATEGORY),
        'name' => $nome,
        'slug' => Slug::unique($nome, Category::class),
        'parent_id' => $padre,
        'position' => 900,
        'visible' => 'true',
    ]);

    return (int) ($riga->insert_id ?? 0);
}

function marchioDiProva(string $nome): int
{
    $riga = Brand::create([
        'code' => Code::make(Brand::class, Codes::BRAND),
        'name' => $nome,
        'slug' => Slug::unique($nome, Brand::class),
        'position' => 1,
    ]);

    return (int) ($riga->insert_id ?? 0);
}

function inCategoria(int $prodotto, int $categoria): void
{
    ProductModelCategory::create(['product_model_id' => modelloDi($prodotto), 'category_id' => $categoria, 'is_main' => 'true', 'position' => 1]);
}

function sku(string $prefisso): string
{
    return $prefisso.'-'.strtoupper(substr(uniqid(), -6));
}

check('category: la categoria e le sue sottocategorie, mai i multiprodotti, le ferme solo con pezzi', function () {
    return prova(static function (): bool {
        $padre = categoriaDiProva('Prova padre '.uniqid());
        $figlia = categoriaDiProva('Prova figlia '.uniqid(), $padre);
        $a = articoloConGiacenza(3, sku('SC-A'));
        $b = articoloConGiacenza(0, sku('SC-B'));
        $fermaConPezzi = articoloConGiacenza(2, sku('SC-F'));
        $fermaVuota = articoloConGiacenza(0, sku('SC-V'));
        $fuori = articoloConGiacenza(1, sku('SC-X'));
        $multi = multiprodottoDiProva('fixed', [['product_id' => $a, 'quantity' => 1]], []);
        inCategoria($a, $padre);
        inCategoria($b, $figlia);
        inCategoria($fermaConPezzi, $padre);
        inCategoria($fermaVuota, $padre);
        inCategoria($multi, $padre);
        Product::update(['active' => 'false'], $fermaConPezzi);
        Product::update(['active' => 'false'], $fermaVuota);
        $trovate = StockVersionScope::find('category:'.$padre, Locations::mainId());
        $ids = $trovate['ids'];
        sort($ids);
        $attese = [$a, $b, $fermaConPezzi];
        sort($attese);

        return $ids === $attese
            && count($trovate['versions']) === 3
            && !in_array($fuori, $ids, true)
            && in_array($a, array_map(static fn (array $v): int => (int) $v['id'], $trovate['versions']), true);
    });
});

check('brand: le versioni dei modelli del marchio', function () {
    return prova(static function (): bool {
        $marchio = marchioDiProva('Prova marchio '.uniqid());
        $a = articoloConGiacenza(1, sku('SB-A'));
        $fuori = articoloConGiacenza(1, sku('SB-X'));
        ProductModel::update(['brand_id' => $marchio], modelloDi($a));
        $trovate = StockVersionScope::find('brand:'.$marchio, Locations::mainId());

        return $trovate['ids'] === [$a] && !in_array($fuori, $trovate['ids'], true);
    });
});

check('scope non valido, categoria o marchio che non c\'è: niente', fn () =>
    StockVersionScope::find('', 0) === ['ids' => [], 'versions' => []]
    && StockVersionScope::find('category:abc', 0) === ['ids' => [], 'versions' => []]
    && StockVersionScope::find('category:999999999', 0) === ['ids' => [], 'versions' => []]
    && StockVersionScope::find('brand:999999999', 0) === ['ids' => [], 'versions' => []]
    && StockVersionScope::find('colour:1', 0) === ['ids' => [], 'versions' => []]
);

check('oltre il limite: gli id ci sono, le versioni no', function () {
    return prova(static function (): bool {
        $marchio = marchioDiProva('Prova limite '.uniqid());
        $a = articoloConGiacenza(1, sku('SL-A'));
        $b = articoloConGiacenza(1, sku('SL-B'));
        ProductModel::update(['brand_id' => $marchio], modelloDi($a));
        ProductModel::update(['brand_id' => $marchio], modelloDi($b));
        $trovate = StockVersionScope::find('brand:'.$marchio, Locations::mainId(), 1);
        $ids = $trovate['ids'];
        sort($ids);

        return $ids === [min($a, $b), max($a, $b)] && $trovate['versions'] === [];
    });
});

check('all: tutte le versioni, ordinate per nome', function () {
    return prova(static function (): bool {
        $a = articoloConGiacenza(1, sku('SA-A'));
        $trovate = StockVersionScope::find('all', Locations::mainId(), 100000);
        $nomi = array_map(static fn (array $v): string => (string) $v['label'], $trovate['versions']);
        $ordinati = $nomi;
        usort($ordinati, 'strnatcasecmp');

        return in_array($a, $trovate['ids'], true) && $nomi === $ordinati;
    });
});

check('options: tutte, categorie in albero e marchi, con i nomi in chiaro', function () {
    return prova(static function (): bool {
        $padre = categoriaDiProva('Prova & opzioni '.uniqid());
        $figlia = categoriaDiProva('Figlia '.uniqid(), $padre);
        $marchio = marchioDiProva('Marchio & co '.uniqid());
        $opzioni = StockVersionScope::options();

        return ($opzioni['']['all'] ?? '') === 'Tutte le versioni'
            && str_starts_with($opzioni['Categorie']['category:'.$padre] ?? '', 'Prova & opzioni')
            && str_starts_with($opzioni['Categorie']['category:'.$figlia] ?? '', '— Figlia')
            && str_starts_with($opzioni['Marchi']['brand:'.$marchio] ?? '', 'Marchio & co')
            && !isset($opzioni['Categorie']['category:']);
    });
});

check('preload: le foto di più modelli con una lettura, anche quelli senza foto', function () {
    return prova(static function (): bool {
        $a = articoloConGiacenza(1, sku('SP-A'));
        $modello = modelloDi($a);
        $ready = new ReflectionProperty(ProductPhotos::class, 'ready');
        $prima = $ready->getValue();
        $ready->setValue(null, []);
        ProductPhotos::preload([$modello, 0, $modello]);
        $dopo = $ready->getValue();
        $ready->setValue(null, $prima);

        return array_keys($dopo) === [$modello] && $dopo[$modello] === [];
    });
});

summary();
```
Se i file d'integrazione definiscono `prova` in un altro modo (per esempio con `Transaction::run` che restituisce il valore), copia la definizione da `tests/integrazione/StockDocumentListTest.php`. Copia anche i nomi giusti di `Code`, `Slug` e `Codes` da `tests/integrazione/DiscountCampaignBackendTest.php`. Se `Brand` vuole altri campi obbligatori, aggiungili come lì. Se `$ready` non è statica, adegua la Reflection.

- [ ] **Step 2: Lancia il test e guardalo fallire**

Run: controllo `ps`, poi `INT tests/integrazione/StockVersionScopeTest.php`.
Expected: FAIL, «Class StockVersionScope not found».

- [ ] **Step 3: `ProductPhotos::preload`**

In `src/Support/Catalog/ProductPhotos.php`, dopo `forModel`:
```php
    /**
     * Le foto di più articoli con una query sola: `describe()` ne chiede fino a 500.
     *
     * @param list<int> $modelIds
     */
    public static function preload(array $modelIds): void
    {
        $ids = array_values(array_diff(
            array_unique(array_filter(array_map('intval', $modelIds), static fn (int $id): bool => $id > 0)),
            array_keys(self::$ready)
        ));

        if ($ids === []) {
            return;
        }

        try {
            $rows = ProductImage::find('`product_model_id` IN ('.implode(', ', $ids).") AND `deleted` = 'false'", null, 'position', 'ASC');
        } catch (Throwable) {
            return;
        }

        $rows = !is_array($rows) || $rows === [] ? [] : (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array')));

        foreach ($ids as $id) {
            self::$ready[$id] = [];
        }

        foreach ($rows as $row) {
            self::$ready[(int) $row['product_model_id']][] = $row;
        }
    }
```

- [ ] **Step 4: `StockVersionSearch`**

- `private static function notBundle()` (~138) diventa `public static function notBundle()`. Il corpo non cambia; aggiungi al docblock «La usa anche `StockVersionScope`».
- In `describe()` (~99), subito dopo `$products = self::rows(...)`:
```php
        ProductPhotos::preload(array_map('intval', array_column($products, 'product_model_id')));
```
  Aggiungi `use Wonder\Plugin\Gestionale\Support\Catalog\ProductPhotos;`, se non c'è già.

- [ ] **Step 5: `StockVersionScope`**

`src/Support/Stock/StockVersionScope.php`:
```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Support\Catalog\CategoryTree;

/**
 * *Aggiungi versioni* dell'inventario (G2b-bis 4.2): tutte, una categoria
 * con le sue sottocategorie o un marchio. Entrano le versioni attive e
 * quelle ferme che nella sede hanno ancora pezzi; mai i multiprodotti.
 */
final class StockVersionScope
{
    /**
     * Le scelte della select: il gruppo senza titolo, poi Categorie e Marchi.
     *
     * @return array<string, array<string, string>>
     */
    public static function options(): array
    {
        $plain = static fn (string $text): string => html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $categories = [];
        $brands = [];

        foreach (CategoryTree::options(self::rows(static fn (): mixed => Category::find(['deleted' => 'false'], null, 'position', 'ASC'))) as $id => $label) {
            if ((string) $id !== '') {
                $categories['category:'.$id] = $plain((string) $label);
            }
        }

        foreach (self::rows(static fn (): mixed => Brand::find(['deleted' => 'false'], null, 'name', 'ASC')) as $brand) {
            $brands['brand:'.(int) $brand['id']] = $plain((string) ($brand['name'] ?? ''));
        }

        return array_filter([
            '' => ['all' => 'Tutte le versioni'],
            'Categorie' => $categories,
            'Marchi' => $brands,
        ]);
    }

    /**
     * Le versioni di uno scope nella sede, ordinate per nome. Oltre `$max`
     * solo gli id: la pagina dice quante righe sarebbero e non ne aggiunge.
     *
     * @return array{ids: list<int>, versions: list<array<string, mixed>>}
     */
    public static function find(string $scope, int $locationId, int $max = StockDocumentRules::MAX_LINES): array
    {
        $none = ['ids' => [], 'versions' => []];
        $location = $locationId > 0 ? $locationId : Locations::mainId();
        $condition = self::condition($scope);

        if ($condition === null) {
            return $none;
        }

        $products = '`'.Product::$table.'`';
        $where = $products.".`deleted` = 'false'"
            .' AND '.StockVersionSearch::notBundle()
            .' AND ('.$products.".`active` <> 'false' OR ".LevelsSql::quantityAt($location, $products.'.`id`').' > 0)'
            .($condition === '' ? '' : ' AND '.$condition);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], self::rows(static fn (): mixed => Product::find($where, null, 'id', 'ASC')));

        if ($ids === [] || count($ids) > $max) {
            return ['ids' => $ids, 'versions' => []];
        }

        $versions = array_values(StockVersionSearch::describe($ids, $location));
        usort($versions, static fn (array $a, array $b): int => strnatcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));

        return ['ids' => $ids, 'versions' => $versions];
    }

    /** La condizione SQL dello scope: '' per tutte, null se lo scope non vale o non c'è. */
    private static function condition(string $scope): ?string
    {
        if ($scope === 'all') {
            return '';
        }

        if (!preg_match('/^(category|brand):([1-9][0-9]*)$/', $scope, $match)) {
            return null;
        }

        $id = (int) $match[2];

        if ($match[1] === 'category') {
            $rows = self::rows(static fn (): mixed => Category::find(['deleted' => 'false'], null, 'position', 'ASC'));

            if (!in_array($id, array_map(static fn (array $row): int => (int) $row['id'], $rows), true)) {
                return null;
            }

            $ids = array_merge([$id], CategoryTree::descendants($rows, $id));

            return '`product_model_id` IN (SELECT c.`product_model_id` FROM `'.ProductModelCategory::$table.'` c'
                .' WHERE c.`category_id` IN ('.implode(', ', array_map('intval', $ids)).")  AND c.`deleted` = 'false')";
        }

        if (self::rows(static fn (): mixed => Brand::find(['id' => $id, 'deleted' => 'false'], 1)) === []) {
            return null;
        }

        return '`product_model_id` IN (SELECT m.`id` FROM `'.ProductModel::$table.'` m'
            ." WHERE m.`brand_id` IN (".$id.") AND m.`deleted` = 'false')";
    }

    /** @return list<array<string, mixed>> */
    private static function rows(callable $read): array
    {
        try {
            $rows = $read();
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
`LevelsSql` sta nello stesso namespace di `StockVersionSearch`; se sta altrove, aggiungi lo `use` che usa `StockVersionSearch`. Le stesse cose valgono per la firma di `quantityAt`: copiala da come la chiama `StockVersionSearch`. Se `notBundle()` scrive le colonne senza il prefisso della tabella, va bene: la query ha una tabella sola.

- [ ] **Step 6: L'API**

In `http/api/stock/versions.php`:
- aggiungi `use Wonder\Plugin\Gestionale\Support\Stock\StockVersionScope;`;
- nel commento in testa aggiungi «`scope`: *Aggiungi versioni* dell'inventario (`StockVersionScope`)»;
- dopo la lettura di `$ids` e prima di `if ($ids !== '')`:
```php
$scope = ApiRequest::string('scope');

if ($scope !== '') {
    ApiRequest::json(StockVersionScope::find($scope, $location));
}
```
`$location` è la variabile che il file legge già da `location`. Se `ApiRequest::json` non termina la richiesta, aggiungi `return;` come fanno i rami che ci sono già.

- [ ] **Step 7: Lancia i test e guardali passare**

Run: controllo `ps`, poi `INT tests/integrazione/StockVersionScopeTest.php`, `INT tests/integrazione/StockVersionSearchTest.php` (se c'è; altrimenti il test della ricerca delle versioni del piano 5) e `INT tests/integrazione/StockDocumentEditorTest.php`.
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
cd /Users/andreamarinoni/Developer/worktrees/g3-inventario
git add src/Support/Stock/StockVersionScope.php src/Support/Stock/StockVersionSearch.php src/Support/Catalog/ProductPhotos.php http/api/stock/versions.php tests/integrazione/StockVersionScopeTest.php
git commit -m "Inventario: Aggiungi versioni per categoria o marchio, foto lette in una query

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: La scheda dell'inventario

**Files:**
- Modify: `src/Support/Stock/StockDocumentSheet.php` (`for()`, `lines()`)
- Modify: `view/pages/stock-document-show.php:52-55, 59-69, 73`
- Test: `tests/integrazione/StockDocumentActionTest.php`

**Interfaces:**
- Consumes:
  - `StockDocuments::items($id)` con `expected_quantity`;
  - `StockDocumentEditor::stocktakeTotals` (Task 3);
  - `StockDocumentForm::quantityText(?float): string`.
- Produces:
  - nell'inventario ogni riga di `StockDocumentSheet::for($doc)['lines']` è `{label, sku, quantity, expected, difference, tone}`;
  - nei carichi e negli scarichi le righe restano esattamente `{label, sku, quantity}`.

- [ ] **Step 1: Il test**

In `tests/integrazione/StockDocumentActionTest.php`, dopo i check della scheda (~235):
```php
check('scheda: l\'inventario mostra attesi, contati e differenza', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        $a = articolo(5);
        $b = articolo(5);
        $c = articolo(5);
        $id = StockDocuments::create('stocktake', [], [
            ['product_id' => $a, 'quantity' => '7'],
            ['product_id' => $b, 'quantity' => '5'],
            ['product_id' => $c, 'quantity' => ''],
        ]);
        StockDocuments::confirm($id, ['user_id' => $utente]);
        $scheda = StockDocumentSheet::for(StockDocument::findById($id));
        $vista = static fn (array $riga): array => [$riga['expected'], $riga['quantity'], $riga['difference'], $riga['tone']];

        return array_map($vista, $scheda['lines']) === [
                ['5', '7', '+2', 'success'],
                ['5', '5', '0', 'body-secondary'],
                ['', '', '—', 'body-secondary'],
            ]
            && $scheda['total'] === '3 righe · 2 contate · 1 con differenza';
    });
});

check('scheda: un ammanco è rosso col meno tipografico', function () use ($utente) {
    return prova(static function () use ($utente): bool {
        $a = articolo(5);
        $id = StockDocuments::create('stocktake', [], [['product_id' => $a, 'quantity' => '4']]);
        StockDocuments::confirm($id, ['user_id' => $utente]);
        $riga = StockDocumentSheet::for(StockDocument::findById($id))['lines'][0];

        return $riga['difference'] === '−1' && $riga['tone'] === 'danger';
    });
});
```
Il check «scheda: righe con nome e SKU, totale…» (righe 200-216) resta com'è. Così controlla anche che il carico non abbia chiavi in più.

- [ ] **Step 2: Lancia il test e guardalo fallire**

Run: controllo `ps`, poi `INT tests/integrazione/StockDocumentActionTest.php`.
Expected: FAIL sui due check nuovi (chiave `expected` assente, totale in pezzi).

- [ ] **Step 3: `StockDocumentSheet`**

In `for()`:
```php
        $id = (int) ($document['id'] ?? 0);
        $items = $id > 0 ? StockDocuments::items($id) : [];
        $stocktake = (string) ($document['type'] ?? '') === 'stocktake';
        $pieces = 0.0;
        $counted = 0;
        $different = 0;

        foreach ($items as $item) {
            $pieces += (float) ($item['quantity'] ?? 0);

            if ($item['quantity'] !== null) {
                $counted++;

                if (round((float) $item['quantity'] - (float) ($item['expected_quantity'] ?? 0), 3) != 0) {
                    $different++;
                }
            }
        }
```
Nel return:
```php
            'lines' => self::lines($items, $stocktake),
            'total' => $stocktake
                ? StockDocumentEditor::stocktakeTotals(count($items), $counted, $different)
                : StockDocumentEditor::totals(count($items), $pieces),
```
Il `@return` di `for()` diventa `array{lines: list<array{label: string, sku: string, quantity: string, expected?: string, difference?: string, tone?: string}>, total: string, …}`.

In `lines()` la firma diventa `private static function lines(array $items, bool $stocktake = false): array`. Nella closure dell'`array_map` (aggiungi `$stocktake` allo `use`):
```php
            $line = [
                'label' => $product === null ? 'Versione eliminata' : self::text(ProductNames::full($product, $models)),
                'sku' => $product === null ? '' : self::text((string) ($product['sku'] ?? '')),
                'quantity' => StockDocumentForm::quantityText($item['quantity']),
            ];

            if (!$stocktake) {
                return $line;
            }

            $counted = $item['quantity'] !== null;
            $diff = $counted ? round((float) $item['quantity'] - (float) ($item['expected_quantity'] ?? 0), 3) : 0.0;

            return $line + [
                'expected' => $counted ? StockDocumentForm::quantityText((float) ($item['expected_quantity'] ?? 0)) : '',
                'difference' => !$counted ? '—' : ($diff > 0 ? '+'.StockDocumentForm::quantityText($diff) : ($diff < 0 ? '−'.StockDocumentForm::quantityText(-$diff) : '0')),
                'tone' => !$counted || $diff == 0 ? 'body-secondary' : ($diff > 0 ? 'success' : 'danger'),
            ];
```
Il docblock di `lines()` dice che nell'inventario la riga porta anche attesi, differenza e tono. Una riga non contata ha attesi vuoti e «—».

- [ ] **Step 4: La vista**

In `view/pages/stock-document-show.php`, dopo `$sheet = …` (o dove la vista legge il tipo) aggiungi `$stocktake = (string) ($document['type'] ?? '') === 'stocktake';`. Usa la variabile del documento che la vista usa già.

Il thead (righe 52-55):
```php
                    <tr>
                        <th>Articolo</th>
                        <?php if ($stocktake) { ?>
                        <th class="text-end">Attesi</th>
                        <th class="text-end">Contati</th>
                        <th class="text-end">Differenza</th>
                        <?php } else { ?>
                        <th class="text-end">Pezzi</th>
                        <?php } ?>
                    </tr>
```
Nel foreach (righe 59-69), al posto del solo td dei pezzi:
```php
                        <?php if ($stocktake) { ?>
                        <td class="text-end" style="font-variant-numeric: tabular-nums"><?=$e($line['expected'])?></td>
                        <td class="text-end" style="font-variant-numeric: tabular-nums"><?=$e($line['quantity'])?></td>
                        <td class="text-end text-<?=$e($line['tone'])?>" style="font-variant-numeric: tabular-nums"><?=$e($line['difference'])?></td>
                        <?php } else { ?>
                        <td class="text-end" style="font-variant-numeric: tabular-nums"><?=$e($line['quantity'])?></td>
                        <?php } ?>
```
Nel tfoot (riga 73): `colspan="<?=$stocktake ? 4 : 2?>"`.

- [ ] **Step 5: Lancia i test e guardali passare**

Run: `php -l view/pages/stock-document-show.php`, poi controllo `ps` e `INT tests/integrazione/StockDocumentActionTest.php`.
Expected: «No syntax errors»; tutti PASS, anche «scheda: righe con nome e SKU…» con `'1 riga · 2,5 pezzi'`.

- [ ] **Step 6: Commit**

```bash
cd /Users/andreamarinoni/Developer/worktrees/g3-inventario
git add src/Support/Stock/StockDocumentSheet.php view/pages/stock-document-show.php tests/integrazione/StockDocumentActionTest.php
git commit -m "Inventario: la scheda mostra attesi, contati e differenza

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Un inventario in bozza fra i dati di prova

**Files:**
- Modify: `src/Seeding/StockDocumentsDemo.php`
- Test: `tests/integrazione/StockDocumentsDemoTest.php`

**Interfaces:**
- Consumes: `StockDocuments::create('stocktake', …, ['code' => …])`, `DemoCode::forModel(StockDocument::class, 'inventario')`.
- Produces: `StockDocumentsDemo::create()` crea 3 documenti; `clear()` li toglie tutti e 3. La bozza non si annulla: si cancella e basta, come fa già `clear()` con gli stati diversi da `completed`.

- [ ] **Step 1: Il test**

In `tests/integrazione/StockDocumentsDemoTest.php`:
- La lettura di `$documenti` (riga 77) prende anche l'inventario:
```php
        $codici = array_map(static fn (string $ref): string => "'".DemoCode::forModel(StockDocument::class, $ref)."'", ['carico', 'scarico', 'inventario']);
        $documenti = $righe(StockDocument::class, 'code IN ('.implode(', ', $codici).')');
```
- Dopo `$esito['documenti'] = …` va
```php
        $inventario = array_values(array_filter($documenti, static fn (array $d): bool => (string) $d['type'] === 'stocktake'));
        $esito['righe_inventario'] = $inventario === [] ? [] : array_column(StockDocuments::items((int) $inventario[0]['id']), 'quantity');
```
  e lo `use Wonder\Plugin\Gestionale\Support\Stock\StockDocuments;` se manca.
- Il check «create fa un carico e uno scarico…» (righe 132-140) diventa
```php
check('create fa un carico e uno scarico confermati e un inventario in bozza, e un secondo giro non ne duplica', function () use ($esito) {
    $documenti = $esito['documenti'] ?? [];
    $tipi = array_column($documenti, 'type');
    sort($tipi);
    $fatti = array_values(array_filter($documenti, static fn (array $d): bool => $d['type'] !== 'stocktake'));
    $bozze = array_values(array_filter($documenti, static fn (array $d): bool => $d['type'] === 'stocktake'));

    return ($esito['create'] ?? 0) === 3 && ($esito['secondo'] ?? -1) === 0
        && $tipi === ['issue', 'receipt', 'stocktake']
        && array_unique(array_column($fatti, 'status')) === ['completed']
        && !in_array('', array_column($fatti, 'number'), true)
        && count($bozze) === 1 && $bozze[0]['status'] === 'draft' && $bozze[0]['number'] === ''
        && count($esito['righe_inventario'] ?? []) === 2
        && in_array(null, $esito['righe_inventario'], true);
});
```
- Il check della pulizia (riga 159) passa da `=== 2` a `=== 3`; il titolo diventa «la pulizia toglie i tre documenti…».
- Il check della giacenza (`[5.0, 8.0]` e 3 movimenti) resta com'è: la bozza non muove niente.

- [ ] **Step 2: Lancia il test e guardalo fallire**

Run: controllo `ps`, poi `INT tests/integrazione/StockDocumentsDemoTest.php`.
Expected: FAIL su «create fa un carico e uno scarico confermati e un inventario in bozza…» (create 2) e sulla pulizia (2).

- [ ] **Step 3: Il codice**

In `src/Seeding/StockDocumentsDemo.php`:
- Il docblock della classe comincia così: «Un carico e uno scarico di prova, confermati, e un inventario in bozza sugli articoli di `CatalogDemo`: così Carichi, Scarichi, Inventari e i Movimenti non partono vuoti.» Aggiungi poi la frase: «L'inventario resta in bozza, con una riga contata e una da contare: la pulizia lo toglie senza annullarlo.»
- In `register()` la descrizione diventa `'Documenti di magazzino: un carico e uno scarico confermati, un inventario in bozza'`.
- La nota diventa `'I documenti di prova usano gli articoli di prova: lancia il comando con il catalogo di prova.'`.
- Dopo il blocco dello scarico, prima di `return $created;`:
```php
        if (self::document('inventario') === []) {
            StockDocuments::create('stocktake', ['note' => 'Dati di prova'], [
                ['product_id' => $versions[0], 'quantity' => '7'],
                ['product_id' => $versions[1], 'quantity' => ''],
            ], ['code' => DemoCode::forModel(StockDocument::class, 'inventario')]);
            $created++;
        }
```

- [ ] **Step 4: Lancia i test e guardali passare**

Run: controllo `ps`, poi `INT tests/integrazione/StockDocumentsDemoTest.php`.
Expected: PASS, compresa la pulizia (`restano` 0, giacenza com'era).

- [ ] **Step 5: Commit**

```bash
cd /Users/andreamarinoni/Developer/worktrees/g3-inventario
git add src/Seeding/StockDocumentsDemo.php tests/integrazione/StockDocumentsDemoTest.php
git commit -m "Dati di prova: un inventario in bozza accanto al carico e allo scarico

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Chiusura di G2b-bis

**Files:**
- Modify: `docs/dev/concetti/magazzino.md` (righe ~80, ~106, ~168, ~224)
- Modify: `CHANGELOG.md`
- Modify: `TODO.md` (righe ~208, ~236 e la riga del piano 5)

- [ ] **Step 1: La documentazione per chi sviluppa**

In `docs/dev/concetti/magazzino.md` aggiorna i quattro punti:
- **~80, Resource:** ora sono tre, `StockReceiptResource`, `StockIssueResource` e `StockStocktakeResource`.
- **~106, colonne dell'elenco:** `counted_count` e `different_count` per l'inventario.
- **~168, editor:** la modalità `stocktake` (`data-mode`), gli attesi salvati finché numero e sede non cambiano, `StockVersionScope` e il parametro `scope` dell'API.
- **~224, Movimenti:** ogni tipo di documento ha la sua pagina.

Se una riga dice che l'inventario «non ha ancora pagine», riscrivila.

- [ ] **Step 2: CHANGELOG e TODO**

`CHANGELOG.md`, sotto «## 0.1.0 — non rilasciata / ### Aggiunto»:
```markdown
- Magazzino → Inventari: la conta di una sede con Attesi · Contati · Differenza, *Aggiungi versioni* per categoria o marchio, lettore di codici, scheda e un inventario in bozza fra i dati di prova (G2b-bis piano 6).
```
`TODO.md`:
- la riga del piano 6 (~236) si spunta;
- la riga di G2b-bis (~208) diventa chiusa;
- la riga del piano 5 dice «unito con #27».

- [ ] **Step 3: Il sito di prova**

Prima di `forge update --local`, confronta o esporta le funzionalità del sito di prova e avvisa: il comando sovrascrive quello che si è acceso dal backend.
```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site-inventario && php forge update --local
```
Expected: il menu Magazzino ha Inventari fra Scarichi e Movimenti.

- [ ] **Step 4: La suite completa**

```bash
ps aux | grep "php .*Test.php" | grep -v grep
cd /Users/andreamarinoni/Developer/worktrees/g3-inventario && WI_TEST_SITE=/Users/andreamarinoni/Developer/boilerplates/ecommerce-site-inventario WI_TEST_URL=https://ecommerce-inventario.test WONDER_NO_MAIL=1 php tests/run.php > /tmp/suite-piano6.txt 2>&1; tail -30 /tmp/suite-piano6.txt
```
Expected: `ps` vuoto; la suite intera verde.

- [ ] **Step 5: Le prove nel browser (spec §5.4 n.5 e n.7)**

Su `https://ecommerce-inventario.test`. Il login lo fa l'utente; navigazione e JS sono liberi.
1. Apri **Magazzino → Inventari → Nuovo inventario**: Sede · Nota, la select *Aggiungi versioni* con *Tutte le versioni*, Categorie e Marchi.
2. Aggiungi una categoria: le righe entrano vuote, il messaggio dice quante. Aggiungila di nuovo: «Nessuna versione nuova da aggiungere.»
3. Scrivi un numero uguale e uno diverso dalla giacenza: Differenza «0» e «+N»/«−N» colorata; il totale «N righe · 2 contate · 1 con differenza».
4. Salva la bozza, riaprila: gli attesi salvati sono gli stessi. Cambia sede: gli attesi diventano quelli della nuova sede; torna alla prima: quelli salvati.
5. Premi **Conferma inventario** senza righe contate: nessuna domanda, l'errore «Scrivi il numero contato di almeno una riga.».
6. Con righe contate: la domanda con {contate} e {differenze} riempiti (o quella «nessuna differenza»); conferma.
7. La scheda mostra Attesi · Contati · Differenza; Movimenti → la riga dell'inventario porta alla scheda (voce *Visualizza inventario*).
8. Da telefono (375 px): Attesi e Differenza nascosti, la conta col lettore funziona, il totale si vede.

Quello che non va si corregge con un test che fallisce prima, nel task che ha scritto il codice.

- [ ] **Step 6: Commit**

```bash
cd /Users/andreamarinoni/Developer/worktrees/g3-inventario
git add docs/dev/concetti/magazzino.md CHANGELOG.md TODO.md
git commit -m "G2b-bis chiuso: guida per chi sviluppa, CHANGELOG e TODO dell'inventario

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
