<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocuments;
use Wonder\Sql\Transaction;

/**
 * Un carico e uno scarico di prova, confermati, e un inventario in bozza
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

        if (self::document('carico') === []) {
            $id = StockDocuments::create('receipt', ['note' => 'Dati di prova'], [
                ['product_id' => $versions[0], 'quantity' => 10],
                ['product_id' => $versions[1], 'quantity' => 5],
            ], ['code' => DemoCode::forModel(StockDocument::class, 'carico')]);
            StockDocuments::confirm($id, $system);
            $created++;
        }

        if (self::document('scarico') === []) {
            $id = StockDocuments::create('issue', ['reason' => 'damaged', 'note' => 'Dati di prova'], [
                ['product_id' => $versions[0], 'quantity' => 2],
            ], ['code' => DemoCode::forModel(StockDocument::class, 'scarico')]);
            StockDocuments::confirm($id, $system);
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

        return $created;
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

        foreach (self::rows(StockDocument::find("code LIKE '".$prefix."demo-%'", null, 'id', 'DESC')) as $document) {
            $id = (int) $document['id'];

            if ((string) $document['status'] === 'completed') {
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

        return $removed;
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
