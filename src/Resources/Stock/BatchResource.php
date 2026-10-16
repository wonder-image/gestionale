<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

use Throwable;
use Wonder\App\Permission\Access;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Http\Route;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\Batch;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Contacts\SupplierNames;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\BatchExpiry;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;

/**
 * *Magazzino → Lotti* (G3.20): i lotti con i loro pezzi e la scadenza, per
 * sapere cosa scade e, in un richiamo, a chi è andato un lotto.
 *
 * Un lotto nasce al carico e non si crea qui; della scheda si cambiano solo
 * scadenza e nota. Un lotto con movimenti non si elimina.
 */
final class BatchResource extends GestionaleResource
{
    public static string $feature = 'batch_tracking';
    public static string $model = Batch::class;
    public static string $orderColumn = 'expiry_date';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'magazzino/magazzino-lotti';

    public static function path(): string
    {
        return 'app/gestionale/lotti';
    }

    public static function icon(): string
    {
        return 'bi-upc';
    }

    public static function titleLabel(): string
    {
        return 'Lotti';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'lotto',
            'plural_label' => 'lotti',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'il',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'product_id' => 'Versione',
            'number' => 'Lotto',
            'expiry_date' => 'Scadenza',
            'supplier_id' => 'Fornitore',
            'pieces' => 'Pezzi',
            'note' => 'Nota',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('expiry_date')->dateInput()->label('Scadenza'),
            FormField::key('note')->textarea()->label('Nota'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Lotto')
                        ->tooltip('Numero e versione non si cambiano: un lotto è quello scritto sul carico. La scadenza si corregge qui; vuota vuol dire che il lotto non scade.')
                        ->columnSpan(12),
                    static::getInput('expiry_date')->columnSpan(4),
                    static::getInput('note')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    /**
     * Solo scadenza e nota: il resto del lotto non si tocca da qui.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function mutateRequestValues(array $values, string $action, string $context = 'backend', ?array $oldValues = null): array
    {
        $out = [];

        if (array_key_exists('expiry_date', $values)) {
            $out['expiry_date'] = static::expiry($values['expiry_date']);
        }

        if (array_key_exists('note', $values)) {
            $out['note'] = (string) $values['note'];
        }

        return $out;
    }

    /** `Y-m-d` valida o null; altro è un rifiuto. */
    public static function expiry(mixed $value): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw UserError::make('batch.invalid_expiry');
        }

        return $text;
    }

    public static function assertDeletable(int|string $id): void
    {
        $movements = (int) sqlCount(StockMovement::$table, '`batch_id` = '.(int) $id." AND `deleted` = 'false'");

        if ($movements > 0) {
            throw UserError::refusal('batch.in_use');
        }
    }

    public static function tableSchema(): array
    {
        $today = date('Y-m-d');
        $days = BatchExpiry::days();
        $columns = [
            TableColumn::key('number')->text()->link('view'),
            TableColumn::key('product_id')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(StockMovementResource::productLabel((int) ($row['product_id'] ?? 0)))),
            TableColumn::key('expiry_date')
                ->text()
                ->size('little')
                ->sortable()
                ->formatter(static fn (array $row): string => BatchExpiry::badge((string) ($row['expiry_date'] ?? ''), $today, $days)),
        ];

        if (Gestionale::feature('purchasing')) {
            $columns[] = TableColumn::key('supplier_id')
                ->text()
                ->size('little')
                ->hiddenDevice('mobile')
                ->formatter(static fn (array $row): string => static::escape(SupplierNames::of((int) ($row['supplier_id'] ?? 0))));
        }

        $columns[] = TableColumn::key('pieces')
            ->text()
            ->size('little')
            ->sortable()
            ->formatter(static fn (array $row): string => static::escape(static::pieces((float) ($row['pieces'] ?? 0))));
        $columns[] = TableColumn::key('actions')->button()->actions(['view', 'edit', 'delete']);

        return $columns;
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        $schema = TableLayoutSchema::for(static::class)
            ->title('Lotti')
            ->results()
            ->hideButtonAdd()
            ->select(static::piecesSql().' AS pieces')
            ->filterSearch()
            ->searchFields(['number', [
                'table' => Product::$table,
                'local_key' => 'product_id',
                'foreign_key' => 'id',
                'columns' => ['sku', 'ean', 'name'],
                'relations' => [[
                    'table' => ProductModel::$table,
                    'local_key' => 'product_model_id',
                    'foreign_key' => 'id',
                    'columns' => ['name'],
                ]],
            ]])
            ->filterQuery(
                'Stato',
                'stato',
                static::statusOptions(),
                static fn (array $values): string => static::statusCondition($values, date('Y-m-d'), BatchExpiry::days()),
                'select',
                false,
                'con'
            );

        if (count(Locations::shown()) >= 2) {
            $options = ['' => 'Tutte'];

            foreach (Locations::shown() as $location) {
                $options[(string) $location['id']] = $location['label'];
            }

            $schema->filterQuery('Sede', 'sede', $options, static fn (array $values): string => static::locationCondition($values));
        }

        return $schema;
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return ['con' => 'Con pezzi', 'scadenza' => 'In scadenza', 'scaduti' => 'Scaduti', 'esauriti' => 'Esauriti', '' => 'Tutti'];
    }

    /**
     * I pezzi del lotto in tutte le sedi, in SQL sulla riga di `gst_batches`:
     * per la colonna (select) e per i filtri, mai per `querySchema()`, dove il
     * core toglierebbe il WHERE della sottoquery.
     */
    public static function piecesSql(): string
    {
        return '(SELECT COALESCE(SUM(s.`quantity`), 0) FROM `'.StockRow::$table.'` s'
            .' WHERE s.`batch_id` = `'.Batch::$table."`.`id` AND s.`deleted` = 'false')";
    }

    /**
     * La condizione del filtro *Stato*. «In scadenza» e «Scaduti» guardano
     * solo i lotti con pezzi: un lotto finito non preoccupa più.
     *
     * @param list<string> $values
     */
    public static function statusCondition(array $values, string $today, int $days): string
    {
        $pieces = static::piecesSql();
        $expiry = '`'.Batch::$table.'`.`expiry_date`';
        $limit = BatchExpiry::plus($today, $days);
        $parts = [];

        foreach (array_unique(array_map('strval', $values)) as $value) {
            $parts[] = match ($value) {
                'con' => $pieces.' > 0',
                'scadenza' => '('.$pieces.' > 0 AND '.$expiry." >= '".$today."' AND ".$expiry." <= '".$limit."')",
                'scaduti' => '('.$pieces.' > 0 AND '.$expiry." < '".$today."')",
                'esauriti' => $pieces.' <= 0',
                default => '',
            };
        }

        $parts = array_values(array_filter($parts));

        return match (count($parts)) {
            0 => '',
            1 => $parts[0],
            default => '('.implode(' OR ', $parts).')',
        };
    }

    /**
     * Il filtro *Sede*: i lotti con pezzi in quelle sedi. Solo id interi.
     *
     * @param list<string> $values
     */
    public static function locationCondition(array $values): string
    {
        $ids = [];

        foreach ($values as $value) {
            if (preg_match('/^\d+$/D', (string) $value) === 1 && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        if ($ids === []) {
            return '';
        }

        return '`'.Batch::$table.'`.`id` IN (SELECT s.`batch_id` FROM `'.StockRow::$table.'` s'
            ." WHERE s.`deleted` = 'false' AND s.`quantity` <> 0 AND s.`location_id` IN (".implode(', ', array_unique($ids)).'))';
    }

    public static function pageSchema(): PageSchema
    {
        // Il lotto si apre in lettura; la modifica (scadenza e nota) dietro al bottone.
        return static::withFeature(parent::pageSchema()
            ->only(['list', 'view', 'edit', 'update', 'delete'])
            ->titles(['list' => 'Lotti', 'view' => 'Lotto', 'edit' => 'Modifica lotto'])
            ->subtitles(['list' => 'I lotti con i loro pezzi e la scadenza. Un lotto nasce con il carico; da qui si corregge la scadenza e si vede a chi è andato.'])
            ->view('show', Gestionale::viewPath('pages/batch-show.php'))
            ->actions('view', static fn (array $item): array => [[
                'label' => 'Modifica',
                'icon' => 'bi-pencil',
                'class' => 'btn-warning btn-sm',
                'href' => static::editUrlFor((int) ($item['id'] ?? 0)),
            ]]));
    }

    /** «Scarica i clienti»: il CSV del richiamo. */
    public static function registerBackendRoutes(string $rootApp, string $slug): void
    {
        Route::get('/{id}/clienti/', Gestionale::handlerPath('backend/stock/batch-customers.php'), [
            'resource' => $slug,
            'resource_action' => 'customers',
        ])->name('customers')
            ->permit(Access::roles(Gestionale::area('magazzino'), 'view'))
            ->where('id', '[0-9]+');
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('magazzino', 'Magazzino', 'bi-boxes', 400)
            ->title('Lotti')
            ->order(45)
            ->access(Gestionale::area('magazzino'))
            ->enabled(static::featureActive());
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('magazzino'), ['list', 'view', 'edit', 'update', 'delete']);
    }

    public static function viewUrl(int $id): string
    {
        return static::routeUrl('view', '/backend/'.static::path().'/'.$id.'/', ['id' => $id]);
    }

    public static function editUrlFor(int $id): string
    {
        return static::routeUrl('edit', '/backend/'.static::path().'/'.$id.'/edit/', ['id' => $id]);
    }

    public static function customersUrl(int $id): string
    {
        return static::routeUrl('customers', '/backend/'.static::path().'/'.$id.'/clienti/', ['id' => $id]);
    }

    public static function listUrl(): string
    {
        return static::routeUrl('list', '/backend/'.static::path());
    }

    /** I pezzi interi si scrivono interi: «3», non «3,000». */
    public static function pieces(float $value): string
    {
        if (round($value, 3) === round($value, 0)) {
            return number_format($value, 0, ',', '.');
        }

        return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');
    }

    private static function routeUrl(string $action, string $fallback, array $params = []): string
    {
        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.static::slug().'.'.$action, $params);
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
    }
}
