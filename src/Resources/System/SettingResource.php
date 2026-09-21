<?php

namespace Wonder\Plugin\Gestionale\Resources\System;

use Throwable;

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
use Wonder\Plugin\Custom\Fattura\Valori\EsigibilitaIva;
use Wonder\Plugin\Custom\Fattura\Valori\RegimiFiscali;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Models\Tax\Tax;

/**
 * "Impostazioni" tecniche in Set Up: una riga sola, di `admin`.
 *
 * Tre riquadri: Fiscale (regime, esigibilità, prezzi, aliquote di ripiego),
 * Documenti (sezionale e bollo) ed Errori (dove arrivano le email allo
 * sviluppatore). Il salvataggio segna `fiscal_confirmed_at`: i Primi passi
 * hanno bisogno di sapere che una persona ha guardato i valori precaricati.
 */
final class SettingResource extends SingletonResource
{
    public static string $model = Setting::class;

    public static function path(): string
    {
        return 'app/gestionale/impostazioni';
    }

    public static function icon(): string
    {
        return 'bi-sliders';
    }

    public static function titleLabel(): string
    {
        return 'Impostazioni';
    }

    public static function labelSchema(): array
    {
        return [
            'tax_regime' => 'Regime fiscale',
            'vat_collectability' => 'Esigibilità IVA',
            'transmitter_country' => 'Paese del trasmittente',
            'transmitter_fiscal_code' => 'Codice fiscale del trasmittente',
            'catalog_prices_include_tax' => 'Prezzi del catalogo',
            'fallback_tax_id' => 'Aliquota di ripiego',
            'shipping_tax_id' => 'Aliquota della spedizione',
            'invoice_provider' => 'Fatturazione elettronica',
            'invoice_numeration' => 'Sezionale delle fatture',
            'stamp_duty_auto' => 'Bollo automatico',
            'fiscal_confirmed_at' => 'Confermate il',
            'developer_error_emails' => 'Email dello sviluppatore',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('tax_regime')->select(RegimiFiscali::Valori)->value('RF01')->label('Regime fiscale')->required(),
            FormField::key('vat_collectability')->select(EsigibilitaIva::Valori)->value('I')->label('Esigibilità IVA')->required(),
            FormField::key('transmitter_country')->country()->value('IT')->label('Paese del trasmittente'),
            FormField::key('transmitter_fiscal_code')->text()->label('Codice fiscale del trasmittente'),
            FormField::key('catalog_prices_include_tax')
                ->select(['true' => 'IVA inclusa', 'false' => 'IVA esclusa'])
                ->value('true')
                ->label('Prezzi del catalogo')
                ->required(),
            FormField::key('fallback_tax_id')->select(static::taxes())->label('Aliquota di ripiego')->required(),
            FormField::key('shipping_tax_id')->select(static::taxes())->label('Aliquota della spedizione')->required(),
            FormField::key('invoice_provider')
                ->select(['' => 'Nessuna', 'fatture-in-cloud' => 'Fatture in Cloud'])
                ->label('Fatturazione elettronica'),
            FormField::key('invoice_numeration')->text()->value('WEB')->label('Sezionale delle fatture'),
            FormField::key('stamp_duty_auto')
                ->select(['true' => 'Sì', 'false' => 'No'])
                ->value('true')
                ->label('Bollo automatico'),
            FormField::key('developer_error_emails')->text()->label('Email dello sviluppatore'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Fiscale')
                        ->tooltip('Valori da confermare con il commercialista: il gestionale li usa per ogni documento.')
                        ->columnSpan(12),
                    static::getInput('tax_regime')->columnSpan(6),
                    static::getInput('vat_collectability')->columnSpan(6),
                    static::getInput('catalog_prices_include_tax')->columnSpan(4),
                    static::getInput('fallback_tax_id')->columnSpan(4),
                    static::getInput('shipping_tax_id')->columnSpan(4),
                    static::getInput('transmitter_country')->columnSpan(6),
                    static::getInput('transmitter_fiscal_code')->columnSpan(6),
                ])->columns(12)->columnSpan(12),

                (new Card)->components([
                    SectionTitle::make('Documenti')->columnSpan(12),
                    static::getInput('invoice_provider')->columnSpan(4),
                    static::getInput('invoice_numeration')->columnSpan(4),
                    static::getInput('stamp_duty_auto')->columnSpan(4),
                ])->columns(12)->columnSpan(12),

                (new Card)->components([
                    SectionTitle::make('Errori')
                        ->tooltip('Più indirizzi separati da virgola. Al commerciante scrivono le sue impostazioni.')
                        ->columnSpan(12),
                    static::getInput('developer_error_emails')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function pageSchema(): PageSchema
    {
        $schema = parent::pageSchema()->titles(['edit' => 'Impostazioni']);
        $url = Gestionale::docsUrl('impostazioni/tecniche');

        return $url === '' ? $schema : $schema->docs($url);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)
            ->backend(['list', 'edit', 'update'], ['admin']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('set-up')
            ->title('Impostazioni')
            ->order(63)
            ->authority(['admin']);
    }

    /** La prima conferma di una persona vale per i Primi passi e non si riscrive. */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        $confermata = trim((string) ($oldValues['fiscal_confirmed_at'] ?? ''));

        $values['fiscal_confirmed_at'] = $confermata !== '' && $confermata !== '0000-00-00 00:00:00'
            ? $confermata
            : date('Y-m-d H:i:s');

        return $values;
    }

    /** @return array<string, string> */
    private static function taxes(): array
    {
        try {
            $rows = Tax::find(['deleted' => 'false'], null, 'rate', 'DESC');
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $rows = isset($rows['id']) ? [$rows] : $rows;
        $options = [];

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'])) {
                $options[(string) $row['id']] = (string) ($row['name'] ?? $row['code'] ?? $row['id']);
            }
        }

        return $options;
    }
}
