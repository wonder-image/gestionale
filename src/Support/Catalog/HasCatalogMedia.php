<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Throwable;
use Wonder\App\Media\MediaFile;
use Wonder\App\Path;
use Wonder\App\Support\MediaFileManager;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Le immagini di una voce del catalogo (categoria, tag, marchio, selezione)
 * stanno nella libreria Media del core: la riga ne tiene l'id.
 *
 * Il Model dichiara i suoi campi in {@see mediaFields()}; da lì nascono le
 * colonne, i campi del form ({@see CatalogMedia::input()}), la lettura e la
 * migrazione dei file di prima ({@see CatalogMediaPlan}).
 *
 * Si legge sempre da qui, mai dalla colonna:
 *
 * ```php
 * Category::mediaUrl($categoria, 'image');             // indirizzo, '' se non c'è
 * Category::media($categoria, 'image');                // MediaFile|null, per Image::fromMedia()
 * Category::mediaMany($categorie, 'image');            // un elenco: una lettura sola
 * ```
 *
 * **Colonna di prima.** Ogni campo aveva una colonna file con lo stesso nome
 * (`image`, `logo`, `background`). Resta nella tabella, deprecata: la
 * migrazione la svuota quando ha creato il media, quindi una riga che la ha
 * ancora piena è una riga non migrata (prima del secondo `forge update`, o
 * con un file che non si è potuto copiare), e per quella riga
 * {@see mediaUrl()} ripiega sul file di prima.
 */
trait HasCatalogMedia
{
    /**
     * I campi media del Model: nome del campo (che è anche la colonna file
     * di prima) => `columns` (tipo di media => colonna con l'id, nell'ordine
     * in cui si leggono), `slug` e `alt` (i modelli del media creato dal
     * campo) e `legacy` (la cartella dei file di prima, sotto
     * `assets/upload`).
     *
     * @return array<string, array{columns: array<string, string>, slug: string, alt: string, legacy: string}>
     */
    abstract public static function mediaFields(): array;

    /** L'id del media di un campo: la prima colonna piena, 0 se nessuna. */
    public static function mediaId(array $row, string $field): int
    {
        foreach (static::mediaFields()[$field]['columns'] ?? [] as $column) {
            $id = $row[$column] ?? null;

            if (is_scalar($id) && (int) $id > 0) {
                return (int) $id;
            }
        }

        return 0;
    }

    /** Il media di un campo, null se la riga non ne ha o non c'è più. */
    public static function media(array $row, string $field): ?MediaFile
    {
        $id = static::mediaId($row, $field);

        return $id > 0 ? MediaFile::find($id) : null;
    }

    /**
     * I media di un campo per più righe, con una lettura sola. Le chiavi
     * sono quelle di `$rows`; chi non ha un media manca dal risultato.
     *
     * @param array<int|string, array<string, mixed>> $rows
     * @return array<int|string, MediaFile>
     */
    public static function mediaMany(array $rows, string $field): array
    {
        $ids = [];

        foreach ($rows as $key => $row) {
            $id = is_array($row) ? static::mediaId($row, $field) : 0;

            if ($id > 0) {
                $ids[$key] = $id;
            }
        }

        $found = MediaFile::many(array_values($ids));
        $media = [];

        foreach ($ids as $key => $id) {
            if (isset($found[$id])) {
                $media[$key] = $found[$id];
            }
        }

        return $media;
    }

    /**
     * L'indirizzo del file di un campo, vuoto se non c'è. Passare il media
     * già letto con {@see mediaMany()} evita una lettura per riga.
     */
    public static function mediaUrl(array $row, string $field, ?MediaFile $media = null): string
    {
        if (static::mediaId($row, $field) > 0) {
            return (string) ($media ?? static::media($row, $field))?->url();
        }

        return static::legacyMediaUrl($row, $field);
    }

    /**
     * L'indirizzo del file nella colonna di prima, per le righe non ancora
     * migrate.
     *
     * @deprecated solo per il passaggio alla libreria Media.
     */
    public static function legacyMediaUrl(array $row, string $field): string
    {
        $config = static::mediaFields()[$field] ?? null;
        $name = trim((string) (MediaFileManager::decodeStoredFiles($row[$field] ?? '')[0] ?? ''));

        if ($config === null || $name === '') {
            return '';
        }

        if (filter_var($name, FILTER_VALIDATE_URL) !== false) {
            return $name;
        }

        try {
            $upload = rtrim((string) (new Path())->upload, '/');
        } catch (Throwable) {
            // Fuori dal sito avviato (test, comandi) l'indirizzo è quello relativo.
            $upload = '/assets/upload';
        }

        return $upload.'/'.trim($config['legacy'], '/').'/'.ltrim($name, '/');
    }

    /**
     * Le colonne con l'id dei media: un intero che può restare vuoto.
     *
     * @return list<Column>
     */
    protected static function mediaTableColumns(): array
    {
        $columns = [];

        foreach (static::mediaFields() as $config) {
            foreach ($config['columns'] as $column) {
                $columns[] = Column::key($column)->int()->null();
            }
        }

        return $columns;
    }

    /** @return list<object> i campi dei dati delle colonne con l'id */
    protected static function mediaDataFields(): array
    {
        $fields = [];

        foreach (static::mediaFields() as $config) {
            foreach ($config['columns'] as $column) {
                $fields[] = Field::key($column)->number();
            }
        }

        return $fields;
    }
}
