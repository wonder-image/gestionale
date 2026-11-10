<?php
/** php tests/DocumentBrandsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\CustomerAccount\Support\CompanyEvents;
use Wonder\Plugin\CustomerAccount\Support\ContactEvents;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Contacts\DocumentBrands;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentData;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderForm;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrders;
use Wonder\Sql\Query;

/*
 * Il brand dell'azienda sui preventivi e sugli ordini da ufficio
 * (`company_brand_id`, nome copiato in `company_brand_name`). I brand di
 * customer-account sono un doppio in memoria: l'azienda 10 ha RClass (3) e
 * Hotel & Spa (4, predefinito); il 5 era suo ed è stato staccato; l'azienda 11
 * non ha brand.
 */
final class BrandFinti
{
    /** @var array<int, array<int, bool>> azienda => [brand => predefinito] */
    public static array $legami = [10 => [3 => false, 4 => true]];

    /** @var array<int, array<string, mixed>> */
    public static array $brand = [
        3 => ['id' => 3, 'name' => 'RClass'],
        4 => ['id' => 4, 'name' => 'Hotel &amp; Spa'],
        5 => ['id' => 5, 'name' => 'Vecchio', 'archived' => 'true'],
    ];

    public static function defaultOf(int $companyId): int
    {
        $legami = self::$legami[$companyId] ?? [];

        return (int) (array_search(true, $legami, true) ?: (array_key_first($legami) ?? 0));
    }

    public static function belongsTo(int $brandId, int $companyId, ?string $at = null): bool
    {
        return isset(self::$legami[$companyId][$brandId]);
    }

    public static function find(int $brandId): ?array
    {
        return self::$brand[$brandId] ?? null;
    }
}

DocumentBrands::useBrands(BrandFinti::class);

// «Brand» accesa; i controlli a funzionalità spenta la spengono e la riaccendono.
$forza = static function (bool $brand): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, ['private_customers' => true, 'companies' => true, 'brands' => $brand]);
};
$forza(true);

$rifiuto = static function (callable $fn): string {
    try {
        $fn();
    } catch (UserError $error) {
        return $error->key();
    }

    return '';
};
$salvato = ['id' => 9, 'company_id' => 10, 'company_brand_id' => 5, 'company_brand_name' => 'Vecchio'];

check('nuovo documento senza brand: il predefinito dell\'azienda, con il nome', fn () =>
    DocumentBrands::resolve(10, null) === ['company_brand_id' => 4, 'company_brand_name' => 'Hotel & Spa']
);

check('nuovo documento: un brand dell\'azienda si tiene, «nessuno» anche', fn () =>
    DocumentBrands::resolve(10, 3) === ['company_brand_id' => 3, 'company_brand_name' => 'RClass']
    && DocumentBrands::resolve(10, 0) === ['company_brand_id' => 0, 'company_brand_name' => '']
);

check('un\'azienda senza brand: nessun brand', fn () =>
    DocumentBrands::resolve(11, null) === ['company_brand_id' => 0, 'company_brand_name' => '']
);

check('un brand che non è dell\'azienda è un errore, non un azzeramento', fn () =>
    $rifiuto(fn () => DocumentBrands::resolve(10, 99)) === 'office_order.brand_unknown'
    && $rifiuto(fn () => DocumentBrands::resolve(11, 3)) === 'office_order.brand_unknown'
);

check('in modifica, senza il campo, il brand salvato resta anche se staccato', fn () =>
    DocumentBrands::resolve(10, null, $salvato) === []
);

check('in modifica, lo stesso brand salvato si tiene senza rivalidarlo', fn () =>
    DocumentBrands::resolve(10, 5, $salvato) === []
);

check('in modifica, un documento di prima della copia prende il nome', fn () =>
    DocumentBrands::resolve(10, null, ['company_brand_name' => ''] + $salvato) === ['company_brand_id' => 5, 'company_brand_name' => 'Vecchio']
);

check('in modifica, un brand diverso dev\'essere dell\'azienda oggi', fn () =>
    DocumentBrands::resolve(10, 3, $salvato) === ['company_brand_id' => 3, 'company_brand_name' => 'RClass']
    && DocumentBrands::resolve(10, 0, $salvato) === ['company_brand_id' => 0, 'company_brand_name' => '']
    && $rifiuto(fn () => DocumentBrands::resolve(10, 99, $salvato)) === 'office_order.brand_unknown'
);

check('cambiata l\'azienda, senza il campo vale il predefinito della nuova; il brand vecchio è un errore', fn () =>
    DocumentBrands::resolve(11, null, $salvato) === ['company_brand_id' => 0, 'company_brand_name' => '']
    && DocumentBrands::resolve(10, null, ['company_id' => 11, 'company_brand_id' => 0] + $salvato) === ['company_brand_id' => 4, 'company_brand_name' => 'Hotel & Spa']
    && $rifiuto(fn () => DocumentBrands::resolve(11, 5, $salvato)) === 'office_order.brand_unknown'
);

check('il nome stampato: quello copiato, altrimenti quello di oggi; niente senza azienda', fn () =>
    DocumentBrands::nameOf(['company_id' => 10, 'company_brand_id' => 3, 'company_brand_name' => 'RClass 2019']) === 'RClass 2019'
    && DocumentBrands::nameOf(['company_id' => 10, 'company_brand_id' => 4, 'company_brand_name' => '']) === 'Hotel & Spa'
    && DocumentBrands::nameOf(['company_id' => 0, 'company_brand_id' => 4, 'company_brand_name' => 'X']) === ''
    && DocumentBrands::nameOf(['company_id' => 10, 'company_brand_id' => 0]) === ''
);

check('cliente privato o nessun cliente: brand a zero e nome vuoto, il nome postato non passa', function () {
    $testata = new ReflectionMethod(OfficeOrders::class, 'headerValues');
    $valori = $testata->invoke(null, ['company_id' => '0', 'customer_id' => '0', 'company_brand_id' => '4', 'company_brand_name' => 'Finto'], [
        'fulfillment_type' => 'none', 'manual_discount_type' => 'none', 'manual_discount_value' => 0.0,
    ], null);

    return $valori['company_brand_id'] === 0 && $valori['company_brand_name'] === ''
        && $valori['referent_name'] === '';
});

check('senza «Brand» il brand postato si ignora: il salvato resta, il nuovo nasce senza', function () use ($forza, $salvato) {
    $forza(false);

    try {
        return DocumentBrands::resolve(10, null, $salvato) === []
            && DocumentBrands::resolve(10, 3, $salvato) === []
            && DocumentBrands::resolve(10, 99, $salvato) === []
            && DocumentBrands::resolve(10, null, ['company_brand_name' => ''] + $salvato) === []
            && DocumentBrands::resolve(10, null) === ['company_brand_id' => 0, 'company_brand_name' => '']
            && DocumentBrands::resolve(10, 3) === ['company_brand_id' => 0, 'company_brand_name' => '']
            && DocumentBrands::resolve(11, null, $salvato) === ['company_brand_id' => 0, 'company_brand_name' => ''];
    } finally {
        $forza(true);
    }
});

check('il brand da stampare: quello del documento con «Brand», niente senza', function () use ($forza) {
    $ordine = ['company_id' => 10, 'company_brand_id' => 3, 'company_brand_name' => 'RClass 2019'];
    $acceso = DocumentData::brand($ordine);
    $forza(false);
    $spento = [DocumentData::brand($ordine), OfficeOrders::defaultBrand(10)];
    $forza(true);

    return $acceso === 'RClass 2019' && $spento === ['', 0] && DocumentData::brand([]) === '';
});

check('il form posta il brand, mai il suo nome', fn () =>
    OfficeOrderForm::header(['company_id' => '10', 'company_brand_id' => '4', 'company_brand_name' => 'Finto', 'brand_id' => '3'])
        === ['company_id' => '10', 'company_brand_id' => '4']
);

// Un Query che non parla con il database: si guarda cosa scriverebbe.
$scritture = new class extends Query {
    /** @var list<array{0: string, 1: array<string, mixed>, 2: string, 3: string|int}> */
    public array $fatte = [];

    public function __construct()
    {
    }

    public function Update(string $table, array $values, string $column, string|int $value): object
    {
        $this->fatte[] = [$table, $values, $column, $value];

        return (object) ['success' => true];
    }
};

check('l\'unione dei brand sposta i documenti con una UPDATE sola, senza toccare il nome', function () use ($scritture) {
    DocumentBrands::merged(CompanyEvents::BRAND_MERGED, ['from_id' => '7', 'into_id' => 3, 'brand' => ['id' => 3]], $scritture);

    return $scritture->fatte === [['gst_orders', ['company_brand_id' => 3], 'company_brand_id', 7]];
});

check('un\'unione senza id, o su se stesso, non scrive', function () use ($scritture) {
    $scritture->fatte = [];
    DocumentBrands::merged(CompanyEvents::BRAND_MERGED, ['from_id' => 0, 'into_id' => 3], $scritture);
    DocumentBrands::merged(CompanyEvents::BRAND_MERGED, ['from_id' => 3, 'into_id' => 3], $scritture);
    DocumentBrands::merged(CompanyEvents::BRAND_MERGED, ['from_id' => 7, 'into_id' => -2], $scritture);

    return $scritture->fatte === [];
});

check('il boot del modulo ascolta company.brand_merged', function () {
    ContactEvents::reset();
    DocumentBrands::register();
    $ascoltatori = (new ReflectionProperty(ContactEvents::class, 'listeners'))->getValue();
    ContactEvents::reset();

    return in_array([DocumentBrands::class, 'merged'], $ascoltatori[CompanyEvents::BRAND_MERGED] ?? [], true)
        && str_contains((string) file_get_contents(__DIR__.'/../config/boot.php'), 'DocumentBrands::register()');
});

DocumentBrands::useBrands(null);

summary();
