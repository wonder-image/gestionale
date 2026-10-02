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
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCustomization;
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

    return count($cards) === 3
        && $larghezze($cards[0]) === ['name' => 6, 'active' => 6, 'label' => 12, 'help_text' => 12]
        && $larghezze($cards[1]) === ['options' => 12]
        && $larghezze($cards[2]) === ['kind' => 12, 'max_length' => 12, 'decimals' => 12, 'surcharge' => 12];
});

check('il form ha a sinistra la personalizzazione e le opzioni, a destra i dettagli', function () {
    $form = CustomizationResource::formLayoutSchema();
    $titoli = [];

    foreach ($form->components as $contenitore) {
        $span = ((array) $contenitore->columnSpan)['default'] ?? null;

        foreach ($contenitore->components as $card) {
            foreach ($card->components as $dentro) {
                if ($dentro instanceof SectionTitle) {
                    $titoli[$span][] = $dentro->getText();
                }
            }
        }
    }

    return array_keys($titoli) === [8, 4]
        && $titoli[4] === ['Dettagli']
        && count($titoli[8]) === 2;
});

check('i caratteri massimi si vedono solo con il testo, i decimali solo con il numero e le opzioni solo con la scelta', function () use ($riquadri) {
    $cards = $riquadri();

    return CustomizationResource::getInput('max_length')->conditionalAttributes() === [
            'data-visible-when' => 'kind',
            'data-visible-when-values' => 'text',
        ]
        && CustomizationResource::getInput('decimals')->conditionalAttributes() === [
            'data-visible-when' => 'kind',
            'data-visible-when-values' => 'number',
        ]
        && $cards[1]->getAttr('data-visible-when') === 'kind'
        && $cards[1]->getAttr('data-visible-when-values') === 'choice';
});

check('il tipo offre testo, numero e scelta', function () {
    return array_keys(CustomizationResource::kinds()) === ['text', 'number', 'choice'];
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

check('l\'API accetta solo lo store, con nome e sovrapprezzo', function () {
    $schema = CustomizationResource::apiSchema()->toArray();

    return ($schema['routes']['store'] ?? false) === true
        && ($schema['routes']['index'] ?? true) === false
        && ($schema['routes']['destroy'] ?? true) === false
        && ($schema['fields']['store'] ?? []) === ['name', 'kind', 'surcharge'];
});

check('quickCreateValues dà un testo da 100 caratteri e attivo, col sovrapprezzo scritto', function () {
    $valori = CustomizationResource::quickCreateValues(['name' => ' Incisione ', 'surcharge' => '2,5', 'kind' => 'choice']);

    return $valori['name'] === 'Incisione'
        && $valori['label'] === 'Incisione'
        && $valori['kind'] === 'text'
        && (int) $valori['max_length'] === 100
        && $valori['surcharge'] === '2.50'
        && $valori['active'] === 'true';
});

check('quickCreateValues con tipo Numero scrive un numero senza decimali, senza tipo o con uno ignoto un testo', function () {
    $numero = CustomizationResource::quickCreateValues(['name' => 'X', 'kind' => 'number']);
    $ignoto = CustomizationResource::quickCreateValues(['name' => 'X', 'kind' => 'zzz']);

    return $numero['kind'] === 'number'
        && (int) $numero['decimals'] === 0
        && $ignoto['kind'] === 'text'
        && (int) $ignoto['max_length'] === 100;
});

check('il modal offre Testo e Numero: una scelta ha bisogno delle sue opzioni e si fa dalla sua pagina', function () {
    $campi = CustomizationResource::quickCreateFields();
    $tipo = null;
    foreach ($campi as $campo) {
        if ((string) $campo->name === 'kind') {
            $tipo = $campo;
        }
    }

    return $tipo !== null
        && array_keys((array) ($tipo->get('options') ?? [])) === ['text', 'number'];
});

check('quickCreateValues senza sovrapprezzo è gratis', function () {
    return CustomizationResource::quickCreateValues(['name' => 'X'])['surcharge'] === '0.00'
        && CustomizationResource::quickCreateValues(['name' => 'X', 'surcharge' => ' '])['surcharge'] === '0.00';
});

check('quickCreateValues rifiuta un sovrapprezzo negativo o che non è un numero', function () {
    foreach (['-1', 'abc'] as $importo) {
        try {
            CustomizationResource::quickCreateValues(['name' => 'X', 'surcharge' => $importo]);

            return false;
        } catch (UserError $e) {
            if ($e->key() !== 'customization.surcharge') {
                return false;
            }
        }
    }

    return true;
});

check('il modal «Nuova personalizzazione» chiede nome, tipo e sovrapprezzo', function () {
    $campi = CustomizationResource::quickCreateFields();

    return array_map(static fn ($c) => (string) $c->name, $campi) === ['name', 'kind', 'surcharge'];
});

check('il sovrapprezzo si nasconde quando il tipo è scelta', function () {
    return CustomizationResource::getInput('surcharge')->conditionalAttributes() === [
        'data-hidden-when' => 'kind',
        'data-hidden-when-values' => 'choice',
    ];
});

check('una scelta si salva sempre senza sovrapprezzo, anche se ne arriva uno', function () {
    $_POST = ['options' => [['label' => 'Rosso', 'surcharge' => '0'], ['label' => 'Blu', 'surcharge' => '2']]];
    $valori = CustomizationResource::mutateRequestValues(['name' => 'Colore', 'kind' => 'choice', 'surcharge' => '5'], 'update', 'backend', ['id' => 3]);
    $_POST = [];

    return $valori['surcharge'] === '0.00';
});

check('l\'elenco mostra se la personalizzazione è usata e nasconde l\'elimina se lo è', function () {
    $colonne = [];

    foreach (CustomizationResource::tableSchema() as $colonna) {
        $colonne[] = $colonna;
    }

    $chiavi = array_map(static fn ($c) => (string) $c->name, $colonne);
    $posizione = array_search('usage', $chiavi, true);
    $schema = $posizione === false ? [] : ($colonne[$posizione]->toArray()['function'] ?? []);

    return $posizione !== false
        && $posizione < array_search('actions', $chiavi, true)
        && ($schema['name'] ?? '') === 'empty'
        && ($schema['tables'] ?? []) === [ProductModelCustomization::$table]
        && ($schema['column'] ?? '') === 'customization_id';
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

check('un numero si salva con i suoi decimali, senza caratteri e senza opzioni', function () {
    $_POST = ['options' => [['label' => 'Rosso', 'surcharge' => '0'], ['label' => 'Blu', 'surcharge' => '0']]];
    $valori = CustomizationResource::mutateRequestValues(
        ['name' => 'Larghezza', 'kind' => 'number', 'max_length' => '100', 'decimals' => '2', 'surcharge' => '1,50'],
        'update',
        'backend',
        ['id' => 3]
    );
    $dopo = $_POST['options'] ?? null;
    $_POST = [];

    return $dopo === []
        && $valori['decimals'] === 2
        && $valori['max_length'] === 100
        && $valori['surcharge'] === '1.50';
});

check('un testo o una scelta non portano decimali', function () {
    $_POST = [];
    $testo = CustomizationResource::mutateRequestValues(
        ['name' => 'Incisione', 'kind' => 'text', 'max_length' => '20', 'decimals' => '3', 'surcharge' => '0'],
        'update',
        'backend',
        ['id' => 3]
    );

    return $testo['decimals'] === 0;
});

check('un numero con decimali sbagliati non si salva', function () {
    $_POST = [];

    try {
        CustomizationResource::mutateRequestValues(['name' => 'L', 'kind' => 'number', 'decimals' => '9', 'surcharge' => '0'], 'update', 'backend', ['id' => 3]);
    } catch (UserError $e) {
        return $e->key() === 'customization.decimals';
    }

    return false;
});

check('un numero con i decimali lasciati vuoti si salva con zero decimali', function () {
    $_POST = [];
    $valori = CustomizationResource::mutateRequestValues(
        ['name' => 'Pezzi', 'kind' => 'number', 'max_length' => '', 'decimals' => '', 'surcharge' => '0'],
        'update',
        'backend',
        ['id' => 3]
    );

    return $valori['decimals'] === 0;
});

check('quickCreateValues parte senza decimali', function () {
    return CustomizationResource::quickCreateValues(['name' => 'X'])['decimals'] === 0;
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
