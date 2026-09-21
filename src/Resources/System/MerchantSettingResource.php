<?php

namespace Wonder\Plugin\Gestionale\Resources\System;

use Wonder\App\Resources\Support\SingletonResource;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;

/**
 * "Impostazioni" del commerciante, nella sezione Gestionale: una riga sola,
 * di `administrator`.
 *
 * Qui stanno le scelte di chi usa il gestionale tutti i giorni, e restano
 * nell'ambiente dove si lavora: un deploy non le riporta indietro. In G1 c'è
 * solo dove arrivano le notifiche del negozio; ogni sotto-progetto aggiunge le
 * sue.
 */
final class MerchantSettingResource extends SingletonResource
{
    public static string $model = MerchantSetting::class;

    public static function path(): string
    {
        return 'app/gestionale/impostazioni-negozio';
    }

    public static function icon(): string
    {
        return 'bi-shop';
    }

    public static function titleLabel(): string
    {
        return 'Impostazioni';
    }

    public static function labelSchema(): array
    {
        return [
            'merchant_notification_emails' => 'Email di chi riceve le notifiche',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('merchant_notification_emails')->text()->label('Email di chi riceve le notifiche'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Notifiche')
                        ->tooltip('Più indirizzi separati da virgola. Arrivano le notifiche che riguardano il negozio; i guasti tecnici vanno a chi ti segue.')
                        ->columnSpan(12),
                    static::getInput('merchant_notification_emails')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function pageSchema(): PageSchema
    {
        $schema = parent::pageSchema()->titles(['edit' => 'Impostazioni']);
        $url = Gestionale::docsUrl('impostazioni/negozio');

        return $url === '' ? $schema : $schema->docs($url);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)
            ->backend(['list', 'edit', 'update'], ['administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('gestionale', 'Gestionale', 'bi-shop-window', 200, ['administrator', 'admin'])
            ->inSection('gestionale')
            ->title('Impostazioni')
            ->order(900)
            ->authority(['administrator']);
    }
}
