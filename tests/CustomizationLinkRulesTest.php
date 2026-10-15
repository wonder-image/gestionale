<?php
/** php tests/CustomizationLinkRulesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationLink;
use Wonder\Plugin\Gestionale\Resources\Catalog\CustomizationResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

$rifiuto = static function (callable $corpo): ?UserError {
    try {
        $corpo();
    } catch (UserError $e) {
        return $e;
    }

    return null;
};

check('un legame con il solo articolo passa', fn () =>
    $rifiuto(fn () => CustomizationLink::assertTarget(['product_model_id' => 4, 'plan_id' => null])) === null
);

check('un legame con il solo piano passa', fn () =>
    $rifiuto(fn () => CustomizationLink::assertTarget(['product_model_id' => '', 'plan_id' => '7'])) === null
);

check('un legame con articolo e piano insieme non passa', fn () =>
    $rifiuto(fn () => CustomizationLink::assertTarget(['product_model_id' => 4, 'plan_id' => 7]))?->key() === 'customization.link_target'
);

check('un legame senza articolo né piano non passa', function () use ($rifiuto) {
    foreach ([[], ['product_model_id' => 0, 'plan_id' => 0], ['product_model_id' => '', 'plan_id' => null]] as $valori) {
        if ($rifiuto(fn () => CustomizationLink::assertTarget($valori))?->key() !== 'customization.link_target') {
            return false;
        }
    }

    return true;
});

check('una personalizzazione né per i prodotti né per gli abbonamenti non si salva', fn () =>
    $rifiuto(fn () => CustomizationResource::assertScopes(['for_products' => 'false', 'for_subscriptions' => 'false'], 0, 0))?->key()
        === 'customization.scope_required'
);

check('spegnere «Per i prodotti» con articoli collegati dice quanti sono', function () use ($rifiuto) {
    $errore = $rifiuto(fn () => CustomizationResource::assertScopes(['for_products' => 'false', 'for_subscriptions' => 'true'], 2, 0));

    return $errore?->key() === 'customization.linked_products' && str_contains($errore->getMessage(), '2');
});

check('spegnere «Per gli abbonamenti» con un piano collegato non si salva', fn () =>
    $rifiuto(fn () => CustomizationResource::assertScopes(['for_products' => 'true', 'for_subscriptions' => 'false'], 0, 1))?->key()
        === 'customization.linked_plans'
);

check('spegnere un interruttore senza legami passa', fn () =>
    $rifiuto(fn () => CustomizationResource::assertScopes(['for_products' => 'false', 'for_subscriptions' => 'true'], 0, 3)) === null
    && $rifiuto(fn () => CustomizationResource::assertScopes(['for_products' => 'true', 'for_subscriptions' => 'false'], 5, 0)) === null
);

summary();
