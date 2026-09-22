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
        ];
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
}
