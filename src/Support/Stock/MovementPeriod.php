<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use DateTimeImmutable;

/**
 * I periodi del filtro *Periodo* di Movimenti e Ordini: oggi, gli ultimi sette
 * giorni, il mese, l'anno.
 *
 * Il fuso è quello del sito: l'`$now` lo passa il chiamante, qui non si
 * chiama mai `date()`. Gli estremi sono inclusi e arrivano come testo
 * `Y-m-d H:i:s`; la chiave scelta dall'utente non entra mai nell'SQL, che si
 * compone solo di quelle date.
 */
final class MovementPeriod
{
    /** @var array<string, string> chiave → etichetta */
    public const OPTIONS = [
        'oggi' => 'Oggi',
        '7-giorni' => 'Ultimi 7 giorni',
        'questo-mese' => 'Questo mese',
        'mese-scorso' => 'Mese scorso',
        'quest-anno' => 'Quest\'anno',
    ];

    /** Le voci del filtro: «Sempre» (nessun limite) e poi i periodi. */
    public static function filterOptions(): array
    {
        return ['' => 'Sempre'] + self::OPTIONS;
    }

    /** @return array{from: string, to: string}|null `null` per una chiave sconosciuta o vuota */
    public static function range(string $key, DateTimeImmutable $now): ?array
    {
        $today = $now->setTime(0, 0, 0);

        $bounds = match ($key) {
            'oggi' => [$today, $today],
            '7-giorni' => [$today->modify('-6 days'), $today],
            'questo-mese' => [$today->modify('first day of this month'), $today->modify('last day of this month')],
            'mese-scorso' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
            'quest-anno' => [$today->setDate((int) $today->format('Y'), 1, 1), $today->setDate((int) $today->format('Y'), 12, 31)],
            default => null,
        };

        if ($bounds === null) {
            return null;
        }

        return [
            'from' => $bounds[0]->format('Y-m-d').' 00:00:00',
            'to' => $bounds[1]->format('Y-m-d').' 23:59:59',
        ];
    }

    /** La condizione già pronta sulla colonna data; testo vuoto se non c'è un periodo. */
    public static function sql(string $key, string $column, DateTimeImmutable $now): string
    {
        $range = self::range($key, $now);

        if ($range === null || preg_match('/^[A-Za-z0-9_]+$/D', $column) !== 1) {
            return '';
        }

        return '`'.$column."` >= '".$range['from']."' AND `".$column."` <= '".$range['to']."'";
    }
}
