<?php

namespace Wonder\Plugin\Gestionale\Support;

use Throwable;

/**
 * Posizione in fondo all'elenco per una riga nuova.
 *
 * La colonna `position` esiste per tenere un ordine, non per essere compilata
 * a mano: il backend la mette da sé alla creazione e non la mostra. Dove il
 * commerciante deve poter dire "questo viene prima", si usa una colonna
 * `importance` con un select, non un numero da indovinare.
 *
 * `$scope` limita il conteggio a un gruppo, per esempio i fratelli di una
 * categoria: `Positions::next($table, ['parent_id' => 3])`.
 */
final class Positions
{
    /** @param array<string, mixed> $scope */
    public static function next(string $table, array $scope = []): int
    {
        try {
            $last = sqlSelect(
                $table,
                array_merge($scope, ['deleted' => 'false']),
                1,
                'position',
                'DESC'
            )->row;
        } catch (Throwable) {
            // Senza database (test degli schemi) la prima posizione va bene.
            return 1;
        }

        return is_array($last) && $last !== [] ? (int) ($last['position'] ?? 0) + 1 : 1;
    }
}
