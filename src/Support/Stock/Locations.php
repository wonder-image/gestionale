<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\App\Support\SocietyLocations;
use Wonder\Plugin\Gestionale\Models\Locations\Location;

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
 */
final class Locations
{
    private static ?int $mainId = null;

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
