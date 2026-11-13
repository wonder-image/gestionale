<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use DateTimeImmutable;
use RuntimeException;
use Wonder\Plugin\Gestionale\Support\Stock\MovementPeriod;

/**
 * Quanti clienti (persone o aziende) e quanti nuovi questo mese, per i
 * riquadri della home. Cliente vuol dire `is_customer`: i referenti senza
 * ruolo e i fornitori non contano, come nel filtro «Clienti» degli elenchi.
 */
final class CustomerCounts
{
    /** La query su una tabella delle anagrafiche (`contacts` o `companies`). */
    public static function countSql(string $table, DateTimeImmutable $now): string
    {
        return 'SELECT COUNT(*) AS `totale`, COALESCE(SUM('.MovementPeriod::sql('questo-mese', 'creation', $now).'), 0) AS `mese`'
            .' FROM `'.$table."` WHERE `deleted` = 'false' AND `is_customer` = 'true'";
    }

    /**
     * @param class-string<\Wonder\App\Model> $model
     * @return array{totale: int, mese: int}
     */
    public static function of(string $model, ?DateTimeImmutable $now = null): array
    {
        $connection = $model::connection();
        $result = $connection->query(self::countSql($model::$table, $now ?? new DateTimeImmutable('now')));

        // Meglio nessun riquadro che uno zero falso.
        if ($result === false) {
            throw new RuntimeException('Conteggio clienti non riuscito: '.$connection->error);
        }

        $row = $result->fetch_assoc();

        return self::normalize(is_array($row) ? $row : []);
    }

    /** @return array{totale: int, mese: int} */
    public static function normalize(array $row): array
    {
        return [
            'totale' => max(0, (int) ($row['totale'] ?? 0)),
            'mese' => max(0, (int) ($row['mese'] ?? 0)),
        ];
    }
}
