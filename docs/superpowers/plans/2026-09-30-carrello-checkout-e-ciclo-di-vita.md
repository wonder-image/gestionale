# Carrello, checkout e ciclo di vita — piano di realizzazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** portare un ordine dal carrello alla consegna — riga messa nel carrello, checkout che prenota la merce e apre il pagamento, conferma, annullamento, scadenza automatica delle prenotazioni non pagate — con le sei email che raccontano ogni passaggio.

**Architecture:** quattro servizi in `Support\Orders`, ognuno con un mestiere solo. `Cart` tiene la riga di `gst_orders` con `stage='cart'` e non prenota niente. `Checkout` è l'unico punto in cui un carrello diventa ordine: una transazione sola che ricalcola, prenota con `Allocation`, numera con `DocumentSequences` e apre il pagamento con `Ledger`. `Lifecycle` è l'unico punto in cui lo `status` di un ordine cambia: conferma, annulla, chiude. `Expiry` è il giro dello scheduler che libera le prenotazioni scadute e annulla chi non ha pagato, e per annullare passa da `Lifecycle` come tutti gli altri. Le email non le manda nessuno di loro direttamente: le compone e le spedisce `OrderNotifier`, che chi cambia stato chiama alla fine.

**Tech Stack:** PHP 8.2+ (in sviluppo 8.5.7 via Herd), framework Wonder Image (`wonder-image/app`), MySQL con `Wonder\Sql\Transaction`, harness dei test `tests/harness.php` con `php tests/run.php`. Nessuna libreria nuova.

**Spec:** `docs/superpowers/specs/2026-09-29-ordini-e-pagamenti-design.md` (§1 unità, §3 flussi, §5 test)

## Global Constraints

- Lingua: commenti, testi utente e nomi dei test in **italiano** con gli accenti corretti; classi, tabelle, colonne e chiavi in inglese.
- Prefisso delle tabelle `gst_`, namespace `Wonder\Plugin\Gestionale\`.
- I messaggi d'errore all'utente passano da `UserError::make('chiave', [...])` e la frase sta in `lang/it/gestionale.json` sotto `gestionale.errors.*`: `tests/ErrorKeysTest.php` cade se una chiave non ha la sua frase.
- Prenotazione e scarico passano **solo** da `Support\Stock\Allocation` (D61): nessuno chiama `Stock::apply()` da qui.
- Il `payment_status` di un ordine lo scrive **solo** `Ledger::sync()`: nessun `Order::update(['payment_status' => ...])` in questo piano.
- Ogni cambio di `status` è accompagnato da `StatusLogger::record(OrderStatusLog::class, ...)` con `source` fra `user`, `system`, `cron`, `webhook`, `api`.
- Il denaro si scrive con il punto e due decimali (`number_format($v, 2, '.', '')`), le quantità con tre.
- TDD: test rosso, implementazione minima, test verde, commit. Ogni task chiude con `php tests/run.php` verde per intero.
- I test d'integrazione girano dentro `Transaction::run()` annullata con `final class Annulla extends RuntimeException {}` e non lasciano righe dietro.

## Review Focus

- **Prodotto sparito sotto il carrello.** Un articolo cancellato o disattivato mentre è nel carrello: il ricalcolo non deve esplodere né mettere in silenzio il prezzo a zero. La riga va tolta e il cliente avvisato. *(test nel Task 2)*
- **Checkout di un carrello vuoto o a quantità zero.** Nessun ordine numerato, nessun pagamento aperto, nessuna prenotazione: il carrello resta com'è. *(test nel Task 6)*
- **Unione di due carrelli oltre la disponibilità.** Due pezzi nel carrello dell'ospite più due in quello dell'account, con tre soli pezzi a magazzino: l'unione non può scrivere quattro. *(test nel Task 2)*
- **Metodo di pagamento sparito o spento.** Un `payment_method_id` che non esiste più, o `active='false'`: il checkout rifiuta invece di aprire un pagamento orfano. *(test nel Task 6)*
- **Scadenza che ripassa su un ordine già chiuso.** Il giro dello scheduler che incontra un ordine già annullato o già confermato non deve annullarlo di nuovo né mandare una seconda email. *(test nel Task 7)*

---

## File Structure

**Nuovi:**

| File | Responsabilità |
|---|---|
| `src/Support/Orders/Cart.php` | righe del carrello: apri, aggiungi, cambia quantità, togli, ricalcola, unisci |
| `src/Support/Orders/PaymentTiming.php` | quando si paga con quel metodo, e quanto dura la prenotazione |
| `src/Support/Orders/OrderEmail.php` | oggetto e corpo delle sei email (puro: prende array, rende una vista) |
| `src/Support/Orders/OrderNotifier.php` | destinatari e invio delle sei email |
| `src/Support/Orders/Lifecycle.php` | conferma, annulla, chiudi: l'unico che scrive `status` |
| `src/Support/Orders/Checkout.php` | da carrello a ordine, in una transazione sola |
| `src/Support/Orders/Expiry.php` | prenotazioni scadute, promemoria, annullamento automatico |
| `src/Scheduler/ExpiryTask.php` | il giro orario dello scheduler |
| `view/emails/order.php` | vista delle quattro email al cliente |
| `view/emails/order-merchant.php` | vista delle due email al commerciante |
| `tests/PaymentTimingTest.php` | test unitario puro della scadenza |
| `tests/integrazione/CartTest.php` | carrello |
| `tests/integrazione/OrderEmailsTest.php` | composizione e invio |
| `tests/integrazione/LifecycleTest.php` | conferma e annullamento |
| `tests/integrazione/CheckoutTest.php` | checkout |
| `tests/integrazione/ExpiryTest.php` | scadenze |

**Modificati:**

| File | Modifica |
|---|---|
| `src/Models/Payments/PaymentMethod.php` | colonna `timing` (`immediate`/`deferred`/`on_delivery`) |
| `src/Gestionale.php` | `tasks()` restituisce anche `ExpiryTask` |
| `tests/TasksTest.php` | le chiavi dei task diventano tre |
| `lang/it/gestionale.json` | frasi d'errore nuove e testi delle sei email |
| `TODO.md` | riga del Piano 3 segnata fatta |

---

### Task 1: Il carrello che si apre e accoglie una riga

**Files:**
- Create: `src/Support/Orders/Cart.php`
- Create: `tests/integrazione/CartTest.php`
- Modify: `lang/it/gestionale.json`

**Interfaces:**
- Consumes: `Support\Pricing\LinePrice::of(array): array`, `Support\Pricing\OrderTotals::of(array $lines, array $context): array`, `Support\Stock\Levels::of(int): array{quantity,reserved,available}`, `Support\Stock\Stock::allowsBackorder(array $product): bool`, `Support\Tax\TaxResolver::resolve(array $rules, string $country, string $customerType, int $taxCategoryId, int $fallbackTaxId): int`, `Support\Catalog\Code::make()`, `Support\Codes::ORDER`.
- Produces:
  - `Cart::open(array $context): array` — la riga di `gst_orders` con `stage='cart'`. Contesto: `cart_token`, `customer_id`, `channel`, `currency`, `email`.
  - `Cart::add(int $cartId, array $line): array{order: array<string,mixed>, items: list<array<string,mixed>>}` — riga: `product_id`, `quantity`, `customization`, `customization_surcharge`.
  - `Cart::recalculate(int $cartId): array{order: array<string,mixed>, items: list<array<string,mixed>>, removed: list<string>}`
  - `Cart::contents(int $cartId): array{order: array<string,mixed>, items: list<array<string,mixed>>}`

- [ ] **Step 1: Scrivi il test rosso**

Crea `tests/integrazione/CartTest.php`:

```php
<?php
/** php tests/integrazione/CartTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Sql\Transaction;

/** Serve a buttare via tutto quello che la prova scrive. */
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

check('un carrello nuovo nasce con il gettone dell\'ospite', function () {
    return prova(static function (): bool {
        $carrello = Cart::open(['cart_token' => 'tok-'.uniqid()]);

        return (int) $carrello['id'] > 0
            && $carrello['stage'] === 'cart'
            && $carrello['status'] === 'draft'
            && trim((string) $carrello['last_activity_at']) !== '';
    });
});

check('lo stesso gettone ritrova il carrello di prima', function () {
    return prova(static function (): bool {
        $gettone = 'tok-'.uniqid();

        return (int) Cart::open(['cart_token' => $gettone])['id']
            === (int) Cart::open(['cart_token' => $gettone])['id'];
    });
});

check('la riga aggiunta porta prezzo, quantità e totale', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        Product::update(['price' => '20.00'], $prodotto);

        $carrello = Cart::open(['cart_token' => 'tok-'.uniqid()]);
        $esito = Cart::add((int) $carrello['id'], ['product_id' => $prodotto, 'quantity' => 2]);
        $riga = $esito['items'][0] ?? [];

        return count($esito['items']) === 1
            && (string) $riga['unit_price'] === '20.00'
            && (string) $riga['line_total'] === '40.00'
            && (string) $esito['order']['products_total'] === '40.00';
    });
});

check('due volte lo stesso articolo fanno una riga sola', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        Product::update(['price' => '20.00'], $prodotto);

        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 1]);
        $esito = Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 2]);

        return count($esito['items']) === 1
            && (float) $esito['items'][0]['quantity'] === 3.0
            && (string) $esito['order']['products_total'] === '60.00';
    });
});

check('più pezzi di quanti ce ne sono: rifiutato, e dice quanti restano', function () {
    return prova(static function (): string {
        $prodotto = articoloConGiacenza(3, 'TST-CART-'.substr((string) microtime(true), -6));
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];

        try {
            Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 4]);
        } catch (UserError $errore) {
            return $errore->getMessage();
        }

        return 'nessun rifiuto';
    }) !== 'nessun rifiuto';
});

check('quantità zero: rifiutata', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(3, 'TST-CART-'.substr((string) microtime(true), -6));
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];

        try {
            Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 0]);
        } catch (UserError) {
            return true;
        }

        return false;
    });
});

summary();
```

- [ ] **Step 2: Guarda il test fallire**

Run: `php tests/integrazione/CartTest.php`
Expected: FAIL — `Class "Wonder\Plugin\Gestionale\Support\Orders\Cart" not found`

- [ ] **Step 3: Scrivi `Cart`**

Crea `src/Support/Orders/Cart.php`. Il corpo, per intero:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Models\Tax\Tax;
use Wonder\Plugin\Gestionale\Models\Tax\TaxRule;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Pricing\LinePrice;
use Wonder\Plugin\Gestionale\Support\Pricing\OrderTotals;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Plugin\Gestionale\Support\Tax\TaxResolver;
use Wonder\Sql\Transaction;

/**
 * Il carrello: una riga di `gst_orders` con `stage='cart'` e le sue righe.
 *
 * **Non prenota niente.** La merce resta libera per tutti finché qualcuno non
 * arriva al checkout: tenerla impegnata da qui vorrebbe dire un magazzino
 * bloccato da carrelli abbandonati. La disponibilità si guarda lo stesso —
 * all'aggiunta, per non promettere quello che non c'è — ma è una fotografia,
 * non un impegno, e il checkout la rifà con la riga bloccata.
 *
 * Ogni operazione ricalcola prezzi e totali da capo: il prezzo di listino può
 * essere cambiato da quando la riga è entrata, e il carrello deve mostrare
 * quello che il cliente pagherà adesso, non quello che avrebbe pagato ieri.
 */
final class Cart
{
    /**
     * Il carrello dell'ospite o del cliente: quello che c'è, o uno nuovo.
     *
     * @param array{cart_token?: string, customer_id?: int, channel?: string, currency?: string, email?: string} $context
     * @return array<string, mixed>
     */
    public static function open(array $context): array
    {
        return Transaction::run(static function () use ($context): array {
            $customerId = (int) ($context['customer_id'] ?? 0);
            $token = trim((string) ($context['cart_token'] ?? ''));
            $found = null;

            if ($customerId > 0) {
                $found = Order::find(
                    ['stage' => 'cart', 'customer_id' => $customerId, 'deleted' => 'false'],
                    1
                );
            }

            if (!is_array($found) && $token !== '') {
                $found = Order::find(['stage' => 'cart', 'cart_token' => $token, 'deleted' => 'false'], 1);
            }

            if (is_array($found) && isset($found['id'])) {
                return self::touch((int) $found['id']);
            }

            $settings = Setting::current();
            $channel = (string) ($context['channel'] ?? 'online');
            $created = Order::create([
                'code' => Code::make(Order::class, Codes::ORDER),
                'stage' => 'cart',
                'channel' => in_array($channel, Order::CHANNELS, true) ? $channel : 'online',
                'status' => 'draft',
                'payment_status' => 'unpaid',
                'fulfillment_status' => 'unfulfilled',
                'customer_id' => $customerId,
                'email' => (string) ($context['email'] ?? ''),
                'currency' => (string) ($context['currency'] ?? 'EUR'),
                'prices_include_tax' => (string) ($settings['catalog_prices_include_tax'] ?? 'true'),
                'cart_token' => $token !== '' ? $token : bin2hex(random_bytes(16)),
                'last_activity_at' => date('Y-m-d H:i:s'),
            ]);

            if (($created->success ?? false) !== true) {
                throw UserError::make('cart.not_opened');
            }

            $row = Order::findById((int) ($created->insert_id ?? 0));

            return is_array($row) ? $row : [];
        });
    }

    /**
     * Mette un articolo nel carrello, o ne aumenta la quantità.
     *
     * @param array{product_id: int, quantity?: float, customization?: array<string, mixed>, customization_surcharge?: float} $line
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>, removed: list<string>}
     */
    public static function add(int $cartId, array $line): array
    {
        return Transaction::run(static function () use ($cartId, $line): array {
            $cart = self::cart($cartId);
            $quantity = round((float) ($line['quantity'] ?? 1), 3);

            if ($quantity <= 0) {
                throw UserError::make('order.zero_quantity');
            }

            $productId = (int) ($line['product_id'] ?? 0);
            $product = self::product($productId);
            $customization = $line['customization'] ?? [];
            $signature = self::signature($customization);
            $existing = self::itemLike($cartId, $productId, $signature);
            $wanted = round($quantity + (float) ($existing['quantity'] ?? 0), 3);

            self::assertAvailable($product, $wanted);

            if (is_array($existing)) {
                OrderItem::update(['quantity' => self::number($wanted)], (int) $existing['id']);
            } else {
                $model = ProductModel::findById((int) $product['product_model_id']);
                OrderItem::create([
                    'order_id' => $cartId,
                    'type' => 'product',
                    'product_id' => $productId,
                    'position' => self::nextPosition($cartId),
                    'sku' => (string) $product['sku'],
                    'name' => (string) $product['name'],
                    'unit' => (string) (is_array($model) ? ($model['unit'] ?? 'pz') : 'pz'),
                    'quantity' => self::number($wanted),
                    'tax_category_id' => (int) (is_array($model) ? ($model['tax_category_id'] ?? 0) : 0),
                    'customization' => $signature !== '' ? $signature : '',
                    'customization_surcharge' => self::money((float) ($line['customization_surcharge'] ?? 0)),
                ]);
            }

            unset($cart);

            return self::recalculate($cartId);
        });
    }

    /**
     * Riprezza tutte le righe e riscrive i totali.
     *
     * Le righe di un prodotto che non c'è più — cancellato o spento mentre
     * stava nel carrello — escono, e chi chiama le trova elencate in
     * `removed`: sparire in silenzio vorrebbe dire un totale che cala da solo.
     *
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>, removed: list<string>}
     */
    public static function recalculate(int $cartId): array
    {
        return Transaction::run(static function () use ($cartId): array {
            $cart = self::cart($cartId);
            $settings = Setting::current();
            $rules = self::rows(TaxRule::find(['deleted' => 'false']));
            $fallback = (int) ($settings['fallback_tax_id'] ?? 0);
            $country = strtoupper(trim((string) ($cart['billing_country'] ?? ''))) ?: 'IT';
            $customerType = trim((string) ($cart['billing_pi'] ?? '')) !== '' ? 'business' : 'private';
            $removed = [];
            $computed = [];

            foreach (self::items($cartId) as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $product = $productId > 0 ? Product::findById($productId) : null;

                if ((string) $item['type'] === 'product'
                    && (!is_array($product) || ($product['active'] ?? 'false') !== 'true')) {
                    OrderItem::delete((int) $item['id']);
                    $removed[] = (string) $item['name'];

                    continue;
                }

                $price = LinePrice::of([
                    'quantity' => $item['quantity'] ?? 0,
                    'price' => is_array($product) ? ($product['price'] ?? 0) : ($item['list_price'] ?? 0),
                    'sale_price' => is_array($product) ? ($product['sale_price'] ?? 0) : 0,
                    'manual_unit_price' => (string) $item['price_source'] === 'manual'
                        ? ($item['unit_price'] ?? '')
                        : '',
                    'discount_type' => $item['discount_type'] ?? 'none',
                    'discount_value' => $item['discount_value'] ?? 0,
                    'customization_surcharge' => $item['customization_surcharge'] ?? 0,
                ]);

                $taxId = TaxResolver::resolve(
                    $rules,
                    $country,
                    $customerType,
                    (int) ($item['tax_category_id'] ?? 0),
                    $fallback
                );
                $tax = $taxId > 0 ? Tax::findById($taxId) : null;

                $computed[] = $item['id'] ? [
                    'id' => (int) $item['id'],
                    'type' => (string) $item['type'],
                    'weight' => is_array($product) ? (float) ($product['weight'] ?? 0) : 0.0,
                    'tax_id' => $taxId,
                    'tax_rate' => is_array($tax) ? (float) ($tax['rate'] ?? 0) : 0.0,
                    'tax_nature' => is_array($tax) ? (string) ($tax['nature'] ?? '') : '',
                ] + $price : [];
            }

            $totals = OrderTotals::of($computed, [
                'prices_include_tax' => (string) ($cart['prices_include_tax'] ?? 'true') === 'true',
                'discount_type' => (string) ($cart['manual_discount_type'] ?? 'none'),
                'discount_value' => (float) ($cart['manual_discount_value'] ?? 0),
            ]);

            foreach ($totals['lines'] as $line) {
                OrderItem::update([
                    'list_price' => $line['list_price'],
                    'unit_price' => $line['unit_price'],
                    'price_source' => $line['price_source'],
                    'discount_type' => $line['discount_type'],
                    'discount_value' => $line['discount_value'],
                    'order_discount_amount' => $line['order_discount_amount'],
                    'quantity' => $line['quantity'],
                    'tax_id' => (int) $line['tax_id'],
                    'tax_rate' => number_format((float) $line['tax_rate'], 2, '.', ''),
                    'tax_nature' => (string) $line['tax_nature'],
                    'line_total' => $line['line_total'],
                ], (int) $line['id']);
            }

            Order::update([
                'products_total' => $totals['products_total'],
                'discount_total' => $totals['discount_total'],
                'shipping_total' => $totals['shipping_total'],
                'fees_total' => $totals['fees_total'],
                'taxable_total' => $totals['taxable_total'],
                'tax_total' => $totals['tax_total'],
                'total' => $totals['total'],
                'total_weight' => $totals['total_weight'],
                'last_activity_at' => date('Y-m-d H:i:s'),
            ], $cartId);

            return self::contents($cartId) + ['removed' => $removed];
        });
    }

    /**
     * L'ordine e le sue righe, come stanno adesso.
     *
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>}
     */
    public static function contents(int $cartId): array
    {
        $order = Order::findById($cartId);

        return [
            'order' => is_array($order) ? $order : [],
            'items' => self::items($cartId),
        ];
    }

    /**
     * La riga del carrello, bloccata, con la garanzia che sia un carrello.
     *
     * @return array<string, mixed>
     */
    private static function cart(int $cartId): array
    {
        $row = Order::findForUpdate(['id' => $cartId], 1);

        if (!is_array($row) || $row === []) {
            throw UserError::make('cart.not_a_cart');
        }

        if ((string) $row['stage'] !== 'cart') {
            // Un ordine già fatto non si modifica dal carrello: le sue righe
            // hanno merce prenotata dietro.
            throw UserError::make('cart.not_a_cart');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private static function product(int $productId): array
    {
        $product = $productId > 0 ? Product::findById($productId) : null;

        if (!is_array($product) || ($product['active'] ?? 'false') !== 'true') {
            throw UserError::make('cart.product_unavailable');
        }

        return $product;
    }

    /**
     * Quello che c'è basta? Con la vendita scoperta accesa, sempre.
     *
     * @param array<string, mixed> $product
     */
    private static function assertAvailable(array $product, float $wanted): void
    {
        if (Stock::allowsBackorder($product)) {
            return;
        }

        $available = Levels::of((int) $product['id'])['available'];

        if ($wanted > $available) {
            throw UserError::make('cart.not_enough_stock', [
                'name' => (string) $product['name'],
                'available' => rtrim(rtrim(number_format(max(0.0, $available), 3, ',', ''), '0'), ','),
            ]);
        }
    }

    /**
     * La riga uguale a quella che sta entrando, se c'è.
     *
     * @return array<string, mixed>|null
     */
    private static function itemLike(int $cartId, int $productId, string $signature): ?array
    {
        foreach (self::items($cartId) as $item) {
            if ((string) $item['type'] === 'product'
                && (int) $item['product_id'] === $productId
                && (string) ($item['customization'] ?? '') === $signature) {
                return $item;
            }
        }

        return null;
    }

    /** Due personalizzazioni uguali devono dare la stessa stringa. */
    private static function signature(mixed $customization): string
    {
        if (!is_array($customization) || $customization === []) {
            return '';
        }

        ksort($customization);

        return (string) json_encode($customization, JSON_UNESCAPED_UNICODE);
    }

    private static function nextPosition(int $cartId): int
    {
        return count(self::items($cartId)) + 1;
    }

    /** @return list<array<string, mixed>> */
    private static function items(int $cartId): array
    {
        return self::rows(OrderItem::find(['order_id' => $cartId, 'deleted' => 'false']));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $result): array
    {
        if (!is_array($result) || $result === []) {
            return [];
        }

        return isset($result['id']) ? [$result] : array_values(array_filter($result, 'is_array'));
    }

    private static function number(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
```

- [ ] **Step 4: Aggiungi le frasi d'errore**

In `lang/it/gestionale.json`, dentro `gestionale.errors`, aggiungi il ramo `cart`:

```json
"cart": {
    "not_opened": "Non è stato possibile aprire il carrello. Riprova.",
    "not_a_cart": "Questo ordine non è più un carrello e non si può modificare.",
    "product_unavailable": "Questo articolo non è più in vendita.",
    "not_enough_stock": "Di «:name» restano :available pezzi."
}
```

Se `gestionale.errors.order` esiste già, lascialo dov'è: `cart` è un fratello, non un figlio.

- [ ] **Step 5: Guarda il test passare**

Run: `php tests/integrazione/CartTest.php`
Expected: PASS, 6 su 6

- [ ] **Step 6: Tutta la suite**

Run: `php tests/run.php`
Expected: tutti verdi, zero falliti (compreso `ErrorKeysTest`, che ora trova le quattro frasi nuove)

- [ ] **Step 7: Commit**

```bash
git add src/Support/Orders/Cart.php tests/integrazione/CartTest.php lang/it/gestionale.json
git commit -m "$(cat <<'EOF'
Apre il carrello e ci mette dentro la prima riga

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Cambiare quantità, togliere, unire due carrelli

**Files:**
- Modify: `src/Support/Orders/Cart.php`
- Modify: `tests/integrazione/CartTest.php`
- Modify: `lang/it/gestionale.json`

**Interfaces:**
- Consumes: quanto prodotto dal Task 1.
- Produces:
  - `Cart::setQuantity(int $cartId, int $itemId, float $quantity): array{order,items,removed}` — quantità a zero significa togliere la riga.
  - `Cart::remove(int $cartId, int $itemId): array{order,items,removed}`
  - `Cart::merge(int $guestCartId, int $targetCartId): array{order,items,removed}` — svuota il primo dentro il secondo e lo cancella.

- [ ] **Step 1: Scrivi i test rossi**

Aggiungi in `tests/integrazione/CartTest.php`, **prima** di `summary();`:

```php
check('la quantità cambiata rifà il totale', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        Product::update(['price' => '15.00'], $prodotto);

        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $esito = Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 1]);
        $riga = (int) $esito['items'][0]['id'];
        $dopo = Cart::setQuantity($carrello, $riga, 4);

        return (float) $dopo['items'][0]['quantity'] === 4.0
            && (string) $dopo['order']['products_total'] === '60.00';
    });
});

check('la quantità a zero toglie la riga', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $esito = Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 1]);
        $dopo = Cart::setQuantity($carrello, (int) $esito['items'][0]['id'], 0);

        return $dopo['items'] === [] && (string) $dopo['order']['products_total'] === '0.00';
    });
});

check('la riga tolta non torna', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $esito = Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 2]);

        return Cart::remove($carrello, (int) $esito['items'][0]['id'])['items'] === [];
    });
});

check('la riga di un altro carrello non si tocca', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        $mio = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $altrui = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $esito = Cart::add($altrui, ['product_id' => $prodotto, 'quantity' => 1]);

        try {
            Cart::remove($mio, (int) $esito['items'][0]['id']);
        } catch (UserError) {
            return true;
        }

        return false;
    });
});

check('due carrelli uniti sommano le righe uguali', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        Product::update(['price' => '10.00'], $prodotto);

        $ospite = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $cliente = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        Cart::add($ospite, ['product_id' => $prodotto, 'quantity' => 2]);
        Cart::add($cliente, ['product_id' => $prodotto, 'quantity' => 1]);

        $unito = Cart::merge($ospite, $cliente);

        return count($unito['items']) === 1
            && (float) $unito['items'][0]['quantity'] === 3.0
            && (string) $unito['order']['products_total'] === '30.00'
            && !is_array(Wonder\Plugin\Gestionale\Models\Sales\Order::find(
                ['id' => $ospite, 'deleted' => 'false'],
                1
            ));
    });
});

check('l\'unione non scrive più pezzi di quanti ce ne sono', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(3, 'TST-CART-'.substr((string) microtime(true), -6));
        $ospite = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $cliente = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        Cart::add($ospite, ['product_id' => $prodotto, 'quantity' => 2]);
        Cart::add($cliente, ['product_id' => $prodotto, 'quantity' => 2]);

        // Quattro pezzi chiesti, tre sul banco: l'unione si ferma a tre invece
        // di scrivere una quantità che il checkout non potrebbe prenotare.
        return (float) Cart::merge($ospite, $cliente)['items'][0]['quantity'] === 3.0;
    });
});

check('l\'articolo spento sotto il carrello esce, e si sa', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 1]);

        Product::update(['active' => 'false'], $prodotto);
        $dopo = Cart::recalculate($carrello);

        return $dopo['items'] === []
            && count($dopo['removed']) === 1
            && (string) $dopo['order']['total'] === '0.00';
    });
});
```

- [ ] **Step 2: Guarda i test fallire**

Run: `php tests/integrazione/CartTest.php`
Expected: FAIL — `Call to undefined method ...::setQuantity()`

- [ ] **Step 3: Aggiungi i tre metodi**

In `src/Support/Orders/Cart.php`, dopo `recalculate()`:

```php
    /**
     * Cambia la quantità di una riga. Zero vuol dire toglierla.
     *
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>, removed: list<string>}
     */
    public static function setQuantity(int $cartId, int $itemId, float $quantity): array
    {
        return Transaction::run(static function () use ($cartId, $itemId, $quantity): array {
            self::cart($cartId);
            $item = self::item($cartId, $itemId);
            $quantity = round($quantity, 3);

            if ($quantity <= 0) {
                OrderItem::delete($itemId);

                return self::recalculate($cartId);
            }

            if ((int) ($item['product_id'] ?? 0) > 0) {
                self::assertAvailable(self::product((int) $item['product_id']), $quantity);
            }

            OrderItem::update(['quantity' => self::number($quantity)], $itemId);

            return self::recalculate($cartId);
        });
    }

    /**
     * Toglie una riga dal carrello.
     *
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>, removed: list<string>}
     */
    public static function remove(int $cartId, int $itemId): array
    {
        return Transaction::run(static function () use ($cartId, $itemId): array {
            self::cart($cartId);
            self::item($cartId, $itemId);
            OrderItem::delete($itemId);

            return self::recalculate($cartId);
        });
    }

    /**
     * Versa il carrello dell'ospite in quello di chi ha appena fatto l'accesso.
     *
     * Le righe uguali si sommano, ma non oltre quello che c'è: due pezzi più
     * due, con tre sul banco, fanno tre. Scriverne quattro sposterebbe il
     * rifiuto al checkout, dove il cliente ha già messo l'indirizzo.
     *
     * @return array{order: array<string, mixed>, items: list<array<string, mixed>>, removed: list<string>}
     */
    public static function merge(int $guestCartId, int $targetCartId): array
    {
        return Transaction::run(static function () use ($guestCartId, $targetCartId): array {
            if ($guestCartId === $targetCartId) {
                return self::recalculate($targetCartId);
            }

            self::cart($targetCartId);
            self::cart($guestCartId);

            foreach (self::items($guestCartId) as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $signature = (string) ($item['customization'] ?? '');
                $existing = self::itemLike($targetCartId, $productId, $signature);
                $wanted = round((float) $item['quantity'] + (float) ($existing['quantity'] ?? 0), 3);
                $wanted = self::capped($productId, $wanted);

                if ($wanted <= 0) {
                    continue;
                }

                if (is_array($existing)) {
                    OrderItem::update(['quantity' => self::number($wanted)], (int) $existing['id']);

                    continue;
                }

                OrderItem::update([
                    'order_id' => $targetCartId,
                    'position' => self::nextPosition($targetCartId),
                    'quantity' => self::number($wanted),
                ], (int) $item['id']);
            }

            // Quello dell'ospite ha finito il suo mestiere: se restasse, il
            // prossimo accesso con lo stesso gettone lo ritroverebbe vuoto e
            // sembrerebbe che la roba sia sparita.
            Order::delete($guestCartId);

            return self::recalculate($targetCartId);
        });
    }
```

E in fondo, fra gli arnesi privati:

```php
    /**
     * La riga, ma solo se è di questo carrello.
     *
     * @return array<string, mixed>
     */
    private static function item(int $cartId, int $itemId): array
    {
        foreach (self::items($cartId) as $item) {
            if ((int) $item['id'] === $itemId) {
                return $item;
            }
        }

        throw UserError::make('cart.item_not_found');
    }

    /** La quantità chiesta, tagliata a quello che c'è davvero. */
    private static function capped(int $productId, float $wanted): float
    {
        if ($productId <= 0) {
            return $wanted;
        }

        $product = Product::findById($productId);

        if (!is_array($product) || ($product['active'] ?? 'false') !== 'true') {
            return 0.0;
        }

        if (Stock::allowsBackorder($product)) {
            return $wanted;
        }

        return min($wanted, max(0.0, Levels::of($productId)['available']));
    }
```

- [ ] **Step 4: Aggiungi la frase d'errore**

In `lang/it/gestionale.json`, dentro `gestionale.errors.cart`:

```json
"item_not_found": "Questa riga non è nel tuo carrello."
```

- [ ] **Step 5: Guarda i test passare**

Run: `php tests/integrazione/CartTest.php`
Expected: PASS, 13 su 13

- [ ] **Step 6: Tutta la suite**

Run: `php tests/run.php`
Expected: tutti verdi, zero falliti

- [ ] **Step 7: Commit**

```bash
git add src/Support/Orders/Cart.php tests/integrazione/CartTest.php lang/it/gestionale.json
git commit -m "$(cat <<'EOF'
Cambia, toglie e unisce le righe del carrello

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Quando si paga, e quanto dura la prenotazione

**Files:**
- Create: `src/Support/Orders/PaymentTiming.php`
- Create: `tests/PaymentTimingTest.php`
- Modify: `src/Models/Payments/PaymentMethod.php`

**Interfaces:**
- Consumes: `Models\System\Setting::current()` (`order_reservation_minutes`, `order_payment_wait_days`).
- Produces:
  - `PaymentTiming::IMMEDIATE = 'immediate'`, `PaymentTiming::DEFERRED = 'deferred'`, `PaymentTiming::ON_DELIVERY = 'on_delivery'`, `PaymentTiming::ALL` (lista dei tre).
  - `PaymentTiming::of(int $paymentMethodId): string` — legge la colonna `timing`; ripiego `immediate`.
  - `PaymentTiming::expiry(string $timing, int $minutes, int $days, ?string $at = null): ?string` — puro: la scadenza della prenotazione, `null` per `on_delivery`.
  - `PaymentTiming::reservationExpiry(string $timing, ?string $at = null): ?string` — come sopra, ma con minuti e giorni presi dalle impostazioni.
  - `PaymentMethod` ha la colonna `timing` enum con default `immediate`.

**Perché serve una colonna.** La spec dà tre comportamenti diversi al checkout — «`order_reservation_minutes` per i pagamenti immediati, `order_payment_wait_days` per il bonifico, nessuna scadenza per contrassegno/ritiro» — e alla conferma ne dà un quarto: «i metodi pagati dopo confermano subito col pagamento in attesa». Le colonne di `gst_payment_methods` (`provider`, `code`, `available_for`, `applies_*`) non dicono *quando* si paga: indovinarlo dal `provider` metterebbe il bonifico e il contrassegno nello stesso sacco (`manual`). La colonna lo dice una volta sola, e il commerciante la sceglie dalla scheda del metodo nel Piano 4.

> **Nota per chi aggiorna un'installazione esistente:** dopo questo task va rilanciato `PaymentMethod::createTable()` (o `forge update`), altrimenti la colonna `timing` non esiste e il checkout legge sempre il ripiego.

- [ ] **Step 1: Scrivi il test rosso**

Crea `tests/PaymentTimingTest.php`:

```php
<?php
/** php tests/PaymentTimingTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Gestionale\Support\Orders\PaymentTiming;

check('la carta impegna la merce per i minuti delle impostazioni', function () {
    return PaymentTiming::expiry(PaymentTiming::IMMEDIATE, 30, 7, '2026-09-30 10:00:00')
        === '2026-09-30 10:30:00';
});

check('il bonifico la impegna per i giorni delle impostazioni', function () {
    return PaymentTiming::expiry(PaymentTiming::DEFERRED, 30, 7, '2026-09-30 10:00:00')
        === '2026-10-07 10:00:00';
});

check('il contrassegno non scade mai', function () {
    return PaymentTiming::expiry(PaymentTiming::ON_DELIVERY, 30, 7, '2026-09-30 10:00:00') === null;
});

check('un modo di pagare che non conosciamo si comporta da immediato', function () {
    return PaymentTiming::expiry('chissà', 30, 7, '2026-09-30 10:00:00') === '2026-09-30 10:30:00';
});

check('minuti e giorni a zero non fanno una scadenza già passata', function () {
    // Zero nelle impostazioni vuol dire «non scade», non «scaduto un istante fa»:
    // con una scadenza nel passato la merce si libererebbe al primo giro.
    return PaymentTiming::expiry(PaymentTiming::IMMEDIATE, 0, 7, '2026-09-30 10:00:00') === null
        && PaymentTiming::expiry(PaymentTiming::DEFERRED, 30, 0, '2026-09-30 10:00:00') === null;
});

check('i tre modi di pagare sono quelli dichiarati', function () {
    return PaymentTiming::ALL === ['immediate', 'deferred', 'on_delivery'];
});

summary();
```

- [ ] **Step 2: Guarda il test fallire**

Run: `php tests/PaymentTimingTest.php`
Expected: FAIL — `Class "Wonder\Plugin\Gestionale\Support\Orders\PaymentTiming" not found`

- [ ] **Step 3: Scrivi `PaymentTiming`**

Crea `src/Support/Orders/PaymentTiming.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\System\Setting;

/**
 * Quando si paga con quel metodo, e quanto resta impegnata la merce.
 *
 * Tre modi, e tre attese diverse. Con la carta il denaro arriva subito o non
 * arriva: mezz'ora di prenotazione e via. Col bonifico il denaro arriva fra
 * giorni, e la merce deve aspettarlo. Col contrassegno o il ritiro in negozio
 * il denaro arriva alla consegna: la merce esce di magazzino appena l'ordine è
 * confermato, e non c'è niente da far scadere.
 */
final class PaymentTiming
{
    public const IMMEDIATE = 'immediate';
    public const DEFERRED = 'deferred';
    public const ON_DELIVERY = 'on_delivery';

    public const ALL = [self::IMMEDIATE, self::DEFERRED, self::ON_DELIVERY];

    /** Il modo di quel metodo di pagamento; senza metodo, il più stretto. */
    public static function of(int $paymentMethodId): string
    {
        $method = $paymentMethodId > 0 ? PaymentMethod::findById($paymentMethodId) : null;

        if (!is_array($method)) {
            return self::IMMEDIATE;
        }

        $timing = (string) ($method['timing'] ?? '');

        return in_array($timing, self::ALL, true) ? $timing : self::IMMEDIATE;
    }

    /** La scadenza della prenotazione, con minuti e giorni dalle impostazioni. */
    public static function reservationExpiry(string $timing, ?string $at = null): ?string
    {
        $settings = Setting::current();

        return self::expiry(
            $timing,
            (int) ($settings['order_reservation_minutes'] ?? 30),
            (int) ($settings['order_payment_wait_days'] ?? 7),
            $at
        );
    }

    /**
     * La scadenza, contata da `$at`. Pura.
     *
     * `null` vuol dire «non scade»: il contrassegno, e anche un'impostazione
     * messa a zero — che è il modo del commerciante di dire «non far scadere
     * niente», non «fai scadere subito».
     */
    public static function expiry(string $timing, int $minutes, int $days, ?string $at = null): ?string
    {
        $from = strtotime($at ?? date('Y-m-d H:i:s'));

        if ($from === false) {
            $from = time();
        }

        if ($timing === self::ON_DELIVERY) {
            return null;
        }

        if ($timing === self::DEFERRED) {
            return $days > 0 ? date('Y-m-d H:i:s', $from + $days * 86400) : null;
        }

        return $minutes > 0 ? date('Y-m-d H:i:s', $from + $minutes * 60) : null;
    }
}
```

- [ ] **Step 4: Guarda il test passare**

Run: `php tests/PaymentTimingTest.php`
Expected: PASS, 6 su 6

- [ ] **Step 5: Aggiungi la colonna al metodo di pagamento**

In `src/Models/Payments/PaymentMethod.php`, accanto alle altre costanti:

```php
    public const TIMINGS = ['immediate', 'deferred', 'on_delivery'];
```

In `tableSchema()`, subito dopo la colonna `provider`:

```php
            // Quando arriva il denaro: subito (carta), fra giorni (bonifico),
            // alla consegna (contrassegno, ritiro). Decide quanto resta
            // impegnata la merce e se l'ordine si conferma senza incasso.
            Column::key('timing')->enum(static::TIMINGS)->default('immediate'),
```

In `dataSchema()`, nello stesso punto:

```php
            Field::key('timing')->text()->sanitize(false),
```

- [ ] **Step 6: Rifai la tabella sul sito di prova e controlla la colonna**

```bash
php -r '$s="/Users/andreamarinoni/Developer/boilerplates/ecommerce-site"; chdir($s); $GLOBALS["ROOT"]=$s; require $s."/vendor/autoload.php"; require $s."/vendor/wonder-image/app/wonder-image.php"; Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod::createTable(); var_dump(array_key_exists("timing", (array) Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod::find(["deleted" => "false"], 1) ?: ["timing" => null]));'
```

Expected: `bool(true)`

- [ ] **Step 7: Tutta la suite**

Run: `php tests/run.php`
Expected: tutti verdi, zero falliti

- [ ] **Step 8: Commit**

```bash
git add src/Support/Orders/PaymentTiming.php tests/PaymentTimingTest.php src/Models/Payments/PaymentMethod.php
git commit -m "$(cat <<'EOF'
Dice quando si paga e quanto dura la prenotazione

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Le sei email

**Files:**
- Create: `src/Support/Orders/OrderEmail.php`
- Create: `src/Support/Orders/OrderNotifier.php`
- Create: `view/emails/order.php`
- Create: `view/emails/order-merchant.php`
- Create: `tests/integrazione/OrderEmailsTest.php`
- Modify: `lang/it/gestionale.json`

**Interfaces:**
- Consumes: `Support\Mail\Mailer::send(string $key, array $to, string $subject, string $body): array{status,to,sent,failed}`, `Mailer::useTransport(?callable)`, `Support\Mail\Recipients::parse(string): array{valid: list<string>}`, `Models\System\MerchantSetting::current()`, `Gestionale::viewPath(string): string`.
- Produces:
  - `OrderEmail::CUSTOMER_VIEW = 'emails/order.php'`, `OrderEmail::MERCHANT_VIEW = 'emails/order-merchant.php'`
  - `OrderEmail::KEYS = ['received','confirmed','reminder','cancelled','merchant_new','merchant_cancelled']`
  - `OrderEmail::compose(string $key, array $order, array $items, array $extra = []): array{subject: string, body: string}`
  - `OrderNotifier::send(string $key, int $orderId, array $extra = []): array{status: string, to: list<string>, sent: list<string>, failed: list<string>}`

**Due viste, sei testi.** Le sei email dicono la stessa cosa in sei momenti diversi: un titolo, una frase, il riepilogo dell'ordine. Sei file di vista sarebbero sei copie da tenere allineate; due viste — una per il cliente, una per il commerciante — con titolo e frase presi da `lang/it/gestionale.json` danno al sito un punto solo da sovrascrivere e al commerciante testi che può correggere senza toccare il PHP.

- [ ] **Step 1: Scrivi il test rosso**

Crea `tests/integrazione/OrderEmailsTest.php`:

```php
<?php
/** php tests/integrazione/OrderEmailsTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\OrderEmail;
use Wonder\Plugin\Gestionale\Support\Orders\OrderNotifier;
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

/** Un ordine con una riga, per avere qualcosa da raccontare. */
function ordineConRiga(): int
{
    $ordine = ordineDiProva(61.00);
    Order::update([
        'order_number' => date('Y').'/09'.substr((string) microtime(true), -4),
        'email' => 'cliente@example.test',
        'ordered_at' => date('Y-m-d H:i:s'),
    ], $ordine);
    OrderItem::create([
        'order_id' => $ordine,
        'type' => 'product',
        'position' => 1,
        'sku' => 'TST-MAIL',
        'name' => 'Crema da prova',
        'quantity' => '2.000',
        'unit_price' => '25.00',
        'line_total' => '50.00',
    ]);

    return $ordine;
}

check('le sei chiavi hanno tutte un oggetto e un corpo', function () {
    return prova(static function (): bool {
        $ordine = ordineConRiga();
        $riga = Order::findById($ordine);
        $righe = [['name' => 'Crema da prova', 'quantity' => '2.000', 'line_total' => '50.00']];

        foreach (OrderEmail::KEYS as $chiave) {
            $email = OrderEmail::compose($chiave, (array) $riga, $righe);

            if (trim($email['subject']) === '' || trim($email['body']) === '') {
                return false;
            }
        }

        return true;
    });
});

check('l\'email al cliente porta numero, riga e totale', function () {
    return prova(static function (): bool {
        $ordine = ordineConRiga();
        $riga = (array) Order::findById($ordine);
        $email = OrderEmail::compose('received', $riga, [
            ['name' => 'Crema da prova', 'quantity' => '2.000', 'line_total' => '50.00'],
        ]);

        return str_contains($email['subject'], (string) $riga['order_number'])
            && str_contains($email['body'], 'Crema da prova')
            && str_contains($email['body'], '61,00');
    });
});

check('le istruzioni del metodo finiscono nell\'email di ricevuta', function () {
    return prova(static function (): bool {
        $ordine = ordineConRiga();
        $email = OrderEmail::compose('received', (array) Order::findById($ordine), [], [
            'instructions' => 'Bonifico a IT00 X000 0000 0000',
        ]);

        return str_contains($email['body'], 'IT00 X000 0000 0000');
    });
});

check('il corpo non si fa scrivere dentro dal nome di un articolo', function () {
    return prova(static function (): bool {
        $ordine = ordineConRiga();
        $email = OrderEmail::compose('received', (array) Order::findById($ordine), [
            ['name' => '<script>alert(1)</script>', 'quantity' => '1.000', 'line_total' => '1.00'],
        ]);

        return !str_contains($email['body'], '<script>');
    });
});

check('l\'email al cliente parte e arriva al suo indirizzo', function () {
    return prova(static function (): bool {
        $partite = [];
        Mailer::useTransport(static function (string $to) use (&$partite): bool {
            $partite[] = $to;

            return true;
        });

        try {
            $esito = OrderNotifier::send('received', ordineConRiga());
        } finally {
            Mailer::useTransport(null);
        }

        return $esito['sent'] === ['cliente@example.test'] && $partite === ['cliente@example.test'];
    });
});

check('l\'email al commerciante va ai destinatari delle notifiche', function () {
    return prova(static function (): bool {
        $riga = MerchantSetting::current();
        MerchantSetting::update(
            ['merchant_notification_emails' => 'uno@example.test, due@example.test'],
            (int) ($riga['id'] ?? 1)
        );

        Mailer::useTransport(static fn (): bool => true);

        try {
            $esito = OrderNotifier::send('merchant_new', ordineConRiga());
        } finally {
            Mailer::useTransport(null);
        }

        return $esito['sent'] === ['uno@example.test', 'due@example.test'];
    });
});

check('senza indirizzo non si manda niente e non si esplode', function () {
    return prova(static function (): bool {
        $ordine = ordineConRiga();
        Order::update(['email' => ''], $ordine);

        return OrderNotifier::send('received', $ordine)['status'] === OrderNotifier::NO_RECIPIENTS;
    });
});

summary();
```

- [ ] **Step 2: Guarda il test fallire**

Run: `php tests/integrazione/OrderEmailsTest.php`
Expected: FAIL — `Class "Wonder\Plugin\Gestionale\Support\Orders\OrderEmail" not found`

- [ ] **Step 3: Scrivi i testi delle email**

In `lang/it/gestionale.json`, accanto a `gestionale.errors`, aggiungi `gestionale.emails.order`:

```json
"emails": {
    "order": {
        "received": {
            "subject": "Abbiamo ricevuto il tuo ordine :number",
            "title": "Grazie, l'ordine è arrivato",
            "intro": "Abbiamo registrato il tuo ordine :number del :date. Appena il pagamento risulta, te lo confermiamo."
        },
        "confirmed": {
            "subject": "Ordine :number confermato",
            "title": "Ordine confermato",
            "intro": "Il pagamento è a posto: prepariamo l'ordine :number."
        },
        "reminder": {
            "subject": "L'ordine :number aspetta il pagamento",
            "title": "Manca il pagamento",
            "intro": "L'ordine :number è ancora in attesa. Teniamo la merce da parte fino al :deadline, poi lo annulliamo."
        },
        "cancelled": {
            "subject": "Ordine :number annullato",
            "title": "Ordine annullato",
            "intro": "L'ordine :number è stato annullato. Se avevi già pagato, ti restituiamo l'importo."
        },
        "merchant_new": {
            "subject": "Nuovo ordine :number",
            "title": "È arrivato un ordine",
            "intro": "Ordine :number del :date, :total. Metodo di pagamento: :method."
        },
        "merchant_cancelled": {
            "subject": "Ordine :number annullato per mancato pagamento",
            "title": "Ordine annullato",
            "intro": "L'ordine :number è scaduto senza pagamento: la merce è tornata disponibile."
        }
    }
}
```

- [ ] **Step 4: Scrivi le due viste**

Crea `view/emails/order.php`:

```php
<?php
/**
 * L'email al cliente: titolo, una frase, il riepilogo.
 *
 * Il sito la sostituisce copiandola in `custom/modules/gestionale/view/`.
 *
 * @var string $title
 * @var string $intro
 * @var string $instructions
 * @var array<string, mixed> $order
 * @var list<array<string, mixed>> $items
 * @var string $url
 * @var callable(mixed): string $e
 * @var callable(mixed): string $money
 * @var callable(mixed): string $qty
 */
?>
<h2><?= $e($title) ?></h2>
<p><?= $e($intro) ?></p>
<?php if (trim($instructions) !== '') { ?>
    <p><strong><?= $e($instructions) ?></strong></p>
<?php } ?>
<?php if ($items !== []) { ?>
    <table cellpadding="6" cellspacing="0" border="0">
        <?php foreach ($items as $item) { ?>
            <tr>
                <td><?= $e($item['name'] ?? '') ?></td>
                <td align="right"><?= $e($qty($item['quantity'] ?? 0)) ?></td>
                <td align="right"><?= $e($money($item['line_total'] ?? 0)) ?> &euro;</td>
            </tr>
        <?php } ?>
    </table>
<?php } ?>
<p><strong>Totale: <?= $e($money($order['total'] ?? 0)) ?> &euro;</strong></p>
<?php if (trim($url) !== '') { ?>
    <p><a href="<?= $e($url) ?>"><?= $e($url) ?></a></p>
<?php } ?>
```

Crea `view/emails/order-merchant.php`:

```php
<?php
/**
 * L'email al commerciante: la stessa sostanza, senza convenevoli.
 *
 * @var string $title
 * @var string $intro
 * @var array<string, mixed> $order
 * @var list<array<string, mixed>> $items
 * @var string $url
 * @var callable(mixed): string $e
 * @var callable(mixed): string $money
 * @var callable(mixed): string $qty
 */
?>
<h2><?= $e($title) ?></h2>
<p><?= $e($intro) ?></p>
<?php if ($items !== []) { ?>
    <table cellpadding="6" cellspacing="0" border="0">
        <?php foreach ($items as $item) { ?>
            <tr>
                <td><?= $e($item['sku'] ?? '') ?></td>
                <td><?= $e($item['name'] ?? '') ?></td>
                <td align="right"><?= $e($qty($item['quantity'] ?? 0)) ?></td>
                <td align="right"><?= $e($money($item['line_total'] ?? 0)) ?> &euro;</td>
            </tr>
        <?php } ?>
    </table>
<?php } ?>
<p><strong>Totale: <?= $e($money($order['total'] ?? 0)) ?> &euro;</strong></p>
<?php if (trim($url) !== '') { ?>
    <p><a href="<?= $e($url) ?>"><?= $e($url) ?></a></p>
<?php } ?>
```

- [ ] **Step 5: Scrivi `OrderEmail`**

Crea `src/Support/Orders/OrderEmail.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * Oggetto e corpo delle sei email di un ordine.
 *
 * Quattro al cliente — ricevuto, confermato, promemoria, annullato — e due al
 * commerciante — ordine nuovo, ordine annullato per mancato pagamento. Sei
 * momenti, due viste: quello che cambia è il titolo e la frase, che stanno nel
 * file di lingua e si correggono senza toccare il codice.
 *
 * Classe pura: prende array, restituisce stringhe. Non legge il database e non
 * manda niente — di quello si occupa `OrderNotifier`.
 */
final class OrderEmail
{
    public const CUSTOMER_VIEW = 'emails/order.php';
    public const MERCHANT_VIEW = 'emails/order-merchant.php';

    public const KEYS = ['received', 'confirmed', 'reminder', 'cancelled', 'merchant_new', 'merchant_cancelled'];

    /** Quelle che vanno al commerciante, non al cliente. */
    public const MERCHANT_KEYS = ['merchant_new', 'merchant_cancelled'];

    /**
     * @param array<string, mixed> $order riga di `gst_orders`
     * @param list<array<string, mixed>> $items righe di `gst_order_items`
     * @param array{instructions?: string, deadline?: string, method?: string, url?: string} $extra
     * @return array{subject: string, body: string}
     */
    public static function compose(string $key, array $order, array $items, array $extra = []): array
    {
        $key = in_array($key, self::KEYS, true) ? $key : 'received';
        $merchant = in_array($key, self::MERCHANT_KEYS, true);
        $values = [
            ':number' => (string) ($order['order_number'] ?? ''),
            ':date' => self::date((string) ($order['ordered_at'] ?? '')),
            ':total' => self::money($order['total'] ?? 0).' €',
            ':deadline' => self::date((string) ($extra['deadline'] ?? '')),
            ':method' => (string) ($extra['method'] ?? ''),
        ];

        return [
            'subject' => self::text($key, 'subject', $values),
            'body' => self::render($merchant ? self::MERCHANT_VIEW : self::CUSTOMER_VIEW, [
                'title' => self::text($key, 'title', $values),
                'intro' => self::text($key, 'intro', $values),
                'instructions' => (string) ($extra['instructions'] ?? ''),
                'order' => $order,
                'items' => $items,
                'url' => self::absoluteUrl((string) ($extra['url'] ?? '')),
                'e' => static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'),
                'money' => static fn (mixed $v): string => self::money($v),
                'qty' => static fn (mixed $v): string => self::quantity((float) $v),
            ]),
        ];
    }

    /** Il testo dal file di lingua, con i segnaposto sostituiti. */
    private static function text(string $key, string $part, array $values): string
    {
        $text = (string) __t("gestionale.emails.order.{$key}.{$part}");

        // `__t()` senza frase restituisce la chiave: meglio niente che una
        // riga di puntini in mezzo a un'email.
        if ($text === "gestionale.emails.order.{$key}.{$part}") {
            $text = '';
        }

        return trim(strtr($text, $values));
    }

    /** Un'email si apre fuori dal sito: il link deve avere il dominio. */
    public static function absoluteUrl(string $path): string
    {
        if (trim($path) === '' || preg_match('#^https?://#i', $path) === 1) {
            return trim($path);
        }

        $base = defined('APP_URL') ? rtrim((string) constant('APP_URL'), '/') : '';

        return $base.'/'.ltrim($path, '/');
    }

    /** Le date si leggono come le scrive un italiano. */
    private static function date(string $value): string
    {
        $time = trim($value) === '' || str_starts_with($value, '0000-00-00') ? false : strtotime($value);

        return $time === false ? '' : date('d/m/Y', $time);
    }

    private static function money(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }

    /** I pezzi interi si scrivono interi: "3", non "3,000". */
    private static function quantity(float $value): string
    {
        if (round($value, 3) === round($value, 0)) {
            return number_format($value, 0, ',', '');
        }

        return rtrim(rtrim(number_format($value, 3, ',', ''), '0'), ',');
    }

    /** @param array<string, mixed> $variables */
    private static function render(string $view, array $variables): string
    {
        extract($variables, EXTR_SKIP);
        ob_start();

        try {
            require Gestionale::viewPath($view);
        } catch (Throwable $error) {
            ob_end_clean();

            throw $error;
        }

        return (string) ob_get_clean();
    }
}
```

- [ ] **Step 6: Scrivi `OrderNotifier`**

Crea `src/Support/Orders/OrderNotifier.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Mail\Recipients;

/**
 * Manda le email di un ordine a chi le deve ricevere.
 *
 * Un'email che non parte non ferma un ordine: se manca l'indirizzo o la posta
 * rifiuta, chi ha chiamato riceve l'esito e va avanti. Il denaro e la merce
 * sono già a posto — rimandare indietro tutto per una casella piena sarebbe
 * il danno peggiore.
 */
final class OrderNotifier
{
    public const NO_RECIPIENTS = 'no_recipients';
    public const NOT_FOUND = 'not_found';

    /**
     * @param array{instructions?: string, deadline?: string, url?: string} $extra
     * @return array{status: string, to: list<string>, sent: list<string>, failed: list<string>}
     */
    public static function send(string $key, int $orderId, array $extra = []): array
    {
        $order = Order::findById($orderId);

        if (!is_array($order) || $order === []) {
            return ['status' => self::NOT_FOUND, 'to' => [], 'sent' => [], 'failed' => []];
        }

        $to = self::recipients($key, $order);

        if ($to === []) {
            return ['status' => self::NO_RECIPIENTS, 'to' => [], 'sent' => [], 'failed' => []];
        }

        $method = (int) ($order['payment_method_id'] ?? 0) > 0
            ? PaymentMethod::findById((int) $order['payment_method_id'])
            : null;
        $email = OrderEmail::compose($key, $order, self::items($orderId), $extra + [
            'method' => is_array($method) ? (string) ($method['name'] ?? '') : '',
            'instructions' => is_array($method) ? (string) ($method['instructions'] ?? '') : '',
        ]);

        return Mailer::send('order.'.$key, $to, $email['subject'], $email['body']);
    }

    /**
     * @param array<string, mixed> $order
     * @return list<string>
     */
    private static function recipients(string $key, array $order): array
    {
        if (in_array($key, OrderEmail::MERCHANT_KEYS, true)) {
            return Recipients::parse(
                (string) (MerchantSetting::current()['merchant_notification_emails'] ?? '')
            )['valid'];
        }

        return Recipients::parse((string) ($order['email'] ?? ''))['valid'];
    }

    /** @return list<array<string, mixed>> */
    private static function items(int $orderId): array
    {
        $rows = OrderItem::find(['order_id' => $orderId, 'deleted' => 'false']);

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
```

- [ ] **Step 7: Guarda i test passare**

Run: `php tests/integrazione/OrderEmailsTest.php`
Expected: PASS, 7 su 7

- [ ] **Step 8: Tutta la suite**

Run: `php tests/run.php`
Expected: tutti verdi, zero falliti

- [ ] **Step 9: Commit**

```bash
git add src/Support/Orders/OrderEmail.php src/Support/Orders/OrderNotifier.php view/emails/ tests/integrazione/OrderEmailsTest.php lang/it/gestionale.json
git commit -m "$(cat <<'EOF'
Racconta l'ordine al cliente e al commerciante

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Conferma, annullamento, chiusura

**Files:**
- Create: `src/Support/Orders/Lifecycle.php`
- Create: `tests/integrazione/LifecycleTest.php`
- Modify: `lang/it/gestionale.json`

**Interfaces:**
- Consumes: `Allocation::commit(array): array{movement_id,before,after,reserved,oversold,merchant_alert}`, `Allocation::release(array): int`, `Allocation::restore(array): array{movement_id,before,after}`, `Ledger::register(array): array{payment_id,created,payment_status}`, `Ledger::fail(int, string): string`, `Ledger::sync(int): string`, `StatusLogger::record()`, `PaymentTiming::of()`, `OrderNotifier::send()`.
- Produces:
  - `Lifecycle::confirm(int $orderId, array $options = []): array{order_id: int, status: string, payment_status: string, committed: int, changed: bool}` — opzioni: `payment` (bool|null), `provider`, `provider_reference`, `amount`, `source`, `user_id`, `notify` (bool, default `true`).
  - `Lifecycle::cancel(int $orderId, array $options = []): array{order_id: int, status: string, released: int, restored: int, refundable: string, changed: bool}` — opzioni: `reason`, `source`, `user_id`, `notify`, `merchant_notice` (bool, default `false`).
  - `Lifecycle::fulfill(int $orderId, string $fulfillment, array $options = []): array{order_id: int, fulfillment_status: string, status: string, changed: bool}` — opzioni: `source` (default `user`), `user_id`, `message`.
  - `Lifecycle::refresh(int $orderId): string` — il nuovo `status`.

- [ ] **Step 1: Scrivi il test rosso**

Crea `tests/integrazione/LifecycleTest.php`:

```php
<?php
/** php tests/integrazione/LifecycleTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
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

/**
 * Un ordine in attesa, con la merce già prenotata: il punto in cui il
 * checkout lo lascia.
 *
 * @return array{0: int, 1: int}
 */
function ordinePrenotato(float $pezzi = 5, float $quantita = 2, float $totale = 50.0): array
{
    $prodotto = articoloConGiacenza($pezzi, 'TST-VITA-'.substr((string) microtime(true), -6));
    $ordine = ordineDiProva($totale);
    Order::update(['email' => 'cliente@example.test', 'ordered_at' => date('Y-m-d H:i:s')], $ordine);
    $riga = OrderItem::create([
        'order_id' => $ordine,
        'type' => 'product',
        'product_id' => $prodotto,
        'position' => 1,
        'name' => 'Crema da prova',
        'quantity' => number_format($quantita, 3, '.', ''),
        'unit_price' => '25.00',
        'line_total' => number_format($totale, 2, '.', ''),
    ]);

    Allocation::reserve([
        'product_id' => $prodotto,
        'quantity' => $quantita,
        'order_id' => $ordine,
        'order_item_id' => (int) ($riga->insert_id ?? 0),
    ]);

    return [$ordine, $prodotto];
}

/** Le email non devono uscire dalla prova. */
function senzaPosta(callable $corpo): mixed
{
    Mailer::useTransport(static fn (): bool => true);

    try {
        return $corpo();
    } finally {
        Mailer::useTransport(null);
    }
}

check('la conferma incassa, scarica e porta l\'ordine a confermato', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordinePrenotato();
        $prima = Levels::of($prodotto);

        $esito = senzaPosta(static fn (): array => Lifecycle::confirm($ordine, [
            'provider' => 'stripe',
            'provider_reference' => 'pi_'.uniqid(),
        ]));

        $dopo = Levels::of($prodotto);

        return $esito['status'] === 'confirmed'
            && $esito['payment_status'] === 'paid'
            && $esito['committed'] === 1
            && $dopo['quantity'] === round($prima['quantity'] - 2, 3)
            && $dopo['reserved'] === 0.0;
    });
});

check('confermare due volte non scarica due volte', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordinePrenotato();
        $riferimento = 'pi_'.uniqid();

        senzaPosta(static fn (): array => Lifecycle::confirm($ordine, [
            'provider' => 'stripe',
            'provider_reference' => $riferimento,
        ]));
        $giacenza = Levels::of($prodotto)['quantity'];
        $seconda = senzaPosta(static fn (): array => Lifecycle::confirm($ordine, [
            'provider' => 'stripe',
            'provider_reference' => $riferimento,
        ]));

        return $seconda['changed'] === false
            && $seconda['status'] === 'confirmed'
            && Levels::of($prodotto)['quantity'] === $giacenza;
    });
});

check('il contrassegno conferma con il pagamento ancora in attesa', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordinePrenotato();

        $esito = senzaPosta(static fn (): array => Lifecycle::confirm($ordine, ['payment' => false]));

        return $esito['status'] === 'confirmed'
            && $esito['payment_status'] !== 'paid'
            && Levels::of($prodotto)['quantity'] === 3.0;
    });
});

check('annullato prima della conferma: la merce torna libera, la giacenza non si muove', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordinePrenotato();
        $prima = Levels::of($prodotto);

        $esito = senzaPosta(static fn (): array => Lifecycle::cancel($ordine, ['reason' => 'a mano']));
        $dopo = Levels::of($prodotto);

        return $esito['status'] === 'cancelled'
            && $esito['released'] === 1
            && $esito['restored'] === 0
            && $dopo['quantity'] === $prima['quantity']
            && $dopo['available'] === $prima['quantity']
            && (int) sqlCount(StockReservation::$table, "order_id = {$ordine} AND released_at IS NULL AND deleted = 'false'") === 0;
    });
});

check('annullato dopo la conferma: la merce rientra a magazzino', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordinePrenotato();
        senzaPosta(static fn (): array => Lifecycle::confirm($ordine, ['payment' => false]));
        $scaricato = Levels::of($prodotto)['quantity'];

        $esito = senzaPosta(static fn (): array => Lifecycle::cancel($ordine));

        return $esito['status'] === 'cancelled'
            && $esito['restored'] === 1
            && Levels::of($prodotto)['quantity'] === round($scaricato + 2, 3);
    });
});

check('annullare due volte non rimette la merce due volte', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordinePrenotato();
        senzaPosta(static fn (): array => Lifecycle::confirm($ordine, ['payment' => false]));
        senzaPosta(static fn (): array => Lifecycle::cancel($ordine));
        $giacenza = Levels::of($prodotto)['quantity'];

        $seconda = senzaPosta(static fn (): array => Lifecycle::cancel($ordine));

        return $seconda['changed'] === false && Levels::of($prodotto)['quantity'] === $giacenza;
    });
});

check('un ordine annullato non si può confermare', function () {
    return prova(static function (): bool {
        [$ordine] = ordinePrenotato();
        senzaPosta(static fn (): array => Lifecycle::cancel($ordine));

        try {
            senzaPosta(static fn (): array => Lifecycle::confirm($ordine, ['payment' => false]));
        } catch (UserError) {
            return true;
        }

        return false;
    });
});

check('pagato ed evaso si chiude da solo', function () {
    return prova(static function (): bool {
        [$ordine] = ordinePrenotato();
        senzaPosta(static fn (): array => Lifecycle::confirm($ordine, [
            'provider' => 'stripe',
            'provider_reference' => 'pi_'.uniqid(),
        ]));

        $evaso = Lifecycle::fulfill($ordine, 'fulfilled');
        $riga = Order::findById($ordine);

        // `fulfill()` chiude l'ordine da sé: chi lavora gli ordini segna
        // «evaso» e non deve ricordarsi di un secondo passaggio.
        return $evaso['fulfillment_status'] === 'fulfilled'
            && $evaso['status'] === 'completed'
            && trim((string) $riga['completed_at']) !== '';
    });
});

check('l\'evasione a metà non chiude l\'ordine e resta scritta nella storia', function () {
    return prova(static function (): bool {
        [$ordine] = ordinePrenotato();
        senzaPosta(static fn (): array => Lifecycle::confirm($ordine, [
            'provider' => 'stripe',
            'provider_reference' => 'pi_'.uniqid(),
        ]));

        $primo = Lifecycle::fulfill($ordine, 'partially_fulfilled', ['user_id' => 1]);
        // Ripetere lo stesso stato non scrive una seconda riga di storia.
        $secondo = Lifecycle::fulfill($ordine, 'partially_fulfilled');

        $righe = (int) sqlCount(
            OrderStatusLog::$table,
            "order_id = {$ordine} AND field = 'fulfillment_status' AND deleted = 'false'"
        );

        return $primo['status'] === 'confirmed'
            && $primo['changed'] === true
            && $secondo['changed'] === false
            && $righe === 1;
    });
});

check('uno stato di evasione inventato si rifiuta', function () {
    return prova(static function (): bool {
        [$ordine] = ordinePrenotato();

        try {
            Lifecycle::fulfill($ordine, 'spedito');
        } catch (UserError) {
            return true;
        }

        return false;
    });
});

check('l\'annullamento dice quanto denaro resta da restituire', function () {
    return prova(static function (): bool {
        [$ordine] = ordinePrenotato();
        senzaPosta(static fn (): array => Lifecycle::confirm($ordine, [
            'provider' => 'stripe',
            'provider_reference' => 'pi_'.uniqid(),
        ]));

        return senzaPosta(static fn (): array => Lifecycle::cancel($ordine))['refundable'] === '50.00';
    });
});

summary();
```

- [ ] **Step 2: Guarda il test fallire**

Run: `php tests/integrazione/LifecycleTest.php`
Expected: FAIL — `Class "Wonder\Plugin\Gestionale\Support\Orders\Lifecycle" not found`

- [ ] **Step 3: Scrivi `Lifecycle`**

Crea `src/Support/Orders/Lifecycle.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Sql\Transaction;

/**
 * **L'unico che scrive lo `status` di un ordine.**
 *
 * Confermare vuol dire tre cose insieme: il denaro risulta, la merce esce dal
 * magazzino, il cliente lo sa. Annullare vuol dire le stesse tre al contrario,
 * e quale contrario dipende da dov'era l'ordine: prima della conferma la merce
 * era solo impegnata e basta liberarla; dopo era già uscita e deve rientrare.
 *
 * L'evasione è l'eccezione che conferma la regola: `fulfill()` scrive
 * `fulfillment_status` e non tocca il magazzino, perché la merce è già uscita
 * alla conferma. Sta qui perché il cambio va nella storia dell'ordine e perché
 * può essere il pezzo che mancava per chiudere.
 *
 * Tutto è idempotente. Un webhook che ripassa, un operatore che clicca due
 * volte, il giro dello scheduler che incontra un ordine già chiuso: la seconda
 * volta non scarica niente, non rimette niente e non manda una seconda email.
 * Chi chiama lo capisce da `changed`.
 */
final class Lifecycle
{
    /** Gli stati da cui si può ancora confermare. */
    private const CONFIRMABLE = ['draft', 'pending'];

    /** Quelli in cui la merce è già uscita di magazzino. */
    private const COMMITTED = ['confirmed', 'processing'];

    /**
     * Il pagamento risulta, la merce esce, il cliente lo sa.
     *
     * @param array{payment?: bool|null, provider?: string, provider_reference?: string, amount?: float, source?: string, user_id?: int, notify?: bool} $options
     * @return array{order_id: int, status: string, payment_status: string, committed: int, changed: bool}
     */
    public static function confirm(int $orderId, array $options = []): array
    {
        $result = Transaction::run(static function () use ($orderId, $options): array {
            $order = self::order($orderId);
            $status = (string) $order['status'];

            if ($status === 'cancelled') {
                throw UserError::make('order.cancelled_cannot_confirm');
            }

            if (!in_array($status, self::CONFIRMABLE, true)) {
                // Già confermato: la merce è fuori e il denaro è a posto.
                return [
                    'order_id' => $orderId,
                    'status' => $status,
                    'payment_status' => (string) $order['payment_status'],
                    'committed' => 0,
                    'changed' => false,
                ];
            }

            $timing = PaymentTiming::of((int) ($order['payment_method_id'] ?? 0));
            $wantsPayment = $options['payment'] ?? ($timing !== PaymentTiming::ON_DELIVERY);
            $paymentStatus = (string) $order['payment_status'];

            if ($wantsPayment === true && $paymentStatus !== 'paid') {
                $paymentStatus = Ledger::register([
                    'order_id' => $orderId,
                    'amount' => (float) ($options['amount'] ?? $order['total']),
                    'customer_id' => (int) ($order['customer_id'] ?? 0),
                    'payment_method_id' => (int) ($order['payment_method_id'] ?? 0),
                    'currency' => (string) ($order['currency'] ?? 'EUR'),
                    'provider' => (string) ($options['provider'] ?? ''),
                    'provider_reference' => (string) ($options['provider_reference'] ?? ''),
                    'source' => (string) ($options['source'] ?? 'system'),
                    'user_id' => (int) ($options['user_id'] ?? 0),
                ])['payment_status'];
            }

            $committed = 0;

            foreach (self::items($orderId) as $item) {
                if ((int) ($item['product_id'] ?? 0) <= 0) {
                    continue;
                }

                Allocation::commit([
                    'product_id' => (int) $item['product_id'],
                    'quantity' => (float) $item['quantity'],
                    'location_id' => (int) ($order['location_id'] ?? 0),
                    'order_id' => $orderId,
                    'order_item_id' => (int) $item['id'],
                    // Col contrassegno la merce esce prima dell'incasso: è il
                    // patto di quel metodo, non una svista.
                    'payment_ok' => $paymentStatus === 'paid' || $timing === PaymentTiming::ON_DELIVERY,
                    'source' => (string) ($options['source'] ?? 'system'),
                    'user_id' => (int) ($options['user_id'] ?? 0),
                ]);
                ++$committed;
            }

            Order::update(['status' => 'confirmed'], $orderId);
            StatusLogger::record(
                OrderStatusLog::class,
                $orderId,
                'status',
                $status,
                'confirmed',
                (string) ($options['source'] ?? 'system'),
                (int) ($options['user_id'] ?? 0) ?: null
            );

            return [
                'order_id' => $orderId,
                'status' => 'confirmed',
                'payment_status' => $paymentStatus,
                'committed' => $committed,
                'changed' => true,
            ];
        });

        if ($result['changed'] && ($options['notify'] ?? true)) {
            // Fuori dalla transazione: la posta è lenta e non deve tenere
            // aperto un blocco sulle righe di magazzino.
            OrderNotifier::send('confirmed', $orderId);
        }

        return $result;
    }

    /**
     * L'ordine si ferma: la merce torna disponibile e il denaro in attesa
     * decade.
     *
     * Quello già incassato non si rimborsa da qui — il rimborso lo fa il
     * gateway, e `Ledger::refund()` lo registra quando risponde. Qui si dice
     * soltanto quanto c'è da restituire, così chi annulla non se ne dimentica.
     *
     * @param array{reason?: string, source?: string, user_id?: int, notify?: bool, merchant_notice?: bool} $options
     * @return array{order_id: int, status: string, released: int, restored: int, refundable: string, changed: bool}
     */
    public static function cancel(int $orderId, array $options = []): array
    {
        $result = Transaction::run(static function () use ($orderId, $options): array {
            $order = self::order($orderId);
            $status = (string) $order['status'];

            if ($status === 'cancelled') {
                return [
                    'order_id' => $orderId,
                    'status' => 'cancelled',
                    'released' => 0,
                    'restored' => 0,
                    'refundable' => self::money(self::paid($orderId)),
                    'changed' => false,
                ];
            }

            if ($status === 'completed') {
                // Un ordine chiuso si disfa con un reso, che rimette la merce
                // riga per riga e lascia la storia in piedi.
                throw UserError::make('order.completed_cannot_cancel');
            }

            $released = 0;
            $restored = 0;
            $reason = trim((string) ($options['reason'] ?? ''));
            $source = (string) ($options['source'] ?? 'system');

            if (in_array($status, self::COMMITTED, true)) {
                foreach (self::items($orderId) as $item) {
                    if ((int) ($item['product_id'] ?? 0) <= 0) {
                        continue;
                    }

                    Allocation::restore([
                        'product_id' => (int) $item['product_id'],
                        'quantity' => (float) $item['quantity'],
                        'location_id' => (int) ($order['location_id'] ?? 0),
                        'order_id' => $orderId,
                        'order_item_id' => (int) $item['id'],
                        'source' => $source,
                        'user_id' => (int) ($options['user_id'] ?? 0),
                    ]);
                    ++$restored;
                }
            } else {
                $released = Allocation::release(['order_id' => $orderId]);
            }

            foreach (self::payments($orderId) as $payment) {
                if ((string) $payment['status'] === 'pending') {
                    Ledger::fail((int) $payment['id'], $reason !== '' ? $reason : 'Ordine annullato');
                }
            }

            Order::update(['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s')], $orderId);
            StatusLogger::record(
                OrderStatusLog::class,
                $orderId,
                'status',
                $status,
                'cancelled',
                $source,
                (int) ($options['user_id'] ?? 0) ?: null,
                $reason
            );

            return [
                'order_id' => $orderId,
                'status' => 'cancelled',
                'released' => $released,
                'restored' => $restored,
                'refundable' => self::money(self::paid($orderId)),
                'changed' => true,
            ];
        });

        if ($result['changed'] && ($options['notify'] ?? true)) {
            OrderNotifier::send('cancelled', $orderId);

            if (($options['merchant_notice'] ?? false) === true) {
                OrderNotifier::send('merchant_cancelled', $orderId);
            }
        }

        return $result;
    }

    /**
     * Segna a che punto è la merce che esce.
     *
     * L'evasione si muove a mano — la spunta «evaso» dopo aver spedito — e
     * **non tocca il magazzino**: la merce è già uscita alla conferma. Passa
     * di qui e non dalla pagina perché il cambio va scritto nella storia
     * dell'ordine, e perché «evaso» può essere l'ultimo pezzo che manca per
     * chiudere l'ordine: `refresh()` se ne accorge subito, senza che chi ha
     * spuntato la casella debba ricordarsi di un secondo passaggio.
     *
     * @param array{source?: string, user_id?: int, message?: string} $options
     * @return array{order_id: int, fulfillment_status: string, status: string, changed: bool}
     */
    public static function fulfill(int $orderId, string $fulfillment, array $options = []): array
    {
        if (!in_array($fulfillment, Order::FULFILLMENT_STATUSES, true)) {
            throw UserError::make('order.unknown_fulfillment', ['status' => $fulfillment]);
        }

        $result = Transaction::run(static function () use ($orderId, $fulfillment, $options): array {
            $order = self::order($orderId);
            $before = (string) $order['fulfillment_status'];

            if ($before === $fulfillment) {
                // Due clic sullo stesso pulsante non sono due evasioni.
                return [
                    'order_id' => $orderId,
                    'fulfillment_status' => $before,
                    'status' => (string) $order['status'],
                    'changed' => false,
                ];
            }

            Order::update(['fulfillment_status' => $fulfillment], $orderId);

            StatusLogger::record(
                OrderStatusLog::class,
                $orderId,
                'fulfillment_status',
                $before,
                $fulfillment,
                in_array((string) ($options['source'] ?? ''), StatusLogger::SOURCES, true)
                    ? (string) $options['source']
                    : 'user',
                (int) ($options['user_id'] ?? 0) ?: null,
                (string) ($options['message'] ?? '')
            );

            return [
                'order_id' => $orderId,
                'fulfillment_status' => $fulfillment,
                'status' => (string) $order['status'],
                'changed' => true,
            ];
        });

        if ($result['changed'] === true) {
            // Fuori dalla transazione di sopra solo per chiarezza: `refresh()`
            // apre la sua, e annidata diventa un savepoint.
            $result['status'] = self::refresh($orderId);
        }

        return $result;
    }

    /**
     * Chiude l'ordine quando è pagato per intero ed evaso per intero.
     *
     * `processing` resta una scelta di chi lavora gli ordini: non si entra e
     * non si esce da lì da soli.
     *
     * @return string il nuovo `status`
     */
    public static function refresh(int $orderId): string
    {
        return Transaction::run(static function () use ($orderId): string {
            $order = self::order($orderId);
            $status = (string) $order['status'];

            if (!in_array($status, self::COMMITTED, true)) {
                return $status;
            }

            if ((string) $order['payment_status'] !== 'paid'
                || (string) $order['fulfillment_status'] !== 'fulfilled') {
                return $status;
            }

            Order::update(['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')], $orderId);
            StatusLogger::record(
                OrderStatusLog::class,
                $orderId,
                'status',
                $status,
                'completed',
                'system'
            );

            return 'completed';
        });
    }

    /**
     * La riga dell'ordine, bloccata.
     *
     * @return array<string, mixed>
     */
    private static function order(int $orderId): array
    {
        $row = Order::findForUpdate(['id' => $orderId], 1);

        if (!is_array($row) || $row === []) {
            throw UserError::make('order.not_found');
        }

        if ((string) $row['stage'] !== 'order') {
            // Un carrello non ha stati da cambiare: quelli nascono al checkout.
            throw UserError::make('order.not_an_order');
        }

        return $row;
    }

    /** Quanto è stato incassato davvero, meno i rimborsi. */
    private static function paid(int $orderId): float
    {
        $total = 0.0;

        foreach (self::payments($orderId) as $payment) {
            if ((string) $payment['status'] !== 'paid') {
                continue;
            }

            $amount = (float) ($payment['amount'] ?? 0);
            $total += (string) $payment['type'] === 'refund' ? -$amount : $amount;
        }

        return round(max(0.0, $total), 2);
    }

    /** @return list<array<string, mixed>> */
    private static function payments(int $orderId): array
    {
        return self::rows(Payment::find(['order_id' => $orderId, 'deleted' => 'false']));
    }

    /** @return list<array<string, mixed>> */
    private static function items(int $orderId): array
    {
        return self::rows(OrderItem::find(['order_id' => $orderId, 'deleted' => 'false']));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $result): array
    {
        if (!is_array($result) || $result === []) {
            return [];
        }

        return isset($result['id']) ? [$result] : array_values(array_filter($result, 'is_array'));
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
```

- [ ] **Step 4: Aggiungi le frasi d'errore**

In `lang/it/gestionale.json`, dentro `gestionale.errors.order`:

```json
"not_found": "Questo ordine non esiste più.",
"not_an_order": "Questo è ancora un carrello: non ha uno stato da cambiare.",
"cancelled_cannot_confirm": "L'ordine è stato annullato e non si può confermare.",
"completed_cannot_cancel": "L'ordine è chiuso: per disfarlo serve un reso.",
"unknown_fulfillment": "«:status» non è uno stato di evasione."
```

- [ ] **Step 5: Guarda i test passare**

Run: `php tests/integrazione/LifecycleTest.php`
Expected: PASS, 9 su 9

- [ ] **Step 6: Tutta la suite**

Run: `php tests/run.php`
Expected: tutti verdi, zero falliti

- [ ] **Step 7: Commit**

```bash
git add src/Support/Orders/Lifecycle.php tests/integrazione/LifecycleTest.php lang/it/gestionale.json
git commit -m "$(cat <<'EOF'
Conferma, annulla e chiude un ordine da un punto solo

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Il checkout

**Files:**
- Create: `src/Support/Orders/Checkout.php`
- Create: `tests/integrazione/CheckoutTest.php`
- Modify: `lang/it/gestionale.json`

**Interfaces:**
- Consumes: `Cart::recalculate()`, `Cart::contents()`, `PaymentTiming::of()`, `PaymentTiming::reservationExpiry()`, `Allocation::reserve(array): array{reservation_id,quantity,available,expires_at}`, `DocumentSequences::next('order')`, `Ledger::open(array): array{payment_id,created,payment_status}`, `Lifecycle::confirm()`, `OrderNotifier::send()`, `OrderTaxSummary`.
- Produces:
  - `Checkout::place(int $cartId, array $data): array{order_id: int, order_number: string, payment_id: int, total: string, reserved: int, status: string}` — dati: `email`, `phone`, `customer_id`, `billing` (array `country`, `city`, `cap`, `street`, `number`, `province`, `business_name`, `cf`, `pi`, `sdi`, `pec`, `name`, `surname`), `shipping` (le stesse senza i campi fiscali), `payment_method_id`, `fulfillment_type`, `location_id`, `shipping_method_id`, `customer_note`, `source`, `user_id`.

- [ ] **Step 1: Scrivi il test rosso**

Crea `tests/integrazione/CheckoutTest.php`:

```php
<?php
/** php tests/integrazione/CheckoutTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Plugin\Gestionale\Support\Orders\Checkout;
use Wonder\Plugin\Gestionale\Support\Orders\PaymentTiming;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
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

/** Un metodo di pagamento di prova, col modo e la commissione che servono. */
function metodoDiProva(string $timing, string $feeType = 'none', float $feeValue = 0): int
{
    $metodo = PaymentMethod::create([
        'code' => Code::make(PaymentMethod::class, Codes::PAYMENT_METHOD),
        'name' => 'Prova '.$timing,
        'provider' => $timing === PaymentTiming::IMMEDIATE ? 'stripe' : 'manual',
        'timing' => $timing,
        'fee_type' => $feeType,
        'fee_value' => number_format($feeValue, 2, '.', ''),
        'available_for' => 'all',
        'active' => 'true',
        'position' => 1,
        'instructions' => 'Istruzioni di prova',
    ]);

    return (int) ($metodo->insert_id ?? 0);
}

/** Un carrello con dentro un articolo da 20 €. */
function carrelloPronto(float $giacenza = 10, float $quantita = 2): array
{
    $prodotto = articoloConGiacenza($giacenza, 'TST-CHK-'.substr((string) microtime(true), -6));
    Wonder\Plugin\Gestionale\Models\Catalog\Product::update(['price' => '20.00'], $prodotto);

    $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
    Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => $quantita]);

    return [$carrello, $prodotto];
}

/** @return array<string, mixed> */
function datiCheckout(int $metodo): array
{
    return [
        'email' => 'cliente@example.test',
        'payment_method_id' => $metodo,
        'fulfillment_type' => 'shipping',
        'billing' => [
            'country' => 'IT',
            'province' => 'MI',
            'city' => 'Milano',
            'cap' => '20100',
            'street' => 'Via Prova',
            'number' => '1',
            'name' => 'Mario',
            'surname' => 'Rossi',
        ],
    ];
}

function senzaPosta(callable $corpo): mixed
{
    Mailer::useTransport(static fn (): bool => true);

    try {
        return $corpo();
    } finally {
        Mailer::useTransport(null);
    }
}

check('il carrello diventa un ordine numerato, con la merce impegnata', function () {
    return prova(static function (): bool {
        [$carrello, $prodotto] = carrelloPronto();
        $prima = Levels::of($prodotto);

        $esito = senzaPosta(static fn (): array => Checkout::place(
            $carrello,
            datiCheckout(metodoDiProva(PaymentTiming::IMMEDIATE))
        ));

        $ordine = Order::findById($esito['order_id']);
        $dopo = Levels::of($prodotto);

        return $esito['order_id'] === $carrello
            && preg_match('#^\d{4}/\d{2}\d{4}$#', $esito['order_number']) === 1
            && $ordine['stage'] === 'order'
            && $ordine['status'] === 'pending'
            && trim((string) $ordine['ordered_at']) !== ''
            && $ordine['billing_city'] === 'Milano'
            && $esito['reserved'] === 1
            && $dopo['quantity'] === $prima['quantity']
            && $dopo['available'] === round($prima['available'] - 2, 3);
    });
});

check('il pagamento nasce in attesa per l\'importo dell\'ordine', function () {
    return prova(static function (): bool {
        [$carrello] = carrelloPronto();
        $esito = senzaPosta(static fn (): array => Checkout::place(
            $carrello,
            datiCheckout(metodoDiProva(PaymentTiming::IMMEDIATE))
        ));

        $pagamento = Payment::findById($esito['payment_id']);
        $ordine = Order::findById($esito['order_id']);

        return $pagamento['status'] === 'pending'
            && (string) $pagamento['amount'] === (string) $ordine['total']
            && $ordine['payment_status'] === 'pending';
    });
});

check('i riepiloghi IVA restano scritti sull\'ordine', function () {
    return prova(static function (): bool {
        [$carrello] = carrelloPronto();
        $esito = senzaPosta(static fn (): array => Checkout::place(
            $carrello,
            datiCheckout(metodoDiProva(PaymentTiming::IMMEDIATE))
        ));

        return (int) sqlCount(OrderTaxSummary::$table, 'order_id = '.$esito['order_id']) > 0;
    });
});

check('la commissione del metodo diventa una riga', function () {
    return prova(static function (): bool {
        [$carrello] = carrelloPronto();
        $esito = senzaPosta(static fn (): array => Checkout::place(
            $carrello,
            datiCheckout(metodoDiProva(PaymentTiming::DEFERRED, 'amount', 2.50))
        ));

        $contenuto = Cart::contents($esito['order_id']);
        $commissioni = array_values(array_filter(
            $contenuto['items'],
            static fn (array $riga): bool => (string) $riga['type'] === 'fee'
        ));

        return count($commissioni) === 1
            && (string) $commissioni[0]['line_total'] === '2.50'
            && (string) $contenuto['order']['fees_total'] === '2.50';
    });
});

check('la carta impegna per mezz\'ora, il bonifico per giorni, il contrassegno non scade', function () {
    return prova(static function (): bool {
        $scadenze = [];

        foreach ([PaymentTiming::IMMEDIATE, PaymentTiming::DEFERRED, PaymentTiming::ON_DELIVERY] as $modo) {
            [$carrello] = carrelloPronto();
            $esito = senzaPosta(static fn (): array => Checkout::place(
                $carrello,
                datiCheckout(metodoDiProva($modo))
            ));
            $riga = StockReservation::find(['order_id' => $esito['order_id'], 'deleted' => 'false'], 1);
            $scadenze[$modo] = trim((string) ($riga['expires_at'] ?? ''));
        }

        $subito = strtotime($scadenze[PaymentTiming::IMMEDIATE]) - time();
        $dopo = strtotime($scadenze[PaymentTiming::DEFERRED]) - time();

        return $subito > 0 && $subito <= 1800
            && $dopo > 86400
            && ($scadenze[PaymentTiming::ON_DELIVERY] === ''
                || str_starts_with($scadenze[PaymentTiming::ON_DELIVERY], '0000-00-00'));
    });
});

check('col contrassegno l\'ordine è già confermato e la merce è uscita', function () {
    return prova(static function (): bool {
        [$carrello, $prodotto] = carrelloPronto();
        $prima = Levels::of($prodotto)['quantity'];

        $esito = senzaPosta(static fn (): array => Checkout::place(
            $carrello,
            datiCheckout(metodoDiProva(PaymentTiming::ON_DELIVERY))
        ));

        return $esito['status'] === 'confirmed'
            && Levels::of($prodotto)['quantity'] === round($prima - 2, 3);
    });
});

check('il carrello vuoto non diventa un ordine', function () {
    return prova(static function (): bool {
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];

        try {
            senzaPosta(static fn (): array => Checkout::place(
                $carrello,
                datiCheckout(metodoDiProva(PaymentTiming::IMMEDIATE))
            ));
        } catch (UserError) {
            $riga = Order::findById($carrello);

            return $riga['stage'] === 'cart' && trim((string) $riga['order_number']) === '';
        }

        return false;
    });
});

check('un metodo di pagamento spento ferma tutto, e il carrello resta intero', function () {
    return prova(static function (): bool {
        [$carrello, $prodotto] = carrelloPronto();
        $metodo = metodoDiProva(PaymentTiming::IMMEDIATE);
        PaymentMethod::update(['active' => 'false'], $metodo);
        $prima = Levels::of($prodotto)['available'];

        try {
            senzaPosta(static fn (): array => Checkout::place($carrello, datiCheckout($metodo)));
        } catch (UserError) {
            $riga = Order::findById($carrello);

            return $riga['stage'] === 'cart'
                && Levels::of($prodotto)['available'] === $prima
                && (int) sqlCount(StockReservation::$table, "order_id = {$carrello} AND deleted = 'false'") === 0;
        }

        return false;
    });
});

check('il metodo che non esiste è rifiutato', function () {
    return prova(static function (): bool {
        [$carrello] = carrelloPronto();

        try {
            senzaPosta(static fn (): array => Checkout::place($carrello, datiCheckout(999999)));
        } catch (UserError) {
            return true;
        }

        return false;
    });
});

check('la merce finita fra il carrello e il checkout ferma l\'ordine', function () {
    return prova(static function (): bool {
        [$carrello, $prodotto] = carrelloPronto(2, 2);

        // Qualcuno l'ha comprata prima: al checkout non c'è più niente.
        $altro = ordineDiProva();
        Wonder\Plugin\Gestionale\Support\Stock\Allocation::reserve([
            'product_id' => $prodotto,
            'quantity' => 2,
            'order_id' => $altro,
        ]);

        try {
            senzaPosta(static fn (): array => Checkout::place(
                $carrello,
                datiCheckout(metodoDiProva(PaymentTiming::IMMEDIATE))
            ));
        } catch (UserError) {
            return (string) Order::findById($carrello)['stage'] === 'cart';
        }

        return false;
    });
});

summary();
```

- [ ] **Step 2: Guarda il test fallire**

Run: `php tests/integrazione/CheckoutTest.php`
Expected: FAIL — `Class "Wonder\Plugin\Gestionale\Support\Orders\Checkout" not found`

- [ ] **Step 3: Scrivi `Checkout`**

Crea `src/Support/Orders/Checkout.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentSequences;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Plugin\Gestionale\Support\Tax\TaxTotals;
use Wonder\Sql\Transaction;

/**
 * Da carrello a ordine, in una transazione sola.
 *
 * È il punto più delicato del modulo: qui la merce smette di essere libera e
 * il denaro comincia ad avere un nome. Se una qualsiasi di queste cose non
 * riesce — la merce finita mentre il cliente scriveva l'indirizzo, il metodo
 * di pagamento spento dal commerciante un minuto fa — **non deve restare
 * niente a metà**: né un ordine numerato senza merce, né merce impegnata per
 * un ordine che non esiste. Per questo tutto sta dentro `Transaction::run()`,
 * e la prenotazione blocca le righe di magazzino con `FOR UPDATE` dentro
 * `Allocation`.
 *
 * L'ordine dei passi non è casuale: prima si scrive chi compra e come paga,
 * perché il paese di fatturazione cambia l'IVA e il metodo può aggiungere una
 * commissione; poi si ricalcola; poi si prenota; e solo alla fine si prende il
 * numero. Prendere il numero prima vorrebbe dire bruciarne uno a ogni
 * tentativo fallito, e la numerazione dei documenti non ammette buchi.
 */
final class Checkout
{
    /**
     * @param array<string, mixed> $data
     * @return array{order_id: int, order_number: string, payment_id: int, total: string, reserved: int, status: string}
     */
    public static function place(int $cartId, array $data): array
    {
        $result = Transaction::run(static function () use ($cartId, $data): array {
            $cart = Order::findForUpdate(['id' => $cartId], 1);

            if (!is_array($cart) || $cart === [] || (string) $cart['stage'] !== 'cart') {
                throw UserError::make('cart.not_a_cart');
            }

            $method = self::method((int) ($data['payment_method_id'] ?? 0));
            Order::update(self::details($data, $method), $cartId);

            self::applyFee($cartId, $method);
            $recalculated = Cart::recalculate($cartId);
            $order = $recalculated['order'];
            $items = $recalculated['items'];

            if (self::goods($items) === []) {
                throw UserError::make('order.empty_cart');
            }

            $timing = PaymentTiming::of((int) $method['id']);
            $expires = PaymentTiming::reservationExpiry($timing);
            $reserved = 0;

            foreach (self::goods($items) as $item) {
                Allocation::reserve([
                    'product_id' => (int) $item['product_id'],
                    'quantity' => (float) $item['quantity'],
                    'location_id' => (int) ($order['location_id'] ?? 0),
                    'order_id' => $cartId,
                    'order_item_id' => (int) $item['id'],
                    'expires_at' => $expires ?? '',
                ]);
                ++$reserved;
            }

            $number = DocumentSequences::next('order');
            Order::update([
                'stage' => 'order',
                'status' => 'pending',
                'order_number' => $number,
                'ordered_at' => date('Y-m-d H:i:s'),
            ], $cartId);

            StatusLogger::record(
                OrderStatusLog::class,
                $cartId,
                'status',
                (string) $cart['status'],
                'pending',
                (string) ($data['source'] ?? 'user'),
                (int) ($data['user_id'] ?? 0) ?: null
            );

            $payment = Ledger::open([
                'order_id' => $cartId,
                'amount' => (float) $order['total'],
                'customer_id' => (int) ($order['customer_id'] ?? 0),
                'payment_method_id' => (int) $method['id'],
                'payment_account_id' => (int) ($method['payment_account_id'] ?? 0),
                'currency' => (string) ($order['currency'] ?? 'EUR'),
                'provider' => (string) ($method['provider'] ?? 'manual'),
                'source' => (string) ($data['source'] ?? 'user'),
                'user_id' => (int) ($data['user_id'] ?? 0),
            ]);

            self::writeTaxSummaries($cartId, $recalculated);

            return [
                'order_id' => $cartId,
                'order_number' => $number,
                'payment_id' => (int) $payment['payment_id'],
                'total' => (string) $order['total'],
                'reserved' => $reserved,
                'timing' => $timing,
                'status' => 'pending',
            ];
        });

        // Il contrassegno e il ritiro si pagano alla consegna: l'ordine è
        // buono così com'è e la merce può uscire subito. `confirm()` manda la
        // sua email di conferma, quindi qui basta avvisare il commerciante.
        if ($result['timing'] === PaymentTiming::ON_DELIVERY) {
            $confirmed = Lifecycle::confirm($result['order_id'], [
                'payment' => false,
                'source' => (string) ($data['source'] ?? 'user'),
                'user_id' => (int) ($data['user_id'] ?? 0),
            ]);
            $result['status'] = $confirmed['status'];
        } else {
            OrderNotifier::send('received', $result['order_id']);
        }

        OrderNotifier::send('merchant_new', $result['order_id']);
        unset($result['timing']);

        return $result;
    }

    /**
     * Il metodo di pagamento, se si può ancora usare.
     *
     * @return array<string, mixed>
     */
    private static function method(int $methodId): array
    {
        $method = $methodId > 0 ? PaymentMethod::findById($methodId) : null;

        if (!is_array($method) || $method === [] || ($method['active'] ?? 'false') !== 'true') {
            throw UserError::make('order.payment_method_unavailable');
        }

        return $method;
    }

    /**
     * I dati che il cliente ha scritto, pronti per la riga dell'ordine.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $method
     * @return array<string, string|int>
     */
    private static function details(array $data, array $method): array
    {
        $fields = [
            'email' => (string) ($data['email'] ?? ''),
            'phone' => (string) ($data['phone'] ?? ''),
            'customer_id' => (int) ($data['customer_id'] ?? 0),
            'payment_method_id' => (int) $method['id'],
            'shipping_method_id' => (int) ($data['shipping_method_id'] ?? 0),
            'location_id' => (int) ($data['location_id'] ?? 0),
            'customer_note' => (string) ($data['customer_note'] ?? ''),
            'last_activity_at' => date('Y-m-d H:i:s'),
        ];

        $type = (string) ($data['fulfillment_type'] ?? 'shipping');
        $fields['fulfillment_type'] = in_array($type, Order::FULFILLMENT_TYPES, true) ? $type : 'shipping';

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

    /**
     * La commissione del metodo, come riga dell'ordine.
     *
     * Una sola: se il cliente torna indietro e cambia metodo, quella di prima
     * se ne va invece di sommarsi.
     *
     * @param array<string, mixed> $method
     */
    private static function applyFee(int $orderId, array $method): void
    {
        foreach (self::rows(OrderItem::find(['order_id' => $orderId, 'type' => 'fee', 'deleted' => 'false'])) as $old) {
            OrderItem::delete((int) $old['id']);
        }

        $type = (string) ($method['fee_type'] ?? 'none');
        $value = round((float) ($method['fee_value'] ?? 0), 2);

        if ($type === 'none' || $value <= 0) {
            return;
        }

        $order = Order::findById($orderId);
        $base = (float) (is_array($order) ? ($order['products_total'] ?? 0) : 0);
        $amount = $type === 'percent' ? round($base * min($value, 100.0) / 100, 2) : $value;

        if ($amount <= 0) {
            return;
        }

        OrderItem::create([
            'order_id' => $orderId,
            'type' => 'fee',
            'position' => 900,
            'name' => (string) ($method['name'] ?? 'Commissione'),
            'quantity' => '1.000',
            'list_price' => number_format($amount, 2, '.', ''),
            'unit_price' => number_format($amount, 2, '.', ''),
            'price_source' => 'manual',
            'line_total' => number_format($amount, 2, '.', ''),
            // La commissione non ha un tipo fiscale suo: prende l'aliquota di
            // ripiego delle impostazioni, come la spedizione.
            'tax_category_id' => 0,
        ]);
    }

    /**
     * Riscrive i riepiloghi IVA dell'ordine.
     *
     * @param array{order: array<string, mixed>, items: list<array<string, mixed>>} $recalculated
     */
    private static function writeTaxSummaries(int $orderId, array $recalculated): void
    {
        sqlDelete(OrderTaxSummary::$table, 'order_id = '.$orderId);

        $order = $recalculated['order'];
        $lines = [];

        foreach ($recalculated['items'] as $item) {
            $lines[] = [
                'total' => round((float) $item['line_total'] - (float) ($item['order_discount_amount'] ?? 0), 2),
                'rate' => (float) ($item['tax_rate'] ?? 0),
                'nature' => (string) ($item['tax_nature'] ?? ''),
            ];
        }

        $includeTax = (string) ($order['prices_include_tax'] ?? 'true') === 'true';

        foreach (TaxTotals::summaries($lines, $includeTax) as $summary) {
            OrderTaxSummary::create([
                'order_id' => $orderId,
                'rate' => number_format($summary['rate'], 2, '.', ''),
                'taxable' => number_format($summary['taxable'], 2, '.', ''),
                'tax' => number_format($summary['tax'], 2, '.', ''),
                'total' => number_format($summary['total'], 2, '.', ''),
                'nature' => $summary['nature'],
            ]);
        }
    }

    /**
     * Le righe che hanno merce dietro: quelle da prenotare.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function goods(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn (array $item): bool => (string) $item['type'] === 'product'
                && (int) ($item['product_id'] ?? 0) > 0
                && (float) $item['quantity'] > 0
        ));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $result): array
    {
        if (!is_array($result) || $result === []) {
            return [];
        }

        return isset($result['id']) ? [$result] : array_values(array_filter($result, 'is_array'));
    }
}
```

- [ ] **Step 4: Aggiungi le frasi d'errore**

In `lang/it/gestionale.json`, dentro `gestionale.errors.order`:

```json
"empty_cart": "Il carrello è vuoto.",
"payment_method_unavailable": "Questo metodo di pagamento non è disponibile."
```

- [ ] **Step 5: Guarda i test passare**

Run: `php tests/integrazione/CheckoutTest.php`
Expected: PASS, 10 su 10

- [ ] **Step 6: Tutta la suite**

Run: `php tests/run.php`
Expected: tutti verdi, zero falliti

- [ ] **Step 7: Commit**

```bash
git add src/Support/Orders/Checkout.php tests/integrazione/CheckoutTest.php lang/it/gestionale.json
git commit -m "$(cat <<'EOF'
Porta il carrello a ordine senza lasciare niente a metà

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Le scadenze, e il giro dello scheduler

**Files:**
- Create: `src/Support/Orders/Expiry.php`
- Create: `src/Scheduler/ExpiryTask.php`
- Create: `tests/integrazione/ExpiryTest.php`
- Modify: `src/Gestionale.php`
- Modify: `tests/TasksTest.php`
- Modify: `TODO.md`

**Interfaces:**
- Consumes: `Lifecycle::cancel()`, `Allocation::release()`, `OrderNotifier::send()`, `PaymentTiming::of()`, `StatusLogger::record()`, `Wonder\App\Scheduler\AbstractTask`, `Wonder\App\Scheduler\Context`.
- Produces:
  - `Expiry::REMINDER_FIELD = 'payment_reminder'`
  - `Expiry::run(?string $now = null): array{released: int, reminded: int, cancelled: int, orders: list<int>}`
  - `ExpiryTask` con `key() === 'gestionale.order_expiry'`, `expression() === '0 * * * *'`, `enabled() === false`, `timeout() === 300`.

**Il promemoria non ha bisogno di una colonna.** Che il promemoria sia già partito si legge dalla storia dell'ordine: `StatusLogger::record(OrderStatusLog::class, $id, 'payment_reminder', '', 'sent', 'cron')`. Prima di mandarlo si cerca quella riga. Una colonna in più su `gst_orders` direbbe la stessa cosa in un posto dove nessun altro guarda.

**Il lock lo mette il core.** Il `Worker` dello scheduler avvolge ogni task in `NamedLock::run('scheduler:task:'.$key, ...)`: due giri non si sovrappongono e `Expiry` non deve gestirlo da sé.

- [ ] **Step 1: Scrivi il test rosso**

Crea `tests/integrazione/ExpiryTest.php`:

```php
<?php
/** php tests/integrazione/ExpiryTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\Expiry;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
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

/** Conta le email che partono dentro il corpo. */
function conPosta(callable $corpo): array
{
    $partite = [];
    Mailer::useTransport(static function (string $to) use (&$partite): bool {
        $partite[] = $to;

        return true;
    });

    try {
        $esito = $corpo();
    } finally {
        Mailer::useTransport(null);
    }

    return [$esito, $partite];
}

/**
 * Un ordine in attesa, prenotato, ordinato nel momento che si vuole.
 *
 * @return array{0: int, 1: int}
 */
function ordineInAttesa(string $orderedAt, ?string $expiresAt): array
{
    $prodotto = articoloConGiacenza(5, 'TST-SCAD-'.substr((string) microtime(true), -6));
    $ordine = ordineDiProva(40.0);
    Order::update([
        'email' => 'cliente@example.test',
        'order_number' => date('Y').'/09'.substr((string) microtime(true), -4),
        'ordered_at' => $orderedAt,
    ], $ordine);
    $riga = OrderItem::create([
        'order_id' => $ordine,
        'type' => 'product',
        'product_id' => $prodotto,
        'position' => 1,
        'name' => 'Crema da prova',
        'quantity' => '2.000',
        'unit_price' => '20.00',
        'line_total' => '40.00',
    ]);

    Allocation::reserve([
        'product_id' => $prodotto,
        'quantity' => 2,
        'order_id' => $ordine,
        'order_item_id' => (int) ($riga->insert_id ?? 0),
        'expires_at' => $expiresAt ?? '',
    ]);

    return [$ordine, $prodotto];
}

check('la prenotazione scaduta libera la merce', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordineInAttesa(
            date('Y-m-d H:i:s', strtotime('-2 hours')),
            date('Y-m-d H:i:s', strtotime('-1 hour'))
        );
        $prima = Levels::of($prodotto)['available'];

        [$esito] = conPosta(static fn (): array => Expiry::run());

        return $esito['released'] >= 1
            && Levels::of($prodotto)['available'] === round($prima + 2, 3)
            && (int) sqlCount(StockReservation::$table, "order_id = {$ordine} AND released_at IS NULL AND deleted = 'false'") === 0;
    });
});

check('la prenotazione ancora buona non si tocca', function () {
    return prova(static function (): bool {
        [$ordine, $prodotto] = ordineInAttesa(
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', strtotime('+1 hour'))
        );
        $prima = Levels::of($prodotto)['available'];

        conPosta(static fn (): array => Expiry::run());

        return Levels::of($prodotto)['available'] === $prima
            && (int) sqlCount(StockReservation::$table, "order_id = {$ordine} AND released_at IS NULL AND deleted = 'false'") === 1;
    });
});

check('a metà strada parte il promemoria, una volta sola', function () {
    return prova(static function (): bool {
        $giorni = (int) (Wonder\Plugin\Gestionale\Models\System\Setting::current()['order_payment_wait_days'] ?? 7);
        [$ordine] = ordineInAttesa(
            date('Y-m-d H:i:s', strtotime('-'.max(1, (int) ceil($giorni / 2)).' days')),
            date('Y-m-d H:i:s', strtotime('+1 day'))
        );

        [$primo, $partite] = conPosta(static fn (): array => Expiry::run());
        [$secondo] = conPosta(static fn (): array => Expiry::run());

        return $primo['reminded'] === 1
            && $partite === ['cliente@example.test']
            && $secondo['reminded'] === 0
            && (int) sqlCount(
                OrderStatusLog::$table,
                "order_id = {$ordine} AND field = 'payment_reminder' AND deleted = 'false'"
            ) === 1;
    });
});

check('scaduto il tempo l\'ordine si annulla e la merce torna libera', function () {
    return prova(static function (): bool {
        $giorni = (int) (Wonder\Plugin\Gestionale\Models\System\Setting::current()['order_payment_wait_days'] ?? 7);
        [$ordine, $prodotto] = ordineInAttesa(
            date('Y-m-d H:i:s', strtotime('-'.($giorni + 1).' days')),
            date('Y-m-d H:i:s', strtotime('-1 hour'))
        );
        $prima = Levels::of($prodotto);

        [$esito, $partite] = conPosta(static fn (): array => Expiry::run());
        $riga = Order::findById($ordine);
        $dopo = Levels::of($prodotto);

        return $esito['cancelled'] === 1
            && in_array($ordine, $esito['orders'], true)
            && $riga['status'] === 'cancelled'
            && $dopo['quantity'] === $prima['quantity']
            && $dopo['available'] === $prima['quantity']
            // Una al cliente, una al commerciante.
            && count($partite) >= 1;
    });
});

check('l\'ordine confermato non lo tocca nessuno', function () {
    return prova(static function (): bool {
        $giorni = (int) (Wonder\Plugin\Gestionale\Models\System\Setting::current()['order_payment_wait_days'] ?? 7);
        [$ordine] = ordineInAttesa(
            date('Y-m-d H:i:s', strtotime('-'.($giorni + 1).' days')),
            date('Y-m-d H:i:s', strtotime('-1 hour'))
        );

        Mailer::useTransport(static fn (): bool => true);

        try {
            Lifecycle::confirm($ordine, ['payment' => false]);
            $esito = Expiry::run();
        } finally {
            Mailer::useTransport(null);
        }

        return $esito['cancelled'] === 0 && Order::findById($ordine)['status'] === 'confirmed';
    });
});

check('l\'ordine già annullato non si annulla di nuovo e non riscrive email', function () {
    return prova(static function (): bool {
        $giorni = (int) (Wonder\Plugin\Gestionale\Models\System\Setting::current()['order_payment_wait_days'] ?? 7);
        [$ordine] = ordineInAttesa(
            date('Y-m-d H:i:s', strtotime('-'.($giorni + 1).' days')),
            date('Y-m-d H:i:s', strtotime('-1 hour'))
        );

        conPosta(static fn (): array => Expiry::run());
        [$secondo, $partite] = conPosta(static fn (): array => Expiry::run());

        return $secondo['cancelled'] === 0
            && $partite === []
            && (string) Order::findById($ordine)['status'] === 'cancelled';
    });
});

summary();
```

- [ ] **Step 2: Guarda il test fallire**

Run: `php tests/integrazione/ExpiryTest.php`
Expected: FAIL — `Class "Wonder\Plugin\Gestionale\Support\Orders\Expiry" not found`

- [ ] **Step 3: Scrivi `Expiry`**

Crea `src/Support/Orders/Expiry.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;

/**
 * Il giro che tiene pulito il magazzino: chi non paga libera la merce.
 *
 * Tre cose, in quest'ordine. Le prenotazioni scadute si liberano — la carta
 * abbandonata dopo mezz'ora, il bonifico che non arriva. A metà dell'attesa
 * parte un promemoria, una volta sola. Alla fine dell'attesa l'ordine si
 * annulla, e per annullarlo si passa da `Lifecycle::cancel()` come tutti gli
 * altri: un annullamento scritto qui a mano lascerebbe la storia dell'ordine
 * diversa da quella di un annullamento fatto dal backend.
 *
 * Il lock non lo mette questa classe: il `Worker` dello scheduler avvolge ogni
 * attività in `NamedLock`, e due giri non si sovrappongono.
 */
final class Expiry
{
    /** Il campo con cui si annota nella storia che il promemoria è partito. */
    public const REMINDER_FIELD = 'payment_reminder';

    /** Gli stati in cui un ordine aspetta ancora il denaro. */
    private const WAITING = ['pending'];

    /**
     * @return array{released: int, reminded: int, cancelled: int, orders: list<int>}
     */
    public static function run(?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $settings = Setting::current();
        $days = (int) ($settings['order_payment_wait_days'] ?? 7);
        $result = ['released' => 0, 'reminded' => 0, 'cancelled' => 0, 'orders' => []];

        foreach (self::expiredOrders($now) as $orderId) {
            $order = Order::findById($orderId);

            if (!is_array($order) || !in_array((string) $order['status'], self::WAITING, true)) {
                continue;
            }

            $result['released'] += Allocation::release(['order_id' => $orderId]);
        }

        foreach (self::waitingOrders() as $order) {
            $orderId = (int) $order['id'];
            $ordered = strtotime((string) $order['ordered_at']);

            if ($ordered === false || $days <= 0) {
                continue;
            }

            $deadline = $ordered + $days * 86400;
            $moment = strtotime($now);

            if ($moment >= $deadline) {
                Lifecycle::cancel($orderId, [
                    'reason' => 'Pagamento non ricevuto entro il termine',
                    'source' => 'cron',
                    'merchant_notice' => true,
                ]);
                ++$result['cancelled'];
                $result['orders'][] = $orderId;

                continue;
            }

            if ($moment >= $ordered + (int) floor($days * 86400 / 2) && !self::reminded($orderId)) {
                OrderNotifier::send('reminder', $orderId, [
                    'deadline' => date('Y-m-d H:i:s', $deadline),
                ]);
                StatusLogger::record(
                    OrderStatusLog::class,
                    $orderId,
                    self::REMINDER_FIELD,
                    '',
                    'sent',
                    'cron'
                );
                ++$result['reminded'];
            }
        }

        return $result;
    }

    /**
     * Gli ordini con almeno una prenotazione scaduta e mai rilasciata.
     *
     * @return list<int>
     */
    private static function expiredOrders(string $now): array
    {
        $rows = StockReservation::find(
            "released_at IS NULL AND expires_at IS NOT NULL AND expires_at > '0000-00-00 00:00:00'"
            ." AND expires_at <= '".addslashes($now)."' AND deleted = 'false'"
        );

        $orders = [];

        foreach (self::rows($rows) as $row) {
            $orderId = (int) ($row['order_id'] ?? 0);

            if ($orderId > 0) {
                $orders[$orderId] = true;
            }
        }

        return array_map('intval', array_keys($orders));
    }

    /**
     * Gli ordini che stanno ancora aspettando il denaro.
     *
     * @return list<array<string, mixed>>
     */
    private static function waitingOrders(): array
    {
        return self::rows(Order::find([
            'stage' => 'order',
            'status' => 'pending',
            'deleted' => 'false',
        ]));
    }

    /** Il promemoria di questo ordine è già partito? */
    private static function reminded(int $orderId): bool
    {
        $row = OrderStatusLog::find([
            'order_id' => $orderId,
            'field' => self::REMINDER_FIELD,
            'deleted' => 'false',
        ], 1);

        return is_array($row) && $row !== [];
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $result): array
    {
        if (!is_array($result) || $result === []) {
            return [];
        }

        return isset($result['id']) ? [$result] : array_values(array_filter($result, 'is_array'));
    }
}
```

Nomi delle colonne del log, già verificati: `OrderStatusLog::entityColumn()` ritorna `order_id`, e le colonne comuni di `StatusLog` sono `field`, `from_value`, `to_value`, `source`, `user_id`, `message`, `response`. Le due `find()` di questa classe cercano su `order_id` e `field`: sono quelli giusti, non c'è niente da adattare. La colonna del testo libero si chiama `message` (non `note`), se mai ti servisse.

- [ ] **Step 4: Scrivi il task dello scheduler**

Crea `src/Scheduler/ExpiryTask.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Scheduler;

use Wonder\App\Scheduler\AbstractTask;
use Wonder\App\Scheduler\Context;
use Wonder\Plugin\Gestionale\Support\Orders\Expiry;

/**
 * Prenotazioni scadute, promemoria e annullamenti, una volta all'ora.
 *
 * Nasce **spenta**, come le altre: la si accende dalla pagina delle attività
 * quando il negozio comincia a vendere davvero. Finché è spenta la merce di un
 * ordine mai pagato resta impegnata, ed è una scelta che il commerciante deve
 * fare sapendolo.
 */
final class ExpiryTask extends AbstractTask
{
    public function key(): string
    {
        return 'gestionale.order_expiry';
    }

    public function label(): string
    {
        return 'Gestionale: prenotazioni e pagamenti scaduti';
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
        $result = Expiry::run();

        return [
            'released' => $result['released'],
            'reminded' => $result['reminded'],
            'cancelled' => $result['cancelled'],
        ];
    }
}
```

- [ ] **Step 5: Dichiara il task e aggiorna il suo test**

In `src/Gestionale.php`:

```php
        return [new ImagesTask(), new StockAlertsTask(), new ExpiryTask()];
```

più l'`use Wonder\Plugin\Gestionale\Scheduler\ExpiryTask;` accanto agli altri.

In `tests/TasksTest.php`, l'ultimo check passa da due a tre chiavi:

```php
    return count($chiavi) === 3;
```

- [ ] **Step 6: Guarda i test passare**

Run: `php tests/integrazione/ExpiryTest.php`
Expected: PASS, 6 su 6

Run: `php tests/TasksTest.php`
Expected: PASS — tre chiavi, tutte nel formato buono

- [ ] **Step 7: Tutta la suite**

Run: `php tests/run.php`
Expected: tutti verdi, zero falliti

- [ ] **Step 8: Segna il piano fatto nel TODO**

In `TODO.md`, la riga del Piano 3 di G4 diventa:

```markdown
  - [x] Piano 3 **fatto** (2026-09-30): `docs/superpowers/plans/2026-09-30-carrello-checkout-e-ciclo-di-vita.md` — `Cart` (apri, aggiungi, cambia, togli, unisci, ricalcola) che non prenota niente, `PaymentTiming` con la colonna `timing` sui metodi di pagamento, le sei email su due viste con i testi nel file di lingua, `Lifecycle` come unico punto che scrive lo `status`, `Checkout` in una transazione sola (dati, commissione, ricalcolo, prenotazione, numero, pagamento, riepiloghi IVA), `Expiry` con `ExpiryTask` orario. Sette task, ramo `feature/ordini-carrello-e-checkout`. **Chi aggiorna deve rilanciare `PaymentMethod::createTable()`** per la colonna `timing`.
```

- [ ] **Step 9: Commit**

```bash
git add src/Support/Orders/Expiry.php src/Scheduler/ExpiryTask.php tests/integrazione/ExpiryTest.php src/Gestionale.php tests/TasksTest.php TODO.md
git commit -m "$(cat <<'EOF'
Libera la merce di chi non paga, e lo avvisa prima

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```
