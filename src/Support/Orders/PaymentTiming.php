<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\System\Setting;

/**
 * Quando si paga con quel metodo, e quanto resta impegnata la merce.
 *
 * Tre modi, e tre attese diverse. Con la carta il denaro arriva subito o non
 * arriva: mezz'ora di prenotazione e via. Col bonifico il denaro arriva fra
 * giorni, e la merce deve aspettarlo. Col contrassegno o il ritiro in negozio
 * il denaro arriva alla consegna: la merce esce di magazzino appena l'ordine è
 * confermato, e non c'è niente da far scadere.
 */
final class PaymentTiming
{
    public const IMMEDIATE = 'immediate';
    public const DEFERRED = 'deferred';
    public const ON_DELIVERY = 'on_delivery';

    public const ALL = [self::IMMEDIATE, self::DEFERRED, self::ON_DELIVERY];

    /** Il modo di quel metodo di pagamento; senza metodo, il più stretto. */
    public static function of(int $paymentMethodId): string
    {
        $method = $paymentMethodId > 0 ? PaymentMethod::findById($paymentMethodId) : null;

        if (!is_array($method)) {
            return self::IMMEDIATE;
        }

        $timing = (string) ($method['timing'] ?? '');

        return in_array($timing, self::ALL, true) ? $timing : self::IMMEDIATE;
    }

    /** La scadenza della prenotazione, con minuti e giorni dalle impostazioni. */
    public static function reservationExpiry(string $timing, ?string $at = null): ?string
    {
        $settings = Setting::current();

        return self::expiry(
            $timing,
            (int) ($settings['order_reservation_minutes'] ?? 30),
            (int) ($settings['order_payment_wait_days'] ?? 7),
            $at
        );
    }

    /**
     * La scadenza, contata da `$at`. Pura.
     *
     * `null` vuol dire «non scade»: il contrassegno, e anche un'impostazione
     * messa a zero — che è il modo del commerciante di dire «non far scadere
     * niente», non «fai scadere subito».
     */
    public static function expiry(string $timing, int $minutes, int $days, ?string $at = null): ?string
    {
        $from = strtotime($at ?? date('Y-m-d H:i:s'));

        if ($from === false) {
            $from = time();
        }

        if ($timing === self::ON_DELIVERY) {
            return null;
        }

        if ($timing === self::DEFERRED) {
            return $days > 0 ? date('Y-m-d H:i:s', $from + $days * 86400) : null;
        }

        return $minutes > 0 ? date('Y-m-d H:i:s', $from + $minutes * 60) : null;
    }
}
