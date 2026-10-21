<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Stock\BatchExpiry;

/**
 * Le rate da incassare (G9.3), per lo scadenzario e il suo riquadro. Pura
 * salvo `days()`.
 *
 * Una rata è una riga `payment` di `gst_payments` con la scadenza. Scaduta
 * vuol dire: la scadenza è prima di oggi; il giorno stesso si paga ancora in
 * tempo. «In scadenza» è entro `payment_due_days` giorni da oggi, oggi
 * compreso: le stesse regole dei lotti, e infatti i conti sulle date sono
 * quelli di `BatchExpiry`.
 */
final class DuePayments
{
    public const DEFAULT_DAYS = 7;
    /** I pagati dello scadenzario: gli ultimi novanta giorni. */
    public const PAID_DAYS = 90;

    /** `expired`, `expiring`, `ok` o `none` (senza scadenza). */
    public static function status(?string $due, string $today, int $days): string
    {
        return BatchExpiry::status($due, $today, $days);
    }

    /** La scadenza dentro un badge: rosso scaduta, giallo in scadenza. HTML già escapato. */
    public static function badge(?string $due, string $today, int $days): string
    {
        $text = htmlspecialchars(BatchExpiry::label($due), ENT_QUOTES, 'UTF-8');

        // Non con Badge del core: lo avvolge sempre in un <div>, qui serve in linea.
        return match (self::status($due, $today, $days)) {
            'expired' => '<span class="badge text-bg-danger">'.$text.' · scaduta</span>',
            'expiring' => '<span class="badge text-bg-warning">'.$text.'</span>',
            default => $text,
        };
    }

    /** Le scelte del filtro *Stato* dello scadenzario. @return array<string, string> */
    public static function filterOptions(): array
    {
        return [
            'attesa' => 'Tutte in attesa',
            'scadute' => 'Scadute',
            'scadenza' => 'In scadenza',
            'pagate' => 'Pagate (90 giorni)',
        ];
    }

    /**
     * Le righe che lo scadenzario guarda: incassi con la scadenza, vivi.
     * Senza nome di tabella, come le condizioni di `querySchema()` del core.
     */
    public static function baseCondition(): string
    {
        return "deleted = 'false' AND type = 'payment' AND due_date IS NOT NULL AND due_date > '1000-01-01'";
    }

    /**
     * La condizione del filtro *Stato*. Senza scelta, le rate in attesa.
     *
     * @param list<string> $values
     */
    public static function filterCondition(array $values, string $today, int $days): string
    {
        $t = '`'.Payment::$table.'`';
        $pending = $t.".`status` = 'pending'";
        $parts = [];

        foreach (array_unique(array_map('strval', $values)) as $value) {
            $parts[] = match ($value) {
                'attesa', '' => $pending,
                'scadute' => '('.$pending.' AND '.$t.".`due_date` < '".$today."')",
                'scadenza' => '('.$pending.' AND '.$t.".`due_date` >= '".$today."' AND ".$t.".`due_date` <= '".BatchExpiry::plus($today, $days)."')",
                'pagate' => '('.$t.".`status` = 'paid' AND ".$t.".`paid_at` >= '".self::minus($today, self::PAID_DAYS)."')",
                default => '',
            };
        }

        $parts = array_values(array_unique(array_filter($parts)));

        return match (count($parts)) {
            0 => $pending,
            1 => $parts[0],
            default => '('.implode(' OR ', $parts).')',
        };
    }

    /**
     * Il riepilogo del riquadro: le rate scadute o in scadenza, quante sono
     * e quanto valgono. Pura: le righe le passa chi chiama.
     *
     * @param list<array<string, mixed>> $rows righe in attesa, in ordine di scadenza
     * @return array{rows: list<array<string, mixed>>, count: int, total: float, expired: float}
     */
    public static function summary(array $rows, string $today, int $days): array
    {
        $out = [];
        $total = 0.0;
        $expired = 0.0;

        foreach ($rows as $row) {
            $status = self::status((string) ($row['due_date'] ?? ''), $today, $days);

            if ($status !== 'expired' && $status !== 'expiring') {
                continue;
            }

            $amount = round((float) ($row['amount'] ?? 0), 2);
            $total = round($total + $amount, 2);

            if ($status === 'expired') {
                $expired = round($expired + $amount, 2);
            }

            $out[] = $row;
        }

        return ['rows' => $out, 'count' => count($out), 'total' => $total, 'expired' => $expired];
    }

    /** I giorni dell'impostazione `payment_due_days`; 7 se manca. */
    public static function days(): int
    {
        try {
            $value = Setting::current()['payment_due_days'] ?? null;
        } catch (Throwable) {
            return self::DEFAULT_DAYS;
        }

        return $value === null || $value === '' ? self::DEFAULT_DAYS : max(0, min(365, (int) $value));
    }

    private static function minus(string $today, int $days): string
    {
        return date('Y-m-d', (int) strtotime($today.' -'.max(0, $days).' days'));
    }
}
