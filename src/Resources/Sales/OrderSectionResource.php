<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Throwable;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;

/**
 * Una tabella della scheda ordine: righe, pagamenti, resi o storico.
 *
 * Non ha pagina né voce di menu e non si legge dall'API: esiste perché la
 * scheda possa incorporare la sua tabella con `TableColumn` e
 * `TableLayoutSchema`, come qualsiasi elenco del backend, con la query già
 * ristretta all'ordine. Le quattro si leggono solo da amministratore, come
 * l'ordine.
 */
abstract class OrderSectionResource extends GestionaleResource
{
    public static string $feature = 'orders';
    public static string $orderColumn = 'id';
    public static string $orderDirection = 'ASC';

    /** La frase al posto della tabella, quando non c'è niente da mostrare. */
    abstract protected static function emptyText(): string;

    /** La colonna per cui la tabella si ordina; `id` e crescente è l'ordine di inserimento. */
    protected static function sortColumn(): string
    {
        return static::$orderColumn;
    }

    protected static function sortDirection(): string
    {
        return static::$orderDirection;
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()->only([]);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('vendite')
            ->title(static::titleLabel())
            ->authority(['admin', 'administrator'])
            ->enabled(false);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backend(['list'], ['admin', 'administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /** Senza intestazione, senza filtri e senza pulsanti: la scheda ha già il suo titolo. */
    public static function tableLayoutSchema(): TableLayoutSchema
    {
        return TableLayoutSchema::for(static::class)
            ->cleanHeader()
            ->filters(false, false);
    }

    /**
     * La tabella dell'ordine, o la frase quando non ci sono righe.
     *
     * Senza database la tabella non nasce: resta la frase, e la scheda si
     * legge lo stesso.
     */
    public static function embed(int $orderId): string
    {
        $vuoto = '<p class="text-muted mb-0">'.static::escape(static::emptyText()).'</p>';

        if ($orderId <= 0 || !static::featureActive() || static::rowsOf(static::$model, ['order_id' => $orderId]) === []) {
            return $vuoto;
        }

        try {
            $tabella = static::backendTable([], static::tableLayoutSchema());
            $tabella->length(50);
            $tabella->query('`order_id` = '.$orderId." AND `deleted` = 'false'");
            $tabella->queryOrder(static::sortColumn(), static::sortDirection());
            $html = (string) $tabella->generate(false);
        } catch (Throwable) {
            return $vuoto;
        }

        return $html !== '' ? $html : $vuoto;
    }
}
