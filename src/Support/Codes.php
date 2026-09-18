<?php

namespace Wonder\Plugin\Gestionale\Support;

use ReflectionClass;

/**
 * Prefissi dei codici delle entità del gestionale.
 *
 * Ogni entità operativa ha una colonna `code` con prefisso e sette caratteri
 * casuali (es. `pay_k3x9d2a`), generata dal framework con
 * `Field::key('code')->text()->uniqueCode(Codes::PAYMENT)`: unica, minuscola,
 * creata all'inserimento e mai più modificata.
 *
 * È il riferimento tecnico — link mandati al cliente, metadati dei gateway,
 * log — e non sostituisce SKU, numero d'ordine o numero di fattura. Le tabelle
 * di configurazione (aliquote, tipi fiscali, regole) non passano di qui: hanno
 * codici parlanti come `22` o `italia-privato-ordinaria`.
 *
 * Qui stanno i prefissi di tutte le entità dell'architettura, anche di quelle
 * che nasceranno nei prossimi sotto-progetti: sono una sola fonte, così due
 * entità non finiscono per condividere un prefisso.
 */
final class Codes
{
    // Catalogo
    public const MODEL = 'mod_';
    public const VARIANT = 'var_';
    public const PRODUCT = 'pro_';
    public const BRAND = 'bra_';
    public const CATEGORY = 'cat_';
    public const TAG = 'tag_';

    // Anagrafiche e sedi
    public const CONTACT = 'con_';
    public const LOCATION = 'loc_';

    // Magazzino
    public const BATCH = 'bat_';
    public const STOCK_MOVEMENT = 'mov_';
    public const STOCK_DOCUMENT = 'stk_';

    // Documenti di vendita
    public const ORDER = 'ord_';
    public const DELIVERY_NOTE = 'del_';
    public const SALES_RETURN = 'ret_';
    public const PAYMENT = 'pay_';
    public const INVOICE = 'inv_';
    public const SHIPMENT = 'shp_';

    // Abbonamenti, listini e sconti
    public const SUBSCRIPTION = 'sub_';
    public const PLAN = 'pln_';
    public const PRICE_LIST = 'prl_';
    public const DISCOUNT_CAMPAIGN = 'dsc_';

    /** Tutti i prefissi, nome della costante => prefisso. @return array<string, string> */
    public static function all(): array
    {
        /** @var array<string, string> $constants */
        $constants = (new ReflectionClass(self::class))->getConstants();

        return $constants;
    }
}
