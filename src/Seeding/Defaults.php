<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\App\Models\Config\SocietyLocation;
use Wonder\App\Module\Contracts\ModuleDefaults;
use Wonder\App\Support\DefaultRows;
use Wonder\Plugin\Custom\Fattura\Valori\AliquoteIva;
use Wonder\Plugin\Custom\Fattura\Valori\Natura;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Models\Tax\Tax;
use Wonder\Plugin\Gestionale\Models\Tax\TaxCategory;
use Wonder\Plugin\Gestionale\Models\Tax\TaxRule;
use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;

/**
 * Righe precaricate del gestionale, create da `forge update` in locale.
 *
 * Regola unica: si inserisce solo ciò che manca, mai si modifica ciò che c'è.
 * I nomi sono in italiano, perché finiscono davanti al commerciante. In
 * produzione queste righe non nascono qui: arrivano dal file di sync con il
 * deploy.
 */
final class Defaults implements ModuleDefaults
{
    /** Codice dell'aliquota ordinaria: ripiego, spedizione e regole puntano a lei. */
    private const ORDINARY_RATE = '22';

    /** Codice dell'unico tipo fiscale precaricato. */
    private const ORDINARY_CATEGORY = 'ordinaria';

    public static function seed(DefaultRows $rows): void
    {
        self::features($rows);
        self::taxes($rows);
        self::taxCategories($rows);
        self::taxRules($rows);
        self::settings($rows);
        self::location($rows);
    }

    /** Una riga bloccata per ogni funzionalità del catalogo. */
    private static function features(DefaultRows $rows): void
    {
        $unlock = array_map('strval', (array) Gestionale::config('features.unlock', []));
        $features = [];

        foreach (array_keys(FeatureCatalog::all()) as $key) {
            $features[] = [
                'feature_key' => $key,
                // Il sito può far nascere sbloccate alcune funzionalità (starter),
                // mai cambiarle dopo: le righe esistenti non si toccano.
                'enabled' => in_array($key, $unlock, true) ? 'true' : 'false',
            ];
        }

        $rows->ensure(Feature::class, 'feature_key', $features);
    }

    /** Aliquote italiane visibili, operazioni a zero nascoste ma pronte. */
    private static function taxes(DefaultRows $rows): void
    {
        $taxes = [];

        foreach (AliquoteIva::Valori as $rate => $description) {
            $value = (float) $rate;
            $taxes[] = [
                'code' => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'),
                'name' => number_format($value, 0).'% - '.$description,
                'description' => $description,
                'invoice_description' => $description,
                'rate' => $value,
                'nature' => '',
                'visible' => 'true',
            ];
        }

        foreach (Natura::valide() as $code => $description) {
            $taxes[] = [
                'code' => strtolower(str_replace('.', '-', (string) $code)),
                'name' => $code.' - '.$description,
                'description' => $description,
                'invoice_description' => $description,
                'rate' => 0,
                'nature' => (string) $code,
                // Si accendono quando servono: un negozio normale non le usa.
                'visible' => 'false',
            ];
        }

        $rows->ensure(Tax::class, 'code', $taxes);
    }

    /**
     * Un tipo fiscale solo, ed è il predefinito: la scheda prodotto parte da
     * lì, e chi vende libri o alimentari ne aggiunge altri.
     */
    private static function taxCategories(DefaultRows $rows): void
    {
        $rows->ensure(TaxCategory::class, 'code', [[
            'code' => self::ORDINARY_CATEGORY,
            'name' => 'Aliquota ordinaria',
            'description' => 'Beni e servizi con aliquota ordinaria.',
            'position' => 1,
            'visible' => 'true',
            'is_default' => 'true',
        ]]);
    }

    /** Italia, privato e azienda, tipo ordinario: il caso di tutti i giorni. */
    private static function taxRules(DefaultRows $rows): void
    {
        $taxId = self::ordinaryTaxId();
        $categoryId = self::ordinaryCategoryId();

        if ($taxId === 0 || $categoryId === 0) {
            return;
        }

        $candidates = [
            [
                // Stesso formato che compone la pagina: {paese}-{cliente}-{tipo}.
                'code' => 'it-private-'.self::ORDINARY_CATEGORY,
                'country' => 'IT',
                'customer_type' => 'private',
                'tax_category_id' => $categoryId,
                'tax_id' => $taxId,
            ],
            [
                'code' => 'it-business-'.self::ORDINARY_CATEGORY,
                'country' => 'IT',
                'customer_type' => 'business',
                'tax_category_id' => $categoryId,
                'tax_id' => $taxId,
            ],
        ];

        $rows->ensure(TaxRule::class, 'code', self::withoutExistingRules($candidates));
    }

    /**
     * Le regole che il sito non ha già, guardando paese, tipo di cliente e
     * tipo fiscale.
     *
     * Il codice della regola è composto da quelle tre cose, ma il formato è
     * cambiato: un sito aggiornato ha le stesse regole con il codice vecchio, e
     * `ensure()` — che guarda solo il codice — proverebbe a reinserirle
     * sbattendo contro l'indice unico. Qui si controlla la chiave vera.
     *
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private static function withoutExistingRules(array $candidates): array
    {
        $existing = TaxRule::find(['deleted' => 'false']);
        $existing = is_array($existing) && $existing !== []
            ? (isset($existing['id']) ? [$existing] : array_values(array_filter($existing, 'is_array')))
            : [];

        $known = [];

        foreach ($existing as $row) {
            $known[self::ruleKey($row)] = true;
        }

        return array_values(array_filter(
            $candidates,
            static fn (array $row): bool => !isset($known[self::ruleKey($row)])
        ));
    }

    /** Paese, tipo di cliente e tipo fiscale: ciò che rende unica una regola. */
    private static function ruleKey(array $row): string
    {
        return strtolower((string) ($row['country'] ?? ''))
            .'|'.strtolower((string) ($row['customer_type'] ?? ''))
            .'|'.(int) ($row['tax_category_id'] ?? 0);
    }

    /**
     * Le due righe uniche. La conferma fiscale resta vuota apposta: i Primi
     * passi devono far guardare questi valori a una persona.
     */
    private static function settings(DefaultRows $rows): void
    {
        $taxId = self::ordinaryTaxId();

        if ($taxId > 0) {
            $rows->ensureSingleton(Setting::class, [
                'invoice_provider' => '',
                'tax_regime' => 'RF01',
                'vat_collectability' => 'I',
                'transmitter_country' => 'IT',
                'transmitter_fiscal_code' => '',
                'catalog_prices_include_tax' => 'true',
                'fallback_tax_id' => $taxId,
                'shipping_tax_id' => $taxId,
                'invoice_numeration' => 'WEB',
                'stamp_duty_auto' => 'true',
                'fiscal_confirmed_at' => '',
                'developer_error_emails' => '',
            ]);
        }

        $rows->ensureSingleton(MerchantSetting::class, [
            'merchant_notification_emails' => self::societyEmail(),
        ]);
    }

    /** La sede predefinita del core diventa il primo magazzino. */
    private static function location(DefaultRows $rows): void
    {
        $location = SocietyLocation::find(['is_default' => 'true', 'deleted' => 'false'], 1);
        $id = (int) ($location['id'] ?? 0);

        if ($id === 0) {
            return;
        }

        $rows->ensure(Location::class, 'society_location_id', [[
            'code' => Location::newCode(),
            'society_location_id' => $id,
            'has_stock' => 'true',
            'is_pickup_point' => 'false',
            'is_pos' => 'false',
            'active' => 'true',
        ]]);
    }

    private static function ordinaryTaxId(): int
    {
        $tax = Tax::find(['code' => self::ORDINARY_RATE, 'deleted' => 'false'], 1);

        return (int) ($tax['id'] ?? 0);
    }

    private static function ordinaryCategoryId(): int
    {
        $category = TaxCategory::find(['code' => self::ORDINARY_CATEGORY, 'deleted' => 'false'], 1);

        return (int) ($category['id'] ?? 0);
    }

    /** L'email della società, se il sito l'ha compilata. */
    private static function societyEmail(): string
    {
        $location = SocietyLocation::find(['is_default' => 'true', 'deleted' => 'false'], 1);

        return trim((string) ($location['email'] ?? ''));
    }
}
