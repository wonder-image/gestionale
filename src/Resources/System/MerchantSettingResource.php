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
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Recipients;

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

    /** La pagina della guida commercianti: `gruppo/file` del SUMMARY. */
    public const DOCS_PAGE = 'ogni-giorno/impostazioni';

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
            'low_stock_emails' => 'Destinatari degli avvisi',
        ];
    }

    public static function formSchema(): array
    {
        $fields = [
            FormField::key('merchant_notification_emails')->text()->label('Email di chi riceve le notifiche'),
        ];

        if (Gestionale::feature('low_stock_alerts')) {
            $fields[] = FormField::key('low_stock_emails')->text()->label('Destinatari degli avvisi');
        }

        return $fields;
    }

    public static function formLayoutSchema(): ?Form
    {
        $cards = [
            (new Card)->components([
                SectionTitle::make('Notifiche')
                    ->tooltip('Più indirizzi separati da virgola. Arrivano le notifiche che riguardano il negozio; i guasti tecnici vanno a chi ti segue.')
                    ->columnSpan(12),
                static::getInput('merchant_notification_emails')->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ];

        if (Gestionale::feature('low_stock_alerts')) {
            $cards[] = (new Card)->components([
                SectionTitle::make('Avvisi di scorta minima')
                    ->tooltip('Chi riceve l\'email dei prodotti sotto la scorta minima: più indirizzi separati da virgola. Vuoto, l\'email non parte e gli avvisi aspettano.')
                    ->columnSpan(12),
                static::getInput('low_stock_emails')->columnSpan(12),
            ])->columns(12)->columnSpan(12);
        }

        return (new Form)->components([
            (new Container)->components($cards)->columns(12)->columnSpan(12),
        ]);
    }

    /**
     * I destinatari si salvano puliti: un indirizzo storto si rifiuta adesso,
     * nominandolo, invece di scoprirlo il giorno che l'email non arriva.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        if (!array_key_exists('low_stock_emails', $values)) {
            return $values;
        }

        if (!Gestionale::feature('low_stock_alerts')) {
            unset($values['low_stock_emails']);

            return $values;
        }

        $parsed = Recipients::parse((string) ($values['low_stock_emails'] ?? ''));

        if ($parsed['invalid'] !== []) {
            throw UserError::make('settings.email_invalid', ['email' => $parsed['invalid'][0]]);
        }

        $values['low_stock_emails'] = Recipients::join($parsed['valid']);

        return $values;
    }

    public static function pageSchema(): PageSchema
    {
        $schema = parent::pageSchema()->titles(['edit' => 'Impostazioni']);
        $url = Gestionale::docsUrl(self::DOCS_PAGE);

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
