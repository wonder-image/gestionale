<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;

/**
 * "Varianti": Colore, Taglia — le scelte che fanno nascere le versioni in
 * vendita di un articolo.
 *
 * Stessa tabella degli Attributi (`gst_attributes`), con l'uso `product` o
 * `variant`; se ogni valore abbia una pagina sua lo decide la scheda
 * dell'articolo, con l'interruttore «Una pagina per ogni valore».
 */
class VariantResource extends AttributeResource
{
    public static string $docsPage = 'catalogo/catalogo-attributi';

    /** Senza la funzionalità «Varianti» la pagina non c'è. */
    public static string $feature = 'variants';

    public static function path(): string
    {
        return 'app/gestionale/varianti';
    }

    public static function icon(): string
    {
        return 'bi-palette';
    }

    public static function titleLabel(): string
    {
        return 'Varianti';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'variante',
            'plural_label' => 'varianti',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'full' => 'visibile',
            'empty' => 'nascosta',
            'this' => 'questa',
        ];
    }

    public static function defaultLevel(): string
    {
        return 'product';
    }

    protected static function ownTypes(): array
    {
        return Attributes::variantTypes();
    }

    public static function listCondition(): string
    {
        return static::variantCondition();
    }

    public static function formTooltip(): string
    {
        return 'Una variante fa nascere le versioni in vendita di un articolo, come il Colore o la Taglia. Il tipo dice come la sceglie il cliente: da una Tendina, da una fila di Bottoni, da un pallino di Colore o da un\'Immagine. Se ogni valore ha una pagina sua lo decidi nella scheda dell\'articolo, con «Una pagina per ogni valore».';
    }

    protected static function singularTitle(): string
    {
        return 'Variante';
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()->titles([
            'list' => 'Varianti',
            'create' => 'Nuova variante',
            'edit' => 'Modifica variante',
        ]);
    }

    /** Niente API: «Nuova caratteristica» crea solo Attributi. */
    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('catalogo'));
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('catalogo')
            ->title('Varianti')
            ->order(42)
            ->access(Gestionale::area('catalogo'))
            ->enabled(static::featureActive());
    }
}
