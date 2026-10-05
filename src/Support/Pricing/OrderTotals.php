<?php

namespace Wonder\Plugin\Gestionale\Support\Pricing;

use Wonder\Plugin\Gestionale\Support\Tax\TaxTotals;

/**
 * Dalle righe ai totali dell'ordine (§1 di G4, §4.5 e §4.7).
 *
 * Lo sconto sul totale — coupon o sconto scritto a mano sulla testata — non
 * resta appeso alla testata: si **riparte in proporzione** sulle righe a cui si
 * applica e si scrive riga per riga in `order_discount_amount`. Se restasse
 * fuori, i riepiloghi IVA verrebbero calcolati su un imponibile che il cliente
 * non ha pagato, e la fattura non tornerebbe con l'incasso.
 *
 * L'ultimo centesimo di resto va alla riga più alta: tre righe da 33,33 con
 * dieci euro di sconto fanno 3,33 a testa, cioè 9,99, e quel centesimo deve
 * finire da qualche parte perché la somma delle righe faccia esattamente il
 * totale.
 *
 * Spedizione, commissioni e righe di testo non si scontano: il coupon vale
 * sulla merce. Entrano però nei riepiloghi IVA, ognuna con la sua aliquota.
 * Un coupon di spedizione gratuita non sconta la merce: con la chiave di
 * contesto `free_shipping` la riga di spedizione scende a zero (il prezzo di
 * listino resta, per mostrare cosa si è risparmiato) e il risparmio esce in
 * `shipping_saved`.
 *
 * Classe pura: l'unica cosa che chiama è `Tax\TaxTotals`, che è pura anche lei.
 * Le aliquote arrivano già risolte da `Tax\TaxResolver`.
 */
final class OrderTotals
{
    /** Le righe che lo sconto sul totale può toccare. */
    private const DISCOUNTABLE_TYPES = ['product', 'custom'];

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $context
     * @return array{products_total: string, discount_total: string, shipping_total: string, fees_total: string, taxable_total: string, tax_total: string, total: string, total_weight: string, shipping_saved: string, tax_summaries: list<array{rate: float, nature: string, taxable: float, tax: float, total: float}>, lines: list<array<string, mixed>>}
     */
    public static function of(array $lines, array $context = []): array
    {
        $lines = array_values(array_filter($lines, 'is_array'));
        [$lines, $shippingSaved] = static::freeShipping($lines, $context);
        $pricesIncludeTax = !array_key_exists('prices_include_tax', $context)
            || filter_var($context['prices_include_tax'], FILTER_VALIDATE_BOOL);

        $products = 0.0;
        $shipping = 0.0;
        $fees = 0.0;
        $weight = 0.0;
        $base = 0.0;

        foreach ($lines as $line) {
            $total = static::money($line['line_total'] ?? 0);
            $type = static::type($line);

            if ($type === 'shipping') {
                $shipping += $total;
            } elseif ($type === 'fee') {
                $fees += $total;
            } else {
                $products += $total;
            }

            $weight += (float) ($line['weight'] ?? 0) * (float) ($line['quantity'] ?? 0);

            if ($total > 0.0 && static::isDiscountable($line)) {
                $base += $total;
            }
        }

        $base = round($base, 2);
        $discount = static::discount($context, $base);
        $shares = static::shares($lines, $discount, $base);

        $taxLines = [];
        $result = [];

        foreach ($lines as $index => $line) {
            $share = $shares[$index] ?? 0.0;
            $line['order_discount_amount'] = static::asMoney($share);
            $result[] = $line;

            $taxLines[] = [
                // L'imposta si calcola su quello che il cliente paga davvero.
                'total' => round(static::money($line['line_total'] ?? 0) - $share, 2),
                'rate' => (float) ($line['tax_rate'] ?? 0),
                'nature' => (string) ($line['tax_nature'] ?? ''),
            ];
        }

        $summaries = TaxTotals::summaries($taxLines, $pricesIncludeTax);
        $taxable = 0.0;
        $tax = 0.0;

        foreach ($summaries as $summary) {
            $taxable += $summary['taxable'];
            $tax += $summary['tax'];
        }

        $taxable = round($taxable, 2);
        $tax = round($tax, 2);

        return [
            'products_total' => static::asMoney(round($products, 2)),
            'discount_total' => static::asMoney(round(array_sum($shares), 2)),
            'shipping_total' => static::asMoney(round($shipping, 2)),
            'fees_total' => static::asMoney(round($fees, 2)),
            'taxable_total' => static::asMoney($taxable),
            'tax_total' => static::asMoney($tax),
            'total' => static::asMoney(round($taxable + $tax, 2)),
            'total_weight' => number_format(round(max(0.0, $weight), 3), 3, '.', ''),
            'shipping_saved' => static::asMoney($shippingSaved),
            'tax_summaries' => $summaries,
            'lines' => $result,
        ];
    }

    /**
     * Con la spedizione gratuita le righe di spedizione costano zero; ridà le
     * righe e quanto si è risparmiato.
     *
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $context
     * @return array{0: list<array<string, mixed>>, 1: float}
     */
    private static function freeShipping(array $lines, array $context): array
    {
        if (!filter_var($context['free_shipping'] ?? false, FILTER_VALIDATE_BOOL)) {
            return [$lines, 0.0];
        }

        $saved = 0.0;

        foreach ($lines as $index => $line) {
            if (static::type($line) !== 'shipping') {
                continue;
            }

            $saved += max(0.0, static::money($line['line_total'] ?? 0));
            $lines[$index]['unit_price'] = '0.00';
            $lines[$index]['line_total'] = '0.00';
        }

        return [$lines, round($saved, 2)];
    }

    /**
     * Lo sconto sul totale, mai più grande della merce che può scontare.
     *
     * @param array<string, mixed> $context
     */
    private static function discount(array $context, float $base): float
    {
        $value = round((float) ($context['discount_value'] ?? 0), 2);

        // Base zero: niente da scontare e nessuna divisione per zero dopo.
        if ($base <= 0.0 || $value <= 0.0) {
            return 0.0;
        }

        $discount = match ((string) ($context['discount_type'] ?? 'none')) {
            'amount' => $value,
            'percent' => round($base * min($value, 100.0) / 100, 2),
            default => 0.0,
        };

        // Un coupon da 500 € su un ordine da 100 € sconta 100 €: il resto non
        // si prende dalla spedizione né dalle commissioni.
        return min($discount, $base);
    }

    /**
     * La quota di sconto di ogni riga, indicizzata come le righe.
     *
     * @param list<array<string, mixed>> $lines
     * @return array<int, float>
     */
    private static function shares(array $lines, float $discount, float $base): array
    {
        if ($discount <= 0.0 || $base <= 0.0) {
            return [];
        }

        $shares = [];
        $totals = [];
        $assigned = 0.0;

        foreach ($lines as $index => $line) {
            $total = static::money($line['line_total'] ?? 0);

            if ($total <= 0.0 || !static::isDiscountable($line)) {
                continue;
            }

            $share = min(round($discount * $total / $base, 2), $total);
            $shares[$index] = $share;
            $totals[$index] = $total;
            $assigned = round($assigned + $share, 2);
        }

        // Il centesimo che manca (o che avanza) va alle righe più alte, una
        // per volta: la somma delle quote deve fare **esattamente** lo sconto,
        // ma nessuna riga può scontare più di quanto costa — una riga con
        // imponibile negativo non entra in fattura né in un reso.
        $rest = (int) round(($discount - $assigned) * 100);

        if ($rest !== 0 && $totals !== []) {
            $order = $totals;
            arsort($order);
            $step = $rest > 0 ? 1 : -1;

            foreach (array_keys($order) as $index) {
                while ($rest !== 0) {
                    $next = round($shares[$index] + $step / 100, 2);

                    if ($next < 0.0 || $next > $totals[$index]) {
                        break;
                    }

                    $shares[$index] = $next;
                    $rest -= $step;
                }

                if ($rest === 0) {
                    break;
                }
            }
        }

        return $shares;
    }

    /** @param array<string, mixed> $line */
    private static function type(array $line): string
    {
        $type = trim((string) ($line['type'] ?? 'product'));

        return $type === '' ? 'product' : $type;
    }

    /** @param array<string, mixed> $line */
    private static function isDiscountable(array $line): bool
    {
        // Chi chiama può forzare la scelta riga per riga: serve ai coupon di G6
        // che valgono solo su certi prodotti.
        if (array_key_exists('discountable', $line)) {
            return filter_var($line['discountable'], FILTER_VALIDATE_BOOL);
        }

        return in_array(static::type($line), self::DISCOUNTABLE_TYPES, true);
    }

    private static function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private static function asMoney(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
