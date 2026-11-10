<?php
/** php tests/DocumentReferentsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
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

check('restano solo i legami vivi che ricevono i documenti, senza doppioni', fn () =>
    array_column(DocumentReferents::options(10), 'id') === [1, 2]
);

check('l\'opzione porta nome, email ripulita, ruolo e principale', function () {
    $prima = DocumentReferents::options(10)[0];

    return $prima['name'] === 'Cognome1 Nome1'
        && $prima['email'] === 'p1@example.com'
        && $prima['job_title'] === 'Acquisti'
        && $prima['role'] === 'Acquisti' && $prima['departments'] === []
        && $prima['is_primary'] === true;
});

check('il principale è il referente proposto', fn () => DocumentReferents::defaultFor(10) === 1);

check('senza principale non si propone nessuno', function () use ($righe) {
    DocumentReferents::useReader(fn (): array => [riga(2), riga(6)]);
    $out = DocumentReferents::defaultFor(10) === 0;
    DocumentReferents::useReader(fn (int $id): array => $id === 10 ? $righe : []);

    return $out;
});

check('con il brand si propone il principale fra chi segue quel brand', function () use ($righe) {
    // Il 7 segue solo il brand 4 ed è principale; l'1 è principale e li segue tutti.
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

check('se fra chi segue il brand non c\'è un principale, il principale di tutti', function () use ($righe) {
    $letti = [];
    DocumentReferents::useReader(static function (int $id, int $brand = 0) use (&$letti): array {
        $letti[] = $brand;

        return $brand === 5 ? [riga(2)] : [riga(1, ['is_primary' => 'true']), riga(2)];
    });
    $out = DocumentReferents::defaultFor(10, 5) === 1 && $letti === [5, 0];
    DocumentReferents::useReader(fn (int $id): array => $id === 10 ? $righe : []);

    return $out;
});

check('un referente valido si copia con il suo nome', fn () =>
    DocumentReferents::resolve(10, 2) === ['customer_id' => 2, 'referent_name' => 'Cognome2 Nome2']
);

check('fra le persone dell\'azienda c\'è anche chi non riceve i documenti', fn () =>
    array_column(DocumentReferents::people(10), 'id') === [1, 2, 3]
    && DocumentReferents::resolve(10, 3) === ['customer_id' => 3, 'referent_name' => 'Cognome3 Nome3']
);

check('un referente cancellato o di un\'altra azienda diventa nessuno', fn () =>
    DocumentReferents::resolve(10, 4) === ['customer_id' => 0, 'referent_name' => '']
    && DocumentReferents::resolve(11, 1) === ['customer_id' => 0, 'referent_name' => '']
    && DocumentReferents::resolve(10, 0) === ['customer_id' => 0, 'referent_name' => '']
);

check('in copia vanno l\'azienda e i referenti, non chi riceve già la mail', fn () =>
    DocumentReferents::copyEmails(
        ['company_id' => 10, 'email' => 'P2@example.com'],
        ['id' => 10, 'email' => 'info@rossi.it'],
    ) === ['info@rossi.it', 'p1@example.com']
);

check('niente doppioni né indirizzi non validi in copia', fn () =>
    DocumentReferents::copyEmails(
        ['company_id' => 10, 'email' => 'info@rossi.it'],
        ['id' => 10, 'email' => 'info@rossi.it'],
    ) === ['p1@example.com', 'p2@example.com']
);

check('una scheda che non è l\'azienda del documento non va in copia', fn () =>
    DocumentReferents::copyEmails(
        ['company_id' => 10, 'email' => 'p1@example.com'],
        ['id' => 3, 'email' => 'privato@example.com'],
    ) === ['p2@example.com']
);

check('un\'azienda senza referenti non aggiunge copie, nemmeno la sua email', fn () =>
    DocumentReferents::copyEmails(
        ['company_id' => 11, 'email' => 'x@example.com'],
        ['id' => 11, 'email' => 'info@altra.it'],
    ) === []
);

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
        ($area === '' || ($r['departments'] ?? []) === [] || in_array($area, $r['departments'], true))
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

check('il preventivo va a chi si occupa degli acquisti o di tutto e riceve i documenti, il principale per primo', function () use ($perArea) {
    DocumentReferents::useReader($perArea);
    $ids = array_column(DocumentReferents::recipients(10, 'quote_sent'), 'id');
    $proforma = array_column(DocumentReferents::recipients(10, 'subscription.proforma'), 'id');
    $brand = array_column(DocumentReferents::recipients(10, 'quote_sent', 7), 'id');

    return $ids === [1, 2, 5] && $proforma === [1, 3] && $brand === [1, 2];
});

check('se nessuno corrisponde all\'area vale la regola di prima: tutti quelli che ricevono i documenti', function () {
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

    return $ripiego === [2, 3] && $nessunaArea === [2, 3];
});

check('un\'azienda che non compila le aree non cambia: tutti quelli che ricevono i documenti', function () use ($righe) {
    DocumentReferents::useReader(fn (int $id): array => $id === 10 ? $righe : []);

    return array_column(DocumentReferents::recipients(10, 'quote_sent'), 'id') === [1, 2]
        && array_column(DocumentReferents::recipients(10, 'subscription.reminder'), 'id') === [1, 2];
});

check('in copia l\'area del documento e il brand dell\'ordine, solo le email principali', function () use ($perArea) {
    DocumentReferents::useReader($perArea);
    $preventivo = DocumentReferents::copyEmails(['company_id' => 10, 'email' => 'info@rossi.it', 'company_brand_id' => 7], ['id' => 10, 'email' => 'info@rossi.it'], 'quote_sent');
    $proforma = DocumentReferents::copyEmails(['company_id' => 10, 'email' => 'info@rossi.it'], ['id' => 10, 'email' => 'info@rossi.it'], 'subscription.proforma');

    return $preventivo === ['p1@example.com', 'p2@example.com'] && $proforma === ['p1@example.com', 'p3@example.com'];
});

check('senza mansione il ruolo sono le aree di cui si occupa', function () use ($perArea) {
    DocumentReferents::useReader($perArea);
    $terzo = DocumentReferents::people(10)[2];

    return $terzo['departments'] === ['administration'] && $terzo['role'] === 'Amministrazione'
        && DocumentReferents::areasLabel(['purchasing', 'administration', 'sconosciuta']) === 'Acquisti, Amministrazione'
        && DocumentReferents::areasLabel([]) === '';
});

DocumentReferents::useReader(null);
ContactRelations::useEnabled(null);

summary();
