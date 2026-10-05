<?php

namespace Wonder\Plugin\Gestionale\Support\Pricing;

/**
 * Il prezzo di **una** riga: quale prezzo vince, lo sconto della riga, il
 * sovrapprezzo della personalizzazione (§1 di G4, §4.5 e §4.6).
 *
 * La priorità è **listino → campagna → prezzo scontato → prezzo base**, e la
 * sorgente che ha vinto resta scritta in `price_source`: senza quella colonna,
 * sei mesi dopo, nessuno sa più perché quella riga costava così. Listino e
 * campagna arrivano con G6: in G4 la classe li riceve vuoti e li salta.
 *
 * Classe pura: niente database, niente `date()`, niente eccezioni. Le quantità
 * storte e gli sconti impossibili si normalizzano invece di essere rifiutati,
 * perché questa classe gira anche sul carrello di un cliente e un carrello non
 * deve andare in pagina 500 per una quantità negativa. I rifiuti che una
 * persona deve leggere li fa il servizio che chiama, con `UserError`.
 *
 * Restituisce **stringhe canoniche**, pronte per le colonne di
 * `gst_order_items`: due decimali per il denaro, tre per la quantità.
 */
final class LinePrice
{
    public const SOURCES = ['price_list', 'campaign', 'sale_price', 'base', 'manual'];
    public const DISCOUNT_TYPES = ['none', 'amount', 'percent'];

    /**
     * @param array<string, mixed> $input
     * @return array{list_price: string, unit_price: string, price_source: string, discount_type: string, discount_value: string, customization_surcharge: string, quantity: string, line_total: string}
     */
    public static function of(array $input): array
    {
        $quantity = static::quantity($input['quantity'] ?? 1);
        $listPrice = static::money($input['price'] ?? 0);

        [$source, $gross] = static::source($input, $listPrice);

        $discountType = (string) ($input['discount_type'] ?? 'none');

        if (!in_array($discountType, static::DISCOUNT_TYPES, true)) {
            $discountType = 'none';
        }

        $discountValue = static::money($input['discount_value'] ?? 0);

        // Tipo e valore stanno insieme: uno sconto senza tipo, o un tipo senza
        // valore, è nessuno sconto — e in tabella non resta un valore orfano.
        if ($discountValue <= 0.0 || $discountType === 'none') {
            $discountType = 'none';
            $discountValue = 0.0;
        }

        $discount = match ($discountType) {
            // Uno sconto più grande del prezzo si ferma al prezzo: un prezzo
            // negativo è un rimborso che nessuno ha chiesto.
            'amount' => min($discountValue, $gross),
            'percent' => round($gross * min($discountValue, 100.0) / 100, 2),
            default => 0.0,
        };

        if ($discount > 0.0) {
            // Lo sconto scritto a mano vince su qualsiasi prezzo automatico.
            $source = 'manual';
        }

        $surcharge = static::money($input['customization_surcharge'] ?? 0);
        // Il sovrapprezzo si somma **dopo** lo sconto: non si sconta una
        // personalizzazione che il cliente ha chiesto in più.
        $unitPrice = round(max(0.0, round($gross - $discount, 2)) + $surcharge, 2);

        return [
            'list_price' => static::asMoney($listPrice),
            'unit_price' => static::asMoney($unitPrice),
            'price_source' => $source,
            'discount_type' => $discountType,
            'discount_value' => static::asMoney($discountValue),
            'customization_surcharge' => static::asMoney($surcharge),
            'quantity' => number_format($quantity, 3, '.', ''),
            'line_total' => static::asMoney(round($unitPrice * $quantity, 2)),
        ];
    }

    /**
     * Il prezzo che vince e da dove viene.
     *
     * @param array<string, mixed> $input
     * @return array{0: string, 1: float}
     */
    private static function source(array $input, float $listPrice): array
    {
        $manual = trim((string) ($input['manual_unit_price'] ?? ''));

        // Un prezzo a mano a zero è una scelta — l'omaggio — non un campo vuoto.
        if ($manual !== '' && is_numeric($manual)) {
            return ['manual', static::money($manual)];
        }

        $priceList = static::money($input['price_list_price'] ?? 0);

        if ($priceList > 0.0) {
            return ['price_list', $priceList];
        }

        $campaign = trim((string) ($input['campaign_price'] ?? ''));

        // Come il prezzo a mano: una campagna a zero è un omaggio, non l'assenza di campagna.
        if ($campaign !== '' && is_numeric($campaign)) {
            return ['campaign', static::money($campaign)];
        }

        $sale = static::money($input['sale_price'] ?? 0);

        // Un prezzo scontato che non è più basso del base non è uno sconto:
        // succede quando il listino sale e nessuno svuota la colonna.
        if ($sale > 0.0 && $sale < $listPrice) {
            return ['sale_price', $sale];
        }

        return ['base', $listPrice];
    }

    /** Denaro: mai sotto zero, sempre due decimali. */
    private static function money(mixed $value): float
    {
        return round(max(0.0, (float) $value), 2);
    }

    /** Quantità: mai sotto zero, sempre tre decimali. */
    private static function quantity(mixed $value): float
    {
        return round(max(0.0, (float) $value), 3);
    }

    private static function asMoney(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
