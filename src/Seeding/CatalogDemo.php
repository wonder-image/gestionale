<?php

namespace Wonder\Plugin\Gestionale\Seeding;

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
 * tag, tre attributi con i loro valori e **quattro articoli** — uno senza
 * attributi, uno che li usa tutti e tre, uno con molte opzioni in vendita e
 * uno venduto solo a taglia, senza colore — ciascuno con la sua foto.
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
            'Catalogo: tassonomie, attributi e quattro articoli',
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

        // Il colore ha pagina e foto proprie: è lui a raggruppare la griglia
        // delle opzioni in vendita. Taglia e materiale si scelgono invece nel
        // carrello. Insieme sono tre attributi su un articolo solo, il
        // massimo che la scheda accetta.
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

        // Il terzo attributo: serve a vedere una riga che si legge "S / Gomma"
        // e a toccare il limite di tre attributi per articolo.
        $created += self::attribute('Materiale', [
            'slug' => 'prova-materiale',
            'type' => 'select',
            'level' => 'product',
            'group_name' => 'Materiali',
            'position' => 3,
        ], [
            ['label' => 'Cotone'],
            ['label' => 'Gomma'],
        ]);

        $created += self::packages();
        $created += self::models();

        return $created;
    }

    /**
     * Due scatole: quella che basta quasi sempre e una busta per le cose
     * piatte. La scatola è la predefinita, così un articolo che non sceglie
     * niente ha comunque una tara.
     *
     * @return int righe create
     */
    private static function packages(): int
    {
        $created = self::ensure(Package::class, self::PREFIX.'Busta imbottita', [
            'weight' => '0.050',
            'length' => '35.00',
            'width' => '25.00',
            'height' => '3.00',
            'is_default' => 'false',
            'position' => 1,
            'active' => 'true',
        ]);

        $created += self::ensure(Package::class, self::PREFIX.'Scatola media', [
            'weight' => '0.200',
            'length' => '40.00',
            'width' => '30.00',
            'height' => '20.00',
            'is_default' => 'true',
            'position' => 2,
            'active' => 'true',
        ]);

        return $created;
    }

    /**
     * I quattro articoli di prova, che sono i quattro casi che la griglia
     * delle opzioni in vendita deve reggere: nessun attributo, tutti e tre,
     * molte opzioni, e nessun colore.
     *
     * @return int righe create, articoli con tutto quello che ci sta sotto
     */
    private static function models(): int
    {
        $created = 0;
        $colore = self::valuesOf(self::PREFIX.'Colore');
        $taglia = self::valuesOf(self::PREFIX.'Taglia');
        $materiale = self::valuesOf(self::PREFIX.'Materiale');
        $magliette = self::idOf(Category::class, self::PREFIX.'Magliette');
        $accessori = self::idOf(Category::class, self::PREFIX.'Accessori');

        // Senza attributi: una sola opzione in vendita, con il suo prezzo.
        $created += self::model('Cappello di lana', 'CAP-1', $magliette, [], [], '24.90');

        // Tutti e tre gli attributi: due colori, tre taglie e due materiali
        // fanno dodici opzioni in vendita. È l'articolo dove una riga della
        // griglia si legge "S / Gomma", perché il colore lo dice la testata.
        $maglietta = self::model(
            'Maglietta girocollo',
            'TSH-1',
            $magliette,
            array_slice($colore, 0, 2),
            [array_slice($taglia, 0, 3), $materiale],
            '19.90'
        );
        $created += $maglietta;

        // Le foto del colore e della singola opzione stanno su questo
        // articolo, e solo se è appena nato: rifare i dati di prova non deve
        // aggiungerne altre due.
        if ($maglietta > 0) {
            $created += self::optionImages(self::PREFIX.'Maglietta girocollo');
        }

        // Molte opzioni in vendita: tre colori e quattro taglie.
        $created += self::model(
            'Felpa con cappuccio',
            'FEL-1',
            $magliette,
            $colore,
            [$taglia],
            '49.90'
        );

        // Senza colore: si vende solo a taglia. Nessun attributo con pagina
        // propria fra quelli spuntati, e la griglia resta piatta, senza
        // testate di gruppo.
        $created += self::model(
            'Calzini a costine',
            'CAL-1',
            $accessori,
            [],
            [$taglia],
            '9.90'
        );

        return $created;
    }

    /**
     * Un articolo di prova, con i suoi colori, le sue opzioni in vendita e la
     * sua foto.
     *
     * @param list<array{id: int, label: string}> $variantValues i valori del
     *        colore, l'attributo con pagina propria; vuoto quando l'articolo
     *        non ne usa e la griglia resta piatta
     * @param list<list<array{id: int, label: string}>> $productAxes gli altri
     *        attributi spuntati, uno per elenco: si moltiplicano fra loro
     */
    private static function model(
        string $name,
        string $sku,
        int $categoryId,
        array $variantValues,
        array $productAxes,
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
            // Con una scatola e un peso, il riquadro Spedizione della scheda
            // dice davvero quanto parte invece di lamentare un dato mancante.
            'package_id' => self::idOf(Package::class, self::PREFIX.'Scatola media'),
            'weight' => '0.250',
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

        if ($variantValues !== [] || $productAxes !== []) {
            $prima = self::countOf(ProductVariant::class, $modelId) + self::countOf(Product::class, $modelId);

            ProductModelResource::forgetCatalogCache();
            // Gli assi arrivano già divisi: il colore da una parte, gli altri
            // attributi dall'altra, uno per elenco. Il generatore li moltiplica
            // fra loro, e con tre attributi nasce "Blu / S / Gomma".
            Generator::run(
                $modelId,
                $variantValues,
                $productAxes,
                $sku
            );

            $dopo = self::countOf(ProductVariant::class, $modelId) + self::countOf(Product::class, $modelId);
            $created += max(0, $dopo - $prima);
        }

        // Il prezzo va su tutte le opzioni in vendita: senza, la scheda sembra
        // a metà.
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
                // Un'opzione sotto scorta: è il caso che si vuole provare.
                Product::update(['min_stock_quantity' => '5'], $productId);
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

        $file = 'prova-'.Sku::part($alt).'-'.uniqid().'.jpg';
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
     * Le due foto che fanno vedere l'eredità a tre livelli.
     *
     * L'articolo ha già la sua; qui si aggiunge quella di un colore e quella
     * di una singola opzione in vendita dentro quel colore. Nella stessa
     * scheda si legge allora tutta la regola: quella riga mostra la propria
     * foto, le altre righe dello stesso colore mostrano la foto del colore, le
     * righe degli altri colori mostrano la foto dell'articolo.
     *
     * @return int righe create
     */
    private static function optionImages(string $name): int
    {
        $modelId = self::idOf(ProductModel::class, $name);

        if ($modelId <= 0) {
            return 0;
        }

        $variant = self::rowsOfModel(ProductVariant::class, $modelId)[0] ?? [];
        $variantId = (int) ($variant['id'] ?? 0);

        if ($variantId <= 0) {
            return 0;
        }

        $created = self::image($modelId, trim((string) ($variant['name'] ?? '')) ?: $name, $variantId);

        foreach (self::rowsOfModel(Product::class, $modelId) as $product) {
            if ((int) ($product['product_variant_id'] ?? 0) !== $variantId) {
                continue;
            }

            // Basta la prima opzione di quel colore: le altre devono restare
            // senza foto propria, o il livello di mezzo non si vedrebbe.
            return $created + self::image(
                $modelId,
                trim((string) ($product['name'] ?? '')) ?: $name,
                $variantId,
                (int) ($product['id'] ?? 0)
            );
        }

        return $created;
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

        foreach (self::rows(Package::class) as $row) {
            if (!str_starts_with((string) ($row['name'] ?? ''), self::PREFIX)) {
                continue;
            }

            $result = Package::delete((int) $row['id']);
            $removed += !empty($result->success) ? 1 : 0;
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
            // **Anche quelli cancellati**: il repeater li segna `deleted` e
            // basta, la riga resta e il vincolo la vede. Un valore aggiunto a
            // mano e poi tolto bloccava tutta la pulizia.
            foreach (self::valuesOfAttribute((int) $row['id']) as $value) {
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

        $riga = array_merge($values, [
            'code' => Code::make($model, self::prefixOf($model)),
            'name' => $name,
        ]);

        // Non tutte le tabelle hanno uno slug: gli imballaggi non hanno una
        // pagina pubblica, e scriverlo lo farebbe finire nella query.
        foreach ($model::tableSchema() as $column) {
            if ((string) $column->name === 'slug') {
                $riga['slug'] = Slug::make($name, $model::$table);
                break;
            }
        }

        $result = $model::create($riga);

        return !empty($result->success) ? 1 : 0;
    }

    private static function idOf(string $model, string $name): int
    {
        $row = $model::find(['name' => $name, 'deleted' => 'false'], 1);

        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }

    /**
     * I valori di un attributo, compresi quelli segnati come cancellati.
     *
     * @return list<array<string, mixed>>
     */
    private static function valuesOfAttribute(int $attributeId): array
    {
        // La condizione nomina `deleted` di proposito: `find()` aggiunge da sé
        // `deleted = 'false'` a chi non ne parla, e qui servono anche le righe
        // segnate come cancellate — la chiave esterna le vede lo stesso.
        $rows = AttributeValue::find(
            "attribute_id = ".$attributeId." AND (deleted = 'true' OR deleted = 'false')"
        );

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
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
