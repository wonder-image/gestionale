<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Throwable;
use Wonder\Plugin\CustomerAccount\Support\ContactPoints;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;

/**
 * Il referente di un documento intestato a un'azienda (`company_id`), e a
 * chi dell'azienda va in copia.
 *
 * Il referente è la persona del documento (`customer_id`): si sceglie fra
 * tutte le persone legate all'azienda (`people()`), e di default è proposto
 * il primo della scheda. Il nome si copia in `referent_name`, così resta
 * anche se il legame cambia. Con il brand del documento si propone prima chi
 * segue quel brand. «Principale» e «Riceve i documenti» non si scelgono più
 * nei form e qui non contano: restano salvati sul legame.
 *
 * In copia (`copyEmails()`) vanno le email dell'azienda e il referente
 * scelto sul documento: nessun altro referente riceve copie da solo.
 * `recipients()` elenca chi «si occupa di» l'area del documento (`AREAS`), di
 * tutto o è dell'ufficio «Generale», e segue il brand del documento o tutti
 * (`ContactRelations::peopleFor()`); se nessuno corrisponde, tutti i
 * referenti. L'email di un referente è quella per quell'azienda
 * (`contact_email`: di lavoro se c'è, altrimenti la principale della persona).
 *
 * Le email dell'azienda (`companyEmails()`) sono quelle del suo riquadro
 * «Contatti» che si occupano dell'area del documento o di tutto
 * (`ContactPoints::companyEmailsFor()`); se nessuna corrisponde, la
 * principale della scheda, come prima delle aree.
 */
final class DocumentReferents
{
    /**
     * L'area di ogni documento che parte verso un'azienda: chi compra riceve
     * preventivo, conferma e DDT; chi paga proforma e solleciti degli
     * abbonamenti. Un documento che non è qui non ha un'area: vale per tutti. Le chiavi sono quelle delle email (`OrderNotifier`,
     * `subscription.` + quelle di `SubscriptionNotifier`); un sito ne aggiunge
     * o ne cambia con `useArea()`.
     */
    public const AREAS = [
        'quote_sent' => 'purchasing',
        'office_confirmed' => 'purchasing',
        'delivery_note_sent' => 'purchasing',
        'subscription.proforma' => 'administration',
        'subscription.reminder' => 'administration',
        'subscription.payment_failed' => 'administration',
    ];

    /** @var array<string, string> le aree cambiate o aggiunte da `useArea()` */
    private static array $areas = [];

    /** @var null|callable(int, int, string): list<array<string, mixed>> azienda, brand (0 = tutti), area ('' = tutte) */
    private static $reader = null;

    /** @var null|callable(int, string): list<string> solo per i test: azienda, area → le sue email */
    private static $companyReader = null;

    /**
     * Cambia o aggiunge l'area di un documento; vuota = nessuna area.
     * Un modulo lo chiama al boot.
     */
    public static function useArea(string $document, string $area): void
    {
        self::$areas[trim($document)] = trim($area);
    }

    /** L'area di un documento; vuota se non ne ha una. */
    public static function area(string $document): string
    {
        return self::$areas[$document] ?? self::AREAS[$document] ?? '';
    }

    /**
     * I referenti dell'azienda per un documento: chi si occupa della sua area
     * (o di tutto, o è «Generale») e segue il brand (o tutti), nell'ordine
     * della scheda. Nessuno così: tutti i referenti.
     *
     * @return list<array{id: int, name: string, email: string, phone: string, job_title: string, departments: list<string>, role: string, is_primary: bool}>
     */
    public static function recipients(int $companyId, string $document = '', int $brandId = 0): array
    {
        $area = self::area($document);

        if ($area !== '' || $brandId > 0) {
            $matching = self::read($companyId, max(0, $brandId), $area);

            if ($matching !== []) {
                return $matching;
            }
        }

        return self::read($companyId);
    }

    /**
     * Tutti i referenti dell'azienda, senza guardare area e brand: come `people()`.
     *
     * @return list<array{id: int, name: string, email: string, phone: string, job_title: string, departments: list<string>, role: string, is_primary: bool}>
     */
    public static function options(int $companyId): array
    {
        return self::read($companyId);
    }

    /**
     * Tutte le persone legate all'azienda: fra queste si sceglie il referente.
     *
     * @return list<array{id: int, name: string, email: string, phone: string, job_title: string, departments: list<string>, role: string, is_primary: bool}>
     */
    public static function people(int $companyId): array
    {
        return self::read($companyId);
    }

    /**
     * Con `$brandId` solo chi segue quel brand o tutti i brand, con `$area`
     * solo chi se ne occupa o si occupa di tutto (`ContactRelations::peopleFor()`).
     *
     * @return list<array{id: int, name: string, email: string, phone: string, job_title: string, departments: list<string>, role: string, is_primary: bool}>
     */
    private static function read(int $companyId, int $brandId = 0, string $area = ''): array
    {
        if ($companyId <= 0 || !ContactRelations::enabled()) {
            return [];
        }

        try {
            $rows = self::$reader !== null ? (self::$reader)($companyId, $brandId, $area) : ContactRelations::peopleFor($companyId, $area, $brandId);
        } catch (Throwable $error) {
            error_log('[gestionale] referenti di '.$companyId.' non letti: '.$error->getMessage());

            return [];
        }

        return self::fromRows($rows);
    }

    /**
     * Dalle righe di `ContactRelations::peopleFor()` alle opzioni: solo legami
     * vivi, con la persona viva. `$onlyDocuments` resta per compatibilità e
     * non filtra più: «Riceve i documenti» non si sceglie più.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: int, name: string, email: string, phone: string, job_title: string, departments: list<string>, role: string, is_primary: bool}>
     */
    public static function fromRows(array $rows, bool $onlyDocuments = true): array
    {
        $out = [];
        $seen = [];

        foreach ($rows as $row) {
            $contact = is_array($row['contact'] ?? null) ? $row['contact'] : [];
            $id = (int) ($row[ContactRelations::PERSON] ?? ($contact['id'] ?? 0));

            if ($id <= 0 || isset($seen[$id])
                || ($row['deleted'] ?? 'false') !== 'false'
                || ($contact['deleted'] ?? 'false') !== 'false') {
                continue;
            }

            $seen[$id] = true;
            $workPhone = trim((string) ($row['work_phone'] ?? ''));
            $out[] = [
                'id' => $id,
                'name' => Contacts::displayName($contact),
                // Per quell'azienda: l'email di lavoro se c'è, altrimenti quella della persona.
                'email' => strtolower(trim((string) ($row['contact_email'] ?? ($contact['email'] ?? '')))),
                'phone' => $workPhone !== ''
                    ? trim((string) ($row['work_phone_prefix'] ?? '').' '.$workPhone)
                    : trim((string) ($contact['phone_prefix'] ?? '').' '.(string) ($contact['phone'] ?? '')),
                'job_title' => $job = trim((string) ($row['job_title'] ?? '')),
                'departments' => $areas = ContactRelations::cleanDepartments($row['departments'] ?? []),
                // Il ruolo, altrimenti gli uffici: è quello che si legge nelle tendine.
                'role' => $job !== '' ? $job : self::areasLabel($areas),
                'is_primary' => ($row['is_primary'] ?? 'false') === 'true',
            ];
        }

        return $out;
    }

    /** «Acquisti, Amministrazione»: le aree di un referente da leggere; vuota = si occupa di tutto. @param list<string> $areas */
    public static function areasLabel(array $areas): string
    {
        return implode(', ', array_filter(array_map(static fn (string $area): string => ContactRelations::departmentLabel($area), $areas)));
    }

    /**
     * Il referente proposto per un documento nuovo: il primo della scheda,
     * altrimenti nessuno. Con il brand del documento, il primo fra chi segue
     * quel brand; se nessuno lo segue, il primo di tutti.
     */
    public static function defaultFor(int $companyId, int $brandId = 0): int
    {
        foreach (array_unique([max(0, $brandId), 0]) as $brand) {
            $first = self::read($companyId, $brand)[0] ?? null;

            if ($first !== null) {
                return $first['id'];
            }
        }

        return 0;
    }

    /**
     * Il referente scelto, controllato contro quelli dell'azienda. Un id che non
     * è fra le opzioni diventa «nessun referente».
     *
     * @return array{customer_id: int, referent_name: string}
     */
    public static function resolve(int $companyId, int $personId): array
    {
        if ($personId > 0) {
            foreach (self::people($companyId) as $option) {
                if ($option['id'] === $personId) {
                    return ['customer_id' => $option['id'], 'referent_name' => $option['name']];
                }
            }
        }

        return ['customer_id' => 0, 'referent_name' => ''];
    }

    /**
     * L'email a cui scrivere a un referente per quell'azienda (`contact_email`:
     * di lavoro se c'è, altrimenti la sua); vuota se non è un suo referente.
     */
    public static function emailOf(int $companyId, int $personId): string
    {
        foreach ($personId > 0 ? self::people($companyId) : [] as $option) {
            if ($option['id'] === $personId) {
                return $option['email'];
            }
        }

        return '';
    }

    /**
     * Le email dell'azienda per un documento: quelle del riquadro «Contatti»
     * che si occupano della sua area o di tutto, la principale per prima
     * (`ContactPoints::companyEmailsFor()`). Se nessuna corrisponde (o non si
     * leggono), la principale della scheda (`$company`), come prima. Mai
     * un'email di accesso: le aziende non ne hanno.
     *
     * @param array<string, mixed>|null $company la scheda dell'azienda, se già letta
     * @return list<string>
     */
    public static function companyEmails(int $companyId, string $document = '', ?array $company = null): array
    {
        if ($companyId <= 0) {
            return [];
        }

        try {
            $emails = self::$companyReader !== null
                ? (self::$companyReader)($companyId, self::area($document))
                : ContactPoints::companyEmailsFor($companyId, self::area($document));
        } catch (Throwable $error) {
            error_log('[gestionale] email dell\'azienda '.$companyId.' non lette: '.$error->getMessage());
            $emails = [];
        }

        $emails = array_values(array_filter(array_map(static fn ($email): string => strtolower(trim((string) $email)), $emails)));

        if ($emails !== []) {
            return $emails;
        }

        $main = $company !== null && (int) ($company['id'] ?? 0) === $companyId ? strtolower(trim((string) ($company['email'] ?? ''))) : '';

        return $main !== '' ? [$main] : [];
    }

    /**
     * Gli indirizzi in copia per le mail al cliente di un documento: le email
     * dell'azienda per l'area del documento (`companyEmails()`) e quella del
     * referente scelto sul documento (`customer_id`), senza doppioni e senza
     * gli indirizzi che ricevono già la mail. Gli altri referenti non
     * ricevono copie.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed>|null $company la scheda dell'azienda, se già letta
     * @return list<string>
     */
    public static function copyEmails(array $order, ?array $company = null, string $document = ''): array
    {
        $companyId = (int) ($order['company_id'] ?? 0);

        if ($companyId <= 0) {
            return [];
        }

        $exclude = array_map('strtolower', array_map('trim', preg_split('/[,;\s]+/', (string) ($order['email'] ?? '')) ?: []));
        $emails = self::companyEmails($companyId, $document, $company);

        $emails[] = self::emailOf($companyId, (int) ($order['customer_id'] ?? 0));

        $out = [];

        foreach ($emails as $email) {
            if ($email === '' || in_array($email, $exclude, true) || in_array($email, $out, true)
                || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $out[] = $email;
        }

        return $out;
    }

    /** Solo per i test: sostituisce la lettura dei referenti (`null` torna a `ContactRelations`). */
    public static function useReader(?callable $reader): void
    {
        self::$reader = $reader;
    }

    /** Solo per i test: sostituisce la lettura delle email dell'azienda (`null` torna a `ContactPoints`). */
    public static function useCompanyReader(?callable $reader): void
    {
        self::$companyReader = $reader;
    }
}
