<?php
/** php tests/RowSignalsContactsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Backend\Table\RowSignal;
use Wonder\Backend\Table\RowSignals;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\Contacts\CompanyResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\SupplierResource;
use Wonder\Plugin\Gestionale\Support\Contacts\PartySignals;

// Senza sito avviato: `__t()` finto sul file del modulo, come negli altri test.
if (!function_exists('__t')) {
    function __t(string $key, array $replacements = []): string
    {
        static $dati = null;
        $dati ??= json_decode((string) file_get_contents(__DIR__.'/../lang/it/gestionale.json'), true);
        $nodo = str_starts_with($key, 'gestionale.') ? $dati : null;

        foreach (explode('.', substr($key, strlen('gestionale.'))) as $passo) {
            $nodo = is_array($nodo) ? ($nodo[$passo] ?? null) : null;
        }

        return is_string($nodo) ? $nodo : $key;
    }
}

$forza = static function (array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato);
};

$codici = static fn (array $segnali): array => array_map(static fn (RowSignal $s): string => $s->code, $segnali);

check('ogni lista ha i suoi segnali', fn () =>
    $codici(PartySignals::companies()) === ['missing_brand', 'missing_contact', 'missing_referent', 'missing_billing']
    && $codici(PartySignals::contacts()) === ['missing_name', 'missing_surname', 'missing_email']
    && $codici(PartySignals::suppliers()) === ['missing_contact', 'missing_billing']
);

check('ogni segnale è giallo e ha il suo testo', function () {
    foreach ([...PartySignals::companies(), ...PartySignals::contacts()] as $segnale) {
        if ($segnale->level !== RowSignal::WARNING || str_starts_with($segnale->message, 'gestionale.')) {
            return false;
        }
    }

    return true;
});

check('le condizioni puntano alla tabella della lista', function () {
    foreach ([...PartySignals::companies(), ...PartySignals::contacts()] as $segnale) {
        $sql = $segnale->sql('t_lista');
        if (str_contains($sql, '{table}') || !str_contains($sql, 't_lista.')) {
            return false;
        }
    }

    return true;
});

check('fatturazione: in Italia anche P.IVA o CF e SDI o PEC', function () {
    $sql = PartySignals::missingBilling()->sql('t');

    return str_contains($sql, "UPPER(TRIM(t.country)) = 'IT'")
        && str_contains($sql, 't.pi') && str_contains($sql, 't.cf')
        && str_contains($sql, 't.sdi') && str_contains($sql, 't.pec')
        && str_contains($sql, 't.business_name');
});

check('il brand vale solo per i clienti e con i brand accesi', function () use ($forza) {
    $forza(['brands' => false]);
    $spento = PartySignals::missingBrand()->active();

    return !$spento && str_contains(PartySignals::missingBrand()->sql('t'), "t.is_customer = 'true'");
});

check('ogni Resource legge la sua lista', fn () =>
    CustomerResource::signalList() === PartySignals::CONTACTS
);

check('aziende e fornitori hanno liste diverse', fn () =>
    CompanyResource::signalList() === PartySignals::COMPANIES
    && SupplierResource::signalList() === PartySignals::SUPPLIERS
);

check('il sito aggiunge e toglie segnali sulla lista', function () use ($codici) {
    RowSignals::reset();
    RowSignals::add(PartySignals::SUPPLIERS, RowSignal::warning('missing_website', "{table}.website = ''", 'Manca il sito'));
    RowSignals::remove(PartySignals::SUPPLIERS, 'missing_contact');
    $codes = $codici(SupplierResource::rowSignals());
    RowSignals::reset();

    return $codes === ['missing_billing', 'missing_website'];
});

check('il pallino è la prima colonna della lista', function () {
    $prima = CompanyResource::tableSchema()[0] ?? null;

    return $prima !== null && str_contains(get_class($prima), 'TableColumn');
});

summary();
