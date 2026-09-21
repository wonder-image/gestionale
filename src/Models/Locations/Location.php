<?php

namespace Wonder\Plugin\Gestionale\Models\Locations;

use Wonder\App\Model;
use Wonder\App\Models\Config\SocietyLocation;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * La sede vista dal gestionale: giacenza, ritiro e banco.
 *
 * Nome, indirizzo, contatti, dati legali, orari e chiusure restano nella sede
 * del core (`society_locations`): qui c'è solo ciò che serve al magazzino, con
 * una riga per sede. Niente copie di dati tra le due tabelle.
 */
final class Location extends Model
{
    public static string $table = 'gst_locations';
    public static string $folder = 'gestionale/locations';
    public static string $icon = 'bi bi-geo-alt';

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::multiRow()->keepIds()->localOnly();
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code']),
            Column::key('society_location_id')->int()->null(false)->unique()->foreign(SocietyLocation::$table),
            Column::key('has_stock')->enum(['true', 'false'])->default('true'),
            Column::key('is_pickup_point')->enum(['true', 'false'])->default('false'),
            Column::key('is_pos')->enum(['true', 'false'])->default('false'),
            Column::key('active')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::LOCATION),
            Field::key('society_location_id')->number()->decimals(0),
            Field::key('has_stock')->text()->sanitize(false),
            Field::key('is_pickup_point')->text()->sanitize(false),
            Field::key('is_pos')->text()->sanitize(false),
            Field::key('active')->text()->sanitize(false),
        ];
    }

    /**
     * Codice nuovo per una sede.
     *
     * `Model::prepare()` formatta i valori ma non genera i codici unici: li
     * fa il flusso dei form. Chi inserisce una riga da codice (le righe
     * precaricate, il salvataggio della scheda) chiede il codice qui.
     */
    public static function newCode(): string
    {
        return create_unique_code(static::$table, Codes::LOCATION, 7, 'code');
    }

    public static function create(array $values): object
    {
        if (trim((string) ($values['code'] ?? '')) === '') {
            $values['code'] = static::newCode();
        }

        return parent::create($values);
    }

    /** La riga del gestionale legata a una sede del core, `[]` se non c'è. */
    public static function forSocietyLocation(int $societyLocationId): array
    {
        $row = static::find([
            'society_location_id' => $societyLocationId,
            'deleted' => 'false',
        ], 1);

        return is_array($row) ? $row : [];
    }
}
