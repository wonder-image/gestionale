# Anagrafiche — Piano 3 di 4 di G2b

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** la rubrica del gestionale — clienti e fornitori — con i dati di
fatturazione validati dal core, gli indirizzi di consegna, e le due voci di
menu che ci portano.

**Architecture:** una tabella sola (`gst_contacts`) e due elenchi che la
guardano da due punti di vista: `CustomerResource` è la scheda completa,
`SupplierResource` la estende cambiando solo titolo, indirizzo, filtro e
funzionalità — la stessa parentela che c'è già fra `ProductModelResource` e
`ProductResource`. I dati di fatturazione (tipo, ragione sociale, CF e partita
IVA **già validati**, SDI, PEC, indirizzo, telefono) arrivano da
`AddressExtension::billing()` del core: il modulo non li riscrive.

**Tech Stack:** PHP 8.2, `wonder-image/app` 2.3.0 (`AddressExtension`,
`RepeaterRelation`, `HomeWidget`), harness di test del modulo, MySQL del sito di
prova `ecommerce-site`.

**Spec:** [G2b — Magazzino base e anagrafiche](../specs/2026-09-21-magazzino-e-anagrafiche-design.md) §7

## Global Constraints

- Lingua: **italiano** nei commenti, nei testi e nei nomi dei test; **inglese**
  per classi, tabelle e colonne.
- Prefisso `gst_`, `syncSchema(): null`, `code` con prefisso `Codes::CONTACT`
  (`con_`).
- **Una scheda = una identità fiscale.** Partita IVA, codice fiscale ed email,
  quando compilati, sono unici; il rifiuto dice **quale scheda** li ha già.
  L'unicità la controlla la Resource, non un indice: il framework scrive
  stringhe vuote e non `NULL`, e un indice unico farebbe scontrare tutte le
  schede senza partita IVA (stessa ragione di SKU ed EAN in G2a).
- **D20:** il ruolo fornitore e la voce di menu *Fornitori* esistono solo con la
  funzionalità `purchasing` sbloccata; i campi aziendali solo per il tipo
  `business`.
- `price_list_id`, `payment_method_id` e `payment_term_id` nascono come colonne
  **senza nessun campo**: non c'è ancora niente da scegliere (G4, G6).
- Le colonne che puntano a tabelle che non esistono ancora (`user_id`,
  `price_list_id`, `payment_method_id`, `payment_term_id`) **non hanno chiave
  esterna**.
- I rifiuti dentro un form sono `UserError::make()`; quelli
  dell'endpoint che cancella una riga `UserError::refusal()`.
- Ogni task finisce con un commit sul ramo `feature/anagrafiche`;
  `php tests/run.php` deve restare verde.
- **Mai `git add -A`**: in questa cartella ci sono anche modifiche non
  committate di chi ci lavora. Si aggiungono percorsi espliciti.

---

## File Structure

**Da creare**

| File | Responsabilità |
|---|---|
| `src/Models/Contacts/Contact.php` | tabella `gst_contacts`: una scheda, una identità fiscale |
| `src/Models/Contacts/ContactAddress.php` | tabella `gst_contact_addresses`: gli indirizzi di consegna |
| `src/Support/Contacts/Contacts.php` | **pura** dove può: nome da mostrare, ruoli, duplicati |
| `src/Resources/Contacts/CustomerResource.php` | elenco *Clienti* e scheda completa |
| `src/Resources/Contacts/SupplierResource.php` | elenco *Fornitori*: stessa scheda, altro punto di vista |
| `src/Backend/Widgets/ContactsWidget.php` | riquadro della home con i due numeri |
| `docs/user/anagrafiche.md` | guida commerciante |
| `tests/ContactModelsTest.php`, `tests/ContactsTest.php`, `tests/ContactResourcesTest.php` | unitari |
| `tests/integrazione/ContactsTest.php` | integrazione |

**Da modificare**

| File | Modifica |
|---|---|
| `lang/it/gestionale.json` | chiavi di rifiuto sotto `gestionale.errors.contact` |
| `config/module.php` | il riquadro nuovo fra gli `home_widgets` |
| `src/Seeding/CatalogDemo.php` **o** un seeder nuovo | quattro anagrafiche di prova |
| `tests/ConventionsTest.php` | `CustomerResource` fra le pagine sempre attive |
| `docs/user/SUMMARY.md` | la pagina nuova |

---

## Task 1: Le due tabelle

**Files:**
- Create: `src/Models/Contacts/Contact.php`, `src/Models/Contacts/ContactAddress.php`
- Test: `tests/ContactModelsTest.php`

**Interfaces:**
- Consumes: `AddressExtension::billing()` e `::simple()` del core,
  `Support\Codes::CONTACT`.
- Produces:
  - `Contact::$table = 'gst_contacts'`, `Contact::billing(): AddressExtension`,
    `Contact::newCode(): string`
  - `ContactAddress::$table = 'gst_contact_addresses'`,
    `ContactAddress::address(): AddressExtension`

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/ContactModelsTest.php`:

```php
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

check('le due tabelle hanno il prefisso del gestionale', fn () =>
    Contact::$table === 'gst_contacts'
    && ContactAddress::$table === 'gst_contact_addresses'
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

    return ($c['contact_id'] ?? null)?->getSchema('foreign_table') === 'gst_contacts';
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
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `php tests/ContactModelsTest.php`
Expected: FAIL — `Class "…\Models\Contacts\Contact" not found`.

- [ ] **Step 3: Scrivi `src/Models/Contacts/Contact.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Contacts;

use Wonder\App\Model;
use Wonder\App\Schema\Extensions\AddressExtension;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Una scheda della rubrica: un cliente, un fornitore, o tutti e due.
 *
 * **Una scheda è una identità fiscale**, non un ruolo: la stessa azienda a cui
 * vendi e da cui compri è una riga sola, con due interruttori. Duplicarla
 * vorrebbe dire due partite IVA uguali e due storie separate della stessa
 * persona.
 *
 * I dati di fatturazione arrivano da `AddressExtension::billing()` del core —
 * tipo, ragione sociale, codice fiscale e partita IVA **già validati**, SDI,
 * PEC, indirizzo e telefono. Il modulo aggiunge solo quello che il core non
 * sa: i ruoli, l'email, l'account del sito e le scelte commerciali.
 *
 * `price_list_id`, `payment_method_id` e `payment_term_id` nascono adesso ma
 * non hanno nessun campo nel form: non c'è ancora niente da scegliere. È la
 * stessa regola con cui `min_stock_quantity` è nato in G2a.
 */
final class Contact extends Model
{
    public static string $table = 'gst_contacts';
    public static string $folder = 'gestionale/contacts';
    public static string $icon = 'bi bi-person-vcard';

    /** I dati di fatturazione del core, sempre con la stessa configurazione. */
    public static function billing(): AddressExtension
    {
        // Niente link a Google Maps: l'indirizzo di fatturazione non si visita.
        return AddressExtension::billing(countryDefault: 'IT')->withLink(false);
    }

    /** La rubrica è il lavoro di chi vende, non configurazione da spostare. */
    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code', 'email', 'color']),
            ...static::billing()->tableSchema(),
            Column::key('is_customer')->enum(['true', 'false'])->default('true'),
            Column::key('is_supplier')->enum(['true', 'false'])->default('false'),
            // Nessuna chiave esterna: le tabelle degli utenti del core, dei
            // listini e dei pagamenti o non sono nostre o non esistono ancora,
            // e lo zero non potrebbe puntare a niente.
            Column::key('user_id')->int(),
            Column::key('price_list_id')->int(),
            Column::key('payment_method_id')->int(),
            Column::key('payment_term_id')->int(),
            Column::key('note')->type('TEXT'),
            Column::key('custom_data')->json(),
            Column::key('active')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_customer' => ['index' => 'is_customer'],
            'ind_supplier' => ['index' => 'is_supplier'],
            'ind_user' => ['index' => 'user_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::CONTACT),
            ...static::billing()->dataSchema(),
            Field::key('email')->email(),
            Field::key('is_customer')->text()->sanitize(false),
            Field::key('is_supplier')->text()->sanitize(false),
            Field::key('user_id')->number()->decimals(0),
            Field::key('price_list_id')->number()->decimals(0),
            Field::key('payment_method_id')->number()->decimals(0),
            Field::key('payment_term_id')->number()->decimals(0),
            Field::key('color')->text()->sanitize(false),
            Field::key('note')->text(),
            Field::key('custom_data')->json(),
            Field::key('active')->text()->sanitize(false),
        ];
    }

    /** L'indirizzo già composto, come lo mostra il core. */
    public static function decorate(array $row): array
    {
        return static::billing()->decorate($row);
    }

    /**
     * Codice nuovo per una scheda.
     *
     * `Model::prepare()` formatta i valori ma non genera i codici unici: li fa
     * il flusso dei form. Chi inserisce una riga da codice (i dati di prova,
     * un ordine da ospite in E1) chiede il codice qui.
     */
    public static function newCode(): string
    {
        return Code::make(static::class, Codes::CONTACT);
    }

    public static function create(array $values): object
    {
        if (trim((string) ($values['code'] ?? '')) === '') {
            $values['code'] = static::newCode();
        }

        return parent::create($values);
    }
}
```

- [ ] **Step 4: Scrivi `src/Models/Contacts/ContactAddress.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\Contacts;

use Wonder\App\Model;
use Wonder\App\Schema\Extensions\AddressExtension;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Dove si consegna: gli indirizzi di una scheda della rubrica.
 *
 * Sono un'altra cosa dall'indirizzo di fatturazione, che sta sulla scheda: chi
 * compra per l'ufficio e si fa consegnare a casa ne ha due, e chi ha tre
 * cantieri ne ha tre.
 *
 * Destinatario e telefono arrivano dall'estensione del core
 * (`withContactName()`, `withPhone()`): al corriere serve sapere chi cercare,
 * e quel nome spesso non è quello della scheda.
 */
final class ContactAddress extends Model
{
    public static string $table = 'gst_contact_addresses';
    public static string $folder = 'gestionale/contacts';
    public static string $icon = 'bi bi-geo';

    /** L'indirizzo semplice del core, con destinatario e telefono. */
    public static function address(): AddressExtension
    {
        return AddressExtension::simple(countryDefault: 'IT')
            ->withLink(false)
            ->withContactName()
            ->withPhone();
    }

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('contact_id')->int()->null(false)->foreign(Contact::$table),
            Column::key('label')->length(100),
            ...static::address()->tableSchema(),
            Column::key('is_default')->enum(['true', 'false'])->default('false'),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_contact' => ['index' => 'contact_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('contact_id')->number()->decimals(0),
            Field::key('label')->text(),
            ...static::address()->dataSchema(),
            Field::key('is_default')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }

    public static function decorate(array $row): array
    {
        return static::address()->decorate($row);
    }
}
```

- [ ] **Step 5: Esegui il test e verifica che passi**

Run: `php tests/ContactModelsTest.php`
Expected: `10 test, 0 falliti`

- [ ] **Step 6: Crea le tabelle sul sito di prova**

Run: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update --local`
Expected: crea `gst_contacts` e `gst_contact_addresses` senza errori.

- [ ] **Step 7: Commit**

```bash
git add -- src/Models/Contacts tests/ContactModelsTest.php
git commit -m "Anagrafiche: le due tabelle"
```

---

## Task 2: Le regole della rubrica

**Files:**
- Create: `src/Support/Contacts/Contacts.php`
- Modify: `lang/it/gestionale.json`
- Test: `tests/ContactsTest.php`

**Interfaces:**
- Consumes: `Contact` (solo per le letture), `Gestionale::feature()`.
- Produces:
  - `Contacts::displayName(array $row): string` — **pura**: ragione sociale per
    un'azienda, "Cognome Nome" per un privato, l'email come ultima spiaggia.
  - `Contacts::roles(array $row): string` — **pura**: "Cliente", "Fornitore",
    "Cliente e fornitore", "—".
  - `Contacts::isCompany(array $row): bool` — **pura**
  - `Contacts::duplicateOf(string $column, string $value, ?int $ignoreId): array`
    — la scheda che ha già quel valore, `[]` se è libero.

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/ContactsTest.php`:

```php
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
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `php tests/ContactsTest.php`
Expected: FAIL — `Class "…\Support\Contacts\Contacts" not found`.

- [ ] **Step 3: Scrivi `src/Support/Contacts/Contacts.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;

/**
 * Come si legge una scheda della rubrica, e come si scopre che ce n'è già
 * un'altra uguale.
 *
 * Il nome da mostrare non è una colonna: un'azienda si chiama con la ragione
 * sociale, un privato con cognome e nome, e chi arriva da un ordine online a
 * volte ha solo un'email. Deciderlo in un posto solo evita che elenco, scheda,
 * documenti e vetrina lo facciano ognuno a modo suo.
 */
final class Contacts
{
    /** @param array<string, mixed> $row */
    public static function isCompany(array $row): bool
    {
        return strtolower(trim((string) ($row['type'] ?? ''))) === 'business';
    }

    /** @param array<string, mixed> $row */
    public static function displayName(array $row): string
    {
        $business = trim((string) ($row['business_name'] ?? ''));

        if (self::isCompany($row) && $business !== '') {
            return $business;
        }

        $person = trim(implode(' ', array_filter([
            trim((string) ($row['surname'] ?? '')),
            trim((string) ($row['name'] ?? '')),
        ])));

        if ($person !== '') {
            return $person;
        }

        $email = trim((string) ($row['email'] ?? ''));

        if ($email !== '') {
            return $email;
        }

        return $business !== '' ? $business : 'Senza nome';
    }

    /** @param array<string, mixed> $row */
    public static function roles(array $row): string
    {
        $customer = ($row['is_customer'] ?? 'false') === 'true';
        $supplier = ($row['is_supplier'] ?? 'false') === 'true';

        return match (true) {
            $customer && $supplier => 'Cliente e fornitore',
            $customer => 'Cliente',
            $supplier => 'Fornitore',
            default => '—',
        };
    }

    /**
     * La scheda che ha già quel valore, `[]` se è libero.
     *
     * Serve a dire **di chi** è la partita IVA che stai riscrivendo: un
     * "già presente" senza nome costringe a cercarla a mano.
     *
     * @return array<string, mixed>
     */
    public static function duplicateOf(string $column, string $value, ?int $ignoreId): array
    {
        $value = trim($value);

        if ($value === '') {
            return [];
        }

        try {
            $rows = Contact::find([$column => $value, 'deleted' => 'false']);
        } catch (Throwable) {
            // Senza database (test degli schemi, comandi) non c'è niente da
            // confrontare: il valore è libero.
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $rows = isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));

        foreach ($rows as $row) {
            if ($ignoreId === null || (int) ($row['id'] ?? 0) !== $ignoreId) {
                return $row;
            }
        }

        return [];
    }
}
```

- [ ] **Step 4: Aggiungi le chiavi di rifiuto**

In `lang/it/gestionale.json`, dentro `gestionale.errors`, accanto a `product`:

```json
"contact": {
    "vat_taken": "Questa partita IVA è già di {{contact}}: apri quella scheda invece di crearne un'altra.",
    "tax_code_taken": "Questo codice fiscale è già di {{contact}}.",
    "email_taken": "Questa email è già di {{contact}}.",
    "no_role": "Di' se è un cliente, un fornitore o tutti e due.",
    "has_account": "Questa scheda ha un account sul sito e non si elimina: mettila su «Non attiva» se non lavori più con lei."
}
```

- [ ] **Step 5: Esegui il test e verifica che passi**

Run: `php tests/ContactsTest.php`
Expected: `9 test, 0 falliti`

- [ ] **Step 6: Commit**

```bash
git add -- src/Support/Contacts tests/ContactsTest.php lang/it/gestionale.json
git commit -m "Anagrafiche: nome da mostrare, ruoli e duplicati"
```

---

## Task 3: Clienti e fornitori, due elenchi e una scheda

**Files:**
- Create: `src/Resources/Contacts/CustomerResource.php`,
  `src/Resources/Contacts/SupplierResource.php`
- Modify: `tests/ConventionsTest.php`
- Test: `tests/ContactResourcesTest.php`

**Interfaces:**
- Consumes: `Contact`, `ContactAddress`, `Contacts::*`, `Gestionale::feature()`,
  `UserError`.
- Produces:
  - `CustomerResource::path()` = `app/gestionale/clienti`
  - `SupplierResource::path()` = `app/gestionale/fornitori`,
    `SupplierResource::$feature = 'purchasing'`

- [ ] **Step 1: Scrivi il test che fallisce**

`tests/ContactResourcesTest.php`:

```php
<?php
/** php tests/ContactResourcesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\SupplierResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

$campi = static function (string $resource): array {
    $campi = [];

    foreach ($resource::formSchema() as $field) {
        $campi[(string) $field->name] = $field;
    }

    return $campi;
};

check('i due elenchi guardano la stessa tabella', fn () =>
    CustomerResource::$model === Contact::class
    && SupplierResource::$model === Contact::class
);

check('ognuno ha il suo indirizzo e il suo titolo', fn () =>
    CustomerResource::path() === 'app/gestionale/clienti'
    && SupplierResource::path() === 'app/gestionale/fornitori'
    && CustomerResource::titleLabel() === 'Clienti'
    && SupplierResource::titleLabel() === 'Fornitori'
);

check('i fornitori esistono solo con gli acquisti sbloccati', fn () =>
    SupplierResource::$feature === 'purchasing'
    && CustomerResource::$feature === ''
);

check('le due voci stanno sotto Anagrafiche', fn () =>
    (CustomerResource::navigationSchema()->toArray()['section_key'] ?? '') === 'anagrafiche'
    && (SupplierResource::navigationSchema()->toArray()['section_key'] ?? '') === 'anagrafiche'
);

check('ogni elenco mostra solo i suoi', function () {
    $clienti = (string) (CustomerResource::querySchema()['condition'] ?? '');
    $fornitori = (string) (SupplierResource::querySchema()['condition'] ?? '');

    return str_contains($clienti, "is_customer = 'true'")
        && str_contains($fornitori, "is_supplier = 'true'");
});

check('la scheda chiede i dati di fatturazione del core', function () use ($campi) {
    $chiavi = array_keys($campi(CustomerResource::class));

    foreach (['type', 'business_name', 'cf', 'pi', 'sdi', 'pec', 'city', 'street'] as $nome) {
        if (!in_array($nome, $chiavi, true)) {
            return false;
        }
    }

    return true;
});

check('i campi aziendali si vedono solo per le aziende', function () use ($campi) {
    $campo = $campi(CustomerResource::class)['business_name'] ?? null;
    $visibilita = json_encode($campo?->get('context') ?? []);

    return str_contains((string) $visibilita, 'business');
});

check('gli indirizzi di consegna sono un repeater sulla loro tabella', function () use ($campi) {
    $campo = $campi(CustomerResource::class)['addresses'] ?? null;
    $relazione = ((array) ($campo?->get('context') ?? []))['relation'] ?? null;

    return $relazione !== null && $relazione->table === 'gst_contact_addresses';
});

check('una partita IVA già presa si ferma con il nome di chi ce l\'ha', function () {
    // `Contacts::duplicateOf()` senza database torna vuoto: qui conta che il
    // messaggio esista e nomini la scheda.
    $errore = UserError::make('contact.vat_taken', ['contact' => 'Rossi Srl']);

    return str_contains($errore->getMessage(), 'Rossi Srl')
        && str_contains($errore->getMessage(), 'partita IVA');
});

check('una scheda senza ruolo viene rifiutata', function () {
    try {
        CustomerResource::mutateRequestValues(
            ['is_customer' => 'false', 'is_supplier' => 'false'],
            'store'
        );
    } catch (UserError $errore) {
        return $errore->key() === 'contact.no_role';
    }

    return false;
});

check('un cliente nuovo nasce cliente', function () {
    $valori = CustomerResource::mutateRequestValues(['name' => 'Mario'], 'store');

    return ($valori['is_customer'] ?? '') === 'true';
});

check('un fornitore nuovo nasce fornitore', function () {
    $valori = SupplierResource::mutateRequestValues(['name' => 'Mario'], 'store');

    return ($valori['is_supplier'] ?? '') === 'true';
});

summary();
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `php tests/ContactResourcesTest.php`
Expected: FAIL — `Class "…\Resources\Contacts\CustomerResource" not found`.

- [ ] **Step 3: Scrivi `src/Resources/Contacts/CustomerResource.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Resources\Contacts;

use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * "Clienti": l'elenco di chi compra, e la scheda della rubrica.
 *
 * La scheda è **una sola** per clienti e fornitori: chi è tutti e due si
 * modifica in un posto, e la sua partita IVA resta una. Quello che cambia fra
 * i due elenchi — titolo, indirizzo, filtro, funzionalità — sta in
 * `SupplierResource`, che estende questa.
 *
 * Non è `final`: la estende `SupplierResource`, e i test con una classe
 * anonima.
 */
class CustomerResource extends GestionaleResource
{
    public static string $model = Contact::class;
    public static string $orderColumn = 'id';
    public static string $orderDirection = 'DESC';
    public static string $docsPage = 'anagrafiche/anagrafiche';

    /** La colonna del ruolo che questo elenco mostra. */
    public static function roleColumn(): string
    {
        return 'is_customer';
    }

    public static function path(): string
    {
        return 'app/gestionale/clienti';
    }

    public static function icon(): string
    {
        return 'bi-person-vcard';
    }

    public static function titleLabel(): string
    {
        return 'Clienti';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'cliente',
            'plural_label' => 'clienti',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'email' => 'Email',
            'is_customer' => 'Cliente',
            'is_supplier' => 'Fornitore',
            'note' => 'Note',
            'active' => 'Stato',
            'addresses' => 'Indirizzi di consegna',
            'label' => 'Etichetta',
            ...Contact::billing()->labels(),
        ];
    }

    public static function formSchema(): array
    {
        $billing = Contact::billing()->formSchema();

        // I campi aziendali si vedono solo per le aziende: un privato non deve
        // incontrare la parola "SDI" (D20).
        foreach (['business_name', 'pi', 'sdi', 'pec'] as $key) {
            if (isset($billing[$key])) {
                $billing[$key]->visibleWhen('type', 'business');
            }
        }

        $fields = [
            ...array_values($billing),
            FormField::key('email')->email()->label('Email'),
            FormField::key('is_customer')
                ->select(['true' => 'Sì', 'false' => 'No'])
                ->value('true')
                ->label('È un cliente'),
            FormField::key('active')
                ->select(['true' => 'Attiva', 'false' => 'Non attiva'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('note')->textarea()->label('Note'),
            static::addressesField(),
        ];

        // Il ruolo fornitore esiste solo con gli acquisti sbloccati (D20).
        if (Gestionale::feature('purchasing')) {
            array_splice($fields, 2, 0, [
                FormField::key('is_supplier')
                    ->select(['true' => 'Sì', 'false' => 'No'])
                    ->value('false')
                    ->label('È anche un fornitore'),
            ]);
        }

        return $fields;
    }

    public static function formLayoutSchema(): ?Form
    {
        $chiIe = [
            SectionTitle::make('Chi è')
                ->tooltip('Una scheda è una sola identità fiscale: la stessa azienda a cui vendi e da cui compri è una riga sola, con i due ruoli accesi.')
                ->columnSpan(12),
            static::getInput('type')->columnSpan(4),
            static::getInput('name')->columnSpan(4),
            static::getInput('surname')->columnSpan(4),
            static::getInput('business_name')->columnSpan(6),
            static::getInput('is_customer')->columnSpan(3),
        ];

        if (Gestionale::feature('purchasing')) {
            $chiIe[] = static::getInput('is_supplier')->columnSpan(3);
        }

        $chiIe[] = static::getInput('active')->columnSpan(3);

        $cards = [
            (new Card)->components($chiIe)->columns(12)->columnSpan(12),
            (new Card)->components([
                SectionTitle::make('Contatti')->columnSpan(12),
                static::getInput('email')->columnSpan(6),
                static::getInput('phone_prefix')->columnSpan(2),
                static::getInput('phone')->columnSpan(4),
            ])->columns(12)->columnSpan(12),
            (new Card)->components([
                SectionTitle::make('Dati di fatturazione')
                    ->tooltip('Partita IVA e codice fiscale li controlla il framework: se sono sbagliati non si salvano.')
                    ->columnSpan(12),
                static::getInput('cf')->columnSpan(4),
                static::getInput('pi')->columnSpan(4),
                static::getInput('sdi')->columnSpan(2),
                static::getInput('pec')->columnSpan(2),
                static::getInput('country')->columnSpan(3),
                static::getInput('province')->columnSpan(3),
                static::getInput('city')->columnSpan(3),
                static::getInput('cap')->columnSpan(3),
                static::getInput('street')->columnSpan(6),
                static::getInput('number')->columnSpan(2),
                static::getInput('more')->columnSpan(4),
            ])->columns(12)->columnSpan(12),
            (new Card)->components([
                SectionTitle::make('Indirizzi di consegna')
                    ->tooltip('Dove si consegna, quando non è l\'indirizzo di fatturazione. Il destinatario è chi il corriere deve cercare.')
                    ->columnSpan(12),
                static::getInput('addresses')->columnSpan(12),
            ])->columns(12)->columnSpan(12),
            (new Card)->components([
                SectionTitle::make('Note')->columnSpan(12),
                static::getInput('note')->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ];

        return (new Form)->components([
            (new Container)->components($cards)->columns(12)->columnSpan(12),
        ])->columns(12);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')
                ->text()
                ->link('edit')
                ->formatter(static fn (array $row): string => static::escape(
                    Contacts::displayName($row)
                )),
            TableColumn::key('email')->text(),
            TableColumn::key('city')->text()->size('little'),
            TableColumn::key('is_customer')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(Contacts::roles($row))),
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Attiva', 'bi-check-circle', 'success')
                ->badgeOff('Non attiva', 'bi-pause-circle', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()->titles([
            'list' => static::titleLabel(),
            'create' => 'Aggiungi '.static::textSchema()['label'],
            'edit' => 'Modifica '.static::textSchema()['label'],
        ]);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('anagrafiche', 'Anagrafiche', 'bi-people', 500, ['admin', 'administrator'])
            ->title(static::titleLabel())
            ->order(10)
            ->authority(['admin', 'administrator'])
            ->enabled(static::featureActive());
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /** Ogni elenco mostra solo i suoi: la tabella è la stessa. */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        $schema['condition'] = "deleted = 'false' AND ".static::roleColumn()." = 'true'";

        return $schema;
    }

    /**
     * Le regole della rubrica, prima di salvare.
     *
     * Chi nasce in questo elenco nasce con il suo ruolo: aprire "Clienti",
     * premere "Aggiungi" e ritrovarsi una scheda senza ruolo sarebbe un modo
     * per perdere le righe.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        $id = (int) ($oldValues['id'] ?? 0);

        if ($action === 'store') {
            $values[static::roleColumn()] = 'true';
        }

        if (!Gestionale::feature('purchasing') && $action === 'store') {
            // Senza acquisti il campo non si stampa, e un fornitore creato
            // prima resterebbe senza ruolo al salvataggio.
            $values['is_supplier'] ??= 'false';
        }

        $customer = ($values['is_customer'] ?? 'false') === 'true';
        $supplier = ($values['is_supplier'] ?? 'false') === 'true';

        if (!$customer && !$supplier) {
            throw UserError::make('contact.no_role');
        }

        foreach ([
            'pi' => 'contact.vat_taken',
            'cf' => 'contact.tax_code_taken',
            'email' => 'contact.email_taken',
        ] as $column => $key) {
            $value = trim((string) ($values[$column] ?? ''));
            $duplicate = Contacts::duplicateOf($column, $value, $id > 0 ? $id : null);

            if ($duplicate !== []) {
                throw UserError::make($key, ['contact' => Contacts::displayName($duplicate)]);
            }
        }

        return $values;
    }

    /**
     * Una scheda con un account sul sito non si elimina.
     *
     * Cancellarla lascerebbe un utente che può entrare e non ha più una
     * scheda. Quando arriveranno ordini e documenti (G4) la stessa regola
     * varrà per loro: si disattiva, non si cancella.
     */
    public static function assertDeletable(int|string $id): void
    {
        $row = Contact::findById((int) $id);

        if (is_array($row) && (int) ($row['user_id'] ?? 0) > 0) {
            throw UserError::refusal('contact.has_account');
        }
    }

    /** Gli indirizzi di consegna, dentro la scheda. */
    protected static function addressesField(): Input
    {
        return FormField::key('addresses')
            ->repeater([
                RepeaterColumn::key('id')->hidden(),
                RepeaterColumn::key('label')->text()->label('Etichetta')->columnSpan(2),
                RepeaterColumn::key('name')->text()->label('Nome')->columnSpan(2),
                RepeaterColumn::key('surname')->text()->label('Cognome')->columnSpan(2),
                RepeaterColumn::key('street')->text()->label('Via')->columnSpan(2),
                RepeaterColumn::key('number')->text()->label('N.')->columnSpan(1),
                RepeaterColumn::key('cap')->text()->label('CAP')->columnSpan(1),
                RepeaterColumn::key('city')->text()->label('Città')->columnSpan(1),
                RepeaterColumn::key('is_default')
                    ->select(['false' => 'No', 'true' => 'Sì'])
                    ->label('Predefinito')
                    ->columnSpan(1),
            ])
            ->relation(
                RepeaterRelation::make(ContactAddress::$table, 'contact_id')
                    ->model(ContactAddress::class)
                    ->positionKey('position')
            )
            ->nested()
            ->repeaterSortable()
            ->repeaterAddLabel('Aggiungi indirizzo')
            ->repeaterDeleteTitle('Elimina indirizzo')
            ->repeaterDeleteText('Confermi l\'eliminazione di questo indirizzo?')
            ->repeaterDeleteCancelLabel('Annulla')
            ->repeaterDeleteConfirmLabel('Elimina')
            ->repeaterDeleteConfirmClass('btn btn-danger')
            ->label('Indirizzi di consegna');
    }
}
```

- [ ] **Step 4: Scrivi `src/Resources/Contacts/SupplierResource.php`**

```php
<?php

namespace Wonder\Plugin\Gestionale\Resources\Contacts;

/**
 * "Fornitori": lo stesso elenco visto dall'altra parte.
 *
 * Cambia il titolo, l'indirizzo, il filtro e la funzionalità che lo governa;
 * la scheda è quella dei clienti, perché la scheda è la stessa persona. Chi è
 * cliente e fornitore compare in tutti e due gli elenchi e si modifica una
 * volta sola.
 */
final class SupplierResource extends CustomerResource
{
    public static string $feature = 'purchasing';

    public static function roleColumn(): string
    {
        return 'is_supplier';
    }

    public static function path(): string
    {
        return 'app/gestionale/fornitori';
    }

    public static function icon(): string
    {
        return 'bi-truck';
    }

    public static function titleLabel(): string
    {
        return 'Fornitori';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'fornitore',
            'plural_label' => 'fornitori',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function navigationSchema(): \Wonder\App\ResourceSchema\NavigationSchema
    {
        return parent::navigationSchema()->order(20);
    }
}
```

- [ ] **Step 5: Dichiara i Clienti fra le pagine sempre attive**

In `tests/ConventionsTest.php`, dentro `$sempreAttive`, dopo
`Stock\StockMovementResource`:

```php
        // I clienti ci sono sempre; i fornitori arrivano con gli acquisti, e
        // infatti `SupplierResource` dichiara la sua funzionalità.
        'Wonder\\Plugin\\Gestionale\\Resources\\Contacts\\CustomerResource',
```

- [ ] **Step 6: Esegui i test**

Run: `php tests/ContactResourcesTest.php && php tests/ConventionsTest.php`
Expected: `0 falliti` per tutti e due.

> `DocsPagesTest` fallirà finché la guida non esiste: la scrive il task 4. Se
> dà fastidio, esegui prima lo Step 3 del task 4.

- [ ] **Step 7: Commit**

```bash
git add -- src/Resources/Contacts tests/ContactResourcesTest.php tests/ConventionsTest.php
git commit -m "Anagrafiche: clienti e fornitori, due elenchi e una scheda"
```

---

## Task 4: Riquadro della home, dati di prova, guida

**Files:**
- Create: `src/Backend/Widgets/ContactsWidget.php`,
  `src/Seeding/ContactsDemo.php`, `docs/user/anagrafiche.md`
- Modify: `config/module.php`, `src/Seeding/Demo.php`,
  `docs/user/SUMMARY.md`
- Test: `tests/integrazione/ContactsTest.php`, `tests/WidgetsTest.php`

**Interfaces:**
- Consumes: `Contacts::displayName()`, `Contact`, `ContactAddress`,
  `CustomerResource`, `SupplierResource`, `Gestionale::feature()`.
- Produces: `ContactsDemo::seed(DemoData $rows): int`, `ContactsDemo::clear(): int`.

- [ ] **Step 1: Guarda come è fatto un seeder e un riquadro**

Run: `sed -n '1,60p' src/Seeding/Demo.php && sed -n '1,40p' src/Backend/Widgets/SetupWidget.php`
Expected: il contratto di `DemoData` e quello di `HomeWidget`, da seguire.

- [ ] **Step 2: Scrivi il test d'integrazione**

`tests/integrazione/ContactsTest.php`:

```php
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

try {
    Transaction::run(static function (): void {
        $creato = Contact::create([
            'type' => 'business',
            'business_name' => 'Prova Rossi Srl',
            'country' => 'IT',
            'pi' => '98765432103',
            'email' => 'prova-rossi@example.com',
            'is_customer' => 'true',
            'active' => 'true',
        ]);
        $contactId = (int) ($creato->insert_id ?? 0);

        check('la scheda nasce con il suo codice', function () use ($contactId) {
            $riga = Contact::findById($contactId);

            return str_starts_with((string) ($riga['code'] ?? ''), 'con_');
        });

        check('il nome da mostrare è la ragione sociale', function () use ($contactId) {
            return Contacts::displayName((array) Contact::findById($contactId)) === 'Prova Rossi Srl';
        });

        check('una partita IVA già presa viene rifiutata con il nome di chi ce l\'ha', function () {
            try {
                CustomerResource::mutateRequestValues(
                    ['is_customer' => 'true', 'pi' => 'IT01234567897'],
                    'update',
                    'backend',
                    ['id' => 0]
                );
            } catch (UserError $errore) {
                return $errore->key() === 'contact.vat_taken'
                    && str_contains($errore->getMessage(), 'Prova Rossi Srl');
            }

            return false;
        });

        check('la stessa scheda può risalvare la sua partita IVA', function () use ($contactId) {
            $valori = CustomerResource::mutateRequestValues(
                ['is_customer' => 'true', 'pi' => 'IT01234567897'],
                'update',
                'backend',
                ['id' => $contactId]
            );

            return ($valori['pi'] ?? '') === 'IT01234567897';
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

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento la rubrica è come prima', function () {
    $riga = Contact::find(['email' => 'prova-rossi@example.com', 'deleted' => 'false'], 1);

    return !is_array($riga) || $riga === [];
});

summary();
```

- [ ] **Step 3: Scrivi la guida e mettila nel sommario**

`docs/user/anagrafiche.md`:

```markdown
---
icon: people
---

# Clienti e fornitori

> **Inclusa.** I clienti ci sono sempre. I **fornitori** compaiono solo con la
> funzionalità *Acquisti* sbloccata.

## Una scheda, una persona

Clienti e fornitori sono due elenchi della **stessa rubrica**. La stessa
azienda a cui vendi e da cui compri è **una scheda sola**, con i due ruoli
accesi: compare in tutti e due gli elenchi e si modifica in un posto solo.

Per questo partita IVA, codice fiscale ed email sono **unici**: se li riscrivi
su una scheda nuova, il gestionale ti dice di chi sono già e ti lascia aprire
quella invece di crearne una seconda.

## Privato o azienda

Il campo **Tipo** decide il resto della scheda: a un privato non vengono
chiesti ragione sociale, partita IVA, SDI e PEC. Partita IVA e codice fiscale
li controlla il gestionale: se sono sbagliati, il salvataggio si ferma e te lo
dice.

## Indirizzi di consegna

L'indirizzo della scheda è quello **di fatturazione**. Dove si consegna si
scrive nel riquadro *Indirizzi di consegna*: uno per magazzino, uno per casa,
uno per cantiere. Il **destinatario** è chi il corriere deve cercare, e spesso
non è chi compra.

## Eliminare o disattivare

Una scheda con un **account sul sito** non si elimina: chi ha le credenziali
resterebbe senza scheda. E appena ci saranno ordini, varrà lo stesso per chi ha
comprato. La strada giusta è metterla su **Non attiva**: sparisce dal lavoro di
ogni giorno e la storia resta.
```

In `docs/user/SUMMARY.md`, dopo il gruppo `## Magazzino`:

```markdown
## Anagrafiche

* [Clienti e fornitori](anagrafiche.md)
```

- [ ] **Step 4: Scrivi il riquadro della home**

`src/Backend/Widgets/ContactsWidget.php`: un `HomeWidget` con titolo
"Anagrafiche", `order()` fra quelli esistenti, che conta le righe vive di
`gst_contacts` con `is_customer = 'true'` e, **solo con `purchasing`
sbloccata**, quelle con `is_supplier = 'true'`, e linka i due elenchi con
`CustomerResource::path()` / `SupplierResource::path()`. Segui `markup()` di
`AttentionWidget`: `wi-card`, titolo `h5`, testo `text-body-secondary small`,
e `htmlspecialchars()` su tutto quello che viene dal database.

Registralo in `config/module.php`, dentro `home_widgets`, dopo
`AttentionWidget::class`.

- [ ] **Step 5: Scrivi i dati di prova**

`src/Seeding/ContactsDemo.php`, sul modello di `CatalogDemo`: quattro schede
con il prefisso dei dati di prova —

1. **privato cliente**: nome, cognome, email, telefono, indirizzo;
2. **azienda cliente**: ragione sociale, partita IVA valida, SDI, PEC, più un
   indirizzo di consegna diverso da quello di fatturazione;
3. **due fornitori** azienda, con partita IVA.

`clear()` toglie prima gli indirizzi e poi le schede (la chiave esterna non
lascia andare una scheda con indirizzi), contando tutte le righe tolte.
Aggiungilo all'elenco dei seeder in `src/Seeding/Demo.php` accanto a
`CatalogDemo`.

- [ ] **Step 6: Prova il comando sul sito**

Run: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge gestionale:demo --fresh`
Expected: "Cancellati N" e "Creati N" con lo **stesso numero**, senza errori.

- [ ] **Step 7: Esegui tutta la suite**

Run: `php tests/run.php`
Expected: "Tutti i test del gestionale passano."

- [ ] **Step 8: Commit**

```bash
git add -- src/Backend/Widgets src/Seeding config/module.php docs tests
git commit -m "Anagrafiche: riquadro della home, dati di prova e guida"
```

---

## Task 5: Verifica nel browser e chiusura

**Files:**
- Modify: `TODO.md`

- [ ] **Step 1: Verifica nel browser**

Su `https://ecommerce.test/backend/`:

1. nel menu c'è la sezione **Anagrafiche** con la sola voce *Clienti*
   (gli acquisti nascono bloccati);
2. l'elenco mostra le schede di prova con nome, email, città, ruolo e stato;
3. **Aggiungi cliente** → scegliendo *Privato* spariscono ragione sociale,
   partita IVA, SDI e PEC; scegliendo *Azienda* tornano;
4. salvando un'azienda con una **partita IVA non valida** il salvataggio si
   ferma con il messaggio del framework;
5. riscrivendo la partita IVA di un'altra scheda, il rifiuto **dice di chi è**;
6. una scheda senza nessun ruolo non si salva;
7. si aggiunge un indirizzo di consegna, si salva, si riapre: è ancora lì (è il
   trabocchetto dei repeater — un campo non stampato non viene postato);
8. sbloccando *Acquisti* in **Set Up → Funzionalità** compare la voce
   *Fornitori* e, nella scheda, l'interruttore "È anche un fornitore";
9. accendendo il ruolo fornitore su un cliente, la scheda compare in tutti e
   due gli elenchi;
10. il riquadro **Anagrafiche** della home conta le schede e i link portano
    agli elenchi giusti.

Rimetti *Acquisti* su bloccata quando hai finito.

- [ ] **Step 2: Ripulisci il sito di prova**

Run: `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge gestionale:demo --fresh`
Expected: la rubrica torna alle sole schede di prova.

- [ ] **Step 3: Spunta il piano nel TODO**

In `TODO.md`, sotto **G2b**, sostituisci la riga del piano 3 con una voce `[x]`
che racconta cosa è stato fatto e cosa è emerso, sullo stile delle altre.

- [ ] **Step 4: Unisci e pubblica**

```bash
php tests/run.php
git add -- TODO.md
git commit -m "G2b: piano 3 eseguito, anagrafiche"
git checkout main
git merge --no-ff feature/anagrafiche -m "Anagrafiche (piano 3 di G2b)"
git branch -d feature/anagrafiche
git push
```

Expected: suite verde, push riuscito, CI verde.
