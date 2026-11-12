<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Closure;
use Wonder\App\Media\MediaTypes;
use Wonder\App\Support\MediaFileManager;

/**
 * Decide come i file delle voci del catalogo (immagine di categorie, tag e
 * selezioni, logo dei marchi) diventano media della libreria del core,
 * senza toccare database né disco: lo esegue
 * {@see \Wonder\Plugin\Gestionale\Migrations\CatalogMediaMigration}.
 *
 * Per ogni riga con un file nella colonna di prima:
 *
 * - il tipo viene dall'estensione: svg è un'icona, png, jpg e webp
 *   un'immagine; la colonna con l'id è quella di quel tipo;
 * - lo slug del media (e il nome del file in libreria) viene dal modello del
 *   campo (`categoria-{slug}`); se è già di un altro media, o in libreria
 *   c'è già un file diverso con quel nome, prende `-2`, `-3`, ...;
 * - il file si **copia** nella cartella del tipo: mai spostato, mai
 *   sovrascritto. Una copia già fatta e identica (un giro interrotto) si
 *   riusa.
 *
 * Una riga che ha già il suo media, ma la colonna di prima ancora piena, si
 * limita a svuotare la colonna. Le righe che non si possono migrare (file
 * mancante, estensione non ammessa) restano come sono, con il motivo.
 */
final class CatalogMediaPlan
{
    public const MISSING = 'file di prima non trovato';
    public const EXTERNAL = 'la colonna di prima contiene un indirizzo, non un file';
    public const EXTENSION = 'estensione non ammessa in libreria';
    public const NO_NAME = 'nessun nome libero in libreria';

    /** Tentativi di nome prima di rinunciare a una riga. */
    private const MAX_ATTEMPTS = 200;

    /**
     * @param list<array{
     *     table: string,
     *     field: string,
     *     columns: array<string, string>,
     *     slug: string,
     *     alt: string,
     *     dirs: array<string, list<string>>,
     *     rows: list<array<string, mixed>>
     * }> $sources una voce per campo: `dirs` sono le cartelle dei file di prima (sotto `assets/upload`)
     *        con i nomi dei file che contengono, nell'ordine in cui si cercano
     * @param list<string> $mediaSlugs gli slug già presi nella tabella `media`
     * @param array<string, list<string>> $libraryFiles i nomi dei file già in libreria, per cartella (`images`, `icons`)
     * @param (Closure(string, string): bool)|null $identical vero se il file di prima (percorso sotto `assets/upload`)
     *        e quello in libreria (idem) hanno lo stesso contenuto
     *
     * @return array{
     *     create: list<array{table: string, id: int, field: string, column: string, type: string, source: string, target: string, slug: string, name: string, alt: string}>,
     *     clear: list<array{table: string, id: int, field: string}>,
     *     skipped: list<array{table: string, id: int, field: string, reason: string}>
     * }
     */
    public static function plan(array $sources, array $mediaSlugs = [], array $libraryFiles = [], ?Closure $identical = null): array
    {
        $identical ??= static fn (string $source, string $target): bool => false;
        $slugs = array_fill_keys(array_map('strval', $mediaSlugs), true);
        $files = array_map(static fn (array $names): array => array_fill_keys($names, true), $libraryFiles);
        $plan = ['create' => [], 'clear' => [], 'skipped' => []];

        foreach ($sources as $source) {
            foreach ($source['rows'] as $row) {
                $id = (int) ($row['id'] ?? 0);
                $name = trim((string) (MediaFileManager::decodeStoredFiles($row[$source['field']] ?? '')[0] ?? ''));

                if ($id <= 0 || $name === '') {
                    continue;
                }

                $key = ['table' => $source['table'], 'id' => $id, 'field' => $source['field']];

                if (self::hasMedia($row, $source['columns'])) {
                    $plan['clear'][] = $key;
                    continue;
                }

                if (filter_var($name, FILTER_VALIDATE_URL) !== false) {
                    $plan['skipped'][] = $key + ['reason' => self::EXTERNAL];
                    continue;
                }

                $extension = MediaTypes::extension($name);
                $type = self::type($extension, $source['columns']);

                if ($type === null) {
                    $plan['skipped'][] = $key + ['reason' => self::EXTENSION];
                    continue;
                }

                $from = self::locate($name, $source['dirs']);

                if ($from === null) {
                    $plan['skipped'][] = $key + ['reason' => self::MISSING];
                    continue;
                }

                $folder = MediaTypes::folder($type);
                $slug = self::freeSlug(
                    self::fill($source['slug'], $row, true),
                    $extension,
                    $slugs,
                    $files[$folder] ?? [],
                    static fn (string $target): bool => $identical($from, $folder.'/'.$target)
                );

                if ($slug === null) {
                    $plan['skipped'][] = $key + ['reason' => self::NO_NAME];
                    continue;
                }

                $slugs[$slug] = true;
                $files[$folder][$slug.'.'.$extension] = true;
                $label = trim((string) ($row['name'] ?? ''));

                $plan['create'][] = $key + [
                    'column' => $source['columns'][$type],
                    'type' => $type,
                    'source' => $from,
                    'target' => $folder.'/'.$slug.'.'.$extension,
                    'slug' => $slug,
                    'name' => $label !== '' ? $label : $slug,
                    'alt' => self::fill($source['alt'], $row, false),
                ];
            }
        }

        return $plan;
    }

    /** Vero se c'è qualcosa da fare o da riferire. */
    public static function needed(array $plan): bool
    {
        return ($plan['create'] ?? []) !== [] || ($plan['clear'] ?? []) !== [] || ($plan['skipped'] ?? []) !== [];
    }

    /**
     * Riempie un modello (`categoria-{slug}`, `Logo {name}`) con i valori
     * della riga. Per lo slug il risultato è uno slug; per un testo restano
     * i valori come sono salvati, che è come li vuole la tabella `media`.
     *
     * @param array<string, mixed> $row
     */
    public static function fill(string $template, array $row, bool $slug): string
    {
        $text = (string) preg_replace_callback(
            '/\{([A-Za-z0-9_]+)\}/',
            static fn (array $match): string => is_scalar($row[$match[1]] ?? null) ? trim((string) $row[$match[1]]) : '',
            $template
        );
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return $slug ? trim(Slug::base($text), '-') : $text;
    }

    /** @param array<string, string> $columns tipo => colonna */
    private static function hasMedia(array $row, array $columns): bool
    {
        foreach ($columns as $column) {
            if (is_scalar($row[$column] ?? null) && (int) $row[$column] > 0) {
                return true;
            }
        }

        return false;
    }

    /** Il tipo di media di un'estensione, fra quelli che il campo sa tenere. */
    private static function type(string $extension, array $columns): ?string
    {
        foreach (array_keys($columns) as $type) {
            if (MediaTypes::has($type) && in_array($extension, MediaTypes::extensions($type), true)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Il percorso del file di prima, nella prima cartella che lo contiene.
     *
     * @param array<string, list<string>> $dirs
     */
    private static function locate(string $name, array $dirs): ?string
    {
        // La colonna tiene un nome di file, non un percorso.
        if ($name !== basename($name)) {
            return null;
        }

        foreach ($dirs as $dir => $names) {
            if (in_array($name, $names, true)) {
                return trim((string) $dir, '/').'/'.$name;
            }
        }

        return null;
    }

    /**
     * Lo slug libero più vicino a `$base`: non è di un altro media e in
     * libreria non c'è un file con quel nome (né una sua misura
     * `nome-<larghezza>` o il suo webp), a meno che sia la copia identica
     * lasciata da un giro interrotto.
     *
     * @param array<string, true> $slugs
     * @param array<string, true> $files
     * @param callable(string): bool $sameCopy
     */
    private static function freeSlug(string $base, string $extension, array $slugs, array $files, callable $sameCopy): ?string
    {
        $base = $base !== '' ? $base : 'media';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $slug = $attempt === 1 ? $base : $base.'-'.$attempt;

            if (isset($slugs[$slug])) {
                continue;
            }

            $taken = false;

            foreach (array_keys($files) as $file) {
                if (preg_match('/^'.preg_quote($slug, '/').'(-\d+)?\.[a-z0-9]+$/i', (string) $file) !== 1) {
                    continue;
                }

                if ($file === $slug.'.'.$extension && $sameCopy($file)) {
                    continue;
                }

                $taken = true;
                break;
            }

            if (!$taken) {
                return $slug;
            }
        }

        return null;
    }
}
