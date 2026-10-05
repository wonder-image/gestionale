<?php

namespace Wonder\Plugin\Gestionale\Support\Shipping;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRate;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRateBracket;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * L'unica porta del calcolo della spedizione: quali metodi si offrono per un
 * carrello, quanto costa uno, e la riga che il carrello deve portare.
 *
 * Mette insieme le tre cose che da sole non sanno niente di carrelli: la zona
 * della destinazione (`ShippingZones`), il peso tassabile (`ShippingWeight`) e
 * il prezzo del listino (`ShippingRates`).
 *
 * Con la funzionalità `shipping` spenta non offre e non scrive niente.
 */
final class Shipping
{
    /** I canali per cui un metodo vale «ufficio»; gli altri valgono «online». */
    private const OFFICE_CHANNELS = ['office', 'pos'];

    /**
     * I metodi che si possono scegliere per il carrello, con il prezzo, in
     * ordine di `position`. Vuoto con la funzionalità spenta, senza righe da
     * spedire o se nessun metodo copre la destinazione.
     *
     * @return list<array{method_id: int, name: string, description: string, carrier_id: int, price: string, free: bool}>
     */
    public static function options(int $cartId): array
    {
        $cart = self::order($cartId);

        if ($cart === [] || !Gestionale::feature('shipping')) {
            return [];
        }

        $context = self::context($cart, self::itemsOf($cartId), self::storedTotal($cart));

        if ($context['lines'] === []) {
            return [];
        }

        $options = [];

        foreach (self::methods() as $method) {
            $quote = self::evaluate($method, $context);

            if (is_string($quote)) {
                continue;
            }

            $options[] = [
                'method_id' => (int) $method['id'],
                'name' => (string) ($method['name'] ?? ''),
                'description' => (string) ($method['description'] ?? ''),
                'carrier_id' => (int) ($method['carrier_id'] ?? 0),
            ] + $quote;
        }

        return $options;
    }

    /**
     * Il prezzo di un metodo per il carrello.
     *
     * @return array{price: string, free: bool}
     * @throws UserError `shipping.not_available`, con il motivo, se il metodo
     *                   non si può usare per questo carrello
     */
    public static function quote(int $cartId, int $methodId): array
    {
        $cart = self::order($cartId);
        $method = self::method($methodId);

        if ($cart === [] || !Gestionale::feature('shipping')) {
            throw self::refusal($method, 'shipping.reason_method');
        }

        $context = self::context($cart, self::itemsOf($cartId), self::storedTotal($cart));
        $quote = $context['lines'] === [] ? 'shipping.reason_nothing' : self::evaluate($method, $context);

        if (is_string($quote)) {
            throw self::refusal($method, $quote);
        }

        return $quote;
    }

    /**
     * La riga di spedizione che `Cart` deve scrivere, o `null` se non ce n'è
     * una da scrivere: funzionalità spenta, ritiro, consegna `none`, nessun
     * metodo scelto, niente da spedire, o metodo che non copre più la
     * destinazione (in quel caso `dropped()` dice perché).
     *
     * `$computed` sono le righe come le ha già costruite `Cart::recalculate`
     * (`product_id`, `quantity`, `line_total`, `type`): il totale per la soglia
     * di gratuità è la somma dei `line_total` delle righe che non sono
     * spedizione né commissioni, cioè dopo gli sconti di riga e di campagna.
     *
     * @return array{name: string, amount: string, tax_id: int, free: bool}|null
     */
    public static function line(array $cart, array $computed): ?array
    {
        return self::resolveLine($cart, $computed)['line'];
    }

    /**
     * Il motivo per cui il metodo scelto non copre più il carrello, in frase;
     * stringa vuota se la riga si scrive o non c'è nulla da dire.
     */
    public static function dropped(array $cart, array $computed): string
    {
        return self::resolveLine($cart, $computed)['dropped'];
    }

    /**
     * Il costo di contrassegno del listino scelto per la destinazione del
     * carrello: `Checkout` lo mette al posto della commissione del metodo di
     * pagamento. Zero se non c'è un listino o non si spedisce.
     */
    public static function codFee(int $cartId): float
    {
        $cart = self::order($cartId);
        $methodId = (int) ($cart['shipping_method_id'] ?? 0);

        if (!Gestionale::feature('shipping')
            || (string) ($cart['fulfillment_type'] ?? 'shipping') !== 'shipping'
            || $methodId <= 0) {
            return 0.0;
        }

        [$country, $province] = self::destination($cart);
        $zone = ShippingZones::resolve($country, $province);
        $rate = $zone !== null ? self::rate($methodId, $zone) : null;

        return $rate === null ? 0.0 : max(0.0, round((float) ($rate['cod_fee'] ?? 0), 2));
    }

    /**
     * `line()` e `dropped()` insieme, per chi li vuole tutti e due con un solo calcolo.
     *
     * @return array{line: ?array{name: string, amount: string, tax_id: int, free: bool}, dropped: string}
     */
    public static function resolveLine(array $cart, array $computed): array
    {
        $none = ['line' => null, 'dropped' => ''];
        $methodId = (int) ($cart['shipping_method_id'] ?? 0);

        if (!Gestionale::feature('shipping')
            || (string) ($cart['fulfillment_type'] ?? 'shipping') !== 'shipping'
            || $methodId <= 0) {
            return $none;
        }

        $stored = self::itemsOf((int) ($cart['id'] ?? 0));
        $children = array_values(array_filter($stored, static fn (array $row): bool => (int) ($row['parent_item_id'] ?? 0) > 0));
        $total = 0.0;

        foreach ($computed as $row) {
            if (!in_array((string) ($row['type'] ?? ''), ['shipping', 'fee'], true)) {
                $total += (float) ($row['line_total'] ?? 0);
            }
        }

        // Le madri delle confezioni stanno in `computed`, le figlie solo nel
        // carrello: le figlie sono ciò che esce dal magazzino.
        $context = self::context($cart, array_merge($computed, $children), round($total, 2), $stored);

        if ($context['lines'] === []) {
            return $none;
        }

        $method = self::method($methodId);
        $quote = self::evaluate($method, $context);

        if (is_string($quote)) {
            return ['line' => null, 'dropped' => self::refusal($method, $quote)->getMessage()];
        }

        return ['line' => [
            'name' => (string) ($method['name'] ?? ''),
            'amount' => $quote['price'],
            'tax_id' => (int) (Setting::current()['shipping_tax_id'] ?? 0),
            'free' => $quote['free'],
        ], 'dropped' => ''];
    }

    /**
     * Quello che serve a prezzare un carrello: le righe da pesare, la zona, il
     * canale e il totale dei prodotti.
     *
     * @param list<array<string, mixed>> $candidates righe che possono pesare
     * @param list<array<string, mixed>>|null $stored righe del carrello, per riconoscere le madri
     * @return array{lines: list<array<string, float>>, zone: ?int, channel: string, total: float}
     */
    private static function context(array $cart, array $candidates, float $total, ?array $stored = null): array
    {
        [$country, $province] = self::destination($cart);
        $mothers = [];

        foreach ($stored ?? $candidates as $row) {
            if ((int) ($row['parent_item_id'] ?? 0) > 0) {
                $mothers[(int) $row['parent_item_id']] = true;
            }
        }

        $lines = [];
        $products = [];
        $models = [];

        foreach ($candidates as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $id = (int) ($row['id'] ?? 0);

            if ((string) ($row['type'] ?? '') !== 'product' || $productId <= 0 || ($id > 0 && isset($mothers[$id]))) {
                continue;
            }

            $products[$productId] ??= Product::findById($productId);
            $product = $products[$productId];

            if (!is_array($product)) {
                continue;
            }

            $modelId = (int) ($product['product_model_id'] ?? 0);
            $models[$modelId] ??= $modelId > 0 ? ProductModel::findById($modelId) : null;

            if (is_array($models[$modelId]) && ($models[$modelId]['requires_shipping'] ?? 'true') !== 'true') {
                continue;
            }

            $lines[] = [
                'weight' => (float) ($product['weight'] ?? 0),
                'length' => (float) ($product['length'] ?? 0),
                'width' => (float) ($product['width'] ?? 0),
                'height' => (float) ($product['height'] ?? 0),
                'quantity' => (float) ($row['quantity'] ?? 0),
            ];
        }

        return [
            'lines' => $lines,
            'zone' => ShippingZones::resolve($country, $province),
            'channel' => (string) ($cart['channel'] ?? 'online'),
            'total' => $total,
        ];
    }

    /**
     * Prezzo di un metodo nel contesto, o la chiave della frase del motivo
     * (`shipping.reason_*`).
     *
     * @return array{price: string, free: bool}|string
     */
    private static function evaluate(array $method, array $context): array|string
    {
        $office = in_array($context['channel'], self::OFFICE_CHANNELS, true);

        if ($method === []
            || ($method['active'] ?? 'false') !== 'true'
            || ($method[$office ? 'applies_office' : 'applies_online'] ?? 'false') !== 'true') {
            return 'shipping.reason_method';
        }

        if ($context['zone'] === null) {
            return 'shipping.reason_zone';
        }

        $rate = self::rate((int) $method['id'], $context['zone']);

        if ($rate === null) {
            return 'shipping.reason_zone';
        }

        $brackets = self::rows(ShippingRateBracket::find(['shipping_rate_id' => (int) $rate['id']]));
        $divisor = (float) ($rate['volumetric_divisor'] ?? 0);
        $weight = ShippingWeight::of($context['lines'], $divisor > 0 ? $divisor : null);
        $price = ShippingRates::price($rate, $brackets, $weight, $context['total']);

        if ($price === null) {
            return 'shipping.reason_weight';
        }

        return ['price' => number_format($price['amount'], 2, '.', ''), 'free' => $price['free']];
    }

    /** Il listino attivo del metodo per la zona, senza ricadere sul paese. */
    private static function rate(int $methodId, int $zone): ?array
    {
        return self::rows(ShippingRate::find([
            'shipping_method_id' => $methodId,
            'shipping_zone_id' => $zone,
            'active' => 'true',
        ]))[0] ?? null;
    }

    private static function refusal(array $method, string $reason): UserError
    {
        return UserError::make('shipping.not_available', [
            'method' => (string) ($method['name'] ?? ''),
            'reason' => lcfirst(UserError::make($reason)->getMessage()),
        ]);
    }

    /**
     * Paese e provincia della destinazione: quelli dell'indirizzo di consegna
     * se ne ha uno, altrimenti quelli della fatturazione; senza paese, `IT`.
     * Il paese da solo non fa un indirizzo di consegna: ha un valore predefinito.
     *
     * @return array{string, string}
     */
    private static function destination(array $cart): array
    {
        $delivery = false;

        foreach (['province', 'city', 'cap', 'street'] as $field) {
            $delivery = $delivery || trim((string) ($cart['shipping_'.$field] ?? '')) !== '';
        }

        $prefix = $delivery ? 'shipping_' : 'billing_';
        $country = strtoupper(trim((string) ($cart[$prefix.'country'] ?? '')));

        return [$country !== '' ? $country : 'IT', strtoupper(trim((string) ($cart[$prefix.'province'] ?? '')))];
    }

    /** I metodi non eliminati, in ordine di `position`. */
    private static function methods(): array
    {
        $methods = self::rows(ShippingMethod::find([]));
        usort($methods, static fn (array $a, array $b): int => [(int) ($a['position'] ?? 0), (int) $a['id']] <=> [(int) ($b['position'] ?? 0), (int) $b['id']]);

        return $methods;
    }

    private static function method(int $methodId): array
    {
        $method = $methodId > 0 ? ShippingMethod::findById($methodId) : null;

        return is_array($method) && isset($method['id']) ? $method : [];
    }

    private static function order(int $cartId): array
    {
        $order = $cartId > 0 ? Order::findById($cartId) : null;

        return is_array($order) && isset($order['id']) ? $order : [];
    }

    /**
     * I prodotti del carrello a prezzo di riga, come li ha scritti l'ultimo
     * ricalcolo: senza lo sconto del coupon o a mano sulla testata, che per la
     * soglia gratuita non conta, come dentro `line()`.
     */
    private static function storedTotal(array $cart): float
    {
        return max(0.0, round((float) ($cart['products_total'] ?? 0), 2));
    }

    /** @return list<array<string, mixed>> */
    private static function itemsOf(int $cartId): array
    {
        return $cartId > 0 ? self::rows(OrderItem::find(['order_id' => $cartId, 'deleted' => 'false'])) : [];
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
