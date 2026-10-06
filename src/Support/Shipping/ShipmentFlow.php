<?php

namespace Wonder\Plugin\Gestionale\Support\Shipping;

/**
 * Gli stati di una spedizione e come si passa dall'uno all'altro, e da questi
 * lo stato di evasione dell'ordine. Pura: niente database.
 *
 * In avanti si può saltare (una consegna in attesa può diventare subito
 * «consegnata»), indietro no. Una consegna già in viaggio non si annulla: si
 * segna fallita, in eccezione o resa. Dopo un tentativo fallito o
 * un'eccezione la consegna riprende o torna al mittente.
 */
final class ShipmentFlow
{
    /** @var array<string, list<string>> */
    private const DELIVERY = [
        'pending' => ['label_created', 'in_transit', 'out_for_delivery', 'delivered', 'cancelled'],
        'label_created' => ['in_transit', 'out_for_delivery', 'delivered', 'cancelled'],
        'in_transit' => ['out_for_delivery', 'delivered', 'failed_attempt', 'exception', 'returned'],
        'out_for_delivery' => ['delivered', 'failed_attempt', 'exception', 'returned'],
        'failed_attempt' => ['out_for_delivery', 'delivered', 'exception', 'returned'],
        'exception' => ['out_for_delivery', 'delivered', 'returned'],
        'delivered' => [],
        'returned' => [],
        'cancelled' => [],
    ];

    /** @var array<string, list<string>> */
    private const PICKUP = [
        'pending' => ['ready_for_pickup', 'cancelled'],
        'ready_for_pickup' => ['picked_up', 'cancelled'],
        'picked_up' => [],
        'cancelled' => [],
    ];

    /** Gli stati in cui una consegna ha già lasciato il magazzino e conta per l'evasione. */
    public const DELIVERY_COUNTED = ['in_transit', 'out_for_delivery', 'delivered', 'failed_attempt', 'exception'];

    /** Gli stati in cui un ritiro conta per l'evasione. */
    public const PICKUP_COUNTED = ['picked_up'];

    /**
     * Dove può andare una spedizione da quello stato.
     *
     * @return list<string>
     */
    public static function allowed(string $type, string $from): array
    {
        return match ($type) {
            'delivery' => self::DELIVERY[$from] ?? [],
            'pickup' => self::PICKUP[$from] ?? [],
            default => [],
        };
    }

    /**
     * Lo stato di evasione di un ordine, dalle quantità che sono davvero partite.
     *
     * `$lines` ha una voce per ogni riga spedibile con la quantità ordinata
     * (`ordered`) e quella già in spedizioni che contano (`shipped`); senza righe
     * da spedire l'evasione resta com'è. Un ritiro pronto ma non ancora ritirato
     * non ha spedito niente: lo dice `$pickupReady`.
     *
     * @param list<array{ordered: float|int, shipped: float|int}> $lines
     */
    public static function fulfillment(string $current, array $lines, bool $pickupReady = false): string
    {
        if ($lines === []) {
            return $current;
        }

        $complete = true;
        $any = false;

        foreach ($lines as $line) {
            $ordered = round((float) $line['ordered'], 3);
            $shipped = round((float) $line['shipped'], 3);

            if ($shipped > 0) {
                $any = true;
            }

            if ($shipped < $ordered - 0.0005) {
                $complete = false;
            }
        }

        if ($complete) {
            return 'fulfilled';
        }

        if ($any) {
            return 'partially_fulfilled';
        }

        return $pickupReady ? 'ready_for_pickup' : 'unfulfilled';
    }
}
