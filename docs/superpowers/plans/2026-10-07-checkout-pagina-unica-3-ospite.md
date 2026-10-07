# Checkout a pagina unica — Piano 3: Ospite

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** permettere all'ospite di ordinare dalla pagina unica (con `checkout.guest_enabled`): al «Ordina» nasce o si riusa l'account con la sua email, l'ordine si collega a utente e contatto, e l'email al cliente porta il link «Crea la tua password per seguire i tuoi ordini».

**Architecture:** nell'app `UserAccountGateway::createUserWithoutPassword` crea un cliente senza password e senza email verificata; `PasswordReset::reset` segna l'email come verificata. Nel gestionale `Checkout::place` inoltra `$data['customer_email']` all'email del cliente (`received`, oppure `confirmed` tramite `Lifecycle::confirm(['email_extra' => …])` per il pagamento alla consegna) e `OrderEmail` stampa il blocco con il pulsante quando c'è `account_url`. Nell'ecommerce la classe nuova `GuestCheckout` trova o crea utente e contatto, salva i consensi ed emette il link; il controller la chiama prima di `Checkout::place` e la pagina di conferma dice che il link è partito.

**Tech Stack:** PHP 8.2; prove con `tests/harness.php` e test di integrazione sul database del sito di prova (transazione annullata alla fine).

**Spec:** `gestionale/docs/superpowers/specs/2026-10-07-checkout-pagina-unica-design.md` (§3.2, §11, §12, «I tre piani» punto 3) e, per l'ospite, il §4 di `gestionale/docs/superpowers/specs/2026-10-06-checkout-a-passi-design.md`. I piani 1 e 2 sono fatti e uniti (wonder-image/lib#5, wonder-image/gestionale#20, wonder-image/ecommerce#4).

## Global Constraints

- Percorsi: `app` = `/Users/andreamarinoni/Developer/packages/app`, `gestionale` = `/Users/andreamarinoni/Developer/packages/gestionale`, `ecommerce` = `/Users/andreamarinoni/Developer/packages/ecommerce`, sito di prova = `/Users/andreamarinoni/Developer/boilerplates/ecommerce-site`. App, gestionale ed ecommerce sul branch `e1c-checkout-ospite`, creato da `main`: verificarlo con `git branch --show-current` prima di ogni commit.
- `vendor/wonder-image/{app,ecommerce,gestionale}` del sito di prova sono link ai pacchetti: i test vedono subito le classi nuove.
- `checkout.guest_enabled` resta `false` nel `config/module.php` dell'ecommerce; il sito di prova la accende con `custom/config/modules/ecommerce.php`.
- La sessione resta da ospite: nessun accesso automatico dopo l'ordine. La pagina di conferma non mostra mai dati dell'account.
- Per un'email esistente i dati dell'account e del contatto **non si toccano**; i consensi vanno con `registerLeadConsents`.
- Il link per la password dura 7 giorni (`new PasswordReset(7 * 86400)`) e parte solo se l'utente non ha una password locale.
- Testi, commenti, commit in italiano; commit che finiscono con `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. **Niente push, PR o merge** senza la conferma esplicita dell'utente.
- I test ignorati da git si aggiungono con `git add -f`.
- **Non toccare** le modifiche non committate dell'utente: in ecommerce `view/components/checkout/aside.php`, `view/components/checkout/lines.php`, `view/pages/checkout/index.php`; nel sito di prova tutto tranne `custom/config/modules/ecommerce.php`. Si aggiungono solo file nominati.
- Nessun `render('wonder')` forzato in viste e FormField.
- zsh: niente `--include`, niente glob senza corrispondenze, separatore `echo '----'`; il cwd torna al gestionale dopo ogni comando, quindi `cd …;` nello stesso comando.
- Le credenziali di prova valgono solo su `ecommerce.test`; il login nel browser lo fa l'utente, la password non si scrive in chat.

## Review Focus

1. **Email dell'ospite con maiuscole o spazi** (`  Mario@Example.COM `): si trova lo stesso account e non ne nasce un secondo → `GuestCheckout::account` normalizza (Task 4, test).
2. **Email di un account che ha già una password**: l'ordine si collega a quell'account, nome e telefono restano quelli dell'account, nessun link per la password → `GuestCheckout::account` e `passwordLink` (Task 4, test).
3. **Contatto con la stessa email già collegato a un altro utente** (`contact_link_conflict`): l'ordine nasce lo stesso, con `customer_id = 0` e `user_id` dell'account → `GuestCheckout::account` (Task 4, test).
4. **`Checkout::place` fallisce dopo la creazione dell'account** (merce finita): al secondo tentativo l'email è «esistente», si riusa lo stesso utente e il link riparte → `GuestCheckout::account` chiamato due volte (Task 4, test).
5. **Pagamento online contro pagamento alla consegna**: il link va nell'email `received` per il bonifico, nell'email `confirmed` per il contrassegno, e mai nell'email al commerciante (Task 3, test).

---

### Task 1: account senza password e reset che verifica l'email (app)

**Files:**
- Modify: `app/class/Auth/Frontend/UserAccountGateway.php` (dopo `createUserFromFederatedIdentity`, riga ~50)
- Modify: `app/class/Auth/PasswordReset.php:37-46`
- Modify: `app/CHANGELOG.md` (sezione `Unreleased` → `Added` e `Changed`)
- Test: `ecommerce/tests/integrazione/AuthAccountTest.php` (prima di `throw new AnnullaAuthAccount();`)

**Interfaces:**
- Produces: `UserAccountGateway::createUserWithoutPassword(string $name, string $surname, string $email, string $area): int` — id del nuovo utente, `0` se l'email è vuota o non valida. Email salvata in minuscolo e senza spazi; `password` vuota, `active = 'true'`, `authority` dal costruttore (default `['client']`), `area` `[$area]`, email **non** verificata.
- Produces: `PasswordReset::reset()` segna `email_verified = 1` sull'utente del token.

- [ ] **Step 1: creare i branch e committare il piano**

```bash
cd /Users/andreamarinoni/Developer/packages; for r in app gestionale ecommerce; do git -C $r switch main && git -C $r switch -c e1c-checkout-ospite; done
```

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale; git add docs/superpowers/plans/2026-10-07-checkout-pagina-unica-3-ospite.md && git commit -m "Piano 3 del checkout a pagina unica: ospite

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Expected: tre `Switched to a new branch 'e1c-checkout-ospite'` e un commit sul gestionale.

- [ ] **Step 2: scrivere le prove che falliscono**

In `ecommerce/tests/integrazione/AuthAccountTest.php`, subito prima di `throw new AnnullaAuthAccount();`:

```php
        $guestEmail = 'ecommerce-guest-'.bin2hex(random_bytes(6)).'@example.com';
        $guestId = $gateway->createUserWithoutPassword('Ada', 'Ospite', '  '.strtoupper($guestEmail).' ', 'frontend');
        $guest = $gateway->findUserById($guestId);

        check('l\'ospite diventa un cliente attivo senza password e con l\'email da verificare', fn () =>
            $guestId > 0
            && ($guest['email'] ?? '') === $guestEmail
            && ($guest['password'] ?? 'x') === ''
            && ($guest['active'] ?? false) === true
            && in_array('client', $guest['authority'] ?? [], true)
            && in_array('frontend', $guest['area'] ?? [], true)
            && !$gateway->hasLocalPassword($guestId)
            && $gateway->canAccessArea($guestId, 'frontend', ['client'])
            && (infoUser($guestId, 'id')->email_verified ?? true) === false
        );

        check('senza email valida non nasce nessun account', fn () =>
            $gateway->createUserWithoutPassword('Ada', 'Ospite', '', 'frontend') === 0
            && $gateway->createUserWithoutPassword('Ada', 'Ospite', 'non-una-email', 'frontend') === 0
        );

        $guestReset = new PasswordReset(7 * 86400);
        $guestToken = $guestReset->issueForUser($guestId, '/account/');
        $guestDone = $guestReset->reset($guestToken->token, 'password-ospite-123');

        check('scegliere la password dal link verifica l\'email', fn () =>
            ($guestDone->success ?? false)
            && $gateway->hasLocalPassword($guestId)
            && (infoUser($guestId, 'id')->email_verified ?? false) === true
        );
```

- [ ] **Step 3: vedere le prove fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/integrazione/AuthAccountTest.php`
Expected: errore fatale `Call to undefined method …EcommerceUserAccountGateway::createUserWithoutPassword()`.

- [ ] **Step 4: il metodo nel gateway**

In `app/class/Auth/Frontend/UserAccountGateway.php`, dopo `createUserFromFederatedIdentity`:

```php
    /**
     * Il cliente che ordina come ospite: account attivo, senza password e con
     * l'email da verificare. La password la sceglie dal link nell'email
     * dell'ordine, e quel link verifica anche l'email.
     */
    public function createUserWithoutPassword(string $name, string $surname, string $email, string $area): int
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 0;
        }

        $created = User::create([
            'name' => trim($name),
            'surname' => trim($surname),
            'email' => $email,
            'username' => \create_link(explode('@', $email)[0], 'user', 'username'),
            'authority' => json_encode($this->authorities, JSON_THROW_ON_ERROR),
            'area' => json_encode([$area], JSON_THROW_ON_ERROR),
            'active' => 'true',
        ]);

        return (int) ($created->insert_id ?? 0);
    }
```

- [ ] **Step 5: il reset verifica l'email**

In `app/class/Auth/PasswordReset.php`, dopo il controllo di `$update` e prima di `RememberMe::revokeUser`:

```php
                // Il token è arrivato a quella casella: il possesso dell'email è provato.
                $verified = \markUserEmailVerified($consumed->subject_user_id);
                if (!($verified->success ?? false)) {
                    throw new RuntimeException('password_reset_verify_failed');
                }
```

- [ ] **Step 6: vedere le prove passare**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/integrazione/AuthAccountTest.php`
Expected: tutte le righe `ok`, compresa «il test non lascia account nel database», e il riepilogo senza fallimenti.

- [ ] **Step 7: suite dell'app**

Run: `cd /Users/andreamarinoni/Developer/packages/app; for f in tests/*.php; do php $f > /dev/null || echo "FALLITO $f"; done`
Expected: nessuna riga `FALLITO`.

- [ ] **Step 8: CHANGELOG e commit**

In `app/CHANGELOG.md`, sotto `## Unreleased` → `### Added`:

```markdown
- `UserAccountGateway::createUserWithoutPassword()`: crea il cliente che ordina come
  ospite, attivo, senza password e con l'email da verificare.
```

e sotto `### Changed` (crearla se manca, dopo `### Added`):

```markdown
- `PasswordReset::reset()` segna l'email come verificata: il token è arrivato a quella casella.
```

```bash
cd /Users/andreamarinoni/Developer/packages/app; git branch --show-current && git add class/Auth/Frontend/UserAccountGateway.php class/Auth/PasswordReset.php CHANGELOG.md && git commit -m "Account senza password per l'ospite; il reset della password verifica l'email

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

```bash
cd /Users/andreamarinoni/Developer/packages/ecommerce; git branch --show-current && git add tests/integrazione/AuthAccountTest.php && git commit -m "Prove dell'account dell'ospite e della verifica dal reset

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: il blocco «Crea la tua password» nell'email al cliente (gestionale)

**Files:**
- Modify: `gestionale/src/Support/Orders/OrderEmail.php:45-76` (`compose`)
- Modify: `gestionale/view/emails/order.php` (dopo il `Totale`)
- Modify: `gestionale/lang/it/gestionale.json` (`gestionale.emails.order`, nuova chiave `account`)
- Test: `gestionale/tests/integrazione/OrderEmailsTest.php` (prima di `summary();`)

**Interfaces:**
- Produces: `OrderEmail::compose($key, $order, $items, ['account_url' => string])` — per `received` e `confirmed` la vista riceve `$account_url` assoluto (`absoluteUrl`), `$account_title` e `$account_button`; per le altre chiavi `$account_url === ''`.

- [ ] **Step 1: scrivere le prove che falliscono**

In `gestionale/tests/integrazione/OrderEmailsTest.php`, prima di `summary();`:

```php
check('il link per scegliere la password sta solo nelle email ricevuto e confermato', function () {
    return prova(static function (): bool {
        $ordine = (array) Order::findById(ordineConRiga());
        $righe = [['name' => 'Crema da prova', 'quantity' => '2.000', 'line_total' => '50.00']];
        $extra = ['account_url' => '/account/password-restore/?token=abc'];
        $assoluto = OrderEmail::absoluteUrl('/account/password-restore/?token=abc');
        $dentro = [];

        foreach (['received', 'confirmed', 'shipped', 'merchant_new'] as $chiave) {
            $corpo = OrderEmail::compose($chiave, $ordine, $righe, $extra)['body'];
            $dentro[$chiave] = str_contains($corpo, 'Crea la tua password') && str_contains($corpo, htmlspecialchars($assoluto, ENT_QUOTES, 'UTF-8'));
        }

        return $dentro === ['received' => true, 'confirmed' => true, 'shipped' => false, 'merchant_new' => false];
    });
});

check('senza link nessun blocco per la password', function () {
    return prova(static function (): bool {
        $ordine = (array) Order::findById(ordineConRiga());
        $corpo = OrderEmail::compose('received', $ordine, [])['body'];

        return !str_contains($corpo, 'Crea la tua password');
    });
});
```

- [ ] **Step 2: vedere le prove fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale; php tests/integrazione/OrderEmailsTest.php`
Expected: `FALLITO` su «il link per scegliere la password sta solo nelle email ricevuto e confermato»; le altre `ok`.

- [ ] **Step 3: i testi**

In `gestionale/lang/it/gestionale.json`, dentro `gestionale.emails.order`, dopo `merchant_new` (con la virgola dopo la sua `}`):

```json
                "account": {
                    "title": "Crea la tua password per seguire i tuoi ordini",
                    "button": "Scegli la password"
                }
```

Controllo: `php -r 'json_decode(file_get_contents("lang/it/gestionale.json"), flags: JSON_THROW_ON_ERROR); echo "ok\n";'` → `ok`.

- [ ] **Step 4: `compose` passa il link**

In `OrderEmail::compose`, nell'array della vista dopo `'url' => …`:

```php
                // L'ospite sceglie la password dal link: solo nelle email che riceve all'ordine.
                'account_url' => in_array($key, ['received', 'confirmed'], true) ? self::absoluteUrl((string) ($extra['account_url'] ?? '')) : '',
                'account_title' => self::text('account', 'title', []),
                'account_button' => self::text('account', 'button', []),
```

- [ ] **Step 5: la vista**

In `gestionale/view/emails/order.php`, dopo il paragrafo `Totale` e prima del blocco `$url`:

```php
<?php if (trim($account_url ?? '') !== '') { ?>
    <p><strong><?= $e($account_title) ?></strong></p>
    <p><a href="<?= $e($account_url) ?>" style="display: inline-block; padding: 10px 16px; background: #111; color: #fff; text-decoration: none; border-radius: 4px"><?= $e($account_button) ?></a></p>
<?php } ?>
```

- [ ] **Step 6: vedere le prove passare**

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale; php tests/integrazione/OrderEmailsTest.php`
Expected: tutte `ok`.

- [ ] **Step 7: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale; git branch --show-current && git add src/Support/Orders/OrderEmail.php view/emails/order.php lang/it/gestionale.json tests/integrazione/OrderEmailsTest.php && git commit -m "Email dell'ordine: il blocco per scegliere la password

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

(Se `git add` rifiuta il test perché ignorato, ripetere con `git add -f tests/integrazione/OrderEmailsTest.php`.)

---

### Task 3: `Checkout::place` inoltra il link all'email del cliente (gestionale)

**Files:**
- Modify: `gestionale/src/Support/Orders/Checkout.php:73-82`
- Modify: `gestionale/src/Support/Orders/Lifecycle.php:47` (phpdoc) e `:128-131`
- Modify: `gestionale/src/Support/Orders/OrderNotifier.php` (phpdoc di `send`, chiave `account_url`)
- Modify: `gestionale/CHANGELOG.md`
- Test: `gestionale/tests/integrazione/CheckoutTest.php` (prima di `summary();`)

**Interfaces:**
- Consumes: `OrderEmail::compose(..., ['account_url' => …])` dal Task 2.
- Produces: `Checkout::place(int $cartId, array $data)` accetta `$data['customer_email']` (`array{account_url?: string}`), inoltrato come `$extra` a `OrderNotifier::send('received', …)` oppure come `email_extra` a `Lifecycle::confirm`.
- Produces: `Lifecycle::confirm($orderId, ['email_extra' => array])` — passato a `OrderNotifier::send('confirmed', $orderId, $emailExtra)`.

- [ ] **Step 1: scrivere le prove che falliscono**

In `gestionale/tests/integrazione/CheckoutTest.php`, prima di `summary();`:

```php
/**
 * Le email partite durante il corpo, per destinatario.
 *
 * @return array<string, string>
 */
function postaPerDestinatario(callable $corpo): array
{
    $posta = [];
    Mailer::useTransport(static function (string $to, string $subject, string $body) use (&$posta): bool {
        $posta[$to] = ($posta[$to] ?? '').$body;

        return true;
    });

    try {
        $corpo();
    } finally {
        Mailer::useTransport(null);
    }

    return $posta;
}

check('col bonifico il link per la password va nell\'email di ordine ricevuto, solo al cliente', function () {
    return prova(static function (): bool {
        [$carrello] = carrelloPronto();
        $dati = datiCheckout(metodoDiProva(PaymentTiming::DEFERRED)) + ['customer_email' => ['account_url' => '/account/password-restore/?token=ospite1']];

        $posta = postaPerDestinatario(static fn () => Checkout::place($carrello, $dati));
        $altri = array_diff_key($posta, ['cliente@example.com' => true]);

        return str_contains($posta['cliente@example.com'] ?? '', 'token=ospite1')
            && !str_contains(implode(' ', $altri), 'token=ospite1');
    });
});

check('col contrassegno il link per la password va nell\'email di conferma', function () {
    return prova(static function (): bool {
        [$carrello] = carrelloPronto();
        $dati = datiCheckout(metodoDiProva(PaymentTiming::ON_DELIVERY)) + ['customer_email' => ['account_url' => '/account/password-restore/?token=ospite2']];

        $posta = postaPerDestinatario(static fn () => Checkout::place($carrello, $dati));

        return str_contains($posta['cliente@example.com'] ?? '', 'token=ospite2')
            && str_contains($posta['cliente@example.com'] ?? '', 'Crea la tua password');
    });
});
```

`metodoDiProva(PaymentTiming::DEFERRED)` è il bonifico (provider `bank_transfer`); `cliente@example.com` è l'email di `datiCheckout`.

- [ ] **Step 2: vedere le prove fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale; php tests/integrazione/CheckoutTest.php`
Expected: `FALLITO` sulle due prove nuove; le altre `ok`.

- [ ] **Step 3: `Lifecycle::confirm` inoltra l'extra**

In `Lifecycle.php`, nel phpdoc di `confirm` aggiungere `email_extra?: array<string, string>` alle chiavi di `$options`; poi:

```php
            OrderNotifier::send('confirmed', $orderId, (array) ($options['email_extra'] ?? []));
```

al posto di `OrderNotifier::send('confirmed', $orderId);`.

- [ ] **Step 4: `Checkout::place` passa `customer_email`**

In `Checkout.php`, nel blocco dopo la creazione:

```php
        // Il link per la password dell'ospite viaggia con l'email al cliente.
        $customerEmail = array_filter((array) ($data['customer_email'] ?? []), 'is_string');

        try {
            if ($result['timing'] === PaymentTiming::ON_DELIVERY) {
                $confirmed = Lifecycle::confirm($result['order_id'], [
                    'payment' => false,
                    'source' => (string) ($data['source'] ?? 'user'),
                    'user_id' => (int) ($data['user_id'] ?? 0),
                    'email_extra' => $customerEmail,
                ]);
                $result['status'] = $confirmed['status'];
            } else {
                OrderNotifier::send('received', $result['order_id'], $customerEmail);
            }
```

Nel phpdoc di `place` (o di `$data`) aggiungere la riga `customer_email?: array{account_url?: string}`. In `OrderNotifier::send` aggiungere `account_url?: string` alle chiavi documentate di `$extra`.

- [ ] **Step 5: vedere le prove passare**

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale; php tests/integrazione/CheckoutTest.php`
Expected: tutte `ok`.

- [ ] **Step 6: le prove vicine**

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale; for f in tests/integrazione/OrderEmailsTest.php tests/integrazione/LifecycleTest.php; do [ -f $f ] && php $f | tail -2; done`
Expected: riepiloghi senza fallimenti.

- [ ] **Step 7: CHANGELOG e commit**

In `gestionale/CHANGELOG.md`, sotto `## 0.1.0 — non rilasciata` → `### Aggiunto`:

```markdown
- Checkout dell'ospite: `Checkout::place` accetta `customer_email.account_url` e lo porta
  nell'email al cliente (ricevuto, o confermato col pagamento alla consegna tramite
  `Lifecycle::confirm(['email_extra' => …])`); l'email mostra «Crea la tua password per
  seguire i tuoi ordini» con il pulsante.
```

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale; git branch --show-current && git add src/Support/Orders/Checkout.php src/Support/Orders/Lifecycle.php src/Support/Orders/OrderNotifier.php CHANGELOG.md tests/integrazione/CheckoutTest.php && git commit -m "Checkout: il link per la password dell'ospite nell'email al cliente

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `GuestCheckout`, account e link dell'ospite (ecommerce)

**Files:**
- Create: `ecommerce/src/Frontend/Checkout/GuestCheckout.php`
- Test: `ecommerce/tests/integrazione/GuestCheckoutTest.php`

**Interfaces:**
- Consumes: `EcommerceUserAccountGateway::createUserWithoutPassword(string, string, string, string): int` (Task 1), `findUserByEmail(string): ?array`, `hasLocalPassword(int): bool`; `CustomerAccount::linkContact(int, array): object{success, reason?, contact_id?}`; `Contact::find(array, 1): ?array`; `consentService()->registerBaseConsents(int, array, array)` e `->registerLeadConsents(string, array, array)`; `PasswordReset::issueForUser(int, ?string): object{token}`.
- Produces:
  - `GuestCheckout::account(array $order, array $post, array $asked): array{user_id: int, customer_id: int, created: bool}` — `$order` è il carrello riletto dopo l'anteprima (chiavi `email`, `shipping_name`, `shipping_surname`, `shipping_phone_prefix`, `shipping_phone`). Lancia `RuntimeException` con `ecommerce.checkout.errors.generic` se l'account non nasce.
  - `GuestCheckout::passwordLink(int $userId, string $restorePath, string $continueUrl): string` — `''` se l'utente ha già una password, altrimenti `$restorePath.'?token='.rawurlencode($token)` (relativo: lo fa assoluto `OrderEmail`).

- [ ] **Step 1: scrivere le prove che falliscono**

Creare `ecommerce/tests/integrazione/GuestCheckoutTest.php`:

```php
<?php
/** php tests/integrazione/GuestCheckoutTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\App\Models\User\User;
use Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceUserAccountGateway;
use Wonder\Plugin\Ecommerce\Frontend\Checkout\GuestCheckout;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Sql\Transaction;

final class AnnullaGuestCheckout extends RuntimeException {}

/** Il carrello come lo rilegge il controller dopo l'anteprima. */
function carrelloOspite(string $email, string $nome = 'Lina'): array
{
    return [
        'email' => $email,
        'shipping_name' => $nome,
        'shipping_surname' => 'Ospite',
        'shipping_phone_prefix' => '+39',
        'shipping_phone' => '3330001111',
    ];
}

$email = 'ecommerce-ospite-'.bin2hex(random_bytes(6)).'@example.com';

try {
    Transaction::run(static function () use ($email): void {
        $gateway = new EcommerceUserAccountGateway();

        $nuovo = GuestCheckout::account(carrelloOspite('  '.strtoupper($email).' '), [], []);
        $utente = $gateway->findUserById($nuovo['user_id']);
        $contatto = Contact::find(['user_id' => $nuovo['user_id']], 1);

        check('un\'email nuova crea cliente e contatto, senza password', fn () =>
            $nuovo['created'] === true
            && ($utente['email'] ?? '') === $email
            && ($utente['password'] ?? 'x') === ''
            && is_array($contatto)
            && (int) $contatto['id'] === $nuovo['customer_id']
            && ($contatto['name'] ?? '') === 'Lina'
            && ($contatto['phone'] ?? '') === '3330001111'
        );

        $ancora = GuestCheckout::account(carrelloOspite($email, 'Altro'), [], []);

        check('la stessa email riusa account e contatto senza cambiarne i dati', function () use ($ancora, $nuovo) {
            $contatto = (array) Contact::findById($nuovo['customer_id']);

            return $ancora === ['user_id' => $nuovo['user_id'], 'customer_id' => $nuovo['customer_id'], 'created' => false]
                && ($contatto['name'] ?? '') === 'Lina';
        });

        $link = GuestCheckout::passwordLink($nuovo['user_id'], '/account/password-restore/', '/account/');

        check('senza password parte il link con il token', fn () =>
            str_starts_with($link, '/account/password-restore/?token=')
            && strlen($link) > strlen('/account/password-restore/?token=') + 10
        );

        $conPassword = 'ecommerce-ospite-'.bin2hex(random_bytes(6)).'@example.com';
        $creato = User::create([
            'name' => 'Ada', 'surname' => 'Locale', 'email' => $conPassword,
            'username' => create_link('ospite-locale', 'user', 'username'),
            'password' => hashPassword('password-locale-123'),
            'authority' => json_encode(['client']), 'area' => json_encode(['frontend']), 'active' => 'true',
        ]);
        $locale = (int) ($creato->insert_id ?? 0);
        $collegato = GuestCheckout::account(carrelloOspite($conPassword, 'Intruso'), [], []);

        check('l\'email di un account con password collega l\'ordine e non manda link', fn () =>
            $collegato['user_id'] === $locale
            && $collegato['created'] === false
            && $collegato['customer_id'] > 0
            && GuestCheckout::passwordLink($locale, '/account/password-restore/', '/account/') === ''
            && (infoUser($locale, 'id')->name ?? '') === 'Ada'
        );

        $conflitto = 'ecommerce-ospite-'.bin2hex(random_bytes(6)).'@example.com';
        Contact::create([
            'name' => 'Altra', 'surname' => 'Scheda', 'email' => $conflitto,
            'user_id' => $nuovo['user_id'], 'is_customer' => 'true', 'active' => 'true',
        ]);
        $senzaScheda = GuestCheckout::account(carrelloOspite($conflitto), [], []);

        check('una scheda con quella email già di un altro account lascia l\'ordine senza contatto', fn () =>
            $senzaScheda['user_id'] > 0
            && $senzaScheda['user_id'] !== $nuovo['user_id']
            && $senzaScheda['customer_id'] === 0
        );

        throw new AnnullaGuestCheckout();
    });
} catch (AnnullaGuestCheckout) {
}

check('il test non lascia account nel database', fn () => !(infoUser($email, 'email')->exists ?? false));

summary();
```

- [ ] **Step 2: vedere le prove fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/integrazione/GuestCheckoutTest.php`
Expected: errore fatale `Class "Wonder\Plugin\Ecommerce\Frontend\Checkout\GuestCheckout" not found`.

- [ ] **Step 3: la classe**

Creare `ecommerce/src/Frontend/Checkout/GuestCheckout.php`:

```php
<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

use RuntimeException;
use Throwable;
use Wonder\Auth\PasswordReset;
use Wonder\Plugin\Ecommerce\Frontend\Auth\EcommerceUserAccountGateway;
use Wonder\Plugin\Ecommerce\Frontend\Client\CustomerAccount;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;

/**
 * L'ospite che ordina: l'ordine va sempre a un account. Con un'email nuova
 * l'account nasce senza password; con un'email nota si riusa così com'è.
 * La sessione resta da ospite: chi ordina non entra nell'account.
 */
final class GuestCheckout
{
    /** Il link per scegliere la password vale una settimana. */
    private const LINK_TTL = 7 * 86400;

    /**
     * Trova o crea utente e contatto per l'email dell'ordine e salva i consensi.
     *
     * @param array<string, mixed> $order il carrello riletto dopo l'anteprima
     * @param array<string, mixed> $post
     * @param list<string> $asked i documenti chiesti dalla pagina
     * @return array{user_id: int, customer_id: int, created: bool}
     */
    public static function account(array $order, array $post, array $asked): array
    {
        $users = new EcommerceUserAccountGateway();
        $email = strtolower(trim((string) ($order['email'] ?? '')));
        $found = $users->findUserByEmail($email);
        $context = ['required_document_types' => $asked, 'ui_surface' => 'checkout'];

        if ($found !== null) {
            $userId = (int) $found['id'];
            $contact = Contact::find(['user_id' => $userId], 1);
            $customerId = is_array($contact) ? (int) ($contact['id'] ?? 0) : self::link($userId, []);

            // I consensi dell'account restano i suoi: l'accettazione si traccia sull'email.
            self::consents(static fn () => consentService()->registerLeadConsents($email, $post, $context), $asked);

            return ['user_id' => $userId, 'customer_id' => $customerId, 'created' => false];
        }

        $userId = $users->createUserWithoutPassword(
            (string) ($order['shipping_name'] ?? ''),
            (string) ($order['shipping_surname'] ?? ''),
            $email,
            'frontend'
        );
        if ($userId <= 0) {
            throw new RuntimeException((string) __t('ecommerce.checkout.errors.generic'));
        }

        $customerId = self::link($userId, [
            'phone_prefix' => (string) ($order['shipping_phone_prefix'] ?? ''),
            'phone' => (string) ($order['shipping_phone'] ?? ''),
        ]);
        self::consents(static fn () => consentService()->registerBaseConsents($userId, $post, $context), $asked);

        return ['user_id' => $userId, 'customer_id' => $customerId, 'created' => true];
    }

    /**
     * Il link per scegliere la password, solo per chi non ne ha una (anche
     * l'ospite che torna). Relativo: l'email lo fa assoluto.
     */
    public static function passwordLink(int $userId, string $restorePath, string $continueUrl): string
    {
        if ($userId <= 0 || (new EcommerceUserAccountGateway())->hasLocalPassword($userId)) {
            return '';
        }

        $issued = (new PasswordReset(self::LINK_TTL))->issueForUser($userId, $continueUrl);

        return $restorePath.'?token='.rawurlencode((string) $issued->token);
    }

    /**
     * Il contatto dell'account; `0` se la scheda con quella email è già di un
     * altro account: l'ordine nasce lo stesso, con l'email e l'utente.
     *
     * @param array<string, string> $input
     */
    private static function link(int $userId, array $input): int
    {
        $linked = CustomerAccount::linkContact($userId, $input);

        return ($linked->success ?? false) ? (int) ($linked->contact_id ?? 0) : 0;
    }

    /** @param list<string> $asked */
    private static function consents(callable $register, array $asked): void
    {
        if ($asked === []) {
            return;
        }

        try {
            $register();
        } catch (Throwable $error) {
            // Un consenso non registrato non ferma l'ordine.
            Errors::internal($error, 'ecommerce.checkout.guest_consents');
        }
    }
}
```

- [ ] **Step 4: vedere le prove passare**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/integrazione/GuestCheckoutTest.php`
Expected: sei righe `ok`, nessun fallimento.

- [ ] **Step 5: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/ecommerce; git branch --show-current && git add src/Frontend/Checkout/GuestCheckout.php && git add -f tests/integrazione/GuestCheckoutTest.php && git commit -m "Checkout ospite: account, contatto, consensi e link per la password

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: il controller, la pagina di conferma, testi e documenti (ecommerce)

**Files:**
- Modify: `ecommerce/src/Frontend/Checkout/CheckoutController.php:155-182` (`place`) e `:296-309` (`completed`)
- Modify: `ecommerce/view/pages/checkout/completed.php`
- Modify: `ecommerce/lang/it/ecommerce.json`, `ecommerce/lang/en/ecommerce.json` (`ecommerce.checkout.completed.password_sent`, `.shop`)
- Modify: `ecommerce/config/module.php` (commento di `checkout.guest_enabled`)
- Modify: `ecommerce/docs/cart-checkout.md:91-94`, `ecommerce/docs/authentication.md:58`, `ecommerce/CHANGELOG.md`, `ecommerce/TODO.md:146-148`
- Test: `ecommerce/tests/CartCheckoutTest.php` (prima di `summary();`)

**Interfaces:**
- Consumes: `GuestCheckout::account(array, array, array)` e `GuestCheckout::passwordLink(int, string, string)` (Task 4); `Checkout::place($cartId, $data + ['customer_email' => ['account_url' => …]])` (Task 3).
- Produces: `$_SESSION[CheckoutController::COMPLETED]` con le chiavi nuove `guest: bool` e `password_link: bool`, lette da `pages/checkout/completed.php`.

- [ ] **Step 1: scrivere la prova che fallisce**

In `ecommerce/tests/CartCheckoutTest.php`, prima di `summary();`:

```php
check('l\'ospite ottiene account e link prima dell\'ordine, e la conferma non mostra l\'account', function () use ($root) {
    $controller = (string) file_get_contents($root.'/src/Frontend/Checkout/CheckoutController.php');
    $completed = (string) file_get_contents($root.'/view/pages/checkout/completed.php');
    $it = json_decode((string) file_get_contents($root.'/lang/it/ecommerce.json'), true);
    $en = json_decode((string) file_get_contents($root.'/lang/en/ecommerce.json'), true);
    $account = strpos($controller, 'GuestCheckout::account(');
    $place = strpos($controller, 'Checkout::place(');

    return $account !== false && $place !== false && $account < $place
        && str_contains($controller, 'GuestCheckout::passwordLink(')
        && str_contains($controller, "'customer_email'")
        && str_contains($controller, "'password_link'")
        && str_contains($completed, "ecommerce.checkout.completed.password_sent")
        && str_contains($completed, "empty(\$result['guest'])")
        && is_string($it['ecommerce']['checkout']['completed']['password_sent'] ?? null)
        && is_string($en['ecommerce']['checkout']['completed']['password_sent'] ?? null)
        && is_string($it['ecommerce']['checkout']['completed']['shop'] ?? null)
        && is_string($en['ecommerce']['checkout']['completed']['shop'] ?? null);
});
```

Prima controllare la struttura del JSON (`grep -n '"completed"' -B3 lang/it/ecommerce.json`): se la radice non è `ecommerce`, adattare il percorso nella prova e annotarlo.

- [ ] **Step 2: vedere la prova fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/CartCheckoutTest.php`
Expected: `FALLITO` solo sulla prova nuova.

- [ ] **Step 3: il controller**

In `CheckoutController::place`, al posto del blocco da `$result = Checkout::place(` fino alla chiusura dell'`if ($userId > 0 && $asked !== [])`:

```php
            // L'ospite ordina sempre su un account: si trova o nasce dalla sua email.
            $guest = !CartSession::authenticated();
            $customerId = CartSession::customerId();
            $passwordLink = '';
            if ($guest) {
                $account = GuestCheckout::account($order, $post, $asked);
                $userId = $account['user_id'];
                $customerId = $account['customer_id'];
                $passwordLink = GuestCheckout::passwordLink($userId, self::route('ecommerce.auth.password.restore'), self::route('ecommerce.account.index'));
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
```

e in `$_SESSION[self::COMPLETED]` aggiungere:

```php
                'guest' => $guest,
                'password_link' => $passwordLink !== '',
```

Nessun `use` nuovo: `GuestCheckout` è nello stesso namespace.

- [ ] **Step 4: la pagina di conferma**

In `ecommerce/view/pages/checkout/completed.php`, al posto del link «Vai al tuo account»:

```php
    <?php if (!empty($result['password_link'])): ?>
        <p class="text mt-4"><?=e(__t('ecommerce.checkout.completed.password_sent'))?></p>
    <?php endif; ?>
    <?php if (empty($result['guest'])): ?>
        <a class="btn btn-primary mt-6" href="<?=e(__r('ecommerce.account.index'))?>"><?=e(__t('ecommerce.checkout.completed.account'))?></a>
    <?php else: ?>
        <a class="btn btn-primary mt-6" href="<?=e(__r('ecommerce.cart.index'))?>"><?=e(__t('ecommerce.checkout.completed.shop'))?></a>
    <?php endif; ?>
```

Se il sito ha una rotta del negozio più adatta del carrello (`grep -n "'ecommerce.shop" config/routes/route.frontend.php`), usare quella e annotarlo.

- [ ] **Step 5: i testi**

In `lang/it/ecommerce.json`, in `checkout.completed`:

```json
            "account": "Vai al tuo account",
            "password_sent": "Ti abbiamo mandato un'email per scegliere la password e seguire i tuoi ordini.",
            "shop": "Torna al negozio"
```

In `lang/en/ecommerce.json`, nella stessa posizione:

```json
            "password_sent": "We have sent you an email to choose your password and follow your orders.",
            "shop": "Back to the shop"
```

Controllo: `for f in lang/it/ecommerce.json lang/en/ecommerce.json; do php -r 'json_decode(file_get_contents($argv[1]), flags: JSON_THROW_ON_ERROR); echo "ok\n";' $f; done` → due `ok`.

- [ ] **Step 6: vedere la prova passare, poi la suite**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/CartCheckoutTest.php | tail -3 && php tests/run.php > /tmp/ecommerce-suite.txt; tail -5 /tmp/ecommerce-suite.txt`
Expected: `CartCheckoutTest` senza fallimenti; `run.php` con tutte le prove verdi (le due che chiedono `guest_enabled === false` restano verdi: il default non cambia).

- [ ] **Step 7: configurazione, documenti, CHANGELOG, TODO**

`config/module.php`, commento sopra `'guest_enabled' => false`:

```php
        // Ospite: all'ordine nasce (o si riusa) l'account con la sua email e
        // l'email al cliente porta il link per scegliere la password.
```

`docs/cart-checkout.md`, il paragrafo «`checkout.guest_enabled` è `false`…» diventa:

```markdown
`checkout.guest_enabled` è `false`; un sito lo accende con
`custom/config/modules/ecommerce.php`. L'ospite vede «Accedi» nei Contatti e
scrive la sua email; l'invio è protetto da reCAPTCHA (action
`ecommerce_checkout`). Al «Ordina» `GuestCheckout` trova l'account con quella
email o lo crea senza password, collega il contatto e salva i consensi
(`registerLeadConsents` per un account che c'era già, i cui dati non cambiano).
Se l'account non ha una password, l'email dell'ordine porta il link «Scegli la
password» (7 giorni); scegliendola l'email risulta verificata. La sessione resta
da ospite.
```

`docs/authentication.md:58`: sostituire «attualmente solo contratto per la fase checkout» con «accende il checkout ospite: vedi `cart-checkout.md`, «Account e ospite»».

`CHANGELOG.md` (sezione non rilasciata, `Aggiunto`):

```markdown
- Checkout ospite (`checkout.guest_enabled`): `GuestCheckout` trova o crea l'account
  dall'email, collega contatto e consensi e manda nell'email dell'ordine il link per
  scegliere la password; la conferma lo dice all'ospite senza mostrare l'account.
```

`TODO.md:146-148`: D4 diventa `[x]`, testo «Il percorso ospite è subordinato a `checkout.guest_enabled`, protetto da reCAPTCHA; l'ordine si collega a un account creato o riusato dall'email, con il link per scegliere la password.»

- [ ] **Step 8: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/ecommerce; git branch --show-current && git add src/Frontend/Checkout/CheckoutController.php view/pages/checkout/completed.php lang/it/ecommerce.json lang/en/ecommerce.json config/module.php docs/cart-checkout.md docs/authentication.md CHANGELOG.md TODO.md tests/CartCheckoutTest.php && git status --short && git commit -m "Checkout ospite: account prima dell'ordine e conferma con il link per la password

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Expected da `git status --short` prima del commit: `aside.php`, `lines.php` e `pages/checkout/index.php` restano ` M` (non in stage).

---

### Task 6: il sito di prova e la prova nel browser

**Files:**
- Create: `ecommerce-site/custom/config/modules/ecommerce.php`
- Modify: `gestionale/TODO.md:257-264` (piano 3 fatto)

- [ ] **Step 1: accendere l'ospite sul sito di prova**

Creare `/Users/andreamarinoni/Developer/boilerplates/ecommerce-site/custom/config/modules/ecommerce.php`:

```php
<?php

// Il sito di prova prova anche il checkout dell'ospite.
return [
    'checkout' => [
        'guest_enabled' => true,
    ],
];
```

Controllo: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site; php -r 'require "vendor/autoload.php"; require "vendor/wonder-image/app/wonder-image.php"; var_dump(Wonder\Plugin\Ecommerce\Ecommerce::config("checkout.guest_enabled", false));'`
Expected: `bool(true)`.

- [ ] **Step 2: prova nel browser (sessione da ospite)**

Nel pannello del browser, in una scheda senza accesso (o dopo «Esci»): mettere un prodotto nel carrello, aprire `https://ecommerce.test/checkout/`, controllare «Accedi» nei Contatti e il campo email, compilare con un'email nuova di prova (`ospite-<data>@example.com`), bonifico, «Ordina».

Expected: pagina di conferma con «Ti abbiamo mandato un'email per scegliere la password…» e il bottone «Torna al negozio», nessun dato dell'account; nel backend l'ordine ha il cliente collegato. Se reCAPTCHA blocca l'invio sul sito di prova, fermarsi e chiedere all'utente di fare l'ordine.

Controllo del link: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site; ls -t storage/logs 2>/dev/null | head -3` (o il log della posta del sito) per l'email `received` con «Crea la tua password». Aprire il link: la pagina del ripristino password deve accettare il token. **La password la sceglie l'utente.**

- [ ] **Step 3: TODO del gestionale e commit**

In `gestionale/TODO.md`, nella voce del checkout a pagina unica, segnare «Piano 3 Ospite» come fatto (`[x]`), con la data 2026-10-07 e il branch `e1c-checkout-ospite`.

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale; git branch --show-current && git add TODO.md && git commit -m "TODO: piano 3 del checkout a pagina unica fatto

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Il file del sito di prova non si committa (il sito ha molte modifiche dell'utente): lo si dice nel messaggio finale.

- [ ] **Step 4: suite complete**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/run.php > /tmp/ecommerce-suite.txt; tail -3 /tmp/ecommerce-suite.txt`
Run: `cd /Users/andreamarinoni/Developer/packages/gestionale; for f in tests/*.php tests/integrazione/*Test.php; do php $f > /dev/null || echo "FALLITO $f"; done`
Expected: ecommerce verde; nel gestionale nessuna riga `FALLITO`.
