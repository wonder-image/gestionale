<?php

/** Quello che serve alle prove dei pagamenti: lo stato dell'ordine e l'ultimo log. */

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;

/** Com'è messo l'ordine adesso, riletto dal database. */
function statoPagamento(int $ordine): string
{
    $riga = Order::findById($ordine);

    return is_array($riga) ? (string) $riga['payment_status'] : '';
}

/** L'ultimo cambio di stato registrato sull'ordine, per il campo dato. */
function ultimoLog(int $ordine, string $campo): array
{
    $righe = sqlSelect(
        OrderStatusLog::$table,
        "order_id = {$ordine} AND field = '{$campo}'",
        order: 'id',
        orderDirection: 'DESC'
    )->row;

    if (!is_array($righe) || $righe === []) {
        return [];
    }

    return isset($righe['id']) ? $righe : (array) reset($righe);
}
