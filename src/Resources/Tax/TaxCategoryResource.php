<?php

namespace Wonder\Plugin\Gestionale\Resources\Tax;

use RuntimeException;
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
use Wonder\Plugin\Gestionale\Models\Tax\TaxCategory;
use Wonder\Plugin\Gestionale\Models\Tax\TaxRule;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;

/**
 * "Tipi fiscali": come si tassa un prodotto (ordinario, alimentare, libri,
 * servizi). Ogni modello del catalogo ne avrà uno; insieme al paese e al tipo
 * di cliente è quello che sceglie l'aliquota.
 */
final class TaxCategoryResource extends GestionaleResource
{
    public static string $model = TaxCategory::class;
    public static string $orderColumn = 'position';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'impostazioni/iva';

    public static function path(): string
    {
        return 'app/gestionale/tipi-fiscali';
    }

    public static function icon(): string
    {
        return 'bi-tags';
    }

    public static function titleLabel(): string
    {
        return 'Tipi fiscali';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'tipo fiscale',
            'plural_label' => 'tipi fiscali',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'full' => 'visibile',
            'empty' => 'nascosto',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'code' => 'Codice',
            'name' => 'Nome',
            'description' => 'Descrizione',
            'position' => 'Posizione',
            'visible' => 'Stato',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('code')->text()->label('Codice')->required(),
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('position')->number()->decimals(0)->label('Posizione')->value('1'),
            FormField::key('visible')
                ->select(['true' => 'Visibile', 'false' => 'Nascosto'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('description')->textarea()->label('Descrizione'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Tipo fiscale')
                        ->tooltip('Con un tipo fiscale solo, la scheda prodotto non lo mostra nemmeno.')
                        ->columnSpan(12),
                    static::getInput('code')->columnSpan(3),
                    static::getInput('name')->columnSpan(5),
                    static::getInput('position')->columnSpan(2),
                    static::getInput('visible')->columnSpan(2),
                    static::getInput('description')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('code')->text()->link('edit'),
            TableColumn::key('name')->text(),
            TableColumn::key('position')->text()->size('little'),
            TableColumn::key('visible')->visibleBadge()->size('little'),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Tipi fiscali',
                'create' => 'Nuovo tipo fiscale',
                'edit' => 'Modifica tipo fiscale',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)
            ->backendCrud(['admin']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('set-up')
            ->title('Tipi fiscali')
            ->order(61)
            ->authority(['admin']);
    }

    /** Senza il tipo fiscale le sue regole non saprebbero più che aliquota usare. */
    public static function assertDeletable(int|string $id): void
    {
        $regole = TaxRule::find(['tax_category_id' => (int) $id, 'deleted' => 'false'], 1);

        if (is_array($regole) && $regole !== []) {
            throw new RuntimeException(
                'Questo tipo fiscale è usato da una regola IVA: togli prima la regola, oppure nascondilo.'
            );
        }
    }
}
