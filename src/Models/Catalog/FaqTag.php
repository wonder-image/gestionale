<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\ForgetsRequestMemo;
use Wonder\Sql\TableSchema as Column;

/**
 * I tag di una FAQ.
 */
final class FaqTag extends Model
{
    use ForgetsRequestMemo;

    public const MEMO_GROUP = 'faqs';

    public static string $table = 'gst_faq_tags';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-tag';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('faq_id')->int()->null(false)->foreign(Faq::$table),
            Column::key('tag_id')->int()->null(false)->foreign(Tag::$table),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_faq' => ['index' => 'faq_id'],
            'ind_tag' => ['index' => 'tag_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('faq_id')->number()->decimals(0),
            Field::key('tag_id')->number()->decimals(0),
        ];
    }
}
