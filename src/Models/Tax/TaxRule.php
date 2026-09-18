<?php

namespace Wonder\Plugin\Gestionale\Models\Tax;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Regola IVA: paese del cliente × tipo di cliente × tipo fiscale del prodotto
 * → aliquota.
 *
 * La corrispondenza è esatta: se la combinazione non c'è si usa l'aliquota di
 * ripiego delle impostazioni fiscali. Una sola regola per combinazione, ed è
 * l'indice unico a garantirlo.
 */
final class TaxRule extends Model
{
    public static string $table = 'gst_tax_rules';
    public static string $folder = 'gestionale/tax-rules';
    public static string $icon = 'bi bi-diagram-3';

    public const CUSTOMER_TYPES = ['private', 'business'];

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::multiRow()->keepIds()->localOnly();
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('code')->length(100)->null(false)->unique(),
            Column::key('country')->length(2),
            Column::key('customer_type')->enum(self::CUSTOMER_TYPES)->default('private'),
            Column::key('tax_category_id')->int()->null(false)->foreign(TaxCategory::$table)
                ->unique(['country', 'customer_type', 'tax_category_id']),
            Column::key('tax_id')->int()->null(false)->foreign(Tax::$table),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('country')->text()->sanitize(false),
            Field::key('customer_type')->text()->sanitize(false),
            Field::key('tax_category_id')->number()->decimals(0),
            Field::key('tax_id')->number()->decimals(0),
        ];
    }
}
