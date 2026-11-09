<?php

namespace Wonder\Plugin\Gestionale\Models\SalesAgents;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Un commerciale (`sales_agents`, #77): l'utente del backend con ruolo
 * `sales_agent` che vede e gestisce solo i propri clienti.
 *
 * `user_id` è obbligatorio (senza utente non c'è pannello); `contact_id` è la
 * scheda anagrafica, opzionale, per i dati delle provvigioni. `parent_id` è il
 * commerciale sopra di lui e lo usa la rete (`sales_agent_network`).
 * Commissione e sconto massimo sono i valori predefiniti delle funzionalità
 * che li usano: qui si salvano soltanto.
 */
final class SalesAgent extends Model
{
    public static string $table = 'gst_sales_agents';
    public static string $folder = 'gestionale/sales-agents';
    public static string $icon = 'bi bi-person-badge';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('user_id')->int()->default(0),
            Column::key('contact_id')->int()->default(0),
            Column::key('parent_id')->int()->default(0),
            Columns::decimal('commission_percent', '5,2'),
            Columns::decimal('max_discount_percent', '5,2'),
            Column::key('active')->enum(['true', 'false'])->default('true'),
            Column::key('note')->type('TEXT'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_user' => ['index' => 'user_id'],
            'ind_parent' => ['index' => 'parent_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('user_id')->number()->decimals(0),
            Field::key('contact_id')->number()->decimals(0),
            Field::key('parent_id')->number()->decimals(0),
            Field::key('commission_percent')->number()->decimals(2),
            Field::key('max_discount_percent')->number()->decimals(2),
            Field::key('active')->text()->sanitize(false),
            Field::key('note')->text(),
        ];
    }
}
