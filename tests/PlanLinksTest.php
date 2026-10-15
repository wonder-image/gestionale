<?php
/** php tests/PlanLinksTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Subscriptions\PlanLinks;

$rifiuto = static function (callable $corpo): ?UserError {
    try {
        $corpo();
    } catch (UserError $e) {
        return $e;
    }

    return null;
};

// Dominio 1, Hosting 2, Backup 3, Email 4.

check('Hosting che si lega a Dominio passa', fn () =>
    $rifiuto(fn () => PlanLinks::assert(2, [1], [])) === null
);

check('nessuna madre passa anche se il piano è madre di altri', fn () =>
    $rifiuto(fn () => PlanLinks::assert(1, [], [2 => [1]])) === null
);

check('un piano che si lega a sé non passa', fn () =>
    $rifiuto(fn () => PlanLinks::assert(1, [1], []))?->key() === 'plan.link_self'
);

check('legarsi a un piano che ha già una madre non passa: un livello solo', fn () =>
    $rifiuto(fn () => PlanLinks::assert(3, [2], [2 => [1]]))?->key() === 'plan.link_depth'
);

check('un piano madre di altri che riceve una madre non passa', fn () =>
    $rifiuto(fn () => PlanLinks::assert(2, [1], [3 => [2]]))?->key() === 'plan.link_depth'
);

check('le madri vecchie del piano stesso non contano: si riscrivono', fn () =>
    $rifiuto(fn () => PlanLinks::assert(2, [4], [2 => [1]])) === null
);

check('normalize toglie zeri, doppioni e il piano stesso resta per il controllo', fn () =>
    PlanLinks::normalize(['', '0', '1', 1, '4', 'x']) === [1, 4]
);

summary();
