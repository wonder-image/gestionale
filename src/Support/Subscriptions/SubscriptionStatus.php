<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionStatusLog;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;

/**
 * L'unico che scrive lo stato di un abbonamento: lo cambia e lascia la riga
 * nella storia. Uno stato uguale non scrive niente, così una notifica
 * ripetuta non riempie la storia.
 */
final class SubscriptionStatus
{
    /** `true` se lo stato è cambiato. */
    public static function move(int $subscriptionId, string $to, string $source = 'system', string $message = '', ?int $userId = null): bool
    {
        if (!in_array($to, Subscription::STATUSES, true)) {
            return false;
        }

        $row = Subscription::findById($subscriptionId);

        if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0) {
            return false;
        }

        $from = (string) ($row['status'] ?? '');

        if ($from === $to) {
            return false;
        }

        Subscription::update(['status' => $to], $subscriptionId);
        SubscriptionItems::follow($subscriptionId, $from, $to);
        StatusLogger::record(SubscriptionStatusLog::class, $subscriptionId, 'status', $from, $to, $source, $userId, $message);

        return true;
    }
}
