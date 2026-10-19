<?php

namespace Wonder\Plugin\Gestionale\Models\Contacts;

/** Compatibility entrypoint; shared storage is owned by wonder-image/customer-account. */
final class ContactAddress extends \Wonder\Plugin\CustomerAccount\Models\ContactAddress
{
    public static string $folder = 'gestionale/contacts';

    /** L'azienda che riceve, facoltativa: dal backend si spedisce anche in ufficio. */
    public static function tableSchema(): array
    {
        return [
            ...parent::tableSchema(),
            \Wonder\Sql\TableSchema::key('business_name'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            ...parent::dataSchema(),
            \Wonder\Data\UploadSchema::key('business_name')->text(),
        ];
    }
}
