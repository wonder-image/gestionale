<?php
/** php tests/integrazione/LocationTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\App\Models\Config\SocietyLocation;
use Wonder\App\ResourceRegistry;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Resources\Locations\LocationResource;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$campi = static fn (): array => array_map(
    static fn (object $field): string => (string) $field->name,
    LocationResource::formSchema()
);

// Lo stato delle funzionalità si forza senza toccare il database.
$forza = static function (?array $stato): void {
    $proprieta = new ReflectionProperty(Gestionale::class, 'features');
    $proprieta->setValue(null, $stato);
};

check('il registro sceglie la pagina del gestionale', fn () =>
    ResourceRegistry::resolve(LocationResource::slug()) === LocationResource::class
);

check('la scheda tiene i campi della sede e aggiunge il magazzino', function () use ($campi, $forza) {
    $forza(['shipping' => false, 'pos' => false]);
    $campi = $campi();
    $forza(null);

    return in_array('label', $campi, true)
        && in_array('hours', $campi, true)
        && in_array('special_hours', $campi, true)
        && in_array('has_stock', $campi, true)
        && in_array('active', $campi, true);
});

check('ritiro e banco compaiono solo con la loro funzionalità', function () use ($campi, $forza) {
    $forza(['shipping' => false, 'pos' => false]);
    $senza = $campi();

    $forza(['shipping' => true, 'pos' => true]);
    $con = $campi();

    $forza(null);

    return !in_array('is_pickup_point', $senza, true)
        && !in_array('is_pos', $senza, true)
        && in_array('is_pickup_point', $con, true)
        && in_array('is_pos', $con, true);
});

check('i campi del magazzino non finiscono nella query della sede', function () {
    $valori = LocationResource::mutateRequestValues([
        'label' => 'Sede di prova',
        'has_stock' => 'true',
        'is_pos' => 'false',
    ], 'update');

    return !isset($valori['has_stock']) && !isset($valori['is_pos']) && isset($valori['label']);
});

$sede = SocietyLocation::find(['is_default' => 'true', 'deleted' => 'false'], 1);
$sedeId = (int) ($sede['id'] ?? 0);

try {
    Transaction::run(static function () use ($sedeId): void {
        // La riga precaricata arriva con i Defaults: qui si crea al volo se
        // manca, così il test prova la pagina e non il seed.
        if (Location::forSocietyLocation($sedeId) === []) {
            Location::create(['society_location_id' => $sedeId, 'has_stock' => 'true', 'active' => 'true']);
        }

        check('la sede ha la sua riga nel gestionale', fn () => Location::forSocietyLocation($sedeId) !== []);

        check('la scheda mostra i valori del magazzino', function () use ($sedeId) {
            $valori = LocationResource::mutateFormValues(['id' => $sedeId, 'label' => 'Sede'], 'edit');

            return isset($valori['has_stock'], $valori['is_pickup_point'], $valori['is_pos'], $valori['active']);
        });

        check('una sede collegata al magazzino non si elimina', function () use ($sedeId) {
            try {
                LocationResource::assertDeletable($sedeId);
            } catch (RuntimeException $exception) {
                return str_contains($exception->getMessage(), 'disattivala')
                    || str_contains($exception->getMessage(), 'predefinita');
            }

            return false;
        });

        check('il salvataggio scrive la riga del magazzino', function () use ($sedeId) {
            $_POST = ['has_stock' => 'false', 'active' => 'true'];
            LocationResource::afterUpdate($sedeId, (object) ['success' => true], []);
            $_POST = [];

            return (Location::forSocietyLocation($sedeId)['has_stock'] ?? '') === 'false';
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento non resta niente di quello che ha scritto il test', fn () =>
    Location::forSocietyLocation($sedeId) === []
    || (Location::forSocietyLocation($sedeId)['has_stock'] ?? '') === 'true'
);

summary();
