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
use Wonder\Plugin\Custom\Fattura\Valori\Natura;
use Wonder\Plugin\Gestionale\Models\Tax\Tax;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;

/**
 * "Aliquote IVA": le aliquote usate dai documenti.
 *
 * Non si eliminano — un documento già emesso punta alla sua — e si nascondono
 * quando non servono più. La pagina è di `admin` e si modifica solo in locale:
 * in produzione arriva con il deploy.
 */
final class TaxResource extends GestionaleResource
{
    public static string $model = Tax::class;
    public static string $orderColumn = 'rate';
    public static string $orderDirection = 'DESC';
    // Aliquote, tipi fiscali e regole le cura chi segue il negozio: la guida
    // è quella dello sviluppatore, non quella del commerciante.
    public static string $docsPage = 'concetti/iva-e-impostazioni';
    public static string $docsSpace = 'dev';

    public static function path(): string
    {
        return 'app/gestionale/aliquote-iva';
    }

    public static function icon(): string
    {
        return 'bi-percent';
    }

    public static function titleLabel(): string
    {
        return 'Aliquote IVA';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'aliquota',
            'plural_label' => 'aliquote',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'full' => 'visibile',
            'empty' => 'nascosta',
            'this' => 'questa',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'code' => 'Codice',
            'name' => 'Nome',
            'description' => 'Descrizione',
            'invoice_description' => 'Descrizione in fattura',
            'rate' => 'Aliquota',
            'nature' => 'Natura',
            'visible' => 'Stato',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('code')->text()->label('Codice')->required(),
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('rate')->number()->decimals(2)->label('Aliquota')->required(),
            FormField::key('nature')
                ->select(['' => 'Nessuna (aliquota maggiore di zero)'] + Natura::valide())
                ->label('Natura'),
            FormField::key('visible')
                ->select(['true' => 'Visibile', 'false' => 'Nascosta'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('description')->textarea()->label('Descrizione'),
            FormField::key('invoice_description')->text()->label('Descrizione in fattura'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Aliquota')
                        ->tooltip('La natura si compila solo quando l\'aliquota è zero: dice perché l\'operazione non ha IVA.')
                        ->columnSpan(12),
                    static::getInput('code')->columnSpan(3),
                    static::getInput('name')->columnSpan(6),
                    static::getInput('visible')->columnSpan(3),
                    static::getInput('rate')->columnSpan(3),
                    static::getInput('nature')->columnSpan(9),
                ])->columns(12)->columnSpan(12),

                (new Card)->components([
                    SectionTitle::make('Descrizioni')->columnSpan(12),
                    static::getInput('description')->columnSpan(6),
                    static::getInput('invoice_description')->columnSpan(6),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('code')->text()->link('edit'),
            TableColumn::key('name')->text(),
            TableColumn::key('rate')->text(),
            TableColumn::key('nature')->text()->size('little'),
            TableColumn::key('visible')->visibleBadge()->size('little'),
            TableColumn::key('actions')->button()->actions(['edit']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view', 'delete'])
            ->titles([
                'list' => 'Aliquote IVA',
                'create' => 'Nuova aliquota',
                'edit' => 'Modifica aliquota',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)
            ->backend(['list', 'create', 'store', 'edit', 'update'], ['admin']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('set-up')
            ->title('Aliquote IVA')
            ->order(60)
            ->authority(['admin']);
    }

    /** Un'aliquota cancellata lascerebbe senza IVA i documenti che la usano. */
    public static function assertDeletable(int|string $id): void
    {
        throw new RuntimeException(
            'Le aliquote non si eliminano: nascondi quella che non serve più, i documenti già emessi la usano.'
        );
    }
}
