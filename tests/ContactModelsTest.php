<?php
/** php tests/ContactModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress;
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

check('le due tabelle condivise usano i nomi del core', fn () =>
    Contact::$table === 'contacts'
    && ContactAddress::$table === 'contact_addresses'
);

check('la rubrica non viaggia con il deploy', fn () =>
    Contact::syncSchema() === null && ContactAddress::syncSchema() === null
);

check('la scheda ha il suo prefisso nel codice', function () use ($campo) {
    return ($campo(Contact::class, 'code')?->getSchema('unique_code')['prefix'] ?? null)
        === Codes::CONTACT;
});

check('i dati di fatturazione arrivano dal core, non riscritti qui', function () use ($colonne) {
    // `AddressExtension::billing()`: tipo, ragione sociale, CF e partita IVA
    // già validati, SDI, PEC, indirizzo e telefono.
    $c = $colonne(Contact::class);

    foreach ([
        'type', 'name', 'surname', 'business_name', 'cf', 'pi', 'sdi', 'pec',
        'country', 'province', 'city', 'cap', 'street', 'number',
        'phone_prefix', 'phone',
    ] as $nome) {
        if (!isset($c[$nome])) {
            return false;
        }
    }

    return true;
});

check('i due ruoli stanno sulla stessa scheda', function () use ($colonne) {
    $c = $colonne(Contact::class);

    return isset($c['is_customer'], $c['is_supplier']);
});

check('partita IVA e codice fiscale li controlla il core', function () use ($campo) {
    // Non sono caselle di testo: il framework ha un campo apposta per
    // ognuno, e rifiuta da sé i codici sbagliati.
    return $campo(Contact::class, 'pi') instanceof \Wonder\Data\Fields\Vat
        && $campo(Contact::class, 'cf') instanceof \Wonder\Data\Fields\Tin;
});

check('listino e pagamento nascono come colonne, senza chiave esterna', function () use ($colonne) {
    // Le tabelle non esistono ancora: una chiave esterna non avrebbe dove
    // puntare, e lo zero la farebbe fallire comunque.
    $c = $colonne(Contact::class);

    foreach (['user_id', 'price_list_id', 'payment_method_id', 'payment_term_id'] as $nome) {
        if (!isset($c[$nome]) || $c[$nome]->getSchema('foreign_table') !== null) {
            return false;
        }
    }

    return true;
});

check('l\'indirizzo di consegna sa a chi appartiene', function () use ($colonne) {
    $c = $colonne(ContactAddress::class);

    return ($c['contact_id'] ?? null)?->getSchema('foreign_table') === 'contacts';
});

check('un indirizzo di consegna ha destinatario, telefono ed etichetta', function () use ($colonne) {
    $c = $colonne(ContactAddress::class);

    foreach (['label', 'name', 'surname', 'phone', 'is_default', 'position'] as $nome) {
        if (!isset($c[$nome])) {
            return false;
        }
    }

    return true;
});

check('nessuna colonna usa una parola riservata di MySQL', function () use ($colonne) {
    $riservate = ['key', 'group', 'order', 'index', 'default'];

    foreach ([Contact::class, ContactAddress::class] as $model) {
        foreach (array_keys($colonne($model)) as $nome) {
            if (in_array(strtolower((string) $nome), $riservate, true)) {
                return false;
            }
        }
    }

    return true;
});

summary();
