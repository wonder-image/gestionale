# Checkout a pagina unica — Piano 2: la pagina unica

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** sostituire i passi Spedizione e Pagamento con una sola pagina di checkout in stile Shopify (contatti, consegna, pagamento con loghi e pannello, fatturazione, fattura, riepilogo a destra sticky) e dare al commerciante la scelta del font di accesso, account, checkout e carrello.

**Architecture:** nel gestionale il metodo di pagamento prende le icone (`icons`, CSV) e il commerciante quattro font (`font_*`); l'anteprima del checkout mostra solo i provider collegati (`PaymentProviders::connected`) e porta icone e commissione. Nell'ecommerce `CheckoutSteps` diventa `CheckoutRules` (regole della pagina unica), il controller ha una sola pagina (`index`) e un solo invio (`place`), la vista `pages/checkout/index.php` usa i componenti del piano 1 (`ChoiceGroup::variant`, `Choice::icon/icons/panel`), `checkout.js` perde i passi e guadagna loghi, pannello, errori sotto il campo e la copia dei nomi nella fatturazione. `StoreFont::style()` stampa il font scelto nei layout.

**Tech Stack:** PHP 8.2 (prove con `tests/harness.php` e test di integrazione sul sito di prova), JavaScript senza dipendenze, CSS della lib, SVG di activemerchant/payment_icons (MIT).

**Spec:** `gestionale/docs/superpowers/specs/2026-10-07-checkout-pagina-unica-design.md`. Il piano 1 (componenti e font in lib e app) è fatto e unito: `gestionale/docs/superpowers/plans/2026-10-07-checkout-pagina-unica-1-componenti.md`.

## Global Constraints

- Percorsi: `gestionale` = `/Users/andreamarinoni/Developer/packages/gestionale`, `ecommerce` = `/Users/andreamarinoni/Developer/packages/ecommerce`, sito di prova = `/Users/andreamarinoni/Developer/boilerplates/ecommerce-site`. Tutti e due i repository sul branch `e1c-checkout-a-passi`: verificarlo con `git branch --show-current` prima di ogni commit.
- `ecommerce/vendor/wonder-image/app` e il gestionale del sito sono link ai pacchetti: i test di ecommerce vedono subito le classi nuove del gestionale.
- Testi, commenti, commit in italiano; commit che finiscono con `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. **Niente push, PR o merge** senza la conferma esplicita dell'utente.
- I test ignorati da git si aggiungono con `git add -f`.
- **Non toccare** le modifiche non committate dell'utente in ecommerce (`view/components/checkout/aside.php`, `view/components/checkout/coupon.php`, `view/layout/frontend/ecommerce.checkout.php`, `view/layout/frontend/ecommerce.shop.php`) prima del Task 1, che le committa **solo dopo la conferma dell'utente**.
- Nessun `render('wonder')` forzato in viste e FormField.
- Form del backend compatti: tooltip al posto dei testi d'aiuto.
- zsh: niente `--include`, niente glob senza corrispondenze, separatore `echo '----'`; il cwd torna al gestionale dopo ogni comando, quindi `cd …;` nello stesso comando.
- Niente `innerHTML` in `checkout.js`; restano `sequence`, il ritardo di 300 ms, il commento in `schedule()`, `this.submit(this.latest)`, `payload.redirect`, `window.ecommerceCheckout`.
- Ogni `<div` delle viste del checkout ha una classe `w-N` e nessun `class` unisce `col-N col-t-N` con `d-grid` (lo controlla `CartCheckoutTest`).
- Un solo `<h1` per pagina in `view/pages/checkout/*.php`.
- Lo scaricamento degli SVG (Task 5) chiede prima la conferma all'utente.
- Le credenziali di prova valgono solo su `ecommerce.test`; il login nel browser lo fa l'utente.

## Review Focus

1. **`payment_method_id` manipolato** (un id che esiste ma non è nell'anteprima, o un provider non collegato come Stripe): `place` non crea l'ordine e torna alla pagina con `errors.payment_method` → `CheckoutRules::method()` (Task 7, test) e filtro dell'anteprima (Task 4, test).
2. **POST senza JavaScript con tutti i pannelli aperti** (indirizzo pieno + ritiro, «Utilizza un indirizzo diverso» non scelto ma campi `billing_*` pieni): col ritiro l'indirizzo si svuota, con «uguale» vince la spedizione → `CheckoutRules::post()` e `billing()` (Task 7, test).
3. **Icona sconosciuta** salvata a mano nel CSV (`visa,bitcoin,<x>`): si tiene solo quello che c'è in `PaymentMethod::ICONS` → `iconsOf()` (Task 2, test) e `icon_urls` dell'anteprima (Task 10, test).
4. **Font sconosciuto** (`font_checkout = 'comic'`) o area sconosciuta: niente `<style>` → `StoreFont::style()` (Task 8, test) e il salvataggio lo riporta a `''` (Task 3, test).
5. **Doppio invio o carrello già ordinato**: il secondo `Checkout::place` dà `UserError` `cart.not_a_cart` e non nasce un secondo ordine (Task 10, test).

---

### Task 1: committare le modifiche dell'utente in ecommerce

**Files:**
- Modify (già modificati dall'utente, solo commit): `ecommerce/view/components/checkout/aside.php`, `ecommerce/view/components/checkout/coupon.php`, `ecommerce/view/layout/frontend/ecommerce.checkout.php`, `ecommerce/view/layout/frontend/ecommerce.shop.php`

**Interfaces:**
- Consumes: nulla.
- Produces: un albero pulito su cui lavorano i task 8, 11 e 12.

- [ ] **Step 1: Mostrare le modifiche e chiedere la conferma**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; git branch --show-current; git status --short; git diff --stat`
Expected: branch `e1c-checkout-a-passi`, i quattro file modificati.

Chiedere all'utente: «Committo le tue modifiche a aside, coupon e ai layout checkout/shop come commit a parte?». **Fermarsi finché non risponde sì.** Se dice no, i task 8, 11 e 12 lavorano sopra i file modificati senza committarli e lo si scrive nel ledger.

- [ ] **Step 2: Commit (solo dopo il sì)**

```bash
cd /Users/andreamarinoni/Developer/packages/ecommerce; git add view/components/checkout/aside.php view/components/checkout/coupon.php view/layout/frontend/ecommerce.checkout.php view/layout/frontend/ecommerce.shop.php; git commit -m "E1c: ritocchi di Andrea al riepilogo, al coupon e ai layout

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Expected: un commit con 4 file; `git status --short` vuoto.

---

### Task 2: icone dei metodi di pagamento (gestionale)

**Files:**
- Modify: `gestionale/src/Models/Payments/PaymentMethod.php`
- Modify: `gestionale/src/Resources/Payments/PaymentMethodResource.php`
- Modify: `gestionale/src/Seeding/Defaults.php` (`legacyPaymentMethods`)
- Test: `gestionale/tests/PaymentMethodResourceTest.php`, `gestionale/tests/integrazione/DefaultsTest.php`

**Interfaces:**
- Consumes: nulla.
- Produces:
  - `PaymentMethod::ICONS` — `array<string, string>` chiave → etichetta, 14 voci (elenco sotto).
  - `PaymentMethod::defaultIcons(string $provider): list<string>`.
  - `PaymentMethod::iconsOf(string $csv): list<string>` — solo chiavi di `ICONS`, senza doppioni, in ordine.
  - Colonna `payment_methods.icons` TEXT: CSV di chiavi, `'none'` = scelta vuota voluta, `''` = mai decisa.

- [ ] **Step 1: Scrivere i test che falliscono**

In fondo a `tests/PaymentMethodResourceTest.php`, prima di `summary();`:

```php
check('le icone si salvano come elenco pulito; vuote all\'inserimento prendono quelle del tipo', function () {
    $scelte = PaymentMethodResource::mutateRequestValues(['name' => 'X', 'icons' => ['visa', 'paypal', 'visa', 'bitcoin']], 'update');
    $nuovo = PaymentMethodResource::mutateRequestValues(['name' => 'Bonifico', 'provider' => 'bank_transfer', 'icons' => []], 'store');
    $tolte = PaymentMethodResource::mutateRequestValues(['name' => 'X', 'icons' => []], 'update');

    return $scelte['icons'] === 'visa,paypal'
        && $nuovo['icons'] === 'genericbank'
        && $tolte['icons'] === 'none';
});

check('il form rilegge le icone come elenco e scarta quelle sconosciute', function () {
    $form = PaymentMethodResource::mutateFormValues(['icons' => 'visa,<x>,paypal'], 'edit');
    $vuoto = PaymentMethodResource::mutateFormValues(['icons' => 'none'], 'edit');

    return $form['icons'] === ['visa', 'paypal'] && $vuoto['icons'] === [];
});

check('ogni tipo ha le sue icone di partenza, tutte nel catalogo', function () {
    $tutte = array_merge(...array_map([PaymentMethod::class, 'defaultIcons'], PaymentMethod::PROVIDERS));

    return count(PaymentMethod::ICONS) === 14
        && PaymentMethod::defaultIcons('stripe') === ['visa', 'master', 'maestro', 'american_express', 'google_pay', 'apple_pay']
        && PaymentMethod::defaultIcons('cash') === ['cash']
        && PaymentMethod::defaultIcons('sconosciuto') === []
        && array_diff($tutte, array_keys(PaymentMethod::ICONS)) === [];
});
```

In `tests/integrazione/DefaultsTest.php`, nel check «i metodi di un sito aggiornato si sistemano da soli», prima di quel blocco svuotare le icone e poi controllarle. Sostituire le due righe `PaymentMethod::update(['provider' => 'manual', …` e `PaymentMethod::update(['provider' => 'manual'], …` con:

```php
        PaymentMethod::update(['provider' => 'manual', 'fee_type' => 'percent', 'fee_value' => '1.50', 'fee_percent' => '0.00', 'icons' => ''], (int) $metodi()['bank-transfer']['id']);
        PaymentMethod::update(['provider' => 'manual', 'icons' => ''], (int) $metodi()['cash']['id']);
        PaymentMethod::update(['icons' => ''], (int) $metodi()['stripe']['id']);
```

e nel `return` del check aggiungere:

```php
                && ($m['bank-transfer']['icons'] ?? '') === 'genericbank'
                && ($m['cash']['icons'] ?? '') === 'cash'
                && ($m['stripe']['icons'] ?? '') === 'visa,master,maestro,american_express,google_pay,apple_pay';
```

(togliendo il `;` dalla riga `&& ($m['cash']['provider'] ?? '') === 'cash'`).

- [ ] **Step 2: Lanciare i test e vederli fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale; php tests/PaymentMethodResourceTest.php | tail -5`
Expected: FAIL — i tre check nuovi con `✗` («Undefined constant … ICONS» o `icons` mancante).

- [ ] **Step 3: Il model**

In `src/Models/Payments/PaymentMethod.php`, sotto `TIMINGS`:

```php
    /** Le icone che si possono mostrare accanto al metodo: file `payment-icons/<chiave>.svg` dell'ecommerce. */
    public const ICONS = [
        'visa' => 'Visa',
        'master' => 'Mastercard',
        'maestro' => 'Maestro',
        'american_express' => 'American Express',
        'diners_club' => 'Diners Club',
        'discover' => 'Discover',
        'jcb' => 'JCB',
        'unionpay' => 'UnionPay',
        'paypal' => 'PayPal',
        'google_pay' => 'Google Pay',
        'apple_pay' => 'Apple Pay',
        'klarna' => 'Klarna',
        'genericbank' => 'Bonifico',
        'cash' => 'Contanti',
    ];

    /** @return list<string> le icone con cui nasce un metodo di quel tipo */
    public static function defaultIcons(string $provider): array
    {
        return match ($provider) {
            'stripe' => ['visa', 'master', 'maestro', 'american_express', 'google_pay', 'apple_pay'],
            'paypal' => ['paypal'],
            'nexi' => ['visa', 'master', 'maestro'],
            'bank_transfer' => ['genericbank'],
            'cash' => ['cash'],
            default => [],
        };
    }

    /** @return list<string> le chiavi note del CSV salvato, senza doppioni */
    public static function iconsOf(string $csv): array
    {
        $keys = array_map('trim', explode(',', $csv));

        return array_values(array_unique(array_filter($keys, static fn (string $key): bool => isset(self::ICONS[$key]))));
    }
```

In `tableSchema()`, dopo la colonna `instructions`: `Column::key('icons')->type('TEXT'),`. In `dataSchema()`: `Field::key('icons')->text()->sanitize(false),`. Nel docblock della classe aggiungere una riga: «`icons` è il CSV delle icone (`ICONS`); `none` vuol dire nessuna, vuoto vuol dire mai deciso.»

- [ ] **Step 4: La resource**

In `src/Resources/Payments/PaymentMethodResource.php`:
- nelle etichette (vicino a `'instructions' => 'Istruzioni'`): `'icons' => 'Icone',`;
- in `formSchema()`, dopo il campo `instructions`: `FormField::key('icons')->selectSearch(PaymentMethod::ICONS, true)->label('Icone'),`;
- nella Card «Istruzioni e fattura», come primo campo dopo il `SectionTitle`: `static::getInput('icons')->columnSpan(12),`; nel tooltip del `SectionTitle` di quella Card aggiungere la frase «Le icone sono i loghi mostrati accanto al metodo nel checkout.»;
- in `mutateRequestValues`, subito dopo `$values = Channels::keepHidden($values, $oldValues);`:

```php
        // Vuote all'inserimento: quelle del tipo. Tolte a mano: «none», così non tornano.
        if (array_key_exists('icons', $values) || $action === 'store' || array_key_exists('provider', $values)) {
            $icons = PaymentMethod::iconsOf(implode(',', array_map('strval', (array) ($values['icons'] ?? []))));
            if ($icons === [] && $action === 'store') {
                $icons = PaymentMethod::defaultIcons((string) ($values['provider'] ?? ''));
            }
            $values['icons'] = $icons === [] ? 'none' : implode(',', $icons);
        }
```

- un nuovo metodo dopo `mutateRequestValues`:

```php
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        $values['icons'] = PaymentMethod::iconsOf((string) ($values['icons'] ?? ''));

        return $values;
    }
```

Attenzione: `array_key_exists('provider', …)` senza `icons` nel POST (un salvataggio da un altro form) farebbe `none`. Il form del metodo manda sempre `icons`; il check «i canali che il form non mostra restano com'erano» passa `['name' => 'Bonifico']` in update: lì `icons` non esiste e il blocco non scatta. Va bene.

- [ ] **Step 5: Le icone dei metodi già salvati**

In `src/Seeding/Defaults.php`, in `legacyPaymentMethods()`, subito prima di `if ($changes !== []) {`:

```php
            // I metodi nati prima delle icone prendono quelle del loro tipo.
            if (trim((string) ($row['icons'] ?? '')) === '') {
                $icons = implode(',', PaymentMethod::defaultIcons((string) ($changes['provider'] ?? $row['provider'] ?? '')));
                $changes['icons'] = $icons === '' ? 'none' : $icons;
            }
```

- [ ] **Step 6: Lanciare i test**

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale; php tests/PaymentMethodResourceTest.php | tail -3`
Expected: tutti `✓`, `… 0 falliti`.

Run: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update | tail -5`
Expected: la colonna `icons` creata, nessun errore.

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale; php tests/integrazione/DefaultsTest.php | tail -3`
Expected: tutti `✓`, `… 0 falliti`.

- [ ] **Step 7: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale; git branch --show-current; git add src/Models/Payments/PaymentMethod.php src/Resources/Payments/PaymentMethodResource.php src/Seeding/Defaults.php; git add -f tests/PaymentMethodResourceTest.php tests/integrazione/DefaultsTest.php; git commit -m "E1c: icone dei metodi di pagamento

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: font del negozio online (gestionale)

**Files:**
- Modify: `gestionale/src/Models/System/MerchantSetting.php`
- Modify: `gestionale/src/Resources/System/MerchantSettingResource.php`
- Test: `gestionale/tests/SettingsTest.php`

**Interfaces:**
- Consumes: `Wonder\View\WebFonts::all(): array<string, string>`, `WebFonts::has(string): bool` (piano 1).
- Produces: colonne `font_auth`, `font_account`, `font_cart` (`''` = come il sito) e `font_checkout` (default `inter`) su `MerchantSetting`; `MerchantSetting::current()['font_<area>']`.

- [ ] **Step 1: Scrivere i test che falliscono**

In `tests/SettingsTest.php`, prima di `$forza(null);`:

```php
check('il commerciante sceglie il font di accesso, account, checkout e carrello', function () use ($colonne) {
    $c = $colonne(MerchantSetting::class);
    $schema = MerchantSetting::getSchema('default');

    return in_array('font_auth', $c, true) && in_array('font_account', $c, true)
        && in_array('font_cart', $c, true) && in_array('font_checkout', $c, true)
        && ($schema['font_checkout']['default'] ?? null) === 'inter';
});

check('un font sconosciuto si salva vuoto, uno noto resta', function () {
    $v = MerchantSettingResource::mutateRequestValues(['font_checkout' => 'comic', 'font_auth' => 'inter', 'font_cart' => ''], 'update');

    return $v['font_checkout'] === '' && $v['font_auth'] === 'inter' && $v['font_cart'] === '';
});
```

Se `getSchema('default')` non restituisce `default` in quella forma, leggere com'è fatto con `var_dump(MerchantSetting::getSchema('default')['low_stock_emails'])` e adattare **solo** l'accesso alla chiave (ledger).

- [ ] **Step 2: Lanciarli e vederli fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale; php tests/SettingsTest.php | tail -4`
Expected: i due check nuovi con `✗`.

- [ ] **Step 3: Model**

In `src/Models/System/MerchantSetting.php`, in `tableSchema()` dopo `low_stock_emails`:

```php
            Column::key('font_auth')->length(40),
            Column::key('font_account')->length(40),
            Column::key('font_cart')->length(40),
            Column::key('font_checkout')->length(40)->default('inter'),
```

In `dataSchema()`:

```php
            Field::key('font_auth')->text()->sanitize(false),
            Field::key('font_account')->text()->sanitize(false),
            Field::key('font_cart')->text()->sanitize(false),
            Field::key('font_checkout')->text()->sanitize(false),
```

Nel docblock: «`font_*` sono i font del negozio online per area (chiavi di `WebFonts`); vuoto vuol dire come il sito.»

- [ ] **Step 4: Resource**

In `src/Resources/System/MerchantSettingResource.php`:
- `use Wonder\View\WebFonts;`;
- etichette: `'font_auth' => 'Font accesso', 'font_account' => 'Font account', 'font_checkout' => 'Font checkout', 'font_cart' => 'Font carrello',`;
- in `formSchema()`, prima del `return`:

```php
        if (Gestionale::feature('online_sales')) {
            foreach (['auth' => 'Font accesso', 'account' => 'Font account', 'checkout' => 'Font checkout', 'cart' => 'Font carrello'] as $area => $label) {
                $fields[] = FormField::key('font_'.$area)->select(['' => 'Come il sito'] + WebFonts::all())->label($label);
            }
        }
```

- in `formLayoutSchema()`, prima del `return`:

```php
        if (Gestionale::feature('online_sales')) {
            $cards[] = (new Card)->components([
                SectionTitle::make('Negozio online')
                    ->tooltip('Il font delle pagine di accesso, account, checkout e carrello. «Come il sito» usa quello del tema.')
                    ->columnSpan(12),
                static::getInput('font_auth')->columnSpan(6),
                static::getInput('font_account')->columnSpan(6),
                static::getInput('font_checkout')->columnSpan(6),
                static::getInput('font_cart')->columnSpan(6),
            ])->columns(12)->columnSpan(12);
        }
```

- in `mutateRequestValues`, come prima istruzione (prima di `if (!array_key_exists('low_stock_emails', $values))`):

```php
        foreach (['auth', 'account', 'checkout', 'cart'] as $area) {
            if (array_key_exists('font_'.$area, $values)) {
                $font = strtolower(trim((string) $values['font_'.$area]));
                $values['font_'.$area] = WebFonts::has($font) ? $font : '';
            }
        }
```

Se la funzionalità `online_sales` non esiste con questo nome, cercarla con `grep -rn "'online_sales'\|online" src/Gestionale.php` e usare quella (ledger).

- [ ] **Step 5: Lanciare i test e aggiornare il sito di prova**

Run: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update | tail -5`
Expected: quattro colonne nuove, nessun errore.

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale; php tests/SettingsTest.php | tail -3`
Expected: tutti `✓`, `… 0 falliti`.

- [ ] **Step 6: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale; git add src/Models/System/MerchantSetting.php src/Resources/System/MerchantSettingResource.php; git add -f tests/SettingsTest.php; git commit -m "E1c: font del negozio online nelle impostazioni del commerciante

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: solo i provider collegati nell'anteprima, con icone e commissione (gestionale)

**Files:**
- Create: `gestionale/src/Support/Payments/PaymentProviders.php`
- Modify: `gestionale/src/Support/Orders/Checkout.php` (`paymentMethods` ~377, `paymentChoice` ~396, docblock di `preview` ~110-122)
- Test: `gestionale/tests/integrazione/CheckoutSpedizioneTest.php`

**Interfaces:**
- Consumes: `PaymentMethod::iconsOf()` (Task 2), `PaymentMethod::ledgerProvider(string): string`.
- Produces:
  - `PaymentProviders::connected(string $provider): bool` — vero per i manuali; per gli online solo quelli in `ONLINE` (vuoto fino a D6).
  - Ogni voce di `preview()['payment_methods']['options']`: `{id, name, provider, manual, instructions, icons: list<string>, fee_type: string, fee_value: float, fee_percent: float}`.
  - `allowed()` e `method()` **non** cambiano: gli ordini del backend accettano ancora tutti i metodi.

- [ ] **Step 1: Scrivere il test che fallisce**

In `tests/integrazione/CheckoutSpedizioneTest.php`, dopo il check della riga ~206 («il ritiro filtra i pagamenti»):

```php
check('l\'anteprima mostra solo i pagamenti collegati, con icone e commissione', fn () => prova(static function (): bool {
    $metodo = metodo('Standard');
    listino($metodo, zona('Italia', [['IT', '']]), [[5, 8.0]]);
    $stripe = pagamento(PaymentMethod::TIMING_IMMEDIATE);
    $bonifico = pagamento('deferred', ['icons' => 'genericbank,<x>', 'fee_type' => 'amount', 'fee_value' => '1.50']);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $p = Checkout::preview($cart, ['shipping_country' => 'IT', 'shipping_method_id' => $metodo]);
    $voci = array_column((array) $p['payment_methods']['options'], null, 'id');

    return !isset($voci[$stripe]) && isset($voci[$bonifico])
        && $voci[$bonifico]['icons'] === ['genericbank']
        && $voci[$bonifico]['fee_type'] === 'amount'
        && $voci[$bonifico]['fee_value'] === 1.5;
}));
```

Prima di scriverlo, controllare con `grep -n "function pagamento\|TIMING_" tests/integrazione/CheckoutSpedizioneTest.php ../gestionale/src/Models/Payments/PaymentMethod.php` come si chiama il timing immediato e come `pagamento()` riceve i valori in più; usare quei nomi esatti (se il timing è la stringa `'immediate'`, scrivere `'immediate'`).

- [ ] **Step 2: Lanciarlo e vederlo fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale; php tests/integrazione/CheckoutSpedizioneTest.php | tail -4`
Expected: il check nuovo `✗` (Stripe ancora nelle opzioni o `icons` mancante).

- [ ] **Step 3: `PaymentProviders`**

`src/Support/Payments/PaymentProviders.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;

/**
 * I provider che il checkout online può usare davvero: i manuali sempre,
 * quelli online solo quando il collegamento è fatto (D6).
 */
final class PaymentProviders
{
    /** @var list<string> */
    private const ONLINE = [];

    public static function connected(string $provider): bool
    {
        return PaymentMethod::ledgerProvider($provider) === 'manual' || in_array($provider, self::ONLINE, true);
    }
}
```

- [ ] **Step 4: `Checkout`**

In `src/Support/Orders/Checkout.php`:
- `use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;`;
- in `paymentMethods()`, nella closure del filtro che chiama `self::allowed(...)`, aggiungere `&& PaymentProviders::connected((string) ($method['provider'] ?? ''))`;
- in `paymentChoice()`, aggiungere all'array restituito:

```php
            'icons' => PaymentMethod::iconsOf((string) ($method['icons'] ?? '')),
            'fee_type' => (string) ($method['fee_type'] ?? 'none'),
            'fee_value' => (float) ($method['fee_value'] ?? 0),
            'fee_percent' => (float) ($method['fee_percent'] ?? 0),
```

- nel docblock di `preview()` la riga di `payment_methods` diventa: «`payment_methods`: `{options: [{id, name, provider, manual, instructions, icons, fee_type, fee_value, fee_percent}], selected}` — solo i provider collegati (`PaymentProviders::connected`)»; nel docblock di `paymentChoice()` lo stesso elenco di chiavi.

- [ ] **Step 5: Lanciare i test**

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale; php tests/integrazione/CheckoutSpedizioneTest.php | tail -3`
Expected: tutti `✓` (compreso il check della riga ~271 «l'anteprima con stripe non apre pagamenti»), `… 0 falliti`.

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale; for f in tests/integrazione/*Test.php; do echo "$f"; php "$f" | tail -1; done`
Expected: ogni riga `… 0 falliti`.

- [ ] **Step 6: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale; git add src/Support/Payments/PaymentProviders.php src/Support/Orders/Checkout.php; git add -f tests/integrazione/CheckoutSpedizioneTest.php; git commit -m "E1c: l'anteprima del checkout mostra solo i pagamenti collegati, con icone e commissione

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: SVG delle icone di pagamento (ecommerce)

**Files:**
- Create: `ecommerce/resources/assets/payment-icons/<chiave>.svg` (14 file) e `ecommerce/resources/assets/payment-icons/LICENSE`
- Test: `ecommerce/tests/CartCheckoutTest.php`

**Interfaces:**
- Consumes: `PaymentMethod::ICONS` (Task 2).
- Produces: `module_asset('ecommerce', 'payment-icons/<chiave>.svg')` per ogni chiave.

- [ ] **Step 1: Scrivere il test che fallisce**

In `tests/CartCheckoutTest.php`, prima di `summary();`:

```php
check('ogni icona di pagamento ha il suo SVG e la licenza', function (): bool {
    $dir = dirname(__DIR__).'/resources/assets/payment-icons';
    foreach (array_keys(\Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod::ICONS) as $key) {
        $svg = (string) @file_get_contents($dir.'/'.$key.'.svg');
        if (!str_contains($svg, '<svg') || str_contains($svg, '<script')) {
            return false;
        }
    }

    return str_contains((string) @file_get_contents($dir.'/LICENSE'), 'MIT');
});
```

- [ ] **Step 2: Lanciarlo e vederlo fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/CartCheckoutTest.php | tail -3`
Expected: il check nuovo `✗`.

- [ ] **Step 3: Chiedere la conferma e scaricare**

Chiedere all'utente: «Scarico 14 SVG (pochi KB l'uno) e la licenza MIT da github.com/activemerchant/payment_icons in `ecommerce/resources/assets/payment-icons/`?». **Fermarsi finché non risponde sì.**

Dopo il sì:

```bash
cd /Users/andreamarinoni/Developer/packages/ecommerce; mkdir -p resources/assets/payment-icons; cd resources/assets/payment-icons; for k in visa master maestro american_express diners_club discover jcb unionpay paypal google_pay apple_pay klarna genericbank cash; do curl -fsSL -o "$k.svg" "https://raw.githubusercontent.com/activemerchant/payment_icons/master/app/assets/images/payment_icons/$k.svg" || echo "MANCA $k"; done; curl -fsSL -o LICENSE https://raw.githubusercontent.com/activemerchant/payment_icons/master/LICENSE || echo 'MANCA LICENSE'; ls -la; grep -l '<script' *.svg; echo '----'; head -3 LICENSE
```

Expected: 14 SVG e `LICENSE` (che contiene «MIT»); nessun `MANCA`; `grep -l '<script'` non stampa nulla.

Se una chiave stampa `MANCA` (404): cercare il nome giusto nella cartella del repository (`https://github.com/activemerchant/payment_icons/tree/master/app/assets/images/payment_icons`) e salvarlo col nome della chiave nostra; se non esiste, ledger `Ruling:` e togliere la chiave da `PaymentMethod::ICONS` (Task 2) con un commit nel gestionale. Se `LICENSE` non c'è a quel percorso, scrivere a mano il testo MIT con «Copyright (c) Shopify» preso dal README del repository (ledger).

- [ ] **Step 4: Lanciare il test**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/CartCheckoutTest.php | tail -2`
Expected: il check nuovo `✓`.

- [ ] **Step 5: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/ecommerce; git add resources/assets/payment-icons; git add -f tests/CartCheckoutTest.php; git commit -m "E1c: icone dei metodi di pagamento (activemerchant/payment_icons, MIT)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: segnaposto del check-out rapido (ecommerce)

**Files:**
- Create: `ecommerce/src/Frontend/Checkout/ExpressCheckout.php`
- Test: `ecommerce/tests/CartCheckoutTest.php`

**Interfaces:**
- Produces: `ExpressCheckout::buttons(array $order): list<string>` — HTML dei bottoni rapidi; oggi sempre `[]` (arrivano con D6). La vista mostra la sezione «Check-out rapido» solo se non è vuoto.

- [ ] **Step 1: Test che fallisce**

In `tests/CartCheckoutTest.php`, prima di `summary();`:

```php
check('il check-out rapido non ha ancora bottoni (arrivano coi pagamenti online)', fn () =>
    \Wonder\Plugin\Ecommerce\Frontend\Checkout\ExpressCheckout::buttons(['total' => '10.00']) === []
);
```

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/CartCheckoutTest.php | tail -2`
Expected: `✗` con «Class … ExpressCheckout not found».

- [ ] **Step 2: La classe**

```php
<?php

namespace Wonder\Plugin\Ecommerce\Frontend\Checkout;

/**
 * I bottoni del check-out rapido (Google Pay, Apple Pay, PayPal) in cima
 * alla pagina. Vuoto finché i pagamenti online non sono collegati (D6):
 * la vista allora non stampa la sezione.
 */
final class ExpressCheckout
{
    /**
     * @param array<string, mixed> $order
     * @return list<string> HTML già pronto, uno per bottone
     */
    public static function buttons(array $order): array
    {
        return [];
    }
}
```

- [ ] **Step 3: Test e commit**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/CartCheckoutTest.php | tail -2`
Expected: il check nuovo `✓`.

```bash
cd /Users/andreamarinoni/Developer/packages/ecommerce; git add src/Frontend/Checkout/ExpressCheckout.php; git add -f tests/CartCheckoutTest.php; git commit -m "E1c: segnaposto del check-out rapido

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: `CheckoutRules` al posto di `CheckoutSteps` (ecommerce)

**Files:**
- Rename: `ecommerce/src/Frontend/Checkout/CheckoutSteps.php` → `CheckoutRules.php`
- Rename: `ecommerce/tests/integrazione/CheckoutStepsTest.php` → `CheckoutRulesTest.php`
- Modify: `ecommerce/src/Frontend/Checkout/CheckoutController.php` (solo i nomi: `CheckoutSteps::` → `CheckoutRules::`, finché il Task 10 non lo riscrive)

**Interfaces:**
- Consumes: `Order::shippingAddress()->keys()`.
- Produces:
  - `CheckoutRules::CONSENTS` (invariata).
  - `CheckoutRules::post(array $post, ?object $user = null): array` — il POST come lo scrive la pagina unica: `shipping_phone = phone`; col ritiro l'indirizzo di spedizione si svuota (restano `shipping_name`, `shipping_surname`, `shipping_phone`, `shipping_phone_prefix`); con un utente con email, `email` = la sua.
  - `CheckoutRules::addressComplete(array $order): bool` — paese, città, CAP e via di spedizione pieni.
  - `CheckoutRules::deliveryErrors(array $order, array $preview, bool $shipping): list<string>` — ex `shippingErrors`, stesso risultato.
  - `CheckoutRules::method(array $preview, int $id): ?array` — l'opzione di pagamento dell'anteprima con quell'id, o null.
  - `CheckoutRules::billing(array $post, array $order): array` — «uguale» di default (`same_as_shipping` assente o diverso da `'0'`), mai col ritiro; col ritiro nome, cognome e telefono di fatturazione vuoti prendono quelli della consegna.
  - `CheckoutRules::paymentErrors()` e `askedConsents()` invariati.
  - Tolti: `shippingComplete`, `fromCart`, `CART_KEYS` (il controller ha il suo `fromCart` privato dal Task 10; fino ad allora lo si copia lì, vedi Step 4).

- [ ] **Step 1: Rinominare**

```bash
cd /Users/andreamarinoni/Developer/packages/ecommerce; git mv src/Frontend/Checkout/CheckoutSteps.php src/Frontend/Checkout/CheckoutRules.php; git mv tests/integrazione/CheckoutStepsTest.php tests/integrazione/CheckoutRulesTest.php
```

Nel test: `/** php tests/integrazione/CheckoutRulesTest.php */`, `use …\CheckoutRules;`, ogni `CheckoutSteps::` → `CheckoutRules::`, ogni `shippingErrors(` → `deliveryErrors(`. Nel check «la Spedizione completa non ha errori» togliere `&& CheckoutSteps::shippingComplete($o, $p, true)`; togliere tutto il check «fromCart legge contatto e indirizzi ma non i totali».

- [ ] **Step 2: Scrivere i test nuovi**

Nel test, dopo il check «il privato con fattura tiene solo il codice fiscale»:

```php
check('la fatturazione è uguale alla spedizione finché non si sceglie «diverso»', function (): bool {
    $order = ['fulfillment_type' => 'shipping', 'shipping_name' => 'Ada', 'shipping_city' => 'Milano'];
    $uguale = CheckoutRules::billing(['billing_name' => 'Altro', 'billing_city' => 'Roma'], $order);
    $diverso = CheckoutRules::billing(['same_as_shipping' => '0', 'billing_name' => 'Bea', 'billing_city' => 'Roma'], $order);

    return $uguale['billing_name'] === 'Ada' && $uguale['billing_city'] === 'Milano'
        && $diverso['billing_name'] === 'Bea' && $diverso['billing_city'] === 'Roma';
});

check('col ritiro la fatturazione prende nome e telefono della consegna se vuoti', function (): bool {
    $b = CheckoutRules::billing(['billing_city' => 'Roma', 'billing_surname' => 'Mia'], ['fulfillment_type' => 'pickup', 'shipping_name' => 'Ada', 'shipping_surname' => 'L', 'shipping_phone' => '333']);

    return $b['billing_name'] === 'Ada' && $b['billing_surname'] === 'Mia' && $b['billing_phone'] === '333' && $b['billing_city'] === 'Roma';
});

check('POST senza JavaScript col ritiro: l\'indirizzo di spedizione si svuota, il nome resta', function (): bool {
    $p = CheckoutRules::post(['fulfillment_type' => 'pickup', 'phone' => '333', 'shipping_name' => 'Ada', 'shipping_city' => 'Milano', 'shipping_street' => 'Via', 'email' => 'c@example.com']);

    return $p['shipping_city'] === '' && $p['shipping_street'] === '' && $p['shipping_name'] === 'Ada'
        && $p['shipping_phone'] === '333' && $p['email'] === 'c@example.com';
});

check('con l\'accesso fatto vince l\'email dell\'utente', function (): bool {
    $p = CheckoutRules::post(['email' => 'altra@example.com', 'phone' => '1'], (object) ['email' => 'ada@example.com']);
    $senza = CheckoutRules::post(['email' => 'altra@example.com'], (object) ['email' => '']);

    return $p['email'] === 'ada@example.com' && $senza['email'] === 'altra@example.com';
});

check('un metodo di pagamento che non è nell\'anteprima non vale', function (): bool {
    $preview = ['payment_methods' => ['options' => [['id' => 4, 'name' => 'Bonifico', 'manual' => true]]]];

    return (CheckoutRules::method($preview, 4)['name'] ?? '') === 'Bonifico'
        && CheckoutRules::method($preview, 5) === null
        && CheckoutRules::method([], 4) === null;
});

check('l\'indirizzo è completo con paese, città, CAP e via', fn () =>
    CheckoutRules::addressComplete(['shipping_country' => 'IT', 'shipping_city' => 'Milano', 'shipping_cap' => '20100', 'shipping_street' => 'Via'])
    && !CheckoutRules::addressComplete(['shipping_country' => 'IT', 'shipping_city' => 'Milano', 'shipping_cap' => '', 'shipping_street' => 'Via'])
);
```

Il check vecchio ««Uguale alla spedizione» copia nome e indirizzo» resta (passa `same_as_shipping => '1'`). Il check ««Uguale» è ignorato col ritiro» cambia il `return` in `return $b['billing_name'] === 'Bea';` (invariato: `Bea` non è vuoto).

- [ ] **Step 3: Lanciarli e vederli fallire**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/integrazione/CheckoutRulesTest.php | tail -8`
Expected: FAIL — «Class … CheckoutRules not found» (la classe si chiama ancora `CheckoutSteps`).

- [ ] **Step 4: La classe**

In `src/Frontend/Checkout/CheckoutRules.php`:
- `final class CheckoutRules`, docblock: «Le regole della pagina di checkout: cosa serve per ordinare e cosa si scrive sull'ordine.»;
- togliere `CART_KEYS`, `shippingComplete()` e `fromCart()`;
- aggiungere `private const KEEP = ['shipping_name', 'shipping_surname', 'shipping_phone', 'shipping_phone_prefix'];`;
- rinominare `shippingErrors` in `deliveryErrors` (docblock: «Cosa manca a contatti e consegna. `$order` è il carrello riletto dopo l'anteprima.») e sostituire il ciclo `foreach (self::ADDRESS …) { … $errors[] = 'address'; break; }` con:

```php
        if (!self::addressComplete($order)) {
            $errors[] = 'address';
        }
```

- aggiungere:

```php
    /** @param array<string, mixed> $order */
    public static function addressComplete(array $order): bool
    {
        foreach (self::ADDRESS as $field) {
            if (trim((string) ($order['shipping_'.$field] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Il POST della pagina come va sul carrello: il telefono del contatto è
     * anche quello del corriere, col ritiro non resta un indirizzo vecchio,
     * con l'accesso fatto l'email è quella dell'utente.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function post(array $post, ?object $user = null): array
    {
        $post['shipping_phone'] = (string) ($post['phone'] ?? '');

        if ((string) ($post['fulfillment_type'] ?? '') === 'pickup') {
            foreach (Order::shippingAddress()->keys() as $key) {
                if (!in_array($key, self::KEEP, true)) {
                    $post[$key] = '';
                }
            }
        }

        $email = trim((string) ($user->email ?? ''));
        if ($email !== '') {
            $post['email'] = $email;
        }

        return $post;
    }

    /**
     * L'opzione di pagamento dell'anteprima con quell'id: un id che la pagina
     * non ha offerto (manipolato o di un provider non collegato) non vale.
     *
     * @param array<string, mixed> $preview
     * @return array<string, mixed>|null
     */
    public static function method(array $preview, int $id): ?array
    {
        foreach ((array) ($preview['payment_methods']['options'] ?? []) as $option) {
            if (is_array($option) && (int) ($option['id'] ?? 0) === $id && $id > 0) {
                return $option;
            }
        }

        return null;
    }
```

- in `billing()` sostituire il blocco `if (!empty($post['same_as_shipping']) && …) { … }` con:

```php
        $pickup = (string) ($order['fulfillment_type'] ?? '') === 'pickup';
        // «Uguale all'indirizzo di spedizione» è la scelta di partenza.
        if (!$pickup && (string) ($post['same_as_shipping'] ?? '1') !== '0') {
            foreach (self::SAME as $field) {
                $billing['billing_'.$field] = trim((string) ($order['shipping_'.$field] ?? ''));
            }
        }

        // Col ritiro nome e telefono si scrivono una volta sola, nella consegna.
        if ($pickup) {
            foreach (['name', 'surname', 'phone'] as $field) {
                if (($billing['billing_'.$field] ?? '') === '') {
                    $billing['billing_'.$field] = trim((string) ($order['shipping_'.$field] ?? ''));
                }
            }
        }
```

  e aggiornare il docblock di `billing()`: «“Uguale all'indirizzo di spedizione” (di partenza, `same_as_shipping` diverso da `0`) copia l'indirizzo, non col ritiro…».

In `CheckoutController.php` sostituire `CheckoutSteps::` con `CheckoutRules::` e `shippingErrors(`/`shippingComplete(` con `deliveryErrors(` (per `shippingComplete(...)` scrivere `deliveryErrors(...) === []`); per le chiamate a `fromCart(` aggiungere al controller, per ora, il metodo privato:

```php
    /**
     * I valori dei moduli presi dal carrello: contatto, scelte, nota e indirizzi.
     *
     * @param array<string, mixed> $order
     * @return array<string, string>
     */
    private static function fromCart(array $order): array
    {
        $values = [];
        foreach (array_merge(self::CART_KEYS, Order::shippingAddress()->keys(), Order::billingAddress()->keys()) as $key) {
            $values[$key] = trim((string) ($order[$key] ?? ''));
        }

        return $values;
    }
```

con `private const CART_KEYS = ['email', 'phone', 'fulfillment_type', 'shipping_method_id', 'location_id', 'payment_method_id', 'customer_note'];` e `CheckoutRules::fromCart(` → `self::fromCart(`.

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; grep -rn "CheckoutSteps" src view tests docs config`
Expected: nessuna riga (se ce ne sono in `tests/CartCheckoutTest.php` o nella documentazione, sostituire il nome).

- [ ] **Step 5: Lanciare i test**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/integrazione/CheckoutRulesTest.php | tail -3; php tests/CartCheckoutTest.php | tail -1`
Expected: `CheckoutRulesTest` tutti `✓`; `CartCheckoutTest` senza fallimenti nuovi rispetto a prima del task (alcuni check sui passi possono già cercare `CheckoutSteps::shippingComplete(`: sostituire la stringa cercata con `CheckoutRules::deliveryErrors(`, il check verrà riscritto al Task 10).

- [ ] **Step 6: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/ecommerce; git add -A src/Frontend/Checkout; git add -f tests/integrazione/CheckoutRulesTest.php tests/CartCheckoutTest.php; git status --short; git commit -m "E1c: CheckoutRules, le regole della pagina unica (fatturazione uguale di partenza, POST, metodo)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Expected: `git status --short` prima del commit mostra solo file di questo task (il rename `R`); le modifiche dell'utente, se il Task 1 non le ha committate, restano fuori.

---

### Task 8: `StoreFont` e font nei layout (ecommerce)

**Files:**
- Create: `ecommerce/src/Frontend/StoreFont.php`
- Modify: `ecommerce/view/layout/frontend/ecommerce.auth.php`, `ecommerce.account.php`, `ecommerce.checkout.php`
- Test: `ecommerce/tests/integrazione/StoreFontTest.php`, `ecommerce/tests/CartCheckoutTest.php`

**Interfaces:**
- Consumes: `MerchantSetting::current(): array` e le colonne `font_*` (Task 3); `WebFonts::css(string $key, ?string $baseUrl = null): string` (piano 1).
- Produces: `StoreFont::style(string $area, ?array $settings = null): string` — `<style>…</style>` col font dell'area (`auth`, `account`, `checkout`, `cart`), `''` per area o font sconosciuti o vuoti. `$settings` serve ai test; senza, si legge `MerchantSetting::current()`.

- [ ] **Step 1: Test che falliscono**

`tests/integrazione/StoreFontTest.php`:

```php
<?php
/** php tests/integrazione/StoreFontTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\Plugin\Ecommerce\Frontend\StoreFont;

check('il font scelto per l\'area diventa uno <style> con le variabili del sito', function (): bool {
    $css = StoreFont::style('checkout', ['font_checkout' => 'inter']);

    return str_starts_with($css, '<style>') && str_ends_with($css, '</style>')
        && str_contains($css, '@font-face') && str_contains($css, 'Inter');
});

check('area sconosciuta, font sconosciuto o vuoto: niente', fn () =>
    StoreFont::style('blog', ['font_blog' => 'inter']) === ''
    && StoreFont::style('checkout', ['font_checkout' => 'comic']) === ''
    && StoreFont::style('cart', ['font_cart' => '']) === ''
);

check('senza impostazioni legge quelle del commerciante', fn () =>
    is_string(StoreFont::style('checkout'))
);

summary();
```

In `tests/CartCheckoutTest.php`, prima di `summary();`:

```php
check('accesso, account e checkout stampano il loro font', function (): bool {
    $dir = dirname(__DIR__).'/view/layout/frontend/';
    foreach (['auth', 'account', 'checkout'] as $area) {
        if (!str_contains((string) file_get_contents($dir.'ecommerce.'.$area.'.php'), "StoreFont::style('".$area."')")) {
            return false;
        }
    }

    return true;
});
```

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/integrazione/StoreFontTest.php | tail -4; php tests/CartCheckoutTest.php | tail -2`
Expected: FAIL — «Class … StoreFont not found» e il check dei layout `✗`.

- [ ] **Step 2: La classe**

`src/Frontend/StoreFont.php`:

```php
<?php

namespace Wonder\Plugin\Ecommerce\Frontend;

use Throwable;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\View\WebFonts;

/** Il font che il commerciante ha scelto per accesso, account, checkout e carrello. */
final class StoreFont
{
    public const AREAS = ['auth', 'account', 'checkout', 'cart'];

    /** @param array<string, mixed>|null $settings le impostazioni del commerciante (null: quelle salvate) */
    public static function style(string $area, ?array $settings = null): string
    {
        if (!in_array($area, self::AREAS, true)) {
            return '';
        }

        if ($settings === null) {
            try {
                $settings = MerchantSetting::current();
            } catch (Throwable) {
                return '';
            }
        }

        $css = WebFonts::css((string) ($settings['font_'.$area] ?? ''));

        return $css === '' ? '' : '<style>'.$css.'</style>';
    }
}
```

- [ ] **Step 3: I layout**

- `view/layout/frontend/ecommerce.auth.php`: subito prima di `echo $PAGE_CONTENT;` aggiungere `echo \Wonder\Plugin\Ecommerce\Frontend\StoreFont::style('auth');`.
- `view/layout/frontend/ecommerce.account.php`: subito prima di `echo $PAGE_CONTENT;` aggiungere `echo \Wonder\Plugin\Ecommerce\Frontend\StoreFont::style('account');`.
- `view/layout/frontend/ecommerce.checkout.php` (file dell'utente: solo dopo il Task 1): subito dopo `View::layout('frontend.minimal')` aggiungere `<?=\Wonder\Plugin\Ecommerce\Frontend\StoreFont::style('checkout')?>` (dentro un blocco PHP già aperto: `echo …;`).

Leggere ciascun file prima di modificarlo; la riga va nel contenuto della pagina, non nel `<head>` (lo `<style>` vale ovunque nel body).

- [ ] **Step 4: Test e commit**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/integrazione/StoreFontTest.php | tail -2; php tests/CartCheckoutTest.php | tail -1`
Expected: `3 test, 0 falliti`; il check dei layout `✓`.

```bash
cd /Users/andreamarinoni/Developer/packages/ecommerce; git add src/Frontend/StoreFont.php view/layout/frontend/ecommerce.auth.php view/layout/frontend/ecommerce.account.php view/layout/frontend/ecommerce.checkout.php; git add -f tests/integrazione/StoreFontTest.php tests/CartCheckoutTest.php; git commit -m "E1c: font del commerciante su accesso, account e checkout

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: testi it/en della pagina unica (ecommerce)

**Files:**
- Modify: `ecommerce/lang/it/ecommerce.json`, `ecommerce/lang/en/ecommerce.json`
- Test: `ecommerce/tests/CartCheckoutTest.php`

**Interfaces:**
- Produces (chiavi usate da Task 10-13):
  - `ecommerce.cart.products_total` «Subtotale» / «Subtotal»; `ecommerce.cart.proceed` «Vai al checkout» / «Go to checkout».
  - `ecommerce.checkout.submit_manual` «Ordina» / «Place order»; `submit_online` «Paga ora» / «Pay now»; `billing_same` «Uguale all'indirizzo di spedizione» / «Same as shipping address».
  - Nuove in `ecommerce.checkout`: `express`, `or`, `secure`, `logout`, `billing_different`, `field_required` (con `{{label}}`), `shipping_pending`, `shipping_methods_pending`, `redirect_panel` (con `{{name}}`).
  - Tolte: `ecommerce.checkout.steps` (tutto il gruppo), `continue_payment`, `edit`, `errors.shipping_incomplete`.

- [ ] **Step 1: Test che fallisce**

In `tests/CartCheckoutTest.php`, prima di `summary();`:

```php
check('i testi della pagina unica ci sono in italiano e in inglese, quelli dei passi no', function (): bool {
    foreach (['it', 'en'] as $lingua) {
        $t = json_decode((string) file_get_contents(dirname(__DIR__).'/lang/'.$lingua.'/ecommerce.json'), true);
        $c = (array) ($t['checkout'] ?? []);
        foreach (['express', 'or', 'secure', 'logout', 'billing_different', 'field_required', 'shipping_pending', 'shipping_methods_pending', 'redirect_panel'] as $chiave) {
            if (trim((string) ($c[$chiave] ?? '')) === '') {
                return false;
            }
        }
        if (isset($c['steps']) || isset($c['continue_payment']) || isset($c['edit']) || isset($c['errors']['shipping_incomplete'])
            || !str_contains((string) $c['field_required'], '{{label}}') || !str_contains((string) $c['redirect_panel'], '{{name}}')) {
            return false;
        }
    }

    $it = json_decode((string) file_get_contents(dirname(__DIR__).'/lang/it/ecommerce.json'), true);

    return $it['cart']['products_total'] === 'Subtotale' && $it['checkout']['submit_online'] === 'Paga ora'
        && $it['checkout']['submit_manual'] === 'Ordina';
});
```

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/CartCheckoutTest.php | tail -2`
Expected: il check nuovo `✗`.

- [ ] **Step 2: I testi**

Con uno script PHP (mantiene l'ordine delle chiavi e gli accenti):

```bash
cd /Users/andreamarinoni/Developer/packages/ecommerce; php -r '
$testi = [
  "it" => ["cart" => ["products_total" => "Subtotale", "proceed" => "Vai al checkout"],
    "checkout" => ["submit_manual" => "Ordina", "submit_online" => "Paga ora", "billing_same" => "Uguale all’indirizzo di spedizione",
      "express" => "Check-out rapido", "or" => "OPPURE", "secure" => "Tutte le transazioni sono sicure e crittografate", "logout" => "Esci",
      "billing_different" => "Utilizza un indirizzo diverso", "field_required" => "Inserisci {{label}}",
      "shipping_pending" => "Inserisci l’indirizzo di spedizione",
      "shipping_methods_pending" => "Inserisci l’indirizzo di spedizione per vedere i metodi disponibili",
      "redirect_panel" => "Dopo aver cliccato «Paga ora» verrai reindirizzato a {{name}} per completare l’acquisto in modo sicuro"]],
  "en" => ["cart" => ["products_total" => "Subtotal", "proceed" => "Go to checkout"],
    "checkout" => ["submit_manual" => "Place order", "submit_online" => "Pay now", "billing_same" => "Same as shipping address",
      "express" => "Express checkout", "or" => "OR", "secure" => "All transactions are secure and encrypted", "logout" => "Log out",
      "billing_different" => "Use a different billing address", "field_required" => "Enter {{label}}",
      "shipping_pending" => "Enter your shipping address",
      "shipping_methods_pending" => "Enter your shipping address to view available shipping methods",
      "redirect_panel" => "After clicking “Pay now”, you will be redirected to {{name}} to complete your purchase securely"]],
];
foreach ($testi as $lingua => $gruppi) {
  $file = "lang/$lingua/ecommerce.json";
  $t = json_decode(file_get_contents($file), true);
  foreach ($gruppi as $gruppo => $voci) { foreach ($voci as $k => $v) { $t[$gruppo][$k] = $v; } }
  unset($t["checkout"]["steps"], $t["checkout"]["continue_payment"], $t["checkout"]["edit"], $t["checkout"]["errors"]["shipping_incomplete"]);
  file_put_contents($file, json_encode($t, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
}'; git diff --stat lang
```

Expected: due file cambiati. Controllare con `git diff lang/it/ecommerce.json | head -60` che l'indentazione sia rimasta quella di prima (se il file usava 4 spazi, `JSON_PRETTY_PRINT` li mantiene; se usava 2, rifare la scrittura con `str_replace('    ', '  ', …)` e annotarlo).

- [ ] **Step 3: Test e commit**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/CartCheckoutTest.php | tail -3`
Expected: il check nuovo `✓`. Il check «testi it/en (glob delle viste)» può ora fallire perché le viste dei passi usano ancora `steps.*`, `continue_payment` e `edit`: è atteso, si chiude al Task 11. Annotarlo nel ledger.

```bash
cd /Users/andreamarinoni/Developer/packages/ecommerce; git add lang/it/ecommerce.json lang/en/ecommerce.json; git add -f tests/CartCheckoutTest.php; git commit -m "E1c: testi della pagina unica di checkout

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: `CheckoutSummary`, controller e rotte della pagina unica (ecommerce)

**Files:**
- Modify: `ecommerce/src/Frontend/Checkout/CheckoutSummary.php`
- Modify: `ecommerce/src/Frontend/Checkout/CheckoutController.php`
- Modify: `ecommerce/config/routes/route.frontend.php` (righe 62-63)
- Test: `ecommerce/tests/integrazione/CheckoutSummaryTest.php`, `ecommerce/tests/CartCheckoutTest.php`

**Interfaces:**
- Consumes: `CheckoutRules::post/deliveryErrors/addressComplete/method/billing/paymentErrors/askedConsents` (Task 7), `ExpressCheckout::buttons` (Task 6), anteprima con `icons`/`fee_*` (Task 4), `PaymentMethod::ICONS` (Task 2), chiavi di lingua (Task 9).
- Produces:
  - Ogni opzione di `payload()['payment_methods']['options']` ha in più: `icon_urls: list<{src, alt}>`, `fee_display: string` (`''` senza commissione), `panel: string` (istruzioni se manuale, altrimenti `redirect_panel`).
  - `payload()['shipping_methods']['address_complete']: bool`; `payload()['display']['shipping_pending']: bool`.
  - Vista `pages/checkout/index.php` (Task 11) riceve: `cart`, `summary`, `values`, `shipping_fields`, `billing_fields`, `business_fields`, `cf_field`, `consents`, `express`, `user_email`, `guest`, `csrf_token`, `errors`, `notice`.
  - Rotte: restano `index`, `place`, `summary`, `coupon`, `completed`; via `shipping` e `payment`.

- [ ] **Step 1: Test che falliscono (riepilogo)**

In `tests/integrazione/CheckoutSummaryTest.php`, prima di `summary();`:

```php
check('ogni pagamento porta loghi, commissione e pannello', fn () => prova(static function (): bool {
    standard();
    $bonifico = manuale();
    PaymentMethod::update(['icons' => 'genericbank,bitcoin', 'fee_type' => 'amount', 'fee_value' => '1.50'], $bonifico);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $p = CheckoutSummary::payload($cart, modulo());
    $voce = array_column((array) $p['payment_methods']['options'], null, 'id')[$bonifico] ?? [];

    return count($voce['icon_urls'] ?? []) === 1
        && str_ends_with((string) $voce['icon_urls'][0]['src'], 'payment-icons/genericbank.svg')
        && ($voce['icon_urls'][0]['alt'] ?? '') === PaymentMethod::ICONS['genericbank']
        && ($voce['fee_display'] ?? '') === '+ '.CartPresenter::money(1.5, 'EUR')
        && ($voce['panel'] ?? '') === (string) ($voce['instructions'] ?? '');
}));

check('senza indirizzo i metodi di spedizione aspettano l\'indirizzo', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $vuoto = CheckoutSummary::payload($cart, ['shipping_country' => 'IT']);
    $pieno = CheckoutSummary::payload($cart, modulo());

    return $vuoto['shipping_methods']['address_complete'] === false
        && $pieno['shipping_methods']['address_complete'] === true
        && $pieno['display']['shipping_pending'] === false;
}));

check('un carrello già ordinato non diventa un secondo ordine', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $post = modulo(fatturazione()) + ['shipping_name' => 'Ada', 'shipping_surname' => 'L', 'phone' => '333'];
    invia($cart, $post, manuale());

    try {
        invia($cart, $post, manuale());
    } catch (UserError $e) {
        return $e->getMessage() !== '' && str_contains((string) $e->key(), 'cart.not_a_cart');
    }

    return false;
}));
```

Prima di scriverlo, controllare come `UserError` espone la chiave (`grep -n "function " ../gestionale/src/Support/Errors/UserError.php`) e come `manuale()` restituisce l'id (se crea un metodo nuovo a ogni chiamata, nel terzo check salvare l'id in `$m = manuale();` e usarlo due volte). Adattare solo questi accessi (ledger se cambia qualcosa).

Il check esistente «l'email dell'utente non sostituisce quella scritta al passo Spedizione» resta com'è: prova `CheckoutSummary::payload`, che non cambia. La nuova regola (vince l'email dell'utente) sta in `CheckoutRules::post` ed è provata al Task 7. Rinominare solo il titolo in «l'anteprima non sostituisce da sola l'email scritta (lo fa `CheckoutRules::post`)».

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/integrazione/CheckoutSummaryTest.php | tail -5`
Expected: i primi due check nuovi `✗`; il terzo può già passare (il gestionale rifiuta il secondo `place`): annotarlo nel ledger, è un check di guardia.

- [ ] **Step 2: `CheckoutSummary`**

In `src/Frontend/Checkout/CheckoutSummary.php`:
- `use Wonder\Plugin\Gestionale\Gestionale;`, `use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;`, `use Wonder\Plugin\Gestionale\Models\Sales\Order;` (se mancano);
- in `payload()`, dopo il blocco che aggiunge `price_display` alle opzioni di spedizione:

```php
        $currency = (string) ($preview['order']['currency'] ?? 'EUR');
        foreach ((array) ($preview['payment_methods']['options'] ?? []) as $i => $option) {
            $preview['payment_methods']['options'][$i] = $option + self::paymentDisplay((array) $option, (array) ($preview['order'] ?? []), $currency);
        }

        $preview['shipping_methods']['address_complete'] = CheckoutRules::addressComplete((array) Order::findById($cartId));
        $preview['display']['shipping_pending'] = Gestionale::feature('shipping')
            && (string) ($preview['fulfillment']['type'] ?? 'shipping') === 'shipping'
            && (int) ($preview['shipping_methods']['selected'] ?? 0) === 0;
```

  (usare la variabile del risultato di `Checkout::preview` com'è chiamata nel file; qui `$preview`).
- un nuovo metodo privato:

```php
    /**
     * Loghi, commissione e pannello di un'opzione di pagamento.
     *
     * @param array<string, mixed> $option
     * @param array<string, mixed> $order
     * @return array{icon_urls: list<array{src: string, alt: string}>, fee_display: string, panel: string}
     */
    private static function paymentDisplay(array $option, array $order, string $currency): array
    {
        $icons = [];
        foreach ((array) ($option['icons'] ?? []) as $key) {
            $src = isset(PaymentMethod::ICONS[$key]) ? (string) module_asset('ecommerce', 'payment-icons/'.$key.'.svg') : '';
            if ($src !== '') {
                $icons[] = ['src' => $src, 'alt' => PaymentMethod::ICONS[$key]];
            }
        }

        $base = (float) ($order['products_total'] ?? 0) - (float) ($order['discount_total'] ?? 0) + (float) ($order['shipping_total'] ?? 0);
        $fee = match ((string) ($option['fee_type'] ?? 'none')) {
            'amount' => (float) ($option['fee_value'] ?? 0),
            'percent' => round($base * (float) ($option['fee_percent'] ?? 0) / 100, 2),
            'amount_percent' => (float) ($option['fee_value'] ?? 0) + round($base * (float) ($option['fee_percent'] ?? 0) / 100, 2),
            default => 0.0,
        };

        return [
            'icon_urls' => $icons,
            'fee_display' => $fee > 0 ? '+ '.CartPresenter::money($fee, $currency) : '',
            'panel' => !empty($option['manual'])
                ? (string) ($option['instructions'] ?? '')
                : (string) __t('ecommerce.checkout.redirect_panel', ['name' => (string) ($option['name'] ?? '')]),
        ];
    }
```

  Il `+ ` davanti alla cifra è voluto: è un'aggiunta al totale. Prima di scrivere il calcolo, controllare in `../gestionale/src/Support/Orders/` come il gestionale calcola la riga `fee` (`grep -rn "amount_percent" ../gestionale/src/Support`): se la base è diversa (per esempio senza la spedizione), usare la stessa e annotarlo. La cifra mostrata è un'anticipazione: il totale vero lo dà sempre l'anteprima del metodo scelto.

- [ ] **Step 3: Test che falliscono (controller e rotte)**

In `tests/CartCheckoutTest.php`:
- **togliere** i check «il Pagamento chiede la Spedizione completa…», «il passo Spedizione salva…» e «checkout.js lavora a passi…» (quest'ultimo lo sostituisce il Task 13);
- **aggiungere** prima di `summary();`:

```php
check('il checkout è una pagina sola: niente rotte dei passi', function (): bool {
    $rotte = (string) file_get_contents(dirname(__DIR__).'/config/routes/route.frontend.php');
    $c = (string) file_get_contents(dirname(__DIR__).'/src/Frontend/Checkout/CheckoutController.php');

    return !str_contains($rotte, "'shipping'") && !str_contains($rotte, "'payment'")
        && str_contains($rotte, "['checkout_action' => 'place']")
        && !str_contains($c, 'function shipping(') && !str_contains($c, 'function payment(')
        && !str_contains($c, 'shippingDone') && !str_contains($c, 'backToShipping')
        && str_contains($c, "pages/checkout/index.php");
});

check('place passa dalle regole: POST pulito, errori insieme, solo i metodi offerti', function (): bool {
    $c = (string) file_get_contents(dirname(__DIR__).'/src/Frontend/Checkout/CheckoutController.php');
    $place = substr($c, (int) strpos($c, 'private static function place('));

    return str_contains($place, 'CheckoutRules::post($_POST, CartSession::user())')
        && str_contains($place, 'CheckoutRules::deliveryErrors(')
        && str_contains($place, 'CheckoutRules::paymentErrors(')
        && str_contains($place, 'CheckoutRules::method(')
        && str_contains($place, 'CheckoutForm::isManual(')
        && !str_contains($place, "ecommerce.checkout.payment'");
});

check('il riepilogo usa lo stesso POST della pagina', function (): bool {
    $c = (string) file_get_contents(dirname(__DIR__).'/src/Frontend/Checkout/CheckoutController.php');

    return str_contains($c, 'CheckoutSummary::payload($cartId, CheckoutRules::post($_POST, CartSession::user()), CartSession::user())');
});
```

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/CartCheckoutTest.php | tail -5`
Expected: i tre check nuovi `✗`.

- [ ] **Step 4: Rotte**

In `config/routes/route.frontend.php` togliere le due righe:

```php
                Route::post('/shipping/', $handler, ['checkout_action' => 'shipping'])->name('shipping');
                Route::get('/payment/', $handler, ['checkout_action' => 'payment'])->name('payment');
```

- [ ] **Step 5: Il controller**

In `src/Frontend/Checkout/CheckoutController.php`:
- `handle()`: togliere `'shipping' => self::shipping(),` e `'payment' => self::payment(),`;
- togliere i metodi `shipping()`, `payment()`, `shippingDone()`, `backToShipping()`, `paymentMethods()`, `method()` e l'`use … PaymentMethod;`;
- `index()` diventa:

```php
    private static function index(): void
    {
        if (!self::guestAllowed() && !CartSession::authenticated()) {
            self::redirect(self::loginUrl());
        }

        $cart = CartSession::current(false);
        if ((array) ($cart['items'] ?? []) === []) {
            self::redirect(self::route('ecommerce.cart.index'));
        }

        $flash = self::pullFlash();
        $fromCart = array_filter(self::fromCart((array) ($cart['order'] ?? [])), static fn (string $v): bool => $v !== '' && $v !== '0');
        $values = $flash['values'] !== [] ? self::defaults($flash['values']) : $fromCart + self::defaults([]);
        // Alla prima visita le scelte di consegna e pagamento restano quelle del carrello.
        $summary = self::initialSummary((int) ($cart['order']['id'] ?? 0), $values, $flash['values'] === []);
        // L'anteprima riscrive spedizione e commissione sul carrello: la pagina parte da quello aggiornato.
        $cart = CartSession::current(false);
        $user = CartSession::user();
        $billing = Order::billingAddress()->formSchema($values['billing_country'] ?? null);

        self::seo((string) __t('ecommerce.checkout.title'), self::route('ecommerce.checkout.index'));
        View::make(Ecommerce::viewPath('pages/checkout/index.php'), [
            'cart' => $cart,
            'summary' => $summary,
            'values' => $values,
            'shipping_fields' => self::fields(array_diff_key(
                Order::shippingAddress()->formSchema($values['shipping_country'] ?? null),
                array_flip(['shipping_name', 'shipping_surname', 'shipping_phone', 'shipping_phone_prefix'])
            ), $values),
            'billing_fields' => self::fields(array_diff_key(
                $billing,
                array_flip(['billing_type', 'billing_business_name', 'billing_cf', 'billing_pi', 'billing_sdi', 'billing_pec'])
            ), $values),
            'business_fields' => self::fields(array_intersect_key(
                $billing,
                array_flip(['billing_business_name', 'billing_pi', 'billing_sdi', 'billing_pec'])
            ), $values),
            'cf_field' => self::fields(array_intersect_key($billing, ['billing_cf' => true]), $values),
            'consents' => CheckoutRules::askedConsents((int) ($user->id ?? 0)),
            'express' => ExpressCheckout::buttons((array) ($cart['order'] ?? [])),
            'user_email' => CartSession::authenticated() ? trim((string) ($user->email ?? '')) : '',
            'guest' => !CartSession::authenticated(),
            'csrf_token' => AuthSession::csrfToken(),
            'errors' => $flash['errors'],
            'notice' => $flash['notice'],
        ])->render();
    }
```

  Attenzione: `$billing` è usato tre volte; `formSchema()` restituisce oggetti, e `fields()` ne imposta il valore: tre `array_*_key` diverse sullo stesso array non si sovrappongono (chiavi disgiunte), quindi va bene riusarlo.
- `place()` diventa:

```php
    private static function place(): void
    {
        self::requirePost();
        self::requireCsrf();

        if (!self::guestAllowed() && !CartSession::authenticated()) {
            self::redirect(self::loginUrl());
        }

        $user = CartSession::user();
        $post = CheckoutRules::post($_POST, $user);

        try {
            if (!CartSession::authenticated()) {
                RecaptchaGuard::for('ecommerce_checkout')
                    ->withService('ecommerce-checkout')
                    ->withLogAction('place')
                    ->verify();
            }

            $cartId = self::cartId();
            if ($cartId === 0) {
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
                self::flash(array_map(static fn (string $key): string => (string) __t('ecommerce.checkout.errors.'.$key), $missing), $post);
                self::redirect(self::route('ecommerce.checkout.index'));
            }

            // Vale solo un metodo che la pagina ha offerto.
            $method = CheckoutRules::method($preview, (int) ($post['payment_method_id'] ?? 0));
            if ($method === null) {
                throw new RuntimeException((string) __t('ecommerce.checkout.errors.payment_method'));
            }
            if (!CheckoutForm::isManual($method)) {
                throw new RuntimeException((string) __t('ecommerce.checkout.errors.provider_pending'));
            }

            $data = CheckoutForm::data([
                'payment_method_id' => (string) $method['id'],
                'customer_note' => (string) ($post['customer_note'] ?? ''),
            ] + $billing + self::fromCart($order), $user);

            $result = Checkout::place($cartId, $data + [
                'customer_id' => CartSession::customerId(),
                'source' => 'ecommerce',
                'user_id' => $userId,
            ]);

            if ($userId > 0 && $asked !== []) {
                try {
                    consentService()->registerBaseConsents($userId, $post, ['required_document_types' => $asked, 'ui_surface' => 'checkout']);
                } catch (Throwable $error) {
                    // L'ordine è nato: un consenso non registrato non lo ferma.
                    Errors::internal($error, 'ecommerce.checkout.consents');
                }
            }

            $_SESSION[self::COMPLETED] = [
                'order_id' => (int) ($result['order_id'] ?? 0),
                'order_number' => (string) ($result['order_number'] ?? ''),
                'total' => (string) ($result['total'] ?? ''),
                'status' => (string) ($result['status'] ?? 'pending'),
                'instructions' => (string) ($method['instructions'] ?? ''),
            ];
            self::redirect(self::route('ecommerce.checkout.completed'));
        } catch (UserError $error) {
            self::flash([$error->getMessage()], $post);
        } catch (RuntimeException $error) {
            self::flash([$error->getMessage()], $post);
        } catch (Throwable $error) {
            Errors::internal($error, 'ecommerce.checkout.place');
            self::flash([(string) __t('ecommerce.checkout.errors.generic')], $post);
        }

        self::redirect(self::route('ecommerce.checkout.index'));
    }
```

  `CheckoutForm::isManual()` riceve ora l'opzione dell'anteprima (che ha `provider` e `manual`): controllare con `grep -n "function isManual" -A8 src/Frontend/Checkout/CheckoutForm.php` che legga `provider` (o `manual`); se legge un campo che l'opzione non ha, passare `['provider' => $method['provider']]` e annotarlo.
- `summary()`: `CheckoutSummary::payload($cartId, $_POST, CartSession::user())` diventa `CheckoutSummary::payload($cartId, CheckoutRules::post($_POST, CartSession::user()), CartSession::user())`.
- `coupon()`: `$return = ($_POST['return'] ?? '') === 'cart' ? 'cart' : 'checkout';` e `$back = $return === 'cart' ? self::route('ecommerce.cart.index') : self::route('ecommerce.checkout.index');` al posto del `match`.
- `use Wonder\Plugin\Gestionale\Gestionale;` resta (serve a `place`).

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php -l src/Frontend/Checkout/CheckoutController.php; grep -n "checkout.payment\|checkout.shipping'" -r src view config`
Expected: «No syntax errors»; il `grep` trova solo le viste dei passi (`view/pages/checkout/shipping.php`, `payment.php`, `components/checkout/*.php`) che il Task 11 elimina o riscrive.

- [ ] **Step 6: Lanciare i test**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/integrazione/CheckoutSummaryTest.php | tail -3; php tests/integrazione/CheckoutRulesTest.php | tail -1; php tests/CartCheckoutTest.php | tail -6`
Expected: `CheckoutSummaryTest` e `CheckoutRulesTest` tutti `✓`; in `CartCheckoutTest` i tre check nuovi `✓`; restano rossi solo i check delle viste (testi it/en, un `<h1`, ganci `->attr('form', 'checkout')`, carrello) che chiudono i Task 11-12 — elencarli nel ledger.

- [ ] **Step 7: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/ecommerce; git add src/Frontend/Checkout/CheckoutSummary.php src/Frontend/Checkout/CheckoutController.php config/routes/route.frontend.php; git add -f tests/integrazione/CheckoutSummaryTest.php tests/CartCheckoutTest.php; git commit -m "E1c: controller e riepilogo della pagina unica (loghi, pannello, commissione; via i passi)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: la vista unica `pages/checkout/index.php` (ecommerce)

**Files:**
- Create: `ecommerce/view/pages/checkout/index.php`
- Create: `ecommerce/resources/assets/css/checkout.css`
- Delete: `ecommerce/view/pages/checkout/shipping.php`, `payment.php`, `ecommerce/view/components/checkout/steps.php`
- Modify: `ecommerce/view/components/checkout/lines.php` (64 px, senza SKU), `mobile.php` (`return` = checkout), `aside.php` (dopo il Task 1: niente `wi-box`, niente `step`)
- Modify: `ecommerce/view/layout/frontend/ecommerce.checkout.php` (foglio `checkout.css`)
- Test: `ecommerce/tests/CartCheckoutTest.php`

**Interfaces:**
- Consumes: le variabili del Task 10; `Choice::icon/icons/panel`, `ChoiceGroup::variant('segmented'|'list')` (piano 1); `PaymentMethod` niente (le opzioni arrivano dal riepilogo con `icon_urls`, `fee_display`, `panel`).
- Produces (ganci per il Task 13): `form#checkout[data-checkout]` con `data-shipping`, `data-coupons`, `data-summary-url`, `data-coupon-url`, `data-labels`, `data-initial`; `[data-checkout-fulfillment]`, `[data-checkout-shipping]`, `[data-checkout-shipping-methods]`, `[data-checkout-notice]`, `[data-checkout-pickup]`, `[data-checkout-pickup-locations]`, `[data-checkout-payments]`, `template[data-checkout-choice]`, `[data-checkout-toggle]`, `[data-checkout-submit]` (dentro il form), `aside[data-checkout-aside]`.

- [ ] **Step 1: Test che falliscono**

In `tests/CartCheckoutTest.php`:
- il check dei ganci che vuole `->attr('form', 'checkout')`: sostituire quella condizione con `str_contains($vista, "->attr('data-checkout-submit', '')")` dove `$vista` è `view/pages/checkout/index.php` (leggere il check e cambiare solo il file e la stringa);
- aggiungere prima di `summary();`:

```php
check('la pagina unica ha le sezioni nell\'ordine giusto e il bottone nel form', function (): bool {
    $v = (string) @file_get_contents(dirname(__DIR__).'/view/pages/checkout/index.php');
    $pos = static fn (string $s): int => ($p = strpos($v, $s)) === false ? -1 : $p;
    $ordine = [$pos('id="contatti"'), $pos('id="consegna"'), $pos('id="pagamento"'), $pos('id="fatturazione"'), $pos('data-checkout-submit')];
    $form = substr($v, $pos('<form id="checkout"'), $pos('</form>') - $pos('<form id="checkout"'));

    return !in_array(-1, $ordine, true) && $ordine === array_values(array_unique($ordine)) && $ordine == array_values((function ($o) { sort($o); return $o; })($ordine))
        && str_contains($form, 'data-checkout-submit')
        && str_contains($v, "variant('segmented')") && str_contains($v, "variant('list')")
        && str_contains($v, "->icon('truck')") && str_contains($v, "->icon('shop')")
        && str_contains($v, '->icons(') && str_contains($v, '->panel(')
        && str_contains($v, 'same_as_shipping:0') && str_contains($v, 'billing_type:business')
        && str_contains($v, "ecommerce.auth.logout") && str_contains($v, "StoreFont") === false
        && !file_exists(dirname(__DIR__).'/view/pages/checkout/shipping.php')
        && !file_exists(dirname(__DIR__).'/view/pages/checkout/payment.php')
        && !file_exists(dirname(__DIR__).'/view/components/checkout/steps.php');
});

check('il riepilogo è sticky e le righe non hanno lo SKU', function (): bool {
    $css = (string) @file_get_contents(dirname(__DIR__).'/resources/assets/css/checkout.css');
    $righe = (string) file_get_contents(dirname(__DIR__).'/view/components/checkout/lines.php');

    return str_contains($css, 'position: sticky') && str_contains($css, '100vmax') && str_contains($css, '560px')
        && !str_contains($righe, 'data-line-sku') && str_contains($righe, '64px');
});
```

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/CartCheckoutTest.php | tail -4`
Expected: i check nuovi `✗`.

- [ ] **Step 2: Il CSS**

`resources/assets/css/checkout.css`:

```css
/* Pagina unica del checkout: modulo a sinistra, riepilogo grigio e sticky a destra. */
.wi-checkout {
    align-items: start;
}
.wi-checkout__form {
    width: 100%;
    max-width: 560px;
    margin-left: auto;
}
.wi-checkout__aside {
    position: sticky;
    top: 0;
    max-height: 100vh;
    overflow: auto;
    padding: calc(var(--spacer) * 6);
    box-sizing: border-box;
    background: #f5f5f5;
    /* Il grigio arriva fino al bordo destro della finestra. */
    box-shadow: 0 0 0 100vmax #f5f5f5;
    clip-path: inset(0 -100vmax 0 0);
}
.wi-checkout__currency {
    font-size: var(--text-small-font-size);
    font-weight: 400;
    opacity: 0.7;
    margin-right: 4px;
}
.wi-checkout__or {
    display: flex;
    align-items: center;
    gap: calc(var(--spacer) * 3);
    font-size: var(--text-small-font-size);
    opacity: 0.7;
}
.wi-checkout__or::before,
.wi-checkout__or::after {
    content: "";
    flex: 1;
    border-top: 1px solid var(--dropdown-border-color);
}
@media (max-width: 1024px) {
    .wi-checkout__form {
        max-width: none;
    }
    .wi-checkout__aside {
        display: none;
    }
}
```

In `view/layout/frontend/ecommerce.checkout.php` (dopo il Task 1), accanto a `StoreFont::style('checkout')`:

```php
if (($checkoutCss = module_asset('ecommerce', 'css/checkout.css')) !== '') {
    echo '<link rel="stylesheet" href="'.e($checkoutCss).'">';
}
```

- [ ] **Step 3: Le parti comuni**

- `view/components/checkout/lines.php`: `--wi-thumb-size` da `56px` a `64px` (nelle righe stampate e nel template); togliere l'elemento `[data-line-sku]` (riga e template).
- `view/components/checkout/mobile.php`: il coupon usa `'return' => 'checkout'` (non più `payment`/`checkout` secondo il passo).
- `view/components/checkout/aside.php` (file dell'utente, dopo il Task 1): `$withLines = true;` (non dipende più da `$step`); la classe dell'`<aside>` diventa `w-100 wi-checkout__aside` con `data-checkout-aside` (niente `wi-box`, niente `h-auto p-5`); il coupon `return` = `'checkout'`; `shippingRow` = `Gestionale::feature('shipping') && ($order['fulfillment_type'] ?? '') !== 'pickup'`. Se l'aside serve ancora al carrello con `step = cart`, lasciare la variabile `$step` col default `'checkout'` e usare `$step !== 'cart'` solo per `shippingRow`. Leggere il file prima e cambiare solo queste righe; descrivere nel ledger cosa si è toccato del lavoro dell'utente.
- `view/components/checkout/totals.php`: la riga del Totale stampa la valuta piccola prima della cifra: `<strong class="a-r"><span class="wi-checkout__currency">EUR</span>…</strong>` (usare `$currency`); le righe `div` hanno già `w-100`.

- [ ] **Step 4: La vista**

`view/pages/checkout/index.php`:

```php
<?php

use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Choice;
use Wonder\Elements\Components\ChoiceGroup;
use Wonder\Plugin\Ecommerce\Ecommerce;
use Wonder\Plugin\Ecommerce\Frontend\Cart\CartPresenter;
use Wonder\Plugin\Ecommerce\Frontend\Tracking\DataLayer;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\View\View;

$order = (array) ($cart['order'] ?? []);
$items = CartPresenter::lines(array_values((array) ($cart['items'] ?? [])));
$currency = (string) ($order['currency'] ?? 'EUR');
$shipping = Gestionale::feature('shipping');
$coupons = Gestionale::feature('coupons');
$summary = is_array($summary ?? null) ? $summary : null;
$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

$fulfillment = (string) ($summary['fulfillment']['type'] ?? 'shipping');
$pickup = $shipping && $fulfillment === 'pickup';
$canPickup = in_array('pickup', (array) ($summary['fulfillment']['choices'] ?? []), true);
$shippingOptions = (array) ($summary['shipping_methods']['options'] ?? []);
$shippingSelected = (int) ($summary['shipping_methods']['selected'] ?? 0);
$addressComplete = (bool) ($summary['shipping_methods']['address_complete'] ?? false);
$pickupOptions = (array) ($summary['pickup_locations']['options'] ?? []);
$pickupSelected = (int) ($summary['pickup_locations']['selected'] ?? 0);
$payments = (array) ($summary['payment_methods']['options'] ?? []);
$paymentSelected = (int) ($values['payment_method_id'] ?? $summary['payment_methods']['selected'] ?? 0);
$manual = true;
foreach ($payments as $p) {
    if ((int) $p['id'] === $paymentSelected) {
        $manual = (bool) $p['manual'];
    }
}
$couponCode = (string) ($summary['coupon']['code'] ?? ($order['coupon_code'] ?? ''));
$same = (string) ($values['same_as_shipping'] ?? '1') !== '0';
$invoice = !empty($values['invoice']);
$business = ($values['billing_type'] ?? 'private') === 'business';
$shippingNotice = $shippingOptions !== [] ? '' : ($addressComplete ? 'no_shipping' : 'shipping_methods_pending');

$order = ($summary['order'] ?? null) !== null ? $summary['order'] + ['fulfillment_type' => $fulfillment] : $order;
$aside = [
    'step' => 'checkout', 'items' => (array) ($summary['items'] ?? $items), 'order' => $order, 'currency' => $currency,
    'csrf_token' => $csrf_token, 'coupons' => $coupons, 'couponCode' => $couponCode, 'notices' => (array) ($summary['notices'] ?? []),
];

$labels = [];
foreach (['free', 'no_shipping', 'shipping_pending', 'shipping_methods_pending', 'shipping_total', 'fees_total', 'coupon_remove', 'submit_manual', 'submit_online', 'updating', 'summary_error', 'field_required'] as $key) {
    $labels[$key] = (string) __t('ecommerce.checkout.'.$key);
}
foreach (['products_total', 'discount', 'total'] as $key) {
    $labels[$key] = (string) __t('ecommerce.cart.'.$key);
}

$gaItems = array_map(static fn (array $item): array => [
    'item_id' => (string) ($item['sku'] ?? $item['product_id'] ?? ''),
    'item_name' => (string) ($item['name'] ?? ''),
    'price' => round((float) ($item['line_total'] ?? 0) / max((float) ($item['quantity'] ?? 1), 1), 2),
    'quantity' => (float) ($item['quantity'] ?? 1),
], array_values(array_filter($items, static fn (array $item): bool => (string) ($item['type'] ?? 'product') === 'product')));

$payment = static fn (array $p): Choice => Choice::make('payment_method_id', (int) $p['id'])
    ->type('radio')->title((string) $p['name'])->aside((string) ($p['fee_display'] ?? ''))
    ->icons((array) ($p['icon_urls'] ?? []))->panel((string) ($p['panel'] ?? ''))
    ->checked((int) $p['id'] === $paymentSelected);

Ecommerce::layout('checkout', compact('errors', 'notice'));
?>
<?=DataLayer::script([
    'type' => 'checkout',
    'language' => __l(),
    'currency' => $currency,
], [
    'event' => 'begin_checkout',
    'ecommerce' => ['currency' => $currency, 'value' => (float) ($order['total'] ?? 0), 'items' => $gaItems],
], (int) ($_SESSION['user_id'] ?? 0))?>
<h1 class="sr-only"><?=e(__t('ecommerce.checkout.title'))?></h1>
<?=View::component(Ecommerce::viewPath('components/checkout/mobile.php'), $aside)?>
<div class="w-100 d-grid col-2 col-t-1 gap-6 wi-checkout">
    <form id="checkout" class="w-100 d-flex d-column gap-6 wi-checkout__form" method="post" action="<?=e(__r('ecommerce.checkout.place'))?>" novalidate
        data-checkout
        data-shipping="<?=$shipping ? 'on' : 'off'?>"
        data-coupons="<?=$coupons ? 'on' : 'off'?>"
        data-summary-url="<?=e(__r('ecommerce.checkout.summary'))?>"
        data-coupon-url="<?=e(__r('ecommerce.checkout.coupon'))?>"
        data-labels="<?=e(json_encode($labels, $flags))?>"
        data-initial="<?=e(json_encode($summary, $flags))?>">
        <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
        <?php if ($express !== []): ?>
            <section id="rapido" class="w-100">
                <h2 class="text-small a-c mb-3"><?=e(__t('ecommerce.checkout.express'))?></h2>
                <div class="w-100 d-grid col-3 gap-3"><?php foreach ($express as $button): ?><?=$button?><?php endforeach; ?></div>
                <p class="wi-checkout__or mt-5"><?=e(__t('ecommerce.checkout.or'))?></p>
            </section>
        <?php endif; ?>
        <section id="contatti" class="w-100">
            <div class="w-100 d-flex gap-3 mb-3">
                <h2 class="subtitle"><?=e(__t('ecommerce.checkout.contact'))?></h2>
                <?php if ($guest): ?>
                    <a class="text-small ml-auto" href="<?=e(__r('ecommerce.auth.login').'?continue='.rawurlencode((string) __r('ecommerce.checkout.index')))?>"><?=e(__t('ecommerce.checkout.login'))?></a>
                <?php endif; ?>
            </div>
            <?php if ($guest || $user_email === ''): ?>
                <?=FormField::key('email')->email()->label((string) __t('ecommerce.auth.fields.email'))->required()->value($values['email'] ?? '')?>
            <?php else: ?>
                <div class="w-100 d-flex gap-3">
                    <p class="text"><?=e($user_email)?></p>
                    <?=Button::make((string) __t('ecommerce.checkout.logout'))->type('submit')->attr('form', 'checkout-logout')->variant('link')->class('ml-auto')?>
                </div>
            <?php endif; ?>
        </section>
        <section id="consegna" class="w-100">
            <h2 class="subtitle mb-3"><?=e(__t('ecommerce.checkout.delivery'))?></h2>
            <?php if ($shipping): ?>
                <div class="w-100 mb-4" data-checkout-fulfillment>
                    <?=ChoiceGroup::make()->variant('segmented')->choices(
                        Choice::make('fulfillment_type', 'shipping')->type('radio')->icon('truck')->title((string) __t('ecommerce.checkout.fulfillment_shipping'))->checked(!$pickup),
                        Choice::make('fulfillment_type', 'pickup')->type('radio')->icon('shop')->title((string) __t('ecommerce.checkout.fulfillment_pickup'))->checked($pickup)->disabled(!$canPickup)
                    )?>
                </div>
            <?php endif; ?>
            <div class="w-100 d-grid col-2 col-p-1 gap-4">
                <?=FormField::key('shipping_name')->text()->label((string) __t('ecommerce.auth.fields.name'))->required()->value($values['shipping_name'] ?? '')?>
                <?=FormField::key('shipping_surname')->text()->label((string) __t('ecommerce.auth.fields.surname'))->required()->value($values['shipping_surname'] ?? '')?>
            </div>
            <div class="w-100 mt-4" data-checkout-shipping<?=$pickup ? ' hidden' : ''?>>
                <div class="w-100 d-grid col-2 col-p-1 gap-4">
                    <?php foreach ($shipping_fields as $field): ?><?=$field?><?php endforeach; ?>
                </div>
            </div>
            <?php if ($shipping): ?>
                <div class="w-100 mt-4" data-checkout-pickup<?=$pickup ? '' : ' hidden'?>>
                    <h3 class="text fw-600 mb-3"><?=e(__t('ecommerce.checkout.pickup_locations'))?></h3>
                    <div class="w-100" data-checkout-pickup-locations>
                        <?=ChoiceGroup::make()->variant('list')->choices(...array_map(static fn (array $o): Choice => Choice::make('location_id', (int) $o['id'])
                            ->type('radio')->title((string) $o['name'])->text((string) ($o['address'] ?? ''))
                            ->checked((int) $o['id'] === $pickupSelected), $pickupOptions))?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="w-100 mt-4">
                <?=FormField::key('phone')->tel()->label((string) __t('ecommerce.auth.fields.mobile'))->required()->value($values['phone'] ?? '')?>
            </div>
            <?php if ($shipping): ?>
                <div class="w-100 mt-5" data-checkout-shipping<?=$pickup ? ' hidden' : ''?>>
                    <h3 class="text fw-600 mb-3"><?=e(__t('ecommerce.checkout.shipping_methods'))?></h3>
                    <div class="w-100" data-checkout-shipping-methods>
                        <?=ChoiceGroup::make()->variant('list')->choices(...array_map(static fn (array $o): Choice => Choice::make('shipping_method_id', (int) $o['method_id'])
                            ->type('radio')->title((string) $o['name'])->text((string) ($o['description'] ?? ''))->aside((string) ($o['price_display'] ?? ''))
                            ->checked((int) $o['method_id'] === $shippingSelected), $shippingOptions))?>
                    </div>
                    <p class="w-100 wi-box p-4 text-small" data-checkout-notice role="status"<?=$shippingNotice === '' ? ' hidden' : ''?>><?=$shippingNotice === '' ? '' : e(__t('ecommerce.checkout.'.$shippingNotice))?></p>
                </div>
            <?php endif; ?>
        </section>
        <section id="pagamento" class="w-100">
            <h2 class="subtitle"><?=e(__t('ecommerce.checkout.payment'))?></h2>
            <p class="text-small tx-secondary mt-1 mb-3"><?=e(__t('ecommerce.checkout.secure'))?></p>
            <div class="w-100" data-checkout-payments>
                <?php if ($payments === []): ?>
                    <p class="text"><?=e(__t('ecommerce.checkout.no_payment_methods'))?></p>
                <?php else: ?>
                    <?=ChoiceGroup::make()->variant('list')->choices(...array_map($payment, $payments))?>
                <?php endif; ?>
            </div>
        </section>
        <section id="fatturazione" class="w-100">
            <h2 class="subtitle mb-3"><?=e(__t('ecommerce.checkout.billing'))?></h2>
            <?php if ($shipping): ?>
                <div class="w-100 mb-4" data-checkout-toggle="fulfillment_type:shipping"<?=$pickup ? ' hidden' : ''?>>
                    <?=ChoiceGroup::make()->variant('list')->choices(
                        Choice::make('same_as_shipping', '1')->type('radio')->title((string) __t('ecommerce.checkout.billing_same'))->checked($same),
                        Choice::make('same_as_shipping', '0')->type('radio')->title((string) __t('ecommerce.checkout.billing_different'))->checked(!$same)
                    )?>
                </div>
            <?php endif; ?>
            <div class="w-100 d-grid col-2 col-p-1 gap-4" data-checkout-toggle="<?=$shipping ? 'same_as_shipping:0|fulfillment_type:pickup' : ''?>"<?=$shipping && $same && !$pickup ? ' hidden' : ''?>>
                <?php foreach ($billing_fields as $field): ?><?=$field?><?php endforeach; ?>
            </div>
            <div class="w-100 mt-5">
                <?=Choice::make('invoice', '1')->type('checkbox')->title((string) __t('ecommerce.checkout.invoice'))->checked($invoice)?>
            </div>
            <div class="w-100 mt-4" data-checkout-toggle="invoice:on"<?=$invoice ? '' : ' hidden'?>>
                <?=ChoiceGroup::make()->variant('segmented')->choices(
                    Choice::make('billing_type', 'private')->type('radio')->title((string) __t('ecommerce.checkout.billing_private'))->checked(!$business),
                    Choice::make('billing_type', 'business')->type('radio')->title((string) __t('ecommerce.checkout.billing_business'))->checked($business)
                )?>
                <div class="w-100 mt-4" data-checkout-toggle="billing_type:private"<?=$business ? ' hidden' : ''?>>
                    <?php foreach ($cf_field as $field): ?><?=$field?><?php endforeach; ?>
                </div>
                <div class="w-100 d-grid col-2 col-p-1 gap-4 mt-4" data-checkout-toggle="billing_type:business"<?=$business ? '' : ' hidden'?>>
                    <?php foreach ($business_fields as $field): ?><?=$field?><?php endforeach; ?>
                </div>
            </div>
        </section>
        <section id="conferma" class="w-100 d-flex d-column gap-4">
            <?=FormField::key('customer_note')->textarea()->label((string) __t('ecommerce.checkout.note'))->value($values['customer_note'] ?? '')?>
            <?php foreach ($consents as $type): ?>
                <?=FormField::key('accept_'.$type)->acceptDocument($type)->required()->value($values['accept_'.$type] ?? '')?>
            <?php endforeach; ?>
            <?php if ($guest): ?>
                <?=FormField::key('recaptcha')->recaptcha('ecommerce_checkout')?>
            <?php endif; ?>
            <?php $submit = Button::make((string) __t('ecommerce.checkout.'.($manual ? 'submit_manual' : 'submit_online')))->type('submit')->attr('data-checkout-submit', '')->variant('black')->size('lg')->class('w-100 wi-input-submit wi-submit'); ?>
            <?=$payments === [] ? $submit->disabled(true)->attr('data-checkout-locked', '') : $submit?>
        </section>
        <template data-checkout-choice><?=Choice::make('', '')->type('radio')?></template>
    </form>
    <?=View::component(Ecommerce::viewPath('components/checkout/aside.php'), $aside + ['button' => ''])?>
</div>
<?php if (!$guest && $user_email !== ''): ?>
    <form id="checkout-logout" method="post" action="<?=e(__r('ecommerce.auth.logout'))?>" hidden>
        <?=FormField::key('csrf_token')->hidden()->value($csrf_token)?>
    </form>
<?php endif; ?>
<?php if (($checkoutJs = module_asset('ecommerce', 'js/checkout.js')) !== ''): ?>
    <script src="<?=e($checkoutJs)?>"></script>
<?php endif; ?>
<?php View::end(); ?>
```

Note per chi esegue:
- `data-checkout-toggle` accetta oggi `nome:valore` (anche `on`/`off`); il Task 13 aggiunge l'alternativa con `|` («visibile se una delle due»). Senza JS i blocchi stampati `hidden` restano nascosti: per il POST senza JavaScript (Review Focus 2) il server ignora i campi nascosti grazie a `CheckoutRules::billing()` (uguale di partenza) e `post()` (ritiro).
- `->variant('link')` e `->variant('black')->size('lg')`: controllare con `grep -n "VARIANTS\|function size" ../app/class/Elements/Components/Button.php` che esistano; se `link` non c'è, usare `->class('ml-auto tx-link')` senza variant; se `size` non c'è, togliere `->size('lg')` (ledger).
- `ecommerce.auth.logout` è la rotta POST di `AuthRoutes` (nome `logout` sotto `ecommerce.auth`); non si passa `continue`: dopo l'uscita il core manda dove manda sempre.
- Il titolo `h1` è `sr-only`: la pagina ha un `<h1` solo (check «un solo `<h1`»). Se `sr-only` non è una classe della lib, usare `visually-hidden` o la classe che `grep -rn "sr-only\|visually-hidden" ../lib/src/build/frontend/css | head -3` trova.
- Le classi `ml-auto`, `tx-secondary`, `text-small`, `fw-600` sono quelle già usate nelle viste di prima; nessuna classe nuova oltre a `wi-checkout*`.

- [ ] **Step 5: Eliminare le viste dei passi**

```bash
cd /Users/andreamarinoni/Developer/packages/ecommerce; git rm view/pages/checkout/shipping.php view/pages/checkout/payment.php view/components/checkout/steps.php; grep -rn "steps.php\|checkout/shipping.php\|checkout/payment.php" src view tests config docs
```

Expected: nessuna riga da `src`, `view`, `config`; righe in `tests/CartCheckoutTest.php` o `docs/` si sistemano (test: la stringa cercata diventa `pages/checkout/index.php`; docs: Task 14). Il carrello (`view/pages/cart/index.php`) usa ancora `steps.php`: lo sistema il Task 12, quindi `grep` lo trova; va bene finché il Task 12 non è fatto.

- [ ] **Step 6: Lanciare i test**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php -l view/pages/checkout/index.php; php tests/CartCheckoutTest.php | tail -8`
Expected: «No syntax errors»; i due check nuovi `✓`; il check del float (`w-N` in ogni `<div`, niente `col-N col-t-N` con `d-grid` nella stessa `class`) `✓`; il check testi it/en `✓`. Rossi ammessi solo quelli del carrello (Task 12), da elencare nel ledger.

Se il check del float segnala `class="w-100 d-grid col-2 col-t-1 gap-6 wi-checkout"`: è proprio la combinazione vietata. Spostare la griglia in CSS: la classe diventa `w-100 wi-checkout` e in `checkout.css` aggiungere `.wi-checkout { display: grid; grid-template-columns: 1fr 1fr; gap: calc(var(--spacer) * 6); }` con `grid-template-columns: 1fr` nel `@media (max-width: 1024px)` (ledger). Fare lo stesso per ogni altro `<div` segnalato.

- [ ] **Step 7: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/ecommerce; git add view/pages/checkout/index.php resources/assets/css/checkout.css view/components/checkout view/layout/frontend/ecommerce.checkout.php; git add -f tests/CartCheckoutTest.php; git status --short; git commit -m "E1c: la pagina unica del checkout (via Spedizione e Pagamento)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: il carrello nel layout del negozio (ecommerce)

**Files:**
- Modify: `ecommerce/view/pages/cart/index.php`
- Test: `ecommerce/tests/CartCheckoutTest.php`

**Interfaces:**
- Consumes: `StoreFont::style('cart')` (Task 8), `Ecommerce::layout('shop', …)` (layout dell'utente, Task 1).
- Produces: un carrello senza passi e senza SKU, col font del commerciante.

- [ ] **Step 1: Test che fallisce**

In `tests/CartCheckoutTest.php`, il check «il passo Carrello usa il layout del checkout…» diventa:

```php
check('il carrello sta nel layout del negozio, col suo font, senza passi e senza SKU', function (): bool {
    $v = (string) file_get_contents(dirname(__DIR__).'/view/pages/cart/index.php');

    return str_contains($v, "Ecommerce::layout('shop'")
        && str_contains($v, "StoreFont::style('cart')")
        && str_contains($v, 'data-checkout-cart')
        && !str_contains($v, 'Steps::make(') && !str_contains($v, 'steps.php')
        && !str_contains($v, "labels['sku']") && !str_contains($v, 'data-step');
});
```

Prima di sostituirlo, leggere il check vecchio: tenere le condizioni che valgono ancora (`<details`, `'coupon_applied'`, `CartController::flash(`, il manifest `components/checkout`), aggiungendole con `&&` a quelle sopra; togliere solo `Ecommerce::layout('checkout'`, `'step' => 'cart'` e `Steps::make(`.

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/CartCheckoutTest.php | tail -3`
Expected: il check `✗`.

- [ ] **Step 2: La vista**

In `view/pages/cart/index.php`:
- `Ecommerce::layout('checkout', compact('errors', 'notice'));` → `Ecommerce::layout('shop', compact('errors', 'notice'));` (controllare con `sed -n 1,40p view/layout/frontend/ecommerce.shop.php` che il layout `shop` stampi `$errors` e `$notice` come quello del checkout; se no, stampare gli `Alert` in cima alla pagina copiandoli da `ecommerce.checkout.php`, ledger);
- togliere la riga `<?=View::component(Ecommerce::viewPath('components/checkout/steps.php'), ['step' => 'cart'])?>` e mettere al suo posto `<?=\Wonder\Plugin\Ecommerce\Frontend\StoreFont::style('cart')?>`;
- togliere `$labels['sku'] = …;`;
- togliere il blocco `<?php if (trim((string) ($item['sku'] ?? '')) !== ''): ?> … <?php endif; ?>`;
- togliere `data-step="cart"` dal contenitore `data-checkout`.

Il JS del Task 13 non legge più `data-step`: sul carrello `root` non è un form, quindi niente `refresh` e niente riga Spedizione.

- [ ] **Step 3: Test e commit**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php -l view/pages/cart/index.php; php tests/CartCheckoutTest.php | tail -3`
Expected: «No syntax errors»; tutti `✓` tranne il check JS che chiude il Task 13 (se presente).

```bash
cd /Users/andreamarinoni/Developer/packages/ecommerce; git add view/pages/cart/index.php; git add -f tests/CartCheckoutTest.php; git commit -m "E1c: il carrello nel layout del negozio, col suo font, senza passi

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: `checkout.js` per la pagina unica (ecommerce)

**Files:**
- Modify: `ecommerce/resources/assets/js/checkout.js`
- Test: `ecommerce/tests/CartCheckoutTest.php`

**Interfaces:**
- Consumes: i ganci del Task 11, il payload del Task 10 (`icon_urls`, `fee_display`, `panel`, `address_complete`, `display.shipping_pending`), le etichette (`field_required`, `shipping_pending`, `shipping_methods_pending`).
- Produces: `window.Checkout`, `window.ecommerceCheckout` (invariati).

- [ ] **Step 1: Test che fallisce**

In `tests/CartCheckoutTest.php`, prima di `summary();`:

```php
check('checkout.js lavora su una pagina sola', function (): bool {
    $js = (string) file_get_contents(dirname(__DIR__).'/resources/assets/js/checkout.js');

    return !str_contains($js, 'dataset.step') && !str_contains($js, 'this.step')
        && !str_contains($js, 'data-line-sku') && !str_contains($js, 'innerHTML')
        && str_contains($js, '[data-choice-icons]') && str_contains($js, '[data-choice-panel]')
        && str_contains($js, 'wi-choice__more')
        && str_contains($js, 'field_required') && str_contains($js, "aria-invalid")
        && str_contains($js, 'scrollIntoView')
        && str_contains($js, 'shipping_methods_pending') && str_contains($js, 'address_complete')
        && str_contains($js, 'mirror(')
        && str_contains($js, "'pageshow'") && str_contains($js, 'data-checkout-locked')
        && str_contains($js, 'this.submit(this.latest)')
        && (bool) preg_match('/schedule\(\) \{\s*\/\/[^\n]*\n\s*this\.sequence\+\+;/', $js);
});
```

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/CartCheckoutTest.php | tail -2`
Expected: `✗`.

- [ ] **Step 2: Togliere i passi**

Leggere tutto `resources/assets/js/checkout.js` (438 righe) prima di cambiarlo. Poi:
- `constructor`: togliere `this.step = this.root.dataset.step …;`.
- `bind()`: il selettore osservato diventa sempre

```js
        const watch = 'select, [name^="shipping_"], [name="fulfillment_type"], [name="location_id"], [name="payment_method_id"]';
```

  e nel listener `change` aggiungere, prima di `this.toggles()`:

```js
            if (event.target.hasAttribute('aria-invalid')) this.fieldError(event.target, '');
```

  nel listener `input` aggiungere `this.mirror();` e lo stesso azzeramento dell'errore.
- `body(extra)`: manda sempre il form intero quando c'è:

```js
    body(extra = {}) {
        const data = this.form ? new FormData(this.form) : new FormData();
        if (!this.form) {
            const csrf = document.querySelector('[name="csrf_token"]');
            if (csrf) data.set('csrf_token', csrf.value);
        }
        Object.entries(extra).forEach(([key, value]) => data.set(key, value));
        return data;
    }
```

  (adattare i nomi di `extra` a quelli che il file usa già, per esempio nel coupon).
- `line()`: togliere la parte su `[data-line-sku]` e `labels.sku`.
- `totals()`: la condizione della riga Spedizione diventa `this.form && this.root.dataset.shipping !== 'off' && fulfillment.type === 'shipping'`; l'importo è `this.labels.shipping_pending` quando `display.shipping_pending` è vero, altrimenti `display.shipping_total` (o `labels.free` se è zero, come oggi).
- `row()`: `className = 'w-100 d-grid col-2 gap-3'`.
- `payments()`: si esegue sempre (non più solo col passo Pagamento):

```js
    payments(preview) {
        const box = this.root.querySelector('[data-checkout-payments]');
        if (!box) return;
        const methods = preview.payment_methods || {};
        this.choices(box, 'payment_method_id', methods.options || [], methods.selected,
            (option) => [option.name, '', option.fee_display || '', option.icon_urls || [], option.panel || '']);
    }
```

- `submit()`: si esegue se c'è il form (non più solo col passo Pagamento).

- [ ] **Step 3: Loghi e pannello in `choices()`**

In `choices(container, name, options, selected, parts)`, dove oggi si legge `const [title, text, aside] = parts(option);`:

```js
            const [title, text, aside, icons = [], panel = ''] = parts(option);
```

e, dopo aver impostato `aside`:

```js
            const logos = node.querySelector('[data-choice-icons]');
            if (logos) {
                const shown = icons.filter((icon) => icon && icon.src).slice(0, 3);
                logos.replaceChildren(...shown.map((icon) => {
                    const img = document.createElement('img');
                    img.src = icon.src;
                    img.alt = icon.alt || '';
                    img.width = 38;
                    img.height = 24;
                    img.loading = 'lazy';
                    return img;
                }));
                const more = icons.filter((icon) => icon && icon.src).length - shown.length;
                if (more > 0) {
                    const span = document.createElement('span');
                    span.className = 'wi-choice__more';
                    span.textContent = '+' + more;
                    logos.append(span);
                }
                logos.hidden = shown.length === 0;
            }
            const pane = node.querySelector('[data-choice-panel]');
            if (pane) {
                pane.textContent = panel;
                pane.hidden = panel === '';
            }
```

- [ ] **Step 4: Avvisi della spedizione**

In `shippingMethods(preview)`, la parte che mostra `no_shipping` diventa:

```js
        const notice = this.root.querySelector('[data-checkout-notice]');
        if (notice) {
            const methods = preview.shipping_methods || {};
            const key = (methods.options || []).length ? '' : (methods.address_complete ? 'no_shipping' : 'shipping_methods_pending');
            notice.textContent = key ? (this.labels[key] || '') : '';
            notice.hidden = key === '';
        }
```

- [ ] **Step 5: Interruttori con alternative**

In `toggles()`, la regola di un `[data-checkout-toggle]` accetta più condizioni separate da `|` (visibile se una è vera). Dove oggi si legge la regola (`nome:on|off|valore`, vuoto → visibile):

```js
        this.root.querySelectorAll('[data-checkout-toggle]').forEach((node) => {
            const rule = node.dataset.checkoutToggle || '';
            node.hidden = rule !== '' && !rule.split('|').some((part) => this.matches(part));
        });
```

con il controllo della singola condizione spostato in un metodo:

```js
    matches(part) {
        const [name, wanted] = part.split(':');
        const fields = [...this.root.querySelectorAll(`[name="${name}"]`)];
        if (!fields.length) return false;
        const field = fields.find((f) => (f.type === 'radio' ? f.checked : true));
        if (field && field.type === 'checkbox') return wanted === 'on' ? field.checked : !field.checked;
        const value = field ? field.value : '';
        if (wanted === 'on') return value !== '' && value !== '0';
        if (wanted === 'off') return value === '' || value === '0';
        return value === wanted;
    }
```

Attenzione: `|` prima voleva dire «on oppure off» dentro `nome:on|off|valore` (la sintassi era la descrizione dei valori, non un separatore). Controllare nel file che nessuna vista usi `|` col significato vecchio (`grep -rn "data-checkout-toggle" view`); le regole stampate dal Task 11 sono `fulfillment_type:shipping`, `same_as_shipping:0|fulfillment_type:pickup`, `invoice:on`, `billing_type:private`, `billing_type:business`.

- [ ] **Step 6: Errori sotto il campo**

In `guardSubmit()`, all'inizio del listener `submit`:

```js
            if (!this.validate(event)) return;
```

e i metodi nuovi:

```js
    validate(event) {
        let first = null;
        this.form.querySelectorAll('input, select, textarea').forEach((field) => {
            if (field.disabled || field.type === 'hidden' || field.closest('[hidden]')) return;
            const ok = field.checkValidity();
            this.fieldError(field, ok ? '' : this.message(field));
            if (!ok && !first) first = field;
        });
        if (!first) return true;
        event.preventDefault();
        first.focus({ preventScroll: true });
        first.scrollIntoView({ block: 'center', behavior: 'smooth' });
        return false;
    }

    message(field) {
        if (!field.validity.valueMissing) return field.validationMessage;
        const label = field.id ? this.form.querySelector(`label[for="${CSS.escape(field.id)}"]`) : null;
        const text = (label ? label.textContent : field.name).replace('*', '').trim().toLowerCase();
        return (this.labels.field_required || '{{label}}').replace('{{label}}', text);
    }

    fieldError(field, text) {
        const holder = field.parentElement;
        let note = holder.querySelector(':scope > [data-checkout-field-error]');
        if (!text) {
            field.removeAttribute('aria-invalid');
            if (note) note.remove();
            return;
        }
        if (!note) {
            note = document.createElement('p');
            note.className = 'text-small tx-danger mt-1';
            note.setAttribute('data-checkout-field-error', '');
            note.id = (field.id || field.name) + '-error';
            holder.append(note);
        }
        note.textContent = text;
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', note.id);
    }
```

- [ ] **Step 7: Nome e telefono nella fatturazione**

```js
    // Col ritiro (o con «indirizzo diverso») nome, cognome e telefono partono da quelli della consegna.
    mirror() {
        if (!this.form) return;
        [['shipping_name', 'billing_name'], ['shipping_surname', 'billing_surname'], ['phone', 'billing_phone']].forEach(([from, to]) => {
            const source = this.form.querySelector(`[name="${from}"]`);
            const target = this.form.querySelector(`[name="${to}"]`);
            if (!source || !target) return;
            if (target.value === '' || target.dataset.mirrored === target.value) {
                target.value = source.value;
                target.dataset.mirrored = source.value;
            }
        });
    }
```

Chiamarla anche una volta in fondo a `bind()`, dopo `this.toggles()`.

- [ ] **Step 8: Test e controllo nel browser**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; node --check resources/assets/js/checkout.js && php tests/CartCheckoutTest.php | tail -3`
Expected: nessun errore di sintassi; `CartCheckoutTest` tutti `✓`.

Nel browser pane aprire `https://ecommerce.test/checkout/` (con un prodotto nel carrello; l'accesso, se serve, lo fa l'utente): controllare con `read_console_messages` che non ci siano errori; cambiare spedizione/ritiro, scegliere un pagamento (si apre il pannello), premere «Ordina» col modulo vuoto (errori sotto i campi, scroll al primo). Fermarsi prima di inviare un ordine vero: l'invio lo prova l'utente.

- [ ] **Step 9: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/ecommerce; git add resources/assets/js/checkout.js; git add -f tests/CartCheckoutTest.php; git commit -m "E1c: checkout.js per la pagina unica (loghi, pannello, errori sotto il campo, fatturazione)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: documentazione e suite complete

**Files:**
- Modify: `ecommerce/docs/cart-checkout.md`, `ecommerce/CHANGELOG.md`, `ecommerce/TODO.md` (se esiste)
- Modify: `gestionale/CHANGELOG.md`, `gestionale/TODO.md`

- [ ] **Step 1: Documentazione**

- `ecommerce/docs/cart-checkout.md`: sostituire la parte sui tre passi con la pagina unica: rotte (`index`, `place`, `summary`, `coupon`, `completed`), ordine delle sezioni (rapido, contatti, consegna, pagamento, fatturazione, fattura, conferma), `CheckoutRules` (`post`, `deliveryErrors`, `billing` con «uguale» di partenza, `method`), solo metodi offerti dall'anteprima, loghi e pannello, `StoreFont` e le quattro aree, icone in `resources/assets/payment-icons` (MIT).
- `ecommerce/CHANGELOG.md`, `## Unreleased`: `### Changed` «Checkout in una pagina sola (via i passi Spedizione e Pagamento); carrello nel layout del negozio.»; `### Added` «Loghi dei metodi di pagamento, pannello sotto il metodo scelto, errori sotto il campo, font del commerciante su accesso, account, checkout e carrello.»; `### Removed` «Rotte `ecommerce.checkout.shipping` e `ecommerce.checkout.payment`.».
- `gestionale/CHANGELOG.md`, `## Unreleased` → `### Added`: «Icone dei metodi di pagamento; font del negozio online nelle impostazioni del commerciante; l'anteprima del checkout mostra solo i provider collegati.».
- `gestionale/TODO.md`: segnare fatto il piano 2 del checkout a pagina unica; resta la prova nel browser dell'utente.

- [ ] **Step 2: Suite complete**

Run: `cd /Users/andreamarinoni/Developer/packages/ecommerce; php tests/run.php > /tmp/ecommerce-suite.txt 2>&1; tail -5 /tmp/ecommerce-suite.txt; for f in tests/integrazione/*Test.php; do echo "$f"; php "$f" | tail -1; done`
Expected: suite verde; ogni test di integrazione `… 0 falliti`.

Run: `cd /Users/andreamarinoni/Developer/packages/gestionale; for f in tests/*Test.php tests/integrazione/*Test.php; do r=$(php "$f" 2>&1 | tail -1); echo "$f: $r"; done | grep -v ' 0 falliti'`
Expected: nessuna riga (tutti `0 falliti`). Se ci sono test che già fallivano prima di questo piano, elencarli per nome nel ledger e nel messaggio finale.

(Il file di uscita va nella cartella del ledger, non in `/tmp`, se chi esegue ne ha una.)

- [ ] **Step 3: Commit**

```bash
cd /Users/andreamarinoni/Developer/packages/ecommerce; git add docs/cart-checkout.md CHANGELOG.md; git add TODO.md 2>/dev/null; git commit -m "E1c: documentazione del checkout a pagina unica

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
cd /Users/andreamarinoni/Developer/packages/gestionale; git add CHANGELOG.md TODO.md; git commit -m "TODO: piano 2 del checkout a pagina unica fatto

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Niente push: lo decide l'utente.
