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
        if ($orderId <= 0) {
            return static::emptyHtml(static::emptyText());
        }

        return static::embedWhere('`order_id` = '.$orderId);
    }

    /**
     * La stessa tabella per le righe di più ordini — i carrelli di un cliente,
     * per esempio. Una lista vuota è la frase vuota.
     *
     * @param list<int> $orderIds
     */
    public static function embedMany(array $orderIds, ?string $empty = null): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return static::emptyHtml($empty ?? static::emptyText());
        }

        return static::embedWhere('`order_id` IN ('.implode(',', $ids).')', $empty);
    }

    /**
     * La tabella delle righe che soddisfano la condizione, o la frase se non
     * ce n'è nessuna. La condizione la scrive la Resource, mai l'utente: gli
     * id arrivano già interi.
     */
    protected static function embedWhere(string $condition, ?string $empty = null): string
    {
        $vuoto = static::emptyHtml($empty ?? static::emptyText());

        if (!static::featureActive()) {
            return $vuoto;
        }

        try {
            $prima = static::$model::find("({$condition}) AND `deleted` = 'false'", 1);

            if (!is_array($prima) || !isset($prima['id'])) {
                return $vuoto;
            }

            $tabella = static::backendTable([], static::tableLayoutSchema());
            $tabella->length(50);
            $tabella->query("({$condition}) AND `deleted` = 'false'");
            $tabella->queryOrder(static::sortColumn(), static::sortDirection());
            $html = (string) $tabella->generate(false);
        } catch (Throwable) {
            return $vuoto;
        }

        return $html !== '' ? $html : $vuoto;
    }

    private static function emptyHtml(string $text): string
    {
        return '<p class="text-muted mb-0">'.static::escape($text).'</p>';
    }
}
