<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use Throwable;
use Wonder\App\Permission\Access;
use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Http\Route;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Labels\LabelDialog;
use Wonder\Plugin\Gestionale\Support\Promotions\ScopeForm;

/**
 * Catalogo › Etichette (#116): si scelgono i prodotti col selettore dei
 * coupon (categorie, tag, marchi, articoli, esclusi) e si scarica il PDF.
 * Una pagina senza model: la vista è `view/pages/labels.php`, il PDF lo fa
 * l'handler `backend/catalog/labels.php`.
 */
final class LabelsResource extends NavigationOnlyResource
{
    use ScopeForm {
        scopeFields as private selectorFields;
    }

    public static function path(): string
    {
        return 'app/gestionale/etichette';
    }

    public static function icon(): string
    {
        return 'bi-upc';
    }

    public static function titleLabel(): string
    {
        return 'Etichette';
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('catalogo')
            ->title('Etichette')
            ->order(39)
            ->access(Gestionale::area('catalogo'))
            ->enabled(Gestionale::feature('barcode_labels'));
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('catalogo'));
    }

    /**
     * La pagina in GET e il PDF in POST (una scelta lunga non sta in un
     * indirizzo). Si registrano anche a funzionalità spenta: la pagina lo
     * dice, il PDF risponde 403.
     */
    public static function registerBackendRoutes(string $rootApp, string $slug): void
    {
        $permit = Access::roles(Gestionale::area('catalogo'), 'view');

        Route::get('/', $rootApp.'/http/backend/resource/page.php', ['resource' => $slug])
            ->name('page')
            ->permit($permit);

        Route::post('/stampa/', Gestionale::handlerPath('backend/catalog/labels.php'), [
            'resource' => $slug,
            'resource_action' => 'print',
            'labels_source' => 'scope',
        ])->name('print')
            ->permit($permit);
    }

    public static function pageView(): string
    {
        return Gestionale::viewPath('pages/labels.php');
    }

    /**
     * Il selettore del coupon, ma partendo da «Solo la selezione»: tutto il
     * catalogo è un PDF enorme, da chiedere apposta.
     *
     * @return list<\Wonder\App\ResourceSchema\Input>
     */
    public static function scopeFields(): array
    {
        $fields = self::selectorFields();
        // Il primo è `applies_to_all`.
        $fields[0]->value('false')->label('Prodotti da stampare');

        return $fields;
    }

    /**
     * I campi della pagina: il selettore, le copie e le scelte di stampa
     * della finestra del carico.
     *
     * @return list<\Wonder\App\ResourceSchema\Input>
     */
    public static function formFields(): array
    {
        return [
            ...array_map(static fn ($field) => $field->columnSpan(12), static::scopeFields()),
            FormField::key('copies')->number()->integer()->value('1')->label('Copie per variante')->columnSpan(['default' => 12, 'md' => 6]),
            ...LabelDialog::fields(),
        ];
    }

    /** Come `GestionaleResource::rowsOf()`, per le voci del selettore. */
    protected static function rowsOf(
        string $modelClass,
        array|string $where = [],
        ?string $order = null,
        string $direction = 'ASC'
    ): array {
        try {
            $rows = $modelClass::find(
                is_array($where) ? array_merge(['deleted' => 'false'], $where) : "({$where}) AND `deleted` = 'false'",
                null,
                $order,
                $order === null ? null : $direction
            );
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /** L'indirizzo del PDF. */
    public static function printUrl(): string
    {
        $fallback = '/backend/'.static::path().'/stampa/';

        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.static::slug().'.print');
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
    }

    /**
     * La richiesta del PDF nella forma di `Labels::respond()`: le scelte di
     * stampa così come arrivano, il selettore già ripulito.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function printQuery(array $post): array
    {
        return ['scope' => static::readScope($post)] + array_intersect_key($post, array_flip(['fields', 'copies', 'start']));
    }
}
