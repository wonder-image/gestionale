<?php

namespace Wonder\Plugin\Gestionale\Models\Contacts;

/**
 * Il Model del gestionale sulla tabella `contacts` di `wonder-image/customer-account`:
 * aggiunge listino, metodo e termine di pagamento.
 * `ResourceRegistry::resolveByTable()` sceglie la prima Resource non fallback (qui
 * Clienti) e non `ContactResource` di customer-account, che è fallback.
 */
final class Contact extends \Wonder\Plugin\CustomerAccount\Models\Contact
{
    public static string $folder = 'gestionale/contacts';

    public static function tableSchema(): array
    {
        return [
            ...parent::tableSchema(),
            \Wonder\Sql\TableSchema::key('price_list_id')->int(),
            \Wonder\Sql\TableSchema::key('payment_method_id')->int(),
            \Wonder\Sql\TableSchema::key('payment_term_id')->int(),
            // Vuota: quella delle impostazioni.
            \Wonder\Sql\TableSchema::key('vat_collectability')->length(5),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            ...parent::dataSchema(),
            \Wonder\Data\UploadSchema::key('price_list_id')->number()->decimals(0),
            \Wonder\Data\UploadSchema::key('payment_method_id')->number()->decimals(0),
            \Wonder\Data\UploadSchema::key('payment_term_id')->number()->decimals(0),
            \Wonder\Data\UploadSchema::key('vat_collectability')->text()->sanitize(false),
        ];
    }
}
