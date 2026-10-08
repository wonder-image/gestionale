<?php
/** php tests/integrazione/EliminazioniTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Purchasing\ProductSuppliers;
use Wonder\Plugin\Gestionale\Support\Stock\Alerts;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$conta = static function (string $model): int {
    $rows = $model::find(['deleted' => ['true', 'false']]);

    if (!is_array($rows) || $rows === []) {
        return 0;
    }

    return isset($rows['id']) ? 1 : count(array_filter($rows, 'is_array'));
};

/** Le tabelle che la prova tocca, contate prima e dopo l'annullamento. */
$tabelle = static fn (): array => array_map($conta, [
    ProductModel::class, ProductVariant::class, Product::class, ProductImage::class,
    StockAlert::class, Contact::class, ContactAddress::class,
]);

$prima = $tabelle();

/** Le righe di un modello, anche quelle nel cestino. @return list<array<string, mixed>> */
function righe(string $modelClass, array $where): array
{
    $rows = $modelClass::find($where + ['deleted' => ['true', 'false']]);

    if (!is_array($rows) || $rows === []) {
        return [];
    }

    return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
}

/** Un controllo dentro una transazione che poi si annulla: il sito resta com'era. */
function annullando(callable $prova): bool
{
    $esito = false;

    try {
        Transaction::run(static function () use ($prova, &$esito): void {
            $esito = (bool) $prova();

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    return $esito;
}

/** Un colore con due valori. @return array{id: int, values: list<int>} */
function colore(): array
{
    $creato = Attribute::create([
        'code' => Code::make(Attribute::class, Codes::ATTRIBUTE),
        'name' => 'Prova colore eliminazioni',
        'slug' => Slug::make('prova-colore-eliminazioni-'.uniqid()),
        'type' => 'color',
        'level' => 'variant',
        'unit' => '',
        'group_name' => '',
        'is_filterable' => 'true',
        'is_visible' => 'true',
        'position' => 90,
    ]);
    $id = (int) ($creato->insert_id ?? 0);
    $values = [];

    foreach (['Blu', 'Rosso'] as $position => $label) {
        $valore = AttributeValue::create([
            'attribute_id' => $id,
            'label' => $label,
            'color' => '',
            'position' => $position + 1,
        ]);
        $values[] = (int) ($valore->insert_id ?? 0);
    }

    // Il catalogo si legge una volta per richiesta: l'attributo nasce dopo.
    ProductModelResource::forgetCatalogCache();

    return ['id' => $id, 'values' => $values];
}

/**
 * Un articolo in blu e rosso, poi il rosso tolto dalla griglia e salvato.
 *
 * Il salvataggio passa dal core come fa la pagina: prima il repeater, che
 * mette nel cestino la riga che non vede più, poi `afterUpdate()`. Prima del
 * salvataggio `$primaDiSalvare` può dare al rosso quello che serve alla prova.
 *
 * @return array{model: int, kept: int, removed: int}
 */
function articoloConOpzioneTolta(string $sku, ?callable $primaDiSalvare = null): array
{
    $colore = colore();
    $modello = ProductModel::create([
        'code' => Code::make(ProductModel::class, Codes::MODEL),
        'name' => 'Prova eliminazione '.$sku,
        'slug' => Slug::make('prova-eliminazione-'.uniqid()),
        'sku' => $sku,
        'unit' => 'pz',
        'type' => 'simple',
        'visible' => 'true',
        'visible_online' => 'true',
        'position' => 1,
    ]);
    $modelId = (int) ($modello->insert_id ?? 0);
    Skeleton::forModel($modelId, 'Prova eliminazione '.$sku, $sku);

    $post = [
        'has_variants' => 'true',
        'axes_order' => (string) $colore['id'],
        'option_'.$colore['id'] => array_map('strval', $colore['values']),
        'product_price' => '10,00',
    ];
    ProductModelResource::saveExtras($modelId, $post, $sku);
    [$kept, $removed] = array_map(
        static fn (array $product): int => (int) $product['id'],
        ProductModelResource::products($modelId)
    );

    if ($primaDiSalvare !== null) {
        $primaDiSalvare($removed);
    }

    // La griglia senza la riga del rosso, e il rosso non più spuntato.
    $post['option_'.$colore['id']] = [(string) $colore['values'][0]];
    $post['products'] = ['0' => ['id' => (string) $kept]];
    ProductModelResource::syncRepeaterRelations($modelId, $post, [], 'update', 'backend');
    ProductModelResource::saveExtras($modelId, $post, '');

    return ['model' => $modelId, 'kept' => $kept, 'removed' => $removed];
}

check('un\'opzione tolta dalla griglia resta nel cestino, attaccata alla sua variante', fn () => annullando(function (): bool {
    $articolo = articoloConOpzioneTolta('ELM-1');
    // `findById()` non vede il cestino: si guarda con `righe()`.
    $tolta = righe(Product::class, ['id' => $articolo['removed']])[0] ?? [];

    return ($tolta['deleted'] ?? '') === 'true'
        && ProductVariant::findById((int) ($tolta['product_variant_id'] ?? 0)) !== []
        && count(righe(ProductVariant::class, ['product_model_id' => $articolo['model']])) === 2;
}));

check('l\'articolo con un\'opzione tolta dalla griglia si elimina, e non lascia righe', fn () => annullando(function (): bool {
    $articolo = articoloConOpzioneTolta('ELM-2');
    $esito = ProductModelResource::deleteRecord($articolo['model']);

    return !empty($esito->success)
        && righe(Product::class, ['product_model_id' => $articolo['model']]) === []
        && righe(ProductVariant::class, ['product_model_id' => $articolo['model']]) === []
        && righe(ProductModel::class, ['id' => $articolo['model']]) === [];
}));

check('con l\'opzione tolta se ne vanno anche il suo avviso di scorta e la sua foto', fn () => annullando(function (): bool {
    $articolo = articoloConOpzioneTolta('ELM-3', static function (int $productId): void {
        \Wonder\Plugin\Gestionale\Support\Stock\Thresholds::save($productId, [\Wonder\Plugin\Gestionale\Support\Stock\Locations::mainId() => 5.0]);
        Alerts::refresh($productId);
    });
    // La foto dopo il salvataggio: la griglia rimette in riga le foto che vede.
    ProductImage::create([
        'product_model_id' => $articolo['model'],
        'product_id' => $articolo['removed'],
        'file' => json_encode(['prova-eliminazione-'.uniqid().'.jpg']),
        'alt' => '',
        'position' => 1,
        'status' => 'pending',
        'attempts' => 0,
    ]);
    $avvisi = righe(StockAlert::class, ['product_id' => $articolo['removed']]);

    $esito = ProductModelResource::deleteRecord($articolo['model']);

    return $avvisi !== []
        && !empty($esito->success)
        && righe(StockAlert::class, ['product_id' => $articolo['removed']]) === []
        && righe(ProductImage::class, ['product_model_id' => $articolo['model']]) === [];
}));

check('con l\'opzione tolta se ne vanno anche i suoi fornitori', fn () => annullando(function (): bool {
    $fornitore = (int) (Contact::create([
        'type' => 'business',
        'business_name' => 'Prova Fornitore Eliminazioni Srl',
        'country' => 'IT',
        'is_customer' => 'false',
        'is_supplier' => 'true',
        'active' => 'true',
    ])->insert_id ?? 0);
    // L'eccezione scritta prima del salvataggio se ne va con la riga tolta
    // dalla griglia (`saveExtras()` → `dropRemovedOptions()`, P99)…
    $articolo = articoloConOpzioneTolta('ELM-5', static function (int $productId) use ($fornitore): void {
        ProductSuppliers::sync($productId, [['supplier_id' => $fornitore, 'cost' => '3']]);
    });
    $dopoLaGriglia = righe(ProductSupplier::class, ['product_id' => $articolo['removed']]);

    // …e una rimasta nel cestino (scritta dopo, come una di prima di questo
    // giro) se ne va con l'articolo: la chiave esterna non lo lascerebbe
    // eliminare.
    ProductSuppliers::sync($articolo['removed'], [['supplier_id' => $fornitore, 'cost' => '3']]);
    $legami = righe(ProductSupplier::class, ['product_id' => $articolo['removed']]);

    $esito = ProductModelResource::deleteRecord($articolo['model']);

    return $dopoLaGriglia === []
        && $legami !== []
        && !empty($esito->success)
        && righe(ProductSupplier::class, ['supplier_id' => $fornitore]) === [];
}));

check('un\'opzione tolta dalla griglia con dei movimenti ferma ancora l\'eliminazione', fn () => annullando(function (): bool {
    $articolo = articoloConOpzioneTolta('ELM-4', static function (int $productId): void {
        Stock::apply(['product_id' => $productId, 'quantity' => 3, 'reason' => 'inventory']);
    });

    try {
        ProductModelResource::deleteRecord($articolo['model']);
    } catch (RuntimeException $e) {
        // Il rifiuto per chi usa il gestionale, non l'errore del database.
        return str_contains($e->getMessage(), 'movimenti di magazzino')
            && !($e instanceof UserError)
            && righe(Product::class, ['id' => $articolo['removed']]) !== [];
    }

    return false;
}));

/** Una scheda della rubrica con due indirizzi di consegna. @return array{contact: int, addresses: list<int>} */
function schedaConIndirizzi(): array
{
    $creato = Contact::create([
        'type' => 'business',
        'business_name' => 'Prova Eliminazione Srl',
        'country' => 'IT',
        'is_customer' => 'true',
        'active' => 'true',
    ]);
    $contactId = (int) ($creato->insert_id ?? 0);
    $indirizzi = [];

    foreach (['Magazzino', 'Negozio'] as $position => $label) {
        $riga = ContactAddress::create([
            'contact_id' => $contactId,
            'label' => $label,
            'name' => 'Mario',
            'surname' => 'Rossi',
            'street' => 'Via Prova',
            'number' => (string) ($position + 1),
            'cap' => '20100',
            'city' => 'Milano',
            'country' => 'IT',
            'is_default' => $position === 0 ? 'true' : 'false',
            'position' => $position,
        ]);
        $indirizzi[] = (int) ($riga->insert_id ?? 0);
    }

    return ['contact' => $contactId, 'addresses' => $indirizzi];
}

check('una scheda con degli indirizzi di consegna si elimina, e i suoi indirizzi con lei', fn () => annullando(function (): bool {
    $scheda = schedaConIndirizzi();
    $esito = CustomerResource::deleteRecord($scheda['contact']);

    return !empty($esito->success)
        && righe(ContactAddress::class, ['contact_id' => $scheda['contact']]) === []
        && righe(Contact::class, ['id' => $scheda['contact']]) === [];
}));

check('anche con un indirizzo tolto dalla griglia', fn () => annullando(function (): bool {
    $scheda = schedaConIndirizzi();
    [$tenuto, $tolto] = $scheda['addresses'];
    CustomerResource::syncRepeaterRelations($scheda['contact'], [
        'addresses' => [(array) ContactAddress::findById($tenuto)],
    ], [], 'update', 'backend');
    $nelCestino = (righe(ContactAddress::class, ['id' => $tolto])[0]['deleted'] ?? '') === 'true';

    $esito = CustomerResource::deleteRecord($scheda['contact']);

    return $nelCestino
        && !empty($esito->success)
        && righe(ContactAddress::class, ['contact_id' => $scheda['contact']]) === [];
}));

check('dopo l\'annullamento il sito è come prima', fn () => $tabelle() === $prima);

summary();
