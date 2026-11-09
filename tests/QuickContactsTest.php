<?php
/** php tests/QuickContactsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Contacts\QuickContacts;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgentUsers;

// Solo i controlli che si fermano prima del database.
check('l\'etichetta è come nelle tendine dei legami', function () {
    return QuickContacts::label(['name' => 'Mario', 'surname' => 'Rossi', 'email' => 'm@example.com']) === 'Mario Rossi · m@example.com'
        && QuickContacts::label(['business_name' => 'Wonder &amp; Co', 'name' => 'X']) === 'Wonder & Co'
        && QuickContacts::label(['id' => 7]) === '#7';
});

check('il tipo sconosciuto non crea niente', function () {
    $r = QuickContacts::create(['type' => 'altro']);

    return $r['ok'] === false && $r['id'] === 0;
});

check('il referente vuole nome o cognome ed email valida', function () {
    $vuoto = QuickContacts::person(['email' => 'a@example.com']);
    $email = QuickContacts::person(['name' => 'Mario', 'email' => 'non-email']);

    return $vuoto['ok'] === false && str_contains($vuoto['message'], 'nome')
        && $email['ok'] === false && str_contains($email['message'], 'email');
});

check('l\'azienda vuole la ragione sociale', function () {
    $r = QuickContacts::company(['pi' => 'IT01234567890']);

    return $r['ok'] === false && str_contains($r['message'], 'ragione sociale');
});

$errore = static function (callable $fn): string {
    try {
        $fn();
    } catch (UserError $e) {
        return $e->key();
    }

    return '';
};

check('il commerciale nuovo vuole nome ed email valida', function () use ($errore) {
    return $errore(fn () => SalesAgentUsers::create('', 'Rossi', 'm@example.com')) === 'sales_agent.user_fields'
        && $errore(fn () => SalesAgentUsers::create('Mario', '', '')) === 'sales_agent.user_fields'
        && $errore(fn () => SalesAgentUsers::create('Mario', '', 'non-email')) === 'sales_agent.user_email';
});

summary();
