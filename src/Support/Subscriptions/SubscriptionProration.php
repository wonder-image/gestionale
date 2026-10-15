<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use DateTimeImmutable;

/**
 * Le date dei periodi di una riga d'abbonamento e il pro-rata (G10b), pure.
 *
 * Ogni riga ha un'**ancora** (la data da cui contano i suoi periodi) e una
 * durata in mesi. I periodi si contano sempre dall'ancora (`+n × durata`
 * mesi), mai uno dall'altro: un mensile partito il 31 gennaio rinnova il 28
 * febbraio e poi il 31 marzo, non il 28 di ogni mese. Se il giorno dell'ancora
 * non c'è nel mese, vale l'ultimo giorno del mese.
 *
 * Le date sono giorni: chi chiama le passa a mezzanotte.
 */
final class SubscriptionProration
{
    /** `$date` più `$months` mesi, all'ultimo giorno del mese se il giorno non c'è. */
    public static function addMonths(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        $total = (int) $date->format('Y') * 12 + (int) $date->format('n') - 1 + $months;
        $year = intdiv($total, 12);
        $month = $total % 12 + 1;
        $first = $date->setDate($year, $month, 1);
        $day = min((int) $date->format('j'), (int) $first->format('t'));

        return $first->setDate($year, $month, $day);
    }

    /** I mesi interi da `$from` a `$to` (negativi se `$to` viene prima). */
    public static function monthsBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if ($to < $from) {
            return -self::monthsBetween($to, $from);
        }

        $months = ((int) $to->format('Y') - (int) $from->format('Y')) * 12 + (int) $to->format('n') - (int) $from->format('n');

        return self::addMonths($from, $months) > $to ? $months - 1 : $months;
    }

    /** La riga con durata `$term` si addebita nel periodo che parte in `$periodStart`? */
    public static function charges(DateTimeImmutable $anchor, int $term, DateTimeImmutable $periodStart): bool
    {
        $months = self::monthsBetween($anchor, $periodStart);
        $term = max(1, $term);

        return $months >= 0 && $months % $term === 0
            && self::addMonths($anchor, $months)->format('Y-m-d') === $periodStart->format('Y-m-d');
    }

    /** Il numero dell'addebito della riga nel periodo che parte in `$periodStart`, da zero. */
    public static function chargeIndex(DateTimeImmutable $anchor, int $term, DateTimeImmutable $periodStart): int
    {
        return intdiv(max(0, self::monthsBetween($anchor, $periodStart)), max(1, $term));
    }

    /**
     * Inizio e fine (esclusa) del periodo della riga che contiene `$at`.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public static function rowPeriod(DateTimeImmutable $anchor, int $term, DateTimeImmutable $at): array
    {
        $term = max(1, $term);
        $months = self::monthsBetween($anchor, $at);
        $n = intdiv($months, $term) - ($months < 0 && $months % $term !== 0 ? 1 : 0);

        return [self::addMonths($anchor, $n * $term), self::addMonths($anchor, ($n + 1) * $term)];
    }

    /**
     * La parte di `$amount` che resta da `$effective` alla fine del periodo
     * `[$start, $end)`, a giorni: tutto se parte all'inizio, niente dalla fine.
     */
    public static function quota(float $amount, DateTimeImmutable $effective, DateTimeImmutable $start, DateTimeImmutable $end): float
    {
        if ($effective >= $end) {
            return 0.0;
        }

        if ($effective <= $start) {
            return round($amount, 2);
        }

        $total = (int) $start->diff($end)->format('%a');

        return $total <= 0 ? 0.0 : round($amount * (int) $effective->diff($end)->format('%a') / $total, 2);
    }
}
