<?php
/** php tests/PaymentMethodResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Payments\PaymentAccount;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Resources\Payments\PaymentAccountResource;
use Wonder\Plugin\Gestionale\Resources\Payments\PaymentMethodResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

$risorse = [
    PaymentMethodResource::class => PaymentMethod::class,
    PaymentAccountResource::class => PaymentAccount::class,
];

/** Le chiavi del form di una Resource. */
$campi = static function (string $resource): array {
    return array_values(array_map(static fn (object $f): string => (string) $f->name, $resource::formSchema()));
};

/** Le colonne vere di un Model, senza quelle di sistema. */
$colonne = static function (string $model): array {
    $nomi = [];

    foreach ($model::tableSchema() as $colonna) {
        $nomi[] = (string) $colonna->name;
    }

    return $nomi;
};

$opzioni = static function (string $resource, string $key): array {
    foreach ($resource::formSchema() as $campo) {
        if ((string) $campo->name === $key) {
            return array_keys((array) ($campo->get('options') ?? []));
        }
    }

    return [];
};

check('metodi e conti hanno il loro model e il loro indirizzo', fn () =>
    PaymentMethodResource::$model === PaymentMethod::class
    && PaymentAccountResource::$model === PaymentAccount::class
    && PaymentMethodResource::path() === 'app/gestionale/metodi-di-pagamento'
    && PaymentAccountResource::path() === 'app/gestionale/conti-di-pagamento'
);

check('stanno in Set Up, gruppo Pagamenti, solo per admin', function () use ($risorse) {
    foreach (array_keys($risorse) as $resource) {
        $nav = $resource::navigationSchema()->toArray();

        if (($nav['section_key'] ?? '') !== 'set-up' || ($nav['group_key'] ?? '') !== 'pagamenti' || ($nav['authority'] ?? []) !== ['admin']) {
            return false;
        }

        foreach ($resource::permissionSchema()->toArray()['backend'] ?? [] as $autorita) {
            if ($autorita !== [] && $autorita !== ['admin']) {
                return false;
            }
        }
    }

    return true;
});

check('sono la configurazione: sempre attive e senza API', function () use ($risorse) {
    foreach (array_keys($risorse) as $resource) {
        if ($resource::$feature !== '' || (bool) ($resource::apiSchema()->toArray()['enabled'] ?? true) !== false) {
            return false;
        }
    }

    return true;
});

check('ogni campo del form è una colonna del Model: nessun campo fantasma', function () use ($risorse, $campi, $colonne) {
    foreach ($risorse as $resource => $model) {
        $vere = $colonne($model);

        foreach ($campi($resource) as $campo) {
            if (!in_array($campo, $vere, true)) {
                echo "    {$resource}: campo fantasma {$campo}\n";

                return false;
            }
        }
    }

    return true;
});

check('ogni colonna ha il suo campo, tranne il codice e la posizione, che mette il backend, e i tipi di Stripe', function () use ($risorse, $campi, $colonne) {
    $esenti = ['id', 'creation', 'last_modified', 'deleted', 'position', 'code', 'stripe_payment_method_types'];

    foreach ($risorse as $resource => $model) {
        foreach ($colonne($model) as $colonna) {
            if (!in_array($colonna, $esenti, true) && !in_array($colonna, $campi($resource), true)) {
                echo "    {$resource}: colonna senza campo {$colonna}\n";

                return false;
            }
        }
    }

    return true;
});

check('il tipo e il «quando» offrono le chiavi del Model', fn () =>
    $opzioni(PaymentMethodResource::class, 'provider') === PaymentMethod::PROVIDERS
    && $opzioni(PaymentMethodResource::class, 'timing') === PaymentMethod::TIMINGS
    && $opzioni(PaymentMethodResource::class, 'fee_type') === PaymentMethod::FEE_TYPES
    && $opzioni(PaymentMethodResource::class, 'available_for') === PaymentMethod::AVAILABLE_FOR
);

check('il codice lo crea il sistema: non è un campo del form', fn () =>
    !in_array('code', $campi(PaymentMethodResource::class), true) && !in_array('code', $campi(PaymentAccountResource::class), true)
);

check('il conto ha l\'intestatario', fn () => in_array('holder', $campi(PaymentAccountResource::class), true));

check('l\'IBAN si normalizza: spazi tolti, maiuscole', fn () =>
    PaymentAccountResource::normalizeIban(' it60 x054 2811 1010 0000 0123 456 ') === 'IT60X0542811101000000123456'
);

check('l\'IBAN malformato si rifiuta, quello vuoto no', function () {
    foreach (['IT60', 'IT60-X054-2811-1010-0000-0123-456', str_repeat('A', 35)] as $iban) {
        try {
            PaymentAccountResource::normalizeIban($iban);

            return false;
        } catch (UserError) {
            // atteso
        }
    }

    return PaymentAccountResource::normalizeIban('') === '';
});

check('il form offre i canali solo se ce n\'è più di uno acceso, e i metodi nuovi partono dal sito', function () {
    $nomi = [];
    $scendi = function (array $componenti) use (&$scendi, &$nomi): void {
        foreach ($componenti as $c) {
            if (isset($c->name)) {
                $nomi[] = (string) $c->name;
            }

            if (isset($c->components) && is_array($c->components)) {
                $scendi($c->components);
            }
        }
    };
    $scendi(PaymentMethodResource::formLayoutSchema()->components);

    $valori = PaymentMethodResource::mutateRequestValues(['name' => 'Bonifico'], 'store');

    return !in_array('applies_online', $nomi, true) && !in_array('applies_office', $nomi, true) && !in_array('applies_pos', $nomi, true)
        && in_array('available_for', $nomi, true)
        && $valori['applies_online'] === 'true' && $valori['applies_office'] === 'false' && $valori['applies_pos'] === 'false';
});

check('salvando un metodo, i canali che il form non mostra restano com\'erano', function () {
    $vecchio = ['applies_online' => 'true', 'applies_office' => 'true', 'applies_pos' => 'false'];
    $valori = PaymentMethodResource::mutateRequestValues(['name' => 'Bonifico'], 'update', 'backend', $vecchio);

    return $valori['applies_office'] === 'true' && $valori['applies_pos'] === 'false' && $valori['applies_online'] === 'true'
        && !array_key_exists('position', $valori);
});

check('il codice della fattura si sceglie da un elenco: «Codice - Nome»', function () use ($opzioni) {
    $codici = PaymentMethodResource::sdiCodes();

    return $opzioni(PaymentMethodResource::class, 'sdi_code') === array_keys($codici)
        && ($codici['MP05'] ?? '') === 'MP05 - '.\Wonder\Plugin\Custom\Fattura\Valori\Pagamento::Valori['MP05']
        && ($codici['MP01'] ?? '') === 'MP01 - '.\Wonder\Plugin\Custom\Fattura\Valori\Pagamento::Valori['MP01']
        && ($codici[''] ?? null) !== null;
});

check('bonifico e contanti sono due tipi, e il tipo manuale non si offre più', fn () =>
    array_keys(PaymentMethodResource::providers()) === PaymentMethod::PROVIDERS
    && in_array('bank_transfer', PaymentMethod::PROVIDERS, true)
    && in_array('cash', PaymentMethod::PROVIDERS, true)
    && !in_array('manual', PaymentMethod::PROVIDERS, true)
);

check('la commissione ha le sue cifre solo quando il tipo le usa', function () {
    $salva = static fn (string $tipo): array => PaymentMethodResource::mutateRequestValues(
        ['name' => 'X', 'fee_type' => $tipo, 'fee_value' => '1.50', 'fee_percent' => '2.00'],
        'update'
    );

    $nessuna = $salva('none');
    $fisso = $salva('amount');
    $percento = $salva('percent');
    $entrambe = $salva('amount_percent');

    return $nessuna['fee_value'] === '0' && $nessuna['fee_percent'] === '0'
        && $fisso['fee_value'] === '1.50' && $fisso['fee_percent'] === '0'
        && $percento['fee_value'] === '0' && $percento['fee_percent'] === '2.00'
        && $entrambe['fee_value'] === '1.50' && $entrambe['fee_percent'] === '2.00';
});

check('il conto serve solo al bonifico', function () {
    $bonifico = PaymentMethodResource::mutateRequestValues(['name' => 'X', 'provider' => 'bank_transfer', 'payment_account_id' => '3'], 'update');
    $contanti = PaymentMethodResource::mutateRequestValues(['name' => 'X', 'provider' => 'cash', 'payment_account_id' => '3'], 'update');

    return $bonifico['payment_account_id'] === '3' && $contanti['payment_account_id'] === '0';
});

check('il tipo di pagamento dice come si scrive nel registro dei pagamenti', fn () =>
    PaymentMethod::ledgerProvider('bank_transfer') === 'manual'
    && PaymentMethod::ledgerProvider('cash') === 'manual'
    && PaymentMethod::ledgerProvider('manual') === 'manual'
    && PaymentMethod::ledgerProvider('stripe') === 'stripe'
    && PaymentMethod::ledgerProvider('paypal') === 'paypal'
    && PaymentMethod::ledgerProvider('nexi') === 'nexi'
);

summary();
