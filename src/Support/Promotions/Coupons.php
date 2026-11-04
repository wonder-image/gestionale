<?php

namespace Wonder\Plugin\Gestionale\Support\Promotions;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponCode;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponCustomer;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;
use Wonder\Plugin\Gestionale\Models\Promotions\OrderCoupon;
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
 * consumati, ordini precedenti — e si scrive sul carrello. I coupon del
 * carrello stanno in `gst_order_coupons`, nell'ordine in cui sono stati
 * scritti; `coupon_id` e `coupon_code` dell'ordine ne sono lo specchio. Ogni
 * coupon è **ricontrollato a ogni ricalcolo**: quello che smette di reggere
 * (la spesa scende sotto la soglia, il coupon scade, non è più cumulabile)
 * esce dal carrello e chi chiama ne trova il motivo.
 *
 * Più coupon stanno insieme solo se sono tutti cumulabili (gestionale#113).
 * Un codice generato in blocco porta le regole del suo coupon e vale una
 * volta sola.
 *
 * Con la funzionalità `coupons` spenta nessun coupon si applica, ma quelli già
 * scritti sul carrello restano dove sono: riaccesa la funzionalità, tornano a
 * valere.
 */
final class Coupons
{
    /**
     * Il coupon con quel codice, senza badare a maiuscole e spazi: prima fra i
     * coupon (il codice di un coupon in blocco non vale), poi fra i codici
     * generati. La riga è quella del coupon, con `code` = il codice trovato e
     * `coupon_code_id` (0 per il codice del coupon); `null` se non c'è o il
     * coupon è cancellato.
     */
    public static function find(string $code): ?array
    {
        $code = (string) preg_replace('/\s+/u', '', $code);

        if ($code === '') {
            return null;
        }

        $found = Coupon::find(['code' => $code, 'deleted' => 'false'], 1);

        if (is_array($found) && (int) ($found['id'] ?? 0) > 0) {
            return (string) ($found['batch'] ?? 'false') === 'true' ? null : ['coupon_code_id' => 0] + $found;
        }

        $generated = CouponCode::find(['code' => $code], 1);

        if (!is_array($generated) || (int) ($generated['id'] ?? 0) <= 0) {
            return null;
        }

        $coupon = Coupon::find(['id' => (int) $generated['coupon_id'], 'deleted' => 'false'], 1);

        if (!is_array($coupon) || (int) ($coupon['id'] ?? 0) <= 0) {
            return null;
        }

        return ['code' => (string) $generated['code'], 'coupon_code_id' => (int) $generated['id']] + $coupon;
    }

    /**
     * Applica un codice al carrello e ridà il carrello ricalcolato. Se il
     * coupon nuovo e quelli già applicati sono tutti cumulabili si
     * **aggiunge**; altrimenti **sostituisce** quelli che c'erano.
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
            $applied = self::applied($cartId);

            // Due codici dello stesso blocco sono lo stesso coupon.
            foreach ($applied as $row) {
                if ((int) $row['coupon_id'] === (int) $coupon['id']) {
                    throw UserError::make('coupon.already_applied');
                }
            }

            $result = self::evaluate($cartId, $coupon, date('Y-m-d H:i:s'));

            if (!$result['ok']) {
                throw self::reject($result['reason']);
            }

            $kept = array_values(array_filter(array_map(
                static fn (array $row): ?array => self::couponOf((int) $row['coupon_id']),
                $applied
            )));

            if (!CouponRules::stacks($kept, $coupon)) {
                self::clear($cartId);
                $applied = [];
            }

            OrderCoupon::create([
                'order_id' => $cartId,
                'coupon_id' => (int) $coupon['id'],
                'coupon_code_id' => (int) $coupon['coupon_code_id'],
                'code' => (string) $coupon['code'],
                'position' => $applied === [] ? 0 : max(array_map(static fn (array $row): int => (int) $row['position'], $applied)) + 1,
                'discount_amount' => '0.00',
            ]);
            self::mirror($cartId);

            return Cart::recalculate($cartId);
        });
    }

    /**
     * Toglie dal carrello il coupon con quel codice (tutti con `null`) e ridà
     * il carrello ricalcolato.
     *
     * @return array<string, mixed>
     */
    public static function remove(int $cartId, ?string $code = null): array
    {
        return Transaction::run(static function () use ($cartId, $code): array {
            if ($code === null) {
                self::detach($cartId);

                return Cart::recalculate($cartId);
            }

            $code = mb_strtolower((string) preg_replace('/\s+/u', '', $code));
            self::materialize($cartId);

            foreach (self::applied($cartId) as $row) {
                if (mb_strtolower((string) $row['code']) === $code) {
                    OrderCoupon::delete((int) $row['id']);
                }
            }

            self::mirror($cartId);

            return Cart::recalculate($cartId);
        });
    }

    /**
     * I coupon del carrello o dell'ordine, nell'ordine in cui sono stati
     * applicati: righe di `gst_order_coupons` (`id`, `coupon_id`,
     * `coupon_code_id`, `code`, `position`, `discount_amount`). Un carrello di
     * prima, con il solo `coupon_id`, ridà una riga con `id` 0.
     *
     * @return list<array<string, mixed>>
     */
    public static function applied(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $rows = self::rows(OrderCoupon::find(['order_id' => $orderId]));

        if ($rows !== []) {
            usort($rows, static fn (array $a, array $b): int => [(int) $a['position'], (int) $a['id']] <=> [(int) $b['position'], (int) $b['id']]);

            return $rows;
        }

        $order = Order::findById($orderId);
        $couponId = is_array($order) ? (int) ($order['coupon_id'] ?? 0) : 0;

        if ($couponId <= 0) {
            return [];
        }

        return [[
            'id' => 0,
            'order_id' => $orderId,
            'coupon_id' => $couponId,
            'coupon_code_id' => 0,
            'code' => (string) ($order['coupon_code'] ?? ''),
            'position' => 0,
            'discount_amount' => (string) ($order['discount_total'] ?? '0.00'),
        ]];
    }

    /**
     * Un coupon com'è adesso, valutato sulle righe scritte del carrello.
     * Serve ad `apply` e a `redeem`; il ricalcolo usa `context`, che ha le
     * righe fresche.
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
     * Prende un utilizzo per ogni coupon dell'ordine: va chiamata **dentro**
     * la transazione di `Checkout::place`, dopo il ricalcolo con i dati veri
     * del checkout (email, cliente) già scritti sulla riga.
     *
     * Ogni coupon si legge con `FOR UPDATE`: due checkout sullo stesso coupon
     * (o su due codici dello stesso blocco) passano uno alla volta, e il
     * secondo conta gli usi dopo il commit del primo. Le regole si rivalutano
     * da capo; se non reggono, o se il ricalcolo aveva già tolto un coupon
     * (`coupon_dropped`), si lancia l'errore del motivo e la transazione torna
     * indietro: nessun ordine, nessun utilizzo. Con la funzionalità spenta i
     * coupon non valgono e si staccano dall'ordine, che non li ha scontati.
     *
     * L'importo di ogni utilizzo è quello che il coupon ha tolto all'ultimo
     * ricalcolo; per la spedizione gratuita, la spedizione risparmiata.
     *
     * @param array<string, mixed> $recalculated il ritorno di `Cart::recalculate`
     */
    public static function redeem(int $orderId, array $recalculated): void
    {
        $dropped = (string) ($recalculated['coupon_dropped'] ?? '');

        if ($dropped !== '') {
            throw self::reject($dropped);
        }

        $applied = self::applied($orderId);

        if ($applied === []) {
            return;
        }

        if (!Gestionale::feature('coupons')) {
            self::detach($orderId);

            return;
        }

        $order = (array) Order::findById($orderId);
        $now = date('Y-m-d H:i:s');

        foreach ($applied as $row) {
            $coupon = Coupon::findForUpdate(['id' => (int) $row['coupon_id'], 'deleted' => 'false'], 1);

            if (!is_array($coupon) || (int) ($coupon['id'] ?? 0) <= 0) {
                throw self::reject('unknown');
            }

            $coupon = ['code' => (string) $row['code'], 'coupon_code_id' => (int) $row['coupon_code_id']] + $coupon;
            $result = self::evaluate($orderId, $coupon, $now, true);

            if (!$result['ok']) {
                throw self::reject($result['reason']);
            }

            CouponRedemption::create([
                'coupon_id' => (int) $coupon['id'],
                'coupon_code_id' => (int) $coupon['coupon_code_id'],
                'code' => (string) $coupon['code'],
                'order_id' => $orderId,
                'customer_id' => (int) ($order['customer_id'] ?? 0),
                'email' => trim((string) ($order['email'] ?? '')),
                'discount_amount' => number_format(round((float) ($row['discount_amount'] ?? 0), 2), 2, '.', ''),
                'redeemed_at' => $now,
            ]);
        }

        self::mirror($orderId);
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
     * Quello che il ricalcolo dà ai totali, dalle righe appena calcolate:
     * `discounts` (per `OrderTotals`: uno per coupon tenuto, con `type`,
     * `value` e `lines`, gli id delle righe che può scontare; la spedizione
     * gratuita vi sta come `none`), `free_shipping`, `coupons` (i coupon
     * tenuti, nello stesso ordine) e `dropped` (il motivo del primo coupon
     * uscito, o vuoto). Ridà `[]` se il carrello non ha coupon (o la
     * funzionalità è spenta).
     *
     * I coupon che non reggono più, o che non stanno con quelli prima di
     * loro, **escono** dal carrello. La spesa minima di ognuno si misura sulle
     * righe prima dei coupon.
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

        self::materialize($cartId);
        $applied = self::applied($cartId);

        if ($applied === []) {
            return [];
        }

        $cart = (array) Order::findById($cartId);
        $lines = array_values($lines);
        $facts = self::cartFacts($cart, $lines);
        $kept = [];
        $discounts = [];
        $freeShipping = false;
        $dropped = '';

        foreach ($applied as $row) {
            $coupon = self::couponOf((int) $row['coupon_id']);
            $reason = '';

            // Il codice di un coupon passato in blocco non vale più.
            if ($coupon === null || ((string) ($coupon['batch'] ?? 'false') === 'true' && (int) $row['coupon_code_id'] <= 0)) {
                $reason = 'unknown';
            } else {
                $coupon = ['code' => (string) $row['code'], 'coupon_code_id' => (int) $row['coupon_code_id'], 'order_coupon_id' => (int) $row['id']] + $coupon;
                $result = CouponRules::check(self::withFacts($coupon, $cart), $facts, $now);

                if (!$result['ok']) {
                    $reason = $result['reason'];
                } elseif (!CouponRules::stacks($kept, $coupon)) {
                    $reason = 'not_stackable';
                }
            }

            if ($reason !== '') {
                OrderCoupon::delete((int) $row['id']);
                $dropped = $dropped === '' ? $reason : $dropped;

                continue;
            }

            $type = (string) $coupon['discount_type'];
            $kept[] = $coupon;
            $freeShipping = $freeShipping || $type === 'free_shipping';
            $discounts[] = [
                'type' => $type === 'free_shipping' ? 'none' : $type,
                'value' => $type === 'free_shipping' ? 0.0 : (float) ($coupon['discount_value'] ?? 0),
                'lines' => array_map(static fn (int $index): int => (int) ($lines[$index]['id'] ?? 0), $result['eligible']),
            ];
        }

        if ($dropped !== '') {
            self::mirror($cartId);
        }

        if ($kept === []) {
            return ['dropped' => $dropped];
        }

        return ['discounts' => $discounts, 'free_shipping' => $freeShipping, 'coupons' => $kept, 'dropped' => $dropped];
    }

    /**
     * Scrive su ogni coupon tenuto quanto ha tolto: l'importo che `OrderTotals`
     * gli ha dato e, al primo di spedizione gratuita, la spedizione
     * risparmiata.
     *
     * @param array<string, mixed> $context il ritorno di `context`
     * @param array<string, mixed> $totals il ritorno di `OrderTotals::of`
     */
    public static function record(array $context, array $totals): void
    {
        $shipping = (string) ($totals['shipping_saved'] ?? '0.00');

        foreach (array_values((array) ($context['coupons'] ?? [])) as $index => $coupon) {
            $amount = (string) ($totals['discounts'][$index] ?? '0.00');

            if ((string) $coupon['discount_type'] === 'free_shipping') {
                [$amount, $shipping] = [$shipping, '0.00'];
            }

            OrderCoupon::update(['discount_amount' => $amount], (int) $coupon['order_coupon_id']);
        }
    }

    /** Il rifiuto con la frase del motivo (`gestionale.errors.coupon.<motivo>`). */
    private static function reject(string $reason): UserError
    {
        return UserError::make(implode('.', ['coupon', $reason]));
    }

    private static function detach(int $cartId): void
    {
        self::clear($cartId);
        Order::update(['coupon_id' => 0, 'coupon_code' => ''], $cartId);
    }

    private static function clear(int $orderId): void
    {
        foreach (self::rows(OrderCoupon::find(['order_id' => $orderId])) as $row) {
            OrderCoupon::delete((int) $row['id']);
        }
    }

    /** Lo specchio sull'ordine: il primo coupon e i codici separati da «, ». */
    private static function mirror(int $orderId): void
    {
        $applied = array_filter(self::applied($orderId), static fn (array $row): bool => (int) $row['id'] > 0);
        $first = reset($applied);

        Order::update([
            'coupon_id' => is_array($first) ? (int) $first['coupon_id'] : 0,
            'coupon_code' => mb_substr(implode(', ', array_map(static fn (array $row): string => (string) $row['code'], $applied)), 0, 255),
        ], $orderId);
    }

    /** Un carrello di prima, con il solo `coupon_id`, prende la sua riga in `gst_order_coupons`. */
    private static function materialize(int $orderId): void
    {
        foreach (self::applied($orderId) as $row) {
            if ((int) $row['id'] === 0) {
                OrderCoupon::create(array_diff_key($row, ['id' => true]));
            }
        }
    }

    /** @return array<string, mixed>|null */
    private static function couponOf(int $couponId): ?array
    {
        $coupon = $couponId > 0 ? Coupon::find(['id' => $couponId, 'deleted' => 'false'], 1) : null;

        return is_array($coupon) && (int) ($coupon['id'] ?? 0) > 0 ? $coupon : null;
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
        $codeId = (int) ($coupon['coupon_code_id'] ?? 0);
        $customerId = (int) ($cart['customer_id'] ?? 0);
        $email = mb_strtolower(trim((string) ($cart['email'] ?? '')));
        $used = array_filter(
            self::rows($lock ? CouponRedemption::findForUpdate(['coupon_id' => $id]) : CouponRedemption::find(['coupon_id' => $id])),
            static fn (array $row): bool => self::empty((string) ($row['released_at'] ?? ''))
        );

        // Una persona è il suo account **o** la sua email: chi ha usato il codice da ospite non lo
        // riusa registrandosi con la stessa email.
        $mine = array_filter($used, static function (array $row) use ($customerId, $email): bool {
            return ($customerId > 0 && (int) ($row['customer_id'] ?? 0) === $customerId)
                || ($email !== '' && mb_strtolower(trim((string) ($row['email'] ?? ''))) === $email);
        });

        return $coupon + [
            'scope' => ProductScope::of('coupon', $id),
            'customers' => array_map(
                static fn (array $row): int => (int) $row['customer_id'],
                self::rows(CouponCustomer::find(['coupon_id' => $id]))
            ),
            'used_total' => count($used),
            'used_by_customer' => count($mine),
            'code_used' => $codeId > 0 && array_filter($used, static fn (array $row): bool => (int) ($row['coupon_code_id'] ?? 0) === $codeId) !== [],
            'has_previous_orders' => self::hasPreviousOrders((int) ($cart['id'] ?? 0), $customerId, $email),
        ];
    }

    /** Chi ha già un ordine vero (non annullato, non il carrello di adesso). */
    private static function hasPreviousOrders(int $cartId, int $customerId, string $email): bool
    {
        // L'account o l'email: un ordine fatto da ospite con questa email conta anche per il cliente registrato.
        $wheres = array_filter([
            $customerId > 0 ? ['customer_id' => $customerId, 'deleted' => 'false'] : null,
            $email !== '' ? ['email' => $email, 'deleted' => 'false'] : null,
        ]);

        foreach ($wheres as $where) {
            foreach (self::rows(Order::find($where)) as $order) {
                if ((int) $order['id'] !== $cartId
                    && (string) $order['stage'] !== 'cart'
                    && in_array((string) $order['status'], Order::LIVE_STATUSES, true)
                    && (string) $order['status'] !== 'cancelled') {
                    return true;
                }
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

    /**
     * Quante volte il coupon è stato usato: gli utilizzi non rilasciati (un
     * ordine annullato o scaduto restituisce il suo).
     */
    public static function usedCount(int $couponId): int
    {
        if ($couponId <= 0) {
            return 0;
        }

        return count(array_filter(
            self::rows(CouponRedemption::find(['coupon_id' => $couponId])),
            static fn (array $row): bool => self::empty((string) ($row['released_at'] ?? ''))
        ));
    }

    /** Lo sconto concesso finora: la somma degli utilizzi non rilasciati. */
    public static function discountGranted(int $couponId): float
    {
        if ($couponId <= 0) {
            return 0.0;
        }

        return round(array_sum(array_map(
            static fn (array $row): float => self::empty((string) ($row['released_at'] ?? '')) ? (float) ($row['discount_amount'] ?? 0) : 0.0,
            self::rows(CouponRedemption::find(['coupon_id' => $couponId]))
        )), 2);
    }

    /**
     * I coupon riservati al cliente, con gli utilizzi suoi non rilasciati. Li
     * usano la scheda del backend e il pannello del cliente.
     *
     * @return list<array{coupon: array<string, mixed>, used: int}>
     */
    public static function reserved(int $customerId): array
    {
        if ($customerId <= 0 || !Gestionale::feature('coupons')) {
            return [];
        }

        $reserved = [];
        foreach (self::rows(CouponCustomer::find(['customer_id' => $customerId])) as $link) {
            $coupon = Coupon::find(['id' => (int) $link['coupon_id']], 1);
            if (!is_array($coupon) || !isset($coupon['id'])) {
                continue;
            }
            $used = count(array_filter(
                self::rows(CouponRedemption::find(['coupon_id' => (int) $coupon['id'], 'customer_id' => $customerId])),
                static fn (array $row): bool => self::empty((string) ($row['released_at'] ?? ''))
            ));
            $reserved[] = ['coupon' => $coupon, 'used' => $used];
        }

        return $reserved;
    }

    /**
     * Rifiuta un coupon che non sta in piedi, prima di scrivere qualsiasi cosa:
     * codice mancante o già preso (anche da un coupon eliminato, il codice è
     * unico per sempre), tipo che a mano non si sceglie, percentuale fuori da
     * 0-100, importo non positivo, date capovolte, clienti riservati che non
     * esistono, spedizione gratuita o mesi mancanti su un coupon degli
     * abbonamenti. La spedizione gratuita non ha valore da controllare.
     *
     * @param array<string, mixed> $draft code, discount_type, discount_value, starts_at, ends_at, customers
     */
    public static function validate(array $draft, int $exceptId = 0): void
    {
        $code = trim((string) ($draft['code'] ?? ''));

        if ($code === '') {
            throw UserError::make('coupon.code_required');
        }

        // Il codice è unico anche tra gli eliminati: si cerca in tutti e due i gruppi.
        foreach (['false', 'true'] as $deleted) {
            foreach (self::rows(Coupon::find(['code' => $code, 'deleted' => $deleted])) as $taken) {
                if ((int) $taken['id'] !== $exceptId && mb_strtolower((string) $taken['code']) === mb_strtolower($code)) {
                    throw UserError::make('coupon.code_taken');
                }
            }
        }

        $generated = CouponCode::find(['code' => $code], 1);

        if (is_array($generated) && (int) ($generated['id'] ?? 0) > 0) {
            throw UserError::make('coupon.code_taken');
        }

        $type = (string) ($draft['discount_type'] ?? '');

        if (!in_array($type, ['percent', 'amount', 'free_shipping'], true)) {
            throw UserError::make('coupon.type_not_allowed');
        }

        $value = (float) ($draft['discount_value'] ?? 0);

        if ($type === 'percent' && ($value <= 0.0 || $value > 100.0)) {
            throw UserError::make('coupon.percent_out_of_range');
        }

        if ($type === 'amount' && $value <= 0.0) {
            throw UserError::make('coupon.amount_not_positive');
        }

        // Sugli abbonamenti (G10.6): niente spedizione gratuita, e «per N
        // mesi» vuole i mesi.
        if ((string) ($draft['applies_subscriptions'] ?? 'false') === 'true') {
            if ($type === 'free_shipping') {
                throw UserError::make('coupon.subscriptions_free_shipping');
            }

            // Un abbonamento non consuma un codice monouso (gestionale#113).
            if ((string) ($draft['batch'] ?? 'false') === 'true') {
                throw UserError::make('coupon.batch_subscriptions');
            }

            if ((string) ($draft['duration'] ?? 'once') === 'repeating' && (int) ($draft['duration_months'] ?? 0) < 1) {
                throw UserError::make('coupon.duration_months_required');
            }
        }

        $starts = trim((string) ($draft['starts_at'] ?? ''));
        $ends = trim((string) ($draft['ends_at'] ?? ''));

        if ($starts !== '' && $ends !== '' && $ends < $starts) {
            throw UserError::make('coupon.ends_before_starts');
        }

        foreach ((array) ($draft['customers'] ?? []) as $customerId) {
            $found = Contact::find(['id' => (int) $customerId, 'is_customer' => 'true', 'deleted' => 'false'], 1);

            if (!is_array($found) || (int) ($found['id'] ?? 0) <= 0) {
                throw UserError::make('coupon.customer_unknown');
            }
        }
    }
}
