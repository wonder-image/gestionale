# Checkout a passi — piano 2: passi (gestionale + ecommerce)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** dividere il checkout in tre pagine (Carrello, Spedizione, Pagamento) con il box di sinistra che cambia a ogni passo e il riepilogo a destra. Si usano i componenti del piano 1 (`Choice`, `ChoiceGroup`, `Steps`, `.wi-thumb`).

**Architecture:**
- **Gestionale**: l'anteprima (`Checkout::preview`) scrive anche email e telefono sul carrello, così il passo Spedizione si salva sul carrello stesso.
- **Ecommerce**:
  - `CheckoutSummary::payload` tocca solo le scelte che il modulo porta davvero, così la pagina Pagamento non azzera quelle del passo prima;
  - la classe nuova `CheckoutSteps` dice se la Spedizione è completa, legge i valori dal carrello, compone la fatturazione e controlla i consensi;
  - il controller ha due rotte nuove: `POST /checkout/shipping/` e `GET /checkout/payment/`;
  - `place` costruisce l'ordine dal carrello più i campi del Pagamento.
- **Viste**: parziali in `view/components/checkout/` condivisi dalle tre pagine.
- **`checkout.js`**: lavora con un passo per pagina.

**Tech Stack:**
- PHP 8.2, l'harness `tests/harness.php` (`check()`, `summary()`) e le prove d'integrazione sul sito di prova;
- JavaScript senza dipendenze;
- componenti di app/lib, sul ramo `checkout-a-passi-componenti`.

**Spec:** `docs/superpowers/specs/2026-10-06-checkout-a-passi-design.md` (gestionale), §1–§3, §5, §6, §8 e §9. L'ospite (§4) è il piano 3.

## Global Constraints

- **Repository**:
  - gestionale = `/Users/andreamarinoni/Developer/packages/gestionale`, ramo `e1c-checkout-a-passi`, già attivo;
  - ecommerce = `/Users/andreamarinoni/Developer/packages/ecommerce`. Il ramo nuovo `e1c-checkout-a-passi` parte da `e1c-pagina-checkout` (Task 0);
  - app e lib restano su `checkout-a-passi-componenti`: il `vendor` del sito di prova punta ad app con un symlink. **Non si toccano.**
- **Sito di prova**: `/Users/andreamarinoni/Developer/boilerplates/ecommerce-site`. Le sue modifiche non committate non si toccano, e nemmeno le 4 di app.
- **Prove**: si lanciano dalla cartella del pacchetto, con `php tests/X.php`. `php tests/run.php` in ecommerce lancia tutta la suite.
- **Pubblicazione**: niente push, PR o merge senza la conferma esplicita dell'utente.
- **Commit**: in italiano, con la riga finale `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. I file di prova ignorati da git si aggiungono con `git add -f`.
- **Viste**:
  - nessuna vista e nessun FormField forza `render('wonder')`;
  - i testi passano da `e()` o dai componenti, mai HTML grezzo;
  - solo utility della lib: `d-grid`, `col-N`, `col-t-N`, `col-p-N`, `gap-N`, `wi-box`, `p-N`, `mt/mb/pt-N`, `a-r`, `a-c`, `fw-600/700`, `text-small`, `tx-secondary`, `d-block`, `d-flex`, `w-100`;
  - `pc-none` nasconde sopra i 1000px, `tablet-none` fino a 1000px.
- **JavaScript**: niente `innerHTML`. Tutti i ganci si cercano con `querySelectorAll`, perché lo stesso blocco può comparire due volte (riepilogo da desktop e da telefono).
- **Codice già provato**: si cita per file:riga, non si ricopia.

## Review Focus

1. **Le scelte non si azzerano.** Il Pagamento apre l'anteprima senza i campi della Spedizione: metodo, sede, consegna ed email devono restare quelli del carrello. *Task 2*, con la prova «un'anteprima senza scelte tiene quelle del carrello».
2. **Un metodo che diventa invalido tra i passi.** Il cliente cambia indirizzo, o il carrello cambia, dopo la Spedizione: Pagamento e `place` devono rimandare alla Spedizione, non ordinare con un metodo che non copre l'indirizzo. *Task 3*, con la prova «Milano poi Parigi»; il controllo lo fanno `payment()` e `place()` nel Task 6.
3. **Un'email non valida con una buona già sul carrello.** L'anteprima non scrive quella nuova, ma il passo non deve passare con quella vecchia. *Task 3*, con la prova «email non valida».
4. **Ritiro e indirizzo.** Con il ritiro l'indirizzo di spedizione si svuota, e «Uguale alla spedizione» non copia nulla. *Task 3* (prova «uguale ignorato col ritiro») e *Task 5* (svuotamento nel controller, prova statica).
5. **Doppio invio e consensi.** Il bottone si spegne all'invio e si riaccende su `pageshow`: *Task 7*. Un consenso senza documento attivo non si chiede: *Task 3*, con la prova «documento spento».

---

## Mappa dei file

| Repository | File | Responsabilità |
|---|---|---|
| gestionale | `src/Support/Orders/Checkout.php` | `preview` scrive email e telefono se arrivano |
| gestionale | `tests/integrazione/CheckoutSpedizioneTest.php` | prove del contatto nell'anteprima |
| ecommerce | `src/Frontend/Checkout/CheckoutSummary.php` | `payload` tocca solo le chiavi presenti nel modulo |
| ecommerce | `src/Frontend/Checkout/CheckoutSteps.php` (nuovo) | Spedizione completa, valori dal carrello, fatturazione, consensi |
| ecommerce | `src/Frontend/Checkout/CheckoutController.php` | rotte `shipping` e `payment`, `place` dal carrello, coupon con `return` |
| ecommerce | `src/Frontend/Cart/CartController.php` | carrello nel layout del checkout, `flash` pubblico |
| ecommerce | `config/routes/route.frontend.php` | due rotte nuove |
| ecommerce | `view/components/checkout/{steps,lines,totals,coupon,aside,mobile}.php` (nuovi) | parziali condivisi |
| ecommerce | `view/pages/cart/index.php` | passo Carrello |
| ecommerce | `view/pages/checkout/shipping.php` (nuovo) | passo Spedizione |
| ecommerce | `view/pages/checkout/payment.php` (nuovo) | passo Pagamento |
| ecommerce | `view/pages/checkout/index.php` | **eliminato** (sostituito da shipping e payment) |
| ecommerce | `resources/assets/js/checkout.js` | un passo per pagina, scelte da template, pannelli, doppio invio |
| ecommerce | `lang/{it,en}/ecommerce.json` | testi nuovi |
| ecommerce | `module.json` | `components/checkout` tra le viste sigillate |
| ecommerce | `tests/CartCheckoutTest.php` | prove statiche su viste, rotte e JS |
| ecommerce | `tests/integrazione/CheckoutSummaryTest.php` | prove di `payload` |
| ecommerce | `tests/integrazione/CheckoutStepsTest.php` (nuovo) | prove di `CheckoutSteps` |

---

### Task 0: Ramo di ecommerce

- [ ] **Step 1: Crea il ramo**

Run: `git -C /Users/andreamarinoni/Developer/packages/ecommerce status --short && git -C /Users/andreamarinoni/Developer/packages/ecommerce switch -c e1c-checkout-a-passi`

Expected:
- lo stato è vuoto;
- poi `Switched to a new branch 'e1c-checkout-a-passi'`.

Se lo stato non è vuoto, fermati e chiedi.

---

### Task 1 (gestionale): l'anteprima scrive email e telefono

**Files:**
- Modify: `src/Support/Orders/Checkout.php` — docblock di `preview` (circa 98–121) e chiamata a `writeLoosely` (164–170)
- Test: `tests/integrazione/CheckoutSpedizioneTest.php`, prima di `summary()` (riga 473)

**Interfaces:**
- Produces: `Checkout::preview($cartId, $data)` scrive `email` e `phone` quando arrivano non vuoti. Se l'email non è valida, compare in `invalid` come ogni altro campo di `writeLoosely`, e la colonna resta quella di prima. Le stringhe vuote non si scrivono.

- [ ] **Step 1: Scrivi le prove che falliscono**

Le prove vanno prima di `summary();` e usano gli helper già presenti: `prova` (39), `standard` (109), `milano` (117).

```php
check('l\'anteprima scrive email e telefono sul carrello', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $p = Checkout::preview($cart, milano() + ['email' => 'c@example.com', 'phone' => '333 1234567']);
    $ordine = Order::findById($cart);

    return $p['invalid'] === [] && $ordine['email'] === 'c@example.com' && $ordine['phone'] === '333 1234567';
}));

check('un\'email non valida non si scrive e torna in invalid, il telefono sì', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $p = Checkout::preview($cart, milano() + ['email' => 'non-una-email', 'phone' => '333 1234567']);
    $ordine = Order::findById($cart);

    return $p['invalid'] === ['email'] && (string) $ordine['email'] === '' && $ordine['phone'] === '333 1234567';
}));

check('un\'email vuota non cancella quella già sul carrello', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    Checkout::preview($cart, milano() + ['email' => 'c@example.com']);
    Checkout::preview($cart, milano() + ['email' => '']);

    return Order::findById($cart)['email'] === 'c@example.com';
}));
```

Se `milano()` restituisce un array con chiavi diverse da quelle dell'indirizzo, usalo come lo usano le prove vicine. Se il file non importa `Order`, aggiungi `use Wonder\Plugin\Gestionale\Models\Sales\Order;`.

- [ ] **Step 2: Verifica che falliscano**

Run: `php tests/integrazione/CheckoutSpedizioneTest.php`

Expected: le 3 prove nuove FAIL (email e telefono restano vuoti, `invalid === []`); le altre PASS.

- [ ] **Step 3: Implementa**

In `preview`, subito prima di `$invalid = self::writeLoosely(` (164):

```php
            // Il contatto si salva al passo Spedizione; place() poi lo esige.
            $contact = array_filter([
                'email' => trim((string) ($data['email'] ?? '')),
                'phone' => trim((string) ($data['phone'] ?? '')),
            ], static fn (string $value): bool => $value !== '');
```

e nella chiamata sostituisci `] + self::addresses($data));` con `] + $contact + self::addresses($data));`.

Nel docblock di `preview` la frase «Non prenota, non numera, non consuma il coupon e non chiede l'email» diventa:
«Non prenota, non numera e non consuma il coupon. Scrive email e telefono se arrivano, ma non li chiede: li esige `place()`.»

- [ ] **Step 4: Verifica che passino**

Run: `php tests/integrazione/CheckoutSpedizioneTest.php`

Expected: tutte PASS, compresa «senza email» (156).

- [ ] **Step 5: Commit**

```bash
git add src/Support/Orders/Checkout.php && git add -f tests/integrazione/CheckoutSpedizioneTest.php
git commit -m "$(printf 'E1c: l'"'"'anteprima del checkout salva email e telefono\n\nCo-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>')"
```

---

### Task 2 (ecommerce): `payload` tocca solo ciò che il modulo porta

**Files:**
- Modify: `src/Frontend/Checkout/CheckoutSummary.php:22-24`
- Test: `tests/integrazione/CheckoutSummaryTest.php`, prima di `summary()` (210)

**Interfaces:**
- Consumes: Task 1 (`preview` scrive email e telefono).
- Produces: `CheckoutSummary::payload(int $cartId, array $post, ?object $user = null, bool $keepCart = false): array`. La firma non cambia.
  - Le chiavi `fulfillment_type`, `shipping_method_id`, `location_id` e `payment_method_id` arrivano a `preview` solo se sono in `$post` e `$keepCart` è falso.
  - `email` e `phone` arrivano solo se sono in `$post` e non sono vuote, così l'utente loggato non riscrive l'email del carrello con la sua.
  - Il Pagamento chiama `payload($cartId, [], $user)` e il carrello resta com'è.

- [ ] **Step 1: Scrivi le prove che falliscono**

```php
check('un\'anteprima senza scelte tiene quelle del carrello', fn () => prova(static function (): bool {
    $italia = zona('Italia', [['IT', '']]);
    listino(metodo('Standard'), $italia, [[5, 8.0]]);
    $veloce = metodo('Express');
    listino($veloce, $italia, [[5, 12.0]]);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    CheckoutSummary::payload($cart, modulo(['shipping_method_id' => $veloce]));
    $p = CheckoutSummary::payload($cart, ['customer_note' => 'x']);

    return $p['shipping_methods']['selected'] === $veloce && (float) $p['order']['shipping_total'] === 12.0;
}));

check('l\'email dell\'utente non sostituisce quella scritta al passo Spedizione', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    CheckoutSummary::payload($cart, modulo(['email' => 'c@example.com']));
    CheckoutSummary::payload($cart, modulo(), (object) ['email' => 'altro@example.com']);

    return Order::findById($cart)['email'] === 'c@example.com';
}));
```

- [ ] **Step 2: Verifica che falliscano**

Run: `php tests/integrazione/CheckoutSummaryTest.php`

Expected: FAIL tutte e due.
- La prima perché `CheckoutForm::data` porta sempre `fulfillment_type` e un metodo vuoto: `selected` diventa quello unico o 0.
- La seconda perché arriva l'email dell'utente.

- [ ] **Step 3: Implementa**

Sostituisci le righe 22–24 con:

```php
        // Arriva al gestionale solo ciò che il modulo porta: il Pagamento non
        // ripete i campi della Spedizione e non deve azzerarli.
        foreach (['fulfillment_type', 'shipping_method_id', 'location_id', 'payment_method_id'] as $chiave) {
            if ($keepCart || !array_key_exists($chiave, $post)) {
                unset($data[$chiave]);
            }
        }
        foreach (['email', 'phone'] as $chiave) {
            if (!array_key_exists($chiave, $post) || $data[$chiave] === '') {
                unset($data[$chiave]);
            }
        }
```

Aggiorna il docblock di `$keepCart`: «anche senza, le scelte che il modulo non porta restano quelle del carrello».

- [ ] **Step 4: Verifica che passino**

Run: `php tests/integrazione/CheckoutSummaryTest.php`

Expected: tutte PASS, compresa «la prima anteprima lascia le scelte già sul carrello» (127).

- [ ] **Step 5: Commit**

```bash
git add src/Frontend/Checkout/CheckoutSummary.php && git add -f tests/integrazione/CheckoutSummaryTest.php
git commit -m "$(printf 'E1c: l'"'"'anteprima del checkout tocca solo le scelte del modulo\n\nCo-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>')"
```

---

### Task 3 (ecommerce): `CheckoutSteps`

**Files:**
- Create: `src/Frontend/Checkout/CheckoutSteps.php`
- Test: `tests/integrazione/CheckoutStepsTest.php` (nuovo)

**Interfaces:**
- Consumes: la forma dell'anteprima, cioè `Checkout::preview` (gestionale `Checkout.php:203-222`) passata da `payload`:
  - `shipping_methods.options[].method_id`;
  - `pickup_locations.options[].id`;
  - `invalid`.
- Produces (tutti metodi `public static`):
  - `shippingErrors(array $order, array $preview, bool $shipping): list<string>`. Le chiavi sono quelle di `ecommerce.checkout.errors.*`, prese tra `contact`, `address`, `shipping_method` e `pickup_location`;
  - `shippingComplete(array $order, array $preview, bool $shipping): bool`;
  - `fromCart(array $order): array<string,string>`: email, telefono, scelte, nota e le chiavi degli indirizzi dell'ordine. Le altre colonne non entrano (per esempio `shipping_total`);
  - `billing(array $post, array $order): array<string,string>`: le chiavi `billing_*` già composte;
  - `paymentErrors(array $post, array $billing, array $asked): list<string>`, con chiavi prese tra `billing`, `invoice` e `consents`;
  - `askedConsents(int $userId): list<string>`, i tipi di documento ancora da accettare;
  - la costante `CONSENTS = ['privacy_policy', 'terms_conditions']`.

Decisioni:
- `$order` è sempre `Order::findById($cartId)` letto **dopo** l'anteprima.
- L'indirizzo è completo con paese, città, CAP e via.
- «Mi serve la fattura» decide il tipo: senza fattura è `private` e i campi fiscali si svuotano. Il privato con fattura tiene solo il codice fiscale.
- «Uguale alla spedizione» copia nome, cognome, indirizzo e telefono di spedizione, ma solo con la spedizione.

- [ ] **Step 1: Scrivi le prove che falliscono**

`tests/integrazione/CheckoutStepsTest.php`. L'intestazione è quella di `CheckoutSummaryTest.php:1-31`: `SITE`, `chdir`, i `require`, `$supporto` e `Annulla`. Gli `use` sono:

```php
use Wonder\Auth\Federated\FederatedIdentityPayload;
use Wonder\Auth\Federated\FederatedIdentityRepository;
use Wonder\Auth\Federated\FederatedLoginService;
use Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceUserAccountGateway;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutSteps;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutSummary;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Seeding\ShippingDemo;
use Wonder\Sql\Transaction;
```

Gli helper `prova`, `standard` e `modulo` sono copiati da `CheckoutSummaryTest.php:34-67`, perché i test sono script separati. Poi:

```php
function contatto(array $extra = []): array
{
    return $extra + ['email' => 'c@example.com', 'phone' => '333 1234567', 'shipping_name' => 'Ada', 'shipping_surname' => 'Lovelace'];
}

/** Anteprima e carrello riletto, come fa il controller. */
function passo(int $cart, array $post): array
{
    $p = CheckoutSummary::payload($cart, $post);

    return [(array) Order::findById($cart), $p];
}

check('la Spedizione completa non ha errori', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    [$o, $p] = passo($cart, modulo(contatto()));

    return CheckoutSteps::shippingErrors($o, $p, true) === [] && CheckoutSteps::shippingComplete($o, $p, true);
}));

check('senza contatto manca contact', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    [$o, $p] = passo($cart, modulo());

    return CheckoutSteps::shippingErrors($o, $p, true) === ['contact'];
}));

check('un\'email non valida ferma il passo anche con una buona già sul carrello', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    passo($cart, modulo(contatto()));
    [$o, $p] = passo($cart, modulo(contatto(['email' => 'non-una-email'])));

    return CheckoutSteps::shippingErrors($o, $p, true) === ['contact'];
}));

check('senza indirizzo mancano indirizzo e metodo', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    [$o, $p] = passo($cart, contatto(['shipping_country' => 'IT']));

    return CheckoutSteps::shippingErrors($o, $p, true) === ['address', 'shipping_method'];
}));

check('con le spedizioni spente basta l\'indirizzo', fn () => prova(static function (): bool {
    spegniFunzionalita(['shipping']);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    [$o, $p] = passo($cart, modulo(contatto()));

    return CheckoutSteps::shippingErrors($o, $p, false) === [];
}));

check('il ritiro vuole una sede valida e non l\'indirizzo', fn () => prova(static function (): bool {
    standard();
    $sede = sede();
    $prodotto = articolo(2.0, 10.0);
    giacenzaIn($prodotto, $sede, 5);
    $cart = carrello([[$prodotto, 1]]);
    [$o, $p] = passo($cart, contatto(['fulfillment_type' => 'pickup', 'location_id' => (string) $sede]));
    $buona = CheckoutSteps::shippingErrors($o, $p, true);
    $o['location_id'] = $sede + 999;

    return $buona === [] && CheckoutSteps::shippingErrors($o, $p, true) === ['pickup_location'];
}));

check('un metodo che non copre più l\'indirizzo rimanda alla Spedizione', fn () => prova(static function (): bool {
    $milano = metodo('Solo Italia');
    listino($milano, zona('Italia', [['IT', '']]), [[5, 8.0]]);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    passo($cart, modulo(contatto(['shipping_method_id' => (string) $milano])));
    [$o, $p] = passo($cart, contatto(['shipping_country' => 'FR', 'shipping_city' => 'Paris', 'shipping_cap' => '75001', 'shipping_street' => 'Rue', 'shipping_province' => '']));

    return CheckoutSteps::shippingErrors($o, $p, true) === ['shipping_method'];
}));

check('fromCart legge contatto e indirizzi ma non i totali', function (): bool {
    $v = CheckoutSteps::fromCart(['email' => 'c@example.com', 'shipping_city' => 'Milano', 'shipping_total' => '8.00', 'id' => 5]);

    return $v['email'] === 'c@example.com' && $v['shipping_city'] === 'Milano'
        && !array_key_exists('shipping_total', $v) && !array_key_exists('id', $v) && $v['billing_city'] === '';
});

check('«Uguale alla spedizione» copia nome e indirizzo', function (): bool {
    $b = CheckoutSteps::billing(['same_as_shipping' => '1', 'billing_name' => 'Altro'], ['fulfillment_type' => 'shipping', 'shipping_name' => 'Ada', 'shipping_city' => 'Milano']);

    return $b['billing_name'] === 'Ada' && $b['billing_city'] === 'Milano' && $b['billing_type'] === 'private';
});

check('«Uguale» è ignorato col ritiro', function (): bool {
    $b = CheckoutSteps::billing(['same_as_shipping' => '1', 'billing_name' => 'Bea'], ['fulfillment_type' => 'pickup', 'shipping_name' => 'Ada']);

    return $b['billing_name'] === 'Bea';
});

check('senza fattura i campi fiscali si svuotano e il tipo è privato', function (): bool {
    $b = CheckoutSteps::billing(['billing_type' => 'business', 'billing_pi' => '123', 'billing_cf' => 'X'], []);

    return $b['billing_type'] === 'private' && $b['billing_pi'] === '' && $b['billing_cf'] === '';
});

check('il privato con fattura tiene solo il codice fiscale', function (): bool {
    $b = CheckoutSteps::billing(['invoice' => '1', 'billing_type' => 'private', 'billing_cf' => 'X', 'billing_pi' => '123'], []);

    return $b['billing_type'] === 'private' && $b['billing_cf'] === 'X' && $b['billing_pi'] === '';
});

check('fatturazione: incompleta, azienda senza partita IVA, consensi mancanti', function (): bool {
    $piena = ['billing_name' => 'Ada', 'billing_surname' => 'L', 'billing_country' => 'IT', 'billing_city' => 'Milano', 'billing_cap' => '20100', 'billing_street' => 'Via'];

    return CheckoutSteps::paymentErrors([], ['billing_name' => 'Ada'], []) === ['billing']
        && CheckoutSteps::paymentErrors(['invoice' => '1'], $piena + ['billing_type' => 'business', 'billing_business_name' => 'Srl', 'billing_pi' => ''], []) === ['invoice']
        && CheckoutSteps::paymentErrors([], $piena, ['privacy_policy']) === ['consents']
        && CheckoutSteps::paymentErrors(['accept_privacy_policy' => '1'], $piena, ['privacy_policy']) === [];
});

check('l\'ospite deve accettare tutti e due i documenti', fn () =>
    CheckoutSteps::askedConsents(0) === ['privacy_policy', 'terms_conditions']
);

check('chi li ha già accettati non li rivede', fn () => prova(static function (): bool {
    $pid = 'tst-'.uniqid();
    $service = new FederatedLoginService(new FederatedIdentityRepository(), new EcommerceUserAccountGateway());
    $userId = (int) $service->authenticate(new FederatedIdentityPayload('google', $pid, $pid.'@example.com', true, 'Ada', 'Lovelace', ['sub' => $pid, 'email_verified' => true]), 'frontend', ['client'])->userId;
    $input = [];
    foreach (CheckoutSteps::CONSENTS as $tipo) {
        $input['accept_'.$tipo] = '1';
        $input[$tipo.'_id'] = (string) sqlSelect('legal_documents', ['doc_type' => $tipo, 'language_code' => __l(), 'active' => 'true'], 1, 'published_at DESC, id', 'DESC')->id;
    }
    consentService()->registerUserConsentsFromPayload($userId, $input, ['required_document_types' => CheckoutSteps::CONSENTS]);

    return CheckoutSteps::askedConsents($userId) === [];
}));

check('un documento spento non si chiede', fn () => prova(static function (): bool {
    sqlModify('legal_documents', ['active' => 'false'], 'doc_type', 'privacy_policy');

    return CheckoutSteps::askedConsents(0) === ['terms_conditions'];
}));

summary();
```

Due avvertenze:
- Se la costruzione di `FederatedLoginService` è diversa, copiala da `tests/integrazione/AuthAccountTest.php:25-44`.
- Se `sqlModify` non accetta queste chiavi, usa la stessa forma di `prova()` riga 41.

- [ ] **Step 2: Verifica che falliscano**

Run: `php tests/integrazione/CheckoutStepsTest.php`

Expected: errore fatale `Class "…CheckoutSteps" not found`.

- [ ] **Step 3: Implementa** `src/Frontend/Checkout/CheckoutSteps.php`

```php
<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use Wonder\Consent\ConsentDictionary;
use Wonder\Plugin\Gestionale\Models\Sales\Order;

/** Le regole dei passi del checkout: cosa serve per andare avanti e cosa si scrive sull'ordine. */
final class CheckoutSteps
{
    public const CONSENTS = ['privacy_policy', 'terms_conditions'];

    private const CART_KEYS = ['email', 'phone', 'fulfillment_type', 'shipping_method_id', 'location_id', 'payment_method_id', 'customer_note'];
    private const ADDRESS = ['country', 'city', 'cap', 'street'];
    private const FISCAL = ['business_name', 'cf', 'pi', 'sdi', 'pec'];
    private const SAME = ['name', 'surname', 'country', 'province', 'city', 'cap', 'street', 'number', 'more', 'phone_prefix', 'phone'];

    /**
     * Cosa manca al passo Spedizione. `$order` è il carrello riletto dopo l'anteprima.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $preview
     * @return list<string> chiavi di ecommerce.checkout.errors.*
     */
    public static function shippingErrors(array $order, array $preview, bool $shipping): array
    {
        $errors = [];
        $text = static fn (string $key): string => trim((string) ($order[$key] ?? ''));

        // Un'email non valida non si scrive: quella sul carrello può essere la vecchia.
        if (in_array('email', (array) ($preview['invalid'] ?? []), true)
            || filter_var($text('email'), FILTER_VALIDATE_EMAIL) === false
            || $text('phone') === '' || $text('shipping_name') === '' || $text('shipping_surname') === '') {
            $errors[] = 'contact';
        }

        if ($text('fulfillment_type') === 'pickup') {
            $ids = array_map('intval', array_column((array) ($preview['pickup_locations']['options'] ?? []), 'id'));
            if (!in_array((int) ($order['location_id'] ?? 0), $ids, true)) {
                $errors[] = 'pickup_location';
            }

            return $errors;
        }

        foreach (self::ADDRESS as $field) {
            if ($text('shipping_'.$field) === '') {
                $errors[] = 'address';
                break;
            }
        }

        $methods = array_map('intval', array_column((array) ($preview['shipping_methods']['options'] ?? []), 'method_id'));
        if ($shipping && !in_array((int) ($order['shipping_method_id'] ?? 0), $methods, true)) {
            $errors[] = 'shipping_method';
        }

        return $errors;
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $preview */
    public static function shippingComplete(array $order, array $preview, bool $shipping): bool
    {
        return self::shippingErrors($order, $preview, $shipping) === [];
    }

    /**
     * I valori dei moduli presi dal carrello: contatto, scelte, nota e indirizzi.
     *
     * @param array<string, mixed> $order
     * @return array<string, string>
     */
    public static function fromCart(array $order): array
    {
        $values = [];
        foreach (array_merge(self::CART_KEYS, Order::shippingAddress()->keys(), Order::billingAddress()->keys()) as $key) {
            $values[$key] = trim((string) ($order[$key] ?? ''));
        }

        return $values;
    }

    /**
     * La fatturazione da scrivere sull'ordine: «Uguale alla spedizione» copia
     * l'indirizzo (non col ritiro), «Mi serve la fattura» decide il tipo.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $order
     * @return array<string, string>
     */
    public static function billing(array $post, array $order): array
    {
        $billing = [];
        foreach ($post as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'billing_') && is_scalar($value)) {
                $billing[$key] = trim((string) $value);
            }
        }

        if (!empty($post['same_as_shipping']) && (string) ($order['fulfillment_type'] ?? '') !== 'pickup') {
            foreach (self::SAME as $field) {
                $billing['billing_'.$field] = trim((string) ($order['shipping_'.$field] ?? ''));
            }
        }

        $invoice = !empty($post['invoice']);
        $type = $invoice && ($billing['billing_type'] ?? '') === 'business' ? 'business' : 'private';
        $billing['billing_type'] = $type;

        foreach (self::FISCAL as $field) {
            if (!$invoice || ($type === 'private' && $field !== 'cf')) {
                $billing['billing_'.$field] = '';
            }
        }

        return $billing;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, string> $billing
     * @param list<string> $asked
     * @return list<string> chiavi di ecommerce.checkout.errors.*
     */
    public static function paymentErrors(array $post, array $billing, array $asked): array
    {
        $errors = [];

        foreach (array_merge(['name', 'surname'], self::ADDRESS) as $field) {
            if (($billing['billing_'.$field] ?? '') === '') {
                $errors[] = 'billing';
                break;
            }
        }

        if (!empty($post['invoice'])) {
            $needed = ($billing['billing_type'] ?? 'private') === 'business' ? ['business_name', 'pi'] : ['cf'];
            foreach ($needed as $field) {
                if (($billing['billing_'.$field] ?? '') === '') {
                    $errors[] = 'invoice';
                    break;
                }
            }
        }

        foreach ($asked as $type) {
            if (empty($post['accept_'.$type])) {
                $errors[] = 'consents';
                break;
            }
        }

        return $errors;
    }

    /**
     * I documenti da far accettare: quelli con un testo attivo nella lingua
     * della pagina che l'utente non ha già accettato (l'ospite li vede tutti).
     *
     * @return list<string>
     */
    public static function askedConsents(int $userId): array
    {
        $accepted = [];
        if ($userId > 0) {
            foreach ((array) (consentService()->getUserConsents($userId)['current_state'] ?? []) as $row) {
                $row = (array) $row;
                if ((string) ($row['current_status'] ?? '') === ConsentDictionary::STATUS_ACCEPTED) {
                    $accepted[] = (string) ($row['consent_type'] ?? '');
                }
            }
        }

        return array_values(array_filter(
            self::CONSENTS,
            static fn (string $type): bool => !in_array(ConsentDictionary::consentTypeFromDocumentType($type), $accepted, true)
                && sqlSelect('legal_documents', ['doc_type' => $type, 'language_code' => __l(), 'active' => 'true'], 1, 'published_at DESC, id', 'DESC')->exists
        ));
    }
}
```

La ricerca del documento è la stessa di app `InputAcceptDocument.php:104-114`.

- [ ] **Step 4: Verifica che passino**

Run: `php tests/integrazione/CheckoutStepsTest.php`

Expected: tutte PASS.

Se «senza indirizzo» dà solo `['address']` perché senza città nessun metodo compare, la prova resta com'è: `shipping_method` deve uscire, perché nessun metodo è scelto.

- [ ] **Step 5: Commit**

```bash
git add src/Frontend/Checkout/CheckoutSteps.php && git add -f tests/integrazione/CheckoutStepsTest.php
git commit -m "$(printf 'E1c: CheckoutSteps, le regole dei passi del checkout\n\nCo-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>')"
```

---

### Task 4 (ecommerce): parziali, passo Carrello e coupon senza JavaScript

**Files:**
- Create: `view/components/checkout/{steps,lines,totals,coupon,aside,mobile}.php`
- Modify: `view/pages/cart/index.php` (tutto), `module.json:37-42`
- Modify: `src/Frontend/Cart/CartController.php` — `flash` (133) diventa `public static`; `index` (28–40) passa `summary` e `coupons`
- Modify: `src/Frontend/Checkout/CheckoutController.php` — `coupon` (162–188), `guardJson` (191) e `guardPage` (211)
- Modify: `lang/{it,en}/ecommerce.json`
- Test: `tests/CartCheckoutTest.php`

**Interfaces:**
- Consumes:
  - `Steps::make(string $label)->step(string $label, ?string $href, string $state)`, con `state` tra `done`, `current` e `todo`;
  - `CartPresenter::money()`, `CartPresenter::quantity()`, `CartPresenter::lines()`.
- Produces: i parziali, da includere con `<?=View::component(Ecommerce::viewPath('components/checkout/X.php'), [...])?>`. Ognuno riceve solo le variabili elencate:

| Parziale | Variabili | Cosa disegna |
|---|---|---|
| `steps.php` | `$step` (`cart`/`shipping`/`payment`) | i passi; i passi fatti hanno il link |
| `lines.php` | `$items` (righe di `CartPresenter::lines`, magari con `*_display`), `$currency` | `<div data-checkout-lines>` con una riga per articolo e un `<template data-checkout-line>` |
| `totals.php` | `$order` (importi grezzi), `$currency`, `$shippingRow` (bool) | `<div data-checkout-totals>`; lo sconto c'è se diverso da 0, la spedizione se `$shippingRow`, la commissione se diversa da 0 |
| `coupon.php` | `$csrf_token`, `$code`, `$return` (`cart`/`checkout`/`payment`), `$id` | il form `data-checkout-coupon` con il campo nascosto `return` |
| `aside.php` | `$step`, `$items`, `$order`, `$currency`, `$csrf_token`, `$coupons`, `$couponCode`, `$notices`, `$button` (stringa HTML già resa) | il riepilogo di destra |
| `mobile.php` | come `aside.php` senza `$button` | il `<details>` per il telefono |

- Il coupon senza JavaScript torna alla pagina da cui è partito, col messaggio `coupon_applied`, `coupon_removed` o l'errore.
- Il passo Carrello non chiede il login, nemmeno per il coupon (`return=cart`).

- [ ] **Step 1: Scrivi le prove che falliscono**

In `tests/CartCheckoutTest.php`, dopo `$root = dirname(__DIR__);`:

```php
/** Le pagine e i parziali del checkout, uniti: i ganci possono stare in un parziale. */
function checkoutViews(string $root): string
{
    $files = array_merge(glob($root.'/view/pages/checkout/*.php') ?: [], glob($root.'/view/components/checkout/*.php') ?: []);

    return implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), $files));
}
```

Nuova prova:

```php
check('il passo Carrello usa il layout del checkout, i passi e il coupon che torna al carrello', function () use ($root) {
    $cart = (string) file_get_contents($root.'/view/pages/cart/index.php');
    $parts = checkoutViews($root);
    $controller = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
    $manifest = json_decode((string) file_get_contents($root.'/module.json'), true);

    return str_contains($cart, "Ecommerce::layout('checkout'")
        && str_contains($cart, 'data-checkout-cart')
        && str_contains($cart, "'step' => 'cart'")
        && str_contains($parts, 'Steps::make(')
        && str_contains($parts, 'wi-thumb')
        && str_contains($parts, '<template data-checkout-line>')
        && str_contains($parts, "FormField::key('return')->hidden()")
        && str_contains($parts, '<details')
        && str_contains($controller, "'coupon_applied'")
        && str_contains($controller, 'CartController::flash(')
        && in_array('components/checkout', $manifest['views']['sealed'] ?? [], true)
        && !str_contains($cart.$parts, "render('wonder')");
});
```

Nella prova dei testi (153–174), `$view` diventa `checkoutViews($root)."\n".(string) file_get_contents($root.'/view/pages/cart/index.php')`.

- [ ] **Step 2: Verifica che falliscano**

Run: `php tests/CartCheckoutTest.php`

Expected: FAIL la prova nuova; le altre PASS.

- [ ] **Step 3: Testi**

In `lang/it/ecommerce.json`, sotto `cart`:
- `"proceed": "Procedi alla spedizione"`
- `"back_to_shop": "Torna al negozio"`

Sotto `checkout`:
- `"steps": {"label": "Passaggi dell'ordine", "cart": "Carrello", "shipping": "Spedizione", "payment": "Pagamento"}`
- `"your_cart": "Il tuo carrello"`
- `"show_cart": "Mostra carrello"`
- `"continue_payment": "Continua al pagamento"`
- `"have_account": "Hai già un account?"`
- `"login": "Accedi"`
- `"edit": "Modifica"`
- `"billing_same": "Uguale alla spedizione"`
- `"invoice": "Mi serve la fattura"`
- `"billing_private": "Privato"`
- `"billing_business": "Azienda"`
- `"consents": "Consensi"`
- `"coupon_applied": "Coupon applicato."`
- `"coupon_removed": "Coupon tolto."`

Sotto `checkout.errors`:
- `"contact": "Inserisci nome, cognome, un'email valida e il telefono."`
- `"address": "Completa l'indirizzo di spedizione."`
- `"shipping_method": "Scegli un metodo di spedizione per questo indirizzo."`
- `"pickup_location": "Scegli una sede per il ritiro."`
- `"shipping_incomplete": "Completa prima i dati di spedizione."`
- `"billing": "Completa i dati di fatturazione."`
- `"invoice": "Per la fattura servono il codice fiscale, oppure ragione sociale e partita IVA."`
- `"consents": "Accetta i documenti richiesti per ordinare."`

In `lang/en/ecommerce.json`, le stesse chiavi:
- `cart`: `"Proceed to shipping"`, `"Back to the shop"`;
- `checkout.steps`: `"Order steps"`, `"Cart"`, `"Shipping"`, `"Payment"`;
- `checkout`, nell'ordine: `"Your cart"`, `"Show cart"`, `"Continue to payment"`, `"Already have an account?"`, `"Log in"`, `"Edit"`, `"Same as shipping"`, `"I need an invoice"`, `"Private"`, `"Business"`, `"Consents"`, `"Coupon applied."`, `"Coupon removed."`;
- `checkout.errors`, nell'ordine: `"Enter first name, last name, a valid email and a phone number."`, `"Complete the shipping address."`, `"Choose a shipping method for this address."`, `"Choose a pickup location."`, `"Complete the shipping details first."`, `"Complete the billing details."`, `"An invoice needs a tax code, or a company name and VAT number."`, `"Accept the required documents to place the order."`.

- [ ] **Step 4: Parziali**

`view/components/checkout/steps.php`:

```php
<?php

use Wonder\Elements\Components\Steps;

$order = ['cart' => 'ecommerce.cart.index', 'shipping' => 'ecommerce.checkout.index', 'payment' => 'ecommerce.checkout.payment'];
$current = array_search($step, array_keys($order), true);
$steps = Steps::make((string) __t('ecommerce.checkout.steps.label'));

foreach (array_keys($order) as $i => $key) {
    $state = $i < $current ? 'done' : ($i === $current ? 'current' : 'todo');
    $steps->step((string) __t('ecommerce.checkout.steps.'.$key), $state === 'done' ? (string) __r($order[$key]) : null, $state);
}
?>
<div class="mb-6"><?=$steps?></div>
```

`view/components/checkout/lines.php`. L'immagine arriva solo dalle righe del carrello (`$item['image']`). Se manca, il riquadro resta vuoto.

```php
<?php

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;

$line = static function (array $item) use ($currency): string {
    $image = trim((string) ($item['image'] ?? ''));
    $sku = trim((string) ($item['sku'] ?? ''));

    return '<div class="d-flex gap-4">'
        .'<span class="wi-thumb" style="--wi-thumb-size: 56px">'
        .($image === '' ? '' : '<img src="'.e($image).'" alt="" loading="lazy" data-line-image>')
        .'<span class="badge badge-dark" data-line-quantity>'.e($item['quantity_display'] ?? CartPresenter::quantity($item['quantity'] ?? 1)).'</span></span>'
        .'<span class="w-100"><span class="d-block fw-600" data-line-name>'.e($item['name'] ?? '').'</span>'
        .'<span class="d-block text-small tx-secondary" data-line-sku'.($sku === '' ? ' hidden' : '').'>'.e($sku === '' ? '' : (string) __t('ecommerce.cart.sku', ['sku' => $sku])).'</span></span>'
        .'<span class="fw-600 a-r" data-line-total>'.e($item['line_total_display'] ?? CartPresenter::money($item['line_total'] ?? 0, $currency)).'</span>'
        .'</div>';
};
?>
<div class="d-grid col-1 gap-4" data-checkout-lines>
    <?php foreach ($items as $item): ?><?=$line($item)?><?php endforeach; ?>
</div>
<template data-checkout-line><?=$line(['name' => '', 'quantity_display' => '', 'line_total_display' => '', 'image' => 'data:,'])?></template>
```

Il template ha un'`img` con `src="data:,"`: il JS cambia `src` o, se l'articolo non ha immagine, toglie l'`img`. La chiave `ecommerce.cart.sku` già esiste e ha il segnaposto `:sku`.

`view/components/checkout/totals.php`:

```php
<?php

use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;

$row = static fn (string $label, mixed $amount, bool $strong = false): string => '<div class="d-grid col-2 gap-3"><span'.($strong ? ' class="fw-700"' : '').'>'.e($label).'</span>'
    .($strong ? '<strong class="a-r">' : '<span class="a-r">').e(CartPresenter::money($amount, $currency)).($strong ? '</strong>' : '</span>').'</div>';
?>
<div class="d-grid col-1 gap-3 mt-5 pt-4" data-checkout-totals>
    <?=$row((string) __t('ecommerce.cart.products_total'), $order['products_total'] ?? 0)?>
    <?php if ((float) ($order['discount_total'] ?? 0) !== 0.0): ?><?=$row((string) __t('ecommerce.cart.discount'), -abs((float) $order['discount_total']))?><?php endif; ?>
    <?php if ($shippingRow): ?><?=$row((string) __t('ecommerce.checkout.shipping_total'), $order['shipping_total'] ?? 0)?><?php endif; ?>
    <?php if ((float) ($order['fees_total'] ?? 0) !== 0.0): ?><?=$row((string) __t('ecommerce.checkout.fees_total'), $order['fees_total'])?><?php endif; ?>
    <?=$row((string) __t('ecommerce.cart.total'), $order['total'] ?? 0, true)?>
</div>
```

Lo sconto: confronta con il formato che usa `checkout.js` `totals()` (152–176) e tieni lo stesso segno nei due posti.

`view/components/checkout/coupon.php`, ripreso dalle righe 171–181 della vecchia `pages/checkout/index.php`:

```php
<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
?>
<form id="<?=e($id)?>" class="mt-5" method="post" action="<?=e(__r('ecommerce.checkout.coupon'))?>" data-checkout-coupon>
    <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
    <?=FormField::key('return')->hidden()->value($return)?>
    <div class="d-grid col-2 gap-3">
        <?=FormField::key('code')->text()->label((string) __t('ecommerce.checkout.coupon_label'))->value($code)?>
        <div class="d-flex gap-3 a-end">
            <?=Button::make((string) __t('ecommerce.checkout.coupon_apply'))->type('submit')->variant('secondary')->attr('name', 'action')->attr('value', 'apply')?>
            <?php $remove = Button::make((string) __t('ecommerce.checkout.coupon_remove'))->type('submit')->variant('secondary')->attr('name', 'action')->attr('value', 'remove')->attr('data-checkout-coupon-remove', ''); ?>
            <?=$code === '' ? $remove->attr('hidden', 'hidden') : $remove?>
        </div>
    </div>
</form>
```

`view/components/checkout/aside.php`. Sul Carrello niente righe; sugli altri passi c'è il titolo «Il tuo carrello» e le righe. Su telefono l'aside dei passi Spedizione e Pagamento si nasconde: lo sostituisce `mobile.php`. L'aside del Carrello resta.

```php
<?php

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\View\View;

$withLines = $step !== 'cart';
?>
<aside class="wi-box p-5<?=$withLines ? ' tablet-none' : ''?>">
    <h2 class="subtitle mb-4"><?=e(__t($withLines ? 'ecommerce.checkout.your_cart' : 'ecommerce.cart.summary'))?></h2>
    <?php if ($withLines): ?><?=View::component(Ecommerce::viewPath('components/checkout/lines.php'), compact('items', 'currency'))?><?php endif; ?>
    <?=View::component(Ecommerce::viewPath('components/checkout/totals.php'), ['order' => $order, 'currency' => $currency, 'shippingRow' => $step !== 'cart' && \Wonder\Plugin\Gestionale\Gestionale::feature('shipping') && (string) ($order['fulfillment_type'] ?? 'shipping') !== 'pickup'])?>
    <div class="mt-4" data-checkout-notices>
        <?php foreach ($notices as $message): ?><p class="text-small" role="status"><?=e($message)?></p><?php endforeach; ?>
    </div>
    <?php if ($coupons): ?>
        <?=View::component(Ecommerce::viewPath('components/checkout/coupon.php'), ['csrf_token' => $csrf_token, 'code' => $couponCode, 'return' => $step === 'payment' ? 'payment' : ($step === 'cart' ? 'cart' : 'checkout'), 'id' => 'checkout-coupon'])?>
    <?php endif; ?>
    <?=$button?>
</aside>
```

Il `fulfillment_type` di `$order` può mancare, perché gli importi dell'anteprima non lo portano. Le pagine passano `$order` già unito: `$summary['order'] + ['fulfillment_type' => …]`, vedi Task 5 e 6.

`view/components/checkout/mobile.php`:

```php
<?php

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\View\View;
?>
<details class="wi-box p-4 mb-4 pc-none">
    <summary class="d-flex gap-3">
        <span class="w-100"><?=e(__t('ecommerce.checkout.show_cart'))?></span>
        <strong data-checkout-total><?=e(CartPresenter::money($order['total'] ?? 0, $currency))?></strong>
    </summary>
    <div class="mt-4">
        <?=View::component(Ecommerce::viewPath('components/checkout/lines.php'), compact('items', 'currency'))?>
        <?=View::component(Ecommerce::viewPath('components/checkout/totals.php'), ['order' => $order, 'currency' => $currency, 'shippingRow' => \Wonder\Plugin\Gestionale\Gestionale::feature('shipping') && (string) ($order['fulfillment_type'] ?? 'shipping') !== 'pickup'])?>
        <?php if ($coupons): ?>
            <?=View::component(Ecommerce::viewPath('components/checkout/coupon.php'), ['csrf_token' => $csrf_token, 'code' => $couponCode, 'return' => $step === 'payment' ? 'payment' : 'checkout', 'id' => 'checkout-coupon-mobile'])?>
        <?php endif; ?>
    </div>
</details>
```

In `module.json:37-42` aggiungi `"components/checkout"` alla lista `sealed`.

- [ ] **Step 5: Passo Carrello**

`CartController::index` (28–40) passa due variabili in più alla vista:
- `'coupons' => Gestionale::feature('coupons')`, con `use Wonder\Plugin\Gestionale\Gestionale;`;
- `'step' => 'cart'`, tenuta solo per chiarezza.

`flash` (133) diventa `public static function flash(...)`.

`view/pages/cart/index.php` si riscrive. Le righe del carrello restano quelle di oggi (35–65); cambia l'immagine, che diventa la miniatura quadrata.

```php
<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\View\View;

$order = (array) ($cart['order'] ?? []);
$items = CartPresenter::lines(array_values((array) ($cart['items'] ?? [])));
$currency = (string) ($order['currency'] ?? 'EUR');
$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
$labels = [];
foreach (['coupon_remove', 'updating', 'summary_error'] as $key) {
    $labels[$key] = (string) __t('ecommerce.checkout.'.$key);
}
foreach (['products_total', 'discount', 'total'] as $key) {
    $labels[$key] = (string) __t('ecommerce.cart.'.$key);
}

Ecommerce::layout('checkout', compact('errors', 'notice'));
?>
<?=View::component(Ecommerce::viewPath('components/checkout/steps.php'), ['step' => 'cart'])?>
<h1 class="title mb-6"><?=e(__t('ecommerce.cart.title'))?></h1>
<?php if ($items === []): ?>
    <div class="wi-box p-6 a-c">
        <h2 class="subtitle"><?=e(__t('ecommerce.cart.empty_title'))?></h2>
        <p class="text mt-3"><?=e(__t('ecommerce.cart.empty_text'))?></p>
        <?=Button::to((string) (__r('ecommerce.catalog.index') ?: '/'), (string) __t('ecommerce.cart.back_to_shop'))->variant('primary')->class('mt-5')?>
    </div>
<?php else: ?>
    <div class="d-grid col-3 col-t-1 gap-6" data-checkout data-checkout-cart data-step="cart"
        data-summary-url="<?=e(__r('ecommerce.checkout.summary'))?>"
        data-coupon-url="<?=e(__r('ecommerce.checkout.coupon'))?>"
        data-labels="<?=e(json_encode($labels, $flags))?>">
        <div class="col-2 col-t-1 wi-box p-5 d-grid col-1 gap-5">
            <p class="text-small"><?=e(__t('ecommerce.cart.count', ['count' => CartPresenter::count($items)]))?></p>
            <?php foreach ($items as $item): ?>
                <article class="d-flex d-p-column gap-4">
                    <span class="wi-thumb" style="--wi-thumb-size: 88px">
                        <?php if (trim((string) ($item['image'] ?? '')) !== ''): ?><img src="<?=e($item['image'])?>" alt="" loading="lazy"><?php endif; ?>
                    </span>
                    <div class="w-100">
                        <!-- nome, SKU, prezzo e i due form cart_quantity_ / cart_remove_: come le righe 43–62 di oggi -->
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?=View::component(Ecommerce::viewPath('components/checkout/aside.php'), [
            'step' => 'cart', 'items' => $items, 'order' => $order, 'currency' => $currency, 'csrf_token' => $csrf_token,
            'coupons' => $coupons, 'couponCode' => (string) ($order['coupon_code'] ?? ''), 'notices' => [],
            'button' => (string) Button::to((string) __r('ecommerce.checkout.index'), (string) __t('ecommerce.cart.proceed'))->variant('primary')->class('w-100 mt-5'),
        ])?>
    </div>
    <?php if (($checkoutJs = module_asset('ecommerce', 'js/checkout.js')) !== ''): ?>
        <script src="<?=e($checkoutJs)?>"></script>
    <?php endif; ?>
<?php endif; ?>
<?php View::end(); ?>
```

Il commento HTML nel `<div class="w-100">` è solo un segnaposto del piano. Al suo posto vanno le righe 43–62 della vista di oggi, identiche: nome, SKU, prezzo, il form `cart_quantity_ID` e il form `cart_remove_ID`.

Se la rotta `ecommerce.catalog.index` non esiste (verifica con `grep -n "name('ecommerce.catalog" config/routes/route.frontend.php`), usa quella del catalogo che c'è.

- [ ] **Step 6: Coupon con `return` e guardie**

In `CheckoutController`, `guardJson(bool $login = true)` e `guardPage(bool $login = true)`: la verifica del login (199 e 216) diventa `if ($login && !self::guestAllowed() && !CartSession::authenticated())`.

`coupon` (162–188) diventa:

```php
    private static function coupon(): void
    {
        $return = in_array($_POST['return'] ?? '', ['cart', 'payment'], true) ? (string) $_POST['return'] : 'checkout';
        $json = self::wantsJson();
        // Il coupon si prova già dal carrello, prima del login.
        $cartId = $json ? self::guardJson($return !== 'cart') : self::guardPage($return !== 'cart');
        $action = (string) ($_POST['action'] ?? 'apply') === 'remove' ? 'remove' : 'apply';
        $back = match ($return) {
            'cart' => self::route('ecommerce.cart.index'),
            'payment' => self::route('ecommerce.checkout.payment'),
            default => self::route('ecommerce.checkout.index'),
        };

        try {
            // Senza JavaScript il form del coupon non porta il resto del modulo: valgono le scelte del carrello.
            $payload = CheckoutSummary::coupon($cartId, $action, (string) ($_POST['code'] ?? ''), $_POST, CartSession::user(), !$json);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.coupon');

            if ($json) {
                self::json(['success' => false, 'error' => (string) __t('ecommerce.checkout.summary_error')], 500);
            }

            $payload = ['error' => (string) __t('ecommerce.checkout.errors.generic')];
        }

        if ($json) {
            self::json(['success' => $payload['error'] === ''] + $payload);
        }

        $errors = $payload['error'] !== '' ? [$payload['error']] : [];
        $notice = $errors === [] ? (string) __t('ecommerce.checkout.'.($action === 'remove' ? 'coupon_removed' : 'coupon_applied')) : '';
        $return === 'cart' ? CartController::flash($errors, $notice) : self::flash($errors, [], $notice);
        self::redirect($back);
    }
```

Aggiungi `use Wonder\Plugin\Ecommerce\Frontend\Cart\CartController;`.

`flash` prende un terzo parametro, `string $notice = ''`, che va in `$_SESSION[self::FLASH]['notice']`. `pullFlash` lo restituisce come `'notice' => trim((string) ($flash['notice'] ?? ''))` e aggiorna il tipo nel docblock. Le pagine lo passano al layout: Task 5 e 6.

La rotta `ecommerce.checkout.payment` nasce nel Task 6. Fino ad allora `self::route()` ripiega su `/`, e nessuna pagina manda ancora `return=payment`.

- [ ] **Step 7: Verifica**

Run: `php tests/CartCheckoutTest.php && php -l src/Frontend/Checkout/CheckoutController.php && php tests/integrazione/CheckoutSummaryTest.php`

Expected:
- `CartCheckoutTest`: tutte PASS. Le prove che leggono `pages/checkout/index.php` passano ancora, perché quel file c'è fino al Task 6;
- `No syntax errors`;
- `CheckoutSummaryTest`: tutte PASS.

- [ ] **Step 8: Commit**

```bash
git add view/components/checkout view/pages/cart/index.php module.json src/Frontend/Cart/CartController.php src/Frontend/Checkout/CheckoutController.php lang && git add -f tests/CartCheckoutTest.php
git commit -m "$(printf 'E1c: passo Carrello, parziali del checkout e coupon che torna alla pagina\n\nCo-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>')"
```

---

### Task 5 (ecommerce): passo Spedizione

**Files:**
- Create: `view/pages/checkout/shipping.php`
- Modify: `config/routes/route.frontend.php:60-64`
- Modify: `src/Frontend/Checkout/CheckoutController.php`:
  - `handle` (28–35);
  - `index` (38–71);
  - un metodo nuovo, `shipping`.
- Test: `tests/CartCheckoutTest.php`

**Interfaces:**
- Consumes:
  - Task 3: `CheckoutSteps::shippingErrors()` e `fromCart()`;
  - Task 4: i parziali e `flash(array $errors, array $values = [], string $notice = '')`.
- Produces:
  - la rotta `ecommerce.checkout.shipping` (POST `/checkout/shipping/`);
  - la vista `pages/checkout/shipping.php`, con il form `#checkout` e `data-step="shipping"`;
  - dopo un POST valido, il redirect a `ecommerce.checkout.payment`.

- [ ] **Step 1: Scrivi le prove che falliscono**

```php
check('il passo Spedizione salva contatto e consegna e porta al Pagamento', function () use ($root) {
    $routes = (string) file_get_contents($root.'/config/routes/route.frontend.php');
    $c = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
    $view = (string) file_get_contents($root.'/view/pages/checkout/shipping.php');

    return str_contains($routes, "['checkout_action' => 'shipping']")
        && str_contains($c, 'CheckoutSteps::shippingErrors(')
        && str_contains($c, "\$post['shipping_phone']")
        && str_contains($c, "self::route('ecommerce.checkout.payment')")
        && str_contains($view, 'id="checkout"')
        && str_contains($view, 'data-step="shipping"')
        && str_contains($view, 'id="contatto"')
        && str_contains($view, 'id="consegna"')
        && str_contains($view, "__r('ecommerce.checkout.shipping')")
        && str_contains($view, 'ChoiceGroup::make(')
        && str_contains($view, 'data-checkout-choice')
        && str_contains($view, 'data-checkout-inline-submit')
        && substr_count($view, '<h1') === 1;
});
```

- [ ] **Step 2: Verifica che fallisca**

Run: `php tests/CartCheckoutTest.php`

Expected: FAIL solo la prova nuova.

- [ ] **Step 3: Rotta e controller**

In `route.frontend.php`, dopo la riga 61:

```php
                Route::post('/shipping/', $handler, ['checkout_action' => 'shipping'])->name('shipping');
```

In `handle` aggiungi `'shipping' => self::shipping(),`.

`index` (38–71). I valori della pagina vengono, in ordine:
1. dal flash, se c'è;
2. altrimenti dal carrello;
3. per i campi vuoti, dai dati del cliente.

```php
        $flash = self::pullFlash();
        $fromCart = array_filter(CheckoutSteps::fromCart((array) ($cart['order'] ?? [])), static fn (string $v): bool => $v !== '' && $v !== '0');
        $values = $flash['values'] !== [] ? self::defaults($flash['values']) : $fromCart + self::defaults([]);
```

Queste righe sostituiscono le righe 49–50. Poi:
- la riga 51 (`$methods`) e il `payment_field` si tolgono: il pagamento sta nel suo passo;
- la riga 58 apre `pages/checkout/shipping.php`;
- l'array passato alla vista è:

```php
            'cart' => $cart,
            'shipping_fields' => self::fields(array_diff_key(
                Order::shippingAddress()->formSchema($values['shipping_country'] ?? null),
                array_flip(['shipping_name', 'shipping_surname', 'shipping_phone', 'shipping_phone_prefix'])
            ), $values),
            'guest' => !CartSession::authenticated(),
            'csrf_token' => AuthSession::csrfToken(),
            'errors' => $flash['errors'],
            'notice' => $flash['notice'],
            'values' => $values,
            'summary' => $summary,
```

Il metodo nuovo:

```php
    /** Il passo Spedizione: salva contatto e consegna sul carrello, poi va al Pagamento. */
    private static function shipping(): never
    {
        $cartId = self::guardPage();
        $post = $_POST;
        // Il telefono del contatto è anche quello del corriere.
        $post['shipping_phone'] = (string) ($post['phone'] ?? '');

        // Col ritiro non resta un indirizzo vecchio sull'ordine.
        if ((string) ($post['fulfillment_type'] ?? '') === 'pickup') {
            foreach (Order::shippingAddress()->keys() as $key) {
                if (!in_array($key, ['shipping_name', 'shipping_surname', 'shipping_phone', 'shipping_phone_prefix'], true)) {
                    $post[$key] = '';
                }
            }
        }

        try {
            $summary = CheckoutSummary::payload($cartId, $post, CartSession::user());
            $errors = CheckoutSteps::shippingErrors((array) Order::findById($cartId), $summary, Gestionale::feature('shipping'));
            $messages = array_map(static fn (string $key): string => (string) __t('ecommerce.checkout.errors.'.$key), $errors);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.shipping');
            $messages = [(string) __t('ecommerce.checkout.errors.generic')];
        }

        if ($messages !== []) {
            self::flash($messages, $post);
            self::redirect(self::route('ecommerce.checkout.index'));
        }

        self::redirect(self::route('ecommerce.checkout.payment'));
    }
```

Aggiungi `use Wonder\Plugin\Gestionale\Gestionale;`.

Due note:
- `addresses()` del gestionale (`Checkout.php:582`) scrive solo le chiavi presenti, quindi le stringhe vuote del ritiro arrivano davvero sull'ordine.
- Svuotare `shipping_country` col ritiro non rompe l'anteprima: col ritiro non conta.

- [ ] **Step 4: Vista `pages/checkout/shipping.php`**

Le righe 11–52 della vecchia `index.php` restano come sono: variabili, `$labels`, `$gaItems` e `DataLayer begin_checkout`. Poi:

```php
<?=View::component(Ecommerce::viewPath('components/checkout/steps.php'), ['step' => 'shipping'])?>
<h1 class="title mb-6"><?=e(__t('ecommerce.checkout.title'))?></h1>
<?=View::component(Ecommerce::viewPath('components/checkout/mobile.php'), $aside)?>
<div class="d-grid col-3 col-t-1 gap-6">
    <form id="checkout" class="col-2 col-t-1 d-grid col-1 gap-6" method="post" action="<?=e(__r('ecommerce.checkout.shipping'))?>" novalidate
        data-checkout data-step="shipping"
        data-shipping="<?=$shipping ? 'on' : 'off'?>"
        data-coupons="<?=$coupons ? 'on' : 'off'?>"
        data-summary-url="<?=e(__r('ecommerce.checkout.summary'))?>"
        data-coupon-url="<?=e(__r('ecommerce.checkout.coupon'))?>"
        data-labels="<?=e(json_encode($labels, $flags))?>"
        data-initial="<?=e(json_encode($summary, $flags))?>">
        <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
        <section id="contatto" class="wi-box p-5">
            <h2 class="subtitle mb-4"><?=e(__t('ecommerce.checkout.contact'))?></h2>
            <?php if ($guest): ?>
                <p class="text-small mb-4"><?=e(__t('ecommerce.checkout.have_account'))?> <a href="<?=e(__r('ecommerce.auth.login').'?continue='.rawurlencode((string) __r('ecommerce.checkout.index')))?>"><?=e(__t('ecommerce.checkout.login'))?></a></p>
            <?php endif; ?>
            <div class="d-grid col-2 col-p-1 gap-4">
                <?=FormField::key('shipping_name')->text()->label((string) __t('ecommerce.auth.fields.name'))->required()->value($values['shipping_name'] ?? '')?>
                <?=FormField::key('shipping_surname')->text()->label((string) __t('ecommerce.auth.fields.surname'))->required()->value($values['shipping_surname'] ?? '')?>
                <?=FormField::key('email')->email()->label((string) __t('ecommerce.auth.fields.email'))->required()->value($values['email'] ?? '')?>
                <?=FormField::key('phone')->tel()->label((string) __t('ecommerce.auth.fields.mobile'))->required()->value($values['phone'] ?? '')?>
            </div>
        </section>
        <section id="consegna" class="wi-box p-5">
            <h2 class="subtitle mb-4"><?=e(__t('ecommerce.checkout.delivery'))?></h2>
            <?php if ($shipping): ?>
                <div class="mb-5" data-checkout-fulfillment>
                    <?=ChoiceGroup::make()->choices(
                        Choice::make('fulfillment_type', 'shipping')->type('radio')->title((string) __t('ecommerce.checkout.fulfillment_shipping'))->checked($fulfillment === 'shipping'),
                        Choice::make('fulfillment_type', 'pickup')->type('radio')->title((string) __t('ecommerce.checkout.fulfillment_pickup'))->checked($fulfillment === 'pickup')->disabled(!$canPickup)
                    )?>
                </div>
            <?php endif; ?>
            <div data-checkout-shipping<?=$shipping && $fulfillment === 'pickup' ? ' hidden' : ''?>>
                <div class="d-grid col-2 col-p-1 gap-4">
                    <?php foreach ($shipping_fields as $field): ?><?=$field?><?php endforeach; ?>
                </div>
                <?php if ($shipping): ?>
                    <h3 class="subtitle mt-5 mb-3"><?=e(__t('ecommerce.checkout.shipping_methods'))?></h3>
                    <div data-checkout-shipping-methods>
                        <?=ChoiceGroup::make()->choices(...array_map(static fn (array $o): Choice => Choice::make('shipping_method_id', (int) $o['method_id'])
                            ->type('radio')->title((string) $o['name'])->text((string) ($o['description'] ?? ''))->aside((string) ($o['price_display'] ?? ''))
                            ->checked((int) $o['method_id'] === $shippingSelected), $shippingOptions))?>
                    </div>
                    <p class="text-small mt-3" data-checkout-notice role="status"<?=$shippingOptions === [] ? '' : ' hidden'?>><?=$shippingOptions === [] ? e(__t('ecommerce.checkout.no_shipping')) : ''?></p>
                <?php endif; ?>
            </div>
            <?php if ($shipping): ?>
                <div data-checkout-pickup<?=$fulfillment === 'pickup' ? '' : ' hidden'?>>
                    <h3 class="subtitle mb-3"><?=e(__t('ecommerce.checkout.pickup_locations'))?></h3>
                    <div data-checkout-pickup-locations>
                        <?=ChoiceGroup::make()->choices(...array_map(static fn (array $o): Choice => Choice::make('location_id', (int) $o['id'])
                            ->type('radio')->title((string) $o['name'])->text((string) ($o['address'] ?? ''))
                            ->checked((int) $o['id'] === $pickupSelected), $pickupOptions))?>
                    </div>
                </div>
            <?php endif; ?>
            <template data-checkout-choice><?=Choice::make('', '')->type('radio')?></template>
        </section>
        <?=Button::make((string) __t('ecommerce.checkout.continue_payment'))->type('submit')->attr('data-checkout-submit', '')->attr('data-checkout-inline-submit', '')->variant('primary')->class('w-100 pc-none')?>
    </form>
    <?=View::component(Ecommerce::viewPath('components/checkout/aside.php'), $aside + [
        'button' => (string) Button::make((string) __t('ecommerce.checkout.continue_payment'))->type('submit')->attr('form', 'checkout')->attr('data-checkout-submit', '')->variant('primary')->class('w-100 mt-5'),
    ])?>
</div>
<?php if (($checkoutJs = module_asset('ecommerce', 'js/checkout.js')) !== ''): ?>
    <script src="<?=e($checkoutJs)?>"></script>
<?php endif; ?>
<?php View::end(); ?>
```

In testa alla vista, dopo le variabili:

```php
$order = ($summary['order'] ?? null) !== null ? $summary['order'] + ['fulfillment_type' => $fulfillment] : $order;
$aside = [
    'step' => 'shipping', 'items' => (array) ($summary['items'] ?? $items), 'order' => $order, 'currency' => $currency,
    'csrf_token' => $csrf_token, 'coupons' => $coupons, 'couponCode' => $couponCode, 'notices' => (array) ($summary['notices'] ?? []),
];
```

Gli `use` sono: `FormField`, `Button`, `Choice` e `ChoiceGroup` (`Wonder\Elements\Components\*`), `Ecommerce`, `CartPresenter`, `DataLayer`, `Gestionale` e `View`.

`$labels` tiene le chiavi di oggi e aggiunge `no_shipping`. Senza JavaScript, la scelta della consegna e del metodo si conferma col bottone: il controller ricalcola e, se manca il metodo, torna con l'errore.

- [ ] **Step 5: Verifica**

Run: `php tests/CartCheckoutTest.php && php -l view/pages/checkout/shipping.php`

Expected:
- tutte PASS;
- `No syntax errors`.

- [ ] **Step 6: Commit**

```bash
git add config/routes/route.frontend.php src/Frontend/Checkout/CheckoutController.php view/pages/checkout/shipping.php && git add -f tests/CartCheckoutTest.php
git commit -m "$(printf 'E1c: passo Spedizione del checkout\n\nCo-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>')"
```

---

### Task 6 (ecommerce): passo Pagamento e `place` dal carrello

**Files:**
- Create: `view/pages/checkout/payment.php`
- Delete: `view/pages/checkout/index.php`
- Modify: `config/routes/route.frontend.php`
- Modify: `src/Frontend/Checkout/CheckoutController.php`:
  - `handle`;
  - `place` (92–148);
  - un metodo nuovo, `payment`;
  - `paymentField` e `fallbackMethods` si tolgono se restano senza uso.
- Test: `tests/CartCheckoutTest.php`, cioè le prove 76–85, 121–151 e 194–204 più una nuova

**Interfaces:**
- Consumes:
  - Task 2: `payload($cartId, [], $user)` tiene le scelte del carrello;
  - Task 3: `shippingComplete`, `billing`, `paymentErrors`, `askedConsents`, `fromCart`, `CONSENTS`;
  - Task 4: i parziali.
- Produces:
  - la rotta `ecommerce.checkout.payment` (GET `/checkout/payment/`);
  - la vista con il form `#checkout`, `data-step="payment"` e action `ecommerce.checkout.place`;
  - gli errori di `place` tornano a `/checkout/payment/`.

- [ ] **Step 1: Aggiorna le prove**

Le prove che leggevano `view/pages/checkout/index.php` leggono `checkoutViews($root)`:
- 79: recaptcha;
- 123 e 132: `id="checkout"`;
- 136: i ganci;
- 197: `CartPresenter::lines(`.

La prova dei ganci (135–151) cambia così:
- `$hooks` tiene tutte le voci di oggi e aggiunge `'data-checkout-line'`, `'data-checkout-toggle'` e `'data-checkout-total'`;
- `substr_count($view, '<h1') === 1` diventa un controllo per pagina:

```php
    foreach (glob($root.'/view/pages/checkout/*.php') as $page) {
        if (substr_count((string) file_get_contents($page), '<h1') !== 1) {
            return false;
        }
    }
```

Nuova prova:

```php
check('il Pagamento chiede la Spedizione completa e place ordina dal carrello', function () use ($root) {
    $routes = (string) file_get_contents($root.'/config/routes/route.frontend.php');
    $c = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
    $view = (string) file_get_contents($root.'/view/pages/checkout/payment.php');

    return str_contains($routes, "['checkout_action' => 'payment']")
        && !is_file($root.'/view/pages/checkout/index.php')
        && str_contains($c, 'CheckoutSteps::shippingComplete(')
        && str_contains($c, 'CheckoutSteps::fromCart(')
        && str_contains($c, 'CheckoutSteps::paymentErrors(')
        && str_contains($c, 'registerUserConsentsFromPayload(')
        && str_contains($c, "'ecommerce.checkout.errors.shipping_incomplete'")
        && str_contains($view, 'data-step="payment"')
        && str_contains($view, "__r('ecommerce.checkout.place')")
        && str_contains($view, "Choice::make('same_as_shipping'")
        && str_contains($view, "Choice::make('invoice'")
        && str_contains($view, '->acceptDocument(')
        && str_contains($view, "#contatto")
        && str_contains($view, "#consegna");
});
```

La prova 194–204 tiene `"\$flash['values'] === []"` e `'CartSession::user(), !$json)'`: restano tutti e due nel controller.

- [ ] **Step 2: Verifica che falliscano**

Run: `php tests/CartCheckoutTest.php`

Expected: FAIL la prova nuova.

- [ ] **Step 3: Rotta e controller**

In `route.frontend.php`, dopo la riga di `shipping`:

```php
                Route::get('/payment/', $handler, ['checkout_action' => 'payment'])->name('payment');
```

In `handle` aggiungi `'payment' => self::payment(),`.

Due helper privati:

```php
    /**
     * L'anteprima con le scelte del carrello e il carrello riletto; null se il
     * passo Spedizione non è completo (o il gestionale non risponde).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    private static function shippingDone(int $cartId): ?array
    {
        try {
            $summary = CheckoutSummary::payload($cartId, [], CartSession::user());
            $order = (array) Order::findById($cartId);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.payment');

            return null;
        }

        return CheckoutSteps::shippingComplete($order, $summary, Gestionale::feature('shipping')) ? [$summary, $order] : null;
    }

    private static function backToShipping(): never
    {
        self::flash([(string) __t('ecommerce.checkout.errors.shipping_incomplete')]);
        self::redirect(self::route('ecommerce.checkout.index'));
    }
```

Il metodo `payment`:

```php
    private static function payment(): void
    {
        if (!self::guestAllowed() && !CartSession::authenticated()) {
            self::redirect(self::loginUrl());
        }

        $cartId = self::cartId();
        if ($cartId === 0) {
            self::redirect(self::route('ecommerce.cart.index'));
        }

        // Il carrello può essere cambiato dopo la Spedizione: si ricalcola e si ricontrolla.
        [$summary, $order] = self::shippingDone($cartId) ?? self::backToShipping();
        $flash = self::pullFlash();
        $values = $flash['values'] !== []
            ? self::defaults($flash['values'])
            : array_filter(CheckoutSteps::fromCart($order), static fn (string $v): bool => $v !== '' && $v !== '0') + self::defaults([]) + ['same_as_shipping' => '1'];
        $userId = (int) (CartSession::user()->id ?? 0);

        self::seo((string) __t('ecommerce.checkout.title'), self::route('ecommerce.checkout.payment'));
        View::make(Ecommerce::viewPath('pages/checkout/payment.php'), [
            'cart' => CartSession::current(false),
            'order' => $order,
            'summary' => $summary,
            'billing_fields' => self::fields(array_diff_key(
                Order::billingAddress()->formSchema($values['billing_country'] ?? null),
                array_flip(['billing_type', 'billing_business_name', 'billing_cf', 'billing_pi', 'billing_sdi', 'billing_pec'])
            ), $values),
            'business_fields' => self::fields(array_intersect_key(
                Order::billingAddress()->formSchema($values['billing_country'] ?? null),
                array_flip(['billing_business_name', 'billing_pi', 'billing_sdi', 'billing_pec'])
            ), $values),
            'cf_field' => self::fields(array_intersect_key(Order::billingAddress()->formSchema($values['billing_country'] ?? null), ['billing_cf' => true]), $values),
            'consents' => CheckoutSteps::askedConsents($userId),
            'guest' => !CartSession::authenticated(),
            'csrf_token' => AuthSession::csrfToken(),
            'errors' => $flash['errors'],
            'notice' => $flash['notice'],
            'values' => $values,
        ])->render();
    }
```

`self::backToShipping()` restituisce `never`, quindi `?? self::backToShipping()` interrompe la pagina.

In `place` (92–148), le righe 114–128 diventano:

```php
            $cartId = (int) ($cart['order']['id'] ?? 0);
            [, $order] = self::shippingDone($cartId) ?? self::backToShipping();
            $user = CartSession::user();
            $userId = (int) ($user->id ?? 0);
            $asked = CheckoutSteps::askedConsents($userId);
            $billing = CheckoutSteps::billing($_POST, $order);
            $missing = CheckoutSteps::paymentErrors($_POST, $billing, $asked);
            if ($missing !== []) {
                self::flash(array_map(static fn (string $key): string => (string) __t('ecommerce.checkout.errors.'.$key), $missing), $_POST);
                self::redirect(self::route('ecommerce.checkout.payment'));
            }

            // L'ordine nasce dal carrello (Spedizione) più i campi del Pagamento.
            $data = CheckoutForm::data([
                'payment_method_id' => (string) ($_POST['payment_method_id'] ?? ''),
                'customer_note' => (string) ($_POST['customer_note'] ?? ''),
            ] + $billing + CheckoutSteps::fromCart($order), $user);
            $method = self::method($data['payment_method_id']);
            if (!is_array($method)) {
                throw new RuntimeException((string) __t('ecommerce.checkout.errors.payment_method'));
            }
            if (!CheckoutForm::isManual($method)) {
                throw new RuntimeException((string) __t('ecommerce.checkout.errors.provider_pending'));
            }

            $result = Checkout::place($cartId, $data + [
                'customer_id' => CartSession::customerId(),
                'source' => 'ecommerce',
                'user_id' => $userId,
            ]);

            if ($userId > 0 && $asked !== []) {
                try {
                    consentService()->registerUserConsentsFromPayload($userId, $_POST, ['required_document_types' => $asked]);
                } catch (Throwable $error) {
                    // L'ordine è nato: un consenso non registrato non lo ferma.
                    Errors::internal($error, 'ecommerce.checkout.consents');
                }
            }
```

Il resto di `place` non cambia: `$_SESSION[self::COMPLETED]` e i `catch`. L'unica differenza è la riga 147, che diventa `self::redirect(self::route('ecommerce.checkout.payment'));`.

Il consenso dell'ospite (utente 0) si registra nel piano 3, quando nasce l'account.

`index` non usa più `fallbackMethods` né `paymentField`. Se `grep -n "fallbackMethods\|paymentField" src/Frontend/Checkout/CheckoutController.php` trova solo le definizioni, toglile. `paymentMethods` e `method` restano.

- [ ] **Step 4: Vista `pages/checkout/payment.php`**

In testa:

```php
<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Choice;
use Wonder\Elements\Components\ChoiceGroup;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\View\View;

$items = (array) ($summary['items'] ?? []);
$currency = (string) ($summary['order']['currency'] ?? 'EUR');
$coupons = Gestionale::feature('coupons');
$pickup = (string) ($order['fulfillment_type'] ?? '') === 'pickup';
$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
$payments = (array) ($summary['payment_methods']['options'] ?? []);
$selected = (int) ($values['payment_method_id'] ?? $summary['payment_methods']['selected'] ?? 0);
$manual = true;
foreach ($payments as $p) {
    if ((int) $p['id'] === $selected) {
        $manual = (bool) $p['manual'];
    }
}
$submit = (string) __t('ecommerce.checkout.'.($manual ? 'submit_manual' : 'submit_online'));
$labels = [];
foreach (['coupon_remove', 'submit_manual', 'submit_online', 'updating', 'summary_error', 'fees_total', 'shipping_total'] as $key) {
    $labels[$key] = (string) __t('ecommerce.checkout.'.$key);
}
foreach (['products_total', 'discount', 'total'] as $key) {
    $labels[$key] = (string) __t('ecommerce.cart.'.$key);
}
$delivery = '';
if ($pickup) {
    foreach ((array) ($summary['pickup_locations']['options'] ?? []) as $o) {
        if ((int) $o['id'] === (int) $order['location_id']) {
            $delivery = trim($o['name'].' — '.($o['address'] ?? ''), ' —');
        }
    }
} else {
    $delivery = implode(', ', array_filter([trim($order['shipping_street'].' '.$order['shipping_number']), trim($order['shipping_cap'].' '.$order['shipping_city']), $order['shipping_country']]));
    foreach ((array) ($summary['shipping_methods']['options'] ?? []) as $o) {
        if ((int) $o['method_id'] === (int) $order['shipping_method_id']) {
            $delivery .= ' · '.$o['name'];
        }
    }
}
$aside = [
    'step' => 'payment', 'items' => $items, 'order' => $summary['order'] + ['fulfillment_type' => (string) $order['fulfillment_type']], 'currency' => $currency,
    'csrf_token' => $csrf_token, 'coupons' => $coupons, 'couponCode' => (string) ($summary['coupon']['code'] ?? ''), 'notices' => (array) ($summary['notices'] ?? []),
];

Ecommerce::layout('checkout', compact('errors', 'notice'));
?>
```

Il corpo:

```php
<?=View::component(Ecommerce::viewPath('components/checkout/steps.php'), ['step' => 'payment'])?>
<h1 class="title mb-6"><?=e(__t('ecommerce.checkout.payment'))?></h1>
<?=View::component(Ecommerce::viewPath('components/checkout/mobile.php'), $aside)?>
<div class="d-grid col-3 col-t-1 gap-6">
    <form id="checkout" class="col-2 col-t-1 d-grid col-1 gap-6" method="post" action="<?=e(__r('ecommerce.checkout.place'))?>" novalidate
        data-checkout data-step="payment"
        data-coupons="<?=$coupons ? 'on' : 'off'?>"
        data-summary-url="<?=e(__r('ecommerce.checkout.summary'))?>"
        data-coupon-url="<?=e(__r('ecommerce.checkout.coupon'))?>"
        data-labels="<?=e(json_encode($labels, $flags))?>"
        data-initial="<?=e(json_encode($summary, $flags))?>">
        <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
        <section class="wi-box p-5 d-grid col-1 gap-3">
            <div class="d-flex gap-3"><span class="w-100"><span class="d-block text-small tx-secondary"><?=e(__t('ecommerce.checkout.contact'))?></span><?=e(trim($order['shipping_name'].' '.$order['shipping_surname'].' · '.$order['email'].' · '.$order['phone']))?></span><a class="text-small" href="<?=e(__r('ecommerce.checkout.index'))?>#contatto"><?=e(__t('ecommerce.checkout.edit'))?></a></div>
            <div class="d-flex gap-3"><span class="w-100"><span class="d-block text-small tx-secondary"><?=e(__t('ecommerce.checkout.delivery'))?></span><?=e($delivery)?></span><a class="text-small" href="<?=e(__r('ecommerce.checkout.index'))?>#consegna"><?=e(__t('ecommerce.checkout.edit'))?></a></div>
        </section>
        <section class="wi-box p-5">
            <h2 class="subtitle mb-4"><?=e(__t('ecommerce.checkout.payment_method'))?></h2>
            <div data-checkout-payments>
                <?php if ($payments === []): ?>
                    <p class="text"><?=e(__t('ecommerce.checkout.no_payment_methods'))?></p>
                <?php else: ?>
                    <?=ChoiceGroup::make()->choices(...array_map(static fn (array $p): Choice => Choice::make('payment_method_id', (int) $p['id'])
                        ->type('radio')->title((string) $p['name'])->text((string) ($p['instructions'] ?? ''))
                        ->checked((int) $p['id'] === $selected)->attr('data-manual', $p['manual'] ? '1' : '0'), $payments))?>
                <?php endif; ?>
            </div>
            <template data-checkout-choice><?=Choice::make('', '')->type('radio')?></template>
        </section>
        <section class="wi-box p-5">
            <h2 class="subtitle mb-4"><?=e(__t('ecommerce.checkout.billing'))?></h2>
            <?php if (!$pickup): ?>
                <?=Choice::make('same_as_shipping', '1')->type('checkbox')->title((string) __t('ecommerce.checkout.billing_same'))->checked(!empty($values['same_as_shipping']))?>
            <?php endif; ?>
            <div class="d-grid col-2 col-p-1 gap-4 mt-4" data-checkout-toggle="<?=$pickup ? '' : 'same_as_shipping:off'?>">
                <?php foreach ($billing_fields as $field): ?><?=$field?><?php endforeach; ?>
            </div>
            <div class="mt-5">
                <?=Choice::make('invoice', '1')->type('checkbox')->title((string) __t('ecommerce.checkout.invoice'))->checked(!empty($values['invoice']))?>
            </div>
            <div class="mt-4" data-checkout-toggle="invoice:on">
                <?=ChoiceGroup::make()->choices(
                    Choice::make('billing_type', 'private')->type('radio')->title((string) __t('ecommerce.checkout.billing_private'))->checked(($values['billing_type'] ?? 'private') !== 'business'),
                    Choice::make('billing_type', 'business')->type('radio')->title((string) __t('ecommerce.checkout.billing_business'))->checked(($values['billing_type'] ?? '') === 'business')
                )?>
                <div class="d-grid col-2 col-p-1 gap-4 mt-4">
                    <?php foreach ($cf_field as $field): ?><?=$field?><?php endforeach; ?>
                </div>
                <div class="d-grid col-2 col-p-1 gap-4 mt-4" data-checkout-toggle="billing_type:business">
                    <?php foreach ($business_fields as $field): ?><?=$field?><?php endforeach; ?>
                </div>
            </div>
        </section>
        <section class="wi-box p-5">
            <?=FormField::key('customer_note')->textarea()->label((string) __t('ecommerce.checkout.note'))->value($values['customer_note'] ?? '')?>
            <?php if ($consents !== []): ?>
                <h2 class="subtitle mt-5 mb-3"><?=e(__t('ecommerce.checkout.consents'))?></h2>
                <?php foreach ($consents as $type): ?>
                    <?=FormField::key('accept_'.$type)->acceptDocument($type)->required()->value($values['accept_'.$type] ?? '')?>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
        <?php if ($guest): ?>
            <?=FormField::key('recaptcha')->recaptcha('ecommerce_checkout')?>
        <?php endif; ?>
        <?=Button::make($submit)->type('submit')->attr('data-checkout-submit', '')->attr('data-checkout-inline-submit', '')->variant('primary')->class('w-100 pc-none wi-input-submit wi-submit')->disabled($payments === [])?>
    </form>
    <?=View::component(Ecommerce::viewPath('components/checkout/aside.php'), $aside + [
        'button' => (string) Button::make($submit)->type('submit')->attr('form', 'checkout')->attr('data-checkout-submit', '')->variant('primary')->class('w-100 mt-5 wi-input-submit wi-submit')->disabled($payments === []),
    ])?>
</div>
<?php if (($checkoutJs = module_asset('ecommerce', 'js/checkout.js')) !== ''): ?>
    <script src="<?=e($checkoutJs)?>"></script>
<?php endif; ?>
<?php View::end(); ?>
```

Note:
- Senza JavaScript i pannelli `data-checkout-toggle` restano aperti, e `CheckoutSteps::billing` ignora ciò che non serve.
- `data-checkout-toggle=""` (il ritiro) vuol dire «sempre visibile».
- Se `Choice::attr()` non mette `data-manual` sull'`input`, il JS legge il manuale dall'anteprima (`payment_methods.options[].manual`), che c'è già. In quel caso togli `->attr('data-manual', …)`.

Poi elimina la vecchia vista:

```bash
git rm view/pages/checkout/index.php
```

- [ ] **Step 5: Verifica**

Run: `php tests/CartCheckoutTest.php && php -l view/pages/checkout/payment.php && php -l src/Frontend/Checkout/CheckoutController.php && php tests/integrazione/CheckoutSummaryTest.php && php tests/integrazione/CheckoutStepsTest.php`

Expected: tutte PASS, `No syntax errors` due volte.

- [ ] **Step 6: Commit**

```bash
git add config/routes/route.frontend.php src/Frontend/Checkout/CheckoutController.php view/pages/checkout/payment.php && git add -f tests/CartCheckoutTest.php
git commit -m "$(printf 'E1c: passo Pagamento, ordine dal carrello e consensi\n\nCo-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>')"
```

---

### Task 7 (ecommerce): `checkout.js` a passi

**Files:**
- Modify: `resources/assets/js/checkout.js` (340 righe)
- Test: `tests/CartCheckoutTest.php`, le prove 176–204 più una nuova

**Interfaces:**
- Consumes, dai Task 4–6:
  - la radice `[data-checkout]` con `data-step` (`cart`, `shipping` o `payment`), `data-summary-url`, `data-coupon-url`, `data-labels` e `data-initial` (assente sul Carrello);
  - i ganci `data-checkout-lines`, `<template data-checkout-line>` (con `data-line-image`, `data-line-quantity`, `data-line-name`, `data-line-sku`, `data-line-total`), `data-checkout-totals`, `data-checkout-total`, `data-checkout-notices`, `data-checkout-coupon`, `data-checkout-coupon-remove`, `data-checkout-shipping-methods`, `data-checkout-pickup-locations`, `data-checkout-payments`, `<template data-checkout-choice>`, `data-checkout-fulfillment`, `data-checkout-shipping`, `data-checkout-pickup`, `data-checkout-notice`, `data-checkout-toggle` e `data-checkout-submit`;
  - nella Choice: `data-choice-input`, `data-choice-title`, `data-choice-text` e `data-choice-aside` (piano 1).
- Produces: `window.Checkout` e `window.ecommerceCheckout`. Tutti i test di 176–204 restano veri.

Cosa cambia rispetto a oggi; il resto (per esempio `request`, 59–94) non si tocca:

| Riga di oggi | Cambio |
|---|---|
| 2–21 `constructor` | La radice è `document.querySelector('[data-checkout]')`; se manca, si esce. Poi: `this.form = root.tagName === 'FORM' ? root : null`, `this.step = root.dataset.step`, `this.latest = JSON.parse(root.dataset.initial \|\| 'null')`. |
| 23–40 `bind` | Sul Carrello si collegano solo i form `data-checkout-coupon` (tutti, con `querySelectorAll`). Su Spedizione e Pagamento: `input` e `change` del form chiamano `schedule()`; `change` chiama anche `this.toggles()`. Poi il doppio invio, vedi sotto. |
| 49 `body` | Senza `this.form` si crea un `FormData` vuoto con il `csrf_token` preso dal form del coupon. Sul Pagamento si mandano solo `csrf_token` e `payment_method_id`: l'anteprima tiene il resto (Task 2). |
| 106 `coupon` | Aggiunge `return`: il valore viene dal campo del form del coupon. |
| 142 `lines` | Per ogni `[data-checkout-lines]` svuota e clona `<template data-checkout-line>`, poi riempie i `data-line-*` con `textContent`. L'`img` prende `src` = `item.image`; senza immagine l'`img` si rimuove. Lo SKU usa l'etichetta `sku`, aggiunta a `data-labels` con il segnaposto. |
| 152 `totals` | Agisce su ogni `[data-checkout-totals]`. La riga della spedizione c'è solo se `this.step !== 'cart'` e la consegna è `shipping`. Poi aggiorna ogni `[data-checkout-total]` con `display.total`. |
| 211–231 `choices` | Clona `<template data-checkout-choice>` e imposta `input.name`, `input.value` e `input.checked`. `[data-choice-title]`, `[data-choice-text]` e `[data-choice-aside]` prendono il loro `textContent`; un testo vuoto mette `hidden`. Il gruppo è il primo `[data-choice-list]` dentro il contenitore; se manca, il contenitore stesso. |
| 233 `shippingMethods`, 248 `pickup`, 256 `payments` | Passano a `choices()` titolo, testo e aside: nome, descrizione e prezzo per i metodi; nome e indirizzo per le sedi; nome e istruzioni per i pagamenti. `payments` agisce solo se `this.step === 'payment'`. |
| 285 `submit` | Cambia il testo di ogni `[data-checkout-submit]` solo se `this.step === 'payment'`. |
| 312–335 `announce` | Non cambia. `add_shipping_info` parte alla scelta del metodo sulla Spedizione, `add_payment_info` alla scelta del pagamento. |

Metodi nuovi:

```js
    // I pannelli si aprono e chiudono con le caselle: «nome:on», «nome:off» o «nome:valore».
    toggles() {
        this.root.querySelectorAll('[data-checkout-toggle]').forEach((panel) => {
            const [name, want] = (panel.dataset.checkoutToggle || '').split(':');
            if (!name) {
                panel.hidden = false;
                return;
            }
            const inputs = [...this.root.querySelectorAll(`[name="${name}"]`)];
            const on = inputs.some((input) => input.checked && (want === 'on' || want === 'off' || input.value === want));
            panel.hidden = want === 'off' ? on : !on;
        });
    }

    // Un clic solo: il bottone si spegne all'invio e torna acceso se il browser riapre la pagina dalla cache.
    guardSubmit() {
        this.form.addEventListener('submit', () => {
            setTimeout(() => document.querySelectorAll('[data-checkout-submit]').forEach((button) => { button.disabled = true; }), 0);
        });
        window.addEventListener('pageshow', () => {
            document.querySelectorAll('[data-checkout-submit]:not([data-checkout-locked])').forEach((button) => { button.disabled = false; });
        });
    }
```

`bind` chiama `this.toggles()` e `this.guardSubmit()` quando c'è `this.form`.

Il `setTimeout(…, 0)` serve perché il bottone deve partire con il form prima di spegnersi. Il pagamento online resta spento finché il provider non è collegato: è il `disabled` della vista che decide, e `pageshow` non lo accende se non ci sono pagamenti. Per questo riaccendi solo i bottoni senza `data-checkout-locked`, e nella vista metti `->attr('data-checkout-locked', '')` dove c'è `->disabled($payments === [])` con `$payments === []` vero.

- [ ] **Step 1: Scrivi la prova che fallisce**

```php
check('checkout.js lavora a passi, clona i template e non manda due volte', function () use ($root) {
    $js = (string) file_get_contents($root.'/resources/assets/js/checkout.js');

    return str_contains($js, "querySelector('[data-checkout]')")
        && str_contains($js, 'dataset.step')
        && str_contains($js, '[data-checkout-line]')
        && str_contains($js, '[data-checkout-choice]')
        && str_contains($js, 'querySelectorAll(\'[data-checkout-lines]\')')
        && str_contains($js, 'data-checkout-toggle')
        && str_contains($js, "'pageshow'")
        && str_contains($js, 'data-checkout-locked')
        && str_contains($js, '.content.cloneNode(true)');
});
```

- [ ] **Step 2: Verifica che fallisca**

Run: `php tests/CartCheckoutTest.php`

Expected: FAIL solo la prova nuova.

- [ ] **Step 3: Implementa** i cambi della tabella e i metodi nuovi

Tieni `this.sequence++` come prima riga di `schedule()`, sotto il suo commento, perché la regex della prova 199 lo cerca. Restano anche: `setTimeout(…, 300)`, `if (sequence !== this.sequence)`, `payload.redirect`, `X-Requested-With`, `this.submit(this.latest)` e `dataLayer.push({ ecommerce: null })`. Niente `innerHTML`.

In `shipping.php`, `payment.php` e `pages/cart/index.php`, aggiungi a `$labels` la chiave `sku` (`(string) __t('ecommerce.cart.sku', ['sku' => ':sku'])`). Il JS sostituisce `:sku`.

- [ ] **Step 4: Verifica**

Run: `php tests/CartCheckoutTest.php && node --check resources/assets/js/checkout.js`

Expected: tutte PASS; `node --check` non stampa nulla.

- [ ] **Step 5: Commit**

```bash
git add resources/assets/js/checkout.js view/pages && git add -f tests/CartCheckoutTest.php
git commit -m "$(printf 'E1c: checkout.js a passi, scelte da template e un solo invio\n\nCo-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>')"
```

---

### Task 8: Suite complete e prova nel browser

- [ ] **Step 1: Suite**

Run:
```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/run.php > /private/tmp/claude-501/gestionale-suite.txt 2>&1; tail -5 /private/tmp/claude-501/gestionale-suite.txt
cd /Users/andreamarinoni/Developer/packages/ecommerce && php tests/run.php > /private/tmp/claude-501/ecommerce-suite.txt 2>&1; tail -5 /private/tmp/claude-501/ecommerce-suite.txt
cd /Users/andreamarinoni/Developer/packages/app && php tests/run.php > /private/tmp/claude-501/app-suite.txt 2>&1; tail -5 /private/tmp/claude-501/app-suite.txt
```

Expected: tutte verdi. Se `run.php` non esiste in gestionale o in app, lancia i test come fanno gli altri piani (`for f in tests/*Test.php tests/integrazione/*Test.php; do php "$f"; done`). Se in gestionale si rompe qualcosa che non dipende da questo piano, va nel resoconto con il nome della prova.

- [ ] **Step 2: Browser** (in-app, `https://ecommerce.test`, utente di prova; l'accesso lo fa Claude)

Desktop:
1. **Carrello**:
   - un articolo nel carrello;
   - `/cart/` mostra i passi, le miniature quadrate e l'aside con coupon e totali;
   - un coupon applicato aggiorna i totali senza ricaricare;
   - «Procedi alla spedizione» porta avanti.
2. **Spedizione**:
   - i campi sono precompilati;
   - cambiando la città, metodi e totale si aggiornano;
   - passare al ritiro nasconde l'indirizzo e mostra le sedi;
   - un'email non valida, con «Continua al pagamento», torna con l'errore e i valori tenuti.
3. **Pagamento**:
   - il riepilogo ha i link «Modifica»;
   - togliendo «Uguale alla spedizione» compaiono i campi;
   - «Mi serve la fattura» con «Azienda» mostra i campi dell'azienda;
   - cambiando metodo, il bottone passa a «Vai al pagamento» o «Ordina»;
   - il bonifico con i consensi fa nascere l'ordine e porta a `completed`.
4. **Pagamento aperto subito**: con un carrello nuovo, `/checkout/payment/` riporta alla Spedizione col messaggio.

Telefono (`resize_window` mobile, poi preset desktop alla fine):
- il `<details>` «Mostra carrello» è chiuso e porta il totale;
- il bottone in fondo al box di sinistra c'è e l'aside no.

Senza JavaScript, tramite `javascript_tool`: non serve. La prova statica e i pannelli aperti per default bastano.

- [ ] **Step 3: Nessun commit** se le prove nel browser non cambiano nulla. Se trovi un difetto: prova che fallisce, correzione, commit nel repository giusto.

---

## Dopo il piano

- Revisione finale di tutto il ramo, come da `superpowers:executing-plans`.
- Poi, con la conferma dell'utente: PR di `e1c-checkout-a-passi` in gestionale ed ecommerce, e di `checkout-a-passi-componenti` in app e lib. Merge commit su main, poi si eliminano rami remoti, rami locali e worktree.
- **Piano 3**: ospite senza password con l'account creato comunque, consensi dell'ospite, guida, CHANGELOG e TODO.
