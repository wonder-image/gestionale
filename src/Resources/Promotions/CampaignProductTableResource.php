<?php

namespace Wonder\Plugin\Gestionale\Resources\Promotions;

use Throwable;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaign;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductPhotos;
use Wonder\Plugin\Gestionale\Support\Promotions\Campaigns;
use Wonder\Plugin\Gestionale\Support\Stock\LevelsSql;
use Wonder\Plugin\Gestionale\Support\Stock\ProductNames;

/**
 * L'elenco dei prodotti nell'anteprima di una campagna.
 *
 * Come le tabelle della scheda ordine non ha pagina né voce di menu e non si
 * legge dall'API: esiste perché la scheda della campagna incorpori la sua
 * tabella con `TableColumn` e `TableLayoutSchema`. È un elenco di `Product`
 * ristretto ai prodotti che la campagna prende, con la foto, il nome per
 * intero («Articolo — opzione») con lo SKU sotto, il prezzo di prima e quello
 * di dopo; la casella di ricerca e l'ordinamento sono quelli del core.
 *
 * Il prezzo di dopo non sta nel database e le righe le chiede l'API a ogni
 * pagina, in un'altra richiesta: per questo la query porta con sé l'id della
 * campagna (`campaign_id`, firmato come il resto) e il formatter ricalcola lo
 * sconto dalla campagna salvata.
 */
final class CampaignProductTableResource extends GestionaleResource
{
    public static string $feature = 'discount_campaigns';
    public static string $model = Product::class;
    public static string $orderColumn = 'model_name';
    public static string $orderDirection = 'ASC';

    /** @var array<int, array<string, mixed>> le campagne già lette in questa richiesta */
    private static array $campaigns = [];

    /** L'id della campagna di cui si mostra l'anteprima: entra nella select. */
    private static int $campaignId = 0;

    public static function path(): string
    {
        return 'app/gestionale/campagne-prodotti';
    }

    public static function icon(): string
    {
        return 'bi-tags';
    }

    public static function titleLabel(): string
    {
        return 'Prodotti della campagna';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'prodotto',
            'plural_label' => 'prodotti',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'photo' => 'Foto',
            'model_name' => 'Articolo',
            'price' => 'Prezzo',
            'campaign_price' => 'Con la campagna',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('photo')
                ->image()
                ->size('little')
                ->formatter(static fn (array $row): string => ProductPhotos::forModel(
                    (int) ($row['product_model_id'] ?? 0),
                    (int) ($row['product_variant_id'] ?? 0),
                    (int) ($row['id'] ?? 0)
                )),
            TableColumn::key('model_name')
                ->text()
                ->sortable()
                ->formatter(static fn (array $row): string => static::nameCell($row)),
            TableColumn::key('price')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::priceCell((string) ($row['price'] ?? ''), false)),
            TableColumn::key('campaign_price')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::priceCell(static::afterPrice($row), true)),
        ];
    }

    /** Il nome dell'articolo e la campagna arrivano dalla query: così il core ordina, cerca e firma. */
    public static function select(): string
    {
        return LevelsSql::modelName().' AS model_name, '.static::$campaignId.' AS campaign_id';
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        return TableLayoutSchema::for(static::class)
            ->cleanHeader()
            ->select(static::select())
            ->filterSearch()
            ->filterLimit(false)
            ->searchFields(['name', 'sku', ProductModel::$table.'.name']);
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()->only([]);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('promozioni')
            ->title(static::titleLabel())
            ->authority(['admin', 'administrator'])
            ->enabled(false);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backend(['list'], ['admin', 'administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /**
     * La tabella di questi prodotti con il prezzo di questa campagna, o la
     * frase se non ce n'è nessuno.
     *
     * @param list<int> $productIds i prodotti che la campagna prende (`Campaigns::preview`)
     */
    public static function embed(int $campaignId, array $productIds): string
    {
        $vuoto = '<p class="text-muted mb-0">Nessun prodotto: la campagna, così com\'è salvata, non ne prende.</p>';
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));

        if ($campaignId <= 0 || $ids === [] || !static::featureActive()) {
            return $vuoto;
        }

        try {
            static::$campaignId = $campaignId;
            $condition = '`id` IN ('.implode(',', $ids).") AND `deleted` = 'false'";
            $tabella = static::backendTable([], static::tableLayoutSchema());
            $tabella->length(25);
            $tabella->query($condition);
            $tabella->queryOrder(static::$orderColumn, static::$orderDirection);
            $html = (string) $tabella->generate(false);
        } catch (Throwable) {
            return $vuoto;
        }

        return $html !== '' ? $html : $vuoto;
    }

    /** "Articolo — opzione" con lo SKU sotto. */
    public static function nameCell(array $row): string
    {
        $names = ProductNames::of($row, [
            (int) ($row['product_model_id'] ?? 0) => trim((string) ($row['model_name'] ?? '')),
        ]);
        $html = static::escapeStored($names['article'])
            .($names['option'] !== '' ? ' — '.static::escapeStored($names['option']) : '');
        $sku = trim((string) ($row['sku'] ?? ''));

        return $html.($sku !== '' ? '<div class="text-muted small">'.static::escapeStored($sku).'</div>' : '');
    }

    /** Un importo a destra, in grassetto quello di dopo; vuoto se la campagna non lo dà. */
    public static function priceCell(string $value, bool $strong): string
    {
        if (!is_numeric($value)) {
            return '';
        }

        $text = number_format((float) $value, 2, ',', '.').' €';

        return '<span class="d-block text-end" style="font-variant-numeric: tabular-nums">'
            .($strong ? '<strong>'.$text.'</strong>' : $text).'</span>';
    }

    /** Il prezzo di questa riga con la campagna della query; vuoto se non lo cambia. */
    private static function afterPrice(array $row): string
    {
        $campaignId = (int) ($row['campaign_id'] ?? 0);
        $campaign = self::$campaigns[$campaignId] ??= (array) (DiscountCampaign::find(['id' => $campaignId], 1) ?: []);

        return $campaign === [] ? '' : Campaigns::previewPrice($campaign, (float) ($row['price'] ?? 0), (float) ($row['sale_price'] ?? 0));
    }
}
