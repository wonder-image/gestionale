<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Throwable;
use Wonder\App\Visibility\RowVisibility;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Resources\Contacts\CompanyResource;
use Wonder\Sql\Query;

/**
 * Cosa vede e cosa può legare chi ha una visibilità per riga (il
 * commerciale vede solo i suoi clienti, le sue aziende e i loro brand: le
 * regole sono in `SalesAgents::register()`).
 *
 * Tutto passa da `RowVisibility`: chi non ha una regola sulla tabella vede
 * tutto e qui non si fa nessuna query. Chi ce l'ha, una query per chiamata.
 * I ganci di customer-account (`useCounterpartFilter()`, `useAccessGuard()`)
 * si registrano al boot del modulo con `register()`.
 */
final class ContactVisibility
{
    /** I brand creati dalla «+» di chi ha una visibilità per riga, in sessione: li può legare finché non sono di un'azienda. */
    public const SESSION_BRANDS = 'gst_quick_brands';

    /** @var null|callable(string, string): list<int> solo per i test: tabella, condizione → id trovati */
    private static $reader = null;

    /** Solo per i test: sostituisce la lettura degli id (`null` torna al database). */
    public static function useReader(?callable $reader): void
    {
        self::$reader = $reader;
    }

    /** @return list<int> */
    private static function read(string $table, string $sql): array
    {
        $rows = (new Query())->Select($table, $sql, null, null, null, '`id`')->row;
        $rows = is_array($rows) ? (isset($rows['id']) ? [$rows] : $rows) : [];

        return array_map(static fn ($row): int => is_array($row) ? (int) ($row['id'] ?? 0) : 0, $rows);
    }

    /** Registra i ganci di customer-account (dal file di boot del modulo). */
    public static function register(): void
    {
        ContactRelations::useCounterpartFilter(static fn (string $modelClass, array $ids): array => self::visibleIds($modelClass::$table, $ids));
        ContactRelations::useAccessGuard(static fn (int $companyId, int $personId, string $access): bool => self::canGrantAccess());
    }

    /** Vero se l'utente di adesso vede solo alcune righe della tabella. */
    public static function restricted(string $table, ?object $user = null): bool
    {
        return RowVisibility::conditionFor($table, $user ?? RowVisibility::currentUser()) !== null;
    }

    /**
     * Gli id visibili all'utente di adesso fra quelli dati, nell'ordine dato.
     * Senza regola tutti, senza query; con la regola una query. Un errore di
     * lettura non ne lascia passare nessuno.
     *
     * @param list<int|string> $ids
     * @return list<int>
     */
    public static function visibleIds(string $table, array $ids, ?object $user = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($ids, 'is_scalar')), static fn (int $id): bool => $id > 0)));
        $user ??= RowVisibility::currentUser();

        if ($ids === [] || RowVisibility::conditionFor($table, $user) === null) {
            return $ids;
        }

        $sql = RowVisibility::applySql($table, '`id` IN ('.implode(', ', $ids).')', $user);

        try {
            $found = self::$reader !== null ? (self::$reader)($table, $sql) : self::read($table, $sql);
        } catch (Throwable) {
            return [];
        }

        $seen = array_flip(array_map('intval', $found));

        return array_values(array_filter($ids, static fn (int $id): bool => isset($seen[$id])));
    }

    /**
     * Le scelte di un select (id => testo) ristrette a quelle visibili, più
     * quelle in `$keep` (già scelte sulla scheda: chi non le vede non le
     * perde salvando).
     *
     * @param array<int|string, string> $options
     * @param list<int> $keep
     * @return array<int|string, string>
     */
    public static function options(string $table, array $options, array $keep = []): array
    {
        if (!self::restricted($table)) {
            return $options;
        }

        $allowed = array_flip([...self::visibleIds($table, array_keys($options)), ...array_map('intval', $keep)]);

        return array_filter($options, static fn ($id): bool => (string) $id === '' || isset($allowed[(int) $id]), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Chi alza l'accesso di un referente all'area clienti (`buyer`, `admin`):
     * chi vede tutte le aziende e può modificarle. Il commerciale no: con
     * `admin` una persona vede ordini e abbonamenti di tutta l'azienda sul
     * sito. Senza utente (comandi, import) sì. Abbassarlo si può sempre (lo
     * decide customer-account).
     */
    public static function canGrantAccess(?object $user = null): bool
    {
        $user ??= RowVisibility::currentUser();

        if ($user === null) {
            return true;
        }

        return !self::restricted(Company::$table, $user)
            && CompanyResource::allows(RowVisibility::authorities($user), 'edit');
    }

    /** Ricorda un brand nato dalla «+» di chi ha una visibilità per riga. */
    public static function rememberBrand(int $brandId): void
    {
        if ($brandId > 0 && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_BRANDS] = array_values(array_unique([...self::rememberedBrands(), $brandId]));
        }
    }

    /** @return list<int> */
    public static function rememberedBrands(): array
    {
        return session_status() === PHP_SESSION_ACTIVE
            ? array_values(array_filter(array_map('intval', (array) ($_SESSION[self::SESSION_BRANDS] ?? []))))
            : [];
    }

    /**
     * I brand che l'utente di adesso può legare a un'azienda: quelli che vede
     * (legati a un'azienda sua) e quelli appena creati da lui.
     *
     * @param list<int|string> $ids
     * @return list<int>
     */
    public static function linkableBrands(array $ids): array
    {
        if (!self::restricted(CompanyBrand::$table)) {
            return array_values(array_filter(array_map('intval', array_filter($ids, 'is_scalar'))));
        }

        $ids = array_map('intval', array_filter($ids, 'is_scalar'));
        $allowed = array_flip([...self::visibleIds(CompanyBrand::$table, $ids), ...self::rememberedBrands()]);

        return array_values(array_filter($ids, static fn (int $id): bool => isset($allowed[$id])));
    }
}
