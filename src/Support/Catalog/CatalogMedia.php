<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use InvalidArgumentException;
use Wonder\App\Media\MediaInputs;
use Wonder\App\Media\MediaTypes;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Inputs\InputMedia;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSelection;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;

/**
 * Le voci del catalogo che tengono le loro immagini nella libreria Media
 * del core, e il campo del form che le sceglie.
 *
 * Cosa ha ogni voce lo dice il suo Model ({@see HasCatalogMedia::mediaFields()});
 * qui c'è l'elenco dei Model, che serve alla migrazione dei file di prima
 * ({@see \Wonder\Plugin\Gestionale\Migrations\CatalogMediaMigration}).
 */
final class CatalogMedia
{
    /** @var list<class-string<\Wonder\App\Model>> i Model con {@see HasCatalogMedia} */
    public const MODELS = [Category::class, Tag::class, Brand::class, ProductSelection::class];

    /**
     * Il campo media di un form: `FormField::media()` del core sulla colonna
     * con l'id, con i modelli di slug e testo alternativo del Model.
     *
     * @param class-string $model uno di {@see MODELS}
     */
    public static function input(string $model, string $field, string $type = MediaTypes::IMAGE): InputMedia
    {
        $config = $model::mediaFields()[$field] ?? null;
        $column = $config['columns'][$type] ?? null;

        if ($column === null) {
            throw new InvalidArgumentException("Il campo {$field} di {$model} non ha media di tipo {$type}.");
        }

        $input = FormField::key($column)->media($type)->mediaSlug($config['slug']);

        return $config['alt'] !== '' ? $input->mediaAlt($config['alt']) : $input;
    }

    /**
     * I nomi che un campo media manda nella richiesta: la colonna con l'id
     * e il file caricato. Servono dove la richiesta viene filtrata per nome
     * (le schede in sola lettura con qualche campo modificabile).
     *
     * @param class-string $model
     * @return list<string>
     */
    public static function requestKeys(string $model): array
    {
        $keys = [];

        foreach ($model::mediaFields() as $config) {
            foreach ($config['columns'] as $column) {
                $keys[] = $column;
                $keys[] = $column.MediaInputs::UPLOAD_SUFFIX;
            }
        }

        return $keys;
    }

    /**
     * Le colonne con l'id dei media di un Model.
     *
     * @param class-string $model
     * @return list<string>
     */
    public static function columns(string $model): array
    {
        $columns = [];

        foreach ($model::mediaFields() as $config) {
            array_push($columns, ...array_values($config['columns']));
        }

        return $columns;
    }
}
