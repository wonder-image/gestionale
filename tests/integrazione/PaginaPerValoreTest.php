<?php
/** php tests/integrazione/PaginaPerValoreTest.php */
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
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Exports\ProductFeeds;
use Wonder\Plugin\Gestionale\Support\Catalog\PageRedirect;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** Conta le richieste di feed e sitemap invece di farle. */
final class ProvaPagine extends ProductModelResource
{
    public static int $chiesti = 0;

    protected static function refreshCatalog(): void
    {
        self::$chiesti++;
    }
}

/** @return list<array<string, mixed>> */
$righe = static function (string $model, array $where): array {
    $rows = $model::find($where, null, 'id', 'ASC');

    if (!is_array($rows) || $rows === []) {
        return [];
    }

    return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
};

$conta = static fn (string $model): int => count($righe($model, ['deleted' => 'false']));
$tabelle = static fn (): array => array_map($conta, [ProductModel::class, ProductVariant::class, Product::class, ProductImage::class]);
$prima = $tabelle();

/** @return array{id: int, slug: string, values: list<int>} */
$attributo = static function (string $name, string $level, string $type, array $labels): array {
    $slug = Slug::make($name.'-'.uniqid());
    $creato = Attribute::create([
        'code' => Code::make(Attribute::class, Codes::ATTRIBUTE),
        'name' => $name,
        'slug' => $slug,
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
        $valore = AttributeValue::create(['attribute_id' => $id, 'label' => $label, 'color' => '', 'position' => $position++]);
        $values[] = (int) ($valore->insert_id ?? 0);
    }

    // Lo slug come l'ha salvato il modello.
    return ['id' => $id, 'slug' => (string) (Attribute::find(['id' => $id], 1)['slug'] ?? $slug), 'values' => $values];
};

Gestionale::feature('multi_location');
$unaSede = new ReflectionProperty(Gestionale::class, 'features');
$unaSede->setValue(null, array_merge((array) $unaSede->getValue(), ['multi_location' => false]));
Locations::reset();

// Il 301, senza database.
$pagine = [
    ['slug' => 'blu', 'attribute_slug' => 'colore', 'value_id' => 7, 'value_label' => 'Blu notte'],
    ['slug' => 'rosso', 'attribute_slug' => 'colore', 'value_id' => 8, 'value_label' => 'Rosso'],
];

check('301: ?colore=blu-notte va sulla pagina blu e la query perde la chiave', fn () =>
    PageRedirect::pageFor($pagine, ['colore' => 'blu-notte', 'taglia' => 's']) === ['slug' => 'blu', 'query' => ['taglia' => 's']]
);

check('301: vale anche l\'id del valore', fn () =>
    PageRedirect::pageFor($pagine, ['colore' => '8'])['slug'] === 'rosso'
);

$cartella = sys_get_temp_dir().'/ppv-'.uniqid();
mkdir($cartella);
touch($cartella.'/google-merchant.xml');
ProductFeeds::expire($cartella);

check('feed: expire() rende scaduto un file appena scritto', fn () =>
    ProductFeeds::due(ProductFeeds::writtenAt('google', $cartella), 1, time())
    && ProductFeeds::writtenAt('meta', $cartella) === null
);
unlink($cartella.'/google-merchant.xml');
rmdir($cartella);

check('301: niente pagina per un valore che non c\'è o una chiave diversa', fn () =>
    PageRedirect::pageFor($pagine, ['colore' => 'verde']) === null
    && PageRedirect::pageFor($pagine, ['taglia' => 'blu-notte']) === null
);

try {
    Transaction::run(static function () use ($righe, $attributo): void {
        $colore = $attributo('Prova pagina colore', 'variant', 'color', ['Blu', 'Rosso']);
        $taglia = $attributo('Prova pagina taglia', 'product', 'select', ['S', 'M']);
        ProductModelResource::forgetCatalogCache();

        $articolo = static function (string $sku): int {
            $creato = ProductModel::create([
                'code' => Code::make(ProductModel::class, Codes::MODEL),
                'name' => 'Prova pagina '.$sku,
                'slug' => Slug::make('prova-pagina-'.uniqid()),
                'sku' => $sku,
                'unit' => 'pz',
                'type' => 'simple',
                'visible' => 'true',
                'visible_online' => 'true',
                'position' => 1,
            ]);
            $id = (int) ($creato->insert_id ?? 0);
            Skeleton::forModel($id, 'Prova pagina '.$sku, $sku);

            return $id;
        };

        $spunte = [
            'has_variants' => 'true',
            'option_'.$colore['id'] => array_map('strval', $colore['values']),
            'option_'.$taglia['id'] => array_map('strval', $taglia['values']),
            'product_price' => '10,00',
        ];
        $salva = static fn (int $modelId, string $pagina) =>
            ProvaPagine::saveExtras($modelId, $spunte + ['page_attribute' => $pagina], 'PPV');

        $varianti = static fn (int $modelId): array => $righe(ProductVariant::class, ['product_model_id' => $modelId, 'deleted' => 'false']);
        $prodotti = static fn (int $modelId): array => $righe(Product::class, ['product_model_id' => $modelId, 'deleted' => 'false']);
        $valoreProdotto = static fn (int $productId, int $attributeId): int =>
            (int) (ProductAttributes::read('product', $productId)[$attributeId]['attribute_value_id'] ?? 0);
        $valoreVariante = static fn (int $variantId, int $attributeId): int =>
            (int) (ProductAttributes::read('variant', $variantId)[$attributeId]['attribute_value_id'] ?? 0);

        // Articolo senza pagine: una variante sola, senza slug.
        $senza = $articolo('PPV-0');
        $salva($senza, '');

        check('articolo senza pagine: una variante sola, senza slug, e quattro opzioni', fn () =>
            count($varianti($senza)) === 1
            && (string) $varianti($senza)[0]['slug'] === ''
            && count($prodotti($senza)) === 4
            && ProductModelResource::variantAttributeId($senza) === 0
        );

        check('articolo senza pagine: il colore sta sulla tabella delle opzioni', function () use ($senza, $prodotti, $valoreProdotto, $colore) {
            foreach ($prodotti($senza) as $product) {
                if (!in_array($valoreProdotto((int) $product['id'], $colore['id']), $colore['values'], true)) {
                    return false;
                }
            }

            return true;
        });

        // Con le pagine del colore, poi spento, poi riacceso, poi sulla taglia.
        $modelId = $articolo('PPV-1');
        $salva($modelId, (string) $colore['id']);
        $prima = $varianti($modelId);
        $blu = (int) $prima[0]['id'];
        $slugBlu = (string) $prima[0]['slug'];
        $unProdotto = (int) $prodotti($modelId)[0]['id'];

        check('acceso: due pagine, il colore sulla tabella delle varianti', fn () =>
            count($prima) === 2
            && $valoreVariante($blu, $colore['id']) === $colore['values'][0]
            && $valoreProdotto($unProdotto, $colore['id']) === 0
            && ProductModelResource::variantAttributeId($modelId) === $colore['id']
        );

        $fotoColore = (int) (ProductImage::create([
            'product_model_id' => $modelId, 'product_variant_id' => $blu, 'file' => json_encode(['blu.jpg']),
            'alt' => '', 'position' => 1, 'status' => 'pending', 'attempts' => 0,
        ])->insert_id ?? 0);
        $fotoOpzione = (int) (ProductImage::create([
            'product_model_id' => $modelId, 'product_variant_id' => $blu, 'product_id' => $unProdotto,
            'file' => json_encode(['blu-s.jpg']), 'alt' => '', 'position' => 2, 'status' => 'pending', 'attempts' => 0,
        ])->insert_id ?? 0);
        $variantDi = static fn (int $id): ?int => ($row = ProductImage::find(['id' => $id], 1)) && $row['product_variant_id'] !== null
            ? (int) $row['product_variant_id'] : null;

        $chiestiPrima = ProvaPagine::$chiesti;
        $salva($modelId, '');
        $spenta = $varianti($modelId);

        check('online e pagine cambiate: feed e sitemap chiesti una volta', fn () =>
            ProvaPagine::$chiesti === $chiestiPrima + 1
        );

        check('spento: una variante senza pagina, le quattro opzioni lì', fn () =>
            count($spenta) === 1
            && (string) $spenta[0]['slug'] === ''
            && count($prodotti($modelId)) === 4
            && count(array_filter($prodotti($modelId), static fn (array $p): bool => (int) $p['product_variant_id'] === (int) $spenta[0]['id'])) === 4
            && ProductModelResource::variantAttributeId($modelId) === 0
        );

        check('spento: il colore passa sulla tabella delle opzioni', fn () =>
            $valoreProdotto($unProdotto, $colore['id']) === $colore['values'][0]
        );

        check('spento: le pagine vanno nel cestino con lo slug, la foto del colore vale per l\'articolo', fn () =>
            (int) (ProductVariant::find(['id' => $blu, 'deleted' => 'true'], 1)['id'] ?? 0) === $blu
            && $variantDi($fotoColore) === null
            && $variantDi($fotoOpzione) === (int) $spenta[0]['id']
        );

        check('301 da spento: la vecchia pagina va sulla query del colore', fn () =>
            PageRedirect::formerQuery($modelId, $slugBlu) === [$colore['slug'] => 'blu']
        );

        $salva($modelId, (string) $colore['id']);
        $riaccese = $varianti($modelId);

        check('riacceso: tornano le pagine di prima, con lo stesso slug', fn () =>
            count($riaccese) === 2
            && in_array($blu, array_map(static fn (array $v): int => (int) $v['id'], $riaccese), true)
            && (string) ProductVariant::find(['id' => $blu], 1)['slug'] === $slugBlu
            && $valoreProdotto($unProdotto, $colore['id']) === 0
            && (int) Product::find(['id' => $unProdotto], 1)['product_variant_id'] === $blu
            && $variantDi($fotoOpzione) === $blu
        );

        check('301 da acceso: ?colore=blu va sulla pagina blu', fn () =>
            (PageRedirect::pageFor(PageRedirect::pages($modelId), [$colore['slug'] => 'blu'])['slug'] ?? '') === $slugBlu
        );

        $chiestiPrima = ProvaPagine::$chiesti;
        $salva($modelId, (string) $taglia['id']);
        $salva($modelId, (string) $taglia['id']);

        check('stessa scelta salvata di nuovo: niente richieste in più', fn () =>
            ProvaPagine::$chiesti === $chiestiPrima + 1
        );

        check('uno solo acceso: passando alla taglia il colore torna sulle opzioni', function () use ($modelId, $varianti, $prodotti, $valoreProdotto, $valoreVariante, $colore, $taglia) {
            $nuove = $varianti($modelId);

            if (count($nuove) !== 2 || ProductModelResource::variantAttributeId($modelId) !== $taglia['id']) {
                return false;
            }

            foreach ($nuove as $variant) {
                if ($valoreVariante((int) $variant['id'], $colore['id']) !== 0) {
                    return false;
                }
            }

            foreach ($prodotti($modelId) as $product) {
                if ($valoreProdotto((int) $product['id'], $colore['id']) === 0 || $valoreProdotto((int) $product['id'], $taglia['id']) !== 0) {
                    return false;
                }
            }

            return count($prodotti($modelId)) === 4;
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento il catalogo è come prima', fn () => $tabelle() === $prima);

summary();
