<?php

namespace Wonder\Plugin\Gestionale\Resources\Tax;

use Throwable;
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
    // Aliquote, tipi fiscali e regole le cura chi segue il negozio: la guida
    // è quella dello sviluppatore, non quella del commerciante.
    public static string $docsPage = 'concetti/iva-e-impostazioni';
    public static string $docsSpace = 'dev';

    /** @var array<string, string>|null nomi dei tipi fiscali, letti una volta */
    private static ?array $categoryNames = null;

    /** @var array<string, string>|null nomi delle aliquote, letti una volta */
    private static ?array $taxNames = null;

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
            FormField::key('country')->country()->value('IT')->label('Paese')->required(),
            FormField::key('customer_type')
                ->select(static::customerTypes())
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
                        ->tooltip('Una sola regola per paese, tipo di cliente e tipo fiscale. Il codice lo compone il gestionale con quello che scegli qui.')
                        ->columnSpan(12),
                    static::getInput('country')->columnSpan(6),
                    static::getInput('customer_type')->columnSpan(6),
                    static::getInput('tax_category_id')->columnSpan(6),
                    static::getInput('tax_id')->columnSpan(6),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        // Nell'elenco vanno i nomi: un id non dice a nessuno di che regola
        // si tratta. Le due mappe si leggono una volta sola per pagina.
        return [
            TableColumn::key('code')->text()->link('edit'),
            TableColumn::key('country')->text()->size('little'),
            TableColumn::key('customer_type')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(
                    static::customerTypes()[(string) ($row['customer_type'] ?? '')] ?? (string) ($row['customer_type'] ?? '')
                )),
            TableColumn::key('tax_category_id')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(
                    static::taxCategories()[(string) ($row['tax_category_id'] ?? '')] ?? '—'
                )),
            TableColumn::key('tax_id')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(
                    static::taxes()[(string) ($row['tax_id'] ?? '')] ?? '—'
                )),
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
            ->inGroup('iva')
            ->title('Regole')
            ->order(30)
            ->authority(['admin']);
    }

    /**
     * Il codice racconta la regola: `it-private-ordinaria`. Lo compone il
     * gestionale da quello che è stato scelto, così due regole non possono
     * chiamarsi allo stesso modo e nessuno deve inventarsi una sigla.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        if ($action === 'store') {
            $values['code'] = static::codeFor(
                (string) ($values['country'] ?? ''),
                (string) ($values['customer_type'] ?? ''),
                (int) ($values['tax_category_id'] ?? 0)
            );
        } else {
            // Il codice è immutabile: cambiarlo romperebbe i riferimenti.
            unset($values['code']);
        }

        return $values;
    }

    /** `{paese}-{tipo cliente}-{tipo fiscale}`, tutto minuscolo. */
    public static function codeFor(string $country, string $customerType, int $taxCategoryId): string
    {
        try {
            $category = TaxCategory::find(['id' => $taxCategoryId, 'deleted' => 'false'], 1);
        } catch (Throwable) {
            // Senza database resta l'id: meglio un codice che un errore.
            $category = null;
        }

        $categoryCode = is_array($category) ? (string) ($category['code'] ?? '') : '';

        $parts = [
            strtolower(trim($country)),
            strtolower(trim($customerType)),
            $categoryCode !== '' ? $categoryCode : (string) $taxCategoryId,
        ];

        return implode('-', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /** Nomi dei tipi di cliente, gli stessi del form. @return array<string, string> */
    public static function customerTypes(): array
    {
        return ['private' => 'Privato', 'business' => 'Azienda'];
    }

    /** @return array<string, string> */
    public static function taxCategories(): array
    {
        return static::$categoryNames ??= static::options(
            TaxCategory::class,
            static fn (array $row): string => (string) ($row['name'] ?? '')
        );
    }

    /** @return array<string, string> */
    public static function taxes(): array
    {
        return static::$taxNames ??= static::options(
            Tax::class,
            static fn (array $row): string => (string) ($row['name'] ?? '')
        );
    }


    /** @return array<string, string> */
    private static function options(string $model, callable $label): array
    {
        try {
            $rows = $model::find(['deleted' => 'false'], null, 'id', 'ASC');
        } catch (Throwable) {
            // Senza database (test degli schemi) il select resta vuoto invece
            // di far saltare il form.
            return [];
        }

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
