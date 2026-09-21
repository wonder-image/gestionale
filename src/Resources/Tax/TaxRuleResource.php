<?php

namespace Wonder\Plugin\Gestionale\Resources\Tax;

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
use Wonder\Plugin\Gestionale\Models\Tax\Tax;
use Wonder\Plugin\Gestionale\Models\Tax\TaxCategory;
use Wonder\Plugin\Gestionale\Models\Tax\TaxRule;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;

/**
 * "Regole IVA": paese del cliente × tipo di cliente × tipo fiscale del
 * prodotto → aliquota.
 *
 * La corrispondenza è esatta: quando la combinazione non c'è, il documento usa
 * l'aliquota di ripiego delle impostazioni fiscali.
 */
final class TaxRuleResource extends GestionaleResource
{
    public static string $model = TaxRule::class;
    public static string $orderColumn = 'country';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'impostazioni/iva';

    public static function path(): string
    {
        return 'app/gestionale/regole-iva';
    }

    public static function icon(): string
    {
        return 'bi-diagram-3';
    }

    public static function titleLabel(): string
    {
        return 'Regole IVA';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'regola',
            'plural_label' => 'regole',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'this' => 'questa',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'code' => 'Codice',
            'country' => 'Paese',
            'customer_type' => 'Tipo di cliente',
            'tax_category_id' => 'Tipo fiscale',
            'tax_id' => 'Aliquota',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('code')->text()->label('Codice')->required(),
            FormField::key('country')->country()->value('IT')->label('Paese')->required(),
            FormField::key('customer_type')
                ->select(['private' => 'Privato', 'business' => 'Azienda'])
                ->value('private')
                ->label('Tipo di cliente')
                ->required(),
            FormField::key('tax_category_id')
                ->select(static::taxCategories())
                ->label('Tipo fiscale')
                ->required(),
            FormField::key('tax_id')
                ->select(static::taxes())
                ->label('Aliquota')
                ->required(),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Regola')
                        ->tooltip('Una sola regola per paese, tipo di cliente e tipo fiscale.')
                        ->columnSpan(12),
                    static::getInput('code')->columnSpan(4),
                    static::getInput('country')->columnSpan(4),
                    static::getInput('customer_type')->columnSpan(4),
                    static::getInput('tax_category_id')->columnSpan(6),
                    static::getInput('tax_id')->columnSpan(6),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('code')->text()->link('edit'),
            TableColumn::key('country')->text()->size('little'),
            TableColumn::key('customer_type')->text()->size('little'),
            TableColumn::key('tax_category_id')->text(),
            TableColumn::key('tax_id')->text(),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Regole IVA',
                'create' => 'Nuova regola',
                'edit' => 'Modifica regola',
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
            ->title('Regole IVA')
            ->order(62)
            ->authority(['admin']);
    }

    /** @return array<string, string> */
    private static function taxCategories(): array
    {
        return static::options(TaxCategory::class, static fn (array $row): string => (string) ($row['name'] ?? ''));
    }

    /** @return array<string, string> */
    private static function taxes(): array
    {
        return static::options(Tax::class, static fn (array $row): string => (string) ($row['name'] ?? ''));
    }

    /** @return array<string, string> */
    private static function options(string $model, callable $label): array
    {
        $rows = $model::find(['deleted' => 'false'], null, 'id', 'ASC');

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $rows = isset($rows['id']) ? [$rows] : $rows;
        $options = [];

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'])) {
                $options[(string) $row['id']] = $label($row);
            }
        }

        return $options;
    }
}
