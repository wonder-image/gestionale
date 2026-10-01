<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/** I resi dell'ordine: la tabella c'è solo con la funzionalità «returns». */
final class OrderReturnTableResource extends OrderSectionResource
{
    public static string $model = SalesReturn::class;
    public static string $feature = 'returns';

    public static function path(): string
    {
        return 'app/gestionale/ordine-resi';
    }

    public static function icon(): string
    {
        return 'bi-arrow-return-left';
    }

    public static function titleLabel(): string
    {
        return 'Resi dell\'ordine';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'reso',
            'plural_label' => 'resi',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'number' => 'Numero',
            'status' => 'Stato',
            'requested_at' => 'Data',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('number')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape((string) (($row['number'] ?? '') !== '' ? $row['number'] : ($row['code'] ?? '')))),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(OrderSheet::returnStatus((string) ($row['status'] ?? '')))),
            TableColumn::key('requested_at')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(OrderSheet::date(
                    (string) (($row['requested_at'] ?? '') !== '' ? $row['requested_at'] : ($row['creation'] ?? ''))
                ))),
        ];
    }

    protected static function emptyText(): string
    {
        return 'Nessun reso su questo ordine.';
    }
}
