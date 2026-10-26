<?php

namespace Wonder\Plugin\Gestionale\Support;

use RuntimeException;
use Throwable;
use Wonder\Http\ClientIp;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Sql\Connection;

/**
 * Limiti di tentativi in una finestra di tempo.
 *
 * `attempt()` conta e decide in un colpo solo, sulla tabella
 * `gst_throttles` (Models\System\Throttle), a finestra fissa.
 * `allowed()` e `hit()` restano su file: ogni coppia gruppo e chiave è un
 * file con gli istanti dei tentativi; quelli fuori dalla finestra si buttano
 * a ogni scrittura e il file vuoto se ne va.
 */
final class Throttle
{
    // `hits` prima di `window_start`: MySQL assegna da sinistra e il secondo IF deve vedere la finestra vecchia.
    public const UPSERT = 'INSERT INTO gst_throttles (throttle_key, hits, window_start) VALUES (?, 1, ?)'
        .' ON DUPLICATE KEY UPDATE hits = IF(window_start <= ?, 1, hits + 1),'
        .' window_start = IF(window_start <= ?, ?, window_start)';

    private static ?string $directory = null;

    /** @var callable|null Contatore sostituibile nei test: fn(string $hash, int $now, int $since): int, i tentativi dopo questo. */
    private static $store = null;

    /** Il contatore di attempt(); null torna alla tabella. Serve ai test. */
    public static function useStore(?callable $store): void
    {
        self::$store = $store;
    }

    /**
     * Conta un tentativo e dice se rientra nel limite: al massimo `$limit`
     * per finestra di `$window` secondi. Una chiave vuota o un limite a 0 non
     * limitano. Se la tabella non risponde il tentativo passa e l'errore va
     * nel registro: un limite rotto non ferma le vendite.
     */
    public static function attempt(string $bucket, string $key, int $limit, int $window): bool
    {
        if ($key === '' || $limit <= 0 || $window <= 0) {
            return true;
        }

        $now = time();
        try {
            $hits = (self::$store ?? self::database(...))(hash('sha256', $bucket.'|'.$key), $now, $now - $window);
        } catch (Throwable $error) {
            Errors::internal($error, 'gestionale.throttle', ['bucket' => $bucket]);

            return true;
        }

        return $hits <= $limit;
    }

    /** La cartella dei contatori; null torna a quella del sito. Serve ai test. */
    public static function useDirectory(?string $directory): void
    {
        self::$directory = $directory === null ? null : rtrim($directory, '/');
    }

    /** Si può ancora provare: i tentativi nella finestra sono meno del limite. Una chiave vuota non ha limite. */
    public static function allowed(string $bucket, string $key, int $limit, int $window): bool
    {
        if ($key === '') {
            return true;
        }

        $file = self::file($bucket, $key);
        if (!is_file($file)) {
            return true;
        }

        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return true;
        }

        flock($handle, LOCK_SH);
        $times = self::recent((string) stream_get_contents($handle), $window);
        flock($handle, LOCK_UN);
        fclose($handle);

        return count($times) < $limit;
    }

    /** Conta un tentativo adesso. */
    public static function hit(string $bucket, string $key, int $window): void
    {
        if ($key === '') {
            return;
        }

        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $file = self::file($bucket, $key);
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return;
        }

        flock($handle, LOCK_EX);
        $times = self::recent((string) stream_get_contents($handle), $window);
        $times[] = time();
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($times));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** L'indirizzo di chi chiama, quello del core: dietro un proxy fidato (APP_TRUST_PROXY) l'ultima voce di X-Forwarded-For. */
    public static function ip(): string
    {
        return ClientIp::resolve();
    }

    /**
     * L'upsert e la rilettura sulla stessa connessione. Con richieste in
     * parallelo la rilettura può vedere anche il tentativo di un altro: si
     * blocca di più, mai di meno.
     */
    private static function database(string $hash, int $now, int $since): int
    {
        $connection = Connection::Connect('main');
        $upsert = $connection->prepare(self::UPSERT);
        if (!$upsert || !$upsert->execute([$hash, $now, $since, $since, $now])) {
            throw new RuntimeException('throttle_upsert');
        }

        $select = $connection->prepare('SELECT hits FROM gst_throttles WHERE throttle_key = ?');
        if (!$select || !$select->execute([$hash])) {
            throw new RuntimeException('throttle_select');
        }
        $row = $select->get_result()->fetch_assoc();

        return (int) ($row['hits'] ?? 0);
    }

    /** @return list<int> */
    private static function recent(string $content, int $window): array
    {
        $since = time() - $window;
        $times = json_decode($content, true);

        return is_array($times)
            ? array_values(array_filter(array_map('intval', $times), static fn (int $time): bool => $time > $since))
            : [];
    }

    private static function file(string $bucket, string $key): string
    {
        return self::directory().'/'.hash('sha256', $bucket.'|'.$key).'.json';
    }

    private static function directory(): string
    {
        if (self::$directory !== null) {
            return self::$directory;
        }

        $root = defined('ROOT') ? (string) constant('ROOT') : (string) getcwd();

        return rtrim($root, '/').'/storage/gestionale/throttle';
    }
}
