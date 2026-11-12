<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Throwable;
use Wonder\App\Media\ResizeQueue;

/**
 * Le misure di un'immagine che il modulo scrive da sé, senza passare da
 * `uploadFiles()`: una foto scaricata dall'importazione, una dei dati di
 * prova, un file copiato da una migrazione.
 *
 * La coda è una sola, quella del core (`Wonder\App\Media\ResizeQueue`, task
 * `wonder.media-resize`, comando `php forge media:resize`). Qui c'è solo il
 * modo di consegnarle un file:
 *
 * - {@see prepare()} fa quello che fa `uploadFiles()`: accoda, e dove la coda
 *   non si può usare (tabella assente, scheduler fermo) ridimensiona subito;
 * - {@see handOver()} accoda e basta, anche con lo scheduler fermo: serve
 *   alla migrazione delle foto rimaste in attesa nella vecchia coda del
 *   modulo, che non deve ridimensionare niente dentro `forge update`.
 *
 * Un video e ogni file che non è png, jpg o webp non hanno misure.
 */
final class ImageSizes
{
    public const QUEUED = 'queued';
    public const RESIZED = 'resized';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    /** Le misure del core, per quando il sito non le ha dichiarate (un comando, lo scheduler). */
    private const DEFAULT_WIDTHS = [240, 480, 620, 960, 1200, 1440, 1920, 2400];

    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** @return list<int> */
    public static function widths(): array
    {
        $widths = defined('RESPONSIVE_IMAGE_SIZES') ? constant('RESPONSIVE_IMAGE_SIZES') : null;

        return is_array($widths) && $widths !== [] ? array_values(array_map('intval', $widths)) : self::DEFAULT_WIDTHS;
    }

    public static function webp(): bool
    {
        return defined('RESPONSIVE_IMAGE_WEBP') ? (bool) constant('RESPONSIVE_IMAGE_WEBP') : true;
    }

    /** Vero per i file di cui si generano le misure: gli stessi di `uploadFiles()`. */
    public static function resizable(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * Accoda le misure di un file; senza coda le genera subito.
     *
     * @return self::QUEUED|self::RESIZED|self::SKIPPED|self::FAILED
     */
    public static function prepare(string $absolutePath): string
    {
        if (!self::resizable($absolutePath) || !is_file($absolutePath)) {
            return self::SKIPPED;
        }

        if (ResizeQueue::enqueue($absolutePath, self::widths(), self::webp())) {
            return self::QUEUED;
        }

        try {
            ResizeQueue::resize($absolutePath, self::widths(), self::webp());
        } catch (Throwable $error) {
            // L'originale c'è e il sito lo mostra: una misura mancata non
            // deve far fallire chi stava salvando la riga.
            if (function_exists('__log')) {
                \__log($error, '[gestionale]', '[image-sizes]');
            }

            return self::FAILED;
        }

        return self::RESIZED;
    }

    /**
     * Consegna un file alla coda del core anche se lo scheduler non batte:
     * lo lavorerà il task, o `php forge media:resize`. Una riga che c'è già
     * (in attesa, pronta o fallita) non si tocca. Falso se la coda non c'è.
     */
    public static function handOver(string $absolutePath): bool
    {
        try {
            $path = ResizeQueue::relative($absolutePath);
            $store = ResizeQueue::store();

            if ($path === null || !$store->usable()) {
                return false;
            }

            if ($store->find($path) === null) {
                $store->put($path, [
                    'widths' => self::widths(),
                    'webp' => self::webp(),
                    'quality' => null,
                    'delete' => [],
                ], ResizeQueue::time(time()));
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
