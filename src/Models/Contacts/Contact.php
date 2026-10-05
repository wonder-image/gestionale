<?php

namespace Wonder\Plugin\Gestionale\Models\Contacts;

/** Compatibility entrypoint; shared storage is owned by wonder-image/app. */
final class Contact extends \Wonder\App\Models\Contacts\Contact
{
    public static string $folder = 'gestionale/contacts';

    public static function tableSchema(): array
    {
        return [
            ...parent::tableSchema(),
            \Wonder\Sql\TableSchema::key('price_list_id')->int(),
            \Wonder\Sql\TableSchema::key('payment_method_id')->int(),
            \Wonder\Sql\TableSchema::key('payment_term_id')->int(),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            ...parent::dataSchema(),
            \Wonder\Data\UploadSchema::key('price_list_id')->number()->decimals(0),
            \Wonder\Data\UploadSchema::key('payment_method_id')->number()->decimals(0),
            \Wonder\Data\UploadSchema::key('payment_term_id')->number()->decimals(0),
        ];
    }
}
