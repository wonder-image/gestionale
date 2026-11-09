<?php
/** php tests/ContactModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Contacts\Company;
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
    // `AddressExtension::billing()`: privato sulle persone, azienda sulle aziende.
    $persona = $colonne(Contact::class);
    $azienda = $colonne(Company::class);
    $comuni = ['cf', 'country', 'province', 'city', 'cap', 'street', 'number', 'phone_prefix', 'phone'];

    foreach ([...$comuni, 'name', 'surname'] as $nome) {
        if (!isset($persona[$nome])) {
            return false;
        }
    }

    foreach ([...$comuni, 'business_name', 'pi', 'sdi', 'pec'] as $nome) {
        if (!isset($azienda[$nome])) {
            return false;
        }
    }

    // Niente più Privato/Società sulla persona.
    return !isset($persona['type']) && !isset($persona['business_name']) && !isset($persona['pi']);
});

check('le persone sono clienti, le aziende anche fornitori', function () use ($colonne) {
    $persona = $colonne(Contact::class);
    $azienda = $colonne(Company::class);

    return isset($persona['is_customer']) && !isset($persona['is_supplier'])
        && isset($azienda['is_customer'], $azienda['is_supplier']);
});

check('partita IVA e codice fiscale li controlla il core', function () use ($campo) {
    // Non sono caselle di testo: il framework ha un campo apposta per
    // ognuno, e rifiuta da sé i codici sbagliati.
    return $campo(Company::class, 'pi') instanceof \Wonder\Data\Fields\Vat
        && $campo(Company::class, 'cf') instanceof \Wonder\Data\Fields\Tin
        && $campo(Contact::class, 'cf') instanceof \Wonder\Data\Fields\Tin;
});

check('listino e pagamento nascono come colonne, senza chiave esterna', function () use ($colonne) {
    // Le tabelle non esistono ancora: una chiave esterna non avrebbe dove
    // puntare, e lo zero la farebbe fallire comunque.
    $c = $colonne(Contact::class);
    $a = $colonne(Company::class);

    foreach (['user_id', 'price_list_id', 'payment_method_id', 'payment_term_id'] as $nome) {
        if (!isset($c[$nome]) || $c[$nome]->getSchema('foreign_table') !== null) {
            return false;
        }
    }

    // Con un'azienda nel documento valgono le sue condizioni.
    foreach (['price_list_id', 'payment_method_id', 'payment_term_id', 'vat_collectability'] as $nome) {
        if (!isset($a[$nome]) || $a[$nome]->getSchema('foreign_table') !== null) {
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
