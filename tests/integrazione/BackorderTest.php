<?php
/** php tests/integrazione/BackorderTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$conta = static function (string $model): int {
    $rows = $model::find(['deleted' => 'false']);

    if (!is_array($rows) || $rows === []) {
        return 0;
    }

    return isset($rows['id']) ? 1 : count(array_filter($rows, 'is_array'));
};

$prima = [$conta(ProductModel::class), $conta(ProductVariant::class), $conta(Product::class)];

/** Un attributo con i suoi valori. @return array{id: int, values: list<int>} */
$attributo = static function (string $name, string $level, string $type, array $labels): array {
    $creato = Attribute::create([
        'code' => Code::make(Attribute::class, Codes::ATTRIBUTE),
        'name' => $name,
        'slug' => Slug::make($name.'-'.uniqid()),
        'type' => $type,
        'level' => $level,
        'unit' => '',
        'group_name' => '',
        'is_filterable' => 'true',
        'is_visible' => 'true',
        'position' => 90,
    ]);
    $id = (int) ($creato->insert_id ?? 0);
    $values = [];
    $position = 1;

    foreach ($labels as $label) {
        $valore = AttributeValue::create([
            'attribute_id' => $id,
            'label' => $label,
            'color' => '',
            'position' => $position++,
        ]);
        $values[] = (int) ($valore->insert_id ?? 0);
    }

    return ['id' => $id, 'values' => $values];
};

/** Esegue la prova con la vendita senza giacenza accesa o spenta. */
$conBackorder = static function (bool $acceso, callable $prova): mixed {
    Gestionale::feature('backorders');
    $stato = new ReflectionProperty(Gestionale::class, 'features');
    $prima = $stato->getValue();
    $stato->setValue(null, array_merge((array) $prima, ['backorders' => $acceso]));

    try {
        return $prova();
    } finally {
        $stato->setValue(null, $prima);
    }
};

/** Interruttore e giorni di ogni opzione viva, nell'ordine della griglia. */
$opzioni = static function (int $modelId): array {
    return array_map(
        static fn (array $riga): array => [(string) $riga['allow_backorder'], (int) $riga['backorder_lead_days']],
        ProductModelResource::products($modelId)
    );
};

try {
    Transaction::run(static function () use ($attributo, $conBackorder, $opzioni): void {
        $colore = $attributo('Prova colore attesa', 'variant', 'color', ['Blu', 'Rosso', 'Verde']);

        $modello = ProductModel::create([
            'code' => Code::make(ProductModel::class, Codes::MODEL),
            'name' => 'Prova vendita senza giacenza',
            'slug' => Slug::make('prova-vendita-senza-giacenza-'.uniqid()),
            'sku' => 'BKO-1',
            'unit' => 'pz',
            'type' => 'simple',
            'visible' => 'true',
            'visible_online' => 'true',
            'position' => 1,
        ]);
        $modelId = (int) ($modello->insert_id ?? 0);
        Skeleton::forModel($modelId, 'Prova vendita senza giacenza', 'BKO-1');

        // Il catalogo si legge una volta per richiesta: qui gli attributi
        // nascono dopo l'avvio del sito.
        ProductModelResource::forgetCatalogCache();

        /** Il post di un articolo a colori, con i valori spuntati. */
        $spunte = static function (array $valori) use ($colore): array {
            return [
                'has_variants' => 'true',
                'axes_order' => (string) $colore['id'],
                'option_'.$colore['id'] => array_map('strval', $valori),
                'product_price' => '10,00',
            ];
        };

        // Due colori, senza l'interruttore: le opzioni nascono spente.
        $conBackorder(true, static function () use ($modelId, $spunte, $colore): void {
            ProductModelResource::saveExtras($modelId, $spunte([$colore['values'][0], $colore['values'][1]]), 'BKO-1');
        });

        check('senza l\'interruttore nel post le opzioni restano come sono', fn () =>
            $opzioni($modelId) === [['false', 0], ['false', 0]]
        );

        check('accesa, la vendita senza giacenza va su tutte le opzioni, anche su quella appena nata', function () use ($conBackorder, $modelId, $spunte, $colore, $opzioni) {
            return $conBackorder(true, static function () use ($modelId, $spunte, $colore, $opzioni): bool {
                ProductModelResource::saveExtras($modelId, $spunte($colore['values']) + [
                    'allow_backorder' => 'true',
                    'backorder_lead_days' => '5',
                ], 'BKO-1');

                return $opzioni($modelId) === [['true', 5], ['true', 5], ['true', 5]];
            });
        });

        check('riaprendo la scheda l\'interruttore è acceso, con i suoi giorni', function () use ($conBackorder, $modelId) {
            return $conBackorder(true, static function () use ($modelId): bool {
                $valori = ProductModelResource::mutateFormValues(['id' => $modelId], 'edit');

                return ($valori['allow_backorder'] ?? null) === 'true'
                    && (string) ($valori['backorder_lead_days'] ?? '') === '5';
            });
        });

        check('un\'opzione spenta a mano basta a riaprire la scheda spenta', function () use ($conBackorder, $modelId) {
            return $conBackorder(true, static function () use ($modelId): bool {
                $productId = (int) (ProductModelResource::products($modelId)[1]['id'] ?? 0);
                Product::update(['allow_backorder' => 'false'], $productId);
                $valori = ProductModelResource::mutateFormValues(['id' => $modelId], 'edit');

                // I giorni restano quelli delle opzioni ancora accese.
                return $productId > 0
                    && ($valori['allow_backorder'] ?? null) === 'false'
                    && (string) ($valori['backorder_lead_days'] ?? '') === '5';
            });
        });

        check('spenta, tutte le opzioni tornano a zero giorni', function () use ($conBackorder, $modelId, $spunte, $colore, $opzioni) {
            return $conBackorder(true, static function () use ($modelId, $spunte, $colore, $opzioni): bool {
                ProductModelResource::saveExtras($modelId, $spunte($colore['values']) + [
                    'allow_backorder' => 'false',
                    'backorder_lead_days' => '9',
                ], 'BKO-1');

                return $opzioni($modelId) === [['false', 0], ['false', 0], ['false', 0]];
            });
        });

        check('senza la funzionalità non si scrive niente', function () use ($conBackorder, $modelId, $spunte, $colore, $opzioni) {
            return $conBackorder(false, static function () use ($modelId, $spunte, $colore, $opzioni): bool {
                ProductModelResource::saveExtras($modelId, $spunte($colore['values']) + [
                    'allow_backorder' => 'true',
                    'backorder_lead_days' => '7',
                ], 'BKO-1');
                $valori = ProductModelResource::mutateFormValues(['id' => $modelId], 'edit');

                return $opzioni($modelId) === [['false', 0], ['false', 0], ['false', 0]]
                    // E riaprendo la scheda non se ne parla.
                    && !array_key_exists('allow_backorder', $valori)
                    && !array_key_exists('backorder_lead_days', $valori);
            });
        });

        check('un articolo senza varianti la scrive sulla sua unica opzione', function () use ($conBackorder, $opzioni) {
            return $conBackorder(true, static function () use ($opzioni): bool {
                $modello = ProductModel::create([
                    'code' => Code::make(ProductModel::class, Codes::MODEL),
                    'name' => 'Prova attesa singola',
                    'slug' => Slug::make('prova-attesa-singola-'.uniqid()),
                    'sku' => 'BKO-2',
                    'unit' => 'pz',
                    'type' => 'simple',
                    'visible' => 'true',
                    'visible_online' => 'true',
                    'position' => 1,
                ]);
                $nuovo = (int) ($modello->insert_id ?? 0);
                Skeleton::forModel($nuovo, 'Prova attesa singola', 'BKO-2');

                ProductModelResource::saveExtras($nuovo, [
                    'has_variants' => 'false',
                    'product_price' => '10,00',
                    'allow_backorder' => 'true',
                    'backorder_lead_days' => '12',
                ], 'BKO-2');

                return $opzioni($nuovo) === [['true', 12]];
            });
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento il catalogo è come prima', fn () =>
    [$conta(ProductModel::class), $conta(ProductVariant::class), $conta(Product::class)] === $prima
);

summary();
