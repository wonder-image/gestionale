<?php

namespace Wonder\Plugin\Gestionale\Models\Contacts;

/**
 * Il Model del gestionale sulla tabella `contact_addresses` di `wonder-image/customer-account`:
 * aggiunge l'azienda che riceve (`business_name`).
 * La `ContactAddressResource` del gestionale è solo di navigazione, senza Model sulla
 * tabella: per `resolveByTable()` risponde quella di customer-account (fallback).
 */
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
