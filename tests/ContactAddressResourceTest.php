<?php
/** php tests/ContactAddressResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\Contacts\ContactAddressResource;
use Wonder\Plugin\Gestionale\Support\Contacts\VisitorCountry;

// Fuori dall'app le funzioni dei paesi non esistono: ne bastano di minime perché i select si costruiscano.
if (!function_exists('countries')) {
    function countries(): array { return ['IT' => 'Italia', 'FR' => 'France']; }
}
if (!function_exists('states')) {
    function states(string $country): array { return $country === 'IT' ? ['BG' => 'Bergamo', 'MI' => 'Milano'] : []; }
}
if (!function_exists('phonePrefix')) {
    function phonePrefix(): array { return ['+39' => '+39', '+33' => '+33']; }
}

if (!function_exists('countryPhonePrefix')) {
    function countryPhonePrefix($iso2): string { return ['IT' => '39', 'ES' => '+34'][strtoupper($iso2)] ?? ''; }
}

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

check('il prefisso ha sempre il «+», come l\'elenco dei prefissi: i dati vecchi lo perdono', function () use ($valido) {
    return ContactAddressResource::clean([...$valido, 'phone_prefix' => '39'])['phone_prefix'] === '+39'
        && ContactAddressResource::clean([...$valido, 'phone_prefix' => '+39'])['phone_prefix'] === '+39'
        && ContactAddressResource::clean($valido)['phone_prefix'] === '';
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

check('la finestra: aggiungi/modifica con tutti i campi, in una form sola', function () {
    $html = ContactAddressResource::modals(12);

    // Paese, provincia e prefisso sono i select del core, non campi di testo.
    $select = ['country', 'province', 'phone_prefix'];

    foreach (ContactAddressResource::FIELDS as $campo => $lunghezza) {
        $trovato = in_array($campo, $select, true)
            ? preg_match('/<select(?=[^>]*name="'.$campo.'")[^>]*>/', $html) === 1 && !preg_match('/<input(?=[^>]*name="'.$campo.'")[^>]*>/', $html)
            : preg_match('/<input(?=[^>]*name="'.$campo.'")(?=[^>]*maxlength="'.$lunghezza.'")[^>]*>/', $html) === 1;

        if (!$trovato) {
            return false;
        }
    }

    return str_contains($html, 'id="'.ContactAddressResource::MODAL_ID.'"')
        && substr_count($html, '<form') === 1 && str_contains($html, 'method="post"')
        && preg_match('/<input type="hidden" name="contact_id" value="12"/', $html) === 1
        && preg_match('/<input type="hidden" name="action" value="save"/', $html) === 1
        && preg_match('/<input type="hidden" name="address_id" value="0"/', $html) === 1
        && preg_match('/<input(?=[^>]*type="checkbox")(?=[^>]*name="is_default")(?=[^>]*value="true")[^>]*>/', $html) === 1
        && !str_contains($html, ' required');
});

check('l\'eliminazione è una form che la lib fa confermare, senza finestra propria', function () {
    $html = ContactAddressResource::deleteButton('<i class="bi bi-trash"></i>', 'btn-sm', ['id' => 7, 'contact_id' => 12, 'label' => 'Casa "mia" <b>X</b>']);

    return str_contains($html, 'name="action" value="delete"') && str_contains($html, 'name="address_id" value="7"')
        && str_contains($html, 'name="contact_id" value="12"')
        && str_contains($html, 'data-wi-confirm="Confermi l&#039;eliminazione dell&#039;indirizzo «Casa &quot;mia&quot; &lt;b&gt;X&lt;/b&gt;»?"')
        && str_contains($html, 'data-wi-confirm-ok="Elimina"') && str_contains($html, 'data-wi-confirm-variant="danger"')
        && str_contains($html, '<i class="bi bi-trash"></i>') && !str_contains($html, 'data-bs-toggle');
});

check('«Rendi predefinito» è una form che manda action=default', function () {
    $html = ContactAddressResource::defaultForm(12, 7, 'Rendi predefinito', 'btn-sm');

    return str_contains($html, 'name="action" value="default"') && str_contains($html, 'name="address_id" value="7"')
        && str_contains($html, 'name="contact_id" value="12"');
});

check('il prefisso predefinito è quello del paese del visitatore, con il «+»; da una rete locale resta IT', function () {
    unset($_SESSION['gestionale_visitor_country']);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $locale = VisitorCountry::code() === 'IT' && VisitorCountry::phonePrefix() === '+39';

    $_SESSION['gestionale_visitor_country'] = 'ES';
    $spagna = VisitorCountry::phonePrefix() === '+34';

    $_SESSION['gestionale_visitor_country'] = 'ZZ';
    $ignoto = VisitorCountry::phonePrefix() === '';
    unset($_SESSION['gestionale_visitor_country']);

    return $locale && $spagna && $ignoto;
});

check('la finestra riempie il prefisso di un indirizzo nuovo con quello del visitatore', function () {
    $_SESSION['gestionale_visitor_country'] = 'ES';
    $html = ContactAddressResource::modals(12);
    unset($_SESSION['gestionale_visitor_country']);

    return str_contains($html, 'PREFIX="+34"');
});

summary();
