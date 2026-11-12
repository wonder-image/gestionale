<?php

namespace Wonder\Plugin\Gestionale\Migrations;

use mysqli;
use Wonder\App\Media\ResizeQueue;
use Wonder\App\Module\Contracts\ModuleMigration;
use Wonder\Plugin\Gestionale\Support\Catalog\ImageSizes;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;

/**
 * Le foto rimaste `pending` nella coda del modulo passano a quella del core.
 *
 * La coda del modulo (`ImageQueue`) non c'è più: le righe di
 * `gst_product_images` ancora in attesa si consegnano a `image_resizes`, per
 * percorso del file, e la colonna `status` della riga si chiude (`ready`):
 * da lì in poi lo stato lo dice la coda del core. Niente si ridimensiona
 * dentro `forge update`: lo fa il task `wonder.media-resize`, o
 * `php forge media:resize`.
 *
 * Gira a ogni update e scrive solo dove trova `pending`: la seconda volta
 * non ha niente da fare. Raccoglie così anche le righe scritte `pending` da
 * codice del sito che non conosce ancora la coda nuova. Gira dopo
 * l'allineamento delle tabelle (`database.migrations_after_tables`), quando
 * `image_resizes` c'è già; se manca comunque, non fa niente.
 */
final class ProductImageQueueMigration implements ModuleMigration
{
    public const TABLE = 'gst_product_images';

    /** Consegnare alla coda del core. */
    public const QUEUE = 'queue';

    /** Niente da generare: un video, un file senza misure. */
    public const READY = 'ready';

    /** Il file non c'è più: la riga resta nascosta al sito, come con la vecchia coda. */
    public const MISSING = 'missing';

    public const MISSING_ERROR = 'Il file non c\'è più.';

    public static function run(mysqli $connection): array
    {
        if (!self::ready($connection)) {
            return [];
        }

        try {
            if (!ResizeQueue::store()->usable()) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        $result = $connection->query(
            'SELECT `id`, `file` FROM `'.self::TABLE."` WHERE `status` = 'pending' ORDER BY `id`"
        );
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

        if ($rows === []) {
            return [];
        }

        $root = ResizeQueue::root();

        return self::apply(
            self::plan($rows, static fn (array $row): string => ProductImages::path($row, $root), 'is_file'),
            ImageSizes::handOver(...),
            static function (int $id, string $status, string $error) use ($connection): bool {
                $statement = $connection->prepare(
                    'UPDATE `'.self::TABLE."` SET `status` = ?, `error` = ? WHERE `id` = ? AND `status` = 'pending'"
                );

                return $statement && $statement->execute([$status, $error, $id]);
            }
        );
    }

    /**
     * Cosa fare di ogni riga in attesa, senza toccare database né disco.
     *
     * @param list<array<string, mixed>> $rows righe `pending`, con `id` e `file`
     * @param callable(array): string $path il percorso assoluto del file di una riga, vuoto se non ha nome
     * @param callable(string): bool $exists vero se il file c'è
     * @return list<array{id: int, action: string, path: string}>
     */
    public static function plan(array $rows, callable $path, callable $exists): array
    {
        $plan = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $file = $path($row);

            $plan[] = [
                'id' => $id,
                'action' => match (true) {
                    $file === '' || !$exists($file) => self::MISSING,
                    !ImageSizes::resizable($file) => self::READY,
                    default => self::QUEUE,
                },
                'path' => $file,
            ];
        }

        return $plan;
    }

    /**
     * Esegue il piano. Una riga cambia stato solo dopo che il suo file è
     * nella coda del core: se la consegna non riesce resta `pending` e ci si
     * riprova all'update successivo.
     *
     * @param list<array{id: int, action: string, path: string}> $plan
     * @param callable(string): bool $handOver consegna un percorso alla coda del core
     * @param callable(int, string, string): bool $update scrive stato ed errore della riga
     * @return array<string, int> quante righe per esito; vuoto se niente è cambiato
     */
    public static function apply(array $plan, callable $handOver, callable $update): array
    {
        $done = ['queued' => 0, 'ready' => 0, 'missing' => 0, 'waiting' => 0];

        foreach ($plan as $step) {
            if ($step['action'] === self::QUEUE && !$handOver($step['path'])) {
                $done['waiting']++;
                continue;
            }

            $missing = $step['action'] === self::MISSING;

            if (!$update($step['id'], $missing ? ProductImages::FAILED : ProductImages::READY, $missing ? self::MISSING_ERROR : '')) {
                $done['waiting']++;
                continue;
            }

            $done[match ($step['action']) {
                self::QUEUE => 'queued',
                self::MISSING => 'missing',
                default => 'ready',
            }]++;
        }

        return array_filter($done);
    }

    /** La tabella c'è e ha ancora la colonna della vecchia coda. */
    private static function ready(mysqli $connection): bool
    {
        $table = $connection->query("SHOW TABLES LIKE '".self::TABLE."'");

        if ($table === false || $table->num_rows === 0) {
            return false;
        }

        $column = $connection->query('SHOW COLUMNS FROM `'.self::TABLE."` LIKE 'status'");

        return $column !== false && $column->num_rows > 0;
    }
}
