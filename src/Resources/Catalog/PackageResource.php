<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Models\Catalog\Package;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Packages;
use Wonder\Plugin\Gestionale\Support\Positions;

/**
 * "Imballaggi": le scatole in cui il negozio spedisce.
 *
 * Due o tre buone bastano a descrivere tutto quello che parte. Quella segnata
 * come predefinita vale per gli articoli che non ne scelgono una, così una
 * scheda prodotto può non parlarne affatto.
 */
final class PackageResource extends GestionaleResource
{
    public static string $model = Package::class;
    public static string $orderColumn = 'position';
    public static string $orderDirection = 'ASC';

    public static function path(): string
    {
        return 'app/gestionale/imballaggi';
    }

    public static function icon(): string
    {
        return 'bi-box-seam';
    }

    public static function titleLabel(): string
    {
        return 'Imballaggi';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'imballaggio',
            'plural_label' => 'imballaggi',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'full' => 'in uso',
            'empty' => 'fermo',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'name' => 'Nome',
            'weight' => 'Tara',
            'size' => 'Misure',
            'length' => 'Lunghezza',
            'width' => 'Larghezza',
            'height' => 'Altezza',
            'is_default' => 'Predefinito',
            'active' => 'Stato',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('weight')->number()->decimal(3)->label('Peso a vuoto (kg)')->required(),
            FormField::key('length')->number()->decimal(2)->label('Lunghezza (cm)')->required(),
            FormField::key('width')->number()->decimal(2)->label('Larghezza (cm)')->required(),
            FormField::key('height')->number()->decimal(2)->label('Altezza (cm)')->required(),
            FormField::key('is_default')
                ->select(['false' => 'No', 'true' => 'Sì'])
                ->value('false')
                ->label('Predefinito'),
            FormField::key('active')
                ->select(['true' => 'In uso', 'false' => 'Fermo'])
                ->value('true')
                ->label('Stato'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Imballaggio')
                        ->tooltip('Il peso a vuoto è la tara: si somma al peso del prodotto per sapere quanto parte davvero. Le misure sono quelle interne, lo spazio che hai per metterci le cose.')
                        ->columnSpan(12),
                    static::getInput('name')->columnSpan(8),
                    static::getInput('weight')->columnSpan(4),
                    static::getInput('length')->columnSpan(4),
                    static::getInput('width')->columnSpan(4),
                    static::getInput('height')->columnSpan(4),
                    static::getInput('is_default')->columnSpan(6),
                    static::getInput('active')->columnSpan(6),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('weight')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(
                    Packages::kg((float) ($row['weight'] ?? 0)).' kg'
                )),
            TableColumn::key('size')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(static::sizeOf($row))),
            TableColumn::key('is_default')
                ->booleanBadge()
                ->badgeOn('Predefinito', 'bi-star-fill', 'success')
                ->badgeOff('No', 'bi-dash', 'secondary')
                ->size('little'),
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('In uso', 'bi-check-lg', 'success')
                ->badgeOff('Fermo', 'bi-pause', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }

    /** "40 × 30 × 20 cm", o niente se le misure non ci sono. */
    protected static function sizeOf(array $row): string
    {
        $parts = [];

        foreach (['length', 'width', 'height'] as $key) {
            $value = (float) ($row[$key] ?? 0);

            if ($value <= 0) {
                return '';
            }

            $parts[] = rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
        }

        return implode(' × ', $parts).' cm';
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Imballaggi',
                'create' => 'Nuovo imballaggio',
                'edit' => 'Modifica imballaggio',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin']);
    }

    /** Solo `store`, per il "+" del riquadro Spedizione della scheda prodotto. */
    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)
            ->only(['store'])
            ->fields('store', ['name', 'weight']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('set-up')
            ->inGroup('gestionale')
            ->title('Imballaggi')
            ->order(30)
            ->authority(['admin']);
    }

    /** La posizione la mette il pannello; il codice lo genera il core. */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        unset($values['position']);

        if ($action === 'store') {
            $values['position'] = Positions::next(Package::$table);
        }

        return $values;
    }

    /**
     * Un predefinito solo.
     *
     * Sceglierne uno nuovo toglie il segno al vecchio: due scatole
     * "predefinite" vorrebbero dire che nessuno sa quale vince.
     */
    public static function afterStore(object $result, array $values = []): void
    {
        static::keepSingleDefault((int) ($result->insert_id ?? 0), $values);
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        static::keepSingleDefault((int) $id, $values);
    }

    protected static function keepSingleDefault(int $id, array $values): void
    {
        if ($id <= 0 || ($values['is_default'] ?? 'false') !== 'true') {
            return;
        }

        foreach (static::rowsOf(Package::class) as $row) {
            if ((int) $row['id'] !== $id && ($row['is_default'] ?? 'false') === 'true') {
                Package::update(['is_default' => 'false'], (int) $row['id']);
            }
        }
    }
}
