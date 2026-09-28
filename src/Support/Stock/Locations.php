<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\App\Models\Config\SocietyLocation;
use Wonder\App\Support\SocietyLocations;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;

/**
 * La sede del magazzino, quando nessuno l'ha scelta.
 *
 * Finché la funzionalità "Più sedi" è bloccata la sede è una sola e non
 * compare in nessun campo (D20): chi scrive un movimento non la passa, e la
 * chiede qui. La sede principale è quella predefinita del core, con la sua
 * riga in `gst_locations`.
 *
 * Il valore si tiene per tutta la richiesta: è una lettura che tornerebbe
 * altrimenti a ogni riga di un salvataggio in blocco.
 *
 * `shown()` dice invece quali sedi hanno una colonna negli elenchi del
 * magazzino: la regola sta qui sola, così Giacenze, Movimenti e Documenti
 * non la scrivono ognuno a modo suo.
 */
final class Locations
{
    private static ?int $mainId = null;

    /** @var list<array{id: int, label: string}>|null */
    private static ?array $shown = null;

    /** L'id della riga di `gst_locations` della sede principale; `0` se manca. */
    public static function mainId(): int
    {
        if (self::$mainId !== null) {
            return self::$mainId;
        }

        return self::$mainId = self::resolve();
    }

    public static function reset(): void
    {
        self::$mainId = null;
        self::$shown = null;
    }

    /**
     * Le sedi da mostrare, una colonna ciascuna, nell'ordine della pagina Sedi.
     *
     * Con "Più sedi" bloccata c'è la sola sede principale, e chi legge non
     * vede colonne di sede (D20). Con la funzionalità attiva ci sono le sedi
     * che tengono merce, più ogni altra che ha ancora pezzi: così le colonne
     * sommano sempre al totale. Le colonne servono solo da due sedi in su.
     *
     * @return list<array{id: int, label: string}>
     */
    public static function shown(): array
    {
        if (self::$shown !== null) {
            return self::$shown;
        }

        if (!Gestionale::feature('multi_location')) {
            $main = self::mainId();

            return self::$shown = $main > 0 ? [['id' => $main, 'label' => '']] : [];
        }

        // Anche le righe cancellate: una sede tolta che ha ancora pezzi resta
        // in colonna, con il suo nome.
        $all = "deleted IN ('true', 'false')";

        return self::$shown = self::pick(
            self::rows(Location::class, $all),
            self::rows(SocietyLocation::class, $all),
            array_map(
                static fn (array $row): int => (int) ($row['location_id'] ?? 0),
                self::rows(StockRow::class, "deleted = 'false' AND quantity <> 0", 'DISTINCT location_id')
            )
        );
    }

    /**
     * La regola di `shown()`, senza database.
     *
     * Una sede entra se tiene merce ed è viva (lei e la sua sede del core), o
     * se ha pezzi: in quel caso anche cancellata o senza riga, con il nome
     * "Sede #id" quando non ne ha uno.
     *
     * @param list<array<string, mixed>> $locations righe di `gst_locations`
     * @param list<array<string, mixed>> $societyRows righe di `society_locations`
     * @param list<int> $withStock le sedi con almeno una riga di giacenza diversa da zero
     * @return list<array{id: int, label: string}>
     */
    public static function pick(array $locations, array $societyRows, array $withStock): array
    {
        $society = [];

        foreach ($societyRows as $row) {
            $society[(int) ($row['id'] ?? 0)] = $row;
        }

        $withStock = array_flip(array_filter(array_map('intval', $withStock)));
        $picked = [];

        foreach ($locations as $row) {
            $id = (int) ($row['id'] ?? 0);
            $place = $society[(int) ($row['society_location_id'] ?? 0)] ?? null;
            $alive = ($row['has_stock'] ?? '') === 'true'
                && ($row['deleted'] ?? 'false') !== 'true'
                && $place !== null
                && ($place['deleted'] ?? 'false') !== 'true';

            if ($id > 0 && ($alive || isset($withStock[$id]))) {
                $picked[$id] = [
                    'id' => $id,
                    'label' => self::label($place, $id),
                    'position' => $place !== null ? (int) ($place['position'] ?? 0) : PHP_INT_MAX,
                ];
            }
        }

        // Pezzi in una sede che non ha più la sua riga: si vedono lo stesso.
        foreach (array_keys($withStock) as $id) {
            $picked[$id] ??= ['id' => $id, 'label' => self::label(null, $id), 'position' => PHP_INT_MAX];
        }

        usort($picked, static fn (array $a, array $b): int => [$a['position'], $a['id']] <=> [$b['position'], $b['id']]);

        return array_map(static fn (array $row): array => ['id' => $row['id'], 'label' => $row['label']], $picked);
    }

    /** @param array<string, mixed>|null $place */
    private static function label(?array $place, int $id): string
    {
        foreach (['label', 'name'] as $key) {
            $text = trim((string) ($place[$key] ?? ''));

            if ($text !== '') {
                return $text;
            }
        }

        return 'Sede #'.$id;
    }

    /**
     * @param class-string<\Wonder\App\Model> $modelClass
     * @return list<array<string, mixed>>
     */
    private static function rows(string $modelClass, string $condition, string $columns = '*'): array
    {
        try {
            $rows = $modelClass::find($condition, null, null, null, $columns);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return array_is_list($rows) ? array_values(array_filter($rows, 'is_array')) : [$rows];
    }

    private static function resolve(): int
    {
        try {
            $society = SocietyLocations::default();
            $row = Location::forSocietyLocation((int) ($society->id ?? 0));

            if (($id = (int) ($row['id'] ?? 0)) > 0) {
                return $id;
            }
        } catch (Throwable) {
            // Sito non avviato (comandi, test degli schemi): resta il ripiego.
        }

        // Ripiego: la prima sede che tiene merce. Un sito appena installato ne
        // ha una sola, creata dalle righe precaricate del modulo.
        try {
            $row = Location::find(['has_stock' => 'true', 'deleted' => 'false'], 1, 'id', 'ASC');
        } catch (Throwable) {
            return 0;
        }

        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }
}
