<?php
/** php tests/integrazione/CatalogDemoTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Seeding\CatalogDemo;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$conta = static function (string $model): int {
    $rows = $model::find(['deleted' => 'false']);

    if (!is_array($rows) || $rows === []) {
        return 0;
    }

    return isset($rows['id']) ? 1 : count(array_filter($rows, 'is_array'));
};

$tutte = [
    Brand::class, Category::class, Tag::class, Attribute::class, AttributeValue::class,
    ProductModel::class, ProductVariant::class, Product::class, ProductImage::class,
];
$stato = static fn (): array => array_map($conta, $tutte);
$prima = $stato();

// Il sito aveva già i dati di prova? Serve in fondo: `clear()` toglie le foto
// dal disco e la transazione non le rimette.
$aveva = is_array(Brand::find(['name' => 'Prova Marchio', 'deleted' => 'false'], 1));

try {
    Transaction::run(static function () use ($conta, $stato): void {
        // Il sito può avere già i dati di prova: si parte dal pulito, e la
        // transazione rimette tutto com'era.
        CatalogDemo::clear();
        $prima = $stato();

        $creati = CatalogDemo::create();

        check('crea tassonomie, attributi e tre articoli', function () use ($creati, $stato, $prima) {
            $dopo = $stato();

            return $creati > 15
                && $dopo[0] === $prima[0] + 1      // marchio
                && $dopo[1] === $prima[1] + 3      // categorie
                && $dopo[2] === $prima[2] + 2      // tag
                && $dopo[3] === $prima[3] + 2      // attributi
                && $dopo[4] === $prima[4] + 7      // valori
                && $dopo[5] === $prima[5] + 3      // articoli
                && $dopo[8] === $prima[8] + 3;     // una foto per articolo
        });

        check('i tre articoli sono i tre casi che servono', function () {
            $conta = static function (string $name): array {
                $modello = ProductModel::find(['name' => 'Prova '.$name, 'deleted' => 'false'], 1);
                $id = (int) ($modello['id'] ?? 0);
                $righe = static function (string $model) use ($id): int {
                    $rows = $model::find(['product_model_id' => $id, 'deleted' => 'false']);

                    if (!is_array($rows) || $rows === []) {
                        return 0;
                    }

                    return isset($rows['id']) ? 1 : count(array_filter($rows, 'is_array'));
                };

                return [$righe(ProductVariant::class), $righe(Product::class)];
            };

            return $conta('Cappello di lana') === [1, 1]
                && $conta('Maglietta girocollo') === [2, 6]
                && $conta('Felpa con cappuccio') === [3, 12];
        });

        check('le foto nascono in attesa delle misure', function () {
            $rows = ProductImage::find(['status' => 'pending', 'deleted' => 'false']);
            $rows = is_array($rows) && $rows !== []
                ? (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array')))
                : [];

            return count($rows) >= 3;
        });

        check('il colore sta sulla variante e la taglia sul prodotto', function () {
            $colore = Attribute::find(['name' => 'Prova Colore', 'deleted' => 'false'], 1);
            $taglia = Attribute::find(['name' => 'Prova Taglia', 'deleted' => 'false'], 1);

            return ($colore['level'] ?? '') === 'variant'
                && ($colore['type'] ?? '') === 'color'
                && ($taglia['level'] ?? '') === 'product'
                && ($taglia['type'] ?? '') === 'select'
                && str_starts_with((string) ($colore['code'] ?? ''), 'att_');
        });

        check('i valori stanno sotto il loro attributo, in ordine', function () {
            $colore = Attribute::find(['name' => 'Prova Colore', 'deleted' => 'false'], 1);
            $valori = AttributeValue::find(
                ['attribute_id' => (int) ($colore['id'] ?? 0), 'deleted' => 'false'],
                null,
                'position',
                'ASC'
            );
            $valori = is_array($valori) ? array_values(array_filter($valori, 'is_array')) : [];

            return array_column($valori, 'label') === ['Blu', 'Rosso', 'Nero'];
        });

        check('le categorie di prova sono un albero', function () {
            $padre = Category::find(['name' => 'Prova Abbigliamento', 'deleted' => 'false'], 1);
            $figlia = Category::find(['name' => 'Prova Magliette', 'deleted' => 'false'], 1);

            return (int) ($figlia['parent_id'] ?? 0) === (int) ($padre['id'] ?? -1);
        });

        check('ogni riga ha codice e indirizzo', function () {
            $marchio = Brand::find(['name' => 'Prova Marchio', 'deleted' => 'false'], 1);

            return str_starts_with((string) ($marchio['code'] ?? ''), 'bra_')
                && ($marchio['slug'] ?? '') === 'prova-marchio';
        });

        check('una seconda esecuzione non duplica niente', fn () => CatalogDemo::create() === 0);

        check('la pulizia toglie solo le righe di prova', function () use ($stato, $prima) {
            CatalogDemo::clear();

            return $stato() === $prima;
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento il catalogo è come prima', fn () => $stato() === $prima);

// La transazione riporta indietro le righe, non i file: `clear()` ha tolto le
// foto dal disco e quelle non tornano da sole. Se il sito aveva i dati di
// prova, glieli si rifà, altrimenti resterebbe con tre righe che puntano a
// file che non ci sono più.
if ($aveva) {
    CatalogDemo::clear();
    CatalogDemo::create();
}

check('il sito resta con foto vere sul disco', function () use ($aveva) {
    if (!$aveva) {
        return true;
    }

    foreach (ProductImage::find(['deleted' => 'false']) ?: [] as $riga) {
        if (!is_array($riga)) {
            continue;
        }

        $percorso = ProductImages::path($riga);

        if ($percorso !== '' && !is_file($percorso)) {
            return false;
        }
    }

    return true;
});

summary();
