<?php

namespace Wonder\Plugin\Gestionale\Support\SalesAgents;

use Throwable;
use Wonder\App\Models\User\User;
use Wonder\App\Visibility\RowVisibility;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrandLink;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgent;
use Wonder\Plugin\Gestionale\Models\Sales\DeliveryNote;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\App\ResourceSchema\TableLayoutSchema;

/**
 * I commerciali (#77, funzionalità `sales_agents`): chi è commerciale, a chi va
 * un documento e cosa vede.
 *
 * È commerciale l'utente con ruolo `sales_agent` e senza un ruolo d'ufficio
 * (`OFFICE`): chi ha anche `sales` lavora per tutti e vede tutto. Il suo
 * perimetro è la riga di `gst_sales_agents` col suo `user_id`; senza riga
 * attiva non vede nulla.
 *
 * La visibilità è in OR: un documento si vede se `agent_id` è il suo (la
 * vendita è sua) oppure se il cliente o l'azienda del documento gli sono
 * assegnati oggi (eredita lo storico). Un contatto si vede anche se è legato a
 * un'azienda sua; un brand se è legato oggi (legame aperto) a un'azienda
 * sua. Le sotto-query non possono filtrare `deleted` (`RowVisibility` lo
 * vieta: una regola che nomina la colonna toglie il soft delete alla query
 * principale): un cliente eliminato ma ancora assegnato apre comunque i suoi
 * documenti, e un legame di referente cancellato apre ancora la persona.
 */
final class SalesAgents
{
    public const ROLE = 'sales_agent';

    /** Ruoli con cui il commerciale torna operatore d'ufficio. */
    public const OFFICE = ['admin', 'administrator', 'sales'];

    /** Le tabelle dei documenti con `customer_id`, `company_id` e `agent_id`. */
    public const DOCUMENT_TABLES = [Order::class, DeliveryNote::class, SalesReturn::class, Subscription::class];

    /** @var array<int, int> user_id → id del commerciale (0 = nessuno) */
    private static array $ids = [];

    public static function enabled(): bool
    {
        return Gestionale::feature('sales_agents');
    }

    /** Vero se l'utente lavora solo sui propri clienti. */
    public static function restricted(?object $user): bool
    {
        if ($user === null || !self::enabled()) {
            return false;
        }

        $roles = RowVisibility::authorities($user);

        return in_array(self::ROLE, $roles, true) && array_intersect($roles, self::OFFICE) === [];
    }

    /** L'id del commerciale attivo dell'utente; 0 se non c'è. */
    public static function idOf(?object $user): int
    {
        $userId = (int) ($user->id ?? 0);
        if ($userId <= 0) {
            return 0;
        }

        if (!array_key_exists($userId, self::$ids)) {
            try {
                $row = SalesAgent::find(['user_id' => $userId, 'active' => 'true', 'deleted' => 'false'], 1);
                $row = is_array($row) && isset($row[0]) && is_array($row[0]) ? $row[0] : (is_array($row) && isset($row['id']) ? $row : []);
                self::$ids[$userId] = (int) ($row['id'] ?? 0);
            } catch (Throwable $error) {
                error_log('[gestionale] commerciale di '.$userId.' non letto: '.$error->getMessage());
                self::$ids[$userId] = 0;
            }
        }

        return self::$ids[$userId];
    }

    /** Il commerciale di chi sta lavorando, se è un commerciale; altrimenti 0. */
    public static function current(): int
    {
        $user = RowVisibility::currentUser();

        return self::restricted($user) ? self::idOf($user) : 0;
    }

    /**
     * I ruoli senza `sales_agent`: per le pagine e i riquadri con dati di tutti.
     *
     * @param list<string> $roles
     * @return list<string>
     */
    public static function withoutAgents(array $roles): array
    {
        return array_values(array_filter($roles, static fn ($role): bool => $role !== self::ROLE));
    }

    /** Dimentica gli id letti: dopo una modifica ai commerciali e nei test. */
    public static function forget(): void
    {
        self::$ids = [];
    }

    /** Registra le regole di visibilità (dal file di boot del modulo). */
    public static function register(): void
    {
        RowVisibility::rule(Contact::$table, static fn (object $user): ?string => self::contactsRule($user));
        RowVisibility::rule(Company::$table, static fn (object $user): ?string => self::companiesRule($user));
        RowVisibility::rule(CompanyBrand::$table, static fn (object $user): ?string => self::brandsRule($user));

        foreach (self::DOCUMENT_TABLES as $model) {
            RowVisibility::rule($model::$table, static fn (object $user): ?string => self::documentsRule($user));
        }

        RowVisibility::rule(Payment::$table, static fn (object $user): ?string => self::paymentsRule($user));
    }

    public static function contactsRule(object $user): ?string
    {
        if (!self::restricted($user)) {
            return null;
        }

        $id = self::idOf($user);
        if ($id <= 0) {
            return RowVisibility::none();
        }

        return '`agent_id` = '.$id
            .' OR `id` IN (SELECT `person_id` FROM `'.ContactRelation::$table.'` WHERE `company_id` IN ('.self::companiesOf($id).'))';
    }

    public static function companiesRule(object $user): ?string
    {
        if (!self::restricted($user)) {
            return null;
        }

        $id = self::idOf($user);

        return $id > 0 ? '`agent_id` = '.$id : RowVisibility::none();
    }

    /**
     * I brand legati con un legame aperto a un'azienda sua: elenco, scheda,
     * modifica e API del brand. Un brand senza aziende sue non lo vede.
     */
    public static function brandsRule(object $user): ?string
    {
        if (!self::restricted($user)) {
            return null;
        }

        $id = self::idOf($user);

        return $id > 0
            ? '`id` IN (SELECT `brand_id` FROM `'.CompanyBrandLink::$table.'` WHERE `ended_at` IS NULL AND `company_id` IN ('.self::companiesOf($id).'))'
            : RowVisibility::none();
    }

    public static function documentsRule(object $user): ?string
    {
        if (!self::restricted($user)) {
            return null;
        }

        $id = self::idOf($user);
        if ($id <= 0) {
            return RowVisibility::none();
        }

        return '`agent_id` = '.$id
            .' OR `customer_id` IN (SELECT `id` FROM `'.Contact::$table.'` WHERE `agent_id` = '.$id.')'
            .' OR `company_id` IN ('.self::companiesOf($id).')';
    }

    /** Rate e incassi: quelli dei suoi ordini, abbonamenti e clienti (Scadenzario). */
    public static function paymentsRule(object $user): ?string
    {
        $documents = self::documentsRule($user);
        if ($documents === null || $documents === RowVisibility::none()) {
            return $documents;
        }

        $id = self::idOf($user);

        return '`order_id` IN (SELECT `id` FROM `'.Order::$table.'` WHERE '.$documents.')'
            .' OR `subscription_id` IN (SELECT `id` FROM `'.Subscription::$table.'` WHERE '.$documents.')'
            .' OR `customer_id` IN (SELECT `id` FROM `'.Contact::$table.'` WHERE `agent_id` = '.$id.')'
            .' OR `company_id` IN ('.self::companiesOf($id).')';
    }

    private static function companiesOf(int $id): string
    {
        return 'SELECT `id` FROM `'.Company::$table.'` WHERE `agent_id` = '.$id;
    }

    /**
     * Il commerciale di un documento nuovo: quello dell'azienda se c'è,
     * altrimenti della persona; se il cliente non ne ha, chi lo crea quando è
     * un commerciale. 0 con la funzionalità spenta.
     */
    public static function forDocument(array $document, ?object $creator = null): int
    {
        if (!self::enabled()) {
            return 0;
        }

        $companyId = (int) ($document['company_id'] ?? 0);
        $id = $companyId > 0 ? self::forCompany($companyId) : self::forContact((int) ($document['customer_id'] ?? 0));

        if ($id <= 0 && self::restricted($creator)) {
            $id = self::idOf($creator);
        }

        return $id;
    }

    public static function forCompany(int $companyId): int
    {
        return self::assigned(Company::class, $companyId);
    }

    public static function forContact(int $contactId): int
    {
        return self::assigned(Contact::class, $contactId);
    }

    /** @param class-string<Contact|Company> $model */
    private static function assigned(string $model, int $id): int
    {
        if ($id <= 0 || !self::enabled()) {
            return 0;
        }

        try {
            $row = $model::findById($id);
        } catch (Throwable) {
            return 0;
        }

        return is_array($row) ? max(0, (int) ($row['agent_id'] ?? 0)) : 0;
    }

    /**
     * I commerciali per i select, col nome dell'utente.
     *
     * @return array<int, string>
     */
    public static function options(bool $activeOnly = true, int $keep = 0): array
    {
        try {
            $rows = SalesAgent::find(['deleted' => 'false']) ?: [];
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach (self::listOf($rows) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || ($activeOnly && ($row['active'] ?? 'true') !== 'true' && $id !== $keep)) {
                continue;
            }
            $out[$id] = self::label($row);
        }

        asort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /** Il filtro «Commerciale» degli elenchi, solo per l'ufficio. */
    public static function filter(TableLayoutSchema $layout): TableLayoutSchema
    {
        if (!self::enabled() || self::restricted(RowVisibility::currentUser())) {
            return $layout;
        }

        return $layout->filterCustom('Commerciale', 'agent_id', ['' => 'Tutti', '0' => 'Nessuno'] + self::options(false));
    }

    /** Il nome del commerciale, '' se non c'è. */
    public static function name(int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        try {
            $row = SalesAgent::findById($id);
        } catch (Throwable) {
            return '';
        }

        return is_array($row) ? self::label($row) : '';
    }

    /** @param array<string, mixed> $agent */
    public static function label(array $agent): string
    {
        $userId = (int) ($agent['user_id'] ?? 0);

        try {
            $user = $userId > 0 ? User::findById($userId) : null;
        } catch (Throwable) {
            $user = null;
        }

        $name = is_array($user) ? trim(($user['name'] ?? '').' '.($user['surname'] ?? '')) : '';
        if ($name === '' && is_array($user)) {
            $name = trim((string) ($user['email'] ?? ''));
        }

        return $name !== '' ? $name : 'Commerciale #'.(int) ($agent['id'] ?? 0);
    }

    /**
     * Gli utenti del backend con ruolo `sales_agent`, per il form del commerciale.
     *
     * @return array<int, string>
     */
    public static function userOptions(): array
    {
        try {
            $rows = User::find("JSON_CONTAINS(`authority`, '\"".self::ROLE."\"')") ?: [];
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach (self::listOf($rows) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $name = trim(($row['name'] ?? '').' '.($row['surname'] ?? ''));
                $out[$id] = $name !== '' ? $name.' ('.($row['email'] ?? '').')' : (string) ($row['email'] ?? '#'.$id);
            }
        }

        asort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /**
     * `Model::find()` dà una riga sola come array associativo.
     *
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
