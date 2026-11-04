<?php
/** php tests/CouponTableResourcesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Promotions\CouponCodeTableResource;
use Wonder\Plugin\Gestionale\Resources\Promotions\CouponRedemptionTableResource;

/**
 * Codici e utilizzi nella scheda del coupon sono Resource senza pagina, menu
 * né API, come l'anteprima delle campagne: servono solo la tabella del core.
 */
foreach ([CouponCodeTableResource::class, CouponRedemptionTableResource::class] as $resource) {
    check($resource::titleLabel().': nessuna pagina, nessuna voce di menu, nessuna API', function () use ($resource) {
        $pagine = array_filter((array) ($resource::pageSchema()->toArray()['pages'] ?? []));

        return $pagine === [] && empty($resource::navigationSchema()->toArray()['enabled'])
            && empty($resource::apiSchema()->toArray()['enabled']);
    });

    check($resource::titleLabel().': ogni colonna ha la sua etichetta', function () use ($resource) {
        $etichette = $resource::labelSchema();

        foreach ($resource::tableSchema() as $colonna) {
            if (!isset($etichette[(string) $colonna->name])) {
                return false;
            }
        }

        return true;
    });
}

check('i codici: codice, stato, ordine e data d\'uso', fn () =>
    array_map(static fn ($c): string => (string) $c->name, CouponCodeTableResource::tableSchema()) === ['code', 'code_status', 'order_number', 'used_at']
);

check('il link all\'ordine mostra il numero, o l\'id se manca; niente senza ordine', fn () =>
    CouponCodeTableResource::orderLink(0, 'A-1') === ''
    && str_contains(CouponCodeTableResource::orderLink(7, 'A-1'), '>A-1</a>')
    && str_contains(CouponCodeTableResource::orderLink(7, ' '), '>#7</a>')
    && !str_contains(CouponCodeTableResource::orderLink(7, '<b>'), '<b>')
);

check('la data è d/m/Y, vuota se manca o è la data zero', fn () =>
    CouponCodeTableResource::day('2026-10-05 14:00:00') === '05/10/2026'
    && CouponCodeTableResource::day('') === ''
    && CouponCodeTableResource::day('0000-00-00 00:00:00') === ''
);

check('un utilizzo conta finché non ha data di rilascio, anche la data zero di MySQL', fn () =>
    str_contains(CouponCodeTableResource::notReleased('r'), 'r.released_at IS NULL')
    && str_contains(CouponCodeTableResource::notReleased('r'), 'YEAR(r.released_at) = 0')
);

summary();
