<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\ForgetsRequestMemo;
use Wonder\Sql\TableSchema as Column;

/**
 * Una domanda frequente con la sua risposta.
 *
 * Si vede nella scheda dei prodotti che sceglie (tutti, oppure categorie con
 * le sottocategorie, tag, marchi e articoli: le quattro tabelle `gst_faq_*`)
 * e, con `show_on_home`, nella home. Senza selezione e senza home non si vede
 * da nessuna parte. L'ordine è `importance`, poi `position` (quella di
 * creazione).
 *
 * Non si sincronizza, come il resto del catalogo.
 */
final class Faq extends Model
{
    use ForgetsRequestMemo;

    public const MEMO_GROUP = 'faqs';

    public static string $table = 'gst_faqs';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-question-circle';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('question'),
            Column::key('answer')->type('TEXT'),
            Column::key('importance')->enum(['high', 'normal', 'low'])->default('normal'),
            Column::key('position')->int(),
            Column::key('visible')->enum(['true', 'false'])->default('true'),
            Column::key('show_on_home')->enum(['true', 'false'])->default('false'),
            Column::key('applies_to_all')->enum(['true', 'false'])->default('false'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::FAQ),
            Field::key('question')->text()->sanitize(),
            Field::key('answer')->text()->richText(),
            Field::key('importance')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
            Field::key('visible')->text()->sanitize(false),
            Field::key('show_on_home')->text()->sanitize(false),
            Field::key('applies_to_all')->text()->sanitize(false),
        ];
    }
}
