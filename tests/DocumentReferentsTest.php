<?php
/** php tests/DocumentReferentsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Contacts\DocumentReferents;

// Le etichette delle aree chiedono `__t()`, che esiste solo a sito avviato:
// qui una finta che legge i file di lingua come il core.
if (!function_exists('__t')) {
    function __t(string $key, array $replacements = []): string
    {
        static $file = [];
        [$nome, $resto] = explode('.', $key, 2) + [1 => ''];
        $percorso = $nome === 'contacts'
            ? __DIR__.'/../vendor/wonder-image/customer-account/lang/it/contacts.json'
            : __DIR__.'/../lang/it/'.$nome.'.json';
        $file[$nome] ??= (array) json_decode((string) @file_get_contents($percorso), true);
        $nodo = $file[$nome];

        foreach (explode('.', $resto) as $passo) {
            $nodo = is_array($nodo) ? ($nodo[$passo] ?? null) : null;
        }

        return is_string($nodo) ? $nodo : $key;
    }
}

function riga(int $id, array $legame = [], array $persona = []): array
{
    return $legame + [
        'company_id' => 10,
        'person_id' => $id,
        'job_title' => '',
        'is_primary' => 'false',
        'receives_documents' => 'true',
        'deleted' => 'false',
        'contact' => $persona + [
            'id' => $id,
            'type' => 'private',
            'name' => 'Nome'.$id,
            'surname' => 'Cognome'.$id,
            'email' => 'p'.$id.'@example.com',
            'deleted' => 'false',
        ],
    ];
}

$righe = [
    riga(1, ['job_title' => 'Acquisti', 'is_primary' => 'true'], ['email' => ' P1@Example.com ']),
    riga(2),
    riga(3, ['receives_documents' => 'false']),
    riga(4, ['deleted' => 'true']),
    riga(5, [], ['deleted' => 'true']),
    riga(2),
];

DocumentReferents::useReader(fn (int $id): array => $id === 10 ? $righe : []);

ContactRelations::useEnabled(false);

check('con i referenti spenti non c\'è niente da scegliere', fn () =>
    DocumentReferents::options(10) === []
    && DocumentReferents::defaultFor(10) === 0
    && DocumentReferents::copyEmails(['company_id' => 10, 'email' => 'x@example.com']) === []
);

check('con i referenti spenti un referente passato diventa nessuno', fn () =>
    DocumentReferents::resolve(10, 1) === ['customer_id' => 0, 'referent_name' => '']
);

ContactRelations::useEnabled(true);

check('restano i legami vivi, senza doppioni: «Riceve i documenti» non filtra più', fn () =>
    array_column(DocumentReferents::options(10), 'id') === [1, 2, 3]
);

check('l\'opzione porta nome, email ripulita, ruolo e principale', function () {
    $prima = DocumentReferents::options(10)[0];

    return $prima['name'] === 'Cognome1 Nome1'
        && $prima['email'] === 'p1@example.com'
        && $prima['job_title'] === 'Acquisti'
        && $prima['role'] === 'Acquisti' && $prima['departments'] === []
        && $prima['is_primary'] === true;
});

check('il primo della scheda è il referente proposto', fn () => DocumentReferents::defaultFor(10) === 1);

check('il proposto non dipende da «Principale»: il primo della scheda; senza referenti nessuno', function () use ($righe) {
    DocumentReferents::useReader(fn (): array => [riga(2), riga(6, ['is_primary' => 'true'])]);
    $primo = DocumentReferents::defaultFor(10) === 2;
    DocumentReferents::useReader(fn (): array => []);
    $nessuno = DocumentReferents::defaultFor(10) === 0;
    DocumentReferents::useReader(fn (int $id): array => $id === 10 ? $righe : []);

    return $primo && $nessuno;
});

check('con il brand si propone il primo fra chi segue quel brand', function () use ($righe) {
    // Il 7 segue solo il brand 4; l'1 li segue tutti ed è il primo della scheda.
    $perBrand = static fn (int $id, int $brand = 0): array => match ($brand) {
        4 => [riga(7, ['is_primary' => 'true']), riga(2)],
        5 => [riga(2)],
        default => [riga(1, ['is_primary' => 'true']), riga(7, ['is_primary' => 'true']), riga(2)],
    };
    DocumentReferents::useReader($perBrand);
    $out = DocumentReferents::defaultFor(10, 4) === 7
        && DocumentReferents::defaultFor(10) === 1
        && DocumentReferents::defaultFor(10, 0) === 1;
    DocumentReferents::useReader(fn (int $id): array => $id === 10 ? $righe : []);

    return $out;
});

check('se nessuno segue il brand, il primo di tutti', function () use ($righe) {
    $letti = [];
    DocumentReferents::useReader(static function (int $id, int $brand = 0) use (&$letti): array {
        $letti[] = $brand;

        return $brand === 5 ? [] : [riga(1), riga(2, ['is_primary' => 'true'])];
    });
    $out = DocumentReferents::defaultFor(10, 5) === 1 && $letti === [5, 0];
    DocumentReferents::useReader(fn (int $id): array => $id === 10 ? $righe : []);

    return $out;
});

check('un referente valido si copia con il suo nome', fn () =>
    DocumentReferents::resolve(10, 2) === ['customer_id' => 2, 'referent_name' => 'Cognome2 Nome2']
);

check('fra le persone dell\'azienda c\'è anche chi aveva «Riceve i documenti» spento', fn () =>
    array_column(DocumentReferents::people(10), 'id') === [1, 2, 3]
    && DocumentReferents::resolve(10, 3) === ['customer_id' => 3, 'referent_name' => 'Cognome3 Nome3']
);

check('un referente cancellato o di un\'altra azienda diventa nessuno', fn () =>
    DocumentReferents::resolve(10, 4) === ['customer_id' => 0, 'referent_name' => '']
    && DocumentReferents::resolve(11, 1) === ['customer_id' => 0, 'referent_name' => '']
    && DocumentReferents::resolve(10, 0) === ['customer_id' => 0, 'referent_name' => '']
);

check('in copia vanno l\'azienda e il referente scelto, non chi riceve già la mail', fn () =>
    DocumentReferents::copyEmails(
        ['company_id' => 10, 'customer_id' => 1, 'email' => 'P2@example.com'],
        ['id' => 10, 'email' => 'info@rossi.it'],
    ) === ['info@rossi.it', 'p1@example.com']
    && DocumentReferents::copyEmails(
        ['company_id' => 10, 'customer_id' => 2, 'email' => 'P2@example.com'],
        ['id' => 10, 'email' => 'info@rossi.it'],
    ) === ['info@rossi.it']
);

check('nessun referente riceve copie da solo: senza referente scelto solo l\'azienda', fn () =>
    DocumentReferents::copyEmails(
        ['company_id' => 10, 'email' => 'x@example.com'],
        ['id' => 10, 'email' => 'info@rossi.it'],
    ) === ['info@rossi.it']
    && DocumentReferents::copyEmails(
        ['company_id' => 10, 'email' => 'info@rossi.it'],
        ['id' => 10, 'email' => 'info@rossi.it'],
    ) === []
);

check('il referente scelto riceve la copia anche se aveva «Riceve i documenti» spento', fn () =>
    DocumentReferents::copyEmails(
        ['company_id' => 10, 'customer_id' => 3, 'email' => 'info@rossi.it'],
        ['id' => 10, 'email' => 'info@rossi.it'],
    ) === ['p3@example.com']
);

check('una scheda che non è l\'azienda del documento non va in copia', fn () =>
    DocumentReferents::copyEmails(
        ['company_id' => 10, 'customer_id' => 2, 'email' => 'p1@example.com'],
        ['id' => 3, 'email' => 'privato@example.com'],
    ) === ['p2@example.com']
);

check('un\'azienda senza referenti: in copia le sue email (la principale se non ne ha per l\'area), mai chi riceve già', function () {
    DocumentReferents::useCompanyReader(static fn (): array => []);
    $principale = DocumentReferents::copyEmails(['company_id' => 11, 'email' => 'x@example.com'], ['id' => 11, 'email' => 'info@altra.it']);
    $stessa = DocumentReferents::copyEmails(['company_id' => 11, 'email' => 'info@altra.it'], ['id' => 11, 'email' => 'info@altra.it']);
    DocumentReferents::useCompanyReader(null);

    return $principale === ['info@altra.it'] && $stessa === [];
});

check('un errore di lettura lascia l\'elenco vuoto', function () {
    DocumentReferents::useReader(fn (): array => throw new RuntimeException('db giù'));
    $out = DocumentReferents::options(10) === [];
    DocumentReferents::useReader(null);

    return $out;
});

// Le aree (#«Si occupa di»): `peopleFor()` di customer-account, qui imitato
// dal lettore finto: chi si occupa dell'area o di tutto, e segue il brand o tutti.
$aree = [
    riga(1, ['is_primary' => 'true', 'departments' => []]),
    riga(2, ['departments' => ['purchasing']]),
    riga(3, ['departments' => ['administration']]),
    riga(4, ['departments' => ['purchasing'], 'receives_documents' => 'false']),
    riga(5, ['departments' => ['purchasing'], 'brand_ids' => [9]]),
];
$perArea = static function (int $id, int $brand = 0, string $area = '') use ($aree): array {
    return array_values(array_filter($aree, static fn (array $r): bool =>
        ($area === '' || ($r['departments'] ?? []) === [] || in_array('general', $r['departments'], true) || in_array($area, $r['departments'], true))
        && ($brand === 0 || !isset($r['brand_ids']) || in_array($brand, $r['brand_ids'], true))));
};

check('ogni documento ha la sua area, in un punto solo; un documento sconosciuto nessuna', function () {
    $prima = DocumentReferents::area('delivery_note_sent');
    DocumentReferents::useArea('delivery_note_sent', 'technical');
    $dopo = DocumentReferents::area('delivery_note_sent');
    DocumentReferents::useArea('delivery_note_sent', DocumentReferents::AREAS['delivery_note_sent']);

    return DocumentReferents::area('quote_sent') === 'purchasing'
        && DocumentReferents::area('office_confirmed') === 'purchasing'
        && DocumentReferents::area('subscription.proforma') === 'administration'
        && DocumentReferents::area('subscription.payment_failed') === 'administration'
        && DocumentReferents::area('order.whatever') === ''
        && $prima === 'purchasing' && $dopo === 'technical';
});

check('per il preventivo chi si occupa degli acquisti o di tutto, nell\'ordine della scheda', function () use ($perArea) {
    DocumentReferents::useReader($perArea);
    $ids = array_column(DocumentReferents::recipients(10, 'quote_sent'), 'id');
    $proforma = array_column(DocumentReferents::recipients(10, 'subscription.proforma'), 'id');
    $brand = array_column(DocumentReferents::recipients(10, 'quote_sent', 7), 'id');

    return $ids === [1, 2, 4, 5] && $proforma === [1, 3] && $brand === [1, 2, 4];
});

check('un referente con ufficio «Generale» risponde per ogni area', function () use ($aree) {
    $conGenerale = [...$aree, riga(8, ['departments' => ['general']])];
    DocumentReferents::useReader(static fn (int $id, int $brand = 0, string $area = ''): array => array_values(array_filter(
        $conGenerale,
        static fn (array $r): bool => \Wonder\Plugin\CustomerAccount\Support\ContactRelations::coversDepartment($r['departments'] ?? [], $area)
    )));

    return array_column(DocumentReferents::recipients(10, 'subscription.proforma'), 'id') === [1, 3, 8]
        && array_column(DocumentReferents::recipients(10, 'quote_sent'), 'id') === [1, 2, 4, 5, 8];
});

check('se nessuno corrisponde all\'area: tutti i referenti', function () {
    // Nessuno si occupa della direzione né di tutto: l'area non trova nessuno.
    $soloAree = [riga(2, ['departments' => ['purchasing']]), riga(3, ['departments' => ['administration']]), riga(6, ['receives_documents' => 'false', 'is_primary' => 'true'])];
    DocumentReferents::useReader(static fn (int $id, int $brand = 0, string $area = ''): array => array_values(array_filter(
        $soloAree,
        static fn (array $r): bool => $area === '' || in_array($area, $r['departments'] ?? [], true)
    )));
    DocumentReferents::useArea('quote_sent', 'management');
    $ripiego = array_column(DocumentReferents::recipients(10, 'quote_sent'), 'id');
    DocumentReferents::useArea('quote_sent', 'purchasing');
    $nessunaArea = array_column(DocumentReferents::recipients(10, 'order.whatever'), 'id');

    return $ripiego === [2, 3, 6] && $nessunaArea === [2, 3, 6];
});

check('un\'azienda che non compila le aree: tutti i referenti', function () use ($righe) {
    DocumentReferents::useReader(fn (int $id): array => $id === 10 ? $righe : []);

    return array_column(DocumentReferents::recipients(10, 'quote_sent'), 'id') === [1, 2, 3]
        && array_column(DocumentReferents::recipients(10, 'subscription.reminder'), 'id') === [1, 2, 3];
});

check('in copia area e brand non aggiungono referenti: solo quello scelto sul documento', function () use ($perArea) {
    DocumentReferents::useReader($perArea);
    $funzionalita = new ReflectionProperty(Gestionale::class, 'features');
    $ordine = ['company_id' => 10, 'email' => 'info@rossi.it', 'company_brand_id' => 9];
    $funzionalita->setValue(null, ['companies' => true, 'brands' => true]);
    $preventivo = DocumentReferents::copyEmails($ordine, ['id' => 10, 'email' => 'info@rossi.it'], 'quote_sent');
    $scelto = DocumentReferents::copyEmails(['customer_id' => 3] + $ordine, ['id' => 10, 'email' => 'info@rossi.it'], 'quote_sent');
    $funzionalita->setValue(null, null);
    $proforma = DocumentReferents::copyEmails(['company_id' => 10, 'customer_id' => 2, 'email' => 'info@rossi.it'], ['id' => 10, 'email' => 'info@rossi.it'], 'subscription.proforma');

    return $preventivo === [] && $scelto === ['p3@example.com'] && $proforma === ['p2@example.com'];
});

check('senza mansione il ruolo sono le aree di cui si occupa', function () use ($perArea) {
    DocumentReferents::useReader($perArea);
    $terzo = DocumentReferents::people(10)[2];

    return $terzo['departments'] === ['administration'] && $terzo['role'] === 'Amministrazione'
        && DocumentReferents::areasLabel(['purchasing', 'administration', 'sconosciuta']) === 'Acquisti, Amministrazione'
        && DocumentReferents::areasLabel([]) === '';
});

check('i rappresentanti legali: solo chi ha la spunta, nell\'ordine della scheda, anche più d\'uno', function () {
    DocumentReferents::useReader(fn (): array => [
        riga(1, ['is_legal_representative' => 'true']),
        riga(2),
        riga(3, ['is_legal_representative' => 'true']),
    ]);
    $legali = DocumentReferents::legalRepresentatives(10);
    $tutti = DocumentReferents::people(10);
    DocumentReferents::useReader(fn (): array => [riga(2)]);
    $nessuno = DocumentReferents::legalRepresentatives(10);
    DocumentReferents::useReader(null);

    return array_column($legali, 'id') === [1, 3]
        && array_column($tutti, 'is_legal_representative') === [true, false, true]
        && $nessuno === [];
});

// --- Contatti di lavoro ed email dell'azienda per area ---

check('il referente si scrive all\'email per quell\'azienda (di lavoro se c\'è) con il telefono di lavoro', function () {
    $righe = DocumentReferents::fromRows([
        riga(1, ['contact_email' => 'anna@rossi.it', 'work_email' => 'anna@rossi.it', 'work_phone_prefix' => '+39', 'work_phone' => '0212345'], ['phone_prefix' => '+39', 'phone' => '333']),
        riga(2, ['contact_email' => 'p2@example.com', 'work_email' => ''], ['phone_prefix' => '+39', 'phone' => '3401234567']),
        riga(3, [], ['email' => 'P3@Example.com']),
    ]);

    return array_column($righe, 'email') === ['anna@rossi.it', 'p2@example.com', 'p3@example.com']
        && array_column($righe, 'phone') === ['+39 0212345', '+39 3401234567', ''];
});

check('le email dell\'azienda: quelle dell\'area del documento, altrimenti la principale della scheda', function () {
    $chieste = [];
    DocumentReferents::useCompanyReader(static function (int $id, string $area) use (&$chieste): array {
        $chieste[] = $area;

        return $area === 'administration' ? ['amministrazione@rossi.it', 'INFO@rossi.it'] : [];
    });
    $azienda = ['id' => 10, 'email' => 'info@rossi.it'];
    $sollecito = DocumentReferents::companyEmails(10, 'subscription.reminder', $azienda);
    $preventivo = DocumentReferents::companyEmails(10, 'quote_sent', $azienda);
    $senzaScheda = DocumentReferents::companyEmails(10, 'quote_sent');
    DocumentReferents::useCompanyReader(static fn (): array => throw new RuntimeException('db giù'));
    $errore = DocumentReferents::companyEmails(10, 'quote_sent', $azienda);
    DocumentReferents::useCompanyReader(null);

    return $sollecito === ['amministrazione@rossi.it', 'info@rossi.it'] && $preventivo === ['info@rossi.it']
        && $senzaScheda === [] && $errore === ['info@rossi.it']
        && $chieste === ['administration', 'purchasing', 'purchasing'];
});

check('in copia caselle dell\'azienda e referente scelto, senza doppioni né chi riceve già', function () use ($perArea) {
    DocumentReferents::useReader(static fn (int $id, int $brand = 0, string $area = ''): array => [
        riga(1, ['contact_email' => 'acquisti@rossi.it']),
        riga(2, ['contact_email' => 'luca@rossi.it']),
    ]);
    DocumentReferents::useCompanyReader(static fn (): array => ['acquisti@rossi.it', 'info@rossi.it']);
    $copie = DocumentReferents::copyEmails(['company_id' => 10, 'customer_id' => 2, 'email' => 'info@rossi.it'], ['id' => 10, 'email' => 'info@rossi.it'], 'quote_sent');
    $stessa = DocumentReferents::copyEmails(['company_id' => 10, 'customer_id' => 1, 'email' => 'info@rossi.it'], ['id' => 10, 'email' => 'info@rossi.it'], 'quote_sent');
    DocumentReferents::useCompanyReader(null);
    DocumentReferents::useReader(null);

    return $copie === ['acquisti@rossi.it', 'luca@rossi.it'] && $stessa === ['acquisti@rossi.it'];
});

check('l\'email di un referente per l\'azienda; vuota per chi non è suo referente', function () {
    DocumentReferents::useReader(static fn (): array => [riga(1, ['contact_email' => 'anna@rossi.it', 'receives_documents' => 'false'])]);
    $suo = DocumentReferents::emailOf(10, 1);
    $altro = DocumentReferents::emailOf(10, 9);
    DocumentReferents::useReader(null);

    return $suo === 'anna@rossi.it' && $altro === '' && DocumentReferents::emailOf(10, 0) === '';
});

check('il sollecito di un abbonamento va alla prima email dell\'azienda per l\'amministrazione, il referente per l\'azienda', function () {
    $codice = (string) file_get_contents(__DIR__.'/../src/Support/Subscriptions/SubscriptionNotifier.php');

    return str_contains($codice, "DocumentReferents::companyEmails(\$companyId, 'subscription.'.\$key, \$company)[0] ?? \$personEmail")
        && str_contains($codice, 'DocumentReferents::emailOf($companyId');
});

DocumentReferents::useReader(null);
ContactRelations::useEnabled(null);

summary();
