<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Throwable;
use Wonder\App\Visibility\RowVisibility;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\CustomerAccount\Support\CompanyEvents;
use Wonder\Plugin\CustomerAccount\Support\ContactEvents;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgents;

/**
 * La scheda creata al volo (la creazione rapida di
 * `CustomerResource::quickCreateStore()`): dal «+» nelle righe dei legami il
 * referente di un'azienda o l'azienda di una persona; dall'ordine da ufficio
 * il cliente (persona o azienda) e il referente dell'azienda scelta.
 *
 * Prima di creare cerca un doppione (email per le persone, partita IVA per le
 * aziende): se c'è e chi scrive lo vede, torna quello e il form lo seleziona.
 * Salva come l'utente della sessione, non come `@system`: la scheda creata da
 * un commerciale nasce sua. Legge solo i campi che conosce e rispetta il modo
 * del gestionale (`CustomerMode`).
 */
final class QuickContacts
{
    /** Il valore di `role` che chiede una persona cliente (ordine da ufficio). */
    public const ROLE_CUSTOMER = 'customer';

    /**
     * Il campo con l'azienda a cui legare la scheda nuova (referente, brand).
     * Non si chiama `company_id`: la finestra nasce dentro il form dell'ordine,
     * che ha già il suo.
     */
    public const LINK_COMPANY = 'link_company_id';

    /**
     * Una persona: nome o cognome, email facoltativa.
     *
     * Di serie è un referente, non cliente. Con `role` = `customer` nasce
     * cliente, dove si vende ai privati. Con `LINK_COMPANY` (anche zero: il
     * campo c'è ma l'azienda no) nasce già legata a quell'azienda, che deve
     * esistere ed essere fra quelle che chi scrive vede; la persona che c'era
     * già si lega lo stesso, senza toccare gli altri suoi legami.
     *
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string, id: int, label: string, existing: bool}
     */
    public static function person(array $post): array
    {
        $name = self::text($post, 'name', 100);
        $surname = self::text($post, 'surname', 100);
        $email = mb_strtolower(self::text($post, 'email', 190));
        $customer = ($post['role'] ?? '') === self::ROLE_CUSTOMER;
        $companyId = array_key_exists(self::LINK_COMPANY, $post) ? self::companyFor($post[self::LINK_COMPANY]) : 0;

        if ($companyId < 0) {
            return self::error(match ($companyId) {
                -1 => 'Scegli prima l\'azienda: il referente nasce legato a lei.',
                -2 => 'Il gestionale lavora solo con le persone: i referenti delle aziende non si creano.',
                default => 'Questa azienda non c\'è più o non è fra le tue: ricarica la pagina.',
            });
        }

        if ($customer && !CustomerMode::people()) {
            return self::error('Il gestionale lavora solo con le aziende: crea l\'azienda e aggiungi la persona come suo referente.');
        }

        if ($name === '' && $surname === '') {
            return self::error('Scrivi almeno il nome o il cognome.');
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return self::error('L\'email non è valida.');
        }

        $result = $email !== '' ? self::existing('email', $email, Contact::class) : null;

        if ($result === null) {
            $values = array_filter([
                'is_customer' => $customer ? 'true' : 'false',
                'active' => 'true',
                'name' => $name,
                'surname' => $surname,
                'email' => $email,
            ], static fn (string $value): bool => $value !== '') + self::agent();

            $result = self::store(Contact::class, $values, static function (int $id, array $row): void {
                ContactEvents::dispatch(ContactEvents::CREATED, ['id' => $id, 'contact' => $row]);
            });
        }

        if ($result['ok'] && $companyId > 0) {
            try {
                self::link($result['id'], $companyId);
            } catch (Throwable) {
                return self::error('La persona è salvata, ma non sono riuscito a legarla all\'azienda: aggiungila dalla scheda dell\'azienda.');
            }
        }

        return $result;
    }

    /**
     * L'azienda a cui legare la persona nuova: l'id, oppure -1 se manca, -2
     * se il gestionale non ha aziende, -3 se non c'è o chi scrive non la vede.
     */
    private static function companyFor(mixed $raw): int
    {
        $id = is_scalar($raw) && ctype_digit(trim((string) $raw)) ? (int) $raw : 0;

        return match (true) {
            $id <= 0 => -1,
            !CustomerMode::companies() => -2,
            !self::reachableCompany($id) => -3,
            default => $id,
        };
    }

    /** L'azienda è viva e chi scrive la vede (il commerciale vede solo le sue). */
    public static function reachableCompany(int $companyId): bool
    {
        if ($companyId <= 0) {
            return false;
        }

        try {
            $row = Company::findById($companyId);
        } catch (Throwable) {
            return false;
        }

        return is_array($row) && isset($row['id']) && ($row['deleted'] ?? 'false') === 'false'
            && self::visible(Company::class, $companyId);
    }

    /**
     * Lega la persona all'azienda dal lato della persona: i suoi legami di
     * prima tornano tali e quali (mansione, principale, documenti, accesso,
     * reparto), quello nuovo si aggiunge in fondo.
     */
    private static function link(int $personId, int $companyId): void
    {
        if (ContactRelations::isLinked($personId, $companyId)) {
            return;
        }

        $rows = array_values(array_filter(
            ContactRelations::allRowsOf(ContactRelations::PERSON, $personId),
            static fn (array $row): bool => ($row['deleted'] ?? 'false') === 'false'
        ));
        $rows[] = [ContactRelations::COMPANY => $companyId];

        ContactRelations::sync(ContactRelations::PERSON, $personId, $rows);
    }

    /**
     * Un'azienda: ragione sociale, partita IVA facoltativa. Nasce cliente,
     * come dall'elenco «Aziende».
     *
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string, id: int, label: string, existing: bool}
     */
    public static function company(array $post): array
    {
        if (!CustomerMode::companies()) {
            return self::error('Il gestionale lavora solo con le persone: le aziende non si creano.');
        }

        $business = self::text($post, 'business_name', 190);
        $pi = strtoupper(str_replace(' ', '', self::text($post, 'pi', 30)));

        if ($business === '') {
            return self::error('Scrivi la ragione sociale.');
        }

        // Il prefisso del paese (IT0123…) si toglie: la partita IVA si valida col paese.
        $country = '';

        if (preg_match('/^([A-Z]{2})(\d.*)$/', $pi, $match) === 1) {
            [, $country, $pi] = $match;
        }

        if ($pi !== '' && ($found = self::existing('pi', $pi, Company::class)) !== null) {
            return $found;
        }

        $values = array_filter([
            'is_customer' => 'true',
            'active' => 'true',
            'business_name' => $business,
            'pi' => $pi,
            'country' => $pi !== '' ? ($country !== '' ? $country : 'IT') : '',
        ], static fn (string $value): bool => $value !== '') + self::agent();

        return self::store(Company::class, $values, static function (int $id, array $row): void {
            CompanyEvents::dispatch(CompanyEvents::CREATED, ['id' => $id, 'company' => $row]);
        });
    }

    /**
     * Il doppione, se c'è: visibile si seleziona, altrimenti si dice di
     * chiedere all'ufficio (il commerciale non vede le schede degli altri).
     *
     * @param class-string $model
     * @return array{ok: bool, message: string, id: int, label: string, existing: bool}|null
     */
    private static function existing(string $column, string $value, string $model): ?array
    {
        $row = Contacts::duplicateOf($column, $value, null, $model);

        if ($row === []) {
            return null;
        }

        $id = (int) $row['id'];

        if (!self::visible($model, $id)) {
            return self::error($column === 'pi'
                ? 'C\'è già un\'azienda con questa partita IVA: chiedi all\'ufficio di collegarla.'
                : 'C\'è già una persona con questa email: chiedi all\'ufficio di collegarla.');
        }

        $label = self::label($row);

        return [
            'ok' => true,
            'message' => ($column === 'pi' ? 'L\'azienda c\'era già' : 'La persona c\'era già').': «'.$label.'» è stata selezionata.',
            'id' => $id,
            'label' => $label,
            'existing' => true,
        ];
    }

    /** @param class-string $model */
    private static function visible(string $model, int $id): bool
    {
        try {
            return RowVisibility::visible($model::$table, $id, RowVisibility::currentUser());
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * @param class-string $model
     * @param array<string, string|int> $values
     * @param callable(int, array<string, mixed>): void $event
     * @return array{ok: bool, message: string, id: int, label: string, existing: bool}
     */
    private static function store(string $model, array $values, callable $event): array
    {
        try {
            $result = $model::create($values);
        } catch (Throwable $e) {
            return self::error($e->getMessage() !== '' ? $e->getMessage() : 'Non sono riuscito a salvare la scheda.');
        }

        $id = (int) ($result->insert_id ?? 0);

        if (!($result->success ?? false) || $id <= 0) {
            return self::error(self::invalid($result) ?? 'Non sono riuscito a salvare la scheda.');
        }

        $row = $model::findById($id);
        $row = is_array($row) ? $row : $values;
        $event($id, $row);

        return ['ok' => true, 'message' => '', 'id' => $id, 'label' => self::label($row), 'existing' => false];
    }

    /** Il primo messaggio di validazione del modello (es. partita IVA non valida). */
    private static function invalid(object $result): ?string
    {
        foreach ((array) ($result->response ?? []) as $check) {
            if (is_object($check) && method_exists($check, 'isValid') && !$check->isValid()) {
                $message = trim((string) ($check->message ?? ''));

                if ($message !== '') {
                    return $message;
                }
            }
        }

        return null;
    }

    /** Il commerciale di chi crea, come per le schede dal form (#77). */
    private static function agent(): array
    {
        $own = SalesAgents::current();

        return $own > 0 ? ['agent_id' => $own] : [];
    }

    /** Come le tendine dei legami: ragione sociale o «Nome Cognome», ed email se c'è. */
    public static function label(array $row): string
    {
        $name = trim((string) ($row['business_name'] ?? ''));

        if ($name === '') {
            $name = trim(((string) ($row['name'] ?? '')).' '.((string) ($row['surname'] ?? '')));
        }

        $name = html_entity_decode($name !== '' ? $name : '#'.($row['id'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $email = trim((string) ($row['email'] ?? ''));

        return $email !== '' ? "{$name} · {$email}" : $name;
    }

    /** @param array<string, mixed> $post */
    private static function text(array $post, string $key, int $max): string
    {
        return mb_substr(trim(strip_tags((string) ($post[$key] ?? ''))), 0, $max);
    }

    /** @return array{ok: bool, message: string, id: int, label: string, existing: bool} */
    private static function error(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'id' => 0, 'label' => '', 'existing' => false];
    }
}
