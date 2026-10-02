<?php

namespace Wonder\Plugin\Gestionale\Support\Returns;

use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;

/**
 * Le regole del reso che non hanno bisogno del database: quanto si può
 * rendere, cosa rientra a magazzino di norma, come si legge una quantità.
 */
final class ReturnRules
{
    /** I motivi, con le parole che vede chi registra il reso. */
    public const REASON_LABELS = [
        'damaged' => 'Danneggiato',
        'defective' => 'Difettoso',
        'wrong_item' => 'Articolo sbagliato',
        'not_as_described' => 'Diverso dalla descrizione',
        'changed_mind' => 'Ripensamento',
        'wrong_size' => 'Taglia sbagliata',
        'other' => 'Altro',
    ];

    /** Il motivo da cui parte la scelta: il più comune, quello che di norma rientra. */
    public const DEFAULT_REASON = 'changed_mind';

    /** Gli stati d'ordine su cui un reso ha senso. */
    private const ORDER_STATUSES = ['confirmed', 'processing', 'completed'];

    /**
     * Il reso online (dal sito, senza il commerciante) è per la merce
     * di serie: una riga personalizzata è fatta su misura e non si rimanda
     * indietro da sola. Resta possibile registrarla dal backend.
     *
     * @param array<string, mixed> $item una riga d'ordine
     */
    public static function onlineReturnable(array $item): bool
    {
        return Customizations::decode($item['customization'] ?? '') === [];
    }

    /** Merce rotta non torna in vendita: la spunta parte spenta. */
    public static function defaultRestock(string $reason): bool
    {
        return !in_array($reason, ['damaged', 'defective'], true);
    }

    /** Quanto si può ancora rendere di una riga: l'ordinato meno il già reso. */
    public static function returnable(float $ordered, float $alreadyReturned): float
    {
        return max(0.0, round($ordered - $alreadyReturned, 3));
    }

    /** Quanto di un componente torna con le confezioni rese: i pezzi per confezione per le confezioni. */
    public static function childQuantity(float $perUnit, float $returnedPacks): float
    {
        return round($perUnit * $returnedPacks, 3);
    }

    /** `2`, `2,5`, `2.5`: la quantità di una persona; null se non è maggiore di zero. */
    public static function quantityFrom(mixed $value): ?float
    {
        $text = str_replace(',', '.', trim(str_replace(["\u{a0}", ' '], '', (string) ($value ?? ''))));

        if (preg_match('/^\d+(\.\d{1,3})?$/', $text) !== 1) {
            return null;
        }

        $quantity = round((float) $text, 3);

        return $quantity > 0 ? $quantity : null;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, bool> $features
     */
    public static function eligibleOrder(array $order, array $features = []): bool
    {
        return ($features['returns'] ?? false) === true
            && (string) ($order['stage'] ?? '') === 'order'
            && in_array((string) ($order['status'] ?? ''), self::ORDER_STATUSES, true);
    }
}
