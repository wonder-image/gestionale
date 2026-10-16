<?php

namespace Wonder\Plugin\Gestionale\Models\System;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Registro delle modifiche fatte dal server MCP: chi (utente e token), quale
 * strumento, con quali argomenti, lo stato prima e dopo, l'esito.
 */
final class McpLog extends Model
{
    public static string $table = 'gst_mcp_logs';
    public static string $folder = 'gestionale/mcp';
    public static string $icon = 'bi bi-robot';

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::multiRow()->keepIds()->localOnly();
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('tool')->length(60),
            Column::key('user_id')->int(),
            Column::key('api_user_id')->int(),
            Column::key('oauth_grant_id')->int()->null(true),
            Column::key('subject_id')->int(),
            Column::key('arguments')->type('TEXT'),
            Column::key('state_before')->type('TEXT'),
            Column::key('state_after')->type('TEXT'),
            Column::key('success')->enum(['true', 'false'])->default('true'),
            Column::key('error')->type('TEXT'),
            Column::key('ip')->length(45),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_tool' => ['index' => 'tool'],
            'ind_user' => ['index' => 'user_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('tool')->text()->sanitize(false),
            Field::key('user_id')->number()->decimals(0),
            Field::key('api_user_id')->number()->decimals(0),
            Field::key('oauth_grant_id')->number()->decimals(0),
            Field::key('subject_id')->number()->decimals(0),
            Field::key('arguments')->text()->sanitize(false),
            Field::key('state_before')->text()->sanitize(false),
            Field::key('state_after')->text()->sanitize(false),
            Field::key('success')->text()->sanitize(false),
            Field::key('error')->text()->sanitize(false),
            Field::key('ip')->text()->sanitize(false),
        ];
    }
}
