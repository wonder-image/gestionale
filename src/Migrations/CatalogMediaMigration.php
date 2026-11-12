<?php

namespace Wonder\Plugin\Gestionale\Migrations;

use mysqli;
use RuntimeException;
use Throwable;
use Wonder\App\Media\DatabaseMediaUsageStore;
use Wonder\App\Media\MediaTypes;
use Wonder\App\Media\MediaUsages;
use Wonder\App\Module\Contracts\ModuleMigration;
use Wonder\Plugin\Gestionale\Support\Catalog\CatalogMedia;
use Wonder\Plugin\Gestionale\Support\Catalog\CatalogMediaPlan;
use Wonder\Plugin\Gestionale\Support\Catalog\ImageSizes;

/**
 * Le immagini di categorie, tag e selezioni e il logo dei marchi passano
 * alla libreria Media del core: per ogni riga con un file nella colonna di
 * prima nasce un media del tipo giusto, la riga ne prende l'id e l'utilizzo
 * finisce nel registro (`MediaUsages`).
 *
 * Esegue il piano di {@see CatalogMediaPlan}:
 *
 * - il file si **copia** nella cartella della libreria (`images` o
 *   `icons`); l'originale resta dov'è, niente si sovrascrive;
 * - media, id sulla riga, colonna di prima svuotata e utilizzo si scrivono
 *   in una transazione sola: o tutto o niente;
 * - le misure di un'immagine vanno alla coda del core (o si generano subito
 *   dove lo scheduler è fermo).
 *
 * Idempotente: lavora solo le righe con la colonna di prima ancora piena, e
 * una riga migrata l'ha vuota. Gira prima dell'allineamento delle tabelle,
 * quindi le colonne con l'id le aggiunge da sé; se la libreria o il registro
 * del core non ci sono ancora (li crea lo stesso update, dopo) non fa niente
 * e ci riprova all'update successivo: fino ad allora il sito legge il file di
 * prima.
 */
final class CatalogMediaMigration implements ModuleMigration
{
    public const MEDIA_TABLE = 'media';
    public const USAGES_TABLE = 'media_usages';

    /** La colonna di prima svuotata: un elenco di file vuoto, che è JSON valido. */
    public const EMPTY = '[]';

    public static function run(mysqli $connection, ?string $uploadRoot = null): array
    {
        $uploadRoot ??= defined('ROOT') ? rtrim((string) constant('ROOT'), '/').'/assets/upload' : null;

        if ($uploadRoot === null
            || !self::tableExists($connection, self::MEDIA_TABLE)
            || !self::tableExists($connection, self::USAGES_TABLE)) {
            return [];
        }

        $uploadRoot = rtrim($uploadRoot, '/');
        $sources = self::sources($connection, $uploadRoot);

        if ($sources === []) {
            return [];
        }

        $plan = CatalogMediaPlan::plan(
            $sources,
            array_column(self::all($connection, 'SELECT `slug` FROM `'.self::MEDIA_TABLE.'`'), 'slug'),
            [
                MediaTypes::folder(MediaTypes::IMAGE) => self::files($uploadRoot.'/'.MediaTypes::folder(MediaTypes::IMAGE)),
                MediaTypes::folder(MediaTypes::ICON) => self::files($uploadRoot.'/'.MediaTypes::folder(MediaTypes::ICON)),
            ],
            static fn (string $source, string $target): bool => self::identical($uploadRoot.'/'.$source, $uploadRoot.'/'.$target)
        );

        // L'utilizzo si scrive sulla stessa connessione, dentro la transazione della riga.
        MediaUsages::useStore(new DatabaseMediaUsageStore($connection));

        try {
            return self::apply(
                $plan,
                static fn (string $source, string $target): bool => self::copy($uploadRoot.'/'.$source, $uploadRoot.'/'.$target),
                static fn (array $step): bool => self::save($connection, $step),
                static fn (array $step): bool => self::clear($connection, $step),
                static function (array $step) use ($uploadRoot): void {
                    if ($step['type'] === MediaTypes::IMAGE) {
                        ImageSizes::prepare($uploadRoot.'/'.$step['target']);
                    }
                }
            );
        } finally {
            MediaUsages::useStore(null);
        }
    }

    /**
     * Esegue un piano senza sapere di database e disco: copia, scrive,
     * svuota e prepara le misure con le funzioni che riceve.
     *
     * @param array{create: list<array<string, mixed>>, clear: list<array<string, mixed>>, skipped: list<array<string, mixed>>} $plan
     * @param callable(string, string): bool $copy copia il file di prima in libreria (percorsi sotto `assets/upload`)
     * @param callable(array): bool $save scrive media, id sulla riga e utilizzo, o niente
     * @param callable(array): bool $clear svuota la colonna di prima di una riga che ha già il suo media
     * @param callable(array): void $after dopo il salvataggio di un media (le misure)
     * @return array{migrated?: int, cleared?: int, skipped?: array<string, string>} vuoto se niente è cambiato
     */
    public static function apply(array $plan, callable $copy, callable $save, callable $clear, callable $after): array
    {
        if (!CatalogMediaPlan::needed($plan)) {
            return [];
        }

        $summary = ['migrated' => 0, 'cleared' => 0, 'skipped' => []];
        $label = static fn (array $step): string => $step['table'].'#'.$step['id'].'.'.$step['field'];

        foreach ($plan['skipped'] as $skipped) {
            $summary['skipped'][$label($skipped)] = $skipped['reason'];
        }

        foreach ($plan['create'] as $step) {
            if (!$copy($step['source'], $step['target'])) {
                $summary['skipped'][$label($step)] = 'copia del file non riuscita';
                continue;
            }

            if (!$save($step)) {
                $summary['skipped'][$label($step)] = 'media non salvato';
                continue;
            }

            $summary['migrated']++;
            $after($step);
        }

        foreach ($plan['clear'] as $step) {
            if ($clear($step)) {
                $summary['cleared']++;
            }
        }

        return array_filter($summary);
    }

    /**
     * Una voce per campo dei Model del catalogo, con le righe che hanno
     * ancora un file nella colonna di prima. Aggiunge le colonne con l'id
     * che mancano.
     *
     * @return list<array<string, mixed>>
     */
    private static function sources(mysqli $connection, string $uploadRoot): array
    {
        $sources = [];

        foreach (CatalogMedia::MODELS as $model) {
            $table = (string) $model::$table;

            if (!self::tableExists($connection, $table)) {
                continue;
            }

            $columns = self::columns($connection, $table);

            foreach ($model::mediaFields() as $field => $config) {
                if (!in_array($field, $columns, true)) {
                    continue;
                }

                foreach ($config['columns'] as $column) {
                    if (!in_array($column, $columns, true)) {
                        // DDL: come lo scriverà l'allineamento delle tabelle.
                        if ($connection->query('ALTER TABLE `'.$table.'` ADD COLUMN `'.$column.'` INT NULL') === false) {
                            throw new RuntimeException('Colonna '.$table.'.'.$column.' non aggiunta: '.$connection->error);
                        }

                        $columns[] = $column;
                    }
                }

                $rows = self::all(
                    $connection,
                    'SELECT * FROM `'.$table.'` WHERE `'.$field."` IS NOT NULL AND `deleted` = 'false' ORDER BY `id`"
                );

                if ($rows === []) {
                    continue;
                }

                // I file di prima li ha scritti il form, nella cartella della sua pagina.
                $dir = trim($config['legacy'], '/');
                $dirs = [$dir => self::files($uploadRoot.'/'.$dir)];

                $sources[] = [
                    'table' => $table,
                    'field' => $field,
                    'columns' => $config['columns'],
                    'slug' => $config['slug'],
                    'alt' => $config['alt'],
                    'dirs' => $dirs,
                    'rows' => $rows,
                ];
            }
        }

        return $sources;
    }

    /** Media, id sulla riga, colonna di prima svuotata e utilizzo: tutto o niente. */
    private static function save(mysqli $connection, array $step): bool
    {
        $table = '`'.$step['table'].'`';
        $column = '`'.$step['column'].'`';

        try {
            $connection->begin_transaction();

            $insert = $connection->prepare(
                'INSERT INTO `'.self::MEDIA_TABLE.'` (`slug`, `name`, `alt`, `type`, `file`) VALUES (?, ?, ?, ?, ?)'
            );

            if (!$insert || !$insert->execute([
                $step['slug'],
                $step['name'],
                $step['alt'],
                $step['type'],
                json_encode([basename($step['target'])], JSON_PRETTY_PRINT),
            ])) {
                throw new RuntimeException($connection->error);
            }

            $mediaId = (int) $connection->insert_id;

            // Scrive solo se la riga è ancora da migrare: due update insieme non creano due media.
            $update = $connection->prepare(
                'UPDATE '.$table.' SET '.$column.' = ?, `'.$step['field']."` = '".self::EMPTY."' WHERE `id` = ? AND (".$column.' IS NULL OR '.$column.' = 0)'
            );

            if ($mediaId <= 0 || !$update || !$update->execute([$mediaId, $step['id']]) || $update->affected_rows !== 1) {
                throw new RuntimeException($connection->error);
            }

            MediaUsages::sync($step['table'], $step['id'], $step['column'], [$mediaId]);

            return $connection->commit();
        } catch (Throwable) {
            $connection->rollback();

            return false;
        }
    }

    private static function clear(mysqli $connection, array $step): bool
    {
        $statement = $connection->prepare('UPDATE `'.$step['table'].'` SET `'.$step['field']."` = '".self::EMPTY."' WHERE `id` = ?");

        return $statement && $statement->execute([$step['id']]);
    }

    /** Copia senza sovrascrivere: un file già presente dev'essere la stessa copia. */
    private static function copy(string $source, string $target): bool
    {
        if (is_file($target)) {
            return self::identical($source, $target);
        }

        $directory = dirname($target);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        if (!@copy($source, $target)) {
            // Una copia a metà sarebbe un file diverso al prossimo giro.
            @unlink($target);

            return false;
        }

        return true;
    }

    private static function identical(string $source, string $target): bool
    {
        return is_file($source) && is_file($target)
            && filesize($source) === filesize($target)
            && hash_file('sha256', $source) === hash_file('sha256', $target);
    }

    /** @return list<string> */
    private static function files(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        return array_values(array_filter(
            scandir($directory) ?: [],
            static fn (string $name): bool => is_file($directory.'/'.$name)
        ));
    }

    /** @return list<array<string, mixed>> */
    private static function all(mysqli $connection, string $sql): array
    {
        $result = $connection->query($sql);

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    /** @return list<string> */
    private static function columns(mysqli $connection, string $table): array
    {
        $result = $connection->query('SHOW COLUMNS FROM `'.$table.'`');

        return $result ? array_map(static fn (array $row): string => (string) $row[0], $result->fetch_all()) : [];
    }

    private static function tableExists(mysqli $connection, string $table): bool
    {
        $result = $connection->query("SHOW TABLES LIKE '".$connection->real_escape_string($table)."'");

        return $result !== false && $result->num_rows > 0;
    }
}
