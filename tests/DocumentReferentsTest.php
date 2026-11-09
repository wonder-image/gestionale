<?php
/** php tests/DocumentReferentsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\Gestionale\Support\Contacts\DocumentReferents;

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
        && $prima['role'] === 'Acquisti' && $prima['department'] === ''
        && $prima['is_primary'] === true;
});

check('il principale è il referente proposto', fn () => DocumentReferents::defaultFor(10) === 1);

check('senza principale non si propone nessuno', function () use ($righe) {
    DocumentReferents::useReader(fn (): array => [riga(2), riga(6)]);
    $out = DocumentReferents::defaultFor(10) === 0;
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

ContactRelations::useEnabled(null);

summary();
