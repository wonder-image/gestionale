<?php
/** php tests/PaymentModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentAccount;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Support\Codes;

$colonne = static function (string $model): array {
    $colonne = [];

    foreach ($model::tableSchema() as $column) {
        $colonne[(string) $column->name] = $column;
    }

    return $colonne;
};

$campo = static function (string $model, string $key): ?object {
    foreach ($model::dataSchema() as $field) {
        if ((string) $field->key === $key) {
            return $field;
        }
    }

    return null;
};

check('le tre tabelle dei pagamenti hanno il prefisso del gestionale', fn () =>
    Payment::$table === 'gst_payments'
    && PaymentMethod::$table === 'gst_payment_methods'
    && PaymentAccount::$table === 'gst_payment_accounts'
);

check('i pagamenti restano nel loro ambiente, metodi e conti viaggiano', function () {
    $metodi = PaymentMethod::syncSchema();
    $conti = PaymentAccount::syncSchema();

    return Payment::syncSchema() === null
        && $metodi !== null && $metodi->keepIds && $metodi->localOnly
        && $conti !== null && $conti->keepIds && $conti->localOnly;
});

check('il pagamento ha il suo prefisso, il metodo il codice parlante', function () use ($campo, $colonne) {
    return ($campo(Payment::class, 'code')?->getSchema('unique_code')['prefix'] ?? null) === Codes::PAYMENT
        && ($campo(PaymentMethod::class, 'code')?->getSchema('unique_code') ?? null) === null
        && $colonne(PaymentMethod::class)['code']->getSchema('unique') === true;
});

check('il pagamento tiene tutte le colonne di 4.8', function () use ($colonne) {
    $attese = [
        'code', 'type', 'order_id', 'subscription_id', 'customer_id',
        'payment_method_id', 'payment_account_id', 'amount', 'currency',
        'status', 'provider', 'provider_reference', 'due_date', 'paid_at', 'note',
    ];

    return array_diff($attese, array_keys($colonne(Payment::class))) === [];
});

check('gli enum del pagamento sono quelli della spec', fn () =>
    Payment::TYPES === ['payment', 'refund']
    && Payment::STATUSES === ['pending', 'paid', 'failed', 'cancelled']
    && Payment::PROVIDERS === ['stripe', 'paypal', 'nexi', 'manual']
);

check('la notifica doppia del gateway non passa due volte, il rimborso sì', function () {
    // Il tipo sta nella chiave perché certi gateway rimandano il riferimento
    // dell'incasso anche sul rimborso: quello non è una notifica ripetuta.
    $unico = Payment::tablePseudos()['uni_provider_reference']['unique'] ?? [];

    return $unico === ['provider', 'provider_reference', 'type'];
});

check('il metodo di pagamento tiene tutte le colonne di 4.8', function () use ($colonne) {
    $attese = [
        'code', 'name', 'sdi_code', 'provider', 'payment_account_id',
        'fee_type', 'fee_value', 'available_for', 'applies_online', 'applies_office',
        'applies_pos', 'instructions', 'stripe_payment_method_types', 'active', 'position',
    ];

    return array_diff($attese, array_keys($colonne(PaymentMethod::class))) === [];
});

check('il conto tiene banca e IBAN', function () use ($colonne) {
    $attese = ['code', 'name', 'bank_name', 'iban', 'bic', 'active'];

    return array_diff($attese, array_keys($colonne(PaymentAccount::class))) === [];
});

check('le colonne che valgono zero non hanno chiave esterna', function () use ($colonne) {
    // Un pagamento di un abbonamento non ha ordine, un pagamento al banco non
    // ha cliente, e un metodo può non avere un conto: sempre zero.
    foreach ([
        [Payment::class, 'order_id'], [Payment::class, 'subscription_id'],
        [Payment::class, 'customer_id'], [Payment::class, 'payment_method_id'],
        [Payment::class, 'payment_account_id'],
        [PaymentMethod::class, 'payment_account_id'],
    ] as [$modello, $nome]) {
        if (($colonne($modello)[$nome] ?? null)?->getSchema('foreign_table') !== null) {
            return false;
        }
    }

    return true;
});

check('gli importi tengono due decimali', function () use ($campo) {
    return (int) ($campo(Payment::class, 'amount')?->getSchema('decimals') ?? 0) === 2
        && (int) ($campo(PaymentMethod::class, 'fee_value')?->getSchema('decimals') ?? 0) === 2;
});

check('il pagamento si ritrova per ordine e per scadenza', function () {
    $pseudo = Payment::tablePseudos();

    return isset($pseudo['ind_order'], $pseudo['ind_due_date'], $pseudo['ind_status']);
});

summary();
