<?php

namespace Wonder\Plugin\Gestionale\Models\System;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Evento arrivato da un servizio esterno (webhook).
 *
 * Serve a non fare due volte la stessa cosa: i provider rimandano lo stesso
 * evento quando non ricevono risposta, e un pagamento registrato due volte è
 * un problema vero. L'id dell'evento è unico per provider e ambiente.
 */
final class ProviderEvent extends Model
{
    public static string $table = 'gst_provider_events';
    public static string $folder = 'gestionale/provider-events';
    public static string $icon = 'bi bi-arrow-down-circle';

    public const ENVIRONMENTS = ['live', 'test'];
    public const STATUSES = ['received', 'processed', 'failed'];

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('provider')->length(100),
            Column::key('environment')->enum(self::ENVIRONMENTS)->default('live'),
            Column::key('event_id')->length(191)->null(false)->unique(['provider', 'environment', 'event_id']),
            Column::key('type')->length(100),
            Column::key('payload')->json(),
            Column::key('status')->enum(self::STATUSES)->default('received'),
            Column::key('attempts')->int()->default('0'),
            Column::key('error')->type('TEXT'),
            Column::key('processed_at')->datetime(),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('provider')->text()->sanitize(false),
            Field::key('environment')->text()->sanitize(false),
            Field::key('event_id')->text()->sanitize(false),
            Field::key('type')->text()->sanitize(false),
            Field::key('payload')->json(),
            Field::key('status')->text()->sanitize(false),
            Field::key('attempts')->number()->decimals(0),
            Field::key('error')->text(),
            Field::key('processed_at')->date(),
        ];
    }
}
