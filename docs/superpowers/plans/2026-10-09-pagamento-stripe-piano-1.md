# Pagamento con Stripe, piano 1 (Payment Element) — piano di lavoro

> **Per chi esegue:** SOTTO-SKILL OBBLIGATORIA: superpowers:subagent-driven-development (consigliata) oppure superpowers:executing-plans, un compito alla volta. I passi usano le caselle (`- [ ]`) per tenere il conto.

**Obiettivo:** il negozio incassa con Stripe nel proprio checkout (Payment Element). L'ordine si conferma una sola volta, che la conferma arrivi dalla pagina di ritorno, dal webhook o dal riallineamento orario. Test e produzione non si mescolano mai.

**Architettura:** l'app dà le credenziali, le chiamate Stripe sul conto collegato (`PaymentIntent`, `Connect`) e il bottone «Collega webhook». Il gestionale dà il contratto `PaymentProvider` e l'adapter `StripeProvider`. Dà anche il registro dei pagamenti online (`OnlinePayments`), il webhook, il riallineamento orario e la cancellazione dell'intento quando l'ordine si annulla. L'ecommerce manda `place` in fetch quando il metodo è Stripe, monta il Payment Element in modalità differita e gestisce ritorno, pagina di pagamento e abbandono.

**Tecnologie:** PHP 8.2, stripe-php ^17.2 (`\Stripe\StripeClient`, `\Stripe\Webhook`), Stripe.js v3 da `https://js.stripe.com/v3/`, JS senza build, test con `check()`/`summary()` di `tests/harness.php`.

**Spec:** `docs/superpowers/specs/2026-10-09-pagamento-stripe-design.md` (§4–§10 e §12 senza express). Chi esegue legge spec e piano insieme.

## Vincoli globali

- Branch `pagamento-stripe` in tre worktree: `/Users/andreamarinoni/Developer/worktrees/pagamento-stripe/{app,gestionale,ecommerce}`. Mai `git switch` nelle cartelle `packages/*`: altre sessioni ci lavorano.
- Nessun `git stash`. Si mettono in stage solo i file del compito, per nome.
- Messaggi di commit in italiano, chiusi da `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Niente push, PR o merge senza il sì esplicito dell'utente.
- Le chiavi Stripe vere non entrano mai nel codice, nei test, nei log o nelle risposte. Nei test si usano `sk_test_prova`, `pk_test_prova`, `whsec_prova` e simili.
- Gli importi si confrontano sempre in centesimi interi: `(int) round($totale * 100) === $centesimi`. Le valute si confrontano senza badare a maiuscole e minuscole.
- Ambiente attivo: `Credentials::api()->stripe_test ? 'test' : 'live'`. Una sola funzione lo calcola: `StripeProvider::activeEnvironment()`.
- Stripe.js si carica solo se la pagina offre almeno un metodo Stripe.
- Nel browser arrivano solo la chiave pubblica e l'id del conto collegato.
- Form del backend compatti: tooltip al posto dei testi d'aiuto, nessun campo automatico.
- TDD: ogni test si vede fallire prima di scrivere il codice.
- Nell'ecommerce altre chat cambiano viste e catalogo su `main`. Le modifiche si ancorano a simboli e segni (`data-checkout-payments`, `function place`, `match ($action)`), non a numeri di riga.
- Basi attuali delle suite: gestionale 192 file verdi, ecommerce 24, app 181. In app `tests/scheduler-integration.php` fallisce per scelta: è l'unico rosso ammesso.

## Punti da guardare nella revisione

Cinque casi che la spec implica e che danno più fastidio a chi usa il negozio. Ognuno ha il suo test nel compito indicato.

1. **Carta rifiutata, poi un nuovo tentativo.** Il modulo resta bloccato (non si può rifare `place`), e il nuovo «Paga» richiama `confirmPayment` con lo stesso `client_secret`. Non nasce un secondo ordine. → Compito 17 (`if (!this.placed)`), Compito 18 (prova nel browser) e Compito 15 (`place` col carrello vuoto e un ordine in attesa).
2. **Ritorno con esito negativo e il carrello già vuoto.** Il carrello è diventato l'ordine al `place`. Si torna alla pagina di pagamento dell'ordine in sessione, non a un checkout vuoto. → Compito 14.
3. **Ogni uscita di `place` in JSON risponde JSON**, anche errori di convalida, reCAPTCHA, totale cambiato ed eccezioni. Un `redirect` in mezzo rompe il fetch. → Compito 15 (`CheckoutPlaceJsonTest` strutturale, `CheckoutPayHttpTest` sulle uscite).
4. **«Collega webhook» usa le chiavi dell'ambiente scelto**, non quelle dell'ambiente attivo. → Compito 3.
5. **Pagina di ritorno con il `payment_intent` di un altro ordine:** 404, e niente conferma. → Compito 14.

---

## Mappa dei file

**app** (`/Users/andreamarinoni/Developer/worktrees/pagamento-stripe/app`)

| File | Compito | Cosa fa |
|------|---------|---------|
| `class/App/Credentials.php` | 1 | 4 chiavi nuove e 2 calcolate, estratte in `stripe()` |
| `class/App/Models/Config/Security.php` | 1 | colonne e campi delle 4 chiavi |
| `class/App/Resources/Config/SecurityResource.php` | 1, 3 | campi nella card Stripe, bottoni «Collega webhook» |
| `class/Plugin/Stripe/PaymentIntent.php` (nuovo) | 2 | intenti, Customer e rimborsi sul conto collegato |
| `class/Plugin/Stripe/Connect.php` (nuovo) | 3 | endpoint del webhook e dominio, per ambiente |
| `app/http/api/service/stripe/connect.php` (nuovo) | 3 | azione del bottone |
| `app/config/routes/route.api.php` | 3 | rotta `service.stripe.connect` |
| `tests/App/CredentialsStripeTest.php` (nuovo) | 1 | |
| `tests/Plugin/Stripe/FakeStripeHttp.php` (nuovo) | 2 | client HTTP finto di stripe-php |
| `tests/Plugin/Stripe/PaymentIntentTest.php`, `ConnectTest.php` (nuovi) | 2, 3 | |

**gestionale** (`/Users/andreamarinoni/Developer/worktrees/pagamento-stripe/gestionale`)

| File | Compito | Cosa fa |
|------|---------|---------|
| `src/Models/Payments/Payment.php` | 4 | colonna `environment` |
| `src/Support/Payments/Ledger.php` | 4 | `attach()`, `byReference()` |
| `src/Support/Providers/ProviderEvents.php` | 5 | `receive` scarta solo i `processed`; `markFailed(..., $final)` |
| `src/Providers/Payments/*.php` (nuovi) | 6, 7 | contratto, valori, `StripeProvider` |
| `src/Support/Payments/PaymentProviders.php` | 6 | registro |
| `src/Support/Payments/OnlinePayments.php`, `PaymentMismatch.php` (nuovi) | 8, 13 | start, confirm, fail, refund; ambiente per il bollino |
| `src/Support/Payments/PaymentEvents.php` (nuovo) | 8 | firma, registro degli eventi, smistamento |
| `config/routes/route.api.php`, `http/api/stripe/webhook.php` (nuovi), `module.json` | 8 | rotta del webhook |
| `src/Support/Orders/Checkout.php`, `Lifecycle.php` | 9, 11 | email al `place` e alla conferma, cancellazione dell'intento |
| `src/Support/Payments/Reconcile.php` (nuovo) | 12 | riallineamento degli intenti in attesa |
| `src/Extensions/ProvidesOrderEmailExtras.php`, `OrderEmailExtras.php` (nuovi), `OrderNotifier.php` | 10 | link per la password dell'ospite alla conferma |
| `src/Scheduler/StripeReconcileTask.php` (nuovo), `src/Gestionale.php` | 12 | riallineamento orario |
| `src/Resources/Sales/OrderResource.php` | 13 | bollino «Prova» |
| `tests/integrazione/LedgerAttachTest.php`, `StripeProviderTest.php`, `OnlinePaymentsTest.php`, `PaymentEventsTest.php`, `ReconcileTest.php`, `OrderEmailsTest.php`, `CheckoutTest.php`, `ProviderEventsTest.php`; `tests/PaymentProvidersTest.php`, `OrderEmailExtrasTest.php`, `OrderResourceTest.php`, `TasksTest.php` | 4–13 | |
| `TODO.md` | 18 | |
| `tests/integrazione/supporto/FakeStripeHttp.php`, `FakePaymentProvider.php`, `firma.php` (nuovi) | 6, 7 | |
| `tests/fixtures/stripe/*.json` (nuovi) | 7 | eventi |

**ecommerce** (`/Users/andreamarinoni/Developer/worktrees/pagamento-stripe/ecommerce`)

| File | Compito | Cosa fa |
|------|---------|---------|
| `src/Frontend/Checkout/OnlinePayment.php` (nuovo) | 14 | logica dei pagamenti online, provabile senza HTTP |
| `src/Frontend/Checkout/CheckoutController.php` | 14, 15 | `place` in JSON, `pay`, `return`, `abandon` |
| `config/routes/route.frontend.php` | 14 | tre rotte nuove |
| `src/Ecommerce.php` | 16 | `ProvidesOrderEmailExtras` |
| `src/Frontend/Checkout/CheckoutSummary.php` | 17 | `payload.stripe`, `payment_method_types` |
| `view/pages/checkout/pay.php` (nuovo), `completed.php` | 14 | pagina «Paga ora», esito in lavorazione |
| `lang/it/ecommerce.json`, `lang/en/ecommerce.json` | 14, 15 | testi di `pay.*` ed errori |
| `resources/assets/js/checkout.js`, `view/pages/checkout/index.php` | 17 | Payment Element, «Paga» in fetch, pagina «Paga ora» |
| `tests/integrazione/OnlinePaymentTest.php`, `CheckoutPayHttpTest.php`, `OrderEmailExtrasTest.php` (nuovi) | 14, 15, 16 | |
| `tests/CheckoutPlaceJsonTest.php`, `CheckoutStripeTest.php` (nuovi), `CartCheckoutTest.php` | 15, 17 | |
| `TODO.md` | 18 | |

## Preparazione (una volta, prima del Compito 1)

- [ ] **P1: worktree di app ed ecommerce.** Quello del gestionale esiste già.

```bash
git -C /Users/andreamarinoni/Developer/packages/app worktree add -b pagamento-stripe /Users/andreamarinoni/Developer/worktrees/pagamento-stripe/app main
git -C /Users/andreamarinoni/Developer/packages/ecommerce worktree add -b pagamento-stripe /Users/andreamarinoni/Developer/worktrees/pagamento-stripe/ecommerce main
```

- [ ] **P2: vendor nei tre worktree**, copiati (non collegati) dai pacchetti. Salta quelli che ce l'hanno già.

```bash
for x in app gestionale ecommerce; do [ -d /Users/andreamarinoni/Developer/worktrees/pagamento-stripe/$x/vendor ] || cp -R /Users/andreamarinoni/Developer/packages/$x/vendor /Users/andreamarinoni/Developer/worktrees/pagamento-stripe/$x/; done
```

- [ ] **P3: il sito di prova punta ai worktree.** Così i test d'integrazione e il browser vedono il codice nuovo.

```bash
for x in app gestionale ecommerce; do ln -sfn /Users/andreamarinoni/Developer/worktrees/pagamento-stripe/$x/ /Users/andreamarinoni/Developer/boilerplates/ecommerce-site/vendor/wonder-image/$x; done
```

- [ ] **P4: basi verdi.** Esegui le tre suite e annota i numeri.

```bash
cd /Users/andreamarinoni/Developer/worktrees/pagamento-stripe/gestionale && php tests/run.php > /tmp/base-gestionale.txt; tail -3 /tmp/base-gestionale.txt
```

Atteso: «Tutti i test del gestionale passano.» Fai lo stesso con `php tests/run.php` in ecommerce e in app (in app l'unico rosso ammesso è `scheduler-integration.php`).

- [ ] **P5: schema del database.** Dopo il Compito 4 la tabella dei pagamenti cambia. Esegui l'aggiornamento dello schema nel sito di prova, con lo stesso comando delle volte precedenti:

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update
```

**Ripristino (alla fine, nel Compito 18):**

```bash
for x in app gestionale ecommerce; do ln -sfn ../../../../packages/$x/ /Users/andreamarinoni/Developer/boilerplates/ecommerce-site/vendor/wonder-image/$x; done
```

---

## Parte A — app

Cartella di lavoro: `/Users/andreamarinoni/Developer/worktrees/pagamento-stripe/app`. Suite: `php tests/run.php`.

### Compito 1: credenziali Stripe nuove

**File:**
- Modifica: `class/App/Credentials.php` (blocco Stripe di `api()`, da `// stripe_test è un booleano` a `stripe_api_key`; `apiDefaults()`)
- Modifica: `class/App/Models/Config/Security.php` (colonne accanto a `stripe_test_account_id`, campi accanto a `Field::key('stripe_test_account_id')`)
- Modifica: `class/App/Resources/Config/SecurityResource.php` (`formSchema()` accanto a `stripe_account_id`; card «Stripe»)
- Crea: `tests/App/CredentialsStripeTest.php`

**Interfacce:**
- Consuma: niente.
- Produce: `Credentials::api()->stripe_public_key`, `stripe_test_public_key`, `stripe_webhook_secret`, `stripe_test_webhook_secret` (stringhe), `stripe_publishable_key` e `stripe_webhook_key` (calcolate da `stripe_test`). `protected static function stripe(object $api, array $row): void`. Colonne `security.stripe_public_key`, `stripe_test_public_key`, `stripe_webhook_secret`, `stripe_test_webhook_secret`.

- [ ] **Passo 1: test che fallisce**

```php
<?php
/** php tests/App/CredentialsStripeTest.php */
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../harness.php';

use Wonder\App\Credentials;
use Wonder\App\Models\Config\Security;
use Wonder\App\Resources\Config\SecurityResource;

if (!function_exists('mailService')) {
    function mailService(): array
    {
        return ['phpmailer' => 'PHPMailer', 'brevo' => 'Brevo'];
    }
}

const NUOVE = ['stripe_public_key', 'stripe_test_public_key', 'stripe_webhook_secret', 'stripe_test_webhook_secret'];

// Le variabili d'ambiente della macchina non devono falsare le prove.
foreach (['STRIPE_TEST', 'STRIPE_TEST_KEY', 'STRIPE_PRIVATE_KEY', 'STRIPE_ACCOUNT_ID', 'STRIPE_TEST_ACCOUNT_ID',
    'STRIPE_PUBLIC_KEY', 'STRIPE_TEST_PUBLIC_KEY', 'STRIPE_WEBHOOK_SECRET', 'STRIPE_TEST_WEBHOOK_SECRET'] as $chiave) {
    unset($_ENV[$chiave]);
}

$credenziali = new class extends Credentials {
    public static function leggi(array $riga): object
    {
        $api = static::apiDefaults();
        static::stripe($api, $riga);

        return $api;
    }
};

$riga = [
    'stripe_test' => 'false',
    'stripe_private_key' => 'sk_live_prova',
    'stripe_test_key' => 'sk_test_prova',
    'stripe_account_id' => 'acct_live',
    'stripe_test_account_id' => 'acct_test',
    'stripe_public_key' => 'pk_live_prova',
    'stripe_test_public_key' => 'pk_test_prova',
    'stripe_webhook_secret' => 'whsec_live_prova',
    'stripe_test_webhook_secret' => 'whsec_test_prova',
];

check('i default hanno le sei chiavi nuove, vuote', function () {
    $api = Credentials::apiDefaults();

    foreach ([...NUOVE, 'stripe_publishable_key', 'stripe_webhook_key'] as $chiave) {
        if (($api->$chiave ?? null) !== '') {
            return false;
        }
    }

    return true;
});

check('produzione: chiave pubblica e segreto del webhook di produzione', function () use ($credenziali, $riga) {
    $api = $credenziali::leggi($riga);

    return $api->stripe_publishable_key === 'pk_live_prova'
        && $api->stripe_webhook_key === 'whsec_live_prova'
        && $api->stripe_api_key === 'sk_live_prova'
        && $api->stripe_id === 'acct_live';
});

check('test: chiave pubblica e segreto del webhook di test', function () use ($credenziali, $riga) {
    $api = $credenziali::leggi(['stripe_test' => 'true'] + $riga);

    return $api->stripe_publishable_key === 'pk_test_prova'
        && $api->stripe_webhook_key === 'whsec_test_prova'
        && $api->stripe_api_key === 'sk_test_prova'
        && $api->stripe_id === 'acct_test';
});

check('.env vince sulla riga anche per le chiavi nuove', function () use ($credenziali, $riga) {
    $_ENV['STRIPE_TEST_PUBLIC_KEY'] = 'pk_test_env';
    $_ENV['STRIPE_TEST'] = 'true';

    try {
        return $credenziali::leggi($riga)->stripe_publishable_key === 'pk_test_env';
    } finally {
        unset($_ENV['STRIPE_TEST_PUBLIC_KEY'], $_ENV['STRIPE_TEST']);
    }
});

check('la tabella security ha le quattro colonne', function () {
    $colonne = array_map(static fn ($column) => $column->name, Security::tableSchema());

    return array_diff(NUOVE, $colonne) === [];
});

check('il data schema ha le quattro chiavi', function () {
    $campi = array_map(static fn ($field) => $field->key, Security::dataSchema());

    return array_diff(NUOVE, $campi) === [];
});

check('la pagina Sicurezza ha i quattro campi', function () {
    $campi = array_map(static fn ($field) => $field->name, SecurityResource::formSchema());

    return array_diff(NUOVE, $campi) === [];
});

summary();
```

- [ ] **Passo 2: lo vedi fallire**

Esegui: `php tests/App/CredentialsStripeTest.php`
Atteso: FAIL. Errore fatale su `static::stripe` (metodo inesistente), oppure i controlli dei default e della tabella falliscono.

- [ ] **Passo 3: il codice**

In `Credentials::api()` sostituisci tutto il blocco Stripe (dal commento `// stripe_test è un booleano` fino alla riga di `stripe_api_key` compresa) con una riga:

```php
                self::stripe(self::$API, $row);
```

Aggiungi il metodo nella classe, subito dopo `api()`:

```php
        /**
         * Le chiavi Stripe. Le calcolate seguono `stripe_test`: chi incassa
         * usa sempre `stripe_api_key`, `stripe_id`, `stripe_publishable_key`
         * e `stripe_webhook_key`, e così test e produzione non si mescolano.
         */
        protected static function stripe(object $api, array $row): void
        {
            // stripe_test è un booleano: env "true"/"1"/"on" → true.
            $stripeTestEnv = trim((string) ($_ENV['STRIPE_TEST'] ?? ''));
            if ($stripeTestEnv !== '') {
                $api->stripe_test = filter_var($stripeTestEnv, FILTER_VALIDATE_BOOLEAN);
            } elseif (isset($row['stripe_test'])) {
                $api->stripe_test = filter_var($row['stripe_test'], FILTER_VALIDATE_BOOLEAN);
            }

            $api->stripe_test_key            = self::envOrRow('STRIPE_TEST_KEY',            $row, 'stripe_test_key',            $api->stripe_test_key);
            $api->stripe_private_key         = self::envOrRow('STRIPE_PRIVATE_KEY',         $row, 'stripe_private_key',         $api->stripe_private_key);
            $api->stripe_account_id          = self::envOrRow('STRIPE_ACCOUNT_ID',          $row, 'stripe_account_id',          $api->stripe_account_id);
            $api->stripe_test_account_id     = self::envOrRow('STRIPE_TEST_ACCOUNT_ID',     $row, 'stripe_test_account_id',     $api->stripe_test_account_id);
            $api->stripe_public_key          = self::envOrRow('STRIPE_PUBLIC_KEY',          $row, 'stripe_public_key',          $api->stripe_public_key);
            $api->stripe_test_public_key     = self::envOrRow('STRIPE_TEST_PUBLIC_KEY',     $row, 'stripe_test_public_key',     $api->stripe_test_public_key);
            $api->stripe_webhook_secret      = self::envOrRow('STRIPE_WEBHOOK_SECRET',      $row, 'stripe_webhook_secret',      $api->stripe_webhook_secret);
            $api->stripe_test_webhook_secret = self::envOrRow('STRIPE_TEST_WEBHOOK_SECRET', $row, 'stripe_test_webhook_secret', $api->stripe_test_webhook_secret);

            $api->stripe_id              = $api->stripe_test ? $api->stripe_test_account_id : $api->stripe_account_id;
            $api->stripe_api_key         = $api->stripe_test ? $api->stripe_test_key : $api->stripe_private_key;
            $api->stripe_publishable_key = $api->stripe_test ? $api->stripe_test_public_key : $api->stripe_public_key;
            $api->stripe_webhook_key     = $api->stripe_test ? $api->stripe_test_webhook_secret : $api->stripe_webhook_secret;
        }
```

In `apiDefaults()`, dopo `'stripe_api_key' => '',`:

```php
                'stripe_public_key' => '',
                'stripe_test_public_key' => '',
                'stripe_webhook_secret' => '',
                'stripe_test_webhook_secret' => '',
                'stripe_publishable_key' => '',
                'stripe_webhook_key' => '',
```

In `Security.php`, dopo `Column::key('stripe_test_account_id'),`:

```php
            Column::key('stripe_public_key'),
            Column::key('stripe_test_public_key'),
            Column::key('stripe_webhook_secret'),
            Column::key('stripe_test_webhook_secret'),
```

e dopo `Field::key('stripe_test_account_id')->text(),`:

```php
            Field::key('stripe_public_key')->text(),
            Field::key('stripe_test_public_key')->text(),
            Field::key('stripe_webhook_secret')->text(),
            Field::key('stripe_test_webhook_secret')->text(),
```

In `SecurityResource`, nelle etichette (accanto a `'stripe_test_account_id' => 'Account ID Test',`):

```php
            'stripe_public_key' => 'Chiave pubblica',
            'stripe_test_public_key' => 'Chiave pubblica Test',
            'stripe_webhook_secret' => 'Segreto webhook',
            'stripe_test_webhook_secret' => 'Segreto webhook Test',
```

in `formSchema()`, dopo `FormField::key('stripe_test_account_id')->text()->readonly(),`:

```php
            FormField::key('stripe_public_key')->text(),
            FormField::key('stripe_test_public_key')->text(),
            FormField::key('stripe_webhook_secret')->text()->readonly(),
            FormField::key('stripe_test_webhook_secret')->text()->readonly(),
```

e nella card «Stripe», sotto `static::getInput('stripe_account_id')->columnSpan(12),` e sotto `static::getInput('stripe_test_account_id')->columnSpan(12),`, i campi del proprio ambiente:

```php
                    static::getInput('stripe_public_key')->columnSpan(6),
                    static::getInput('stripe_webhook_secret')->columnSpan(6),
```

```php
                    static::getInput('stripe_test_public_key')->columnSpan(6),
                    static::getInput('stripe_test_webhook_secret')->columnSpan(6),
```

- [ ] **Passo 4: lo vedi passare, con la suite**

Esegui: `php tests/App/CredentialsStripeTest.php && php tests/App/CredentialsPaymentProvidersTest.php`
Atteso: PASS, 7 e 4 controlli verdi.
Esegui: `php tests/run.php > /tmp/app-1.txt; tail -5 /tmp/app-1.txt`
Atteso: rosso solo `scheduler-integration.php`.

- [ ] **Passo 5: commit**

```bash
git add class/App/Credentials.php class/App/Models/Config/Security.php class/App/Resources/Config/SecurityResource.php tests/App/CredentialsStripeTest.php
git commit -m "Credenziali Stripe: chiavi pubbliche e segreti del webhook per ambiente

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Compito 2: `PaymentIntent` sul conto collegato

**File:**
- Crea: `class/Plugin/Stripe/PaymentIntent.php`
- Crea: `tests/Plugin/Stripe/FakeStripeHttp.php`
- Crea: `tests/Plugin/Stripe/PaymentIntentTest.php`

**Interfacce:**
- Consuma: niente.
- Produce:
  - `final class Wonder\Plugin\Stripe\PaymentIntent`, `__construct(string $secretKey, string $accountId)`.
  - `create(array $params, string $idempotencyKey): \Stripe\PaymentIntent`
  - `get(string $id): \Stripe\PaymentIntent`
  - `update(string $id, array $params): \Stripe\PaymentIntent`
  - `cancel(string $id): \Stripe\PaymentIntent`
  - `createCustomer(array $params, string $idempotencyKey): \Stripe\Customer`
  - `refundsOf(string $chargeId): list<array{id: string, amount: int, status: string}>`
  - `FakeStripeHttp` (solo test): `queue(int $code, array $body): void`, `public array $requests` di `['method', 'url', 'headers' => array<string,string>, 'params']`, `header(int $i, string $name): ?string`.

- [ ] **Passo 1: il client finto**

`tests/Plugin/Stripe/FakeStripeHttp.php`:

```php
<?php
declare(strict_types=1);

/**
 * Client HTTP finto di stripe-php: risponde con le risposte in coda e tiene
 * le richieste per i controlli. Nessuna chiamata esce dalla macchina.
 */
final class FakeStripeHttp implements \Stripe\HttpClient\ClientInterface
{
    /** @var list<array{0: int, 1: array}> */
    private array $responses = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, params: array}> */
    public array $requests = [];

    public static function install(): self
    {
        $fake = new self();
        \Stripe\ApiRequestor::setHttpClient($fake);

        return $fake;
    }

    public function queue(int $code, array $body): void
    {
        $this->responses[] = [$code, $body];
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $named = [];

        foreach ($headers as $line) {
            [$name, $value] = array_map('trim', explode(':', (string) $line, 2)) + [1 => ''];
            $named[strtolower($name)] = $value;
        }

        $this->requests[] = ['method' => strtoupper((string) $method), 'url' => (string) $absUrl, 'headers' => $named, 'params' => (array) $params];
        [$code, $body] = array_shift($this->responses) ?? [500, ['error' => ['message' => 'Nessuna risposta in coda']]];

        return [json_encode($body), $code, []];
    }

    public function header(int $i, string $name): ?string
    {
        return $this->requests[$i]['headers'][strtolower($name)] ?? null;
    }

    public function path(int $i): string
    {
        return (string) parse_url($this->requests[$i]['url'], PHP_URL_PATH);
    }
}
```

- [ ] **Passo 2: test che fallisce**

`tests/Plugin/Stripe/PaymentIntentTest.php`:

```php
<?php
/** php tests/Plugin/Stripe/PaymentIntentTest.php */
declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../../harness.php';
require __DIR__ . '/FakeStripeHttp.php';

use Wonder\Plugin\Stripe\PaymentIntent;

$http = FakeStripeHttp::install();
$intenti = new PaymentIntent('sk_test_prova', 'acct_prova');

check('create: sul conto collegato, con la chiave d\'idempotenza', function () use ($http, $intenti) {
    $http->requests = [];
    $http->queue(200, ['id' => 'pi_1', 'object' => 'payment_intent', 'client_secret' => 'pi_1_secret_x', 'status' => 'requires_payment_method']);
    $pi = $intenti->create(['amount' => 1999, 'currency' => 'eur', 'metadata' => ['order_id' => '7']], 'pay_abc');

    return $pi->id === 'pi_1'
        && $http->requests[0]['method'] === 'POST'
        && $http->path(0) === '/v1/payment_intents'
        && $http->header(0, 'Stripe-Account') === 'acct_prova'
        && $http->header(0, 'Idempotency-Key') === 'pay_abc'
        && $http->header(0, 'Authorization') === 'Bearer sk_test_prova'
        && (int) $http->requests[0]['params']['amount'] === 1999;
});

check('get, update e cancel passano dal conto collegato', function () use ($http, $intenti) {
    $http->requests = [];
    $http->queue(200, ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'requires_payment_method']);
    $http->queue(200, ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'requires_payment_method']);
    $http->queue(200, ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'canceled']);
    $intenti->get('pi_1');
    $intenti->update('pi_1', ['amount' => 2500]);
    $cancellato = $intenti->cancel('pi_1');

    return $cancellato->status === 'canceled'
        && [$http->requests[0]['method'], $http->path(0)] === ['GET', '/v1/payment_intents/pi_1']
        && [$http->requests[1]['method'], $http->path(1)] === ['POST', '/v1/payment_intents/pi_1']
        && [$http->requests[2]['method'], $http->path(2)] === ['POST', '/v1/payment_intents/pi_1/cancel']
        && $http->header(0, 'Stripe-Account') === 'acct_prova'
        && $http->header(1, 'Stripe-Account') === 'acct_prova'
        && $http->header(2, 'Stripe-Account') === 'acct_prova';
});

check('createCustomer: sul conto collegato, con la chiave d\'idempotenza', function () use ($http, $intenti) {
    $http->requests = [];
    $http->queue(200, ['id' => 'cus_1', 'object' => 'customer']);
    $cliente = $intenti->createCustomer(['email' => 'cliente@example.com'], 'cus_42');

    return $cliente->id === 'cus_1'
        && $http->path(0) === '/v1/customers'
        && $http->header(0, 'Stripe-Account') === 'acct_prova'
        && $http->header(0, 'Idempotency-Key') === 'cus_42';
});

check('refundsOf: i rimborsi della carica, con importo e stato', function () use ($http, $intenti) {
    $http->requests = [];
    $http->queue(200, ['object' => 'list', 'url' => '/v1/refunds', 'has_more' => false, 'data' => [
        ['id' => 're_1', 'object' => 'refund', 'amount' => 500, 'status' => 'succeeded'],
        ['id' => 're_2', 'object' => 'refund', 'amount' => 300, 'status' => 'failed'],
    ]]);
    $rimborsi = $intenti->refundsOf('ch_1');

    return $rimborsi === [
            ['id' => 're_1', 'amount' => 500, 'status' => 'succeeded'],
            ['id' => 're_2', 'amount' => 300, 'status' => 'failed'],
        ]
        && $http->requests[0]['params']['charge'] === 'ch_1'
        && $http->header(0, 'Stripe-Account') === 'acct_prova';
});

summary();
```

- [ ] **Passo 3: lo vedi fallire**

Esegui: `php tests/Plugin/Stripe/PaymentIntentTest.php`
Atteso: FAIL, `Class "Wonder\Plugin\Stripe\PaymentIntent" not found`.

- [ ] **Passo 4: il codice**

`class/Plugin/Stripe/PaymentIntent.php`:

```php
<?php

namespace Wonder\Plugin\Stripe;

use Stripe\Customer;
use Stripe\StripeClient;

/**
 * Intenti di pagamento, Customer e rimborsi sul conto collegato, con le
 * chiavi che passa chi chiama.
 *
 * Non estende `Stripe`: quella prende sempre le chiavi dell'ambiente attivo,
 * mentre il webhook e il riallineamento lavorano sull'ambiente del pagamento.
 */
final class PaymentIntent
{
    private StripeClient $client;

    /** @var array{stripe_account: string} */
    private array $options;

    public function __construct(string $secretKey, string $accountId)
    {
        $this->client = new StripeClient($secretKey);
        $this->options = ['stripe_account' => $accountId];
    }

    public function create(array $params, string $idempotencyKey): \Stripe\PaymentIntent
    {
        return $this->client->paymentIntents->create($params, $this->options + ['idempotency_key' => $idempotencyKey]);
    }

    public function get(string $id): \Stripe\PaymentIntent
    {
        return $this->client->paymentIntents->retrieve($id, [], $this->options);
    }

    public function update(string $id, array $params): \Stripe\PaymentIntent
    {
        return $this->client->paymentIntents->update($id, $params, $this->options);
    }

    public function cancel(string $id): \Stripe\PaymentIntent
    {
        return $this->client->paymentIntents->cancel($id, [], $this->options);
    }

    public function createCustomer(array $params, string $idempotencyKey): Customer
    {
        return $this->client->customers->create($params, $this->options + ['idempotency_key' => $idempotencyKey]);
    }

    /** @return list<array{id: string, amount: int, status: string}> */
    public function refundsOf(string $chargeId): array
    {
        $refunds = [];

        foreach ($this->client->refunds->all(['charge' => $chargeId, 'limit' => 100], $this->options)->autoPagingIterator() as $refund) {
            $refunds[] = ['id' => (string) $refund->id, 'amount' => (int) $refund->amount, 'status' => (string) $refund->status];
        }

        return $refunds;
    }
}
```

- [ ] **Passo 5: lo vedi passare**

Esegui: `php tests/Plugin/Stripe/PaymentIntentTest.php`
Atteso: PASS, 4 controlli verdi. Controlla anche che `php tests/run.php` raccolga i test in `tests/Plugin/Stripe/` (se il runner salta le sottocartelle, ledger: i due test si eseguono a mano nei compiti 2 e 3).

- [ ] **Passo 6: commit**

```bash
git add class/Plugin/Stripe/PaymentIntent.php tests/Plugin/Stripe/FakeStripeHttp.php tests/Plugin/Stripe/PaymentIntentTest.php
git commit -m "Stripe: PaymentIntent sul conto collegato con le chiavi di chi chiama

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Compito 3: `Connect` e il bottone «Collega webhook»

**File:**
- Crea: `class/Plugin/Stripe/Connect.php`
- Crea: `app/http/api/service/stripe/connect.php`
- Modifica: `app/config/routes/route.api.php` (gruppo `stripe.`, dopo la rotta `onboarding.check`)
- Modifica: `class/App/Resources/Config/SecurityResource.php` (card «Stripe», accanto ai badge «Collega»)
- Crea: `tests/Plugin/Stripe/ConnectTest.php`

**Interfacce:**
- Consuma: `FakeStripeHttp` (Compito 2); `Credentials::apiDefaults()` e le chiavi del Compito 1.
- Produce:
  - `final class Wonder\Plugin\Stripe\Connect`, `__construct(string $secretKey, string $accountId)`.
  - `public const EVENTS = ['payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.canceled', 'charge.refunded']`.
  - `webhook(string $url): string` (il segreto `whsec_…`).
  - `domain(string $domain): void`.
  - `static environment(string $env, string $webhookUrl, string $domain, ?object $api = null): string`, con `$env` `'test'` o `'live'`.
  - `static secretColumn(string $env): string`.
  - Rotta `api.service.stripe.connect` (GET `…/service/stripe/connect/?account=test|production`).
  - Il gestionale (Compito 8) dà la rotta con nome `api.gestionale.stripe.webhook`: il bottone la legge con `Route::url`.

- [ ] **Passo 1: test che fallisce**

`tests/Plugin/Stripe/ConnectTest.php`:

```php
<?php
/** php tests/Plugin/Stripe/ConnectTest.php */
declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../../harness.php';
require __DIR__ . '/FakeStripeHttp.php';

use Wonder\App\Credentials;
use Wonder\Plugin\Stripe\Connect;

const URL = 'https://negozio.example/api/gestionale/stripe/webhook/';

$http = FakeStripeHttp::install();

function api(bool $test): object
{
    $api = Credentials::apiDefaults();
    $api->stripe_test = $test;
    $api->stripe_private_key = 'sk_live_prova';
    $api->stripe_account_id = 'acct_live';
    $api->stripe_test_key = 'sk_test_prova';
    $api->stripe_test_account_id = 'acct_test';

    return $api;
}

check('webhook: cancella l\'endpoint con lo stesso url, ne crea uno e dà il segreto', function () use ($http) {
    $http->requests = [];
    $http->queue(200, ['object' => 'list', 'url' => '/v1/webhook_endpoints', 'has_more' => false, 'data' => [
        ['id' => 'we_vecchio', 'object' => 'webhook_endpoint', 'url' => URL],
        ['id' => 'we_altro', 'object' => 'webhook_endpoint', 'url' => 'https://altro.example/hook'],
    ]]);
    $http->queue(200, ['id' => 'we_vecchio', 'object' => 'webhook_endpoint', 'deleted' => true]);
    $http->queue(200, ['id' => 'we_nuovo', 'object' => 'webhook_endpoint', 'url' => URL, 'secret' => 'whsec_nuovo']);

    $segreto = (new Connect('sk_test_prova', 'acct_test'))->webhook(URL);
    $creato = $http->requests[2]['params'];

    return $segreto === 'whsec_nuovo'
        && count($http->requests) === 3
        && [$http->requests[1]['method'], $http->path(1)] === ['DELETE', '/v1/webhook_endpoints/we_vecchio']
        && [$http->requests[2]['method'], $http->path(2)] === ['POST', '/v1/webhook_endpoints']
        && $creato['url'] === URL
        && array_values($creato['enabled_events']) === Connect::EVENTS
        && $http->header(0, 'Stripe-Account') === 'acct_test'
        && $http->header(2, 'Stripe-Account') === 'acct_test';
});

check('domain: un dominio già registrato non è un errore', function () use ($http) {
    $http->requests = [];
    $http->queue(400, ['error' => ['type' => 'invalid_request_error', 'message' => 'This domain is already registered.']]);
    (new Connect('sk_test_prova', 'acct_test'))->domain('negozio.example');

    return $http->path(0) === '/v1/payment_method_domains'
        && $http->requests[0]['params']['domain_name'] === 'negozio.example';
});

check('domain: gli altri errori passano', function () use ($http) {
    $http->queue(400, ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid domain.']]);

    try {
        (new Connect('sk_test_prova', 'acct_test'))->domain('x');

        return false;
    } catch (\Stripe\Exception\InvalidRequestException) {
        return true;
    }
});

check('environment usa le chiavi dell\'ambiente scelto, non di quello attivo', function () use ($http) {
    $http->requests = [];
    $http->queue(200, ['object' => 'list', 'url' => '/v1/webhook_endpoints', 'has_more' => false, 'data' => []]);
    $http->queue(200, ['id' => 'we_1', 'object' => 'webhook_endpoint', 'url' => URL, 'secret' => 'whsec_live']);
    $http->queue(200, ['id' => 'pmd_1', 'object' => 'payment_method_domain']);

    // Il sito è in test, ma si collega la produzione.
    $segreto = Connect::environment('live', URL, 'negozio.example', api(true));

    return $segreto === 'whsec_live'
        && $http->header(0, 'Authorization') === 'Bearer sk_live_prova'
        && $http->header(0, 'Stripe-Account') === 'acct_live'
        && $http->header(2, 'Stripe-Account') === 'acct_live';
});

check('environment senza conto collegato si ferma prima di chiamare Stripe', function () use ($http) {
    $http->requests = [];
    $api = api(false);
    $api->stripe_test_account_id = '';

    try {
        Connect::environment('test', URL, 'negozio.example', $api);

        return false;
    } catch (RuntimeException) {
        return $http->requests === [];
    }
});

check('secretColumn per ambiente', fn () => Connect::secretColumn('test') === 'stripe_test_webhook_secret'
    && Connect::secretColumn('live') === 'stripe_webhook_secret');

summary();
```

- [ ] **Passo 2: lo vedi fallire**

Esegui: `php tests/Plugin/Stripe/ConnectTest.php`
Atteso: FAIL, `Class "Wonder\Plugin\Stripe\Connect" not found`.

- [ ] **Passo 3: il codice**

`class/Plugin/Stripe/Connect.php`:

```php
<?php

namespace Wonder\Plugin\Stripe;

use RuntimeException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use Wonder\App\Credentials;

/**
 * Collega un ambiente del conto Stripe al sito: endpoint del webhook e
 * dominio per i wallet. Lavora con le chiavi dell'ambiente scelto, che può
 * non essere quello attivo.
 */
final class Connect
{
    public const EVENTS = [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
        'charge.refunded',
    ];

    private StripeClient $client;

    /** @var array{stripe_account: string} */
    private array $options;

    public function __construct(string $secretKey, string $accountId)
    {
        $this->client = new StripeClient($secretKey);
        $this->options = ['stripe_account' => $accountId];
    }

    /** Rifà l'endpoint per questo url: quello vecchio si cancella. Dà il segreto nuovo. */
    public function webhook(string $url): string
    {
        foreach ($this->client->webhookEndpoints->all(['limit' => 100], $this->options)->data as $endpoint) {
            if ((string) $endpoint->url === $url) {
                $this->client->webhookEndpoints->delete((string) $endpoint->id, [], $this->options);
            }
        }

        $endpoint = $this->client->webhookEndpoints->create(['url' => $url, 'enabled_events' => self::EVENTS], $this->options);

        return (string) $endpoint->secret;
    }

    /** Registra il dominio per Apple Pay e gli altri wallet. Già registrato va bene. */
    public function domain(string $domain): void
    {
        try {
            $this->client->paymentMethodDomains->create(['domain_name' => $domain], $this->options);
        } catch (InvalidRequestException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'already')) {
                throw $e;
            }
        }
    }

    /** Collega l'ambiente `test` o `live` e dà il segreto del webhook da salvare. */
    public static function environment(string $env, string $webhookUrl, string $domain, ?object $api = null): string
    {
        $api ??= Credentials::api();
        [$key, $account] = $env === 'test'
            ? [(string) $api->stripe_test_key, (string) $api->stripe_test_account_id]
            : [(string) $api->stripe_private_key, (string) $api->stripe_account_id];

        if (trim($key) === '' || trim($account) === '') {
            throw new RuntimeException('manca la chiave o il conto collegato di '.($env === 'test' ? 'test' : 'produzione').'.');
        }

        $connect = new self($key, $account);
        $secret = $connect->webhook($webhookUrl);

        if ($domain !== '') {
            $connect->domain($domain);
        }

        return $secret;
    }

    public static function secretColumn(string $env): string
    {
        return $env === 'test' ? 'stripe_test_webhook_secret' : 'stripe_webhook_secret';
    }
}
```

- [ ] **Passo 4: lo vedi passare**

Esegui: `php tests/Plugin/Stripe/ConnectTest.php`
Atteso: PASS, 6 controlli verdi.

- [ ] **Passo 5: azione, rotta e bottoni**

`app/http/api/service/stripe/connect.php` (stessa forma di `onboarding.php`):

```php
<?php

use Stripe\Exception\ApiErrorException;
use Wonder\App\Support\ApiRequest;
use Wonder\Http\Route;
use Wonder\Plugin\Stripe\Connect;

$env = ApiRequest::string('account') === 'test' ? 'test' : 'live';
$url = Route::url('api.gestionale.stripe.webhook');

if ($url === '') {
    echo 'Errore Stripe: il webhook del gestionale non è installato.';
    exit;
}

try {
    $secret = Connect::environment($env, $url, (string) parse_url($url, PHP_URL_HOST));
} catch (ApiErrorException|RuntimeException $e) {
    echo 'Errore Stripe: '.$e->getMessage();
    exit;
}

sqlModify('security', [Connect::secretColumn($env) => $secret], 'id', 1);

header('Location: '.rtrim((string) $PATH->backend, '/').'/app/config/credentials/');
exit;
```

In `route.api.php`, nel gruppo `stripe.`, dopo la rotta `onboarding.check`:

```php
                        Route::get('/connect/', $ROOT_APP.'/http/api/service/stripe/connect.php')
                            ->name('connect');
```

In `SecurityResource`, card «Stripe»: dopo ognuno dei due `Badge::to(... '/service/stripe/onboarding/?account=…', 'Collega')`, il badge del webhook dello stesso ambiente. Le colonne dei titoli passano da 6 a 4 e i due badge prendono 4 ciascuno:

```php
                    SectionTitle::make('Produzione')
                        ->columnSpan(4),
                    Badge::to((new Path)->appApi.'/service/stripe/onboarding/?account=production', 'Collega')
                        ->variant('dark')
                        ->addClass('float-end')
                        ->columnSpan(4),
                    Badge::to((new Path)->appApi.'/service/stripe/connect/?account=production', 'Collega webhook')
                        ->variant('dark')
                        ->addClass('float-end')
                        ->columnSpan(4),
```

e lo stesso per «Test» con `?account=test`.

- [ ] **Passo 6: suite**

Esegui: `php tests/run.php > /tmp/app-3.txt; tail -5 /tmp/app-3.txt` e `php -l app/http/api/service/stripe/connect.php`
Atteso: rosso solo `scheduler-integration.php`; nessun errore di sintassi.

- [ ] **Passo 7: commit**

```bash
git add class/Plugin/Stripe/Connect.php app/http/api/service/stripe/connect.php app/config/routes/route.api.php class/App/Resources/Config/SecurityResource.php tests/Plugin/Stripe/ConnectTest.php
git commit -m "Stripe: bottone «Collega webhook» per ambiente, con dominio dei wallet

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---


## Parte B — gestionale

Cartella di lavoro: `/Users/andreamarinoni/Developer/worktrees/pagamento-stripe/gestionale`. Suite: `php tests/run.php` (raccoglie `tests/*Test.php` e `tests/integrazione/*Test.php`). I test d'integrazione girano sul sito di prova, che dopo P3 punta ai worktree.

Forma dei test d'integrazione nuovi (la stessa di `tests/integrazione/ShippingSyncTest.php`): intestazione con `SITE`, `chdir`, `wonder-image.php`, `harness.php`, `supporto/compra.php`, la classe `Annulla` e la funzione `prova(callable)` che esegue il corpo in `Transaction::run` e poi lancia `Annulla`, così nel database non resta niente. Nel piano questa intestazione si chiama **«intestazione d'integrazione»** e si scrive per intero così:

```php
<?php
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';
require __DIR__ . '/supporto/compra.php';

use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
        // Voluto: i dati della prova non restano.
    }

    return $esito;
}
```

Le `use` delle classi del compito vanno subito dopo `use Wonder\Sql\Transaction;`.

### Compito 4: ambiente sul pagamento, `Ledger::attach` e `Ledger::byReference`

**File:**
- Modifica: `src/Models/Payments/Payment.php` (costante, colonna dopo `provider_reference`, campo accanto a `Field::key('provider')`)
- Modifica: `src/Support/Payments/Ledger.php` (due metodi pubblici dopo `fail()`)
- Crea: `tests/integrazione/LedgerAttachTest.php`

**Interfacce:**
- Consuma: `Ledger::open/register` e `ordineDiProva()` di oggi.
- Produce:
  - `Payment::ENVIRONMENTS = ['live', 'test']`; colonna `gst_payments.environment` (enum, predefinita `live`).
  - `Ledger::attach(int $paymentId, string $provider, string $reference, string $environment): void` — scrive fornitore, riferimento e ambiente sulla riga in attesa (o fallita: una carta rifiutata lascia l'intento riusabile). Lancia `RuntimeException` se la riga non c'è o è già `paid`/`cancelled`.
  - `Ledger::byReference(string $provider, string $reference, string $type = 'payment'): ?array` — la riga, senza blocco.

- [ ] **Passo 1: test che fallisce**

`tests/integrazione/LedgerAttachTest.php`: intestazione d'integrazione (commento in testa `/** php tests/integrazione/LedgerAttachTest.php */`), con queste `use`:

```php
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
```

poi:

```php
/** Ordine di prova con il suo pagamento stripe in attesa, come lo lascia `Checkout::place`. */
function aperto(float $totale = 50.0): array
{
    $ordine = ordineDiProva($totale);
    $pagamento = Ledger::open(['order_id' => $ordine, 'amount' => $totale, 'provider' => 'stripe'])['payment_id'];

    return [$ordine, $pagamento];
}

check('un pagamento nuovo nasce in produzione', fn () => prova(static function (): bool {
    [, $pagamento] = aperto();

    return (Payment::findById($pagamento)['environment'] ?? '') === 'live';
}));

check('attach scrive riferimento e ambiente sulla riga in attesa', fn () => prova(static function (): bool {
    [, $pagamento] = aperto();
    Ledger::attach($pagamento, 'stripe', 'pi_prova_1', 'test');
    $riga = Payment::findById($pagamento);

    return ($riga['provider_reference'] ?? '') === 'pi_prova_1'
        && ($riga['environment'] ?? '') === 'test'
        && ($riga['status'] ?? '') === 'pending';
}));

check('byReference trova la riga attaccata', fn () => prova(static function (): bool {
    [, $pagamento] = aperto();
    Ledger::attach($pagamento, 'stripe', 'pi_prova_2', 'test');

    return (int) (Ledger::byReference('stripe', 'pi_prova_2')['id'] ?? 0) === $pagamento
        && Ledger::byReference('stripe', 'pi_mai_visto') === null;
}));

check('la conferma con lo stesso pi_ chiude quella riga, senza aprirne una seconda', fn () => prova(static function (): bool {
    [$ordine, $pagamento] = aperto(50.0);
    Ledger::attach($pagamento, 'stripe', 'pi_prova_3', 'test');
    $esito = Ledger::register(['order_id' => $ordine, 'amount' => 50.0, 'provider' => 'stripe', 'provider_reference' => 'pi_prova_3', 'source' => 'webhook']);
    $righe = Payment::find(['order_id' => $ordine, 'deleted' => 'false']);

    return $esito['payment_id'] === $pagamento
        && $esito['created'] === false
        && isset($righe['id'])
        && ($righe['status'] ?? '') === 'paid'
        && ($righe['environment'] ?? '') === 'test';
}));

check('una riga fallita si può riattaccare: la carta rifiutata lascia l\'intento riusabile', fn () => prova(static function (): bool {
    [, $pagamento] = aperto();
    Ledger::attach($pagamento, 'stripe', 'pi_prova_4', 'test');
    Ledger::fail($pagamento, 'Carta rifiutata');
    Ledger::attach($pagamento, 'stripe', 'pi_prova_5', 'test');

    return (Payment::findById($pagamento)['provider_reference'] ?? '') === 'pi_prova_5';
}));

check('una riga pagata non si riattacca', fn () => prova(static function (): bool {
    [$ordine, $pagamento] = aperto(50.0);
    Ledger::register(['order_id' => $ordine, 'amount' => 50.0, 'provider' => 'stripe', 'provider_reference' => 'pi_prova_6']);

    try {
        Ledger::attach($pagamento, 'stripe', 'pi_altro', 'test');
    } catch (RuntimeException) {
        return (Payment::findById($pagamento)['provider_reference'] ?? '') === 'pi_prova_6';
    }

    return false;
}));

summary();
```

- [ ] **Passo 2: lo vedi fallire**

Esegui: `php tests/integrazione/LedgerAttachTest.php`
Atteso: FAIL, `Call to undefined method ...Ledger::attach()` (il primo controllo può fallire anche per la colonna che manca).

- [ ] **Passo 3: colonna e campo**

In `src/Models/Payments/Payment.php`, sotto `public const PROVIDERS`:

```php
    /** Test e produzione non si mescolano: un incasso di prova non è denaro. */
    public const ENVIRONMENTS = ['live', 'test'];
```

in `tableSchema()`, subito dopo `Column::key('provider_reference')->length(191),`:

```php
            Column::key('environment')->enum(static::ENVIRONMENTS)->default('live'),
```

e in `dataSchema()`, accanto a `Field::key('provider')->text()->sanitize(false)`, con la stessa forma:

```php
            Field::key('environment')->text()->sanitize(false),
```

Poi aggiorna lo schema del sito di prova (P5): `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update`.

- [ ] **Passo 4: i due metodi**

In `src/Support/Payments/Ledger.php`, subito dopo il metodo `fail()`:

```php
    /**
     * Lega la riga aperta al checkout all'intento del gateway.
     *
     * Da qui in poi la conferma, da qualunque parte arrivi, trova questa riga
     * con il riferimento del gateway e la chiude: non ne nasce una seconda.
     * Vale anche per una riga fallita, perché una carta rifiutata lascia
     * l'intento riusabile e il cliente riprova sullo stesso.
     */
    public static function attach(int $paymentId, string $provider, string $reference, string $environment): void
    {
        Transaction::run(static function () use ($paymentId, $provider, $reference, $environment): void {
            $row = Payment::findForUpdate(['id' => $paymentId], 1);

            if (!is_array($row) || $row === []) {
                throw new RuntimeException("Pagamento {$paymentId} non trovato.");
            }

            if (!in_array((string) $row['status'], ['pending', 'failed'], true)) {
                throw new RuntimeException("Pagamento {$paymentId} già chiuso.");
            }

            Payment::update([
                'provider' => $provider,
                'provider_reference' => trim($reference),
                'environment' => in_array($environment, Payment::ENVIRONMENTS, true) ? $environment : 'live',
            ], $paymentId);
        });
    }

    /**
     * La riga con quel riferimento del gateway, senza bloccarla: per chi
     * legge e poi passa da `register()`, `fail()` o `refund()`, che bloccano.
     *
     * @return array<string, mixed>|null
     */
    public static function byReference(string $provider, string $reference, string $type = 'payment'): ?array
    {
        $row = Payment::find([
            'provider' => $provider,
            'provider_reference' => trim($reference),
            'type' => $type,
            'deleted' => 'false',
        ], 1);

        return is_array($row) && $row !== [] ? $row : null;
    }
```

- [ ] **Passo 5: lo vedi passare**

Esegui: `php tests/integrazione/LedgerAttachTest.php`
Atteso: PASS, 6 controlli verdi.

- [ ] **Passo 6: suite e commit**

Esegui: `php tests/run.php > /tmp/g-4.txt; tail -3 /tmp/g-4.txt`
Atteso: «Tutti i test del gestionale passano.»

```bash
git add src/Models/Payments/Payment.php src/Support/Payments/Ledger.php tests/integrazione/LedgerAttachTest.php
git commit -m "Pagamenti: ambiente sulla riga, attach dell'intento e ricerca per riferimento

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito 5: eventi dei provider ripresi finché non sono elaborati

**File:**
- Modifica: `src/Support/Providers/ProviderEvents.php`
- Modifica: `tests/integrazione/ProviderEventsTest.php`

**Interfacce:**
- Consuma: niente di nuovo.
- Produce:
  - `ProviderEvents::MAX_ATTEMPTS = 4`.
  - `receive(...)`: `false` solo se l'evento esiste ed è `processed`; se esiste `received` o `failed` restituisce `true` senza una seconda riga.
  - `markFailed(string $provider, string $eventId, string $error, string $environment = 'live', bool $final = false): bool` — con `$final` porta `attempts` a `MAX_ATTEMPTS`: l'evento non si riprova più e resta in «Da controllare».

- [ ] **Passo 1: test che fallisce**

In `tests/integrazione/ProviderEventsTest.php` sostituisci il controllo `'lo stesso evento non si registra due volte'` con:

```php
        check('un evento ricevuto e non ancora elaborato si riprende, senza una seconda riga', function () use ($provider, $evento) {
            $prima = ProviderEvents::find($provider, $evento);

            return ProviderEvents::receive($provider, $evento, 'payment.succeeded', ['amount' => 1000]) === true
                && (int) (ProviderEvents::find($provider, $evento)['id'] ?? 0) === (int) ($prima['id'] ?? -1);
        });
```

e, subito prima di `throw new Annulla();`, aggiungi:

```php
        check('un evento elaborato non si riprende', fn () =>
            ProviderEvents::receive($provider, $evento, 'payment.succeeded', []) === false
        );

        check('un evento fallito si riprende', function () use ($provider) {
            $fallito = 'evt_fallito_'.bin2hex(random_bytes(3));
            ProviderEvents::receive($provider, $fallito, 'payment.succeeded', []);
            ProviderEvents::markFailed($provider, $fallito, 'rete giù');

            return ProviderEvents::receive($provider, $fallito, 'payment.succeeded', []) === true
                && (int) (ProviderEvents::find($provider, $fallito)['attempts'] ?? 0) === 1;
        });

        check('un fallimento definitivo porta i tentativi al massimo', function () use ($provider) {
            $definitivo = 'evt_definitivo_'.bin2hex(random_bytes(3));
            ProviderEvents::receive($provider, $definitivo, 'payment.succeeded', []);
            ProviderEvents::markFailed($provider, $definitivo, 'importo diverso', 'live', true);
            $riga = ProviderEvents::find($provider, $definitivo);

            return ($riga['status'] ?? '') === 'failed'
                && (int) ($riga['attempts'] ?? 0) === ProviderEvents::MAX_ATTEMPTS;
        });
```

- [ ] **Passo 2: lo vedi fallire**

Esegui: `php tests/integrazione/ProviderEventsTest.php`
Atteso: FAIL sul controllo «si riprende» (oggi `receive` restituisce `false`) e `Undefined constant ...MAX_ATTEMPTS`.

- [ ] **Passo 3: il codice**

In `src/Support/Providers/ProviderEvents.php`, sotto `final class ProviderEvents {`:

```php
    /** Dopo quattro tentativi andati male l'evento resta a una persona. */
    public const MAX_ATTEMPTS = 4;
```

aggiorna il commento della classe: «`receive()` dice se l'evento va elaborato: `false` solo quando l'avevamo già elaborato. Un evento ricevuto o fallito si riprende — il rinvio del provider è proprio il nuovo tentativo.»

In `receive()` sostituisci il blocco iniziale:

```php
        $existing = self::find($provider, $eventId, $environment);

        if ($existing !== null) {
            // Già elaborato: il rinvio non rifà il lavoro. Ricevuto o fallito:
            // il rinvio è il nuovo tentativo, sulla stessa riga.
            return (string) ($existing['status'] ?? '') !== 'processed';
        }
```

e `markFailed()` diventa:

```php
    /**
     * Tentativo andato male: si conta, così si vede chi insiste a fallire.
     *
     * `$final` è per gli errori che riprovare non aggiusta (importo diverso,
     * ordine sbagliato): l'evento arriva subito al massimo dei tentativi.
     */
    public static function markFailed(
        string $provider,
        string $eventId,
        string $error,
        string $environment = 'live',
        bool $final = false
    ): bool {
        $event = self::find($provider, $eventId, $environment);

        if ($event === null) {
            return false;
        }

        return !empty(sqlModify(ProviderEvent::$table, [
            'status' => 'failed',
            'attempts' => $final ? self::MAX_ATTEMPTS : (int) ($event['attempts'] ?? 0) + 1,
            'error' => $error,
        ], 'id', (int) $event['id'])->success);
    }
```

- [ ] **Passo 4: lo vedi passare**

Esegui: `php tests/integrazione/ProviderEventsTest.php`
Atteso: PASS.

- [ ] **Passo 5: suite e commit**

Esegui: `php tests/run.php > /tmp/g-5.txt; tail -3 /tmp/g-5.txt`
Atteso: «Tutti i test del gestionale passano.» Se un altro test contava sul vecchio `false` per un evento solo ricevuto, cambialo secondo la spec §6 e annota il ruling.

```bash
git add src/Support/Providers/ProviderEvents.php tests/integrazione/ProviderEventsTest.php
git commit -m "Eventi dei provider: si riprendono finché non sono elaborati, con un tetto ai tentativi

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito 6: contratto dei provider di pagamento e registro

**File:**
- Crea: `src/Providers/Payments/PaymentProvider.php`, `PaymentStart.php`, `PaymentState.php`, `PaymentEvent.php`
- Modifica: `src/Support/Payments/PaymentProviders.php` (da elenco fisso a registro)
- Crea: `tests/integrazione/supporto/FakePaymentProvider.php`
- Crea: `tests/PaymentProvidersTest.php`

**Interfacce:**
- Consuma: `PaymentMethod::ledgerProvider()` di oggi (usato da `Checkout.php` attraverso `PaymentProviders::connected`).
- Produce (namespace `Wonder\Plugin\Gestionale\Providers\Payments`):
  - `interface PaymentProvider`: `code(): string`, `environment(): string` (`'live'`/`'test'`), `connected(): bool`, `start(array $order, array $payment): PaymentStart`, `status(string $reference): PaymentState`, `event(string $raw, string $signature): ?PaymentEvent`, `replay(array $payload, string $environment): ?PaymentEvent` (l'evento rifatto dal payload salvato, senza firma: per il riallineamento), `cancel(string $reference): void`.
  - `final class PaymentStart(public readonly string $reference, public readonly string $clientSecret, public readonly string $environment)`.
  - `final class PaymentState(public readonly string $status, public readonly int $amount, public readonly string $currency, public readonly int $orderId)` con le costanti `SUCCEEDED = 'succeeded'`, `PROCESSING = 'processing'`, `REQUIRES_PAYMENT_METHOD = 'requires_payment_method'`, `CANCELED = 'canceled'`, `OTHER = 'other'`. `$amount` è in centesimi.
  - `final class PaymentEvent(id, type, environment, reference, amount, currency, orderId, refunds, chargeId, payload)`, tutti `public readonly`: `string $id`, `string $type`, `string $environment` (`''` = ambiente incerto, non si tocca nulla), `string $reference` (`pi_…`), `int $amount` (centesimi), `string $currency`, `int $orderId` (dai metadati, 0 se manca), `array $refunds` (`list<array{id: string, amount: int, status: string}>`), `string $chargeId`, `array $payload`.
  - `PaymentProviders::register(PaymentProvider $provider): void`, `get(string $code): ?PaymentProvider`, `reset(): void`, `connected(string $provider): bool` (manuali sempre; gli altri chiedono al provider registrato, e senza provider sono spenti).
  - `FakePaymentProvider` (solo test, nello spazio globale): codice `stripe`; `public string $environment = 'test'`, `public bool $connected = true`, `public array $states = []` (riferimento → `PaymentState`), `public ?PaymentEvent $nextEvent = null`, `public array $replays = []` (id dell'evento → `PaymentEvent`), `public array $cancelled = []`, `public array $started = []`. `start()` restituisce `pi_finto_1`, `pi_finto_2`, …; `event()` restituisce `$nextEvent` solo con la firma `'valida'`; `replay()` restituisce `$replays[$payload['id']]`.

- [ ] **Passo 1: test che fallisce**

`tests/PaymentProvidersTest.php`:

```php
<?php
/** php tests/PaymentProvidersTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';
require __DIR__ . '/integrazione/supporto/FakePaymentProvider.php';

use Wonder\Plugin\Gestionale\Providers\Payments\PaymentState;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;

PaymentProviders::reset();
$finto = new FakePaymentProvider();

check('i metodi manuali sono sempre collegati', fn () =>
    PaymentProviders::connected('manual')
    && PaymentProviders::connected('bank_transfer')
    && PaymentProviders::connected('')
);

check('un provider registrato si ritrova col suo codice', function () use ($finto) {
    PaymentProviders::register($finto);

    return PaymentProviders::get('stripe') === $finto && PaymentProviders::get('paypal') === null;
});

check('collegato è quello che dice il provider', function () use ($finto) {
    $finto->connected = false;
    $spento = PaymentProviders::connected('stripe');
    $finto->connected = true;

    return $spento === false && PaymentProviders::connected('stripe') === true;
});

check('un provider senza adapter non è collegato', fn () => PaymentProviders::connected('paypal') === false);

check('il provider finto numera gli intenti e tiene quelli cancellati', function () use ($finto) {
    $primo = $finto->start(['id' => 1], ['id' => 1]);
    $secondo = $finto->start(['id' => 1], ['id' => 1]);
    $finto->cancel($secondo->reference);

    return $primo->reference !== $secondo->reference
        && str_starts_with($primo->reference, 'pi_finto_')
        && $finto->cancelled === [$secondo->reference];
});

check('lo stato di un intento sconosciuto è «altro»', fn () =>
    $finto->status('pi_mai_visto')->status === PaymentState::OTHER
);

PaymentProviders::reset();

summary();
```

- [ ] **Passo 2: lo vedi fallire**

Esegui: `php tests/PaymentProvidersTest.php`
Atteso: FAIL, `Failed opening required '.../FakePaymentProvider.php'` o `Class ...PaymentState not found`.

- [ ] **Passo 3: i valori e il contratto**

`src/Providers/Payments/PaymentProvider.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Providers\Payments;

/**
 * Un gateway di pagamento online, visto dal gestionale.
 *
 * Il gestionale non conosce Stripe: chiede a questo contratto di aprire un
 * pagamento, di dirne lo stato, di leggere un evento firmato e di cancellare
 * un intento. Tutto quello che il gateway restituisce arriva già tradotto in
 * valori nostri, con gli importi in centesimi.
 */
interface PaymentProvider
{
    /** Il codice scritto su `gst_payments.provider` (es. `stripe`). */
    public function code(): string;

    /** L'ambiente attivo adesso: `live` o `test`. */
    public function environment(): string;

    /** Le credenziali dell'ambiente attivo ci sono tutte. */
    public function connected(): bool;

    /**
     * Apre (o riprende) l'intento per il pagamento in attesa dell'ordine.
     *
     * @param array<string, mixed> $order riga di `gst_orders`
     * @param array<string, mixed> $payment riga di `gst_payments` in attesa
     */
    public function start(array $order, array $payment): PaymentStart;

    /** Lo stato dell'intento, riletto dal gateway: mai dalla query del browser. */
    public function status(string $reference): PaymentState;

    /** L'evento, se la firma regge; `null` se non regge. */
    public function event(string $raw, string $signature): ?PaymentEvent;

    /**
     * L'evento rifatto dal payload salvato, che la firma l'ha già passata
     * all'arrivo: lo usa il riallineamento per riprovare. `null` se il
     * payload non è un evento.
     *
     * @param array<string, mixed> $payload
     */
    public function replay(array $payload, string $environment): ?PaymentEvent;

    /** Cancella l'intento, così un pagamento tardivo non può più arrivare. */
    public function cancel(string $reference): void;
}
```

`src/Providers/Payments/PaymentStart.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Providers\Payments;

/** L'intento aperto: il riferimento per noi, il segreto per il browser. */
final class PaymentStart
{
    public function __construct(
        public readonly string $reference,
        public readonly string $clientSecret,
        public readonly string $environment,
    ) {
    }
}
```

`src/Providers/Payments/PaymentState.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Providers\Payments;

/** Lo stato di un intento, come lo dice il gateway. Importo in centesimi. */
final class PaymentState
{
    public const SUCCEEDED = 'succeeded';
    public const PROCESSING = 'processing';
    public const REQUIRES_PAYMENT_METHOD = 'requires_payment_method';
    public const CANCELED = 'canceled';
    public const OTHER = 'other';

    public function __construct(
        public readonly string $status,
        public readonly int $amount = 0,
        public readonly string $currency = '',
        public readonly int $orderId = 0,
    ) {
    }
}
```

`src/Providers/Payments/PaymentEvent.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Providers\Payments;

/**
 * Un evento del gateway con la firma verificata.
 *
 * `environment` è quello del segreto che ha verificato la firma; vuoto quando
 * l'evento dice un ambiente diverso: in quel caso non si tocca niente.
 */
final class PaymentEvent
{
    /**
     * @param list<array{id: string, amount: int, status: string}> $refunds
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $environment,
        public readonly string $reference = '',
        public readonly int $amount = 0,
        public readonly string $currency = '',
        public readonly int $orderId = 0,
        public readonly array $refunds = [],
        public readonly string $chargeId = '',
        public readonly array $payload = [],
    ) {
    }
}
```

- [ ] **Passo 4: il registro**

`src/Support/Payments/PaymentProviders.php` diventa:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentProvider;

/**
 * I provider che il checkout online può usare davvero: i manuali sempre,
 * quelli online solo quando il loro adapter dice che il collegamento è fatto.
 *
 * I test registrano un provider finto; `reset()` torna ai predefiniti.
 */
final class PaymentProviders
{
    /** @var array<string, PaymentProvider> */
    private static array $providers = [];

    public static function register(PaymentProvider $provider): void
    {
        self::$providers[$provider->code()] = $provider;
    }

    public static function get(string $code): ?PaymentProvider
    {
        return self::$providers[$code] ?? null;
    }

    public static function reset(): void
    {
        self::$providers = [];
    }

    public static function connected(string $provider): bool
    {
        if (PaymentMethod::ledgerProvider($provider) === 'manual') {
            return true;
        }

        return self::get($provider)?->connected() ?? false;
    }
}
```

- [ ] **Passo 5: il provider finto**

`tests/integrazione/supporto/FakePaymentProvider.php`:

```php
<?php
declare(strict_types=1);

use Wonder\Plugin\Gestionale\Providers\Payments\PaymentEvent;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentProvider;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentStart;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentState;

/**
 * Stripe finto per i test del gestionale e dell'ecommerce: nessuna rete,
 * stati ed eventi decisi dal test.
 */
final class FakePaymentProvider implements PaymentProvider
{
    public string $environment = 'test';
    public bool $connected = true;

    /** @var array<string, PaymentState> */
    public array $states = [];

    public ?PaymentEvent $nextEvent = null;

    /** @var array<string, PaymentEvent> */
    public array $replays = [];

    /** @var list<string> */
    public array $cancelled = [];

    /** @var list<array{order: array, payment: array}> */
    public array $started = [];

    private int $counter = 0;

    public function code(): string
    {
        return 'stripe';
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function connected(): bool
    {
        return $this->connected;
    }

    public function start(array $order, array $payment): PaymentStart
    {
        $this->started[] = ['order' => $order, 'payment' => $payment];
        $reference = 'pi_finto_'.(++$this->counter);

        return new PaymentStart($reference, $reference.'_secret_prova', $this->environment);
    }

    public function status(string $reference): PaymentState
    {
        return $this->states[$reference] ?? new PaymentState(PaymentState::OTHER);
    }

    public function event(string $raw, string $signature): ?PaymentEvent
    {
        return $signature === 'valida' ? $this->nextEvent : null;
    }

    public function replay(array $payload, string $environment): ?PaymentEvent
    {
        return $this->replays[(string) ($payload['id'] ?? '')] ?? null;
    }

    public function cancel(string $reference): void
    {
        $this->cancelled[] = $reference;
    }
}
```

- [ ] **Passo 6: lo vedi passare**

Esegui: `php tests/PaymentProvidersTest.php`
Atteso: PASS, 6 controlli verdi.

- [ ] **Passo 7: suite e commit**

Esegui: `php tests/run.php > /tmp/g-6.txt; tail -3 /tmp/g-6.txt`
Atteso: «Tutti i test del gestionale passano.» (Il checkout di oggi chiede `connected('stripe')` e riceve ancora `false`: nessun adapter registrato fino al Compito 7.)

```bash
git add src/Providers/Payments/PaymentProvider.php src/Providers/Payments/PaymentStart.php src/Providers/Payments/PaymentState.php src/Providers/Payments/PaymentEvent.php src/Support/Payments/PaymentProviders.php tests/integrazione/supporto/FakePaymentProvider.php tests/PaymentProvidersTest.php
git commit -m "Pagamenti: contratto dei provider online e registro

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito 7: `StripeProvider`

**File:**
- Crea: `src/Providers/Payments/StripeProvider.php`
- Modifica: `src/Support/Payments/PaymentProviders.php` (`get()` crea da sé l'adapter `stripe`)
- Crea: `tests/integrazione/supporto/FakeStripeHttp.php` (copia identica di quello dell'app, Compito 2 passo 1)
- Crea: `tests/integrazione/supporto/firma.php`
- Crea: `tests/fixtures/stripe/payment_intent.succeeded.json`, `payment_intent.payment_failed.json`, `payment_intent.canceled.json`, `charge.refunded.json`
- Crea: `tests/integrazione/StripeProviderTest.php`

**Interfacce:**
- Consuma:
  - dall'app: `Wonder\Plugin\Stripe\PaymentIntent` (Compito 2: `create`, `get`, `cancel`, `createCustomer`, `refundsOf`), `Credentials::api()` (Compito 1);
  - `PaymentProvider`, `PaymentStart`, `PaymentState`, `PaymentEvent`, `PaymentProviders` (Compito 6); `ExternalReferences::find/save`; `Contacts::displayName`, `Contact::findById`; `PaymentMethod::findById`.
- Produce:
  - `final class StripeProvider implements PaymentProvider`, `__construct(?object $api = null)` (`null` = `Credentials::api()`; i test passano un oggetto con i campi).
  - `public static function activeEnvironment(?object $api = null): string` — l'unica funzione che calcola l'ambiente.
  - `public static function methodTypes(string $column): list<string>` — `'card, klarna ,'` → `['card', 'klarna']`.
  - `public function replay(array $payload, string $environment): ?PaymentEvent` — rifà l'evento dal corpo salvato, senza firma (per `ProviderEvents` in errore e il riallineamento, Compiti 8 e 12).
  - Il Customer si salva in `external_references` con `('contact', $customerId, 'stripe', 'customer', $ambiente)`.
  - I metadati dell'intento: `order_id`, `order_code`, `payment_code`, `attempt` (1, 2, …). La chiave d'idempotenza è `pay_…` al primo tentativo e `pay_…-N` dal secondo.
  - `PaymentProviders::get('stripe')` restituisce uno `StripeProvider` se nessuno ne ha registrato un altro.
  - Nei test: `eventoStripe(string $nome, array $oggetto = [], array $evento = []): string` e `firmaStripe(string $corpo, string $segreto, ?int $tempo = null): string`.

- [ ] **Passo 1: supporti e fixture**

Copia il client finto dall'app, così com'è:

```bash
cp /Users/andreamarinoni/Developer/worktrees/pagamento-stripe/app/tests/Plugin/Stripe/FakeStripeHttp.php tests/integrazione/supporto/FakeStripeHttp.php
```

`tests/integrazione/supporto/firma.php`:

```php
<?php
declare(strict_types=1);

/**
 * Eventi Stripe di prova: la fixture con i campi cambiati dal test, e la
 * firma come la manda Stripe (`t=…,v1=…`).
 */
function eventoStripe(string $nome, array $oggetto = [], array $evento = []): string
{
    $dati = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/stripe/'.$nome.'.json'), true);
    $dati = array_replace($dati, $evento);
    $dati['data']['object'] = array_replace($dati['data']['object'], $oggetto);

    return (string) json_encode($dati);
}

function firmaStripe(string $corpo, string $segreto, ?int $tempo = null): string
{
    $tempo ??= time();

    return 't='.$tempo.',v1='.hash_hmac('sha256', $tempo.'.'.$corpo, $segreto);
}
```

`tests/fixtures/stripe/payment_intent.succeeded.json`:

```json
{
  "id": "evt_prova_succeeded",
  "object": "event",
  "type": "payment_intent.succeeded",
  "livemode": false,
  "data": {
    "object": {
      "id": "pi_prova",
      "object": "payment_intent",
      "amount": 5000,
      "amount_received": 5000,
      "currency": "eur",
      "status": "succeeded",
      "latest_charge": "ch_prova",
      "metadata": {"order_id": "0", "order_code": "", "payment_code": "", "attempt": "1"}
    }
  }
}
```

`tests/fixtures/stripe/payment_intent.payment_failed.json`: uguale, con `"id": "evt_prova_failed"`, `"type": "payment_intent.payment_failed"`, `"amount_received": 0`, `"status": "requires_payment_method"`, `"latest_charge": "ch_prova"` e in più `"last_payment_error": {"message": "La carta è stata rifiutata."}` dentro `object`.

`tests/fixtures/stripe/payment_intent.canceled.json`: uguale, con `"id": "evt_prova_canceled"`, `"type": "payment_intent.canceled"`, `"amount_received": 0`, `"status": "canceled"`, `"latest_charge": null`.

`tests/fixtures/stripe/charge.refunded.json`:

```json
{
  "id": "evt_prova_refunded",
  "object": "event",
  "type": "charge.refunded",
  "livemode": false,
  "data": {
    "object": {
      "id": "ch_prova",
      "object": "charge",
      "amount": 5000,
      "amount_refunded": 2000,
      "currency": "eur",
      "payment_intent": "pi_prova",
      "metadata": {"order_id": "0"},
      "refunds": {
        "object": "list",
        "data": [{"id": "re_prova_1", "object": "refund", "amount": 2000, "status": "succeeded"}]
      }
    }
  }
}
```

- [ ] **Passo 2: test che fallisce**

`tests/integrazione/StripeProviderTest.php`: intestazione d'integrazione (commento in testa `/** php tests/integrazione/StripeProviderTest.php */`), poi:

```php
require __DIR__ . '/supporto/FakeStripeHttp.php';
require __DIR__ . '/supporto/firma.php';
```

con queste `use`:

```php
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentState;
use Wonder\Plugin\Gestionale\Providers\Payments\StripeProvider;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
use Wonder\Plugin\Gestionale\Support\Providers\ExternalReferences;
```

poi:

```php
/** Credenziali di prova: test acceso, chiavi finte per i due ambienti. */
function chiavi(array $cambi = []): object
{
    return (object) array_replace([
        'stripe_test' => true,
        'stripe_test_key' => 'sk_test_prova',
        'stripe_test_account_id' => 'acct_prova_test',
        'stripe_test_public_key' => 'pk_test_prova',
        'stripe_test_webhook_secret' => 'whsec_prova_test',
        'stripe_private_key' => 'sk_live_prova',
        'stripe_account_id' => 'acct_prova_live',
        'stripe_public_key' => 'pk_live_prova',
        'stripe_webhook_secret' => 'whsec_prova_live',
    ], $cambi);
}

function intento(array $campi = []): array
{
    return array_replace([
        'id' => 'pi_nuovo',
        'object' => 'payment_intent',
        'client_secret' => 'pi_nuovo_secret_prova',
        'status' => 'requires_payment_method',
        'amount' => 1234,
        'currency' => 'eur',
        'metadata' => ['order_id' => '7', 'attempt' => '1'],
    ], $campi);
}

$ordine = ['id' => 7, 'code' => 'ord_prova', 'order_number' => 'W-7', 'customer_id' => 987654321, 'email' => 'cliente@example.com', 'currency' => 'EUR', 'payment_method_id' => 0];
$pagamento = ['id' => 3, 'code' => 'pay_prova', 'amount' => '12.34', 'provider_reference' => 'pay_prova'];

check('l\'ambiente attivo segue stripe_test', fn () =>
    StripeProvider::activeEnvironment(chiavi()) === 'test'
    && StripeProvider::activeEnvironment(chiavi(['stripe_test' => false])) === 'live'
);

check('collegato solo con le quattro credenziali dell\'ambiente attivo', fn () =>
    (new StripeProvider(chiavi()))->connected()
    && !(new StripeProvider(chiavi(['stripe_test_webhook_secret' => ''])))->connected()
    && !(new StripeProvider(chiavi(['stripe_test_public_key' => ''])))->connected()
    && (new StripeProvider(chiavi(['stripe_test' => false, 'stripe_test_key' => ''])))->connected()
);

check('i tipi di pagamento del metodo si leggono dalla colonna', fn () =>
    StripeProvider::methodTypes('card, klarna ,') === ['card', 'klarna']
    && StripeProvider::methodTypes('') === []
);

check('start crea il Customer, poi l\'intento sul conto collegato, in centesimi e con la chiave pay_', fn () => prova(static function () use ($ordine, $pagamento): bool {
    $http = FakeStripeHttp::install();
    $http->queue(200, ['id' => 'cus_prova', 'object' => 'customer']);
    $http->queue(200, intento());
    $avvio = (new StripeProvider(chiavi()))->start($ordine, $pagamento);
    $parametri = $http->requests[1]['params'];

    return $http->path(0) === '/v1/customers'
        && $http->path(1) === '/v1/payment_intents'
        && $http->header(1, 'Stripe-Account') === 'acct_prova_test'
        && $http->header(1, 'Idempotency-Key') === 'pay_prova'
        && (int) $parametri['amount'] === 1234
        && $parametri['currency'] === 'eur'
        && $parametri['customer'] === 'cus_prova'
        && $parametri['description'] === 'W-7'
        && $parametri['metadata']['order_id'] === '7'
        && $parametri['metadata']['payment_code'] === 'pay_prova'
        && ($parametri['automatic_payment_methods']['enabled'] ?? '') === 'true'
        && $avvio->reference === 'pi_nuovo'
        && $avvio->clientSecret === 'pi_nuovo_secret_prova'
        && $avvio->environment === 'test'
        && (ExternalReferences::find('contact', 987654321, 'stripe', 'customer', 'test')['external_id'] ?? '') === 'cus_prova';
}));

check('il Customer già salvato si riusa, senza crearne un altro', fn () => prova(static function () use ($ordine, $pagamento): bool {
    ExternalReferences::save('contact', 987654321, 'stripe', 'customer', 'cus_salvato', 'test');
    $http = FakeStripeHttp::install();
    $http->queue(200, intento());
    (new StripeProvider(chiavi()))->start($ordine, $pagamento);

    return count($http->requests) === 1
        && $http->requests[0]['params']['customer'] === 'cus_salvato';
}));

check('un intento ancora da pagare si riusa', fn () => prova(static function () use ($ordine, $pagamento): bool {
    ExternalReferences::save('contact', 987654321, 'stripe', 'customer', 'cus_salvato', 'test');
    $http = FakeStripeHttp::install();
    $http->queue(200, intento(['id' => 'pi_vecchio', 'client_secret' => 'pi_vecchio_secret']));
    $avvio = (new StripeProvider(chiavi()))->start($ordine, ['provider_reference' => 'pi_vecchio'] + $pagamento);

    return count($http->requests) === 1
        && $http->requests[0]['method'] === 'GET'
        && $avvio->reference === 'pi_vecchio'
        && $avvio->clientSecret === 'pi_vecchio_secret';
}));

check('un intento annullato lascia il posto a uno nuovo, con la chiave -2', fn () => prova(static function () use ($ordine, $pagamento): bool {
    ExternalReferences::save('contact', 987654321, 'stripe', 'customer', 'cus_salvato', 'test');
    $http = FakeStripeHttp::install();
    $http->queue(200, intento(['id' => 'pi_vecchio', 'status' => 'canceled']));
    $http->queue(200, intento(['id' => 'pi_secondo', 'metadata' => ['order_id' => '7', 'attempt' => '2']]));
    $avvio = (new StripeProvider(chiavi()))->start($ordine, ['provider_reference' => 'pi_vecchio'] + $pagamento);

    return $avvio->reference === 'pi_secondo'
        && $http->header(1, 'Idempotency-Key') === 'pay_prova-2'
        && $http->requests[1]['params']['metadata']['attempt'] === '2';
}));

check('status traduce lo stato e porta importo, valuta e ordine', function () {
    $http = FakeStripeHttp::install();
    $http->queue(200, intento(['status' => 'succeeded', 'amount_received' => 1234]));
    $http->queue(200, intento(['status' => 'requires_action']));
    $provider = new StripeProvider(chiavi());
    $riuscito = $provider->status('pi_nuovo');
    $altro = $provider->status('pi_nuovo');

    return $riuscito->status === PaymentState::SUCCEEDED
        && $riuscito->amount === 1234
        && $riuscito->currency === 'eur'
        && $riuscito->orderId === 7
        && $altro->status === PaymentState::OTHER;
});

check('evento con la firma dell\'ambiente attivo: valori tradotti', function () {
    $corpo = eventoStripe('payment_intent.succeeded', ['metadata' => ['order_id' => '7']]);
    $evento = (new StripeProvider(chiavi()))->event($corpo, firmaStripe($corpo, 'whsec_prova_test'));

    return $evento !== null
        && $evento->id === 'evt_prova_succeeded'
        && $evento->type === 'payment_intent.succeeded'
        && $evento->environment === 'test'
        && $evento->reference === 'pi_prova'
        && $evento->amount === 5000
        && $evento->orderId === 7
        && $evento->chargeId === 'ch_prova';
});

check('firma sbagliata o scaduta: nessun evento', function () {
    $corpo = eventoStripe('payment_intent.succeeded');
    $provider = new StripeProvider(chiavi());

    return $provider->event($corpo, firmaStripe($corpo, 'whsec_altro')) === null
        && $provider->event($corpo, firmaStripe($corpo, 'whsec_prova_test', time() - 3600)) === null
        && $provider->event($corpo, '') === null;
});

check('firmato col segreto dell\'altro ambiente: l\'evento dice quell\'ambiente', function () {
    $corpo = eventoStripe('payment_intent.succeeded', [], ['livemode' => true]);
    $evento = (new StripeProvider(chiavi()))->event($corpo, firmaStripe($corpo, 'whsec_prova_live'));

    return $evento !== null && $evento->environment === 'live';
});

check('livemode in disaccordo con la firma: ambiente vuoto', function () {
    $corpo = eventoStripe('payment_intent.succeeded', [], ['livemode' => true]);
    $evento = (new StripeProvider(chiavi()))->event($corpo, firmaStripe($corpo, 'whsec_prova_test'));

    return $evento !== null && $evento->environment === '';
});

check('rimborso con l\'elenco nella carica', function () {
    $corpo = eventoStripe('charge.refunded');
    $evento = (new StripeProvider(chiavi()))->event($corpo, firmaStripe($corpo, 'whsec_prova_test'));

    return $evento !== null
        && $evento->reference === 'pi_prova'
        && $evento->chargeId === 'ch_prova'
        && $evento->refunds === [['id' => 're_prova_1', 'amount' => 2000, 'status' => 'succeeded']];
});

check('rimborso senza elenco nella carica: lo chiede a Stripe', function () {
    $corpo = eventoStripe('charge.refunded', ['refunds' => null]);
    $http = FakeStripeHttp::install();
    $http->queue(200, ['object' => 'list', 'url' => '/v1/refunds', 'has_more' => false, 'data' => [
        ['id' => 're_prova_2', 'object' => 'refund', 'amount' => 700, 'status' => 'succeeded'],
    ]]);
    $evento = (new StripeProvider(chiavi()))->event($corpo, firmaStripe($corpo, 'whsec_prova_test'));

    return $http->path(0) === '/v1/refunds'
        && $evento !== null
        && $evento->refunds === [['id' => 're_prova_2', 'amount' => 700, 'status' => 'succeeded']];
});

check('replay rifà l\'evento dal payload salvato, senza firma', function () {
    $dati = json_decode(eventoStripe('payment_intent.succeeded', ['metadata' => ['order_id' => '7']]), true);
    $evento = (new StripeProvider(chiavi()))->replay($dati, 'test');

    return $evento !== null
        && $evento->id === 'evt_prova_succeeded'
        && $evento->environment === 'test'
        && $evento->orderId === 7
        && (new StripeProvider(chiavi()))->replay(['id' => 'evt_rotto'], 'test') === null;
});

check('cancel annulla l\'intento sul conto collegato', function () {
    $http = FakeStripeHttp::install();
    $http->queue(200, intento(['status' => 'canceled']));
    (new StripeProvider(chiavi()))->cancel('pi_nuovo');

    return $http->path(0) === '/v1/payment_intents/pi_nuovo/cancel'
        && $http->header(0, 'Stripe-Account') === 'acct_prova_test';
});

check('senza registrazioni, il registro dà lo StripeProvider', function () {
    PaymentProviders::reset();

    return PaymentProviders::get('stripe') instanceof StripeProvider;
});

summary();
```

- [ ] **Passo 3: lo vedi fallire**

Esegui: `php tests/integrazione/StripeProviderTest.php`
Atteso: FAIL, `Class "Wonder\Plugin\Gestionale\Providers\Payments\StripeProvider" not found`.

- [ ] **Passo 4: l'adapter**

`src/Providers/Payments/StripeProvider.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Providers\Payments;

use Throwable;
use Wonder\App\Credentials;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Providers\ExternalReferences;
use Wonder\Plugin\Stripe\PaymentIntent;

/**
 * Stripe visto dal gestionale: addebiti diretti sul conto collegato del sito,
 * con le chiavi della piattaforma (Connect).
 *
 * L'ambiente lo decide `stripe_test` delle credenziali. Un evento si verifica
 * prima col segreto dell'ambiente attivo e poi con quello dell'altro: così un
 * evento di prova che arriva in produzione si riconosce e si lascia stare,
 * invece di finire tra le firme sbagliate.
 */
final class StripeProvider implements PaymentProvider
{
    /** Gli stati che Stripe lascia pagare di nuovo sullo stesso intento. */
    private const REUSABLE = ['requires_payment_method', 'requires_confirmation', 'requires_action'];

    private const STATES = [
        'succeeded' => PaymentState::SUCCEEDED,
        'processing' => PaymentState::PROCESSING,
        'requires_payment_method' => PaymentState::REQUIRES_PAYMENT_METHOD,
        'canceled' => PaymentState::CANCELED,
    ];

    public function __construct(private readonly ?object $api = null)
    {
    }

    public static function activeEnvironment(?object $api = null): string
    {
        return ($api ?? Credentials::api())->stripe_test ? 'test' : 'live';
    }

    /** @return list<string> */
    public static function methodTypes(string $column): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $column)), 'strlen'));
    }

    public function code(): string
    {
        return 'stripe';
    }

    public function environment(): string
    {
        return self::activeEnvironment($this->api());
    }

    public function connected(): bool
    {
        return !in_array('', $this->keys($this->environment()), true);
    }

    public function start(array $order, array $payment): PaymentStart
    {
        $environment = $this->environment();
        $intents = $this->intents($environment);
        $code = (string) $payment['code'];
        $previous = trim((string) ($payment['provider_reference'] ?? ''));
        $attempt = 1;

        if (str_starts_with($previous, 'pi_')) {
            $old = $intents->get($previous);

            if (in_array((string) $old->status, self::REUSABLE, true)) {
                return new PaymentStart((string) $old->id, (string) $old->client_secret, $environment);
            }

            $attempt = (int) ($old->metadata['attempt'] ?? 1) + 1;
        }

        $params = [
            'amount' => (int) round((float) $payment['amount'] * 100),
            'currency' => strtolower((string) ($order['currency'] ?? 'EUR')),
            'description' => (string) ($order['order_number'] ?? $order['code'] ?? ''),
            'metadata' => [
                'order_id' => (string) (int) $order['id'],
                'order_code' => (string) ($order['code'] ?? ''),
                'payment_code' => $code,
                'attempt' => (string) $attempt,
            ],
        ];

        $customer = $this->customer($order, $environment, $intents);
        if ($customer !== '') {
            $params['customer'] = $customer;
        }

        $types = self::methodTypes((string) ($this->method($order)['stripe_payment_method_types'] ?? ''));
        if ($types !== []) {
            $params['payment_method_types'] = $types;
        } else {
            $params['automatic_payment_methods'] = ['enabled' => true];
        }

        $intent = $intents->create($params, $attempt === 1 ? $code : $code.'-'.$attempt);

        return new PaymentStart((string) $intent->id, (string) $intent->client_secret, $environment);
    }

    public function status(string $reference): PaymentState
    {
        $intent = $this->intents($this->environment())->get($reference);

        return new PaymentState(
            self::STATES[(string) $intent->status] ?? PaymentState::OTHER,
            (int) ($intent->amount_received ?: $intent->amount),
            strtolower((string) $intent->currency),
            (int) ($intent->metadata['order_id'] ?? 0)
        );
    }

    public function event(string $raw, string $signature): ?PaymentEvent
    {
        $active = $this->environment();
        $verifiedBy = null;

        foreach ([$active, $active === 'test' ? 'live' : 'test'] as $environment) {
            $secret = $this->keys($environment)['webhook'];

            if ($secret === '' || $signature === '') {
                continue;
            }

            try {
                \Stripe\WebhookSignature::verifyHeader($raw, $signature, $secret, 300);
                $verifiedBy = $environment;
                break;
            } catch (Throwable) {
                // Firma non di questo ambiente: si prova l'altro.
            }
        }

        $data = json_decode($raw, true);

        if ($verifiedBy === null || !is_array($data)) {
            return null;
        }

        // Il segreto dice l'ambiente; se l'evento ne dice un altro, non si tocca nulla.
        return $this->replay($data, (bool) ($data['livemode'] ?? false) === ($verifiedBy === 'live') ? $verifiedBy : '');
    }

    public function replay(array $payload, string $environment): ?PaymentEvent
    {
        $object = $payload['data']['object'] ?? null;

        if (!is_array($object) || (string) ($payload['id'] ?? '') === '') {
            return null;
        }

        $isCharge = ($object['object'] ?? '') === 'charge';

        return new PaymentEvent(
            (string) $payload['id'],
            (string) ($payload['type'] ?? ''),
            $environment,
            (string) ($isCharge ? ($object['payment_intent'] ?? '') : ($object['id'] ?? '')),
            (int) ($isCharge ? ($object['amount'] ?? 0) : (($object['amount_received'] ?? 0) ?: ($object['amount'] ?? 0))),
            strtolower((string) ($object['currency'] ?? '')),
            (int) ($object['metadata']['order_id'] ?? 0),
            $isCharge && $environment !== '' ? $this->refunds($object, $environment) : [],
            (string) ($isCharge ? ($object['id'] ?? '') : ($object['latest_charge'] ?? '')),
            $payload
        );
    }

    public function cancel(string $reference): void
    {
        $this->intents($this->environment())->cancel($reference);
    }

    private function api(): object
    {
        return $this->api ?? Credentials::api();
    }

    /** @return array{secret: string, account: string, public: string, webhook: string} */
    private function keys(string $environment): array
    {
        $api = $this->api();
        $test = $environment === 'test';

        return [
            'secret' => trim((string) ($test ? ($api->stripe_test_key ?? '') : ($api->stripe_private_key ?? ''))),
            'account' => trim((string) ($test ? ($api->stripe_test_account_id ?? '') : ($api->stripe_account_id ?? ''))),
            'public' => trim((string) ($test ? ($api->stripe_test_public_key ?? '') : ($api->stripe_public_key ?? ''))),
            'webhook' => trim((string) ($test ? ($api->stripe_test_webhook_secret ?? '') : ($api->stripe_webhook_secret ?? ''))),
        ];
    }

    private function intents(string $environment): PaymentIntent
    {
        $keys = $this->keys($environment);

        return new PaymentIntent($keys['secret'], $keys['account']);
    }

    /** Il Customer della scheda in questo ambiente: lo crea al primo pagamento. */
    private function customer(array $order, string $environment, PaymentIntent $intents): string
    {
        $contactId = (int) ($order['customer_id'] ?? 0);

        if ($contactId <= 0) {
            return '';
        }

        $saved = ExternalReferences::find('contact', $contactId, 'stripe', 'customer', $environment);

        if ($saved !== null && trim((string) $saved['external_id']) !== '') {
            return (string) $saved['external_id'];
        }

        $contact = Contact::findById($contactId);
        $customer = $intents->createCustomer(array_filter([
            'email' => (string) ($order['email'] ?? ''),
            'name' => is_array($contact) && $contact !== [] ? Contacts::displayName($contact) : '',
            'metadata' => ['contact_id' => (string) $contactId],
        ]), 'contact-'.$contactId.'-'.$environment);

        ExternalReferences::save('contact', $contactId, 'stripe', 'customer', (string) $customer->id, $environment);

        return (string) $customer->id;
    }

    /** @return array<string, mixed> */
    private function method(array $order): array
    {
        $id = (int) ($order['payment_method_id'] ?? 0);
        $method = $id > 0 ? PaymentMethod::findById($id) : null;

        return is_array($method) ? $method : [];
    }

    /**
     * I rimborsi della carica. Da un certo API in poi la carica non li porta
     * più con sé: allora si chiedono a Stripe.
     *
     * @return list<array{id: string, amount: int, status: string}>
     */
    private function refunds(array $charge, string $environment): array
    {
        $list = $charge['refunds']['data'] ?? null;

        if (!is_array($list)) {
            return $this->intents($environment)->refundsOf((string) ($charge['id'] ?? ''));
        }

        return array_map(static fn (array $refund): array => [
            'id' => (string) ($refund['id'] ?? ''),
            'amount' => (int) ($refund['amount'] ?? 0),
            'status' => (string) ($refund['status'] ?? ''),
        ], array_values(array_filter($list, 'is_array')));
    }
}
```

In `src/Support/Payments/PaymentProviders.php`, `get()` diventa (con `use Wonder\Plugin\Gestionale\Providers\Payments\StripeProvider;`):

```php
    public static function get(string $code): ?PaymentProvider
    {
        // Stripe c'è sempre: se nessuno ha registrato un altro adapter (i test),
        // si usa quello vero, con le credenziali del sito.
        if (!isset(self::$providers[$code]) && $code === 'stripe') {
            self::$providers[$code] = new StripeProvider();
        }

        return self::$providers[$code] ?? null;
    }
```

- [ ] **Passo 5: lo vedi passare**

Esegui: `php tests/integrazione/StripeProviderTest.php && php tests/PaymentProvidersTest.php`
Atteso: PASS, 17 controlli verdi nel primo e 6 nel secondo.

Se il controllo «start crea il Customer…» fallisce su `automatic_payment_methods`, guarda come stripe-php codifica i booleani in `$http->requests[1]['params']` (può essere `true` o `'true'`) e confronta con `in_array(..., [true, 'true'], true)`. Annota il ruling.

- [ ] **Passo 6: suite e commit**

Esegui: `php tests/run.php > /tmp/g-7.txt; tail -3 /tmp/g-7.txt`
Atteso: «Tutti i test del gestionale passano.» Da qui `connected('stripe')` legge le credenziali vere del sito di prova. Se un test del checkout dava per scontato Stripe spento, all'inizio di quel test registra `$f = new FakePaymentProvider(); $f->connected = false; PaymentProviders::register($f);` e alla fine `PaymentProviders::reset();`, e annota il ruling.

```bash
git add src/Providers/Payments/StripeProvider.php src/Support/Payments/PaymentProviders.php tests/integrazione/supporto/FakeStripeHttp.php tests/integrazione/supporto/firma.php tests/fixtures/stripe tests/integrazione/StripeProviderTest.php
git commit -m "Pagamenti: adapter Stripe con Customer, riuso dell'intento ed eventi firmati

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito 8: pagamenti online, eventi e rotta del webhook

**File:**
- Crea: `src/Support/Payments/PaymentMismatch.php`, `src/Support/Payments/OnlinePayments.php`, `src/Support/Payments/PaymentEvents.php`
- Crea: `config/routes/route.api.php`, `http/api/stripe/webhook.php`
- Modifica: `module.json` (voce `"api"` in `"routes"`)
- Crea: `tests/integrazione/OnlinePaymentsTest.php`, `tests/integrazione/PaymentEventsTest.php`

**Interfacce:**
- Consuma: `Ledger::open/register/refund/fail/attach/byReference`, `Lifecycle::confirm/cancel`, `ProviderEvents::receive/markProcessed/markFailed/find/MAX_ATTEMPTS`, `PaymentProviders::get/register/reset`, `PaymentEvent`, `PaymentStart`, `FakePaymentProvider`, `Errors::internal/report`.
- Produce (namespace `Wonder\Plugin\Gestionale\Support\Payments`):
  - `final class PaymentMismatch extends RuntimeException` — l'evento o l'intento non tornano con l'ordine: non si conferma e non si riprova.
  - `OnlinePayments::payment(int $orderId): ?array` — l'ultima riga `payment` online dell'ordine (fornitore diverso da `manual`), `null` se non c'è.
  - `OnlinePayments::start(int $orderId): PaymentStart` — crea o riusa l'intento e lo lega alla riga con `Ledger::attach`. Lancia `RuntimeException` se l'ordine non è in attesa, se manca la riga online aperta o se il fornitore non è collegato.
  - `OnlinePayments::succeeded(string $provider, string $reference, int $amount, string $currency, int $orderId, string $source): array` — controlla riga, ordine, centesimi e valuta (altrimenti `PaymentMismatch`), registra l'incasso e conferma l'ordine con `merchant_notice`. Su un ordine annullato registra l'incasso, avvisa il commerciante e restituisce `['status' => 'cancelled', 'changed' => false]`.
  - `OnlinePayments::fail(string $provider, string $reference, string $message): void` — `Ledger::fail` sulla riga, salvo che sia già pagata o che non ci sia.
  - `OnlinePayments::refunded(string $provider, string $reference, array $refunds, string $currency, string $source): int` — un `Ledger::refund` per ogni rimborso non fallito né annullato, con `re_…` come riferimento; restituisce quanti ne ha scritti.
  - `PaymentEvents::handle(PaymentProvider $provider, string $raw, string $signature): int` — il codice HTTP: 400 firma non valida; 200 ambiente diverso o incerto, evento già elaborato, elaborato ora o scartato per incoerenza; 500 errore da riprovare.
  - `PaymentEvents::process(PaymentProvider $provider, PaymentEvent $event, string $source = 'webhook'): bool` — `false` solo per un errore da riprovare.
  - Rotta `api.gestionale.stripe.webhook`: POST `/api/gestionale/stripe/webhook/`.

- [ ] **Passo 1: test che falliscono**

`tests/integrazione/OnlinePaymentsTest.php`: intestazione d'integrazione (commento in testa `/** php tests/integrazione/OnlinePaymentsTest.php */`), più `require __DIR__ . '/supporto/FakePaymentProvider.php';` dopo quello di `compra.php`, con queste `use`:

```php
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Payments\OnlinePayments;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
```

poi:

```php
$finto = new FakePaymentProvider();
PaymentProviders::register($finto);

/** L'ordine come lo lascia `Checkout::place` con un metodo Stripe. */
function ordineStripe(float $totale = 50.0): array
{
    $ordine = ordineDiProva($totale);
    $pagamento = Ledger::open(['order_id' => $ordine, 'amount' => $totale, 'provider' => 'stripe'])['payment_id'];

    return [$ordine, $pagamento];
}

check('start lega l\'intento alla riga aperta, con l\'ambiente del fornitore', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento] = ordineStripe();
    $avvio = OnlinePayments::start($ordine);
    $riga = Payment::findById($pagamento);

    return str_starts_with($avvio->reference, 'pi_finto_')
        && $avvio->clientSecret === $avvio->reference.'_secret_prova'
        && ($riga['provider_reference'] ?? '') === $avvio->reference
        && ($riga['environment'] ?? '') === 'test'
        && (int) (end($finto->started)['payment']['id'] ?? 0) === $pagamento;
}));

check('payment trova la riga online dell\'ordine', fn () => prova(static function (): bool {
    [$ordine, $pagamento] = ordineStripe();

    return (int) (OnlinePayments::payment($ordine)['id'] ?? 0) === $pagamento
        && OnlinePayments::payment(ordineDiProva()) === null;
}));

check('un ordine annullato non riparte', fn () => prova(static function (): bool {
    [$ordine] = ordineStripe();
    Lifecycle::cancel($ordine, ['notify' => false]);

    try {
        OnlinePayments::start($ordine);
    } catch (RuntimeException) {
        return true;
    }

    return false;
}));

check('con il fornitore spento non parte nulla', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento] = ordineStripe();
    $avviati = count($finto->started);
    $finto->connected = false;

    try {
        OnlinePayments::start($ordine);
    } catch (RuntimeException) {
        return !str_starts_with((string) (Payment::findById($pagamento)['provider_reference'] ?? ''), 'pi_')
            && count($finto->started) === $avviati;
    } finally {
        $finto->connected = true;
    }

    return false;
}));

PaymentProviders::reset();
summary();
```

`tests/integrazione/PaymentEventsTest.php`: intestazione d'integrazione (commento in testa `/** php tests/integrazione/PaymentEventsTest.php */`), più `require __DIR__ . '/supporto/FakePaymentProvider.php';` dopo quello di `compra.php`, con queste `use`:

```php
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentEvent;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Payments\OnlinePayments;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentEvents;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
use Wonder\Plugin\Gestionale\Support\Providers\ProviderEvents;
```

poi:

```php
$finto = new FakePaymentProvider();
PaymentProviders::register($finto);

/** Ordine in attesa col pagamento legato all'intento, come dopo il «Paga». */
function intentoAperto(float $totale = 50.0): array
{
    $ordine = ordineDiProva($totale);
    $pagamento = Ledger::open(['order_id' => $ordine, 'amount' => $totale, 'provider' => 'stripe'])['payment_id'];
    $intento = 'pi_ev_'.uniqid();
    Ledger::attach($pagamento, 'stripe', $intento, 'test');

    return [$ordine, $pagamento, $intento];
}

/** Un evento normalizzato; `$cambi` sostituisce i campi per nome. */
function evento(string $tipo, string $intento, int $ordine, int $centesimi, array $cambi = []): PaymentEvent
{
    $id = 'evt_'.uniqid();

    return new PaymentEvent(...array_merge([
        'id' => $id,
        'type' => $tipo,
        'environment' => 'test',
        'reference' => $intento,
        'amount' => $centesimi,
        'currency' => 'eur',
        'orderId' => $ordine,
        'refunds' => [],
        'chargeId' => 'ch_prova',
        'payload' => ['id' => $id, 'type' => $tipo],
    ], $cambi));
}

/** Manda l'evento come farebbe Stripe, con la firma che il finto accetta. */
function arriva(FakePaymentProvider $finto, PaymentEvent $evento): int
{
    $finto->nextEvent = $evento;

    return PaymentEvents::handle($finto, '{}', 'valida');
}

function stato(int $ordine): string
{
    return (string) (Order::findById($ordine)['status'] ?? '');
}

function righe(int $ordine, string $tipo = 'payment'): array
{
    $trovate = Payment::find(['order_id' => $ordine, 'type' => $tipo, 'deleted' => 'false']);

    return is_array($trovate) && isset($trovate['id']) ? [$trovate] : array_values(array_filter((array) $trovate, 'is_array'));
}

check('firma non valida: 400 e nessun evento registrato', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto();
    $finto->nextEvent = evento('payment_intent.succeeded', $intento, $ordine, 5000);

    return PaymentEvents::handle($finto, '{}', 'falsa') === 400
        && ProviderEvents::find('stripe', $finto->nextEvent->id, 'test') === null;
}));

check('evento dell\'altro ambiente o incerto: 200 e niente di fatto', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto();
    $live = evento('payment_intent.succeeded', $intento, $ordine, 5000, ['environment' => 'live']);
    $incerto = evento('payment_intent.succeeded', $intento, $ordine, 5000, ['environment' => '']);

    return arriva($finto, $live) === 200
        && arriva($finto, $incerto) === 200
        && ProviderEvents::find('stripe', $live->id, 'live') === null
        && stato($ordine) === 'pending';
}));

check('succeeded conferma l\'ordine e chiude la stessa riga', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto(50.0);
    $evento = evento('payment_intent.succeeded', $intento, $ordine, 5000);
    $righe = null;

    $esito = arriva($finto, $evento);
    $righe = righe($ordine);

    return $esito === 200
        && stato($ordine) === 'confirmed'
        && count($righe) === 1
        && (int) $righe[0]['id'] === $pagamento
        && $righe[0]['status'] === 'paid'
        && (ProviderEvents::find('stripe', $evento->id, 'test')['status'] ?? '') === 'processed';
}));

check('lo stesso evento ripetuto non fa nulla due volte', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto(50.0);
    $evento = evento('payment_intent.succeeded', $intento, $ordine, 5000);
    arriva($finto, $evento);

    return arriva($finto, $evento) === 200 && count(righe($ordine)) === 1;
}));

foreach ([
    'importo diverso' => ['amount' => 4999],
    'valuta diversa' => ['currency' => 'usd'],
    'ordine diverso' => ['orderId' => 999999999],
] as $caso => $cambio) {
    check("{$caso}: niente conferma, evento fermo in «Da controllare» e 200", fn () => prova(static function () use ($finto, $cambio): bool {
        [$ordine, $pagamento, $intento] = intentoAperto(50.0);
        $evento = evento('payment_intent.succeeded', $intento, $ordine, 5000, $cambio);
        $riga = null;

        $esito = arriva($finto, $evento);
        $riga = ProviderEvents::find('stripe', $evento->id, 'test');

        return $esito === 200
            && stato($ordine) === 'pending'
            && (Payment::findById($pagamento)['status'] ?? '') === 'pending'
            && ($riga['status'] ?? '') === 'failed'
            && (int) ($riga['attempts'] ?? 0) === ProviderEvents::MAX_ATTEMPTS;
    }));
}

check('incasso su un ordine annullato: il denaro si registra, l\'ordine resta annullato', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto(50.0);
    Lifecycle::cancel($ordine, ['notify' => false]);
    $evento = evento('payment_intent.succeeded', $intento, $ordine, 5000);

    return arriva($finto, $evento) === 200
        && stato($ordine) === 'cancelled'
        && (Payment::findById($pagamento)['status'] ?? '') === 'paid'
        && (ProviderEvents::find('stripe', $evento->id, 'test')['status'] ?? '') === 'processed';
}));

check('carta rifiutata: riga fallita, ordine in attesa; poi lo stesso intento riesce', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto(50.0);
    arriva($finto, evento('payment_intent.payment_failed', $intento, $ordine, 5000));
    $fallita = (string) (Payment::findById($pagamento)['status'] ?? '');
    $attesa = stato($ordine);
    arriva($finto, evento('payment_intent.succeeded', $intento, $ordine, 5000));

    return $fallita === 'failed'
        && $attesa === 'pending'
        && stato($ordine) === 'confirmed'
        && count(righe($ordine)) === 1;
}));

check('il fallimento che arriva dopo l\'incasso non tocca niente', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto(50.0);
    arriva($finto, evento('payment_intent.succeeded', $intento, $ordine, 5000));
    $esito = arriva($finto, evento('payment_intent.canceled', $intento, $ordine, 5000));

    return $esito === 200 && (Payment::findById($pagamento)['status'] ?? '') === 'paid';
}));

check('charge.refunded registra i rimborsi riusciti, una volta sola', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto(50.0);
    arriva($finto, evento('payment_intent.succeeded', $intento, $ordine, 5000));
    $rimborsi = [
        ['id' => 're_'.uniqid(), 'amount' => 2000, 'status' => 'succeeded'],
        ['id' => 're_'.uniqid(), 'amount' => 1000, 'status' => 'failed'],
    ];
    arriva($finto, evento('charge.refunded', $intento, $ordine, 5000, ['refunds' => $rimborsi]));
    $ancora = OnlinePayments::refunded('stripe', $intento, $rimborsi, 'eur', 'cron');
    $scritti = righe($ordine, 'refund');

    return count($scritti) === 1
        && $ancora === 1
        && $scritti[0]['provider_reference'] === $rimborsi[0]['id']
        && (float) $scritti[0]['amount'] === 20.0;
}));

check('un tipo che non serve si segna elaborato', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto();
    $evento = evento('payment_intent.created', $intento, $ordine, 5000);

    return arriva($finto, $evento) === 200
        && (ProviderEvents::find('stripe', $evento->id, 'test')['status'] ?? '') === 'processed';
}));

check('un errore nostro risponde 500; l\'evento fallito si rielabora e riesce', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto(50.0);
    $evento = evento('payment_intent.succeeded', $intento, $ordine, 5000);
    // Un carrello non si conferma: `Lifecycle::confirm` lancia.
    Order::update(['stage' => 'cart'], $ordine);
    $primo = arriva($finto, $evento);
    $riga = ProviderEvents::find('stripe', $evento->id, 'test');
    Order::update(['stage' => 'order'], $ordine);
    $secondo = arriva($finto, $evento);

    return $primo === 500
        && ($riga['status'] ?? '') === 'failed'
        && (int) ($riga['attempts'] ?? 0) === 1
        && $secondo === 200
        && stato($ordine) === 'confirmed';
}));

check('la rotta del webhook è registrata e punta al suo file', static function (): bool {
    $modulo = json_decode((string) file_get_contents(Gestionale::manifestPath()), true);
    $rotte = (string) file_get_contents(Gestionale::root().'/config/routes/route.api.php');

    return ($modulo['routes']['api'] ?? '') === 'config/routes/route.api.php'
        && str_contains($rotte, "Route::post('/stripe/webhook/'")
        && is_file(Gestionale::handlerPath('api/stripe/webhook.php'));
});

PaymentProviders::reset();
summary();
```

- [ ] **Passo 2: li vedi fallire**

Esegui: `php tests/integrazione/OnlinePaymentsTest.php; php tests/integrazione/PaymentEventsTest.php`
Atteso: FAIL, `Class "Wonder\Plugin\Gestionale\Support\Payments\OnlinePayments" not found` e lo stesso per `PaymentEvents`.

- [ ] **Passo 3: `PaymentMismatch` e `OnlinePayments`**

`src/Support/Payments/PaymentMismatch.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use RuntimeException;

/**
 * Il gateway dice di aver incassato, ma importo, valuta o ordine non tornano.
 * Non si conferma e non si riprova: lo guarda il commerciante.
 */
final class PaymentMismatch extends RuntimeException {}
```

`src/Support/Payments/OnlinePayments.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use RuntimeException;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentStart;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;

/**
 * Il denaro che passa da un gateway: l'avvio dell'intento e quello che il
 * gateway racconta dopo. Webhook, pagina di ritorno e riallineamento passano
 * tutti da qui, così i controlli sono gli stessi da qualunque parte arrivi
 * la notizia.
 */
final class OnlinePayments
{
    /** L'ultima riga di pagamento online dell'ordine. @return array<string, mixed>|null */
    public static function payment(int $orderId): ?array
    {
        $found = Payment::find(['order_id' => $orderId, 'type' => 'payment', 'deleted' => 'false']);
        $rows = is_array($found) && isset($found['id']) ? [$found] : array_values(array_filter((array) $found, 'is_array'));
        $online = array_values(array_filter($rows, static fn (array $row): bool => (string) $row['provider'] !== 'manual'));

        return $online === [] ? null : end($online);
    }

    /**
     * Crea o riusa l'intento dell'ordine e lo lega alla riga aperta al
     * checkout. Il riuso lo decide il fornitore, che vede lo stato dell'intento.
     */
    public static function start(int $orderId): PaymentStart
    {
        $order = Order::findById($orderId);

        if (!is_array($order) || (string) ($order['status'] ?? '') !== 'pending') {
            throw new RuntimeException("Ordine {$orderId} non più da pagare.");
        }

        $payment = self::payment($orderId);

        if ($payment === null || !in_array((string) $payment['status'], ['pending', 'failed'], true)) {
            throw new RuntimeException("Ordine {$orderId} senza un pagamento online aperto.");
        }

        $provider = PaymentProviders::get((string) $payment['provider']);

        if ($provider === null || !$provider->connected()) {
            throw new RuntimeException('Pagamento online non collegato.');
        }

        $start = $provider->start($order, $payment);
        Ledger::attach((int) $payment['id'], $provider->code(), $start->reference, $start->environment);

        return $start;
    }

    /**
     * Il gateway ha incassato: si controlla che il denaro sia proprio
     * dell'ordine, poi si registra e si conferma.
     *
     * L'incasso si scrive prima della conferma e per conto suo: se la
     * conferma cade, il denaro resta registrato e il prossimo passaggio
     * (rinvio del webhook, riallineamento) trova la riga già pagata e
     * conferma soltanto.
     *
     * @return array<string, mixed> l'esito di `Lifecycle::confirm`
     */
    public static function succeeded(string $provider, string $reference, int $amount, string $currency, int $orderId, string $source): array
    {
        $row = Ledger::byReference($provider, $reference);

        if ($row === null) {
            throw new PaymentMismatch("Intento {$reference} senza un pagamento nel gestionale.");
        }

        $rowOrder = (int) $row['order_id'];
        $order = Order::findById($rowOrder);

        if ($orderId !== $rowOrder || !is_array($order)) {
            throw new PaymentMismatch("Intento {$reference}: ordine {$orderId} al posto di {$rowOrder}.");
        }

        if ($amount !== (int) round((float) $row['amount'] * 100)) {
            throw new PaymentMismatch("Intento {$reference}: {$amount} centesimi al posto di ".(int) round((float) $row['amount'] * 100).'.');
        }

        $expected = (string) ($order['currency'] ?? '') ?: 'EUR';

        if (strtolower($currency) !== strtolower($expected)) {
            throw new PaymentMismatch("Intento {$reference}: valuta {$currency} al posto di {$expected}.");
        }

        Ledger::register([
            'order_id' => $rowOrder,
            'amount' => $amount / 100,
            'currency' => $expected,
            'provider' => $provider,
            'provider_reference' => $reference,
            'source' => $source,
        ]);

        if ((string) $order['status'] === 'cancelled') {
            // Il cliente ha pagato un ordine già annullato: il denaro va
            // restituito dalla dashboard del gateway.
            Errors::report($provider, 'payment.cancelled_order', "Incasso {$reference} sull'ordine annullato {$rowOrder}: va rimborsato.", ['order_id' => $rowOrder]);

            return ['order_id' => $rowOrder, 'status' => 'cancelled', 'changed' => false];
        }

        return Lifecycle::confirm($rowOrder, [
            'provider' => $provider,
            'provider_reference' => $reference,
            'amount' => $amount / 100,
            'source' => $source,
            'merchant_notice' => true,
        ]);
    }

    /**
     * Il tentativo non è andato: la riga si segna fallita e l'ordine resta in
     * attesa. L'intento resta riusabile: il cliente riprova sullo stesso.
     */
    public static function fail(string $provider, string $reference, string $message): void
    {
        $row = Ledger::byReference($provider, $reference);

        if ($row === null || (string) $row['status'] === 'paid') {
            // Un fallimento in ritardo dopo l'incasso non toglie il denaro.
            return;
        }

        Ledger::fail((int) $row['id'], $message);
    }

    /**
     * I rimborsi fatti dalla dashboard del gateway. Il riferimento di ognuno
     * (`re_…`) rende la scrittura idempotente.
     *
     * @param list<array{id: string, amount: int, status: string}> $refunds
     */
    public static function refunded(string $provider, string $reference, array $refunds, string $currency, string $source): int
    {
        $row = Ledger::byReference($provider, $reference);

        if ($row === null) {
            throw new PaymentMismatch("Rimborso sull'intento {$reference}, che il gestionale non conosce.");
        }

        $written = 0;

        foreach ($refunds as $refund) {
            if (in_array((string) $refund['status'], ['failed', 'canceled'], true) || (int) $refund['amount'] <= 0) {
                continue;
            }

            Ledger::refund([
                'order_id' => (int) $row['order_id'],
                'amount' => (int) $refund['amount'] / 100,
                'currency' => strtoupper($currency) ?: 'EUR',
                'provider' => $provider,
                'provider_reference' => (string) $refund['id'],
                'source' => $source,
            ]);
            ++$written;
        }

        return $written;
    }
}
```

Il controllo «ordine diverso» confronta l'ordine dei metadati con quello della riga: un `orderId` a 0 (metadati mancanti) è già un'incoerenza.

- [ ] **Passo 4: `PaymentEvents`**

`src/Support/Payments/PaymentEvents.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use Throwable;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentEvent;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentProvider;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Providers\ProviderEvents;

/**
 * Gli eventi che il gateway manda: firma, registro e smistamento.
 *
 * Il registro (`ProviderEvents`) fa sì che un evento già elaborato non faccia
 * nulla due volte; uno ricevuto o fallito si riprende, dal webhook che lo
 * rimanda o dal riallineamento orario.
 */
final class PaymentEvents
{
    /** Il codice HTTP da rendere al gateway. */
    public static function handle(PaymentProvider $provider, string $raw, string $signature): int
    {
        $event = $provider->event($raw, $signature);

        if ($event === null) {
            return 400;
        }

        if ($event->environment === '' || $event->environment !== $provider->environment()) {
            // L'altro ambiente non si tocca: test e produzione non si mescolano.
            return 200;
        }

        if (!ProviderEvents::receive($provider->code(), $event->id, $event->type, $event->payload, $event->environment)) {
            return 200;
        }

        return self::process($provider, $event) ? 200 : 500;
    }

    /** `false` solo se l'evento va riprovato. */
    public static function process(PaymentProvider $provider, PaymentEvent $event, string $source = 'webhook'): bool
    {
        $code = $provider->code();
        $context = ['event' => $event->id, 'type' => $event->type, 'reference' => $event->reference];

        try {
            self::apply($code, $event, $source);
            ProviderEvents::markProcessed($code, $event->id, $event->environment);

            return true;
        } catch (PaymentMismatch $error) {
            // Riprovare non cambia i numeri: l'evento resta in «Da controllare».
            Errors::report($code, 'payment.mismatch', $error, $context);
            ProviderEvents::markFailed($code, $event->id, $error->getMessage(), $event->environment, true);

            return true;
        } catch (Throwable $error) {
            Errors::internal($error, 'payments.event', $context);
            ProviderEvents::markFailed($code, $event->id, $error->getMessage(), $event->environment);

            return false;
        }
    }

    private static function apply(string $provider, PaymentEvent $event, string $source): void
    {
        match ($event->type) {
            'payment_intent.succeeded' => OnlinePayments::succeeded($provider, $event->reference, $event->amount, $event->currency, $event->orderId, $source),
            'payment_intent.payment_failed' => OnlinePayments::fail($provider, $event->reference, 'Pagamento non riuscito'),
            'payment_intent.canceled' => OnlinePayments::fail($provider, $event->reference, 'Pagamento annullato'),
            'charge.refunded' => OnlinePayments::refunded($provider, $event->reference, $event->refunds, $event->currency, $source),
            default => null,
        };
    }
}
```

- [ ] **Passo 5: rotta e file del webhook**

In `module.json`, dentro `"routes"`:

```json
    "routes": {
        "backend": "config/routes/route.backend.php",
        "api": "config/routes/route.api.php"
    },
```

`config/routes/route.api.php`:

```php
<?php

use Wonder\Http\Route;
use Wonder\Plugin\Gestionale\Gestionale;

Route::area('api')->response('json')->frontend()->translatable(false)
    ->name('api.gestionale.')->prefix('/gestionale')->group(function () {
        // Niente sessione e niente CSRF: vale la firma di Stripe sul corpo.
        Route::post('/stripe/webhook/', Gestionale::handlerPath('api/stripe/webhook.php'))
            ->name('stripe.webhook');
    });
```

`http/api/stripe/webhook.php`:

```php
<?php

use Wonder\Plugin\Gestionale\Support\Payments\PaymentEvents;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;

// La firma è sul corpo grezzo: si legge così com'è, prima di ogni decodifica.
$raw = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
$provider = PaymentProviders::get('stripe');
$code = $provider === null ? 503 : PaymentEvents::handle($provider, $raw, $signature);

http_response_code($code);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['received' => $code === 200]);
```

- [ ] **Passo 6: li vedi passare**

Esegui: `php tests/integrazione/OnlinePaymentsTest.php && php tests/integrazione/PaymentEventsTest.php`
Atteso: PASS, 4 controlli verdi nel primo e 14 nel secondo.

Poi una prova a mano della rotta (il sito di prova risponde su `https://ecommerce.test`):

```bash
curl -sk -o /dev/null -w '%{http_code}\n' -X POST https://ecommerce.test/api/gestionale/stripe/webhook/ -d '{}'
```

Atteso: `400` (firma mancante). Un `404` vuol dire che la rotta non è caricata: guarda come il core legge `"routes"` di `module.json` e annota il ruling. Un `503` vuol dire che `PaymentProviders::get('stripe')` è `null`, cosa che dopo il Compito 7 non deve succedere.

- [ ] **Passo 7: suite e commit**

Esegui: `php tests/run.php > /tmp/g-8.txt; tail -3 /tmp/g-8.txt`
Atteso: «Tutti i test del gestionale passano.»

```bash
git add src/Support/Payments/PaymentMismatch.php src/Support/Payments/OnlinePayments.php src/Support/Payments/PaymentEvents.php config/routes/route.api.php http/api/stripe/webhook.php module.json tests/integrazione/OnlinePaymentsTest.php tests/integrazione/PaymentEventsTest.php
git commit -m "Pagamenti: incassi online, eventi del gateway e rotta del webhook

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito 9: il checkout online aspetta l'incasso per avvisare

**File:**
- Modifica: `src/Support/Orders/Checkout.php` (`place()` righe 47–113, `create()` righe 322–345)
- Modifica: `src/Support/Orders/Lifecycle.php` (`confirm()` righe 127–131)
- Test: `tests/integrazione/CheckoutTest.php` (in coda, prima di `summary();`)

**Interfacce:**
- Consuma: `PaymentMethod::ledgerProvider()`, `OrderNotifier::send()`.
- Produce:
  - `Checkout::place()` restituisce anche `provider` (`'manual'` o il codice del gateway, `'stripe'`). Con un gateway l'ordine nasce in attesa senza email «ricevuto» al cliente e senza `merchant_new`: le manda la conferma, quando arriva l'incasso.
  - `Lifecycle::confirm($id, ['merchant_notice' => true])`: dopo l'email «confermato» al cliente manda `merchant_new` al commerciante, solo se l'ordine è cambiato e con `notify` acceso (come `cancel()` con `merchant_cancelled`).

- [ ] **Passo 1: test che falliscono**

In `tests/integrazione/CheckoutTest.php` aggiungi `use Wonder\Plugin\Gestionale\Models\System\Setting;` e `use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;` fra le `use`, poi prima di `summary();`:

```php
/** Corre il corpo segnando chi riceve posta; il commerciante è `negozio@example.com`. */
function chiRiceve(callable $corpo): array
{
    sqlModify(Setting::$table, ['merchant_notification_emails' => 'negozio@example.com'], 'id', '1');
    $posta = [];
    Mailer::useTransport(static function (string $to) use (&$posta): bool {
        $posta[] = $to;

        return true;
    });

    try {
        $corpo();
    } finally {
        Mailer::useTransport(null);
    }

    return $posta;
}

check('col bonifico partono «ricevuto» al cliente e l\'avviso al commerciante', function () {
    return prova(static function (): bool {
        [$carrello] = carrelloPronto();
        $esito = [];
        $posta = chiRiceve(static function () use ($carrello, &$esito): void {
            $esito = Checkout::place($carrello, datiCheckout(metodoDiProva(PaymentTiming::DEFERRED)));
        });

        return $esito['provider'] === 'manual'
            && $posta === ['cliente@example.com', 'negozio@example.com'];
    });
});

check('con la carta l\'ordine nasce in silenzio: le email aspettano l\'incasso', function () {
    return prova(static function (): bool {
        [$carrello] = carrelloPronto();
        $esito = [];
        $posta = chiRiceve(static function () use ($carrello, &$esito): void {
            $esito = Checkout::place($carrello, datiCheckout(metodoDiProva(PaymentTiming::IMMEDIATE)));
        });

        return $esito['provider'] === 'stripe'
            && $esito['status'] === 'pending'
            && $esito['customer_email_sent'] === false
            && $posta === [];
    });
});

check('la conferma con merchant_notice avvisa cliente e commerciante, una volta', function () {
    return prova(static function (): bool {
        [$carrello] = carrelloPronto();
        $ordine = senzaPosta(static fn (): array => Checkout::place($carrello, datiCheckout(metodoDiProva(PaymentTiming::IMMEDIATE))))['order_id'];
        $posta = chiRiceve(static function () use ($ordine): void {
            Lifecycle::confirm($ordine, ['payment' => false, 'merchant_notice' => true]);
            Lifecycle::confirm($ordine, ['payment' => false, 'merchant_notice' => true]);
        });

        return $posta === ['cliente@example.com', 'negozio@example.com'];
    });
});
```

- [ ] **Passo 2: li vedi fallire**

Esegui: `php tests/integrazione/CheckoutTest.php`
Atteso: FAIL sui tre controlli nuovi (`Undefined array key "provider"` nei primi due; nel terzo la posta è solo `['cliente@example.com']`).

- [ ] **Passo 3: `create()` dice il fornitore**

In `create()` il fornitore si calcola una volta, prima di `Ledger::open`:

```php
            $provider = PaymentMethod::ledgerProvider((string) ($method['provider'] ?? ''));

            $payment = Ledger::open([
                // … come prima …
                'provider' => $provider,
                // … come prima …
            ]);
```

e il `return` lo porta fuori:

```php
            return [
                'order_id' => $cartId,
                'order_number' => $number,
                'payment_id' => (int) $payment['payment_id'],
                'total' => (string) $order['total'],
                'reserved' => $reserved,
                'timing' => $timing,
                'status' => 'pending',
                'provider' => $provider,
            ];
```

- [ ] **Passo 4: `place()` tace col gateway**

Nel docblock di `place()` il ritorno diventa `array{order_id: int, order_number: string, payment_id: int, total: string, reserved: int, status: string, provider: string, customer_email_sent: bool}`. Poi, subito dopo `$result['customer_email_sent'] = false;`:

```php
        // Col gateway il denaro non c'è ancora: «ricevuto» e l'avviso al
        // commerciante partirebbero anche per chi chiude la pagina senza
        // pagare. Li manda la conferma, quando arriva l'incasso.
        $online = $result['provider'] !== 'manual';
```

e `$key = 'received';` diventa `$key = $online ? '' : 'received';`. Il blocco di `merchant_new` si chiude in `if (!$online) { … }`:

```php
        if (!$online) {
            try {
                OrderNotifier::send('merchant_new', $result['order_id']);
            } catch (Throwable $error) {
                Errors::internal($error, 'checkout.merchant_notice', ['order_id' => $result['order_id']]);
            }
        }
```

Il commento sopra il `try` («Il contrassegno e il ritiro si pagano alla consegna…») resta com'è.

- [ ] **Passo 5: `confirm()` può avvisare il commerciante**

In `Lifecycle::confirm()`, il blocco dopo la transazione diventa:

```php
        if ($result['changed'] && ($options['notify'] ?? true)) {
            // Fuori dalla transazione: la posta è lenta e non deve tenere
            // aperto un blocco sulle righe di magazzino.
            OrderNotifier::send('confirmed', $orderId, (array) ($options['email_extra'] ?? []));

            if (($options['merchant_notice'] ?? false) === true) {
                // L'ordine pagato online arriva al commerciante qui, non al checkout.
                OrderNotifier::send('merchant_new', $orderId);
            }
        }
```

e il docblock di `confirm()` aggiunge l'opzione: `merchant_notice` (bool) — dopo «confermato» manda `merchant_new`.

- [ ] **Passo 6: li vedi passare**

Esegui: `php tests/integrazione/CheckoutTest.php && php tests/integrazione/PaymentEventsTest.php`
Atteso: PASS. `PaymentEventsTest` resta verde: `succeeded` passava già `merchant_notice`.

- [ ] **Passo 7: suite e commit**

Esegui: `php tests/run.php > /tmp/g-9.txt; tail -3 /tmp/g-9.txt`
Atteso: «Tutti i test del gestionale passano.» Se un test confronta le chiavi esatte del risultato di `place()`, aggiungi `provider` all'atteso.

```bash
git add src/Support/Orders/Checkout.php src/Support/Orders/Lifecycle.php tests/integrazione/CheckoutTest.php
git commit -m "Checkout: col gateway le email partono alla conferma dell'incasso

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito 10: i moduli aggiungono dati alle email dell'ordine

L'email «confermato» di un ordine pagato online parte dal webhook, senza la sessione del cliente: il link per scegliere la password dell'ospite (`account_url`) non lo può dare il checkout. Lo dà il modulo che conosce l'account (l'ecommerce, Compito 16) attraverso questo punto d'estensione, sul modello di `SettingsSections`.

**File:**
- Crea: `src/Extensions/ProvidesOrderEmailExtras.php`, `src/Extensions/OrderEmailExtras.php`
- Modifica: `src/Support/Orders/OrderNotifier.php` (`send()` righe 44–51)
- Crea: `tests/OrderEmailExtrasTest.php`
- Test: `tests/integrazione/OrderEmailsTest.php` (in coda, prima di `summary();`)

**Interfacce:**
- Consuma: `Registry::enabled()` (manifest con `entrypoint()`), `Errors::internal()`.
- Produce (namespace `Wonder\Plugin\Gestionale\Extensions`):
  - `interface ProvidesOrderEmailExtras { public static function orderEmailExtras(string $key, array $order): array; }` — implementata dall'entrypoint di un modulo; restituisce le chiavi di `OrderEmail::compose` (`account_url`, `url`, …) per quella email.
  - `OrderEmailExtras::for(string $key, array $order): array` — l'unione di quello che danno i moduli accesi; il primo modulo che dà una chiave vince. Un modulo che lancia si salta, con `Errors::internal`: l'email parte comunque.
  - `OrderEmailExtras::fromEntrypoints(iterable $entrypoints): array` — le classi che implementano l'interfaccia.
  - `OrderEmailExtras::use(?array $classes): void` — forza le classi; `null` torna a leggerle dai moduli.
  - In `OrderNotifier::send()` vince chi chiama, poi i moduli, poi i valori del metodo di pagamento.

- [ ] **Passo 1: test che falliscono**

`tests/OrderEmailExtrasTest.php`:

```php
<?php
/** php tests/OrderEmailExtrasTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Extensions\OrderEmailExtras;
use Wonder\Plugin\Gestionale\Extensions\ProvidesOrderEmailExtras;

final class PrimoModulo implements ProvidesOrderEmailExtras
{
    public static function orderEmailExtras(string $key, array $order): array
    {
        return $key === 'confirmed' ? ['account_url' => '/account/'.$order['id'].'/'] : [];
    }
}

final class SecondoModulo implements ProvidesOrderEmailExtras
{
    public static function orderEmailExtras(string $key, array $order): array
    {
        return ['account_url' => '/altro/', 'url' => '/ordine/'];
    }
}

final class NonUnModulo {}

check('dagli entrypoint restano solo quelli che danno dati', fn () => OrderEmailExtras::fromEntrypoints([
    PrimoModulo::class, NonUnModulo::class, 'Classe\\Che\\Non\\Esiste', SecondoModulo::class,
]) === [PrimoModulo::class, SecondoModulo::class]);

check('il primo modulo che dà una chiave vince, gli altri completano', function () {
    OrderEmailExtras::use([PrimoModulo::class, SecondoModulo::class]);

    try {
        return OrderEmailExtras::for('confirmed', ['id' => 7]) === ['account_url' => '/account/7/', 'url' => '/ordine/']
            && OrderEmailExtras::for('shipped', ['id' => 7]) === ['account_url' => '/altro/', 'url' => '/ordine/'];
    } finally {
        OrderEmailExtras::use(null);
    }
});

check('senza moduli niente', function () {
    OrderEmailExtras::use([]);

    try {
        return OrderEmailExtras::for('confirmed', ['id' => 1]) === [];
    } finally {
        OrderEmailExtras::use(null);
    }
});

summary();
```

In `tests/integrazione/OrderEmailsTest.php` aggiungi `use Wonder\Plugin\Gestionale\Extensions\OrderEmailExtras;` e `use Wonder\Plugin\Gestionale\Extensions\ProvidesOrderEmailExtras;`, la classe dopo `final class Annulla …`:

```php
final class LinkDelModulo implements ProvidesOrderEmailExtras
{
    public static function orderEmailExtras(string $key, array $order): array
    {
        return ['account_url' => '/account/password-restore/?token=dal-modulo'];
    }
}

final class ModuloRotto implements ProvidesOrderEmailExtras
{
    public static function orderEmailExtras(string $key, array $order): array
    {
        throw new RuntimeException('rotto');
    }
}
```

(Il modulo che lancia sta nel test d'integrazione perché `Errors::internal` scrive col registro del core, che vuole il sito.)

e prima di `summary();`:

```php
check('i dati dei moduli arrivano nell\'email, ma vince chi chiama', function () {
    return prova(static function (): bool {
        $corpi = [];
        Mailer::useTransport(static function (string $to, string $subject, string $body) use (&$corpi): bool {
            $corpi[] = $body;

            return true;
        });
        OrderEmailExtras::use([LinkDelModulo::class]);

        try {
            $ordine = ordineConRiga();
            OrderNotifier::send('confirmed', $ordine);
            OrderNotifier::send('confirmed', $ordine, ['account_url' => '/account/password-restore/?token=da-chi-chiama']);
        } finally {
            OrderEmailExtras::use(null);
            Mailer::useTransport(null);
        }

        return count($corpi) === 2
            && str_contains($corpi[0], 'token=dal-modulo')
            && str_contains($corpi[1], 'token=da-chi-chiama')
            && !str_contains($corpi[1], 'token=dal-modulo');
    });
});

check('un modulo che lancia si salta e gli altri danno i loro dati', function () {
    OrderEmailExtras::use([ModuloRotto::class, LinkDelModulo::class]);

    try {
        return OrderEmailExtras::for('confirmed', ['id' => 1]) === ['account_url' => '/account/password-restore/?token=dal-modulo'];
    } finally {
        OrderEmailExtras::use(null);
    }
});
```

- [ ] **Passo 2: li vedi fallire**

Esegui: `php tests/OrderEmailExtrasTest.php; php tests/integrazione/OrderEmailsTest.php`
Atteso: FAIL, `Interface "Wonder\Plugin\Gestionale\Extensions\ProvidesOrderEmailExtras" not found`.

- [ ] **Passo 3: interfaccia e raccolta**

`src/Extensions/ProvidesOrderEmailExtras.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Extensions;

/**
 * L'entrypoint di un modulo che aggiunge dati alle email dell'ordine: le
 * chiavi di `OrderEmail::compose` (`account_url`, `url`, …) che solo lui sa
 * calcolare, anche quando l'email parte da un webhook senza sessione.
 */
interface ProvidesOrderEmailExtras
{
    /**
     * @param array<string, mixed> $order la riga dell'ordine
     * @return array<string, string>
     */
    public static function orderEmailExtras(string $key, array $order): array;
}
```

`src/Extensions/OrderEmailExtras.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Extensions;

use Throwable;
use Wonder\App\Module\Registry;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;

/**
 * I dati che i moduli accesi aggiungono alle email dell'ordine, letti una
 * volta per richiesta.
 *
 * A differenza dei riquadri delle impostazioni, un modulo che si rompe qui si
 * salta: l'email al cliente conta più del dato in più.
 */
final class OrderEmailExtras
{
    /** @var list<class-string<ProvidesOrderEmailExtras>>|null */
    private static ?array $classes = null;

    /**
     * @param array<string, mixed> $order
     * @return array<string, string>
     */
    public static function for(string $key, array $order): array
    {
        $extras = [];

        foreach (self::classes() as $class) {
            try {
                $extras += array_filter($class::orderEmailExtras($key, $order), 'is_string');
            } catch (Throwable $error) {
                Errors::internal($error, 'orders.email_extras', ['module' => $class, 'key' => $key]);
            }
        }

        return $extras;
    }

    /**
     * @param iterable<string> $entrypoints
     * @return list<class-string<ProvidesOrderEmailExtras>>
     */
    public static function fromEntrypoints(iterable $entrypoints): array
    {
        $classes = [];

        foreach ($entrypoints as $entrypoint) {
            if (is_string($entrypoint) && is_subclass_of($entrypoint, ProvidesOrderEmailExtras::class)) {
                $classes[] = $entrypoint;
            }
        }

        return $classes;
    }

    /** Forza le classi; `null` torna a leggerle dai moduli. */
    public static function use(?array $classes): void
    {
        self::$classes = $classes === null ? null : array_values($classes);
    }

    /** @return list<class-string<ProvidesOrderEmailExtras>> */
    private static function classes(): array
    {
        if (self::$classes !== null) {
            return self::$classes;
        }

        try {
            $entrypoints = array_map(static fn ($manifest): string => $manifest->entrypoint(), Registry::enabled());
        } catch (Throwable) {
            $entrypoints = [];
        }

        return self::$classes = self::fromEntrypoints($entrypoints);
    }
}
```

- [ ] **Passo 4: `OrderNotifier` li usa**

In `OrderNotifier::send()` aggiungi `use Wonder\Plugin\Gestionale\Extensions\OrderEmailExtras;` e la chiamata a `compose` diventa:

```php
        $email = OrderEmail::compose($key, $order, self::items($orderId), $extra + OrderEmailExtras::for($key, $order) + [
            'method' => is_array($method) ? (string) ($method['name'] ?? '') : '',
            'instructions' => is_array($method) ? (string) ($method['instructions'] ?? '') : '',
            'bank' => self::bankAccount($method),
        ]);
```

- [ ] **Passo 5: li vedi passare**

Esegui: `php tests/OrderEmailExtrasTest.php && php tests/integrazione/OrderEmailsTest.php`
Atteso: PASS, 3 controlli nel primo; nel secondo anche i due nuovi.

- [ ] **Passo 6: suite e commit**

Esegui: `php tests/run.php > /tmp/g-10.txt; tail -3 /tmp/g-10.txt`
Atteso: «Tutti i test del gestionale passano.»

```bash
git add src/Extensions/ProvidesOrderEmailExtras.php src/Extensions/OrderEmailExtras.php src/Support/Orders/OrderNotifier.php tests/OrderEmailExtrasTest.php tests/integrazione/OrderEmailsTest.php
git commit -m "Email dell'ordine: i moduli accesi possono aggiungere i loro dati

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito 11: annullare l'ordine annulla l'intento

Un ordine online annullato (dal commerciante, dalla scadenza di `Expiry`, dall'ecommerce che ne apre uno nuovo) non deve lasciare su Stripe un intento ancora pagabile: il cliente che torna sulla pagina vecchia pagherebbe un ordine morto. `Lifecycle::cancel` raccoglie gli intenti dentro la transazione e li annulla dopo, quando l'ordine è già scritto: una chiamata a Stripe non sta mai dentro una transazione, e un errore di Stripe non ferma l'annullo.

**File:**
- Modifica: `src/Support/Orders/Lifecycle.php` (`cancel()`, righe 147–238; un metodo privato nuovo dopo)
- Modifica: `tests/integrazione/supporto/FakePaymentProvider.php` (`failCancel`)
- Test: `tests/integrazione/OnlinePaymentsTest.php` (in coda, prima di `summary();`)

**Interfacce:**
- Consuma: `PaymentProviders::get()`, `PaymentProvider::environment()/cancel()` (Compito 6), `Errors::internal()`, la colonna `environment` di `gst_payments` (Compito 4), `OnlinePayments::start()` (Compito 8).
- Produce:
  - `Lifecycle::cancel()` annulla dal fornitore, dopo la transazione, l'intento di ogni riga `payment` in attesa o fallita con fornitore registrato, riferimento non vuoto e ambiente uguale a quello del fornitore. Il risultato non cambia forma.
  - `FakePaymentProvider::$failCancel` (`bool`, predefinito `false`): con `true`, `cancel()` lancia `RuntimeException`.
  - L'evento `payment_intent.canceled` che Stripe manda dopo trova la riga già fallita: `OnlinePayments::fail` non la tocca di nuovo.

- [ ] **Passo 1: test che falliscono**

In `tests/integrazione/supporto/FakePaymentProvider.php`, accanto a `public array $cancelled = [];`:

```php
    public bool $failCancel = false;
```

e `cancel()` diventa:

```php
    public function cancel(string $reference): void
    {
        if ($this->failCancel) {
            throw new RuntimeException('Stripe non risponde');
        }

        $this->cancelled[] = $reference;
    }
```

In coda a `tests/integrazione/OnlinePaymentsTest.php`, prima di `summary();`:

```php
check('annullare l\'ordine annulla l\'intento e chiude la riga', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento] = ordineStripe();
    $avvio = OnlinePayments::start($ordine);
    $esito = Lifecycle::cancel($ordine, ['notify' => false]);

    return in_array($avvio->reference, $finto->cancelled, true)
        && ($esito['status'] ?? '') === 'cancelled'
        && !array_key_exists('intents', $esito)
        && (Payment::findById($pagamento)['status'] ?? '') === 'failed';
}));

check('l\'intento dell\'altro ambiente non si tocca', fn () => prova(static function () use ($finto): bool {
    [$ordine] = ordineStripe();
    $avvio = OnlinePayments::start($ordine);
    $finto->environment = 'live';

    try {
        Lifecycle::cancel($ordine, ['notify' => false]);
    } finally {
        $finto->environment = 'test';
    }

    return !in_array($avvio->reference, $finto->cancelled, true);
}));

check('una riga senza intento non chiama il fornitore', fn () => prova(static function () use ($finto): bool {
    [$ordine] = ordineStripe();
    $prima = count($finto->cancelled);
    Lifecycle::cancel($ordine, ['notify' => false]);

    return count($finto->cancelled) === $prima;
}));

check('se Stripe non risponde l\'ordine si annulla lo stesso', fn () => prova(static function () use ($finto): bool {
    [$ordine] = ordineStripe();
    OnlinePayments::start($ordine);
    $finto->failCancel = true;

    try {
        $esito = Lifecycle::cancel($ordine, ['notify' => false]);
    } finally {
        $finto->failCancel = false;
    }

    return ($esito['changed'] ?? false) === true
        && (Order::findById($ordine)['status'] ?? '') === 'cancelled';
}));
```

Aggiungi `use Wonder\Plugin\Gestionale\Models\Sales\Order;` alle `use` del file, se manca.

- [ ] **Passo 2: lo vedi fallire**

Esegui: `php tests/integrazione/OnlinePaymentsTest.php`
Atteso: FAIL su «annullare l'ordine annulla l'intento e chiude la riga» (nessun riferimento in `$finto->cancelled`); gli altri tre passano già.

- [ ] **Passo 3: implementazione**

In `src/Support/Orders/Lifecycle.php` aggiungi le `use`:

```php
use Throwable;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
```

Dentro la transazione di `cancel()`, il ciclo sui pagamenti diventa:

```php
            // Gli intenti ancora pagabili si annullano dopo, fuori dalla
            // transazione: Stripe non aspetta il nostro commit.
            $intents = [];

            foreach (self::payments($orderId) as $payment) {
                $paymentStatus = (string) $payment['status'];

                if ($paymentStatus === 'pending') {
                    Ledger::fail((int) $payment['id'], $reason !== '' ? $reason : 'Ordine annullato');
                }

                if (in_array($paymentStatus, ['pending', 'failed'], true) && ($intent = self::openIntent($payment)) !== null) {
                    $intents[] = $intent;
                }
            }
```

e il `return` del ramo che annulla prende la chiave in più:

```php
            return [
                'order_id' => $orderId,
                'status' => 'cancelled',
                'released' => $released,
                'restored' => $restored,
                'refundable' => self::money(self::paid($orderId)),
                'changed' => true,
                'intents' => $intents,
            ];
        });

        $intents = $result['intents'] ?? [];
        unset($result['intents']);

        foreach ($intents as [$provider, $reference]) {
            try {
                PaymentProviders::get($provider)?->cancel($reference);
            } catch (Throwable $error) {
                // L'ordine è annullato comunque: se il cliente pagasse lo stesso,
                // il webhook registra l'incasso e avvisa il commerciante.
                Errors::internal($error, 'orders.cancel_intent', ['order' => $orderId, 'reference' => $reference]);
            }
        }
```

(il blocco `if ($result['changed'] && …)` delle email resta com'è, subito dopo). Dopo `cancel()` aggiungi:

```php
    /**
     * Il fornitore e l'intento della riga, se c'è un intento online ancora da
     * chiudere nell'ambiente in cui il fornitore gira adesso.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function openIntent(array $payment): ?array
    {
        $provider = (string) ($payment['provider'] ?? '');
        $reference = (string) ($payment['provider_reference'] ?? '');

        if ($provider === '' || $provider === 'manual' || $reference === '') {
            return null;
        }

        $gateway = PaymentProviders::get($provider);

        if ($gateway === null || (string) ($payment['environment'] ?? 'live') !== $gateway->environment()) {
            return null;
        }

        return [$provider, $reference];
    }
```

- [ ] **Passo 4: lo vedi passare**

Esegui: `php tests/integrazione/OnlinePaymentsTest.php`
Atteso: PASS, tutti i controlli verdi (i 4 del Compito 8 più questi 4).

- [ ] **Passo 5: suite e commit**

Esegui: `php tests/run.php > /tmp/g-11.txt; tail -3 /tmp/g-11.txt`
Atteso: «Tutti i test del gestionale passano.» `ExpiryTest` annulla ordini con righe manuali: nessuna chiamata al fornitore.

```bash
git add src/Support/Orders/Lifecycle.php tests/integrazione/supporto/FakePaymentProvider.php tests/integrazione/OnlinePaymentsTest.php
git commit -m "Ordini: annullare l'ordine annulla l'intento di pagamento

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito 12: riallineamento orario con Stripe

La rete sotto il webhook (spec §9): un'ora dopo, quello che il webhook ha perso lo recupera il giro. La logica sta in `Reconcile`, che prende il fornitore come argomento e si prova col finto; l'attività la chiama con quello vero.

**File:**
- Crea: `src/Support/Payments/Reconcile.php`
- Crea: `src/Scheduler/StripeReconcileTask.php`
- Modifica: `src/Gestionale.php` (`tasks()`, riga 61)
- Crea: `tests/integrazione/ReconcileTest.php`
- Modifica: `tests/TasksTest.php`

**Interfacce:**
- Consuma: `OnlinePayments::succeeded/fail`, `PaymentMismatch`, `PaymentEvents::process` (Compito 8), `PaymentProvider::code/environment/connected/status/replay`, `PaymentState` (Compito 6), `ProviderEvents::receive/find/markFailed/MAX_ATTEMPTS` (Compito 5), `Ledger::open/attach/fail` (Compito 4), `Errors::internal/report`, `FakePaymentProvider`.
- Produce:
  - `Reconcile::run(PaymentProvider $provider, ?string $now = null): array{payments: int, events: int}` (namespace `Wonder\Plugin\Gestionale\Support\Payments`) — quanti pagamenti ha sistemato e quanti eventi ha elaborato. Con il fornitore non collegato non fa nulla.
  - `Reconcile::BACKOFF = [1 => 300, 2 => 1800, 3 => 7200]` — secondi dall'ultimo tentativo, per `attempts`. Con `attempts = MAX_ATTEMPTS` (4) l'evento non si riprova più.
  - `Reconcile::GRACE = 600` — un evento `received` più giovane lo sta ancora elaborando il webhook.
  - `StripeReconcileTask`: chiave `gestionale.stripe_reconcile`, `'0 * * * *'`, nasce spenta, `timeout()` 300.

Regole dei pagamenti: righe `payment` del fornitore, `pending` o `failed` (una carta rifiutata lascia l'intento riusabile, e il secondo tentativo riuscito può perdere il webhook), con riferimento, dell'ambiente attivo, con l'ordine in `pending`. `SUCCEEDED` → `OnlinePayments::succeeded(…, 'cron')`; `CANCELED` → `OnlinePayments::fail` solo se la riga è ancora `pending`; altri stati nulla. Un'incoerenza (`PaymentMismatch`) su una riga `pending` si segnala al commerciante con `Errors::report` e la riga passa a `failed`; sulle righe già `failed` va solo nel log, così la segnalazione parte una volta sola.

- [ ] **Passo 1: test che falliscono**

`tests/integrazione/ReconcileTest.php`: intestazione d'integrazione (commento in testa `/** php tests/integrazione/ReconcileTest.php */`), più `require __DIR__ . '/supporto/FakePaymentProvider.php';` dopo quello di `compra.php`, con queste `use`:

```php
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\System\ProviderEvent;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentEvent;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentState;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
use Wonder\Plugin\Gestionale\Support\Payments\Reconcile;
use Wonder\Plugin\Gestionale\Support\Providers\ProviderEvents;
```

poi:

```php
// Registrato anche se `Reconcile` lo riceve come argomento: `Lifecycle::cancel`
// lo cerca nel registro, e senza il finto chiamerebbe Stripe davvero.
$finto = new FakePaymentProvider();
PaymentProviders::register($finto);

/** Ordine in attesa col pagamento legato all'intento, come dopo il «Paga». */
function intentoAperto(float $totale = 50.0, string $ambiente = 'test'): array
{
    $ordine = ordineDiProva($totale);
    $pagamento = Ledger::open(['order_id' => $ordine, 'amount' => $totale, 'provider' => 'stripe'])['payment_id'];
    $intento = 'pi_ric_'.uniqid();
    Ledger::attach($pagamento, 'stripe', $intento, $ambiente);

    return [$ordine, $pagamento, $intento];
}

/** Quello che `status()` direbbe di un intento riuscito. */
function riuscito(int $ordine, int $centesimi = 5000): PaymentState
{
    return new PaymentState(PaymentState::SUCCEEDED, $centesimi, 'eur', $ordine);
}

/** Un evento normalizzato; `$cambi` sostituisce i campi per nome. */
function evento(string $tipo, string $intento, int $ordine, int $centesimi, array $cambi = []): PaymentEvent
{
    $id = 'evt_'.uniqid();

    return new PaymentEvent(...array_merge([
        'id' => $id,
        'type' => $tipo,
        'environment' => 'test',
        'reference' => $intento,
        'amount' => $centesimi,
        'currency' => 'eur',
        'orderId' => $ordine,
        'refunds' => [],
        'chargeId' => 'ch_prova',
        'payload' => ['id' => $id, 'type' => $tipo],
    ], $cambi));
}

/** L'evento salvato com'era rimasto: stato, tentativi e da quanti secondi. */
function salvato(FakePaymentProvider $finto, PaymentEvent $evento, string $stato, int $tentativi, int $secondiFa): void
{
    ProviderEvents::receive('stripe', $evento->id, $evento->type, $evento->payload, 'test');
    $quando = date('Y-m-d H:i:s', time() - $secondiFa);
    sqlModify(ProviderEvent::$table, [
        'status' => $stato,
        'attempts' => $tentativi,
        'creation' => $quando,
        'last_modified' => $quando,
    ], 'id', (int) ProviderEvents::find('stripe', $evento->id, 'test')['id']);
    $finto->replays[$evento->id] = $evento;
}

function stato(int $ordine): string
{
    return (string) (Order::findById($ordine)['status'] ?? '');
}

function riga(int $pagamento): string
{
    return (string) (Payment::findById($pagamento)['status'] ?? '');
}

function evStato(PaymentEvent $evento): array
{
    $riga = ProviderEvents::find('stripe', $evento->id, 'test') ?? [];

    return [(string) ($riga['status'] ?? ''), (int) ($riga['attempts'] ?? 0)];
}

check('l\'intento riuscito di cui non è arrivato il webhook conferma l\'ordine', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto();
    $finto->states[$intento] = riuscito($ordine);
    Reconcile::run($finto);

    return stato($ordine) === 'confirmed' && riga($pagamento) === 'paid';
}));

check('anche dopo una carta rifiutata: il secondo tentativo riuscito si recupera', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto();
    Ledger::fail($pagamento, 'Carta rifiutata');
    $finto->states[$intento] = riuscito($ordine);
    Reconcile::run($finto);

    return stato($ordine) === 'confirmed';
}));

check('l\'intento annullato chiude la riga in attesa', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto();
    $finto->states[$intento] = new PaymentState(PaymentState::CANCELED, 5000, 'eur', $ordine);
    Reconcile::run($finto);

    return riga($pagamento) === 'failed' && stato($ordine) === 'pending';
}));

check('l\'ordine non più in attesa non si tocca', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto();
    $finto->states[$intento] = riuscito($ordine);
    Lifecycle::cancel($ordine, ['notify' => false]);
    Reconcile::run($finto);

    return stato($ordine) === 'cancelled' && riga($pagamento) === 'failed';
}));

check('l\'altro ambiente non si interroga', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto(50.0, 'live');
    $finto->states[$intento] = riuscito($ordine);
    Reconcile::run($finto);

    return stato($ordine) === 'pending' && riga($pagamento) === 'pending';
}));

check('un importo diverso non conferma, chiude la riga e al giro dopo non si ripete', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = intentoAperto();
    $finto->states[$intento] = riuscito($ordine, 4000);
    Reconcile::run($finto);
    $dopoIlPrimo = riga($pagamento);
    Reconcile::run($finto);

    return $dopoIlPrimo === 'failed' && stato($ordine) === 'pending' && riga($pagamento) === 'failed';
}));

check('un evento ricevuto da più di 10 minuti si rielabora', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto();
    $evento = evento('payment_intent.succeeded', $intento, $ordine, 5000);
    salvato($finto, $evento, 'received', 0, 700);
    Reconcile::run($finto);

    return stato($ordine) === 'confirmed' && evStato($evento)[0] === 'processed';
}));

check('un evento appena ricevuto lo lascia al webhook', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto();
    $evento = evento('payment_intent.succeeded', $intento, $ordine, 5000);
    salvato($finto, $evento, 'received', 0, 60);
    Reconcile::run($finto);

    return stato($ordine) === 'pending' && evStato($evento)[0] === 'received';
}));

check('un evento fallito aspetta il suo turno: 5 minuti dopo il primo tentativo', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto();
    $presto = evento('payment_intent.succeeded', $intento, $ordine, 5000);
    salvato($finto, $presto, 'failed', 1, 240);
    Reconcile::run($finto);
    $primo = stato($ordine);

    sqlModify(ProviderEvent::$table, ['last_modified' => date('Y-m-d H:i:s', time() - 360)], 'id', (int) ProviderEvents::find('stripe', $presto->id, 'test')['id']);
    Reconcile::run($finto);

    return $primo === 'pending' && stato($ordine) === 'confirmed';
}));

check('al quarto tentativo l\'evento non si riprova più', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto();
    $evento = evento('payment_intent.succeeded', $intento, $ordine, 5000);
    salvato($finto, $evento, 'failed', ProviderEvents::MAX_ATTEMPTS, 86400);
    Reconcile::run($finto);

    return stato($ordine) === 'pending' && evStato($evento) === ['failed', ProviderEvents::MAX_ATTEMPTS];
}));

check('un payload che non si rilegge finisce in «Da controllare»', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto();
    $evento = evento('payment_intent.succeeded', $intento, $ordine, 5000);
    salvato($finto, $evento, 'received', 0, 700);
    unset($finto->replays[$evento->id]);
    Reconcile::run($finto);

    return evStato($evento) === ['failed', ProviderEvents::MAX_ATTEMPTS];
}));

check('con il fornitore non collegato non fa nulla', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = intentoAperto();
    $finto->states[$intento] = riuscito($ordine);
    $finto->connected = false;

    try {
        $esito = Reconcile::run($finto);
    } finally {
        $finto->connected = true;
    }

    return $esito === ['payments' => 0, 'events' => 0] && stato($ordine) === 'pending';
}));

summary();
```

In `tests/TasksTest.php` aggiungi `use Wonder\Plugin\Gestionale\Scheduler\StripeReconcileTask;`, nel controllo `'le attività hanno chiavi diverse'` cambia `count($chiavi) === 3` in `count($chiavi) === 4`, e prima di `summary();`:

```php
check('il riallineamento con Stripe è un\'attività del modulo, oraria e spenta', function () {
    $trovata = null;

    foreach (Gestionale::tasks() as $task) {
        if ($task instanceof StripeReconcileTask) {
            $trovata = $task;
        }
    }

    return $trovata instanceof TaskInterface
        && $trovata->key() === 'gestionale.stripe_reconcile'
        && $trovata->expression() === '0 * * * *'
        && $trovata->enabled() === false
        && $trovata->timeout() > 0 && $trovata->timeout() <= 3600;
});
```

- [ ] **Passo 2: lo vedi fallire**

Esegui: `php tests/integrazione/ReconcileTest.php; php tests/TasksTest.php`
Atteso: il primo muore con `Class "Wonder\Plugin\Gestionale\Support\Payments\Reconcile" not found`; il secondo con `StripeReconcileTask` non trovata.

- [ ] **Passo 3: implementazione**

`src/Support/Payments/Reconcile.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\System\ProviderEvent;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentProvider;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentState;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Providers\ProviderEvents;

/**
 * La rete sotto il webhook: ogni ora chiede al fornitore come sono finiti i
 * pagamenti ancora aperti e rielabora gli eventi rimasti indietro.
 *
 * Interroga solo l'ambiente in cui il fornitore gira adesso. Un errore su un
 * pagamento o su un evento finisce nel log e non ferma gli altri.
 */
final class Reconcile
{
    /** Secondi dall'ultimo tentativo prima di riprovare, per tentativi già fatti. */
    public const BACKOFF = [1 => 300, 2 => 1800, 3 => 7200];

    /** Un evento ricevuto da meno di così lo sta ancora elaborando il webhook. */
    public const GRACE = 600;

    /** @return array{payments: int, events: int} */
    public static function run(PaymentProvider $provider, ?string $now = null): array
    {
        if (!$provider->connected()) {
            return ['payments' => 0, 'events' => 0];
        }

        $now ??= date('Y-m-d H:i:s');

        return [
            'payments' => self::payments($provider),
            'events' => self::events($provider, $now),
        ];
    }

    private static function payments(PaymentProvider $provider): int
    {
        $code = $provider->code();
        $done = 0;
        $rows = Payment::find(
            "provider = '".addslashes($code)."' AND type = 'payment' AND status IN ('pending', 'failed')"
            ." AND provider_reference <> '' AND environment = '".addslashes($provider->environment())."'"
            ." AND deleted = 'false'"
        );

        foreach (self::rows($rows) as $payment) {
            $orderId = (int) $payment['order_id'];
            $reference = (string) $payment['provider_reference'];
            $pending = (string) $payment['status'] === 'pending';
            $context = ['order' => $orderId, 'reference' => $reference];

            if ((string) (Order::findById($orderId)['status'] ?? '') !== 'pending') {
                continue;
            }

            try {
                $state = $provider->status($reference);

                if ($state->status === PaymentState::SUCCEEDED) {
                    OnlinePayments::succeeded($code, $reference, $state->amount, $state->currency, $state->orderId, 'cron');
                    ++$done;
                } elseif ($state->status === PaymentState::CANCELED && $pending) {
                    OnlinePayments::fail($code, $reference, 'Pagamento annullato');
                    ++$done;
                }
            } catch (PaymentMismatch $error) {
                if ($pending) {
                    // Una volta sola: la riga passa a «fallito» e dal giro dopo
                    // l'incoerenza va solo nel log.
                    Errors::report($code, 'payment.mismatch', $error, $context);
                    Ledger::fail((int) $payment['id'], 'Importo o ordine diversi da quelli di '.$code);
                } else {
                    Errors::internal($error, 'payments.reconcile', $context);
                }
            } catch (Throwable $error) {
                Errors::internal($error, 'payments.reconcile', $context);
            }
        }

        return $done;
    }

    private static function events(PaymentProvider $provider, string $now): int
    {
        $code = $provider->code();
        $environment = $provider->environment();
        $received = date('Y-m-d H:i:s', strtotime($now) - self::GRACE);
        $done = 0;
        $rows = ProviderEvent::find(
            "provider = '".addslashes($code)."' AND environment = '".addslashes($environment)."' AND deleted = 'false'"
            ." AND ((status = 'received' AND creation <= '".addslashes($received)."')"
            ." OR (status = 'failed' AND attempts < ".ProviderEvents::MAX_ATTEMPTS.'))'
        );

        foreach (self::rows($rows) as $row) {
            $eventId = (string) $row['event_id'];

            if ((string) $row['status'] === 'failed' && !self::due($row, $now)) {
                continue;
            }

            try {
                $payload = json_decode((string) $row['payload'], true);
                $event = is_array($payload) ? $provider->replay($payload, $environment) : null;

                if ($event === null) {
                    // Il payload ha già passato la firma: se non si rilegge,
                    // riprovare non serve.
                    ProviderEvents::markFailed($code, $eventId, 'Evento non rileggibile', $environment, true);

                    continue;
                }

                if (PaymentEvents::process($provider, $event, 'cron')) {
                    ++$done;
                }
            } catch (Throwable $error) {
                Errors::internal($error, 'payments.reconcile', ['event' => $eventId]);
            }
        }

        return $done;
    }

    /** Se è passato abbastanza dall'ultimo tentativo dell'evento fallito. */
    private static function due(array $row, string $now): bool
    {
        $wait = self::BACKOFF[(int) ($row['attempts'] ?? 0)] ?? null;

        return $wait !== null && strtotime((string) $row['last_modified']) + $wait <= strtotime($now);
    }

    /** @return list<array> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
```

`src/Scheduler/StripeReconcileTask.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Scheduler;

use Wonder\App\Scheduler\AbstractTask;
use Wonder\App\Scheduler\Context;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
use Wonder\Plugin\Gestionale\Support\Payments\Reconcile;

/**
 * Una volta all'ora recupera da Stripe quello che il webhook ha perso:
 * pagamenti riusciti o annullati ed eventi rimasti indietro.
 *
 * Nasce **spenta**, come le altre: si accende insieme a `ExpiryTask` quando il
 * negozio comincia a incassare con la carta.
 */
final class StripeReconcileTask extends AbstractTask
{
    public function key(): string
    {
        return 'gestionale.stripe_reconcile';
    }

    public function label(): string
    {
        return 'Gestionale: riallineamento con Stripe';
    }

    public function expression(): string
    {
        return '0 * * * *';
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
        $provider = PaymentProviders::get('stripe');

        return $provider === null ? ['payments' => 0, 'events' => 0] : Reconcile::run($provider);
    }
}
```

In `src/Gestionale.php` aggiungi `use Wonder\Plugin\Gestionale\Scheduler\StripeReconcileTask;` e `tasks()` diventa:

```php
        return [new ImagesTask(), new StockAlertsTask(), new ExpiryTask(), new StripeReconcileTask()];
```

- [ ] **Passo 4: lo vedi passare**

Esegui: `php tests/integrazione/ReconcileTest.php && php tests/TasksTest.php`
Atteso: PASS, 12 controlli verdi nel primo e tutti verdi nel secondo.

In produzione `creation` e `last_modified` li scrive il database (`CURRENT_TIMESTAMP`), mentre `$now` è di PHP. Confronta `SELECT NOW()` con `date('Y-m-d H:i:s')` nel sito di prova: se i fusi differiscono, calcola i limiti con l'ora del database (`sqlSelect` di `NOW()`) invece che con `date()`, e annota il ruling.

- [ ] **Passo 5: suite e commit**

Esegui: `php tests/run.php > /tmp/g-12.txt; tail -3 /tmp/g-12.txt`
Atteso: «Tutti i test del gestionale passano.»

```bash
git add src/Support/Payments/Reconcile.php src/Scheduler/StripeReconcileTask.php src/Gestionale.php tests/integrazione/ReconcileTest.php tests/TasksTest.php
git commit -m "Pagamenti: riallineamento orario con Stripe

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito 13: badge «Prova» sugli ordini pagati in modalità test

Un ordine pagato con le chiavi di test non ha portato soldi veri. Nell'elenco e nella scheda il badge del pagamento ne porta accanto un secondo, «Prova», così nessuno lo spedisce per sbaglio.

**File:**
- Modifica: `src/Support/Payments/OnlinePayments.php` (un metodo pubblico)
- Modifica: `src/Resources/Sales/OrderResource.php` (formatter di `payment_status` righe 111–114, `headerItems()` riga 508, un metodo pubblico nuovo)
- Test: `tests/OrderResourceTest.php` (unitario), `tests/integrazione/OnlinePaymentsTest.php`

**Interfacce:**
- Consuma: la colonna `environment` di `gst_payments` (Compito 4), `StatusLabels::badge()`.
- Produce:
  - `OnlinePayments::inTest(int $orderId): bool` — l'ordine ha almeno una riga di pagamento in ambiente `test`. Con `$orderId <= 0` risponde `false` senza interrogare il database.
  - `OrderResource::paymentBadge(string $status, bool $test): string` — il badge dello stato, più `<span class="badge bg-warning text-dark ms-1">Prova</span>` se `$test`.

- [ ] **Passo 1: test che falliscono**

In `tests/OrderResourceTest.php`, prima di `summary();`:

```php
check('il pagamento in prova porta il badge «Prova» accanto allo stato', fn () =>
    str_contains(OrderResource::paymentBadge('paid', true), 'Pagato')
    && str_contains(OrderResource::paymentBadge('paid', true), '>Prova</span>')
    && !str_contains(OrderResource::paymentBadge('paid', false), 'Prova')
);
```

In coda a `tests/integrazione/OnlinePaymentsTest.php`, prima di `summary();`:

```php
check('inTest riconosce l\'ordine pagato con le chiavi di prova', fn () => prova(static function (): bool {
    [$prova] = ordineStripe();
    OnlinePayments::start($prova);
    $vero = ordineDiProva(20.0);
    Ledger::open(['order_id' => $vero, 'amount' => 20.0, 'provider' => 'manual']);

    return OnlinePayments::inTest($prova) && !OnlinePayments::inTest($vero) && !OnlinePayments::inTest(0);
}));
```

- [ ] **Passo 2: lo vedi fallire**

Esegui: `php tests/OrderResourceTest.php; php tests/integrazione/OnlinePaymentsTest.php`
Atteso: il primo muore con `Call to undefined method …OrderResource::paymentBadge()`; il secondo con `Call to undefined method …OnlinePayments::inTest()`.

- [ ] **Passo 3: implementazione**

In `src/Support/Payments/OnlinePayments.php`:

```php
    /** Se l'ordine ha un pagamento fatto con le chiavi di prova: soldi non veri. */
    public static function inTest(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        $row = Payment::find(['order_id' => $orderId, 'environment' => 'test', 'deleted' => 'false'], 1);

        return is_array($row) && $row !== [];
    }
```

In `src/Resources/Sales/OrderResource.php` (con `use Wonder\Plugin\Gestionale\Support\Payments\OnlinePayments;`):

```php
    /** Il badge dello stato del pagamento, con «Prova» se i soldi non sono veri. */
    public static function paymentBadge(string $status, bool $test): string
    {
        return StatusLabels::badge('payment', $status)
            .($test ? '<span class="badge bg-warning text-dark ms-1">Prova</span>' : '');
    }
```

Il formatter della colonna `payment_status` diventa:

```php
                ->formatter(static fn (array $row): string => static::paymentBadge(
                    (string) ($row['payment_status'] ?? ''),
                    OnlinePayments::inTest((int) ($row['id'] ?? 0))
                )),
```

e in `headerItems()`:

```php
            $dato('Pagamento', static::paymentBadge((string) ($order['payment_status'] ?? ''), OnlinePayments::inTest((int) ($order['id'] ?? 0))), true),
```

- [ ] **Passo 4: lo vedi passare**

Esegui: `php tests/OrderResourceTest.php && php tests/integrazione/OnlinePaymentsTest.php`
Atteso: PASS, tutti i controlli verdi. Il controllo «le celle si disegnano davvero» passa ancora: la riga non ha `id` e `inTest(0)` non interroga il database.

- [ ] **Passo 5: suite e commit**

Esegui: `php tests/run.php > /tmp/g-13.txt; tail -3 /tmp/g-13.txt`
Atteso: «Tutti i test del gestionale passano.»

```bash
git add src/Support/Payments/OnlinePayments.php src/Resources/Sales/OrderResource.php tests/OrderResourceTest.php tests/integrazione/OnlinePaymentsTest.php
git commit -m "Ordini: badge «Prova» sui pagamenti fatti con le chiavi di test

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Parte C — ecommerce

Cartella di lavoro: `/Users/andreamarinoni/Developer/worktrees/pagamento-stripe/ecommerce`. Suite: `php tests/run.php` (raccoglie `tests/*Test.php` e `tests/integrazione/*Test.php`), atteso in fondo «Tutti i test dell'ecommerce passano.». I test d'integrazione girano sul sito di prova, che dopo P3 punta ai tre worktree; per i dati di prova riusano i file di supporto del gestionale attraverso `SITE.'/vendor/wonder-image/gestionale/…'`.

Forma dei test d'integrazione nuovi dell'ecommerce, chiamata nel piano **«intestazione dell'ecommerce»** e scritta per intero così:

```php
<?php
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require SITE.'/vendor/wonder-image/gestionale/tests/integrazione/supporto/compra.php';
require SITE.'/vendor/wonder-image/gestionale/tests/integrazione/supporto/FakePaymentProvider.php';

use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
        // Voluto: i dati della prova non restano.
    }

    return $esito;
}
```

Le `use` delle classi del compito vanno subito dopo `use Wonder\Sql\Transaction;`.

Il giro del pagamento online visto dal negozio, in breve:

1. «Paga» sul checkout: il JavaScript chiama `place` in JSON. Nasce l'ordine (il carrello diventa l'ordine), l'id dell'ordine resta in sessione (`OnlinePayment::SESSION`), il server crea l'intento e restituisce il `client_secret`.
2. Il browser chiama `stripe.confirmPayment` e Stripe rimanda a `/checkout/return/?payment_intent=…`.
3. Il ritorno chiede a Stripe com'è andata (`OnlinePayment::settle`): riuscito o in lavorazione → pagina «completato»; rifiutato o annullato → pagina di pagamento `/checkout/pay/`, che riprova sullo stesso ordine.
4. Un nuovo `place` o «Annulla l'ordine» lasciano cadere l'ordine in sospeso: si annulla, salvo che il denaro sia già arrivato o in arrivo.

### Compito 14: `OnlinePayment`, ritorno, pagina di pagamento e abbandono

**File:**
- Crea: `src/Frontend/Checkout/OnlinePayment.php`
- Crea: `view/pages/checkout/pay.php`
- Crea: `tests/integrazione/OnlinePaymentTest.php`, `tests/integrazione/CheckoutPayHttpTest.php`
- Modifica: `src/Frontend/Checkout/CheckoutController.php` (`handle`, `index`, metodi nuovi `returned`, `pay`, `abandon`)
- Modifica: `config/routes/route.frontend.php`
- Modifica: `view/pages/checkout/completed.php`
- Modifica: `lang/it/ecommerce.json`, `lang/en/ecommerce.json`

**Interfacce:**
- Consuma (gestionale, Compiti 6, 8, 11):
  - `OnlinePayments::payment(int $orderId): ?array`, `OnlinePayments::start(int $orderId): PaymentStart` (`->clientSecret`), `OnlinePayments::succeeded(string $provider, string $reference, int $amount, string $currency, int $orderId, string $source): array` (lancia `PaymentMismatch`; su ordine annullato `['status' => 'cancelled', 'changed' => false]`).
  - `PaymentProviders::get(string $code): ?PaymentProvider`, `PaymentProvider::status(string $reference): PaymentState`, `PaymentProvider::code(): string`.
  - `PaymentState` con `status`, `amount`, `currency`, `orderId` e le costanti `SUCCEEDED`, `PROCESSING`, `REQUIRES_PAYMENT_METHOD`, `CANCELED`, `OTHER`.
  - `Lifecycle::cancel(int $orderId, array $options = []): array` — annulla anche l'intento aperto.
  - `Credentials::api()` (app, Compito 1) con `stripe_publishable_key` e `stripe_id`.
  - Test: `ordineDiProva(float $totale = 100.0): int` da `compra.php`; `FakePaymentProvider` con `$states`, `$cancelled`, `start` che dà `pi_finto_N` e `pi_finto_N_secret_prova`.
- Produce (per i Compiti 15 e 17):
  - `OnlinePayment::SESSION = 'ecommerce_checkout_pending'`, `OnlinePayment::RECEIPT = 'ecommerce_checkout_receipt'`.
  - `OnlinePayment::sessionOrder(): int`, `pending(): int`, `remember(int $orderId): void`, `forget(): void`.
  - `OnlinePayment::browserKeys(): array{publishable_key: string, account: string}`.
  - `OnlinePayment::sameTotal(mixed $seen, mixed $actual): bool` — confronto in centesimi.
  - `OnlinePayment::start(int $orderId): array{client_secret: string}` — ricorda l'ordine in sessione.
  - `OnlinePayment::settle(int $orderId, string $reference): string` — `'succeeded'`, `'processing'`, `'retry'` o `'canceled'`; `OutOfBoundsException` se il riferimento non è dell'ordine.
  - `OnlinePayment::dropPending(): void` — annulla l'ordine in sospeso della sessione, salvo denaro arrivato o in arrivo.
  - Rotte `ecommerce.checkout.return` (GET `/checkout/return/`), `ecommerce.checkout.pay` (GET `/checkout/pay/`), `ecommerce.checkout.abandon` (POST `/checkout/abandon/`).
  - Chiavi di lingua `checkout.pay.{title,text,submit,abandon,abandoned,failed,canceled,error}` e `checkout.completed.processing`.

- [ ] **Passo 1: il test di `OnlinePayment` che fallisce**

`tests/integrazione/OnlinePaymentTest.php`: intestazione dell'ecommerce (commento in testa `/** php tests/integrazione/OnlinePaymentTest.php */`), con queste `use`:

```php
use Wonder\Plugin\Ecommerce\Frontend\Checkout\OnlinePayment;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentState;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;
```

poi:

```php
$_SESSION = [];
$finto = new FakePaymentProvider();
PaymentProviders::register($finto);

/** L'ordine come lo lascia `Checkout::place` con un metodo Stripe, già avviato dal negozio. */
function avviato(float $totale = 50.0): array
{
    $_SESSION = [];
    $ordine = ordineDiProva($totale);
    $pagamento = Ledger::open(['order_id' => $ordine, 'amount' => $totale, 'provider' => 'stripe'])['payment_id'];
    OnlinePayment::start($ordine);

    return [$ordine, $pagamento, (string) (Payment::findById($pagamento)['provider_reference'] ?? '')];
}

check('sameTotal confronta i centesimi', fn () => OnlinePayment::sameTotal('10', 10.00)
    && OnlinePayment::sameTotal(19.9, '19.90')
    && !OnlinePayment::sameTotal('10.01', 10)
    && !OnlinePayment::sameTotal('', 10)
    && !OnlinePayment::sameTotal(null, 10));

check('start ricorda l\'ordine in sessione e dà il segreto dell\'intento', fn () => prova(static function (): bool {
    [$ordine, , $intento] = avviato();

    return $intento !== ''
        && OnlinePayment::start($ordine)['client_secret'] !== ''
        && OnlinePayment::sessionOrder() === $ordine
        && OnlinePayment::pending() === $ordine;
}));

check('settle con l\'intento riuscito registra l\'incasso e conferma l\'ordine', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = avviato(50.0);
    $finto->states[$intento] = new PaymentState(PaymentState::SUCCEEDED, 5000, 'eur', $ordine);

    return OnlinePayment::settle($ordine, $intento) === 'succeeded'
        && (Payment::findById($pagamento)['status'] ?? '') === 'paid'
        && (Order::findById($ordine)['status'] ?? '') !== 'pending';
}));

check('settle sulla riga già pagata non chiede di nuovo al fornitore', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = avviato(50.0);
    $finto->states[$intento] = new PaymentState(PaymentState::SUCCEEDED, 5000, 'eur', $ordine);
    OnlinePayment::settle($ordine, $intento);
    unset($finto->states[$intento]);

    return OnlinePayment::settle($ordine, $intento) === 'succeeded';
}));

check('settle distingue in lavorazione, da riprovare e annullato', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = avviato();
    $esiti = [];

    foreach ([PaymentState::PROCESSING, PaymentState::REQUIRES_PAYMENT_METHOD, PaymentState::CANCELED, PaymentState::OTHER] as $stato) {
        $finto->states[$intento] = new PaymentState($stato);
        $esiti[] = OnlinePayment::settle($ordine, $intento);
    }

    return $esiti === ['processing', 'retry', 'canceled', 'retry']
        && (Order::findById($ordine)['status'] ?? '') === 'pending';
}));

check('settle rifiuta un intento che non è dell\'ordine', fn () => prova(static function (): bool {
    [$ordine] = avviato();

    try {
        OnlinePayment::settle($ordine, 'pi_estraneo');
    } catch (OutOfBoundsException) {
        return true;
    }

    return false;
}));

check('pending dimentica un ordine che non è più in attesa', fn () => prova(static function (): bool {
    [$ordine] = avviato();
    Lifecycle::cancel($ordine, ['notify' => false]);

    return OnlinePayment::pending() === 0 && OnlinePayment::sessionOrder() === 0;
}));

check('dropPending annulla l\'ordine in sospeso e il suo intento', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = avviato();
    OnlinePayment::dropPending();

    return (Order::findById($ordine)['status'] ?? '') === 'cancelled'
        && in_array($intento, $finto->cancelled, true)
        && OnlinePayment::sessionOrder() === 0;
}));

check('dropPending lascia stare un pagamento in lavorazione', fn () => prova(static function () use ($finto): bool {
    [$ordine, , $intento] = avviato();
    $finto->states[$intento] = new PaymentState(PaymentState::PROCESSING);
    $annullati = count($finto->cancelled);
    OnlinePayment::dropPending();

    return (Order::findById($ordine)['status'] ?? '') === 'pending'
        && count($finto->cancelled) === $annullati
        && OnlinePayment::sessionOrder() === 0;
}));

check('dropPending conferma invece un pagamento già riuscito', fn () => prova(static function () use ($finto): bool {
    [$ordine, $pagamento, $intento] = avviato(50.0);
    $finto->states[$intento] = new PaymentState(PaymentState::SUCCEEDED, 5000, 'eur', $ordine);
    OnlinePayment::dropPending();

    return (Payment::findById($pagamento)['status'] ?? '') === 'paid'
        && !in_array((string) (Order::findById($ordine)['status'] ?? ''), ['pending', 'cancelled'], true);
}));

PaymentProviders::reset();
summary();
```

- [ ] **Passo 2: lo vedi fallire**

Esegui: `php tests/integrazione/OnlinePaymentTest.php`
Atteso: FAIL con `Class "Wonder\Plugin\Ecommerce\Frontend\Checkout\OnlinePayment" not found`.

- [ ] **Passo 3: `OnlinePayment`**

`src/Frontend/Checkout/OnlinePayment.php`:

```php
<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use OutOfBoundsException;
use Throwable;
use Wonder\App\Credentials;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentState;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Payments\OnlinePayments;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;

/**
 * Il pagamento online visto dal negozio: l'ordine in sospeso della sessione,
 * l'avvio dell'intento e l'esito al ritorno dal gateway. Niente risposte HTTP
 * qui dentro: le dà il controller.
 *
 * L'ordine nasce a «Paga» e il carrello diventa l'ordine; finché il denaro non
 * arriva, l'id resta in sessione per riprovare dalla pagina di pagamento.
 */
final class OnlinePayment
{
    public const SESSION = 'ecommerce_checkout_pending';
    public const RECEIPT = 'ecommerce_checkout_receipt';

    /** L'ordine ricordato in sessione, senza guardare in che stato è. */
    public static function sessionOrder(): int
    {
        return (int) ($_SESSION[self::SESSION] ?? 0);
    }

    /** L'ordine in sessione se è ancora da pagare; altrimenti lo dimentica. */
    public static function pending(): int
    {
        $id = self::sessionOrder();
        if ($id <= 0) {
            return 0;
        }

        $order = Order::findById($id);
        if (is_array($order) && ($order['stage'] ?? '') === 'order' && ($order['status'] ?? '') === 'pending') {
            return $id;
        }

        self::forget();

        return 0;
    }

    public static function remember(int $orderId): void
    {
        $_SESSION[self::SESSION] = $orderId;
    }

    public static function forget(): void
    {
        unset($_SESSION[self::SESSION]);
    }

    /**
     * Le sole cose di Stripe che arrivano al browser.
     *
     * @return array{publishable_key: string, account: string}
     */
    public static function browserKeys(): array
    {
        $api = Credentials::api();

        return [
            'publishable_key' => (string) ($api->stripe_publishable_key ?? ''),
            'account' => (string) ($api->stripe_id ?? ''),
        ];
    }

    /** Il totale visto dal cliente è quello dell'ordine, al centesimo. */
    public static function sameTotal(mixed $seen, mixed $actual): bool
    {
        if (!is_numeric($seen) || !is_numeric($actual)) {
            return false;
        }

        return (int) round((float) $seen * 100) === (int) round((float) $actual * 100);
    }

    /**
     * Crea o riusa l'intento dell'ordine e lo ricorda in sessione.
     *
     * @return array{client_secret: string}
     */
    public static function start(int $orderId): array
    {
        self::remember($orderId);

        return ['client_secret' => OnlinePayments::start($orderId)->clientSecret];
    }

    /**
     * Com'è andata, chiesto al gateway e non al browser: `succeeded`
     * (incasso registrato), `processing` (arriverà col webhook), `retry`
     * (rifiutato, si riprova) o `canceled`.
     */
    public static function settle(int $orderId, string $reference): string
    {
        $payment = OnlinePayments::payment($orderId);
        if ($payment === null || $reference === '' || (string) ($payment['provider_reference'] ?? '') !== $reference) {
            throw new OutOfBoundsException("Il pagamento {$reference} non è dell'ordine {$orderId}.");
        }

        if ((string) $payment['status'] === 'paid') {
            return 'succeeded';
        }

        $provider = PaymentProviders::get((string) $payment['provider']);
        if ($provider === null) {
            return 'processing';
        }

        try {
            $state = $provider->status($reference);
        } catch (Throwable $error) {
            // Il webhook e il riallineamento chiudono il giro anche senza di noi.
            Errors::internal($error, 'ecommerce.checkout.return', ['order_id' => $orderId]);

            return 'processing';
        }

        return match ($state->status) {
            PaymentState::SUCCEEDED => self::confirm($provider->code(), $reference, $state),
            PaymentState::PROCESSING => 'processing',
            PaymentState::CANCELED => 'canceled',
            default => 'retry',
        };
    }

    /**
     * Lascia cadere l'ordine in sospeso della sessione: si annulla, intento
     * compreso, salvo che il denaro sia già arrivato o in arrivo.
     */
    public static function dropPending(): void
    {
        $id = self::pending();
        self::forget();
        if ($id <= 0) {
            return;
        }

        $reference = (string) (OnlinePayments::payment($id)['provider_reference'] ?? '');
        if ($reference !== '' && in_array(self::settle($id, $reference), ['succeeded', 'processing'], true)) {
            return;
        }

        Lifecycle::cancel($id, ['reason' => 'Abbandonato dal cliente nel checkout', 'source' => 'ecommerce', 'notify' => false]);
    }

    private static function confirm(string $provider, string $reference, PaymentState $state): string
    {
        try {
            $result = OnlinePayments::succeeded($provider, $reference, $state->amount, $state->currency, $state->orderId, 'ecommerce');
        } catch (Throwable $error) {
            // Anche `PaymentMismatch`: il commerciante è già avvisato e il cliente non vede un esito falso.
            Errors::internal($error, 'ecommerce.checkout.return');

            return 'processing';
        }

        return ($result['status'] ?? '') === 'cancelled' ? 'processing' : 'succeeded';
    }
}
```

- [ ] **Passo 4: lo vedi passare**

Esegui: `php tests/integrazione/OnlinePaymentTest.php`
Atteso: PASS, tutti i controlli verdi.

- [ ] **Passo 5: il test HTTP delle tre rotte che fallisce**

`tests/integrazione/CheckoutPayHttpTest.php` (stessa forma di `AuthHttpTest.php`, con le intestazioni della risposta e quelle della richiesta):

```php
<?php
/** php tests/integrazione/CheckoutPayHttpTest.php */
declare(strict_types=1);
require __DIR__.'/../harness.php';

$cookie = '';
/** @return array{0: int, 1: string, 2: string} stato, intestazioni, corpo */
$request = static function (string $path, ?array $post = null, array $headers = []) use (&$cookie): array {
    $curl = curl_init('https://ecommerce.test'.$path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_HTTPHEADER => $headers]);
    if ($cookie !== '') { curl_setopt($curl, CURLOPT_COOKIE, $cookie); }
    if ($post !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $response = curl_exec($curl);
    if (!is_string($response)) { throw new RuntimeException(curl_error($curl)); }
    $headerLength = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $head = substr($response, 0, $headerLength);
    if (preg_match('/Set-Cookie:\s*(PHPSESSID=[^;\r\n]+)/i', $head, $match)) {
        $cookie = $match[1];
    }
    unset($curl);
    return [$status, $head, substr($response, $headerLength)];
};

[$status] = $request('/checkout/return/?payment_intent=pi_estraneo');
check('il ritorno senza un pagamento in sessione è un 404', fn () => $status === 404);

[$status, $head] = $request('/checkout/pay/');
check('la pagina di pagamento senza ordine in sessione rimanda al carrello', fn () =>
    $status === 302 && preg_match('#^Location:\s*\S*/cart/\s*$#mi', $head) === 1);

[$status] = $request('/checkout/abandon/', ['csrf_token' => 'invalid']);
check('l\'abbandono con CSRF invalido viene rifiutato', fn () => $status === 419);

summary();
```

- [ ] **Passo 6: lo vedi fallire**

Esegui: `php tests/integrazione/CheckoutPayHttpTest.php`
Atteso: FAIL sui tre controlli (le rotte non esistono: il sito risponde con la sua pagina 404 anche a `/checkout/pay/` e al POST).

- [ ] **Passo 7: le rotte**

In `config/routes/route.frontend.php`, subito dopo la riga della rotta `completed`:

```php
                Route::get('/completed/', $handler, ['checkout_action' => 'completed'])->name('completed');
```

aggiungi:

```php
                Route::get('/return/', $handler, ['checkout_action' => 'return'])->name('return');
                Route::get('/pay/', $handler, ['checkout_action' => 'pay'])->name('pay');
                Route::post('/abandon/', $handler, ['checkout_action' => 'abandon'])->name('abandon');
```

- [ ] **Passo 8: il controller**

In `src/Frontend/Checkout/CheckoutController.php`, nel `match` di `handle()`, dopo `'completed' => self::completed(),`:

```php
            'return' => self::returned(),
            'pay' => self::pay(),
            'abandon' => self::abandon(),
```

In `index()` sostituisci:

```php
        $cart = CartSession::current(false);
        if ((array) ($cart['items'] ?? []) === []) {
            self::redirect(self::route('ecommerce.cart.index'));
        }
```

con:

```php
        $cart = CartSession::current(false);
        if ((array) ($cart['items'] ?? []) === []) {
            // Il carrello è diventato l'ordine: si torna a pagarlo.
            self::redirect(self::route(OnlinePayment::pending() > 0 ? 'ecommerce.checkout.pay' : 'ecommerce.cart.index'));
        }
```

Subito dopo il metodo `completed()` aggiungi:

```php
    /**
     * Il ritorno dal gateway. L'esito si chiede a Stripe e vale solo per
     * l'ordine in sessione: un `payment_intent` estraneo è un 404.
     */
    private static function returned(): void
    {
        $orderId = OnlinePayment::sessionOrder();
        if ($orderId <= 0) {
            self::notFound();
        }

        try {
            $outcome = OnlinePayment::settle($orderId, (string) ($_GET['payment_intent'] ?? ''));
        } catch (OutOfBoundsException) {
            self::notFound();
        }

        if ($outcome === 'retry' || $outcome === 'canceled') {
            FlashMessage::error((string) __t('ecommerce.checkout.pay.'.($outcome === 'retry' ? 'failed' : 'canceled')), (string) __t('ecommerce.checkout.error_title'));
            self::redirect(self::route('ecommerce.checkout.pay'));
        }

        OnlinePayment::forget();
        $receipt = (array) ($_SESSION[OnlinePayment::RECEIPT] ?? []);
        unset($_SESSION[OnlinePayment::RECEIPT]);
        if ((int) ($receipt['order_id'] ?? 0) !== $orderId) {
            $order = (array) Order::findById($orderId);
            $receipt = [
                'order_id' => $orderId,
                'order_number' => (string) ($order['order_number'] ?? $order['code'] ?? ''),
                'total' => (string) ($order['total'] ?? ''),
                'guest' => !CartSession::authenticated(),
            ];
        }

        // L'email di conferma parte con l'incasso; in lavorazione arriverà più tardi.
        $_SESSION[self::COMPLETED] = [
            'processing' => $outcome === 'processing',
            'email_sent' => !empty($receipt['guest']) && $outcome === 'succeeded',
        ] + $receipt;
        self::redirect(self::route('ecommerce.checkout.completed'));
    }

    /** La pagina per pagare l'ordine in sospeso della sessione, anche dopo un rifiuto. */
    private static function pay(): void
    {
        $orderId = OnlinePayment::pending();
        if ($orderId === 0) {
            self::redirect(self::route('ecommerce.cart.index'));
        }

        try {
            $clientSecret = OnlinePayment::start($orderId)['client_secret'];
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.pay', ['order_id' => $orderId]);
            $clientSecret = '';
        }

        self::seo((string) __t('ecommerce.checkout.pay.title'), self::route('ecommerce.checkout.pay'));
        View::make(Ecommerce::viewPath('pages/checkout/pay.php'), [
            'order' => (array) Order::findById($orderId),
            'client_secret' => $clientSecret,
            'stripe' => OnlinePayment::browserKeys(),
            'return_url' => self::route('ecommerce.checkout.return'),
            'csrf_token' => AuthSession::csrfToken(),
        ])->render();
    }

    /** «Annulla l'ordine» dalla pagina di pagamento. */
    private static function abandon(): never
    {
        self::requirePost();
        self::requireCsrf();

        try {
            OnlinePayment::dropPending();
        } catch (Throwable $error) {
            // Senza annullo l'ordine scade da solo con le prenotazioni.
            Errors::internal($error, 'ecommerce.checkout.abandon');
        }

        FlashMessage::info((string) __t('ecommerce.checkout.pay.abandoned'), (string) __t('ecommerce.checkout.notice_title'));
        self::redirect(self::route('ecommerce.cart.index'));
    }
```

e in testa al file, tra le `use`, dopo `use RuntimeException;`:

```php
use OutOfBoundsException;
```

(`OutOfBoundsException` va in ordine alfabetico prima di `RuntimeException`: mettila sopra.)

`return_url` è relativo: Stripe vuole un indirizzo assoluto, e lo fa assoluto il JavaScript del Compito 17 con `new URL(returnUrl, location.href)`.

- [ ] **Passo 9: la pagina di pagamento e il ritorno «in lavorazione»**

`view/pages/checkout/pay.php`:

```php
<?php

use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\View\View;

Ecommerce::layout('checkout');

$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
$labels = [
    'failed' => (string) __t('ecommerce.checkout.pay.failed'),
    'error' => (string) __t('ecommerce.checkout.pay.error'),
];
?>
<div class="w-100 wi-box p-6">
    <h1 class="title"><?=e(__t('ecommerce.checkout.pay.title'))?></h1>
    <p class="text mt-4"><?=e(__t('ecommerce.checkout.pay.text', [
        'number' => (string) ($order['order_number'] ?? ''),
        'total' => CartPresenter::money($order['total'] ?? 0, (string) ($order['currency'] ?? 'EUR')),
    ]))?></p>
    <?php if ($client_secret === '' || $stripe['publishable_key'] === ''): ?>
        <p class="text mt-4"><?=e(__t('ecommerce.checkout.pay.error'))?></p>
    <?php else: ?>
        <div class="w-100 mt-6" data-checkout-pay
            data-client-secret="<?=e($client_secret)?>"
            data-publishable-key="<?=e($stripe['publishable_key'])?>"
            data-account="<?=e($stripe['account'])?>"
            data-return-url="<?=e($return_url)?>"
            data-labels="<?=e(json_encode($labels, $flags))?>">
            <div data-checkout-pay-element></div>
            <p class="text-small mt-3" data-checkout-pay-notice role="status"></p>
            <button type="button" class="btn btn-primary w-100 mt-4" data-checkout-pay-submit disabled><?=e(__t('ecommerce.checkout.pay.submit'))?></button>
        </div>
    <?php endif; ?>
    <form class="mt-4 a-c" method="post" action="<?=e(__r('ecommerce.checkout.abandon'))?>">
        <input type="hidden" name="csrf_token" value="<?=e($csrf_token)?>">
        <button type="submit" class="btn btn-link"><?=e(__t('ecommerce.checkout.pay.abandon'))?></button>
    </form>
</div>
<?php if (($checkoutJs = module_asset('ecommerce', 'js/checkout.js')) !== '') {
    View::head('<script src="'.e($checkoutJs).'" defer></script>');
} ?>
<?php View::end(); ?>
```

In `view/pages/checkout/completed.php` sostituisci:

```php
    <?php if (trim((string) ($result['instructions'] ?? '')) !== ''): ?>
```

con:

```php
    <?php if (!empty($result['processing'])): ?>
        <p class="text mt-4"><?=e(__t('ecommerce.checkout.completed.processing'))?></p>
    <?php endif; ?>
    <?php if (trim((string) ($result['instructions'] ?? '')) !== ''): ?>
```

- [ ] **Passo 10: le parole**

Esegui (dalla cartella del worktree):

```bash
python3 - <<'PY'
import pathlib
testi = {
    'it': ('"shop": "Torna al negozio"\n        }\n    },\n    "auth": {',
           '"shop": "Torna al negozio",\n'
           '            "processing": "Il pagamento è in lavorazione: ti scriviamo appena arriva la conferma."\n'
           '        },\n'
           '        "pay": {\n'
           '            "title": "Pagamento dell\'ordine",\n'
           '            "text": "Ordine {{number}}, totale {{total}}.",\n'
           '            "submit": "Paga ora",\n'
           '            "abandon": "Annulla l\'ordine",\n'
           '            "abandoned": "L\'ordine è stato annullato.",\n'
           '            "failed": "Il pagamento non è andato a buon fine. Riprova o usa un altro metodo.",\n'
           '            "canceled": "Il pagamento è stato annullato. Puoi riprovare.",\n'
           '            "error": "Il pagamento online non è disponibile in questo momento. Riprova tra poco."\n'
           '        }\n    },\n    "auth": {'),
    'en': ('"shop": "Back to the shop"\n        }\n    },\n    "auth": {',
           '"shop": "Back to the shop",\n'
           '            "processing": "Your payment is processing: we will email you as soon as it is confirmed."\n'
           '        },\n'
           '        "pay": {\n'
           '            "title": "Order payment",\n'
           '            "text": "Order {{number}}, total {{total}}.",\n'
           '            "submit": "Pay now",\n'
           '            "abandon": "Cancel the order",\n'
           '            "abandoned": "The order has been cancelled.",\n'
           '            "failed": "The payment did not go through. Try again or use a different method.",\n'
           '            "canceled": "The payment was cancelled. You can try again.",\n'
           '            "error": "Online payment is not available right now. Please try again shortly."\n'
           '        }\n    },\n    "auth": {'),
}
for lingua, (vecchio, nuovo) in testi.items():
    p = pathlib.Path(f'lang/{lingua}/ecommerce.json')
    s = p.read_text()
    assert s.count(vecchio) == 1, lingua
    p.write_text(s.replace(vecchio, nuovo))
PY
php -r 'foreach (["it","en"] as $l) { json_decode(file_get_contents("lang/$l/ecommerce.json"), flags: JSON_THROW_ON_ERROR); } echo "ok\n";'
```

Atteso: `ok`.

- [ ] **Passo 11: i test passano**

Esegui: `php tests/integrazione/OnlinePaymentTest.php && php tests/integrazione/CheckoutPayHttpTest.php`
Atteso: PASS, tutti i controlli verdi.

- [ ] **Passo 12: suite e commit**

Esegui: `php tests/run.php > /tmp/e-14.txt; tail -3 /tmp/e-14.txt`
Atteso: «Tutti i test dell'ecommerce passano.»

```bash
git add src/Frontend/Checkout/OnlinePayment.php src/Frontend/Checkout/CheckoutController.php config/routes/route.frontend.php view/pages/checkout/pay.php view/pages/checkout/completed.php lang/it/ecommerce.json lang/en/ecommerce.json tests/integrazione/OnlinePaymentTest.php tests/integrazione/CheckoutPayHttpTest.php
git commit -m "Checkout: ordine in sospeso, ritorno dal gateway, pagina di pagamento e annullo

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito 15: `place` in JSON per il pagamento online

**File:**
- Modifica: `src/Frontend/Checkout/CheckoutController.php` (`place()` per intero, helper nuovi `leave` e `reject`)
- Modifica: `lang/it/ecommerce.json`, `lang/en/ecommerce.json` (`errors.provider_pending` diventa `errors.online_javascript`, nuova `errors.total_changed`)
- Modifica: `tests/CartCheckoutTest.php` (il controllo «il checkout non finge il completamento…»)
- Crea: `tests/CheckoutPlaceJsonTest.php`
- Test: `tests/integrazione/CheckoutPayHttpTest.php` (in coda, prima di `summary();`)

**Interfacce:**
- Consuma: dal Compito 14 `OnlinePayment::pending()`, `sameTotal()`, `dropPending()`, `start()`, `OnlinePayment::RECEIPT`, le rotte `ecommerce.checkout.pay` e `ecommerce.checkout.return`; `CheckoutSummary::payload()` (anteprima con `order.total` e `order.currency`); `Checkout::place()` del gestionale, che per un metodo online lascia la riga del registro `pending` e non manda le email «ricevuto» (Compito 9).
- Produce (per il Compito 17), la risposta di `POST /checkout/` con `X-Requested-With: XMLHttpRequest`:
  - `200 {success: true, client_secret, return_url}` — ordine nato, intento pronto;
  - `200 {success: true, redirect}` — metodo manuale, oppure carrello già diventato ordine da pagare (`redirect` alla pagina di pagamento);
  - `409 {success: false, error: 'total_changed', message, summary}` — il totale visto non è più quello dell'ordine; `summary` è l'anteprima nuova;
  - `422 {success: false, errors: list<string>}` — campi mancanti o errori da mostrare;
  - `401` / `409 {success: false, redirect}` — login richiesto / carrello vuoto;
  - `419 {success: false, error}` — CSRF scaduto;
  - `502 {success: false, redirect}` — ordine nato ma intento non creato: si va alla pagina di pagamento.
  - Il campo POST `expected_total` è il totale che il cliente ha visto.
  - Senza JavaScript un metodo online è rifiutato con `errors.online_javascript`.

- [ ] **Passo 1: i test che falliscono**

In `tests/CartCheckoutTest.php` sostituisci:

```php
        && str_contains($controller, 'ecommerce.checkout.errors.provider_pending')
```

con:

```php
        && str_contains($controller, 'ecommerce.checkout.errors.online_javascript')
        && !str_contains($controller, 'provider_pending')
```

`tests/CheckoutPlaceJsonTest.php`:

```php
<?php
/** php tests/CheckoutPlaceJsonTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');

/** Il corpo di un metodo privato del controller, fino al metodo dopo. */
function corpo(string $sorgente, string $metodo): string
{
    $inizio = strpos($sorgente, 'private static function '.$metodo.'(');
    if ($inizio === false) {
        return '';
    }
    $fine = strpos($sorgente, 'private static function ', $inizio + 1);

    return substr($sorgente, $inizio, $fine === false ? null : $fine - $inizio);
}

$place = corpo($controller, 'place');

check('place risponde in JSON o con un redirect solo attraverso leave e reject', fn () => $place !== ''
    && str_contains($place, 'self::wantsJson()')
    && !str_contains($place, 'self::redirect(')
    && !str_contains($place, 'self::rememberErrors('));

check('place confronta il totale visto e lascia cadere l\'ordine in sospeso prima di crearne uno', fn () =>
    str_contains($place, "OnlinePayment::sameTotal(\$_POST['expected_total'] ?? null")
    && strpos($place, 'OnlinePayment::dropPending()') !== false
    && strpos($place, 'OnlinePayment::dropPending()') < strpos($place, 'Checkout::place('));

check('place avvia il pagamento online e risponde col segreto dell\'intento', fn () =>
    str_contains($place, 'OnlinePayment::start(')
    && str_contains($place, "'client_secret' =>")
    && str_contains($place, "'return_url' => self::route('ecommerce.checkout.return')"));

check('leave e reject esistono e terminano la richiesta', fn () =>
    str_contains(corpo($controller, 'leave'), 'self::json(')
    && str_contains(corpo($controller, 'leave'), 'self::redirect(')
    && str_contains(corpo($controller, 'reject'), '422')
    && str_contains(corpo($controller, 'reject'), 'self::rememberErrors('));

summary();
```

In `tests/integrazione/CheckoutPayHttpTest.php`, prima di `summary();`:

```php
$xhr = ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'];

[$status, , $body] = $request('/checkout/', ['csrf_token' => 'invalid'], $xhr);
$risposta = json_decode($body, true);
check('place in JSON con CSRF invalido risponde 419 in JSON', fn () =>
    $status === 419 && is_array($risposta) && ($risposta['success'] ?? null) === false);

// Il token valido viene dalla pagina di login, nella stessa sessione.
[, , $login] = $request('/account/auth/login/');
preg_match('/name="csrf_token" value="([^"]+)"/', $login, $token);
[$status, , $body] = $request('/checkout/', ['csrf_token' => $token[1] ?? ''], $xhr);
$risposta = json_decode($body, true);
check('place in JSON senza carrello risponde in JSON e non con una pagina', fn () =>
    ($token[1] ?? '') !== ''
    && in_array($status, [401, 409, 422], true)
    && is_array($risposta)
    && ($risposta['success'] ?? null) === false);
```

- [ ] **Passo 2: li vedi fallire**

Esegui: `php tests/CartCheckoutTest.php; php tests/CheckoutPlaceJsonTest.php; php tests/integrazione/CheckoutPayHttpTest.php`
Atteso: FAIL su `il checkout non finge…`, sui quattro controlli di `CheckoutPlaceJsonTest` e sui due controlli nuovi dell'HTTP (oggi il CSRF invalido risponde col testo `CSRF token invalid`, e senza carrello arriva un redirect).

- [ ] **Passo 3: `place()` nuovo**

Sostituisci per intero il metodo `place()` di `src/Frontend/Checkout/CheckoutController.php` (da `    private static function place(): void` fino alla graffa che lo chiude, subito prima di `    private static function summary(): never`) con:

```php
    /**
     * L'ordine nasce qui. Un metodo manuale porta alla pagina «completato»;
     * un metodo online risponde al JavaScript col segreto dell'intento, e
     * l'ordine resta in sessione finché il denaro non arriva.
     */
    private static function place(): void
    {
        self::requirePost();
        $json = self::wantsJson();
        if ($json && !AuthSession::verify($_POST['csrf_token'] ?? '')) {
            self::json(['success' => false, 'error' => (string) __t('ecommerce.checkout.summary_error')], 419);
        }
        self::requireCsrf();

        if (!self::guestAllowed() && !CartSession::authenticated()) {
            self::leave(self::loginUrl(), $json, 401);
        }

        $post = CheckoutRules::post($_POST, CartSession::user());
        $user = CartSession::user();

        try {
            if (!CartSession::authenticated()) {
                RecaptchaGuard::for('ecommerce_checkout')
                    ->withService('ecommerce-checkout')
                    ->withLogAction('place')
                    ->verify();
            }

            $cartId = self::cartId();
            if ($cartId === 0) {
                // Il carrello è già diventato l'ordine: si torna a pagarlo.
                if (OnlinePayment::pending() > 0) {
                    self::leave(self::route('ecommerce.checkout.pay'), $json);
                }
                if ($json) {
                    self::leave(self::route('ecommerce.cart.index'), true, 409);
                }

                throw new RuntimeException((string) __t('ecommerce.checkout.errors.empty'));
            }

            // L'anteprima scrive contatti e consegna sul carrello; poi si rilegge.
            $preview = CheckoutSummary::payload($cartId, $post, $user);
            $order = (array) Order::findById($cartId);
            $userId = (int) ($user->id ?? 0);
            $asked = CheckoutRules::askedConsents($userId);
            $billing = CheckoutRules::billing($post, $order);
            $missing = array_values(array_unique(array_merge(
                CheckoutRules::deliveryErrors($order, $preview, Gestionale::feature('shipping')),
                CheckoutRules::paymentErrors($post, $billing, $asked)
            )));
            if ($missing !== []) {
                self::reject(array_map(static fn (string $key): string => (string) __t('ecommerce.checkout.errors.'.$key), $missing), $post, $json);
            }

            // Vale solo un metodo che la pagina ha offerto.
            $method = CheckoutRules::method($preview, (int) ($post['payment_method_id'] ?? 0));
            if ($method === null) {
                throw new RuntimeException((string) __t('ecommerce.checkout.errors.payment_method'));
            }

            $online = !CheckoutForm::isManual($method);
            if ($online && !$json) {
                throw new RuntimeException((string) __t('ecommerce.checkout.errors.online_javascript'));
            }
            // Il Payment Element mostra un importo: se nel frattempo è cambiato, il cliente lo rivede prima di pagare.
            if ($online && !OnlinePayment::sameTotal($_POST['expected_total'] ?? null, $preview['order']['total'] ?? null)) {
                self::json([
                    'success' => false,
                    'error' => 'total_changed',
                    'message' => (string) __t('ecommerce.checkout.errors.total_changed'),
                    'summary' => $preview,
                ], 409);
            }

            $data = CheckoutForm::data([
                'payment_method_id' => (string) $method['id'],
                'customer_note' => (string) ($post['customer_note'] ?? ''),
            ] + $billing + self::fromCart($order), $user);

            // L'ospite ordina sempre su un account: si trova o nasce dalla sua email.
            $guest = !CartSession::authenticated();
            $customerId = CartSession::customerId();
            $passwordLink = '';
            if ($guest) {
                $account = GuestCheckout::account($order, $post, $asked);
                $userId = $account['user_id'];
                $customerId = $account['customer_id'];
                // Online il link parte con la conferma, dal modulo (Ecommerce::orderEmailExtras).
                if (!$online) {
                    $passwordLink = GuestCheckout::passwordLink($userId, self::route('ecommerce.auth.password.restore'));
                }
            }

            // Un ordine online lasciato a metà non resta a tenere la merce.
            try {
                OnlinePayment::dropPending();
            } catch (Throwable $error) {
                Errors::internal($error, 'ecommerce.checkout.drop');
            }

            $result = Checkout::place($cartId, $data + [
                'customer_id' => $customerId,
                'source' => 'ecommerce',
                'user_id' => $userId,
                'customer_email' => $passwordLink === '' ? [] : ['account_url' => $passwordLink],
            ]);

            if (!$guest && $userId > 0 && $asked !== []) {
                try {
                    consentService()->registerBaseConsents($userId, $post, ['required_document_types' => $asked, 'ui_surface' => 'checkout']);
                } catch (Throwable $error) {
                    // L'ordine è nato: un consenso non registrato non lo ferma.
                    Errors::internal($error, 'ecommerce.checkout.consents');
                }
            }

            $receipt = [
                'order_id' => (int) ($result['order_id'] ?? 0),
                'order_number' => (string) ($result['order_number'] ?? ''),
                'total' => (string) ($result['total'] ?? ''),
                'status' => (string) ($result['status'] ?? 'pending'),
                'instructions' => (string) ($method['instructions'] ?? ''),
                'guest' => $guest,
                'email_sent' => $guest && ($result['customer_email_sent'] ?? false),
            ];

            if (!$online) {
                $_SESSION[self::COMPLETED] = $receipt;
                self::leave(self::route('ecommerce.checkout.completed'), $json);
            }

            $_SESSION[OnlinePayment::RECEIPT] = $receipt;
            try {
                $clientSecret = OnlinePayment::start($receipt['order_id'])['client_secret'];
            } catch (Throwable $error) {
                // L'ordine c'è: la pagina di pagamento riprova a creare l'intento.
                Errors::internal($error, 'ecommerce.checkout.start', ['order_id' => $receipt['order_id']]);
                self::json(['success' => false, 'redirect' => self::route('ecommerce.checkout.pay')], 502);
            }

            self::json([
                'success' => true,
                'client_secret' => $clientSecret,
                'return_url' => self::route('ecommerce.checkout.return'),
            ]);
        } catch (UserError $error) {
            self::reject([$error->getMessage()], $post, $json);
        } catch (RuntimeException $error) {
            self::reject([$error->getMessage()], $post, $json);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.place');
            self::reject([(string) __t('ecommerce.checkout.errors.generic')], $post, $json);
        }
    }
```

Nota: `OnlinePayment::start()` ricorda l'ordine in sessione; se lancia, `pay()` lo ritrova con `pending()` solo se è già in sessione. Perciò `start()` del Compito 14 chiama `remember()` **prima** di `OnlinePayments::start()`: non invertire le due righe.

Subito dopo il metodo `json()` aggiungi i due helper:

```php
    /** Fuori dal checkout: in JSON un indirizzo da seguire, altrimenti un redirect. */
    private static function leave(string $url, bool $json, int $status = 200): never
    {
        if ($json) {
            self::json(['success' => $status === 200, 'redirect' => $url], $status);
        }

        self::redirect($url);
    }

    /** Errori da mostrare sul modulo: in JSON tornano al JavaScript, altrimenti in sessione. */
    private static function reject(array $errors, array $post, bool $json): never
    {
        if ($json) {
            self::json(['success' => false, 'errors' => array_values($errors)], 422);
        }

        self::rememberErrors($errors, $post);
        self::redirect(self::route('ecommerce.checkout.index'));
    }
```

- [ ] **Passo 4: le parole**

Esegui (dalla cartella del worktree):

```bash
python3 - <<'PY'
import pathlib
testi = {
    'it': ('"provider_pending": "Il pagamento online non è ancora disponibile. Scegli un altro metodo di pagamento.",',
           '"online_javascript": "Per pagare online serve JavaScript attivo nel browser. Attivalo o scegli un altro metodo di pagamento.",\n'
           '            "total_changed": "Il totale dell\'ordine è cambiato. Controllalo e premi di nuovo «Paga».",'),
    'en': ('"provider_pending": "Online payment is not available yet. Choose a different payment method.",',
           '"online_javascript": "Online payment needs JavaScript enabled in your browser. Enable it or choose a different payment method.",\n'
           '            "total_changed": "The order total has changed. Please check it and press «Pay» again.",'),
}
for lingua, (vecchio, nuovo) in testi.items():
    p = pathlib.Path(f'lang/{lingua}/ecommerce.json')
    s = p.read_text()
    assert s.count(vecchio) == 1, lingua
    p.write_text(s.replace(vecchio, nuovo))
PY
php -r 'foreach (["it","en"] as $l) { json_decode(file_get_contents("lang/$l/ecommerce.json"), flags: JSON_THROW_ON_ERROR); } echo "ok\n";'
grep -rn "provider_pending" src lang view tests assets || echo "nessun provider_pending"
```

Atteso: `ok` e `nessun provider_pending`.

- [ ] **Passo 5: i test passano**

Esegui: `php tests/CartCheckoutTest.php && php tests/CheckoutPlaceJsonTest.php && php tests/integrazione/CheckoutPayHttpTest.php`
Atteso: PASS, tutti i controlli verdi.

- [ ] **Passo 6: suite e commit**

Esegui: `php tests/run.php > /tmp/e-15.txt; tail -3 /tmp/e-15.txt`
Atteso: «Tutti i test dell'ecommerce passano.»

```bash
git add src/Frontend/Checkout/CheckoutController.php lang/it/ecommerce.json lang/en/ecommerce.json tests/CartCheckoutTest.php tests/CheckoutPlaceJsonTest.php tests/integrazione/CheckoutPayHttpTest.php
git commit -m "Checkout: place risponde in JSON e avvia il pagamento online

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Compito 16: il link per la password dell'ospite nella conferma

L'ordine online dell'ospite non manda l'email «ricevuto» col link per scegliere la password (Compito 9): il link parte con l'email «confermato», quando il denaro arriva, attraverso il punto d'estensione del Compito 10.

**File:**
- Modifica: `src/Ecommerce.php`
- Crea: `tests/integrazione/OrderEmailExtrasTest.php`

**Interfacce:**
- Consuma: `Wonder\Plugin\Gestionale\Extensions\ProvidesOrderEmailExtras` (Compito 10, `public static function orderEmailExtras(string $key, array $order): array`); `GuestCheckout::passwordLink(int $userId, string $restorePath): string` (vuoto per chi ha già la password, entra con Google o non è un cliente del negozio).
- Produce: `Ecommerce::orderEmailExtras('confirmed', $order)` → `['account_url' => …]` per l'ospite senza password, altrimenti `[]`.

- [ ] **Passo 1: il test che fallisce**

`tests/integrazione/OrderEmailExtrasTest.php`: intestazione dell'ecommerce (commento in testa `/** php tests/integrazione/OrderEmailExtrasTest.php */`), con queste `use`:

```php
use Wonder\App\Models\User\User;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Gestionale\Extensions\ProvidesOrderEmailExtras;
```

poi:

```php
/** Un cliente del negozio senza password, come lo crea il checkout dell'ospite. */
function ospite(string $email): int
{
    return (int) (User::create([
        'name' => 'Ida',
        'surname' => 'Cliente',
        'email' => $email,
        'username' => create_link(explode('@', $email)[0], 'user', 'username'),
        'authority' => json_encode(['client'], JSON_THROW_ON_ERROR),
        'area' => json_encode(['frontend'], JSON_THROW_ON_ERROR),
        'active' => 'true',
    ])->insert_id ?? 0);
}

check('l\'ecommerce dà dati alle email dell\'ordine', fn () => is_subclass_of(Ecommerce::class, ProvidesOrderEmailExtras::class));

check('la conferma dell\'ospite senza password porta il link per sceglierla', fn () => prova(static function (): bool {
    $utente = ospite('ospite-conferma-'.bin2hex(random_bytes(4)).'@example.test');
    $dati = Ecommerce::orderEmailExtras('confirmed', ['id' => 1, 'user_id' => $utente]);

    return $utente > 0
        && str_contains((string) ($dati['account_url'] ?? ''), '?token=')
        && Ecommerce::orderEmailExtras('shipped', ['id' => 1, 'user_id' => $utente]) === [];
}));

check('senza account nell\'ordine non c\'è link', fn () =>
    Ecommerce::orderEmailExtras('confirmed', ['id' => 1, 'user_id' => 0]) === []
    && Ecommerce::orderEmailExtras('confirmed', ['id' => 1]) === []);

summary();
```

- [ ] **Passo 2: lo vedi fallire**

Esegui: `php tests/integrazione/OrderEmailExtrasTest.php`
Atteso: FAIL sul primo controllo e `Call to undefined method …Ecommerce::orderEmailExtras()` sul secondo.

- [ ] **Passo 3: `Ecommerce` implementa l'estensione**

In `src/Ecommerce.php` aggiungi tra le `use`, in ordine alfabetico:

```php
use Wonder\Plugin\Ecommerce\Frontend\Checkout\GuestCheckout;
use Wonder\Plugin\Gestionale\Extensions\ProvidesOrderEmailExtras;
```

sostituisci:

```php
final class Ecommerce implements ModuleInterface, ProvidesSettings
```

con:

```php
final class Ecommerce implements ModuleInterface, ProvidesSettings, ProvidesOrderEmailExtras
```

e aggiungi il metodo pubblico, dopo l'ultimo metodo pubblico statico della classe:

```php
    /**
     * Il link per scegliere la password, nella conferma di un ordine fatto
     * da ospite: online la conferma arriva col pagamento, senza la sessione
     * del checkout.
     */
    public static function orderEmailExtras(string $key, array $order): array
    {
        $userId = (int) ($order['user_id'] ?? 0);
        if ($key !== 'confirmed' || $userId <= 0) {
            return [];
        }

        $link = GuestCheckout::passwordLink($userId, \__r('ecommerce.auth.password.restore'));

        return $link === '' ? [] : ['account_url' => $link];
    }
```

`__r` funziona anche da riga di comando (riallineamento orario) e dà l'indirizzo assoluto.

- [ ] **Passo 4: lo vedi passare**

Esegui: `php tests/integrazione/OrderEmailExtrasTest.php`
Atteso: PASS, tre controlli verdi.

- [ ] **Passo 5: suite e commit**

Esegui: `php tests/run.php > /tmp/e-16.txt; tail -3 /tmp/e-16.txt`
Atteso: «Tutti i test dell'ecommerce passano.»

```bash
git add src/Ecommerce.php tests/integrazione/OrderEmailExtrasTest.php
git commit -m "Ecommerce: il link per la password dell'ospite arriva con la conferma del pagamento

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---


### Compito 17: il Payment Element nel checkout e nella pagina «Paga ora»

**File:**
- Crea: `tests/CheckoutStripeTest.php`
- Modifica: `src/Frontend/Checkout/CheckoutSummary.php` (blocco `stripe` nell'anteprima, `paymentDisplay`)
- Modifica: `view/pages/checkout/index.php` (posto per la carta, etichette, modulo `checkout-abandon`)
- Modifica: `resources/assets/js/checkout.js` (Stripe nel checkout, classe `CheckoutPay`)

**Interfacce:**
- Consuma:
  - dal Compito 7 `StripeProvider::methodTypes(string $column): list<string>`;
  - dal Compito 14 `OnlinePayment::browserKeys(): array{publishable_key: string, account: string}`, la pagina `pay.php` con `data-checkout-pay`, `data-client-secret`, `data-publishable-key`, `data-account`, `data-return-url`, `data-labels {failed, error}`, `[data-checkout-pay-element]`, `[data-checkout-pay-notice]`, `[data-checkout-pay-submit]` (spento), la rotta `ecommerce.checkout.abandon`, le chiavi `ecommerce.checkout.pay.{abandon, failed, error}`;
  - dal Compito 15 le risposte JSON di `POST /checkout/` (elencate nelle sue Interfacce) e il campo `expected_total`.
- Produce:
  - nell'anteprima di `CheckoutSummary::payload()`, quando c'è un'opzione Stripe: `stripe: {publishable_key, account, amount (centesimi, int), currency (minuscola)}`;
  - su ogni opzione di pagamento `payment_method_types: list<string>` (vuota se non è Stripe) e `panel` vuoto per Stripe;
  - in `checkout.js` le classi `Checkout` (con `stripeBox`, `payOnline`, `place`, `freeze`) e `CheckoutPay`, e la funzione `loadStripe()`.

Il Payment Element del checkout parte in modalità differita (`mode: 'payment'`, importo e valuta del riepilogo): l'intento nasce solo con l'ordine. Al «Paga»:

1. `elements.submit()` — Stripe controlla i dati della carta, senza ordine;
2. `place()` — `POST /checkout/` in JSON con `expected_total`; nasce l'ordine e torna il `client_secret`;
3. `freeze()` — il modulo non cambia più: l'ordine è quello;
4. `stripe.confirmPayment(...)` — Stripe incassa (o chiede il 3D Secure) e porta il cliente al `return_url`.

Una carta rifiutata lascia l'ordine e l'intento: il secondo «Paga» salta il passo 2 (`this.placed`) e riprova col `client_secret` di prima. Se il cliente vuole pagare in un altro modo, il bottone «Annulla l'ordine» (nascosto finché l'ordine non nasce) manda il modulo `checkout-abandon`, fuori dal modulo del checkout.

- [ ] **Passo 1: il test che fallisce**

`tests/CheckoutStripeTest.php`:

```php
<?php
/** php tests/CheckoutStripeTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

$root = dirname(__DIR__);
$js = (string) file_get_contents($root.'/resources/assets/js/checkout.js');
$view = (string) file_get_contents($root.'/view/pages/checkout/index.php');
$summary = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutSummary.php');

check('Stripe.js arriva da Stripe, una volta sola e solo quando serve', fn () => str_contains($js, "'https://js.stripe.com/v3/'")
    && str_contains($js, 'function loadStripe()')
    && substr_count($js, 'js.stripe.com') === 1);

check('il Payment Element del checkout parte senza intento, con importo e valuta del riepilogo', fn () => str_contains($js, "mode: 'payment'")
    && str_contains($js, 'this.elements.update(')
    && str_contains($js, 'stripeAccount')
    && str_contains($js, 'this.stripeBox(payload)')
    && str_contains($js, 'this.stripeBox(this.latest)')
    && str_contains($js, "chosen?.provider === 'stripe'"));

check('«Paga»: prima Stripe controlla la carta, poi nasce l\'ordine col totale visto, poi Stripe incassa', function () use ($js): bool {
    $submit = strpos($js, 'await this.elements.submit()');
    $place = strpos($js, 'await this.place()');
    $confirm = strpos($js, 'await this.stripe.confirmPayment(');

    return $submit !== false && $place !== false && $confirm !== false
        && $submit < $place && $place < $confirm
        && str_contains($js, 'expected_total')
        && str_contains($js, "'total_changed'")
        && str_contains($js, 'new URL(this.placed.return_url, window.location.href)');
});

check('un secondo «Paga» riusa l\'ordine già nato e il modulo non cambia più', fn () => str_contains($js, 'if (!this.placed)')
    && str_contains($js, 'this.freeze()')
    && (bool) preg_match('/this\.sequence\+\+;\s*clearTimeout\(this\.timer\);\s*if \(this\.frozen\)/', $js)
    && str_contains($js, 'grecaptcha'));

check('la pagina «Paga ora» monta il Payment Element dal client_secret', fn () => str_contains($js, 'class CheckoutPay')
    && str_contains($js, 'clientSecret: this.root.dataset.clientSecret')
    && str_contains($js, "document.querySelectorAll('[data-checkout-pay]')")
    && !str_contains($js, 'innerHTML'));

check('la vista ha il posto per la carta e l\'annullamento dell\'ordine già nato', fn () => str_contains($view, 'data-checkout-stripe hidden')
    && str_contains($view, 'data-checkout-stripe-element')
    && str_contains($view, 'data-checkout-stripe-notice')
    && str_contains($view, 'form="checkout-abandon"')
    && str_contains($view, 'id="checkout-abandon"')
    && str_contains($view, "\$labels['stripe_error']")
    && str_contains($view, "\$labels['pay_failed']"));

check('il riepilogo dà al browser solo chiavi pubbliche, centesimi e metodi della carta', fn () => str_contains($summary, 'OnlinePayment::browserKeys()')
    && str_contains($summary, "'amount' => (int) round((float) \$preview['order']['total'] * 100)")
    && str_contains($summary, 'StripeProvider::methodTypes(')
    && str_contains($summary, "'payment_method_types' =>")
    && str_contains($summary, "(\$option['provider'] ?? '') === 'stripe'")
    && !str_contains($summary, 'stripe_private_key')
    && !str_contains($summary, 'stripe_test_key'));

summary();
```

- [ ] **Passo 2: lo vedi fallire**

Esegui: `php tests/CheckoutStripeTest.php`
Atteso: FAIL, tutti e sette i controlli rossi (nessuno dei pezzi esiste).

- [ ] **Passo 3: il riepilogo**

In `src/Frontend/Checkout/CheckoutSummary.php` sostituisci:

```php
use Wonder\Plugin\Gestionale\Models\Sales\Order;
```

con:

```php
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Providers\Payments\StripeProvider;
```

(`OnlinePayment` sta nello stesso namespace e non vuole `use`.)

Sostituisci:

```php
        $preview['shipping_methods']['address_complete'] = CheckoutRules::addressComplete((array) Order::findById($cartId));
```

con:

```php
        // Il Payment Element si disegna prima che l'ordine nasca: gli servono
        // le chiavi pubbliche, l'importo in centesimi e la valuta, come all'intento.
        foreach ((array) ($preview['payment_methods']['options'] ?? []) as $opzione) {
            if (($opzione['provider'] ?? '') === 'stripe') {
                $preview['stripe'] = OnlinePayment::browserKeys() + [
                    'amount' => (int) round((float) $preview['order']['total'] * 100),
                    'currency' => strtolower($valuta),
                ];
                break;
            }
        }

        $preview['shipping_methods']['address_complete'] = CheckoutRules::addressComplete((array) Order::findById($cartId));
```

In `paymentDisplay()` sostituisci:

```php
     * @return array{icon_urls: list<array{src: string, alt: string}>, fee_display: string, panel: string}
```

con:

```php
     * @return array{icon_urls: list<array{src: string, alt: string}>, fee_display: string, panel: string, payment_method_types: list<string>}
```

e sostituisci:

```php
        return [
            'icon_urls' => $icons,
            'fee_display' => $fee > 0 ? '+ '.CartPresenter::money($fee, $currency) : '',
            'panel' => !empty($option['manual'])
                ? (string) ($option['instructions'] ?? '')
                : (string) __t('ecommerce.checkout.redirect_panel', ['name' => (string) ($option['name'] ?? '')]),
        ];
```

con:

```php
        $stripe = ($option['provider'] ?? '') === 'stripe';

        return [
            'icon_urls' => $icons,
            'fee_display' => $fee > 0 ? '+ '.CartPresenter::money($fee, $currency) : '',
            'panel' => match (true) {
                !empty($option['manual']) => (string) ($option['instructions'] ?? ''),
                // La carta si scrive nel Payment Element, sotto le scelte: niente pannello.
                $stripe => '',
                default => (string) __t('ecommerce.checkout.redirect_panel', ['name' => (string) ($option['name'] ?? '')]),
            },
            // Gli stessi metodi che avrà l'intento: Stripe rifiuta la conferma se non coincidono.
            'payment_method_types' => $stripe
                ? StripeProvider::methodTypes((string) (((array) PaymentMethod::findById((int) ($option['id'] ?? 0)))['stripe_payment_method_types'] ?? ''))
                : [],
        ];
```

- [ ] **Passo 4: la vista del checkout**

In `view/pages/checkout/index.php` sostituisci:

```php
foreach (['products_total', 'discount', 'total'] as $key) {
    $labels[$key] = (string) __t('ecommerce.cart.'.$key);
}
```

con:

```php
foreach (['products_total', 'discount', 'total'] as $key) {
    $labels[$key] = (string) __t('ecommerce.cart.'.$key);
}
$labels['stripe_error'] = (string) __t('ecommerce.checkout.pay.error');
$labels['pay_failed'] = (string) __t('ecommerce.checkout.pay.failed');
```

Sostituisci:

```php
                    <?=ChoiceGroup::make()->variant('list')->choices(...array_map($payment, $payments))?>
                <?php endif; ?>
            </div>
```

con:

```php
                    <?=ChoiceGroup::make()->variant('list')->choices(...array_map($payment, $payments))?>
                <?php endif; ?>
            </div>
            <?php /* Lo riempie checkout.js quando la scelta è Stripe; senza JavaScript resta nascosto e il server rifiuta il pagamento online. */ ?>
            <div class="w-100 mt-3" data-checkout-stripe hidden>
                <div data-checkout-stripe-element></div>
                <p class="text-small mt-3" data-checkout-stripe-notice role="status"></p>
                <button type="submit" form="checkout-abandon" class="btn btn-link mt-2" data-checkout-stripe-abandon hidden><?=e(__t('ecommerce.checkout.pay.abandon'))?></button>
            </div>
```

Sostituisci:

```php
<?php if (!$guest && $user_email !== ''): ?>
    <form id="checkout-logout"
```

con:

```php
<?php /* Fuori dal modulo del checkout: un form dentro un form non vale. Lo usa il bottone «Annulla l'ordine» dopo una carta rifiutata. */ ?>
<form id="checkout-abandon" method="post" action="<?=e(__r('ecommerce.checkout.abandon'))?>" hidden>
    <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
</form>
<?php if (!$guest && $user_email !== ''): ?>
    <form id="checkout-logout"
```

- [ ] **Passo 5: il JavaScript**

Le modifiche a `resources/assets/js/checkout.js`, una per una.

Nel costruttore sostituisci:

```js
        if (this.latest) {
            this.submit(this.latest);
        } else if (this.form) {
```

con:

```js
        if (this.latest) {
            this.submit(this.latest);
            this.stripeBox(this.latest);
        } else if (this.form) {
```

In `schedule()` sostituisci:

```js
        this.sequence++;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.refresh(), 300);
```

con:

```js
        this.sequence++;
        clearTimeout(this.timer);

        if (this.frozen) {
            return;
        }

        this.timer = setTimeout(() => this.refresh(), 300);
```

(Il commento resta subito sopra `this.sequence++;`: `CartCheckoutTest` lo controlla.)

In `render()` sostituisci:

```js
        this.submit(payload);
        this.notices(payload.notices);
    }
```

con:

```js
        this.submit(payload);
        this.stripeBox(payload);
        this.notices(payload.notices);
    }
```

Sostituisci il metodo `guardSubmit()` col suo commento:

```js
    // Un clic solo: il bottone si spegne all'invio e torna acceso se il browser riapre la pagina dalla cache.
    guardSubmit() {
        this.form.addEventListener('submit', (event) => {
            if (!this.validate(event)) {
                return;
            }

            setTimeout(() => document.querySelectorAll('[data-checkout-submit]').forEach((button) => { button.disabled = true; }), 0);
        });
```

con:

```js
    // L'opzione scelta: il radio spuntato, o quella dell'anteprima prima del primo disegno.
    chosenPayment(payload = this.latest) {
        const checked = this.form?.querySelector('[name="payment_method_id"]:checked');
        const id = checked ? Number(checked.value) : Number(payload?.payment_methods?.selected);

        return (payload?.payment_methods?.options || []).find((o) => Number(o.id) === id) || null;
    }

    // Il Payment Element: si monta la prima volta che serve, poi segue importo e valuta del riepilogo.
    stripeBox(payload) {
        const box = document.querySelector('[data-checkout-stripe]');
        const keys = payload?.stripe;
        const chosen = this.chosenPayment(payload);
        const on = Boolean(box && keys?.publishable_key && keys.amount > 0 && chosen?.provider === 'stripe');

        box?.toggleAttribute('hidden', !on);

        if (!on) {
            return;
        }

        this.stripeOptions = { mode: 'payment', amount: keys.amount, currency: keys.currency };

        if (chosen.payment_method_types?.length) {
            this.stripeOptions.paymentMethodTypes = chosen.payment_method_types;
        }

        if (!this.stripeReady) {
            this.stripeReady = loadStripe().then(() => {
                this.stripe = window.Stripe(keys.publishable_key, keys.account ? { stripeAccount: keys.account } : {});
                this.elements = this.stripe.elements(this.stripeOptions);
                this.elements.create('payment').mount(box.querySelector('[data-checkout-stripe-element]'));
            }).catch(() => {
                this.stripeReady = null;
                this.say([this.labels.stripe_error]);
            });

            return;
        }

        this.stripeReady.then(() => this.elements?.update(this.stripeOptions));
    }

    // «Paga»: Stripe controlla la carta, il server fa nascere l'ordine e l'intento, poi Stripe incassa.
    async payOnline() {
        if (this.paying) {
            return;
        }

        if (!this.elements) {
            this.say([this.labels.stripe_error]);

            return;
        }

        // Una risposta del riepilogo ancora in viaggio non deve ridisegnare il modulo mentre si paga.
        this.sequence++;
        clearTimeout(this.timer);
        this.paying = true;
        this.lock(true);
        this.say([]);

        try {
            const checked = await this.elements.submit();

            if (checked.error) {
                this.say([checked.error.message || this.labels.pay_failed]);

                return;
            }

            if (!this.placed) {
                this.placed = await this.place();

                if (!this.placed) {
                    return;
                }

                this.freeze();
            }

            const { error } = await this.stripe.confirmPayment({
                elements: this.elements,
                clientSecret: this.placed.client_secret,
                confirmParams: { return_url: new URL(this.placed.return_url, window.location.href).href },
            });

            // Senza errore Stripe ha già portato il cliente al ritorno.
            if (error) {
                this.say([error.message || this.labels.pay_failed]);
            }
        } catch (error) {
            this.say([this.labels.stripe_error]);
        } finally {
            this.paying = false;
            this.lock(false);
        }
    }

    // L'ordine nasce qui: il server controlla che il totale sia quello che il cliente ha visto.
    async place() {
        const response = await fetch(this.form.action, {
            method: 'POST',
            body: this.body({ expected_total: String(this.latest?.order?.total ?? '') }),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        });
        const payload = await response.json().catch(() => ({}));

        if (payload.client_secret) {
            return payload;
        }

        if (payload.redirect) {
            window.location.href = payload.redirect;

            return null;
        }

        if (payload.error === 'total_changed' && payload.summary) {
            this.render(payload.summary);
            this.say([payload.message]);
        } else {
            this.say(payload.errors || [payload.message || payload.error || this.labels.summary_error]);
        }

        // Il reCAPTCHA vale una volta sola: l'ospite lo rifà prima del prossimo «Paga».
        if (window.grecaptcha && typeof window.grecaptcha.reset === 'function') {
            window.grecaptcha.reset();
        }

        return null;
    }

    // L'ordine è nato: il modulo non cambia più, altrimenti si pagherebbe un ordine diverso da quello che si vede.
    freeze() {
        this.frozen = true;
        clearTimeout(this.timer);
        this.form.querySelectorAll('input, select, textarea').forEach((field) => { field.disabled = true; });
        document.querySelectorAll('[data-checkout-coupon] input, [data-checkout-coupon] button').forEach((field) => { field.disabled = true; });
        document.querySelector('[data-checkout-stripe-abandon]')?.removeAttribute('hidden');
    }

    lock(on) {
        document.querySelectorAll('[data-checkout-submit]').forEach((button) => { button.disabled = on; });
        this.busy(on);
    }

    // Gli errori del pagamento si leggono vicino alla carta e nel riepilogo.
    say(messages) {
        const list = (messages || []).filter(Boolean);
        const box = document.querySelector('[data-checkout-stripe-notice]');

        if (box) {
            box.textContent = list.join(' ');
        }

        this.notices(list);
    }

    // Un clic solo: il bottone si spegne all'invio e torna acceso se il browser riapre la pagina dalla cache.
    // Con Stripe il modulo non parte: lo manda payOnline() in JSON.
    guardSubmit() {
        this.form.addEventListener('submit', (event) => {
            if (!this.validate(event)) {
                return;
            }

            if (this.chosenPayment()?.provider === 'stripe') {
                event.preventDefault();
                this.payOnline();

                return;
            }

            setTimeout(() => document.querySelectorAll('[data-checkout-submit]').forEach((button) => { button.disabled = true; }), 0);
        });
```

(La parte del `pageshow` che chiude `guardSubmit()` resta com'è.)

In fondo al file sostituisci:

```js
window.Checkout = Checkout;
window.ecommerceCheckout = new Checkout();
```

con:

```js
let stripeScript = null;

// Stripe.js arriva da Stripe, come vuole Stripe per la sicurezza della carta, e solo nelle pagine che lo usano.
function loadStripe() {
    if (window.Stripe) {
        return Promise.resolve();
    }

    if (!stripeScript) {
        stripeScript = new Promise((resolve, reject) => {
            const script = document.createElement('script');

            script.src = 'https://js.stripe.com/v3/';
            script.onload = () => resolve();
            script.onerror = () => {
                stripeScript = null;
                script.remove();
                reject(new Error('Stripe.js'));
            };
            document.head.appendChild(script);
        });
    }

    return stripeScript;
}

// La pagina «Paga ora»: ordine e intento ci sono già, il Payment Element parte dal client_secret.
class CheckoutPay {
    constructor(root) {
        this.root = root;
        this.labels = JSON.parse(root.dataset.labels || '{}');
        this.button = root.querySelector('[data-checkout-pay-submit]');
        this.notice = root.querySelector('[data-checkout-pay-notice]');

        loadStripe().then(() => this.mount()).catch(() => this.say(this.labels.error));
    }

    mount() {
        const account = this.root.dataset.account;

        this.stripe = window.Stripe(this.root.dataset.publishableKey, account ? { stripeAccount: account } : {});
        this.elements = this.stripe.elements({ clientSecret: this.root.dataset.clientSecret });
        this.elements.create('payment').mount(this.root.querySelector('[data-checkout-pay-element]'));
        this.button.addEventListener('click', () => this.pay());
        this.button.disabled = false;
    }

    async pay() {
        this.button.disabled = true;
        this.say('');

        try {
            const { error } = await this.stripe.confirmPayment({
                elements: this.elements,
                confirmParams: { return_url: new URL(this.root.dataset.returnUrl, window.location.href).href },
            });

            // Senza errore Stripe ha già portato il cliente al ritorno.
            if (error) {
                this.say(error.message || this.labels.failed);
            }
        } catch (error) {
            this.say(this.labels.error);
        } finally {
            this.button.disabled = false;
        }
    }

    say(message) {
        if (this.notice) {
            this.notice.textContent = message || '';
        }
    }
}

document.querySelectorAll('[data-checkout-pay]').forEach((root) => new CheckoutPay(root));

window.Checkout = Checkout;
window.ecommerceCheckout = new Checkout();
```

Note per chi esegue:
- `loadStripe` e `stripeScript` stanno prima di `new Checkout()`: il costruttore può chiamare `stripeBox()` e la variabile deve già esistere.
- Il totale per `expected_total` è `this.latest.order.total`, l'ultimo riepilogo disegnato. Se il cliente cambia qualcosa e preme «Paga» prima che arrivi il riepilogo nuovo, il server risponde `total_changed`, il modulo si ridisegna col totale giusto e il cliente preme di nuovo: è voluto.
- Niente `innerHTML`: `CartCheckoutTest` lo vieta.

- [ ] **Passo 6: lo vedi passare**

Esegui: `php tests/CheckoutStripeTest.php && php tests/CartCheckoutTest.php`
Atteso: PASS, tutti i controlli verdi (i sette nuovi e quelli del checkout, compreso `schedule() {` seguito dal commento e da `this.sequence++;`).

Esegui anche: `node --check resources/assets/js/checkout.js && php -l src/Frontend/Checkout/CheckoutSummary.php && php -l view/pages/checkout/index.php`
Atteso: nessun errore di sintassi.

- [ ] **Passo 7: suite e commit**

Esegui: `php tests/run.php > /tmp/e-17.txt; tail -3 /tmp/e-17.txt`
Atteso: «Tutti i test dell'ecommerce passano.»

```bash
git add src/Frontend/Checkout/CheckoutSummary.php view/pages/checkout/index.php resources/assets/js/checkout.js tests/CheckoutStripeTest.php
git commit -m "Checkout: il Payment Element di Stripe nel checkout e nella pagina Paga ora

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Parte D — prova nel browser e chiusura

### Compito 18: prova su `ecommerce.test` con le carte di prova, TODO e suite finali

Nessun codice nuovo: si prova il percorso vero con Stripe in modalità test (spec §12, «E2E su `ecommerce.test`») e si chiude il lavoro. Se una prova fallisce, si apre la causa con superpowers:systematic-debugging, si scrive il test che la riproduce nel compito che possiede il codice e si corregge lì, con un commit a parte.

**File:**
- Modifica: `TODO.md` del gestionale (voce E1c)
- Modifica: `TODO.md` dell'ecommerce (voci D6 e D8)

**Interfacce:**
- Consuma: tutto il piano. In particolare le rotte `ecommerce.checkout.pay`, `ecommerce.checkout.return`, `ecommerce.checkout.abandon` (Compito 14), `place` in JSON (Compito 15), il Payment Element (Compito 17), il webhook `api.gestionale.stripe.webhook` (Compito 8), il bollino «Prova» (Compito 13).
- Produce: niente di nuovo.

- [ ] **Passo 1: schema e chiavi di prova.**

Schema del sito di prova aggiornato (P5), se non l'hai già fatto dopo il Compito 4:

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update
```

Le chiavi di test **le inserisce lo sviluppatore**, non chi esegue il piano: chiedigli di mettere in `.env` del sito di prova (oppure nella pagina Sicurezza del backend) `STRIPE_TEST=true`, `STRIPE_TEST_KEY`, `STRIPE_TEST_PUBLIC_KEY` e `STRIPE_TEST_ACCOUNT_ID` del conto collegato di prova. Non leggere, stampare o ripetere quei valori. Controlla soltanto che ci siano:

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && grep -c "^STRIPE_TEST_\(KEY\|PUBLIC_KEY\|ACCOUNT_ID\)=..*" .env
```

Atteso: `3` (oppure `0` se lo sviluppatore le ha messe nella pagina Sicurezza: in quel caso basta la sua conferma).

Nel backend deve esserci un metodo di pagamento attivo con provider `stripe`. Se manca, lo crea lo sviluppatore in Set Up, *Metodi* di pagamento (serve l'accesso al backend, che fa lui).

- [ ] **Passo 2: il webhook in locale con la Stripe CLI.**

Stripe non raggiunge `ecommerce.test`, quindi in locale il bottone «Collega webhook» non serve: gli eventi arrivano dalla Stripe CLI (`/opt/homebrew/bin/stripe`). Se la CLI non è collegata all'account, `stripe login` lo esegue lo sviluppatore.

L'indirizzo del webhook:

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php -r '$GLOBALS["ROOT"] = getcwd(); require "vendor/autoload.php"; require "vendor/wonder-image/app/wonder-image.php"; echo Wonder\Http\Route::url("api.gestionale.stripe.webhook"), PHP_EOL;'
```

Atteso: un indirizzo `https://ecommerce.test/…/gestionale/stripe/webhook/`.

Avvia l'inoltro in background (gli intenti vivono sul conto collegato, quindi servono gli eventi Connect):

```bash
stripe listen --forward-connect-to "<indirizzo del passo sopra>" --skip-verify --events payment_intent.succeeded,payment_intent.payment_failed,payment_intent.canceled,charge.refunded
```

La CLI stampa un segreto `whsec_…`: lo sviluppatore lo mette in `.env` come `STRIPE_TEST_WEBHOOK_SECRET`. Chi esegue non lo copia nelle risposte.

- [ ] **Passo 3: pagamento riuscito.**

Nel browser, su `https://ecommerce.test/`: metti un prodotto nel carrello e apri `/checkout/`. Se il checkout chiede l'accesso, l'accesso lo fa lo sviluppatore (mai inserire la password al suo posto); con «Ordini senza account» acceso si prova da ospite. Compila i dati, scegli il metodo Stripe e controlla che sotto i metodi compaia il Payment Element. Inserisci la carta `4242 4242 4242 4242`, una scadenza futura qualsiasi, CVC `123`, e premi il bottone d'invio.

Atteso:
- la pagina «Ordine completato»;
- nella finestra della CLI `payment_intent.succeeded` con risposta `200`;
- l'ordine confermato e pagato, con una sola riga di pagamento confermata in ambiente `test`.

Controlla l'ordine con questo script (scrivilo nella cartella di lavoro della sessione, non nel repository):

```php
<?php
// ultimo-ordine.php — stato dell'ultimo ordine e dei suoi pagamenti
$GLOBALS['ROOT'] = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';
chdir($GLOBALS['ROOT']);
require 'vendor/autoload.php';
require 'vendor/wonder-image/app/wonder-image.php';

$ordini = sqlSelect('gst_orders', ['deleted' => 'false'], 2, 'id', 'DESC')->row;
foreach ((array) $ordini as $ordine) {
    $ordine = (array) $ordine;
    echo "ordine {$ordine['id']} {$ordine['order_number']}: {$ordine['status']} / {$ordine['payment_status']}", PHP_EOL;
    foreach ((array) sqlSelect('gst_payments', ['order_id' => (int) $ordine['id']])->row as $riga) {
        $riga = (array) $riga;
        echo "  pagamento {$riga['id']}: {$riga['status']} {$riga['provider']} {$riga['environment']}", PHP_EOL;
    }
}
```

```bash
php ultimo-ordine.php
```

Atteso: l'ordine più recente `confirmed / paid`, una riga `confirmed stripe test`. L'email «confermato» arriva una volta sola (nella casella di prova o nel registro delle email del sito).

- [ ] **Passo 4: carta rifiutata, poi riuscita, con lo stesso ordine.**

Nuovo carrello, checkout, metodo Stripe, carta `4000 0000 0000 0002`.

Atteso: il messaggio di rifiuto sotto il Payment Element, il modulo bloccato (campi e coupon non si cambiano più), il link «Annulla l'ordine» visibile. Senza ricaricare, inserisci `4242 4242 4242 4242` e premi di nuovo il bottone.

Atteso: «Ordine completato». `php ultimo-ordine.php` mostra **un solo** ordine nuovo, `confirmed / paid`, con una sola riga di pagamento `confirmed` (lo stesso intento riusato). Nessun ordine in più rispetto al passo 3.

- [ ] **Passo 5: 3DS annullato, ritorno e pagina «Paga ora».**

Nuovo carrello, checkout, metodo Stripe, carta `4000 0027 6000 3184`. Nella finestra di autenticazione di prova premi «Fail authentication».

Atteso: il messaggio d'errore e il modulo bloccato; se Stripe ha fatto un giro di redirect, si torna su `/checkout/pay/` con l'avviso del pagamento non riuscito, mai su un checkout vuoto. Ricarica `/checkout/`: deve portarti a `/checkout/pay/` (l'ordine in sessione è in attesa e il carrello è vuoto). Su `/checkout/pay/` paga con `4000 0027 6000 3184` e premi «Complete authentication».

Atteso: «Ordine completato» e, con `php ultimo-ordine.php`, l'ordine `confirmed / paid`.

- [ ] **Passo 6: «Annulla l'ordine».**

Nuovo carrello, checkout, metodo Stripe, carta `4000 0000 0000 0002` (rifiutata), poi «Annulla l'ordine».

Atteso: si torna al carrello con l'avviso dell'ordine annullato; `php ultimo-ordine.php` mostra l'ordine `cancelled` e nessuna riga `confirmed`; nella CLI arriva `payment_intent.canceled` con `200`; la merce torna disponibile.

- [ ] **Passo 7: il bollino «Prova» nel backend.**

Chiedi allo sviluppatore di aprire (con il suo accesso) l'ordine del passo 3 in Vendite > Ordini.

Atteso: accanto allo stato del pagamento compare il bollino «Prova».

Ferma la CLI (`stripe listen`).

- [ ] **Passo 8: TODO dei due moduli.**

In `TODO.md` del gestionale, sotto la voce `E1c Carrello, checkout con Stripe…`, aggiungi dopo l'ultima sotto-voce del checkout a pagina unica:

```markdown
    - [ ] Pagamento con Stripe — [spec](docs/superpowers/specs/2026-10-09-pagamento-stripe-design.md)
      - [x] Piano 1 [Payment Element](docs/superpowers/plans/2026-10-09-pagamento-stripe-piano-1.md) **fatto** (AAAA-MM-GG) in app, gestionale ed ecommerce, ramo `pagamento-stripe`, **non ancora unito né spinto**: credenziali pubbliche e segreti del webhook per ambiente, «Collega webhook», `StripeProvider`, registro dei pagamenti online, webhook, riallineamento orario, `place` in JSON, pagina «Paga ora», bollino «Prova». Provato su `ecommerce.test` con le carte di prova (riuscito, rifiutato e poi riuscito, 3DS, annullato)
      - [ ] Piano 2 Express Checkout (§11), dopo l'unione del piano 1
```

con la data del giorno al posto di `AAAA-MM-GG`. Nell'ecommerce, `TODO.md`: segna `D6` come fatto (`- [x]`) aggiungendo in coda alla voce «Stripe con il Payment Element, piano 1 del gestionale `2026-10-09-pagamento-stripe-piano-1`»; in `D8` aggiungi «pagamento riuscito, rifiutato, 3DS e annullato provati con Stripe in test». Se una prova del passo 3–7 è rimasta aperta, scrivila nella voce al posto di «Provato…».

```bash
cd /Users/andreamarinoni/Developer/worktrees/pagamento-stripe/gestionale && git add TODO.md && git commit -m "TODO: pagamento con Stripe, piano 1 fatto

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
cd /Users/andreamarinoni/Developer/worktrees/pagamento-stripe/ecommerce && git add TODO.md && git commit -m "TODO: D6 Stripe con il Payment Element

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Passo 9: suite finali, con il sito di prova ancora sui worktree.**

```bash
cd /Users/andreamarinoni/Developer/worktrees/pagamento-stripe/gestionale && php tests/run.php > /tmp/fine-gestionale.txt; tail -3 /tmp/fine-gestionale.txt
cd /Users/andreamarinoni/Developer/worktrees/pagamento-stripe/ecommerce && php tests/run.php > /tmp/fine-ecommerce.txt; tail -3 /tmp/fine-ecommerce.txt
cd /Users/andreamarinoni/Developer/worktrees/pagamento-stripe/app && php tests/run.php > /tmp/fine-app.txt; tail -5 /tmp/fine-app.txt
```

Atteso: «Tutti i test del gestionale passano.» e «Tutti i test dell'ecommerce passano.», con più file delle basi di P4 (gestionale 192, ecommerce 24); in app l'unico rosso è `tests/scheduler-integration.php`.

- [ ] **Passo 10: ripristino del sito di prova.**

Solo dopo le suite: il sito di prova torna a puntare ai pacchetti.

```bash
for x in app gestionale ecommerce; do ln -sfn ../../../../packages/$x/ /Users/andreamarinoni/Developer/boilerplates/ecommerce-site/vendor/wonder-image/$x; done
ls -l /Users/andreamarinoni/Developer/boilerplates/ecommerce-site/vendor/wonder-image/
```

Atteso: i tre collegamenti puntano a `../../../../packages/…`. Lo schema del database resta quello nuovo: le colonne in più non danno fastidio al codice di `main`.

Niente push, PR o merge: si chiede all'utente come unire i tre rami.

---
