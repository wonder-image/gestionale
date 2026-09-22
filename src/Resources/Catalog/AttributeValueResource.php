<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Positions;

/**
 * I valori di un'opzione, come risorsa a sé.
 *
 * Non è una pagina da visitare: i valori si riordinano, si correggono e si
 * cancellano dove stanno da sempre, nel repeater dentro la scheda
 * dell'attributo. Questa classe esiste perché il "+ Aggiungi colore" della
 * scheda prodotto ha bisogno di uno **store API** a cui parlare, e la
 * creazione rapida del core lo cerca su una Resource.
 *
 * Per questo il form ha due campi soli: l'attributo, che il modal porta
 * nascosto, e l'etichetta, l'unica cosa che chi vende deve scrivere. Colore e
 * fantasia si aggiungono dopo, dalla scheda dell'attributo, se servono.
 */
final class AttributeValueResource extends GestionaleResource
{
    public static string $model = AttributeValue::class;
    public static string $orderColumn = 'position';

    public static function path(): string
    {
        return 'app/gestionale/valori-opzione';
    }

    public static function icon(): string
    {
        return 'bi-palette';
    }

    public static function titleLabel(): string
    {
        return 'Valori delle opzioni';
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('attribute_id')->hidden(),
            FormField::key('label')->text()->label('Valore')->required(),
        ];
    }

    /**
     * Solo `store`, e solo per il "+" della scheda prodotto.
     *
     * La chiamata parte lato server come `@system`: non serve dare il permesso
     * a nessun ruolo, il controllo sta sul bottone e nel proxy.
     */
    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)
            ->only(['store'])
            ->fields('store', ['attribute_id', 'label']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)->enabled(false);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'administrator']);
    }

    /**
     * Il valore nuovo va in fondo all'elenco **del suo attributo**, non del
     * catalogo: le posizioni contano dentro l'opzione che le usa.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        unset($values['position']);

        $attributeId = (int) ($values['attribute_id'] ?? 0);

        if ($action === 'store' && $attributeId > 0) {
            $values['position'] = Positions::next(AttributeValue::$table, ['attribute_id' => $attributeId]);
        }

        return $values;
    }
}
