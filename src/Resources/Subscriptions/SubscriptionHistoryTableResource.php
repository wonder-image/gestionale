<?php

namespace Wonder\Plugin\Gestionale\Resources\Subscriptions;

use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionStatusLog;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderSectionResource;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/** Lo storico degli stati dell'abbonamento, come quello dell'ordine. Il più recente in alto. */
final class SubscriptionHistoryTableResource extends OrderSectionResource
{
    public static string $feature = 'subscriptions';
    public static string $model = SubscriptionStatusLog::class;
    public static string $foreignKey = 'subscription_id';
    public static string $orderDirection = 'DESC';

    public static function path(): string
    {
        return 'app/gestionale/abbonamento-storico';
    }

    public static function icon(): string
    {
        return 'bi-clock-history';
    }

    public static function titleLabel(): string
    {
        return 'Storico dell\'abbonamento';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'cambio',
            'plural_label' => 'cambi',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'creation' => 'Data',
            'from_value' => 'Da',
            'to_value' => 'A',
            'source' => 'Origine',
            'message' => 'Nota',
        ];
    }

    public static function tableSchema(): array
    {
        $stato = static fn (string $value): string => static::escape(SubscriptionResource::statusLabels()[$value] ?? $value);

        return [
            TableColumn::key('creation')
                ->text()
                ->size('medium')
                ->formatter(static fn (array $row): string => static::escape(OrderSheet::date((string) ($row['creation'] ?? '')))),
            TableColumn::key('from_value')
                ->text()
                ->formatter(static fn (array $row): string => $stato((string) ($row['from_value'] ?? ''))),
            TableColumn::key('to_value')
                ->text()
                ->formatter(static fn (array $row): string => $stato((string) ($row['to_value'] ?? ''))),
            TableColumn::key('source')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(SubscriptionResource::sourceLabel((string) ($row['source'] ?? '')))),
            TableColumn::key('message')
                ->text()
                ->formatter(static fn (array $row): string => static::escapeStored((string) ($row['message'] ?? ''))),
        ];
    }

    protected static function emptyText(): string
    {
        return 'Nessun cambio di stato.';
    }

    protected static function sortColumn(): string
    {
        return 'id';
    }
}
