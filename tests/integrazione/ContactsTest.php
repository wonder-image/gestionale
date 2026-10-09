<?php
/** php tests/integrazione/ContactsTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\SupplierResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Purchasing\ProductSuppliers;
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

// Lo stato delle funzionalità si forza senza toccare il database.
$forza = static function (?array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato);
};

check('il ruolo è una domanda sola, con tre risposte', function () use ($campi, $forza) {
    $forza(['purchasing' => true]);
    $campi = $campi();
    $forza(null);

    $opzioni = (array) ($campi['roles']?->get('options') ?? []);

    return !isset($campi['is_customer'], $campi['is_supplier'])
        && array_keys($opzioni) === ['customer', 'supplier', 'both']
        && $opzioni['both'] === 'Cliente e fornitore';
});

check('senza gli acquisti il ruolo non si chiede', function () use ($campi, $forza) {
    $forza(['purchasing' => false]);
    $campi = $campi();
    $forza(null);

    // Non c'è nessun fornitore da scegliere: la scheda è di un cliente, e
    // basta.
    return !isset($campi['roles'], $campi['is_customer'], $campi['is_supplier']);
});

check('gli indirizzi di consegna sono un repeater sulla loro tabella', function () use ($campi) {
    $relazione = ((array) ($campi()['addresses']?->get('context') ?? []))['relation'] ?? null;

    return $relazione !== null && $relazione->table === 'contact_addresses';
});

try {
    Transaction::run(static function () use ($forza): void {
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
                // Una scheda nuova: il ruolo lo mette `mutateRequestValues()`
                // da sé, perché la stai creando dall'elenco dei clienti.
                CustomerResource::mutateRequestValues(
                    ['pi' => '98765432103'],
                    'store'
                );
            } catch (UserError $errore) {
                return $errore->key() === 'contact.vat_taken'
                    && str_contains($errore->getMessage(), 'Prova Integrazione Srl');
            }

            return false;
        });

        check('la stessa scheda può risalvare la sua partita IVA', function () use ($contactId) {
            $valori = CustomerResource::mutateRequestValues(
                ['pi' => '98765432103'],
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

        // Due fornitori di prova, uno attivo e uno no, e il cliente di sopra.
        $fornitore = static function (string $nome, string $attivo): int {
            $creato = Contact::create([
                'type' => 'business',
                'business_name' => $nome,
                'country' => 'IT',
                'is_customer' => 'false',
                'is_supplier' => 'true',
                'active' => $attivo,
            ]);

            return (int) ($creato->insert_id ?? 0);
        };
        $attivo = $fornitore('Zeta Prova Fornitore Srl', 'true');
        $fermo = $fornitore('Alfa Prova Fornitore Srl', 'false');

        check('la tendina dei fornitori propone solo i fornitori attivi', function () use ($attivo, $fermo, $contactId) {
            $opzioni = Contacts::supplierOptions();

            return ($opzioni[$attivo] ?? '') === 'Zeta Prova Fornitore Srl'
                && !isset($opzioni[$fermo])
                && !isset($opzioni[$contactId]);
        });

        check('un fornitore non attivo resta nella scelta di chi lo usa, e si vede', function () use ($attivo, $fermo, $contactId) {
            // Senza, la tendina posterebbe un valore vuoto e staccherebbe il
            // fornitore dall'opzione.
            $opzioni = Contacts::supplierOptions([$fermo, $contactId]);
            $nostri = array_values(array_intersect(array_keys($opzioni), [$attivo, $fermo]));

            return ($opzioni[$fermo] ?? '') === 'Alfa Prova Fornitore Srl (non attivo)'
                // Un cliente non diventa fornitore perché qualcuno lo tiene.
                && !isset($opzioni[$contactId])
                // In ordine di nome.
                && $nostri === [$fermo, $attivo];
        });

        // Due articoli in vendita che comprano dal fornitore attivo: i
        // fornitori sono delle opzioni, un legame per ognuna (P107).
        $articolo = static function (string $nome): array {
            $modello = ProductModel::create([
                'code' => Code::make(ProductModel::class, Codes::MODEL),
                'name' => $nome,
                'slug' => Slug::make('prova-rubrica-fornitori-'.uniqid()),
                'sku' => 'TST-RUB-'.strtoupper(substr(uniqid(), -6)),
                'unit' => 'pz',
                'type' => 'simple',
                'visible' => 'true',
                'position' => 1,
            ]);
            $modelId = (int) ($modello->insert_id ?? 0);

            return [$modelId, (int) Skeleton::forModel($modelId, $nome)['product_id']];
        };

        [, $opzione] = $articolo('Prova rubrica fornitori');
        [$altroModello, $altraOpzione] = $articolo('Prova rubrica fornitori, secondo');
        ProductSuppliers::sync($opzione, [['supplier_id' => $attivo, 'cost' => '4,00']]);
        ProductSuppliers::sync($altraOpzione, [['supplier_id' => $attivo, 'cost' => '4,20']]);

        // Le altre funzionalità restano come sono sul sito.
        $solo = static function (array $cambi) use ($forza): void {
            $forza([...Gestionale::features(), ...$cambi]);
        };

        check('un fornitore con dei costi su opzioni in vendita non si elimina', function () use ($attivo, $solo, $forza) {
            // Anche ad acquisti spenti: il costo resta salvato, e toglierlo
            // senza dirlo cancellerebbe un dato che tornerà. Il conto è
            // delle opzioni in vendita che lo usano.
            $solo(['purchasing' => false]);

            try {
                SupplierResource::assertDeletable($attivo);
            } catch (RuntimeException $errore) {
                return !$errore instanceof UserError
                    && str_contains($errore->getMessage(), 'costi d\'acquisto')
                    && str_contains($errore->getMessage(), '(2)');
            } finally {
                $forza(null);
            }

            return false;
        });

        check('un fornitore non torna cliente e basta finché ha dei costi', function () use ($attivo, $solo, $forza) {
            $solo(['purchasing' => true]);

            try {
                CustomerResource::mutateRequestValues(['roles' => 'customer'], 'update', 'backend', ['id' => $attivo]);
            } catch (UserError $errore) {
                return $errore->key() === 'contact.supplier_role_in_use'
                    && str_contains($errore->getMessage(), '(2)');
            } finally {
                $forza(null);
            }

            return false;
        });

        check('diventare anche cliente invece si può', function () use ($attivo, $solo, $forza) {
            $solo(['purchasing' => true]);

            try {
                $valori = CustomerResource::mutateRequestValues(['roles' => 'both'], 'update', 'backend', ['id' => $attivo]);
            } finally {
                $forza(null);
            }

            return ($valori['is_customer'] ?? '') === 'true' && ($valori['is_supplier'] ?? '') === 'true';
        });

        check('con un\'opzione nel cestino l\'altra in vendita tiene ancora fermo il fornitore', function () use ($attivo, $opzione, $solo, $forza) {
            Product::query()->Update(Product::$table, ['deleted' => 'true'], 'id', $opzione);
            $solo(['purchasing' => true]);

            try {
                SupplierResource::assertDeletable($attivo);
            } catch (RuntimeException $errore) {
                return str_contains($errore->getMessage(), '(1)');
            } finally {
                $forza(null);
            }

            return false;
        });

        check('con anche l\'altro articolo eliminato il fornitore si elimina, e i suoi costi con lui', function () use ($attivo, $altroModello) {
            // L'elenco mette nel cestino l'articolo, non le sue opzioni.
            ProductModel::query()->Update(ProductModel::$table, ['deleted' => 'true'], 'id', $altroModello);

            $esito = SupplierResource::deleteRecord($attivo);
            $legami = ProductSupplier::find(['supplier_id' => $attivo, 'deleted' => ['true', 'false']]);

            return !empty($esito->success)
                && (!is_array($legami) || $legami === [])
                && in_array(Contact::findById($attivo), [null, []], true);
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
