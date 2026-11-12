<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog\Import;

use RuntimeException;
use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Support\Catalog\ImageSizes;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * La foto di una cella «Immagine»: scaricata da un indirizzo https e messa in
 * coda come una foto caricata a mano (`pending`, le misure le fa la coda).
 *
 * Il file si chiama come l'indirizzo (`md5(url).ext`): ricaricare lo stesso
 * file non aggiunge la stessa foto una seconda volta.
 */
final class RemoteImage
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const TIMEOUT = 10;

    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /** @var (callable(string): string)|null scarica i byte di un indirizzo; i test lo sostituiscono */
    private static $fetcher = null;

    public static function useFetcher(?callable $fetcher): void
    {
        self::$fetcher = $fetcher;
    }

    public static function isHttps(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    /**
     * Aggiunge la foto all'articolo (o alla sola opzione): l'id della riga
     * nuova, zero se l'articolo l'aveva già.
     *
     * @throws RuntimeException con un messaggio da mostrare
     */
    public static function attach(string $url, int $modelId, int $productId = 0, int $variantId = 0): int
    {
        $url = trim($url);

        if (!self::isHttps($url)) {
            throw new RuntimeException(UserError::make('import.image_https')->getMessage());
        }

        $base = md5($url);

        foreach (self::images($modelId) as $image) {
            $files = json_decode((string) ($image['file'] ?? ''), true);

            foreach (is_array($files) ? $files : [] as $file) {
                if (str_starts_with((string) $file, $base.'.')) {
                    return 0;
                }
            }
        }

        $bytes = self::download($url);
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extension = self::TYPES[$type] ?? null;

        if ($extension === null) {
            throw new RuntimeException(UserError::make('import.image_type')->getMessage());
        }

        $dir = rtrim((string) ($GLOBALS['ROOT'] ?? (defined('ROOT') ? constant('ROOT') : '')), '/').'/assets/upload'.ProductImages::folder();

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(UserError::make('import.image_save')->getMessage());
        }

        $name = $base.'.'.$extension;

        if (@file_put_contents($dir.$name, $bytes) === false) {
            throw new RuntimeException(UserError::make('import.image_save')->getMessage());
        }

        $row = [
            'product_model_id' => $modelId,
            'file' => json_encode([$name]),
            'alt' => '',
            'position' => count(self::images($modelId)) + 1,
            'status' => ProductImages::READY,
        ];

        if ($variantId > 0) {
            $row['product_variant_id'] = $variantId;
        }

        if ($productId > 0) {
            $row['product_id'] = $productId;
        }

        $result = ProductImage::create($row);

        if (empty($result->success) || (int) ($result->insert_id ?? 0) === 0) {
            @unlink($dir.$name);

            throw new RuntimeException(UserError::make('import.image_save')->getMessage());
        }

        // Il file non è passato da `uploadFiles()`: le misure si consegnano
        // qui alla coda del core (senza coda si generano subito).
        ImageSizes::prepare($dir.$name);

        return (int) $result->insert_id;
    }

    /** @return list<array<string, mixed>> */
    private static function images(int $modelId): array
    {
        $rows = ProductImage::find(['product_model_id' => $modelId, 'deleted' => 'false']);

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    private static function download(string $url): string
    {
        if (self::$fetcher !== null) {
            $bytes = (string) (self::$fetcher)($url);
        } else {
            try {
                $bytes = self::curl($url);
            } catch (Throwable) {
                $bytes = '';
            }
        }

        if ($bytes === '') {
            throw new RuntimeException(UserError::make('import.image_download')->getMessage());
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException(UserError::make('import.image_size')->getMessage());
        }

        return $bytes;
    }

    private static function curl(string $url): string
    {
        $handle = curl_init($url);
        $bytes = '';

        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            // Anche i reindirizzamenti restano su https.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_USERAGENT => 'Wonder Gestionale',
            // Si ferma appena il file supera il limite, senza scaricarlo tutto.
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bytes): int {
                $bytes .= $chunk;

                return strlen($bytes) > self::MAX_BYTES ? 0 : strlen($chunk);
            },
        ]);

        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if (strlen($bytes) > self::MAX_BYTES) {
            return $bytes;
        }

        return $ok !== false && $status >= 200 && $status < 300 ? $bytes : '';
    }
}
