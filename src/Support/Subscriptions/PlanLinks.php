<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanLink;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * I legami «Si lega a» fra i piani (G10b §2): letti, controllati e scritti.
 * Le regole stanno in {@see assert()}, che non tocca il database.
 */
final class PlanLinks
{
    /**
     * Gli id delle madri scelte nel form: interi positivi, senza doppioni,
     * nell'ordine.
     *
     * @param array<mixed> $values
     * @return list<int>
     */
    public static function normalize(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            $id = is_numeric($value) ? (int) $value : 0;

            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** Le madri del piano. @return list<int> */
    public static function parentsOf(int $planId): array
    {
        return self::existing()[$planId] ?? [];
    }

    /** Tutti i legami: figlio → madri. @return array<int, list<int>> */
    public static function existing(): array
    {
        try {
            $rows = PlanLink::find([]);
        } catch (Throwable) {
            return [];
        }

        $rows = is_array($rows) ? (isset($rows['id']) ? [$rows] : array_filter($rows, 'is_array')) : [];
        $links = [];

        foreach ($rows as $row) {
            $links[(int) $row['plan_id']][] = (int) $row['parent_plan_id'];
        }

        return $links;
    }

    /**
     * Un piano non si lega a sé; si lega solo a piani senza madri e, se è
     * madre di altri, non riceve madri. Le madri già salvate del piano stesso
     * non contano: il salvataggio le riscrive.
     *
     * @param list<int> $parents
     * @param array<int, list<int>> $existing figlio → madri
     * @throws UserError `plan.link_self`, `plan.link_depth`
     */
    public static function assert(int $planId, array $parents, array $existing): void
    {
        if ($planId > 0 && in_array($planId, $parents, true)) {
            throw UserError::make('plan.link_self');
        }

        if ($parents === []) {
            return;
        }

        unset($existing[$planId]);

        foreach ($parents as $parent) {
            if (($existing[$parent] ?? []) !== []) {
                throw UserError::make('plan.link_depth');
            }
        }

        foreach ($existing as $mothers) {
            if ($planId > 0 && in_array($planId, $mothers, true)) {
                throw UserError::make('plan.link_depth');
            }
        }
    }

    /**
     * Scrive le madri del piano: cancella i legami tolti, aggiunge i nuovi.
     *
     * @param list<int> $parents
     */
    public static function sync(int $planId, array $parents): void
    {
        if ($planId <= 0) {
            return;
        }

        $rows = PlanLink::find(['plan_id' => $planId]);
        $rows = is_array($rows) ? (isset($rows['id']) ? [$rows] : array_filter($rows, 'is_array')) : [];
        $kept = [];

        foreach ($rows as $row) {
            $parent = (int) $row['parent_plan_id'];

            if (in_array($parent, $parents, true) && !in_array($parent, $kept, true)) {
                $kept[] = $parent;
                continue;
            }

            PlanLink::delete((int) $row['id']);
        }

        foreach (array_diff($parents, $kept) as $parent) {
            sqlInsert(PlanLink::$table, ['plan_id' => $planId, 'parent_plan_id' => $parent]);
        }
    }

    /** Toglie i legami del piano come figlio (piano eliminato). */
    public static function forget(int $planId): void
    {
        self::sync($planId, []);
    }
}
