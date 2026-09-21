<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Throwable;
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

        foreach (self::pending($limit) as $image) {
            self::process($image) ? $done++ : $failed++;
        }

        return ['done' => $done, 'failed' => $failed, 'left' => self::count()];
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

        $previous = $ALERT ?? '';
        $ALERT = '';

        try {
            imageResize($path);
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
