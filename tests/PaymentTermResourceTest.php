<?php
/** php tests/PaymentTermResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Payments\PaymentTerm;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentTermInstallment;
use Wonder\Plugin\Gestionale\Resources\Payments\PaymentTermResource;

/** Le colonne vere di un Model, senza quelle di sistema. */
$colonne = static function (string $model): array {
    $nomi = [];

    foreach ($model::tableSchema() as $colonna) {
        $nomi[] = (string) $colonna->name;
    }

    return $nomi;
};

$campo = static function (string $key): ?object {
    foreach (PaymentTermResource::formSchema() as $f) {
        if ((string) $f->name === $key) {
            return $f;
        }
    }

    return null;
};

check('la condizione ha il suo model e il suo indirizzo', fn () =>
    PaymentTermResource::$model === PaymentTerm::class
    && PaymentTermResource::path() === 'app/gestionale/condizioni-di-pagamento'
);

check('sta in Set Up, gruppo Pagamenti, solo per admin, sempre attiva e senza API', function () {
    $nav = PaymentTermResource::navigationSchema()->toArray();

    return ($nav['section_key'] ?? '') === 'set-up'
        && ($nav['group_key'] ?? '') === 'pagamenti'
        && ($nav['authority'] ?? []) === ['admin']
        && PaymentTermResource::$feature === ''
        && (bool) (PaymentTermResource::apiSchema()->toArray()['enabled'] ?? true) === false;
});

check('ogni campo del form è una colonna, tranne le rate che hanno la loro tabella', function () use ($colonne) {
    $vere = $colonne(PaymentTerm::class);

    foreach (PaymentTermResource::formSchema() as $f) {
        $nome = (string) $f->name;

        if ($nome !== 'installments' && !in_array($nome, $vere, true)) {
            echo "    campo fantasma {$nome}\n";

            return false;
        }
    }

    return true;
});

check('le colonne del repeater sono colonne della rata', function () use ($colonne) {
    $vere = $colonne(PaymentTermInstallment::class);

    foreach (['days', 'end_of_month', 'percentage', 'position', 'payment_term_id'] as $nome) {
        if (!in_array($nome, $vere, true)) {
            return false;
        }
    }

    return true;
});

check('la condizione SDI offre le chiavi del Model', function () use ($campo) {
    $opzioni = array_keys((array) ($campo('sdi_condition')?->get('options') ?? []));
    sort($opzioni);

    return $opzioni === PaymentTerm::SDI_CONDITIONS;
});

check('la rata si legge come la si direbbe a voce', fn () =>
    PaymentTermResource::installmentLabel(['days' => 0, 'end_of_month' => 'false', 'percentage' => '100.00']) === 'subito 100%'
    && PaymentTermResource::installmentLabel(['days' => 30, 'end_of_month' => 'true', 'percentage' => '50.00']) === '30 gg fine mese 50%'
    && PaymentTermResource::installmentLabel(['days' => 60, 'end_of_month' => 'false', 'percentage' => '33.33']) === '60 gg 33,33%'
);

check('le righe vuote non sono rate, le altre si normalizzano', function () {
    $righe = PaymentTermResource::prepareRepeaterRows('installments', [
        ['id' => '', 'days' => '', 'end_of_month' => 'false', 'percentage' => ''],
        ['id' => '4', 'days' => ' 30 ', 'end_of_month' => 'true', 'percentage' => '33,5'],
        ['id' => '', 'days' => '60', 'end_of_month' => 'boh', 'percentage' => '66.5'],
    ]);

    return count($righe) === 2
        && $righe[0]['days'] === 30 && $righe[0]['percentage'] === '33.5' && $righe[0]['end_of_month'] === 'true' && $righe[0]['id'] === '4'
        && $righe[1]['end_of_month'] === 'false';
});

check('gli altri repeater passano come sono', fn () =>
    PaymentTermResource::prepareRepeaterRows('altro', [['x' => '']]) === [['x' => '']]
);

check('una condizione senza id non ha usi', fn () => PaymentTermResource::usage(0) === 0);

check('il codice lo crea il sistema: non è un campo del form', fn () => $campo('code') === null);

summary();
