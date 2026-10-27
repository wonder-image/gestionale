<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Support\Pricing\LinePrice;
use Wonder\Plugin\Gestionale\Support\Tax\ChargeSplit;

/**
 * Le righe di spedizione e commissione come quote sulle aliquote della merce.
 *
 * Una spesa si scrive come una riga per aliquota (`Tax\ChargeSplit`): con una
 * sola aliquota fra le merci resta una riga. Ogni quota tiene in
 * `manual_unit_price` la spesa intera scritta a mano, così il ricalcolo la
 * ritrova anche dopo una spedizione gratuita.
 */
final class Charges
{
    /** I tipi di riga che prendono l'aliquota della merce. */
    public const TYPES = ['shipping', 'fee'];

    /**
     * La spesa intera dalle sue righe salvate: quella scritta a mano, se c'è,
     * altrimenti la somma delle quote.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function amount(array $rows): float
    {
        foreach ($rows as $row) {
            $manual = trim((string) ($row['manual_unit_price'] ?? ''));

            if ($manual !== '' && is_numeric($manual)) {
                return round((float) $manual, 2);
            }
        }

        return round(array_sum(array_map(static fn (array $row): float => (float) ($row['list_price'] ?? 0), $rows)), 2);
    }

    /**
     * Riscrive le righe della spesa, una per quota, e le ridà da totalizzare.
     * Le righe in più si tolgono, quelle che mancano si creano.
     *
     * @param list<array<string, mixed>> $rows righe salvate della spesa
     * @param list<array{tax_id: int, rate: float, nature: string, base: float}> $groups da `ChargeSplit::groups()`
     * @param array{type: string, name: string, position: int, price_source: string, manual_unit_price: ?string} $charge
     * @return list<array<string, mixed>>
     */
    public static function write(int $orderId, array $rows, float $amount, array $groups, array $charge): array
    {
        usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        $computed = [];

        foreach (ChargeSplit::shares($amount, $groups) as $index => $share) {
            $price = number_format($share['amount'], 2, '.', '');
            $values = [
                'name' => $charge['name'],
                'position' => $charge['position'],
                'quantity' => '1.000',
                'list_price' => $price,
                'unit_price' => $price,
                'line_total' => $price,
                'price_source' => $charge['price_source'],
                'manual_unit_price' => $charge['manual_unit_price'],
                'tax_id' => $share['tax_id'],
                'tax_rate' => number_format($share['rate'], 2, '.', ''),
                'tax_nature' => $share['nature'],
            ];
            $id = (int) ($rows[$index]['id'] ?? 0);

            if ($id > 0) {
                OrderItem::update($values, $id);
            } else {
                $id = (int) (OrderItem::create($values + [
                    'order_id' => $orderId,
                    'type' => $charge['type'],
                    'tax_category_id' => 0,
                ])->insert_id ?? 0);
            }

            $computed[] = [
                'id' => $id,
                'type' => $charge['type'],
                'product_id' => 0,
                'weight' => 0.0,
                'tax_id' => $share['tax_id'],
                'tax_rate' => $share['rate'],
                'tax_nature' => $share['nature'],
                'discount_campaign_id' => 0,
            ] + LinePrice::of(['quantity' => 1, 'price' => $price]
                + ($charge['price_source'] === 'manual' ? ['manual_unit_price' => $price] : []));
        }

        foreach (array_slice($rows, count($computed)) as $extra) {
            OrderItem::delete((int) $extra['id']);
        }

        return $computed;
    }
}
