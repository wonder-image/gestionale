<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

use Throwable;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\CategoryTree;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Stock\LevelsSql;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\ProductNames;
use Wonder\Plugin\Gestionale\Support\Stock\Thresholds;

/**
 * "Giacenze": quanti pezzi ci sono di ogni versione, e in quale sede.
 *
 * È l'elenco del core sul Model `Product`, una riga per versione, anche a
 * zero e anche ferma. Si consulta e basta: niente *Aggiungi*, niente
 * modifica, niente API. Un numero si corregge con la rettifica nella scheda
 * della versione; carico, scarico e conta passeranno dai documenti.
 *
 * I numeri nascono nella query (`LevelsSql`), così il core li ordina e li
 * pagina da solo. Con più sedi c'è una colonna per sede e la giacenza diventa
 * *Totale*; quali sedi, lo decide `Locations::shown()`.
 */
final class StockLevelResource extends GestionaleResource
{
    public static string $model = Product::class;
    public static string $orderColumn = 'model_name';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'magazzino/magazzino-giacenze';

    /** @var array<int, list<array<string, mixed>>> le foto pronte, per articolo */
    private static array $images = [];

    /** @var array<int, float> la scorta minima sulla sede principale, per versione */
    private static array $thresholds = [];

    public static function path(): string
    {
        return 'app/gestionale/giacenze';
    }

    public static function icon(): string
    {
        return 'bi-boxes';
    }

    public static function titleLabel(): string
    {
        return 'Giacenze';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'versione',
            'plural_label' => 'versioni',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'this' => 'questa',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'photo' => 'Foto',
            'model_name' => 'Articolo',
            'sku' => 'SKU',
            'stock_quantity' => 'Giacenza',
            'min_stock_quantity' => 'Scorta minima',
            'stock_reserved' => 'Impegnati',
            'stock_available' => 'Disponibili',
        ];
    }

    public static function tableSchema(): array
    {
        $locations = static::locationColumns();

        $columns = [
            TableColumn::key('photo')
                ->image()
                ->size('little')
                ->formatter(static fn (array $row): string => static::photo($row)),
            TableColumn::key('model_name')
                ->text()
                ->sortable()
                ->formatter(static fn (array $row): string => static::name($row)),
            TableColumn::key('sku')->text()->size('little')->sortable(),
        ];

        foreach ($locations as $location) {
            $key = 'stock_loc_'.$location['id'];
            $columns[] = TableColumn::key($key)
                ->label($location['label'])
                ->text()
                ->size('little')
                ->sortable()
                ->formatter(static fn (array $row): string => static::number($row[$key] ?? 0, true));
        }

        // La scorta minima è della versione, non della sede: il rosso sta qui.
        $columns[] = TableColumn::key('stock_quantity')
            ->label($locations === [] ? 'Giacenza' : 'Totale')
            ->text()
            ->size('little')
            ->sortable()
            ->formatter(static fn (array $row): string => static::total($row));

        if (Gestionale::feature('low_stock_alerts')) {
            // La soglia sta in `gst_stock_thresholds`, per sede: qui quella
            // della sede principale, senza ordinamento perché non nasce
            // nella query. Le soglie per sede in elenco sono di G2b-bis.
            $columns[] = TableColumn::key('min_stock_quantity')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::number(static::threshold($row)));
        }

        if (Gestionale::feature('orders')) {
            foreach (['stock_reserved', 'stock_available'] as $key) {
                $columns[] = TableColumn::key($key)
                    ->text()
                    ->size('little')
                    ->sortable()
                    ->formatter(static fn (array $row): string => static::number($row[$key] ?? 0));
            }
        }

        $columns[] = TableColumn::key('actions')->button()->actions([
            'movimenti' => ['label' => 'Movimenti', 'href' => StockMovementResource::listUrl().'?versione={id}'],
            'versione' => ['label' => 'Apri la versione', 'href' => static::productUrl()],
        ]);

        return $columns;
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        $schema = TableLayoutSchema::for(static::class)
            ->title('Giacenze')
            ->results()
            ->hideButtonAdd()
            ->select(static::select())
            ->filterSearch()
            ->searchFields(['name', 'sku', 'ean', ProductModel::$table.'.name'])
            ->filterCustom('Stato', 'active', ['' => 'Tutte', 'true' => 'In vendita', 'false' => 'Ferme'])
            ->filterQuery('Marchio', 'marchio', static::brandOptions(), static fn (array $values): string => static::brandCondition($values))
            ->filterQuery('Categoria', 'categoria', static::categoryOptions(), static fn (array $values): string => static::categoryCondition($values));

        if (Gestionale::feature('low_stock_alerts')) {
            $schema->filterQuery(
                'Scorta',
                'scorta',
                ['' => 'Tutte', 'sotto' => 'Sotto scorta'],
                static fn (array $values): string => in_array('sotto', $values, true) ? LevelsSql::openAlert() : ''
            );
        }

        return $schema;
    }

    /**
     * Le colonne calcolate: il nome dell'articolo, le giacenze e, secondo le
     * funzionalità, impegnati, disponibili e avviso di scorta.
     *
     * Il core firma questa select quando disegna la pagina: l'ora delle
     * prenotazioni è quella del caricamento, non quella di ogni pagina
     * dell'elenco.
     */
    public static function select(): string
    {
        $now = date('Y-m-d H:i:s');
        $parts = [
            LevelsSql::modelName().' AS model_name',
            LevelsSql::quantity().' AS stock_quantity',
        ];

        foreach (static::locationColumns() as $location) {
            $parts[] = LevelsSql::quantityAt($location['id']).' AS stock_loc_'.$location['id'];
        }

        if (Gestionale::feature('orders')) {
            $parts[] = LevelsSql::reserved($now).' AS stock_reserved';
            $parts[] = LevelsSql::available($now).' AS stock_available';
        }

        if (Gestionale::feature('low_stock_alerts')) {
            $parts[] = LevelsSql::openAlert().' AS stock_alert';
        }

        return implode(', ', $parts);
    }

    /**
     * Le sedi che hanno una colonna: nessuna finché la sede da mostrare è una
     * sola, perché allora la giacenza è già il totale.
     *
     * @return list<array{id: int, label: string}>
     */
    public static function locationColumns(): array
    {
        $shown = Locations::shown();

        return count($shown) >= 2 ? $shown : [];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only(['list'])
            ->titles(['list' => 'Giacenze'])
            ->subtitles(['list' => 'Quanti pezzi hai di ogni versione'
                .(static::locationColumns() === [] ? '' : ', sede per sede')
                .'. Qui si consulta soltanto: un numero sbagliato si corregge con la rettifica, dalla scheda della versione.']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('magazzino', 'Magazzino', 'bi-boxes', 400, ['admin', 'administrator'])
            ->title('Giacenze')
            ->order(10)
            ->authority(['admin', 'administrator']);
    }

    public static function permissionSchema(): PermissionSchema
    {
        // Solo `list`: da qui non cambia nessun numero.
        return PermissionSchema::for(static::class)->backend(['list'], ['admin', 'administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /**
     * L'elenco con il solo filtro *Scorta*: lo aprono l'email degli avvisi e
     * il riquadro della bacheca.
     */
    public static function lowStockUrl(): string
    {
        return static::listUrl().'?'.Product::$table.'__scorta=sotto';
    }

    /** L'indirizzo dell'elenco, dalla rotta con il nome; il percorso è il ripiego. */
    public static function listUrl(): string
    {
        $base = '/backend/'.static::path();

        if (function_exists('__r')) {
            try {
                $named = (string) __r('backend.resource.'.static::slug().'.list');
                $base = $named !== '' ? $named : $base;
            } catch (Throwable) {
                // Rotta non registrata (test, comandi): resta il percorso.
            }
        }

        return $base;
    }

    /**
     * Le versioni di questi marchi.
     *
     * @param list<string> $values
     */
    public static function brandCondition(array $values): string
    {
        $ids = static::ids($values);

        return $ids === [] ? '' : '`'.Product::$table.'`.`product_model_id` IN (SELECT m.id FROM `'.ProductModel::$table.'` m'
            .' WHERE m.brand_id IN ('.implode(',', $ids).") AND m.deleted = 'false')";
    }

    /**
     * Le versioni degli articoli in queste categorie o in una loro
     * sottocategoria, a qualunque profondità.
     *
     * @param list<string> $values
     * @param list<array<string, mixed>>|null $rows le categorie; `null` le legge
     */
    public static function categoryCondition(array $values, ?array $rows = null): string
    {
        $rows ??= static::rowsOf(Category::class, [], 'position');
        $ids = [];

        foreach (static::ids($values) as $id) {
            $ids = [...$ids, $id, ...CategoryTree::descendants($rows, $id)];
        }

        $ids = array_values(array_unique($ids));

        return $ids === [] ? '' : '`'.Product::$table.'`.`product_model_id` IN (SELECT c.product_model_id FROM `'.ProductModelCategory::$table.'` c'
            .' WHERE c.category_id IN ('.implode(',', $ids).") AND c.deleted = 'false')";
    }

    /** @return array<string, string> */
    private static function brandOptions(): array
    {
        $options = ['' => 'Tutti'];

        foreach (static::rowsOf(Brand::class, [], 'name') as $row) {
            $options[(string) $row['id']] = (string) ($row['name'] ?? '');
        }

        return $options;
    }

    /** @return array<string, string> le categorie indentate, come nella scheda */
    private static function categoryOptions(): array
    {
        $options = CategoryTree::options(static::rowsOf(Category::class, [], 'position'));
        unset($options['']);

        return ['' => 'Tutte'] + $options;
    }

    /**
     * @param list<string> $values
     * @return list<int>
     */
    private static function ids(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn (int $id): bool => $id > 0
        )));
    }

    /** "Articolo — Versione"; con una versione sola, l'articolo (D20). */
    private static function name(array $row): string
    {
        $names = ProductNames::of($row, [
            (int) ($row['product_model_id'] ?? 0) => trim((string) ($row['model_name'] ?? '')),
        ]);

        return static::escape($names['article'])
            .($names['option'] !== '' ? ' — '.static::escape($names['option']) : '');
    }

    /** La prima foto pronta: della versione, poi della variante, poi dell'articolo. */
    private static function photo(array $row): string
    {
        $modelId = (int) ($row['product_model_id'] ?? 0);

        self::$images[$modelId] ??= array_values(array_filter(
            static::rowsOf(ProductImage::class, ['product_model_id' => $modelId], 'position'),
            [ProductImages::class, 'isReady']
        ));

        $images = ProductImages::for(
            self::$images[$modelId],
            (int) ($row['product_variant_id'] ?? 0),
            (int) ($row['id'] ?? 0)
        );

        foreach ($images as $image) {
            if (($url = ProductImages::url($image)) !== '') {
                return $url;
            }
        }

        return '';
    }

    /** La scorta minima della versione sulla sede principale; zero senza soglia. */
    private static function threshold(array $row): float
    {
        $productId = (int) ($row['id'] ?? 0);

        return self::$thresholds[$productId] ??= (float) (Thresholds::forProduct($productId)[Locations::mainId()] ?? 0);
    }

    /** Il totale: in rosso e con il badge quando la versione è sotto scorta. */
    private static function total(array $row): string
    {
        if (empty($row['stock_alert'])) {
            return static::number($row['stock_quantity'] ?? 0);
        }

        return static::number(
            $row['stock_quantity'] ?? 0,
            false,
            'text-danger fw-semibold',
            '<span class="badge text-bg-danger me-1">sotto scorta</span>'
        );
    }

    /**
     * Un numero di pezzi in cella: a destra, cifre in colonna, interi senza
     * decimali. Il core mostrerebbe lo zero come cella vuota.
     */
    private static function number(mixed $value, bool $greyZero = false, string $class = '', string $before = ''): string
    {
        // `+ 0.0` toglie il meno allo zero: "-0" non si legge.
        $number = round((float) $value, 3) + 0.0;
        $classes = trim('d-block text-end '.$class.($greyZero && $number == 0.0 ? ' text-muted' : ''));

        return '<span class="'.$classes.'" style="font-variant-numeric: tabular-nums">'
            .$before.static::escape(static::plain($number)).'</span>';
    }

    /** I pezzi interi si scrivono interi: "3", non "3,000". */
    private static function plain(float $value): string
    {
        $decimals = round($value, 3) === round($value, 0) ? 0 : 3;

        return number_format($value, $decimals, ',', '.');
    }

    /** "Apri la versione": la sua scheda, con l'id della riga al posto giusto. */
    private static function productUrl(): string
    {
        $fallback = '/backend/'.ProductResource::path().'/{id}/edit/';

        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.ProductResource::slug().'.edit', ['id' => '__ROW_ID__']);
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? str_replace('__ROW_ID__', '{id}', $named) : $fallback;
    }
}
