<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Throwable;
use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleComponent;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleGroup;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleGroupOption;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\Package;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationLink;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelTag;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Resources\Catalog\CustomizationResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Bundles;
use Wonder\Plugin\Gestionale\Support\Catalog\Ean;
use Wonder\Plugin\Gestionale\Support\Catalog\Generator;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Purchasing\ProductSuppliers;
use Wonder\Plugin\Gestionale\Support\Stock\StockHistory;

/**
 * Dati di prova della vetrina: tre schede che usano **tutto** quello che il
 * catalogo sa scrivere, per vedere la scheda prodotto e il backend pieni.
 *
 * - **Impermeabile da città**: marchio, imballaggio, peso e misure, descrizione
 *   lunga e breve, due categorie e due tag, colore con foto proprie, taglia e
 *   materiale, scheda tecnica, tre personalizzazioni (testo, scelta
 *   obbligatoria, numero con un decimale). Ogni opzione ha codice, EAN, codice
 *   del produttore, prezzo e misure sue; quelle nere sono in offerta, una si
 *   ordina anche senza giacenza, una è spenta e l'ultima nasce sotto scorta.
 * - **Ombrello pieghevole**: un articolo semplice in offerta su tutte le
 *   opzioni, col solo prezzo scontato — nessuna campagna.
 * - **Kit pioggia**: un multiprodotto misto, con un componente fisso, due
 *   gruppi di scelta con minimi, massimi e sovrapprezzi, e tutto quello che ha
 *   una scheda (marchio, descrizione, misure, foto, personalizzazione).
 *
 * Marchio, categorie, tag, attributi, imballaggi e due personalizzazioni sono
 * quelli di `CatalogDemo`, che viene prima. Qui nasce solo la
 * personalizzazione a numero, e solo le righe nate qui si tolgono: la pulizia
 * cerca per riferimento, non col segno di prova in generale, o porterebbe via
 * anche gli articoli di `CatalogDemo`.
 */
final class ShowcaseDemo
{
    use CatalogDemoRows;

    /** Chiave nel registro dei dati di prova. */
    public const KEY = 'catalogo-completo';

    /** L'articolo completo, l'articolo in offerta e il multiprodotto. */
    private const FULL = 'impermeabile-da-citta';
    private const SALE = 'ombrello-pieghevole';
    private const BUNDLE = 'kit-pioggia';

    /** La personalizzazione a numero, l'unica che nasce qui. */
    private const NUMBER = 'lunghezza-maniche';

    /**
     * Le opzioni dell'impermeabile, per posizione fra le dodici in vendita:
     * una spenta, una che si ordina anche senza giacenza. L'ultima (11) nasce
     * sotto scorta, come in `CatalogDemo`. Il multiprodotto non ne usa
     * nessuna delle tre.
     */
    private const INACTIVE = 9;
    private const BACKORDER = 10;

    /** La scheda tecnica: il campo la salva com'è scritta. */
    private const COMPOSITION = 'Poliestere 100%';

    public static function register(): void
    {
        DemoData::register(
            self::KEY,
            'Catalogo: un articolo completo, uno in offerta e un multiprodotto',
            static fn (): int => self::create(),
            static fn (): int => self::clear()
        );
    }

    /** @return int righe create */
    public static function create(): int
    {
        self::$reused = [];

        if (self::idOf(Brand::class, 'maglificio-aurora', 'Maglificio Aurora') === 0) {
            DemoData::note('L\'articolo completo usa marchio, categorie e attributi del catalogo di prova: crealo prima e rilancia il comando.');

            return 0;
        }

        $created = self::numberCustomization();
        $created += self::full();
        $created += self::sale();
        $created += self::bundle();

        DemoData::note(DemoCode::reusedNote(array_values(array_unique(self::$reused))));
        self::$reused = [];

        return $created;
    }

    /**
     * La personalizzazione a numero: la lunghezza delle maniche, in
     * centimetri con un decimale.
     *
     * @return int righe create
     */
    private static function numberCustomization(): int
    {
        return self::ensure(Customization::class, self::NUMBER, 'Lunghezza maniche', [
            'label' => 'Lunghezza maniche (cm)',
            'help_text' => 'Da 55 a 70 cm, misurata dalla spalla',
            'kind' => 'number',
            'max_length' => 0,
            'decimals' => 1,
            'surcharge' => '4.00',
            'active' => 'true',
            'position' => 3,
        ]);
    }

    /**
     * L'articolo completo: due colori, tre taglie e due materiali fanno dodici
     * opzioni in vendita.
     *
     * @return int righe create
     */
    private static function full(): int
    {
        $colore = CatalogDemo::values('colore');
        $taglia = CatalogDemo::values('taglia');
        $materiale = CatalogDemo::values('materiale');

        $modelId = self::model(self::FULL, [
            'name' => 'Impermeabile da città',
            'sku' => 'IMP-1',
            'short_description' => 'Giacca antipioggia leggera con cappuccio a scomparsa e cuciture nastrate.',
            'description' => '<p>Un impermeabile pensato per la città: <strong>leggero</strong>, '
                .'traspirante e abbastanza lungo da coprire una giacca.</p>'
                .'<ul><li>Cappuccio a scomparsa nel collo</li>'
                .'<li>Cuciture nastrate e zip coperta</li>'
                .'<li>Due tasche con zip e una interna</li></ul>'
                .'<p>Si piega nella sua tasca interna e sta in una borsa.</p>',
            'weight' => '0.650',
            'length' => '62.00',
            'width' => '48.00',
            'height' => '4.00',
            'circumference' => '110.00',
        ], ['abbigliamento' => 'Abbigliamento', 'accessori' => 'Accessori']);

        if ($modelId === 0) {
            return 0;
        }

        // I due colori agli estremi (blu e nero), tre taglie, i due
        // materiali: il generatore li moltiplica, "Blu / S / Cotone".
        ProductModelResource::forgetCatalogCache();
        Generator::run(
            $modelId,
            array_values(array_filter([$colore[0] ?? null, $colore[2] ?? null])),
            array_values(array_filter([array_slice($taglia, 0, 3), $materiale])),
            'IMP-1'
        );

        $products = self::productsOf($modelId);
        $nero = (int) (self::variantsOf($modelId)[1]['id'] ?? 0);

        foreach ($products as $index => $product) {
            // Le taglie pesano e misurano un po' di più a ogni passo: le
            // opzioni hanno le loro misure, non quelle dell'articolo.
            $step = intdiv($index % 6, 2);
            $values = [
                'ean' => self::ean(100 + $index),
                'mpn' => 'AUR-IMP-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'price' => '89.00',
                'weight' => number_format(0.600 + 0.050 * $step, 3, '.', ''),
                'length' => number_format(60 + 4 * $step, 2, '.', ''),
                'width' => '48.00',
                'height' => '4.00',
                'position' => $index + 1,
            ];

            // Il nero è in offerta: il negozio lo mostra barrato, senza
            // bisogno di una campagna.
            if ($nero > 0 && (int) ($product['product_variant_id'] ?? 0) === $nero) {
                $values['sale_price'] = '69.00';
            }

            if ($index === self::BACKORDER) {
                $values['allow_backorder'] = 'true';
                $values['backorder_lead_days'] = 10;
            }

            if ($index === self::INACTIVE) {
                $values['active'] = 'false';
            }

            self::writeProduct((int) $product['id'], $values);
        }

        // Le foto: una per colore, e una sola opzione con la sua.
        foreach (self::variantsOf($modelId) as $variant) {
            self::image($modelId, trim((string) ($variant['name'] ?? '')) ?: 'Colore', (int) $variant['id']);
        }

        if (isset($products[0])) {
            self::image(
                $modelId,
                trim((string) ($products[0]['name'] ?? '')) ?: 'Opzione',
                (int) ($products[0]['product_variant_id'] ?? 0),
                (int) $products[0]['id']
            );
        }

        self::technicalSheet($modelId);

        self::linkCustomization($modelId, 'incisione', 'Incisione', false, 1);
        self::linkCustomization($modelId, 'confezione-regalo', 'Confezione regalo', true, 2);
        self::linkCustomization($modelId, self::NUMBER, 'Lunghezza maniche', false, 3);

        self::suppliers($modelId, [['filati-nord', 'FN-IMP-01', '38,00'], ['imballaggi-sud', 'IS-IMP-01', '41,50']]);

        return 1 + self::footprint($modelId) + self::seedStock($modelId);
    }

    /**
     * L'articolo in offerta: tre colori, tutti con il prezzo scontato.
     *
     * @return int righe create
     */
    private static function sale(): int
    {
        $modelId = self::model(self::SALE, [
            'name' => 'Ombrello pieghevole',
            'sku' => 'OMB-1',
            'short_description' => 'Ombrello automatico che si chiude in 28 cm, antivento.',
            'description' => '<p>Si apre e si chiude con un tasto, e le stecche in fibra '
                .'reggono il vento senza girarsi.</p>',
            'weight' => '0.350',
            'length' => '28.00',
            'width' => '6.00',
            'height' => '6.00',
        ], ['accessori' => 'Accessori']);

        if ($modelId === 0) {
            return 0;
        }

        ProductModelResource::forgetCatalogCache();
        Generator::run($modelId, CatalogDemo::values('colore'), [], 'OMB-1');

        foreach (self::productsOf($modelId) as $index => $product) {
            self::writeProduct((int) $product['id'], [
                'ean' => self::ean(200 + $index),
                'price' => '24.90',
                'sale_price' => '17.90',
                'position' => $index + 1,
            ]);
        }

        self::suppliers($modelId, [['imballaggi-sud', 'IS-OMB-01', '8,40']]);

        return 1 + self::footprint($modelId) + self::seedStock($modelId);
    }

    /**
     * Il multiprodotto misto: l'impermeabile blu fisso, poi un ombrello da
     * scegliere e, se si vuole, una seconda giacca con sovrapprezzo.
     *
     * Mai l'ultima opzione di un articolo (sotto scorta), né quella spenta o
     * quella che si ordina senza giacenza.
     *
     * @return int righe create
     */
    private static function bundle(): int
    {
        if (self::modelId(self::BUNDLE) > 0) {
            return 0;
        }

        $components = [['product_id' => self::productOf(self::FULL, 0), 'quantity' => 1]];
        $groups = [
            ['name' => 'Ombrello', 'min' => 1, 'max' => 1, 'options' => [
                ['product_id' => self::productOf(self::SALE, 0), 'surcharge' => '0.00'],
                ['product_id' => self::productOf(self::SALE, 1), 'surcharge' => '2.00'],
            ]],
            ['name' => 'Seconda giacca', 'min' => 0, 'max' => 1, 'options' => [
                ['product_id' => self::productOf(self::FULL, 2), 'surcharge' => '59.00'],
                ['product_id' => self::productOf(self::FULL, 6), 'surcharge' => '49.00'],
            ]],
        ];

        $ids = array_merge(
            array_column($components, 'product_id'),
            ...array_map(static fn (array $group): array => array_column($group['options'], 'product_id'), $groups)
        );

        if (in_array(0, $ids, true)) {
            return 0;
        }

        // La composizione si controlla come la controlla la scheda: un
        // multiprodotto che il backend rifiuterebbe non nasce.
        try {
            Bundles::assertComposition('mixed', $components, $groups);
            Bundles::assertProducts('mixed', $components, $groups);
        } catch (Throwable $error) {
            DemoData::note('Il multiprodotto completo non nasce: '.$error->getMessage());

            return 0;
        }

        $modelId = self::model(self::BUNDLE, [
            'name' => 'Kit pioggia',
            'sku' => 'KIT-1',
            'type' => 'bundle',
            'bundle_mode' => 'mixed',
            'show_components_value' => 'true',
            'short_description' => 'Impermeabile, ombrello a scelta e, se serve, una seconda giacca.',
            'description' => '<p>Tutto per la stagione delle piogge in una confezione: '
                .'l\'impermeabile blu, un ombrello del colore che preferisci e, '
                .'a prezzo di favore, una seconda giacca.</p>',
            'weight' => '1.100',
            'length' => '40.00',
            'width' => '30.00',
            'height' => '12.00',
        ], ['accessori' => 'Accessori', 'abbigliamento' => 'Abbigliamento']);

        if ($modelId === 0) {
            return 0;
        }

        $skeleton = Skeleton::forModel($modelId, 'Kit pioggia', 'KIT-1');
        // Il prezzo sta sull'unica opzione in vendita, che è la confezione.
        self::writeProduct((int) $skeleton['product_id'], ['price' => '119.00', 'ean' => self::ean(300)]);

        foreach ($components as $index => $component) {
            BundleComponent::create([
                'product_model_id' => $modelId,
                'product_id' => $component['product_id'],
                'quantity' => $component['quantity'],
                'position' => $index + 1,
            ]);
        }

        foreach ($groups as $index => $group) {
            $groupId = (int) (BundleGroup::create([
                'product_model_id' => $modelId,
                'name' => $group['name'],
                'min_choices' => $group['min'],
                'max_choices' => $group['max'],
                'position' => $index + 1,
            ])->insert_id ?? 0);

            foreach ($groupId > 0 ? $group['options'] : [] as $position => $option) {
                BundleGroupOption::create([
                    'bundle_group_id' => $groupId,
                    'product_id' => $option['product_id'],
                    'surcharge' => $option['surcharge'],
                    'position' => $position + 1,
                ]);
            }
        }

        self::linkCustomization($modelId, 'incisione', 'Incisione', false, 1);

        return 1 + self::footprint($modelId);
    }

    /**
     * Un articolo di prova con tutta la sua scheda: marchio, imballaggio,
     * misure, descrizioni, categorie (la prima è la principale), i due tag e
     * la foto. Le opzioni in vendita le fa chi lo chiama.
     *
     * @param array<string, string> $fields
     * @param array<string, string> $categories riferimento => nome
     * @return int l'id dell'articolo appena nato, `0` se c'era già o non nasce
     */
    private static function model(string $ref, array $fields, array $categories): int
    {
        // Un articolo si cerca solo col segno, come in `CatalogDemo`.
        if (self::modelId($ref) > 0) {
            return 0;
        }

        $deleted = self::deletedRow(ProductModel::class, DemoCode::forModel(ProductModel::class, $ref));

        if ($deleted !== null && !self::dropModel($deleted)) {
            return 0;
        }

        $result = ProductModel::create(array_merge([
            'code' => DemoCode::forModel(ProductModel::class, $ref),
            'slug' => Slug::unique($fields['name'], ProductModel::class),
            'brand_id' => self::idOf(Brand::class, 'maglificio-aurora', 'Maglificio Aurora'),
            'tax_category_id' => self::ordinaryTaxCategoryId(),
            'package_id' => self::idOf(Package::class, 'scatola-media', 'Scatola media'),
            'unit' => 'pz',
            'type' => 'simple',
            'returnable' => 'true',
            'requires_shipping' => 'true',
            'visible' => 'true',
            'visible_online' => 'true',
            'position' => 1,
        ], $fields));

        if (empty($result->success)) {
            return 0;
        }

        $modelId = (int) ($result->insert_id ?? 0);

        if (($fields['type'] ?? 'simple') !== 'bundle') {
            Skeleton::forModel($modelId, $fields['name'], $fields['sku']);
        }

        $position = 0;

        foreach ($categories as $categoryRef => $name) {
            $categoryId = self::idOf(Category::class, $categoryRef, $name);

            if ($categoryId > 0) {
                ProductModelCategory::create([
                    'product_model_id' => $modelId,
                    'category_id' => $categoryId,
                    'is_main' => $position === 0 ? 'true' : 'false',
                    'position' => ++$position,
                ]);
            }
        }

        foreach (['novita' => 'Novità', 'saldi' => 'Saldi'] as $tagRef => $name) {
            $tagId = self::idOf(Tag::class, $tagRef, $name);

            if ($tagId > 0) {
                ProductModelTag::create(['product_model_id' => $modelId, 'tag_id' => $tagId]);
            }
        }

        self::image($modelId, $fields['name']);

        return $modelId;
    }

    /**
     * La scheda tecnica: la composizione e tutti i simboli di lavaggio.
     * Solo sugli attributi di livello articolo: uno vero riusato al posto di
     * quello di prova può essere di un altro livello.
     */
    private static function technicalSheet(int $modelId): void
    {
        $input = [
            'composizione' => ['Composizione', self::COMPOSITION],
            'lavaggio' => ['Lavaggio', array_column(CatalogDemo::values('lavaggio'), 'id')],
        ];

        foreach ($input as $ref => [$name, $value]) {
            $attributeId = self::idOf(Attribute::class, $ref, $name);
            $row = $attributeId > 0 ? Attribute::find(['id' => $attributeId, 'deleted' => 'false'], 1) : null;

            if ($value === [] || !is_array($row) || ($row['level'] ?? '') !== 'model') {
                continue;
            }

            ProductAttributes::save('model', $modelId, [$row], [$attributeId => $value]);
        }
    }

    /** Collega la personalizzazione all'articolo appena nato. */
    private static function linkCustomization(int $modelId, string $ref, string $name, bool $required, int $position): void
    {
        $id = self::idOf(Customization::class, $ref, $name);

        if ($id === 0) {
            return;
        }

        CustomizationLink::create([
            'product_model_id' => $modelId,
            'customization_id' => $id,
            'is_required' => $required ? 'true' : 'false',
            'position' => $position,
        ]);
    }

    /**
     * Gli stessi fornitori su tutte le opzioni dell'articolo.
     *
     * @param list<array{string, string, string}> $rows riferimento, codice e costo
     */
    private static function suppliers(int $modelId, array $rows): void
    {
        $rows = self::supplierRows($rows);

        if ($rows === []) {
            return;
        }

        foreach (self::productsOf($modelId) as $product) {
            ProductSuppliers::sync((int) $product['id'], $rows);
        }
    }

    /**
     * Un EAN-13 con la cifra di controllo giusta, nel prefisso 200–299 che
     * GS1 lascia ai codici interni del negozio: non può essere quello di una
     * merce vera. Vuoto se è già di un altro prodotto.
     */
    private static function ean(int $number): string
    {
        $body = '200'.str_pad((string) (4_000_000 + $number), 9, '0', STR_PAD_LEFT);
        $sum = 0;

        foreach (str_split($body) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        $ean = $body.((10 - $sum % 10) % 10);

        return Ean::isFree($ean) ? $ean : '';
    }

    /** @param array<string, mixed> $values */
    private static function writeProduct(int $productId, array $values): void
    {
        if (($values['ean'] ?? null) === '') {
            unset($values['ean']);
        }

        Product::update($values, $productId);
    }

    /**
     * Le opzioni in vendita dell'articolo, nell'ordine in cui sono nate.
     *
     * @return list<array<string, mixed>>
     */
    private static function productsOf(int $modelId): array
    {
        $rows = self::rowsOfModel(Product::class, $modelId);
        usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function variantsOf(int $modelId): array
    {
        $rows = self::rowsOfModel(ProductVariant::class, $modelId);
        usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return $rows;
    }

    /**
     * Le righe che stanno sotto un articolo, giacenza esclusa: si contano
     * dopo averlo creato e prima di toglierlo, così creato e tolto tornano.
     */
    private static function footprint(int $modelId): int
    {
        $productIds = array_map(static fn (array $row): int => (int) $row['id'], self::rowsOfModel(Product::class, $modelId));
        $variantIds = array_map(static fn (array $row): int => (int) $row['id'], self::rowsOfModel(ProductVariant::class, $modelId));
        $parents = ['model' => [$modelId], 'variant' => $variantIds, 'product' => $productIds];

        $count = count($productIds) + count($variantIds)
            + self::countOf(ProductImage::class, $modelId)
            + self::countOf(ProductModelCategory::class, $modelId)
            + self::countOf(ProductModelTag::class, $modelId)
            + self::countOf(CustomizationLink::class, $modelId)
            + self::countOf(BundleComponent::class, $modelId)
            + self::supplierLinksOf($modelId);

        foreach (array_keys(Attributes::LEVELS) as $level) {
            if ($parents[$level] !== []) {
                $count += count(self::listOf(ProductAttributes::modelClass($level)::find(
                    ProductAttributes::parentKey($level).' IN ('.implode(',', $parents[$level]).") AND deleted = 'false'"
                )));
            }
        }

        foreach (self::rowsOfModel(BundleGroup::class, $modelId) as $group) {
            $count += 1 + count(self::listOf(BundleGroupOption::find(['bundle_group_id' => (int) $group['id'], 'deleted' => 'false'])));
        }

        return $count;
    }

    /**
     * Toglie le tre schede nate qui, per riferimento: il multiprodotto prima,
     * che tiene occupate le opzioni degli altri due. Poi la personalizzazione
     * a numero, se nessun articolo vero la usa.
     *
     * @return int righe tolte
     */
    public static function clear(): int
    {
        $removed = 0;
        $kept = [];

        foreach ([self::BUNDLE, self::FULL, self::SALE] as $ref) {
            $row = ProductModel::find(
                "code = '".DemoCode::forModel(ProductModel::class, $ref)."' AND deleted IN ('true', 'false')",
                1
            );

            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }

            $modelId = (int) $row['id'];

            // Un componente che un multiprodotto vero usa ancora resta.
            if (self::inBundle($modelId)) {
                $kept[] = DemoCode::label(ProductModel::class, (string) ($row['name'] ?? ''));
                continue;
            }

            $under = self::footprint($modelId) + StockHistory::purge(array_map(
                static fn (array $product): int => (int) ($product['id'] ?? 0),
                self::rowsOfModel(Product::class, $modelId)
            ));

            if (self::remove(static fn () => ProductModelResource::deleteRecord($modelId))) {
                $removed += 1 + $under;
            } else {
                $kept[] = DemoCode::label(ProductModel::class, (string) ($row['name'] ?? ''));
            }
        }

        $number = Customization::find(['code' => DemoCode::forModel(Customization::class, self::NUMBER), 'deleted' => 'false'], 1);

        if (is_array($number) && isset($number['id'])) {
            $id = (int) $number['id'];

            // Ancora su un articolo vero: la Resource la rifiuta, e resta.
            if (self::remove(static fn () => CustomizationResource::deleteRecord($id))) {
                $removed++;
            } else {
                $kept[] = DemoCode::label(Customization::class, (string) ($number['name'] ?? ''));
            }
        }

        DemoData::note(DemoCode::keptNote($kept));

        return $removed;
    }
}
