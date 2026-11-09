<?php

namespace Wonder\Plugin\Gestionale\Migrations;

use mysqli;
use Throwable;
use Wonder\App\Module\Contracts\ModuleMigration;

/**
 * Porta documenti e fornitori sulle aziende, dopo che `CompanySplitMigration`
 * di customer-account le ha separate dai contatti.
 *
 * Legge `contact_company_map` (contatto → azienda, con l'azione):
 *
 * - documenti di vendita (`company_id` nuovo): un'azienda spostata va in
 *   `company_id` e la persona resta solo se era il referente; un'azienda con
 *   account va in `company_id` e la persona resta cliente;
 * - fornitori (`supplier_id`): da id di `contacts` a id di `companies`, in
 *   tutte le tabelle che lo portano.
 *
 * Gira una volta sola: alla fine scrive il suo nome in `gst_migrations`.
 * Su un'installazione nuova (niente mappa) aggiunge solo le colonne.
 */
final class DocumentCompanyMigration implements ModuleMigration
{
    public const NAME = 'document_company';
    public const LOG_TABLE = 'gst_migrations';
    public const MAP_TABLE = 'contact_company_map';

    /** Documenti con un cliente: ricevono `company_id`. */
    public const CUSTOMER_TABLES = [
        'gst_orders', 'gst_delivery_notes', 'gst_sales_returns', 'gst_payments',
        'gst_subscriptions', 'gst_coupon_customers', 'gst_coupon_redemptions',
    ];

    /** Tabelle con `supplier_id`, che da ora punta a `companies`. */
    public const SUPPLIER_TABLES = [
        'gst_order_items', 'gst_delivery_note_items', 'gst_product_suppliers', 'gst_batches',
        'gst_stock', 'gst_stock_documents', 'gst_stock_document_items', 'gst_stock_movements',
    ];

    public static function run(mysqli $connection): array
    {
        $done = [];

        // Il DDL chiude le transazioni: le colonne si preparano prima.
        foreach (self::CUSTOMER_TABLES as $table) {
            if (self::tableExists($connection, $table) && !self::hasColumn($connection, $table, 'company_id')) {
                $connection->query("ALTER TABLE `{$table}` ADD COLUMN `company_id` INT NOT NULL DEFAULT 0");
                $done['columns'][] = $table;
            }
        }

        if (!self::tableExists($connection, self::MAP_TABLE) || self::alreadyRun($connection)) {
            return $done;
        }

        $foreignKeys = self::dropSupplierForeignKeys($connection);
        self::createLogTable($connection);

        $connection->begin_transaction();
        $connection->query('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach (self::CUSTOMER_TABLES as $table) {
                if (self::tableExists($connection, $table) && self::hasColumn($connection, $table, 'customer_id')) {
                    $done['customers'][$table] = self::remapCustomers($connection, $table);
                }
            }

            foreach (self::SUPPLIER_TABLES as $table) {
                if (self::tableExists($connection, $table) && self::hasColumn($connection, $table, 'supplier_id')) {
                    $done['suppliers'][$table] = self::remapSuppliers($connection, $table);
                }
            }

            $connection->query("INSERT INTO `".self::LOG_TABLE."` (`name`) VALUES ('".self::NAME."')");
            $connection->query('SET FOREIGN_KEY_CHECKS = 1');
            $connection->commit();
        } catch (Throwable $throwable) {
            $connection->rollback();
            $connection->query('SET FOREIGN_KEY_CHECKS = 1');

            throw $throwable;
        }

        if ($foreignKeys !== []) {
            $done['foreign_keys_dropped'] = $foreignKeys;
        }

        return array_filter($done, static fn ($value) => $value !== [] && $value !== 0);
    }

    /** Righe dei documenti di un'azienda: `company_id`, e il cliente che resta. */
    private static function remapCustomers(mysqli $connection, string $table): int
    {
        // Un'azienda spostata non è più una persona: resta il referente, se c'era.
        $person = self::hasColumn($connection, $table, 'referent_id')
            ? "IF(d.`referent_id` > 0, d.`referent_id`, 0)"
            : '0';

        $connection->query(
            "UPDATE `{$table}` d JOIN `".self::MAP_TABLE."` m ON m.`contact_id` = d.`customer_id`
             SET d.`company_id` = m.`company_id`,
                 d.`customer_id` = IF(m.`action` = 'moved', {$person}, d.`customer_id`)
             WHERE d.`company_id` = 0 AND d.`customer_id` > 0 AND m.`action` IN ('moved', 'linked')"
        );

        return max(0, $connection->affected_rows);
    }

    /** `supplier_id` da contatto ad azienda. In due passi: un id nuovo può coincidere con uno vecchio. */
    private static function remapSuppliers(mysqli $connection, string $table): int
    {
        $connection->query(
            "UPDATE `{$table}` d JOIN `".self::MAP_TABLE."` m ON m.`contact_id` = d.`supplier_id`
             SET d.`supplier_id` = -m.`company_id`
             WHERE d.`supplier_id` > 0 AND m.`action` IN ('moved', 'linked', 'supplier')"
        );
        $rows = max(0, $connection->affected_rows);
        $connection->query("UPDATE `{$table}` SET `supplier_id` = -`supplier_id` WHERE `supplier_id` < 0");

        return $rows;
    }

    /**
     * Le chiavi esterne di `supplier_id` verso `contacts`: la sincronizzazione
     * delle tabelle le ricrea verso `companies`.
     *
     * @return list<string>
     */
    private static function dropSupplierForeignKeys(mysqli $connection): array
    {
        $dropped = [];
        $result = $connection->query(
            "SELECT `TABLE_NAME`, `CONSTRAINT_NAME` FROM information_schema.KEY_COLUMN_USAGE
             WHERE `TABLE_SCHEMA` = DATABASE() AND `COLUMN_NAME` = 'supplier_id' AND `REFERENCED_TABLE_NAME` = 'contacts'"
        );

        foreach ($result ? $result->fetch_all() : [] as [$table, $constraint]) {
            $connection->query("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
            $dropped[] = $table.'.'.$constraint;
        }

        return $dropped;
    }

    private static function alreadyRun(mysqli $connection): bool
    {
        if (!self::tableExists($connection, self::LOG_TABLE)) {
            return false;
        }

        $result = $connection->query("SELECT 1 FROM `".self::LOG_TABLE."` WHERE `name` = '".self::NAME."' LIMIT 1");

        return $result !== false && $result->num_rows > 0;
    }

    private static function createLogTable(mysqli $connection): void
    {
        $connection->query('CREATE TABLE IF NOT EXISTS `'.self::LOG_TABLE.'` (
            `name` VARCHAR(100) NOT NULL PRIMARY KEY,
            `creation` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB');
    }

    private static function tableExists(mysqli $connection, string $table): bool
    {
        $result = $connection->query("SHOW TABLES LIKE '".$connection->real_escape_string($table)."'");

        return $result !== false && $result->num_rows > 0;
    }

    private static function hasColumn(mysqli $connection, string $table, string $column): bool
    {
        $result = $connection->query("SHOW COLUMNS FROM `{$table}` LIKE '".$connection->real_escape_string($column)."'");

        return $result !== false && $result->num_rows > 0;
    }
}
