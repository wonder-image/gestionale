<?php

namespace Wonder\Plugin\Gestionale\Support\Sales;

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;

/**
 * Le regole dell'ordine da ufficio (G9 §2), senza database.
 *
 * `OfficeOrders` legge e scrive; qui si decide quali righe dell'editor sono
 * buone, cosa serve alla conferma e quanto si può annullare dopo. Le righe
 * arrivano dal JS come testo: i numeri si leggono all'italiana
 * (`Numbers::fromForm()`), e ogni rifiuto dice la riga.
 */
final class OfficeOrderRules
{
    public const MAX_LINES = 300;

    /** La quantità più alta che `quantity` DECIMAL(10,3) tiene. */
    public const MAX_QUANTITY = 9999999.999;

    /** Il prezzo più alto che DECIMAL(10,2) tiene. */
    public const MAX_PRICE = 99999999.99;

    /** Le righe che l'editor scrive: la spedizione la fa il motore. */
    public const LINE_TYPES = ['product', 'custom', 'text'];

    /**
     * Le righe dell'editor, pulite. Una riga di prodotto senza prodotto, o
     * una libera senza descrizione né prezzo, non è una riga e si salta.
     *
     * - `product`: versione, quantità, prezzo unitario (vuoto = quello del
     *   listino), sconto di riga, lotto e fornitore (0 = automatico);
     * - `custom`: descrizione, quantità, prezzo, categoria IVA;
     * - `text`: solo la descrizione.
     *
     * @param list<mixed> $input
     * @return list<array{type: string, product_id: int, name: string, quantity: float, unit_price: ?float, discount_type: string, discount_value: float, tax_category_id: int, batch_id: int, supplier_id: int}>
     */
    public static function lines(array $input): array
    {
        if (count($input) > self::MAX_LINES) {
            throw UserError::make('office_order.too_many_rows', ['max' => self::MAX_LINES]);
        }

        $lines = [];
        $row = 0;

        foreach ($input as $line) {
            $row++;

            if (!is_array($line)) {
                throw UserError::make('office_order.invalid_lines');
            }

            $type = (string) ($line['type'] ?? 'product');

            if (!in_array($type, self::LINE_TYPES, true)) {
                throw UserError::make('office_order.invalid_lines');
            }

            $name = trim((string) ($line['name'] ?? ''));
            $productId = self::id($line['product_id'] ?? null);

            if ($type === 'product' && $productId === 0) {
                continue;
            }

            if ($type === 'custom' && $name === '' && trim((string) ($line['unit_price'] ?? '')) === '') {
                continue;
            }

            if ($type === 'text') {
                if ($name === '') {
                    continue;
                }

                $lines[] = self::line('text', 0, mb_substr($name, 0, 2000), 0.0, null, 'none', 0.0, 0, 0, 0);

                continue;
            }

            if ($type === 'custom' && $name === '') {
                throw UserError::make('office_order.name_required', ['row' => $row]);
            }

            $quantity = self::quantity($line['quantity'] ?? null, $row);
            $price = self::price($line['unit_price'] ?? null, $row);

            if ($type === 'custom' && $price === null) {
                throw UserError::make('office_order.price_required', ['row' => $row]);
            }

            [$discountType, $discountValue] = self::discount($line['discount_type'] ?? 'none', $line['discount_value'] ?? null, $row);

            $lines[] = self::line(
                $type,
                $type === 'product' ? $productId : 0,
                $type === 'custom' ? mb_substr($name, 0, 255) : '',
                $quantity,
                $price,
                $discountType,
                $discountValue,
                $type === 'custom' ? self::id($line['tax_category_id'] ?? null) : 0,
                $type === 'product' ? self::id($line['batch_id'] ?? null) : 0,
                $type === 'product' ? self::id($line['supplier_id'] ?? null) : 0
            );
        }

        return $lines;
    }

    /**
     * La testata: consegna e sconto sul totale. Il resto (cliente, metodo,
     * note) lo controlla il servizio, che sa cosa esiste.
     *
     * @param array<string, mixed> $input
     * @return array{fulfillment_type: string, manual_discount_type: string, manual_discount_value: float}
     */
    public static function header(array $input): array
    {
        $fulfillment = (string) ($input['fulfillment_type'] ?? 'shipping');

        if (!in_array($fulfillment, Order::FULFILLMENT_TYPES, true)) {
            throw UserError::make('office_order.invalid_fulfillment');
        }

        [$type, $value] = self::discount($input['manual_discount_type'] ?? 'none', $input['manual_discount_value'] ?? null, 0);

        return ['fulfillment_type' => $fulfillment, 'manual_discount_type' => $type, 'manual_discount_value' => $value];
    }

    /**
     * Quello che la conferma chiede alla bozza: un cliente, almeno una riga
     * che si vende, l'indirizzo se si spedisce.
     *
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $items
     */
    public static function assertConfirmable(array $order, array $items): void
    {
        if ((string) ($order['status'] ?? '') !== 'draft') {
            throw UserError::make('office_order.not_draft');
        }

        if ((int) ($order['customer_id'] ?? 0) <= 0) {
            throw UserError::make('office_order.customer_required');
        }

        $sold = array_filter($items, static fn (array $i): bool => in_array((string) ($i['type'] ?? ''), ['product', 'custom'], true)
            && (int) ($i['parent_item_id'] ?? 0) === 0
            && (float) ($i['quantity'] ?? 0) > 0);

        if ($sold === []) {
            throw UserError::make('office_order.no_rows');
        }

        if (!self::billingComplete($order)) {
            throw UserError::make('office_order.billing_required');
        }

        if ((string) ($order['fulfillment_type'] ?? '') === 'shipping') {
            foreach (['shipping_street', 'shipping_city', 'shipping_country'] as $key) {
                if (trim((string) ($order[$key] ?? '')) === '') {
                    throw UserError::make('office_order.address_required');
                }
            }
        }
    }

    /**
     * La fatturazione basta per un documento: a chi (ragione sociale per
     * un'azienda, nome o cognome per un privato) e dove (via, città, paese).
     *
     * @param array<string, mixed> $order la testata, con le chiavi `billing_*`
     */
    public static function billingComplete(array $order): bool
    {
        $has = static fn (string $key): bool => trim((string) ($order['billing_'.$key] ?? '')) !== '';

        $who = (string) ($order['billing_type'] ?? '') === 'business'
            ? $has('business_name')
            : $has('name') || $has('surname');

        return $who && $has('street') && $has('city') && $has('country');
    }

    /**
     * Quanto si annulla di ogni riga dopo la conferma: al massimo quanto non è
     * ancora partito. Le righe a zero o vuote non si toccano.
     *
     * @param array<int, mixed> $wanted riga => quantità scritta
     * @param array<int, float> $remaining riga => quantità ancora annullabile
     * @param array<int, string> $names riga => nome, per il rifiuto
     * @return array<int, float> riga => quantità da annullare
     */
    public static function cancellable(array $wanted, array $remaining, array $names = []): array
    {
        $out = [];

        foreach ($wanted as $id => $raw) {
            $text = trim((string) $raw);

            if ($text === '') {
                continue;
            }

            $number = Numbers::fromForm($text);

            if ($number === null || !is_numeric($number) || (float) $number < 0) {
                throw UserError::make('office_order.invalid_cancel', ['name' => $names[(int) $id] ?? '']);
            }

            $quantity = round((float) $number, 3);

            if ($quantity <= 0) {
                continue;
            }

            if (!array_key_exists((int) $id, $remaining) || $quantity > round((float) $remaining[(int) $id], 3) + 0.0005) {
                throw UserError::make('office_order.cancel_too_much', [
                    'name' => $names[(int) $id] ?? '',
                    'max' => self::plain((float) ($remaining[(int) $id] ?? 0)),
                ]);
            }

            $out[(int) $id] = $quantity;
        }

        if ($out === []) {
            throw UserError::make('office_order.nothing_to_cancel');
        }

        return $out;
    }

    /** «3», «2,5»: i pezzi come si scrivono. */
    public static function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');
    }

    /** @return array{type: string, product_id: int, name: string, quantity: float, unit_price: ?float, discount_type: string, discount_value: float, tax_category_id: int, batch_id: int, supplier_id: int} */
    private static function line(string $type, int $productId, string $name, float $quantity, ?float $price, string $discountType, float $discountValue, int $taxCategoryId, int $batchId, int $supplierId): array
    {
        return [
            'type' => $type,
            'product_id' => $productId,
            'name' => $name,
            'quantity' => $quantity,
            'unit_price' => $price,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'tax_category_id' => $taxCategoryId,
            'batch_id' => $batchId,
            'supplier_id' => $supplierId,
        ];
    }

    private static function id(mixed $value): int
    {
        return is_numeric($value) && (int) $value > 0 && (string) (int) $value === trim((string) $value) ? (int) $value : 0;
    }

    private static function quantity(mixed $value, int $row): float
    {
        $number = Numbers::fromForm($value);

        if ($number === null) {
            throw UserError::make('office_order.quantity_required', ['row' => $row]);
        }

        if (!is_numeric($number) || (float) $number <= 0 || (float) $number > self::MAX_QUANTITY) {
            throw UserError::make('office_order.invalid_quantity', ['row' => $row]);
        }

        return round((float) $number, 3);
    }

    /** Vuoto = il prezzo lo decide il listino. */
    private static function price(mixed $value, int $row): ?float
    {
        $number = Numbers::fromForm($value);

        if ($number === null) {
            return null;
        }

        if (!is_numeric($number) || (float) $number < 0 || (float) $number > self::MAX_PRICE) {
            throw UserError::make('office_order.invalid_price', ['row' => $row]);
        }

        return round((float) $number, 2);
    }

    /** @return array{0: string, 1: float} */
    private static function discount(mixed $type, mixed $value, int $row): array
    {
        $type = (string) $type;

        if (!in_array($type, ['none', 'amount', 'percent'], true)) {
            $type = 'none';
        }

        $number = Numbers::fromForm($value);

        if ($type === 'none' || $number === null || (is_numeric($number) && (float) $number == 0.0)) {
            return ['none', 0.0];
        }

        if (!is_numeric($number) || (float) $number < 0 || ($type === 'percent' && (float) $number > 100) || (float) $number > self::MAX_PRICE) {
            throw UserError::make($row > 0 ? 'office_order.invalid_discount' : 'office_order.invalid_order_discount', ['row' => $row]);
        }

        return [$type, round((float) $number, 2)];
    }
}
