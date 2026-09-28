<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Throwable;
use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelSupplier;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress;
use Wonder\Plugin\Gestionale\Models\Stock\Stock;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;

/**
 * Quattro schede di prova: due clienti e due fornitori.
 *
 * Servono a vedere gli elenchi pieni e a provare i casi che contano davvero —
 * un privato senza partita IVA, un'azienda con fatturazione elettronica, e un
 * indirizzo di consegna diverso da quello di fatturazione.
 *
 * Le schede si riconoscono dal segno nel codice (`con_demo-bianchi`, vedi
 * `DemoCode`), non dal nome. Se la rubrica ha già una scheda vera con lo
 * stesso nome, quella di prova non si crea e la vera resta com'è. La pulizia
 * toglie le schede col segno e quelle con i vecchi nomi `Prova …`, con i loro
 * indirizzi, tranne quelle che un movimento di magazzino o il costo
 * d'acquisto di un articolo o di un'opzione nomina come fornitore.
 *
 * Le partite IVA sono valide davvero: il campo del core le controlla, e una
 * finta non si salverebbe. Stessa cosa per le email, di cui il core controlla
 * **il dominio**: solo `example.com`, che esiste ed è riservato alle prove —
 * un `@qualcosa-di-inventato.example.com` verrebbe rifiutato.
 */
final class ContactsDemo
{
    private const KEY = 'contacts';

    /** Le schede vere usate al posto di quelle di prova, per la nota finale. @var list<string> */
    private static array $reused = [];

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
        self::$reused = [];
        $created = 0;

        $created += self::contact('bianchi', [
            'type' => 'private',
            'name' => 'Luca',
            'surname' => 'Bianchi',
            'email' => 'luca.bianchi@example.com',
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

        $created += self::contact('rossi-abbigliamento', [
            'type' => 'business',
            'business_name' => 'Rossi Abbigliamento Srl',
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

        // Chi fattura in sede e si fa consegnare al magazzino: è il caso per
        // cui gli indirizzi di consegna esistono.
        $created += self::address('rossi-abbigliamento', [
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

        $created += self::contact('filati-nord', [
            'type' => 'business',
            'business_name' => 'Filati Nord Spa',
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

        $created += self::contact('imballaggi-sud', [
            'type' => 'business',
            'business_name' => 'Imballaggi Sud Srl',
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

        DemoData::note(DemoCode::reusedNote(self::$reused));
        self::$reused = [];

        return $created;
    }

    /** @return int righe tolte */
    public static function clear(): int
    {
        $removed = 0;
        $kept = [];

        foreach (self::rows() as $row) {
            if (!self::isDemo($row)) {
                continue;
            }

            $contactId = (int) $row['id'];

            // Un fornitore di prova che un movimento di magazzino nomina resta:
            // quel movimento è storia vera.
            if (self::usedAsSupplier($contactId)) {
                $kept[] = DemoCode::label(Contact::class, Contacts::displayName($row));
                continue;
            }

            // Prima gli indirizzi: la chiave esterna non lascia andare una
            // scheda che ne ha ancora.
            foreach (self::addressesOf($contactId) as $address) {
                $addressId = (int) $address['id'];
                $removed += self::remove(static fn () => ContactAddress::delete($addressId)) ? 1 : 0;
            }

            if (self::remove(static fn () => Contact::delete($contactId))) {
                $removed++;
            } else {
                $kept[] = DemoCode::label(Contact::class, Contacts::displayName($row));
            }
        }

        DemoData::note(DemoCode::keptNote($kept));

        return $removed;
    }

    /**
     * Una scheda di prova, se non c'è già: né col segno, né una vera con lo
     * stesso nome.
     *
     * @param array<string, mixed> $values
     */
    private static function contact(string $ref, array $values): int
    {
        $code = DemoCode::forModel(Contact::class, $ref);
        $name = Contacts::displayName($values);
        $found = DemoCode::pick(self::rows(), $code, $name, [Contacts::class, 'displayName']);

        if ($found !== null) {
            if (!$found['demo']) {
                self::$reused[] = DemoCode::label(Contact::class, $name);
            }

            return 0;
        }

        $values['code'] = $code;
        $values['active'] = 'true';
        $values['is_customer'] ??= 'false';
        $values['is_supplier'] ??= 'false';

        return empty(Contact::create($values)->success) ? 0 : 1;
    }

    /**
     * Un indirizzo di consegna per la scheda di prova con quel riferimento,
     * se non ne ha già. Una scheda vera non si tocca.
     *
     * @param array<string, mixed> $values
     */
    private static function address(string $ref, array $values): int
    {
        $row = Contact::find(['code' => DemoCode::forModel(Contact::class, $ref), 'deleted' => 'false'], 1);
        $contactId = is_array($row) ? (int) ($row['id'] ?? 0) : 0;

        if ($contactId <= 0 || self::addressesOf($contactId) !== []) {
            return 0;
        }

        $values['contact_id'] = $contactId;

        return empty(ContactAddress::create($values)->success) ? 0 : 1;
    }

    /**
     * La scheda è dei dati di prova? Col segno nel codice, o con un vecchio
     * nome: il privato aveva il prefisso nel cognome, le aziende nella
     * ragione sociale.
     *
     * @param array<string, mixed> $row
     */
    private static function isDemo(array $row): bool
    {
        return DemoCode::is((string) ($row['code'] ?? ''))
            || DemoCode::isLegacy(Contact::class, (string) ($row['business_name'] ?? ''))
            || DemoCode::isLegacy(Contact::class, (string) ($row['surname'] ?? ''));
    }

    /**
     * Qualche giacenza, movimento o costo d'acquisto nomina la scheda come
     * fornitore? I costi stanno in due tabelle: quella dell'articolo e quella
     * delle eccezioni di un'opzione.
     */
    private static function usedAsSupplier(int $contactId): bool
    {
        foreach ([Stock::class, StockMovement::class, ProductModelSupplier::class, ProductSupplier::class] as $model) {
            try {
                $row = $model::find(
                    'supplier_id = '.$contactId." AND (deleted = 'true' OR deleted = 'false')",
                    1
                );
            } catch (Throwable) {
                continue;
            }

            if (is_array($row) && $row !== []) {
                return true;
            }
        }

        return false;
    }

    /** Una cancellazione riuscita? Un errore del database conta come un no. */
    private static function remove(callable $delete): bool
    {
        try {
            $result = $delete();
        } catch (Throwable) {
            return false;
        }

        return is_object($result) && !empty($result->success);
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
