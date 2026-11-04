<?php
/** php tests/LabelsTest.php — #116, le etichette con codice a barre: formati, impaginazione, EAN e funzionalità */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Extensions\Extensions;
use Wonder\Plugin\Gestionale\Extensions\GestionaleExtension;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Labels\LabelDialog;
use Wonder\Plugin\Gestionale\Support\Labels\LabelFormats;
use Wonder\Plugin\Gestionale\Support\Labels\Labels;
use Wonder\Support\Labels\LabelFormat;
use Wonder\Support\Labels\LabelSheet;
use Wonder\Plugin\Gestionale\Resources\Catalog\LabelsResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;

/** Solo un rotolo: è il formato con cui si stampa. */
final class LabelsRollExtension extends GestionaleExtension
{
    public function labelFormats(array $formats): array
    {
        return ['roll-50x30' => $formats['roll-50x30']];
    }
}

final class LabelsTestExtension extends GestionaleExtension
{
    public function labelFormats(array $formats): array
    {
        unset($formats['roll-40x25']);
        $formats['roll-60x40'] = LabelFormat::roll('roll-60x40', 'Rotolo 60 × 40', 60.0, 40.0);

        return $formats;
    }
}

$features = new ReflectionProperty(Gestionale::class, 'features');
$item = ['article' => 'Maglia', 'variant' => 'Blu / M', 'sku' => 'MAG-BLU-M', 'price' => 29.9, 'ean' => '4006381333931', 'copies' => 1];

// --- Formati ---

$formats = LabelFormats::defaults();
check('cinque formati standard', fn () => array_keys($formats) === ['a4-24', 'a4-40', 'a4-65', 'roll-50x30', 'roll-40x25']);

$attese = ['a4-24' => 24, 'a4-40' => 40, 'a4-65' => 65, 'roll-50x30' => 1, 'roll-40x25' => 1];

foreach ($attese as $key => $perPage) {
    $format = $formats[$key];
    check("{$key}: {$perPage} per pagina", fn () => $format->perPage() === $perPage);
    check("{$key}: la griglia sta nel foglio", fn () => $format->marginLeft + $format->columns * $format->labelWidth <= $format->pageWidth + 0.001
        && $format->marginTop + $format->rows * $format->labelHeight <= $format->pageHeight + 0.001);

    $labels = LabelSheet::labels(Labels::contents([['copies' => $perPage + 1] + $item], Labels::DEFAULT_FIELDS));
    $plan = LabelSheet::plan($format, $labels);
    check("{$key}: l'etichetta in più va a pagina 2", fn () => count($plan) === $perPage + 1
        && $plan[$perPage - 1]['page'] === 1 && $plan[$perPage]['page'] === 2);

    $last = $plan[$perPage - 1];
    check("{$key}: l'ultima casella tocca il bordo della griglia", fn () => abs($last['x'] + $format->labelWidth - ($format->marginLeft + $format->columns * $format->labelWidth)) < 0.01
        && abs($last['y'] + $format->labelHeight - ($format->marginTop + $format->rows * $format->labelHeight)) < 0.01);

    $box = LabelSheet::layout($format, $labels[0]);
    check("{$key}: barre dentro l'etichetta e alte almeno 5 mm", fn () => $box['bars'] !== null
        && $box['bars']['x'] >= 0 && $box['bars']['x'] + 95 * $box['bars']['module'] <= $format->labelWidth
        && $box['bars']['height'] >= 5.0
        && $box['bars']['y'] + $box['bars']['height'] <= $box['digits']['y'] + 0.001
        && $box['digits']['y'] + $box['line'] <= $format->labelHeight);
    check("{$key}: il prezzo resta", fn () => $box['highlight'] !== '');

    check("{$key}: il PDF esce", fn () => str_starts_with(LabelSheet::render($format, $labels), '%PDF'));
}

check('A4 24: casella 1 e 2 una accanto all\'altra', function () use ($formats, $item) {
    $plan = LabelSheet::plan($formats['a4-24'], LabelSheet::labels(Labels::contents([['copies' => 2] + $item], ['article'])));

    return $plan[0]['x'] === 0.0 && $plan[1]['x'] === 70.0 && $plan[0]['y'] === $plan[1]['y'] && abs($plan[0]['y'] - 0.5) < 0.001;
});
check('start salta le caselle già usate', function () use ($formats, $item) {
    $plan = LabelSheet::plan($formats['a4-24'], LabelSheet::labels(Labels::contents([$item], ['article'])), 4);

    return $plan[0]['x'] === 70.0 && abs($plan[0]['y'] - 37.5) < 0.001;
});
check('sul rotolo start non conta', function () use ($formats, $item) {
    $plan = LabelSheet::plan($formats['roll-50x30'], LabelSheet::labels(Labels::contents([['copies' => 2] + $item], ['article'])), 5);

    return $plan[0]['page'] === 1 && $plan[1]['page'] === 2 && $plan[0]['x'] === 0.0;
});
check('rotolo 50 × 30 in orizzontale', function () use ($formats, $item) {
    $pdf = LabelSheet::render($formats['roll-50x30'], LabelSheet::labels(Labels::contents([$item], Labels::DEFAULT_FIELDS)));

    return preg_match('#/MediaBox \[0 0 ([0-9.]+) ([0-9.]+)\]#', $pdf, $m) === 1 && (float) $m[1] > (float) $m[2];
});
check('PDF vuoto con una pagina', fn () => str_starts_with(LabelSheet::render($formats['a4-24'], []), '%PDF'));
check('sul 40 × 25 le righe in più si lasciano', function () use ($formats, $item) {
    $labels = LabelSheet::labels(Labels::contents([$item], ['article', 'variant', 'sku', 'price', 'barcode']));
    $box = LabelSheet::layout($formats['roll-40x25'], $labels[0]);

    return count($box['lines']) < 3 && $box['bars']['height'] >= 5.0;
});

// --- Contenuto ed EAN ---

check('EAN-13 valido: 95 moduli', function () use ($item) {
    $label = LabelSheet::labels(Labels::contents([$item], ['barcode']))[0];

    return $label['ean'] === '4006381333931' && strlen((string) $label['modules']) === 95 && !$label['invalid'];
});
check('EAN-8 valido: 67 moduli', function () use ($item) {
    $label = LabelSheet::labels(Labels::contents([['ean' => '96385074'] + $item], ['barcode']))[0];

    return strlen((string) $label['modules']) === 67 && !$label['invalid'];
});
check('EAN con la cifra di controllo sbagliata: niente barre, avviso', function () use ($formats, $item) {
    $label = LabelSheet::labels(Labels::contents([['ean' => '4006381333932'] + $item], ['barcode']))[0];
    $box = LabelSheet::layout($formats['a4-24'], $label);

    return $label['invalid'] && $label['modules'] === null && $box['bars'] === null
        && str_contains($box['digits']['text'], 'non valido');
});
check('senza EAN niente codice', function () use ($formats, $item) {
    $label = LabelSheet::labels(Labels::contents([['ean' => ''] + $item], Labels::DEFAULT_FIELDS))[0];
    $box = LabelSheet::layout($formats['a4-24'], $label);

    return $box['bars'] === null && $box['digits'] === null && !$label['invalid'];
});
check('solo i campi scelti', function () use ($item) {
    $label = LabelSheet::labels(Labels::contents([$item], ['sku']))[0];

    return $label['lines'] === ['SKU MAG-BLU-M'] && $label['highlight'] === '' && $label['ean'] === '';
});
check('la variante uguale all\'articolo non si ripete', fn () => LabelSheet::labels(Labels::contents([['variant' => 'Maglia'] + $item], ['article', 'variant']))[0]['lines'] === ['Maglia']);
check('copie: una etichetta ciascuna', fn () => count(LabelSheet::labels(Labels::contents([['copies' => 3] + $item, ['copies' => 0] + $item], ['article']))) === 3);

// --- Opzioni e prezzo ---

check('formato sconosciuto diventa il primo', fn () => LabelFormats::current('nessuno')->key === 'a4-24' && LabelFormats::current('a4-65')->key === 'a4-65');
check('opzioni: il formato non arriva dalla finestra', fn () => Labels::options(['format' => 'a4-65'])['format']->key === LabelFormats::current()->key);
check('opzioni: campi da lista con virgole, sconosciuti via', fn () => Labels::options(['fields' => 'sku,xxx,price'])['fields'] === ['sku', 'price']);
check('opzioni: campi da array', fn () => Labels::options(['fields' => ['barcode', 'article']])['fields'] === ['article', 'barcode']);
check('opzioni: niente spuntato = campi di partenza', fn () => Labels::options(['fields' => []])['fields'] === Labels::DEFAULT_FIELDS);
check('opzioni: copie fra 1 e 500', fn () => Labels::options(['copies' => '0'])['copies'] === 1 && Labels::options(['copies' => '9999'])['copies'] === Labels::MAX_COPIES);
check('opzioni: start contato da 1 e dentro il foglio', fn () => Labels::options(['start' => '5'])['start'] === 4
    && Labels::options(['start' => '99'])['start'] === 23);

check('prezzo: lo scontato se è sotto il pieno', fn () => Labels::price(['price' => '30', 'sale_price' => '25']) === 25.0);
check('prezzo: lo scontato più alto non conta', fn () => Labels::price(['price' => '30', 'sale_price' => '35']) === 30.0);
check('prezzo: senza prezzo nessuno', fn () => Labels::price(['price' => '', 'sale_price' => '']) === null);
check('prezzo: listino senza IVA, si aggiunge', fn () => Labels::price(['price' => '10'], 22.0, false) === 12.2);

// --- Selettore della pagina Catalogo › Etichette ---

$articoli = [
    ['id' => 1, 'name' => 'Borsa', 'type' => 'simple'],
    ['id' => 2, 'name' => 'Maglia', 'type' => 'simple'],
    ['id' => 3, 'name' => 'Box mensile', 'type' => \Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionModels::TYPE],
    ['id' => 4, 'name' => 'Senza varianti', 'type' => 'simple'],
];
$fatti = [
    1 => ['categories' => [10, 1], 'tags' => [5], 'brand_id' => 7, 'model_id' => 1],
    2 => ['categories' => [20], 'tags' => [], 'brand_id' => 8, 'model_id' => 2],
    3 => ['categories' => [10, 1], 'tags' => [], 'brand_id' => 7, 'model_id' => 3],
];
$vuoto = ['all' => false, 'categories' => [], 'tags' => [], 'brands' => [], 'models' => [], 'excluded_models' => []];

check('selettore: tutto il catalogo, senza abbonamenti né articoli vuoti', fn () => Labels::modelsInScope(['all' => true] + $vuoto, $articoli, $fatti) === [1, 2]);
check('selettore: la categoria madre prende le figlie', fn () => Labels::modelsInScope(['categories' => [1]] + $vuoto, $articoli, $fatti) === [1]);
check('selettore: tag, marchio e articolo si sommano', fn () => Labels::modelsInScope(['tags' => [5], 'models' => [2]] + $vuoto, $articoli, $fatti) === [1, 2]);
check('selettore: l\'escluso vince', fn () => Labels::modelsInScope(['all' => true, 'excluded_models' => [2]] + $vuoto, $articoli, $fatti) === [1]);
check('selettore: niente scelto, niente stampato', fn () => Labels::modelsInScope($vuoto, $articoli, $fatti) === []);
check('selettore: l\'abbonamento scelto a mano resta fuori', fn () => Labels::modelsInScope(['models' => [3]] + $vuoto, $articoli, $fatti) === []);

// --- La pagina ---


check('pagina: in Catalogo, alla voce Etichette', function () {
    $nav = LabelsResource::navigationSchema()->all();

    return ($nav['section_key'] ?? '') === 'catalogo' && ($nav['title'] ?? '') === 'Etichette';
});
check('pagina: i campi si costruiscono (selettore dei coupon, copie, scelte di stampa)', function () {
    $nomi = array_map(static fn ($field) => $field->name ?? null, LabelsResource::formFields());

    return in_array('applies_to_all', $nomi, true) && in_array('copies', $nomi, true) && in_array('fields', $nomi, true);
});
check('pagina: la richiesta del PDF porta il selettore ripulito e le scelte di stampa', function () {
    $query = LabelsResource::printQuery([
        'applies_to_all' => 'false', 'categories' => ['3', 'x', '3'], 'models' => '9',
        'fields' => ['price'], 'copies' => '2', 'start' => '4', '_csrf' => 'abc',
    ]);

    return $query['scope']['all'] === false && $query['scope']['categories'] === [3] && $query['scope']['models'] === []
        && $query['fields'] === ['price'] && $query['copies'] === '2' && $query['start'] === '4' && !isset($query['_csrf']);
});
check('lista Prodotti: niente più rotta del pannello', fn () => (new ReflectionMethod(ProductModelResource::class, 'registerBackendRoutes'))->getDeclaringClass()->getName() !== ProductModelResource::class);

// --- Formati del sito ---

Extensions::use([LabelsTestExtension::class]);
check('l\'estensione aggiunge e toglie formati', fn () => isset(LabelFormats::all()['roll-60x40']) && !isset(LabelFormats::all()['roll-40x25']));
check('la tendina mostra il nuovo formato', fn () => (LabelFormats::options()['roll-60x40'] ?? '') === 'Rotolo 60 × 40');
Extensions::use(null);

// --- Funzionalità ---

$features->setValue(null, ['barcode_labels' => false]);
check('spenta: nessun bottone', fn () => LabelDialog::actions() === []);
check('spenta: nessuna finestra', fn () => LabelDialog::modal('/backend/x/1/etichette/') === '');
check('spenta: la rotta risponde 403', fn () => Labels::respond('model', 1, [])['status'] === 403
    && Labels::respond('receipt', 1, [])['status'] === 403
    && Labels::respond('scope', 0, ['scope' => ['all' => true]])['status'] === 403);

$features->setValue(null, ['barcode_labels' => true]);
check('accesa: il bottone apre la finestra', fn () => count(LabelDialog::actions()) === 1
    && str_contains(LabelDialog::actions()[0]->render('bootstrap'), 'data-bs-target="#'.LabelDialog::MODAL.'"'));
check('accesa: la finestra chiede solo campi e casella, senza formato né copie', function () {
    $html = LabelDialog::modal('/backend/x/1/etichette/');

    return str_contains($html, 'id="'.LabelDialog::MODAL.'"') && !str_contains($html, 'name="format"')
        && !str_contains($html, 'Formato: ') && !str_contains($html, 'name="copies"') && str_contains($html, 'name="start"')
        && !str_contains($html, 'name="items');
});
check('pagina: scegliere niente = 404 col suo messaggio', function () {
    $risposta = Labels::respond('scope', 0, ['scope' => ['all' => false]]);

    return $risposta['status'] === 404 && str_contains($risposta['body'], 'Nessun prodotto');
});
Extensions::use([LabelsRollExtension::class]);
check('rotolo nelle Impostazioni: niente casella libera', fn () => !str_contains(LabelDialog::modal('/x'), 'name="start"')
    && Labels::options(['start' => '5'])['start'] === 0);
Extensions::use(null);
check('sul carico la finestra è in GET', fn () => preg_match('/<form[^>]*method="get"/i', LabelDialog::modal('/x')) === 1);
check('accesa: sorgente sconosciuta 404', fn () => Labels::respond('ordine', 1, [])['status'] === 404);
check('accesa: id zero 404', fn () => Labels::respond('model', 0, [])['status'] === 404);
check('url vuoto senza id', fn () => LabelDialog::url('product', 'catalogo/prodotti', 0) === '');
$features->setValue(null, null);

summary();
