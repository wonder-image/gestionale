<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\RequestMemo;
use Wonder\Sql\Transaction;

/**
 * L'ordine di marchi, categorie, tag e attributi: quello in cui il sito li
 * elenca (menu, filtri, schede) e il pannello li propone.
 *
 * La colonna `position` la mette il backend in fondo alla creazione
 * ({@see \Wonder\Plugin\Gestionale\Support\Positions}); qui la si riscrive
 * tutta insieme, da 1 in su, nell'ordine in cui le righe sono state
 * trascinate nella pagina «Ordina». Le categorie si ordinano per livello:
 * le sorelle sotto lo stesso padre.
 *
 * {@see positions()} è pura; {@see rows()} e {@see save()} leggono e scrivono.
 */
final class CatalogOrder
{
    /**
     * Gli elenchi, per la chiave dell'indirizzo (`?elenco=`): il Model e il
     * nome, al plurale e al singolare.
     *
     * @var array<string, array{model: class-string<\Wonder\App\Model>, label: string, one: string}>
     */
    public const LISTS = [
        'marchi' => ['model' => Brand::class, 'label' => 'Marchi', 'one' => 'marchio'],
        'categorie' => ['model' => Category::class, 'label' => 'Categorie', 'one' => 'categoria'],
        'tag' => ['model' => Tag::class, 'label' => 'Tag', 'one' => 'tag'],
        'attributi' => ['model' => Attribute::class, 'label' => 'Attributi', 'one' => 'attributo'],
    ];

    public static function known(string $list): bool
    {
        return isset(self::LISTS[$list]);
    }

    /**
     * Le righe da ordinare, nell'ordine di oggi: posizione, poi nome. Per le
     * categorie solo le figlie di `$parentId` (0 = quelle in cima).
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(string $list, int $parentId = 0): array
    {
        if (!self::known($list)) {
            return [];
        }

        $model = self::LISTS[$list]['model'];
        $where = "`deleted` = 'false'";

        if ($list === 'categorie') {
            $where .= $parentId > 0 ? ' AND `parent_id` = '.$parentId : ' AND (`parent_id` IS NULL OR `parent_id` = 0)';
        }

        return self::list($model::find($where, null, 'COALESCE(`position`, 2147483647), name', 'ASC'));
    }

    /**
     * Le posizioni nuove, `id => posizione` da 1, per gli id nell'ordine
     * dato. Null se gli id non sono esattamente quelli dell'elenco: qualcuno
     * ha aggiunto o tolto una riga mentre la pagina era aperta, e salvare
     * vorrebbe dire mettere in fila un elenco che non c'è più.
     *
     * @param list<int> $current gli id dell'elenco adesso
     * @param array<int|string, mixed> $posted gli id nell'ordine della pagina
     * @return array<int, int>|null
     */
    public static function positions(array $current, array $posted): ?array
    {
        $ids = [];

        foreach ($posted as $id) {
            $id = is_scalar($id) ? (int) $id : 0;

            if ($id <= 0 || isset($ids[$id])) {
                return null;
            }

            $ids[$id] = count($ids) + 1;
        }

        $current = array_map('intval', $current);
        sort($current);
        $given = array_keys($ids);
        sort($given);

        return $current === $given ? $ids : null;
    }

    /**
     * Riscrive le posizioni di un elenco nell'ordine dato.
     *
     * @param array<int|string, mixed> $posted gli id nell'ordine della pagina
     * @return int quante righe sono state ordinate
     * @throws UserError `catalog_order.unknown_list`, `catalog_order.changed`
     */
    public static function save(string $list, int $parentId, array $posted): int
    {
        if (!self::known($list)) {
            throw UserError::make('catalog_order.unknown_list');
        }

        $positions = self::positions(
            array_map(static fn (array $row): int => (int) $row['id'], self::rows($list, $parentId)),
            $posted
        );

        if ($positions === null) {
            throw UserError::make('catalog_order.changed');
        }

        $model = self::LISTS[$list]['model'];

        Transaction::run(static function () use ($model, $positions): void {
            foreach ($positions as $id => $position) {
                $model::update(['position' => $position], $id);
            }
        });

        // I menu del sito e gli alberi del pannello tengono una copia per richiesta.
        if (defined($model.'::MEMO_GROUP')) {
            RequestMemo::forget($model::MEMO_GROUP);
        }

        return count($positions);
    }

    /**
     * Il percorso di una categoria, dalla cima a lei: per il titolo della
     * pagina e il link «su di un livello».
     *
     * @return list<array<string, mixed>>
     */
    public static function categoryPath(int $categoryId): array
    {
        $path = [];
        $seen = [];

        while ($categoryId > 0 && !isset($seen[$categoryId])) {
            $seen[$categoryId] = true;
            $row = Category::find(['id' => $categoryId, 'deleted' => 'false'], 1);

            if (!is_array($row) || !isset($row['id'])) {
                break;
            }

            array_unshift($path, $row);
            $categoryId = (int) ($row['parent_id'] ?? 0);
        }

        return $path;
    }

    /**
     * Quante figlie ha ogni categoria dell'elenco, per il link «Ordina le
     * sottocategorie».
     *
     * @param list<int> $ids
     * @return array<int, int>
     */
    public static function childCounts(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return [];
        }

        $counts = [];

        foreach (self::list(Category::find('`parent_id` IN ('.implode(', ', $ids).") AND `deleted` = 'false'")) as $row) {
            $parent = (int) ($row['parent_id'] ?? 0);
            $counts[$parent] = ($counts[$parent] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return list<array<string, mixed>> */
    private static function list(mixed $result): array
    {
        if (!is_array($result) || $result === []) {
            return [];
        }

        return isset($result['id']) ? [$result] : array_values(array_filter($result, 'is_array'));
    }
}
