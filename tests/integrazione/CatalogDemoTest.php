<?php
/** php tests/integrazione/CatalogDemoTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleComponent;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleGroup;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleGroupOption;
use Wonder\Plugin\Gestionale\Models\Catalog\Package;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationOption;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCustomization;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Seeding\CatalogDemo;
use Wonder\Plugin\Gestionale\Seeding\ContactsDemo;
use Wonder\Plugin\Gestionale\Seeding\DemoCode;
use Wonder\Plugin\Gestionale\Seeding\PromotionsDemo;
use Wonder\Plugin\Gestionale\Support\Catalog\Bundles;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Purchasing\ProductSuppliers;
use Wonder\Plugin\Gestionale\Support\Stock\Alerts;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\Thresholds;
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

/** L'id della scheda fornitore di prova con quel riferimento, `0` se non c'è. */
$fornitoreId = static function (string $ref): int {
    $riga = Contact::find([
        'code' => DemoCode::forModel(Contact::class, $ref),
        'is_supplier' => 'true',
        'deleted' => 'false',
    ], 1);

    return is_array($riga) ? (int) ($riga['id'] ?? 0) : 0;
};

/** Gli articoli di prova, nell'ordine in cui nascono. */
const ARTICOLI = ['cappello-di-lana', 'maglietta-girocollo', 'felpa-con-cappuccio', 'calzini-a-costine'];

$tutte = [
    Brand::class, Category::class, Tag::class, Attribute::class, AttributeValue::class,
    ProductModel::class, ProductVariant::class, Product::class, ProductImage::class,
    Package::class,
    Customization::class, CustomizationOption::class, ProductModelCustomization::class,
    BundleComponent::class, BundleGroup::class, BundleGroupOption::class,
];
$stato = static fn (): array => array_map($conta, $tutte);
$prima = $stato();

// `clear()` toglie le foto dal disco, e la transazione rimette indietro le
// righe ma non i file: senza una fotografia della cartella, il sito resta con
// righe che puntano a file spariti. L'istantanea si rimette da sé anche se
// questo test muore a metà (vedi `Istantanea` in tests/harness.php).
$cartella = rtrim((string) ($GLOBALS['ROOT'] ?? ''), '/').'/assets/upload'.ProductImages::folder();
$foto = Istantanea::di($cartella);

/** Le righe di foto che puntano a un file che non c'è, come `#id → file`. */
$fotoRotte = static function (): array {
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

    return $rotte;
};

// Il sito può avere già righe senza file: conta solo quello che rompe il test.
$fotoRottePrima = $fotoRotte();

// Gli avvisi di scorta nascono solo a funzionalità accesa: si accende qui,
// così l'opzione sotto soglia fa il suo avviso qualunque sia il sito.
$funzionalita = new ReflectionProperty(Gestionale::class, 'features');
$funzionalitaPrima = $funzionalita->getValue();
$funzionalita->setValue(null, [...Gestionale::features(), 'low_stock_alerts' => true]);

try {
    Transaction::run(static function () use ($conta, $stato, $righe, $idDi, $demo, $tutte, $fornitoreId): void {
        // Il sito può avere già i dati di prova: si parte dal pulito, e la
        // transazione rimette tutto com'era. Prima le promozioni: le campagne
        // di prova puntano a marchio, tag e categoria di prova, e li terrebbero.
        PromotionsDemo::clear();
        CatalogDemo::clear();

        // I fornitori degli articoli di prova sono schede della rubrica di
        // prova: senza, il catalogo nasce senza costi. Una scheda vera con lo
        // stesso nome verrebbe riusata al posto di quella di prova, e non
        // avrebbe il segno nel codice: si rinomina, e l'annullamento la
        // rimette com'era.
        foreach (['Filati Nord Spa', 'Imballaggi Sud Srl'] as $nome) {
            foreach ($righe(Contact::class) as $scheda) {
                if (!DemoCode::is((string) ($scheda['code'] ?? '')) && DemoCode::sameName((string) ($scheda['business_name'] ?? ''), $nome)) {
                    Contact::update(['business_name' => $nome.' vera'], (int) $scheda['id']);
                }
            }
        }

        ContactsDemo::create();
        DemoData::notes();

        // Il sito può avere anche righe vere con gli stessi nomi: i dati di
        // prova le userebbero invece di crearne di loro, e i conti qui sotto
        // non tornerebbero. Si rinominano; l'annullamento le rimette com'erano.
        $nomi = [
            Brand::class => ['Maglificio Aurora'],
            Category::class => ['Abbigliamento', 'Magliette e felpe', 'Accessori'],
            Tag::class => ['Novità', 'Saldi'],
            Attribute::class => ['Colore', 'Taglia', 'Materiale', 'Composizione', 'Lavaggio'],
            Package::class => ['Busta imbottita', 'Scatola media'],
            Customization::class => ['Incisione', 'Confezione regalo'],
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

        check('crea tassonomie, attributi, quattro articoli e tre multiprodotti', function () use ($creati, $stato, $prima) {
            $dopo = $stato();

            return $creati > 15
                && $dopo[0] === $prima[0] + 1      // marchio
                && $dopo[1] === $prima[1] + 3      // categorie
                && $dopo[2] === $prima[2] + 2      // tag
                && $dopo[3] === $prima[3] + 5      // attributi, scheda tecnica compresa
                && $dopo[4] === $prima[4] + 12     // valori
                && $dopo[5] === $prima[5] + 7      // articoli: quattro semplici e tre multiprodotti
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
                [Attribute::class, 'composizione', 'Composizione'],
                [Attribute::class, 'lavaggio', 'Lavaggio'],
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

        check('ogni opzione compra da Filati Nord, e quelle dei calzini anche da Imballaggi Sud', function () use ($idDi, $righe, $fornitoreId) {
            $nord = $fornitoreId('filati-nord');
            $sud = $fornitoreId('imballaggi-sud');

            if ($nord <= 0 || $sud <= 0) {
                echo "    mancano le schede fornitore di prova\n";

                return false;
            }

            // I fornitori stanno sull'opzione (spec §23.1), con codice e
            // costo come li scrive la scheda: tutte le opzioni di un articolo
            // hanno le stesse righe, meno l'ultima dei calzini.
            $attesi = [
                'cappello-di-lana' => [['supplier_id' => $nord, 'supplier_sku' => 'FN-CAP-01', 'cost' => 9.5]],
                'maglietta-girocollo' => [['supplier_id' => $nord, 'supplier_sku' => 'FN-TSH-01', 'cost' => 7.2]],
                'felpa-con-cappuccio' => [['supplier_id' => $nord, 'supplier_sku' => 'FN-FEL-01', 'cost' => 21.0]],
                'calzini-a-costine' => [
                    ['supplier_id' => $nord, 'supplier_sku' => 'FN-CAL-01', 'cost' => 2.4],
                    ['supplier_id' => $sud, 'supplier_sku' => 'IS-CAL-01', 'cost' => 2.1],
                ],
            ];
            $ultimaDeiCalzini = [
                ['supplier_id' => $nord, 'supplier_sku' => 'FN-CAL-XL', 'cost' => 2.9],
                ['supplier_id' => $sud, 'supplier_sku' => 'IS-CAL-01', 'cost' => 2.1],
            ];

            foreach ($attesi as $ref => $fornitori) {
                $opzioni = array_map(
                    static fn (array $opzione): int => (int) $opzione['id'],
                    $righe(Product::class, ['product_model_id' => $idDi(ProductModel::class, $ref)])
                );
                $legami = ProductSuppliers::linksFor($opzioni);
                $ultima = $opzioni === [] ? 0 : $opzioni[count($opzioni) - 1];

                if ($opzioni === []) {
                    echo "    nessuna opzione su {$ref}\n";

                    return false;
                }

                foreach ($opzioni as $id) {
                    $suoi = $ref === 'calzini-a-costine' && $id === $ultima ? $ultimaDeiCalzini : $fornitori;

                    if (($legami[$id] ?? []) !== $suoi) {
                        echo "    fornitori diversi su {$ref}, opzione {$id}\n";

                        return false;
                    }
                }
            }

            return true;
        });

        check('rifare i dati di prova non tocca i fornitori già scritti, e riempie solo le opzioni che non ne hanno', function () use ($idDi, $righe, $fornitoreId) {
            $nord = $fornitoreId('filati-nord');
            $opzioni = $righe(Product::class, ['product_model_id' => $idDi(ProductModel::class, 'maglietta-girocollo')]);
            $cambiata = (int) ($opzioni[0]['id'] ?? 0);
            $vuota = (int) ($opzioni[1]['id'] ?? 0);

            ProductSuppliers::sync($cambiata, [['supplier_id' => $nord, 'supplier_sku' => 'A-MANO', 'cost' => '8,00']]);
            ProductSuppliers::sync($vuota, []);
            CatalogDemo::create();
            $legami = ProductSuppliers::linksFor([$cambiata, $vuota]);

            return $cambiata > 0 && $vuota > 0
                && ($legami[$cambiata] ?? []) === [['supplier_id' => $nord, 'supplier_sku' => 'A-MANO', 'cost' => 8.0]]
                && ($legami[$vuota] ?? []) === [['supplier_id' => $nord, 'supplier_sku' => 'FN-TSH-01', 'cost' => 7.2]];
        });

        check('ogni opzione nasce con venti pezzi sulla sede principale, e l\'ultima di ogni articolo con due sotto la sua soglia', function () use ($idDi, $righe) {
            $sede = Locations::mainId();

            foreach (ARTICOLI as $ref) {
                $opzioni = $righe(Product::class, ['product_model_id' => $idDi(ProductModel::class, $ref)]);
                $ultima = count($opzioni) - 1;

                foreach ($opzioni as $indice => $opzione) {
                    $id = (int) $opzione['id'];
                    $pezzi = $indice === $ultima ? 2.0 : 20.0;
                    $livelli = Levels::byLocation($id);
                    $soglia = Thresholds::forProduct($id);
                    $avviso = Alerts::openRow($id);

                    // La giacenza è una riga per sede, e qui c'è solo la
                    // principale; la soglia pure, e ce l'ha solo l'ultima
                    // opzione, che nasce sotto e fa il suo avviso su quella
                    // sede.
                    $bene = (float) (Levels::of($id)['quantity'] ?? -1) === $pezzi
                        && $livelli === [$sede => ['quantity' => $pezzi, 'reserved' => 0.0, 'available' => $pezzi]]
                        && $soglia === ($indice === $ultima ? [$sede => 5.0] : [])
                        && ($indice === $ultima
                            ? $avviso !== []
                                && (int) $avviso['location_id'] === $sede
                                && (float) $avviso['threshold'] === 5.0
                                && (float) $avviso['quantity_at_alert'] === 2.0
                            : $avviso === []);

                    if (!$bene) {
                        echo "    giacenza o soglia diverse su {$ref}, opzione {$indice}\n";

                        return false;
                    }
                }
            }

            return true;
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

        check('la scheda tecnica ha composizione e lavaggio', function () use ($demo, $righe) {
            $composizione = $demo(Attribute::class, 'composizione');
            $lavaggio = $demo(Attribute::class, 'lavaggio');
            $valori = $righe(AttributeValue::class, ['attribute_id' => (int) ($lavaggio['id'] ?? 0)]);
            usort($valori, static fn (array $a, array $b): int => (int) $a['position'] <=> (int) $b['position']);

            // Stanno sull'articolo, si vedono in scheda e non filtrano niente.
            return ($composizione['level'] ?? '') === 'model'
                && ($composizione['type'] ?? '') === 'text'
                && ($composizione['is_visible'] ?? '') === 'true'
                && ($composizione['is_filterable'] ?? '') === 'false'
                && $righe(AttributeValue::class, ['attribute_id' => (int) ($composizione['id'] ?? 0)]) === []
                && ($lavaggio['level'] ?? '') === 'model'
                && ($lavaggio['type'] ?? '') === 'select'
                && ($lavaggio['is_visible'] ?? '') === 'true'
                && ($lavaggio['is_filterable'] ?? '') === 'false'
                && array_column($valori, 'label') === ['Lavaggio a 30°', 'Non candeggiare', 'Non asciugare in asciugatrice'];
        });

        check('un articolo ha la composizione e più simboli di lavaggio', function () use ($demo, $idDi, $righe) {
            $id = $idDi(ProductModel::class, 'maglietta-girocollo');
            $composizione = $demo(Attribute::class, 'composizione');
            $lavaggio = $demo(Attribute::class, 'lavaggio');
            $collegamenti = ProductAttributes::rows('model', $id);
            $testo = $collegamenti[(int) ($composizione['id'] ?? 0)] ?? [];
            $simboli = $collegamenti[(int) ($lavaggio['id'] ?? 0)] ?? [];

            $valori = [];
            foreach ($righe(AttributeValue::class, ['attribute_id' => (int) ($lavaggio['id'] ?? 0)]) as $valore) {
                $valori[(int) $valore['id']] = $valore;
            }

            $scheda = ProductAttributes::describe([$composizione, $lavaggio], $collegamenti, $valori);

            // Solo lei: gli altri articoli la scheda tecnica non la usano.
            $altri = ProductAttributes::rows('model', $idDi(ProductModel::class, 'felpa-con-cappuccio'));

            return $id > 0
                && count($testo) === 1
                && ($testo[0]['value_text'] ?? '') === 'Cotone 100%'
                && count($simboli) === 3
                && ($scheda['Composizione'] ?? '') === 'Cotone 100%'
                && ($scheda['Lavaggio'] ?? '') === 'Lavaggio a 30°, Non candeggiare, Non asciugare in asciugatrice'
                && !isset($altri[(int) ($composizione['id'] ?? 0)])
                && !isset($altri[(int) ($lavaggio['id'] ?? 0)]);
        });

        check('la scheda tecnica torna anche su un articolo di prova già nato', function () use ($demo, $idDi) {
            // Un sito con i dati di prova di prima: l'articolo c'è, la scheda
            // tecnica no. Rifare i dati di prova la aggiunge, una volta sola.
            $id = $idDi(ProductModel::class, 'maglietta-girocollo');
            $lavaggio = (int) ($demo(Attribute::class, 'lavaggio')['id'] ?? 0);
            $composizione = (int) ($demo(Attribute::class, 'composizione')['id'] ?? 0);

            foreach ([$lavaggio, $composizione] as $attributo) {
                foreach (ProductAttributes::rows('model', $id)[$attributo] ?? [] as $riga) {
                    ProductAttributes::modelClass('model')::delete((int) $riga['id']);
                }
            }

            $rifatte = CatalogDemo::create();
            $righe = ProductAttributes::rows('model', $id);

            return $rifatte === 4
                && count($righe[$lavaggio] ?? []) === 3
                && count($righe[$composizione] ?? []) === 1;
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


        check('crea due personalizzazioni: l\'incisione e la confezione con due opzioni', function () use ($demo, $idDi, $righe) {
            $incisione = $demo(Customization::class, 'incisione');
            $confezione = $demo(Customization::class, 'confezione-regalo');
            $opzioni = $righe(CustomizationOption::class, ['customization_id' => (int) ($confezione['id'] ?? 0)]);
            $etichette = array_map(static fn (array $o): string => (string) $o['label'], $opzioni);

            return $incisione !== [] && $confezione !== []
                && $incisione['kind'] === 'text' && (int) $incisione['max_length'] === 20
                && $incisione['surcharge'] === '5.00' && $incisione['label'] === 'Incisione'
                && $incisione['help_text'] === 'Fino a 20 caratteri'
                && $confezione['kind'] === 'choice' && $confezione['surcharge'] === '3.00'
                && $etichette === ['Rossa', 'Blu']
                && array_unique(array_map(static fn (array $o): string => (string) $o['surcharge'], $opzioni)) === ['0.00'];
        });

        check('le personalizzazioni sono collegate, facoltative, a due articoli di prova', function () use ($demo, $idDi, $righe) {
            $a = $righe(ProductModelCustomization::class, ['customization_id' => $idDi(Customization::class, 'incisione')]);
            $b = $righe(ProductModelCustomization::class, ['customization_id' => $idDi(Customization::class, 'confezione-regalo')]);

            return count($a) === 1 && count($b) === 1
                && (int) $a[0]['product_model_id'] === $idDi(ProductModel::class, 'maglietta-girocollo')
                && (int) $b[0]['product_model_id'] === $idDi(ProductModel::class, 'felpa-con-cappuccio')
                && $a[0]['is_required'] === 'false' && $b[0]['is_required'] === 'false';
        });

        check('crea tre multiprodotti: fisso, a scelta e misto, con la composizione attesa', function () use ($idDi, $righe) {
            $fisso = Bundles::forModel($idDi(ProductModel::class, 'cesto-degustazione'));
            $scelta = Bundles::forModel($idDi(ProductModel::class, 'cesto-componibile'));
            $misto = Bundles::forModel($idDi(ProductModel::class, 'cesto-completo'));
            $gruppo = static fn (array $b, int $i): array => $b['groups'][$i] ?? ['name' => '', 'min' => -1, 'max' => -1, 'options' => []];
            $extra = static fn (array $g): array => array_column($g['options'], 'surcharge');

            return $fisso['mode'] === 'fixed' && $fisso['show_value'] === true && $fisso['groups'] === []
                && array_column($fisso['components'], 'quantity') === [1.0, 2.0, 1.0]
                && $scelta['mode'] === 'choice' && $scelta['show_value'] === false && $scelta['components'] === []
                && count($scelta['groups']) === 2
                && $gruppo($scelta, 0)['name'] === 'Vino' && $gruppo($scelta, 0)['min'] === 1 && $gruppo($scelta, 0)['max'] === 1
                && $extra($gruppo($scelta, 0)) === ['0.00', '4.00']
                && $gruppo($scelta, 1)['name'] === 'Dolce' && $gruppo($scelta, 1)['min'] === 0 && $gruppo($scelta, 1)['max'] === 2
                && $extra($gruppo($scelta, 1)) === ['0.00', '0.00', '0.00']
                && $misto['mode'] === 'mixed' && $misto['show_value'] === false
                && $misto['components'] !== [] && $misto['groups'] !== [];
        });

        check('i multiprodotti di prova sono del tipo giusto, hanno un prezzo e si possono vendere', function () use ($idDi, $righe) {
            foreach (['cesto-degustazione', 'cesto-componibile', 'cesto-completo'] as $ref) {
                $modelId = $idDi(ProductModel::class, $ref);
                $modello = ProductModel::findById($modelId);
                $prodotti = $righe(Product::class, ['product_model_id' => $modelId]);

                if (($modello['type'] ?? '') !== 'bundle' || count($prodotti) !== 1
                    || (float) $prodotti[0]['price'] <= 0 || Bundles::available($modelId) <= 0) {
                    return false;
                }
            }

            return true;
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

        check('una personalizzazione di prova che un articolo vero usa ancora resta, e il comando lo dice', function () use ($demo, $idDi, $righe) {
            CatalogDemo::create();
            $incisione = $idDi(Customization::class, 'incisione');
            $vero = ProductModel::create([
                'code' => Code::make(ProductModel::class, Codes::MODEL),
                'name' => 'Articolo vero con incisione',
                'slug' => Slug::make('articolo-vero-con-incisione-'.uniqid()),
                'sku' => 'VERO-INC',
                'unit' => 'pz',
                'type' => 'simple',
                'visible' => 'false',
                'position' => 1,
            ]);
            $veroId = (int) ($vero->insert_id ?? 0);
            ProductModelCustomization::create([
                'product_model_id' => $veroId, 'customization_id' => $incisione, 'is_required' => 'false', 'position' => 1,
            ]);

            DemoData::notes();
            CatalogDemo::clear();
            $note = implode("\n", DemoData::notes());

            $ok = $veroId > 0 && $incisione > 0
                && (int) ($demo(Customization::class, 'incisione')['id'] ?? 0) === $incisione
                && $demo(Customization::class, 'confezione-regalo') === []
                && $righe(CustomizationOption::class, ['customization_id' => $idDi(Customization::class, 'confezione-regalo')]) === []
                && str_contains($note, 'personalizzazione «Incisione»');

            // Tolto il collegamento vero, la pulizia porta via anche l'incisione.
            foreach ($righe(ProductModelCustomization::class, ['customization_id' => $incisione]) as $link) {
                ProductModelCustomization::delete((int) $link['id']);
            }

            CatalogDemo::clear();
            DemoData::notes();

            return $ok && $demo(Customization::class, 'incisione') === [];
        });

        check('un componente di prova che un multiprodotto vero usa ancora resta, con la sua giacenza, e il comando lo dice', function () use ($demo, $idDi, $righe) {
            CatalogDemo::create();
            $cappello = $idDi(ProductModel::class, 'cappello-di-lana');
            $prodotto = (int) ($righe(Product::class, ['product_model_id' => $cappello])[0]['id'] ?? 0);
            $prima = Levels::of($prodotto)['available'];
            $vero = ProductModel::create([
                'code' => Code::make(ProductModel::class, Codes::MODEL),
                'name' => 'Multiprodotto vero col cappello',
                'slug' => Slug::make('multiprodotto-vero-'.uniqid()),
                'sku' => 'VERO-BND',
                'unit' => 'pz',
                'type' => 'bundle',
                'bundle_mode' => 'fixed',
                'visible' => 'false',
                'position' => 1,
            ]);
            $veroId = (int) ($vero->insert_id ?? 0);
            $componente = (int) (BundleComponent::create([
                'product_model_id' => $veroId, 'product_id' => $prodotto, 'quantity' => 1, 'position' => 1,
            ])->insert_id ?? 0);

            DemoData::notes();
            CatalogDemo::clear();
            $note = implode("\n", DemoData::notes());

            $ok = $cappello > 0 && $prodotto > 0 && $componente > 0
                && $demo(ProductModel::class, 'cappello-di-lana') !== []
                && Levels::of($prodotto)['available'] === $prima
                && $demo(ProductModel::class, 'cesto-degustazione') === []
                && $demo(ProductModel::class, 'cesto-componibile') === []
                && $demo(ProductModel::class, 'maglietta-girocollo') === []
                && str_contains($note, 'articolo «Cappello Di Lana»');

            // Tolto il componente vero, la pulizia porta via anche il cappello.
            BundleComponent::delete($componente);
            CatalogDemo::clear();
            DemoData::notes();

            return $ok && $demo(ProductModel::class, 'cappello-di-lana') === [];
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
} finally {
    $funzionalita->setValue(null, $funzionalitaPrima);
}

check('dopo l\'annullamento il catalogo è come prima', fn () => $stato() === $prima);

// Le righe sono tornate con l'annullamento; i file li rimette l'istantanea.
// Qui e non alla fine del processo, perché il check qui sotto guarda il disco.
$foto->ripristina();

check('il sito resta con foto vere sul disco', function () use ($fotoRotte, $fotoRottePrima) {
    $rotte = array_values(array_diff($fotoRotte(), $fotoRottePrima));

    if ($rotte !== []) {
        // Dire quali: una riga che punta a un file sparito non si trova a
        // occhio fra cinquanta.
        echo '    righe senza file: '.implode(', ', $rotte)."\n";
    }

    return $rotte === [];
});

summary();
