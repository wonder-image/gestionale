<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

/**
 * L'albero delle categorie: ordine, profondità, percorso e cicli.
 *
 * Classe pura, riceve le righe già lette: così l'albero si prova senza
 * database e il Model resta una tabella, senza ricorsione dentro.
 *
 * Una riga il cui padre non esiste più viene trattata come radice: sparire
 * dall'elenco sarebbe il modo peggiore di raccontare il problema.
 */
final class CategoryTree
{
    /** Separatore del percorso leggibile. */
    private const SEPARATOR = ' › ';

    /**
     * Righe in ordine di albero, con `depth` e `path` in più.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function sorted(array $rows): array
    {
        $byParent = [];
        $ids = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }

            $ids[(int) $row['id']] = true;
        }

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }

            $parent = (int) ($row['parent_id'] ?? 0);
            // Padre sconosciuto: la riga è una radice, non un fantasma.
            $byParent[isset($ids[$parent]) ? $parent : 0][] = $row;
        }

        foreach ($byParent as $parent => $children) {
            usort($byParent[$parent], static fn (array $a, array $b): int =>
                [(int) ($a['position'] ?? 0), (string) ($a['name'] ?? '')]
                <=> [(int) ($b['position'] ?? 0), (string) ($b['name'] ?? '')]);
        }

        return self::branch($byParent, 0, 0, '');
    }

    /**
     * Id dei discendenti, a qualunque profondità.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    public static function descendants(array $rows, int $id): array
    {
        $found = [];

        foreach (self::sorted($rows) as $row) {
            $parent = (int) ($row['parent_id'] ?? 0);

            if ($parent === $id || in_array($parent, $found, true)) {
                $found[] = (int) $row['id'];
            }
        }

        return $found;
    }

    /**
     * Voci per il select del padre, indentate. `$exclude` toglie una categoria
     * e i suoi discendenti: sono gli unici posti dove non può andare.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, string>
     */
    public static function options(array $rows, ?int $exclude = null): array
    {
        $forbidden = $exclude === null ? [] : array_merge([$exclude], self::descendants($rows, $exclude));
        $options = ['' => 'Nessuna, sta in cima'];

        foreach (self::sorted($rows) as $row) {
            $id = (int) $row['id'];

            if (in_array($id, $forbidden, true)) {
                continue;
            }

            $options[(string) $id] = str_repeat('— ', (int) $row['depth']).(string) ($row['name'] ?? '');
        }

        return $options;
    }

    /**
     * Le stesse voci di `options()`, ma annidate per il `checkTree()` del
     * core: `['id' => ['name' => '…', 'child' => [...]]]`.
     *
     * La prima voce è `0`, "sta in cima": l'albero ha bisogno di qualcosa da
     * spuntare anche quando la categoria non ha padre.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    public static function treeOptions(array $rows, ?int $exclude = null): array
    {
        $forbidden = $exclude === null ? [] : array_merge([$exclude], self::descendants($rows, $exclude));
        $allowed = [];
        $ids = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id']) || in_array((int) $row['id'], $forbidden, true)) {
                continue;
            }

            $allowed[] = $row;
            $ids[(int) $row['id']] = true;
        }

        $children = [];

        // `sorted()` mette le righe in ordine di albero e tratta come radice
        // chi ha perso il padre: qui si raggruppano con la stessa regola.
        foreach (self::sorted($allowed) as $row) {
            $parent = (int) ($row['parent_id'] ?? 0);
            $children[isset($ids[$parent]) ? $parent : 0][] = $row;
        }

        return ['0' => [
            'name' => 'Nessuna, sta in cima',
            'child' => self::optionsBranch($children, 0),
        ]];
    }

    /** Vero se mettere `$id` sotto `$parentId` chiuderebbe un anello. */
    public static function wouldLoop(array $rows, int $id, int $parentId): bool
    {
        if ($parentId === 0) {
            return false;
        }

        return $id === $parentId || in_array($parentId, self::descendants($rows, $id), true);
    }

    /**
     * Un livello dell'albero delle opzioni, con i suoi figli dentro.
     *
     * @param array<int, list<array<string, mixed>>> $children
     * @return array<string, array<string, mixed>>
     */
    private static function optionsBranch(array $children, int $parent): array
    {
        $options = [];

        foreach ($children[$parent] ?? [] as $row) {
            $id = (int) $row['id'];
            $options[(string) $id] = [
                'name' => (string) ($row['name'] ?? ''),
                'child' => self::optionsBranch($children, $id),
            ];
        }

        return $options;
    }

    /**
     * @param array<int, list<array<string, mixed>>> $byParent
     * @return list<array<string, mixed>>
     */
    private static function branch(array $byParent, int $parent, int $depth, string $path): array
    {
        $branch = [];

        foreach ($byParent[$parent] ?? [] as $row) {
            $name = (string) ($row['name'] ?? '');
            $row['depth'] = $depth;
            $row['path'] = $path === '' ? $name : $path.self::SEPARATOR.$name;

            $branch[] = $row;
            $branch = array_merge($branch, self::branch($byParent, (int) $row['id'], $depth + 1, $row['path']));
        }

        return $branch;
    }
}
