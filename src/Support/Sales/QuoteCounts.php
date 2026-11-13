<?php

namespace Wonder\Plugin\Gestionale\Support\Sales;

use DateTimeImmutable;
use RuntimeException;
use Wonder\Plugin\Gestionale\Models\Sales\Order;

/**
 * I preventivi su cui c'è da fare qualcosa, per il riquadro della home:
 * le bozze da inviare, gli inviati che aspettano il cliente e, fra questi,
 * quelli che scadono entro `EXPIRING_DAYS` giorni. Accettato, un preventivo
 * diventa un ordine e qui non conta più.
 */
final class QuoteCounts
{
    /** Entro questi giorni un preventivo inviato si dice «in scadenza». */
    public const EXPIRING_DAYS = 7;

    /** Le stesse righe dell'elenco Preventivi. */
    public const BASE = "`deleted` = 'false' AND `stage` = 'quote'";

    public static function countSql(DateTimeImmutable $now): string
    {
        // Scade «entro 7 giorni» anche quello che scade il settimo giorno.
        $limit = $now->modify('+'.(self::EXPIRING_DAYS + 1).' days')->format('Y-m-d').' 00:00:00';

        return "SELECT COALESCE(SUM(`status` = 'draft'), 0) AS `da_inviare`,"
            ." COALESCE(SUM(`status` = 'sent'), 0) AS `in_attesa`,"
            ." COALESCE(SUM(`status` = 'sent' AND `quote_expires_at` > '1000-01-01' AND `quote_expires_at` < '{$limit}'), 0) AS `in_scadenza`"
            .' FROM `'.Order::$table.'` WHERE '.self::BASE;
    }

    /** @return array{da_inviare: int, in_attesa: int, in_scadenza: int} */
    public static function counts(?DateTimeImmutable $now = null): array
    {
        $connection = Order::connection();
        $result = $connection->query(self::countSql($now ?? new DateTimeImmutable('now')));

        // Meglio nessun riquadro che uno zero verde falso.
        if ($result === false) {
            throw new RuntimeException('Conteggio preventivi non riuscito: '.$connection->error);
        }

        $row = $result->fetch_assoc();

        return self::normalize(is_array($row) ? $row : []);
    }

    /** @return array{da_inviare: int, in_attesa: int, in_scadenza: int} */
    public static function normalize(array $row): array
    {
        $counts = [];

        foreach (['da_inviare', 'in_attesa', 'in_scadenza'] as $key) {
            $counts[$key] = max(0, (int) ($row[$key] ?? 0));
        }

        return $counts;
    }
}
