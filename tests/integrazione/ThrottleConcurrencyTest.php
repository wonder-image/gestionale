<?php
/**
 * php tests/integrazione/ThrottleConcurrencyTest.php
 *
 * Throttle::attempt() sulla tabella vera: N processi in parallelo sulla stessa
 * chiave, ne passano al massimo `limit`.
 */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';

use Wonder\Plugin\Gestionale\Support\Throttle;
use Wonder\Sql\Connection;

// Figlio: un solo tentativo, l'esito su stdout.
if (($argv[1] ?? '') === '--figlio') {
    echo Throttle::attempt('test_concorrenza', (string) $argv[2], (int) $argv[3], 60) ? '1' : '0';
    exit(0);
}

require __DIR__.'/../harness.php';

$connessione = Connection::Connect('main');
if (!$connessione->query("SHOW TABLES LIKE 'gst_throttles'")->num_rows) {
    fwrite(STDERR, "Manca gst_throttles: lancia `php forge update --local` nel sito.\n");
    exit(1);
}

$chiave = 'k'.bin2hex(random_bytes(6));
$hash = hash('sha256', 'test_concorrenza|'.$chiave);
register_shutdown_function(static function () use ($connessione, $hash): void {
    $delete = $connessione->prepare('DELETE FROM gst_throttles WHERE throttle_key = ?');
    $delete->execute([$hash]);
});
$limite = 3;
$processi = 12;

$aperti = [];
$tubi = [];
for ($i = 0; $i < $processi; $i++) {
    $aperti[] = proc_open([PHP_BINARY, __FILE__, '--figlio', $chiave, (string) $limite], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe);
    $tubi[] = $pipe;
}
$esiti = [];
foreach ($aperti as $i => $processo) {
    $esiti[] = trim((string) stream_get_contents($tubi[$i][1]));
    proc_close($processo);
}

check("{$processi} tentativi in parallelo: ne passano al massimo {$limite}", fn () =>
    count($esiti) === $processi && substr_count(implode('', $esiti), '1') <= $limite && substr_count(implode('', $esiti), '1') >= 1);

check('la riga in gst_throttles conta tutti i tentativi della finestra', function () use ($connessione, $hash, $processi): bool {
    $select = $connessione->prepare('SELECT hits FROM gst_throttles WHERE throttle_key = ?');
    $select->execute([$hash]);

    return (int) ($select->get_result()->fetch_assoc()['hits'] ?? 0) === $processi;
});

check('finestra scaduta: il conto riparte da 1', function () use ($connessione, $chiave, $hash): bool {
    $update = $connessione->prepare('UPDATE gst_throttles SET window_start = window_start - 120 WHERE throttle_key = ?');
    $update->execute([$hash]);

    $passa = Throttle::attempt('test_concorrenza', $chiave, 3, 60);
    $select = $connessione->prepare('SELECT hits FROM gst_throttles WHERE throttle_key = ?');
    $select->execute([$hash]);

    return $passa && (int) ($select->get_result()->fetch_assoc()['hits'] ?? 0) === 1;
});

summary();
