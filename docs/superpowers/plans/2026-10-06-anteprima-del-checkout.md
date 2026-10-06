# Anteprima del checkout (E1c D5, piano 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** dare al gestionale `Checkout::preview()`, che scrive sul carrello le scelte del cliente mentre compila e risponde con totali e scelte possibili, e far rifiutare a `Checkout::place()` un ritiro senza sede di ritiro e una spedizione senza metodo valido.

**Architecture:** una classe nuova `Support\Locations\PickupPoints` tiene la regola delle sedi di ritiro, usata da `preview`, `place` e `Shipments::createPickup`. `preview` gira in una transazione: scrive senza fermarsi sui campi rifiutati, ricalcola con `Cart::recalculate`, sceglie da sola il metodo o la sede quando ce n'è uno solo, rimette la commissione del pagamento e ricalcola di nuovo. `place` aggiunge un controllo `checkDelivery` dopo il ricalcolo.

**Tech Stack:** PHP 8.2, framework Wonder Image (Model, `Transaction::run`, `UserError`), test d'integrazione con `tests/harness.php` sul sito `boilerplates/ecommerce-site`.

**Spec:** `docs/superpowers/specs/2026-10-06-checkout-con-spedizione-design.md`, sezione 2 «Gestionale» e «Casi limite e test». Il piano 2 (ecommerce: rotte, `checkout.js`) è fuori da qui.

## Global Constraints

- Lingua: codice, commenti, test e frasi in italiano, come il resto del modulo.
- `preview` non prenota, non numera, non consuma il coupon, non apre pagamenti, non chiede l'email e non scrive email, telefono e note.
- «Sede di ritiro disponibile» = attiva, `is_pickup_point` vero, non eliminata, con la sede della società (`SocietyLocations::find`) presente. **L'orario non conta** al checkout; conta solo in `Shipments::createPickup` (`shipment.location_closed`).
- Funzionalità `shipping` spenta: nessuna sede, nessun metodo di spedizione, nessun controllo nuovo in `place`.
- Il controllo del metodo in `place` vale solo con consegna `shipping`, canale `online`, `source` `user` (il default) e almeno una riga da spedire.
- Errori nuovi in `lang/it/gestionale.json` sotto `errors.order`: `pickup_location_unavailable`, `shipping_method_required`, `shipping_unavailable`.
- Test: `php tests/integrazione/<Nome>Test.php` per un file, `php tests/run.php` per tutto. Rossi noti prima del lavoro: `CatalogDemoTest` e, in `ShipmentsPickupTest`, «una sede di ritiro chiusa si rifiuta». Non devono crescere.
- Commit con il trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Niente push.

## Review Focus

1. **Ritiro in una sede senza giacenza:** `place` deve fermarsi sulla prenotazione e il carrello restare intero (test in Task 3).
2. **Due schede sullo stesso carrello:** l'anteprima di una scheda scrive scelte diverse; vale il modulo inviato dall'altra, che `place` riscrive per intero (test in Task 3).
3. **Campo rifiutato dal modello in anteprima:** gli altri campi si scrivono lo stesso e il campo torna in `invalid` (test in Task 2).
4. **Funzionalità `shipping` spenta:** l'anteprima non offre sedi né metodi e `place` non chiede il metodo (test in Task 2 e 3).
5. **Carrello di soli servizi:** l'anteprima non offre metodi e `place` passa senza (test in Task 3).

---

## Mappa dei file

- Crea `src/Support/Locations/PickupPoints.php` — la regola delle sedi di ritiro.
- Modifica `src/Support/Shipping/Shipments.php` (`createPickup`, ~righe 212-226) — usa `PickupPoints::find`.
- Modifica `src/Support/Shipping/Shipping.php` — aggiunge `shippable()`.
- Modifica `src/Support/Orders/Checkout.php` — `preview`, `allowed`, `paymentMethods`, `paymentChoice`, `writeLoosely`, `addresses`, `checkDelivery`; `applyFee` accetta `null`.
- Modifica `lang/it/gestionale.json` — tre chiavi in `errors.order`.
- Crea `tests/integrazione/PickupPointsTest.php` e `tests/integrazione/CheckoutSpedizioneTest.php`.
- Modifica `tests/integrazione/supporto/spedizioni.php` (`giacenzaIn`), `CheckoutTest.php`, `CouponContemporaneitaTest.php`, `ShipmentsPickupTest.php`.
- Modifica `docs/user/spedizioni-spedire.md`, `docs/user/vendite-ordini.md`, `CHANGELOG.md`, `TODO.md`.

---

### Task 1: Le sedi di ritiro in un posto solo

**Files:**
- Create: `src/Support/Locations/PickupPoints.php`
- Modify: `src/Support/Shipping/Shipments.php:212-226`
- Test: `tests/integrazione/PickupPointsTest.php`

**Interfaces:**
- Produces:
  - `PickupPoints::find(int $locationId): ?array` → `array{location: array<string, mixed>, place: object}` oppure `null`;
  - `PickupPoints::all(): list<array{id: int, name: string, address: string}>`, in ordine di id; `name` è la `label` della sede della società, `address` è «via numero, cap città».

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/integrazione/PickupPointsTest.php`:

```php
<?php
/** php tests/integrazione/PickupPointsTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/spedizioni.php';

use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Support\Locations\PickupPoints;
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

/** @return list<int> */
function idDisponibili(): array
{
    return array_column(PickupPoints::all(), 'id');
}

check('una sede di ritiro attiva compare, con nome e indirizzo', fn () => prova(static function (): bool {
    $sede = sede(true, true, 'true', ['street' => 'Via Roma', 'number' => '3', 'cap' => '20100', 'city' => 'Milano']);
    $trovate = array_values(array_filter(PickupPoints::all(), static fn (array $punto): bool => $punto['id'] === $sede));

    return count($trovate) === 1
        && $trovate[0]['name'] === 'Prova ritiro'
        && $trovate[0]['address'] === 'Via Roma 3, 20100 Milano'
        && (int) PickupPoints::find($sede)['location']['id'] === $sede;
}));

check('una sede non di ritiro, spenta o eliminata non compare', fn () => prova(static function (): bool {
    $nonRitiro = sede(false);
    $spenta = sede(true, true, 'false');
    $eliminata = sede();
    sqlModify(Location::$table, ['deleted' => 'true'], 'id', $eliminata);

    return array_intersect([$nonRitiro, $spenta, $eliminata], idDisponibili()) === []
        && PickupPoints::find($nonRitiro) === null
        && PickupPoints::find($spenta) === null
        && PickupPoints::find($eliminata) === null
        && PickupPoints::find(0) === null;
}));

check('una sede di ritiro fuori orario compare lo stesso', fn () => prova(static function (): bool {
    $chiusa = sede(true, false);

    return in_array($chiusa, idDisponibili(), true) && PickupPoints::find($chiusa) !== null;
}));

summary();
```

- [ ] **Step 2: Lancia il test e guardalo fallire**

Run: `php tests/integrazione/PickupPointsTest.php`
Expected: i tre controlli falliscono con «Class "Wonder\Plugin\Gestionale\Support\Locations\PickupPoints" not found».

- [ ] **Step 3: Scrivi `PickupPoints`**

`src/Support/Locations/PickupPoints.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Locations;

use Wonder\App\Support\SocietyLocations;
use Wonder\Plugin\Gestionale\Models\Locations\Location;

/**
 * Le sedi dove il cliente può ritirare.
 *
 * Una regola sola per il checkout e per le spedizioni: la sede è attiva, è un
 * punto di ritiro, non è eliminata e la sede della società dietro c'è ancora.
 * L'orario qui non conta: chi ordina la sera ritira domani. Conta quando la
 * merce è pronta, in `Shipments::createPickup`.
 */
final class PickupPoints
{
    /**
     * La sede, se ci si può ritirare.
     *
     * @return array{location: array<string, mixed>, place: object}|null
     */
    public static function find(int $locationId): ?array
    {
        $location = $locationId > 0 ? Location::findById($locationId) : null;

        if (!is_array($location) || !isset($location['id']) || !self::usable($location)) {
            return null;
        }

        $place = SocietyLocations::find((int) $location['society_location_id']);

        return is_object($place) ? ['location' => $location, 'place' => $place] : null;
    }

    /**
     * Le sedi dove si può ritirare, in ordine di id.
     *
     * @return list<array{id: int, name: string, address: string}>
     */
    public static function all(): array
    {
        $points = [];

        foreach (self::rows(Location::find(['active' => 'true', 'is_pickup_point' => 'true'])) as $location) {
            if (!self::usable($location)) {
                continue;
            }

            $place = SocietyLocations::find((int) $location['society_location_id']);

            if (!is_object($place)) {
                continue;
            }

            $points[] = [
                'id' => (int) $location['id'],
                'name' => trim((string) ($place->label ?? '')),
                'address' => self::address($place),
            ];
        }

        usort($points, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $points;
    }

    /** @param array<string, mixed> $location */
    private static function usable(array $location): bool
    {
        return (string) ($location['deleted'] ?? 'false') !== 'true'
            && (string) ($location['active'] ?? 'false') === 'true'
            && (string) ($location['is_pickup_point'] ?? 'false') === 'true';
    }

    /** «Via numero, cap città», quel che c'è. */
    private static function address(object $place): string
    {
        $parts = [
            trim(trim((string) ($place->street ?? '')).' '.trim((string) ($place->number ?? ''))),
            trim(trim((string) ($place->cap ?? '')).' '.trim((string) ($place->city ?? ''))),
        ];

        return implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        return array_key_exists('id', $found) ? [$found] : array_values(array_filter($found, 'is_array'));
    }
}
```

- [ ] **Step 4: Lancia il test e guardalo passare**

Run: `php tests/integrazione/PickupPointsTest.php`
Expected: tre ✓, nessun ✗.

- [ ] **Step 5: `Shipments::createPickup` usa la stessa regola**

In `src/Support/Shipping/Shipments.php` sostituisci il blocco da `$locationId = (int) ($order['location_id'] ?? 0);` fino alla chiusura dell'`if` di `shipment.location_closed` con:

```php
            $point = PickupPoints::find((int) ($order['location_id'] ?? 0));

            if ($point === null) {
                throw UserError::make('shipment.not_pickup_point');
            }

            // L'orario conta qui, quando la merce è pronta, non al checkout.
            if (!SocietyLocations::isOpen($point['place'])) {
                throw UserError::make('shipment.location_closed');
            }
```

e aggiungi `use Wonder\Plugin\Gestionale\Support\Locations\PickupPoints;` agli use, in ordine alfabetico. `Location` resta: lo usa `placeOf()`. Una sede della società sparita ora dà `not_pickup_point` invece di `location_closed`: è voluto.

- [ ] **Step 6: Verifica che le spedizioni di ritiro non cambino**

Run: `php tests/integrazione/ShipmentsPickupTest.php`
Expected: stessi esiti di prima; unico ✗ quello noto «una sede di ritiro chiusa si rifiuta».

- [ ] **Step 7: Commit**

```bash
git add src/Support/Locations/PickupPoints.php src/Support/Shipping/Shipments.php tests/integrazione/PickupPointsTest.php
git commit -m "E1c D5: le sedi di ritiro in un posto solo (PickupPoints), senza orario al checkout

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `Checkout::preview`

**Files:**
- Modify: `src/Support/Orders/Checkout.php`
- Test: `tests/integrazione/CheckoutSpedizioneTest.php` (nuovo; il Task 3 ci aggiunge i controlli di `place`)

**Interfaces:**
- Consumes: `PickupPoints::all()`, `Shipping::options(int $cartId)` (`list{method_id, name, description, carrier_id, price, free}`), `Cart::recalculate(int)` (`order`, `items`, `coupon_dropped`, `shipping_dropped`, …).
- Produces:
  - `Checkout::preview(int $cartId, array $data): array` con le chiavi `order` (`products_total`, `discount_total`, `shipping_total`, `fees_total`, `total`, `currency`), `items`, `fulfillment` `{type, choices}`, `shipping_methods` `{options, selected}`, `pickup_locations` `{options, selected}`, `payment_methods` `{options: list{id, name, provider, manual, instructions}, selected}`, `coupon` `{code, dropped}`, `notices: list<string>`, `invalid: list<string>`;
  - privati riusati dal Task 3: `allowed(array $method, string $fulfillment): bool`, `addresses(array $data): array`.
  - `applyFee(int $orderId, ?array $method)`: con `null` toglie soltanto le commissioni.

- [ ] **Step 1: Scrivi i test che falliscono**

`tests/integrazione/CheckoutSpedizioneTest.php`:

```php
<?php
/** php tests/integrazione/CheckoutSpedizioneTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/spedizioni.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\Plugin\Gestionale\Support\Orders\PaymentTiming;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/**
 * Ogni prova parte senza i metodi di spedizione e le sedi di ritiro del sito,
 * che altrimenti entrerebbero nelle scelte; la transazione rimette tutto.
 */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
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

/** Le righe di un Model che rispondono alla condizione, in elenco. */
function righe(string $model, string $dove): array
{
    $rows = $model::find($dove);

    if (!is_array($rows) || $rows === []) {
        return [];
    }

    return array_key_exists('id', $rows) ? [$rows] : array_values(array_filter($rows, 'is_array'));
}

/** Le righe del carrello di un tipo (`shipping`, `fee`, …). */
function righeDi(int $cart, string $tipo): array
{
    return righe(OrderItem::class, "order_id = {$cart} AND type = '{$tipo}' AND deleted = 'false'");
}

/** La chiave del rifiuto, '' se non c'è stato. */
function rifiuto(callable $fn): string
{
    try {
        $fn();
    } catch (UserError $errore) {
        return $errore->key();
    }

    return '';
}

/** Un metodo di pagamento di prova: carta, bonifico o contanti alla consegna secondo il modo. */
function pagamento(string $timing, array $valori = []): int
{
    return (int) (PaymentMethod::create($valori + [
        'code' => 'tst_'.uniqid(),
        'name' => 'Prova '.$timing,
        'provider' => $timing === PaymentTiming::IMMEDIATE ? 'stripe' : ($timing === PaymentTiming::ON_DELIVERY ? 'cash' : 'bank_transfer'),
        'timing' => $timing,
        'fee_type' => 'none',
        'fee_value' => '0.00',
        'fee_percent' => '0.00',
        'available_for' => 'all',
        'active' => 'true',
        'position' => 1,
        'instructions' => 'Istruzioni di prova',
    ])->insert_id ?? 0);
}

/** Un metodo per tutta Italia: 8 € fino a 5 kg, 15 € fino a 20, contrassegno a 3,50 €. */
function standard(string $nome = 'Standard'): int
{
    $metodo = metodo($nome);
    listino($metodo, zona('Italia '.$nome, [['IT', '']]), [[5, 8.0], [20, 15.0]], ['cod_fee' => '3.50']);

    return $metodo;
}

function milano(): array
{
    return ['country' => 'IT', 'province' => 'MI', 'city' => 'Milano', 'cap' => '20100', 'street' => 'Via Prova', 'number' => '1'];
}

function parigi(): array
{
    return ['country' => 'FR', 'province' => '', 'city' => 'Paris', 'cap' => '75001', 'street' => 'Rue de Rivoli', 'number' => '1'];
}

/** La voce di un metodo di pagamento nell'anteprima, o null. */
function voce(array $opzioni, int $id): ?array
{
    foreach ($opzioni as $opzione) {
        if ($opzione['id'] === $id) {
            return $opzione;
        }
    }

    return null;
}

/** Un coupon di prova, al 10%. @return array{id: int, code: string} */
function couponDiProva(array $valori = []): array
{
    $codice = 'T'.strtoupper(substr(uniqid(), -8));
    $id = (int) (Coupon::create($valori + [
        'code' => $codice,
        'name' => 'Prova '.$codice,
        'discount_type' => 'percent',
        'discount_value' => '10.00',
        'applies_to_all' => 'true',
        'applies_online' => 'true',
        'active' => 'true',
    ])->insert_id ?? 0);

    return ['id' => $id, 'code' => $codice];
}

check('l\'anteprima prende dati a metà senza email, e il bonifico è un metodo a mano', fn () => prova(static function (): bool {
    standard();
    $bonifico = pagamento(PaymentTiming::DEFERRED);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    $r = Checkout::preview($cart, ['payment_method_id' => $bonifico, 'shipping' => milano()]);
    $voce = voce($r['payment_methods']['options'], $bonifico);

    return $r['invalid'] === []
        && $r['payment_methods']['selected'] === $bonifico
        && $voce !== null && $voce['manual'] === true && $voce['instructions'] === 'Istruzioni di prova'
        && (int) Order::findById($cart)['payment_method_id'] === $bonifico
        && trim((string) Order::findById($cart)['email']) === '';
}));

check('l\'unico metodo che copre l\'indirizzo si sceglie da solo; a Parigi se ne va con un avviso', fn () => prova(static function (): bool {
    $metodo = standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    $milano = Checkout::preview($cart, ['shipping' => milano()]);
    $parigi = Checkout::preview($cart, ['shipping' => parigi()]);

    return $milano['shipping_methods']['selected'] === $metodo
        && array_column($milano['shipping_methods']['options'], 'method_id') === [$metodo]
        && (float) $milano['order']['shipping_total'] === 8.0
        && $parigi['shipping_methods'] === ['options' => [], 'selected' => 0]
        && (float) $parigi['order']['shipping_total'] === 0.0
        && righeDi($cart, 'shipping') === []
        && count($parigi['notices']) === 1;
}));

check('la commissione segue il pagamento: 2 € col bonifico, 3,50 € col contrassegno, niente senza metodo', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $bonifico = pagamento(PaymentTiming::DEFERRED, ['fee_type' => 'amount', 'fee_value' => '2.00']);
    $contrassegno = pagamento(PaymentTiming::ON_DELIVERY);

    $a = Checkout::preview($cart, ['shipping' => milano(), 'payment_method_id' => $bonifico]);
    $b = Checkout::preview($cart, ['payment_method_id' => $contrassegno]);
    $fee = righeDi($cart, 'fee');
    $c = Checkout::preview($cart, ['payment_method_id' => 0]);

    return (float) $a['order']['fees_total'] === 2.0
        && (float) $b['order']['fees_total'] === 3.5
        && count($fee) === 1
        && (float) $c['order']['fees_total'] === 0.0
        && $c['payment_methods']['selected'] === 0
        && righeDi($cart, 'fee') === [];
}));

check('col ritiro la sola sede si sceglie da sola, la spedizione sparisce e i pagamenti si filtrano', fn () => prova(static function (): bool {
    standard();
    $sede = sede();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    $soloSpedizione = pagamento(PaymentTiming::DEFERRED, ['available_for' => 'shipping']);
    $perRitiro = pagamento(PaymentTiming::DEFERRED, ['available_for' => 'pickup']);

    $r = Checkout::preview($cart, ['fulfillment_type' => 'pickup', 'payment_method_id' => $soloSpedizione, 'shipping' => milano()]);
    $ids = array_column($r['payment_methods']['options'], 'id');
    $riga = Order::findById($cart);

    return $r['fulfillment'] === ['type' => 'pickup', 'choices' => ['shipping', 'pickup']]
        && $r['pickup_locations']['selected'] === $sede
        && array_column($r['pickup_locations']['options'], 'id') === [$sede]
        && $r['shipping_methods'] === ['options' => [], 'selected' => 0]
        && righeDi($cart, 'shipping') === []
        && in_array($perRitiro, $ids, true)
        && !in_array($soloSpedizione, $ids, true)
        && $r['payment_methods']['selected'] === 0
        && (int) $riga['location_id'] === $sede
        && (int) $riga['shipping_method_id'] === 0
        && (int) $riga['payment_method_id'] === 0;
}));

check('con più sedi vale quella scelta se è di ritiro, altrimenti nessuna', fn () => prova(static function (): bool {
    sede();
    $seconda = sede();
    $altra = sede(false);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    $scelta = Checkout::preview($cart, ['fulfillment_type' => 'pickup', 'location_id' => $seconda]);
    $sbagliata = Checkout::preview($cart, ['fulfillment_type' => 'pickup', 'location_id' => $altra]);

    return $scelta['pickup_locations']['selected'] === $seconda
        && $sbagliata['pickup_locations']['selected'] === 0
        && count($sbagliata['pickup_locations']['options']) === 2
        && (int) Order::findById($cart)['location_id'] === 0;
}));

check('senza sedi di ritiro la consegna torna spedizione', fn () => prova(static function (): bool {
    $altra = sede(false);
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    $r = Checkout::preview($cart, ['fulfillment_type' => 'pickup', 'location_id' => $altra]);
    $riga = Order::findById($cart);

    return $r['fulfillment'] === ['type' => 'shipping', 'choices' => ['shipping']]
        && $r['pickup_locations'] === ['options' => [], 'selected' => 0]
        && (string) $riga['fulfillment_type'] === 'shipping'
        && (int) $riga['location_id'] === 0;
}));

check('il coupon che non regge più cade, con la sua frase fra gli avvisi', fn () => prova(static function (): bool {
    $prodotto = articolo(2.0, 40.0);
    $cart = carrello([[$prodotto, 1]]);
    $coupon = couponDiProva(['min_order_amount' => '30.00']);
    Coupons::apply($cart, $coupon['code']);
    Product::update(['price' => '5.00'], $prodotto);

    $r = Checkout::preview($cart, []);

    return $r['coupon'] === ['code' => '', 'dropped' => 'min_order']
        && in_array(UserError::make('coupon.min_order')->getMessage(), $r['notices'], true);
}));

check('l\'anteprima non prenota, non numera, non consuma il coupon e non apre pagamenti', fn () => prova(static function (): bool {
    standard();
    $prodotto = articolo(2.0, 10.0);
    $cart = carrello([[$prodotto, 1]]);
    $coupon = couponDiProva();
    Coupons::apply($cart, $coupon['code']);
    $prima = Levels::of($prodotto)['available'];

    Checkout::preview($cart, ['payment_method_id' => pagamento(PaymentTiming::IMMEDIATE), 'shipping' => milano()]);
    $riga = Order::findById($cart);

    return (string) $riga['stage'] === 'cart'
        && trim((string) $riga['order_number']) === ''
        && Levels::of($prodotto)['available'] === $prima
        && (int) sqlCount(StockReservation::$table, "order_id = {$cart} AND deleted = 'false'") === 0
        && (int) sqlCount(CouponRedemption::$table, "coupon_id = {$coupon['id']}") === 0
        && (int) sqlCount(Payment::$table, "order_id = {$cart} AND deleted = 'false'") === 0;
}));

check('un campo rifiutato torna in invalid e gli altri si scrivono lo stesso', fn () => prova(static function (): bool {
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    $r = Checkout::preview($cart, ['billing' => ['pec' => 'non-una-email', 'city' => 'Torino']]);
    $riga = Order::findById($cart);

    return $r['invalid'] === ['billing_pec']
        && (string) $riga['billing_city'] === 'Torino'
        && trim((string) $riga['billing_pec']) === '';
}));

check('con le spedizioni spente niente sedi e niente metodi', fn () => prova(static function (): bool {
    standard();
    $sede = sede();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    spegniFunzionalita(['shipping']);

    $r = Checkout::preview($cart, ['fulfillment_type' => 'pickup', 'location_id' => $sede, 'shipping' => milano()]);

    return $r['fulfillment'] === ['type' => 'shipping', 'choices' => ['shipping']]
        && $r['shipping_methods'] === ['options' => [], 'selected' => 0]
        && $r['pickup_locations'] === ['options' => [], 'selected' => 0]
        && righeDi($cart, 'shipping') === [];
}));

check('l\'anteprima di un ordine o di niente si rifiuta', fn () => prova(static function (): bool {
    [$ordine] = ordineDaSpedire([[articolo(1.0), 1]]);

    return rifiuto(fn () => Checkout::preview($ordine, [])) === 'cart.not_a_cart'
        && rifiuto(fn () => Checkout::preview(0, [])) === 'cart.not_a_cart';
}));

summary();
```

- [ ] **Step 2: Lancia i test e guardali fallire**

Run: `php tests/integrazione/CheckoutSpedizioneTest.php`
Expected: tutti ✗ con «Call to undefined method …Checkout::preview()».

- [ ] **Step 3: Scrivi `preview` e i suoi aiuti**

In `src/Support/Orders/Checkout.php` aggiungi agli use, in ordine alfabetico:

```php
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Locations\PickupPoints;
```

Dopo `place()` aggiungi:

```php
    /**
     * Il riepilogo del checkout mentre il cliente compila.
     *
     * Scrive sul carrello le scelte fatte fin qui — consegna, sede, metodo di
     * spedizione, pagamento, indirizzi — e toglie quelle che non valgono più;
     * poi ricalcola e dice cosa si può ancora scegliere. Non prenota, non
     * numera, non consuma il coupon e non chiede l'email: quello lo fa
     * `place()`, che riscrive tutto con il modulo inviato.
     *
     * Un campo che il modello rifiuta non ferma niente: torna in `invalid` e
     * gli altri si scrivono lo stesso.
     *
     * @param array<string, mixed> $data
     * @return array{
     *     order: array{products_total: string, discount_total: string, shipping_total: string, fees_total: string, total: string, currency: string},
     *     items: list<array<string, mixed>>,
     *     fulfillment: array{type: string, choices: list<string>},
     *     shipping_methods: array{options: list<array<string, mixed>>, selected: int},
     *     pickup_locations: array{options: list<array{id: int, name: string, address: string}>, selected: int},
     *     payment_methods: array{options: list<array{id: int, name: string, provider: string, manual: bool, instructions: string}>, selected: int},
     *     coupon: array{code: string, dropped: string},
     *     notices: list<string>,
     *     invalid: list<string>
     * }
     */
    public static function preview(int $cartId, array $data): array
    {
        return Transaction::run(static function () use ($cartId, $data): array {
            $cart = Order::findForUpdate(['id' => $cartId], 1);

            if (!is_array($cart) || $cart === [] || (string) $cart['stage'] !== 'cart') {
                throw UserError::make('cart.not_a_cart');
            }

            // Quello che il modulo non manda resta com'è sul carrello.
            $data += [
                'fulfillment_type' => (string) ($cart['fulfillment_type'] ?? 'shipping'),
                'shipping_method_id' => (int) ($cart['shipping_method_id'] ?? 0),
                'location_id' => (int) ($cart['location_id'] ?? 0),
                'payment_method_id' => (int) ($cart['payment_method_id'] ?? 0),
            ];

            $shipping = Gestionale::feature('shipping');
            $points = $shipping ? PickupPoints::all() : [];
            $type = (string) $data['fulfillment_type'];

            if (!in_array($type, ['shipping', 'pickup'], true) || ($type === 'pickup' && $points === [])) {
                $type = 'shipping';
            }

            $locationId = 0;

            if ($type === 'pickup') {
                $ids = array_column($points, 'id');
                $wanted = (int) $data['location_id'];
                $locationId = in_array($wanted, $ids, true) ? $wanted : (count($ids) === 1 ? $ids[0] : 0);
            }

            $payments = self::paymentMethods($type);
            $payment = null;

            foreach ($payments as $candidate) {
                if ((int) $candidate['id'] === (int) $data['payment_method_id']) {
                    $payment = $candidate;
                }
            }

            $invalid = self::writeLoosely($cartId, [
                'fulfillment_type' => $type,
                'location_id' => $locationId,
                'shipping_method_id' => $type === 'shipping' && $shipping ? (int) $data['shipping_method_id'] : 0,
                'payment_method_id' => $payment === null ? 0 : (int) $payment['id'],
                'last_activity_at' => date('Y-m-d H:i:s'),
            ] + self::addresses($data));

            // Il primo ricalcolo toglie il metodo che non copre più
            // l'indirizzo; poi, se ne resta uno solo, si sceglie da sé.
            $first = Cart::recalculate($cartId);
            $methodId = (int) ($first['order']['shipping_method_id'] ?? 0);
            $options = $type === 'shipping' ? Shipping::options($cartId) : [];

            if ($type === 'shipping') {
                $available = array_column($options, 'method_id');
                $chosen = in_array($methodId, $available, true) ? $methodId : (count($available) === 1 ? $available[0] : 0);

                if ($chosen !== $methodId) {
                    Order::update(['shipping_method_id' => $chosen], $cartId);
                    $methodId = $chosen;
                }
            }

            // La commissione dipende dal metodo di spedizione (contrassegno) e
            // dai prodotti: va dopo, e poi un secondo ricalcolo la somma.
            self::applyFee($cartId, $payment);
            $second = Cart::recalculate($cartId);
            $order = $second['order'];
            $dropped = $first['coupon_dropped'] !== '' ? $first['coupon_dropped'] : $second['coupon_dropped'];
            $notices = [];

            foreach ([$first['shipping_dropped'], $second['shipping_dropped'], $dropped !== '' ? UserError::make('coupon.'.$dropped)->getMessage() : ''] as $notice) {
                if ($notice !== '' && !in_array($notice, $notices, true)) {
                    $notices[] = $notice;
                }
            }

            return [
                'order' => [
                    'products_total' => (string) ($order['products_total'] ?? '0.00'),
                    'discount_total' => (string) ($order['discount_total'] ?? '0.00'),
                    'shipping_total' => (string) ($order['shipping_total'] ?? '0.00'),
                    'fees_total' => (string) ($order['fees_total'] ?? '0.00'),
                    'total' => (string) ($order['total'] ?? '0.00'),
                    'currency' => (string) ($order['currency'] ?? 'EUR'),
                ],
                'items' => $second['items'],
                'fulfillment' => ['type' => $type, 'choices' => $points === [] ? ['shipping'] : ['shipping', 'pickup']],
                'shipping_methods' => ['options' => $options, 'selected' => $type === 'shipping' ? $methodId : 0],
                'pickup_locations' => ['options' => $points, 'selected' => $locationId],
                'payment_methods' => [
                    'options' => array_map(self::paymentChoice(...), $payments),
                    'selected' => $payment === null ? 0 : (int) $payment['id'],
                ],
                'coupon' => ['code' => (string) ($order['coupon_code'] ?? ''), 'dropped' => $dropped],
                'notices' => $notices,
                'invalid' => $invalid,
            ];
        });
    }
```

Sostituisci `method()` con la versione che usa `allowed()`, e aggiungi `allowed`, `paymentMethods`, `paymentChoice` subito dopo:

```php
    /**
     * Il metodo di pagamento, se si può ancora usare per questa consegna.
     *
     * @return array<string, mixed>
     */
    private static function method(int $methodId, string $fulfillment): array
    {
        $method = $methodId > 0 ? PaymentMethod::findById($methodId) : null;

        if (!is_array($method) || $method === [] || !self::allowed($method, $fulfillment)) {
            throw UserError::make('order.payment_method_unavailable');
        }

        return $method;
    }

    /**
     * Deve essere attivo, offerto sul sito e ammesso per la consegna scelta:
     * il contrassegno di una spedizione non si usa per un ritiro, e un metodo
     * che il commerciante ha lasciato solo per il banco non compare online.
     *
     * @param array<string, mixed> $method
     */
    private static function allowed(array $method, string $fulfillment): bool
    {
        $for = (string) ($method['available_for'] ?? 'all');

        return ($method['active'] ?? 'false') === 'true'
            && ($method['deleted'] ?? 'false') !== 'true'
            && ($method['applies_online'] ?? 'true') === 'true'
            && ($for === 'all' || $fulfillment === 'none' || $for === $fulfillment);
    }

    /**
     * I metodi di pagamento che il cliente può scegliere, in ordine di posizione.
     *
     * @return list<array<string, mixed>>
     */
    private static function paymentMethods(string $fulfillment): array
    {
        $methods = array_values(array_filter(
            self::rows(PaymentMethod::find(['active' => 'true'])),
            static fn (array $method): bool => self::allowed($method, $fulfillment)
        ));

        usort($methods, static fn (array $a, array $b): int => [(int) ($a['position'] ?? 0), (int) $a['id']] <=> [(int) ($b['position'] ?? 0), (int) $b['id']]);

        return $methods;
    }

    /**
     * La voce del metodo per il modulo: `manual` dice al sito che non c'è un
     * gateway da aprire (bonifico, contanti).
     *
     * @param array<string, mixed> $method
     * @return array{id: int, name: string, provider: string, manual: bool, instructions: string}
     */
    private static function paymentChoice(array $method): array
    {
        $provider = (string) ($method['provider'] ?? '');

        return [
            'id' => (int) $method['id'],
            'name' => (string) ($method['name'] ?? ''),
            'provider' => $provider,
            'manual' => PaymentMethod::ledgerProvider($provider) === 'manual',
            'instructions' => (string) ($method['instructions'] ?? ''),
        ];
    }
```

Dopo `write()` aggiungi `writeLoosely`:

```php
    /**
     * Scrive quello che il modello accetta e restituisce i campi rifiutati:
     * in anteprima un dato sbagliato non deve buttare via gli altri.
     *
     * @param array<string, string|int> $fields
     * @return list<string>
     */
    private static function writeLoosely(int $orderId, array $fields): array
    {
        $result = Order::update($fields, $orderId);

        if (($result->success ?? false) === true) {
            return [];
        }

        $invalid = is_array($result->response ?? null) ? array_map('strval', array_keys($result->response)) : [];
        $rest = array_diff_key($fields, array_flip($invalid));

        if ($rest !== []) {
            self::write($orderId, $rest);
        }

        return array_values($invalid);
    }
```

In `details()` sostituisci il ciclo `foreach (['billing', 'shipping'] as $group) { … }` e il `return $fields;` con:

```php
        // I campi dell'ordine vengono prima: un `shipping[method_id]` nel
        // modulo non deve poter riscrivere `shipping_method_id`.
        return $fields + self::addresses($data);
```

e aggiungi dopo `details()`:

```php
    /**
     * Gli indirizzi del modulo come campi dell'ordine (`billing_city`, …).
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private static function addresses(array $data): array
    {
        $fields = [];

        foreach (['billing', 'shipping'] as $group) {
            $address = $data[$group] ?? [];

            if (!is_array($address)) {
                continue;
            }

            foreach ($address as $key => $value) {
                if (is_scalar($value)) {
                    $fields[$group.'_'.$key] = (string) $value;
                }
            }
        }

        return $fields;
    }
```

In `applyFee` cambia la firma e il docblock e aggiungi l'uscita dopo il ciclo che cancella:

```php
     * @param array<string, mixed>|null $method null quando non c'è ancora un metodo: si toglie e basta
     */
    private static function applyFee(int $orderId, ?array $method): void
    {
        foreach (self::rows(OrderItem::find(['order_id' => $orderId, 'type' => 'fee', 'deleted' => 'false'])) as $old) {
            OrderItem::delete((int) $old['id']);
        }

        if ($method === null) {
            return;
        }
```

- [ ] **Step 4: Lancia i test e guardali passare**

Run: `php tests/integrazione/CheckoutSpedizioneTest.php`
Expected: tutti ✓.

- [ ] **Step 5: Verifica che `place` non sia cambiato**

Run: `php tests/integrazione/CheckoutTest.php && php tests/integrazione/CouponsTest.php && php tests/integrazione/CartShippingTest.php`
Expected: tutti ✓ (`CheckoutTest` può già mostrare ✗ da spedizione accesa solo dal Task 3 in poi; qui nessuno).

- [ ] **Step 6: Commit**

```bash
git add src/Support/Orders/Checkout.php tests/integrazione/CheckoutSpedizioneTest.php
git commit -m "E1c D5: Checkout::preview, il riepilogo del checkout mentre il cliente compila

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `place` controlla sede e metodo di spedizione

**Files:**
- Modify: `src/Support/Orders/Checkout.php` (`create`, nuovo `checkDelivery`)
- Modify: `src/Support/Shipping/Shipping.php` (nuovo `shippable`)
- Modify: `lang/it/gestionale.json:24-27`
- Modify: `tests/integrazione/supporto/spedizioni.php`, `tests/integrazione/CheckoutTest.php:33-46`, `tests/integrazione/CouponContemporaneitaTest.php:~120`, `tests/integrazione/ShipmentsPickupTest.php:294-354`
- Test: `tests/integrazione/CheckoutSpedizioneTest.php`

**Interfaces:**
- Consumes: `PickupPoints::find()`, `Shipping::options()`, gli aiuti di test del Task 2.
- Produces: `Shipping::shippable(int $cartId): bool`; `giacenzaIn(int $prodotto, int $sede, float $pezzi): void` in `supporto/spedizioni.php`; errori `order.pickup_location_unavailable`, `order.shipping_method_required`, `order.shipping_unavailable`.

- [ ] **Step 1: Aggiungi `giacenzaIn` agli aiuti**

In `tests/integrazione/supporto/spedizioni.php` aggiungi `use Wonder\Plugin\Gestionale\Support\Stock\Stock;` agli use e in fondo:

```php
/** Mette dei pezzi di un articolo in una sede. */
function giacenzaIn(int $prodotto, int $sede, float $pezzi): void
{
    Stock::apply([
        'product_id' => $prodotto,
        'location_id' => $sede,
        'quantity' => $pezzi,
        'type' => 'purchase',
        'reason' => 'initial_stock',
    ]);
}
```

- [ ] **Step 2: Scrivi i test che falliscono**

In `tests/integrazione/CheckoutSpedizioneTest.php`, prima di `summary();`:

```php
/** Il checkout, senza spedire posta: col bonifico e l'indirizzo di Milano se non si dice altro. */
function compra(int $cart, array $dati): array
{
    Mailer::useTransport(static fn (): bool => true);

    try {
        return Checkout::place($cart, $dati + [
            'email' => 'cliente@example.com',
            'payment_method_id' => pagamento(PaymentTiming::DEFERRED),
            'fulfillment_type' => 'shipping',
            'billing' => milano() + ['name' => 'Mario', 'surname' => 'Rossi'],
        ]);
    } finally {
        Mailer::useTransport(null);
    }
}

function restaCarrello(int $cart): bool
{
    return (string) Order::findById($cart)['stage'] === 'cart'
        && (int) sqlCount(StockReservation::$table, "order_id = {$cart} AND deleted = 'false'") === 0;
}

check('il ritiro in una sede che non è di ritiro, spenta o nessuna si rifiuta e il carrello resta', fn () => prova(static function (): bool {
    foreach ([sede(false), sede(true, true, 'false'), 0] as $sedeId) {
        $cart = carrello([[articolo(2.0, 10.0), 1]]);

        if (rifiuto(fn () => compra($cart, ['fulfillment_type' => 'pickup', 'location_id' => $sedeId])) !== 'order.pickup_location_unavailable'
            || !restaCarrello($cart)) {
            return false;
        }
    }

    return true;
}));

check('il ritiro in una sede fuori orario passa e prenota la merce di quella sede', fn () => prova(static function (): bool {
    $sede = sede(true, false);
    $prodotto = articolo(2.0, 10.0);
    giacenzaIn($prodotto, $sede, 5);
    $cart = carrello([[$prodotto, 1]]);

    $esito = compra($cart, ['fulfillment_type' => 'pickup', 'location_id' => $sede]);
    $prenotazioni = righe(StockReservation::class, "order_id = {$cart} AND deleted = 'false'");

    return $esito['status'] === 'pending'
        && count($prenotazioni) === 1
        && (int) $prenotazioni[0]['location_id'] === $sede;
}));

check('il ritiro in una sede senza la merce si ferma e il carrello resta', fn () => prova(static function (): bool {
    $sede = sede();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    return rifiuto(fn () => compra($cart, ['fulfillment_type' => 'pickup', 'location_id' => $sede])) !== ''
        && restaCarrello($cart);
}));

check('una spedizione senza metodo si rifiuta; col metodo passa con la sua riga', fn () => prova(static function (): bool {
    $metodo = standard();
    $senza = carrello([[articolo(2.0, 10.0), 1]]);
    $con = carrello([[articolo(2.0, 10.0), 1]]);

    $rifiuto = rifiuto(fn () => compra($senza, []));
    $esito = compra($con, ['shipping_method_id' => $metodo]);

    return $rifiuto === 'order.shipping_method_required'
        && restaCarrello($senza)
        && $esito['status'] === 'pending'
        && count(righeDi($con, 'shipping')) === 1;
}));

check('una spedizione dove nessun metodo arriva si rifiuta', fn () => prova(static function (): bool {
    $metodo = standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    return rifiuto(fn () => compra($cart, ['shipping_method_id' => $metodo, 'shipping' => parigi()])) === 'order.shipping_unavailable'
        && restaCarrello($cart);
}));

check('con le spedizioni spente si compra senza metodo', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);
    spegniFunzionalita(['shipping']);

    return compra($cart, [])['status'] === 'pending';
}));

check('un carrello di soli servizi non offre metodi e si compra senza', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(0.0, 10.0, [0, 0, 0], 'false'), 1]]);

    $anteprima = Checkout::preview($cart, ['shipping' => milano()]);

    return $anteprima['shipping_methods'] === ['options' => [], 'selected' => 0]
        && compra($cart, [])['status'] === 'pending';
}));

check('un ordine fatto dal sistema non chiede il metodo', fn () => prova(static function (): bool {
    standard();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    return compra($cart, ['source' => 'system'])['status'] === 'pending';
}));

check('con due schede aperte vale il modulo inviato', fn () => prova(static function (): bool {
    $metodo = standard();
    $espresso = metodo('Espresso');
    listino($espresso, zona('Italia Espresso', [['IT', '']]), [[20, 20.0]]);
    $sede = sede();
    $cart = carrello([[articolo(2.0, 10.0), 1]]);

    // L'altra scheda sceglie l'espresso, poi il ritiro.
    Checkout::preview($cart, ['shipping_method_id' => $espresso, 'shipping' => milano()]);
    Checkout::preview($cart, ['fulfillment_type' => 'pickup', 'location_id' => $sede]);
    compra($cart, ['shipping_method_id' => $metodo]);
    $riga = Order::findById($cart);

    return (string) $riga['fulfillment_type'] === 'shipping'
        && (int) $riga['shipping_method_id'] === $metodo
        && (int) $riga['location_id'] === 0
        && (float) $riga['shipping_total'] === 8.0;
}));
```

- [ ] **Step 3: Lancia i test e guardali fallire**

Run: `php tests/integrazione/CheckoutSpedizioneTest.php`
Expected: i controlli del Task 2 restano ✓; ✗ su «sede che non è di ritiro…», «spedizione senza metodo…», «dove nessun metodo arriva». Gli altri possono già passare: è il comportamento di oggi che i nuovi controlli non devono rompere.

- [ ] **Step 4: Scrivi `Shipping::shippable`**

In `src/Support/Shipping/Shipping.php`, dopo `options()`:

```php
    /**
     * Il carrello ha almeno una riga da spedire? Non guarda la funzionalità né
     * la destinazione: un carrello di soli servizi risponde no.
     */
    public static function shippable(int $cartId): bool
    {
        $cart = self::order($cartId);

        return $cart !== [] && self::context($cart, self::itemsOf($cartId), 0.0)['lines'] !== [];
    }
```

- [ ] **Step 5: Scrivi `checkDelivery` e chiamalo in `create`**

In `create()`, subito dopo il blocco `if (OrderLines::goods($items) === []) { … }`:

```php
            self::checkDelivery($cartId, $type, $order, (string) ($data['source'] ?? 'user'));
```

Dopo `checkAddress()` aggiungi:

```php
    /**
     * Il ritiro vuole una sede di ritiro; la spedizione dal sito vuole un
     * metodo che arrivi all'indirizzo. Si guarda l'ordine già ricalcolato,
     * cioè quello che il cliente ha appena inviato: un'anteprima aperta in
     * un'altra scheda non conta più.
     *
     * Con le spedizioni spente non si controlla niente. Il metodo si chiede
     * solo al cliente online e solo se c'è qualcosa da spedire: un ordine
     * fatto dal sistema o di soli servizi passa senza.
     *
     * @param array<string, mixed> $order
     */
    private static function checkDelivery(int $cartId, string $type, array $order, string $source): void
    {
        if (!Gestionale::feature('shipping')) {
            return;
        }

        if ($type === 'pickup') {
            if (PickupPoints::find((int) ($order['location_id'] ?? 0)) === null) {
                throw UserError::make('order.pickup_location_unavailable');
            }

            return;
        }

        if ($type !== 'shipping'
            || (string) ($order['channel'] ?? 'online') !== 'online'
            || $source !== 'user'
            || !Shipping::shippable($cartId)) {
            return;
        }

        $available = array_column(Shipping::options($cartId), 'method_id');

        if ($available === []) {
            throw UserError::make('order.shipping_unavailable');
        }

        if (!in_array((int) ($order['shipping_method_id'] ?? 0), $available, true)) {
            throw UserError::make('order.shipping_method_required');
        }
    }
```

In `lang/it/gestionale.json`, in `errors.order`, dopo `address_incomplete` (aggiungi la virgola):

```json
                "address_incomplete": "Per la spedizione servono almeno la città e l'indirizzo.",
                "pickup_location_unavailable": "La sede di ritiro scelta non è disponibile: scegline un'altra.",
                "shipping_method_required": "Scegli come spedire l'ordine.",
                "shipping_unavailable": "Non spediamo all'indirizzo indicato: cambialo o scegli il ritiro in sede."
```

- [ ] **Step 6: Lancia i test nuovi e guardali passare**

Run: `php tests/integrazione/CheckoutSpedizioneTest.php`
Expected: tutti ✓.

- [ ] **Step 7: Sistema i test che compravano senza metodo**

`tests/integrazione/CheckoutTest.php`, in `prova()`, prima di `$esito = $corpo();`:

```php
            // Qui si prova il checkout senza spedizione: la sede e il metodo hanno i loro test.
            spegniFunzionalita(['shipping']);
```

`tests/integrazione/CouponContemporaneitaTest.php`, subito dopo `$prodotto = articoloConGiacenza(10, 'TST-CPGARA-'…);`:

```php
    // Un articolo da non spedire: la gara è sul coupon, non sul metodo di spedizione.
    ProductModel::update(['requires_shipping' => 'false'], modelloDi($prodotto));
```

`tests/integrazione/ShipmentsPickupTest.php`: `carrelloConContrassegno` prende l'articolo da fuori e il test del contrassegno usa una sede vera per il ritiro:

```php
/** Un listino con il contrassegno a 3,50 € e un carrello che lo sceglie. */
function carrelloConContrassegno(string $modo, int $sedeId, ?int $prodotto = null): int
{
    accendiFunzionalita(['orders', 'shipping']);
    $it = zona('Italia', [['IT', '']]);
    $metodo = metodo('Standard');
    listino($metodo, $it, [[5, 8.0], [20, 15.0]], ['cod_fee' => '3.50']);
    $cart = carrello([[$prodotto ?? articolo(2.0, 10.0), 1]]);
    Order::update(['shipping_method_id' => $metodo, 'fulfillment_type' => $modo, 'location_id' => $sedeId], $cart);

    return $cart;
}
```

e nel test «con la consegna il contrassegno porta la commissione del listino…» sostituisci le prime righe con:

```php
    $consegna = contrassegno(carrelloConContrassegno('shipping', 0), 'shipping', 0);
    // Il ritiro vuole una sede di ritiro con la merce.
    $sede = sede();
    $prodotto = articolo(2.0, 10.0);
    giacenzaIn($prodotto, $sede, 5);
    $ritiro = contrassegno(carrelloConContrassegno('pickup', $sede, $prodotto), 'pickup', $sede);
```

lasciando invariati `$commissioni` e il `return`.

- [ ] **Step 8: Lancia i file toccati**

Run: `php tests/integrazione/CheckoutTest.php; php tests/integrazione/CouponContemporaneitaTest.php; php tests/integrazione/ShipmentsPickupTest.php; php tests/integrazione/CouponsTest.php; php tests/integrazione/CartShippingTest.php`
Expected: tutti ✓ tranne il noto «una sede di ritiro chiusa si rifiuta» in `ShipmentsPickupTest`.

- [ ] **Step 9: Lancia tutta la suite**

Run: `php tests/run.php > /tmp/suite.txt 2>&1; tail -40 /tmp/suite.txt`
Expected: rossi solo `CatalogDemoTest` e «una sede di ritiro chiusa si rifiuta». Un rosso nuovo in un file che chiama `Checkout::place` con un articolo spedibile vuol dire che compra senza metodo: si sistema come al passo 7.

- [ ] **Step 10: Commit**

```bash
git add src/Support/Orders/Checkout.php src/Support/Shipping/Shipping.php lang/it/gestionale.json tests/integrazione/supporto/spedizioni.php tests/integrazione/CheckoutSpedizioneTest.php tests/integrazione/CheckoutTest.php tests/integrazione/CouponContemporaneitaTest.php tests/integrazione/ShipmentsPickupTest.php
git commit -m "E1c D5: place rifiuta il ritiro senza sede di ritiro e la spedizione senza metodo

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Guida, CHANGELOG e TODO

**Files:**
- Modify: `docs/user/spedizioni-spedire.md:~64`, `docs/user/vendite-ordini.md`, `CHANGELOG.md`, `TODO.md:~255`

- [ ] **Step 1: Guida delle spedizioni**

In `docs/user/spedizioni-spedire.md`, sezione «Ritiro in sede», sostituisci «La sede deve essere attiva, di ritiro e aperta in quel momento.» con:

```markdown
La sede deve essere attiva, di ritiro e aperta in quel momento. Al checkout invece
l'orario non conta: il cliente può scegliere il ritiro anche di sera, e la merce
viene prenotata nel magazzino di quella sede.
```

- [ ] **Step 2: Guida degli ordini**

In `docs/user/vendite-ordini.md`, in coda alla parte che parla degli ordini dal sito (se non c'è, in fondo al file), aggiungi:

```markdown
## Il checkout del sito

Mentre il cliente compila, il riepilogo a lato si aggiorna: spese di spedizione,
commissione del pagamento e sconto del coupon. Se c'è un solo metodo di spedizione
per l'indirizzo, o una sola sede di ritiro, viene scelto da solo. Un coupon che non
vale più, o un metodo che non arriva al nuovo indirizzo, sparisce con un avviso.

All'invio l'ordine si ferma se il ritiro è in una sede che non è (più) di ritiro o
se la spedizione non ha un metodo che arrivi all'indirizzo. Gli ordini di soli
servizi e quelli con le spedizioni spente non chiedono il metodo.
```

- [ ] **Step 3: CHANGELOG**

In `CHANGELOG.md`, in coda a `### Aggiunto` della `0.1.0`:

```markdown
- Checkout con spedizione: `Checkout::preview()` scrive le scelte del cliente sul
  carrello e risponde con totali, metodi di spedizione, sedi di ritiro e pagamenti;
  `Checkout::place()` rifiuta il ritiro senza sede di ritiro
  (`order.pickup_location_unavailable`) e la spedizione dal sito senza metodo
  (`order.shipping_method_required`, `order.shipping_unavailable`). Le sedi di ritiro
  stanno in `PickupPoints`, usato anche dalle spedizioni.
```

- [ ] **Step 4: TODO**

In `TODO.md`, sotto la riga di `E1c`, aggiungi:

```markdown
    - [~] D5 checkout con spedizione, ritiro e coupon — [spec](docs/superpowers/specs/2026-10-06-checkout-con-spedizione-design.md); piano 1 gestionale ([piano](docs/superpowers/plans/2026-10-06-anteprima-del-checkout.md)) fatto: `Checkout::preview`, controlli di `place`, `PickupPoints`; piano 2 ecommerce (rotte `summary` e `coupon`, `checkout.js`) da scrivere
```

- [ ] **Step 5: Commit**

```bash
git add docs/user/spedizioni-spedire.md docs/user/vendite-ordini.md CHANGELOG.md TODO.md
git commit -m "E1c D5: guida, CHANGELOG e TODO dell'anteprima del checkout

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
