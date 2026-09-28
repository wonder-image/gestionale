<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;

/**
 * Le regole di `Levels` e `Availability` scritte in SQL.
 *
 * Servono all'elenco delle giacenze: il core ordina e pagina nel database, e
 * una colonna si può ordinare solo se il numero nasce nella query. Ogni
 * formula è una sottoquery sul prodotto della riga; un test d'integrazione le
 * confronta con `Levels::forProducts()` e con le righe di `gst_stock`.
 *
 * Niente `AS` dentro le formule: il core legge gli alias della select con
 * una regex, e ne vedrebbe uno in più.
 */
final class LevelsSql
{
    /** Il prodotto della riga nell'elenco del core. */
    public const PRODUCT = '`gst_products`.`id`';

    /** La giacenza di tutte le sedi, come `Levels`. */
    public static function quantity(string $product = self::PRODUCT): string
    {
        return '(SELECT ROUND(COALESCE(SUM(s.quantity), 0), 3) FROM `'.StockRow::$table.'` s'
            .' WHERE s.product_id = '.$product." AND s.deleted = 'false')";
    }

    /** La giacenza di una sede sola. */
    public static function quantityAt(int $locationId, string $product = self::PRODUCT): string
    {
        return '(SELECT ROUND(COALESCE(SUM(s.quantity), 0), 3) FROM `'.StockRow::$table.'` s'
            .' WHERE s.product_id = '.$product.' AND s.location_id = '.$locationId." AND s.deleted = 'false')";
    }

    /**
     * I pezzi impegnati, come `Availability::reserved()`: le prenotazioni non
     * rilasciate e non scadute. Una data "mai" è NULL o gli zeri di MySQL; il
     * confronto con la stringa vuota farebbe fallire la query.
     */
    public static function reserved(string $now, string $product = self::PRODUCT): string
    {
        return '(SELECT ROUND(COALESCE(SUM(r.quantity), 0), 3) FROM `'.StockReservation::$table.'` r'
            .' WHERE r.product_id = '.$product." AND r.deleted = 'false'"
            .' AND '.self::never('r.released_at')
            .' AND ('.self::never('r.expires_at')." OR r.expires_at > '".self::moment($now)."'))";
    }

    /** Disponibili = giacenza − impegnati, come `Availability::of()`. */
    public static function available(string $now, string $product = self::PRODUCT): string
    {
        return '(ROUND('.self::quantity($product).' - '.self::reserved($now, $product).', 3))';
    }

    /** Vero se la versione ha un avviso di scorta minima aperto, come `Alerts`. */
    public static function openAlert(string $product = self::PRODUCT): string
    {
        return 'EXISTS (SELECT 1 FROM `'.StockAlert::$table.'` a'
            .' WHERE a.product_id = '.$product." AND a.deleted = 'false' AND a.resolved_at IS NULL)";
    }

    /** Il nome dell'articolo della versione. */
    public static function modelName(string $product = '`gst_products`.`product_model_id`'): string
    {
        return '(SELECT m.name FROM `'.ProductModel::$table.'` m WHERE m.id = '.$product.')';
    }

    private static function never(string $column): string
    {
        return '('.$column.' IS NULL OR '.$column." < '1000-01-01')";
    }

    /** Solo un `Y-m-d H:i:s` entra nella query; altrimenti adesso. */
    private static function moment(string $now): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $now) === 1 ? $now : date('Y-m-d H:i:s');
    }
}
