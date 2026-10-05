<?php

namespace Wonder\Plugin\Gestionale\Support\Shipping;

use Wonder\Plugin\Gestionale\Models\Shipping\Shipment;

/**
 * Le spedizioni che chiedono un'occhiata: le stesse per i riquadri della
 * bacheca e per il filtro «Situazione» dell'elenco, così il numero del
 * riquadro e le righe che si aprono non possono dire cose diverse.
 *
 * - da controllare: una consegna andata male (`failed_attempt`) o con un
 *   problema (`exception`);
 * - non consegnate: partite da più di `STALE_DAYS` giorni e ancora per strada.
 */
final class ShipmentAlerts
{
    /** Oltre questi giorni dalla partenza una spedizione in viaggio si segnala. */
    public const STALE_DAYS = 7;

    public static function toCheck(): int
    {
        return self::count(self::toCheckCondition());
    }

    public static function notDelivered(): int
    {
        return self::count(self::notDeliveredCondition());
    }

    public static function toCheckCondition(): string
    {
        return "`type` = 'delivery' AND `status` IN ('failed_attempt', 'exception')";
    }

    /** Parte da più di sette giorni: sette esatti non bastano. */
    public static function notDeliveredCondition(): string
    {
        $limit = date('Y-m-d H:i:s', time() - self::STALE_DAYS * 86400);

        return "`type` = 'delivery' AND `status` IN ('in_transit', 'out_for_delivery') AND `shipped_at` IS NOT NULL AND `shipped_at` < '{$limit}'";
    }

    private static function count(string $condition): int
    {
        $rows = Shipment::find("({$condition}) AND `deleted` = 'false'");

        if (!is_array($rows) || $rows === []) {
            return 0;
        }

        return array_key_exists('id', $rows) ? 1 : count(array_filter($rows, 'is_array'));
    }
}
