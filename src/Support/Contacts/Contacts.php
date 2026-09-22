<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;

/**
 * Come si legge una scheda della rubrica, e come si scopre che ce n'è già
 * un'altra uguale.
 *
 * Il nome da mostrare non è una colonna: un'azienda si chiama con la ragione
 * sociale, un privato con cognome e nome, e chi arriva da un ordine online a
 * volte ha solo un'email. Deciderlo in un posto solo evita che elenco, scheda,
 * documenti e vetrina lo facciano ognuno a modo suo.
 */
final class Contacts
{
    /** @param array<string, mixed> $row */
    public static function isCompany(array $row): bool
    {
        return strtolower(trim((string) ($row['type'] ?? ''))) === 'business';
    }

    /** @param array<string, mixed> $row */
    public static function displayName(array $row): string
    {
        $business = trim((string) ($row['business_name'] ?? ''));

        if (self::isCompany($row) && $business !== '') {
            return $business;
        }

        $person = trim(implode(' ', array_filter([
            trim((string) ($row['surname'] ?? '')),
            trim((string) ($row['name'] ?? '')),
        ])));

        if ($person !== '') {
            return $person;
        }

        $email = trim((string) ($row['email'] ?? ''));

        if ($email !== '') {
            return $email;
        }

        return $business !== '' ? $business : 'Senza nome';
    }

    /** @param array<string, mixed> $row */
    public static function roles(array $row): string
    {
        $customer = ($row['is_customer'] ?? 'false') === 'true';
        $supplier = ($row['is_supplier'] ?? 'false') === 'true';

        return match (true) {
            $customer && $supplier => 'Cliente e fornitore',
            $customer => 'Cliente',
            $supplier => 'Fornitore',
            default => '—',
        };
    }

    /**
     * La scheda che ha già quel valore, `[]` se è libero.
     *
     * Serve a dire **di chi** è la partita IVA che stai riscrivendo: un
     * "già presente" senza nome costringe a cercarla a mano.
     *
     * @return array<string, mixed>
     */
    public static function duplicateOf(string $column, string $value, ?int $ignoreId): array
    {
        $value = trim($value);

        if ($value === '') {
            return [];
        }

        try {
            $rows = Contact::find([$column => $value, 'deleted' => 'false']);
        } catch (Throwable) {
            // Senza database (test degli schemi, comandi) non c'è niente da
            // confrontare: il valore è libero.
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $rows = isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));

        foreach ($rows as $row) {
            if ($ignoreId === null || (int) ($row['id'] ?? 0) !== $ignoreId) {
                return $row;
            }
        }

        return [];
    }
}
