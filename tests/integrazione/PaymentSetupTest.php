<?php
/** php tests/integrazione/PaymentSetupTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentAccount;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Resources\Payments\PaymentAccountResource;
use Wonder\Plugin\Gestionale\Resources\Payments\PaymentMethodResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
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

function nuovoMetodo(string $codice, int $conto = 0): int
{
    $riga = PaymentMethod::create([
        'code' => $codice.'-'.uniqid(),
        'name' => 'Metodo di prova',
        'provider' => 'manual',
        'timing' => 'deferred',
        'payment_account_id' => $conto,
    ]);

    return (int) ($riga->insert_id ?? 0);
}

/** Il rifiuto dell'eliminazione: la frase, oppure null se è consentita. */
function rifiuto(string $resource, int $id): ?string
{
    try {
        $resource::assertDeletable($id);
    } catch (RuntimeException $errore) {
        return $errore->getMessage();
    }

    return null;
}

check('un metodo con un ordine collegato non si elimina e dice quanti', function () {
    return prova(static function (): bool {
        $metodo = nuovoMetodo('con-ordine');
        $a = ordineDiProva(10.0);
        $b = ordineDiProva(20.0);
        Wonder\Plugin\Gestionale\Models\Sales\Order::update(['payment_method_id' => $metodo], $a);
        Wonder\Plugin\Gestionale\Models\Sales\Order::update(['payment_method_id' => $metodo], $b);

        $frase = rifiuto(PaymentMethodResource::class, $metodo);

        return $frase !== null && str_contains($frase, '(2)') && str_contains($frase, 'Non attivo');
    });
});

check('un metodo con un pagamento collegato non si elimina', function () {
    return prova(static function (): bool {
        $metodo = nuovoMetodo('con-pagamento');
        Payment::create([
            'code' => Wonder\Plugin\Gestionale\Support\Catalog\Code::make(Payment::class, Wonder\Plugin\Gestionale\Support\Codes::PAYMENT),
            'order_id' => ordineDiProva(10.0),
            'payment_method_id' => $metodo,
            'type' => 'payment',
            'status' => 'paid',
            'amount' => '10.00',
        ]);

        return rifiuto(PaymentMethodResource::class, $metodo) !== null;
    });
});

check('un metodo mai usato si elimina', fn () =>
    prova(static fn (): bool => rifiuto(PaymentMethodResource::class, nuovoMetodo('libero')) === null)
);

check('un metodo usato si può spegnere', function () {
    return prova(static function (): bool {
        $metodo = nuovoMetodo('da-spegnere');
        $ordine = ordineDiProva(10.0);
        Wonder\Plugin\Gestionale\Models\Sales\Order::update(['payment_method_id' => $metodo], $ordine);

        $valori = PaymentMethodResource::mutateRequestValues(['active' => 'false'], 'update');
        PaymentMethod::update($valori, $metodo);

        return (string) PaymentMethod::findById($metodo)['active'] === 'false';
    });
});

check('un conto con metodi collegati non si elimina, uno libero sì', function () {
    return prova(static function (): bool {
        $conto = (int) (PaymentAccount::create(['code' => 'conto-'.uniqid(), 'name' => 'Conto di prova'])->insert_id ?? 0);
        $libero = (int) (PaymentAccount::create(['code' => 'libero-'.uniqid(), 'name' => 'Conto libero'])->insert_id ?? 0);
        nuovoMetodo('sul-conto', $conto);

        $frase = rifiuto(PaymentAccountResource::class, $conto);

        return $frase !== null && str_contains($frase, '(1)') && rifiuto(PaymentAccountResource::class, $libero) === null;
    });
});

check('il salvataggio normalizza l\'IBAN e rifiuta quello malformato', function () {
    $ok = PaymentAccountResource::mutateRequestValues(['iban' => 'it60 x054 2811 1010 0000 0123 456'], 'store');

    try {
        PaymentAccountResource::mutateRequestValues(['iban' => 'non è un iban'], 'update');
    } catch (UserError) {
        return ($ok['iban'] ?? '') === 'IT60X0542811101000000123456';
    }

    return false;
});

check('la posizione di un metodo nuovo la mette il backend, in fondo', function () {
    return prova(static function (): bool {
        $ultima = (int) (PaymentMethod::find(['deleted' => 'false'], 1, 'position', 'DESC')['position'] ?? 0);
        $valori = PaymentMethodResource::mutateRequestValues(['name' => 'Nuovo'], 'store');
        $modifica = PaymentMethodResource::mutateRequestValues(['name' => 'Nuovo', 'position' => 99], 'update');

        return (int) ($valori['position'] ?? 0) === $ultima + 1 && !isset($modifica['position']);
    });
});

summary();
