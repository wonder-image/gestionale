<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;

/**
 * Chi è il cliente di un documento (ordine, DDT, reso, pagamento, abbonamento).
 *
 * Con `company_id` è l'azienda, e `customer_id` è il suo referente. Senza, è
 * la persona di `customer_id`. Il tipo di fattura segue lo stesso ordine:
 * `business` con l'azienda o con la P.IVA nello snapshot, altrimenti `private`.
 */
final class CustomerKind
{
    public const BUSINESS = 'business';
    public const PRIVATE = 'private';

    /** @param array<string, mixed> $document */
    public static function of(array $document): string
    {
        if ((int) ($document['company_id'] ?? 0) > 0) {
            return self::BUSINESS;
        }

        foreach (['billing_pi', 'pi'] as $key) {
            if (trim((string) ($document[$key] ?? '')) !== '') {
                return self::BUSINESS;
            }
        }

        return (string) ($document['billing_type'] ?? '') === self::BUSINESS ? self::BUSINESS : self::PRIVATE;
    }

    /** @param array<string, mixed> $document */
    public static function isBusiness(array $document): bool
    {
        return self::of($document) === self::BUSINESS;
    }

    /**
     * La scheda del cliente: l'azienda se c'è, altrimenti la persona. Vuota se
     * non c'è nessuno o non si legge.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function record(array $document): array
    {
        $companyId = (int) ($document['company_id'] ?? 0);
        $contactId = (int) ($document['customer_id'] ?? 0);

        try {
            $row = $companyId > 0 ? Company::findById($companyId) : ($contactId > 0 ? Contact::findById($contactId) : null);
        } catch (Throwable) {
            return [];
        }

        return is_array($row) && isset($row['id']) ? $row : [];
    }

    /** Il nome del cliente: la ragione sociale dell'azienda o il nome della persona. @param array<string, mixed> $document */
    public static function name(array $document): string
    {
        $record = self::record($document);

        return $record !== [] ? Contacts::displayName($record) : '';
    }
}
