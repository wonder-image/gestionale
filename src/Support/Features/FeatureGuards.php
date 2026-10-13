<?php

namespace Wonder\Plugin\Gestionale\Support\Features;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;

/**
 * Le funzionalità che non si spengono con dei dati in giro (G3.18).
 *
 * Oggi una sola: «Più sedi» non si spegne con pezzi in una sede che non è la
 * principale, o con trasferimenti in viaggio. Spenta, quella merce non si
 * vedrebbe più da nessuna parte e la vetrina venderebbe solo la principale.
 * Vale anche quando si spegnerebbe a catena, per una dipendenza tolta.
 * «Acquisti» e «Lotti e scadenze» si spengono sempre: i dati restano,
 * nascosti, e tornano alla riaccensione.
 */
final class FeatureGuards
{
    /**
     * Il rifiuto, se lo stato nuovo spegne una funzionalità che non si può
     * spegnere adesso.
     *
     * @param array<string, bool> $current lo stato di prima
     * @param array<string, bool> $next lo stato dopo le regole delle dipendenze
     */
    public static function assertAllowed(array $current, array $next): void
    {
        if (($current['multi_location'] ?? false) !== true || ($next['multi_location'] ?? false) !== false) {
            return;
        }

        $where = self::where(self::locationsWithStock(), self::transfersInTransit());

        if ($where !== '') {
            throw UserError::make('feature.multi_location_in_use', ['where' => $where]);
        }
    }

    /**
     * «ci sono pezzi in Negozio Centro e Deposito», «c'è 1 trasferimento in
     * viaggio», le due cose unite; vuota se non c'è niente. Pura.
     *
     * @param list<string> $locations le sedi diverse dalla principale con pezzi
     */
    public static function where(array $locations, int $transfers): string
    {
        $parts = [];
        $count = count($locations);

        if ($count > 0) {
            $shown = array_slice($locations, 0, 3);
            $names = $count <= 3
                ? ($count === 1 ? $shown[0] : implode(', ', array_slice($shown, 0, -1)).' e '.$shown[array_key_last($shown)])
                : implode(', ', $shown).' e '.($count - 3 === 1 ? 'un\'altra sede' : 'altre '.($count - 3).' sedi');
            $parts[] = 'ci sono pezzi in '.$names;
        }

        if ($transfers > 0) {
            $parts[] = $transfers === 1 ? 'c\'è 1 trasferimento in viaggio' : 'ci sono '.$transfers.' trasferimenti in viaggio';
        }

        return implode(' e ', $parts);
    }

    /** @return list<string> i nomi delle sedi, non la principale, con pezzi diversi da zero */
    private static function locationsWithStock(): array
    {
        $main = Locations::mainId();

        try {
            $rows = StockRow::find("`location_id` <> ".$main." AND `quantity` <> 0 AND `deleted` = 'false'", null, null, null, 'DISTINCT location_id');
        } catch (Throwable) {
            return [];
        }

        $rows = !is_array($rows) || $rows === [] ? [] : (isset($rows['location_id']) ? [$rows] : array_values(array_filter($rows, 'is_array')));
        $ids = array_flip(array_map(static fn (array $row): int => (int) $row['location_id'], $rows));
        $names = [];

        foreach (Locations::shown() as $location) {
            if (isset($ids[$location['id']])) {
                $names[] = $location['label'];
                unset($ids[$location['id']]);
            }
        }

        foreach (array_keys($ids) as $id) {
            $names[] = 'Sede #'.$id;
        }

        return $names;
    }

    private static function transfersInTransit(): int
    {
        try {
            return (int) sqlCount(StockDocument::$table, "`type` = 'transfer' AND `status` = 'in_transit' AND `deleted` = 'false'");
        } catch (Throwable) {
            return 0;
        }
    }
}
