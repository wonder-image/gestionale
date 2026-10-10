# Pagamento con Stripe, piano 2b (Express Checkout) — piano di lavoro

> **Per chi esegue:** SOTTO-SKILL OBBLIGATORIA: superpowers:executing-plans (scelto dall'utente) o superpowers:subagent-driven-development. I passi usano le caselle (`- [ ]`).

**Obiettivo:** i bottoni Apple Pay, Google Pay e Link (Express Checkout Element di Stripe) in cima al checkout, nel carrello e sotto «Aggiungi» nella scheda prodotto, con l'ordine creato dal server come nel checkout normale.

**Architettura:** il gestionale accetta una scelta «wallet» solo per gli ordini express e lascia decidere a Stripe i metodi dell'intento. L'ecommerce sposta la creazione dell'ordine del checkout in `CheckoutPlacement` (riusata dal controller normale e da quello express), traduce i dati del wallet in un post del checkout (`ExpressAddress`) e compra sempre da un carrello a parte (`ExpressCart`), così il carrello vero non si tocca finché il pagamento non torna riuscito. Il JS monta l'elemento, chiede tariffe e totale al server a ogni cambio di indirizzo o tariffa e conferma con lo stesso `confirmPayment` della pagina di ritorno.

**Tecnologie:** PHP 8.3, framework Wonder Image, Stripe.js v3 (Express Checkout Element, modalità differita), test PHP del repo (`tests/run.php`, `tests/integrazione/*.php`).

**Spec:** `docs/superpowers/specs/2026-10-09-pagamento-stripe-design.md`, §11 (e §7 per la creazione dell'ordine).

## Vincoli globali

- Bottoni: `applePay`, `googlePay`, `link` su `auto` solo se il tipo è attivo nell'account; tutti gli altri `never`.
- Non compare se: Stripe non connesso o metodo spento; ospite con gli ordini ospite spenti; combinazione non acquistabile (scheda prodotto); «Mi serve la fattura» spuntato (checkout).
- Dalla scheda prodotto si compra un carrello a parte in sessione `ecommerce_express_cart`; il carrello vero resta com'è. Le campagne automatiche valgono; nel wallet niente coupon.
- Modalità differita con `shippingAddressRequired`, `emailRequired`, `phoneNumberRequired`, `billingAddressRequired`.
- Indirizzo senza zona di spedizione → `reject`. Totale cambiato fra anteprima e ordine → `paymentFailed`.
- `provider_method` = `expressPaymentType` convalidato dal gestionale (`apple_pay`, `google_pay`, `link`).
- Sotto i bottoni: «Pagando accetti le condizioni di vendita» con il link.
- Rotte nel gruppo frontend del checkout (`/checkout/express/...`), non in `route.api.php` (ruling: servono sessione, CSRF e carrello del sito).
- I test non chiamano mai Stripe vero (`FakeStripeHttp`) e non mandano email (`WONDER_NO_MAIL=1`). Chiavi finte: `sk_test_prova`, `pk_test_prova`, `acct_prova_test`.
- Testi in italiano nei file di lingua dell'ecommerce; commenti in italiano, stile del codice vicino.

## Punti da guardare (revisione)

1. Prodotto non acquistabile dalla scheda (variante esaurita, offerta assente): il bottone è nascosto e `start` risponde 422 senza creare ordini.
2. Spedizione spenta: una sola tariffa a 0 € e niente rifiuto dell'indirizzo.
3. «Mi serve la fattura» spuntato: il riquadro `#rapido` sparisce, tolta la spunta torna.
4. Totale cambiato (campagna scaduta, giacenza): `place` risponde 409 `total_changed`, il JS chiama `paymentFailed` e non nasce un intento.
5. Ospite con gli ordini ospite spenti: niente bottoni e ogni rotta express risponde 401.

## Note per il messaggio finale

- Apple Pay vuole il dominio registrato su Stripe e HTTPS: in locale si vedono Link e (in Chrome) Google Pay.
- §8.6 non ha un controllo nel codice.
- Niente reCAPTCHA nell'express (il wallet è già un'autenticazione forte).
- I carrelli express abbandonati restano nel DB come carrelli senza cliente: li pulirà la stessa routine dei carrelli vecchi.
- Il carrello vero si svuota solo dalla pagina di ritorno (`returned`), non dal webhook.

## Mappa dei file

**Gestionale** (`/Users/andreamarinoni/Developer/worktrees/stripe-rapido/gestionale`)
- Modifica: `src/Support/Payments/StripeMethods.php` — `expressChoices()`, wallet in `intentTypes()`.
- Modifica: `src/Support/Orders/Checkout.php` — controllo `express` in `create()`, `expressMethod()`.
- Test: `tests/integrazione/CheckoutStripeMethodsTest.php`, `tests/integrazione/StripeProviderTest.php`.

**Ecommerce** (`/Users/andreamarinoni/Developer/worktrees/stripe-rapido/ecommerce`)
- Crea: `src/Frontend/Checkout/CheckoutRejected.php`, `CheckoutPlacement.php`, `ExpressAddress.php`, `ExpressCart.php`, `ExpressController.php`, `http/frontend/express.php`, `view/components/checkout/express.php`.
- Modifica: `CheckoutController.php`, `OnlinePayment.php`, `ExpressCheckout.php`, `config/routes/route.frontend.php`, `assets/js/checkout.js`, `view/pages/checkout/index.php`, `view/pages/cart/index.php`, `view/pages/frontend/product.php`, file di lingua.
- Test: `tests/integrazione/ExpressAddressTest.php`, `ExpressCartTest.php`, `ExpressPlacementTest.php`, `ExpressHttpTest.php`; ripuntati `tests/CheckoutPlaceJsonTest.php`, `tests/CartCheckoutTest.php`, `tests/CheckoutStripeTest.php`.

Comandi: gestionale `cd …/gestionale && WONDER_NO_MAIL=1 php tests/run.php`; ecommerce `cd …/ecommerce && WONDER_NO_MAIL=1 php tests/run.php`. Un test d'integrazione da solo: `WONDER_NO_MAIL=1 php tests/integrazione/X.php`. Mai due suite insieme (DB condiviso).

---

### Compito G1: il gestionale accetta i wallet per l'express

**File:** `src/Support/Payments/StripeMethods.php`, `src/Support/Orders/Checkout.php`, test in `tests/integrazione/CheckoutStripeMethodsTest.php` e `tests/integrazione/StripeProviderTest.php`.

**Interfacce prodotte:**
- `StripeMethods::expressChoices(array $types): list<string>` = i WALLETS attivi, nell'ordine di WALLETS.
- `StripeMethods::intentTypes('apple_pay'|'google_pay'|'link') === []` (decide Stripe: `automatic_payment_methods`).
- `Checkout::place($cartId, $data + ['express' => true, 'stripe_method_type' => 'apple_pay'])`: con `express` il tipo deve stare in `expressChoices`, senza resta `choices`.
- `Checkout::expressMethod(): array{id: int, types: list<string>}` — `{0, []}` senza Stripe connesso o senza wallet.

- [ ] **Passo 1: test che falliscono** (in `CheckoutStripeMethodsTest.php`, prima di `summary();`)

```php
check('express: apple_pay attivo si salva come provider_method', fn () => prova(static function (): bool {
    soloQuestiMetodi();
    stripeConTipi(TIPI);
    $esito = compra(metodoStripe(), ['stripe_method_type' => 'apple_pay', 'express' => true]);
    return providerMethod($esito) === 'apple_pay';
}));

check('express: google_pay non attivo si rifiuta', fn () => prova(static function (): bool {
    soloQuestiMetodi();
    stripeConTipi(['card', 'apple_pay']);
    $metodo = metodoStripe();
    return rifiuto(fn () => compra($metodo, ['stripe_method_type' => 'google_pay', 'express' => true])) === 'order.payment_method_unavailable';
}));

check('express: card non è una scelta del wallet', fn () => prova(static function (): bool {
    soloQuestiMetodi();
    stripeConTipi(TIPI);
    $metodo = metodoStripe();
    return rifiuto(fn () => compra($metodo, ['stripe_method_type' => 'card', 'express' => true])) === 'order.payment_method_unavailable';
}));

check('expressMethod: il metodo Stripe con i wallet attivi', fn () => prova(static function (): bool {
    soloQuestiMetodi();
    stripeConTipi(['card', 'link', 'apple_pay', 'klarna']);
    $id = metodoStripe();
    return Checkout::expressMethod() === ['id' => $id, 'types' => ['apple_pay', 'link']];
}));

check('expressMethod: senza Stripe niente', fn () => prova(static function (): bool {
    soloQuestiMetodi();
    metodoBonifico();
    return Checkout::expressMethod() === ['id' => 0, 'types' => []];
}));
```

In `StripeProviderTest.php` il check «start con provider_method link: solo link» diventa «start con provider_method link: decide Stripe»:

```php
$parametri = $http->requests[0]['params'];
return ($parametri['automatic_payment_methods']['enabled'] ?? '') === 'true' && !isset($parametri['payment_method_types']);
```

- [ ] **Passo 2:** `WONDER_NO_MAIL=1 php tests/integrazione/CheckoutStripeMethodsTest.php` e `… StripeProviderTest.php` — Atteso: FAIL sui nuovi check (express ignorato, `expressMethod` non esiste, `payment_method_types` = `['link']`).

- [ ] **Passo 3: implementazione**

`StripeMethods`:

```php
/**
 * I wallet dell'Express Checkout fra i tipi attivi, nell'ordine di WALLETS.
 *
 * @param list<string> $types
 * @return list<string>
 */
public static function expressChoices(array $types): array
{
    return array_values(array_intersect(self::WALLETS, $types));
}

/** @return list<string> i tipi dell'intento: per i wallet decide Stripe */
public static function intentTypes(string $choice): array
{
    return in_array($choice, self::WALLETS, true) ? [] : [$choice];
}
```

`Checkout::create`: dentro il ramo Stripe,

```php
$choices = !empty($data['express']) ? StripeMethods::expressChoices($stripeTypes) : StripeMethods::choices($stripeTypes);
if (!in_array($stripeMethod, $choices, true)) { throw UserError::make('order.payment_method_unavailable'); }
```

`Checkout::expressMethod()`:

```php
/**
 * Il metodo Stripe dei bottoni rapidi e i wallet attivi nell'account.
 *
 * @return array{id: int, types: list<string>}
 */
public static function expressMethod(): array
{
    $provider = PaymentProviders::get('stripe');
    if (!$provider instanceof StripeProvider || !$provider->connected()) {
        return ['id' => 0, 'types' => []];
    }
    foreach (self::paymentMethods('shipping') as $method) {
        if (PaymentMethod::ledgerProvider((string) ($method['provider'] ?? '')) === 'stripe') {
            $types = StripeMethods::expressChoices($provider->activeTypes());
            return $types === [] ? ['id' => 0, 'types' => []] : ['id' => (int) $method['id'], 'types' => $types];
        }
    }
    return ['id' => 0, 'types' => []];
}
```

(Le chiamate a provider e `activeTypes()` sono quelle già usate da `stripeTypes()`: copiarne la forma.)

- [ ] **Passo 4:** gli stessi due test — Atteso: PASS. Poi la suite del gestionale — Atteso: come `base-g.txt` più i nuovi check.
- [ ] **Passo 5:** commit «Stripe: i wallet dell'Express Checkout si accettano solo per gli ordini express».

---

### Compito E3: `CheckoutPlacement` e `CheckoutRejected`

**File:** crea `src/Frontend/Checkout/CheckoutRejected.php`, `src/Frontend/Checkout/CheckoutPlacement.php`; modifica `CheckoutController.php`; ripunta `tests/CheckoutPlaceJsonTest.php`, `tests/CartCheckoutTest.php`, `tests/CheckoutStripeTest.php`; crea `tests/integrazione/ExpressPlacementTest.php`.

**Interfacce prodotte:**
- `new CheckoutRejected(list<string> $errors, ?array $summary = null)`; `errors(): list<string>`, `summary(): ?array`, `totalChanged(): bool` (vero se c'è il summary).
- `CheckoutPlacement::place(int $cartId, array $post, ?object $user, array $options): array{receipt: array, online: bool, method: array}` con `$options = ['json' => bool, 'expected_total' => mixed, 'express' => bool]`. Lancia `CheckoutRejected` (campi mancanti, totale cambiato), `RuntimeException` (metodo assente, online senza JS), `UserError` dal gestionale. Non avvia l'intento.
- `CheckoutPlacement::startOnline(array $receipt): array{client_secret: string, return_url: string, billing_details: array}` — scrive `OnlinePayment::RECEIPT` e avvia l'intento; lancia in caso d'errore.
- `CheckoutPlacement::fromCart(array $order): array<string,string>` (spostato dal controller, `CART_KEYS` con lui).

- [ ] **Passo 1: test che falliscono.** I tre test statici cercano ora i pezzi (`OnlinePayment::sameTotal($options['expected_total'] ?? null`, `stripe_method_type`, `GuestCheckout::account`, `AccountAddress::remember`, `registerBaseConsents`) in `CheckoutPlacement.php` invece che nel controller; il controller deve contenere `CheckoutRejected`, `totalChanged()` e `'total_changed'`. `ExpressPlacementTest.php` (testata e aiuti come `CheckoutStripeChoicesTest.php`, carrello con `carrello()` e cliente loggato finto):

```php
check('express: totale diverso → CheckoutRejected con il summary', fn () => prova(static function (): bool {
    stripeConTipi(['card', 'apple_pay']);
    $metodo = metodoStripe();
    [$cartId, $post] = postExpress($metodo, 'apple_pay');
    try {
        CheckoutPlacement::place($cartId, $post, utenteProva(), ['json' => true, 'expected_total' => '0.01', 'express' => true]);
    } catch (CheckoutRejected $e) {
        return $e->totalChanged() && isset($e->summary()['order']['total']);
    }
    return false;
}));

check('express: apple_pay crea l\'ordine con provider_method', fn () => prova(static function (): bool {
    stripeConTipi(['card', 'apple_pay']);
    $metodo = metodoStripe();
    [$cartId, $post] = postExpress($metodo, 'apple_pay');
    $totale = CheckoutSummary::payload($cartId, $post, utenteProva())['order']['total'];
    $esito = CheckoutPlacement::place($cartId, $post, utenteProva(), ['json' => true, 'expected_total' => $totale, 'express' => true]);
    return $esito['online'] && providerMethodDi($esito['receipt']['order_id']) === 'apple_pay';
}));

check('senza express apple_pay si rifiuta', fn () => prova(static function (): bool {
    stripeConTipi(['card', 'apple_pay']);
    $metodo = metodoStripe();
    [$cartId, $post] = postExpress($metodo, 'apple_pay');
    $totale = CheckoutSummary::payload($cartId, $post, utenteProva())['order']['total'];
    try {
        CheckoutPlacement::place($cartId, $post, utenteProva(), ['json' => true, 'expected_total' => $totale, 'express' => false]);
    } catch (UserError $e) {
        return $e->key() === 'order.payment_method_unavailable';
    }
    return false;
}));
```

Aiuti nel file: `postExpress(int $metodo, string $tipo): array{int, array}` — un carrello con un articolo spedibile (`zona`, `metodo`, `listino`, `articolo`, `carrello` di `spedizioni.php`), un post completo (contatti, indirizzo IT/MI, `shipping_method_id`, `same_as_shipping` 1, `payment_method_id` `"$metodo:$tipo"`) passato da `CheckoutRules::post`; `utenteProva()` = `(object) ['id' => 0, 'email' => '']` con `CartSession` ospite e gli ordini ospite accesi; `providerMethodDi(int $orderId)` legge `provider_method` del pagamento come `providerMethod()` del test del gestionale.

- [ ] **Passo 2:** `php tests/run.php` (statici) e `php tests/integrazione/ExpressPlacementTest.php` — Atteso: FAIL (file e classi mancanti).
- [ ] **Passo 3: implementazione.** `CheckoutRejected`:

```php
/** Il checkout non si chiude: campi mancanti, o il totale è cambiato (allora c'è il nuovo riepilogo). */
final class CheckoutRejected extends RuntimeException
{
    /** @param list<string> $errors @param array<string, mixed>|null $summary */
    public function __construct(private array $errors, private ?array $summary = null)
    {
        parent::__construct(implode("\n", $errors));
    }
    /** @return list<string> */
    public function errors(): array { return $this->errors; }
    /** @return array<string, mixed>|null */
    public function summary(): ?array { return $this->summary; }
    public function totalChanged(): bool { return $this->summary !== null; }
}
```

`CheckoutPlacement::place` è il blocco del controller da `$preview = CheckoutSummary::payload(...)` fino a `$receipt = [...]` compreso, con tre differenze: i campi mancanti fanno `throw new CheckoutRejected(array_map(...))`; il totale cambiato fa `throw new CheckoutRejected([(string) __t('ecommerce.checkout.errors.total_changed')], $preview)`; `Checkout::place` riceve anche `'express' => !empty($options['express'])`. Restituisce `['receipt' => $receipt, 'online' => $online, 'method' => $method]`. `startOnline` è il pezzo dopo `$_SESSION[OnlinePayment::RECEIPT] = $receipt;` fino al json di successo, senza il catch. Il controller diventa:

```php
$placed = CheckoutPlacement::place($cartId, $post, $user, ['json' => $json, 'expected_total' => $_POST['expected_total'] ?? null]);
$receipt = $placed['receipt'];
if (!$placed['online']) { $_SESSION[self::COMPLETED] = $receipt; self::leave(self::route('ecommerce.checkout.completed'), $json); }
try { self::json(['success' => true] + CheckoutPlacement::startOnline($receipt)); }
catch (Throwable $error) { Errors::internal($error, 'ecommerce.checkout.start', ['order_id' => $receipt['order_id']]); self::json(['success' => false, 'redirect' => self::route('ecommerce.checkout.pay')], 502); }
```

e prima delle altre catch:

```php
} catch (CheckoutRejected $e) {
    if ($e->totalChanged()) {
        self::json(['success' => false, 'error' => 'total_changed', 'message' => $e->getMessage(), 'summary' => $e->summary()], 409);
    }
    self::reject($e->errors(), $post, $json);
}
```

(`self::json` esce: il successo non va dentro il try se `json()` lancia — controllare e, se `exit`, lasciare così.)

- [ ] **Passo 4:** statici e `ExpressPlacementTest.php` — Atteso: PASS; suite ecommerce come `base-e.txt`.
- [ ] **Passo 5:** commit «Checkout: la creazione dell'ordine passa in CheckoutPlacement, pronta per l'express».

---

### Compito E1: `ExpressAddress`

**File:** crea `src/Frontend/Checkout/ExpressAddress.php`, `tests/integrazione/ExpressAddressTest.php`.

**Interfacce prodotte:**
- `ExpressAddress::province(string $country, string $state): string` — sigla di `states($country)` da sigla o nome (maiuscole, accenti e trattini indifferenti), `''` se sconosciuta.
- `ExpressAddress::partial(array $address, string $rateId): array<string,string>` — post per l'anteprima: `fulfillment_type`, `shipping_country`, `shipping_province`, `shipping_city`, `shipping_cap`, e `shipping_method_id` se `$rateId` è un intero positivo.
- `ExpressAddress::post(array $wallet, ?array $contact, int $paymentMethodId, string $type, array $asked): array<string,string>` — post grezzo del checkout (va poi in `CheckoutRules::post`). `$wallet = {email, phone, shipping: {name, address: {line1, line2, city, state, postal_code, country}}, billing: {name, address}, rate}`.

- [ ] **Passo 1: test** (testata dei test d'integrazione, senza Stripe):

```php
check('province: sigla', fn () => ExpressAddress::province('IT', 'mi') === 'MI');
check('province: nome con accento', fn () => ExpressAddress::province('it', 'Forli-Cesena') === 'FC' && ExpressAddress::province('IT', 'Forlì–Cesena') === 'FC');
check('province: apostrofo', fn () => ExpressAddress::province('IT', 'Valle d’Aosta') === 'AO');
check('province: sconosciuta', fn () => ExpressAddress::province('IT', 'Atlantide') === '');
check('partial', fn () => ExpressAddress::partial(['country' => 'it', 'state' => 'Milano', 'city' => 'Milano', 'postal_code' => '20100'], '7') === [
    'fulfillment_type' => 'shipping', 'shipping_country' => 'IT', 'shipping_province' => 'MI',
    'shipping_city' => 'Milano', 'shipping_cap' => '20100', 'shipping_method_id' => '7',
]);
check('partial: tariffa provvisoria non è un metodo', fn () => !isset(ExpressAddress::partial(['country' => 'IT'], 'pending')['shipping_method_id']));
check('post: nome, via, pagamento e consensi', function (): bool {
    $p = ExpressAddress::post(walletProva(), null, 12, 'apple_pay', ['terms']);
    return $p['shipping_name'] === 'Maria Luisa' && $p['shipping_surname'] === 'Rossi'
        && $p['shipping_street'] === 'Via Roma 1 Scala B' && $p['payment_method_id'] === '12:apple_pay'
        && $p['accept_terms'] === '1' && $p['same_as_shipping'] === '0' && $p['billing_city'] === 'Torino'
        && $p['email'] === 'cliente@example.com' && $p['shipping_phone'] === '+39 333 1234567';
});
check('post: un nome solo vale anche da cognome', fn () => ExpressAddress::post(['shipping' => ['name' => 'Cher']] + walletProva(), null, 1, 'link', [])['shipping_surname'] === 'Cher');
check('post: la fatturazione salvata della scheda vince sul wallet', function (): bool {
    $c = ['name' => 'Ditta', 'surname' => 'Bianchi', 'country' => 'IT', 'province' => 'BO', 'city' => 'Bologna', 'cap' => '40100', 'street' => 'Via Emilia', 'number' => '3'];
    $p = ExpressAddress::post(walletProva(), $c, 1, 'link', []);
    return $p['billing_city'] === 'Bologna' && $p['billing_number'] === '3';
});
check('post: senza indirizzo di fatturazione si usa la spedizione', function (): bool {
    $w = walletProva(); $w['billing']['address'] = [];
    return ExpressAddress::post($w, null, 1, 'link', [])['same_as_shipping'] === '1';
});
```

`walletProva()`: email `cliente@example.com`, phone `+39 333 1234567`, shipping `Maria Luisa Rossi`, Via Roma 1 / Scala B, Milano, MI, 20100, IT; billing `Maria Luisa Rossi`, Corso Francia 2, Torino, TO, 10100, IT; rate `7`.

- [ ] **Passo 2:** `php tests/integrazione/ExpressAddressTest.php` — Atteso: FAIL (classe mancante).
- [ ] **Passo 3: implementazione.**

```php
/** Dal wallet al post del checkout: provincia, nome, via, fatturazione e scelta di pagamento. */
final class ExpressAddress
{
    public static function province(string $country, string $state): string
    {
        $state = trim($state);
        $states = $state === '' ? [] : (array) states(strtoupper(trim($country)));
        if (isset($states[strtoupper($state)])) {
            return strtoupper($state);
        }
        $wanted = self::fold($state);
        foreach ($states as $code => $name) {
            if (self::fold((string) $name) === $wanted) {
                return (string) $code;
            }
        }
        return '';
    }

    /** @param array<string, mixed> $address @return array<string, string> */
    public static function partial(array $address, string $rateId): array
    {
        $country = strtoupper(self::text($address['country'] ?? ''));
        $post = [
            'fulfillment_type' => 'shipping',
            'shipping_country' => $country,
            'shipping_province' => self::province($country, self::text($address['state'] ?? '')),
            'shipping_city' => self::text($address['city'] ?? ''),
            'shipping_cap' => self::text($address['postal_code'] ?? ''),
        ];
        if (ctype_digit($rateId) && (int) $rateId > 0) {
            $post['shipping_method_id'] = (string) (int) $rateId;
        }
        return $post;
    }

    /** @param array<string, mixed> $wallet @param array<string, mixed>|null $contact @param list<string> $asked @return array<string, string> */
    public static function post(array $wallet, ?array $contact, int $paymentMethodId, string $type, array $asked): array
    {
        $shipping = (array) ($wallet['shipping'] ?? []);
        $post = self::partial((array) ($shipping['address'] ?? []), self::text($wallet['rate'] ?? ''))
            + self::person('shipping', $shipping)
            + [
                'email' => self::text($wallet['email'] ?? ''),
                'shipping_phone_prefix' => '',
                'shipping_phone' => self::text($wallet['phone'] ?? ''),
                'payment_method_id' => $paymentMethodId.':'.$type,
                'invoice' => '',
            ];
        $billing = self::savedBilling($contact) ?? self::walletBilling((array) ($wallet['billing'] ?? []));
        $post += $billing === null ? ['same_as_shipping' => '1'] : ['same_as_shipping' => '0'] + $billing;
        foreach ($asked as $document) {
            $post['accept_'.$document] = '1';
        }
        return $post;
    }
    // person(group, {name, address}) → {group_name, group_surname, group_street, group_number '', group_more '', …partial-like keys for billing};
    // savedBilling(contact): billing_* da name, surname, country, province, city, cap, street, number, more se name, surname e CheckoutRules::ADDRESS sono pieni, altrimenti null;
    // walletBilling(billing): null se l'indirizzo non ha città e via, altrimenti person('billing', $billing) + country/province/city/cap;
    // fold(): Normalizer FORM_D e via i segni \p{Mn}, ripiego strtr sulle vocali accentate, poi solo [a-z0-9];
    // text(): scalare → trim, altrimenti ''.
}
```

Nome diviso sull'ultimo spazio (`Maria Luisa Rossi` → `Maria Luisa` / `Rossi`); una parola sola vale per nome e cognome. Via = `line1` + spazio + `line2`.

- [ ] **Passo 4:** il test — Atteso: PASS.
- [ ] **Passo 5:** commit «Express: dal wallet al post del checkout».

---

### Compito E2: `ExpressCart` e le guardie del ritorno

**File:** crea `src/Frontend/Checkout/ExpressCart.php`, `tests/integrazione/ExpressCartTest.php`; modifica `OnlinePayment.php` (`reopen`), `CheckoutController.php` (`returned`).

**Interfacce prodotte:**
- `ExpressCart::SESSION = 'ecommerce_express_cart'`, sessione `{id, source_cart, source_items}`.
- `ExpressCart::start(int $productId, float $quantity): int` — carrello nuovo (`Cart::open(['customer_id' => 0, 'cart_token' => 'express-'.bin2hex(random_bytes(16))])`) più `Cart::add`; lancia `UserError` se il prodotto non si compra.
- `ExpressCart::fromCart(int $realId): int` — carrello nuovo con `Cart::restore($realId, $nuovo)`; ricorda gli id delle righe prodotto del carrello vero.
- `ExpressCart::id(): int`, `owns(int $orderId): bool`, `forget(): void` (solo la sessione).
- `ExpressCart::settled(int $orderId): void` — se `owns`, `Cart::remove` sulle righe ricordate ancora presenti nel carrello vero, poi `forget`.

- [ ] **Passo 1: test** (in `prova()`, sessione pulita a ogni check):

```php
check('start: carrello a parte, quello vero intatto', ...);   // reale con 1 riga; start(altro prodotto, 2) → id diverso, righe del vero invariate, express con 1 riga qty 2
check('start: prodotto non acquistabile → UserError e niente sessione', ...);
check('fromCart: copia righe e coupon, ricorda le righe', ...);
check('settled: toglie dal vero solo le righe ricordate', ...); // dopo fromCart si aggiunge una riga al vero; settled → resta solo quella nuova
check('settled: ordine estraneo non tocca niente', ...);
check('reopen: ordine express annullato non torna nel carrello vero', ...); // con FakePaymentProvider come OnlinePaymentTest: start, reopen → carrello vero invariato, sessione express vuota
```

(I corpi usano `articolo()`, `carrello()` di `spedizioni.php`, `Cart::contents()` e, per l'ultimo, gli aiuti di `OnlinePaymentTest.php`.)

- [ ] **Passo 2:** `php tests/integrazione/ExpressCartTest.php` — Atteso: FAIL.
- [ ] **Passo 3: implementazione** come da interfacce. `OnlinePayment::reopen()`, subito dopo `unset($_SESSION[self::RECEIPT]);`:

```php
// L'express compra da un carrello a parte: annullato, si butta e basta.
if (ExpressCart::owns($id)) {
    ExpressCart::forget();
    return ['outcome' => 'cancelled', 'reference' => $reference, 'removed' => []];
}
```

`CheckoutController::returned()`, dopo `OnlinePayment::forget();`: `ExpressCart::settled($orderId);` in un try con `Errors::internal($e, 'ecommerce.checkout.express_settle')`.

- [ ] **Passo 4:** il test e `OnlinePaymentTest.php` — Atteso: PASS.
- [ ] **Passo 5:** commit «Express: il carrello a parte e il carrello vero che si svuota solo al ritorno».

---

### Compito E4: rotte, controller e componente

**File:** crea `src/Frontend/Checkout/ExpressController.php`, `http/frontend/express.php`, `view/components/checkout/express.php`, `tests/integrazione/ExpressHttpTest.php`; modifica `config/routes/route.frontend.php`, `ExpressCheckout.php`, file di lingua, `view/pages/checkout/index.php`, `view/pages/cart/index.php`.

**Interfacce prodotte:**
- Rotte POST `ecommerce.checkout.express.start|shipping|place|cancel` su `/checkout/express/{start,shipping,place,cancel}/`, meta `express_action`.
- `start` (`source` = `product|cart|checkout`, `product_id`, `quantity`) → `{success, amount, total, currency}` (amount in centesimi, total stringa del gestionale); 422 `{success:false, message}`.
- `shipping` (`address` JSON, `rate`) → `{success, rates: [{id, displayName, amount}], amount, total}`; `{success:false, error:'no_shipping'}` 422 senza zona o senza opzioni; con la spedizione spenta una tariffa `{id:'0', displayName: «Nessuna spedizione», amount:0}`.
- `place` (`wallet` JSON, `type`, `expected_total`) → `{success, client_secret, return_url, billing_details}`; 409 `total_changed`; 422 `rejected` con `message`.
- `cancel` → `{success:true}` dopo `OnlinePayment::reopen()` (se c'è un ordine in sospeso) ed `ExpressCart::forget()`.
- Tutte: CSRF 419, ospite con gli ordini ospite spenti 401, niente reCAPTCHA, `ExpressCart::id() === 0` → 409 su `shipping` e `place`.
- `ExpressCheckout::available(): bool`, `ExpressCheckout::render(string $source, array $order = [], int $productId = 0): string`, `ExpressCheckout::buttons(array $order): list<string>`.
- Il componente stampa `<div data-express="{json}" …><div data-express-element></div><p class="text-small">Pagando accetti le <a>condizioni di vendita</a></p><p data-express-message hidden></p></div>`; json = `{source, product_id, urls{start, shipping, place, cancel}, csrf, stripe{publishable_key, account}, amount, currency, types, labels{pending_rate, error}}`.

- [ ] **Passo 1: test.** `ExpressHttpTest.php` sul sito di prova (forma di `CheckoutPayHttpTest.php`): POST senza CSRF → 419; ospite con gli ordini ospite spenti → 401; `shipping` senza carrello express → 409. Nei test statici: le quattro rotte in `route.frontend.php`, `ExpressCheckout::buttons` vuoto senza Stripe.
- [ ] **Passo 2:** Atteso: FAIL (rotte assenti → 404).
- [ ] **Passo 3: implementazione.** Il controller segue `CheckoutController` (stessi `requirePost`, `requireCsrf`, `json`), `place`:

```php
$cartId = ExpressCart::id();
$method = Checkout::expressMethod();
$type = (string) ($_POST['type'] ?? '');
$wallet = json_decode((string) ($_POST['wallet'] ?? ''), true, 8);
$user = CartSession::user();
$contact = CartSession::authenticated() ? (array) Contact::findById(CartSession::customerId()) : null;
$asked = CheckoutRules::askedConsents((int) ($user->id ?? 0));
$post = CheckoutRules::post(ExpressAddress::post(is_array($wallet) ? $wallet : [], $contact, $method['id'], $type, $asked), $user);
$placed = CheckoutPlacement::place($cartId, $post, $user, ['json' => true, 'expected_total' => $_POST['expected_total'] ?? null, 'express' => true]);
self::json(['success' => true] + CheckoutPlacement::startOnline($placed['receipt']));
```

`shipping`: `$post = ExpressAddress::partial($address, $rate)`; con la spedizione accesa e `ShippingZones::resolve(paese, provincia) === null` → `no_shipping`; poi `CheckoutSummary::payload($cartId, $post, $user)`; tariffe dalle `shipping_methods.options`, la scelta per prima; `amount` = `round(total * 100)`.

Viste: `#rapido` del checkout stampa `$express` senza la griglia a tre colonne; il carrello stampa `ExpressCheckout::render('cart', $order)` sotto il bottone «Procedi».

- [ ] **Passo 4:** Atteso: PASS; suite ecommerce verde.
- [ ] **Passo 5:** commit «Express: rotte, controller e bottoni nel checkout e nel carrello».

---

### Compito E5: il JS `Express`

**File:** modifica `assets/js/checkout.js`.

- [ ] **Passo 1:** classe `Express` montata su ogni `[data-express]` (in fondo al file, accanto a `[data-checkout-pay]`):
  - `loadStripe()`, poi `Stripe(pk, {stripeAccount})`, `elements({mode:'payment', amount, currency, appearance: STRIPE_APPEARANCE})`, `create('expressCheckout', {paymentMethods: {applePay, googlePay, link: 'auto'|'never' dai types; paypal, amazonPay, klarna: 'never'}})`.
  - `ready` senza `availablePaymentMethods` → nasconde il riquadro (e il `#rapido` padre).
  - `click`: `event.resolve({emailRequired, phoneNumberRequired, shippingAddressRequired, billingAddressRequired: true, shippingRates: [{id:'pending', displayName: labels.pending_rate, amount: 0}]})` subito; `this.started = post(start, {source, product_id, quantity})` in parallelo (sulla scheda prodotto id e quantità si leggono dal modulo «Aggiungi»).
  - `shippingaddresschange`: `await this.started`; `post(shipping, {address, rate: ''})`; errore → `event.reject()`; altrimenti `elements.update({amount})` e `event.resolve({shippingRates})`.
  - `shippingratechange`: stesso giro con `rate`, poi `event.resolve()`.
  - `confirm`: `elements.submit()`; `post(place, {wallet, type: event.expressPaymentType, expected_total: this.total})`; errore → `event.paymentFailed({reason: error === 'no_shipping' ? 'address_unserviceable' : 'fail'})` e messaggio; poi `stripe.confirmPayment({elements, clientSecret, confirmParams: {return_url}})`; errore → messaggio e `post(cancel)`.
  - `cancel` (foglio chiuso): `post(cancel)` se è partito `start`.
  - Nel checkout: `change` su `[name=invoice]` nasconde/mostra `#rapido`.
- [ ] **Passo 2:** `node --check assets/js/checkout.js` — Atteso: nessun errore. Prova nel browser su ecommerce.test (Link con carta di prova 4242).
- [ ] **Passo 3:** commit «Express: l'elemento di Stripe nel checkout e nel carrello».

---

### Compito E6: la scheda prodotto

**File:** modifica `view/pages/frontend/product.php`.

- [ ] **Passo 1:** sotto il blocco «Aggiungi» `<?=ExpressCheckout::render('product', [], $productId)?>` dentro `<div data-express-product>`; lo script di `checkout.js` nella head se `ExpressCheckout::available()`; nella sincronizzazione delle offerte (accanto a `submit.disabled = !offer || !offer.available;`) `expressBox.hidden = !offer || !offer.available;`.
- [ ] **Passo 2:** test statico in `tests/ProductPageTest.php`: la pagina contiene `data-express-product` e la riga di sincronizzazione. Atteso prima FAIL, poi PASS.
- [ ] **Passo 3:** commit «Express: i bottoni rapidi nella scheda prodotto».

---

### Compito E7: TODO e documentazione

- [ ] `packages/gestionale/TODO.md` (nel worktree): D6 piano 2b fatto; restano le note finali (dominio Apple Pay, carrelli express abbandonati).
- [ ] Commit «TODO: D6 piano 2b fatto».
