<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\App\Models\Config\SocietyLocation;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Stock\Batch;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocuments;
use Wonder\Sql\Transaction;

/**
 * Un carico e uno scarico di prova, confermati, e un inventario in bozza
 * (con *Acquisti* anche un carico da un fornitore, con *Lotti e scadenze* un
 * carico con due lotti su un terzo articolo, con *Più sedi* una sede di prova
 * e un trasferimento in viaggio verso di lei)
 * sugli articoli di `CatalogDemo`: così Carichi e scarichi, Inventari e i
 * Movimenti non partono vuoti. L'inventario ha una riga contata con una
 * differenza e una non contata, e resta da confermare.
 *
 * Nascono dal servizio vero (`StockDocuments`), quindi numero, movimenti e
 * giacenza sono quelli di un documento fatto a mano. Il codice di prova li
 * ritrova: se ci sono già, un secondo giro non li duplica.
 *
 * La pulizia annulla il documento (gli storni rimettono la giacenza) e poi
 * toglie documento, righe, storia e movimenti. Se l'annullamento non si può
 * fare (la merce caricata è già uscita), il documento resta e il comando lo
 * dice: toglierlo lascerebbe una giacenza che non torna.
 */
final class StockDocumentsDemo
{
    /** Chiave nel registro dei dati di prova. */
    public const KEY = 'documenti-magazzino';

    public static function register(): void
    {
        DemoData::register(
            self::KEY,
            'Documenti di magazzino: un carico e uno scarico confermati, un inventario in bozza',
            static fn (): int => self::create(),
            static fn (): int => self::clear()
        );
    }

    /** @return int documenti creati */
    public static function create(): int
    {
        $versions = self::versions();

        if (count($versions) < 2) {
            DemoData::note('Il carico e lo scarico di prova usano gli articoli di prova: lancia il comando con il catalogo di prova.');

            return 0;
        }

        $created = 0;
        $system = ['source' => 'system'];

        foreach (['carico', 'scarico', 'inventario'] as $ref) {
            self::dropDeleted($ref);
        }

        // Creazione e conferma insieme: una bozza di prova rimasta a metà
        // (conferma rifiutata) avrebbe già il codice, e i giri dopo la
        // salterebbero senza confermarla mai.
        if (self::document('carico') === []) {
            Transaction::run(static function () use ($versions, $system): void {
                $id = StockDocuments::create('receipt', ['note' => 'Dati di prova'], [
                    ['product_id' => $versions[0], 'quantity' => 10],
                    ['product_id' => $versions[1], 'quantity' => 5],
                ], ['code' => DemoCode::forModel(StockDocument::class, 'carico')]);
                StockDocuments::confirm($id, $system);
            });
            $created++;
        }

        if (self::document('scarico') === []) {
            Transaction::run(static function () use ($versions, $system): void {
                $id = StockDocuments::create('issue', ['reason' => 'damaged', 'note' => 'Dati di prova'], [
                    ['product_id' => $versions[0], 'quantity' => 2],
                ], ['code' => DemoCode::forModel(StockDocument::class, 'scarico')]);
                StockDocuments::confirm($id, $system);
            });
            $created++;
        }

        // In bozza: la prima versione contata con una differenza, la seconda non ancora.
        if (self::document('inventario') === []) {
            StockDocuments::create('stocktake', ['note' => 'Dati di prova'], [
                ['product_id' => $versions[0], 'quantity' => 3],
                ['product_id' => $versions[1], 'quantity' => null],
            ], ['code' => DemoCode::forModel(StockDocument::class, 'inventario')]);
            $created++;
        }

        // G3, Acquisti: un carico da un fornitore di prova, con i costi.
        if (Gestionale::feature('purchasing') && self::document('carico-fornitore') === []) {
            $supplier = self::demoSupplier();

            if ($supplier > 0) {
                Transaction::run(static function () use ($versions, $system, $supplier): void {
                    $id = StockDocuments::create('receipt', [
                        'supplier_id' => (string) $supplier,
                        'supplier_document_number' => 'DDT 42',
                        'note' => 'Dati di prova',
                    ], [
                        ['product_id' => $versions[0], 'quantity' => 6, 'cost' => '4,50'],
                        ['product_id' => $versions[1], 'quantity' => 4, 'cost' => '2'],
                    ], ['code' => DemoCode::forModel(StockDocument::class, 'carico-fornitore')]);
                    StockDocuments::confirm($id, $system);
                });
                $created++;
            } else {
                DemoData::note('Il carico da un fornitore di prova vuole le anagrafiche di prova: lancia il comando con i fornitori di prova.');
            }
        }

        // G3, lotti: un articolo di prova con i lotti e un carico con due lotti,
        // uno che scade fra dieci giorni e uno già scaduto.
        if (Gestionale::feature('batch_tracking') && self::document('carico-lotti') === []) {
            $version = self::batchVersion($versions);

            if ($version > 0) {
                Transaction::run(static function () use ($version, $system): void {
                    ProductModel::update(['track_batches' => 'true'], self::modelOf($version));
                    $id = StockDocuments::create('receipt', ['note' => 'Dati di prova'], [
                        ['product_id' => $version, 'quantity' => 8, 'batch_number' => self::BATCH_PREFIX.'IN-SCADENZA', 'batch_expiry' => date('Y-m-d', strtotime('+10 days'))],
                        ['product_id' => $version, 'quantity' => 3, 'batch_number' => self::BATCH_PREFIX.'SCADUTO', 'batch_expiry' => date('Y-m-d', strtotime('-5 days'))],
                    ], ['code' => DemoCode::forModel(StockDocument::class, 'carico-lotti')]);
                    StockDocuments::confirm($id, $system);
                });
                $created++;
            } else {
                DemoData::note('Il carico con i lotti di prova vuole un terzo articolo di prova: lancia il comando con il catalogo di prova.');
            }
        }

        // G3.16: una seconda sede di prova e un trasferimento in viaggio verso di lei.
        if (Gestionale::feature('multi_location') && self::document('trasferimento') === []) {
            Transaction::run(static function () use ($versions, $system): void {
                $id = StockDocuments::create('transfer', [
                    'to_location_id' => self::demoLocation(),
                    'note' => 'Dati di prova',
                ], [
                    ['product_id' => $versions[0], 'quantity' => 2],
                ], ['code' => DemoCode::forModel(StockDocument::class, 'trasferimento')]);
                StockDocuments::confirm($id, $system);
            });
            $created++;
        }

        return $created;
    }

    /** La sede di prova dei trasferimenti: c'è già, o nasce adesso con la sua riga del core. */
    private static function demoLocation(): int
    {
        $code = DemoCode::forModel(Location::class, 'deposito');
        $row = Location::find(['code' => $code, 'deleted' => 'false'], 1);

        if (is_array($row) && isset($row['id'])) {
            return (int) $row['id'];
        }

        $place = SocietyLocation::create([
            'label' => 'Deposito di prova',
            'slug' => 'demo-deposito-di-prova-'.substr(md5(uniqid('', true)), 0, 6),
            'position' => 99,
            'visible' => 'false',
        ]);
        $location = Location::create([
            'code' => $code,
            'society_location_id' => (int) ($place->insert_id ?? 0),
            'has_stock' => 'true',
            'active' => 'true',
        ]);
        Locations::reset();

        return (int) ($location->insert_id ?? 0);
    }

    /**
     * La sede di prova se non ha più movimenti: i suoi secchi a zero, la riga
     * del gestionale e quella del core.
     */
    private static function clearLocation(): void
    {
        $row = Location::find(['code' => DemoCode::forModel(Location::class, 'deposito'), 'deleted' => 'false'], 1);

        if (!is_array($row) || !isset($row['id'])) {
            return;
        }

        $id = (int) $row['id'];

        if (self::rows(StockMovement::find(['location_id' => $id], 1)) !== []) {
            DemoData::note('La sede di prova resta: ha ancora dei movimenti.');

            return;
        }

        Transaction::run(static function () use ($id, $row): void {
            sqlDelete(StockRow::$table, 'location_id = '.$id.' AND quantity = 0');
            sqlDelete(Location::$table, 'id = '.$id);
            sqlDelete(SocietyLocation::$table, 'id = '.(int) $row['society_location_id']);
        });
        Locations::reset();
    }

    /** I lotti di prova cominciano così: la pulizia li ritrova. */
    private const BATCH_PREFIX = 'DEMO-';

    /**
     * Una versione di un articolo di prova diverso da quelli dei primi due
     * carichi, così i lotti non cambiano il carico e lo scarico di prova.
     *
     * @param list<int> $taken
     */
    private static function batchVersion(array $taken): int
    {
        $models = array_map(static fn (int $id): int => self::modelOf($id), $taken);
        $prefix = DemoCode::prefixOf(ProductModel::class);

        foreach (self::rows(ProductModel::find("code LIKE '".$prefix."demo-%' AND deleted = 'false' AND type <> 'bundle'", null, 'id', 'ASC')) as $model) {
            if (in_array((int) $model['id'], $models, true)) {
                continue;
            }

            $product = Product::find(['product_model_id' => (int) $model['id'], 'deleted' => 'false'], 1, 'id', 'ASC');

            if (is_array($product) && isset($product['id'])) {
                return (int) $product['id'];
            }
        }

        return 0;
    }

    private static function modelOf(int $productId): int
    {
        $row = Product::find(['id' => $productId], 1);

        return is_array($row) ? (int) ($row['product_model_id'] ?? 0) : 0;
    }

    /** Il primo fornitore delle anagrafiche di prova, o 0. */
    private static function demoSupplier(): int
    {
        $prefix = DemoCode::prefixOf(Contact::class);
        $row = Contact::find("`code` LIKE '".$prefix."demo-%' AND `is_supplier` = 'true' AND `deleted` = 'false'", 1, 'id', 'ASC');

        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }

    /**
     * Toglie i documenti di prova, l'ultimo per primo: lo scarico si annulla
     * prima del carico che gli ha dato la merce.
     *
     * @return int documenti tolti
     */
    public static function clear(): int
    {
        $removed = 0;
        $prefix = str_replace('_', '\\_', DemoCode::prefixOf(StockDocument::class));

        // Anche quelli eliminati dal backend: righe, storia e movimenti restano finché non li toglie qui.
        foreach (self::rows(StockDocument::find("code LIKE '".$prefix."demo-%' AND deleted IN ('true', 'false')", null, 'id', 'DESC')) as $document) {
            $id = (int) $document['id'];

            if ((string) $document['status'] === 'completed' && (string) ($document['deleted'] ?? 'false') === 'false') {
                try {
                    StockDocuments::cancel($id, ['source' => 'system']);
                } catch (UserError) {
                    DemoData::note('Il documento di prova '.(string) $document['number'].' resta: la merce caricata è già uscita. Annullalo a mano quando torna.');
                    continue;
                }
            }

            Transaction::run(static function () use ($id): void {
                sqlDelete(StockMovement::$table, "reference_type IN ('stock_document', 'stock_document_cancel') AND reference_id = ".$id);
                sqlDelete(StockDocumentStatusLog::$table, 'stock_document_id = '.$id);
                sqlDelete(StockDocumentItem::$table, 'stock_document_id = '.$id);
                sqlDelete(StockDocument::$table, 'id = '.$id);
            });
            $removed++;
        }

        self::clearBatches();
        self::clearLocation();

        return $removed;
    }

    /**
     * I lotti di prova senza più movimenti, i loro secchi a zero, e
     * l'interruttore spento sugli articoli di prova che non hanno più lotti.
     */
    private static function clearBatches(): void
    {
        $batches = self::rows(Batch::find("`number` LIKE '".self::BATCH_PREFIX."%'"));

        foreach ($batches as $batch) {
            $id = (int) $batch['id'];
            $movements = self::rows(StockMovement::find(['batch_id' => $id], 1));

            if ($movements !== []) {
                continue;
            }

            Transaction::run(static function () use ($id, $batch): void {
                sqlDelete(StockRow::$table, 'batch_id = '.$id.' AND quantity = 0');
                sqlDelete(Batch::$table, 'id = '.$id);
                $model = self::modelOf((int) $batch['product_id']);
                $left = self::rows(StockRow::find('`batch_id` > 0 AND `product_id` IN (SELECT p.`id` FROM `'.Product::$table.'` p WHERE p.`product_model_id` = '.$model.')', 1));

                if ($model > 0 && $left === []) {
                    ProductModel::update(['track_batches' => 'false'], $model);
                }
            });
        }
    }

    /**
     * Le prime due versioni degli articoli di prova che non sono multiprodotti:
     * un multiprodotto non ha giacenza sua.
     *
     * @return list<int>
     */
    private static function versions(): array
    {
        $out = [];
        $prefix = DemoCode::prefixOf(ProductModel::class);

        foreach (self::rows(ProductModel::find("code LIKE '".$prefix."demo-%' AND deleted = 'false' AND type <> 'bundle'", null, 'id', 'ASC')) as $model) {
            foreach (self::rows(Product::find(['product_model_id' => (int) $model['id'], 'deleted' => 'false'], null, 'id', 'ASC')) as $product) {
                $out[] = (int) $product['id'];

                if (count($out) === 2) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * Un documento di prova eliminato dal backend (solo una bozza si elimina,
     * e le sue righe sono già andate) tiene ancora il codice: si toglie del
     * tutto, così il giro lo rifà invece di inciampare nel codice preso.
     */
    private static function dropDeleted(string $ref): void
    {
        $row = StockDocument::find(['code' => DemoCode::forModel(StockDocument::class, $ref), 'deleted' => 'true'], 1);
        $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;

        if ($id <= 0 || (string) ($row['status'] ?? '') !== 'draft') {
            return;
        }

        Transaction::run(static function () use ($id): void {
            sqlDelete(StockDocumentStatusLog::$table, 'stock_document_id = '.$id);
            sqlDelete(StockDocumentItem::$table, 'stock_document_id = '.$id);
            sqlDelete(StockDocument::$table, 'id = '.$id);
        });
    }

    /** @return array<string, mixed> */
    private static function document(string $ref): array
    {
        $row = StockDocument::find(['code' => DemoCode::forModel(StockDocument::class, $ref)], 1);

        return is_array($row) && isset($row['id']) ? $row : [];
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $result): array
    {
        if (!is_array($result) || $result === []) {
            return [];
        }

        return isset($result['id']) ? [$result] : array_values(array_filter($result, 'is_array'));
    }
}
