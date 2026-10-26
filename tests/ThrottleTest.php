<?php
/** php tests/ThrottleTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';

use Wonder\Plugin\Gestionale\Models\System\Throttle as ThrottleModel;
use Wonder\Plugin\Gestionale\Support\Throttle;

$root = dirname(__DIR__);
$throttle = (string) file_get_contents($root.'/src/Support/Throttle.php');

/** Il corpo di un metodo statico, privato o pubblico, fino al metodo dopo. */
function corpo(string $sorgente, string $metodo): string
{
    if (!preg_match('/(private|public) static function '.preg_quote($metodo, '/').'\\(/', $sorgente, $trovato, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $inizio = $trovato[0][1];
    $fine = preg_match('/(private|public) static function /', $sorgente, $dopo, PREG_OFFSET_CAPTURE, $inizio + 1) ? $dopo[0][1] : false;

    return substr($sorgente, $inizio, $fine === false ? null : $fine - $inizio);
}

// Il contatore su file scrive in una cartella temporanea, mai nel pacchetto.
$cartella = sys_get_temp_dir().'/wi-gst-throttle-'.getmypid();
Throttle::useDirectory($cartella);

check('allowed/hit: sotto il limite si prova, al limite no', function (): bool {
    for ($i = 0; $i < 3; $i++) {
        if (!Throttle::allowed('prova', 'a', 3, 60)) {
            return false;
        }
        Throttle::hit('prova', 'a', 60);
    }

    return !Throttle::allowed('prova', 'a', 3, 60);
});

check('allowed/hit: chiavi e gruppi diversi non si sommano', fn () =>
    Throttle::allowed('prova', 'b', 3, 60) && Throttle::allowed('altro', 'a', 3, 60));

check('allowed/hit: il file sta nella cartella data, col nome sha256(gruppo|chiave)', fn () =>
    is_file($cartella.'/'.hash('sha256', 'prova|a').'.json'));

check('allowed/hit: i tentativi fuori dalla finestra non contano più', function () use ($cartella): bool {
    Throttle::hit('vecchi', 'a', 60);
    Throttle::hit('vecchi', 'a', 60);
    // Si invecchiano a mano i tempi salvati: due minuti fa.
    $percorso = $cartella.'/'.hash('sha256', 'vecchi|a').'.json';
    $tempi = json_decode((string) file_get_contents($percorso), true);
    file_put_contents($percorso, json_encode(array_map(static fn (int $t): int => $t - 120, $tempi)));

    return count($tempi) === 2 && Throttle::allowed('vecchi', 'a', 2, 60);
});

check('allowed/hit: una chiave vuota non si limita e non scrive', function () use ($cartella): bool {
    $prima = count(glob($cartella.'/*.json') ?: []);
    Throttle::hit('prova', '', 60);

    return Throttle::allowed('prova', '', 0, 60) && count(glob($cartella.'/*.json') ?: []) === $prima;
});

check('allowed: un limite 0 o negativo non blocca, come attempt()', function (): bool {
    Throttle::hit('prova', 'zero', 60);

    return Throttle::allowed('prova', 'zero', 0, 60) && Throttle::allowed('prova', 'zero', -1, 60);
});

foreach (glob($cartella.'/*') ?: [] as $file) {
    unlink($file);
}
@rmdir($cartella);
Throttle::useDirectory(null);

check('la cartella di serie è storage/gestionale/throttle sotto la radice del sito', fn () =>
    str_contains(corpo($throttle, 'directory'), "'/storage/gestionale/throttle'")
    && str_contains(corpo($throttle, 'hit'), 'mkdir($directory, 0775, true)'));

// attempt(): la tabella si sostituisce con una finestra fissa in memoria, la stessa regola dell'upsert.
$righe = [];
$scarto = 0;
Throttle::useStore(function (string $hash, int $now, int $since) use (&$righe, &$scarto): int {
    $now += $scarto;
    $since += $scarto;
    $riga = $righe[$hash] ?? null;
    $scaduta = $riga !== null && $riga['window_start'] <= $since;
    $righe[$hash] = $riga === null || $scaduta
        ? ['hits' => 1, 'window_start' => $now]
        : ['hits' => $riga['hits'] + 1, 'window_start' => $riga['window_start']];

    return $righe[$hash]['hits'];
});

check('attempt: dentro il limite sì, oltre no', function (): bool {
    $esiti = [];
    for ($i = 0; $i < 4; $i++) {
        $esiti[] = Throttle::attempt('prova', 'x', 3, 60);
    }

    return $esiti === [true, true, true, false];
});

check('attempt: scaduta la finestra il conto riparte', function () use (&$scarto): bool {
    $scarto += 61;

    return Throttle::attempt('prova', 'x', 3, 60) && Throttle::attempt('prova', 'x', 3, 60);
});

check('attempt: chiave vuota o limite 0 non limitano e non contano', function () use (&$righe): bool {
    $prima = count($righe);

    return Throttle::attempt('prova', '', 1, 60) && Throttle::attempt('prova', '', 1, 60)
        && Throttle::attempt('prova', 'y', 0, 60) && Throttle::attempt('prova', 'y', 0, 60)
        && count($righe) === $prima;
});

check('attempt: gruppi diversi, contatori diversi (sha256 di gruppo|chiave)', function () use (&$righe): bool {
    Throttle::attempt('uno', 'k', 5, 60);
    Throttle::attempt('due', 'k', 5, 60);

    return isset($righe[hash('sha256', 'uno|k')], $righe[hash('sha256', 'due|k')]);
});

check('attempt: se il contatore non risponde il tentativo passa (fail-open)', function (): bool {
    Throttle::useStore(static function (): int {
        throw new RuntimeException('tabella assente');
    });

    return Throttle::attempt('prova', 'z', 1, 60) && Throttle::attempt('prova', 'z', 1, 60);
});
Throttle::useStore(null);

check('attempt: il fallimento va nel registro con Errors::internal, azione gestionale.throttle', fn () =>
    str_contains(corpo($throttle, 'attempt'), "Errors::internal(\$error, 'gestionale.throttle'")
    && str_contains(corpo($throttle, 'attempt'), 'return true;'));

check('UPSERT: un solo INSERT … ON DUPLICATE KEY UPDATE su gst_throttles, hits prima di window_start', fn () =>
    str_starts_with(Throttle::UPSERT, 'INSERT INTO gst_throttles ')
    && str_contains(Throttle::UPSERT, 'ON DUPLICATE KEY UPDATE')
    && strpos(Throttle::UPSERT, 'hits = IF(') < strpos(Throttle::UPSERT, 'window_start = IF(')
    && substr_count(Throttle::UPSERT, 'INSERT') === 1
    && str_contains(corpo($throttle, 'database'), 'SELECT hits FROM gst_throttles'));

check('ip: delega a Wonder\\Http\\ClientIp::resolve, niente REMOTE_ADDR letto a mano', fn () =>
    str_contains(corpo($throttle, 'ip'), 'ClientIp::resolve()')
    && !str_contains(corpo($throttle, 'ip'), 'REMOTE_ADDR'));

check('ip: senza APP_TRUST_PROXY un X-Forwarded-For falso non conta', function (): bool {
    $prima = $_SERVER;
    $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
    $ip = Throttle::ip();
    $_SERVER = $prima;

    return $ip === '203.0.113.7' || \Wonder\Http\Cookie::trustsProxy();
});

$modello = (string) file_get_contents($root.'/src/Models/System/Throttle.php');
$manifesto = json_decode((string) file_get_contents($root.'/module.json'), true);
check('Models\\System\\Throttle: tabella gst_throttles con chiave unica di 64, hits e window_start; il manifest la dichiara', fn () =>
    ThrottleModel::$table === 'gst_throttles'
    && ThrottleModel::dataSchema() === []
    && ThrottleModel::syncSchema() === null
    && preg_match("/Column::key\('throttle_key'\)->type\('CHAR'\)->length\(64\)->null\(false\)->unique\(\)/", $modello) === 1
    && str_contains($modello, "Column::key('hits')->int()")
    && str_contains($modello, "Column::key('window_start')->int()")
    && ($manifesto['database']['models'] ?? '') === 'src/Models');

summary();
