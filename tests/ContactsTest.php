<?php
/** php tests/ContactsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;

check('un\'azienda si chiama con la sua ragione sociale', fn () =>
    Contacts::displayName([
        'type' => 'business',
        'business_name' => 'Rossi Srl',
        'name' => 'Mario',
        'surname' => 'Rossi',
    ]) === 'Rossi Srl'
);

check('un privato si chiama con cognome e nome', fn () =>
    Contacts::displayName([
        'type' => 'private',
        'name' => 'Mario',
        'surname' => 'Rossi',
    ]) === 'Rossi Mario'
);

check('un\'azienda senza ragione sociale non resta senza nome', fn () =>
    // Capita importando: meglio il referente che una riga vuota.
    Contacts::displayName([
        'type' => 'business',
        'business_name' => '',
        'name' => 'Mario',
        'surname' => 'Rossi',
    ]) === 'Rossi Mario'
);

check('senza nomi resta l\'email', fn () =>
    Contacts::displayName(['type' => 'private', 'email' => 'mario@example.com'])
        === 'mario@example.com'
);

check('senza niente non torna una stringa vuota', fn () =>
    Contacts::displayName([]) !== ''
);

check('i ruoli si leggono in italiano', fn () =>
    Contacts::roles(['is_customer' => 'true', 'is_supplier' => 'false']) === 'Cliente'
    && Contacts::roles(['is_customer' => 'false', 'is_supplier' => 'true']) === 'Fornitore'
    && Contacts::roles(['is_customer' => 'true', 'is_supplier' => 'true']) === 'Cliente e fornitore'
    && Contacts::roles(['is_customer' => 'false', 'is_supplier' => 'false']) === '—'
);

check('le risposte alla domanda sul ruolo sono tre', fn () =>
    array_keys(Contacts::ROLE_CHOICES) === ['customer', 'supplier', 'both']
);

check('una scheda salvata si descrive con una risposta sola', fn () =>
    Contacts::roleChoice(['is_customer' => 'true', 'is_supplier' => 'false']) === 'customer'
    && Contacts::roleChoice(['is_customer' => 'false', 'is_supplier' => 'true']) === 'supplier'
    && Contacts::roleChoice(['is_customer' => 'true', 'is_supplier' => 'true']) === 'both'
    && Contacts::roleChoice([]) === ''
);

check('la risposta accende gli interruttori giusti, e spegne gli altri', fn () =>
    Contacts::rolesFromChoice('customer') === ['is_customer' => 'true', 'is_supplier' => 'false']
    && Contacts::rolesFromChoice('supplier') === ['is_customer' => 'false', 'is_supplier' => 'true']
    && Contacts::rolesFromChoice('both') === ['is_customer' => 'true', 'is_supplier' => 'true']
);

check('una risposta che non esiste non accende niente', fn () =>
    Contacts::rolesFromChoice('') === [] && Contacts::rolesFromChoice('capo') === []
);

check('il tipo azienda si riconosce', fn () =>
    Contacts::isCompany(['type' => 'business']) === true
    && Contacts::isCompany(['type' => 'private']) === false
    && Contacts::isCompany([]) === false
);

check('senza database un duplicato non si inventa', fn () =>
    // I test degli schemi girano senza database: la ricerca deve tornare
    // vuota, non esplodere.
    Contacts::duplicateOf('pi', 'IT12345678901', null) === []
);

check('un valore vuoto non è mai un duplicato', fn () =>
    Contacts::duplicateOf('pi', '', null) === []
    && Contacts::duplicateOf('pi', '   ', 7) === []
);

summary();
