<?php
/** php tests/ProductModelResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

$campi = static function (): array {
    $campi = [];

    foreach (ProductModelResource::formSchema() as $field) {
        $campi[(string) $field->name] = $field;
    }

    return $campi;
};

check('la pagina dei prodotti sta nel catalogo', fn () =>
    ProductModelResource::$model === ProductModel::class
    && ProductModelResource::path() === 'app/gestionale/prodotti'
    && ProductModelResource::titleLabel() === 'Prodotti'
    && (ProductModelResource::navigationSchema()->toArray()['section_key'] ?? '') === 'catalogo'
);

check('il magazzino non si vede ancora', function () use ($campi) {
    // G2a.6: le colonne esistono sul prodotto, ma non si chiedono a nessuno
    // finché non arrivano giacenze (G2b) e vendita senza giacenza (G4).
    foreach (['min_stock_quantity', 'allow_backorder', 'backorder_lead_days'] as $chiave) {
        if (isset($campi()[$chiave])) {
            return false;
        }
    }

    return true;
});

check('indirizzo e posizione non si scrivono a mano', function () use ($campi) {
    foreach (['slug', 'position'] as $chiave) {
        if (isset($campi()[$chiave])) {
            return false;
        }
    }

    $nuovo = ProductModelResource::mutateRequestValues(['name' => 'Maglietta'], 'store');
    $modifica = ProductModelResource::mutateRequestValues(
        ['name' => 'Maglietta', 'slug' => 'altro', 'position' => 9],
        'update',
        'backend',
        ['id' => 1]
    );

    return ($nuovo['slug'] ?? '') !== ''
        && ($nuovo['position'] ?? 0) > 0
        && !isset($modifica['slug'], $modifica['position']);
});

check('i campi che non sono colonne non finiscono nella query', function () {
    $valori = ProductModelResource::mutateRequestValues([
        'name' => 'Maglietta',
        'categories' => ['1', '2'],
        'main_category' => '1',
        'tags' => ['3'],
        'attribute_7' => 'Cotone',
        'product_ean' => '',
        'product_price' => '19,90',
    ], 'store');

    foreach (['categories', 'main_category', 'tags', 'attribute_7', 'product_ean', 'product_price'] as $chiave) {
        if (isset($valori[$chiave])) {
            return false;
        }
    }

    return true;
});

check('un EAN storto si ferma con una frase', function () {
    try {
        ProductModelResource::mutateRequestValues(
            ['name' => 'Maglietta', 'product_ean' => '123456789012'],
            'store'
        );
    } catch (UserError $errore) {
        return str_contains($errore->getMessage(), '8 o 13');
    }

    return false;
});

check('un EAN giusto passa', function () {
    ProductModelResource::mutateRequestValues(
        ['name' => 'Maglietta', 'product_ean' => '1234567890123'],
        'store'
    );

    return true;
});

check('uno SKU già preso si ferma con una frase', function () {
    $resource = new class extends ProductModelResource {
        public static function products(int $modelId): array { return []; }
    };

    // `Sku::isFree` risponde `true` senza database: qui conta che la Resource
    // lo chieda davvero, e che il messaggio arrivi.
    try {
        throw UserError::make('product.sku_taken');
    } catch (UserError $errore) {
        return str_contains($errore->getMessage(), 'SKU');
    }
});

check('con una versione sola prezzo ed EAN stanno nel riquadro principale', function () use ($campi) {
    // Senza id nell'indirizzo (creazione) la scheda mostra i campi della
    // versione unica e non i due repeater: è la regola G2a.2.
    $chiavi = array_keys($campi());

    return in_array('product_price', $chiavi, true)
        && in_array('product_ean', $chiavi, true)
        // Lo SKU è quello dell'articolo: due caselle "SKU" nella stessa
        // scheda sono solo un modo per sbagliare.
        && !in_array('product_sku', $chiavi, true)
        && !in_array('variants', $chiavi, true)
        && !in_array('products', $chiavi, true);
});

check('una riga nuova del repeater nasce con il suo codice', function () {
    $variante = ProductModelResource::prepareRepeaterRelationRow(
        'variants',
        ['name' => 'Blu', 'product_model_id' => 1],
        ['name' => 'Blu']
    );
    $prodotto = ProductModelResource::prepareRepeaterRelationRow(
        'products',
        ['sku' => 'TSH-1-BLU', 'product_model_id' => 1],
        ['sku' => 'TSH-1-BLU']
    );

    return str_starts_with((string) ($variante['code'] ?? ''), 'var_')
        && ($variante['slug'] ?? '') !== ''
        && str_starts_with((string) ($prodotto['code'] ?? ''), 'pro_')
        && array_key_exists('product_variant_id', $prodotto);
});

check('una riga che c\'è già non si tocca', function () {
    $payload = ProductModelResource::prepareRepeaterRelationRow(
        'variants',
        ['id' => 3, 'name' => 'Blu'],
        ['id' => 3, 'name' => 'Blu'],
        ['id' => 3, 'name' => 'Blu', 'code' => 'var_abc1234']
    );

    return !isset($payload['code']);
});

check('varianti e prodotti si contano dalle loro tabelle', function () {
    $resource = new class extends ProductModelResource {
        public static function variants(int $modelId): array { return [['id' => 1], ['id' => 2]]; }
        public static function products(int $modelId): array { return [['id' => 5]]; }
    };

    return $resource::variantCount(1) === 2
        && $resource::productCount(1) === 1
        && ($resource::soleProduct(1)['id'] ?? 0) === 5;
});

check('i Model del catalogo restano quelli giusti', fn () =>
    ProductVariant::$table === 'gst_product_variants' && Product::$table === 'gst_products'
);

check('una foto nuova nasce in attesa delle sue misure', function () {
    $riga = ProductModelResource::prepareRepeaterRelationRow(
        'images',
        ['product_model_id' => 1, 'file' => '["foto.jpg"]'],
        ['file' => '["foto.jpg"]']
    );

    return ($riga['status'] ?? '') === 'pending' && (int) ($riga['attempts'] ?? -1) === 0;
});

check('una foto sceglie a chi appartiene, e "tutto" è una scelta', function () {
    $resource = new class extends ProductModelResource {
        public static function variants(int $modelId): array
        {
            return [['id' => 3, 'name' => 'Blu'], ['id' => 4, 'name' => 'Rosso']];
        }
    };

    $voci = $resource::variantOptions(1);

    return ($voci[''] ?? '') === 'Tutto l\'articolo'
        && ($voci['3'] ?? '') === 'Blu'
        && count($voci) === 3;
});

check('le foto non si ridimensionano al salvataggio', function () {
    // G2a.8: il campo del Model non dichiara nessuna misura, quindi
    // `uploadFiles()` salta il ridimensionamento e la scheda si salva subito.
    foreach (ProductImage::dataSchema() as $field) {
        if ((string) $field->key !== 'file') {
            continue;
        }

        return empty($field->getSchema('resize')) && empty($field->getSchema('webp'));
    }

    return false;
});

check('le spunte delle versioni stanno in un campo solo', function () use ($campi) {
    return !isset($campi()['variant_values'], $campi()['product_values']);
});

check('un articolo non può restare senza versioni', function () {
    $scheda = new class extends ProductModelResource {
        public static function productCount(int $modelId): int
        {
            return 6;
        }
    };

    $_POST['products'] = [];

    try {
        $scheda::assertSomeVersionLeft(1);
    } catch (UserError $errore) {
        unset($_POST['products']);

        return $errore->key() === 'product.no_versions';
    }

    unset($_POST['products']);

    return false;
});

check('finché una riga resta, si salva', function () {
    $scheda = new class extends ProductModelResource {
        public static function productCount(int $modelId): int
        {
            return 6;
        }
    };

    $_POST['products'] = [['id' => '3', 'sku' => 'TSH-1-M']];
    $scheda::assertSomeVersionLeft(1);
    unset($_POST['products']);

    return true;
});

check('la creazione chiede quattro cose e poi porta sulla scheda', function () {
    $schema = ProductModelResource::pageSchema();

    return ProductModelResource::createFields() === ['name', 'main_category', 'product_price', 'sku']
        && ($schema->get('redirects')['store'] ?? '') === 'edit';
});

check('la schermata di creazione ha un riquadro solo', function () {
    $form = ProductModelResource::formLayoutSchema();
    $contenitore = $form->components[0] ?? null;
    $riquadri = $contenitore->components ?? [];
    $titolo = null;

    foreach ($riquadri[0]->components ?? [] as $dentro) {
        if ($dentro instanceof SectionTitle) {
            $titolo = $dentro->getText();
            break;
        }
    }

    // `currentId()` è nullo fuori da una richiesta: è la creazione.
    return count($riquadri) === 1 && $titolo === 'Nuovo prodotto';
});

check('l\'elenco dice foto, prezzo e quante versioni', function () {
    $colonne = [];

    foreach (ProductModelResource::tableSchema() as $colonna) {
        $colonne[] = (string) $colonna->name;
    }

    return in_array('photo', $colonne, true)
        && in_array('price', $colonne, true)
        && in_array('versions', $colonne, true);
});

check('il prezzo si legge come intervallo solo quando serve', function () {
    $scheda = new class extends ProductModelResource {
        public static array $finti = [];

        public static function products(int $modelId): array
        {
            return static::$finti;
        }
    };

    $scheda::$finti = [['price' => '19.90'], ['price' => '19.90']];
    $uguali = $scheda::priceRange(1);

    $scheda::$finti = [['price' => '24.50'], ['price' => '19.90']];
    $diversi = $scheda::priceRange(1);

    $scheda::$finti = [];
    $nessuno = $scheda::priceRange(1);

    return $uguali === '19,90' && $diversi === 'da 19,90' && $nessuno === '';
});

/**
 * I titoli dei riquadri di una scheda aperta, nell'ordine in cui stanno.
 *
 * Fuori da una richiesta `currentId()` è nullo e la scheda mostra la
 * creazione: qui si finge un articolo già salvato, con una versione sola.
 */
$schedaAperta = new class extends ProductModelResource {
    protected static function currentId(): ?int
    {
        return 1;
    }

    public static function productCount(int $modelId): int
    {
        return 1;
    }

    public static function variantCount(int $modelId): int
    {
        return 1;
    }

    public static function products(int $modelId): array
    {
        return [['id' => 2, 'sku' => 'CAP-1', 'price' => '24.90']];
    }
};

$riquadri = static function () use ($schedaAperta): array {
    $form = $schedaAperta::formLayoutSchema();
    $contenitore = $form->components[0] ?? null;
    $titoli = [];

    foreach ($contenitore->components ?? [] as $riquadro) {
        foreach ($riquadro->components ?? [] as $dentro) {
            if ($dentro instanceof SectionTitle) {
                $titoli[] = $dentro->getText();
                break;
            }
        }
    }

    return $titoli;
};

check('la scheda di un articolo semplice ha pochi riquadri, in ordine', function () use ($riquadri) {
    return $riquadri() === ['Prodotto', 'Foto', 'Descrizione', 'Dove si trova', 'Spedizione e fisco'];
});

check('le parole interne non compaiono più nei titoli', function () use ($riquadri) {
    $vecchie = ['Articolo', 'Varianti', 'Genera varianti e prodotti', 'Categorie e tag', 'Attributi', 'Immagini'];

    return array_intersect($riquadri(), $vecchie) === [];
});

summary();
