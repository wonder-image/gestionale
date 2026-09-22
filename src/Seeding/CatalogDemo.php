<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Catalog\Generator;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Sku;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Stock\LowStock;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Plugin\Gestionale\Support\Stock\StockHistory;
use Wonder\Plugin\Gestionale\Models\Tax\TaxCategory;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;

/**
 * Dati di prova del catalogo: un marchio, un piccolo albero di categorie, due
 * tag, due attributi con i loro valori e **tre articoli** — uno semplice, uno
 * con due colori e tre taglie, uno con molti prodotti — ciascuno con la sua
 * foto.
 *
 * Servono a provare le pagine su un sito vuoto e, più avanti, a dare un posto
 * ai prodotti finti. Tutte le righe portano il prefisso `Prova` nel nome, così
 * si riconoscono a colpo d'occhio e `--fresh` sa cosa togliere.
 */
final class CatalogDemo
{
    /** Chiave nel registro dei dati di prova. */
    public const KEY = 'catalogo-base';

    /** Riconosce le righe create da qui. */
    private const PREFIX = 'Prova ';

    public static function register(): void
    {
        DemoData::register(
            self::KEY,
            'Catalogo: tassonomie, attributi e tre articoli',
            static fn (): int => self::create(),
            static fn (): int => self::clear()
        );
    }

    /** @return int righe create */
    public static function create(): int
    {
        $created = 0;
        $created += self::ensure(Brand::class, self::PREFIX.'Marchio', ['position' => 1, 'visible' => 'true']);

        $parent = self::ensure(Category::class, self::PREFIX.'Abbigliamento', ['position' => 1, 'visible' => 'true']);
        $created += $parent;
        $parentId = self::idOf(Category::class, self::PREFIX.'Abbigliamento');

        $created += self::ensure(Category::class, self::PREFIX.'Magliette', [
            'parent_id' => $parentId,
            'position' => 1,
            'visible' => 'true',
        ]);
        $created += self::ensure(Category::class, self::PREFIX.'Accessori', ['position' => 2, 'visible' => 'true']);

        foreach (['Novità', 'Saldi'] as $tag) {
            $created += self::ensure(Tag::class, self::PREFIX.$tag, ['visible' => 'true']);
        }

        // Il colore cambia l'aspetto della variante, la taglia distingue i
        // prodotti dentro una variante: i due casi che servono al piano 3.
        $created += self::attribute('Colore', [
            'slug' => 'prova-colore',
            'type' => 'color',
            'level' => 'variant',
            'group_name' => '',
            'position' => 1,
        ], [
            ['label' => 'Blu', 'color' => '#1f4ed8'],
            ['label' => 'Rosso', 'color' => '#c1121f'],
            ['label' => 'Nero', 'color' => '#111111'],
        ]);

        $created += self::attribute('Taglia', [
            'slug' => 'prova-taglia',
            'type' => 'select',
            'level' => 'product',
            'group_name' => 'Misure',
            'position' => 2,
        ], [
            ['label' => 'S'],
            ['label' => 'M'],
            ['label' => 'L'],
            ['label' => 'XL'],
        ]);

        $created += self::models();

        return $created;
    }

    /**
     * I tre articoli di prova: uno semplice, uno con varianti, uno con molti
     * prodotti. Sono i tre casi che servono a provare magazzino e ordini nei
     * sotto-progetti dopo.
     *
     * @return int righe create, articoli con tutto quello che ci sta sotto
     */
    private static function models(): int
    {
        $created = 0;
        $colore = self::valuesOf(self::PREFIX.'Colore');
        $taglia = self::valuesOf(self::PREFIX.'Taglia');
        $categoria = self::idOf(Category::class, self::PREFIX.'Magliette');

        // Semplice: nessuna variante, un prodotto solo, con il suo prezzo.
        $created += self::model('Cappello di lana', 'CAP-1', $categoria, [], [], '24.90');

        // Con varianti: due colori e tre taglie fanno sei prodotti.
        $created += self::model(
            'Maglietta girocollo',
            'TSH-1',
            $categoria,
            array_slice($colore, 0, 2),
            array_slice($taglia, 0, 3),
            '19.90'
        );

        // Molti prodotti: tre colori e quattro taglie.
        $created += self::model(
            'Felpa con cappuccio',
            'FEL-1',
            $categoria,
            $colore,
            $taglia,
            '49.90'
        );

        return $created;
    }

    /**
     * Un articolo di prova, con la sua variante, i suoi prodotti e la sua foto.
     *
     * @param list<array{id: int, label: string}> $variantValues
     * @param list<array{id: int, label: string}> $productValues
     */
    private static function model(
        string $name,
        string $sku,
        int $categoryId,
        array $variantValues,
        array $productValues,
        string $price
    ): int {
        $name = self::PREFIX.$name;

        if (self::idOf(ProductModel::class, $name) > 0) {
            return 0;
        }

        $result = ProductModel::create([
            'code' => Code::make(ProductModel::class, Codes::MODEL),
            'name' => $name,
            'slug' => Slug::make($name.'-'.uniqid()),
            'sku' => $sku,
            // Il tipo fiscale è obbligatorio nella scheda: un articolo di prova
            // senza non si potrebbe nemmeno risalvare.
            'tax_category_id' => self::ordinaryTaxCategoryId(),
            'unit' => 'pz',
            'type' => 'simple',
            'short_description' => 'Articolo di prova del gestionale.',
            'returnable' => 'true',
            'requires_shipping' => 'true',
            'visible' => 'true',
            'visible_online' => 'true',
            'position' => 1,
        ]);

        if (empty($result->success)) {
            return 0;
        }

        $modelId = (int) ($result->insert_id ?? 0);
        $created = 1;

        Skeleton::forModel($modelId, $name, $sku);
        $created += 2;

        if ($categoryId > 0) {
            ProductModelCategory::create([
                'product_model_id' => $modelId,
                'category_id' => $categoryId,
                'is_main' => 'true',
                'position' => 1,
            ]);
            $created++;
        }

        if ($variantValues !== [] || $productValues !== []) {
            $prima = self::countOf(ProductVariant::class, $modelId) + self::countOf(Product::class, $modelId);

            ProductModelResource::forgetCatalogCache();
            // Un asse solo per lato: i dati di prova non devono provare i casi
            // limite, devono somigliare a un catalogo vero.
            Generator::run(
                $modelId,
                $variantValues,
                $productValues === [] ? [] : [$productValues],
                $sku
            );

            $dopo = self::countOf(ProductVariant::class, $modelId) + self::countOf(Product::class, $modelId);
            $created += max(0, $dopo - $prima);
        }

        // Il prezzo va su tutti i prodotti: senza, la scheda sembra a metà.
        foreach (self::rowsOfModel(Product::class, $modelId) as $product) {
            Product::update(['price' => $price], (int) $product['id']);
        }

        $created += self::image($modelId, $name);
        $created += self::seedStock($modelId);

        return $created;
    }

    /**
     * La giacenza iniziale di un articolo di prova.
     *
     * Serve a vedere il magazzino pieno appena installato, e a far comparire
     * un avviso di scorta: l'ultima versione nasce **sotto** la sua soglia.
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
                // Una versione sotto scorta: è il caso che si vuole provare.
                Product::update(['min_stock_quantity' => '5'], $productId);
            }

            $esito = Stock::apply([
                'product_id' => $productId,
                'quantity' => $index === $last ? 2 : 20,
                'reason' => 'initial_stock',
                'source' => 'import',
            ]);

            // Una riga di giacenza e un movimento, più l'avviso quando la
            // versione nasce sotto scorta: sono le righe che `--fresh` poi
            // toglierà, e i due conteggi devono tornare.
            $created += 2;

            if (($esito['alert'] ?? '') === LowStock::OPEN) {
                $created++;
            }
        }

        return $created;
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
     */
    private static function image(int $modelId, string $name): int
    {
        if (!function_exists('imagecreatetruecolor')) {
            return 0;
        }

        $dir = rtrim((string) ($GLOBALS['ROOT'] ?? ''), '/').'/assets/upload'.ProductImages::folder();

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return 0;
        }

        $file = 'prova-'.Sku::part($name).'-'.uniqid().'.jpg';
        $image = imagecreatetruecolor(1200, 900);
        $colors = [[31, 78, 216], [193, 18, 31], [26, 127, 75]];
        $color = $colors[strlen($name) % count($colors)];
        imagefill($image, 0, 0, imagecolorallocate($image, ...$color));
        imagejpeg($image, $dir.$file, 82);

        $result = ProductImage::create([
            'product_model_id' => $modelId,
            'file' => json_encode([$file]),
            'alt' => $name,
            'position' => 1,
            'status' => 'pending',
            'attempts' => 0,
        ]);

        return !empty($result->success) ? 1 : 0;
    }

    /** I valori di un attributo di prova. @return list<array{id: int, label: string}> */
    private static function valuesOf(string $attributeName): array
    {
        $attributeId = self::idOf(Attribute::class, $attributeName);

        if ($attributeId === 0) {
            return [];
        }

        $values = [];

        foreach (self::rows(AttributeValue::class) as $row) {
            if ((int) ($row['attribute_id'] ?? 0) === $attributeId) {
                $values[] = ['id' => (int) $row['id'], 'label' => (string) ($row['label'] ?? '')];
            }
        }

        return $values;
    }

    /** Quante righe di quel Model appartengono al modello. */
    private static function countOf(string $model, int $modelId): int
    {
        return count(self::rowsOfModel($model, $modelId));
    }

    /** @return list<array<string, mixed>> */
    private static function rowsOfModel(string $model, int $modelId): array
    {
        $rows = $model::find(['product_model_id' => $modelId, 'deleted' => 'false']);

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /**
     * Un attributo di prova con i suoi valori.
     *
     * @param array<string, mixed> $values
     * @param list<array<string, mixed>> $rows
     * @return int righe create, attributo e valori insieme
     */
    private static function attribute(string $name, array $values, array $rows): int
    {
        $name = self::PREFIX.$name;

        if (self::idOf(Attribute::class, $name) > 0) {
            return 0;
        }

        $result = Attribute::create(array_merge([
            'code' => Code::make(Attribute::class, Codes::ATTRIBUTE),
            'name' => $name,
            'unit' => '',
            'is_filterable' => 'true',
            'is_visible' => 'true',
        ], $values));

        if (empty($result->success)) {
            return 0;
        }

        $created = 1;
        $attributeId = self::idOf(Attribute::class, $name);
        $position = 1;

        foreach ($rows as $row) {
            $value = AttributeValue::create(array_merge([
                'attribute_id' => $attributeId,
                'color' => '',
                'position' => $position++,
            ], $row));

            $created += !empty($value->success) ? 1 : 0;
        }

        return $created;
    }

    /** @return int righe tolte */
    public static function clear(): int
    {
        $removed = 0;

        // Prima gli articoli: portano via prodotti, varianti, collegamenti e
        // foto, e sono loro a tenere occupati attributi e categorie.
        foreach (self::rows(ProductModel::class) as $row) {
            if (!str_starts_with((string) ($row['name'] ?? ''), self::PREFIX)) {
                continue;
            }

            $modelId = (int) $row['id'];
            // Quello che se ne va con l'articolo si conta prima: dopo non c'è
            // più niente da contare, e «tolte 19, create 49» non si spiega.
            $sotto = self::countOf(ProductVariant::class, $modelId)
                + self::countOf(Product::class, $modelId)
                + self::countOf(ProductImage::class, $modelId)
                + self::countOf(ProductModelCategory::class, $modelId);

            // La storia di magazzino di un articolo di prova se ne va con
            // lui: senza, la chiave esterna dei movimenti bloccherebbe la
            // pulizia. Fuori dai dati di prova il magazzino non si dimentica.
            $sotto += StockHistory::purge(array_map(
                static fn (array $product): int => (int) ($product['id'] ?? 0),
                self::rowsOfModel(Product::class, $modelId)
            ));

            $result = ProductModelResource::deleteRecord($modelId);
            $removed += !empty($result->success) ? 1 + $sotto : 0;
        }

        foreach (self::rows(Attribute::class) as $row) {
            if (!str_starts_with((string) ($row['name'] ?? ''), self::PREFIX)) {
                continue;
            }

            // Prima chi lo usa: un prodotto che punta a questo attributo
            // impedirebbe di toglierlo, e la pulizia morirebbe a metà.
            foreach (Attributes::LEVELS as $level => $ignored) {
                $model = ProductAttributes::modelClass($level);

                foreach (self::rows($model) as $link) {
                    if ((int) ($link['attribute_id'] ?? 0) === (int) $row['id']) {
                        $model::delete((int) $link['id']);
                    }
                }
            }

            // Poi i valori: la chiave esterna non lascia andare l'attributo.
            foreach (self::rows(AttributeValue::class) as $value) {
                if ((int) ($value['attribute_id'] ?? 0) !== (int) $row['id']) {
                    continue;
                }

                $removed += !empty(AttributeValue::delete((int) $value['id'])->success) ? 1 : 0;
            }

            $removed += !empty(Attribute::delete((int) $row['id'])->success) ? 1 : 0;
        }

        foreach ([Tag::class, Category::class, Brand::class] as $model) {
            foreach (self::rows($model) as $row) {
                if (!str_starts_with((string) ($row['name'] ?? ''), self::PREFIX)) {
                    continue;
                }

                $result = $model::delete((int) $row['id']);

                if (!empty($result->success)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /** Crea la riga se manca; ritorna 1 quando l'ha creata. */
    private static function ensure(string $model, string $name, array $values): int
    {
        if (self::idOf($model, $name) > 0) {
            return 0;
        }

        $result = $model::create(array_merge($values, [
            'code' => Code::make($model, self::prefixOf($model)),
            'name' => $name,
            'slug' => Slug::make($name, $model::$table),
        ]));

        return !empty($result->success) ? 1 : 0;
    }

    private static function idOf(string $model, string $name): int
    {
        $row = $model::find(['name' => $name, 'deleted' => 'false'], 1);

        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(string $model): array
    {
        $rows = $model::find(['deleted' => 'false']);

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /** Il prefisso del codice di quel Model, letto dal suo schema. */
    private static function prefixOf(string $model): string
    {
        foreach ($model::dataSchema() as $field) {
            if ((string) $field->key === 'code') {
                return (string) (($field->getSchema('unique_code')['prefix']) ?? '');
            }
        }

        return '';
    }
}
