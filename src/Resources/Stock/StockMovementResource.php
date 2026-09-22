<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

use Throwable;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;

/**
 * "Movimenti": la storia del magazzino, in sola lettura.
 *
 * Risponde a una domanda sola — "perché qui c'è scritto 3?" — e per farlo non
 * serve nessun pulsante: un movimento non si modifica e non si cancella, si
 * corregge con un'altra rettifica. Per questo la pagina ha il solo elenco.
 *
 * I filtri per tipo e causale sono quelli del core; il periodo e la versione
 * arrivano dall'indirizzo, perché la scheda della versione linka qui già
 * filtrata.
 */
final class StockMovementResource extends GestionaleResource
{
    public static string $model = StockMovement::class;
    public static string $orderColumn = 'id';
    public static string $orderDirection = 'DESC';
    public static string $docsPage = 'magazzino/magazzino-movimenti';

    /** @var array<int, string>|null */
    private static ?array $productNames = null;

    public static function path(): string
    {
        return 'app/gestionale/movimenti';
    }

    public static function icon(): string
    {
        return 'bi-arrow-left-right';
    }

    public static function titleLabel(): string
    {
        return 'Movimenti';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'movimento',
            'plural_label' => 'movimenti',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'creation' => 'Quando',
            'product_id' => 'Articolo',
            'type' => 'Tipo',
            'reason' => 'Causale',
            'quantity' => 'Pezzi',
            'quantity_after' => 'Giacenza dopo',
            'note' => 'Nota',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('creation')->text()->size('little'),
            TableColumn::key('product_id')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(
                    static::productNames()[(int) ($row['product_id'] ?? 0)] ?? '—'
                )),
            TableColumn::key('type')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(
                    StockMovement::typeLabels()[(string) ($row['type'] ?? '')] ?? ''
                )),
            TableColumn::key('reason')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(
                    Reasons::label((string) ($row['reason'] ?? ''))
                )),
            // Il segno è l'informazione: "+10" e "-3" si leggono al volo.
            TableColumn::key('quantity')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(
                    static::signed((float) ($row['quantity'] ?? 0))
                )),
            TableColumn::key('quantity_after')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(
                    static::plain((float) ($row['quantity_after'] ?? 0))
                )),
            TableColumn::key('note')->text(),
        ];
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        return TableLayoutSchema::for(static::class)
            ->title('Movimenti')
            ->results()
            ->hideButtonAdd()
            ->filterSearch()
            ->searchFields(['code', 'note'])
            ->filterCustom('Tipo', 'type', StockMovement::typeLabels())
            ->filterCustom('Causale', 'reason', Reasons::all());
    }

    public static function pageSchema(): PageSchema
    {
        // Un movimento non si modifica e non si cancella: si corregge con
        // un'altra rettifica.
        return parent::pageSchema()
            ->only(['list'])
            ->titles(['list' => 'Movimenti'])
            ->subtitles(['list' => 'Ogni pezzo entrato o uscito, con la sua causale. Si legge e basta: una quantità sbagliata si corregge con una rettifica.']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('magazzino', 'Magazzino', 'bi-boxes', 400, ['admin', 'administrator'])
            ->title('Movimenti')
            ->order(20)
            ->authority(['admin', 'administrator']);
    }

    public static function permissionSchema(): PermissionSchema
    {
        // Solo `list`: le altre azioni non esistono nemmeno come rotta.
        return PermissionSchema::for(static::class)->backend(['list'], ['admin', 'administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /**
     * L'elenco filtrato su una versione e su un periodo.
     *
     * Il core rivaluta `querySchema()` a ogni richiesta, quindi leggere la
     * query string qui è sicuro. Le date si controllano con una regex prima di
     * entrare nella condizione: è testo che arriva dall'indirizzo.
     */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        // La condizione di partenza è un array (`deleted => false`); appena si
        // aggiunge un intervallo di date serve una stringa, e le righe
        // cancellate vanno riportate a mano o tornerebbero a galla.
        $parts = ["deleted = 'false'"];
        $productId = (int) ($_GET['versione'] ?? 0);

        if ($productId > 0) {
            $parts[] = 'product_id = '.$productId;
        }

        if (($from = static::date($_GET['dal'] ?? '')) !== '') {
            $parts[] = "creation >= '".$from." 00:00:00'";
        }

        if (($to = static::date($_GET['al'] ?? '')) !== '') {
            $parts[] = "creation <= '".$to." 23:59:59'";
        }

        if (count($parts) > 1) {
            $schema['condition'] = implode(' AND ', $parts);
        }

        return $schema;
    }

    /** L'indirizzo dell'elenco filtrato su una versione in vendita. */
    public static function listUrlFor(int $productId): string
    {
        $base = '/backend/'.static::path();

        if (function_exists('__r')) {
            try {
                $named = (string) __r('backend.resource.'.static::slug().'.list');
                $base = $named !== '' ? $named : $base;
            } catch (Throwable) {
                // Rotta non registrata (test, comandi): resta il percorso.
            }
        }

        return $base.'?versione='.$productId;
    }

    /** Una data `YYYY-MM-DD`, o stringa vuota: niente altro entra in una query. */
    private static function date(mixed $value): string
    {
        $text = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $text) === 1 ? $text : '';
    }

    private static function signed(float $value): string
    {
        return ($value > 0 ? '+' : '').static::plain($value);
    }

    /** I pezzi interi si scrivono interi: "3", non "3,000". */
    private static function plain(float $value): string
    {
        $decimals = round($value, 3) === round($value, 0) ? 0 : 3;

        return number_format($value, $decimals, ',', '.');
    }

    /** @return array<int, string> */
    private static function productNames(): array
    {
        if (self::$productNames !== null) {
            return self::$productNames;
        }

        $names = [];

        foreach (static::rowsOf(Product::class) as $row) {
            $id = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            $names[$id] = $name !== '' ? $name : (string) ($row['sku'] ?? '');
        }

        return self::$productNames = $names;
    }
}
