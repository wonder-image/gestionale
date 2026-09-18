<?php

namespace Wonder\Plugin\Gestionale\Support\Tax;

/**
 * Riepiloghi IVA di un documento, uno per aliquota e natura.
 *
 * L'imposta si calcola sul totale imponibile di ogni aliquota, non riga per
 * riga: è la regola dei riepiloghi FatturaPA, ed è anche l'unico modo perché i
 * conti tornino con quelli del commercialista.
 *
 * Con i prezzi IVA inclusa l'imposta si scorpora: il cliente paga sempre la
 * cifra esposta e cambia solo l'imponibile, anche quando l'aliquota è diversa
 * (per esempio un privato tedesco).
 *
 * Classe pura: le righe arrivano già con la loro aliquota risolta. Sono questi
 * riepiloghi che G4 salverà in `order_tax_summaries` e G8 in
 * `invoice_tax_summaries`.
 */
final class TaxTotals
{
    /**
     * @param list<array{total: float|string, rate: float|string, nature?: string}> $lines
     * @return list<array{rate: float, nature: string, taxable: float, tax: float, total: float}>
     */
    public static function summaries(array $lines, bool $pricesIncludeTax): array
    {
        $groups = [];

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $rate = round((float) ($line['rate'] ?? 0), 2);
            $nature = trim((string) ($line['nature'] ?? ''));
            $key = $rate.'|'.$nature;

            $groups[$key] ??= ['rate' => $rate, 'nature' => $nature, 'amount' => 0.0];
            $groups[$key]['amount'] += (float) ($line['total'] ?? 0);
        }

        // Aliquota più alta per prima, come nei riepiloghi delle fatture.
        uasort($groups, static fn (array $a, array $b): int => $b['rate'] <=> $a['rate']);

        $summaries = [];

        foreach ($groups as $group) {
            $rate = $group['rate'];
            $amount = $group['amount'];

            if ($pricesIncludeTax && $rate > 0) {
                $taxable = round($amount / (1 + ($rate / 100)), 2);
                $tax = round($amount - $taxable, 2);
                $total = round($amount, 2);
            } else {
                $taxable = round($amount, 2);
                $tax = round($taxable * $rate / 100, 2);
                $total = round($taxable + $tax, 2);
            }

            $summaries[] = [
                'rate' => $rate,
                'nature' => $group['nature'],
                'taxable' => $taxable,
                'tax' => $tax,
                'total' => $total,
            ];
        }

        return $summaries;
    }
}
