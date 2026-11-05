<?php

namespace Wonder\Plugin\Gestionale\Models\Pricing;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Il listino di un canale di vendita (#162): un canale ha al più un listino,
 * un listino può valere su più canali.
 *
 * I canali sono quelli del registro `Support\Pricing\PriceListChannels`
 * (ordini, feed, marketplace, più quelli dei moduli). Si scrive dal form del
 * listino; il prezzo per canale lo dà `Support\Pricing\ChannelPrice`.
 */
final class PriceListChannel extends Model
{
    public static string $table = 'gst_price_list_channels';
    public static string $folder = 'gestionale/pricing';
    public static string $icon = 'bi bi-journal-text';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('channel')->length(30)->null(false),
            Column::key('price_list_id')->int()->null(false)->foreign(PriceList::$table),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'uni_channel' => ['unique' => ['channel']],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('channel')->text()->sanitize(false),
            Field::key('price_list_id')->number()->decimals(0),
        ];
    }
}
