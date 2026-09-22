<?php
/** php tests/integrazione/ContactsTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$campi = static function (): array {
    $campi = [];

    foreach (CustomerResource::formSchema() as $field) {
        $campi[(string) $field->name] = $field;
    }

    return $campi;
};

// La scheda si prova qui e non nei test unitari: `AddressExtension` del core
// chiede `__t()`, che esiste solo a sito avviato.
check('la scheda chiede i dati di fatturazione del core', function () use ($campi) {
    $chiavi = array_keys($campi());

    foreach (['type', 'business_name', 'cf', 'pi', 'sdi', 'pec', 'city', 'street'] as $nome) {
        if (!in_array($nome, $chiavi, true)) {
            return false;
        }
    }

    return true;
});

check('i campi aziendali si vedono solo per le aziende', function () use ($campi) {
    return str_contains(json_encode($campi()['business_name']?->get() ?? []), 'business');
});

check('gli indirizzi di consegna sono un repeater sulla loro tabella', function () use ($campi) {
    $relazione = ((array) ($campi()['addresses']?->get('context') ?? []))['relation'] ?? null;

    return $relazione !== null && $relazione->table === 'gst_contact_addresses';
});

try {
    Transaction::run(static function (): void {
        $creato = Contact::create([
            'type' => 'business',
            'business_name' => 'Prova Integrazione Srl',
            // La partita IVA si valida **con il paese**: senza `country` nello
            // stesso salvataggio il campo del core rifiuta tutto.
            'country' => 'IT',
            'pi' => '98765432103',
            'email' => 'prova.integrazione@example.com',
            'is_customer' => 'true',
            'active' => 'true',
        ]);
        $contactId = (int) ($creato->insert_id ?? 0);

        check('la scheda nasce con il suo codice', function () use ($contactId) {
            $riga = Contact::findById($contactId);

            return str_starts_with((string) ($riga['code'] ?? ''), 'con_');
        });

        check('il nome da mostrare è la ragione sociale', fn () =>
            Contacts::displayName((array) Contact::findById($contactId)) === 'Prova Integrazione Srl'
        );

        check('una partita IVA già presa viene rifiutata con il nome di chi ce l\'ha', function () {
            try {
                CustomerResource::mutateRequestValues(
                    ['is_customer' => 'true', 'pi' => '98765432103'],
                    'update',
                    'backend',
                    ['id' => 0]
                );
            } catch (UserError $errore) {
                return $errore->key() === 'contact.vat_taken'
                    && str_contains($errore->getMessage(), 'Prova Integrazione Srl');
            }

            return false;
        });

        check('la stessa scheda può risalvare la sua partita IVA', function () use ($contactId) {
            $valori = CustomerResource::mutateRequestValues(
                ['is_customer' => 'true', 'pi' => '98765432103'],
                'update',
                'backend',
                ['id' => $contactId]
            );

            return ($valori['pi'] ?? '') === '98765432103';
        });

        check('un indirizzo di consegna sta con la sua scheda', function () use ($contactId) {
            ContactAddress::create([
                'contact_id' => $contactId,
                'label' => 'Magazzino',
                'name' => 'Mario',
                'surname' => 'Rossi',
                'street' => 'Via Prova',
                'number' => '1',
                'cap' => '20100',
                'city' => 'Milano',
                'country' => 'IT',
                'is_default' => 'true',
                'position' => 1,
            ]);

            $righe = ContactAddress::find(['contact_id' => $contactId, 'deleted' => 'false']);
            $righe = isset($righe['id']) ? [$righe] : (array) $righe;

            return count(array_filter($righe, 'is_array')) === 1;
        });

        check('una scheda con un account non si elimina', function () use ($contactId) {
            Contact::update(['user_id' => 42], $contactId);

            try {
                CustomerResource::assertDeletable($contactId);
            } catch (RuntimeException $errore) {
                return str_contains($errore->getMessage(), 'account');
            } finally {
                Contact::update(['user_id' => 0], $contactId);
            }

            return false;
        });

        check('senza account invece si elimina', function () use ($contactId) {
            CustomerResource::assertDeletable($contactId);

            return true;
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento la rubrica è come prima', function () {
    $riga = Contact::find(['email' => 'prova.integrazione@example.com', 'deleted' => 'false'], 1);

    return !is_array($riga) || $riga === [];
});

summary();
