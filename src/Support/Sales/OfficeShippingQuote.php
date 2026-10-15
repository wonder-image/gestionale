<?php

namespace Wonder\Plugin\Gestionale\Support\Sales;

use Wonder\Plugin\Gestionale\Support\Shipping\Shipping;

/**
 * Il costo di spedizione del listino per la bozza che si sta scrivendo nel
 * nuovo ordine da ufficio, prima che esista: le stesse regole di
 * `Shipping::resolveLine` su un carrello finto del canale `office`.
 *
 * Le righe arrivano dalla stima dell'editor (`product_id`, `quantity`,
 * `line_total`): servono a pesare e a vedere le soglie di gratuità. Il
 * prezzo vero lo rifà il salvataggio.
 */
final class OfficeShippingQuote
{
    private const MAX_LINES = 500;

    /**
     * @param array<string, mixed> $input metodo, consegna, destinazione e `lines` (JSON o array)
     * @return array{ok: bool, amount: ?string, free: bool, message: string}
     */
    public static function quote(array $input): array
    {
        $lines = $input['lines'] ?? [];
        $lines = is_string($lines) ? json_decode($lines, true) : $lines;
        $computed = [];

        foreach (array_slice(is_array($lines) ? $lines : [], 0, self::MAX_LINES) as $line) {
            if (!is_array($line) || (string) ($line['type'] ?? '') !== 'product' || (int) ($line['product_id'] ?? 0) <= 0) {
                continue;
            }

            $computed[] = [
                'type' => 'product',
                'product_id' => (int) $line['product_id'],
                'quantity' => max(0.0, (float) ($line['quantity'] ?? 0)),
                'line_total' => max(0.0, (float) ($line['line_total'] ?? 0)),
            ];
        }

        $cart = ['id' => 0, 'channel' => 'office', 'fulfillment_type' => 'shipping', 'shipping_method_id' => (int) ($input['shipping_method_id'] ?? 0)];

        foreach (['country', 'province', 'city', 'cap', 'street'] as $field) {
            foreach (['shipping_', 'billing_'] as $prefix) {
                if (is_scalar($input[$prefix.$field] ?? null)) {
                    $cart[$prefix.$field] = (string) $input[$prefix.$field];
                }
            }
        }

        if ($cart['shipping_method_id'] <= 0) {
            return ['ok' => false, 'amount' => null, 'free' => false, 'message' => ''];
        }

        if ($computed === []) {
            return ['ok' => false, 'amount' => null, 'free' => false, 'message' => 'Aggiungi un articolo da spedire per calcolare il costo.'];
        }

        $resolved = Shipping::resolveLine($cart, $computed);
        $line = $resolved['line'];

        if ($line === null) {
            return ['ok' => false, 'amount' => null, 'free' => false, 'message' => $resolved['dropped'] !== '' ? $resolved['dropped'] : 'Nessun articolo da spedire.'];
        }

        return ['ok' => true, 'amount' => number_format((float) $line['amount'], 2, '.', ''), 'free' => (bool) $line['free'], 'message' => ''];
    }
}
