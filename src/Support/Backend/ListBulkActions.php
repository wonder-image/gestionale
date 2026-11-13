<?php

namespace Wonder\Plugin\Gestionale\Support\Backend;

use Closure;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Backend\Table\BulkAction;
use Wonder\Plugin\Gestionale\Support\Orders\OrderActions;

/**
 * Le azioni di massa delle liste del gestionale (`bulkActions()` del core):
 * pubblica o metti in bozza più articoli, conferma, evadi o annulla più ordini.
 *
 * Su un core senza azioni di massa la lista resta com'era.
 */
final class ListBulkActions
{
    /** Le azioni sugli ordini, nell'ordine dei bottoni: annullare per ultimo. */
    public const ORDER_ACTIONS = [OrderActions::CONFIRM, OrderActions::FULFILL, OrderActions::CANCEL];

    private const ORDER_ICONS = [
        OrderActions::CONFIRM => 'bi bi-check2-circle',
        OrderActions::FULFILL => 'bi bi-box-seam',
        OrderActions::CANCEL => 'bi bi-x-circle',
    ];

    /** Il core ha le azioni di massa? */
    public static function supported(): bool
    {
        return class_exists(BulkAction::class) && method_exists(TableLayoutSchema::class, 'bulkActions');
    }

    /** @param Closure(): list<BulkAction> $actions */
    public static function apply(TableLayoutSchema $layout, Closure $actions): TableLayoutSchema
    {
        return self::supported() ? $layout->bulkActions($actions()) : $layout;
    }

    /**
     * Pubblica / Metti in bozza: la colonna `visible`, come la pillola
     * cliccabile della lista.
     *
     * @return list<BulkAction>
     */
    public static function products(): array
    {
        return [
            BulkAction::toggle('publish', 'visible', true)
                ->label(self::text('products.publish'))
                ->icon('bi bi-eye'),
            BulkAction::toggle('draft', 'visible', false)
                ->label(self::text('products.draft'))
                ->icon('bi bi-eye-slash'),
        ];
    }

    /**
     * Conferma, evadi, annulla. Il permesso è quello dei bottoni della
     * scheda (`$permissionResource`, la pagina delle azioni sull'ordine);
     * ogni ordine passa da `$run` con la sua transazione.
     *
     * @param class-string $permissionResource
     * @param Closure(int): array<string, mixed> $load l'ordine visibile, [] se non c'è
     * @param Closure(string, int, int): array{ok: bool, message: string} $run
     * @param array<string, bool> $features
     * @return list<BulkAction>
     */
    public static function orders(string $permissionResource, Closure $load, Closure $run, array $features): array
    {
        $out = [];

        foreach (self::ORDER_ACTIONS as $key) {
            $action = BulkAction::make($key)
                ->label(self::text('orders.'.$key))
                ->icon(self::ORDER_ICONS[$key])
                ->permission('update', $permissionResource)
                ->transaction(false)
                ->handle(static fn (array $ids, string $resourceClass, ?object $user): array => self::runOrders(
                    $key,
                    $ids,
                    is_object($user) ? (int) ($user->id ?? 0) : 0,
                    $load,
                    $run,
                    $features
                ));

            if ($key === OrderActions::CANCEL) {
                $action->variant('danger')->confirm(self::text('orders.cancel_confirm'));
            } else {
                $action->confirm(self::text('orders.'.$key.'_confirm'));
            }

            $out[] = $action;
        }

        return $out;
    }

    /**
     * Un ordine alla volta: solo se l'azione vale per il suo stato (come i
     * bottoni della scheda), altrimenti resta fra i non riusciti col motivo.
     *
     * @param list<int> $ids
     * @param Closure(int): array<string, mixed> $load
     * @param Closure(string, int, int): array{ok: bool, message: string} $run
     * @param array<string, bool> $features
     * @return array{done: list<int>, failed: array<int, string>}
     */
    public static function runOrders(string $action, array $ids, int $userId, Closure $load, Closure $run, array $features): array
    {
        $done = [];
        $failed = [];

        foreach ($ids as $id) {
            $order = $load($id);

            if ($order === []) {
                $failed[$id] = self::text('orders.not_found');
                continue;
            }

            if (!in_array($action, OrderActions::available($order, $features), true)) {
                $failed[$id] = self::text('orders.unavailable');
                continue;
            }

            $result = $run($action, $id, $userId);

            if (!empty($result['ok'])) {
                $done[] = $id;
            } else {
                $failed[$id] = trim((string) ($result['message'] ?? '')) !== ''
                    ? (string) $result['message']
                    : self::text('orders.failed');
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    private static function text(string $key): string
    {
        $full = 'gestionale.bulk.'.$key;
        $value = function_exists('__t') ? __t($full) : null;

        return is_string($value) && $value !== '' ? $value : $full;
    }
}
