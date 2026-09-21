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

$tutte = [Brand::class, Category::class, Tag::class, Attribute::class, AttributeValue::class];
$stato = static fn (): array => array_map($conta, $tutte);
$prima = $stato();

try {
    Transaction::run(static function () use ($conta, $stato): void {
        // Il sito può avere già i dati di prova: si parte dal pulito, e la
        // transazione rimette tutto com'era.
        CatalogDemo::clear();
        $prima = $stato();

        $creati = CatalogDemo::create();

        check('crea marchio, categorie, tag, attributi e valori', fn () =>
            $creati === 15
            && $stato() === [
                $prima[0] + 1,
                $prima[1] + 3,
                $prima[2] + 2,
                $prima[3] + 2,
                $prima[4] + 7,
            ]
        );

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
            $tolte = CatalogDemo::clear();

            return $tolte === 15 && $stato() === $prima;
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento il catalogo è come prima', fn () => $stato() === $prima);

summary();
