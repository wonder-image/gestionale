<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\Plugin\Gestionale\Models\System\Setting;

/**
 * Lo stato della scadenza di un lotto (G3.20). Classe pura salvo `days()`.
 *
 * Scaduto vuol dire: la scadenza è prima di oggi, come in `StockPicking`; il
 * giorno stesso si vende ancora. «In scadenza» è entro `batch_expiry_days`
 * giorni da oggi, oggi compreso.
 */
final class BatchExpiry
{
    public const DEFAULT_DAYS = 30;

    /** `expired`, `expiring`, `ok` o `none` (senza scadenza). */
    public static function status(?string $expiry, string $today, int $days): string
    {
        $expiry = substr(trim((string) $expiry), 0, 10);

        if ($expiry === '' || str_starts_with($expiry, '0000')) {
            return 'none';
        }

        if ($expiry < $today) {
            return 'expired';
        }

        return $expiry <= self::plus($today, $days) ? 'expiring' : 'ok';
    }

    /** La data `Y-m-d` fra `$days` giorni. */
    public static function plus(string $today, int $days): string
    {
        return date('Y-m-d', (int) strtotime($today.' +'.max(0, $days).' days'));
    }

    /** «13/10/2026», o «—» senza scadenza. */
    public static function label(?string $expiry): string
    {
        $expiry = substr(trim((string) $expiry), 0, 10);

        if ($expiry === '' || str_starts_with($expiry, '0000')) {
            return '—';
        }

        [$y, $m, $d] = explode('-', $expiry) + ['', '', ''];

        return $d.'/'.$m.'/'.$y;
    }

    /** La data dentro un badge: rosso scaduto, giallo in scadenza. HTML già escapato. */
    public static function badge(?string $expiry, string $today, int $days): string
    {
        $text = htmlspecialchars(self::label($expiry), ENT_QUOTES, 'UTF-8');

        return match (self::status($expiry, $today, $days)) {
            'expired' => '<span class="badge text-bg-danger">'.$text.' · scaduto</span>',
            'expiring' => '<span class="badge text-bg-warning">'.$text.'</span>',
            default => $text,
        };
    }

    /** I giorni dell'impostazione `batch_expiry_days`; 30 se manca. */
    public static function days(): int
    {
        try {
            $value = Setting::current()['batch_expiry_days'] ?? null;
        } catch (Throwable) {
            return self::DEFAULT_DAYS;
        }

        return $value === null || $value === '' ? self::DEFAULT_DAYS : max(0, min(3650, (int) $value));
    }
}
