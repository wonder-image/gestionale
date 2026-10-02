<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Throwable;
use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleComponent;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleGroup;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleGroupOption;
use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationOption;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCustomization;
use Wonder\Plugin\Gestionale\Resources\Catalog\CustomizationResource;
use Wonder\Plugin\Gestionale\Models\Catalog\Package;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelTag;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Bundles;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Catalog\Generator;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Sku;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Purchasing\ProductSuppliers;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\LowStock;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Plugin\Gestionale\Support\Stock\StockHistory;
use Wonder\Plugin\Gestionale\Support\Stock\Thresholds;
use Wonder\Plugin\Gestionale\Models\Tax\TaxCategory;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;

/**
 * Dati di prova del catalogo: un marchio, un piccolo albero di categorie, due
 * tag, tre attributi con i loro valori, due caratteristiche della scheda
 * tecnica, due imballaggi e **quattro articoli** — uno senza attributi, uno
 * che li usa tutti e tre (ed è l'unico con la scheda tecnica), uno con molte
 * opzioni in vendita e uno venduto solo a taglia, senza colore — ciascuno con
 * la sua foto.
 *
 * Servono a provare le pagine su un sito vuoto e, più avanti, a dare un posto
 * ai prodotti finti. I nomi sono quelli di un negozio vero; le righe si
 * riconoscono dal segno nel codice (`cat_demo-accessori`, vedi `DemoCode`).
 *
 * Gli articoli comprano dai due fornitori di prova di `ContactsDemo`, con le
 * righe scritte opzione per opzione (`gst_product_suppliers`, spec §23.1):
 * tutte le opzioni di un articolo hanno le stesse, e una sola ha un codice e
 * un costo suoi, per vedere due riassunti diversi nella stessa griglia.
 * L'ultima opzione di ogni articolo nasce sotto la sua scorta minima, scritta
 * sulla sede principale.
 *
 * Marchio, categorie, tag, attributi e imballaggi si cercano prima col segno,
 * poi per nome: se il sito ha già una categoria «Accessori» vera, gli
 * articoli di prova finiscono lì, e la categoria resta sua — non si segna e
 * la pulizia non la tocca. Gli articoli invece si cercano solo col segno.
 *
 * Sotto un attributo vero riusato si aggiungono i valori che mancano (un
 * «Colore» senza «Nero», per esempio): quei valori non hanno una colonna
 * `code` dove mettere il segno, e la pulizia li lascia dove sono.
 *
 * La pulizia toglie solo le righe col segno e quelle con i vecchi nomi
 * `Prova …` (`DemoCode::LEGACY_NAMES`). Prima gli articoli di prova, con
 * tutto quello che portano; poi le tassonomie, ma solo quelle che nessun
 * articolo vero usa più: le altre restano, e il comando lo dice.
 */
final class CatalogDemo
{
    /** Chiave nel registro dei dati di prova. */
    public const KEY = 'catalogo-base';

    /**
     * Gli attributi di prova, per riferimento.
     *
     * Il colore è un'opzione con foto proprie: è lui a raggruppare la griglia
     * delle opzioni in vendita. Taglia e materiale sono opzioni da scegliere,
     * senza foto loro.
     * Insieme sono tre attributi su un articolo solo, il massimo che la
     * scheda accetta; il materiale serve a vedere una riga che si legge
     * "S / Gomma".
     */
    private const ATTRIBUTES = [
        'colore' => [
            'name' => 'Colore',
            'row' => ['type' => 'color', 'level' => 'variant', 'group_name' => '', 'position' => 1],
            'values' => [
                ['label' => 'Blu', 'color' => '#1f4ed8'],
                ['label' => 'Rosso', 'color' => '#c1121f'],
                ['label' => 'Nero', 'color' => '#111111'],
            ],
        ],
        'taglia' => [
            'name' => 'Taglia',
            'row' => ['type' => 'select', 'level' => 'product', 'group_name' => 'Misure', 'position' => 2],
            'values' => [['label' => 'S'], ['label' => 'M'], ['label' => 'L'], ['label' => 'XL']],
        ],
        'materiale' => [
            'name' => 'Materiale',
            'row' => ['type' => 'select', 'level' => 'product', 'group_name' => 'Materiali', 'position' => 3],
            'values' => [['label' => 'Cotone'], ['label' => 'Gomma']],
        ],
        // La scheda tecnica: caratteristiche dell'articolo, che si leggono
        // in scheda e non filtrano. La composizione è un testo libero; il
        // lavaggio è un elenco, e un articolo ne prende più d'uno.
        'composizione' => [
            'name' => 'Composizione',
            'row' => ['type' => 'text', 'level' => 'model', 'group_name' => 'Composizione e cura', 'position' => 4, 'is_filterable' => 'false'],
            'values' => [],
        ],
        'lavaggio' => [
            'name' => 'Lavaggio',
            'row' => ['type' => 'select', 'level' => 'model', 'group_name' => 'Composizione e cura', 'position' => 5, 'is_filterable' => 'false'],
            'values' => [
                ['label' => 'Lavaggio a 30°'],
                ['label' => 'Non candeggiare'],
                ['label' => 'Non asciugare in asciugatrice'],
            ],
        ],
    ];

    /**
     * La scheda tecnica dell'articolo che la usa: il testo della composizione.
     *
     * Scritto come lo salva il campo (`sanitizeFirst()`: minuscole, poi
     * maiuscola a ogni parola): «100% cotone» diventerebbe «100% Cotone».
     */
    private const COMPOSITION = 'Cotone 100%';

    /**
     * Da chi si comprano le opzioni di ogni articolo di prova: il riferimento
     * della scheda in `ContactsDemo`, il codice del fornitore e il costo, come
     * si scrivono nella scheda. Filati Nord fornisce tutto; i calzini si
     * comprano anche da Imballaggi Sud, così una finestra ha due righe.
     */
    private const SUPPLIERS = [
        'cappello-di-lana' => [['filati-nord', 'FN-CAP-01', '9,50']],
        'maglietta-girocollo' => [['filati-nord', 'FN-TSH-01', '7,20']],
        'felpa-con-cappuccio' => [['filati-nord', 'FN-FEL-01', '21,00']],
        'calzini-a-costine' => [['filati-nord', 'FN-CAL-01', '2,40'], ['imballaggi-sud', 'IS-CAL-01', '2,10']],
    ];

    /**
     * L'opzione che si compra diversamente: l'ultima dei calzini ha da Filati
     * Nord un codice e un costo suoi; Imballaggi Sud resta com'è per le altre.
     */
    private const LAST_OPTION_SUPPLIERS = [
        'calzini-a-costine' => [['filati-nord', 'FN-CAL-XL', '2,90'], ['imballaggi-sud', 'IS-CAL-01', '2,10']],
    ];

    /** Le righe vere usate al posto di quelle di prova, per la nota finale. @var list<string> */
    private static array $reused = [];

    /**
     * I tre multiprodotti di prova. I componenti sono articoli di prova, per
     * riferimento e posizione fra le loro opzioni in vendita: mai l'ultima, che
     * nasce sotto scorta e non si può vendere a pacchi.
     *
     * Una voce: nome, codice articolo, modalità, prezzo, se mostra il valore dei
     * componenti, componenti fissi `[articolo, posizione, quantità]` e gruppi di
     * scelta `[nome, minimo, massimo, [articolo, posizione, sovrapprezzo]…]`.
     */
    private const BUNDLES = [
        'cesto-degustazione' => [
            'name' => 'Cesto degustazione', 'sku' => 'CES-1', 'mode' => 'fixed', 'price' => '69.00', 'show_value' => true,
            'components' => [['maglietta-girocollo', 0, 1], ['calzini-a-costine', 0, 2], ['felpa-con-cappuccio', 0, 1]],
            'groups' => [],
        ],
        'cesto-componibile' => [
            'name' => 'Cesto componibile', 'sku' => 'CES-2', 'mode' => 'choice', 'price' => '39.00', 'show_value' => false,
            'components' => [],
            'groups' => [
                ['Vino', 1, 1, [['calzini-a-costine', 1, '0.00'], ['felpa-con-cappuccio', 1, '4.00']]],
                ['Dolce', 0, 2, [['maglietta-girocollo', 1, '0.00'], ['maglietta-girocollo', 2, '0.00'], ['maglietta-girocollo', 3, '0.00']]],
            ],
        ],
        'cesto-completo' => [
            'name' => 'Cesto completo', 'sku' => 'CES-3', 'mode' => 'mixed', 'price' => '79.00', 'show_value' => false,
            'components' => [['felpa-con-cappuccio', 2, 1]],
            'groups' => [
                ['Vino', 1, 1, [['calzini-a-costine', 1, '0.00'], ['felpa-con-cappuccio', 3, '4.00']]],
            ],
        ],
    ];

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
        self::$reused = [];
        $created = 0;
        $created += self::ensure(Brand::class, 'maglificio-aurora', 'Maglificio Aurora', ['position' => 1, 'visible' => 'true']);

        $created += self::ensure(Category::class, 'abbigliamento', 'Abbigliamento', ['position' => 1, 'visible' => 'true']);
        $created += self::ensure(Category::class, 'magliette-e-felpe', 'Magliette e felpe', [
            'parent_id' => self::idOf(Category::class, 'abbigliamento', 'Abbigliamento'),
            'position' => 1,
            'visible' => 'true',
        ]);
        $created += self::ensure(Category::class, 'accessori', 'Accessori', ['position' => 2, 'visible' => 'true']);

        $created += self::ensure(Tag::class, 'novita', 'Novità', ['visible' => 'true']);
        $created += self::ensure(Tag::class, 'saldi', 'Saldi', ['visible' => 'true']);

        foreach (array_keys(self::ATTRIBUTES) as $ref) {
            $created += self::attribute($ref);
        }

        $created += self::packages();
        $created += self::models();
        $created += self::suppliers();
        $created += self::customizations();
        $created += self::bundles();

        DemoData::note(DemoCode::reusedNote(array_values(array_unique(self::$reused))));
        self::$reused = [];

        return $created;
    }

    /**
     * Le personalizzazioni di prova, entrambe facoltative: un'incisione
     * (testo, +5) sulla maglietta, l'articolo che gli ordini di prova
     * vendono, e una confezione regalo (scelta fra due colori, +3) sulla
     * felpa. Le opzioni si rimettono a una personalizzazione già nata che non
     * ne ha: la pulizia le porta via con lei.
     *
     * @return int righe create
     */
    private static function customizations(): int
    {
        $created = self::ensure(Customization::class, 'incisione', 'Incisione', [
            'label' => 'Incisione',
            'help_text' => 'Fino a 20 caratteri',
            'kind' => 'text',
            'max_length' => 20,
            'decimals' => 0,
            'surcharge' => '5.00',
            'active' => 'true',
            'position' => 1,
        ]);
        $created += self::ensure(Customization::class, 'confezione-regalo', 'Confezione regalo', [
            'label' => 'Confezione regalo',
            'help_text' => '',
            'kind' => 'choice',
            'max_length' => 0,
            'decimals' => 0,
            'surcharge' => '3.00',
            'active' => 'true',
            'position' => 2,
        ]);

        $gift = self::idOf(Customization::class, 'confezione-regalo', 'Confezione regalo');

        // Solo se non ne ha: un'opzione che il commerciante ha tolto o
        // cambiato a mano non si rimette ogni volta.
        if ($gift > 0 && !self::used(CustomizationOption::class, 'customization_id', $gift)) {
            foreach (['Rossa', 'Blu'] as $position => $label) {
                $created += !empty(CustomizationOption::create([
                    'customization_id' => $gift,
                    'label' => $label,
                    'surcharge' => '0.00',
                    'position' => $position + 1,
                ])->success) ? 1 : 0;
            }
        }

        $created += self::linkCustomization('maglietta-girocollo', 'incisione', 'Incisione');
        $created += self::linkCustomization('felpa-con-cappuccio', 'confezione-regalo', 'Confezione regalo');

        return $created;
    }

    /**
     * I multiprodotti di prova: uno fisso, uno a scelta e uno misto. Nascono
     * dopo gli articoli, che sono i loro componenti; rifare i dati di prova non
     * tocca quelli che ci sono già.
     *
     * @return int righe create
     */
    private static function bundles(): int
    {
        $created = 0;

        foreach (self::BUNDLES as $ref => $bundle) {
            $created += self::bundle($ref, $bundle);
        }

        return $created;
    }

    /**
     * Un multiprodotto di prova, con la sua composizione.
     *
     * @param array<string, mixed> $bundle una voce di `BUNDLES`
     * @return int righe create
     */
    private static function bundle(string $ref, array $bundle): int
    {
        if (self::modelId($ref) > 0) {
            return 0;
        }

        // I componenti sono articoli di prova: se manca uno, il multiprodotto
        // non nasce a metà.
        $pieces = [];

        foreach ($bundle['components'] as [$model, $position, $quantity]) {
            $pieces[] = [self::productOf($model, $position), $quantity];
        }

        $groups = [];

        foreach ($bundle['groups'] as [$name, $min, $max, $options]) {
            $groups[] = [$name, $min, $max, array_map(
                static fn (array $option): array => [self::productOf($option[0], $option[1]), $option[2]],
                $options
            )];
        }

        $ids = array_merge(
            array_column($pieces, 0),
            ...array_map(static fn (array $group): array => array_column($group[3], 0), $groups)
        );

        if (in_array(0, $ids, true)) {
            return 0;
        }

        $result = ProductModel::create([
            'code' => DemoCode::forModel(ProductModel::class, $ref),
            'name' => $bundle['name'],
            'slug' => Slug::unique($bundle['name'], ProductModel::class),
            'sku' => $bundle['sku'],
            'tax_category_id' => self::ordinaryTaxCategoryId(),
            'unit' => 'pz',
            'type' => 'bundle',
            'bundle_mode' => $bundle['mode'],
            'show_components_value' => $bundle['show_value'] ? 'true' : 'false',
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

        $skeleton = Skeleton::forModel($modelId, $bundle['name'], $bundle['sku']);
        $created += 2;

        // Il prezzo sta sull'unica opzione in vendita, che è la confezione.
        Product::update(['price' => $bundle['price']], (int) $skeleton['product_id']);

        $category = self::idOf(Category::class, 'accessori', 'Accessori');

        if ($category > 0) {
            ProductModelCategory::create([
                'product_model_id' => $modelId,
                'category_id' => $category,
                'is_main' => 'true',
                'position' => 1,
            ]);
            $created++;
        }

        foreach ($pieces as $index => [$productId, $quantity]) {
            $created += !empty(BundleComponent::create([
                'product_model_id' => $modelId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'position' => $index + 1,
            ])->success) ? 1 : 0;
        }

        foreach ($groups as $index => [$name, $min, $max, $options]) {
            $group = BundleGroup::create([
                'product_model_id' => $modelId,
                'name' => $name,
                'min_choices' => $min,
                'max_choices' => $max,
                'position' => $index + 1,
            ]);
            $groupId = (int) ($group->insert_id ?? 0);
            $created += $groupId > 0 ? 1 : 0;

            foreach ($options as $position => [$productId, $surcharge]) {
                $created += $groupId > 0 && !empty(BundleGroupOption::create([
                    'bundle_group_id' => $groupId,
                    'product_id' => $productId,
                    'surcharge' => $surcharge,
                    'position' => $position + 1,
                ])->success) ? 1 : 0;
            }
        }

        return $created;
    }

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

    /** Collega la personalizzazione all'articolo, facoltativa, se il collegamento non c'è già. */
    private static function linkCustomization(string $modelRef, string $ref, string $name): int
    {
        $modelId = self::modelId($modelRef);
        $id = self::idOf(Customization::class, $ref, $name);

        if ($modelId === 0 || $id === 0) {
            return 0;
        }

        $link = ProductModelCustomization::find(['product_model_id' => $modelId, 'customization_id' => $id, 'deleted' => 'false'], 1);

        if (is_array($link) && isset($link['id'])) {
            return 0;
        }

        return !empty(ProductModelCustomization::create([
            'product_model_id' => $modelId,
            'customization_id' => $id,
            'is_required' => 'false',
            'position' => 1,
        ])->success) ? 1 : 0;
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
        $created = self::ensure(Package::class, 'busta-imbottita', 'Busta imbottita', [
            'weight' => '0.050',
            'length' => '35.00',
            'width' => '25.00',
            'height' => '3.00',
            'is_default' => 'false',
            'position' => 1,
            'active' => 'true',
        ]);

        $created += self::ensure(Package::class, 'scatola-media', 'Scatola media', [
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
        $colore = self::valuesOf('colore');
        $taglia = self::valuesOf('taglia');
        $materiale = self::valuesOf('materiale');
        $magliette = self::idOf(Category::class, 'magliette-e-felpe', 'Magliette e felpe');
        $accessori = self::idOf(Category::class, 'accessori', 'Accessori');

        // Senza attributi: una sola opzione in vendita, con il suo prezzo.
        $created += self::model(
            'cappello-di-lana',
            'Cappello di lana',
            'Berretto a coste in lana morbida, caldo senza pesare. Taglia unica.',
            'CAP-1',
            $accessori,
            [],
            [],
            '24.90'
        );

        // Tutti e tre gli attributi: due colori, tre taglie e due materiali
        // fanno dodici opzioni in vendita. È l'articolo dove una riga della
        // griglia si legge "S / Gomma", perché il colore lo dice la testata.
        $maglietta = self::model(
            'maglietta-girocollo',
            'Maglietta girocollo',
            'Maglietta a maniche corte con girocollo a coste. Si sceglie colore, taglia e tessuto.',
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
            $created += self::optionImages(self::modelId('maglietta-girocollo'));
        }

        // Molte opzioni in vendita: tre colori e quattro taglie.
        $created += self::model(
            'felpa-con-cappuccio',
            'Felpa con cappuccio',
            'Felpa garzata con cappuccio e tasca a marsupio, per le mezze stagioni.',
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
            'calzini-a-costine',
            'Calzini a costine',
            'Calzini a coste in cotone, rinforzati su punta e tallone.',
            'CAL-1',
            $accessori,
            [],
            [$taglia],
            '9.90'
        );

        $created += self::technicalSheet(self::modelId('maglietta-girocollo'));

        return $created;
    }

    /**
     * La scheda tecnica della maglietta: la composizione e tutti i simboli di
     * lavaggio, più d'uno sulla stessa caratteristica.
     *
     * Fuori da `model()`, così arriva anche su una maglietta di prova nata
     * prima; si scrive solo la caratteristica che manca, e rifare i dati di
     * prova non tocca niente.
     *
     * @return int righe create
     */
    private static function technicalSheet(int $modelId): int
    {
        if ($modelId <= 0) {
            return 0;
        }

        $existing = ProductAttributes::rows('model', $modelId);
        $input = [
            'composizione' => self::COMPOSITION,
            'lavaggio' => array_column(self::valuesOf('lavaggio'), 'id'),
        ];
        $created = 0;

        foreach ($input as $ref => $value) {
            $attribute = self::find(Attribute::class, $ref, self::ATTRIBUTES[$ref]['name']);
            $attributeId = (int) ($attribute['id'] ?? 0);

            // Un attributo vero riusato può essere di un altro livello: la
            // scheda tecnica si scrive solo dove la si legge.
            if ($attributeId <= 0 || $value === [] || isset($existing[$attributeId])) {
                continue;
            }

            $row = Attribute::find(['id' => $attributeId, 'deleted' => 'false'], 1);

            if (!is_array($row) || ($row['level'] ?? '') !== 'model') {
                continue;
            }

            $created += ProductAttributes::save('model', $modelId, [$row], [$attributeId => $value]);
        }

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
        string $ref,
        string $name,
        string $description,
        string $sku,
        int $categoryId,
        array $variantValues,
        array $productAxes,
        string $price
    ): int {
        // Un articolo si cerca solo col segno: uno vero con lo stesso nome
        // non è un articolo di prova, e non gli si mettono sotto opzioni.
        if (self::modelId($ref) > 0) {
            return 0;
        }

        $result = ProductModel::create([
            'code' => DemoCode::forModel(ProductModel::class, $ref),
            'name' => $name,
            'slug' => Slug::unique($name, ProductModel::class),
            'sku' => $sku,
            // Il tipo fiscale è obbligatorio nella scheda: un articolo di prova
            // senza non si potrebbe nemmeno risalvare.
            'tax_category_id' => self::ordinaryTaxCategoryId(),
            // Con una scatola e un peso l'articolo di prova ha quello che
            // serve a calcolare il peso di spedizione.
            'package_id' => self::idOf(Package::class, 'scatola-media', 'Scatola media'),
            'weight' => '0.250',
            'unit' => 'pz',
            'type' => 'simple',
            'short_description' => $description,
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
    private static function optionImages(int $modelId): int
    {
        if ($modelId <= 0) {
            return 0;
        }

        $variant = self::rowsOfModel(ProductVariant::class, $modelId)[0] ?? [];
        $variantId = (int) ($variant['id'] ?? 0);

        if ($variantId <= 0) {
            return 0;
        }

        $created = self::image($modelId, trim((string) ($variant['name'] ?? '')) ?: 'Opzione', $variantId);

        foreach (self::rowsOfModel(Product::class, $modelId) as $product) {
            if ((int) ($product['product_variant_id'] ?? 0) !== $variantId) {
                continue;
            }

            // Basta la prima opzione di quel colore: le altre devono restare
            // senza foto propria, o il livello di mezzo non si vedrebbe.
            return $created + self::image(
                $modelId,
                trim((string) ($product['name'] ?? '')) ?: 'Opzione',
                $variantId,
                (int) ($product['id'] ?? 0)
            );
        }

        return $created;
    }

    /**
     * I valori di un attributo di prova, nell'ordine dei dati di prova.
     *
     * Solo i valori elencati qui: un «Colore» vero riusato può averne altri,
     * e gli articoli di prova non devono prenderseli.
     *
     * @return list<array{id: int, label: string}>
     */
    private static function valuesOf(string $ref): array
    {
        $definition = self::ATTRIBUTES[$ref];
        $attributeId = self::idOf(Attribute::class, $ref, $definition['name']);

        if ($attributeId === 0) {
            return [];
        }

        $existing = self::listOf(AttributeValue::find(['attribute_id' => $attributeId, 'deleted' => 'false']));
        $values = [];

        foreach ($definition['values'] as $value) {
            $row = self::valueNamed($existing, (string) $value['label']);

            if ($row !== null) {
                $values[] = ['id' => (int) $row['id'], 'label' => (string) ($row['label'] ?? '')];
            }
        }

        return $values;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private static function valueNamed(array $rows, string $label): ?array
    {
        foreach ($rows as $row) {
            if (DemoCode::sameName((string) ($row['label'] ?? ''), $label)) {
                return $row;
            }
        }

        return null;
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
     * I fornitori delle opzioni degli articoli di prova.
     *
     * Si scrivono solo sulle opzioni che non hanno ancora nessuna riga: rifare
     * i dati di prova non tocca un costo cambiato a mano. Una scheda di prova
     * che manca (le anagrafiche non create, o una scheda vera riusata al suo
     * posto) non fa riga.
     *
     * @return int righe create
     */
    private static function suppliers(): int
    {
        $created = 0;

        foreach (self::SUPPLIERS as $ref => $rows) {
            $productIds = array_values(array_filter(array_map(
                static fn (array $product): int => (int) ($product['id'] ?? 0),
                self::rowsOfModel(Product::class, self::modelId($ref))
            )));

            if ($productIds === []) {
                continue;
            }

            $links = ProductSuppliers::linksFor($productIds);
            // L'ultima opzione: la stessa che `seedStock()` mette sotto scorta.
            $last = $productIds[count($productIds) - 1];

            foreach ($productIds as $productId) {
                if (($links[$productId] ?? []) !== []) {
                    continue;
                }

                $own = $productId === $last ? (self::LAST_OPTION_SUPPLIERS[$ref] ?? $rows) : $rows;
                $created += ProductSuppliers::sync($productId, self::supplierRows($own));
            }
        }

        return $created;
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
     * Un attributo di prova con i suoi valori.
     *
     * Se c'è già (col segno, o uno vero con lo stesso nome) si aggiungono solo
     * i valori che mancano.
     *
     * @return int righe create, attributo e valori insieme
     */
    private static function attribute(string $ref): int
    {
        $definition = self::ATTRIBUTES[$ref];
        $name = $definition['name'];
        $created = 0;
        $found = self::find(Attribute::class, $ref, $name);

        if ($found === null) {
            $result = Attribute::create(array_merge([
                'code' => DemoCode::forModel(Attribute::class, $ref),
                'name' => $name,
                'slug' => Slug::unique($name, Attribute::class),
                'unit' => '',
                'is_filterable' => 'true',
                'is_visible' => 'true',
            ], $definition['row']));

            if (empty($result->success)) {
                return 0;
            }

            $created++;
            $attributeId = self::idOf(Attribute::class, $ref, $name);
        } else {
            $attributeId = $found['id'];

            if (!$found['demo']) {
                self::$reused[] = DemoCode::label(Attribute::class, $name);
            }
        }

        if ($attributeId <= 0) {
            return $created;
        }

        $existing = self::listOf(AttributeValue::find(['attribute_id' => $attributeId, 'deleted' => 'false']));
        $position = count($existing);

        foreach ($definition['values'] as $value) {
            if (self::valueNamed($existing, (string) $value['label']) !== null) {
                continue;
            }

            $result = AttributeValue::create(array_merge([
                'attribute_id' => $attributeId,
                'color' => '',
                'position' => ++$position,
            ], $value));

            $created += !empty($result->success) ? 1 : 0;
        }

        return $created;
    }

    /**
     * Toglie i dati di prova: le righe col segno e quelle con i vecchi nomi.
     *
     * Una tassonomia, un attributo o un imballaggio di prova che un articolo
     * vero usa ancora resta al suo posto, e una nota lo dice. Gli attributi si
     * staccano solo dagli articoli di prova, che se ne vanno per primi; i
     * valori aggiunti sotto un attributo vero restano (vedi la classe).
     *
     * @return int righe tolte
     */
    public static function clear(): int
    {
        $removed = 0;
        $kept = [];

        // Prima gli articoli: portano via prodotti, varianti, collegamenti e
        // foto, e sono loro a tenere occupati attributi e categorie.
        // I multiprodotti per primi: portano via le loro tre tabelle, e solo
        // dopo i loro componenti smettono di essere «in uso».
        $models = self::ours(ProductModel::class);
        usort($models, static fn (array $a, array $b): int => (($b['type'] ?? '') === 'bundle') <=> (($a['type'] ?? '') === 'bundle'));

        foreach ($models as $row) {
            $modelId = (int) $row['id'];

            // Un componente che un multiprodotto vero usa ancora resta, con la
            // sua giacenza: la storia di magazzino non si tocca.
            if (self::inBundle($modelId)) {
                $kept[] = DemoCode::label(ProductModel::class, (string) ($row['name'] ?? ''));
                continue;
            }

            // Quello che se ne va con l'articolo si conta prima: dopo non c'è
            // più niente da contare, e «tolte 19, create 49» non si spiega.
            $sotto = self::countOf(ProductVariant::class, $modelId)
                + self::countOf(Product::class, $modelId)
                + self::countOf(ProductImage::class, $modelId)
                + self::countOf(ProductModelCategory::class, $modelId)
                + self::supplierLinksOf($modelId);

            // La storia di magazzino di un articolo di prova se ne va con
            // lui: senza, la chiave esterna dei movimenti bloccherebbe la
            // pulizia. Fuori dai dati di prova il magazzino non si dimentica.
            $sotto += StockHistory::purge(array_map(
                static fn (array $product): int => (int) ($product['id'] ?? 0),
                self::rowsOfModel(Product::class, $modelId)
            ));

            if (self::remove(static fn () => ProductModelResource::deleteRecord($modelId))) {
                $removed += 1 + $sotto;
            } else {
                $kept[] = DemoCode::label(ProductModel::class, (string) ($row['name'] ?? ''));
            }
        }

        foreach (self::ours(Customization::class) as $row) {
            $id = (int) $row['id'];

            // Una ancora su un articolo vero (gli articoli di prova sono già
            // andati con i loro collegamenti) resta: lo rifiuta la Resource.
            if (!self::remove(static fn () => CustomizationResource::deleteRecord($id))) {
                $kept[] = DemoCode::label(Customization::class, (string) ($row['name'] ?? ''));
                continue;
            }

            $removed++;
        }

        foreach (self::ours(Package::class) as $row) {
            $id = (int) $row['id'];

            if (self::used(ProductModel::class, 'package_id', $id)
                || !self::remove(static fn () => Package::delete($id))) {
                $kept[] = DemoCode::label(Package::class, (string) ($row['name'] ?? ''));
                continue;
            }

            $removed++;
        }

        foreach (self::ours(Attribute::class) as $row) {
            $id = (int) $row['id'];

            // Gli articoli di prova sono già andati con i loro collegamenti:
            // chi lo usa ancora è un articolo vero, e non gli si toglie niente.
            if (self::attributeInUse($id)) {
                $kept[] = DemoCode::label(Attribute::class, (string) ($row['name'] ?? ''));
                continue;
            }

            // Prima i valori: la chiave esterna non lascia andare l'attributo.
            // **Anche quelli cancellati**: il repeater li segna `deleted` e
            // basta, la riga resta e il vincolo la vede. Un valore aggiunto a
            // mano e poi tolto bloccava tutta la pulizia.
            foreach (self::valuesOfAttribute($id) as $value) {
                $valueId = (int) $value['id'];
                $removed += self::remove(static fn () => AttributeValue::delete($valueId)) ? 1 : 0;
            }

            if (self::remove(static fn () => Attribute::delete($id))) {
                $removed++;
            } else {
                $kept[] = DemoCode::label(Attribute::class, (string) ($row['name'] ?? ''));
            }
        }

        foreach (self::ours(Tag::class) as $row) {
            $id = (int) $row['id'];

            if (self::used(ProductModelTag::class, 'tag_id', $id)
                || !self::remove(static fn () => Tag::delete($id))) {
                $kept[] = DemoCode::label(Tag::class, (string) ($row['name'] ?? ''));
                continue;
            }

            $removed++;
        }

        $removed += self::clearCategories($kept);

        foreach (self::ours(Brand::class) as $row) {
            $id = (int) $row['id'];

            if (self::used(ProductModel::class, 'brand_id', $id)
                || !self::remove(static fn () => Brand::delete($id))) {
                $kept[] = DemoCode::label(Brand::class, (string) ($row['name'] ?? ''));
                continue;
            }

            $removed++;
        }

        DemoData::note(DemoCode::keptNote($kept));

        return $removed;
    }

    /**
     * Le categorie di prova, dalle foglie in su.
     *
     * Si gira più volte: una categoria esce quando non ha più articoli né
     * figlie, e una figlia tolta a questo giro libera il padre al prossimo.
     * Una figlia vera sotto una categoria di prova la tiene al suo posto.
     *
     * @param list<string> $kept dove finiscono quelle rimaste
     * @return int righe tolte
     */
    private static function clearCategories(array &$kept): int
    {
        $pending = [];

        foreach (self::ours(Category::class) as $row) {
            $pending[(int) $row['id']] = (string) ($row['name'] ?? '');
        }

        $removed = 0;

        do {
            $progress = false;

            foreach ($pending as $id => $name) {
                if (self::used(ProductModelCategory::class, 'category_id', $id)
                    || self::used(Category::class, 'parent_id', $id)) {
                    continue;
                }

                if (self::remove(static fn () => Category::delete($id))) {
                    unset($pending[$id]);
                    $removed++;
                    $progress = true;
                }
            }
        } while ($progress && $pending !== []);

        foreach ($pending as $name) {
            $kept[] = DemoCode::label(Category::class, $name);
        }

        return $removed;
    }

    /** Qualche articolo, opzione o collegamento usa ancora l'attributo? */
    private static function attributeInUse(int $attributeId): bool
    {
        foreach (Attributes::LEVELS as $level => $ignored) {
            if (self::used(ProductAttributes::modelClass($level), 'attribute_id', $attributeId)) {
                return true;
            }
        }

        return false;
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

    /**
     * Le righe di quel Model che sono dati di prova: col segno o con un
     * vecchio nome.
     *
     * @return list<array<string, mixed>>
     */
    private static function ours(string $model): array
    {
        return array_values(array_filter(
            self::rows($model),
            static fn (array $row): bool => DemoCode::ours(
                $model,
                (string) ($row['code'] ?? ''),
                (string) ($row['name'] ?? '')
            )
        ));
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
        return self::listOf(AttributeValue::find(
            "attribute_id = ".$attributeId." AND (deleted = 'true' OR deleted = 'false')"
        ));
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
}
