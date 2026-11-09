<?php

namespace Wonder\Plugin\Gestionale\Migrations;

use mysqli;
use RuntimeException;
use Wonder\App\Module\Contracts\ModuleMigration;

/**
 * Il brand dell'azienda sugli ordini da `brand_id` a `company_brand_id`, per
 * non confonderlo con il marchio degli articoli, e il nome copiato in
 * `company_brand_name` sui documenti che ne sono senza.
 *
 * Gira prima della sincronizzazione delle tabelle: le colonne nuove le
 * prepara da sé. La colonna vecchia non la tocca; la toglie poi la
 * sincronizzazione, che fa cadere le colonne non più nello schema.
 * I dati si copiano una volta sola: alla fine scrive il suo nome in
 * `gst_migrations`. Senza `gst_orders`, o su un'installazione che il brand
 * non l'ha mai avuto, non fa niente.
 */
final class OrderCompanyBrandMigration implements ModuleMigration
{
    public const NAME = 'order_company_brand';
    public const LOG_TABLE = DocumentCompanyMigration::LOG_TABLE;
    public const TABLE = 'gst_orders';
    public const BRANDS_TABLE = 'company_brands';

    public static function run(mysqli $connection): array
    {
        if (!self::tableExists($connection, self::TABLE) || self::alreadyRun($connection)) {
            return [];
        }

        $plan = self::plan(self::columns($connection), self::tableExists($connection, self::BRANDS_TABLE));

        if ($plan === []) {
            return [];
        }

        $done = [];

        foreach ($plan as $step => $sql) {
            // Fermarsi qui: subito dopo la sincronizzazione cancella `brand_id`.
            if ($connection->query($sql) === false) {
                throw new RuntimeException('Brand degli ordini non migrato ('.$step.'): '.$connection->error);
            }

            $done[$step] = str_starts_with($step, 'add_') ? true : max(0, $connection->affected_rows);
        }

        $connection->query('CREATE TABLE IF NOT EXISTS `'.self::LOG_TABLE.'` (
            `name` VARCHAR(100) NOT NULL PRIMARY KEY,
            `creation` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB');
        $connection->query("INSERT IGNORE INTO `".self::LOG_TABLE."` (`name`) VALUES ('".self::NAME."')");

        return array_filter($done, static fn ($value) => $value !== 0);
    }

    /**
     * Le istruzioni, in ordine, date le colonne di `gst_orders`: prima le
     * colonne che mancano (il DDL chiude le transazioni), poi la copia
     * dell'id e quella del nome. Ogni copia scrive solo dove manca il
     * valore: rilanciarla non cambia niente.
     *
     * @param list<string> $columns
     * @return array<string, string> passo => SQL
     */
    public static function plan(array $columns, bool $brands): array
    {
        $old = in_array('brand_id', $columns, true);

        // Né il vecchio né il nuovo: la colonna la crea la sincronizzazione, vuota.
        if (!$old && !in_array('company_brand_id', $columns, true)) {
            return [];
        }

        $table = '`'.self::TABLE.'`';
        $plan = [];

        if (!in_array('company_brand_id', $columns, true)) {
            $plan['add_company_brand_id'] = "ALTER TABLE {$table} ADD COLUMN `company_brand_id` INT NULL DEFAULT 0";
        }

        if (!in_array('company_brand_name', $columns, true)) {
            $plan['add_company_brand_name'] = "ALTER TABLE {$table} ADD COLUMN `company_brand_name` VARCHAR(255) NULL";
        }

        if ($old) {
            $plan['copied'] = "UPDATE {$table} SET `company_brand_id` = `brand_id`
                WHERE COALESCE(`company_brand_id`, 0) = 0 AND `brand_id` > 0";
        }

        // Il nome com'è scritto nella scheda del brand: stessa codifica delle
        // colonne di testo del Model, che lo rilegge da sé.
        if ($brands) {
            $plan['named'] = "UPDATE {$table} o JOIN `".self::BRANDS_TABLE."` b ON b.`id` = o.`company_brand_id`
                SET o.`company_brand_name` = b.`name`
                WHERE o.`company_brand_id` > 0 AND COALESCE(o.`company_brand_name`, '') = '' AND b.`deleted` = 'false'";
        }

        return $plan;
    }

    /** @return list<string> */
    private static function columns(mysqli $connection): array
    {
        $result = $connection->query('SHOW COLUMNS FROM `'.self::TABLE.'`');

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
