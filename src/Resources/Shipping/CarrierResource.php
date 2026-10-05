<?php

namespace Wonder\Plugin\Gestionale\Resources\Shipping;

use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Positions;

/**
 * «Corrieri»: chi porta il pacco. Serve a dare un nome al vettore sui metodi
 * di spedizione e a costruire il link con cui il cliente segue il pacco.
 */
final class CarrierResource extends GestionaleResource
{
    public static string $feature = 'shipping';
    public static string $model = Carrier::class;
    public static string $orderColumn = 'position';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'spedizioni/spedizioni-listini';

    public static function path(): string
    {
        return 'app/gestionale/corrieri';
    }

    public static function icon(): string
    {
        return 'bi-truck';
    }

    public static function titleLabel(): string
    {
        return 'Corrieri';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'corriere',
            'plural_label' => 'corrieri',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'full' => 'attivo',
            'empty' => 'non attivo',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'name' => 'Nome',
            'tracking_url_template' => 'Link di tracking',
            'active' => 'Stato',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('tracking_url_template')->text()->label('Link di tracking'),
            FormField::key('active')
                ->select(['true' => 'Attivo', 'false' => 'Non attivo'])
                ->value('true')
                ->label('Stato'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Corriere')
                        ->tooltip('Nel link di tracking scrivi {tracking}: al suo posto va il numero di spedizione. Per esempio https://www.brt.it/it/tracking?code={tracking}. Senza link il cliente riceve solo il numero. Un corriere non attivo non si propone più, ma resta sui metodi che lo usano.')
                        ->columnSpan(12),
                    static::getInput('name')->columnSpan(5),
                    static::getInput('active')->columnSpan(3),
                    static::getInput('tracking_url_template')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('tracking_url_template')->text(),
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Attivo', 'bi-check-circle', 'success')
                ->badgeOff('Non attivo', 'bi-dash-circle', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Corrieri',
                'create' => 'Nuovo corriere',
                'edit' => 'Modifica corriere',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'administrator']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()
            ->inSection('spedizioni')
            ->title('Corrieri')
            ->order(30)
            ->authority(['admin', 'administrator']);
    }

    /** Il corriere è `manual` (i corrieri collegati sono futuri) e la posizione la mette il backend, in fondo all'elenco. */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        $values['tracking_url_template'] = trim((string) ($values['tracking_url_template'] ?? ''));

        if ($action === 'store') {
            $values['provider'] = 'manual';
            $values['position'] = Positions::next(Carrier::$table);
        } else {
            unset($values['position']);
        }

        return $values;
    }

    /** Un corriere su qualche metodo si spegne, non si elimina. */
    public static function assertDeletable(int|string $id): void
    {
        $count = count(static::rowsOf(ShippingMethod::class, ['carrier_id' => (int) $id]));

        if ($count > 0) {
            // `refusal()` e non `make()`: chi cancella dall'elenco intercetta `RuntimeException`.
            throw UserError::refusal('shipping.carrier_in_use', ['count' => $count]);
        }
    }
}
