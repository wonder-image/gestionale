<?php
/** php tests/LocationResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Resources\Config\SocietyLocationResource;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Resources\Locations\LocationResource;

// Qui sta solo ciò che si può controllare senza far partire il framework:
// gli schemi della sede chiamano le funzioni globali del core, quindi il
// resto è in tests/integrazione/LocationTest.php.

check('la pagina prende il posto di quella del core', fn () =>
    is_subclass_of(LocationResource::class, SocietyLocationResource::class)
    && LocationResource::path() === SocietyLocationResource::path()
    && LocationResource::slug() === SocietyLocationResource::slug()
);

check('in produzione restano modificabili solo orari e chiusure', fn () =>
    LocationResource::editableWhenReadonly() === ['hours', 'special_hours']
);

check('la sede del gestionale ha la sua tabella, sincronizzata con id stabili', function () {
    $schema = Location::syncSchema();

    return Location::$table === 'gst_locations' && $schema !== null && $schema->keepIds && $schema->localOnly;
});

check('la sede del gestionale punta a quella del core', function () {
    foreach (Location::tableSchema() as $column) {
        if ((string) $column->name === 'society_location_id') {
            return ($column->schema['foreign_table'] ?? '') === 'society_locations'
                && ($column->schema['unique'] ?? false) === true;
        }
    }

    return false;
});

summary();
