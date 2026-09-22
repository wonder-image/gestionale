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
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
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

/** Le righe di una tabella, già in elenco: le usano i check più minuti. */
$righe = static function (string $model, array $where = []): array {
    $rows = $model::find(array_merge(['deleted' => 'false'], $where));

    if (!is_array($rows) || $rows === []) {
        return [];
    }

    return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
};

/** L'id di una riga di prova, dal suo nome. */
$idDi = static function (string $model, string $name) use ($righe): int {
    return (int) (($righe($model, ['name' => $name])[0] ?? [])['id'] ?? 0);
};

$tutte = [
    Brand::class, Category::class, Tag::class, Attribute::class, AttributeValue::class,
    ProductModel::class, ProductVariant::class, Product::class, ProductImage::class,
];
$stato = static fn (): array => array_map($conta, $tutte);
$prima = $stato();

// `clear()` toglie le foto dal disco, e la transazione rimette indietro le
// righe ma non i file. Se ne tiene una copia e alla fine si rimettono: così il
// sito resta esattamente com'era, id compresi.
$aveva = is_array(Brand::find(['name' => 'Prova Marchio', 'deleted' => 'false'], 1));
$cartella = rtrim((string) ($GLOBALS['ROOT'] ?? ''), '/').'/assets/upload'.ProductImages::folder();
$copia = sys_get_temp_dir().'/gestionale-foto-'.getmypid();
$fileDiPrima = glob($cartella.'*') ?: [];

if ($fileDiPrima !== [] && !is_dir($copia)) {
    mkdir($copia, 0777, true);

    foreach ($fileDiPrima as $file) {
        copy($file, $copia.'/'.basename($file));
    }
}

try {
    Transaction::run(static function () use ($conta, $stato, $righe, $idDi): void {
        // Il sito può avere già i dati di prova: si parte dal pulito, e la
        // transazione rimette tutto com'era.
        CatalogDemo::clear();
        $prima = $stato();

        $creati = CatalogDemo::create();

        check('crea tassonomie, attributi e quattro articoli', function () use ($creati, $stato, $prima) {
            $dopo = $stato();

            return $creati > 15
                && $dopo[0] === $prima[0] + 1      // marchio
                && $dopo[1] === $prima[1] + 3      // categorie
                && $dopo[2] === $prima[2] + 2      // tag
                && $dopo[3] === $prima[3] + 3      // attributi
                && $dopo[4] === $prima[4] + 9      // valori
                && $dopo[5] === $prima[5] + 4      // articoli
                // Una foto per articolo, più quella di un colore e quella di
                // una singola opzione in vendita.
                && $dopo[8] === $prima[8] + 6;
        });

        check('i quattro articoli sono i quattro casi che servono', function () {
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

            // Il colore fa i gruppi, gli altri attributi le righe: due colori
            // per tre taglie per due materiali fanno dodici opzioni, e i
            // calzini ne fanno quattro senza nessun gruppo.
            return $conta('Cappello di lana') === [1, 1]
                && $conta('Maglietta girocollo') === [2, 12]
                && $conta('Felpa con cappuccio') === [3, 12]
                && $conta('Calzini a costine') === [1, 4];
        });

        check('le foto nascono in attesa delle misure', function () {
            $rows = ProductImage::find(['status' => 'pending', 'deleted' => 'false']);
            $rows = is_array($rows) && $rows !== []
                ? (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array')))
                : [];

            return count($rows) >= 6;
        });

        check('il terzo attributo si sceglie nel carrello', function () {
            $materiale = Attribute::find(['name' => 'Prova Materiale', 'deleted' => 'false'], 1);

            return ($materiale['level'] ?? '') === 'product'
                && ($materiale['type'] ?? '') === 'select';
        });

        check('un articolo usa tutti e tre gli attributi', function () use ($righe, $idDi) {
            $id = $idDi(ProductModel::class, 'Prova Maglietta girocollo');
            $nomi = array_column($righe(Product::class, ['product_model_id' => $id]), 'name');

            // Il nome nasce dagli attributi, colore compreso; nella griglia il
            // colore lo dice la testata del gruppo e si legge "S / Gomma".
            return count($nomi) === 12 && in_array('Blu / S / Gomma', $nomi, true);
        });

        check('un articolo si vende solo a taglia, senza colore', function () use ($righe, $idDi) {
            $id = $idDi(ProductModel::class, 'Prova Calzini a costine');
            $varianti = $righe(ProductVariant::class, ['product_model_id' => $id]);
            $nomi = array_column($righe(Product::class, ['product_model_id' => $id]), 'name');

            if (count($varianti) !== 1 || count($nomi) !== 4) {
                return false;
            }

            // Nessun attributo con pagina propria: la griglia resta piatta e
            // le righe si chiamano con la sola taglia.
            return ProductAttributes::read('variant', (int) $varianti[0]['id']) === []
                && in_array('S', $nomi, true);
        });

        check('la foto segue tre livelli: opzione, colore, articolo', function () use ($righe, $idDi) {
            $id = $idDi(ProductModel::class, 'Prova Maglietta girocollo');
            $foto = $righe(ProductImage::class, ['product_model_id' => $id]);
            $sue = array_values(array_filter(
                $foto,
                static fn (array $riga): bool => (int) ($riga['product_id'] ?? 0) > 0
            ));

            if (count($foto) !== 3 || count($sue) !== 1) {
                return false;
            }

            $opzione = (int) $sue[0]['product_id'];
            $colore = (int) $sue[0]['product_variant_id'];
            $altraOpzione = 0;
            $altroColore = 0;

            foreach ($righe(Product::class, ['product_model_id' => $id]) as $prodotto) {
                if ((int) $prodotto['product_variant_id'] === $colore && (int) $prodotto['id'] !== $opzione) {
                    $altraOpzione = (int) $prodotto['id'];
                    break;
                }
            }

            foreach ($righe(ProductVariant::class, ['product_model_id' => $id]) as $variante) {
                if ((int) $variante['id'] !== $colore) {
                    $altroColore = (int) $variante['id'];
                    break;
                }
            }

            $sua = ProductImages::for($foto, $colore, $opzione);
            $delColore = ProductImages::for($foto, $colore, $altraOpzione);
            $dellArticolo = ProductImages::for($foto, $altroColore, 0);

            return count($sua) === 1 && (int) $sua[0]['product_id'] === $opzione
                && count($delColore) === 1
                    && (int) $delColore[0]['product_id'] === 0
                    && (int) $delColore[0]['product_variant_id'] === $colore
                && count($dellArticolo) === 1
                    && (int) $dellArticolo[0]['product_variant_id'] === 0;
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

// Le righe sono tornate con l'annullamento; i file li rimettiamo noi.
foreach (glob($copia.'/*') ?: [] as $file) {
    if (!is_file($cartella.basename($file))) {
        copy($file, $cartella.basename($file));
    }

    unlink($file);
}

if (is_dir($copia)) {
    rmdir($copia);
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
