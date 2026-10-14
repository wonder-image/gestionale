<?php
/**
 * php tests/supporto/adotta-prove-del-browser.php [--esegui]
 *
 * Sul sito di prova le prove fatte nel browser (checkout, carrelli,
 * abbonamenti) usano gli articoli e i piani dei dati di prova: la pulizia li
 * lascia al loro posto perché «ancora in uso», e i test d'integrazione che
 * partono da un sito pulito falliscono.
 *
 * Questo script dà il segno dei dati di prova (`ord_demo-browser-<id>`,
 * `sub_demo-browser-<id>`) agli ordini e ai carrelli non di prova con un
 * articolo di prova, e agli abbonamenti non di prova su un piano di prova.
 * Dopo, `php forge gestionale:demo --fresh` (o la pulizia dei test) li toglie
 * come gli altri dati di prova, con la merce che torna al suo posto.
 *
 * Solo per il sito di prova. Senza `--esegui` dice cosa farebbe e non cambia
 * niente.
 */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Seeding\DemoCode;
use Wonder\Sql\Transaction;

$esegui = in_array('--esegui', $argv, true);

/** @return list<array<string, mixed>> */
function righe(mixed $found): array
{
    if (!is_array($found) || $found === []) {
        return [];
    }

    return isset($found['id']) ? [$found] : array_values(array_filter($found, 'is_array'));
}

/** @return list<int> */
function ids(array $rows): array
{
    return array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], $rows)));
}

/** Le righe col segno dei dati di prova, dal prefisso del codice. */
function diProva(string $model): array
{
    $prefisso = str_replace('_', '\\_', DemoCode::prefixOf($model));

    return array_values(array_filter(
        righe($model::find("`code` LIKE '".$prefisso."demo-%' AND `deleted` IN ('true', 'false')")),
        static fn (array $row): bool => DemoCode::is((string) ($row['code'] ?? ''))
    ));
}

// Gli ordini e i carrelli non di prova con almeno una riga di un articolo di prova.
$modelli = ids(diProva(ProductModel::class));
$prodotti = $modelli === [] ? [] : ids(righe(Product::find('`product_model_id` IN ('.implode(',', $modelli).") AND `deleted` IN ('true', 'false')")));
$ordini = [];

if ($prodotti !== []) {
    $perOrdine = array_unique(array_map(
        static fn (array $riga): int => (int) $riga['order_id'],
        righe(OrderItem::find('`product_id` IN ('.implode(',', $prodotti).')'))
    ));

    foreach ($perOrdine as $orderId) {
        $ordine = Order::findById($orderId);

        if (is_array($ordine) && $ordine !== [] && !DemoCode::is((string) ($ordine['code'] ?? ''))) {
            $ordini[] = $ordine;
        }
    }
}

// Gli abbonamenti non di prova su un prezzo di un piano di prova.
$piani = ids(diProva(Plan::class));
$prezzi = $piani === [] ? [] : ids(righe(PlanPrice::find('`plan_id` IN ('.implode(',', $piani).") AND `deleted` IN ('true', 'false')")));
$abbonamenti = $prezzi === [] ? [] : array_values(array_filter(
    righe(Subscription::find('`plan_price_id` IN ('.implode(',', $prezzi).") AND `deleted` IN ('true', 'false')")),
    static fn (array $row): bool => !DemoCode::is((string) ($row['code'] ?? ''))
));

echo ($esegui ? 'Adozione' : 'Prova (niente cambia; per farlo davvero aggiungi --esegui)').":\n";
echo '· ordini e carrelli: '.($ordini === [] ? 'nessuno' : implode(', ', array_map(
    static fn (array $o): string => (trim((string) ($o['order_number'] ?? '')) ?: '#'.$o['id']).' ('.$o['stage'].', '.$o['status'].')',
    $ordini
)))."\n";
echo '· abbonamenti: '.($abbonamenti === [] ? 'nessuno' : implode(', ', array_map(
    static fn (array $s): string => (string) ($s['code'] ?? '#'.$s['id']).' ('.($s['status'] ?? '').')',
    $abbonamenti
)))."\n";

if (!$esegui) {
    exit(0);
}

Transaction::run(static function () use ($ordini, $abbonamenti): void {
    foreach ($ordini as $ordine) {
        Order::query()->Update(Order::$table, ['code' => DemoCode::make(DemoCode::prefixOf(Order::class), 'browser-'.(int) $ordine['id'])], 'id', (int) $ordine['id']);
    }

    foreach ($abbonamenti as $abbonamento) {
        Subscription::query()->Update(Subscription::$table, ['code' => DemoCode::make(DemoCode::prefixOf(Subscription::class), 'browser-'.(int) $abbonamento['id'])], 'id', (int) $abbonamento['id']);
    }
});

echo "Fatto. Ora: php forge gestionale:demo --fresh (dalla cartella del sito di prova).\n";
