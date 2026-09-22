<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use RuntimeException;
use Throwable;
use Wonder\Plugin\Custom\Image\ResponsiveImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;

/**
 * La coda che genera le misure delle immagini, dopo il salvataggio.
 *
 * Lavora **a blocchi**: un cron ogni minuto può richiamarla senza accavallarsi
 * con sé stessa, e una scheda con cinquanta foto non blocca il processo per
 * mezz'ora. Chi la fa girare — il comando `gestionale:images` o l'attività dello
 * scheduler — chiede solo quante righe prendere.
 *
 * `imageResize()` del core non lancia eccezioni: quando va male scrive il codice
 * in `$ALERT`. Per questo qui si guarda quella variabile invece di fidarsi del
 * ritorno.
 *
 * Quando è il sito a non poter lavorare — una costante che manca fuori da una
 * richiesta web — la coda si ferma e lo dice, invece di bruciare i tentativi
 * delle righe una dopo l'altra.
 *
 * Tre tentativi e poi basta: una foto storta non deve tenere occupata la coda
 * per sempre. Alla terza la riga resta `failed`, con il suo errore da leggere
 * nella scheda, e una segnalazione in `error_reports`.
 */
final class ImageQueue
{
    /** Quante righe per volta, se nessuno dice altro. */
    public const BATCH = 20;

    /** Dopo quanti tentativi una riga si arrende. */
    public const MAX_ATTEMPTS = 3;

    /** Le misure del core, per quando nessuno le ha dichiarate. */
    private const DEFAULT_SIZES = [240, 480, 620, 960, 1200, 1440, 1920, 2400];

    /**
     * Le righe ancora da lavorare, le più vecchie per prime.
     *
     * @return list<array<string, mixed>>
     */
    public static function pending(int $limit = self::BATCH): array
    {
        $rows = ProductImage::find(
            ['status' => 'pending', 'deleted' => 'false'],
            max(1, $limit),
            'id',
            'ASC'
        );

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /**
     * Lavora un blocco e dice com'è andata.
     *
     * @return array{done: int, failed: int, left: int}
     */
    public static function work(int $limit = self::BATCH): array
    {
        $done = 0;
        $failed = 0;
        $blocked = '';

        foreach (self::pending($limit) as $image) {
            try {
                self::process($image) ? $done++ : $failed++;
            } catch (Unavailable $stop) {
                // Non è colpa dell'immagine: qui non si può proprio lavorare.
                // Fermarsi lascia le righe in attesa invece di bruciarne i
                // tentativi una dopo l'altra.
                $blocked = $stop->getMessage();
                break;
            }
        }

        return [
            'done' => $done,
            'failed' => $failed,
            'left' => self::count(),
            'blocked' => $blocked,
        ];
    }

    /** Quante righe restano in attesa. */
    public static function count(): int
    {
        $rows = ProductImage::find(['status' => 'pending', 'deleted' => 'false']);

        if (!is_array($rows) || $rows === []) {
            return 0;
        }

        return isset($rows['id']) ? 1 : count(array_filter($rows, 'is_array'));
    }

    /** Genera le misure di una riga. Vero quando è andata. */
    public static function process(array $image): bool
    {
        global $ALERT;

        $id = (int) ($image['id'] ?? 0);

        if ($id === 0) {
            return false;
        }

        $path = ProductImages::path($image);

        if ($path === '' || !is_file($path)) {
            return self::fail($image, 'Il file non c\'è più: '.($path === '' ? 'nessun nome' : $path));
        }

        // Un video non ha misure da generare: è già pronto appena caricato.
        if (self::isVideo($path)) {
            ProductImage::update([
                'status' => 'ready',
                'processed_at' => date('Y-m-d H:i:s'),
                'error' => '',
            ], $id);

            return true;
        }

        $previous = $ALERT ?? '';
        $ALERT = '';

        try {
            self::resize($path);
        } catch (\Error $fatal) {
            $ALERT = $previous;

            // Una costante che manca, una classe che non c'è: è il sito a non
            // essere in grado, non la foto.
            throw new Unavailable(
                'Questo sito non riesce a ridimensionare le immagini da riga di comando ('
                .$fatal->getMessage().'). Controlla che PHP abbia la libreria GD.',
                0,
                $fatal
            );
        } catch (Throwable $error) {
            $ALERT = $previous;

            return self::fail($image, $error->getMessage());
        }

        $problem = (string) ($ALERT ?? '');
        $ALERT = $previous;

        if ($problem !== '') {
            return self::fail($image, 'Il ridimensionamento non è riuscito (codice '.$problem.').');
        }

        ProductImage::update([
            'status' => 'ready',
            'processed_at' => date('Y-m-d H:i:s'),
            'error' => '',
        ], $id);

        return true;
    }

    /** Vero per i file che non sono immagini da ridimensionare. */
    public static function isVideo(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['mp4', 'webm', 'mov'], true);
    }

    /**
     * Genera le misure di un file.
     *
     * Si passa dalla classe del core invece che da `imageResize()`: i comandi
     * di `forge` girano senza le funzioni globali del framework, e una
     * chiamata senza barra dentro un namespace cercherebbe comunque
     * `Wonder\Plugin\...\imageResize()`. La funzione resta come ripiego per
     * chi ha un core più vecchio.
     */
    private static function resize(string $path): void
    {
        // Le misure del sito sono due costanti che nascono durante una
        // richiesta web: in un comando non c'è nessuno a dichiararle, e la
        // classe del core le legge appena la si costruisce. Qui si mettono
        // quelle del framework, le stesse che userebbe il sito.
        if (!defined('RESPONSIVE_IMAGE_SIZES')) {
            define('RESPONSIVE_IMAGE_SIZES', self::DEFAULT_SIZES);
        }

        if (!defined('RESPONSIVE_IMAGE_WEBP')) {
            define('RESPONSIVE_IMAGE_WEBP', true);
        }

        if (class_exists(ResponsiveImage::class)) {
            ResponsiveImage::path($path)->generate();

            return;
        }

        if (function_exists('imageResize')) {
            \imageResize($path);

            return;
        }

        throw new RuntimeException('Questo sito non sa ridimensionare le immagini.');
    }

    /** Lo stato di una riga dopo un tentativo andato male. */
    public static function nextStatus(int $attempts): string
    {
        return $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
    }

    /** Segna il tentativo andato male e, alla terza, si arrende. */
    private static function fail(array $image, string $message): bool
    {
        $id = (int) ($image['id'] ?? 0);
        $attempts = (int) ($image['attempts'] ?? 0) + 1;
        $status = self::nextStatus($attempts);

        ProductImage::update([
            'status' => $status,
            'attempts' => $attempts,
            'error' => $message,
            'processed_at' => date('Y-m-d H:i:s'),
        ], $id);

        if ($status === 'failed') {
            // Chi sviluppa deve saperlo: una foto che non si lascia
            // ridimensionare di solito è un file rotto o un permesso sbagliato.
            Errors::report('gestionale', 'catalog.image.resize', $message, [
                'image' => $id,
                'model' => (int) ($image['product_model_id'] ?? 0),
                'file' => ProductImages::fileName($image),
            ]);
        }

        return false;
    }
}
