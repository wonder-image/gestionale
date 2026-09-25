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

    /**
     * Le risposte alla domanda "chi è": una scheda ha almeno un ruolo.
     *
     * Non sono colonne. Le colonne sono due interruttori, e chiederli uno per
     * uno costringeva a rispondere «no» a «è un cliente?» per dire che è un
     * fornitore. Qui la domanda è una sola, e le risposte sbagliate non
     * esistono.
     */
    public const ROLE_CHOICES = [
        'customer' => 'Cliente',
        'supplier' => 'Fornitore',
        'both' => 'Cliente e fornitore',
    ];

    /**
     * La risposta che descrive una scheda gia salvata, `''` se non ne ha
     * nessuna: una riga senza ruoli è roba vecchia, e chi la apre deve
     * scegliere.
     *
     * @param array<string, mixed> $row
     */
    public static function roleChoice(array $row): string
    {
        $customer = ($row['is_customer'] ?? 'false') === 'true';
        $supplier = ($row['is_supplier'] ?? 'false') === 'true';

        return match (true) {
            $customer && $supplier => 'both',
            $customer => 'customer',
            $supplier => 'supplier',
            default => '',
        };
    }

    /**
     * I due interruttori che accende una risposta.
     *
     * Una risposta che non esiste non accende niente: è il chiamante a
     * decidere cosa farne, non questa funzione a inventarsi un ruolo.
     *
     * @return array<string, string>
     */
    public static function rolesFromChoice(string $choice): array
    {
        if (!isset(self::ROLE_CHOICES[$choice])) {
            return [];
        }

        return [
            'is_customer' => $choice === 'supplier' ? 'false' : 'true',
            'is_supplier' => $choice === 'customer' ? 'false' : 'true',
        ];
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

    /**
     * I fornitori da proporre in una tendina, id => nome, in ordine di nome.
     *
     * Solo quelli attivi, più quelli di `$keepIds` anche se non lo sono più:
     * sono i fornitori già legati a quello che stai modificando. Senza, la
     * tendina posterebbe un valore vuoto e staccherebbe il fornitore solo
     * perché è stato messo su «Non attivo». Si riconoscono dal «(non attivo)»
     * accanto al nome.
     *
     * @param list<int> $keepIds
     * @return array<int, string>
     */
    public static function supplierOptions(array $keepIds = []): array
    {
        $keep = array_values(array_unique(array_filter(
            array_map('intval', $keepIds),
            static fn (int $id): bool => $id > 0
        )));

        $condition = "is_supplier = 'true' AND deleted = 'false' AND (active = 'true'"
            .($keep === [] ? '' : ' OR id IN ('.implode(',', $keep).')')
            .')';

        try {
            $rows = Contact::find($condition);
        } catch (Throwable) {
            // Senza database (test degli schemi, comandi) non c'è nessuno da
            // proporre.
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $rows = isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
        $names = [];

        foreach ($rows as $row) {
            $names[(int) ($row['id'] ?? 0)] = [self::displayName($row), ($row['active'] ?? 'true') === 'true'];
        }

        uasort($names, static fn (array $a, array $b): int => strnatcasecmp($a[0], $b[0]));

        $options = [];

        foreach ($names as $id => [$name, $active]) {
            if ($id > 0) {
                $options[$id] = $active ? $name : $name.' (non attivo)';
            }
        }

        return $options;
    }
}
