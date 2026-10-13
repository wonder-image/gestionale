<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;

/**
 * *Trasferimenti* (G3.16): la merce che passa da una sede all'altra, in due
 * passi. **Invia** la toglie dalla partenza e la mette *in viaggio*;
 * **Ricevi** la fa entrare all'arrivo. Solo con «Più sedi».
 */
final class StockTransferResource extends StockDocumentResource
{
    public static string $feature = 'multi_location';
    public static string $docsPage = 'magazzino/magazzino-trasferimenti';

    public static function type(): string
    {
        return 'transfer';
    }

    public static function path(): string
    {
        return 'app/gestionale/trasferimenti';
    }

    public static function icon(): string
    {
        return 'bi-arrow-right-square';
    }

    public static function titleLabel(): string
    {
        return 'Trasferimenti';
    }

    public static function singular(): string
    {
        return 'Trasferimento';
    }

    public static function newLabel(): string
    {
        return 'Nuovo trasferimento';
    }

    public static function confirmLabel(): string
    {
        return 'Invia';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'trasferimento',
            'plural_label' => 'trasferimenti',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'il',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return ['location_id' => 'Da', 'to_location_id' => 'A'] + parent::labelSchema();
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return ['' => 'Tutti', 'draft' => 'Bozze', 'in_transit' => 'In viaggio', 'completed' => 'Ricevuti', 'cancelled' => 'Annullati'];
    }

    /**
     * Il filtro *Sede* trova i trasferimenti che partono o arrivano lì.
     *
     * @param list<string> $values
     */
    public static function locationFilter(array $values): string
    {
        $from = StockMovementResource::locationCondition($values);

        return $from === '' ? '' : '('.$from.' OR '.str_replace('`location_id`', '`to_location_id`', $from).')';
    }

    /** Le pagine ci sono solo con «Più sedi»: la base le costruisce da sé, qui si spengono. */
    public static function pageSchema(): PageSchema
    {
        return static::withFeature(parent::pageSchema());
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()->enabled(static::featureActive());
    }

    protected static function navigationOrder(): int
    {
        return 42;
    }
}
