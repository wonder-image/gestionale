<?php
/** php tests/integrazione/ZoneQuickCreateTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Api\Endpoint;
use Wonder\Api\Support\ResourceApiController;
use Wonder\Backend\Support\QuickCreateController;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZone;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZoneArea;
use Wonder\Plugin\Gestionale\Resources\Shipping\ShippingZoneResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Sql\Transaction;

/*
 * «Nuova zona…» dalla scheda del metodo di spedizione, contro il database del
 * sito: lo stesso percorso dello store API, senza rete. Tutto si annulla alla fine.
 */

final class Annulla extends RuntimeException {}

$conta = static function (): int {
    $rows = ShippingZone::find(['deleted' => 'false']);

    if (!is_array($rows) || $rows === []) {
        return 0;
    }

    return isset($rows['id']) ? 1 : count(array_filter($rows, 'is_array'));
};

$controller = (new ReflectionClass(ResourceApiController::class))->newInstanceWithoutConstructor();
(new ReflectionMethod(ResourceApiController::class, '__construct'))->invoke($controller, ShippingZoneResource::class);

$preparati = static function (array $richiesta) use ($controller): array {
    $endpoint = (new ReflectionClass(Endpoint::class))->newInstanceWithoutConstructor();
    $endpoint->data = $richiesta;

    return (new ReflectionMethod(ResourceApiController::class, 'preparedValues'))->invoke($controller, $endpoint, 'store', null);
};

$prima = $conta();

try {
    Transaction::run(static function () use ($preparati): void {
        $_POST = [];
        $post = [
            'resource' => ShippingZoneResource::slug(),
            'quick_label' => 'name',
            'name' => 'Isole integrazione',
            'country' => 'it',
            'province' => 'ca',
            'position' => 99,
        ];

        $richiesta = QuickCreateController::payload($post);

        check('il modal non lascia vuoto nessun campo obbligatorio', fn () =>
            QuickCreateController::missingRequired(ShippingZoneResource::class, $richiesta) === []
        );

        $valori = $preparati($richiesta);
        $risultato = ShippingZone::create($valori);
        $id = (int) ($risultato->insert_id ?? 0);

        check('la zona nasce', fn () => $id > 0);

        check('paese e provincia non finiscono tra le colonne della zona', fn () =>
            !array_key_exists('country', $valori) && !array_key_exists('province', $valori)
        );

        // Come fa lo store API: la richiesta si rilegge dopo la preparazione.
        ShippingZoneResource::syncRepeaterRelations($id, array_merge($richiesta, $_POST), [], 'store', 'api');

        $aree = ShippingZoneArea::find(['shipping_zone_id' => $id, 'deleted' => 'false']);
        $aree = !is_array($aree) || $aree === [] ? [] : (isset($aree['id']) ? [$aree] : array_values(array_filter($aree, 'is_array')));

        check('nasce con la sua prima area, in maiuscolo', fn () =>
            count($aree) === 1 && ($aree[0]['country'] ?? '') === 'IT' && ($aree[0]['province'] ?? '') === 'CA'
        );

        check('senza paese una zona non nasce', function () use ($preparati): bool {
            $_POST = [];

            try {
                $preparati(['name' => 'Senza paese']);
            } catch (UserError $errore) {
                return $errore->key() === 'shipping.zone_no_areas';
            }

            return false;
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('alla fine non resta niente', fn () => $conta() === $prima);

summary();
