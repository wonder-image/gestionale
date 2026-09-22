<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress;

/**
 * Quattro schede di prova: due clienti e due fornitori.
 *
 * Servono a vedere gli elenchi pieni e a provare i casi che contano davvero —
 * un privato senza partita IVA, un'azienda con fatturazione elettronica, e un
 * indirizzo di consegna diverso da quello di fatturazione.
 *
 * Le partite IVA sono valide davvero: il campo del core le controlla, e una
 * finta non si salverebbe. Stessa cosa per le email, di cui il core controlla
 * **il dominio**: solo `example.com`, che esiste ed è riservato alle prove —
 * un `@qualcosa-di-inventato.example.com` verrebbe rifiutato.
 */
final class ContactsDemo
{
    private const KEY = 'contacts';
    private const PREFIX = 'Prova ';

    public static function register(): void
    {
        DemoData::register(
            self::KEY,
            'Anagrafiche: due clienti e due fornitori',
            static fn (): int => self::create(),
            static fn (): int => self::clear()
        );
    }

    /** @return int righe create */
    public static function create(): int
    {
        $created = 0;

        $created += self::contact([
            'type' => 'private',
            'name' => 'Luisa',
            'surname' => self::PREFIX.'Bianchi',
            'email' => 'luisa.bianchi@example.com',
            'phone_prefix' => '+39',
            'phone' => '3401234567',
            'country' => 'IT',
            'province' => 'MI',
            'city' => 'Milano',
            'cap' => '20121',
            'street' => 'Via Manzoni',
            'number' => '12',
            'is_customer' => 'true',
        ]);

        $azienda = self::contact([
            'type' => 'business',
            'business_name' => self::PREFIX.'Rossi Srl',
            'name' => 'Marco',
            'surname' => 'Rossi',
            'email' => 'amministrazione.rossi@example.com',
            'pi' => '00743110157',
            'sdi' => 'ABCDEFG',
            'pec' => 'rossi.pec@example.com',
            'country' => 'IT',
            'province' => 'MI',
            'city' => 'Sesto San Giovanni',
            'cap' => '20099',
            'street' => 'Viale Italia',
            'number' => '40',
            'is_customer' => 'true',
        ]);
        $created += $azienda;

        // Chi fattura in sede e si fa consegnare al magazzino: è il caso per
        // cui gli indirizzi di consegna esistono.
        $created += self::address(self::PREFIX.'Rossi Srl', [
            'label' => 'Magazzino',
            'name' => 'Giulia',
            'surname' => 'Verdi',
            'phone_prefix' => '+39',
            'phone' => '3391112233',
            'country' => 'IT',
            'province' => 'MI',
            'city' => 'Pioltello',
            'cap' => '20096',
            'street' => 'Via delle Industrie',
            'number' => '8',
            'is_default' => 'true',
            'position' => 1,
        ]);

        $created += self::contact([
            'type' => 'business',
            'business_name' => self::PREFIX.'Filati Nord Spa',
            'email' => 'ordini.filatinord@example.com',
            'pi' => '00950501007',
            'country' => 'IT',
            'province' => 'BG',
            'city' => 'Bergamo',
            'cap' => '24121',
            'street' => 'Via Borgo Palazzo',
            'number' => '100',
            'is_customer' => 'false',
            'is_supplier' => 'true',
        ]);

        $created += self::contact([
            'type' => 'business',
            'business_name' => self::PREFIX.'Imballaggi Sud Srl',
            'email' => 'info.imballaggisud@example.com',
            'pi' => '01234567891',
            'country' => 'IT',
            'province' => 'NA',
            'city' => 'Napoli',
            'cap' => '80100',
            'street' => 'Corso Umberto I',
            'number' => '5',
            'is_customer' => 'false',
            'is_supplier' => 'true',
        ]);

        return $created;
    }

    /** @return int righe tolte */
    public static function clear(): int
    {
        $removed = 0;

        foreach (self::rows() as $row) {
            if (!self::isDemo($row)) {
                continue;
            }

            $contactId = (int) $row['id'];

            // Prima gli indirizzi: la chiave esterna non lascia andare una
            // scheda che ne ha ancora.
            foreach (self::addressesOf($contactId) as $address) {
                if (!empty(ContactAddress::delete((int) $address['id'])->success)) {
                    $removed++;
                }
            }

            if (!empty(Contact::delete($contactId)->success)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Una scheda, se non c'è già.
     *
     * @param array<string, mixed> $values
     */
    private static function contact(array $values): int
    {
        $name = trim((string) ($values['business_name'] ?? ''));
        $name = $name !== '' ? $name : trim((string) ($values['surname'] ?? ''));

        if (self::idOf($name) > 0) {
            return 0;
        }

        $values['code'] = Contact::newCode();
        $values['active'] = 'true';
        $values['is_customer'] ??= 'false';
        $values['is_supplier'] ??= 'false';

        return empty(Contact::create($values)->success) ? 0 : 1;
    }

    /**
     * Un indirizzo di consegna per la scheda con quel nome.
     *
     * @param array<string, mixed> $values
     */
    private static function address(string $contactName, array $values): int
    {
        $contactId = self::idOf($contactName);

        if ($contactId <= 0 || self::addressesOf($contactId) !== []) {
            return 0;
        }

        $values['contact_id'] = $contactId;

        return empty(ContactAddress::create($values)->success) ? 0 : 1;
    }

    /** L'id della scheda di prova con quel nome, `0` se non c'è. */
    private static function idOf(string $name): int
    {
        foreach (self::rows() as $row) {
            if ((string) ($row['business_name'] ?? '') === $name
                || (string) ($row['surname'] ?? '') === $name) {
                return (int) $row['id'];
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $row */
    private static function isDemo(array $row): bool
    {
        foreach (['business_name', 'surname'] as $column) {
            if (str_starts_with((string) ($row[$column] ?? ''), self::PREFIX)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(): array
    {
        return self::listOf(Contact::find(['deleted' => 'false']));
    }

    /** @return list<array<string, mixed>> */
    private static function addressesOf(int $contactId): array
    {
        return self::listOf(ContactAddress::find([
            'contact_id' => $contactId,
            'deleted' => 'false',
        ]));
    }

    /** @return list<array<string, mixed>> */
    private static function listOf(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
