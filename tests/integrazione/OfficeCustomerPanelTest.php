<?php
/** php tests/integrazione/OfficeCustomerPanelTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use Wonder\App\Theme;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Resources\Contacts\ContactAddressResource;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeCustomerPanel;
use Wonder\Sql\Transaction;

/*
 * La colonna del cliente nel nuovo ordine da ufficio: le finestre salvano
 * sulla scheda del cliente con lo stesso giro del core, e i riquadri si
 * ridisegnano dal payload.
 */

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

function clienteDiProva(string $email = 'anna.pannello@example.com'): int
{
    $creato = Contact::create([
        'type' => 'private', 'name' => 'Anna', 'surname' => 'Pannello', 'country' => 'IT',
        'email' => $email, 'is_customer' => 'true', 'active' => 'true',
    ]);

    return (int) ($creato->insert_id ?? 0);
}

Theme::set('bootstrap');

check('Contatti: email e telefono finiscono sulla scheda', function () {
    return prova(static function (): bool {
        $id = clienteDiProva();
        $esito = OfficeCustomerPanel::save('contacts', $id, ['email' => 'anna.nuova@example.com', 'phone_prefix' => '+39', 'phone' => '3331234567', 'name' => 'Intrusa']);
        $riga = (array) Contact::findById($id);

        return $esito['ok'] === true
            && $riga['email'] === 'anna.nuova@example.com' && $riga['phone'] === '3331234567'
            && $riga['name'] === 'Anna';
    });
});

check('Contatti: un\'email già usata da un altro cliente non passa', function () {
    return prova(static function (): bool {
        clienteDiProva('gia.usata@example.com');
        $id = clienteDiProva();
        $esito = OfficeCustomerPanel::save('contacts', $id, ['email' => 'gia.usata@example.com', 'phone_prefix' => '', 'phone' => '']);

        return $esito['ok'] === false && $esito['message'] !== ''
            && ((array) Contact::findById($id))['email'] === 'anna.pannello@example.com';
    });
});

check('Fatturazione: un\'azienda con i suoi dati si salva', function () {
    return prova(static function (): bool {
        $id = clienteDiProva();
        $esito = OfficeCustomerPanel::save('billing', $id, [
            'type' => 'business', 'business_name' => 'Pannello srl', 'name' => 'Anna', 'surname' => 'Pannello',
            'cf' => '', 'pi' => '01234567890', 'sdi' => 'ABC1234', 'pec' => '', 'country' => 'IT',
            'street' => 'Via Roma', 'number' => '1', 'province' => 'MI', 'city' => 'Milano', 'cap' => '20100', 'more' => '',
        ]);
        $riga = (array) Contact::findById($id);

        return $esito['ok'] === true && $riga['type'] === 'business' && $riga['business_name'] === 'Pannello srl'
            && $riga['street'] === 'Via Roma' && $riga['email'] === 'anna.pannello@example.com';
    });
});

check('Contatti: passano dalla preparazione della tabella (email in minuscolo)', function () {
    return prova(static function (): bool {
        $id = clienteDiProva();
        $esito = OfficeCustomerPanel::save('contacts', $id, ['email' => 'Anna.MAIUSCOLA@Example.com', 'phone_prefix' => '', 'phone' => '']);

        return $esito['ok'] === true
            && ((array) Contact::findById($id))['email'] === 'anna.maiuscola@example.com';
    });
});

check('Indirizzi: aggiungere dà l\'id, eliminare lo toglie', function () {
    return prova(static function (): bool {
        $id = clienteDiProva();
        $nuovo = OfficeCustomerPanel::save('save', $id, ['address_id' => 0, 'label' => 'Casa', 'street' => 'Via Verdi', 'number' => '2', 'cap' => '24100', 'city' => 'Bergamo', 'country' => 'IT']);
        $indirizzo = (int) ($nuovo['id'] ?? 0);
        $prima = count(ContactAddressResource::addressesOf($id));
        $via = OfficeCustomerPanel::save('delete', $id, ['address_id' => $indirizzo]);

        return $nuovo['ok'] === true && $indirizzo > 0 && $prima === 1
            && $via['ok'] === true && ContactAddressResource::addressesOf($id) === [];
    });
});

check('il payload ha i tre riquadri, gli indirizzi a spunta con Modifica ed Elimina', function () {
    return prova(static function (): bool {
        $id = clienteDiProva();
        OfficeCustomerPanel::save('save', $id, ['address_id' => 0, 'label' => 'Casa', 'street' => 'Via Verdi', 'number' => '2', 'cap' => '24100', 'city' => 'Bergamo', 'country' => 'IT']);
        $p = OfficeCustomerPanel::payload($id);
        $html = $p['html']['addresses'];

        return $p['contact']['email'] === 'anna.pannello@example.com'
            && count($p['addresses']) === 1 && $p['addresses'][0]['street'] === 'Via Verdi'
            && str_contains($p['html']['contacts'], 'anna.pannello@example.com')
            && str_contains($p['html']['billing'], 'Anna Pannello')
            && str_contains($html, 'name="oo_address"') && str_contains($html, 'type="checkbox"')
            && str_contains($html, 'data-wi-address=') && str_contains($html, 'data-wi-oo-address-delete')
            && str_contains($html, 'data-wi-confirm') && str_contains($html, 'Aggiungi indirizzo');
    });
});

check('azione sconosciuta o scheda che non c\'è: niente da fare', function () {
    return OfficeCustomerPanel::save('boh', 1, [])['ok'] === false
        && OfficeCustomerPanel::save('contacts', 0, [])['ok'] === false
        && OfficeCustomerPanel::payload(0) === [];
});

check('un fornitore non si legge né si modifica dal pannello, indirizzi compresi', function () {
    return prova(static function (): bool {
        $creato = Contact::create([
            'type' => 'company', 'business_name' => 'Fornitore Prova', 'country' => 'IT',
            'email' => 'fornitore.pannello@example.com', 'is_customer' => 'false', 'is_supplier' => 'true', 'active' => 'true',
        ]);
        $id = (int) ($creato->insert_id ?? 0);
        $contatti = OfficeCustomerPanel::save('contacts', $id, ['email' => 'cambiata@example.com', 'phone_prefix' => '', 'phone' => '']);
        $indirizzo = OfficeCustomerPanel::save('save', $id, ['address_id' => 0, 'label' => 'Sede', 'street' => 'Via Roma', 'number' => '1', 'cap' => '24100', 'city' => 'Bergamo', 'country' => 'IT']);

        return $id > 0 && OfficeCustomerPanel::payload($id) === []
            && $contatti['ok'] === false && $indirizzo['ok'] === false
            && ((array) Contact::findById($id))['email'] === 'fornitore.pannello@example.com'
            && ContactAddressResource::addressesOf($id) === [];
    });
});

summary();
