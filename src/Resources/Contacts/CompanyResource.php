<?php

namespace Wonder\Plugin\Gestionale\Resources\Contacts;

use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Plugin\CustomerAccount\Models\CompanyAddress;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Purchasing\ProductSuppliers;

/**
 * "Aziende": clienti e fornitori con partita IVA, e i loro referenti.
 *
 * Stessa scheda delle persone, su `companies`: i documenti la legano con
 * `company_id`, non ha account, i suoi indirizzi stanno in
 * `company_addresses`. Con gli acquisti accesi sceglie il ruolo.
 * `SupplierResource` la estende per l'elenco dei fornitori.
 */
class CompanyResource extends CustomerResource
{
    public static string $model = Company::class;

    public static function relationSide(): string
    {
        return ContactRelations::COMPANY;
    }

    public static function documentColumn(): string
    {
        return 'company_id';
    }

    public static function hasAccount(): bool
    {
        return false;
    }

    public static function addressModel(): string
    {
        return CompanyAddress::class;
    }

    public static function addressColumn(): string
    {
        return 'company_id';
    }

    public static function rolesShown(): bool
    {
        return Gestionale::feature('purchasing');
    }

    protected static function uniqueColumns(): array
    {
        return ['pi' => 'contact.vat_taken', 'cf' => 'contact.tax_code_taken', 'email' => 'contact.email_taken'];
    }

    public static function path(): string
    {
        return 'app/gestionale/aziende';
    }

    public static function icon(): string
    {
        return 'bi-building';
    }

    public static function titleLabel(): string
    {
        return 'Aziende';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'azienda',
            'plural_label' => 'aziende',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'this' => 'questa',
        ];
    }

    protected static function sheetView(): string
    {
        return 'pages/company-show.php';
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()->order(15);
    }

    /** Tutte le aziende: il ruolo si sceglie col filtro. */
    protected static function listCondition(): string
    {
        return '1 = 1';
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        $layout = parent::tableLayoutSchema();

        if (!static::rolesShown() || static::listCondition() !== '1 = 1') {
            return $layout;
        }

        return $layout->filterQuery(
            'Ruolo',
            'role',
            ['customer' => 'Clienti', 'supplier' => 'Fornitori'],
            static fn (array $values): string => match ($values[0] ?? '') {
                'customer' => "is_customer = 'true'",
                'supplier' => "is_supplier = 'true'",
                default => '1 = 1',
            }
        );
    }

    /**
     * Un'azienda è cliente, fornitore o tutti e due. Un fornitore che smette di
     * esserlo sparirebbe dalle tendine degli articoli che comprano da lui.
     */
    protected static function checkRoles(array $values, int $id): array
    {
        $customer = ($values['is_customer'] ?? static::storedRole($id, 'is_customer')) === 'true';
        $supplier = ($values['is_supplier'] ?? static::storedRole($id, 'is_supplier')) === 'true';

        if ($id <= 0 && !static::rolesShown() && !isset($values['is_customer'])) {
            // Senza acquisti ogni azienda nuova è un cliente.
            $values['is_customer'] = 'true';
            $customer = true;
        }

        if (!$customer && !$supplier) {
            throw UserError::make('contact.no_role');
        }

        if ($id > 0 && !$supplier && static::storedRole($id, 'is_supplier') === 'true') {
            $links = ProductSuppliers::countForSupplier($id);

            if ($links > 0) {
                throw UserError::make('contact.supplier_role_in_use', ['count' => $links]);
            }
        }

        return $values;
    }

    public static function assertDeletable(int|string $id): void
    {
        parent::assertDeletable($id);

        $links = ProductSuppliers::countForSupplier((int) $id);

        if ($links > 0) {
            throw UserError::refusal('contact.supplier_in_use', ['count' => $links]);
        }
    }

    public static function deleteRecord(int|string $id): object
    {
        static::assertDeletable($id);
        ProductSuppliers::dropForRemovedProducts((int) $id);

        return parent::deleteRecord($id);
    }
}
