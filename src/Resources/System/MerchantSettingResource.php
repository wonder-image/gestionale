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
use Wonder\View\WebFonts;

/**
 * "Impostazioni" del commerciante, nella sezione Gestionale: una riga sola,
 * di `administrator`; la apre anche `admin`, che vede tutto.
 *
 * Qui stanno le scelte di chi usa il gestionale tutti i giorni, e restano
 * nell'ambiente dove si lavora: un deploy non le riporta indietro. Ogni
 * sotto-progetto aggiunge le sue; le email degli ordini stanno invece con
 * quelle degli errori, nelle Impostazioni di Set Up.
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
            'low_stock_emails' => 'Destinatari degli avvisi',
            'font_auth' => 'Font accesso',
            'font_account' => 'Font account',
            'font_checkout' => 'Font checkout',
            'font_cart' => 'Font carrello',
            'checkout_guest' => 'Ordini senza account',
        ];
    }

    public static function formSchema(): array
    {
        $fields = [];

        if (Gestionale::feature('low_stock_alerts')) {
            $fields[] = FormField::key('low_stock_emails')->text()->label('Destinatari degli avvisi');
        }

        if (self::onlineShop()) {
            foreach (['auth' => 'Font accesso', 'account' => 'Font account', 'checkout' => 'Font checkout', 'cart' => 'Font carrello'] as $area => $label) {
                $fields[] = FormField::key('font_'.$area)->select(['' => 'Come il sito'] + WebFonts::all())->label($label);
            }
            $fields[] = FormField::key('checkout_guest')->toggle()->value('false')->label('Ordini senza account');
        }

        return $fields;
    }

    public static function formLayoutSchema(): ?Form
    {
        $cards = [];

        if (Gestionale::feature('low_stock_alerts')) {
            $cards[] = (new Card)->components([
                SectionTitle::make('Avvisi di scorta minima')
                    ->tooltip('Chi riceve l\'email dei prodotti sotto la scorta minima: più indirizzi separati da virgola. Vuoto, l\'email non parte e gli avvisi aspettano.')
                    ->columnSpan(12),
                static::getInput('low_stock_emails')->columnSpan(12),
            ])->columns(12)->columnSpan(12);
        }

        if (self::onlineShop()) {
            $cards[] = (new Card)->components([
                SectionTitle::make('Negozio online')
                    ->tooltip('Il font delle pagine di accesso, account, checkout e carrello. «Come il sito» usa quello del tema.')
                    ->columnSpan(12),
                static::getInput('font_auth')->columnSpan(6),
                static::getInput('font_account')->columnSpan(6),
                static::getInput('font_checkout')->columnSpan(6),
                static::getInput('font_cart')->columnSpan(6),
                SectionTitle::make('Ordini senza account')
                    ->tooltip('Chi non ha un account ordina con la sola email: l\'account nasce senza password e l\'email dell\'ordine porta il link per sceglierla. Spento, il checkout chiede di accedere o registrarsi.')
                    ->columnSpan(12),
                static::getInput('checkout_guest')->columnSpan(12),
            ])->columns(12)->columnSpan(12);
        }

        return (new Form)->components([
            (new Container)->components($cards)->columns(12)->columnSpan(12),
        ]);
    }

    /**
     * Font e ordini senza account valgono per le pagine del negozio: si vedono
     * con la vendita online o col modulo ecommerce acceso, che ha il suo
     * checkout anche quando la funzionalità è spenta.
     */
    private static function onlineShop(): bool
    {
        return Gestionale::feature('online_sales') || Gestionale::module('ecommerce');
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
        foreach (['auth', 'account', 'checkout', 'cart'] as $area) {
            if (array_key_exists('font_'.$area, $values)) {
                $font = strtolower(trim((string) $values['font_'.$area]));
                $values['font_'.$area] = WebFonts::has($font) ? $font : '';
            }
        }

        if (array_key_exists('checkout_guest', $values)) {
            $values['checkout_guest'] = (string) $values['checkout_guest'] === 'true' ? 'true' : 'false';
        }

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
            ->backend(['list', 'edit', 'update'], ['admin', 'administrator']);
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
            ->authority(['admin', 'administrator']);
    }
}
