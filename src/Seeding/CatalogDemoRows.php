<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Throwable;
use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Tax\TaxCategory;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Bundles;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Catalog\Sku;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Stock\StockHistory;
use Wonder\Plugin\Gestionale\Support\Stock\Thresholds;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Plugin\Gestionale\Support\Stock\LowStock;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;

/**
 * Gli attrezzi comuni ai dati di prova del catalogo: trovare una riga di
 * prova (col segno o per nome), crearla se manca, scrivere una foto finta,
 * togliere un articolo con tutto quello che porta.
 *
 * Li usano `CatalogDemo` e `ShowcaseDemo`; ognuna delle due ha la sua copia
 * di `$reused`, e la sua nota finale.
 */
trait CatalogDemoRows
{
    /** Le righe vere usate al posto di quelle di prova, per la nota finale. @var list<string> */
    private static array $reused = [];

    /**
     * L'opzione in vendita di un articolo di prova, per posizione.
     *
     * @return int `0` se l'articolo o l'opzione non ci sono
     */
    private static function productOf(string $modelRef, int $position): int
    {
        $products = self::rowsOfModel(Product::class, self::modelId($modelRef));

        usort($products, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return (int) ($products[$position]['id'] ?? 0);
    }

    /** Il tipo fiscale ordinario, quello che `Defaults` precarica. */
    private static function ordinaryTaxCategoryId(): int
    {
        $row = TaxCategory::find(['code' => 'ordinaria', 'deleted' => 'false'], 1);

        if (is_array($row) && isset($row['id'])) {
            return (int) $row['id'];
        }

        $rows = TaxCategory::find(['deleted' => 'false'], 1);

        return is_array($rows) ? (int) ($rows['id'] ?? 0) : 0;
    }

    /**
     * Una foto finta: un rettangolo colorato scritto sul disco.
     *
     * Niente file esterni da portarsi dietro, e nasce `pending` come una foto
     * vera: così si può provare anche la coda delle misure.
     *
     * Senza altro la foto vale per tutto l'articolo; con il colore vale per
     * quel colore; con anche l'opzione in vendita vale per quella riga sola.
     * Sono i tre livelli che legge `ProductImages::for()`.
     */
    private static function image(int $modelId, string $alt, int $variantId = 0, int $productId = 0): int
    {
        if (!function_exists('imagecreatetruecolor')) {
            return 0;
        }

        $dir = rtrim((string) ($GLOBALS['ROOT'] ?? ''), '/').'/assets/upload'.ProductImages::folder();

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return 0;
        }

        $file = 'demo-'.Sku::part($alt).'-'.uniqid().'.jpg';
        $image = imagecreatetruecolor(1200, 900);
        $colors = [[31, 78, 216], [193, 18, 31], [26, 127, 75]];
        $color = $colors[strlen($alt) % count($colors)];
        imagefill($image, 0, 0, imagecolorallocate($image, ...$color));
        imagejpeg($image, $dir.$file, 82);

        $riga = [
            'product_model_id' => $modelId,
            'file' => json_encode([$file]),
            'alt' => $alt,
            // Le foto di un articolo stanno in fila: quella dell'articolo,
            // poi quella del colore, poi quella dell'opzione.
            'position' => self::countOf(ProductImage::class, $modelId) + 1,
            'status' => 'pending',
            'attempts' => 0,
        ];

        // Le due colonne si scrivono solo quando servono: una foto che vale
        // per tutto l'articolo non deve nemmeno nominarle.
        if ($variantId > 0) {
            $riga['product_variant_id'] = $variantId;
        }

        if ($productId > 0) {
            $riga['product_id'] = $productId;
        }

        $result = ProductImage::create($riga);

        return !empty($result->success) ? 1 : 0;
    }

    /**
     * I fornitori legati alle opzioni di un articolo: se ne vanno con lui.
     *
     * Si contano anche quelli delle opzioni nel cestino, che
     * `ProductModelResource::deleteRecord()` porta via insieme agli altri.
     */
    private static function supplierLinksOf(int $modelId): int
    {
        if ($modelId <= 0) {
            return 0;
        }

        try {
            return (int) ProductSupplier::query()->Count(
                ProductSupplier::$table,
                'WHERE product_id IN (SELECT id FROM '.Product::$table.' WHERE product_model_id = '.$modelId.')'
            );
        } catch (Throwable) {
            // Un sito senza la tabella dei fornitori: non c'è niente da contare.
            return 0;
        }
    }

    /**
     * Le righe per `ProductSuppliers`, con l'id della scheda al posto del
     * riferimento.
     *
     * @param list<array{string, string, string}> $rows riferimento, codice e costo
     * @return list<array{supplier_id: int, supplier_sku: string, cost: string}>
     */
    private static function supplierRows(array $rows): array
    {
        $result = [];

        foreach ($rows as [$ref, $sku, $cost]) {
            $supplierId = self::supplierId($ref);

            if ($supplierId > 0) {
                $result[] = ['supplier_id' => $supplierId, 'supplier_sku' => $sku, 'cost' => $cost];
            }
        }

        return $result;
    }

    /** L'id della scheda fornitore di prova con quel riferimento, `0` se non c'è. */
    private static function supplierId(string $ref): int
    {
        try {
            $row = Contact::find([
                'code' => DemoCode::forModel(Contact::class, $ref),
                'is_supplier' => 'true',
                'deleted' => 'false',
            ], 1);
        } catch (Throwable) {
            // Rubrica non ancora creata: nessuno da cui comprare.
            return 0;
        }

        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }

    /** Quante righe di quel Model appartengono al modello. */
    private static function countOf(string $model, int $modelId): int
    {
        return count(self::rowsOfModel($model, $modelId));
    }

    /** @return list<array<string, mixed>> */
    private static function rowsOfModel(string $model, int $modelId): array
    {
        return self::listOf($model::find(['product_model_id' => $modelId, 'deleted' => 'false']));
    }

    /**
     * Qualche riga di quel Model punta ancora lì?
     *
     * Contano anche le righe segnate come cancellate: la chiave esterna le
     * vede, e la cancellazione fallirebbe lo stesso.
     */
    private static function used(string $model, string $column, int $id): bool
    {
        // La condizione nomina `deleted` di proposito: `find()` altrimenti
        // aggiunge da sé `deleted = 'false'`.
        $row = $model::find(
            $column.' = '.$id." AND (deleted = 'true' OR deleted = 'false')",
            1
        );

        return is_array($row) && $row !== [];
    }

    /** Un'opzione in vendita di questo articolo è componente o scelta di un multiprodotto? */
    private static function inBundle(int $modelId): bool
    {
        foreach (self::rowsOfModel(Product::class, $modelId) as $product) {
            if (Bundles::usedBy((int) $product['id']) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * La riga di prova col codice dato che il backend ha segnato come
     * cancellata, o null. *Elimina* dell'elenco la segna e basta: il codice
     * resta occupato e la riga non si potrebbe rifare.
     *
     * @param class-string<\Wonder\App\Model> $model
     * @return array<string, mixed>|null
     */
    private static function deletedRow(string $model, string $code): ?array
    {
        $row = $model::find(['code' => $code, 'deleted' => 'true'], 1);

        return is_array($row) && isset($row['id']) ? $row : null;
    }

    /**
     * Toglie davvero un articolo di prova cancellato, con opzioni, varianti,
     * foto e giacenza di prova, come la pulizia. Falso se resta (un
     * multiprodotto vero lo usa ancora).
     *
     * @param array<string, mixed> $row
     */
    private static function dropModel(array $row): bool
    {
        $modelId = (int) $row['id'];

        if (self::inBundle($modelId)) {
            DemoData::note(DemoCode::label(ProductModel::class, (string) ($row['name'] ?? '')).' è cancellato ma un multiprodotto lo usa ancora: non si rifà.');

            return false;
        }

        StockHistory::purge(array_map(
            static fn (array $product): int => (int) ($product['id'] ?? 0),
            self::listOf(Product::find(['product_model_id' => $modelId, 'deleted' => ['true', 'false']]))
        ));

        return self::remove(static fn () => ProductModelResource::deleteRecord($modelId));
    }

    /** Una cancellazione riuscita? Un errore del database conta come un no. */
    private static function remove(callable $delete): bool
    {
        try {
            $result = $delete();
        } catch (Throwable) {
            return false;
        }

        return is_object($result) && !empty($result->success);
    }

    /**
     * Crea la riga se manca; ritorna 1 quando l'ha creata.
     *
     * @param class-string<\Wonder\App\Model> $model
     * @param array<string, mixed> $values
     */
    private static function ensure(string $model, string $ref, string $name, array $values): int
    {
        $found = self::find($model, $ref, $name);

        if ($found !== null) {
            if (!$found['demo']) {
                self::$reused[] = DemoCode::label($model, $name);
            }

            return 0;
        }

        $code = DemoCode::forModel($model, $ref);

        // La riga di prova c'è ancora ma è stata cancellata: si rimette al
        // suo posto, perché il codice è unico e rifarla non si può.
        if (DemoCode::revive($model, $code) > 0) {
            return 1;
        }

        $riga = array_merge($values, [
            'code' => $code,
            'name' => $name,
        ]);

        // Non tutte le tabelle hanno uno slug: gli imballaggi non hanno una
        // pagina pubblica, e scriverlo lo farebbe finire nella query.
        foreach ($model::tableSchema() as $column) {
            if ((string) $column->name === 'slug') {
                $riga['slug'] = Slug::unique($name, $model);
                break;
            }
        }

        $result = $model::create($riga);

        return !empty($result->success) ? 1 : 0;
    }

    /**
     * La riga da usare: quella col segno, o una vera con lo stesso nome.
     *
     * @return array{id: int, demo: bool}|null
     */
    private static function find(string $model, string $ref, string $name): ?array
    {
        return DemoCode::pick(self::rows($model), DemoCode::forModel($model, $ref), $name);
    }

    private static function idOf(string $model, string $ref, string $name): int
    {
        return self::find($model, $ref, $name)['id'] ?? 0;
    }

    /** L'id dell'articolo di prova con quel riferimento, `0` se non c'è. */
    private static function modelId(string $ref): int
    {
        $row = ProductModel::find(['code' => DemoCode::forModel(ProductModel::class, $ref), 'deleted' => 'false'], 1);

        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(string $model): array
    {
        return self::listOf($model::find(['deleted' => 'false']));
    }

    /** @return list<array<string, mixed>> */
    private static function listOf(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /**
     * La giacenza iniziale di un articolo di prova.
     *
     * Serve a vedere il magazzino pieno appena installato, e a far comparire
     * un avviso di scorta: l'ultima opzione in vendita nasce **sotto** la sua
     * soglia.
     */
    private static function seedStock(int $modelId): int
    {
        $products = array_values(self::rowsOfModel(Product::class, $modelId));
        $last = count($products) - 1;
        $created = 0;

        foreach ($products as $index => $product) {
            $productId = (int) ($product['id'] ?? 0);

            if ($productId <= 0) {
                continue;
            }

            if ($index === $last) {
                // Un'opzione sotto scorta, sulla sede principale: è il caso
                // che si vuole provare. La soglia è una riga, e la pulizia la
                // toglie con la storia di magazzino.
                $created += Thresholds::save($productId, [Locations::mainId() => 5.0]);
            }

            $esito = Stock::apply([
                'product_id' => $productId,
                'quantity' => $index === $last ? 2 : 20,
                'reason' => 'initial_stock',
                'source' => 'import',
            ]);

            // Una riga di giacenza e un movimento, più l'avviso quando
            // l'opzione nasce sotto scorta: sono le righe che `--fresh` poi
            // toglierà, e i due conteggi devono tornare.
            $created += 2;

            if (($esito['alert'] ?? '') === LowStock::OPEN) {
                $created++;
            }
        }

        return $created;
    }
}
