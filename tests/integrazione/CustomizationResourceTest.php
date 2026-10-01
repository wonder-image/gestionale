<?php
/** php tests/integrazione/CustomizationResourceTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationOption;
use Wonder\Plugin\Gestionale\Resources\Catalog\CustomizationResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Sql\Transaction;

/** Serve a buttare via tutto quello che la prova scrive. */
final class Annulla extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
        // Voluto: i dati della prova non restano.
    }

    return $esito;
}

/**
 * Il salvataggio dalla pagina del backend: lo stesso percorso del controller
 * (preparazione dei valori, scrittura, opzioni del repeater). Ridà l'id.
 *
 * @param array<string, mixed>        $valori
 * @param list<array<string, mixed>>  $opzioni
 */
function salva(array $valori, array $opzioni, ?int $id = null): int
{
    $_POST = $valori + ['options' => $opzioni];
    $vecchi = $id !== null ? Customization::find(['id' => $id], 1) : null;
    $preparati = CustomizationResource::mutateRequestValues($valori, $id === null ? 'store' : 'update', 'backend', $vecchi);

    if ($id === null) {
        $id = (int) (Customization::query()->Insert(Customization::$table, $preparati)->insert_id ?? 0);
        CustomizationResource::syncRepeaterRelations($id, $_POST, [], 'store', 'backend');
    } else {
        Customization::query()->Update(Customization::$table, $preparati, 'id', $id);
        CustomizationResource::syncRepeaterRelations($id, $_POST, [], 'update', 'backend');
    }

    $_POST = [];

    return $id;
}

/** Il tipo di errore con cui una funzione si ferma, '' se non si ferma. */
function errore(callable $fai): string
{
    try {
        $fai();
    } catch (UserError $e) {
        return $e->key();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }

    return '';
}

/** Le opzioni di una personalizzazione, anche quelle nel cestino, nell'ordine. @return list<array<string, mixed>> */
function opzioniDi(int $id): array
{
    $righe = CustomizationOption::find(['customization_id' => $id, 'deleted' => ['true', 'false']], null, 'position', 'ASC');

    if (!is_array($righe) || $righe === []) {
        return [];
    }

    return isset($righe['id']) ? [$righe] : array_values(array_filter($righe, 'is_array'));
}

$scelta = ['name' => 'Colore filo', 'label' => 'Colore', 'help_text' => '', 'kind' => 'choice', 'max_length' => '', 'surcharge' => '1.00', 'active' => 'true'];
$testo = ['name' => 'Incisione', 'label' => 'Incisione', 'help_text' => '', 'kind' => 'text', 'max_length' => '20', 'surcharge' => '5.00', 'active' => 'true'];
$rosso = ['label' => 'Rosso', 'surcharge' => '0'];
$oro = ['label' => 'Oro', 'surcharge' => '2.50'];

check('una scelta con due opzioni le scrive nell\'ordine', function () use ($scelta, $rosso, $oro) {
    return prova(static function () use ($scelta, $rosso, $oro): bool {
        $id = salva($scelta, [$oro, $rosso]);
        $righe = opzioniDi($id);

        return $id > 0
            && array_column($righe, 'label') === ['Oro', 'Rosso']
            && (string) $righe[0]['surcharge'] === '2.50';
    });
});

check('salvando con una riga sola l\'altra sparisce davvero', function () use ($scelta, $rosso, $oro) {
    return prova(static function () use ($scelta, $rosso, $oro): bool {
        $id = salva($scelta, [$rosso, $oro]);
        $righe = opzioniDi($id);
        // Il form rimanda l'id delle righe che restano.
        salva($scelta, [['id' => $righe[1]['id']] + $oro, ['label' => 'Blu', 'surcharge' => '0']], $id);
        $dopo = opzioniDi($id);

        return array_column($dopo, 'label') === ['Oro', 'Blu']
            && array_unique(array_column($dopo, 'deleted')) === ['false']
            && (int) $dopo[0]['id'] === (int) $righe[1]['id'];
    });
});

check('una scelta con un\'opzione sola dà few_options e non scrive niente', function () use ($scelta, $rosso) {
    return prova(static function () use ($scelta, $rosso): bool {
        $prima = count(Customization::all());
        $chiave = errore(static fn () => salva($scelta, [$rosso]));

        return $chiave === 'customization.few_options' && count(Customization::all()) === $prima;
    });
});

check('le opzioni senza etichetta non contano', function () use ($scelta, $rosso) {
    return prova(static function () use ($scelta, $rosso): bool {
        return errore(static fn () => salva($scelta, [$rosso, ['label' => '', 'surcharge' => '4']])) === 'customization.few_options';
    });
});

check('un testo con caratteri massimi 0 o 1001 dà max_length', function () use ($testo) {
    return prova(static function () use ($testo): bool {
        return errore(static fn () => salva(['max_length' => '0'] + $testo, [])) === 'customization.max_length'
            && errore(static fn () => salva(['max_length' => '1001'] + $testo, [])) === 'customization.max_length'
            && errore(static fn () => salva(['max_length' => '1000'] + $testo, [])) === '';
    });
});

check('un sovrapprezzo negativo dà surcharge, sulla personalizzazione e sull\'opzione', function () use ($testo, $scelta, $rosso) {
    return prova(static function () use ($testo, $scelta, $rosso): bool {
        return errore(static fn () => salva(['surcharge' => '-1'] + $testo, [])) === 'customization.surcharge'
            && errore(static fn () => salva($scelta, [$rosso, ['label' => 'Blu', 'surcharge' => '-1']])) === 'customization.surcharge';
    });
});

check('passando da scelta a testo le opzioni se ne vanno', function () use ($scelta, $testo, $rosso, $oro) {
    return prova(static function () use ($scelta, $testo, $rosso, $oro): bool {
        $id = salva($scelta, [$rosso, $oro]);
        salva($testo, [$rosso, $oro], $id);

        return opzioniDi($id) === [];
    });
});

check('eliminare una personalizzazione su un articolo dice quanti la usano', function () use ($testo) {
    return prova(static function () use ($testo): bool {
        $id = salva($testo, []);
        collegaPersonalizzazione(modelloDi(articoloConGiacenza(1, 'PZ-R-'.uniqid())), $id);
        $messaggio = errore(static fn () => CustomizationResource::deleteRecord($id));

        return str_contains($messaggio, '1') && is_array(Customization::find(['id' => $id], 1))
            && CustomizationResource::usage($id) === 1;
    });
});

check('una mai usata sparisce con le sue opzioni, senza errori di chiave', function () use ($scelta, $rosso, $oro) {
    return prova(static function () use ($scelta, $rosso, $oro): bool {
        $id = salva($scelta, [$rosso, $oro]);
        $chiave = errore(static fn () => CustomizationResource::deleteRecord($id));
        $riga = Customization::find(['id' => $id, 'deleted' => ['true', 'false']], 1);

        return $chiave === '' && (!is_array($riga) || $riga === []) && opzioniDi($id) === [];
    });
});

check('lo store API non crea opzioni nemmeno se la richiesta le porta', function () {
    return prova(static function (): bool {
        $preparati = CustomizationResource::mutateRequestValues(['name' => 'Via API'], 'store', 'api');
        $id = (int) (Customization::query()->Insert(Customization::$table, $preparati)->insert_id ?? 0);
        CustomizationResource::syncRepeaterRelations($id, ['options' => [['label' => 'x', 'surcharge' => '1']]], [], 'store', 'api');
        $riga = Customization::find(['id' => $id], 1);

        return $id > 0
            && opzioniDi($id) === []
            && $riga['kind'] === 'text'
            && (int) $riga['max_length'] === 100;
    });
});

summary();
