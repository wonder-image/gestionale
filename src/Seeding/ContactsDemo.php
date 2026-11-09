<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Throwable;
use Wonder\Plugin\CustomerAccount\Models\CompanyAddress;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;
use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress;
use Wonder\Plugin\Gestionale\Models\Stock\Stock;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;

/**
 * Le schede di prova: tre persone (due clienti privati e il referente di
 * un'azienda), un'azienda cliente e due aziende fornitrici.
 *
 * Servono a vedere gli elenchi pieni e a provare i casi che contano davvero —
 * un privato senza partita IVA, un'azienda con fatturazione elettronica e il
 * suo referente, e un indirizzo di consegna diverso da quello di fatturazione.
 *
 * Le schede si riconoscono dal segno nel codice (`con_demo-bianchi`,
 * `cmp_demo-filati-nord`, vedi `DemoCode`), non dal nome. Se la rubrica ha già
 * una scheda vera con lo stesso nome, quella di prova non si crea e la vera
 * resta com'è. La pulizia toglie le schede col segno e quelle con i vecchi nomi
 * `Prova …`, con i loro indirizzi e legami, tranne le aziende che un movimento
 * di magazzino o il costo d'acquisto di un articolo nomina come fornitore.
 *
 * Le partite IVA sono valide davvero: il campo del core le controlla, e una
 * finta non si salverebbe. Stessa cosa per le email, di cui il core controlla
 * **il dominio**: solo `example.com`, che esiste ed è riservato alle prove.
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
            'Anagrafiche: tre persone, un\'azienda cliente e due fornitori',
            static fn (): int => self::create(),
            static fn (): int => self::clear()
        );
    }

    /** @return int righe create */
    public static function create(): int
    {
        self::$reused = [];
        $created = 0;

        $created += self::record(Contact::class, 'bianchi', [
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

        $created += self::record(Contact::class, 'verdi', [
            'name' => 'Anna',
            'surname' => 'Verdi',
            'email' => 'anna.verdi@example.com',
            'phone_prefix' => '+39',
            'phone' => '3487654321',
            'country' => 'IT',
            'province' => 'RM',
            'city' => 'Roma',
            'cap' => '00100',
            'street' => 'Via del Corso',
            'number' => '10',
            'is_customer' => 'true',
        ]);

        // Il referente dell'azienda: una persona senza ruolo di cliente suo.
        $created += self::record(Contact::class, 'rossi-marco', [
            'name' => 'Marco',
            'surname' => 'Rossi',
            'email' => 'marco.rossi@example.com',
            'phone_prefix' => '+39',
            'phone' => '3355550101',
            'country' => 'IT',
            'is_customer' => 'false',
        ]);

        $created += self::record(Company::class, 'rossi-abbigliamento', [
            'business_name' => 'Rossi Abbigliamento Srl',
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

        $created += self::relation('rossi-abbigliamento', 'rossi-marco', 'Titolare');

        // Chi fattura in sede e si fa consegnare al magazzino: è il caso per
        // cui gli indirizzi di consegna esistono.
        $created += self::address(Company::class, 'rossi-abbigliamento', [
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

        $created += self::record(Company::class, 'filati-nord', [
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

        $created += self::record(Company::class, 'imballaggi-sud', [
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

        // Prima le aziende: i legami con le persone se ne vanno con loro.
        foreach ([Company::class, Contact::class] as $model) {
            foreach (self::rows($model) as $row) {
                if (!self::isDemo($model, $row)) {
                    continue;
                }

                $id = (int) $row['id'];
                $label = DemoCode::label($model, Contacts::displayName($row));

                // Un fornitore di prova che un movimento di magazzino nomina
                // resta: quel movimento è storia vera.
                if ($model === Company::class && self::usedAsSupplier($id)) {
                    $kept[] = $label;
                    continue;
                }

                $column = $model === Company::class ? 'company_id' : 'person_id';
                foreach (self::listOf(self::safeFind(ContactRelation::class, [$column => $id])) as $relation) {
                    $relationId = (int) $relation['id'];
                    self::remove(static fn () => ContactRelation::delete($relationId));
                }

                // Poi gli indirizzi: la chiave esterna non lascia andare una
                // scheda che ne ha ancora.
                foreach (self::addressesOf($model, $id) as $address) {
                    $addressId = (int) $address['id'];
                    $addressModel = self::addressModel($model);
                    $removed += self::remove(static fn () => $addressModel::delete($addressId)) ? 1 : 0;
                }

                if (self::remove(static fn () => $model::delete($id))) {
                    $removed++;
                } else {
                    $kept[] = $label;
                }
            }
        }

        DemoData::note(DemoCode::keptNote($kept));

        return $removed;
    }

    /**
     * Una scheda di prova (persona o azienda), se non c'è già: né col segno,
     * né una vera con lo stesso nome.
     *
     * @param class-string $model
     * @param array<string, mixed> $values
     */
    private static function record(string $model, string $ref, array $values): int
    {
        $code = DemoCode::forModel($model, $ref);
        $name = Contacts::displayName($values);
        $found = DemoCode::pick(self::rows($model), $code, $name, [Contacts::class, 'displayName']);

        if ($found !== null) {
            if (!$found['demo']) {
                self::$reused[] = DemoCode::label($model, $name);
            }

            return 0;
        }

        // La scheda di prova c'è ancora ma è stata cancellata: si rimette al
        // suo posto, perché il codice è unico e rifarla non si può.
        if (DemoCode::revive($model, $code) > 0) {
            return 1;
        }

        $values['code'] = $code;
        $values['active'] = 'true';
        $values['is_customer'] ??= 'false';
        if ($model === Company::class) {
            $values['is_supplier'] ??= 'false';
        }

        return empty($model::create($values)->success) ? 0 : 1;
    }

    /** Il legame azienda/persona fra due schede di prova, se manca. */
    private static function relation(string $companyRef, string $personRef, string $jobTitle): int
    {
        $companyId = self::idOf(Company::class, $companyRef);
        $personId = self::idOf(Contact::class, $personRef);

        if ($companyId <= 0 || $personId <= 0
            || self::listOf(self::safeFind(ContactRelation::class, ['company_id' => $companyId, 'person_id' => $personId])) !== []) {
            return 0;
        }

        return empty(ContactRelation::create([
            'company_id' => $companyId,
            'person_id' => $personId,
            'job_title' => $jobTitle,
            'is_primary' => 'true',
            'receives_documents' => 'true',
            'access' => ContactRelation::ACCESS_NONE,
            'position' => 1,
        ])->success) ? 0 : 1;
    }

    /**
     * Un indirizzo di consegna per la scheda di prova con quel riferimento,
     * se non ne ha già. Una scheda vera non si tocca.
     *
     * @param class-string $model
     * @param array<string, mixed> $values
     */
    private static function address(string $model, string $ref, array $values): int
    {
        $id = self::idOf($model, $ref);

        if ($id <= 0 || self::addressesOf($model, $id) !== []) {
            return 0;
        }

        $addressModel = self::addressModel($model);
        $values[$model === Company::class ? 'company_id' : 'contact_id'] = $id;

        return empty($addressModel::create($values)->success) ? 0 : 1;
    }

    /** @param class-string $model */
    private static function idOf(string $model, string $ref): int
    {
        $row = $model::find(['code' => DemoCode::forModel($model, $ref), 'deleted' => 'false'], 1);

        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }

    /** @param class-string $model */
    private static function addressModel(string $model): string
    {
        return $model === Company::class ? CompanyAddress::class : ContactAddress::class;
    }

    /**
     * La scheda è dei dati di prova? Col segno nel codice, o con un vecchio
     * nome: il privato aveva il prefisso nel cognome, le aziende nella
     * ragione sociale.
     *
     * @param class-string $model
     * @param array<string, mixed> $row
     */
    private static function isDemo(string $model, array $row): bool
    {
        return DemoCode::is((string) ($row['code'] ?? ''))
            || DemoCode::isLegacy($model, (string) ($row['business_name'] ?? ''))
            || DemoCode::isLegacy($model, (string) ($row['surname'] ?? ''));
    }

    /**
     * Qualche giacenza, movimento o costo d'acquisto nomina la scheda come
     * fornitore?
     */
    private static function usedAsSupplier(int $companyId): bool
    {
        foreach ([Stock::class, StockMovement::class, ProductSupplier::class] as $model) {
            try {
                $row = $model::find(
                    'supplier_id = '.$companyId." AND (deleted = 'true' OR deleted = 'false')",
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

    /**
     * @param class-string $model
     * @return list<array<string, mixed>>
     */
    private static function rows(string $model): array
    {
        return self::listOf(self::safeFind($model, ['deleted' => 'false']));
    }

    /**
     * @param class-string $model
     * @return list<array<string, mixed>>
     */
    private static function addressesOf(string $model, int $id): array
    {
        return self::listOf(self::safeFind(self::addressModel($model), [
            $model === Company::class ? 'company_id' : 'contact_id' => $id,
            'deleted' => 'false',
        ]));
    }

    /** Una lettura che con la tabella mancante (modulo non aggiornato) dà niente. */
    private static function safeFind(string $model, array $condition): mixed
    {
        try {
            return $model::find($condition);
        } catch (Throwable) {
            return [];
        }
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
