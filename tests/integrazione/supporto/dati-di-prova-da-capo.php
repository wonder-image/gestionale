<?php // tests/integrazione/supporto/dati-di-prova-da-capo.php
declare(strict_types=1);

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationOption;
use Wonder\Plugin\Gestionale\Models\Catalog\Package;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Seeding\Demo;
use Wonder\Plugin\Gestionale\Seeding\DemoCode;

/**
 * Porta il sito a «nessun dato di prova», dentro la transazione del test.
 *
 * Prima la pulizia vera, dall'ultimo registrato. Quello che resta è in uso da
 * righe vere (ordini e movimenti fatti a mano sugli articoli di prova): qui
 * diventa una riga vera, senza segno e con un altro nome, così i dati di
 * prova nascono da capo. L'annullamento rimette tutto com'era.
 */
function datiDiProvaDaCapo(): void
{
    DemoData::reset();
    Demo::registerAll();

    foreach (DemoData::inClearOrder() as $dati) {
        ($dati['clear'])();
    }

    $modelli = [
        Brand::class, Category::class, Tag::class, Attribute::class, AttributeValue::class,
        ProductModel::class, ProductVariant::class, Product::class, Package::class,
        Customization::class, CustomizationOption::class, Company::class,
    ];

    foreach ($modelli as $model) {
        $colonna = $model === Company::class ? 'business_name' : 'name';
        $righe = $model::find(['deleted' => 'false']);
        $righe = is_array($righe) && isset($righe['id']) ? [$righe] : array_filter((array) $righe, 'is_array');

        foreach ($righe as $riga) {
            $codice = (string) ($riga['code'] ?? '');

            if (!DemoCode::is($codice)) {
                continue;
            }

            $valori = ['code' => str_replace('_'.DemoCode::MARK, '_vera', $codice)];

            if (isset($riga[$colonna])) {
                $valori[$colonna] = $riga[$colonna].' vera';
            }

            $model::query()->Update($model::$table, $valori, 'id', (int) $riga['id']);
        }
    }

    DemoData::notes();
}
