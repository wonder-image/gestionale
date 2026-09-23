<?php
/** php tests/RecipientsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Mail\Recipients;

check('virgola, punto e virgola, spazi e a capo separano tutti', fn () =>
    Recipients::parse("a@x.it; b@y.it,c@z.it\nd@w.it  e@v.it")['valid']
        === ['a@x.it', 'b@y.it', 'c@z.it', 'd@w.it', 'e@v.it']
);

check('i doppioni si tolgono senza guardare le maiuscole', fn () =>
    Recipients::parse('Anna@X.it, anna@x.it, b@y.it')['valid'] === ['Anna@X.it', 'b@y.it']
);

check('un indirizzo storto finisce fra i non validi, gli altri restano', function () {
    $esito = Recipients::parse('a@x.it, anna.x.it, b@y.it');

    return $esito['valid'] === ['a@x.it', 'b@y.it'] && $esito['invalid'] === ['anna.x.it'];
});

check('una casella vuota non ha destinatari', fn () =>
    Recipients::parse('  , ; ') === ['valid' => [], 'invalid' => []]
);

check('si salvano separati da virgola e spazio', fn () =>
    Recipients::join(['a@x.it', 'b@y.it']) === 'a@x.it, b@y.it'
);

summary();
