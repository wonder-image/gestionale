<?php
/** php tests/integrazione/DocumentSequencesTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Documents\DocumentSequence;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentSequences;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$tipo = 'test_doc_'.bin2hex(random_bytes(3));
$settembre = new DateTimeImmutable('2026-09-15 10:00:00');
$ottobre = new DateTimeImmutable('2026-10-01 10:00:00');

try {
    Transaction::run(static function () use ($tipo, $settembre, $ottobre): void {
        check('il primo documento del mese è 0001', fn () =>
            DocumentSequences::next($tipo, $settembre) === '2026/090001'
        );

        check('il secondo continua la sequenza', fn () =>
            DocumentSequences::next($tipo, $settembre) === '2026/090002'
        );

        check('un altro tipo di documento ha la sua sequenza', fn () =>
            DocumentSequences::next($tipo.'_b', $settembre) === '2026/090001'
        );

        check('il mese dopo riparte da 0001', fn () =>
            DocumentSequences::next($tipo, $ottobre) === '2026/100001'
        );

        check('la riga tiene l\'ultimo numero dato', function () use ($tipo) {
            $riga = DocumentSequence::find(['document_type' => $tipo, 'year' => 2026, 'month' => 9], 1);

            return (int) ($riga['last_number'] ?? 0) === 2;
        });

        check('senza data si usa adesso', function () use ($tipo) {
            $adesso = new DateTimeImmutable();
            $numero = DocumentSequences::next($tipo.'_c');

            return str_starts_with($numero, $adesso->format('Y').'/'.$adesso->format('m'));
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento non resta nessuna riga', function () use ($tipo) {
    $righe = DocumentSequence::find(['document_type' => $tipo]);

    return $righe === [] || $righe === null || $righe === false;
});

summary();
