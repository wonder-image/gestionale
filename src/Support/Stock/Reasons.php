<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

/**
 * Le causali di una rettifica: perché quei pezzi sono entrati o usciti.
 *
 * Sono poche apposta. Una tendina con venti voci non la legge nessuno, e la
 * causale serve a rispondere a una domanda sola: "cosa è successo qui?".
 *
 * L'ordine è quello della tendina: `inventory` per primo perché è il caso di
 * gran lunga più frequente — si conta lo scaffale e si allinea il gestionale.
 */
final class Reasons
{
    public const DEFAULT = 'inventory';

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            'inventory' => 'Inventario',
            'initial_stock' => 'Giacenza iniziale',
            'damaged' => 'Danneggiato',
            'expired' => 'Scaduto',
            'gift' => 'Regalo',
            'internal_use' => 'Uso interno',
            'other' => 'Altro',
            'batch_assignment' => 'Assegnazione lotto',
        ];
    }

    /**
     * Le causali che scrive solo il gestionale, mai una persona: hanno
     * l'etichetta nei Movimenti e nel filtro, non stanno nelle tendine.
     *
     * `batch_assignment` è la compensazione della vendita scoperta (G3.15).
     *
     * @return list<string>
     */
    public static function internal(): array
    {
        return ['batch_assignment'];
    }

    /**
     * Le causali che si scelgono a mano, nell'ordine della tendina.
     *
     * @return array<string, string>
     */
    public static function selectable(): array
    {
        return array_diff_key(self::all(), array_flip(self::internal()));
    }

    public static function exists(string $reason): bool
    {
        return array_key_exists($reason, self::all());
    }

    /** L'etichetta, o stringa vuota se la causale non esiste. */
    public static function label(string $reason): string
    {
        return self::all()[$reason] ?? '';
    }

    /**
     * Le causali che vanno con un documento di magazzino: lo scarico ne
     * sceglie una, l'inventario usa sempre la sua, il carico nessuna.
     *
     * @return array<string, string> nell'ordine della tendina
     */
    public static function forDocument(string $type): array
    {
        $keys = match ($type) {
            'issue' => ['damaged', 'expired', 'gift', 'internal_use', 'other'],
            'stocktake' => [self::DEFAULT],
            default => [],
        };

        return array_intersect_key(self::all(), array_flip($keys));
    }
}
