<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/** Lo storico dell'ordine: chi ha cambiato cosa, quando e da dove. Il più recente in alto. */
final class OrderHistoryTableResource extends OrderSectionResource
{
    public static string $model = OrderStatusLog::class;
    public static string $orderDirection = 'DESC';

    public static function path(): string
    {
        return 'app/gestionale/ordine-storico';
    }

    public static function icon(): string
    {
        return 'bi-clock-history';
    }

    public static function titleLabel(): string
    {
        return 'Storico dell\'ordine';
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
            'field' => 'Cosa',
            'from_value' => 'Da',
            'to_value' => 'A',
            'source' => 'Origine',
            'user_id' => 'Utente',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('creation')
                ->text()
                ->size('medium')
                ->formatter(static fn (array $row): string => static::escape(OrderSheet::date((string) ($row['creation'] ?? '')))),
            TableColumn::key('field')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(OrderSheet::logField((string) ($row['field'] ?? '')))),
            TableColumn::key('from_value')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(OrderSheet::logValue((string) ($row['field'] ?? ''), (string) ($row['from_value'] ?? '')))),
            TableColumn::key('to_value')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(OrderSheet::logValue((string) ($row['field'] ?? ''), (string) ($row['to_value'] ?? '')))),
            TableColumn::key('source')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape((string) ($row['source'] ?? ''))),
            TableColumn::key('user_id')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => (int) ($row['user_id'] ?? 0) > 0 ? '#'.(int) $row['user_id'] : '—'),
        ];
    }

    protected static function emptyText(): string
    {
        return 'Nessun cambio registrato.';
    }

    protected static function sortColumn(): string
    {
        return 'id';
    }
}
