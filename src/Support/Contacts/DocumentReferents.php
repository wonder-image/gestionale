<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Throwable;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;

/**
 * Il referente di un documento intestato a un'azienda (`company_id`).
 *
 * Il referente è la persona del documento (`customer_id`): si sceglie fra
 * quelli dell'azienda che ricevono i documenti (`receives_documents`), e il
 * principale è proposto di default. Il nome si copia in `referent_name`, così
 * resta anche se il legame cambia. Con le relazioni spente non c'è niente da
 * scegliere.
 */
final class DocumentReferents
{
    /** @var null|callable(int): list<array<string, mixed>> */
    private static $reader = null;

    /**
     * @return list<array{id: int, name: string, email: string, job_title: string, is_primary: bool}>
     */
    public static function options(int $companyId): array
    {
        if ($companyId <= 0 || !ContactRelations::enabled()) {
            return [];
        }

        try {
            $rows = self::$reader !== null ? (self::$reader)($companyId) : ContactRelations::peopleOf($companyId);
        } catch (Throwable $error) {
            error_log('[gestionale] referenti di '.$companyId.' non letti: '.$error->getMessage());

            return [];
        }

        return self::fromRows($rows);
    }

    /**
     * Dalle righe di `ContactRelations::peopleOf()` alle opzioni: solo legami
     * vivi, con la persona viva, che ricevono i documenti.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: int, name: string, email: string, job_title: string, is_primary: bool}>
     */
    public static function fromRows(array $rows): array
    {
        $out = [];
        $seen = [];

        foreach ($rows as $row) {
            $contact = is_array($row['contact'] ?? null) ? $row['contact'] : [];
            $id = (int) ($row[ContactRelations::PERSON] ?? ($contact['id'] ?? 0));

            if ($id <= 0 || isset($seen[$id])
                || ($row['deleted'] ?? 'false') !== 'false'
                || ($contact['deleted'] ?? 'false') !== 'false'
                || ($row['receives_documents'] ?? 'false') !== 'true') {
                continue;
            }

            $seen[$id] = true;
            $out[] = [
                'id' => $id,
                'name' => Contacts::displayName($contact),
                'email' => strtolower(trim((string) ($contact['email'] ?? ''))),
                'job_title' => trim((string) ($row['job_title'] ?? '')),
                'is_primary' => ($row['is_primary'] ?? 'false') === 'true',
            ];
        }

        return $out;
    }

    /** Il referente proposto per un documento nuovo: il principale, altrimenti nessuno. */
    public static function defaultFor(int $companyId): int
    {
        foreach (self::options($companyId) as $option) {
            if ($option['is_primary']) {
                return $option['id'];
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
            foreach (self::options($companyId) as $option) {
                if ($option['id'] === $personId) {
                    return ['customer_id' => $option['id'], 'referent_name' => $option['name']];
                }
            }
        }

        return ['customer_id' => 0, 'referent_name' => ''];
    }

    /**
     * Gli indirizzi in copia per le mail al cliente di un documento: l'email
     * dell'azienda e quelle dei referenti che ricevono i documenti, senza
     * doppioni e senza l'indirizzo che riceve già la mail.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed>|null $company la scheda dell'azienda, se già letta
     * @return list<string>
     */
    public static function copyEmails(array $order, ?array $company = null): array
    {
        $companyId = (int) ($order['company_id'] ?? 0);
        $options = self::options($companyId);

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
