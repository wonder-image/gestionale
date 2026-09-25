<?php
/** php tests/integrazione/ProductSuppliersTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;
use Wonder\Plugin\Gestionale\Seeding\ContactsDemo;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Purchasing\ProductSuppliers;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

// Lo stato delle funzionalità si forza senza toccare il database; `null` lo
// fa rileggere.
$forza = static function (?array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato);
};

// Quante righe ha la tabella dei legami, cestino compreso.
$conta = static function (): int {
    return (int) ProductSupplier::query()->Count(ProductSupplier::$table);
};

// I legami di un'opzione così come sono nel database, cestino compreso.
$legami = static function (int $productId): array {
    $righe = ProductSupplier::find(['product_id' => $productId, 'deleted' => ['true', 'false']], null, 'position', 'ASC');

    return isset($righe['id']) ? [$righe] : array_values(array_filter((array) $righe, 'is_array'));
};

$prima = $conta();

try {
    Transaction::run(static function () use ($legami, $forza): void {
        // Un articolo senza varianti: il modello e la sua unica opzione.
        $articolo = static function (string $nome): array {
            $modello = ProductModel::create([
                'code' => Code::make(ProductModel::class, Codes::MODEL),
                'name' => $nome,
                'slug' => Slug::make('prova-fornitori-'.uniqid()),
                'sku' => 'TST-FRN-'.strtoupper(substr(uniqid(), -6)),
                'unit' => 'pz',
                'type' => 'simple',
                'visible' => 'true',
                'position' => 1,
            ]);
            $modelId = (int) ($modello->insert_id ?? 0);

            return [$modelId, (int) Skeleton::forModel($modelId, $nome)['product_id']];
        };

        $fornitore = static function (string $nome): int {
            $creato = Contact::create([
                'type' => 'business',
                'business_name' => $nome,
                'country' => 'IT',
                'is_customer' => 'false',
                'is_supplier' => 'true',
                'active' => 'true',
            ]);

            return (int) ($creato->insert_id ?? 0);
        };

        // Dritto sulla tabella, come fa l'elenco: `deleted` non è un campo
        // del modello.
        $nelCestino = static function (string $modelClass, int $id): void {
            $modelClass::query()->Update($modelClass::$table, ['deleted' => 'true'], 'id', $id);
        };

        [$modelId, $productId] = $articolo('Prova fornitori');
        $nord = $fornitore('Prova Filati Nord Srl');
        $sud = $fornitore('Prova Imballaggi Sud Srl');

        check('i legami nascono nell\'ordine della pagina, con un preferito solo', function () use ($productId, $nord, $sud, $legami) {
            $quanti = ProductSuppliers::sync($productId, [
                ['supplier_id' => $nord, 'supplier_sku' => 'FN-12', 'cost' => '12,50', 'is_preferred' => 'false'],
                ['supplier_id' => $sud, 'supplier_sku' => '', 'cost' => '', 'is_preferred' => 'false'],
            ]);
            $righe = $legami($productId);

            return $quanti === 2
                && array_map('intval', array_column($righe, 'supplier_id')) === [$nord, $sud]
                && array_map('intval', array_column($righe, 'position')) === [0, 1]
                && array_column($righe, 'is_preferred') === ['true', 'false']
                && $righe[0]['supplier_sku'] === 'FN-12'
                && (float) $righe[0]['cost'] === 12.5
                // Vuoto vuol dire «non lo so»: NULL, non zero.
                && $righe[1]['cost'] === null;
        });

        check('un secondo salvataggio aggiorna le righe, non ne crea altre', function () use ($productId, $nord, $sud, $legami) {
            $ids = array_column($legami($productId), 'id', 'supplier_id');

            ProductSuppliers::sync($productId, [
                ['supplier_id' => $sud, 'supplier_sku' => 'IS-7', 'cost' => '3', 'is_preferred' => 'true'],
                ['supplier_id' => $nord, 'supplier_sku' => 'FN-12', 'cost' => '', 'is_preferred' => 'false'],
            ]);
            $righe = $legami($productId);

            return count($righe) === 2
                && array_column($righe, 'id', 'supplier_id') == $ids
                && array_map('intval', array_column($righe, 'supplier_id')) === [$sud, $nord]
                && array_column($righe, 'is_preferred') === ['true', 'false']
                && $righe[0]['supplier_sku'] === 'IS-7'
                && $righe[1]['cost'] === null;
        });

        check('i legami si rileggono per opzione, in ordine', function () use ($productId, $nord, $sud) {
            return ProductSuppliers::linksFor([$productId]) === [
                $productId => [
                    ['supplier_id' => $sud, 'supplier_sku' => 'IS-7', 'cost' => 3.0, 'is_preferred' => 'true'],
                    ['supplier_id' => $nord, 'supplier_sku' => 'FN-12', 'cost' => null, 'is_preferred' => 'false'],
                ],
            ];
        });

        check('un fornitore tolto dalla pagina se ne va davvero', function () use ($productId, $nord, $legami) {
            ProductSuppliers::sync($productId, [
                ['supplier_id' => $nord, 'supplier_sku' => 'FN-12', 'cost' => '11', 'is_preferred' => 'false'],
            ]);
            $righe = $legami($productId);

            // Nessuna riga nel cestino: terrebbe ferma la chiave esterna.
            return count($righe) === 1
                && (int) $righe[0]['supplier_id'] === $nord
                && $righe[0]['is_preferred'] === 'true';
        });

        check('un fornitore conta le opzioni in vendita che lo usano', fn () =>
            ProductSuppliers::countForSupplier($nord) === 1
            && ProductSuppliers::countForSupplier($sud) === 0
        );

        check('la pulizia dei dati di prova tiene i fornitori usati dalle opzioni', function () use ($nord, $sud) {
            $usato = new ReflectionMethod(ContactsDemo::class, 'usedAsSupplier');

            return $usato->invoke(null, $nord) === true && $usato->invoke(null, $sud) === false;
        });

        check('un\'opzione nel cestino non tiene fermo il fornitore', function () use ($productId, $nord, $nelCestino, $legami) {
            $nelCestino(Product::class, $productId);

            return ProductSuppliers::countForSupplier($nord) === 0
                // Il legame c'è ancora: lo toglie chi elimina.
                && count($legami($productId)) === 1;
        });

        check('eliminando un fornitore se ne vanno i legami con opzioni tolte', function () use ($productId, $nord, $legami) {
            return ProductSuppliers::dropForRemovedProducts($nord) === 1
                && $legami($productId) === [];
        });

        [$altroModello, $altraOpzione] = $articolo('Prova fornitori, articolo nel cestino');

        check('neanche un articolo nel cestino tiene fermo il fornitore', function () use ($altroModello, $altraOpzione, $sud, $nelCestino) {
            ProductSuppliers::sync($altraOpzione, [['supplier_id' => $sud, 'cost' => '2']]);
            $inVendita = ProductSuppliers::countForSupplier($sud);

            // L'elenco degli articoli mette nel cestino il modello, non le
            // sue opzioni.
            $nelCestino(ProductModel::class, $altroModello);

            return $inVendita === 1
                && ProductSuppliers::countForSupplier($sud) === 0
                && ProductSuppliers::dropForRemovedProducts($sud) === 1;
        });

        [$terzoModello, $terzaOpzione] = $articolo('Prova fornitori, griglia');

        check('un\'opzione tolta dalla griglia perde i suoi legami, le altre no', function () use ($terzoModello, $terzaOpzione, $productId, $nord, $sud, $nelCestino, $legami) {
            ProductSuppliers::sync($terzaOpzione, [['supplier_id' => $sud, 'cost' => '2']]);
            // Quella del primo articolo è già nel cestino: non è di questo
            // modello, e resta.
            ProductSuppliers::sync($productId, [['supplier_id' => $nord, 'cost' => '1']]);
            $inVendita = ProductSuppliers::dropRemovedOptions($terzoModello);

            $nelCestino(Product::class, $terzaOpzione);

            return $inVendita === 0
                && ProductSuppliers::dropRemovedOptions($terzoModello) === 1
                && $legami($terzaOpzione) === []
                && count($legami($productId)) === 1;
        });

        check('chi elimina un\'opzione toglie prima i suoi legami', function () use ($productId, $legami) {
            return ProductSuppliers::dropFor([$productId]) === 1
                && $legami($productId) === []
                && ProductSuppliers::dropFor([$productId]) === 0;
        });

        check('una pagina senza righe toglie tutti i legami', function () use ($altraOpzione, $nord, $legami) {
            ProductSuppliers::sync($altraOpzione, [['supplier_id' => $nord, 'cost' => '5']]);

            return ProductSuppliers::sync($altraOpzione, []) === 0 && $legami($altraOpzione) === [];
        });

        // La scheda dell'opzione: il riquadro «Fornitori» salva con il
        // repeater del core, non con `sync()`, e deve arrivare allo stesso
        // posto.
        [, $scheda] = $articolo('Prova fornitori, scheda dell\'opzione');
        [, $altraScheda] = $articolo('Prova fornitori, scheda senza legami');
        $spento = $fornitore('Prova Vernici Ferme Srl');
        Contact::update(['active' => 'false'], $spento);

        // Il riquadro c'è solo con gli acquisti: si accendono sopra a quello
        // che dice il database.
        $acquisti = static function () use ($forza): void {
            $forza([...Gestionale::features(), 'purchasing' => true]);
        };

        // La chiave dell'errore con cui la scheda rifiuta le righe, '' se le
        // accetta.
        $rifiuto = static function (int $productId, array $righe) use ($acquisti, $forza): string {
            $acquisti();
            $_POST['suppliers'] = $righe;

            try {
                ProductResource::mutateRequestValues(['price' => '1,00'], 'update', 'backend', ['id' => $productId]);
            } catch (UserError $errore) {
                return $errore->key();
            } finally {
                unset($_POST['suppliers']);
                $forza(null);
            }

            return '';
        };

        // Come fa il core dopo aver salvato l'opzione.
        $salva = static function (int $productId, array $righe) use ($acquisti, $forza): void {
            $acquisti();

            try {
                ProductResource::syncRepeaterRelations($productId, ['suppliers' => $righe], [], 'update', 'backend');
            } finally {
                $forza(null);
            }
        };

        // Le righe come le trova la scheda riaprendola.
        $riapri = static function (int $productId) use ($acquisti, $forza): array {
            $acquisti();

            try {
                $valori = ProductResource::hydrateRepeaterFormValues(['id' => $productId], $productId, [], []);
                $valori = ProductResource::mutateFormValues($valori, 'edit');
            } finally {
                $forza(null);
            }

            return (array) ($valori['suppliers'] ?? []);
        };

        check('la scheda salva le righe in ordine, con un preferito solo e il costo vuoto a NULL', function () use ($scheda, $nord, $sud, $salva, $legami) {
            $salva($scheda, [
                ['id' => '', 'supplier_id' => (string) $nord, 'supplier_sku' => ' FN-12 ', 'cost' => '12,50', 'is_preferred' => 'false'],
                ['id' => '', 'supplier_id' => (string) $sud, 'supplier_sku' => '', 'cost' => '', 'is_preferred' => 'false'],
                // Aggiunta e lasciata vuota: non è un legame.
                ['id' => '', 'supplier_id' => '', 'supplier_sku' => '', 'cost' => '', 'is_preferred' => 'false'],
            ]);
            $righe = $legami($scheda);

            return array_map('intval', array_column($righe, 'supplier_id')) === [$nord, $sud]
                && array_map('intval', array_column($righe, 'position')) === [0, 1]
                && array_column($righe, 'is_preferred') === ['true', 'false']
                && array_column($righe, 'deleted') === ['false', 'false']
                && $righe[0]['supplier_sku'] === 'FN-12'
                && (float) $righe[0]['cost'] === 12.5
                && $righe[1]['cost'] === null
                // Le legge anche la griglia dell'articolo.
                && count(ProductSuppliers::linksFor([$scheda])[$scheda] ?? []) === 2;
        });

        check('riaprendo la scheda il costo ha due decimali, e vuoto resta vuoto', function () use ($scheda, $riapri) {
            $righe = $riapri($scheda);

            return array_column($righe, 'cost') === ['12.50', '']
                && array_column($righe, 'is_preferred') === ['true', 'false'];
        });

        check('salvare la scheda senza toccarla non arrotonda un costo con quattro decimali', function () use ($scheda, $nord, $sud, $salva, $riapri, $legami) {
            ProductSuppliers::sync($scheda, [
                ['supplier_id' => $nord, 'supplier_sku' => 'FN-12', 'cost' => '12.3456', 'is_preferred' => 'true'],
                ['supplier_id' => $sud, 'supplier_sku' => '', 'cost' => '', 'is_preferred' => 'false'],
            ]);
            $ids = array_column($legami($scheda), 'id');

            // Il form posta quello che ha mostrato: «12.35».
            $mostrate = $riapri($scheda);
            $salva($scheda, $mostrate);
            $righe = $legami($scheda);

            return $mostrate[0]['cost'] === '12.35'
                && array_column($righe, 'id') === $ids
                && (string) $righe[0]['cost'] === '12.3456'
                && $righe[1]['cost'] === null;
        });

        check('scegliere un altro preferito lo sposta, anche se il vecchio resta su «Sì»', function () use ($scheda, $salva, $riapri, $legami) {
            $righe = $riapri($scheda);
            $righe[1]['is_preferred'] = 'true';
            $salva($scheda, $righe);

            return array_column($legami($scheda), 'is_preferred') === ['false', 'true'];
        });

        check('una riga tolta dalla scheda se ne va davvero', function () use ($scheda, $sud, $salva, $riapri, $legami) {
            $salva($scheda, [$riapri($scheda)[1]]);
            $righe = $legami($scheda);

            return count($righe) === 1 && (int) $righe[0]['supplier_id'] === $sud && $righe[0]['is_preferred'] === 'true';
        });

        check('la scheda rifiuta un fornitore che non propone e i doppioni', fn () =>
            $rifiuto($scheda, [['id' => '', 'supplier_id' => (string) $spento, 'cost' => '1,00', 'is_preferred' => 'false']]) === 'product.supplier_invalid'
            && $rifiuto($scheda, [
                ['id' => '', 'supplier_id' => (string) $nord, 'cost' => '1,00', 'is_preferred' => 'false'],
                ['id' => '', 'supplier_id' => (string) $nord, 'supplier_sku' => 'X', 'cost' => '', 'is_preferred' => 'false'],
            ]) === 'product.supplier_duplicate'
            && $rifiuto($scheda, [['id' => '', 'supplier_id' => '', 'supplier_sku' => 'FN-12', 'cost' => '', 'is_preferred' => 'false']]) === 'product.supplier_missing'
            && $rifiuto($scheda, [['id' => '', 'supplier_id' => (string) $nord, 'cost' => '-1', 'is_preferred' => 'false']]) === 'product.supplier_cost_negative'
        );

        check('un fornitore messo su «Non attivo» resta nella scelta delle opzioni che lo usano, e solo di quelle', function () use ($scheda, $altraScheda, $spento, $rifiuto) {
            ProductSuppliers::sync($scheda, [['supplier_id' => $spento, 'cost' => '2']]);
            $riga = [['id' => '', 'supplier_id' => (string) $spento, 'cost' => '2,00', 'is_preferred' => 'true']];

            return $rifiuto($scheda, $riga) === ''
                && $rifiuto($altraScheda, $riga) === 'product.supplier_invalid';
        });

        // Il repeater aggiorna per id e riscrive l'opzione: un id che non è di
        // questa opzione non deve portare via il legame di un'altra, e uno che
        // non c'è più non deve lasciarla senza fornitori.
        check('un id di riga che non è di questa opzione diventa un legame nuovo', function () use ($scheda, $altraScheda, $nord, $sud, $salva, $riapri, $legami) {
            ProductSuppliers::sync($altraScheda, [['supplier_id' => $nord, 'cost' => '3']]);
            $altrui = (string) $legami($altraScheda)[0]['id'];

            // Un form copiato o ritoccato.
            $salva($scheda, [['id' => $altrui, 'supplier_id' => (string) $sud, 'supplier_sku' => '', 'cost' => '4,00', 'is_preferred' => 'true']]);
            $qui = $legami($scheda);
            $li = $legami($altraScheda);

            // Una scheda rimasta aperta mentre la finestra dei costi
            // dell'articolo cambiava il fornitore: vince chi salva per ultimo.
            $mostrate = $riapri($scheda);
            ProductSuppliers::sync($scheda, [['supplier_id' => $nord, 'cost' => '5']]);
            $salva($scheda, $mostrate);
            $dopo = $legami($scheda);

            return count($li) === 1 && (string) $li[0]['id'] === $altrui && (int) $li[0]['supplier_id'] === $nord
                && count($qui) === 1 && (int) $qui[0]['supplier_id'] === $sud && (string) $qui[0]['id'] !== $altrui
                && count($dopo) === 1 && (int) $dopo[0]['supplier_id'] === $sud;
        });

        check('una scheda svuotata toglie tutti i legami', function () use ($scheda, $salva, $legami) {
            $salva($scheda, [['id' => '', 'supplier_id' => '', 'supplier_sku' => '', 'cost' => '', 'is_preferred' => 'false']]);

            return $legami($scheda) === [];
        });

        check('eliminare un\'opzione porta via i suoi fornitori', function () use ($altraScheda, $nord, $legami) {
            ProductSuppliers::sync($altraScheda, [['supplier_id' => $nord, 'cost' => '3']]);
            $esito = ProductResource::deleteRecord($altraScheda);

            return !empty($esito->success) && $legami($altraScheda) === [];
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento i legami sono come prima', fn () => $conta() === $prima);

summary();
