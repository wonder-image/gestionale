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
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

// Lo stato delle funzionalità si forza senza toccare il database; `null` lo
// fa rileggere.
$forza = static function (?array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato);
    Locations::reset();
};

// Quante righe ha la tabella dei fornitori delle opzioni (P107), cestino
// compreso.
$conta = static fn (): int => (int) ProductSupplier::query()->Count(ProductSupplier::$table);

// I fornitori di un'opzione così come sono nel database, cestino compreso.
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

        [, $productId] = $articolo('Prova fornitori');
        $nord = $fornitore('Prova Filati Nord Srl');
        $sud = $fornitore('Prova Imballaggi Sud Srl');

        check('i fornitori di un\'opzione nascono nell\'ordine della pagina', function () use ($productId, $nord, $sud, $legami) {
            $quanti = ProductSuppliers::sync($productId, [
                ['supplier_id' => $nord, 'supplier_sku' => 'FN-12', 'cost' => '12,50'],
                ['supplier_id' => $sud, 'supplier_sku' => '', 'cost' => ''],
            ]);
            $righe = $legami($productId);

            return $quanti === 2
                && array_map('intval', array_column($righe, 'supplier_id')) === [$nord, $sud]
                && array_map('intval', array_column($righe, 'position')) === [0, 1]
                && $righe[0]['supplier_sku'] === 'FN-12'
                && (float) $righe[0]['cost'] === 12.5
                // Vuoto vuol dire «non lo so»: NULL, non zero.
                && $righe[1]['cost'] === null;
        });

        check('un secondo salvataggio aggiorna le righe, non ne crea altre', function () use ($productId, $nord, $sud, $legami) {
            $ids = array_column($legami($productId), 'id', 'supplier_id');

            ProductSuppliers::sync($productId, [
                ['supplier_id' => $sud, 'supplier_sku' => 'IS-7', 'cost' => '3'],
                ['supplier_id' => $nord, 'supplier_sku' => 'FN-12', 'cost' => ''],
            ]);
            $righe = $legami($productId);

            return count($righe) === 2
                && array_column($righe, 'id', 'supplier_id') == $ids
                && array_map('intval', array_column($righe, 'supplier_id')) === [$sud, $nord]
                && $righe[0]['supplier_sku'] === 'IS-7'
                && $righe[1]['cost'] === null;
        });

        check('i fornitori si rileggono per opzione, in ordine', function () use ($productId, $nord, $sud) {
            return ProductSuppliers::linksFor([$productId]) === [
                $productId => [
                    ['supplier_id' => $sud, 'supplier_sku' => 'IS-7', 'cost' => 3.0],
                    ['supplier_id' => $nord, 'supplier_sku' => 'FN-12', 'cost' => null],
                ],
            ];
        });

        check('un fornitore tolto dalla pagina se ne va davvero', function () use ($productId, $nord, $legami) {
            ProductSuppliers::sync($productId, [
                ['supplier_id' => $nord, 'supplier_sku' => 'FN-12', 'cost' => '11'],
            ]);
            $righe = $legami($productId);

            // Nessuna riga nel cestino: terrebbe ferma la chiave esterna.
            return count($righe) === 1
                && (int) $righe[0]['supplier_id'] === $nord;
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

        check('neanche un articolo nel cestino tiene fermo il fornitore', function () use ($altroModello, $altraOpzione, $sud, $nelCestino, $legami) {
            ProductSuppliers::sync($altraOpzione, [['supplier_id' => $sud, 'cost' => '2']]);
            $inVendita = ProductSuppliers::countForSupplier($sud);

            // L'elenco degli articoli mette nel cestino il modello, non le
            // sue opzioni.
            $nelCestino(ProductModel::class, $altroModello);

            return $inVendita === 1
                && ProductSuppliers::countForSupplier($sud) === 0
                && ProductSuppliers::dropForRemovedProducts($sud) === 1
                && $legami($altraOpzione) === []
                // Tolti una volta, non ce ne sono altri.
                && ProductSuppliers::dropForRemovedProducts($sud) === 0;
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

        // --- La scheda dell'opzione (P112): i due campi del fornitore unico,
        // o il JSON che la finestra «Fornitori» scrive nel campo nascosto.
        // `mutateRequestValues()` controlla, `afterUpdate()` scrive.
        [, $scheda] = $articolo('Prova fornitori, scheda dell\'opzione');
        [, $altraScheda] = $articolo('Prova fornitori, scheda senza legami');

        // I fornitori del sito si mettono da parte, e per ora anche il sud:
        // da quanti ne propone la scheda dipende come si compilano, e la
        // prova conta i suoi.
        Contact::query()->Update(Contact::$table, ['active' => 'false'], 'is_supplier', 'true');
        Contact::update(['active' => 'true'], $nord);
        $spento = $fornitore('Prova Vernici Ferme Srl');
        Contact::update(['active' => 'false'], $spento);

        // Esegue la prova come una richiesta della scheda: gli acquisti
        // accesi, o spenti, sopra a quello che dice il database, una sede
        // sola e il form arrivato in `$_POST`.
        $richiesta = static function (array $post, callable $prova, bool $acquisti = true) use ($forza): mixed {
            $forza([...Gestionale::features(), 'purchasing' => $acquisti, 'multi_location' => false]);
            $arrivato = $_POST;
            $_POST = $post;
            // I fornitori proposti si leggono una volta per richiesta.
            ProductResource::forgetCatalogCache();

            try {
                return $prova();
            } finally {
                $_POST = $arrivato;
                $forza(null);
                ProductResource::forgetCatalogCache();
            }
        };

        // La chiave dell'errore con cui la scheda rifiuta il form, '' se lo
        // accetta.
        $rifiuto = static fn (int $productId, array $post, bool $acquisti = true): string => $richiesta(
            $post,
            static function () use ($productId): string {
                try {
                    ProductResource::mutateRequestValues(['price' => '1,00'], 'update', 'backend', ['id' => $productId]);
                } catch (UserError $errore) {
                    return $errore->key();
                }

                return '';
            },
            $acquisti
        );

        // Come fa il core: il controllo, poi — salvata l'opzione — il resto.
        $salva = static fn (int $productId, array $post, bool $acquisti = true): mixed => $richiesta(
            $post,
            static function () use ($productId): void {
                $valori = ProductResource::mutateRequestValues(['price' => '1,00'], 'update', 'backend', ['id' => $productId]);
                ProductResource::afterUpdate($productId, (object) ['success' => true], $valori);
            },
            $acquisti
        );

        // Quello che il form riceve riaprendo la scheda.
        $riapri = static fn (int $productId, bool $acquisti = true): array => $richiesta(
            [],
            static fn (): array => ProductResource::mutateFormValues(
                ProductResource::hydrateRepeaterFormValues(['id' => $productId], $productId, [], []),
                'edit'
            ),
            $acquisti
        );

        // Due campi, finestra o niente: il modo della scheda di un'opzione.
        $modo = static fn (int $productId, bool $acquisti = true): ?string => $richiesta(
            [],
            static fn (): ?string => (new ReflectionMethod(ProductResource::class, 'supplierMode'))->invoke(null, $productId),
            $acquisti
        );

        $json = static fn (array $righe): string => (string) json_encode($righe);

        /** Fornitore, codice e costo di ogni legame, in ordine. */
        $terne = static fn (int $productId): array => array_map(static fn (array $row): array => [
            (int) $row['supplier_id'],
            (string) $row['supplier_sku'],
            $row['cost'] === null ? null : round((float) $row['cost'], 4),
        ], $legami($productId));

        check('con un fornitore solo la scheda ha i due campi, e salva codice e costo', function () use ($scheda, $nord, $salva, $riapri, $modo, $terne) {
            $salva($scheda, ['supplier_sku' => ' FN-12 ', 'supplier_cost' => '12,50']);
            $salvati = $terne($scheda);
            $valori = $riapri($scheda);

            // Solo il codice: il costo che non si sa resta NULL.
            $salva($scheda, ['supplier_sku' => 'FN-13', 'supplier_cost' => '']);

            return $modo($scheda) === 'flat'
                && $salvati === [[$nord, 'FN-12', 12.5]]
                && ($valori['supplier_sku'] ?? null) === 'FN-12'
                // Grezzo col punto e due decimali: cifre e valuta le mette
                // la casella.
                && ($valori['supplier_cost'] ?? null) === '12.50'
                && !array_key_exists('suppliers', $valori)
                && $terne($scheda) === [[$nord, 'FN-13', null]]
                // Li legge anche la griglia dell'articolo.
                && count(ProductSuppliers::linksFor([$scheda])[$scheda] ?? []) === 1;
        });

        check('i due campi non arrotondano un costo con quattro decimali, e vuoti tolgono il fornitore', function () use ($scheda, $nord, $salva, $riapri, $legami, $terne) {
            ProductSuppliers::sync($scheda, [['supplier_id' => $nord, 'supplier_sku' => 'FN-12', 'cost' => '12.3456']]);
            $id = $legami($scheda)[0]['id'] ?? null;

            // Il form posta quello che ha mostrato: «12.35».
            $valori = $riapri($scheda);
            $salva($scheda, ['supplier_sku' => $valori['supplier_sku'], 'supplier_cost' => $valori['supplier_cost']]);
            $tenuto = $legami($scheda);

            // Una scheda che i due campi non li manda non tocca niente.
            $salva($scheda, []);
            $intatto = $terne($scheda);

            $salva($scheda, ['supplier_sku' => '', 'supplier_cost' => '']);

            return ($valori['supplier_cost'] ?? null) === '12.35'
                && count($tenuto) === 1
                && $tenuto[0]['id'] === $id
                && (string) $tenuto[0]['cost'] === '12.3456'
                && $intatto === [[$nord, 'FN-12', 12.3456]]
                && $legami($scheda) === [];
        });

        check('i due campi si fermano su un costo o un codice che non reggono', fn () =>
            $rifiuto($scheda, ['supplier_sku' => 'A', 'supplier_cost' => '1,50']) === ''
            && $rifiuto($scheda, ['supplier_sku' => '', 'supplier_cost' => '']) === ''
            && $rifiuto($scheda, ['supplier_sku' => 'A', 'supplier_cost' => '-1']) === 'product.supplier_cost_negative'
            && $rifiuto($scheda, ['supplier_sku' => 'A', 'supplier_cost' => 'caro']) === 'product.supplier_cost_invalid'
            && $rifiuto($scheda, ['supplier_sku' => str_repeat('A', 300), 'supplier_cost' => '']) === 'product.supplier_sku_too_long'
        );

        // Il modo è dell'opzione, non dell'articolo: un fornitore non più
        // attivo si propone solo a chi lo usa già (P92).
        check('un fornitore non attivo già legato porta la finestra su quell\'opzione, e solo su quella', function () use ($scheda, $altraScheda, $spento, $modo, $legami) {
            ProductSuppliers::sync($scheda, [['supplier_id' => $spento, 'cost' => '2']]);
            $sua = $modo($scheda);
            $altra = $modo($altraScheda);
            ProductSuppliers::sync($scheda, []);

            return $sua === 'modal'
                && $altra === 'flat'
                && $legami($scheda) === [];
        });

        // --- Due fornitori o più: il bottone e la finestra.
        Contact::update(['active' => 'true'], $sud);

        check('con più fornitori la scheda salva quelli della finestra, in ordine, e il costo vuoto a NULL', function () use ($scheda, $nord, $sud, $salva, $modo, $legami, $json) {
            $salva($scheda, ['suppliers' => $json([
                ['supplier_id' => (string) $nord, 'supplier_sku' => ' FN-12 ', 'cost' => '12,50'],
                ['supplier_id' => (string) $sud, 'supplier_sku' => '', 'cost' => ''],
                // Aggiunta e lasciata vuota: non è un legame.
                ['supplier_id' => '', 'supplier_sku' => '', 'cost' => ''],
            ])]);
            $righe = $legami($scheda);

            return $modo($scheda) === 'modal'
                && array_map('intval', array_column($righe, 'supplier_id')) === [$nord, $sud]
                && array_map('intval', array_column($righe, 'position')) === [0, 1]
                && array_column($righe, 'deleted') === ['false', 'false']
                && $righe[0]['supplier_sku'] === 'FN-12'
                && (float) $righe[0]['cost'] === 12.5
                && $righe[1]['cost'] === null
                && count(ProductSuppliers::linksFor([$scheda])[$scheda] ?? []) === 2;
        });

        check('riaprendo la scheda la finestra ha le righe salvate, e il bottone il riassunto', function () use ($scheda, $nord, $sud, $riapri) {
            $valori = $riapri($scheda);
            $righe = ProductSuppliers::fromJson($valori['suppliers'] ?? null) ?? [];

            return array_map('intval', array_column($righe, 'supplier_id')) === [$nord, $sud]
                && array_column($righe, 'supplier_sku') === ['FN-12', '']
                && array_column($righe, 'cost') === ['12.50', '']
                && ($valori['suppliers_button'] ?? null) === 'Prova Filati Nord Srl 12,50 € · Prova Imballaggi Sud Srl'
                && !array_key_exists('supplier_sku', $valori);
        });

        check('salvare la scheda senza toccarla non arrotonda un costo con quattro decimali', function () use ($scheda, $nord, $sud, $salva, $riapri, $legami) {
            ProductSuppliers::sync($scheda, [
                ['supplier_id' => $nord, 'supplier_sku' => 'FN-12', 'cost' => '12.3456'],
                ['supplier_id' => $sud, 'supplier_sku' => '', 'cost' => ''],
            ]);
            $ids = array_column($legami($scheda), 'id');

            // Il form posta quello che ha mostrato: «12.35».
            $mostrato = (string) ($riapri($scheda)['suppliers'] ?? '');
            $salva($scheda, ['suppliers' => $mostrato]);
            $righe = $legami($scheda);

            // Finestra mai aperta: il campo arriva vuoto, e non tocca niente.
            $salva($scheda, ['suppliers' => '']);

            return (ProductSuppliers::fromJson($mostrato)[0]['cost'] ?? null) === '12.35'
                && array_column($righe, 'id') === $ids
                && (string) $righe[0]['cost'] === '12.3456'
                && $righe[1]['cost'] === null
                && array_column($legami($scheda), 'id') === $ids;
        });

        check('una riga tolta dalla finestra se ne va davvero', function () use ($scheda, $sud, $salva, $legami, $json) {
            $salva($scheda, ['suppliers' => $json([['supplier_id' => $sud, 'supplier_sku' => '', 'cost' => '']])]);
            $righe = $legami($scheda);

            return count($righe) === 1 && (int) $righe[0]['supplier_id'] === $sud;
        });

        check('la scheda rifiuta un fornitore che non propone e i doppioni', fn () =>
            $rifiuto($scheda, ['suppliers' => $json([['supplier_id' => $nord, 'supplier_sku' => 'N', 'cost' => '1,00']])]) === ''
            && $rifiuto($scheda, ['suppliers' => '[]']) === ''
            && $rifiuto($scheda, ['suppliers' => $json([['supplier_id' => $spento, 'cost' => '1,00']])]) === 'product.supplier_invalid'
            && $rifiuto($scheda, ['suppliers' => $json([
                ['supplier_id' => $nord, 'cost' => '1,00'],
                ['supplier_id' => $nord, 'supplier_sku' => 'X', 'cost' => ''],
            ])]) === 'product.supplier_duplicate'
            && $rifiuto($scheda, ['suppliers' => $json([['supplier_id' => '', 'supplier_sku' => 'FN-12', 'cost' => '']])]) === 'product.supplier_missing'
            && $rifiuto($scheda, ['suppliers' => $json([['supplier_id' => $nord, 'cost' => '-1']])]) === 'product.supplier_cost_negative'
            // I due campi, rimasti da una scheda aperta quando il fornitore
            // era uno: compilati non si sa di chi siano.
            && $rifiuto($scheda, ['supplier_sku' => 'A', 'supplier_cost' => '1']) === 'product.supplier_invalid'
            && $rifiuto($scheda, ['supplier_sku' => '', 'supplier_cost' => '']) === ''
        );

        check('un fornitore messo su «Non attivo» resta nella scelta delle opzioni che lo usano, e solo di quelle', function () use ($scheda, $altraScheda, $sud, $spento, $rifiuto, $salva, $terne, $json) {
            ProductSuppliers::sync($scheda, [['supplier_id' => $spento, 'cost' => '2']]);
            $sue = ['suppliers' => $json([
                ['supplier_id' => $spento, 'supplier_sku' => 'VF-1', 'cost' => '2,00'],
                ['supplier_id' => $sud, 'supplier_sku' => '', 'cost' => '3'],
            ])];
            $accettata = $rifiuto($scheda, $sue);
            $salva($scheda, $sue);

            return $accettata === ''
                && $rifiuto($altraScheda, $sue) === 'product.supplier_invalid'
                && $terne($scheda) === [[$spento, 'VF-1', 2.0], [$sud, '', 3.0]];
        });

        check('senza acquisti quello che arriva non si guarda e non si scrive', function () use ($scheda, $sud, $spento, $rifiuto, $salva, $riapri, $modo, $terne, $json) {
            $post = [
                'suppliers' => $json([['supplier_id' => '999999', 'cost' => '-1']]),
                'supplier_sku' => 'X',
                'supplier_cost' => '-5',
            ];
            $salva($scheda, $post, false);
            $valori = $riapri($scheda, false);

            return $rifiuto($scheda, $post, false) === ''
                && $modo($scheda, false) === null
                && array_intersect(['suppliers', 'suppliers_button', 'supplier_sku', 'supplier_cost'], array_keys($valori)) === []
                && $terne($scheda) === [[$spento, 'VF-1', 2.0], [$sud, '', 3.0]];
        });

        check('una finestra svuotata toglie tutti i legami', function () use ($scheda, $salva, $legami) {
            $salva($scheda, ['suppliers' => '[]']);

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
