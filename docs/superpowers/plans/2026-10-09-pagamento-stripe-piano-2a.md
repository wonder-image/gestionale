# Pagamento con Stripe, piano 2a (metodi Stripe separati sotto la carta) — piano di lavoro

> **Per chi esegue:** SOTTO-SKILL OBBLIGATORIA: superpowers:subagent-driven-development (consigliata) oppure superpowers:executing-plans, un compito alla volta. I passi usano le caselle (`- [ ]`) per tenere il conto.

**Obiettivo:** nel checkout «Carta di credito» resta solo carta. Sotto compare una scelta per ogni metodo acceso nel conto Stripe (Klarna, Link, PayPal, Bancontact…). Apple Pay e Google Pay non sono fra le scelte: andranno come bottoni separati nella barra rapida in cima (piano 2b), che qui non si tocca.

**Architettura:** l'app legge le configurazioni dei metodi del conto collegato. Il gestionale ne ricava i tipi disponibili, con 10 minuti di cache in `gst_settings`. Da una riga Stripe di `gst_payment_methods` fa nascere più scelte, convalida la scelta in `place`, la salva su `gst_payments.provider_method` e apre l'intento con i soli tipi di quella scelta. L'ecommerce dà a ogni scelta il suo valore di radio (`id` oppure `id:tipo`). In `checkout.js` ogni scelta Stripe ha il suo gruppo `elements` con un Payment Element limitato ai tipi della scelta.

**Tecnologie:** PHP 8.2, stripe-php 19.4 (`$client->paymentMethodConfigurations->all()`), Stripe.js v3, JS senza build, test con `check()` di `tests/harness.php`.

**Spec:** `docs/superpowers/specs/2026-10-09-pagamento-stripe-design.md`, §11b, più §3 (riga «Tipi di pagamento»), §11 («Dove compare») e §12–§14 per il piano 2a. Chi esegue legge spec e piano insieme.

## Vincoli globali

- Branch `stripe-express` in tre worktree: `/Users/andreamarinoni/Developer/worktrees/stripe-express/{app,gestionale,ecommerce}`. Mai `git switch` nelle cartelle `packages/*`: altre sessioni ci lavorano.
- Nessun `git stash`, mai `git add -A`. Si mettono in stage solo i file del compito, per nome.
- Messaggi di commit in italiano, chiusi da `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Niente push, PR o merge senza il sì esplicito dell'utente.
- Le chiavi Stripe vere non entrano mai nel codice, nei test, nei log o nelle risposte. Nei test: `sk_test_prova`, `pk_test_prova`, `whsec_prova`, conti `acct_prova_test`/`acct_prova_live`. Mai stampare un `client_secret`.
- I test non mandano email: le suite girano con `WONDER_NO_MAIL=1`.
- Mai composer nel sito di prova.
- TDD: ogni test si vede fallire prima di scrivere il codice.
- Nessuna chiamata HTTP a Stripe dentro `Transaction::run`: i tipi si leggono prima di aprire la transazione e si passano dentro.
- Valore del radio del pagamento: `"{id}"` per i metodi non Stripe e per la carta, `"{id}:{tipo}"` per le altre scelte Stripe (`"891:klarna"`, `"891:link"`). Lo spezza una sola funzione, `CheckoutRules::post`. Al carrello va sempre l'id nudo. `payment_methods.selected` dell'anteprima resta l'id del metodo.
- Su una riga Stripe un `stripe_method_type` vuoto vale `card` (modulo senza JS, chiamate vecchie). Un tipo che non è fra le scelte risponde `order.payment_method_unavailable`.
- Il CSV `stripe_payment_method_types` non filtra più il checkout: vale solo per i pagamenti senza `provider_method` (§3).
- Stringhe che i test statici dell'ecommerce fissano e che devono restare: `this.stripeBox(payload)`, `this.stripeBox(this.latest)`, `chosen?.provider === 'stripe'`, `"mode: 'payment'"`, `this.elements.update(`, `stripeAccount`, `await this.elements.submit()` prima di `await this.place()` prima di `await this.stripe.confirmPayment(`, `'total_changed'`, `new URL(this.placed.return_url, window.location.href)`, `if (!this.placed)`, `this.freeze()`, `closest('label')?.querySelector('[data-choice-panel]')`, `this.paymentElement.unmount()`, `this.paymentElement.mount(`, `[data-checkout-stripe-element]`, `create('payment', STRIPE_PAYMENT_ELEMENT)` esattamente 2 volte, `paySpinner(true, this.labels.processing)` esattamente 2 volte, `payAlert('')` esattamente 2 volte, `await this.reopen(` esattamente 2 volte, `'payment_method_types' =>` in `CheckoutSummary.php`. Se un compito deve cambiarne una, lo dice e cambia il test nello stesso commit.
- La sezione `#rapido` della vista, `ExpressCheckout` (oggi `buttons()` restituisce `[]`) e le traduzioni `ecommerce.checkout.express` e `ecommerce.checkout.or` restano come sono: il piano 2b ci mette i bottoni Apple Pay, Google Pay e Link.
- `apple_pay` e `google_pay` non diventano mai scelte del modulo, anche se accesi nel conto. `link` sì.
- Nell'ecommerce altre chat cambiano viste e catalogo su `main`. Le modifiche si ancorano a simboli (`data-checkout-payments`, `stripeBox(`, `function place`), non a numeri di riga.
- Basi delle suite: si annotano nel passo P3 e non devono scendere. In app gli unici rossi ammessi sono `tests/Docs/ApiReferenceTest.php` e `tests/scheduler-integration.php`.

## Punti da guardare nella revisione

1. **Klarna (o un altro metodo) fuori dai limiti d'importo o di paese.** Il Payment Element con quel solo tipo emette `loaderror`: la scelta sparisce e torna selezionata la carta, senza messaggi rossi. → Compito 7, prova nel browser (Compito 8).
2. **Cache scritta in un altro ambiente o per un altro conto.** Passando da test a live, i tipi di prova non devono comparire: la cache non vale e si rilegge. → Compito 3, test «cache di un altro ambiente» e «di un altro conto».
3. **Riuso dell'intento con tipi diversi.** Il cliente paga con la carta, viene rifiutato, poi da «Paga ora» o dal modulo riaperto sceglie Klarna: l'intento `pi_` riusabile si aggiorna con `payment_method_types`, non se ne apre un secondo. → Compito 4, test «riuso con tipi diversi fa update» e «riuso con gli stessi tipi, in altro ordine, niente update».
4. **Link da solo nel Payment Element.** Con `paymentMethodTypes: ['link']` l'elemento deve mostrare il campo email di Link; se non si carica (`loaderror`) la scelta sparisce e torna la carta. → Compito 7, prova nel browser.
5. **Apple Pay e Google Pay accesi nel conto.** Non compaiono fra le scelte, e la carta non ne mostra le icone. → Compito 2 (`choices`, `icons`), Compito 5 (test 1), prova nel browser.

---

## Mappa dei file

**app** (`/Users/andreamarinoni/Developer/worktrees/stripe-express/app`)

| File | Compito | Cosa fa |
|------|---------|---------|
| `class/Plugin/Stripe/PaymentIntent.php` | 1 | `paymentMethodConfigurations()` sul conto collegato |
| `tests/Plugin/Stripe/PaymentIntentTest.php` | 1 | |

**gestionale** (`/Users/andreamarinoni/Developer/worktrees/stripe-express/gestionale`)

| File | Compito | Cosa fa |
|------|---------|---------|
| `src/Providers/Payments/StripeMethods.php` (nuovo) | 2 | tipi da una configurazione, scelte, nomi, icone, tipi dell'intento |
| `tests/StripeMethodsTest.php` (nuovo) | 2 | |
| `src/Models/System/Setting.php` | 3 | colonna `stripe_methods_cache`, esclusa dal deploy |
| `src/Providers/Payments/StripeProvider.php` | 3, 4 | `activeTypes()` con cache; tipi in `start`, `update` al riuso |
| `tests/integrazione/StripeProviderTest.php` | 3, 4 | |
| `src/Models/Payments/Payment.php` | 4 | colonna `provider_method` |
| `src/Support/Payments/Ledger.php` | 4 | `provider_method` nella riga nuova |
| `src/Support/Orders/Checkout.php` | 5 | scelte in `preview`, convalida e `provider_method` in `place` |
| `tests/integrazione/CheckoutStripeMethodsTest.php` (nuovo) | 5 | |

**ecommerce** (`/Users/andreamarinoni/Developer/worktrees/stripe-express/ecommerce`)

| File | Compito | Cosa fa |
|------|---------|---------|
| `src/Frontend/Checkout/CheckoutRules.php` | 6 | `post` spezza `id:tipo` |
| `src/Frontend/Checkout/CheckoutSummary.php` | 6 | passa `key`, `stripe_method_type`, `payment_method_types` del gestionale |
| `src/Frontend/Checkout/CheckoutController.php` | 6 | passa `stripe_method_type` a `Checkout::place` |
| `view/pages/checkout/index.php` | 6 | radio con `key` |
| `tests/CheckoutStripeTest.php`, `tests/CartCheckoutTest.php` | 6, 7 | |
| `resources/assets/js/checkout.js` | 7 | gruppi `elements` per scelta, `loaderror` |

---

## Preparazione

- [ ] **P1: vendor nei tre worktree**, copiati (non collegati) dai pacchetti. Salta quelli che ce l'hanno già.

```bash
for x in app gestionale ecommerce; do [ -d /Users/andreamarinoni/Developer/worktrees/stripe-express/$x/vendor ] || cp -R /Users/andreamarinoni/Developer/packages/$x/vendor /Users/andreamarinoni/Developer/worktrees/stripe-express/$x/; done
```

- [ ] **P2: il sito di prova punta ai worktree.** I test d'integrazione caricano app, gestionale ed ecommerce da `SITE/vendor/wonder-image/*`: senza questo passo un test del gestionale troverebbe la `PaymentIntent` vecchia. Finché i link puntano qui, anche le altre sessioni che usano il sito di prova vedono il codice del ramo: va rimesso a posto alla fine (Compito 8).

```bash
for x in app gestionale ecommerce; do ln -sfn /Users/andreamarinoni/Developer/worktrees/stripe-express/$x/ /Users/andreamarinoni/Developer/boilerplates/ecommerce-site/vendor/wonder-image/$x; done
```

- [ ] **P3: basi verdi.** Esegui le tre suite e annota i numeri nel registro.

```bash
cd /Users/andreamarinoni/Developer/worktrees/stripe-express/gestionale && WONDER_NO_MAIL=1 php tests/run.php > /tmp/base-2a-gestionale.txt 2>&1; tail -3 /tmp/base-2a-gestionale.txt
cd /Users/andreamarinoni/Developer/worktrees/stripe-express/ecommerce && WONDER_NO_MAIL=1 php tests/run.php > /tmp/base-2a-ecommerce.txt 2>&1; tail -3 /tmp/base-2a-ecommerce.txt
cd /Users/andreamarinoni/Developer/worktrees/stripe-express/app && WONDER_NO_MAIL=1 php .claude/skills/run-app/driver.php test > /tmp/base-2a-app.txt 2>&1; tail -5 /tmp/base-2a-app.txt
```

Atteso: «Tutti i test del gestionale passano.», «Tutti i test dell'ecommerce passano.», in app solo i due rossi ammessi.

---

## Compito 1 (app): configurazioni dei metodi del conto collegato

**File:**
- Modifica: `class/Plugin/Stripe/PaymentIntent.php`
- Test: `tests/Plugin/Stripe/PaymentIntentTest.php`

**Interfacce:**
- Produce: `PaymentIntent::paymentMethodConfigurations(): list<array<string, mixed>>`, le configurazioni come array semplici (`toArray()`), lette con `Stripe-Account` del conto collegato.

- [ ] **Passo 1: test che fallisce.** In fondo a `PaymentIntentTest.php`, prima di `summary()` se c'è:

```php
check('le configurazioni dei metodi si leggono sul conto collegato, come array', function () use ($http, $intenti) {
    $http->requests = [];
    $http->queue(200, ['object' => 'list', 'url' => '/v1/payment_method_configurations', 'has_more' => false, 'data' => [
        ['id' => 'pmc_1', 'object' => 'payment_method_configuration', 'active' => true, 'is_default' => true,
         'card' => ['available' => true], 'klarna' => ['available' => false]],
    ]]);
    $lista = $intenti->paymentMethodConfigurations();

    return $http->path(0) === '/v1/payment_method_configurations'
        && $http->header(0, 'Stripe-Account') === 'acct_prova'
        && is_array($lista[0] ?? null)
        && ($lista[0]['card']['available'] ?? null) === true
        && ($lista[0]['is_default'] ?? null) === true;
});
```

- [ ] **Passo 2: vederlo fallire.** `cd /Users/andreamarinoni/Developer/worktrees/stripe-express/app && WONDER_NO_MAIL=1 php tests/Plugin/Stripe/PaymentIntentTest.php` → atteso ✗ con «Call to undefined method».

- [ ] **Passo 3: codice.** In `PaymentIntent`, dopo `refundsOf`:

```php
    /**
     * Le configurazioni dei metodi di pagamento del conto collegato: dicono
     * quali metodi il commerciante ha acceso nella sua dashboard.
     *
     * @return list<array<string, mixed>>
     */
    public function paymentMethodConfigurations(): array
    {
        $list = $this->client->paymentMethodConfigurations->all(['limit' => 100], $this->options);

        return array_map(static fn ($configuration): array => $configuration->toArray(), $list->data);
    }
```

- [ ] **Passo 4: vederlo passare**, poi la suite dell'app (solo i due rossi ammessi).

- [ ] **Passo 5: commit.**

```bash
git -C /Users/andreamarinoni/Developer/worktrees/stripe-express/app add class/Plugin/Stripe/PaymentIntent.php tests/Plugin/Stripe/PaymentIntentTest.php
git -C /Users/andreamarinoni/Developer/worktrees/stripe-express/app commit -m "Stripe: configurazioni dei metodi di pagamento del conto collegato

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Compito 2 (gestionale): `StripeMethods`, le scelte senza rete

**File:**
- Crea: `src/Providers/Payments/StripeMethods.php`
- Test: `tests/StripeMethodsTest.php` (unitario: carica solo `vendor/autoload.php` del worktree e `tests/harness.php`, come gli altri test in `tests/`)

**Interfacce:**
- Produce (tutti `public static`):
  - `typesFrom(list<array> $configurations): list<string>` — tipi con `available === true` della configurazione `is_default && active`, altrimenti della prima `active`; nessuna attiva → `[]`. Ordine come arriva.
  - `choices(list<string> $types): list<string>` — `'card'` sempre per primo; poi gli altri tipi in ordine alfabetico, esclusi `card`, `apple_pay`, `google_pay` (quelli vanno nella barra rapida del 2b).
  - `name(string $choice, string $cardName): string` — `card` → `$cardName`; altrimenti dalla mappa `NAMES`, di riserva `ucfirst(str_replace('_', ' ', $choice))`.
  - `icons(string $choice, list<string> $rowIcons): list<string>` — `card` → icone della riga senza `NOT_CARD_ICONS` (`apple_pay`, `google_pay`, `klarna`, `paypal`); altrimenti `[$choice]` se è in `PaymentMethod::ICONS`, se no `['genericbank']` (l'icona generica).
  - `intentTypes(string $choice): list<string>` — `[$choice]` (la carta dà `['card']`).

- [ ] **Passo 1: test che fallisce.** `tests/StripeMethodsTest.php`:

```php
<?php
/** php tests/StripeMethodsTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Gestionale\Providers\Payments\StripeMethods;

$predefinita = ['id' => 'pmc_def', 'active' => true, 'is_default' => true, 'livemode' => false, 'name' => 'Default',
    'card' => ['available' => true], 'apple_pay' => ['available' => true], 'klarna' => ['available' => true],
    'paypal' => ['available' => false], 'link' => ['available' => true], 'sepa_debit' => ['available' => true]];
$altra = ['id' => 'pmc_alt', 'active' => true, 'is_default' => false, 'card' => ['available' => true], 'bancontact' => ['available' => true]];

check('i tipi vengono dalla configurazione predefinita e attiva, solo quelli disponibili', fn () =>
    StripeMethods::typesFrom([$altra, $predefinita]) === ['card', 'apple_pay', 'klarna', 'link', 'sepa_debit']);

check('senza predefinita attiva vale la prima attiva', fn () =>
    StripeMethods::typesFrom([['active' => false, 'is_default' => true, 'card' => ['available' => true]], $altra]) === ['card', 'bancontact']);

check('nessuna configurazione attiva: nessun tipo', fn () =>
    StripeMethods::typesFrom([['active' => false, 'is_default' => true, 'card' => ['available' => true]]]) === []);

check('le scelte: carta, poi gli altri in ordine alfabetico, Link compreso', fn () =>
    StripeMethods::choices(['sepa_debit', 'card', 'klarna', 'link']) === ['card', 'klarna', 'link', 'sepa_debit']);

check('Apple Pay e Google Pay non sono scelte; la carta c\'è sempre', fn () =>
    StripeMethods::choices(['apple_pay', 'google_pay', 'klarna']) === ['card', 'klarna'] && StripeMethods::choices([]) === ['card']);

check('nomi: la carta tiene quello della riga, gli sconosciuti diventano leggibili', fn () =>
    StripeMethods::name('card', 'Carta di credito') === 'Carta di credito'
    && StripeMethods::name('link', 'x') === 'Link'
    && StripeMethods::name('klarna', 'x') === 'Klarna'
    && StripeMethods::name('sepa_debit', 'x') === 'Addebito SEPA'
    && StripeMethods::name('nuovo_metodo', 'x') === 'Nuovo metodo');

check('icone: la carta perde wallet e metodi separati, gli sconosciuti hanno l\'icona generica', fn () =>
    StripeMethods::icons('card', ['visa', 'master', 'google_pay', 'apple_pay', 'klarna', 'paypal']) === ['visa', 'master']
    && StripeMethods::icons('klarna', []) === ['klarna']
    && StripeMethods::icons('bancontact', []) === ['genericbank']);

check('tipi dell\'intento: solo quello della scelta', fn () =>
    StripeMethods::intentTypes('card') === ['card'] && StripeMethods::intentTypes('klarna') === ['klarna']);

summary();
```

Se `harness.php` non definisce `summary()`, chiudi il file come gli altri test di `tests/` (guarda `tests/ErrorsTest.php`).

- [ ] **Passo 2: vederlo fallire.** `cd /Users/andreamarinoni/Developer/worktrees/stripe-express/gestionale && php tests/StripeMethodsTest.php` → atteso errore «Class … StripeMethods not found».

- [ ] **Passo 3: codice.** `src/Providers/Payments/StripeMethods.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Providers\Payments;

use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;

/**
 * Le scelte che un solo metodo Stripe offre nel checkout: la carta e una
 * scelta per ogni altro metodo acceso nel conto (§11b). Apple Pay e Google
 * Pay stanno nella barra rapida (§11), non qui. Non si chiama Stripe: si
 * lavora sui tipi già letti.
 */
final class StripeMethods
{
    /** Vanno nella barra rapida, non fra le scelte del modulo. */
    private const WALLETS = ['apple_pay', 'google_pay'];

    /** Le icone che la carta non mostra: hanno un altro posto. */
    private const NOT_CARD_ICONS = ['apple_pay', 'google_pay', 'klarna', 'paypal'];

    private const NAMES = [
        'affirm' => 'Affirm',
        'afterpay_clearpay' => 'Clearpay',
        'alipay' => 'Alipay',
        'amazon_pay' => 'Amazon Pay',
        'bancontact' => 'Bancontact',
        'blik' => 'BLIK',
        'eps' => 'EPS',
        'ideal' => 'iDEAL',
        'klarna' => 'Klarna',
        'link' => 'Link',
        'mobilepay' => 'MobilePay',
        'multibanco' => 'Multibanco',
        'p24' => 'Przelewy24',
        'paypal' => 'PayPal',
        'revolut_pay' => 'Revolut Pay',
        'satispay' => 'Satispay',
        'sepa_debit' => 'Addebito SEPA',
        'twint' => 'TWINT',
        'wechat_pay' => 'WeChat Pay',
    ];

    /**
     * @param list<array<string, mixed>> $configurations
     * @return list<string>
     */
    public static function typesFrom(array $configurations): array
    {
        $active = array_values(array_filter($configurations, static fn (array $c): bool => ($c['active'] ?? false) === true));
        $chosen = null;

        foreach ($active as $configuration) {
            if (($configuration['is_default'] ?? false) === true) {
                $chosen = $configuration;
                break;
            }
        }

        $chosen ??= $active[0] ?? [];
        $types = [];

        foreach ($chosen as $key => $value) {
            if (is_array($value) && ($value['available'] ?? null) === true) {
                $types[] = (string) $key;
            }
        }

        return $types;
    }

    /**
     * @param list<string> $types
     * @return list<string>
     */
    public static function choices(array $types): array
    {
        $others = array_values(array_diff($types, ['card'], self::WALLETS));
        sort($others);

        return array_merge(['card'], $others);
    }

    public static function name(string $choice, string $cardName): string
    {
        return match ($choice) {
            'card' => $cardName,
            default => self::NAMES[$choice] ?? ucfirst(str_replace('_', ' ', $choice)),
        };
    }

    /**
     * @param list<string> $rowIcons
     * @return list<string>
     */
    public static function icons(string $choice, array $rowIcons): array
    {
        return match ($choice) {
            'card' => array_values(array_diff($rowIcons, self::NOT_CARD_ICONS)),
            default => isset(PaymentMethod::ICONS[$choice]) ? [$choice] : ['genericbank'],
        };
    }

    /** @return list<string> i tipi del PaymentIntent per questa scelta */
    public static function intentTypes(string $choice): array
    {
        return [$choice];
    }
}
```

- [ ] **Passo 4: vederlo passare.** `php tests/StripeMethodsTest.php` → tutti ✓.

- [ ] **Passo 5: commit.**

```bash
git -C /Users/andreamarinoni/Developer/worktrees/stripe-express/gestionale add src/Providers/Payments/StripeMethods.php tests/StripeMethodsTest.php
git -C /Users/andreamarinoni/Developer/worktrees/stripe-express/gestionale commit -m "Stripe: le scelte del checkout dai tipi accesi nel conto

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Compito 3 (gestionale): `StripeProvider::activeTypes()` con la cache

**File:**
- Modifica: `src/Models/System/Setting.php`, `src/Providers/Payments/StripeProvider.php`
- Test: `tests/integrazione/StripeProviderTest.php`

**Interfacce:**
- Consuma: `PaymentIntent::paymentMethodConfigurations()` (Compito 1), `StripeMethods::typesFrom()` (Compito 2).
- Produce: `StripeProvider::activeTypes(): list<string>` (metodo d'istanza); `StripeProvider::forget(): void` (statico, svuota la memoria per richiesta; lo usano i test); `StripeProvider::CACHE_SECONDS = 600`.

Comportamento (spec §11b, «Cache»):
- Memoria statica per richiesta, con chiave `ambiente|conto`.
- Poi `Setting::current()['stripe_methods_cache']`: JSON `{environment, account, types, fetched_at}`. Vale se ambiente e conto coincidono e `time() - fetched_at < 600`.
- Altrimenti `GET /v1/payment_method_configurations`; se va, si scrive la cache con `Setting::update(['stripe_methods_cache' => json], (int) Setting::current()['id'])`.
- Se la chiamata fallisce: cache scaduta dello stesso ambiente e conto, se c'è; altrimenti `['card']`. L'errore si registra con il canale d'errore già usato in `StripeProvider` (guarda come `customer()` gestisce un `Throwable`), mai con le chiavi.
- Non connesso (chiavi mancanti) → `['card']`, senza chiamate.

- [ ] **Passo 1: colonna.** In `Setting::syncSchema()` aggiungi `'stripe_methods_cache'` all'`exclude([...])` accanto a `'merchant_notification_emails'`. Fra le colonne aggiungi `Column::key('stripe_methods_cache')->type('TEXT'),` e in `dataSchema` `Field::key('stripe_methods_cache')->text()->sanitize(false),`. Poi aggiorna lo schema del sito di prova:

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update
```

Dopo `forge update` ricontrolla che il metodo carta (id 891) sia ancora attivo: `forge update --local` in passato ha rimesso `active` a `false`. Se è spento, riaccendilo con `PaymentMethod::update(['active' => 'true'], 891)` da uno script nello scratchpad e annotalo nel registro.

- [ ] **Passo 2: test che falliscono.** In `StripeProviderTest.php`, dopo i test esistenti di `start`. `FakeStripeHttp` è già installato nel file (guarda come lo usano i test di `start`: `$http->queue(...)`, `$http->requests`). Ogni test parte con `StripeProvider::forget()` e scrive la cache a mano dentro `prova()`, così niente resta nel DB.

```php
function configurazioni(array $tipi): array
{
    $configurazione = ['id' => 'pmc_prova', 'object' => 'payment_method_configuration', 'active' => true, 'is_default' => true];
    foreach ($tipi as $tipo) {
        $configurazione[$tipo] = ['available' => true];
    }

    return ['object' => 'list', 'url' => '/v1/payment_method_configurations', 'has_more' => false, 'data' => [$configurazione]];
}

function cacheMetodi(?array $valore): void
{
    $id = (int) \Wonder\Plugin\Gestionale\Models\System\Setting::current()['id'];
    \Wonder\Plugin\Gestionale\Models\System\Setting::update(['stripe_methods_cache' => $valore === null ? '' : json_encode($valore)], $id);
}

check('senza cache i tipi si leggono da Stripe e si mettono in cache', fn () => prova(function () use ($http) {
    StripeProvider::forget();
    cacheMetodi(null);
    $http->requests = [];
    $http->queue(200, configurazioni(['card', 'klarna']));
    $tipi = (new StripeProvider(chiavi()))->activeTypes();
    $cache = json_decode((string) \Wonder\Plugin\Gestionale\Models\System\Setting::current()['stripe_methods_cache'], true);

    return $tipi === ['card', 'klarna']
        && $http->path(0) === '/v1/payment_method_configurations'
        && $http->header(0, 'Stripe-Account') === 'acct_prova_test'
        && $cache['environment'] === 'test' && $cache['account'] === 'acct_prova_test' && $cache['types'] === ['card', 'klarna'];
}));

check('cache valida: nessuna chiamata', fn () => prova(function () use ($http) {
    StripeProvider::forget();
    cacheMetodi(['environment' => 'test', 'account' => 'acct_prova_test', 'types' => ['card', 'paypal'], 'fetched_at' => time() - 60]);
    $http->requests = [];

    return (new StripeProvider(chiavi()))->activeTypes() === ['card', 'paypal'] && $http->requests === [];
}));

check('cache scaduta: si rilegge', fn () => prova(function () use ($http) {
    StripeProvider::forget();
    cacheMetodi(['environment' => 'test', 'account' => 'acct_prova_test', 'types' => ['card', 'paypal'], 'fetched_at' => time() - 601]);
    $http->requests = [];
    $http->queue(200, configurazioni(['card', 'klarna']));

    return (new StripeProvider(chiavi()))->activeTypes() === ['card', 'klarna'] && count($http->requests) === 1;
}));

check('cache di un altro ambiente: non vale', fn () => prova(function () use ($http) {
    StripeProvider::forget();
    cacheMetodi(['environment' => 'test', 'account' => 'acct_prova_test', 'types' => ['card', 'paypal'], 'fetched_at' => time()]);
    $http->requests = [];
    $http->queue(200, configurazioni(['card']));
    $tipi = (new StripeProvider(chiavi(['stripe_test' => false])))->activeTypes();

    return $tipi === ['card'] && $http->header(0, 'Stripe-Account') === 'acct_prova_live';
}));

check('cache di un altro conto: non vale', fn () => prova(function () use ($http) {
    StripeProvider::forget();
    cacheMetodi(['environment' => 'test', 'account' => 'acct_vecchio', 'types' => ['card', 'paypal'], 'fetched_at' => time()]);
    $http->requests = [];
    $http->queue(200, configurazioni(['card', 'klarna']));

    return (new StripeProvider(chiavi()))->activeTypes() === ['card', 'klarna'];
}));

check('chiamata fallita con cache scaduta: si usa la cache', fn () => prova(function () use ($http) {
    StripeProvider::forget();
    cacheMetodi(['environment' => 'test', 'account' => 'acct_prova_test', 'types' => ['card', 'paypal'], 'fetched_at' => time() - 9999]);
    $http->queue(500, ['error' => ['message' => 'giù']]);

    return (new StripeProvider(chiavi()))->activeTypes() === ['card', 'paypal'];
}));

check('chiamata fallita senza cache: solo la carta', fn () => prova(function () use ($http) {
    StripeProvider::forget();
    cacheMetodi(null);
    $http->queue(500, ['error' => ['message' => 'giù']]);

    return (new StripeProvider(chiavi()))->activeTypes() === ['card'];
}));

check('nella stessa richiesta Stripe si chiama una volta sola', fn () => prova(function () use ($http) {
    StripeProvider::forget();
    cacheMetodi(null);
    $http->requests = [];
    $http->queue(200, configurazioni(['card', 'klarna']));
    $provider = new StripeProvider(chiavi());
    $provider->activeTypes();
    cacheMetodi(null);

    return $provider->activeTypes() === ['card', 'klarna'] && count($http->requests) === 1;
}));
```

Se `FakeStripeHttp` del gestionale (`tests/integrazione/supporto/FakeStripeHttp.php`) non ha `queue`, `requests`, `path`, `header` con questi nomi, usa i suoi e annota la differenza nel registro. Una risposta 500 con stripe-php ritenta: se `FakeStripeHttp` non ha più risposte in coda, controlla che lanci un errore e non restituisca la risposta di un altro test (se serve, metti in coda due 500).

- [ ] **Passo 3: vederli fallire.** `cd /Users/andreamarinoni/Developer/worktrees/stripe-express/gestionale && WONDER_NO_MAIL=1 php tests/integrazione/StripeProviderTest.php` → i nuovi ✗ («undefined method forget/activeTypes»), i vecchi ✓.

- [ ] **Passo 4: codice.** In `StripeProvider`:

```php
    public const CACHE_SECONDS = 600;

    /** @var array<string, list<string>> ambiente|conto → tipi, per la richiesta in corso */
    private static array $types = [];

    public static function forget(): void
    {
        self::$types = [];
    }

    /**
     * I tipi di pagamento accesi nel conto collegato (§11b). Si legge Stripe
     * al massimo ogni dieci minuti; se Stripe non risponde vale l'ultima
     * lettura, e senza nessuna lettura resta la carta.
     *
     * @return list<string>
     */
    public function activeTypes(): array
    {
        if (!$this->connected()) {
            return ['card'];
        }

        $environment = $this->environment();
        $account = $this->keys($environment)['account'];
        $memo = $environment.'|'.$account;

        if (isset(self::$types[$memo])) {
            return self::$types[$memo];
        }

        $settings = Setting::current();
        $cache = json_decode((string) ($settings['stripe_methods_cache'] ?? ''), true);
        $same = is_array($cache) && ($cache['environment'] ?? '') === $environment && ($cache['account'] ?? '') === $account && is_array($cache['types'] ?? null);

        if ($same && time() - (int) ($cache['fetched_at'] ?? 0) < self::CACHE_SECONDS) {
            return self::$types[$memo] = array_values($cache['types']);
        }

        try {
            $types = StripeMethods::typesFrom($this->intents($environment)->paymentMethodConfigurations());
            Setting::update(['stripe_methods_cache' => json_encode([
                'environment' => $environment,
                'account' => $account,
                'types' => $types,
                'fetched_at' => time(),
            ])], (int) $settings['id']);
        } catch (Throwable $error) {
            // Registra l'errore come fa già il resto della classe (niente chiavi nel messaggio).
            $types = $same ? array_values($cache['types']) : ['card'];
        }

        return self::$types[$memo] = $types;
    }
```

Adatta i nomi a quelli veri della classe: la chiave del conto in `keys()` (guarda come `intents()` la passa a `new PaymentIntent(...)`), e il modo in cui la classe registra gli errori. Aggiungi `use Wonder\Plugin\Gestionale\Models\System\Setting;`. Se `typesFrom` restituisce `[]` (nessuna configurazione attiva), salva `[]` e restituisci `[]`: le scelte avranno comunque la carta (`StripeMethods::choices`).

- [ ] **Passo 5: vederli passare**, poi l'intera suite del gestionale (`WONDER_NO_MAIL=1 php tests/run.php > file 2>&1; tail -3 file`).

- [ ] **Passo 6: commit.**

```bash
git -C /Users/andreamarinoni/Developer/worktrees/stripe-express/gestionale add src/Models/System/Setting.php src/Providers/Payments/StripeProvider.php tests/integrazione/StripeProviderTest.php
git -C /Users/andreamarinoni/Developer/worktrees/stripe-express/gestionale commit -m "Stripe: tipi accesi nel conto, con dieci minuti di cache nelle impostazioni

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Compito 4 (gestionale): `provider_method` e tipi dell'intento

**File:**
- Modifica: `src/Models/Payments/Payment.php`, `src/Support/Payments/Ledger.php`, `src/Providers/Payments/StripeProvider.php`
- Test: `tests/integrazione/StripeProviderTest.php`, `tests/integrazione/LedgerTest.php`

**Interfacce:**
- Consuma: `StripeMethods::intentTypes()`.
- Produce: colonna `gst_payments.provider_method` (VARCHAR 40, vuota di default); `Ledger::open(['provider_method' => 'klarna', ...])` la scrive; `StripeProvider::start($order, $payment)` legge `$payment['provider_method']`.

Tipi in `start`:
- `provider_method` non vuoto → `StripeMethods::intentTypes($payment['provider_method'])` in `payment_method_types`.
- vuoto → come oggi: CSV della riga, altrimenti `automatic_payment_methods`.
- Riuso di un `pi_` in stato riusabile: se i tipi voluti non sono vuoti e, ordinati, differiscono da quelli ordinati di `$old->payment_method_types`, `$intents->update($old->id, ['payment_method_types' => $types])` e si restituisce l'intento aggiornato. Se i tipi voluti sono vuoti, l'intento resta com'è.

- [ ] **Passo 1: colonna.** In `Payment`: `Column::key('provider_method')->length(40),` dopo `provider_reference`, e `Field::key('provider_method')->text()->sanitize(false),` nel `dataSchema`. In `Ledger::write`, nel `Payment::create([...])`, dopo `'provider' => $provider,`: `'provider_method' => (string) ($data['provider_method'] ?? ''),`. Poi `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update` e ricontrolla il metodo 891 come nel Compito 3.

- [ ] **Passo 2: test che falliscono.** In `LedgerTest.php`, con lo stesso schema degli altri test di `open` del file:

```php
check('open scrive il metodo scelto dentro il gateway', fn () => prova(function () {
    $ordine = ordineDiProva(10.0);
    $esito = Ledger::open(['order_id' => $ordine, 'amount' => 10.0, 'provider' => 'stripe', 'provider_method' => 'klarna']);

    return (string) Payment::findById($esito['payment_id'])['provider_method'] === 'klarna';
}));
```

(Se `LedgerTest` non usa `prova()`, usa lo stesso modo di annullare i dati che usano i suoi test.)

In `StripeProviderTest.php`. Le richieste HTTP da controllare sono quelle a `/v1/payment_intents`: trova l'indice con un ciclo su `$http->requests`, o usa il campo del corpo come fanno i test di `start` già presenti.

```php
check('start con provider_method klarna: solo klarna', fn () => prova(function () use ($http, $ordine, $pagamento) {
    $http->requests = [];
    $http->queue(200, ['id' => 'cus_prova', 'object' => 'customer']); // solo se start crea il Customer: copia la coda dei test di start esistenti
    $http->queue(200, intento());
    (new StripeProvider(chiavi()))->start($ordine, $pagamento + ['provider_method' => 'klarna']);
    $corpo = /* corpo della richiesta POST /v1/payment_intents, decodificato come nei test esistenti */;

    return ($corpo['payment_method_types'] ?? null) === ['klarna'] && !isset($corpo['automatic_payment_methods']);
}));
```

Scrivi allo stesso modo, copiando la preparazione dal test sopra:
- `provider_method` `card` → `['card']`;
- `provider_method` `link` → `['link']`;
- `provider_method` vuoto e CSV vuoto → `automatic_payment_methods[enabled]` presente, `payment_method_types` assente;
- **riuso con tipi diversi:** `$pagamento['provider_reference'] = 'pi_vecchio'`, coda: GET `intento(['id' => 'pi_vecchio', 'payment_method_types' => ['card']])`, poi POST di update `intento(['id' => 'pi_vecchio', 'payment_method_types' => ['klarna']])`; con `provider_method` `klarna` → la seconda richiesta è `POST /v1/payment_intents/pi_vecchio` con `payment_method_types` `['klarna']`, nessuna `POST /v1/payment_intents` di creazione, e `PaymentStart->reference === 'pi_vecchio'`;
- **riuso con gli stessi tipi:** GET `intento(['id' => 'pi_vecchio', 'payment_method_types' => ['card']])`, `provider_method` `card` → una sola richiesta (la GET), niente update;
- **riuso senza scelta:** GET `intento(['id' => 'pi_vecchio', 'payment_method_types' => ['card', 'link']])`, `provider_method` vuoto e CSV vuoto → nessun update.

Le funzioni che decodificano il corpo e cercano la richiesta per percorso le ha già il file (guarda i test «start …» del piano 1): riusale, non scriverne di nuove se ci sono.

- [ ] **Passo 3: vederli fallire.** Lancia i due file di test → i nuovi ✗.

- [ ] **Passo 4: codice in `start`.** Sostituisci il blocco dei tipi e il ramo del riuso:

```php
        $types = $this->intentTypes($order, $payment);

        if (str_starts_with($previous, 'pi_')) {
            $old = $intents->get($previous);

            if (in_array((string) $old->status, self::REUSABLE, true)) {
                // La scelta può essere cambiata dopo un rifiuto: l'intento segue.
                if ($types !== [] && self::sorted($types) !== self::sorted((array) ($old->payment_method_types ?? []))) {
                    $old = $intents->update((string) $old->id, ['payment_method_types' => $types]);
                }

                return new PaymentStart((string) $old->id, (string) $old->client_secret, $environment);
            }
            // … il resto del ramo resta com'è
        }
```

e più sotto:

```php
        if ($types !== []) {
            $params['payment_method_types'] = $types;
        } else {
            $params['automatic_payment_methods'] = ['enabled' => true];
        }
```

con i due aiuti privati:

```php
    /**
     * I tipi dell'intento: quelli della scelta del cliente (§11b); senza
     * scelta il CSV della riga, e se è vuoto decide Stripe (lista vuota).
     *
     * @return list<string>
     */
    private function intentTypes(array $order, array $payment): array
    {
        $choice = trim((string) ($payment['provider_method'] ?? ''));

        if ($choice !== '') {
            return StripeMethods::intentTypes($choice);
        }

        return self::methodTypes((string) ($this->method($order)['stripe_payment_method_types'] ?? ''));
    }

    /** @param list<string> $types @return list<string> */
    private static function sorted(array $types): array
    {
        $types = array_map('strval', $types);
        sort($types);

        return $types;
    }
```

Controlla che `PaymentIntent::update()` dell'app restituisca l'intento aggiornato; se restituisce altro, rileggi con `get`.

- [ ] **Passo 5: vederli passare**, poi la suite del gestionale.

- [ ] **Passo 6: commit.**

```bash
git -C /Users/andreamarinoni/Developer/worktrees/stripe-express/gestionale add src/Models/Payments/Payment.php src/Support/Payments/Ledger.php src/Providers/Payments/StripeProvider.php tests/integrazione/StripeProviderTest.php tests/integrazione/LedgerTest.php
git -C /Users/andreamarinoni/Developer/worktrees/stripe-express/gestionale commit -m "Pagamenti: il metodo scelto dentro Stripe sulla riga, e l'intento ha solo i suoi tipi

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Compito 5 (gestionale): le scelte nel `preview`, la convalida in `place`

**File:**
- Modifica: `src/Support/Orders/Checkout.php`
- Test: crea `tests/integrazione/CheckoutStripeMethodsTest.php`

**Interfacce:**
- Consuma: `StripeProvider::activeTypes()`, `StripeMethods::choices/name/icons/intentTypes`.
- Produce, in ogni elemento di `preview()['payment_methods']['options']`, oltre ai campi di oggi:
  - `key: string` — `"{id}"` per i non Stripe e per la carta, `"{id}:{tipo}"` per le altre scelte Stripe;
  - `stripe_method_type: string` — `''` per i non Stripe, altrimenti `card` o il tipo (`klarna`, `link`…);
  - `payment_method_types: list<string>` — `StripeMethods::intentTypes(...)` per le scelte Stripe, `[]` per gli altri.
  - `name` e `icons` delle scelte Stripe vengono da `StripeMethods::name` e `StripeMethods::icons`.
- `payment_methods.selected` resta l'id del metodo (int).
- `Checkout::place($cartId, $data)` accetta `$data['stripe_method_type']`.

Comportamento:
- In `preview` e in `place`, **prima** di `Transaction::run` (e prima di `create`), si legge una volta `$stripeTypes = self::stripeTypes()`:

```php
    /**
     * I tipi accesi nel conto Stripe, letti fuori dalla transazione: la
     * chiamata a Stripe non deve tenere bloccato il carrello.
     *
     * @return list<string>
     */
    private static function stripeTypes(): array
    {
        $provider = PaymentProviders::get('stripe');

        return $provider instanceof StripeProvider && $provider->connected() ? $provider->activeTypes() : ['card'];
    }
```

- In `preview` le opzioni diventano una lista piatta: ogni riga non Stripe dà una voce; ogni riga Stripe dà una voce per ogni elemento di `StripeMethods::choices($stripeTypes)`, nell'ordine di `choices` (carta, poi gli altri in ordine alfabetico), tutte con lo stesso `id`, `fee`, `fee_type`, `fee_value`, `fee_percent`, `provider`, `manual`, `instructions` della riga.
- In `place`: `create` riceve `$stripeTypes` come terzo argomento. Dopo `$method = self::method(...)`, se `PaymentMethod::ledgerProvider($method['provider']) === 'stripe'`: `$type = trim((string) ($data['stripe_method_type'] ?? '')) ?: 'card'`; se `$type` non è in `StripeMethods::choices($stripeTypes)` → `throw UserError::make('order.payment_method_unavailable')`. `Ledger::open` riceve `'provider_method' => $type`. Per le righe non Stripe il campo si ignora e `provider_method` resta `''`.

- [ ] **Passo 1: test che falliscono.** `tests/integrazione/CheckoutStripeMethodsTest.php`. Prendi l'intestazione (require, `prova()`, `use`) da `tests/integrazione/StripeProviderTest.php`, comprese `chiavi()` e `cacheMetodi()` (copiale: i file di test non si includono a vicenda). Per carrello e metodo segui `tests/integrazione/CheckoutSpedizioneTest.php`: come prepara un carrello pronto per `Checkout::preview`/`place`, come registra un provider con `PaymentProviders::register(...)` e come chiude con `PaymentProviders::reset()`. Il metodo Stripe di prova è una riga di `PaymentMethod` con `provider` `stripe`, attiva, `applies_online` `true`, icone `visa,master,google_pay,apple_pay,klarna`, creata dentro `prova()`.

Test da scrivere (uno per riga, dentro `prova()`, con `StripeProvider::forget()`, `PaymentProviders::register(new StripeProvider(chiavi()))` e `cacheMetodi([... 'types' => ['card', 'apple_pay', 'google_pay', 'link', 'klarna', 'sepa_debit'], 'fetched_at' => time()])`):

1. **scelte nel preview:** le voci del metodo Stripe sono, in ordine, `stripe_method_type` `card`, `klarna`, `link`, `sepa_debit` (niente `apple_pay` né `google_pay`); `key` `"{id}"`, `"{id}:klarna"`, `"{id}:link"`, `"{id}:sepa_debit"`; tutte con lo stesso `id`; la carta ha `name` uguale a quello della riga e icone senza `google_pay`, `apple_pay`, `klarna`; la carta ha `payment_method_types` `['card']`, Klarna `['klarna']`, Link `['link']` con `name` «Link».
2. **i metodi non Stripe restano una voce sola**, con `key` `"{id}"`, `stripe_method_type` `''` e `payment_method_types` `[]` (usa un bonifico creato nel test).
3. **`selected` resta l'id del metodo.**
4. **place con un tipo non in elenco:** `stripe_method_type` `paypal` (spento) e `apple_pay` (acceso ma non è una scelta del modulo) → `UserError` con chiave `order.payment_method_unavailable`.
5. **place senza tipo su Stripe:** nasce il pagamento con `provider_method` `card`.
6. **place con `klarna`:** `provider_method` `klarna` sulla riga di `Payment` dell'ordine.
7. **place su un metodo non Stripe con `stripe_method_type` `klarna`:** il campo si ignora, `provider_method` `''`.

Per vedere `UserError` usa lo stesso schema degli altri test che si aspettano un rifiuto (`try { …; return false; } catch (UserError $e) { return $e->key() === '…'; }`).

- [ ] **Passo 2: vederli fallire.** `WONDER_NO_MAIL=1 php tests/integrazione/CheckoutStripeMethodsTest.php`.

- [ ] **Passo 3: codice.** In `Checkout.php`:
  - aggiungi `use` per `StripeProvider`, `StripeMethods`, `PaymentProviders` (se manca);
  - aggiungi `stripeTypes()` (sopra);
  - in `preview`, prima di `return Transaction::run(...)`: `$stripeTypes = self::stripeTypes();` e passalo nella closure (`use ($cartId, $data, $stripeTypes)`); sostituisci la riga `'options' => array_map(...)` con `'options' => self::paymentOptions($payments, $cartId, $stripeTypes),`;
  - il nuovo aiuto:

```php
    /**
     * Le voci del modulo: una per metodo, e per Stripe una per ogni scelta (§11b).
     *
     * @param list<array<string, mixed>> $methods
     * @param list<string> $stripeTypes
     * @return list<array<string, mixed>>
     */
    private static function paymentOptions(array $methods, int $orderId, array $stripeTypes): array
    {
        $options = [];

        foreach ($methods as $method) {
            $choice = self::paymentChoice($method, $orderId);

            if (PaymentMethod::ledgerProvider($choice['provider']) !== 'stripe') {
                $options[] = $choice + ['key' => (string) $choice['id'], 'stripe_method_type' => '', 'payment_method_types' => []];
                continue;
            }

            foreach (StripeMethods::choices($stripeTypes) as $type) {
                $options[] = array_replace($choice, [
                    'name' => StripeMethods::name($type, $choice['name']),
                    'icons' => StripeMethods::icons($type, $choice['icons']),
                ]) + [
                    'key' => $type === 'card' ? (string) $choice['id'] : $choice['id'].':'.$type,
                    'stripe_method_type' => $type,
                    'payment_method_types' => StripeMethods::intentTypes($type),
                ];
            }
        }

        return $options;
    }
```

  - in `place`: `$stripeTypes = self::stripeTypes();` prima del `try`, e `self::create($cartId, $data, $stripeTypes)`; firma `private static function create(int $cartId, array $data, array $stripeTypes): array` e `use (..., $stripeTypes)` nella sua closure;
  - in `create`, dopo `$method = self::method(...)`, la convalida descritta sopra; nel `Ledger::open([...])` aggiungi `'provider_method' => $stripeMethod,` (dove `$stripeMethod` è `''` per i non Stripe);
  - aggiorna il PHPDoc di `preview` (`payment_methods: array{options: list<array{…, key: string, stripe_method_type: string, payment_method_types: list<string>}>, selected: int}`).

Se altri chiamanti di `create` esistono (grep `self::create(`), passano `self::stripeTypes()`.

- [ ] **Passo 4: vederli passare**, poi la suite del gestionale.

- [ ] **Passo 5: commit.**

```bash
git -C /Users/andreamarinoni/Developer/worktrees/stripe-express/gestionale add src/Support/Orders/Checkout.php tests/integrazione/CheckoutStripeMethodsTest.php
git -C /Users/andreamarinoni/Developer/worktrees/stripe-express/gestionale commit -m "Checkout: una scelta per ogni metodo Stripe acceso, convalidata in place

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Compito 6 (ecommerce): un radio per ogni scelta

**File:**
- Modifica: `src/Frontend/Checkout/CheckoutRules.php`, `src/Frontend/Checkout/CheckoutSummary.php`, `src/Frontend/Checkout/CheckoutController.php`, `view/pages/checkout/index.php`
- Test: `tests/CheckoutStripeTest.php`

**Interfacce:**
- Consuma: le voci del Compito 5 (`key`, `stripe_method_type`, `payment_method_types`, `id`, `name`, `icons`).
- Produce: radio `name="payment_method_id"` con `value` = `key`; `CheckoutRules::post()` restituisce `payment_method_id` (int, l'id) e `stripe_method_type` (stringa, `''` se il valore non ha `:`); ogni opzione di `CheckoutSummary::payload` ha ancora `payment_method_types` (ora preso dal gestionale) più `key` e `stripe_method_type`.

- [ ] **Passo 1: test che falliscono.** In `CheckoutStripeTest.php` aggiungi:

```php
check('il radio del pagamento vale la chiave della scelta; post la spezza in id e tipo', fn () =>
    str_contains($view, "Choice::make('payment_method_id', (string) \$p['key'])")
    && \Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutRules::splitPayment('891:klarna') === [891, 'klarna']
    && \Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutRules::splitPayment('891') === [891, '']
    && \Wonder\Plugin\Ecommerce\Frontend\Checkout\CheckoutRules::splitPayment('x:y') === [0, '']);

check('i tipi della scelta arrivano dal gestionale, non dal CSV', fn () =>
    str_contains($summary, "'payment_method_types' =>")
    && !str_contains($summary, 'stripe_payment_method_types')
    && str_contains($controller, "'stripe_method_type' =>"));

```

`#rapido`, `ExpressCheckout` e il loro test in `CartCheckoutTest.php` non si toccano (Vincoli globali).

- [ ] **Passo 2: vederli fallire.** `cd /Users/andreamarinoni/Developer/worktrees/stripe-express/ecommerce && WONDER_NO_MAIL=1 php tests/CheckoutStripeTest.php`.

- [ ] **Passo 3: codice.**
  - `CheckoutRules`: nuovo `public static function splitPayment(mixed $value): array` → `[int $id, string $type]`; `'891:klarna'` → `[891, 'klarna']`; `'891'` → `[891, '']`; un id non numerico positivo → `[0, '']`; il tipo vale solo se è `[a-z0-9_]{1,40}`, altrimenti `''`. In `post()`, prima del `return`: se `payment_method_id` c'è, `[$post['payment_method_id'], $post['stripe_method_type']] = self::splitPayment($post['payment_method_id']);` (così il riepilogo e il carrello ricevono l'id nudo).
  - `CheckoutSummary::paymentDisplay`: `'payment_method_types' => $stripe ? array_values((array) ($option['payment_method_types'] ?? [])) : [],` al posto della lettura del CSV; togli gli `use` rimasti senza uso (`StripeProvider`, `PaymentMethod` se non serve più altrove nel file). Il PHPDoc di ritorno resta con `payment_method_types`.
  - `CheckoutController::place`: in `CheckoutForm::data([...])` aggiungi `'stripe_method_type' => (string) ($post['stripe_method_type'] ?? ''),` accanto a `'payment_method_id'`, e controlla che `CheckoutForm::data` lo lasci passare (se filtra le chiavi, aggiungilo lì); in alternativa passalo direttamente nell'array di `Checkout::place($cartId, $data + [...])`.
  - `view/pages/checkout/index.php`: la closure `$payment` diventa:

```php
$payment = static fn (array $p): Choice => Choice::make('payment_method_id', (string) $p['key'])
    ->type('radio')->title((string) $p['name'])->aside((string) ($p['fee_display'] ?? ''))
    ->icons((array) ($p['icon_urls'] ?? []))->panel((string) ($p['panel'] ?? ''))
    ->checked((int) $p['id'] === $paymentSelected && in_array((string) ($p['stripe_method_type'] ?? ''), ['', 'card'], true));
```

  Se `Choice::make` accetta solo `int` come valore, guarda la sua firma nella lib: se è `int|string` va bene; se è `int`, annota il problema e usa il modo che la lib offre per un valore stringa (non cambiare la lib in questo piano).

- [ ] **Passo 4: vederli passare**, poi la suite dell'ecommerce (`WONDER_NO_MAIL=1 php tests/run.php > file 2>&1; tail -3 file`).

- [ ] **Passo 5: commit.**

```bash
cd /Users/andreamarinoni/Developer/worktrees/stripe-express/ecommerce
git add src/Frontend/Checkout/CheckoutRules.php src/Frontend/Checkout/CheckoutSummary.php src/Frontend/Checkout/CheckoutController.php view/pages/checkout/index.php tests/CheckoutStripeTest.php
git commit -m "Checkout: un radio per ogni scelta Stripe

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Compito 7 (ecommerce): `checkout.js`, un gruppo `elements` per scelta

**File:**
- Modifica: `resources/assets/js/checkout.js`
- Test: `tests/CheckoutStripeTest.php`

**Interfacce:**
- Consuma: opzioni con `key`, `stripe_method_type`, `payment_method_types`, `provider`; `payload.stripe` (`publishable_key`, `account`, `amount`, `currency`).
- Produce: `this.groups` (oggetto `key → { elements, element, container }`); `this.elements` e `this.paymentElement` puntano sempre al gruppo della scelta corrente (così restano valide le stringhe fissate dai test).

Modifiche, ancorate ai metodi esistenti:

1. **`payments(payload)`**: `value: o.key` al posto di `value: o.id`, e `selected` diventa la chiave della scelta da spuntare: quella del radio già spuntato se esiste ancora fra le opzioni, altrimenti la voce con `o.id === payload.payment_methods.selected` e `stripe_method_type` in `['', 'card']`. Così `choices()` confronta `input.value === String(options[i].value)` sulle chiavi e, con le stesse scelte, non ridisegna (i campi della carta non si ricaricano).
2. **`chosenPayment(payload)`**: cerca per chiave: `checked ? options.find((o) => String(o.key) === checked.value)` ; senza radio spuntato, la voce di `selected` con `stripe_method_type` in `['', 'card']`.
3. **`submit(payload)`**: usa `this.chosenPayment(payload)` al posto della ricerca per id.
4. **`stripeBox(payload)`**: resta il punto d'ingresso (`this.stripeBox(payload)` e `this.stripeBox(this.latest)` non cambiano). Nuovo comportamento:
   - `const stripeOptions = (payload.payment_methods.options || []).filter((o) => o.provider === 'stripe')`; se non ce ne sono, o mancano le chiavi, o `keys.amount <= 0`, nascondi il box e smonta come oggi.
   - Stripe.js si carica come oggi, quando la scelta corrente è Stripe.
   - Per la scelta corrente con `chosen?.provider === 'stripe'`: se il gruppo non c'è, lo crei con `this.stripe.elements({ mode: 'payment', amount, currency, paymentMethodTypes: chosen.payment_method_types })` e `group.elements.create('payment', STRIPE_PAYMENT_ELEMENT)`; per le scelte diverse dalla carta aggiungi `group.element.on('loaderror', () => this.dropChoice(chosen.key))`.
   - `this.elements = group.elements; this.paymentElement = group.element;` poi `this.stripeSlot(true)`.
   - Importi: per ogni gruppo esistente `group.elements.update({ amount, currency })`; per quello corrente la riga resta `this.elements.update(this.stripeOptions)` con `this.stripeOptions = { mode: 'payment', amount: keys.amount, currency: keys.currency }` (la stringa `mode: 'payment'` resta).
5. **`stripeSlot(on)`**: oggi sposta un solo `[data-checkout-stripe-element]`. Ora ogni gruppo ha il suo contenitore: un `div` creato in JS (`document.createElement('div')`, con `dataset.checkoutStripeElement = key`) dentro il pannello della propria scelta (`radio.closest('label')?.querySelector('[data-choice-panel]')`). Montaggio una volta sola per gruppo (`this.paymentElement.mount(` sul contenitore la prima volta); se `choices()` ha ridisegnato le voci e il contenitore non è più nel DOM (`!container.isConnected`), `this.paymentElement.unmount()` e rimonta nel pannello nuovo. Il pannello della scelta corrente si mostra, gli altri si nascondono con `this.pane(...)`. In `choices()` e in `pane()` il filtro `[data-checkout-stripe-element]` deve riconoscere tutti i contenitori (`querySelectorAll`), non uno solo: aggiorna le due funzioni di conseguenza. Il vecchio `[data-checkout-stripe-element]` della vista può restare come primo contenitore della carta.
6. **`dropChoice(key)`**: nasconde la `label` della voce, e se era spuntata spunta la carta Stripe (voce con `stripe_method_type === 'card'`) e richiama `this.stripeBox(this.latest)`. Niente messaggi.
7. **`payOnline()`**: non cambia. Usa `this.elements` e `this.paymentElement`, che puntano al gruppo della scelta corrente.
8. **Stato del modulo** (`ecommerce_checkout_form_state`): il radio ora vale la chiave, quindi la scelta Stripe si ricorda da sola. Controlla che il ripristino cerchi il radio per valore e non converta in numero; se converte, correggi.
9. **`guardSubmit`**: resta `if (this.chosenPayment()?.provider === 'stripe')` → `payOnline()`.

- [ ] **Passo 1: test che falliscono.** In `CheckoutStripeTest.php`:

```php
check('ogni scelta Stripe ha il suo gruppo elements, con i soli tipi della scelta', fn () =>
    str_contains($js, 'this.groups')
    && str_contains($js, 'paymentMethodTypes: chosen.payment_method_types')
    && str_contains($js, "'loaderror'")
    && str_contains($js, 'dropChoice('));

check('il radio del pagamento vale la chiave della scelta', fn () =>
    str_contains($js, 'value: o.key') && !str_contains($js, 'value: o.id }'));
```

- [ ] **Passo 2: vederli fallire.** `WONDER_NO_MAIL=1 php tests/CheckoutStripeTest.php`.

- [ ] **Passo 3: codice**, punti 1–9. Poi `node --check resources/assets/js/checkout.js` (se `node` c'è) per la sintassi.

- [ ] **Passo 4: vederli passare**, e con loro tutti i controlli già presenti in `CheckoutStripeTest.php` e `CheckoutPlaceJsonTest.php` (le stringhe dei Vincoli globali). Poi la suite dell'ecommerce.

- [ ] **Passo 5: commit.**

```bash
git -C /Users/andreamarinoni/Developer/worktrees/stripe-express/ecommerce add resources/assets/js/checkout.js tests/CheckoutStripeTest.php
git -C /Users/andreamarinoni/Developer/worktrees/stripe-express/ecommerce commit -m "Checkout: carta e metodi Stripe separati, ognuno col suo elemento

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Compito 8: suite complete e prova nel browser

- [ ] **Passo 1: suite.** Le tre suite come in P3, con l'output nello scratchpad. Atteso: nessun rosso nuovo rispetto alle basi.

- [ ] **Passo 2: prova nel browser** su https://ecommerce.test/checkout (navigazione e JS autorizzati; il login e il reCAPTCHA li fa l'utente, la carta di prova si può inserire). Dati del cliente fittizi con `@example.com`. Da guardare:
  - sotto «Carta di credito» compaiono le scelte dei metodi accesi nel conto di prova, nell'ordine carta, poi alfabetico; Apple Pay e Google Pay non ci sono;
  - la carta mostra solo i circuiti; il Payment Element della carta non mostra wallet né Link;
  - scegliendo Klarna compare il Payment Element con solo Klarna; se Klarna non vale per l'importo o il paese, la scelta sparisce e torna la carta;
  - scegliendo Link compare il campo email di Link (o la scelta sparisce se non si carica);
  - pagamento con la carta 4242 e con Klarna in test fino alla pagina di ritorno.
  Annota nel registro cosa hai visto per ognuno dei cinque «Punti da guardare».

- [ ] **Passo 3: link del sito di prova rimessi su `packages/*`** (anche se la prova non è finita, prima di passare la mano):

```bash
for x in app gestionale ecommerce; do ln -sfn ../../../../packages/$x/ /Users/andreamarinoni/Developer/boilerplates/ecommerce-site/vendor/wonder-image/$x; done
ls -l /Users/andreamarinoni/Developer/boilerplates/ecommerce-site/vendor/wonder-image/
```

- [ ] **Passo 4:** aggiorna `TODO.md` del gestionale (D6: piano 2a fatto, 2b da fare) e fai il commit.
