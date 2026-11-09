<?php

namespace Wonder\Plugin\Gestionale\Models\Contacts;

/**
 * Il Model del gestionale sulla tabella `companies` di `wonder-image/customer-account`:
 * come `Contact`, aggiunge listino, metodo e termine di pagamento ed esigibilità IVA.
 * Con un'azienda nel documento valgono le sue condizioni, non quelle del referente.
 */
final class Company extends \Wonder\Plugin\CustomerAccount\Models\Company
{
    public static string $folder = 'gestionale/companies';

    public static function tableSchema(): array
    {
        return [
            ...parent::tableSchema(),
            \Wonder\Sql\TableSchema::key('price_list_id')->int(),
            // Il commerciale assegnato (#77).
            \Wonder\Sql\TableSchema::key('agent_id')->int(),
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
            \Wonder\Data\UploadSchema::key('agent_id')->number()->decimals(0),
            \Wonder\Data\UploadSchema::key('payment_method_id')->number()->decimals(0),
            \Wonder\Data\UploadSchema::key('payment_term_id')->number()->decimals(0),
            \Wonder\Data\UploadSchema::key('vat_collectability')->text()->sanitize(false),
        ];
    }
}
