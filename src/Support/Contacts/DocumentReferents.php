<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Throwable;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;

/**
 * Il referente di un documento intestato a un'azienda (`company_id`), e a
 * chi dell'azienda va in copia.
 *
 * Il referente è la persona del documento (`customer_id`): si sceglie fra
 * tutte le persone legate all'azienda (`people()`), e il principale è
 * proposto di default. Il nome si copia in `referent_name`, così resta anche
 * se il legame cambia. Con il brand del documento si propone prima chi segue
 * quel brand.
 *
 * Le copie delle mail (`recipients()`) vanno a chi riceve i documenti e «si
 * occupa di» l'area del documento (`AREAS`) o di tutto, e segue il brand del
 * documento o tutti: `ContactRelations::peopleFor()`. Se nessuno corrisponde
 * vale la regola di prima: tutti quelli che ricevono i documenti. Un'azienda
 * che non compila le aree non vede cambiare niente. Le email sono solo quelle
 * principali delle schede: gli «altri contatti» non ricevono mai documenti.
 */
final class DocumentReferents
{
    /**
     * L'area di ogni documento che parte verso un'azienda: chi compra riceve
     * preventivo, conferma e DDT; chi paga proforma e solleciti degli
     * abbonamenti. Un documento che non è qui va a chi riceve i documenti,
     * qualunque cosa segua. Le chiavi sono quelle delle email (`OrderNotifier`,
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

    /**
     * Cambia o aggiunge l'area di un documento; vuota = a chi riceve i
     * documenti, qualunque area segua. Un modulo lo chiama al boot.
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
     * Chi dell'azienda riceve un documento: chi riceve i documenti fra chi si
     * occupa della sua area (o di tutto) e segue il brand (o tutti), il
     * principale per primo. Nessuno così: tutti quelli che ricevono i
     * documenti, come prima delle aree.
     *
     * @return list<array{id: int, name: string, email: string, job_title: string, departments: list<string>, role: string, is_primary: bool}>
     */
    public static function recipients(int $companyId, string $document = '', int $brandId = 0): array
    {
        $area = self::area($document);

        if ($area !== '' || $brandId > 0) {
            $matching = self::read($companyId, true, max(0, $brandId), $area);

            if ($matching !== []) {
                return $matching;
            }
        }

        return self::read($companyId, true);
    }

    /**
     * Tutti quelli che ricevono i documenti, senza guardare area e brand.
     *
     * @return list<array{id: int, name: string, email: string, job_title: string, departments: list<string>, role: string, is_primary: bool}>
     */
    public static function options(int $companyId): array
    {
        return self::read($companyId, true);
    }

    /**
     * Tutte le persone legate all'azienda, anche quelle che non ricevono i
     * documenti: fra queste si sceglie il referente.
     *
     * @return list<array{id: int, name: string, email: string, job_title: string, departments: list<string>, role: string, is_primary: bool}>
     */
    public static function people(int $companyId): array
    {
        return self::read($companyId, false);
    }

    /**
     * Con `$brandId` solo chi segue quel brand o tutti i brand, con `$area`
     * solo chi se ne occupa o si occupa di tutto (`ContactRelations::peopleFor()`).
     *
     * @return list<array{id: int, name: string, email: string, job_title: string, departments: list<string>, role: string, is_primary: bool}>
     */
    private static function read(int $companyId, bool $onlyDocuments, int $brandId = 0, string $area = ''): array
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

        return self::fromRows($rows, $onlyDocuments);
    }

    /**
     * Dalle righe di `ContactRelations::peopleFor()` alle opzioni: solo legami
     * vivi, con la persona viva e, con `$onlyDocuments`, che ricevono i documenti.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: int, name: string, email: string, job_title: string, departments: list<string>, role: string, is_primary: bool}>
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
                || ($contact['deleted'] ?? 'false') !== 'false'
                || ($onlyDocuments && ($row['receives_documents'] ?? 'false') !== 'true')) {
                continue;
            }

            $seen[$id] = true;
            $out[] = [
                'id' => $id,
                'name' => Contacts::displayName($contact),
                'email' => strtolower(trim((string) ($contact['email'] ?? ''))),
                'job_title' => $job = trim((string) ($row['job_title'] ?? '')),
                'departments' => $areas = ContactRelations::cleanDepartments($row['departments'] ?? []),
                // La mansione, altrimenti le aree di cui si occupa: è quello che si legge nelle tendine.
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
     * Il referente proposto per un documento nuovo: il principale, altrimenti
     * nessuno. Con il brand del documento, prima il principale fra chi segue
     * quel brand; se fra loro non c'è, il principale di tutti.
     */
    public static function defaultFor(int $companyId, int $brandId = 0): int
    {
        foreach (array_unique([max(0, $brandId), 0]) as $brand) {
            foreach (self::read($companyId, false, $brand) as $option) {
                if ($option['is_primary']) {
                    return $option['id'];
                }
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
     * Gli indirizzi in copia per le mail al cliente di un documento: l'email
     * dell'azienda e quelle dei referenti che lo ricevono (`recipients()`,
     * con l'area di `$document` e il brand del documento), senza doppioni e
     * senza l'indirizzo che riceve già la mail.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed>|null $company la scheda dell'azienda, se già letta
     * @return list<string>
     */
    public static function copyEmails(array $order, ?array $company = null, string $document = ''): array
    {
        $companyId = (int) ($order['company_id'] ?? 0);
        $options = self::recipients($companyId, $document, (int) ($order['company_brand_id'] ?? 0));

        if ($options === []) {
            return [];
        }

        $exclude = array_map('strtolower', array_map('trim', preg_split('/[,;\s]+/', (string) ($order['email'] ?? '')) ?: []));
        $emails = [];

        if ($company !== null && (int) ($company['id'] ?? 0) === $companyId) {
            $emails[] = strtolower(trim((string) ($company['email'] ?? '')));
        }

        foreach ($options as $option) {
            $emails[] = $option['email'];
        }

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
}
