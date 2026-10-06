<?php

namespace Wonder\Plugin\Gestionale\Support\Shipping;

/**
 * Quanto resta da spedire di un ordine, riga per riga.
 *
 * Pura: non legge il database. Si spedisce ciò che ha merce dietro e un
 * indirizzo da raggiungere: i prodotti che richiedono spedizione. I servizi,
 * le righe di spedizione e commissione, i testi e le personalizzazioni libere
 * no. Di una confezione si spediscono le figlie, non la madre (che è solo il
 * prezzo di vendita): è la stessa regola con cui il magazzino scarica.
 */
final class ShippableLines
{
    /**
     * @param list<array<string, mixed>> $items righe dell'ordine: `id`, `type`, `quantity`, `requires_shipping`, `parent_item_id`
     * @param array<int, float|int|string> $shipped quantità già in spedizioni vive, per riga d'ordine
     * @return array<int, float> riga d'ordine => quanto resta, mai sotto zero
     */
    public static function remaining(array $items, array $shipped): array
    {
        $mothers = [];

        foreach ($items as $item) {
            $parent = (int) ($item['parent_item_id'] ?? 0);

            if ($parent > 0) {
                $mothers[$parent] = true;
            }
        }

        $remaining = [];

        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            $quantity = (float) ($item['quantity'] ?? 0);

            if ($id <= 0
                || (string) ($item['type'] ?? '') !== 'product'
                || !self::truthy($item['requires_shipping'] ?? true)
                || $quantity <= 0
                || isset($mothers[$id])) {
                continue;
            }

            $remaining[$id] = max(0.0, round($quantity - (float) ($shipped[$id] ?? 0), 3));
        }

        return $remaining;
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }
}
