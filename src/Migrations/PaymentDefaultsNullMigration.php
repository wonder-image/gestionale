<?php

namespace Wonder\Plugin\Gestionale\Migrations;

use mysqli;
use RuntimeException;
use Wonder\App\Module\Contracts\ModuleMigration;

/**
 * Metodo e termini di pagamento delle anagrafiche: chi non li ha resta
 * vuoto. Le schede salvate prima avevano `0` al posto del vuoto; qui
 * diventano `NULL`, su persone e aziende.
 *
 * Tocca solo le colonne che ci sono: una tabella o una colonna che manca
 * la crea poi la sincronizzazione, già vuota. Gira una volta sola: alla
 * fine scrive il suo nome in `gst_migrations`.
 */
final class PaymentDefaultsNullMigration implements ModuleMigration
{
    public const NAME = 'payment_defaults_null';
    public const LOG_TABLE = DocumentCompanyMigration::LOG_TABLE;
    public const TABLES = ['contacts', 'companies'];
    public const COLUMNS = ['payment_method_id', 'payment_term_id'];

    public static function run(mysqli $connection): array
    {
        if (self::alreadyRun($connection)) {
            return [];
        }

        $columns = [];

        foreach (self::TABLES as $table) {
            if (self::tableExists($connection, $table)) {
                $columns[$table] = self::columns($connection, $table);
            }
        }

        // Niente anagrafiche ancora: non c'è nulla da segnare come fatto.
        if ($columns === []) {
            return [];
        }

        $done = [];

        foreach (self::plan($columns) as $step => $sql) {
            if ($connection->query($sql) === false) {
                throw new RuntimeException('Pagamento vuoto non migrato ('.$step.'): '.$connection->error);
            }

            $done[$step] = max(0, $connection->affected_rows);
        }

        $connection->query('CREATE TABLE IF NOT EXISTS `'.self::LOG_TABLE.'` (
            `name` VARCHAR(100) NOT NULL PRIMARY KEY,
            `creation` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB');
        $connection->query("INSERT IGNORE INTO `".self::LOG_TABLE."` (`name`) VALUES ('".self::NAME."')");

        return array_filter($done, static fn ($value) => $value !== 0);
    }

    /**
     * Un'istruzione per colonna presente: scrive solo dove c'è `0`, quindi
     * rilanciarla non cambia niente.
     *
     * @param array<string, list<string>> $columns tabella => colonne
     * @return array<string, string> passo => SQL
     */
    public static function plan(array $columns): array
    {
        $plan = [];

        foreach (self::TABLES as $table) {
            foreach (self::COLUMNS as $column) {
                if (in_array($column, $columns[$table] ?? [], true)) {
                    $plan[$table.'.'.$column] = "UPDATE `{$table}` SET `{$column}` = NULL WHERE `{$column}` = 0";
                }
            }
        }

        return $plan;
    }

    /** @return list<string> */
    private static function columns(mysqli $connection, string $table): array
    {
        $result = $connection->query('SHOW COLUMNS FROM `'.$table.'`');

        return $result ? array_map(static fn (array $row): string => strtolower((string) $row[0]), $result->fetch_all()) : [];
    }

    private static function alreadyRun(mysqli $connection): bool
    {
        if (!self::tableExists($connection, self::LOG_TABLE)) {
            return false;
        }

        $result = $connection->query("SELECT 1 FROM `".self::LOG_TABLE."` WHERE `name` = '".self::NAME."' LIMIT 1");

        return $result !== false && $result->num_rows > 0;
    }

    private static function tableExists(mysqli $connection, string $table): bool
    {
        $result = $connection->query("SHOW TABLES LIKE '".$connection->real_escape_string($table)."'");

        return $result !== false && $result->num_rows > 0;
    }
}
