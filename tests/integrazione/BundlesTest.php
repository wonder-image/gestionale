<?php
/** php tests/integrazione/BundlesTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Support\Catalog\Bundles;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    return $esito;
}

function chiaveDi(callable $fn): string
{
    try {
        $fn();
    } catch (UserError $e) {
        return $e->key();
    }

    return '';
}

/** Esegue la prova con la vendita senza giacenza accesa. */
function conBackorder(callable $prova): mixed
{
    Gestionale::feature('backorders');
    $stato = new ReflectionProperty(Gestionale::class, 'features');
    $prima = $stato->getValue();
    $stato->setValue(null, array_merge((array) $prima, ['backorders' => true]));

    try {
        return $prova();
    } finally {
        $stato->setValue(null, $prima);
    }
}

check('forModel: componenti e gruppi nell\'ordine, con i nomi', function () {
    return prova(function () {
        $a = articoloConGiacenza(5, 'BA-'.uniqid());
        $b = articoloConGiacenza(5, 'BB-'.uniqid());
        $c = articoloConGiacenza(5, 'BC-'.uniqid());
        $bundle = multiprodottoDiProva('mixed', [['product_id' => $b, 'quantity' => 1], ['product_id' => $a, 'quantity' => 2]], [
            ['name' => 'Scelta', 'min' => 1, 'max' => 1, 'options' => [['product_id' => $c, 'surcharge' => 1], ['product_id' => $a]]],
        ]);
        $forma = Bundles::forModel(modelloDi($bundle));

        return $forma['mode'] === 'mixed'
            && array_column($forma['components'], 'product_id') === [$b, $a]
            && array_column($forma['components'], 'quantity') === [1.0, 2.0]
            && stripos($forma['components'][0]['name'], 'Prova vendite') !== false
            && count($forma['groups']) === 1
            && array_column($forma['groups'][0]['options'], 'product_id') === [$c, $a]
            && $forma['groups'][0]['options'][0]['surcharge'] === '1.00'
            && $forma['groups'][0]['min'] === 1 && $forma['groups'][0]['max'] === 1
            && Bundles::forModel(0)['components'] === [];
    });
});

check('isBundle: solo il tipo bundle', function () {
    return prova(function () {
        $semplice = articoloConGiacenza(1, 'BS-'.uniqid());
        $bundle = multiprodottoDiProva('fixed', [['product_id' => $semplice, 'quantity' => 1]], []);

        return Bundles::isBundle($bundle) && !Bundles::isBundle($semplice);
    });
});

check('resolve: figlie fisse e scelte, sovrapprezzo 2.50 + 1.00', function () {
    return prova(function () {
        $a = articoloConGiacenza(9, 'RA-'.uniqid());
        $b = articoloConGiacenza(9, 'RB-'.uniqid());
        $c = articoloConGiacenza(9, 'RC-'.uniqid());
        $bundle = multiprodottoDiProva('mixed', [['product_id' => $a, 'quantity' => 2]], [
            ['name' => 'Colore', 'min' => 1, 'max' => 1, 'options' => [['product_id' => $b, 'surcharge' => 2.5], ['product_id' => $c]]],
            ['name' => 'Extra', 'min' => 0, 'max' => 1, 'options' => [['product_id' => $c, 'surcharge' => 1]]],
        ]);
        $forma = Bundles::forModel(modelloDi($bundle));
        $colore = $forma['groups'][0]['options'][0]['id'];
        $extra = $forma['groups'][1]['options'][0]['id'];
        $r = Bundles::resolve($bundle, [(string) $colore, $extra]);

        return $r['modelId'] === modelloDi($bundle)
            && $r['surcharge'] === '3.50'
            && array_column($r['children'], 'product_id') === [$a, $b, $c]
            && array_column($r['children'], 'quantity') === [2.0, 1.0, 1.0]
            && array_column($r['children'], 'bundle_option_id') === [0, $colore, $extra];
    });
});

check('resolve: prodotto semplice, componente spento, scelta mancante', function () {
    return prova(function () {
        $a = articoloConGiacenza(9, 'RS-'.uniqid());
        $b = articoloConGiacenza(9, 'RT-'.uniqid());
        $fisso = multiprodottoDiProva('fixed', [['product_id' => $a, 'quantity' => 1]], []);
        $scelta = multiprodottoDiProva('choice', [], [['name' => 'Uno', 'min' => 1, 'max' => 1, 'options' => [['product_id' => $b]]]]);

        $semplice = chiaveDi(fn () => Bundles::resolve($a, []));
        $mancante = chiaveDi(fn () => Bundles::resolve($scelta, []));
        Product::update(['active' => 'false'], $a);
        $spento = chiaveDi(fn () => Bundles::resolve($fisso, []));

        return $semplice === 'bundle.not_bundle' && $mancante === 'bundle.too_few' && $spento === 'bundle.component_unavailable';
    });
});

check('resolve: un\'opzione spenta che non si sceglie non blocca', function () {
    return prova(function () {
        $a = articoloConGiacenza(9, 'RO-'.uniqid());
        $b = articoloConGiacenza(9, 'RP-'.uniqid());
        $bundle = multiprodottoDiProva('choice', [], [['name' => 'Uno', 'min' => 1, 'max' => 1, 'options' => [['product_id' => $a], ['product_id' => $b]]]]);
        Product::update(['active' => 'false'], $a);
        $forma = Bundles::forModel(modelloDi($bundle));
        $buona = $forma['groups'][0]['options'][1]['id'];
        $spenta = $forma['groups'][0]['options'][0]['id'];

        return Bundles::resolve($bundle, [$buona])['children'][0]['product_id'] === $b
            && chiaveDi(fn () => Bundles::resolve($bundle, [$spenta])) === 'bundle.component_unavailable';
    });
});

check('available: fisso 2 su 5 → 2; il prodotto in D60 non limita; spento vale 0', function () {
    return prova(function () {
        $a = articoloConGiacenza(5, 'AV-'.uniqid());
        $b = articoloConGiacenza(0, 'AW-'.uniqid());
        $bundle = multiprodottoDiProva('fixed', [['product_id' => $a, 'quantity' => 2]], []);
        $due = Bundles::available(modelloDi($bundle));

        $doppio = multiprodottoDiProva('fixed', [['product_id' => $a, 'quantity' => 2], ['product_id' => $b, 'quantity' => 1]], []);
        $senzaScorta = Bundles::available(modelloDi($doppio));

        Product::update(['allow_backorder' => 'true'], $b);
        $scoperto = conBackorder(fn () => Bundles::available(modelloDi($doppio)));

        Product::update(['active' => 'false'], $a);

        return $due === 2.0 && $senzaScorta === 0.0 && $scoperto === 2.0 && Bundles::available(modelloDi($bundle)) === 0.0;
    });
});

check('componentsValue: somma dei prezzi correnti, scontato se c\'è', function () {
    return prova(function () {
        $a = articoloConGiacenza(1, 'CV-'.uniqid());
        $b = articoloConGiacenza(1, 'CW-'.uniqid());
        Product::update(['price' => '10.00'], $a);
        Product::update(['price' => '8.00', 'sale_price' => '6.00'], $b);
        $bundle = multiprodottoDiProva('fixed', [['product_id' => $a, 'quantity' => 2], ['product_id' => $b, 'quantity' => 1]], []);

        return Bundles::componentsValue(modelloDi($bundle)) === '26.00';
    });
});

check('usedBy: i multiprodotti che usano il prodotto, senza doppioni', function () {
    return prova(function () {
        $a = articoloConGiacenza(1, 'UB-'.uniqid());
        $b = articoloConGiacenza(1, 'UC-'.uniqid());
        $bundle = multiprodottoDiProva('mixed', [['product_id' => $a, 'quantity' => 1]], [
            ['name' => 'Uno', 'min' => 1, 'max' => 1, 'options' => [['product_id' => $a], ['product_id' => $b]]],
        ]);
        $nome = Bundles::forModel(modelloDi($bundle))['components'] === [] ? '' : 'Confezione';

        $usati = Bundles::usedBy($a);

        return count($usati) === 1 && str_starts_with($usati[0], $nome)
            && count(Bundles::usedBy($b)) === 1
            && Bundles::usedBy(articoloConGiacenza(1, 'UD-'.uniqid())) === [];
    });
});

summary();
