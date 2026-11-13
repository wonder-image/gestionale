<?php
/** php tests/ListBulkActionsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;
use Wonder\Plugin\Gestionale\Resources\Promotions\CouponResource;
use Wonder\Plugin\Gestionale\Resources\Promotions\DiscountCampaignResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderActionResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Support\Backend\ListBulkActions;
use Wonder\Plugin\Gestionale\Support\Orders\OrderActions;

// Senza un core con le azioni di massa (wonder-image/app prima della 2.4) non c'è niente da provare.
if (!ListBulkActions::supported()) {
    echo "  - core senza azioni di massa: test saltato\n";
    summary();
}

$orders = [
    1 => ['id' => 1, 'status' => 'pending', 'channel' => 'web', 'type' => 'shipping'],
    2 => ['id' => 2, 'status' => 'cancelled', 'channel' => 'web', 'type' => 'shipping'],
    3 => ['id' => 3, 'status' => 'pending', 'channel' => 'web', 'type' => 'shipping'],
];
$load = static fn (int $id): array => $orders[$id] ?? [];
$calls = [];
$run = static function (string $action, int $id, int $userId) use (&$calls): array {
    $calls[] = [$action, $id, $userId];

    return $id === 3 ? ['ok' => false, 'message' => 'Giacenza insufficiente'] : ['ok' => true, 'message' => ''];
};

check('articoli: pubblica e bozza sulla colonna visible', function () {
    $keys = array_map(static fn ($a): string => $a->key(), ListBulkActions::products());

    return $keys === ['publish', 'draft']
        && ListBulkActions::products()[0]->getPermission() === 'update';
});

check('ordini: conferma, evadi, annulla, col permesso delle azioni sull\'ordine e senza transazione', function () use ($load, $run) {
    $actions = ListBulkActions::orders(OrderActionResource::class, $load, $run, []);
    $keys = array_map(static fn ($a): string => $a->key(), $actions);

    foreach ($actions as $action) {
        if ($action->getPermissionResource() !== OrderActionResource::class
            || $action->getPermission() !== 'update'
            || $action->usesTransaction()) {
            return false;
        }
    }

    return $keys === [OrderActions::CONFIRM, OrderActions::FULFILL, OrderActions::CANCEL]
        && $actions[2]->getVariant() === 'danger';
});

check('ogni ordine passa solo se l\'azione vale per il suo stato', function () use ($load, $run, &$calls) {
    $calls = [];
    $result = ListBulkActions::runOrders(OrderActions::CONFIRM, [1, 2, 3, 9], 7, $load, $run, []);

    return $result['done'] === [1]
        && array_keys($result['failed']) === [2, 3, 9]
        && $result['failed'][2] === 'gestionale.bulk.orders.unavailable'
        && $result['failed'][3] === 'Giacenza insufficiente'
        && $result['failed'][9] === 'gestionale.bulk.orders.not_found'
        && $calls === [[OrderActions::CONFIRM, 1, 7], [OrderActions::CONFIRM, 3, 7]];
});

check('il gestore della barra passa l\'utente all\'azione', function () use ($load, $run, &$calls) {
    $calls = [];
    $confirm = ListBulkActions::orders(OrderActionResource::class, $load, $run, [])[0];
    $result = ($confirm->getHandler())([1], OrderActionResource::class, (object) ['id' => 5]);

    return $result === ['done' => [1], 'failed' => []] && $calls === [[OrderActions::CONFIRM, 1, 5]];
});

check('ordini: nel menu della riga solo le azioni che valgono per il suo stato', function () use ($load, $run) {
    $actions = ListBulkActions::orders(OrderActionResource::class, $load, $run, []);

    // Core senza voci di riga: le azioni restano solo nella barra.
    if (!method_exists($actions[0], 'showsInRow')) {
        return true;
    }

    $shown = static fn (array $row): array => array_values(array_map(
        static fn ($a): string => $a->key(),
        array_filter($actions, static fn ($a): bool => $a->showsInRow($row))
    ));

    return $shown(['status' => 'pending', 'channel' => 'web']) === [OrderActions::CONFIRM, OrderActions::CANCEL]
        && $shown(['status' => 'confirmed', 'channel' => 'web', 'fulfillment_status' => 'unfulfilled']) === [OrderActions::FULFILL, OrderActions::CANCEL]
        && $shown(['status' => 'cancelled', 'channel' => 'web']) === []
        && $shown(['status' => 'draft', 'channel' => 'office']) === [];
});

check('coupon e campagne: attiva, disattiva ed elimina, solo nella barra', function () {
    $actions = ListBulkActions::promotions();
    $keys = array_map(static fn ($a): string => $a->key(), $actions);

    return $keys === ['activate', 'deactivate', 'delete']
        && $actions[0]->getPermission() === 'update'
        && $actions[2]->getPermission() === 'delete'
        && (!method_exists($actions[0], 'inRow') || !$actions[0]->inRow());
});

check('apply() mette le azioni nel layout', function () {
    $layout = ListBulkActions::apply(TableLayoutSchema::for(ProductResource::class), static fn (): array => ListBulkActions::products());

    return count($layout->get('bulk_actions')) === 2;
});

check('le liste vere: articoli, ordini, coupon e campagne; nessuna sulle opzioni', function () {
    $keys = static fn (TableLayoutSchema $layout): array => array_map(static fn ($a): string => $a->key(), (array) $layout->get('bulk_actions'));

    return $keys(ProductModelResource::tableLayoutSchema()) === ['publish', 'draft']
        && $keys(OrderResource::tableLayoutSchema()) === [OrderActions::CONFIRM, OrderActions::FULFILL, OrderActions::CANCEL]
        && $keys(ProductResource::tableLayoutSchema()) === []
        && $keys(CouponResource::tableLayoutSchema()) === ['activate', 'deactivate', 'delete']
        && $keys(DiscountCampaignResource::tableLayoutSchema()) === ['activate', 'deactivate', 'delete'];
});

check('la lista delle opzioni (ProductResource) ha il suo layout, senza barra', function () {
    $method = new ReflectionMethod(ProductResource::class, 'tableLayoutSchema');
    $source = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

    return $method->getDeclaringClass()->getName() === ProductResource::class
        && !str_contains($source, 'ListBulkActions');
});

check('i testi esistono in italiano', function () {
    $lang = json_decode((string) file_get_contents(__DIR__ . '/../lang/it/gestionale.json'), true);
    $bulk = $lang['bulk'] ?? [];

    foreach (['publish', 'draft'] as $key) {
        if (trim((string) ($bulk['products'][$key] ?? '')) === '') {
            return false;
        }
    }

    foreach (['activate', 'deactivate'] as $key) {
        if (trim((string) ($bulk['promotions'][$key] ?? '')) === '') {
            return false;
        }
    }

    foreach (['confirm', 'fulfill', 'cancel', 'confirm_confirm', 'fulfill_confirm', 'cancel_confirm', 'not_found', 'unavailable', 'failed'] as $key) {
        if (trim((string) ($bulk['orders'][$key] ?? '')) === '') {
            return false;
        }
    }

    return true;
});

summary();
