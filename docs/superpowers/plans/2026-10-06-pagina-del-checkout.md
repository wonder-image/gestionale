# La pagina del checkout (E1c D5, piano 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** La pagina `/checkout/` del modulo `ecommerce` sceglie consegna (spedizione o ritiro), metodo di spedizione, sede, pagamento e coupon, e il riepilogo si ricalcola mentre il cliente compila.

**Architecture:** Due classi nuove in `src/Frontend/Checkout/` tengono la logica provabile senza HTTP: `CheckoutForm` (modulo → dati per il gestionale) e `CheckoutSummary` (anteprima e coupon → JSON). Il `CheckoutController` aggiunge le rotte `summary` e `coupon` e corregge `place`. `checkout.js` ascolta il modulo, chiama `summary` dopo una pausa e ridisegna. Il gestionale (piano 1, già unito: `Checkout::preview`, `PickupPoints`, controlli di `place`) decide tutto; il modulo non duplica regole.

**Tech Stack:** PHP 8.2+, framework Wonder Image (`View`, `FormField`, `Route`), JavaScript senza librerie, harness `tests/harness.php` del modulo, sito di prova `/Users/andreamarinoni/Developer/boilerplates/ecommerce-site`.

**Spec:** `docs/superpowers/specs/2026-10-06-checkout-con-spedizione-design.md`, sezioni 3 «Piano 2» e 4 «Errori, casi limite e test». Questo piano vive nel repo `gestionale` con la spec; il codice va nel repo `/Users/andreamarinoni/Developer/packages/ecommerce` (ramo `e1c-pagina-checkout`, tree pulito su `main`).

## Global Constraints

- Codice, commenti, test e frasi in italiano; commit con trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; niente push senza ok.
- Il modulo non riscrive regole del gestionale: legge `Checkout::preview`, `Checkout::place`, `Coupons::apply`/`remove`.
- Frontend (`.claude/rules/frontend-seo-tracking.md`): checkout `NOINDEX,NOFOLLOW`, nessun dato strutturato, `$SEO->breadcrumb = []`, un solo `<h1>`; eventi GA4 `begin_checkout`, `add_shipping_info`, `add_payment_info` con `dataLayer.push({ ecommerce: null })` prima di ognuno e prezzi come numeri.
- Rotte `summary` e `coupon`: POST, CSRF, cliente autenticato o ospite ammesso, nessun reCAPTCHA. Le guardie rispondono JSON (419, 401), mai testo nudo.
- Con `shipping` spenta la sezione consegna non c'è e il checkout resta com'è oggi; con `coupons` spenta il campo coupon non c'è.
- Pagamenti: un provider è manuale se `PaymentMethod::ledgerProvider($provider) === 'manual'` (copre `bank_transfer`, `cash`); gli altri restano «non ancora disponibili» (D6).
- Prove d'integrazione sul sito di prova dentro `Transaction::run` annullata con `throw new Annulla()`; rossi noti preesistenti da non far crescere: nessuno in questo modulo.

## Review Focus

1. **Risposta lenta che arriva dopo una più recente:** `checkout.js` scarta le risposte non più attuali (Task 3, test del sorgente).
2. **Carrello svuotato o scaduto mentre si compila:** `summary` risponde 409 con `redirect` al carrello; il JS ci va (Task 1 e 3).
3. **Valori array nel modulo (`shipping_method_id[]=1`, `billing_city[]=x`):** `CheckoutForm` li scarta invece di sollevare un errore (Task 1).
4. **CSRF o login mancanti nelle rotte JSON:** 419 e 401 in JSON, `place` e `coupon` senza JS tornano alla pagina con il messaggio (Task 1).
5. **`coupons` o `shipping` spente:** niente campo coupon, niente sezione consegna, `place` funziona come prima (Task 2 e 1).

---

## Mappa dei file

- Crea `src/Frontend/Checkout/CheckoutForm.php` — dal `$_POST` ai dati per `preview`/`place`; `isManual()`.
- Crea `src/Frontend/Checkout/CheckoutSummary.php` — `payload()` (anteprima con importi già formattati) e `coupon()`.
- Modifica `src/Frontend/Checkout/CheckoutController.php` — azioni `summary` e `coupon`; `place` con `CheckoutForm`; `index` passa i dati alla vista.
- Modifica `config/routes/route.frontend.php` — due rotte POST.
- Modifica `view/pages/checkout/index.php` — pagina a due colonne con consegna, metodi, sedi, coupon.
- Crea `resources/assets/js/checkout.js`.
- Modifica `lang/it/ecommerce.json`, `lang/en/ecommerce.json`.
- Crea `tests/CheckoutFormTest.php`, `tests/integrazione/CheckoutSummaryTest.php`; modifica `tests/CartCheckoutTest.php`.
- Modifica `docs/cart-checkout.md`, `CHANGELOG.md`, `TODO.md` del modulo; `CHANGELOG.md` e `TODO.md` del gestionale.

---

### Task 1: `CheckoutForm`, `CheckoutSummary`, rotte e `place`

**Files:**
- Create: `src/Frontend/Checkout/CheckoutForm.php`, `src/Frontend/Checkout/CheckoutSummary.php`
- Modify: `src/Frontend/Checkout/CheckoutController.php`, `config/routes/route.frontend.php:55-63`
- Test: `tests/CheckoutFormTest.php`, `tests/integrazione/CheckoutSummaryTest.php`, `tests/CartCheckoutTest.php`

**Interfaces:**
- Produces: `CheckoutForm::data(array $post, ?object $user = null): array` — chiavi `email, phone, payment_method_id, fulfillment_type ('shipping'|'pickup'), shipping_method_id, location_id, customer_note, billing, shipping` (indirizzi come `array<string,string>` senza prefisso); `CheckoutForm::isManual(array $method): bool`.
- Produces: `CheckoutSummary::payload(int $cartId, array $post, ?object $user = null): array` — il risultato di `Checkout::preview` più `display` (importi formattati: `products_total, discount_total, shipping_total, fees_total, total`), `items[*].line_total_display`/`quantity_display`, `shipping_methods.options[*].price_display` («Gratis» se `free`); `CheckoutSummary::coupon(int $cartId, string $action, string $code, array $post): array` — come `payload` più `error` (stringa, vuota se ok).
- Produces: rotte `ecommerce.checkout.summary` (`POST /checkout/summary/`) e `ecommerce.checkout.coupon` (`POST /checkout/coupon/`).
- Consumes: `Checkout::preview(int, array): array` (piano 1), `Coupons::apply(int, string)`, `Coupons::remove(int)`, `CartPresenter::money()`.

- [ ] **Step 1: Test di `CheckoutForm`** — crea `tests/CheckoutFormTest.php`:

```php
<?php
/** php tests/CheckoutFormTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutForm;

check('il modulo diventa i dati del gestionale, con la consegna e gli indirizzi', function () {
    $dati = CheckoutForm::data([
        'email' => ' a@example.com ', 'phone' => '333', 'payment_method_id' => '7',
        'fulfillment_type' => 'pickup', 'shipping_method_id' => '3', 'location_id' => '5',
        'customer_note' => 'ciao', 'billing_city' => 'Milano', 'shipping_city' => 'Roma',
        'csrf_token' => 'x',
    ]);

    return $dati['email'] === 'a@example.com'
        && $dati['payment_method_id'] === 7
        && $dati['fulfillment_type'] === 'pickup'
        && $dati['shipping_method_id'] === 3
        && $dati['location_id'] === 5
        && $dati['billing'] === ['city' => 'Milano']
        && $dati['shipping'] === ['city' => 'Roma'];
});

check('una consegna sconosciuta o assente è spedizione', fn () =>
    CheckoutForm::data(['fulfillment_type' => 'none'])['fulfillment_type'] === 'shipping'
    && CheckoutForm::data([])['fulfillment_type'] === 'shipping');

check('i valori array o negativi non rompono: diventano zero o vuoto', function () {
    $dati = CheckoutForm::data(['shipping_method_id' => ['1'], 'location_id' => '-4', 'billing_city' => ['x'], 'email' => ['a']]);

    return $dati['shipping_method_id'] === 0 && $dati['location_id'] === 0
        && $dati['billing'] === [] && $dati['email'] === '';
});

check('l\'utente riempie email e cellulare mancanti', function () {
    $dati = CheckoutForm::data([], (object) ['email' => 'u@example.com', 'phone' => '999']);

    return $dati['email'] === 'u@example.com' && $dati['phone'] === '999';
});

check('un provider è manuale per bonifico, contanti e «manual»; Stripe no', fn () =>
    CheckoutForm::isManual(['provider' => 'bank_transfer'])
    && CheckoutForm::isManual(['provider' => 'cash'])
    && CheckoutForm::isManual(['provider' => 'manual'])
    && !CheckoutForm::isManual(['provider' => 'stripe']));

summary();
```

- [ ] **Step 2: Run** `php tests/CheckoutFormTest.php` — Expected: FAIL, classe non trovata.

- [ ] **Step 3: Scrivi `CheckoutForm`** — `src/Frontend/Checkout/CheckoutForm.php`:

```php
<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;

/** Il modulo del checkout come lo vuole il gestionale: scalari puliti, mai array. */
final class CheckoutForm
{
    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function data(array $post, ?object $user = null): array
    {
        $consegna = self::text($post['fulfillment_type'] ?? '');

        return [
            'email' => self::text($post['email'] ?? ($user->email ?? '')),
            'phone' => self::text($post['phone'] ?? ($user->phone ?? '')),
            'payment_method_id' => self::id($post['payment_method_id'] ?? 0),
            'fulfillment_type' => $consegna === 'pickup' ? 'pickup' : 'shipping',
            'shipping_method_id' => self::id($post['shipping_method_id'] ?? 0),
            'location_id' => self::id($post['location_id'] ?? 0),
            'customer_note' => self::text($post['customer_note'] ?? ''),
            'billing' => self::address($post, 'billing_'),
            'shipping' => self::address($post, 'shipping_'),
        ];
    }

    /** @param array<string, mixed> $method */
    public static function isManual(array $method): bool
    {
        return PaymentMethod::ledgerProvider((string) ($method['provider'] ?? '')) === 'manual';
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function id(mixed $value): int
    {
        return is_scalar($value) && (int) $value > 0 ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    private static function address(array $post, string $prefix): array
    {
        $risultato = [];

        foreach ($post as $chiave => $valore) {
            if (is_string($chiave) && str_starts_with($chiave, $prefix) && is_scalar($valore)) {
                $risultato[substr($chiave, strlen($prefix))] = trim((string) $valore);
            }
        }

        return $risultato;
    }
}
```

- [ ] **Step 4: Run** `php tests/CheckoutFormTest.php` — Expected: `5 test, 0 falliti`.

- [ ] **Step 5: Test d'integrazione di `CheckoutSummary` e `place`** — crea `tests/integrazione/CheckoutSummaryTest.php` (le funzioni di prova `zona`, `metodo`, `listino`, `carrello`, `articolo`, `sede`, `giacenzaIn`, `accendiFunzionalita`, `spegniFunzionalita` vengono dal gestionale, `packages/gestionale/tests/integrazione/supporto/`):

```php
<?php
/** php tests/integrazione/CheckoutSummaryTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/dns-fixture.php';

$gestionale = dirname(__DIR__, 3).'/gestionale/tests/integrazione/supporto';
require $gestionale.'/compra.php';
require $gestionale.'/spedizioni.php';

use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutForm;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutSummary;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Seeding\ShippingDemo;
use Wonder\Plugin\Gestionale\Support\Notifications\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** Parte senza metodi e sedi del sito di prova, con shipping e coupons accese; la transazione rimette tutto. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            ShippingDemo::clear();
            sqlModify(ShippingMethod::$table, ['active' => 'false'], 'active', 'true');
            sqlModify(Location::$table, ['is_pickup_point' => 'false'], 'is_pickup_point', 'true');
            accendiFunzionalita(['orders', 'shipping', 'coupons']);
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    return $esito;
}

/** Un metodo per tutta Italia: 8 € fino a 5 kg. */
function standard(): int
{
    $metodo = metodo('Standard');
    listino($metodo, zona('Italia Standard', [['IT', '']]), [[5, 8.0], [20, 15.0]]);

    return $metodo;
}

function modulo(array $extra = []): array
{
    return $extra + ['shipping_country' => 'IT', 'shipping_province' => 'MI', 'shipping_city' => 'Milano',
        'shipping_cap' => '20100', 'shipping_street' => 'Via Prova', 'shipping_number' => '1'];
}

function manuale(): int
{
    return (int) (PaymentMethod::create([
        'name' => 'Bonifico di prova', 'provider' => 'bank_transfer', 'timing' => 'deferred', 'active' => 'true',
        'applies_online' => 'true', 'available_for' => 'all', 'fee_type' => 'none', 'fee_value' => '0.00',
    ])->insert_id ?? 0);
}

check('l\'anteprima dà importi formattati e un solo metodo di spedizione già scelto', fn () => prova(static function (): bool {
    $metodo = standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $p = CheckoutSummary::payload($cart, modulo());

    return $p['shipping_methods']['selected'] === $metodo
        && $p['shipping_methods']['options'][0]['price_display'] === '8,00 €'
        && $p['display']['total'] === '18,00 €'
        && $p['items'][0]['line_total_display'] === '10,00 €';
}));

check('un metodo gratuito dice «Gratis»', fn () => prova(static function (): bool {
    $metodo = metodo('Gratis');
    listino($metodo, zona('Italia Gratis', [['IT', '']]), [[5, 0.0]]);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $p = CheckoutSummary::payload($cart, modulo());

    return $p['shipping_methods']['options'][0]['price_display'] === (string) __t('ecommerce.checkout.free');
}));

check('il coupon si applica e si toglie; uno sconosciuto dà il messaggio del gestionale', fn () => prova(static function (): bool {
    standard();
    $codice = 'P'.bin2hex(random_bytes(3));
    Coupon::create(['code' => $codice, 'type' => 'percent', 'value' => '10.00', 'active' => 'true']);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    $su = CheckoutSummary::coupon($cart, 'apply', $codice, modulo());
    $giu = CheckoutSummary::coupon($cart, 'remove', '', modulo());
    $no = CheckoutSummary::coupon($cart, 'apply', 'NONESISTE', modulo());

    return $su['error'] === '' && $su['coupon']['code'] === $codice && (float) $su['order']['discount_total'] > 0
        && $giu['error'] === '' && $giu['coupon']['code'] === ''
        && $no['error'] !== '' && $no['coupon']['code'] === '';
}));

check('con i coupon spenti l\'applicazione dà un errore e il carrello non cambia', fn () => prova(static function (): bool {
    standard();
    spegniFunzionalita(['coupons']);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $p = CheckoutSummary::coupon($cart, 'apply', 'QUALSIASI', modulo());

    return $p['error'] !== '' && $p['coupon']['code'] === '';
}));

/** Come fa il controller: dal modulo ai dati di `place`. */
function invia(int $cart, array $post, int $pagamento): array
{
    Mailer::useTransport(static fn (): bool => true);

    try {
        return Checkout::place($cart, CheckoutForm::data($post + ['payment_method_id' => $pagamento]) + [
            'customer_id' => 0, 'source' => 'ecommerce', 'user_id' => 0,
        ]);
    } finally {
        Mailer::useTransport(null);
    }
}

check('un ordine con spedizione nasce con la sua riga e il bonifico è accettato', fn () => prova(static function (): bool {
    $metodo = standard();
    $pagamento = manuale();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $post = modulo(['email' => 'c@example.com', 'billing_country' => 'IT', 'billing_name' => 'Mario', 'billing_surname' => 'Rossi',
        'billing_city' => 'Milano', 'billing_cap' => '20100', 'billing_street' => 'Via Prova', 'billing_number' => '1',
        'fulfillment_type' => 'shipping', 'shipping_method_id' => (string) $metodo]);
    $esito = invia($cart, $post, $pagamento);

    return $esito['status'] === 'pending'
        && (float) Order::findById($cart)['shipping_total'] === 8.0
        && CheckoutForm::isManual(PaymentMethod::findById($pagamento));
}));

check('senza metodo di spedizione l\'ordine del negozio si ferma', function (): bool {
    return prova(static function (): bool {
        standard();
        $pagamento = manuale();
        $cart = carrello([[articolo(2.0, 10.0), 1]]);

        try {
            invia($cart, modulo(['email' => 'c@example.com', 'billing_country' => 'IT', 'billing_name' => 'Mario', 'billing_surname' => 'Rossi',
                'billing_city' => 'Milano', 'billing_cap' => '20100', 'billing_street' => 'Via Prova', 'billing_number' => '1']), $pagamento);
        } catch (\Wonder\Plugin\Gestionale\Support\Errors\UserError $e) {
            return $e->key() === 'order.shipping_method_required';
        }

        return false;
    });
});

check('un ordine col ritiro nasce senza riga di spedizione, nella sede scelta', fn () => prova(static function (): bool {
    standard();
    $pagamento = manuale();
    $sede = sede();
    $prodotto = articolo(2.0, 10.0);
    giacenzaIn($prodotto, $sede, 5);
    $cart = carrello([[$prodotto, 1]]);
    $esito = invia($cart, ['email' => 'c@example.com', 'fulfillment_type' => 'pickup', 'location_id' => (string) $sede,
        'billing_country' => 'IT', 'billing_name' => 'Mario', 'billing_surname' => 'Rossi', 'billing_city' => 'Milano',
        'billing_cap' => '20100', 'billing_street' => 'Via Prova', 'billing_number' => '1'], $pagamento);

    return $esito['status'] === 'pending'
        && (float) Order::findById($cart)['shipping_total'] === 0.0
        && (int) Order::findById($cart)['location_id'] === $sede;
}));

summary();
```

- [ ] **Step 6: Run** `php tests/integrazione/CheckoutSummaryTest.php` — Expected: FAIL (`CheckoutSummary` non esiste). Se un nome di campo di `Coupon::create`/`PaymentMethod::create` non coincide col Model, si corregge qui guardando `gestionale/tests/integrazione/CheckoutSpedizioneTest.php` (`pagamento()`, `couponDiProva()`), non il codice.

- [ ] **Step 7: Scrivi `CheckoutSummary`** — `src/Frontend/Checkout/CheckoutSummary.php`:

```php
<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;

/** L'anteprima del checkout per la pagina: il gestionale calcola, qui si formatta. */
final class CheckoutSummary
{
    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function payload(int $cartId, array $post, ?object $user = null): array
    {
        $preview = Checkout::preview($cartId, CheckoutForm::data($post, $user));
        $valuta = (string) $preview['order']['currency'];

        $preview['display'] = [];
        foreach (['products_total', 'discount_total', 'shipping_total', 'fees_total', 'total'] as $chiave) {
            $preview['display'][$chiave] = CartPresenter::money($preview['order'][$chiave], $valuta);
        }

        foreach ($preview['items'] as $i => $riga) {
            $preview['items'][$i]['line_total_display'] = CartPresenter::money($riga['line_total'] ?? 0, $valuta);
            $preview['items'][$i]['quantity_display'] = CartPresenter::quantity($riga['quantity'] ?? 1);
        }

        foreach ($preview['shipping_methods']['options'] as $i => $opzione) {
            $preview['shipping_methods']['options'][$i]['price_display'] = !empty($opzione['free'])
                ? (string) __t('ecommerce.checkout.free')
                : CartPresenter::money($opzione['price'] ?? 0, $valuta);
        }

        return $preview;
    }

    /**
     * Applica o toglie il coupon, poi ridà l'anteprima. Un codice rifiutato
     * non è un errore della pagina: il messaggio del gestionale va in `error`.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function coupon(int $cartId, string $action, string $code, array $post, ?object $user = null): array
    {
        $errore = '';

        try {
            $action === 'remove' ? Coupons::remove($cartId) : Coupons::apply($cartId, trim($code));
        } catch (UserError $e) {
            $errore = $e->getMessage();
        }

        return self::payload($cartId, $post, $user) + ['error' => $errore];
    }
}
```

- [ ] **Step 8: Rotte** — in `config/routes/route.frontend.php`, dopo la riga di `place` (riga 61):

```php
                Route::post('/summary/', $handler, ['checkout_action' => 'summary'])->name('summary');
                Route::post('/coupon/', $handler, ['checkout_action' => 'coupon'])->name('coupon');
```

- [ ] **Step 9: Controller.** In `CheckoutController.php`:

  1. Aggiungi `use Wonder\Plugin\Gestionale\Support\Errors\UserError;` (già c'è) e nel `match` di `handle`: `'summary' => self::summary(), 'coupon' => self::coupon(),`.
  2. Aggiungi le azioni:

```php
    private static function summary(): never
    {
        $cartId = self::guardJson();

        try {
            self::json(['success' => true] + CheckoutSummary::payload($cartId, $_POST, CartSession::user()));
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.summary');
            self::json(['success' => false, 'error' => (string) __t('ecommerce.checkout.summary_error')], 500);
        }
    }

    private static function coupon(): void
    {
        $json = self::wantsJson();
        $cartId = $json ? self::guardJson() : self::guardPage();
        $action = (string) ($_POST['action'] ?? 'apply') === 'remove' ? 'remove' : 'apply';

        try {
            $payload = CheckoutSummary::coupon($cartId, $action, (string) ($_POST['code'] ?? ''), $_POST, CartSession::user());
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.coupon');
            $json
                ? self::json(['success' => false, 'error' => (string) __t('ecommerce.checkout.summary_error')], 500)
                : self::flash([(string) __t('ecommerce.checkout.errors.generic')], $_POST);
            self::redirect(self::route('ecommerce.checkout.index'));
        }

        if ($json) {
            self::json(['success' => $payload['error'] === ''] + $payload);
        }

        self::flash($payload['error'] !== '' ? [$payload['error']] : [], $_POST);
        self::redirect(self::route('ecommerce.checkout.index'));
    }

    /** Le guardie delle rotte JSON: POST, CSRF, login; ridà l'id del carrello. */
    private static function guardJson(): int
    {
        self::requirePost();

        if (!AuthSession::verify($_POST['csrf_token'] ?? '')) {
            self::json(['success' => false, 'error' => (string) __t('ecommerce.checkout.summary_error')], 419);
        }

        if (!self::guestAllowed() && !CartSession::authenticated()) {
            self::json(['success' => false, 'redirect' => self::loginUrl()], 401);
        }

        return self::cartId() ?: self::json(['success' => false, 'redirect' => self::route('ecommerce.cart.index')], 409);
    }

    /** Le stesse guardie per la pagina senza JavaScript. */
    private static function guardPage(): int
    {
        self::requirePost();
        self::requireCsrf();

        if (!self::guestAllowed() && !CartSession::authenticated()) {
            self::redirect(self::loginUrl());
        }

        return self::cartId() ?: self::redirect(self::route('ecommerce.cart.index'));
    }

    /** L'id del carrello del cliente, 0 se non c'è o è vuoto. */
    private static function cartId(): int
    {
        $cart = CartSession::current(false);

        return (array) ($cart['items'] ?? []) === [] ? 0 : (int) ($cart['order']['id'] ?? 0);
    }

    private static function wantsJson(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    }

    private static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
```

  3. In `place()`: sostituisci il controllo `!== 'manual'` con `if (!CheckoutForm::isManual($method))`; sostituisci l'array passato a `Checkout::place` con
     `CheckoutForm::data($_POST, $user) + ['customer_id' => CartSession::customerId(), 'source' => 'ecommerce', 'user_id' => (int) ($user->id ?? 0)]`; `$methodId = (int) ($_POST['payment_method_id'] ?? 0)` diventa `CheckoutForm::data($_POST)['payment_method_id']`. Togli i metodi `address()` e l'uso dell'array vecchio (restano `paymentMethods()` e `method()`).
  4. In `paymentMethods()` togli il filtro `available_for` (lo fa il gestionale per consegna): `place` accetta ogni metodo online attivo e `Checkout::place` rifiuta quello non ammesso. Per la pagina senza JavaScript, `index()` continua a mostrare quelli `all`/`shipping` filtrando a parte: tieni il filtro in un metodo `fallbackMethods()` usato solo da `index()`.

- [ ] **Step 10: Sorgente del controller** — in `tests/CartCheckoutTest.php` aggiungi:

```php
check('summary e coupon hanno le guardie in JSON e place usa CheckoutForm', function () use ($root) {
    $routes = (string) file_get_contents($root.'/config/routes/route.frontend.php');
    $c = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');

    return str_contains($routes, "['checkout_action' => 'summary']")
        && str_contains($routes, "['checkout_action' => 'coupon']")
        && str_contains($c, ", 419)")
        && str_contains($c, ", 401)")
        && str_contains($c, "'redirect' => self::route('ecommerce.cart.index')")
        && str_contains($c, 'CheckoutForm::data($_POST')
        && str_contains($c, 'CheckoutForm::isManual($method)')
        && !str_contains($c, "'shipping_method_id' => 0");
});
```

- [ ] **Step 11: Run** `php tests/CheckoutFormTest.php && php tests/integrazione/CheckoutSummaryTest.php && php tests/CartCheckoutTest.php && php tests/run.php > /tmp/e.txt 2>&1; tail -3 /tmp/e.txt` — Expected: tutto verde; nessun rosso nuovo.

- [ ] **Step 12: Commit** (repo `ecommerce`, ramo `e1c-pagina-checkout`):

```bash
git add src/Frontend/Checkout config/routes/route.frontend.php tests/CheckoutFormTest.php tests/integrazione/CheckoutSummaryTest.php tests/CartCheckoutTest.php
git commit -m "E1c D5: rotte summary e coupon, CheckoutForm e CheckoutSummary, place con consegna e metodi manuali

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: La pagina e i testi

**Files:**
- Modify: `view/pages/checkout/index.php`, `src/Frontend/Checkout/CheckoutController.php` (`index()`), `lang/it/ecommerce.json`, `lang/en/ecommerce.json`
- Test: `tests/CartCheckoutTest.php`

**Interfaces:**
- Consumes: `CheckoutSummary::payload` (Task 1), `Gestionale::feature('shipping'|'coupons')` (`Wonder\Plugin\Gestionale\Gestionale`), `DataLayer::script()` (come in `view/pages/frontend/product.php:44`).
- Produces: attributi del modulo letti da `checkout.js` (Task 3) — `form#checkout[data-shipping="on|off"][data-coupons="on|off"][data-summary-url][data-coupon-url][data-labels]` e i contenitori `[data-checkout-fulfillment]`, `[data-checkout-shipping]` (con `[data-checkout-shipping-methods]`, `[data-checkout-notice]`), `[data-checkout-pickup]`, `[data-checkout-payments]`, `[data-checkout-lines]`, `[data-checkout-totals]`, `[data-checkout-notices]`, `[data-checkout-coupon]`, `[data-checkout-submit]`. I campi nati dal JS si chiamano `fulfillment_type`, `shipping_method_id`, `location_id`, `payment_method_id` (radio).

- [ ] **Step 1: Test del sorgente** — in `tests/CartCheckoutTest.php`, sostituisci l'ultima asserzione del test «carrello e checkout hanno layout sigillati…» se serve (`id="checkout"` resta) e aggiungi:

```php
check('la pagina del checkout ha i contenitori per il JS e il coupon fuori dal modulo principale', function () use ($root) {
    $view = (string) file_get_contents($root.'/view/pages/checkout/index.php');
    $it = json_decode((string) file_get_contents($root.'/lang/it/ecommerce.json'), true);
    $en = json_decode((string) file_get_contents($root.'/lang/en/ecommerce.json'), true);
    $chiavi = ['delivery', 'free', 'no_shipping', 'summary_error', 'shipping_total', 'fees_total', 'updating'];

    foreach ($chiavi as $k) {
        if (!isset($it['checkout'][$k], $en['checkout'][$k])) {
            return false;
        }
    }

    return str_contains($view, 'data-summary-url')
        && str_contains($view, 'data-checkout-shipping-methods')
        && str_contains($view, 'data-checkout-pickup')
        && str_contains($view, 'data-checkout-payments')
        && str_contains($view, 'data-checkout-coupon')
        && str_contains($view, 'form="checkout"')
        && str_contains($view, "Gestionale::feature('coupons')")
        && str_contains($view, "Gestionale::feature('shipping')")
        && str_contains($view, "'begin_checkout'")
        && substr_count($view, '<h1') === 1;
});
```

- [ ] **Step 2: Run** `php tests/CartCheckoutTest.php` — Expected: FAIL su questo test.

- [ ] **Step 3: Testi.** In `lang/it/ecommerce.json` dentro `checkout` aggiungi (sostituisci `place_order` con le due chiavi `submit_manual`/`submit_online`; le frasi inglesi in `lang/en` con le stesse chiavi):

```json
"delivery": "Consegna",
"fulfillment_shipping": "Spedizione",
"fulfillment_pickup": "Ritiro in sede",
"shipping_methods": "Metodo di spedizione",
"pickup_locations": "Sede di ritiro",
"no_shipping": "A questo indirizzo non spediamo: cambia l’indirizzo o scegli il ritiro in sede.",
"free": "Gratis",
"shipping_total": "Spedizione",
"fees_total": "Commissione di pagamento",
"coupon_label": "Hai un codice sconto?",
"coupon_apply": "Applica",
"coupon_remove": "Togli",
"submit_manual": "Invia ordine",
"submit_online": "Vai al pagamento",
"updating": "Aggiorno il riepilogo…",
"summary_error": "Non è stato possibile aggiornare il riepilogo. Controlla i dati e riprova."
```

Inglese: «Delivery», «Shipping», «Pick up in store», «Shipping method», «Pick-up location», «We don’t ship to this address: change the address or choose store pick-up.», «Free», «Shipping», «Payment fee», «Have a discount code?», «Apply», «Remove», «Place order», «Go to payment», «Updating summary…», «The summary could not be updated. Check the details and try again.»

- [ ] **Step 4: La vista.** Riscrivi `view/pages/checkout/index.php` mantenendo i campi esistenti (contatto, fatturazione, indirizzo di spedizione, note, reCAPTCHA) con queste differenze:
  - `use Wonder\Plugin\Gestionale\Gestionale;` e `DataLayer`.
  - `$shipping = Gestionale::feature('shipping'); $coupons = Gestionale::feature('coupons');`
  - il `<form id="checkout" …>` avvolge **solo la colonna sinistra** e porta `data-shipping="<?=$shipping ? 'on' : 'off'?>" data-coupons="…" data-summary-url="<?=e(__r('ecommerce.checkout.summary'))?>" data-coupon-url="<?=e(__r('ecommerce.checkout.coupon'))?>" data-labels='<?=e(json_encode([...], JSON_UNESCAPED_UNICODE))?>'` (le etichette: `free, no_shipping, shipping_total, fees_total, discount, coupon_remove, submit_manual, submit_online, updating, summary_error, shipping_methods, pickup_locations`, tutte da `__t`); `<aside>` esce dal form: il pulsante lo richiama con `form="checkout"`.
  - dopo «fatturazione», se `$shipping`, la sezione **Consegna** (`data-checkout-fulfillment`: radio `fulfillment_type` Spedizione/Ritiro, il radio Ritiro nascosto di default finché il JS non conferma che si può); l'indirizzo di spedizione sta in `<section data-checkout-shipping>` seguito da `<div data-checkout-shipping-methods>` e `<p data-checkout-notice hidden>`; `<div data-checkout-pickup hidden>` per le sedi. Senza `$shipping` resta la sola sezione dell'indirizzo, com'è oggi.
  - la sezione pagamento ha `<div data-checkout-payments>` con i metodi di `$payment_methods` come radio `payment_method_id` (resa lato server per chi non ha JS).
  - nell'aside: `<div data-checkout-lines>` con le righe come oggi; `<div data-checkout-totals>` con prodotti, sconto, spedizione, commissione, totale (come oggi, ma dentro il contenitore per essere ridisegnato); `<div data-checkout-notices>`; se `$coupons`, il blocco `data-checkout-coupon`: `<form id="checkout-coupon" method="post" action="<?=e(__r('ecommerce.checkout.coupon'))?>">` con `csrf_token` nascosto, campo `code`, pulsante `action=apply` («Applica») e, con un coupon attivo (`$order['coupon_code']`), codice e pulsante `action=remove` («Togli»); poi `<button type="submit" form="checkout" data-checkout-submit>`.
  - in fondo, `<?=DataLayer::script(['type' => 'checkout'], ['event' => 'begin_checkout', 'ecommerce' => ['currency' => $currency, 'value' => (float) ($order['total'] ?? 0), 'items' => $gaItems]], (int) (…user id…))?>` con `$gaItems` costruito dalle righe (`item_id`, `item_name`, `price` numero, `quantity` numero) — stesso schema di `view/pages/frontend/product.php`.
- `index()` del controller passa già `cart`, `payment_methods`, `values`: aggiungi `'coupon_code' => (string) ($cart['order']['coupon_code'] ?? '')` se la vista lo preferisce, e `'payment_methods' => self::fallbackMethods()`.

- [ ] **Step 5: Run** `php tests/CartCheckoutTest.php` — Expected: tutto verde. Poi apri `https://ecommerce.test/checkout/` col browser integrato (login necessario: se non c'è un cliente di prova, fermati e chiedi) e controlla che la pagina disegni senza errori PHP.

- [ ] **Step 6: Commit**

```bash
git add view/pages/checkout/index.php src/Frontend/Checkout/CheckoutController.php lang tests/CartCheckoutTest.php
git commit -m "E1c D5: la pagina del checkout a due colonne, con consegna, coupon e begin_checkout

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `checkout.js`

**Files:**
- Create: `resources/assets/js/checkout.js`
- Test: `tests/CartCheckoutTest.php`

**Interfaces:**
- Consumes: i contenitori e gli attributi del Task 2; la risposta di `POST summary` (Task 1): `order`, `display`, `items[*]` (`name`, `quantity_display`, `line_total_display`, `sku`, `quantity`, `line_total`), `fulfillment {type, choices}`, `shipping_methods {options[{method_id,name,description,price,price_display,free}], selected}`, `pickup_locations {options[{id,name,address}], selected}`, `payment_methods {options[{id,name,manual,instructions}], selected}`, `coupon`, `notices`, `invalid`; in errore `redirect`.
- Produces: `window.ecommerceCheckout` (istanza), eventi `add_shipping_info` e `add_payment_info`.

- [ ] **Step 1: Test del sorgente** — in `tests/CartCheckoutTest.php`:

```php
check('checkout.js scarta le risposte vecchie, aspetta una pausa e racconta a GTM le scelte', function () use ($root) {
    $js = (string) file_get_contents($root.'/resources/assets/js/checkout.js');

    return str_contains($js, 'class Checkout')
        && str_contains($js, 'this.sequence')
        && str_contains($js, 'if (sequence !== this.sequence)')
        && str_contains($js, 'setTimeout')
        && str_contains($js, '300')
        && str_contains($js, 'textContent')
        && !str_contains($js, 'innerHTML')
        && str_contains($js, "'add_shipping_info'")
        && str_contains($js, "'add_payment_info'")
        && str_contains($js, 'dataLayer.push({ ecommerce: null })')
        && str_contains($js, 'payload.redirect')
        && str_contains($js, 'X-Requested-With')
        && str_contains($js, 'window.ecommerceCheckout');
});
```

- [ ] **Step 2: Run** `php tests/CartCheckoutTest.php` — Expected: FAIL (file assente).

- [ ] **Step 3: Scrivi `resources/assets/js/checkout.js`:**

```js
class Checkout {
    constructor(selector = '#checkout') {
        this.form = document.querySelector(selector);
        this.sequence = 0;
        this.timer = null;

        if (!this.form || !this.form.dataset.summaryUrl) {
            return;
        }

        this.labels = JSON.parse(this.form.dataset.labels || '{}');
        this.page = this.form.closest('body');
        this.bind();
        this.refresh();
    }

    bind() {
        const watch = 'select, input[name^="billing_"], input[name^="shipping_"], [name="fulfillment_type"], [name="shipping_method_id"], [name="location_id"], [name="payment_method_id"]';

        this.form.addEventListener('change', (event) => {
            if (!event.target.matches(watch)) {
                return;
            }

            this.announce(event.target.name);
            this.schedule();
        });
        this.form.addEventListener('input', (event) => {
            if (event.target.matches('input[name^="billing_"], input[name^="shipping_"]')) {
                this.schedule();
            }
        });
        document.querySelector('#checkout-coupon')?.addEventListener('submit', (event) => this.coupon(event));
    }

    schedule() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.refresh(), 300);
    }

    body(extra = null) {
        const data = new FormData(this.form);

        if (extra) {
            Object.entries(extra).forEach(([key, value]) => data.set(key, value));
        }

        return data;
    }

    async request(url, data) {
        const sequence = ++this.sequence;
        this.busy(true);

        try {
            const response = await fetch(url, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            });
            const payload = await response.json();

            if (sequence !== this.sequence) {
                return null;
            }

            if (payload.redirect) {
                window.location.href = payload.redirect;

                return null;
            }

            return payload;
        } catch (error) {
            if (sequence === this.sequence) {
                this.notices([this.labels.summary_error]);
            }

            return null;
        } finally {
            if (sequence === this.sequence) {
                this.busy(false);
            }
        }
    }

    async refresh() {
        const payload = await this.request(this.form.dataset.summaryUrl, this.body());

        if (payload && payload.success !== false) {
            this.render(payload);
        } else if (payload) {
            this.notices([payload.error || this.labels.summary_error]);
        }
    }

    async coupon(event) {
        event.preventDefault();

        const couponForm = event.currentTarget;
        const submitter = event.submitter;
        const data = this.body({
            action: submitter?.value || 'apply',
            code: couponForm.querySelector('[name="code"]')?.value || '',
        });
        const payload = await this.request(this.form.dataset.couponUrl, data);

        if (payload) {
            this.render(payload);
            this.notices(payload.error ? [payload.error] : payload.notices);
        }
    }

    busy(on) {
        this.form.setAttribute('aria-busy', String(on));
    }

    render(payload) {
        this.lines(payload);
        this.totals(payload);
        this.fulfillment(payload);
        this.shippingMethods(payload);
        this.pickup(payload);
        this.payments(payload);
        this.couponBox(payload);
        this.submit(payload);
        this.notices(payload.notices);
        this.latest = payload;
    }

    lines(payload) {
        const box = document.querySelector('[data-checkout-lines]');

        if (!box) {
            return;
        }

        box.replaceChildren(...payload.items.map((item) => this.row(`${item.quantity_display} × ${item.name}`, item.line_total_display)));
    }

    totals(payload) {
        const box = document.querySelector('[data-checkout-totals]');

        if (!box) {
            return;
        }

        const d = payload.display;
        const rows = [[this.labels.products_total, d.products_total]];

        if (parseFloat(payload.order.discount_total) !== 0) {
            rows.push([this.labels.discount, d.discount_total]);
        }

        if (this.form.dataset.shipping === 'on' && payload.fulfillment.type === 'shipping') {
            rows.push([this.labels.shipping_total, d.shipping_total]);
        }

        if (parseFloat(payload.order.fees_total) !== 0) {
            rows.push([this.labels.fees_total, d.fees_total]);
        }

        rows.push([this.labels.total, d.total, true]);
        box.replaceChildren(...rows.map(([label, value, strong]) => this.row(label, value, strong)));
    }

    row(label, value, strong = false) {
        const line = document.createElement('div');
        const name = document.createElement('span');
        const amount = document.createElement(strong ? 'strong' : 'span');

        line.className = 'd-grid col-2 gap-3';
        amount.className = 'a-r';
        name.textContent = label;
        amount.textContent = value;
        line.append(name, amount);

        return line;
    }

    fulfillment(payload) {
        const box = document.querySelector('[data-checkout-fulfillment]');

        if (!box) {
            return;
        }

        const pickup = payload.fulfillment.choices.includes('pickup');

        box.querySelectorAll('[name="fulfillment_type"]').forEach((radio) => {
            radio.checked = radio.value === payload.fulfillment.type;
            radio.closest('label, div')?.toggleAttribute('hidden', radio.value === 'pickup' && !pickup);
        });
        document.querySelector('[data-checkout-shipping]')?.toggleAttribute('hidden', payload.fulfillment.type === 'pickup');
    }

    choices(container, name, options, selected, build) {
        if (!container) {
            return;
        }

        container.replaceChildren(...options.map((option) => {
            const label = document.createElement('label');
            const input = document.createElement('input');
            const text = document.createElement('span');

            input.type = 'radio';
            input.name = name;
            input.value = String(option.value);
            input.checked = option.value === selected;
            text.textContent = build(option);
            label.className = 'd-flex gap-3';
            label.append(input, text);

            return label;
        }));
    }

    shippingMethods(payload) {
        const box = document.querySelector('[data-checkout-shipping-methods]');
        const options = payload.shipping_methods.options.map((o) => ({ ...o, value: o.method_id }));

        this.choices(box, 'shipping_method_id', options, payload.shipping_methods.selected, (o) => [o.name, o.description, o.price_display].filter(Boolean).join(' — '));
        document.querySelector('[data-checkout-notice]')?.toggleAttribute('hidden', options.length > 0 || this.form.dataset.shipping !== 'on');

        const warning = document.querySelector('[data-checkout-notice]');

        if (warning) {
            warning.textContent = this.labels.no_shipping;
        }
    }

    pickup(payload) {
        const box = document.querySelector('[data-checkout-pickup]');

        if (!box) {
            return;
        }

        const options = payload.pickup_locations.options.map((o) => ({ ...o, value: o.id }));

        box.toggleAttribute('hidden', payload.fulfillment.type !== 'pickup');
        this.choices(box, 'location_id', options, payload.pickup_locations.selected, (o) => `${o.name} — ${o.address}`);
    }

    payments(payload) {
        const box = document.querySelector('[data-checkout-payments]');
        const options = payload.payment_methods.options.map((o) => ({ ...o, value: o.id }));

        this.choices(box, 'payment_method_id', options, payload.payment_methods.selected, (o) => (o.instructions ? `${o.name} — ${o.instructions}` : o.name));
    }

    couponBox(payload) {
        const input = document.querySelector('#checkout-coupon [name="code"]');

        if (input && payload.coupon.code === '') {
            input.value = input.value;
        }
    }

    submit(payload) {
        const button = document.querySelector('[data-checkout-submit]');
        const chosen = payload.payment_methods.options.find((o) => o.id === payload.payment_methods.selected);

        if (button) {
            button.textContent = chosen && !chosen.manual ? this.labels.submit_online : this.labels.submit_manual;
        }
    }

    notices(messages) {
        const box = document.querySelector('[data-checkout-notices]');

        if (!box) {
            return;
        }

        box.replaceChildren(...(messages || []).filter(Boolean).map((message) => {
            const p = document.createElement('p');

            p.className = 'text-small';
            p.setAttribute('role', 'status');
            p.textContent = message;

            return p;
        }));
    }

    announce(name) {
        if (!this.latest || !['shipping_method_id', 'location_id', 'fulfillment_type', 'payment_method_id'].includes(name)) {
            return;
        }

        const event = name === 'payment_method_id' ? 'add_payment_info' : 'add_shipping_info';
        const order = this.latest.order;

        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({ ecommerce: null });
        window.dataLayer.push({
            event,
            ecommerce: {
                currency: order.currency,
                value: parseFloat(order.total),
                items: this.latest.items.map((item) => ({
                    item_id: String(item.sku || item.product_id || ''),
                    item_name: item.name,
                    price: parseFloat(item.line_total) / (parseFloat(item.quantity) || 1),
                    quantity: parseFloat(item.quantity),
                })),
            },
        });
    }
}

window.Checkout = Checkout;
window.ecommerceCheckout = new Checkout();
```

(Il metodo `couponBox` è un segnaposto inutile: eliminalo e togli la chiamata in `render` se non serve a niente — il blocco coupon lo ridisegna già `notices`/la pagina; verifica nel browser se serve mostrare «Togli» senza ricaricare e in quel caso ridisegna il blocco con `textContent` come gli altri.)

- [ ] **Step 4: Run** `php tests/CartCheckoutTest.php && node --check resources/assets/js/checkout.js` — Expected: verde, nessun errore di sintassi.

- [ ] **Step 5: Prova nel browser** (browser integrato su `https://ecommerce.test/checkout/`, con il cliente di prova): cambia paese e provincia, scegli il ritiro, applica e togli un coupon, cambia pagamento; guarda Console e Rete (richieste a `/checkout/summary/` con 200 e JSON). Se manca un cliente di prova o il sito non risponde, fermati e dillo.

- [ ] **Step 6: Commit**

```bash
git add resources/assets/js/checkout.js tests/CartCheckoutTest.php
git commit -m "E1c D5: checkout.js, il riepilogo che si ricalcola mentre il cliente compila

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Documenti

**Files:**
- Modify (ecommerce): `docs/cart-checkout.md`, `CHANGELOG.md`, `TODO.md`
- Modify (gestionale): `CHANGELOG.md`, `TODO.md` (riga D5 sotto E1c, `docs/superpowers/plans/...` di questo piano)

- [ ] **Step 1: `docs/cart-checkout.md`** — sostituisci il punto «La scelta e il costo della spedizione attendono le strutture G7…» con una sezione «Consegna e coupon»: la scelta spedizione/ritiro, i metodi e le sedi arrivano da `Checkout::preview` via `POST /checkout/summary/`; `POST /checkout/coupon/` (`action=apply|remove`); `checkout.js` ricalcola dopo ~300 ms e scarta le risposte vecchie; senza JS si compila e si invia, il coupon torna alla pagina; con `shipping` spenta la consegna non c'è, con `coupons` spenta nemmeno il coupon; i provider manuali sono `PaymentMethod::MANUAL_PROVIDERS`. Aggiorna anche la riga «Limiti» sui provider (ora si accettano `bank_transfer`, `cash`, `manual`) e `/checkout/` nell'elenco dei componenti.

- [ ] **Step 2: CHANGELOG del modulo** (in coda ad «Aggiunto» della versione corrente): «Checkout con consegna: spedizione o ritiro in sede, metodi di spedizione, sedi, coupon e riepilogo che si ricalcola mentre si compila (`checkout.js`, rotte `summary` e `coupon`); `place` accetta i pagamenti manuali `bank_transfer` e `cash` e passa consegna, metodo e sede al gestionale; eventi `begin_checkout`, `add_shipping_info`, `add_payment_info`.» Stessa voce, ridotta, nel CHANGELOG del gestionale non serve: lì basta il TODO.

- [ ] **Step 3: TODO.** Nel TODO del gestionale la riga D5 passa da `[~]` a `[x]` con «piano 2 ecommerce ([piano](docs/superpowers/plans/2026-10-06-pagina-del-checkout.md)) fatto; **resta la prova nel browser dell'utente su `ecommerce.test`**: spedizione in Italia e nelle isole, zona non coperta, ritiro, coupon applicato e tolto, bonifico e contrassegno». Nel TODO del modulo, se c'è una riga sul checkout con spedizione, chiudila allo stesso modo.

- [ ] **Step 4: Run** `php tests/run.php > /tmp/e.txt 2>&1; tail -3 /tmp/e.txt` (ecommerce) — Expected: nessun fallito.

- [ ] **Step 5: Commit** in entrambi i repo (messaggio «E1c D5: guida, CHANGELOG e TODO della pagina del checkout», trailer `Co-Authored-By`); nel gestionale il piano stesso entra con questo commit.

---

## Note dall'esecuzione

- La prima anteprima la genera il server (`CheckoutController::initialSummary`) e la vista la passa in `data-initial`: `checkout.js` la legge in `this.latest` e chiede `summary` solo se manca.
- La vista carica `checkout.js` con `module_asset('ecommerce', 'js/checkout.js')`; il metodo segnaposto `couponBox` è diventato l'aggiornamento del campo coupon e del pulsante «Togli» (sempre nel DOM, `hidden` senza coupon).
- Le sedi si agganciano a `[data-checkout-pickup-locations]`; il test della vista controlla i ganci e che i testi esistano in italiano e inglese.
- Prova nel browser non fatta: `ecommerce.test` non ha un cliente di prova e il checkout ospite è spento. Resta all'utente.
