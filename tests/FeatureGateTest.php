<?php
/** php tests/FeatureGateTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Model;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;

final class ModelloDiProva extends Model
{
    public static string $table = 'gst_prova_gate';

    public static function tableSchema(): array { return []; }
    public static function dataSchema(): array { return []; }
}

final class ResourceSempreAttiva extends GestionaleResource
{
    public static string $model = ModelloDiProva::class;

    public static function path(): string { return 'gestionale/sempre-attiva'; }
}

final class ResourceConFunzionalita extends GestionaleResource
{
    public static string $model = ModelloDiProva::class;
    public static string $feature = 'orders';

    public static function path(): string { return 'gestionale/con-funzionalita'; }
}

// Lo stato si forza senza database: features() è memoizzato.
$forza = static function (array $stato): void {
    $proprieta = new ReflectionProperty(Gestionale::class, 'features');
    $proprieta->setAccessible(true);
    $proprieta->setValue(null, $stato);
};

check('senza funzionalità dichiarata la pagina resta attiva', function () use ($forza) {
    $forza(['orders' => false]);

    return ResourceSempreAttiva::featureActive() === true
        && (bool) ResourceSempreAttiva::navigationSchema()->get('enabled') === true;
});

check('funzionalità bloccata: niente menu, niente pagine, niente API', function () use ($forza) {
    $forza(['orders' => false]);

    $pages = (array) ResourceConFunzionalita::pageSchema()->get('pages');

    return ResourceConFunzionalita::featureActive() === false
        && (bool) ResourceConFunzionalita::navigationSchema()->get('enabled') === false
        && array_filter($pages) === []
        && (bool) ResourceConFunzionalita::apiSchema()->get('enabled') === false;
});

check('funzionalità attiva: tutto torna disponibile', function () use ($forza) {
    $forza(['orders' => true]);

    $pages = (array) ResourceConFunzionalita::pageSchema()->get('pages');

    return ResourceConFunzionalita::featureActive() === true
        && (bool) ResourceConFunzionalita::navigationSchema()->get('enabled') === true
        && ($pages['list'] ?? false) === true;
});

summary();
