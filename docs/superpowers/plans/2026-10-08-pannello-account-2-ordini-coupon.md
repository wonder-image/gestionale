# Pannello account del core — piano 2: Ordini e Coupon

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ordini, dettaglio ordine e Coupon diventano sezioni dell'ecommerce
nel pannello account del core, con paginazione e controllo di proprietà.

**Architecture:** Il core aggiunge `AccountPagination` e il componente
`pagination`, che servono a ogni sezione a righe. L'ecommerce registra tre route
nel gruppo `/account/` con la sua `EcommerceAccountExtension`, aggiunge le voci
Ordini e Coupon al menu e le azioni a `EcommerceAccountController`. Due servizi
piccoli preparano i dati per le viste: `AccountOrder` per il dettaglio e
`AccountCoupons` per i coupon. Il gestionale estrae `Coupons::reserved()` da
`CustomerSheet::coupons()`, così backend e pannello contano gli usi allo stesso
modo.

**Tech Stack:**
- PHP 8.2 con il framework Wonder Image.
- Test PHP a script: `$check` nei test unitari del core; `check()` e
  `summary()` di `tests/harness.php` nei test d'integrazione dell'ecommerce e
  del gestionale.
- Browser: Playwright (`.cjs`) e il pannello Browser.

**Spec:** `packages/gestionale/docs/superpowers/specs/2026-10-08-pannello-account-design.md`
(§3.2, §3.3, §4.1, §4.5–4.7, §6–§8).

**Prerequisito:** il piano 1 (`2026-10-08-pannello-account-1-core.md`) è
completato nei worktree `pannello-account`. Questo piano usa gli stessi
worktree, lo stesso sito di prova e le stesse variabili:

```bash
W=/Users/andreamarinoni/Developer/worktrees/pannello-account
S=/Users/andreamarinoni/Developer/boilerplates/ecommerce-site-account
export WI_TEST_SITE=$S WI_TEST_URL=https://ecommerce-account.test
```

## Global Constraints

Valgono tutti i vincoli del piano 1. In più:

**Markup e stile**
- Righe con `.wi-row-table`, piede con `.wi-row-table__foot > span +
  ul.wi-pagination > li > a.wi-pagination__item`. Pagina attiva con
  `aria-current="page"`; frecce agli estremi con `aria-disabled="true"`.
- Stato vuoto: `.wi-empty-state > i.wi-empty-state__icon + p`.
- «Visualizza ›»:
  `Button::to($href, …)->outline()->variant('black')->size('sm')->arrow()->render()`.
- Ogni `type="submit"` ha `wi-input-submit`; nelle pagine di questo piano c'è
  solo «Esci» del menu.
- Colori ammessi: `var(--primary-color)`, `var(--primary-color-10)`, `#000`,
  `#fff`. Nessuno stile in linea salvo il token `--wi-row-table-columns`.

**URL e sicurezza**
- URL esatte di §4.1:
  - `account.orders` su `/account/ordini/`, con `?pagina=N`;
  - `account.orders.show` su `/account/ordini/{code}/`;
  - `account.coupons` su `/account/coupon/`, con `?pagina=N`.
- Un ordine si cerca per `code`, mai per id.
- Un ordine che non è `stage = order` della scheda del cliente dà 404.
  Il controllo è uguale per ordini di altri, carrelli, ordini degli ospiti
  (`customer_id = 0`) e codici inesistenti.
- Con la scheda del cliente mancante (`id` 0) non si cerca nulla: con
  `customer_id = 0` uscirebbero gli ordini degli ospiti.
- `Coupon` spento (`Gestionale::feature('coupons')` falso):
  - la pagina dà 404;
  - la voce del menu non c'è.

**Testi**
- Testi del negozio in `lang/{it,en}/ecommerce.json`, sotto
  `account.orders` e `account.coupons`, con le stesse chiavi nelle due lingue.
- Il testo della paginazione è del core, in `resources/lang/{it,en}/account.json`
  sotto `pagination`.
- Segnaposto nella forma `{{nome}}`.

**Modo di lavorare**
- `EcommerceAccountController` e `AccountController` non sono `final`: i test
  li estendono.
- Niente push, PR o merge senza la conferma esplicita dell'utente.
- Ogni commit finisce con `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Il login nel browser lo fa l'utente; mai password in chat.

## Review Focus

1. **`?pagina=` strano.** `0`, `-3`, `abc`, `2.7`, un array o una pagina oltre
   l'ultima non danno errori né tabelle vuote. La pagina si riporta tra 1 e
   l'ultima, e «Risultati da X a Y di Z» resta coerente. Si prova nei Task 1
   e 2.
2. **Ordini degli ospiti.** Un cliente senza scheda, o con scheda in conflitto,
   non vede gli ordini con `customer_id = 0`. Vede l'errore
   `account.errors.contact` e lo stato vuoto. Si prova nel Task 2.
3. **Dati spariti dopo l'ordine.** Il dettaglio si apre comunque, con
   etichette di ripiego, quando mancano:
   - il metodo di pagamento;
   - il metodo di spedizione;
   - la sede di ritiro (sede eliminata o non più punto di ritiro).

   Si prova nel Task 3.
4. **Nomi con HTML.** Nome della riga, personalizzazioni, codice coupon e
   indirizzi escono escapati. Si prova nel Task 3 con un nome
   `Tazza <b>blu</b>`.
5. **Coupon non usabili.** I coupon spenti, scaduti, programmati o eliminati
   non si mostrano. Gli utilizzi rilasciati e quelli di altri clienti non si
   contano. Si prova nel Task 4.

## Scostamenti dalla spec decisi in fase di piano

- **Coupon programmati.** La spec dice «attivi e non scaduti». Un coupon con
  `starts_at` futuro è attivo e non scaduto, ma il cliente non potrebbe ancora
  usarlo. Si mostrano solo i coupon con `Campaigns::status() === 'running'`.
- **Coupon esauriti.** Un coupon con tutti gli usi spesi (`3 / 3`) resta in
  elenco: la spec chiede di mostrare gli usi.
- **Valore «Credito».** Il coupon `store_credit` mostra solo «Credito», come
  nella spec, senza importo.
- **Paginazione del core.** Testo e componente sono del core
  (`account.pagination.*`, `frontend.account.pagination`), perché la tabella a
  righe è della lib e altri moduli la useranno.
- **Loghi dei pagamenti.** Il ciclo dei loghi di
  `CheckoutSummary::paymentDisplay()` diventa
  `CheckoutSummary::paymentIcons(array $keys)`, pubblico. Il checkout lo usa
  come prima.
- **Righe di testo.** Le righe d'ordine `type = text` mostrano solo il nome,
  senza quantità né prezzo.
- **Indirizzo di ritiro.** Arriva da `PickupPoints::all()`. Se la sede non è
  più un punto di ritiro, la scheda mostra solo «Ritiro in sede».
- **Ordini senza consegna.** Con `fulfillment_type = none` non c'è la scheda
  dell'indirizzo di consegna; resta la fatturazione.

## Mappa dei file

**Core (`$W/app`)**
- Nuovi:
  - `class/Auth/Frontend/AccountPagination.php`;
  - `app/view/components/frontend/account/pagination.php`;
  - `tests/account-pagination.php`.
- Da modificare: `resources/lang/{it,en}/account.json`, `CHANGELOG.md`,
  `docs/app/concetti/utenti/auth-frontend.md`.

**Gestionale (`$W/gestionale`)**
- Da modificare:
  - `src/Support/Promotions/Coupons.php` (nuovo `reserved()`);
  - `src/Support/Contacts/CustomerSheet.php` (`coupons()` lo usa);
  - `tests/integrazione/CustomerSheetBackendTest.php`;
  - `CHANGELOG.md`, `TODO.md`.

**Ecommerce (`$W/ecommerce`)**
- Nuovi:
  - `src/Frontend/Account/AccountOrder.php`, `AccountCoupons.php`;
  - `view/pages/account/{orders,order,coupons}.php`;
  - `tests/integrazione/AccountOrdersTest.php`.
- Da modificare:
  - `src/Frontend/Account/EcommerceAccountExtension.php`,
    `EcommerceAccountController.php`;
  - `src/Frontend/Checkout/CheckoutSummary.php` (`paymentIcons()`);
  - `lang/{it,en}/ecommerce.json`;
  - `tests/EcommerceTest.php` (route attese);
  - `CHANGELOG.md`, `TODO.md`.

---

### Task 1: Paginazione del core

Repo: `$W/app`.

**Files:**
- Create: `class/Auth/Frontend/AccountPagination.php`
- Create: `app/view/components/frontend/account/pagination.php`
- Create: `tests/account-pagination.php`
- Modify: `resources/lang/it/account.json`, `resources/lang/en/account.json`

**Interfaces:**
- Consumes: le classi CSS `.wi-row-table__foot`, `.wi-pagination` e
  `.wi-pagination__item` della lib (piano 1, Task 1); `View::component()`.
- Produces:
  - `AccountPagination::make(int $total, mixed $page, int $perPage = 10): array{page: int, pages: int, per_page: int, offset: int, from: int, to: int, total: int, limit: string}`;
  - `AccountPagination::requested(): mixed`, che legge `$_GET['pagina']`;
  - `AccountPagination::url(string $base, int $page): string`;
  - `AccountPagination::window(array $pagination): list<int>`;
  - il componente `View::component('frontend.account.pagination', ['pagination' => array, 'base_url' => string])`;
  - le chiavi `account.pagination.summary`, `label`, `previous` e `next`.

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/account-pagination.php`:

```php
<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Wonder\Auth\Frontend\AccountPagination;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$empty = AccountPagination::make(0, 1);
$check($empty['page'] === 1 && $empty['pages'] === 1 && $empty['from'] === 0 && $empty['to'] === 0 && $empty['limit'] === '0, 10', 'Senza righe la pagina è 1 di 1, da 0 a 0.');

$second = AccountPagination::make(12, 2);
$check($second['offset'] === 10 && $second['from'] === 11 && $second['to'] === 12 && $second['limit'] === '10, 10', 'La seconda pagina di 12 va da 11 a 12.');

$check(AccountPagination::make(12, 99)['page'] === 2, 'Una pagina oltre l\'ultima non torna all\'ultima.');
foreach (['0', '-3', 'abc', '', ['x'], null] as $strange) {
    $check(AccountPagination::make(12, $strange)['page'] === 1, 'Una pagina strana ('.json_encode($strange).') non torna alla prima.');
}
$check(AccountPagination::make(25, '2.7')['page'] === 2, 'Una pagina decimale non si tronca.');
$check(AccountPagination::make(30, 3, 10)['to'] === 30, 'L\'ultima pagina piena non finisce sul totale.');

$base = 'https://negozio.test/account/ordini/';
$check(AccountPagination::url($base, 1) === $base, 'La prima pagina ha il parametro.');
$check(AccountPagination::url($base, 3) === $base.'?pagina=3', 'La terza pagina non ha ?pagina=3.');
$check(AccountPagination::url($base.'?x=1', 2) === $base.'?x=1&pagina=2', 'Una base con query non usa &.');

$check(AccountPagination::window(AccountPagination::make(100, 5)) === [3, 4, 5, 6, 7], 'La finestra non è due pagine per lato.');
$check(AccountPagination::window(AccountPagination::make(100, 1)) === [1, 2, 3], 'La finestra esce dalla prima pagina.');
$check(AccountPagination::window(AccountPagination::make(5, 1)) === [1], 'Con una pagina la finestra non è [1].');

$_GET = ['pagina' => '4'];
$check(AccountPagination::requested() === '4', 'requested() non legge ?pagina.');
$_GET = [];
$check(AccountPagination::requested() === 1, 'Senza ?pagina requested() non dà 1.');

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures)."\n");
    exit(1);
}

echo "Account pagination: OK\n";
```

Run: `cd $W/app && php tests/account-pagination.php`
Expected: FAIL con `Class "Wonder\Auth\Frontend\AccountPagination" not found`.

- [ ] **Step 2: Scrivi `AccountPagination`**

```php
<?php

namespace Wonder\Auth\Frontend;

/** Conti della paginazione del pannello: la pagina chiesta si riporta tra 1 e l'ultima. */
final class AccountPagination
{
    /** @return array{page: int, pages: int, per_page: int, offset: int, from: int, to: int, total: int, limit: string} */
    public static function make(int $total, mixed $page, int $perPage = 10): array
    {
        $total = max(0, $total);
        $perPage = max(1, $perPage);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = is_numeric($page) ? (int) $page : 1;
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;

        return [
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'offset' => $offset,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => min($total, $offset + $perPage),
            'total' => $total,
            'limit' => $offset.', '.$perPage,
        ];
    }

    /** Il `?pagina=` della richiesta, così com'è: lo ripulisce `make()`. */
    public static function requested(): mixed
    {
        return $_GET['pagina'] ?? 1;
    }

    /** Il link a una pagina; la prima è la base senza parametro. */
    public static function url(string $base, int $page): string
    {
        return $page <= 1 ? $base : $base.(str_contains($base, '?') ? '&' : '?').'pagina='.$page;
    }

    /**
     * Le pagine da mostrare: la corrente con due per lato.
     *
     * @param array{page: int, pages: int} $pagination
     * @return list<int>
     */
    public static function window(array $pagination): array
    {
        return range(max(1, $pagination['page'] - 2), min($pagination['pages'], $pagination['page'] + 2));
    }
}
```

Run: `cd $W/app && php tests/account-pagination.php`
Expected: `Account pagination: OK`.

- [ ] **Step 3: Componente e testi**

`app/view/components/frontend/account/pagination.php` riceve `$pagination` e
`$base_url`. Senza righe (`total` 0) non stampa nulla.

```php
<?php

use Wonder\Auth\Frontend\AccountPagination;

/** @var array{page: int, pages: int, from: int, to: int, total: int} $pagination */
/** @var string $base_url */

if ((int) ($pagination['total'] ?? 0) <= 0) {
    return;
}

$item = static function (int $page, string $label, string $aria = '', bool $current = false, bool $disabled = false) use ($base_url): string {
    $attributes = $disabled
        ? ' aria-disabled="true" tabindex="-1"'
        : ' href="'.e(AccountPagination::url($base_url, $page)).'"'.($current ? ' aria-current="page"' : '');

    return '<li><a class="wi-pagination__item"'.$attributes.($aria !== '' ? ' aria-label="'.e($aria).'"' : '').'>'.$label.'</a></li>';
};
?>
<div class="wi-row-table__foot">
    <span><?=e((string) __t('account.pagination.summary', ['from' => $pagination['from'], 'to' => $pagination['to'], 'total' => $pagination['total']]))?></span>
    <ul class="wi-pagination" aria-label="<?=e((string) __t('account.pagination.label'))?>">
        <?=$item($pagination['page'] - 1, '<i class="bi bi-chevron-left" aria-hidden="true"></i>', (string) __t('account.pagination.previous'), false, $pagination['page'] <= 1)?>
        <?php foreach (AccountPagination::window($pagination) as $page): ?>
            <?=$item($page, (string) $page, '', $page === $pagination['page'])?>
        <?php endforeach; ?>
        <?=$item($pagination['page'] + 1, '<i class="bi bi-chevron-right" aria-hidden="true"></i>', (string) __t('account.pagination.next'), false, $pagination['page'] >= $pagination['pages'])?>
    </ul>
</div>
```

In `resources/lang/it/account.json` aggiungi:

```json
"pagination": {
    "summary": "Risultati da {{from}} a {{to}} di {{total}}",
    "label": "Pagine",
    "previous": "Pagina precedente",
    "next": "Pagina successiva"
}
```

In `resources/lang/en/account.json` aggiungi:

```json
"pagination": {
    "summary": "Showing {{from}} to {{to}} of {{total}}",
    "label": "Pages",
    "previous": "Previous page",
    "next": "Next page"
}
```

Il componente lo provano le pagine dell'ecommerce nel Task 2: lì ci sono
`View`, `__t` ed `e()` veri.

Run: `cd $W/app && for t in tests/account-*.php tests/auth-frontend.php; do php "$t" || echo "FALLITO $t"; done && php -r 'foreach (["it","en"] as $l) { json_decode(file_get_contents("resources/lang/$l/account.json"), true, 512, JSON_THROW_ON_ERROR); } echo "JSON OK\n";'`
Expected: tutti `OK` e `JSON OK`.

- [ ] **Step 4: Commit**

```bash
cd $W/app && git add class/Auth/Frontend/AccountPagination.php app/view/components/frontend/account/pagination.php tests/account-pagination.php resources/lang/it/account.json resources/lang/en/account.json
git commit -m "Account: paginazione del pannello con il suo componente

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Elenco degli ordini

Repo: `$W/ecommerce`.

**Files:**
- Modify: `src/Frontend/Account/EcommerceAccountExtension.php` (`routes()`, `navigation()`)
- Modify: `src/Frontend/Account/EcommerceAccountController.php`
- Create: `view/pages/account/orders.php`
- Modify: `lang/it/ecommerce.json`, `lang/en/ecommerce.json` (blocco `account`)
- Modify: `tests/EcommerceTest.php` (route attese, come nel piano 1 Task 8)
- Create: `tests/integrazione/AccountOrdersTest.php`

**Interfaces:**
- Consumes:
  - `AccountRoutes::group`, `reset`, `register`, `extend`, `panel` e `auth`
    (piano 1, Task 3);
  - i metodi protetti di `AccountController` `user()`, `contact()`, `page()` e
    `notFound()` (piano 1, Task 6);
  - `AccountPagination` e il componente `frontend.account.pagination` (Task 1);
  - `Order::find()`, `OrderSheet::date()`, `CartPresenter::money()`.
- Produces:
  - le route `account.orders` (`GET /account/ordini/`) e `account.orders.show`
    (`GET /account/ordini/{code}/`); la seconda risponde 404 fino al Task 3;
  - la voce `orders` del menu, subito dopo `overview`;
  - l'azione `orders` di `EcommerceAccountController`;
  - `EcommerceAccountController::rows(mixed $found): list<array<string, mixed>>`,
    protetto e statico, usato anche dai Task 3 e 4;
  - nel test, gli aiuti `prova()`, `clienteConScheda()`, `ordineDelCliente()` e
    `paginaNegozio()`, usati anche dai Task 3 e 4.

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/integrazione/AccountOrdersTest.php`. L'intestazione è quella di
`AccountCoreTest.php` del piano 1: `define('SITE', getenv('WI_TEST_SITE') ?: …)`,
`chdir`, autoload, `wonder-image.php` e `harness.php`.

```php
<?php
/** WI_TEST_SITE=… php tests/integrazione/AccountOrdersTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

$supporto = dirname(__DIR__, 3).'/gestionale/tests/integrazione/supporto';
require $supporto.'/compra.php';
require $supporto.'/spedizioni.php';

use Wonder\App\Models\Contacts\Contact;
use Wonder\App\Models\User\User;
use Wonder\Auth\Frontend\AccountPanel;
use Wonder\Auth\Frontend\AccountRoutes;
use Wonder\Auth\Frontend\ContactAccount;
use Wonder\Http\Route;
use Wonder\Plugin\Ecommerce\Frontend\Account\EcommerceAccountController;
use Wonder\Plugin\Ecommerce\Frontend\Account\EcommerceAccountExtension;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\Transaction;

final class AnnullaOrdini extends RuntimeException {}
final class UscitaOrdini extends RuntimeException {}

final class ControllerOrdiniDiProva extends EcommerceAccountController
{
    protected function redirect(string $url): never { throw new UscitaOrdini('redirect '.$url); }
    protected function notFound(): never { throw new UscitaOrdini('404'); }
    protected function invalidCsrf(): never { throw new UscitaOrdini('419'); }
}

/** Esegue il corpo in una transazione e la annulla; rilegge le funzionalità dopo. */
function prova(callable $corpo): mixed
{
    $esito = null;
    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();
            throw new AnnullaOrdini();
        });
    } catch (AnnullaOrdini) {
    } finally {
        Gestionale::reset();
    }
    return $esito;
}

/** Un cliente con login e, se `$collega`, la scheda collegata: [id utente, id scheda o 0]. */
function clienteConScheda(string $prefix, bool $collega = true): array
{
    $email = $prefix.'-'.bin2hex(random_bytes(6)).'@example.com';
    $userId = (int) (User::create([
        'name' => 'Ada', 'surname' => 'Lovelace', 'email' => $email,
        'username' => create_link(explode('@', $email)[0], 'user', 'username'),
        'authority' => json_encode(['client'], JSON_THROW_ON_ERROR),
        'area' => json_encode(['frontend'], JSON_THROW_ON_ERROR),
        'active' => 'true',
    ])->insert_id ?? 0);
    markUserEmailVerified($userId, date('Y-m-d H:i:s'));
    $GLOBALS['ALERT'] = null;

    return [$userId, $collega ? (int) ContactAccount::link($userId)->contact_id : 0];
}

/** Un ordine confermato della scheda data: [id, code]. */
function ordineDelCliente(int $scheda, string $numero, string $quando, array $valori = []): array
{
    $code = Code::make(Order::class, Codes::ORDER);
    $id = (int) (Order::create($valori + [
        'code' => $code, 'order_number' => $numero, 'ordered_at' => $quando,
        'customer_id' => $scheda, 'stage' => 'order', 'status' => 'confirmed', 'payment_status' => 'paid',
        'fulfillment_type' => 'shipping', 'currency' => 'EUR',
        'products_total' => '40.00', 'discount_total' => '0.00', 'shipping_total' => '5.00', 'fees_total' => '0.00', 'total' => '45.00',
    ])->insert_id ?? 0);

    return [$id, $code];
}

/** La pagina dell'azione come la vede l'utente in sessione; 404 e rimandi tornano come testo. */
function paginaNegozio(string $action, array $parameters = [], array $get = []): string
{
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = $get;
    $_POST = [];
    ob_start();
    try {
        (new ControllerOrdiniDiProva(AccountRoutes::panel(), AccountRoutes::auth()))->handle($action, $parameters);
        return (string) ob_get_clean();
    } catch (UscitaOrdini $e) {
        ob_end_clean();
        return $e->getMessage();
    }
}

Route::reset();
AccountRoutes::reset();
AccountRoutes::register(new AccountPanel());
AccountRoutes::extend(new EcommerceAccountExtension());

check('route degli ordini con le URL italiane', fn () =>
    str_ends_with(Route::url('account.orders'), '/account/ordini/')
    && str_ends_with(Route::url('account.orders.show', ['code' => 'ord_abc']), '/account/ordini/ord_abc/'));

check('Ordini nel menu subito dopo Panoramica, attivo sulla sua pagina', fn () => prova(static function (): bool {
    [$userId] = clienteConScheda('ordini-menu');
    $_SESSION['user_id'] = $userId;
    $keys = array_keys(AccountRoutes::panel()->navigation(\infoUser($userId, 'id'), 'orders'));
    $html = paginaNegozio('orders');

    return array_slice($keys, 0, 2) === ['overview', 'orders']
        && str_contains($html, 'wi-side-nav__link" href="'.Route::url('account.orders').'" aria-current="page"');
}));

check('elenco: solo gli ordini del cliente, dal più recente, 10 per pagina', fn () => prova(static function (): bool {
    [$userId, $scheda] = clienteConScheda('ordini-elenco');
    [, $altra] = clienteConScheda('ordini-altro');
    for ($i = 1; $i <= 12; $i++) {
        ordineDelCliente($scheda, sprintf('PRV-%02d', $i), date('Y-m-d H:i:s', strtotime('2026-01-01 10:00 +'.$i.' hours')));
    }
    ordineDelCliente($altra, 'PRV-ALTRUI', '2026-02-01 10:00:00');
    ordineDelCliente(0, 'PRV-OSPITE', '2026-02-01 10:00:00');
    ordineDelCliente($scheda, 'PRV-CARRELLO', '2026-02-01 10:00:00', ['stage' => 'cart']);
    $_SESSION['user_id'] = $userId;

    $prima = paginaNegozio('orders');
    $seconda = paginaNegozio('orders', [], ['pagina' => '2']);
    $oltre = paginaNegozio('orders', [], ['pagina' => '99']);
    $strana = paginaNegozio('orders', [], ['pagina' => 'abc']);
    $summary = static fn (int $from, int $to): string => (string) __t('account.pagination.summary', ['from' => $from, 'to' => $to, 'total' => 12]);

    return str_contains($prima, 'PRV-12') && str_contains($prima, 'PRV-03') && !str_contains($prima, 'PRV-02')
        && strpos($prima, 'PRV-12') < strpos($prima, 'PRV-11')
        && !str_contains($prima, 'PRV-ALTRUI') && !str_contains($prima, 'PRV-OSPITE') && !str_contains($prima, 'PRV-CARRELLO')
        && str_contains($prima, $summary(1, 10))
        && str_contains($prima, 'href="'.Route::url('account.orders').'?pagina=2"')
        && str_contains($prima, CartPresenter::money('45.00', 'EUR'))
        && str_contains($prima, 'href="'.Route::url('account.orders.show', ['code' => (string) (Order::find(['order_number' => 'PRV-12'], 1)['code'] ?? '')]).'"')
        && str_contains($seconda, $summary(11, 12)) && str_contains($seconda, 'PRV-01') && !str_contains($seconda, 'PRV-12')
        && str_contains($oltre, $summary(11, 12))
        && str_contains($strana, $summary(1, 10));
}));

check('senza ordini: stato vuoto e niente paginazione', fn () => prova(static function (): bool {
    [$userId] = clienteConScheda('ordini-vuoto');
    $_SESSION['user_id'] = $userId;
    $html = paginaNegozio('orders');

    return str_contains($html, 'wi-empty-state') && str_contains($html, e((string) __t('ecommerce.account.orders.empty')))
        && !str_contains($html, 'wi-pagination');
}));

check('scheda in conflitto: errore della scheda e nessun ordine degli ospiti', fn () => prova(static function (): bool {
    // Come nel piano 1: la scheda di un altro utente ha già l'email del cliente.
    [$userId] = clienteConScheda('ordini-senza-scheda', false);
    [, $altra] = clienteConScheda('ordini-conflitto');
    Contact::update(['email' => \infoUser($userId, 'id')->email], $altra);
    ordineDelCliente(0, 'PRV-OSPITE2', '2026-02-01 10:00:00');
    $_SESSION['user_id'] = $userId;
    $html = paginaNegozio('orders');

    return !str_contains($html, 'PRV-OSPITE2') && str_contains($html, 'wi-empty-state')
        && str_contains($html, e((string) __t('account.errors.contact')));
}));

check('ogni submit della pagina ha wi-input-submit', fn () => prova(static function (): bool {
    [$userId] = clienteConScheda('ordini-submit');
    $_SESSION['user_id'] = $userId;
    preg_match_all('/<(button|input)\b[^>]*type="submit"[^>]*>/', paginaNegozio('orders'), $submits);

    return $submits[0] !== [] && array_filter($submits[0], static fn ($tag) => !str_contains($tag, 'wi-input-submit')) === [];
}));

summary();
```

Il caso «scheda in conflitto» fa fallire `contact()` del piano 1 come il test
del conflitto di `AccountCoreTest`: `ContactAccount::link()` trova l'email su
una scheda d'altri e non crea niente, quindi `contact()` restituisce `[]`.

Run: `cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/AccountOrdersTest.php`
Expected: FAIL. Il primo caso fallisce perché `account.orders` non esiste.

- [ ] **Step 2: Route e voce del menu**

In `EcommerceAccountExtension::routes()` aggiungi due route nel gruppo, dopo
quella dei metodi di pagamento. L'ordine conta: `/ordini/` prima di
`/ordini/{code}/`.

```php
Route::get('/ordini/', $handler, ['account_action' => 'orders'])->name('orders');
Route::get('/ordini/{code}/', $handler, ['account_action' => 'orders.show'])->name('orders.show');
```

In `navigation()`, prima del ciclo che applica `account.navigation`, inserisci
la voce dopo `overview`. Se `overview` è spenta, la voce va in testa.

```php
$own = ['orders' => ['label' => (string) __t('ecommerce.account.orders.title'), 'href' => Route::url('account.orders'), 'icon' => 'bi bi-bag']];
$at = array_search('overview', array_keys($items), true);
$at = $at === false ? 0 : $at + 1;
$items = array_slice($items, 0, $at, true) + $own + array_slice($items, $at, null, true);
```

Se il piano 1 ha lasciato un commento «Ordini e Coupon arrivano nel piano 2», toglilo.

- [ ] **Step 3: Azione `orders`**

In `EcommerceAccountController`:

```php
public function handle(string $action, array $parameters = []): void
{
    match ($action) {
        'payment-methods' => $this->paymentMethods(),
        'orders' => $this->orders(),
        default => parent::handle($action, $parameters),
    };
}

protected function orders(): void
{
    $contactId = (int) ($this->contact((int) $this->user()->id)['id'] ?? 0);
    $condition = ['stage' => 'order', 'customer_id' => $contactId];
    // Senza scheda non si cerca: customer_id 0 sono gli ordini degli ospiti.
    $total = $contactId > 0 ? (int) (self::rows(Order::find($condition, null, null, null, 'COUNT(*) AS total'))[0]['total'] ?? 0) : 0;
    $pagination = AccountPagination::make($total, AccountPagination::requested());
    $orders = $total > 0 ? self::rows(Order::find($condition, $pagination['limit'], 'ordered_at', 'DESC')) : [];

    $this->page(Ecommerce::viewPath('pages/account/orders.php'), 'orders', [
        'title' => __t('ecommerce.account.orders.title'),
        'seo_url' => Route::url('account.orders'),
        'errors' => $contactId > 0 ? [] : [(string) __t('account.errors.contact')],
        'orders' => array_map(static fn (array $order): array => [
            'number' => (string) ($order['order_number'] ?? ''),
            'date' => OrderSheet::date((string) ($order['ordered_at'] ?? '')),
            'total' => CartPresenter::money($order['total'] ?? 0, (string) (($order['currency'] ?? '') ?: 'EUR')),
            'href' => Route::url('account.orders.show', ['code' => (string) $order['code']]),
        ], $orders),
        'pagination' => $pagination,
        'base_url' => Route::url('account.orders'),
    ]);
}

/** Le righe di un `find()`: una riga sola, una lista o niente diventano sempre una lista. */
protected static function rows(mixed $found): array
{
    if (!is_array($found) || $found === []) {
        return [];
    }

    return array_key_exists('id', $found) || array_key_exists('total', $found) ? [$found] : array_values(array_filter($found, 'is_array'));
}
```

`use` da aggiungere:
- `Wonder\Auth\Frontend\AccountPagination`;
- `Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter`;
- `Wonder\Plugin\Gestionale\Models\Sales\Order`;
- `Wonder\Plugin\Gestionale\Support\Orders\OrderSheet`.

- [ ] **Step 4: Vista e testi**

`view/pages/account/orders.php`:

```php
<?php

use Wonder\Elements\Components\Button;
use Wonder\View\View;

$account_panel->layout(get_defined_vars());
?>
<?php if ($orders === []): ?>
    <div class="wi-empty-state">
        <i class="wi-empty-state__icon bi bi-bag" aria-hidden="true"></i>
        <p><?=e((string) __t('ecommerce.account.orders.empty'))?></p>
    </div>
<?php else: ?>
    <div class="wi-row-table" style="--wi-row-table-columns: minmax(0, 2fr) minmax(0, 1fr) auto">
        <div class="wi-row-table__head">
            <span class="wi-row-table__cell"><?=e((string) __t('ecommerce.account.orders.order'))?></span>
            <span class="wi-row-table__cell"><?=e((string) __t('ecommerce.cart.total'))?></span>
            <span class="wi-row-table__cell"></span>
        </div>
        <?php foreach ($orders as $order): ?>
            <div class="wi-row-table__row">
                <div class="wi-row-table__cell">
                    <div class="wi-row-table__title"><?=e((string) __t('ecommerce.account.orders.number', ['number' => $order['number']]))?></div>
                    <div class="wi-row-table__subtitle"><?=e($order['date'])?></div>
                </div>
                <div class="wi-row-table__cell"><strong class="subtitle"><?=e($order['total'])?></strong></div>
                <div class="wi-row-table__cell">
                    <?=Button::to($order['href'], (string) __t('ecommerce.account.orders.view'))->outline()->variant('black')->size('sm')->arrow()->render()?>
                </div>
            </div>
        <?php endforeach; ?>
        <?=View::component('frontend.account.pagination', ['pagination' => $pagination, 'base_url' => $base_url])?>
    </div>
<?php endif; ?>
<?php View::end(); ?>
```

In `lang/it/ecommerce.json`, nel blocco `account`, accanto a `payment_methods`:

```json
"orders": {
    "title": "Ordini",
    "empty": "Non hai ancora effettuato ordini",
    "order": "Ordine",
    "number": "N° {{number}}",
    "view": "Visualizza"
}
```

In `lang/en/ecommerce.json`:

```json
"orders": {
    "title": "Orders",
    "empty": "You haven't placed any orders yet",
    "order": "Order",
    "number": "No. {{number}}",
    "view": "View"
}
```

In `tests/EcommerceTest.php`, alle route attese del piano 1 aggiungi
`account.orders` e `account.orders.show`, con i loro percorsi.

- [ ] **Step 5: Lancia i test**

Run:
```bash
cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/AccountOrdersTest.php
cd $W/ecommerce && WI_TEST_SITE=$S WI_TEST_URL=https://ecommerce-account.test php tests/run.php
```
Expected: tutti i casi `ok`; poi `Tutti i test dell'ecommerce passano.`

- [ ] **Step 6: Commit**

```bash
cd $W/ecommerce && git add src/Frontend/Account view/pages/account/orders.php lang/it/ecommerce.json lang/en/ecommerce.json tests/EcommerceTest.php tests/integrazione/AccountOrdersTest.php
git commit -m "Account: elenco degli ordini del cliente con paginazione

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Dettaglio dell'ordine

Repo: `$W/ecommerce`.

**Files:**
- Create: `src/Frontend/Account/AccountOrder.php`
- Create: `view/pages/account/order.php`
- Modify: `src/Frontend/Account/EcommerceAccountController.php`
- Modify: `src/Frontend/Checkout/CheckoutSummary.php:81-102`
- Modify: `lang/it/ecommerce.json`, `lang/en/ecommerce.json` (`account.orders`)
- Modify: `tests/integrazione/AccountOrdersTest.php`

**Interfaces:**
- Consumes:
  - dal Task 2: `rows()`, `contact()`, `user()`, `page()`, `notFound()` e gli
    aiuti del test;
  - dal gestionale:
    - `Order`, `OrderItem`, `Shipment`, `Carrier`, `ShippingMethod`,
      `PaymentMethod::iconsOf()`;
    - `Customizations::lines()`;
    - `OrderSheet::address()` e `date()`;
    - `PickupPoints::all()`;
    - `Carriers::trackingUrl()`.
- Produces:
  - `CheckoutSummary::paymentIcons(array $keys): list<array{src: string, alt: string}>`,
    pubblico;
  - `AccountOrder::present(array $order): array{number: string, date: string, info: list<array{label: string, value: string, icons: list<array{src: string, alt: string}>, href: string}>, items: list<array>, summary: list<array{label: string, value: string}>, total: string, delivery: ?array{title: string, html: string}, billing: string}`;
  - l'azione `orders.show`.

- [ ] **Step 1: Aggiungi i casi che falliscono**

In `AccountOrdersTest.php`, prima di `summary()`, aggiungi gli `use` e i casi.

```php
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Shipping\Shipment;

check('dettaglio: un ordine non del cliente dà 404', fn () => prova(static function (): bool {
    [$userId, $scheda] = clienteConScheda('dettaglio-404');
    [, $altra] = clienteConScheda('dettaglio-altro');
    [, $altrui] = ordineDelCliente($altra, 'PRV-D-ALTRUI', '2026-03-01 10:00:00');
    [, $ospite] = ordineDelCliente(0, 'PRV-D-OSPITE', '2026-03-01 10:00:00');
    [, $carrello] = ordineDelCliente($scheda, 'PRV-D-CARRELLO', '2026-03-01 10:00:00', ['stage' => 'cart']);
    $_SESSION['user_id'] = $userId;

    foreach ([$altrui, $ospite, $carrello, 'ord_inesistente', ''] as $code) {
        if (paginaNegozio('orders.show', ['code' => $code]) !== '404') {
            return false;
        }
    }
    return true;
}));

check('dettaglio: informazioni, tracking, prodotti, riepilogo e indirizzi', fn () => prova(static function (): bool {
    [$userId, $scheda] = clienteConScheda('dettaglio');
    $metodo = metodo('Corriere espresso');
    $pagamento = (int) (PaymentMethod::create([
        'code' => 'tst_'.uniqid(), 'name' => 'Carta di prova', 'provider' => 'bank_transfer', 'timing' => 'deferred',
        'fee_type' => 'none', 'fee_value' => '0.00', 'fee_percent' => '0.00', 'available_for' => 'all',
        'active' => 'true', 'position' => 1, 'icons' => 'visa,master',
    ])->insert_id ?? 0);
    $corriere = (int) (Carrier::create([
        'code' => 'car_acc-'.uniqid(), 'name' => 'Corriere di prova',
        'tracking_url_template' => 'https://traccia.example/{tracking}', 'active' => 'true',
    ])->insert_id ?? 0);
    [$id, $code] = ordineDelCliente($scheda, 'PRV-DET', '2026-03-05 09:30:00', [
        'shipping_method_id' => $metodo, 'payment_method_id' => $pagamento,
        'discount_total' => '4.00', 'coupon_code' => 'BENVENUTO', 'fees_total' => '1.50', 'total' => '42.50',
        'shipping_name' => 'Ada', 'shipping_surname' => 'Spedita', 'shipping_street' => 'Via Roma', 'shipping_number' => '1',
        'shipping_cap' => '20100', 'shipping_city' => 'Milano', 'shipping_province' => 'MI',
        'billing_name' => 'Ada', 'billing_surname' => 'Fatturata', 'billing_street' => 'Via Verdi', 'billing_number' => '2',
        'billing_cap' => '10100', 'billing_city' => 'Torino', 'billing_province' => 'TO',
    ]);
    $riga = (int) (OrderItem::create(['order_id' => $id, 'type' => 'product', 'position' => 1, 'name' => 'Tazza <b>blu</b>', 'image' => '', 'quantity' => '2.000', 'unit_price' => '20.00', 'line_total' => '40.00'])->insert_id ?? 0);
    OrderItem::create(['order_id' => $id, 'type' => 'product', 'position' => 2, 'parent_item_id' => $riga, 'bundle_option_id' => 7, 'name' => 'Piattino scelto', 'quantity' => '1.000', 'unit_price' => '0.00', 'line_total' => '0.00']);
    OrderItem::create(['order_id' => $id, 'type' => 'shipping', 'position' => 3, 'name' => 'RIGA-SPEDIZIONE', 'quantity' => '1.000', 'unit_price' => '5.00', 'line_total' => '5.00']);
    Shipment::create(['code' => Code::make(Shipment::class, Codes::SHIPMENT), 'order_id' => $id, 'type' => 'delivery', 'status' => 'in_transit', 'carrier_id' => $corriere, 'tracking_number' => 'TRK123']);
    Shipment::create(['code' => Code::make(Shipment::class, Codes::SHIPMENT), 'order_id' => $id, 'type' => 'delivery', 'status' => 'cancelled', 'carrier_id' => $corriere, 'tracking_number' => 'ANNULLATA']);
    $_SESSION['user_id'] = $userId;
    $html = paginaNegozio('orders.show', ['code' => $code]);
    $money = static fn (string $v): string => e(CartPresenter::money($v, 'EUR'));

    return str_contains($html, e((string) __t('ecommerce.account.orders.order_title', ['number' => 'PRV-DET'])))
        && str_contains($html, '05/03/2026 09:30')
        && str_contains($html, e((string) __t('ecommerce.account.orders.status.confirmed')))
        && str_contains($html, e((string) __t('ecommerce.account.orders.payment_status.paid')))
        && str_contains($html, 'Corriere espresso') && str_contains($html, 'Carta di prova') && str_contains($html, 'payment-icons/visa.svg')
        && str_contains($html, 'TRK123') && str_contains($html, 'href="https://traccia.example/TRK123"') && !str_contains($html, 'ANNULLATA')
        && str_contains($html, 'Tazza &lt;b&gt;blu&lt;/b&gt;') && !str_contains($html, 'Tazza <b>blu</b>')
        && str_contains($html, 'Piattino scelto') && str_contains($html, e((string) __t('ecommerce.account.orders.choice')))
        && !str_contains($html, 'RIGA-SPEDIZIONE')
        && str_contains($html, 'BENVENUTO') && str_contains($html, $money('4.00')) && str_contains($html, $money('1.50')) && str_contains($html, $money('42.50'))
        && str_contains($html, 'Spedita') && str_contains($html, 'Fatturata')
        && strpos($html, e((string) __t('ecommerce.account.orders.delivery_address'))) < strpos($html, e((string) __t('ecommerce.checkout.billing')));
}));

check('dettaglio: ritiro in sede con la sede, o il ripiego se la sede non c\'è più', fn () => prova(static function (): bool {
    [$userId, $scheda] = clienteConScheda('dettaglio-ritiro');
    $punto = sede();
    [, $conSede] = ordineDelCliente($scheda, 'PRV-RIT', '2026-03-06 10:00:00', ['fulfillment_type' => 'pickup', 'location_id' => $punto, 'shipping_total' => '0.00']);
    [, $senzaSede] = ordineDelCliente($scheda, 'PRV-RIT2', '2026-03-06 11:00:00', ['fulfillment_type' => 'pickup', 'location_id' => 999999, 'shipping_total' => '0.00', 'payment_method_id' => 999999]);
    $_SESSION['user_id'] = $userId;
    $con = paginaNegozio('orders.show', ['code' => $conSede]);
    $senza = paginaNegozio('orders.show', ['code' => $senzaSede]);
    $ritiro = e((string) __t('ecommerce.checkout.fulfillment_pickup'));

    return str_contains($con, 'Prova ritiro') && str_contains($con, e((string) __t('ecommerce.account.orders.pickup_address')))
        && !str_contains($con, e((string) __t('ecommerce.account.orders.delivery_address')))
        && str_contains($senza, $ritiro) && str_contains($senza, e((string) __t('ecommerce.account.orders.payment')))
        && str_contains($senza, 'PRV-RIT2');
}));
```

`sede()` di `supporto/spedizioni.php` crea una sede con etichetta «Prova
ritiro»; `metodo()` crea un metodo di spedizione con il nome dato.

Run: `cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/AccountOrdersTest.php`
Expected: falliscono i casi del dettaglio e del ritiro. Il caso del 404 passa
già, perché fino a qui `orders.show` finisce nel 404 del parent: resta come
guardia per il passo 4.

- [ ] **Step 2: Estrai i loghi dei pagamenti**

In `CheckoutSummary.php` aggiungi un metodo pubblico. In `paymentDisplay()`,
`'icon_urls' => self::paymentIcons((array) ($option['icons'] ?? []))` sostituisce
il ciclo.

```php
/**
 * I loghi di un metodo di pagamento: solo le chiavi note, con il loro svg.
 *
 * @param list<string> $keys
 * @return list<array{src: string, alt: string}>
 */
public static function paymentIcons(array $keys): array
{
    $icons = [];
    foreach ($keys as $key) {
        $src = isset(PaymentMethod::ICONS[$key]) ? (string) module_asset('ecommerce', 'payment-icons/'.$key.'.svg') : '';
        if ($src !== '') {
            $icons[] = ['src' => $src, 'alt' => PaymentMethod::ICONS[$key]];
        }
    }

    return $icons;
}
```

Run: `cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/CheckoutSummaryTest.php`
Expected: tutti `ok`.

- [ ] **Step 3: Scrivi `AccountOrder`**

`src/Frontend/Account/AccountOrder.php` prepara i dati della vista e non
stampa HTML. Fanno eccezione gli indirizzi, che arrivano già escapati da
`OrderSheet::address()`.

```php
<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutSummary;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Shipping\Shipment;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Locations\PickupPoints;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Shipping\Carriers;

/** Il dettaglio di un ordine come lo vede il cliente: tutto testo semplice, salvo gli indirizzi. */
final class AccountOrder
{
    public static function present(array $order): array
    {
        $currency = (string) (($order['currency'] ?? '') ?: 'EUR');
        $money = static fn (mixed $value): string => CartPresenter::money($value, $currency);
        $pickup = (string) ($order['fulfillment_type'] ?? '') === 'pickup' ? self::pickupPoint((int) ($order['location_id'] ?? 0)) : null;

        return [
            'number' => (string) ($order['order_number'] ?? ''),
            'date' => OrderSheet::date((string) ($order['ordered_at'] ?? '')),
            'info' => self::info($order, $pickup),
            'items' => self::items((int) $order['id'], $money),
            'summary' => self::summary($order, $money),
            'total' => $money($order['total'] ?? 0),
            'delivery' => self::delivery($order, $pickup),
            'billing' => OrderSheet::address($order, 'billing'),
        ];
    }

    private static function info(array $order, ?array $pickup): array
    {
        $row = static fn (string $label, string $value, array $icons = [], string $href = ''): array => ['label' => $label, 'value' => $value, 'icons' => $icons, 'href' => $href];
        $status = (string) ($order['status'] ?? '');
        $paymentStatus = (string) ($order['payment_status'] ?? '');
        $rows = [$row((string) __t('ecommerce.account.orders.status_label'), in_array($status, Order::LIVE_STATUSES, true) ? (string) __t('ecommerce.account.orders.status.'.$status) : '—')];

        $type = (string) ($order['fulfillment_type'] ?? '');
        if ($type === 'pickup') {
            $rows[] = $row((string) __t('ecommerce.checkout.delivery'), (string) __t('ecommerce.checkout.fulfillment_pickup').($pickup !== null ? ' · '.$pickup['name'] : ''));
        } elseif ($type === 'shipping') {
            $method = self::byId(ShippingMethod::class, (int) ($order['shipping_method_id'] ?? 0));
            $rows[] = $row((string) __t('ecommerce.checkout.delivery'), $method !== [] ? (string) $method['name'] : (string) __t('ecommerce.checkout.fulfillment_shipping'));
        }

        $payment = self::byId(PaymentMethod::class, (int) ($order['payment_method_id'] ?? 0));
        $rows[] = $payment !== []
            ? $row((string) __t('ecommerce.account.orders.payment'), (string) $payment['name'], CheckoutSummary::paymentIcons(PaymentMethod::iconsOf((string) ($payment['icons'] ?? ''))))
            : $row((string) __t('ecommerce.account.orders.payment'), '—');
        $rows[] = $row((string) __t('ecommerce.account.orders.payment_status_label'), in_array($paymentStatus, Order::PAYMENT_STATUSES, true) ? (string) __t('ecommerce.account.orders.payment_status.'.$paymentStatus) : '—');

        foreach (self::rows(Shipment::find(['order_id' => (int) $order['id'], 'type' => 'delivery'])) as $shipment) {
            $tracking = trim((string) ($shipment['tracking_number'] ?? ''));
            if ($tracking === '' || (string) ($shipment['status'] ?? '') === 'cancelled') {
                continue;
            }
            $carrier = self::byId(Carrier::class, (int) ($shipment['carrier_id'] ?? 0));
            $href = trim((string) ($shipment['tracking_url'] ?? '')) ?: Carriers::trackingUrl($carrier, $tracking);
            $rows[] = $row((string) __t('ecommerce.account.orders.tracking'), $tracking);
            $rows[] = $row((string) __t('ecommerce.account.orders.carrier'), (string) ($carrier['name'] ?? '—'), [], $href);
        }

        return $rows;
    }

    private static function items(int $orderId, callable $money): array
    {
        $lines = CartPresenter::lines(self::rows(OrderItem::find(['order_id' => $orderId], null, 'position', 'ASC')));
        $line = static fn (array $item): array => [
            'name' => (string) ($item['name'] ?? ''),
            'image' => trim((string) ($item['image'] ?? '')),
            'details' => Customizations::lines($item),
            'quantity' => (string) ($item['type'] ?? '') === 'text' ? '' : CartPresenter::quantity($item['quantity'] ?? 1),
            'total' => (string) ($item['type'] ?? '') === 'text' ? '' : $money($item['line_total'] ?? 0),
            'choice' => (int) ($item['bundle_option_id'] ?? 0) > 0,
        ];
        $children = [];
        foreach ($lines as $item) {
            if ((int) ($item['parent_item_id'] ?? 0) > 0) {
                $children[(int) $item['parent_item_id']][] = $line($item);
            }
        }
        $items = [];
        foreach ($lines as $item) {
            if ((int) ($item['parent_item_id'] ?? 0) === 0) {
                $items[] = $line($item) + ['children' => $children[(int) $item['id']] ?? []];
            }
        }

        return $items;
    }

    private static function summary(array $order, callable $money): array
    {
        $rows = [['label' => (string) __t('ecommerce.cart.products_total'), 'value' => $money($order['products_total'] ?? 0)]];
        if ((float) ($order['discount_total'] ?? 0) > 0) {
            $coupon = trim((string) ($order['coupon_code'] ?? ''));
            $rows[] = ['label' => (string) __t('ecommerce.cart.discount').($coupon !== '' ? ' ('.$coupon.')' : ''), 'value' => '− '.$money($order['discount_total'])];
        }
        if ((string) ($order['fulfillment_type'] ?? '') === 'shipping') {
            $rows[] = ['label' => (string) __t('ecommerce.checkout.shipping_total'), 'value' => $money($order['shipping_total'] ?? 0)];
        }
        if ((float) ($order['fees_total'] ?? 0) > 0) {
            $rows[] = ['label' => (string) __t('ecommerce.checkout.fees_total'), 'value' => $money($order['fees_total'])];
        }

        return $rows;
    }

    private static function delivery(array $order, ?array $pickup): ?array
    {
        return match ((string) ($order['fulfillment_type'] ?? '')) {
            'pickup' => [
                'title' => (string) __t('ecommerce.account.orders.pickup_address'),
                'html' => $pickup !== null
                    ? e($pickup['name']).'<br>'.e($pickup['address'])
                    : e((string) __t('ecommerce.checkout.fulfillment_pickup')),
            ],
            'shipping' => ['title' => (string) __t('ecommerce.account.orders.delivery_address'), 'html' => OrderSheet::address($order, 'shipping')],
            default => null,
        };
    }

    /** @return array{id: int, name: string, address: string}|null */
    private static function pickupPoint(int $locationId): ?array
    {
        foreach (PickupPoints::all() as $point) {
            if ($point['id'] === $locationId) {
                return $point;
            }
        }

        return null;
    }

    /**
     * Una riga per id, o `[]` se non c'è più: `find()` salta già le eliminate.
     *
     * @param class-string $model
     * @return array<string, mixed>
     */
    private static function byId(string $model, int $id): array
    {
        $row = $id > 0 ? $model::find(['id' => $id], 1) : null;

        return is_array($row) && isset($row['id']) ? $row : [];
    }

    private static function rows(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        return array_key_exists('id', $found) ? [$found] : array_values(array_filter($found, 'is_array'));
    }
}
```

- [ ] **Step 4: Azione, vista e testi**

In `EcommerceAccountController::handle()` aggiungi
`'orders.show' => $this->order((string) ($parameters['code'] ?? ''))`, poi:

```php
protected function order(string $code): void
{
    $contactId = (int) ($this->contact((int) $this->user()->id)['id'] ?? 0);
    $order = $code !== '' && $contactId > 0 ? Order::find(['code' => $code, 'stage' => 'order'], 1) : null;
    if (!is_array($order) || !isset($order['id']) || (int) ($order['customer_id'] ?? 0) !== $contactId) {
        $this->notFound();
    }

    $this->page(Ecommerce::viewPath('pages/account/order.php'), 'orders', [
        'title' => __t('ecommerce.account.orders.order_title', ['number' => (string) $order['order_number']]),
        'seo_url' => Route::url('account.orders.show', ['code' => $code]),
        'order' => AccountOrder::present($order),
    ]);
}
```

`view/pages/account/order.php`:
- `$account_panel->layout(get_defined_vars())`. Il titolo «Ordine N°…» lo stampa
  il layout del piano 1 da `$title`.
- Sotto il titolo: `<p class="wi-row-table__subtitle">` con
  `__t('ecommerce.account.orders.of_date', ['date' => $order['date']])`.
- **Informazioni:** `<h3 class="subtitle">{{ecommerce.account.orders.info}}</h3>`.
  Ogni riga di `$order['info']` è una `.wi-data-row` del piano 1:
  `.wi-data-row__cols > .wi-data-row__col > .wi-data-row__label + .wi-data-row__value`.
  - Il valore passa da `e()`.
  - Se `href` non è vuoto, il valore è
    `<a href="…" target="_blank" rel="noopener">…</a>`.
  - Se ci sono `icons`, ognuna è `<img src="…" alt="…" height="20">` dopo il
    nome.
- **Prodotti:** `<h3 class="subtitle">{{ecommerce.account.orders.products}}</h3>`,
  poi una `.wi-row-table` con
  `--wi-row-table-columns: minmax(0, 3fr) minmax(0, 1fr) minmax(0, 1fr)`.
  Ogni riga ha tre celle:
  - cella 1: `<span class="wi-thumb" style="--wi-thumb-size: 64px">` con
    l'immagine, se c'è, come in `components/checkout/lines.php`. Poi il nome in
    `.wi-row-table__title` e ogni riga di `details` in
    `.wi-row-table__subtitle`. Poi i `children`, uno per riga in
    `.wi-row-table__subtitle`: «Scelta: » (`ecommerce.account.orders.choice`)
    se `choice` è vero, poi il nome, `× quantità` e i loro `details`;
  - cella 2: la quantità;
  - cella 3: il totale della riga.
- **Riepilogo:** `<h3 class="subtitle">{{ecommerce.account.orders.summary}}</h3>`.
  Ogni riga di `$order['summary']` è un `<div class="d-flex justify-content-between">`
  con etichetta e valore. In fondo il Totale (`ecommerce.cart.total`), con il
  valore in `<strong class="subtitle">`.
- **Indirizzi:** `<div class="wi-address-grid">`, la griglia delle schede
  indirizzo del piano 1: tre colonne, poi due, poi una su mobile. Dentro, due
  `<div class="wi-address-card">`:
  - se `delivery` non è null, una scheda con il suo `title` in
    `.wi-row-table__title` e il suo `html` stampato senza `e()`, perché è già
    escapato;
  - una scheda con `ecommerce.checkout.billing` e `$order['billing']`.
- In fondo:
  `Button::to(Route::url('account.orders'), __t('ecommerce.account.orders.back'))->outline()->variant('black')->render()`.
- `View::end()`.

Prima di scrivere la vista, apri il CSS `address-card.css` del piano 1 e
controlla i nomi della griglia e della scheda. Se la griglia ha un altro nome,
usa quello.

Nuove chiavi di `account.orders` in it ed en. `status` e `payment_status`
coprono tutti i valori di `Order::LIVE_STATUSES` e `Order::PAYMENT_STATUSES`.

| Chiave | it | en |
|---|---|---|
| `order_title` | Ordine N° {{number}} | Order No. {{number}} |
| `of_date` | del {{date}} | placed on {{date}} |
| `info` | Informazioni | Details |
| `status_label` | Stato | Status |
| `payment` | Pagamento | Payment |
| `payment_status_label` | Stato pagamento | Payment status |
| `tracking` | N° spedizione | Tracking number |
| `carrier` | Corriere | Carrier |
| `products` | Prodotti | Products |
| `summary` | Riepilogo | Summary |
| `choice` | Scelta | Choice |
| `delivery_address` | Indirizzo di consegna | Delivery address |
| `pickup_address` | Indirizzo di ritiro | Pickup address |
| `back` | Torna agli ordini | Back to orders |
| `status.pending` | In attesa | Pending |
| `status.confirmed` | Confermato | Confirmed |
| `status.processing` | In preparazione | Processing |
| `status.completed` | Completato | Completed |
| `status.cancelled` | Annullato | Cancelled |
| `payment_status.unpaid` | Da pagare | Unpaid |
| `payment_status.pending` | In attesa di pagamento | Awaiting payment |
| `payment_status.partially_paid` | Pagato in parte | Partially paid |
| `payment_status.paid` | Pagato | Paid |
| `payment_status.partially_refunded` | Rimborsato in parte | Partially refunded |
| `payment_status.refunded` | Rimborsato | Refunded |

La tabella copre tutti i valori di `Order::LIVE_STATUSES` e
`Order::PAYMENT_STATUSES` di oggi (`src/Models/Sales/Order.php:47-51`).

- [ ] **Step 5: Lancia i test**

Run:
```bash
cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/AccountOrdersTest.php
cd $W/ecommerce && WI_TEST_SITE=$S WI_TEST_URL=https://ecommerce-account.test php tests/run.php
```
Expected: tutti `ok`; poi `Tutti i test dell'ecommerce passano.`

- [ ] **Step 6: Commit**

```bash
cd $W/ecommerce && git add src/Frontend/Account src/Frontend/Checkout/CheckoutSummary.php view/pages/account/order.php lang/it/ecommerce.json lang/en/ecommerce.json tests/integrazione/AccountOrdersTest.php
git commit -m "Account: dettaglio dell'ordine con tracking, prodotti, riepilogo e indirizzi

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Coupon del cliente

Repo: `$W/gestionale`, poi `$W/ecommerce`.

**Files:**
- Modify: `$W/gestionale/src/Support/Promotions/Coupons.php`
- Modify: `$W/gestionale/src/Support/Contacts/CustomerSheet.php:148-190`
- Modify: `$W/gestionale/tests/integrazione/CustomerSheetBackendTest.php`
- Create: `$W/ecommerce/src/Frontend/Account/AccountCoupons.php`
- Create: `$W/ecommerce/view/pages/account/coupons.php`
- Modify: `$W/ecommerce/src/Frontend/Account/EcommerceAccountExtension.php`,
  `EcommerceAccountController.php`
- Modify: `$W/ecommerce/lang/{it,en}/ecommerce.json`, `tests/EcommerceTest.php`
- Modify: `$W/ecommerce/tests/integrazione/AccountOrdersTest.php`

**Interfaces:**
- Consumes:
  - `Coupon`, `CouponCustomer`, `CouponRedemption`;
  - `Campaigns::status(array $row, string $now)`;
  - `OrderSheet::number()`, `CartPresenter::money()`;
  - `AccountPagination` (Task 1);
  - `rows()` e gli aiuti del test (Task 2).
- Produces:
  - nel gestionale,
    `Coupons::reserved(int $customerId): list<array{coupon: array<string, mixed>, used: int}>`:
    - i coupon non eliminati con una riga in `gst_coupon_customers` per il
      cliente;
    - `used` conta gli utilizzi non rilasciati di quel cliente;
    - restituisce `[]` con la funzionalità spenta o con `customerId <= 0`;
  - `AccountCoupons::forCustomer(int $contactId, string $now): list<array{code: string, value: string, uses: string}>`:
    solo i coupon in corso (`running`), ordinati per codice;
  - la route `account.coupons` (`GET /account/coupon/`) e la voce `coupons` del
    menu, dopo `orders`, solo con la funzionalità accesa;
  - l'azione `coupons`.

- [ ] **Step 1: `Coupons::reserved()` nel gestionale (test prima)**

In `CustomerSheetBackendTest.php`, dopo il caso `coupons(): …` (riga 329),
aggiungi un caso. Usa gli aiuti già nel file: `couponRiservato`,
`clienteDiProva`, `ordineDiProva`, `prova` e `accendiFunzionalita`.

```php
check('Coupons::reserved(): coupon riservati con gli usi non rilasciati del cliente', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['coupons']);
        $cliente = clienteDiProva();
        $a = couponRiservato([$cliente], ['code' => 'RISERVA', 'usage_limit_per_customer' => '3']);
        couponRiservato([$cliente + 999]);
        $ordine = ordineDiProva(10.0);
        CouponRedemption::create(['coupon_id' => $a, 'order_id' => $ordine, 'customer_id' => $cliente, 'email' => 'a@example.com', 'discount_amount' => '1.00', 'redeemed_at' => date('Y-m-d H:i:s')]);
        CouponRedemption::create(['coupon_id' => $a, 'order_id' => $ordine, 'customer_id' => $cliente, 'email' => 'a@example.com', 'discount_amount' => '1.00', 'redeemed_at' => date('Y-m-d H:i:s'), 'released_at' => date('Y-m-d H:i:s')]);
        $righe = \Wonder\Plugin\Gestionale\Support\Promotions\Coupons::reserved($cliente);

        return count($righe) === 1 && $righe[0]['coupon']['code'] === 'RISERVA' && $righe[0]['used'] === 1
            && \Wonder\Plugin\Gestionale\Support\Promotions\Coupons::reserved(0) === [];
    });
});
```

Run: `cd $W/gestionale && php tests/integrazione/CustomerSheetBackendTest.php`
Expected: FAIL con `Call to undefined method …Coupons::reserved()`.

In `Coupons.php`, accanto a `usedCount()`:

```php
/**
 * I coupon riservati al cliente, con gli utilizzi suoi non rilasciati. Li
 * usano la scheda del backend e il pannello del cliente.
 *
 * @return list<array{coupon: array<string, mixed>, used: int}>
 */
public static function reserved(int $customerId): array
{
    if ($customerId <= 0 || !Gestionale::feature('coupons')) {
        return [];
    }

    $reserved = [];
    foreach (self::rows(CouponCustomer::find(['customer_id' => $customerId])) as $link) {
        $coupon = Coupon::find(['id' => (int) $link['coupon_id']], 1);
        if (!is_array($coupon) || !isset($coupon['id'])) {
            continue;
        }
        $used = count(array_filter(
            self::rows(CouponRedemption::find(['coupon_id' => (int) $coupon['id'], 'customer_id' => $customerId])),
            static fn (array $row): bool => self::empty((string) ($row['released_at'] ?? ''))
        ));
        $reserved[] = ['coupon' => $coupon, 'used' => $used];
    }

    return $reserved;
}
```

Prima di usare `self::empty()` e `self::rows()` di `Coupons.php`
(righe 381 e 389), controlla che diano lo stesso risultato del filtro di oggi
in `CustomerSheet::coupons()`:
- `trim(...) === ''`;
- `str_starts_with(..., '0000-00-00')`.

Se non è così, copia il filtro di oggi.

`CustomerSheet::coupons()` diventa un `array_map` su `Coupons::reserved()`:

```php
public static function coupons(int $customerId): array
{
    $now = date('Y-m-d H:i:s');

    return array_map(static function (array $reserved) use ($now): array {
        $coupon = $reserved['coupon'];
        $limit = (int) ($coupon['usage_limit_per_customer'] ?? 0);

        return [
            'code' => (string) $coupon['code'],
            'name' => (string) ($coupon['name'] ?? ''),
            'discount' => CouponResource::discountLabel($coupon),
            'period' => CouponResource::periodLabel($coupon),
            'used' => $limit > 0 ? $reserved['used'].' / '.$limit : (string) $reserved['used'],
            'state' => CouponResource::statusLabel($coupon, $now),
        ];
    }, Coupons::reserved($customerId));
}
```

Togli da `CustomerSheet` gli `use` rimasti senza uso: `CouponCustomer`,
`CouponRedemption` e `Coupon`, se non servono altrove nel file.

Run: `cd $W/gestionale && php tests/integrazione/CustomerSheetBackendTest.php && php tests/run.php`
Expected: tutti `ok`, compreso il caso `coupons(): …` di prima.

```bash
cd $W/gestionale && git add src/Support/Promotions/Coupons.php src/Support/Contacts/CustomerSheet.php tests/integrazione/CustomerSheetBackendTest.php
git commit -m "Coupon: i coupon riservati al cliente si contano in un posto solo

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 2: Casi dell'ecommerce che falliscono**

In `AccountOrdersTest.php`, prima di `summary()`:

```php
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponCustomer;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;

/** Un coupon riservato alla scheda data: id. */
function couponDelCliente(int $scheda, array $valori = []): int
{
    $id = (int) (Coupon::create($valori + [
        'code' => 'C'.strtoupper(substr(uniqid(), -8)), 'name' => 'Riservato', 'discount_type' => 'percent', 'discount_value' => '10.00',
        'applies_to_all' => 'true', 'applies_online' => 'true', 'active' => 'true',
    ])->insert_id ?? 0);
    CouponCustomer::create(['coupon_id' => $id, 'customer_id' => $scheda]);

    return $id;
}

check('coupon: solo quelli in corso, con valore e usi del cliente', fn () => prova(static function (): bool {
    accendiFunzionalita(['coupons']);
    [$userId, $scheda] = clienteConScheda('coupon');
    $tre = couponDelCliente($scheda, ['code' => 'TREUSI', 'usage_limit_per_customer' => '3']);
    couponDelCliente($scheda, ['code' => 'CINQUE', 'discount_type' => 'amount', 'discount_value' => '5.00']);
    couponDelCliente($scheda, ['code' => 'GRATIS', 'discount_type' => 'free_shipping', 'discount_value' => '0.00']);
    couponDelCliente($scheda, ['code' => 'CREDITO', 'discount_type' => 'store_credit', 'discount_value' => '20.00']);
    couponDelCliente($scheda, ['code' => 'SPENTO', 'active' => 'false']);
    couponDelCliente($scheda, ['code' => 'SCADUTO', 'ends_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
    couponDelCliente($scheda, ['code' => 'FUTURO', 'starts_at' => date('Y-m-d H:i:s', strtotime('+1 day'))]);
    $eliminato = couponDelCliente($scheda, ['code' => 'ELIMINATO']);
    Coupon::update(['deleted' => 'true'], $eliminato);
    [$ordine] = ordineDelCliente($scheda, 'PRV-CPN', '2026-03-07 10:00:00');
    CouponRedemption::create(['coupon_id' => $tre, 'order_id' => $ordine, 'customer_id' => $scheda, 'email' => 'a@example.com', 'discount_amount' => '1.00', 'redeemed_at' => date('Y-m-d H:i:s')]);
    CouponRedemption::create(['coupon_id' => $tre, 'order_id' => $ordine, 'customer_id' => $scheda, 'email' => 'a@example.com', 'discount_amount' => '1.00', 'redeemed_at' => date('Y-m-d H:i:s'), 'released_at' => date('Y-m-d H:i:s')]);
    CouponRedemption::create(['coupon_id' => $tre, 'order_id' => $ordine, 'customer_id' => $scheda + 999, 'email' => 'b@example.com', 'discount_amount' => '1.00', 'redeemed_at' => date('Y-m-d H:i:s')]);
    $_SESSION['user_id'] = $userId;
    $html = paginaNegozio('coupons');
    $nav = AccountRoutes::panel()->navigation(\infoUser($userId, 'id'), 'coupons');

    return str_contains($html, 'TREUSI') && str_contains($html, '10%') && str_contains($html, '1 / 3')
        && str_contains($html, 'CINQUE') && str_contains($html, e(CartPresenter::money('5.00', 'EUR')))
        && str_contains($html, e((string) __t('ecommerce.account.coupons.free_shipping')))
        && str_contains($html, e((string) __t('ecommerce.account.coupons.store_credit')))
        && str_contains($html, '0 / '.e((string) __t('ecommerce.account.coupons.unlimited')))
        && !str_contains($html, 'SPENTO') && !str_contains($html, 'SCADUTO') && !str_contains($html, 'FUTURO') && !str_contains($html, 'ELIMINATO')
        && str_contains($html, (string) __t('account.pagination.summary', ['from' => 1, 'to' => 4, 'total' => 4]))
        && array_slice(array_keys($nav), 0, 3) === ['overview', 'orders', 'coupons'];
}));

check('coupon: senza coupon stato vuoto; funzionalità spenta dà 404 e niente voce', fn () => prova(static function (): bool {
    [$userId] = clienteConScheda('coupon-vuoto');
    $_SESSION['user_id'] = $userId;
    accendiFunzionalita(['coupons']);
    $vuoto = paginaNegozio('coupons');
    spegniFunzionalita(['coupons']);
    $spento = paginaNegozio('coupons');
    $nav = AccountRoutes::panel()->navigation(\infoUser($userId, 'id'), '');

    return str_contains($vuoto, 'wi-empty-state') && str_contains($vuoto, e((string) __t('ecommerce.account.coupons.empty')))
        && $spento === '404' && !isset($nav['coupons']);
}));
```

`Model::update(array $values, int|string $id)` con `deleted = 'true'` è
l'eliminazione morbida; `find()` salta già le righe eliminate.

Run: `cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/AccountOrdersTest.php`
Expected: i due casi nuovi falliscono (`coupons` va in `parent::handle` e dà 404).

- [ ] **Step 3: `AccountCoupons`, route, voce e azione**

`src/Frontend/Account/AccountCoupons.php`:

```php
<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Account;

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Promotions\Campaigns;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;

/** I coupon riservati al cliente che può usare adesso, con valore e usi pronti da stampare. */
final class AccountCoupons
{
    /** @return list<array{code: string, value: string, uses: string}> */
    public static function forCustomer(int $contactId, string $now): array
    {
        $rows = [];
        foreach (Coupons::reserved($contactId) as $reserved) {
            $coupon = $reserved['coupon'];
            if (Campaigns::status($coupon, $now) !== 'running') {
                continue;
            }
            $limit = (int) ($coupon['usage_limit_per_customer'] ?? 0);
            $rows[] = [
                'code' => (string) $coupon['code'],
                'value' => self::value($coupon),
                'uses' => $reserved['used'].' / '.($limit > 0 ? (string) $limit : (string) __t('ecommerce.account.coupons.unlimited')),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $rows;
    }

    private static function value(array $coupon): string
    {
        return match ((string) ($coupon['discount_type'] ?? '')) {
            'percent' => OrderSheet::number($coupon['discount_value'] ?? 0).'%',
            'amount' => CartPresenter::money($coupon['discount_value'] ?? 0, 'EUR'),
            'free_shipping' => (string) __t('ecommerce.account.coupons.free_shipping'),
            'store_credit' => (string) __t('ecommerce.account.coupons.store_credit'),
            default => '—',
        };
    }
}
```

`OrderSheet::number('10.00')` dà `10`: niente zeri inutili, virgola decimale.

In `EcommerceAccountExtension`:
- In `routes()` aggiungi
  `Route::get('/coupon/', $handler, ['account_action' => 'coupons'])->name('coupons');`.
  La route c'è sempre; con la funzionalità spenta è il controller a dare 404.
- In `navigation()`, dopo `$own = ['orders' => …]`:
  ```php
  if (Gestionale::feature('coupons')) {
      $own['coupons'] = ['label' => (string) __t('ecommerce.account.coupons.title'), 'href' => Route::url('account.coupons'), 'icon' => 'bi bi-ticket-perforated'];
  }
  ```
  Aggiungi `use Wonder\Plugin\Gestionale\Gestionale;`.

In `EcommerceAccountController::handle()` aggiungi `'coupons' => $this->coupons()`,
poi:

```php
protected function coupons(): void
{
    if (!Gestionale::feature('coupons')) {
        $this->notFound();
    }

    $contactId = (int) ($this->contact((int) $this->user()->id)['id'] ?? 0);
    $coupons = AccountCoupons::forCustomer($contactId, date('Y-m-d H:i:s'));
    $pagination = AccountPagination::make(count($coupons), AccountPagination::requested());

    $this->page(Ecommerce::viewPath('pages/account/coupons.php'), 'coupons', [
        'title' => __t('ecommerce.account.coupons.title'),
        'seo_url' => Route::url('account.coupons'),
        'errors' => $contactId > 0 ? [] : [(string) __t('account.errors.contact')],
        'coupons' => array_slice($coupons, $pagination['offset'], $pagination['per_page']),
        'pagination' => $pagination,
        'base_url' => Route::url('account.coupons'),
    ]);
}
```

- [ ] **Step 4: Vista e testi**

`view/pages/account/coupons.php` segue `orders.php` del Task 2:
- `$account_panel->layout(get_defined_vars())`.
- Senza coupon:
  `.wi-empty-state` con `bi bi-ticket-perforated` e
  `ecommerce.account.coupons.empty`.
- Con coupon: `.wi-row-table` con
  `--wi-row-table-columns: repeat(3, minmax(0, 1fr))`.
  - Testata: `code`, `value` e `uses`.
  - Ogni riga ha tre celle: il codice in `.wi-row-table__title`, poi `value` e
    `uses`. Tutto passa da `e()`.
  - Sotto, il componente `frontend.account.pagination` con `$pagination` e
    `$base_url`.
- `View::end()`.

Chiavi `account.coupons` in it ed en:

| Chiave | it | en |
|---|---|---|
| `title` | Coupon | Coupons |
| `empty` | Non hai coupon attivi | You have no active coupons |
| `code` | Codice | Code |
| `value` | Valore | Value |
| `uses` | Usi | Uses |
| `unlimited` | illimitato | unlimited |
| `free_shipping` | Spedizione gratuita | Free shipping |
| `store_credit` | Credito | Credit |

In `tests/EcommerceTest.php` aggiungi `account.coupons` alle route attese.

- [ ] **Step 5: Lancia i test**

Run:
```bash
cd $W/ecommerce && WI_TEST_SITE=$S php tests/integrazione/AccountOrdersTest.php
cd $W/ecommerce && WI_TEST_SITE=$S WI_TEST_URL=https://ecommerce-account.test php tests/run.php
```
Expected: tutti `ok`; poi `Tutti i test dell'ecommerce passano.`

- [ ] **Step 6: Commit**

```bash
cd $W/ecommerce && git add src/Frontend/Account view/pages/account/coupons.php lang/it/ecommerce.json lang/en/ecommerce.json tests/EcommerceTest.php tests/integrazione/AccountOrdersTest.php
git commit -m "Account: coupon riservati al cliente con valore e usi

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Documenti, suite complete e prova nel browser

Repo: tutti e quattro.

**Files:**
- Modify: `$W/app/docs/app/concetti/utenti/auth-frontend.md`, `$W/app/CHANGELOG.md`
- Modify: `$W/ecommerce/CHANGELOG.md`, `$W/ecommerce/TODO.md:129`
- Modify: `$W/gestionale/CHANGELOG.md`, `$W/gestionale/TODO.md`

- [ ] **Step 1: Documenti**

- **`auth-frontend.md`**, nella sezione «Pannello account» del piano 1:
  - `AccountPagination::make()`;
  - il componente `frontend.account.pagination`, con un esempio di tre righe
    in una sezione a righe;
  - la regola `?pagina=N`.
- **CHANGELOG del core**, sotto `Unreleased`: paginazione del pannello.
- **CHANGELOG dell'ecommerce**, sotto `Unreleased`:
  - Ordini, dettaglio ordine e Coupon nel pannello del cliente;
  - `CheckoutSummary::paymentIcons()`.
- **CHANGELOG del gestionale**: `Coupons::reserved()`; `CustomerSheet::coupons()`
  lo usa.
- **TODO dell'ecommerce:** C4 chiuso senza i resi, con data 2026-10-08 e un
  rimando alla spec. I resi restano una voce aperta.
- **TODO del gestionale:** pannello account, piano 2 fatto; in attesa della
  prova nel browser dell'utente.

- [ ] **Step 2: Suite complete**

```bash
cd $W/lib && npm test
cd $W/app && for t in tests/*.php; do php "$t" || echo "FALLITO $t"; done
cd $W/gestionale && php tests/run.php
cd $W/ecommerce && WI_TEST_SITE=$S WI_TEST_URL=https://ecommerce-account.test php tests/run.php
```

Expected: tutto verde. Un rosso già presente su main va segnalato con
l'output, non nascosto.

- [ ] **Step 3: Prova nel browser (l'utente fa il login)**

1. Se la lib è cambiata dopo il piano 1, riportala nel sito di prova:
   ```bash
   cd $W/lib && npm run build && rsync -a --delete dist/ $S/assets/lib/wonder-image/dist/ && git checkout -- dist
   ```
2. Chiedi all'utente di entrare su `https://ecommerce-account.test/account/`
   con un cliente di prova che abbia ordini e un coupon riservato. Il login non
   lo fai tu.
3. Su 1280, 768 e 390 px controlla:
   - Ordini e Coupon nel menu, nella posizione giusta e attivi sulla loro
     pagina;
   - in Ordini: righe, totale grande, «Visualizza ›», piede «Risultati da…» e
     quadrati della paginazione, con la pagina attiva nera e le frecce grigie
     agli estremi;
   - nel dettaglio: Informazioni con loghi e tracking, prodotti con immagine,
     Riepilogo e due schede indirizzo affiancate (una sotto l'altra a 390);
   - in Coupon: le tre colonne a 1280 e le righe leggibili a 390;
   - gli stati vuoti, con un cliente senza ordini.
4. Salva le schermate nella cartella scratchpad e mandale all'utente con
   SendUserFile.

- [ ] **Step 4: Commit dei documenti**

```bash
cd $W/app && git add docs/app/concetti/utenti/auth-frontend.md CHANGELOG.md
git commit -m "Docs: paginazione del pannello account

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
cd $W/ecommerce && git add CHANGELOG.md TODO.md
git commit -m "Docs: ordini e coupon nel pannello, C4 chiuso senza resi

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
cd $W/gestionale && git add CHANGELOG.md TODO.md
git commit -m "Docs: coupon riservati in un posto solo; stato del piano 2

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Push, PR e unione su main si fanno solo dopo la conferma dell'utente. Poi si
segue la regola di memoria «PR: merge e pulizia li faccio io».
