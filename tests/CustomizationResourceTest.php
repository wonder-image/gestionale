<?php
/** php tests/CustomizationResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\ResourceSchema\Input;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationOption;
use Wonder\Plugin\Gestionale\Resources\Catalog\CustomizationResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/** I riquadri del form, nell'ordine. @return list<Card> */
$riquadri = static function (): array {
    $form = CustomizationResource::formLayoutSchema();
    $out = [];

    foreach ($form->components ?? [] as $contenitore) {
        foreach ($contenitore->components ?? [] as $card) {
            if ($card instanceof Card) {
                $out[] = $card;
            }
        }
    }

    return $out;
};

/** Larghezza di ogni campo del riquadro, nell'ordine in cui compaiono. */
$larghezze = static function (Card $card): array {
    $out = [];

    foreach ($card->components ?? [] as $dentro) {
        if ($dentro instanceof Input) {
            $out[(string) $dentro->name] = ((array) $dentro->columnSpan)['default'] ?? null;
        }
    }

    return $out;
};

check('la pagina sta in Catalogo, dopo gli attributi, dietro la funzionalità', function () {
    $menu = CustomizationResource::navigationSchema()->toArray();

    return CustomizationResource::$feature === 'customizations'
        && CustomizationResource::$model === Customization::class
        && CustomizationResource::$orderColumn === 'position'
        && CustomizationResource::$docsPage === 'catalogo/catalogo-personalizzazioni'
        && CustomizationResource::path() === 'app/gestionale/personalizzazioni'
        && ($menu['section_key'] ?? '') === 'catalogo'
        && (int) ($menu['order'] ?? 0) === 44;
});

check('il form ha i campi nell\'ordine giusto, con le larghezze del disegno', function () use ($riquadri, $larghezze) {
    $cards = $riquadri();

    return count($cards) === 2
        && $larghezze($cards[0]) === [
            'name' => 6, 'label' => 6, 'help_text' => 12,
            'kind' => 3, 'max_length' => 3, 'surcharge' => 3, 'active' => 3,
        ];
});

check('i caratteri massimi si vedono solo con il testo e le opzioni solo con la scelta', function () use ($riquadri) {
    $cards = $riquadri();

    return CustomizationResource::getInput('max_length')->conditionalAttributes() === [
            'data-visible-when' => 'kind',
            'data-visible-when-values' => 'text',
        ]
        && $cards[1]->getAttr('data-visible-when') === 'kind'
        && $cards[1]->getAttr('data-visible-when-values') === 'choice';
});

check('le opzioni sono un repeater che cancella davvero e tiene l\'ordine', function () {
    $relazioni = CustomizationResource::repeaterRelations();
    $relazione = $relazioni['options']['relation'] ?? null;

    return $relazione !== null
        && $relazione->table === CustomizationOption::$table
        && $relazione->parentKey === 'customization_id'
        && $relazione->softDelete === false
        && $relazione->positionKey === 'position';
});

check('l\'API accetta solo lo store e solo il nome', function () {
    $schema = CustomizationResource::apiSchema()->toArray();

    return ($schema['routes']['store'] ?? false) === true
        && ($schema['routes']['index'] ?? true) === false
        && ($schema['routes']['destroy'] ?? true) === false
        && ($schema['fields']['store'] ?? []) === ['name'];
});

check('quickCreateValues dà un testo da 100 caratteri, gratis e attivo', function () {
    $valori = CustomizationResource::quickCreateValues(['name' => ' Incisione ', 'surcharge' => '99', 'kind' => 'choice']);

    return $valori['name'] === 'Incisione'
        && $valori['label'] === 'Incisione'
        && $valori['kind'] === 'text'
        && (int) $valori['max_length'] === 100
        && $valori['surcharge'] === '0.00'
        && $valori['active'] === 'true';
});

check('quickCreateValues senza nome dice di scriverlo', function () {
    foreach ([' ', '', null] as $nome) {
        try {
            CustomizationResource::quickCreateValues(['name' => $nome]);

            return false;
        } catch (UserError $e) {
            if ($e->key() !== 'customization.quick_name') {
                return false;
            }
        }
    }

    return true;
});

check('l\'elenco non stampa il nome come HTML', function () {
    $colonne = [];

    foreach (CustomizationResource::tableSchema() as $colonna) {
        $colonne[(string) $colonna->name] = $colonna;
    }

    $riga = ['name' => '<b>x</b>', 'kind' => '<i>y</i>', 'surcharge' => '0.00', 'id' => 1, 'active' => 'true'];
    $kind = $colonne['kind']->toArray()['formatter'] ?? null;
    $testo = is_callable($kind) ? (string) $kind($riga) : '';

    return isset($colonne['name'], $colonne['kind'], $colonne['active'])
        && !str_contains($testo, '<i>')
        && !str_contains($testo, '<b>');
});

check('mutateRequestValues in modifica non tocca la posizione', function () {
    $_POST = [];
    $valori = CustomizationResource::mutateRequestValues(
        ['name' => 'Incisione', 'kind' => 'text', 'max_length' => '20', 'surcharge' => '0', 'position' => 9],
        'update',
        'backend',
        ['id' => 3]
    );

    return !isset($valori['position']);
});

check('una scelta con meno di due opzioni non si salva', function () {
    $_POST = ['options' => [['label' => 'Rosso', 'surcharge' => '0']]];

    try {
        CustomizationResource::mutateRequestValues(['name' => 'Colore', 'kind' => 'choice', 'surcharge' => '0'], 'update', 'backend', ['id' => 3]);
    } catch (UserError $e) {
        $_POST = [];

        return $e->key() === 'customization.few_options';
    }

    $_POST = [];

    return false;
});

check('passando da scelta a testo le opzioni postate si svuotano', function () {
    $_POST = ['options' => [['label' => 'Rosso', 'surcharge' => '0'], ['label' => 'Blu', 'surcharge' => '0']]];
    CustomizationResource::mutateRequestValues(['name' => 'Colore', 'kind' => 'text', 'max_length' => '20', 'surcharge' => '0'], 'update', 'backend', ['id' => 3]);
    $dopo = $_POST['options'] ?? null;
    $_POST = [];

    return $dopo === [];
});

check('lo store API non crea opzioni nemmeno se la richiesta le porta', function () {
    $righe = CustomizationResource::syncRepeaterRelations(
        1,
        ['name' => 'Incisione', 'options' => [['label' => 'x', 'surcharge' => '1']]],
        [],
        'store',
        'api'
    );

    return $righe === [];
});

check('le opzioni senza etichetta si scartano', function () {
    $righe = CustomizationResource::prepareRepeaterRows('options', [
        ['label' => 'Rosso', 'surcharge' => '0'],
        ['label' => '  ', 'surcharge' => '3'],
    ]);

    return count($righe) === 1 && $righe[0]['label'] === 'Rosso';
});

summary();
