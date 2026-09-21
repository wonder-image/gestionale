<?php
/** php tests/FoldableTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;

final class RiquadroDiProva extends GestionaleResource
{
    public static string $model = ProductModel::class;

    public static function apri(string $title, array $components): object
    {
        return static::foldable($title, $components, 'Un aiuto breve');
    }
}

check('il riquadro di quello che si tocca di rado ha il suo titolo', function () {
    $riquadro = RiquadroDiProva::apri('Spedizione e fisco', []);
    $dentro = $riquadro->components[0] ?? null;

    // Con l'Accordion dentro ci sarebbe un Container; con il riquadro normale
    // il primo componente è il titolo.
    return $dentro instanceof Container
        || ($dentro instanceof SectionTitle && (string) $dentro->getText() === 'Spedizione e fisco');
});

check('la griglia a dodici colonne c\'è', function () {
    $riquadro = RiquadroDiProva::apri('Spedizione e fisco', []);
    $dentro = $riquadro->components[0] ?? null;
    $griglia = $dentro instanceof Container ? $dentro : $riquadro;

    return (int) ($griglia->columns['default'] ?? 0) === 12;
});

check('il riquadro occupa tutta la larghezza', function () {
    $riquadro = RiquadroDiProva::apri('Spedizione e fisco', []);

    return (int) ($riquadro->columnSpan['default'] ?? 0) === 12;
});

check('i campi che gli passi finiscono dentro', function () {
    $campo = SectionTitle::make('Peso');
    $riquadro = RiquadroDiProva::apri('Spedizione e fisco', [$campo]);
    $dentro = $riquadro->components[0] ?? null;
    $lista = $dentro instanceof Container ? $dentro->components : $riquadro->components;

    return in_array($campo, $lista, true);
});

summary();
