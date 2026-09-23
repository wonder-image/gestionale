<?php
/** php tests/integrazione/CatalogDemoTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Package;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Seeding\CatalogDemo;
use Wonder\Plugin\Gestionale\Seeding\DemoCode;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
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

/** Le righe di una tabella, già in elenco: le usano i check più minuti. */
$righe = static function (string $model, array $where = []): array {
    $rows = $model::find(array_merge(['deleted' => 'false'], $where));

    if (!is_array($rows) || $rows === []) {
        return [];
    }

    return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
};

/** La riga di prova con quel riferimento, dal segno nel codice. */
$demo = static function (string $model, string $ref): array {
    $row = $model::find(['code' => DemoCode::forModel($model, $ref), 'deleted' => 'false'], 1);

    return is_array($row) && isset($row['id']) ? $row : [];
};

/** L'id di una riga di prova, dal suo riferimento. */
$idDi = static fn (string $model, string $ref): int => (int) ($demo($model, $ref)['id'] ?? 0);

$tutte = [
    Brand::class, Category::class, Tag::class, Attribute::class, AttributeValue::class,
    ProductModel::class, ProductVariant::class, Product::class, ProductImage::class,
    Package::class,
];
$stato = static fn (): array => array_map($conta, $tutte);
$prima = $stato();

// `clear()` toglie le foto dal disco, e la transazione rimette indietro le
// righe ma non i file: senza una fotografia della cartella, il sito resta con
// righe che puntano a file spariti. L'istantanea si rimette da sé anche se
// questo test muore a metà (vedi `Istantanea` in tests/harness.php).
$cartella = rtrim((string) ($GLOBALS['ROOT'] ?? ''), '/').'/assets/upload'.ProductImages::folder();
$foto = Istantanea::di($cartella);

try {
    Transaction::run(static function () use ($conta, $stato, $righe, $idDi, $demo, $tutte): void {
        // Il sito può avere già i dati di prova: si parte dal pulito, e la
        // transazione rimette tutto com'era.
        CatalogDemo::clear();

        // Il sito può avere anche righe vere con gli stessi nomi: i dati di
        // prova le userebbero invece di crearne di loro, e i conti qui sotto
        // non tornerebbero. Si rinominano; l'annullamento le rimette com'erano.
        $nomi = [
            Brand::class => ['Maglificio Aurora'],
            Category::class => ['Abbigliamento', 'Magliette e felpe', 'Accessori'],
            Tag::class => ['Novità', 'Saldi'],
            Attribute::class => ['Colore', 'Taglia', 'Materiale'],
            Package::class => ['Busta imbottita', 'Scatola media'],
        ];

        // Lo stesso per le righe col vecchio nome che qualcosa di vero usa
        // ancora: la pulizia le lascia e lo dice, e le note qui sotto non
        // sarebbero più vuote.
        foreach (DemoCode::LEGACY_NAMES as $model => $elenco) {
            if (in_array($model, $tutte, true)) {
                $nomi[$model] = array_merge($nomi[$model] ?? [], $elenco);
            }
        }

        foreach ($nomi as $model => $elenco) {
            foreach ($righe($model) as $riga) {
                foreach ($elenco as $nome) {
                    if (DemoCode::sameName((string) ($riga['name'] ?? ''), $nome)) {
                        $model::update(['name' => $nome.' vera'], (int) $riga['id']);
                    }
                }
            }
        }

        DemoData::notes();
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
                && $dopo[8] === $prima[8] + 6
                && $dopo[9] === $prima[9] + 2;     // imballaggi
        });

        check('ogni riga di prova ha il segno nel codice e un nome vero', function () use ($demo) {
            $attese = [
                [Brand::class, 'maglificio-aurora', 'Maglificio Aurora'],
                [Category::class, 'abbigliamento', 'Abbigliamento'],
                [Category::class, 'magliette-e-felpe', 'Magliette e felpe'],
                [Category::class, 'accessori', 'Accessori'],
                [Tag::class, 'novita', 'Novità'],
                [Tag::class, 'saldi', 'Saldi'],
                [Attribute::class, 'colore', 'Colore'],
                [Attribute::class, 'taglia', 'Taglia'],
                [Attribute::class, 'materiale', 'Materiale'],
                [Package::class, 'busta-imbottita', 'Busta imbottita'],
                [Package::class, 'scatola-media', 'Scatola media'],
                [ProductModel::class, 'cappello-di-lana', 'Cappello di lana'],
                [ProductModel::class, 'maglietta-girocollo', 'Maglietta girocollo'],
                [ProductModel::class, 'felpa-con-cappuccio', 'Felpa con cappuccio'],
                [ProductModel::class, 'calzini-a-costine', 'Calzini a costine'],
            ];

            foreach ($attese as [$model, $ref, $nome]) {
                if (!DemoCode::sameName((string) ($demo($model, $ref)['name'] ?? ''), $nome)) {
                    echo "    manca {$ref}\n";

                    return false;
                }
            }

            return true;
        });

        check('gli articoli hanno una descrizione breve vera', function () use ($demo) {
            foreach (['cappello-di-lana', 'maglietta-girocollo', 'felpa-con-cappuccio', 'calzini-a-costine'] as $ref) {
                $testo = trim((string) ($demo(ProductModel::class, $ref)['short_description'] ?? ''));

                if ($testo === '' || str_contains(strtolower($testo), 'prova')) {
                    return false;
                }
            }

            return true;
        });

        check('i quattro articoli sono i quattro casi che servono', function () {
            $conta = static function (string $ref): array {
                $modello = ProductModel::find([
                    'code' => DemoCode::forModel(ProductModel::class, $ref),
                    'deleted' => 'false',
                ], 1);
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
            return $conta('cappello-di-lana') === [1, 1]
                && $conta('maglietta-girocollo') === [2, 12]
                && $conta('felpa-con-cappuccio') === [3, 12]
                && $conta('calzini-a-costine') === [1, 4];
        });

        check('le foto nascono in attesa delle misure', function () {
            $rows = ProductImage::find(['status' => 'pending', 'deleted' => 'false']);
            $rows = is_array($rows) && $rows !== []
                ? (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array')))
                : [];

            return count($rows) >= 6;
        });

        check('il terzo attributo è un\'opzione da scegliere', function () use ($demo) {
            $materiale = $demo(Attribute::class, 'materiale');

            return ($materiale['level'] ?? '') === 'product'
                && ($materiale['type'] ?? '') === 'select';
        });

        check('un articolo usa tutti e tre gli attributi', function () use ($righe, $idDi) {
            $id = $idDi(ProductModel::class, 'maglietta-girocollo');
            $nomi = array_column($righe(Product::class, ['product_model_id' => $id]), 'name');

            // Il nome nasce dagli attributi, colore compreso; nella griglia il
            // colore lo dice la testata del gruppo e si legge "S / Gomma".
            return count($nomi) === 12 && in_array('Blu / S / Gomma', $nomi, true);
        });

        check('un articolo si vende solo a taglia, senza colore', function () use ($righe, $idDi) {
            $id = $idDi(ProductModel::class, 'calzini-a-costine');
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
            $id = $idDi(ProductModel::class, 'maglietta-girocollo');
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

        check('il colore sta sulla variante e la taglia sul prodotto', function () use ($demo) {
            $colore = $demo(Attribute::class, 'colore');
            $taglia = $demo(Attribute::class, 'taglia');

            return ($colore['level'] ?? '') === 'variant'
                && ($colore['type'] ?? '') === 'color'
                && ($taglia['level'] ?? '') === 'product'
                && ($taglia['type'] ?? '') === 'select'
                && ($colore['code'] ?? '') === 'att_demo-colore';
        });

        check('i valori stanno sotto il loro attributo, in ordine', function () use ($demo) {
            $colore = $demo(Attribute::class, 'colore');
            $valori = AttributeValue::find(
                ['attribute_id' => (int) ($colore['id'] ?? 0), 'deleted' => 'false'],
                null,
                'position',
                'ASC'
            );
            $valori = is_array($valori) ? array_values(array_filter($valori, 'is_array')) : [];

            return array_column($valori, 'label') === ['Blu', 'Rosso', 'Nero'];
        });

        check('le categorie di prova sono un albero', function () use ($demo) {
            $padre = $demo(Category::class, 'abbigliamento');
            $figlia = $demo(Category::class, 'magliette-e-felpe');

            return (int) ($figlia['parent_id'] ?? 0) === (int) ($padre['id'] ?? -1);
        });

        check('il cappello sta negli accessori', function () use ($idDi, $righe) {
            $collegamenti = $righe(ProductModelCategory::class, [
                'product_model_id' => $idDi(ProductModel::class, 'cappello-di-lana'),
            ]);

            return count($collegamenti) === 1
                && (int) $collegamenti[0]['category_id'] === $idDi(Category::class, 'accessori');
        });

        check('ogni riga ha codice e indirizzo', function () use ($demo) {
            $marchio = $demo(Brand::class, 'maglificio-aurora');

            // Lo slug di una riga vera rinominata qui sopra resta occupato:
            // allora quello di prova diventa `maglificio-aurora-2`.
            return ($marchio['code'] ?? '') === 'bra_demo-maglificio-aurora'
                && str_starts_with((string) ($marchio['slug'] ?? ''), 'maglificio-aurora');
        });

        check('una seconda esecuzione non duplica niente', fn () => CatalogDemo::create() === 0);

        check('la pulizia toglie solo le righe di prova', function () use ($stato, $prima) {
            CatalogDemo::clear();

            return $stato() === $prima && DemoData::notes() === [];
        });

        check('una riga vera con lo stesso nome si usa, e la pulizia non la tocca', function () use ($conta, $demo) {
            $vera = Tag::create([
                'code' => Code::make(Tag::class, Codes::TAG),
                'name' => 'Saldi',
                'slug' => Slug::unique('Saldi', Tag::class),
                'visible' => 'true',
            ]);
            $id = (int) ($vera->insert_id ?? 0);
            $tagPrima = $conta(Tag::class);

            DemoData::notes();
            CatalogDemo::create();
            $note = implode("\n", DemoData::notes());
            $nuovi = $conta(Tag::class) - $tagPrima;
            $senzaSegno = $demo(Tag::class, 'saldi') === [];

            CatalogDemo::clear();
            DemoData::notes();
            $ancora = Tag::find(['id' => $id, 'deleted' => 'false'], 1);
            $ancora = is_array($ancora) ? $ancora : [];

            $ok = $id > 0
                // Solo «Novità»: «Saldi» c'era già.
                && $nuovi === 1
                && $senzaSegno
                && str_contains($note, 'tag «Saldi»')
                && (int) ($ancora['id'] ?? 0) === $id
                && !DemoCode::is((string) ($ancora['code'] ?? ''));

            Tag::delete($id);

            return $ok;
        });

        check('una categoria di prova che una vera usa ancora resta, e il comando lo dice', function () use ($demo, $idDi) {
            CatalogDemo::create();
            $padre = $idDi(Category::class, 'abbigliamento');
            $figlia = Category::create([
                'code' => Code::make(Category::class, Codes::CATEGORY),
                'name' => 'Pantaloni',
                'slug' => Slug::unique('Pantaloni', Category::class),
                'parent_id' => $padre,
                'position' => 9,
                'visible' => 'true',
            ]);
            $figliaId = (int) ($figlia->insert_id ?? 0);

            DemoData::notes();
            CatalogDemo::clear();
            $note = DemoData::notes();

            $ok = $padre > 0 && $figliaId > 0
                && $demo(Category::class, 'abbigliamento') !== []
                && $demo(Category::class, 'magliette-e-felpe') === []
                && $demo(Category::class, 'accessori') === []
                && $demo(ProductModel::class, 'cappello-di-lana') === []
                && $note === ['Resta al suo posto 1 dato di prova ancora in uso: categoria «Abbigliamento».'];

            // Tolta la figlia vera, la pulizia porta via anche il padre.
            Category::delete($figliaId);
            CatalogDemo::clear();
            DemoData::notes();

            return $ok && $demo(Category::class, 'abbigliamento') === [];
        });

        check('la pulizia toglie anche i vecchi dati «Prova …»', function () {
            $vecchio = Brand::create([
                'code' => Code::make(Brand::class, Codes::BRAND),
                'name' => 'Prova Marchio',
                'slug' => Slug::unique('Prova Marchio', Brand::class),
                'position' => 1,
                'visible' => 'true',
            ]);
            $id = (int) ($vecchio->insert_id ?? 0);

            CatalogDemo::clear();
            DemoData::notes();
            $dopo = Brand::find(['id' => $id, 'deleted' => 'false'], 1);

            return $id > 0 && (!is_array($dopo) || $dopo === []);
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento il catalogo è come prima', fn () => $stato() === $prima);

// Le righe sono tornate con l'annullamento; i file li rimette l'istantanea.
// Qui e non alla fine del processo, perché il check qui sotto guarda il disco.
$foto->ripristina();

check('il sito resta con foto vere sul disco', function () {
    $rotte = [];

    foreach (ProductImage::find(['deleted' => 'false']) ?: [] as $riga) {
        if (!is_array($riga)) {
            continue;
        }

        $percorso = ProductImages::path($riga);

        if ($percorso !== '' && !is_file($percorso)) {
            $rotte[] = '#'.($riga['id'] ?? '?').' → '.basename($percorso);
        }
    }

    if ($rotte !== []) {
        // Dire quali: una riga che punta a un file sparito non si trova a
        // occhio fra cinquanta.
        echo '    righe senza file: '.implode(', ', $rotte)."\n";
    }

    return $rotte === [];
});

summary();
