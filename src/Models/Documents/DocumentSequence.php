<?php

namespace Wonder\Plugin\Gestionale\Models\Documents;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Progressivo dei documenti: una riga per tipo di documento, anno e mese.
 *
 * Non si sincronizza mai tra ambienti (8.2): i numeri dei documenti li fa
 * l'ambiente dove il documento nasce, e portarli in giro creerebbe buchi o
 * doppioni.
 */
final class DocumentSequence extends Model
{
    public static string $table = 'gst_document_sequences';
    public static string $folder = 'gestionale/document-sequences';
    public static string $icon = 'bi bi-123';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('document_type')->length(100)->null(false)->unique(['document_type', 'year', 'month']),
            Column::key('year')->int(),
            Column::key('month')->int(),
            Column::key('last_number')->int()->default('0'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('document_type')->text(),
            Field::key('year')->number()->decimals(0),
            Field::key('month')->number()->decimals(0),
            Field::key('last_number')->number()->decimals(0),
        ];
    }
}
