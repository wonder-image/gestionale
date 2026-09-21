<?php
/** php tests/integrazione/CatalogDemoTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Seeding\CatalogDemo;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$conta = static function (string $model): int {
    $rows = $model::find(['deleted' => 'false']);

    if (!is_array($rows) || $rows === []) {
        return 0;
    }

    return isset($rows['id']) ? 1 : count(array_filter($rows, 'is_array'));
};

$prima = [$conta(Brand::class), $conta(Category::class), $conta(Tag::class)];

try {
    Transaction::run(static function () use ($conta): void {
        // Il sito può avere già i dati di prova: si parte dal pulito, e la
        // transazione rimette tutto com'era.
        CatalogDemo::clear();
        $prima = [$conta(Brand::class), $conta(Category::class), $conta(Tag::class)];

        $creati = CatalogDemo::create();

        check('crea marchio, tre categorie e due tag', fn () =>
            $creati === 6
            && $conta(Brand::class) === $prima[0] + 1
            && $conta(Category::class) === $prima[1] + 3
            && $conta(Tag::class) === $prima[2] + 2
        );

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

        check('la pulizia toglie solo le righe di prova', function () use ($conta, $prima) {
            $tolte = CatalogDemo::clear();

            return $tolte === 6
                && [$conta(Brand::class), $conta(Category::class), $conta(Tag::class)] === $prima;
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento il catalogo è come prima', fn () =>
    [$conta(Brand::class), $conta(Category::class), $conta(Tag::class)] === $prima
);

summary();
