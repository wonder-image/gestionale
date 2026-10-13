<?php

namespace Wonder\Plugin\Gestionale\Models\Subscriptions;

use Wonder\Plugin\Gestionale\Models\System\StatusLog;

/**
 * La storia degli stati di un abbonamento: chi ha cambiato cosa, quando e da
 * dove. Colonne, indice e campi arrivano tutti dalla base.
 */
final class SubscriptionStatusLog extends StatusLog
{
    public static string $table = 'gst_subscription_status_logs';
    public static string $folder = 'gestionale/subscriptions';

    public static function entityColumn(): string
    {
        return 'subscription_id';
    }

    public static function entityTable(): string
    {
        return Subscription::$table;
    }
}
