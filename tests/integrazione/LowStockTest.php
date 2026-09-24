<?php
/** php tests/integrazione/LowStockTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Extensions\Extensions;
use Wonder\Plugin\Gestionale\Extensions\GestionaleExtension;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Stock\Alerts;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockNotifier;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockReport;
use Wonder\Plugin\Gestionale\Support\Stock\NegativeStock;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

final class FermaLaScorta extends GestionaleExtension
{
    public function beforeEmailSend(string $key, array $message): array
    {
        if ($key === LowStockNotifier::KEY) {
            $message['to'] = [];
        }

        return $message;
    }
}

// Qui il sito è avviato e `sendMail()` c'è: senza un postino finto partirebbero
// email vere. Chi dimentica di metterne uno lo scopre subito.
Mailer::useTransport(static fn (): bool => throw new LogicException('Nessuna email vera dai test.'));
// Le estensioni del sito non devono cambiare le email dei test.
Extensions::use([]);

/** @var list<array{0: string, 1: string}> */
$posta = [];
/** @var list<string> */
$segnalazioni = [];

/** Un postino finto che risponde sempre così. */
function postino(bool $esito): void
{
    Mailer::useTransport(static function (string $to, string $subject) use ($esito): bool {
        $GLOBALS['posta'][] = [$to, $subject];

        return $esito;
    });
}

LowStockNotifier::reportUsing(static function (string $message): void {
    $GLOBALS['segnalazioni'][] = $message;
});

/**
 * Prepara un giro pulito dentro la transazione: gli avvisi del sito già da
 * mandare si segnano come mandati, così nell'email ci sono solo quelli della
 * prova; e i destinatari sono quelli chiesti.
 */
function giroDiProva(string $destinatari): void
{
    foreach (LowStockReport::pending() as $avviso) {
        StockAlert::update(['notified_at' => date('Y-m-d H:i:s')], (int) $avviso['id']);
    }

    // Dritto sulla tabella: la riga delle impostazioni è una sola, la 1.
    MerchantSetting::query()->Update(MerchantSetting::$table, ['low_stock_emails' => $destinatari], 'id', 1);
    $GLOBALS['posta'] = [];
    $GLOBALS['segnalazioni'] = [];
}

/** L'articolo di prova con la giacenza sotto la scorta: l'avviso è aperto. */
function sottoScorta(string $sku): int
{
    [$modelId, $productId] = articoloDiProva($sku, '3');
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_min_stock' => '5'], $sku);

    return $productId;
}

// Le funzionalità si leggono una volta per richiesta: si accende solo quella
// che serve, lasciando le altre come le ha il sito.
Gestionale::feature('low_stock_alerts');
$stato = new ReflectionProperty(Gestionale::class, 'features');
$stato->setValue(null, array_merge((array) $stato->getValue(), ['low_stock_alerts' => true]));

/**
 * Un articolo senza varianti. Con una giacenza scritta nasce col suo carico
 * iniziale; senza, non ha nessun movimento e si può ancora eliminare.
 *
 * @return array{0: int, 1: int} id del modello e dell'unico prodotto
 */
function articoloDiProva(string $sku, string $giacenza = ''): array
{
    $modello = ProductModel::create([
        'code' => Code::make(ProductModel::class, Codes::MODEL),
        'name' => 'Prova scorta '.$sku,
        'slug' => Slug::make('prova-scorta-'.uniqid()),
        'sku' => $sku,
        'unit' => 'pz',
        'type' => 'simple',
        'visible' => 'true',
        'visible_online' => 'true',
        'position' => 1,
    ]);
    $modelId = (int) ($modello->insert_id ?? 0);
    $scheletro = Skeleton::forModel($modelId, 'Prova scorta '.$sku, $sku);

    ProductModelResource::forgetCatalogCache();
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_stock' => $giacenza], $sku, [], true);

    return [$modelId, (int) $scheletro['product_id']];
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

check('alzare la soglia sopra il disponibile apre l\'avviso subito, senza movimenti', fn () => annullando(function (): bool {
    [$modelId, $productId] = articoloDiProva('LOW-1', '3');
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_min_stock' => '5'], 'LOW-1');
    $avviso = Alerts::openRow($productId);
    $prodotto = Product::findById($productId);

    return $avviso !== []
        && (float) $avviso['threshold'] === 5.0
        && (float) $avviso['quantity_at_alert'] === 3.0
        && (float) ($prodotto['min_stock_quantity'] ?? 0) === 5.0;
}));

check('abbassarla sotto il disponibile chiude l\'avviso', fn () => annullando(function (): bool {
    [$modelId, $productId] = articoloDiProva('LOW-2', '3');
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_min_stock' => '5'], 'LOW-2');
    $aperto = Alerts::openRow($productId) !== [];
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_min_stock' => '2'], 'LOW-2');

    return $aperto && Alerts::openRow($productId) === [];
}));

check('con la soglia a zero l\'avviso aperto si chiude', fn () => annullando(function (): bool {
    [$modelId, $productId] = articoloDiProva('LOW-3', '1');
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_min_stock' => '4'], 'LOW-3');
    $aperto = Alerts::openRow($productId) !== [];
    ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_min_stock' => ''], 'LOW-3');

    return $aperto && Alerts::openRow($productId) === [];
}));

check('un articolo che nasce già sotto la sua scorta minima ha l\'avviso', fn () => annullando(function (): bool {
    $modello = ProductModel::create([
        'code' => Code::make(ProductModel::class, Codes::MODEL),
        'name' => 'Prova scorta LOW-4',
        'slug' => Slug::make('prova-scorta-'.uniqid()),
        'sku' => 'LOW-4',
        'unit' => 'pz',
        'type' => 'simple',
        'visible' => 'true',
        'visible_online' => 'true',
        'position' => 1,
    ]);
    $modelId = (int) ($modello->insert_id ?? 0);
    $scheletro = Skeleton::forModel($modelId, 'Prova scorta LOW-4', 'LOW-4');
    ProductModelResource::forgetCatalogCache();
    ProductModelResource::saveExtras(
        $modelId,
        ['has_variants' => 'false', 'product_stock' => '2', 'product_min_stock' => '4'],
        'LOW-4',
        [],
        true
    );

    return Alerts::openRow((int) $scheletro['product_id']) !== [];
}));

check('un articolo con l\'avviso aperto e nessun movimento si elimina', fn () => annullando(function (): bool {
    [$modelId, $productId] = articoloDiProva('LOW-5');
    Product::update(['min_stock_quantity' => '5.000'], $productId);
    $aperto = Alerts::refresh($productId) === 'open';
    $esito = ProductModelResource::deleteRecord($modelId);
    $rimasti = StockAlert::find(['product_id' => $productId]);

    return $aperto && !empty($esito->success) && (!is_array($rimasti) || $rimasti === []);
}));

check('anche una versione con l\'avviso aperto si elimina dalla sua scheda', fn () => annullando(function (): bool {
    [, $productId] = articoloDiProva('LOW-6');
    Product::update(['min_stock_quantity' => '5.000'], $productId);
    Alerts::refresh($productId);
    $esito = ProductResource::deleteRecord($productId);

    return !empty($esito->success) && Product::findById($productId) === [];
}));

check('una email sola per i prodotti sotto scorta, poi più niente', fn () => annullando(function (): bool {
    giroDiProva('magazzino@negozio.test, capo@negozio.test');
    postino(true);
    sottoScorta('LOW-10');
    sottoScorta('LOW-11');

    $primo = LowStockNotifier::run();
    $email = $GLOBALS['posta'];
    $secondo = LowStockNotifier::run();

    return $primo['status'] === Mailer::SENT
        && array_column($primo['items'], 'sku') === ['LOW-10', 'LOW-11']
        && $email === [
            ['magazzino@negozio.test', '2 prodotti sotto scorta'],
            ['capo@negozio.test', '2 prodotti sotto scorta'],
        ]
        && $secondo['status'] === LowStockNotifier::NOTHING
        && count($GLOBALS['posta']) === 2;
}));

check('senza destinatari l\'avviso aspetta, e parte appena ce n\'è uno', fn () => annullando(function (): bool {
    giroDiProva('');
    postino(true);
    $productId = sottoScorta('LOW-12');

    $senza = LowStockNotifier::run();
    $aspetta = trim((string) (Alerts::openRow($productId)['notified_at'] ?? '')) === '';
    MerchantSetting::query()->Update(MerchantSetting::$table, ['low_stock_emails' => 'magazzino@negozio.test'], 'id', 1);
    $con = LowStockNotifier::run();

    return $senza['status'] === LowStockNotifier::NO_RECIPIENTS
        && $aspetta
        && $GLOBALS['posta'] === [['magazzino@negozio.test', '1 prodotto sotto scorta']]
        && $con['status'] === Mailer::SENT;
}));

check('se la posta non parte si riprova al giro dopo, e lo sa chi sviluppa', fn () => annullando(function (): bool {
    giroDiProva('magazzino@negozio.test');
    postino(false);
    $productId = sottoScorta('LOW-13');

    $fallito = LowStockNotifier::run();
    $aspetta = trim((string) (Alerts::openRow($productId)['notified_at'] ?? '')) === '';
    postino(true);
    $riuscito = LowStockNotifier::run();

    return $fallito['status'] === Mailer::FAILED
        && $fallito['failed'] === ['magazzino@negozio.test']
        && count($GLOBALS['segnalazioni']) === 1
        && $aspetta
        && $riuscito['status'] === Mailer::SENT
        && trim((string) (Alerts::openRow($productId)['notified_at'] ?? '')) !== '';
}));

check('se il sito ferma l\'email con l\'hook, l\'avviso non torna a ogni giro', fn () => annullando(function (): bool {
    giroDiProva('magazzino@negozio.test');
    postino(true);
    Extensions::use([FermaLaScorta::class]);

    try {
        $productId = sottoScorta('LOW-14');
        $esito = LowStockNotifier::run();
    } finally {
        Extensions::use([]);
    }

    return $esito['status'] === Mailer::CANCELLED
        && $GLOBALS['posta'] === []
        && trim((string) (Alerts::openRow($productId)['notified_at'] ?? '')) !== '';
}));

check('l\'avviso di un prodotto tolto dalla griglia si chiude e non si manda', fn () => annullando(function (): bool {
    giroDiProva('magazzino@negozio.test');
    postino(true);
    $productId = sottoScorta('LOW-15');
    $avviso = Alerts::openRow($productId);
    // Come lo toglie il backend: `Resource` scrive `deleted = 'true'` sulla riga.
    Product::query()->Update(Product::$table, ['deleted' => 'true'], 'id', $productId);

    $esito = LowStockNotifier::run();
    $riga = StockAlert::findById((int) $avviso['id']);

    return $esito['status'] === LowStockNotifier::NOTHING
        && $esito['resolved'] >= 1
        && $GLOBALS['posta'] === []
        && trim((string) ($riga['resolved_at'] ?? '')) !== '';
}));

check('un prodotto tornato sopra la soglia senza movimenti non si segnala, e l\'avviso si chiude', fn () => annullando(function (): bool {
    giroDiProva('magazzino@negozio.test');
    postino(true);
    $productId = sottoScorta('LOW-16');
    // La soglia cambiata senza passare dalla scheda: nessuno chiude l'avviso.
    Product::query()->Update(Product::$table, ['min_stock_quantity' => '1.000'], 'id', $productId);

    $esito = LowStockNotifier::run();

    return $esito['status'] === LowStockNotifier::NOTHING
        && $esito['closed'] >= 1
        && $GLOBALS['posta'] === []
        && Alerts::openRow($productId) === [];
}));

check('l\'anteprima dice cosa partirebbe e non manda né scrive niente', fn () => annullando(function (): bool {
    giroDiProva('magazzino@negozio.test');
    postino(true);
    $productId = sottoScorta('LOW-17');

    $esito = LowStockNotifier::run(true);

    return $esito['status'] === LowStockNotifier::PREVIEW
        && array_column($esito['items'], 'sku') === ['LOW-17']
        && $esito['to'] === ['magazzino@negozio.test']
        && $esito['subject'] === '1 prodotto sotto scorta'
        && $GLOBALS['posta'] === []
        && trim((string) (Alerts::openRow($productId)['notified_at'] ?? '')) === '';
}));

check('a funzionalità spenta non parte niente', fn () => annullando(function () use ($stato): bool {
    giroDiProva('magazzino@negozio.test');
    postino(true);
    sottoScorta('LOW-18');
    $stato->setValue(null, array_merge((array) $stato->getValue(), ['low_stock_alerts' => false]));

    try {
        $esito = LowStockNotifier::run();
    } finally {
        $stato->setValue(null, array_merge((array) $stato->getValue(), ['low_stock_alerts' => true]));
    }

    return $esito['status'] === LowStockNotifier::DISABLED && $GLOBALS['posta'] === [];
}));

check('una giacenza sotto zero finisce fra le cose da controllare', function () use ($stato): bool {
    // Sotto zero si va solo con la vendita senza giacenza: la si accende per
    // questo controllo e poi si rimette come l'ha il sito.
    $prima = $stato->getValue();
    $stato->setValue(null, array_merge((array) $prima, ['backorders' => true]));

    try {
        return annullando(function (): bool {
            [, $productId] = articoloDiProva('NEG-1', '2');
            Stock::apply(['product_id' => $productId, 'quantity' => -5, 'reason' => Reasons::DEFAULT]);
            $trovati = array_values(array_filter(
                NegativeStock::items(),
                static fn (array $item): bool => $item['product_id'] === $productId
            ));

            return count($trovati) === 1
                && $trovati[0]['quantity'] === -3.0
                && $trovati[0]['locations'] === 1
                && $trovati[0]['sku'] === 'NEG-1';
        });
    } finally {
        $stato->setValue(null, $prima);
    }
});

Extensions::use(null);
Mailer::useTransport(null);
LowStockNotifier::reportUsing(null);

summary();
