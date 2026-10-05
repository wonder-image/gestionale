<?php

namespace Wonder\Plugin\Gestionale\Support\Promotions;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponCustomer;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Sql\Transaction;

/**
 * I coupon: l'unica porta da cui carrello e checkout leggono e applicano un
 * codice (§4 della spec di G6).
 *
 * Le regole stanno in `CouponRules`, che è pura; qui si raccoglie dal
 * database quello che le regole chiedono — selettore, clienti riservati, usi
 * consumati, ordini precedenti — e si scrive sul carrello. Il coupon sta sul
 * carrello (`coupon_id`, `coupon_code`) ed è **ricontrollato a ogni
 * ricalcolo**: un coupon che smette di reggere (la spesa scende sotto la
 * soglia, il coupon scade) esce dal carrello e chi chiama ne trova il motivo.
 *
 * Con la funzionalità `coupons` spenta nessun coupon si applica, ma quello già
 * scritto sul carrello resta dov'è: riaccesa la funzionalità, torna a valere.
 */
final class Coupons
{
    /** Il coupon con quel codice, senza badare a maiuscole e spazi; `null` se non c'è o è cancellato. */
    public static function find(string $code): ?array
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        $found = Coupon::find(['code' => $code, 'deleted' => 'false'], 1);

        return is_array($found) && (int) ($found['id'] ?? 0) > 0 ? $found : null;
    }

    /**
     * Applica un codice al carrello e ridà il carrello ricalcolato.
     *
     * @return array<string, mixed>
     */
    public static function apply(int $cartId, string $code): array
    {
        return Transaction::run(static function () use ($cartId, $code): array {
            if (!Gestionale::feature('coupons')) {
                throw UserError::make('coupon.inactive');
            }

            $coupon = self::find($code) ?? throw UserError::make('coupon.unknown');

            // Si valuta su righe e prezzi di adesso, non su quelli dell'ultima volta.
            Cart::recalculate($cartId);
            $result = self::evaluate($cartId, $coupon, date('Y-m-d H:i:s'));

            if (!$result['ok']) {
                throw self::reject($result['reason']);
            }

            Order::update(['coupon_id' => (int) $coupon['id'], 'coupon_code' => (string) $coupon['code']], $cartId);

            return Cart::recalculate($cartId);
        });
    }

    /**
     * Toglie il coupon dal carrello e ridà il carrello ricalcolato.
     *
     * @return array<string, mixed>
     */
    public static function remove(int $cartId): array
    {
        return Transaction::run(static function () use ($cartId): array {
            self::detach($cartId);

            return Cart::recalculate($cartId);
        });
    }

    /**
     * Il coupon sul carrello com'è adesso, valutato sulle righe scritte.
     * Serve ad `apply`; il ricalcolo usa `context`, che ha le righe fresche.
     *
     * Con `$lock` gli usi si contano con letture che bloccano (`FOR UPDATE`) e
     * vedono l'ultimo dato salvato, non quello della fotografia della
     * transazione: serve a `redeem`, dove due checkout contano insieme.
     *
     * @param array<string, mixed> $coupon
     * @return array<string, mixed> il risultato di `CouponRules::check`
     */
    public static function evaluate(int $cartId, array $coupon, string $now, bool $lock = false): array
    {
        $lines = [];

        foreach (self::rows(OrderItem::find(['order_id' => $cartId, 'deleted' => 'false'])) as $item) {
            if ((int) ($item['parent_item_id'] ?? 0) === 0) {
                $lines[] = $item;
            }
        }

        $cart = Order::findById($cartId);

        return CouponRules::check(self::withFacts($coupon, is_array($cart) ? $cart : [], $lock), self::cartFacts(is_array($cart) ? $cart : [], $lines), $now);
    }

    /**
     * Prende l'utilizzo del coupon dell'ordine: va chiamata **dentro** la
     * transazione di `Checkout::place`, dopo il ricalcolo con i dati veri del
     * checkout (email, cliente) già scritti sulla riga.
     *
     * Il coupon si legge con `FOR UPDATE`: due checkout sullo stesso coupon
     * passano uno alla volta, e il secondo conta gli usi dopo il commit del
     * primo. Le regole si rivalutano da capo; se non reggono, o se il
     * ricalcolo aveva già tolto il coupon (`coupon_dropped`), si lancia
     * l'errore del motivo e la transazione torna indietro: nessun ordine,
     * nessun utilizzo. Con la funzionalità spenta il coupon non vale e si
     * stacca dall'ordine, che non lo ha scontato.
     *
     * @param array<string, mixed> $recalculated il ritorno di `Cart::recalculate`
     */
    public static function redeem(int $orderId, array $recalculated): void
    {
        $dropped = (string) ($recalculated['coupon_dropped'] ?? '');

        if ($dropped !== '') {
            throw self::reject($dropped);
        }

        $order = Order::findById($orderId);
        $couponId = is_array($order) ? (int) ($order['coupon_id'] ?? 0) : 0;

        if ($couponId <= 0) {
            return;
        }

        if (!Gestionale::feature('coupons')) {
            self::detach($orderId);

            return;
        }

        $coupon = Coupon::findForUpdate(['id' => $couponId, 'deleted' => 'false'], 1);

        if (!is_array($coupon) || (int) ($coupon['id'] ?? 0) <= 0) {
            throw self::reject('unknown');
        }

        $result = self::evaluate($orderId, $coupon, date('Y-m-d H:i:s'), true);

        if (!$result['ok']) {
            throw self::reject($result['reason']);
        }

        CouponRedemption::create([
            'coupon_id' => $couponId,
            'order_id' => $orderId,
            'customer_id' => (int) ($order['customer_id'] ?? 0),
            'email' => trim((string) ($order['email'] ?? '')),
            'discount_amount' => number_format(self::saved($orderId, $coupon, $order), 2, '.', ''),
            'redeemed_at' => date('Y-m-d H:i:s'),
        ]);
        Order::update(['coupon_id' => $couponId, 'coupon_code' => (string) $coupon['code']], $orderId);
    }

    /**
     * Rimette a disposizione gli utilizzi dell'ordine (annullo, scadenza):
     * `released_at` prende l'ora di adesso, una volta sola. Un reso non passa
     * di qui — la merce che torna non restituisce lo sconto già dato.
     */
    public static function release(int $orderId): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::rows(CouponRedemption::find(['order_id' => $orderId])) as $row) {
            if (self::empty((string) ($row['released_at'] ?? ''))) {
                CouponRedemption::update(['released_at' => $now], (int) $row['id']);
            }
        }
    }

    /**
     * Quello che il coupon ha tolto: lo sconto sulla merce, o — per la
     * spedizione gratuita — quello che la spedizione sarebbe costata.
     *
     * @param array<string, mixed> $coupon
     * @param array<string, mixed> $order
     */
    private static function saved(int $orderId, array $coupon, array $order): float
    {
        if ((string) $coupon['discount_type'] !== 'free_shipping') {
            return round((float) ($order['discount_total'] ?? 0), 2);
        }

        $saved = 0.0;

        foreach (self::rows(OrderItem::find(['order_id' => $orderId, 'type' => 'shipping', 'deleted' => 'false'])) as $item) {
            $saved += max(0.0, round((float) $item['list_price'] * (float) $item['quantity'] - (float) $item['line_total'], 2));
        }

        return round($saved, 2);
    }

    /**
     * Quello che il ricalcolo dà ai totali, dalle righe appena calcolate:
     * `discount_type`, `discount_value`, `free_shipping`, `discountable`
     * (riga → può essere scontata) e `amount`. Ridà `[]` se il carrello non ha
     * un coupon che vale (o la funzionalità è spenta), e `['dropped' => motivo]`
     * se ce l'aveva e non regge più: in quel caso lo **toglie** dal carrello.
     *
     * Le righe sono quelle di `Cart::recalculate`: `id`, `type`, `product_id`,
     * `price_source`, `line_total`.
     *
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public static function context(int $cartId, array $lines, string $now): array
    {
        if (!Gestionale::feature('coupons')) {
            return [];
        }

        $cart = Order::findById($cartId);
        $couponId = is_array($cart) ? (int) ($cart['coupon_id'] ?? 0) : 0;

        if ($couponId <= 0) {
            return [];
        }

        $coupon = Coupon::find(['id' => $couponId, 'deleted' => 'false'], 1);

        if (!is_array($coupon) || (int) ($coupon['id'] ?? 0) <= 0) {
            self::detach($cartId);

            return ['dropped' => 'unknown'];
        }

        $result = CouponRules::check(self::withFacts($coupon, $cart), self::cartFacts($cart, $lines), $now);

        if (!$result['ok']) {
            self::detach($cartId);

            return ['dropped' => $result['reason']];
        }

        $discountable = [];

        foreach (array_values($lines) as $index => $line) {
            $discountable[(int) ($line['id'] ?? 0)] = in_array($index, $result['eligible'], true);
        }

        $type = (string) $coupon['discount_type'];
        $value = (float) ($coupon['discount_value'] ?? 0);

        return [
            'discount_type' => $type === 'free_shipping' ? 'none' : $type,
            // L'importo fisso non supera mai la merce adatta; la percentuale
            // si applica da sola alla stessa base.
            'discount_value' => $type === 'amount' ? $result['amount'] : $value,
            'free_shipping' => $type === 'free_shipping',
            'discountable' => $discountable,
            'amount' => $result['amount'],
        ];
    }

    /** Il rifiuto con la frase del motivo (`gestionale.errors.coupon.<motivo>`). */
    private static function reject(string $reason): UserError
    {
        return UserError::make(implode('.', ['coupon', $reason]));
    }

    private static function detach(int $cartId): void
    {
        Order::update(['coupon_id' => 0, 'coupon_code' => ''], $cartId);
    }

    /**
     * Il coupon con i fatti che `CouponRules` chiede.
     *
     * @param array<string, mixed> $coupon
     * @param array<string, mixed> $cart
     * @return array<string, mixed>
     */
    private static function withFacts(array $coupon, array $cart, bool $lock = false): array
    {
        $id = (int) $coupon['id'];
        $customerId = (int) ($cart['customer_id'] ?? 0);
        $email = mb_strtolower(trim((string) ($cart['email'] ?? '')));
        $used = array_filter(
            self::rows($lock ? CouponRedemption::findForUpdate(['coupon_id' => $id]) : CouponRedemption::find(['coupon_id' => $id])),
            static fn (array $row): bool => self::empty((string) ($row['released_at'] ?? ''))
        );

        $mine = array_filter($used, static function (array $row) use ($customerId, $email): bool {
            if ($customerId > 0) {
                return (int) ($row['customer_id'] ?? 0) === $customerId;
            }

            return $email !== '' && mb_strtolower(trim((string) ($row['email'] ?? ''))) === $email;
        });

        return $coupon + [
            'scope' => ProductScope::of('coupon', $id),
            'customers' => array_map(
                static fn (array $row): int => (int) $row['customer_id'],
                self::rows(CouponCustomer::find(['coupon_id' => $id]))
            ),
            'used_total' => count($used),
            'used_by_customer' => count($mine),
            'has_previous_orders' => self::hasPreviousOrders((int) ($cart['id'] ?? 0), $customerId, $email),
        ];
    }

    /** Chi ha già un ordine vero (non annullato, non il carrello di adesso). */
    private static function hasPreviousOrders(int $cartId, int $customerId, string $email): bool
    {
        $where = $customerId > 0
            ? ['customer_id' => $customerId, 'deleted' => 'false']
            : ($email !== '' ? ['email' => $email, 'deleted' => 'false'] : null);

        if ($where === null) {
            return false;
        }

        foreach (self::rows(Order::find($where)) as $order) {
            if ((int) $order['id'] !== $cartId
                && (string) $order['stage'] !== 'cart'
                && in_array((string) $order['status'], Order::LIVE_STATUSES, true)
                && (string) $order['status'] !== 'cancelled') {
                return true;
            }
        }

        return false;
    }

    /**
     * Il carrello nella forma di `CouponRules`.
     *
     * @param array<string, mixed> $cart
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private static function cartFacts(array $cart, array $lines): array
    {
        $facts = [];
        $out = [];

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $isProduct = (string) ($line['type'] ?? 'product') === 'product' && $productId > 0;

            if ($isProduct && !isset($facts[$productId])) {
                $facts[$productId] = ProductScope::facts($productId);
            }

            $out[] = [
                'facts' => $isProduct ? $facts[$productId] : [],
                'price_source' => (string) ($line['price_source'] ?? 'base'),
                'line_total' => (string) ($line['line_total'] ?? 0),
                'is_product' => $isProduct,
            ];
        }

        return [
            'channel' => (string) ($cart['channel'] ?? ''),
            'customer_id' => (int) ($cart['customer_id'] ?? 0),
            'email' => (string) ($cart['email'] ?? ''),
            'has_manual_discount' => (string) ($cart['manual_discount_type'] ?? 'none') !== 'none'
                && (float) ($cart['manual_discount_value'] ?? 0) > 0,
            'lines' => $out,
        ];
    }

    private static function empty(string $date): bool
    {
        $date = trim($date);

        return $date === '' || str_starts_with($date, '0000-00-00');
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
