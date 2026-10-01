<?php
/** php tests/ContactAddressResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Contacts\ContactAddressResource;

$valido = ['street' => 'Via Roma', 'cap' => '20100', 'city' => 'Milano', 'country' => 'it'];

check('la scheda non ha un menu suo: è una pagina-form con i permessi di edit e update', function () {
    return ContactAddressResource::isFormPage() === true
        && str_contains(ContactAddressResource::path(), 'indirizzi');
});

check('clean tiene solo i campi noti, toglie gli spazi e mette il paese in maiuscolo', function () use ($valido) {
    $clean = ContactAddressResource::clean([...$valido, 'street' => '  Via Roma  ', 'id' => 99, 'contact_id' => 5, 'deleted' => 'true']);

    return $clean['street'] === 'Via Roma' && $clean['country'] === 'IT'
        && !array_key_exists('id', $clean) && !array_key_exists('contact_id', $clean) && !array_key_exists('deleted', $clean);
});

check('senza paese è IT; il predefinito è «true» solo se arriva «true»', function () use ($valido) {
    $senza = ContactAddressResource::clean([...$valido, 'country' => '']);
    $si = ContactAddressResource::clean([...$valido, 'is_default' => 'true']);
    $altro = ContactAddressResource::clean([...$valido, 'is_default' => 'on']);

    return $senza['country'] === 'IT' && $si['is_default'] === 'true' && $altro['is_default'] === 'false';
});

check('un indirizzo valido non ha problemi', fn () =>
    ContactAddressResource::problem(ContactAddressResource::clean($valido)) === null
);

check('mancano via, CAP o città: la frase dice quali', function () {
    $tutti = ContactAddressResource::problem(ContactAddressResource::clean([]));
    $solo = ContactAddressResource::problem(ContactAddressResource::clean(['street' => 'Via X', 'cap' => '1', 'city' => '']));

    return str_contains((string) $tutti, 'la via') && str_contains((string) $tutti, 'il CAP') && str_contains((string) $tutti, 'la città')
        && str_contains((string) $solo, 'la città') && !str_contains((string) $solo, 'la via');
});

check('il paese è di due lettere; un campo troppo lungo si rifiuta', function () use ($valido) {
    $paese = ContactAddressResource::problem(ContactAddressResource::clean([...$valido, 'country' => 'ITA']));
    $lungo = ContactAddressResource::problem(ContactAddressResource::clean([...$valido, 'street' => str_repeat('a', 151)]));

    return str_contains((string) $paese, 'due lettere') && str_contains((string) $lungo, 'troppo lungo');
});

check('il pulsante di modifica porta i dati dell\'indirizzo, escapati', function () {
    $html = ContactAddressResource::openButton('Modifica', 'btn-sm', ['id' => 7, 'label' => 'Casa "mia"', 'street' => 'Via <b>X</b>', 'is_default' => 'true']);

    return str_contains($html, 'data-bs-target="#'.ContactAddressResource::MODAL_ID.'"')
        && str_contains($html, '&quot;id&quot;:7') && str_contains($html, 'Casa \\&quot;mia\\&quot;') // le virgolette interne: prima il json, poi l'attributo
        && !str_contains($html, '<b>X</b>');
});

check('il pulsante di aggiunta non porta dati: la finestra si apre vuota', fn () =>
    str_contains(ContactAddressResource::openButton('Aggiungi', 'btn-sm'), 'data-wi-address="[]"')
);

check('le finestre: aggiungi/modifica con tutti i campi e conferma di eliminazione, ciascuna con la sua form', function () {
    $html = ContactAddressResource::modals(12);

    foreach (array_keys(ContactAddressResource::FIELDS) as $campo) {
        if (!str_contains($html, 'name="'.$campo.'"')) {
            return false;
        }
    }

    return str_contains($html, 'id="'.ContactAddressResource::MODAL_ID.'"') && str_contains($html, 'id="'.ContactAddressResource::DELETE_MODAL_ID.'"')
        && substr_count($html, '<form method="post"') === 2 && substr_count($html, 'name="contact_id" value="12"') === 2
        && str_contains($html, 'name="action" value="save"') && str_contains($html, 'name="action" value="delete"')
        && str_contains($html, 'name="is_default"') && !str_contains($html, ' required');
});

check('«Rendi predefinito» è una form che manda action=default', function () {
    $html = ContactAddressResource::defaultForm(12, 7, 'Rendi predefinito', 'btn-sm');

    return str_contains($html, 'name="action" value="default"') && str_contains($html, 'name="address_id" value="7"')
        && str_contains($html, 'name="contact_id" value="12"');
});

summary();
