<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

use DateTimeImmutable;
use Throwable;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\App\Models\User\User;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\MovementPeriod;
use Wonder\Plugin\Gestionale\Support\Stock\ProductNames;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;

/**
 * "Movimenti": la storia del magazzino, in sola lettura.
 *
 * Risponde a una domanda sola — "perché qui c'è scritto 3?" — e per farlo non
 * serve nessun pulsante: un movimento non si modifica e non si cancella, si
 * corregge con un'altra rettifica. Per questo la pagina ha il solo elenco.
 *
 * Tipo, sede e periodo sono filtri con la loro condizione; la versione arriva
 * dall'indirizzo, perché la scheda della versione linka qui già filtrata.
 */
final class StockMovementResource extends GestionaleResource
{
    public static string $model = StockMovement::class;
    public static string $orderColumn = 'id';
    public static string $orderDirection = 'DESC';
    public static string $docsPage = 'magazzino/magazzino-movimenti';

    /** @var array<string, array<string, mixed>> le righe già lette, per classe e id */
    private static array $found = [];

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
            'product_id' => 'Versione',
            'location_id' => 'Sede',
            'type' => 'Tipo',
            'reason' => 'Causale',
            'quantity_before' => 'Prima',
            'quantity' => 'Pezzi',
            'quantity_after' => 'Dopo',
            'user_id' => 'Chi',
            'note' => 'Nota',
        ];
    }

    public static function tableSchema(): array
    {
        $columns = [
            TableColumn::key('creation')
                ->text()
                ->size('little')
                ->sortable()
                ->formatter(static fn (array $row): string => static::escape(OrderResource::date((string) ($row['creation'] ?? '')))),
            TableColumn::key('product_id')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::productLabel((int) ($row['product_id'] ?? 0)))),
        ];

        if (static::showsLocation()) {
            $columns[] = TableColumn::key('location_id')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(static::locationLabel((int) ($row['location_id'] ?? 0))));
        }

        $columns[] = TableColumn::key('type')
            ->text()
            ->formatter(static fn (array $row): string => static::typeCell($row, ...static::documentOf($row)));
        $columns[] = TableColumn::key('reason')
            ->text()
            ->size('little')
            ->formatter(static fn (array $row): string => static::escape(
                Reasons::label((string) ($row['reason'] ?? ''))
            ));
        $columns[] = TableColumn::key('quantity_before')
            ->text()
            ->size('little')
            ->formatter(static fn (array $row): string => static::escape(
                static::plain((float) ($row['quantity_before'] ?? 0))
            ));
        // Il segno è l'informazione: "+10" e "-3" si leggono al volo.
        $columns[] = TableColumn::key('quantity')
            ->text()
            ->size('little')
            ->formatter(static fn (array $row): string => static::escape(
                static::signed((float) ($row['quantity'] ?? 0))
            ));
        $columns[] = TableColumn::key('quantity_after')
            ->text()
            ->size('little')
            ->formatter(static fn (array $row): string => static::escape(
                static::plain((float) ($row['quantity_after'] ?? 0))
            ));
        $columns[] = TableColumn::key('user_id')
            ->text()
            ->size('little')
            ->formatter(static fn (array $row): string => static::whoCell($row, static::userName((int) ($row['user_id'] ?? 0))));
        $columns[] = TableColumn::key('note')->text();
        $columns[] = TableColumn::key('actions')->button()->actions([
            'versione' => ['label' => 'Apri la versione', 'href' => static::productUrl()],
            'ordine' => [
                'label' => 'Visualizza ordine',
                'href' => OrderResource::detailUrlPattern('{reference_id}'),
                'filter' => ['row' => ['reference_type' => 'order']],
            ],
        ]);

        return $columns;
    }

    /**
     * Le colonne della cronologia dentro la scheda della versione: le stesse
     * celle dell'elenco, senza la versione (la scheda è già la sua) e senza la
     * nota, che ruberebbe la riga alle quantità.
     *
     * @return list<string>
     */
    public static function historyColumns(): array
    {
        return array_values(array_filter([
            'creation',
            'type',
            static::showsLocation() ? 'location_id' : null,
            'reason',
            'quantity',
            'quantity_after',
        ]));
    }

    /** La colonna e il filtro *Sede* servono da due sedi in su: con una sola non dicono niente. */
    public static function showsLocation(): bool
    {
        return count(Locations::shown()) >= 2;
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        $schema = TableLayoutSchema::for(static::class)
            ->title(static::listTitle())
            ->results()
            ->hideButtonAdd()
            ->filterSearch()
            ->searchFields(['code', 'note', [
                'table' => Product::$table,
                'local_key' => 'product_id',
                'foreign_key' => 'id',
                'columns' => ['sku', 'ean', 'name'],
                'relations' => [[
                    'table' => ProductModel::$table,
                    'local_key' => 'product_model_id',
                    'foreign_key' => 'id',
                    'columns' => ['name'],
                ]],
            ]])
            ->filterQuery('Tipo', 'tipo', ['' => 'Tutti'] + StockMovement::typeLabels(), static fn (array $values): string => static::typeCondition($values))
            ->filterCustom('Causale', 'reason', Reasons::all());

        if (static::showsLocation()) {
            $schema->filterQuery('Sede', 'sede', static::locationOptions(), static fn (array $values): string => static::locationCondition($values));
        }

        $schema->filterQuery(
            'Periodo',
            'periodo',
            MovementPeriod::filterOptions(),
            static fn (array $values): string => MovementPeriod::sql((string) ($values[0] ?? ''), 'creation', new DateTimeImmutable('now'))
        );

        if (static::versionFilter() > 0) {
            $schema->buttonCustomHtml(
                '<a class="btn btn-sm btn-outline-secondary" href="'.static::escape(static::listUrl()).'">'
                .'<i class="bi bi-x-lg me-1"></i>Mostra tutti</a>'
            );
        }

        return $schema;
    }

    /** Il titolo: «Movimenti», o «Movimenti di Maglia — Rossa, M» quando l'elenco è ristretto a una versione. */
    private static function listTitle(): string
    {
        $productId = static::versionFilter();

        return $productId > 0 ? 'Movimenti di '.static::productLabel($productId) : 'Movimenti';
    }

    /** La versione dall'indirizzo, o 0: è testo che arriva dall'utente, entra solo come intero. */
    private static function versionFilter(): int
    {
        $text = trim((string) ($_GET['versione'] ?? ''));

        return preg_match('/^\d+$/D', $text) === 1 ? (int) $text : 0;
    }

    /**
     * La condizione del filtro *Tipo*: solo i tipi che esistono.
     *
     * @param list<string> $values
     */
    public static function typeCondition(array $values): string
    {
        $types = array_values(array_intersect(array_map('strval', $values), StockMovement::TYPES));

        return $types === [] ? '' : '`type` IN ('.implode(', ', array_map(static fn (string $t): string => "'".$t."'", $types)).')';
    }

    /**
     * La condizione del filtro *Sede*: solo id interi.
     *
     * @param list<string> $values
     */
    public static function locationCondition(array $values): string
    {
        $ids = [];

        foreach ($values as $value) {
            if (preg_match('/^\d+$/D', (string) $value) === 1 && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return $ids === [] ? '' : '`location_id` IN ('.implode(', ', array_unique($ids)).')';
    }

    /** @return array<string, string> */
    private static function locationOptions(): array
    {
        $options = ['' => 'Tutte'];

        foreach (Locations::shown() as $location) {
            $options[(string) $location['id']] = $location['label'];
        }

        return $options;
    }

    /**
     * Chi ha mosso i pezzi: la persona, se c'è; altrimenti da dove è venuto il
     * movimento. Un'origine sconosciuta e un utente sparito contano come
     * «Sistema»: la domanda è «ha toccato una persona?», e qui la risposta è no.
     */
    public static function who(array $row, ?string $userName): string
    {
        $name = trim((string) $userName);

        if ($name !== '') {
            return $name;
        }

        return (string) ($row['source'] ?? '') === 'import' ? 'Importazione' : 'Sistema';
    }

    public static function whoCell(array $row, ?string $userName): string
    {
        return static::escape(static::who($row, $userName));
    }

    /**
     * La cella *Tipo*: l'etichetta e, per un movimento nato da un documento, il
     * suo numero con il link. Senza numero (il documento non c'è più, o non è
     * ancora un tipo noto) resta l'etichetta.
     */
    public static function typeCell(array $row, string $number, string $url): string
    {
        $label = static::escape(StockMovement::typeLabels()[(string) ($row['type'] ?? '')] ?? '');

        if ($number === '') {
            return $label;
        }

        $number = static::escape($number);

        return $label.' · '.($url === '' ? $number : '<a href="'.static::escape($url).'">'.$number.'</a>');
    }

    /**
     * Il numero e il link del documento che ha causato il movimento.
     *
     * @return array{0: string, 1: string}
     */
    private static function documentOf(array $row): array
    {
        $id = (int) ($row['reference_id'] ?? 0);

        if ((string) ($row['reference_type'] ?? '') !== 'order' || $id <= 0) {
            return ['', ''];
        }

        $number = trim((string) (static::one(Order::class, $id)['order_number'] ?? ''));

        return $number === '' ? ['', ''] : [$number, OrderResource::detailUrl($id)];
    }

    public static function pageSchema(): PageSchema
    {
        // Un movimento non si modifica e non si cancella: si corregge con
        // un'altra rettifica.
        return parent::pageSchema()
            ->only(['list'])
            ->titles(['list' => static::listTitle()])
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
     * L'elenco filtrato su una versione, se l'indirizzo lo chiede.
     *
     * Il core rivaluta `querySchema()` a ogni richiesta, quindi leggere la
     * query string qui è sicuro. La condizione di partenza è un array
     * (`deleted => false`); appena si aggiunge la versione serve una stringa,
     * e le righe cancellate vanno riportate a mano o tornerebbero a galla.
     */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        $productId = static::versionFilter();

        if ($productId > 0) {
            $schema['condition'] = "deleted = 'false' AND product_id = ".$productId;
        }

        return $schema;
    }

    /** L'indirizzo dell'elenco filtrato su una versione. */
    public static function listUrlFor(int $productId): string
    {
        return static::listUrl().'?versione='.$productId;
    }

    /** L'indirizzo dell'elenco, dalla rotta con il nome; il percorso è il ripiego. */
    public static function listUrl(): string
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

        return $base;
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

    /** «Maglia — Rossa, M»: l'articolo e la versione, come ovunque nel gestionale. */
    public static function productLabel(int $productId): string
    {
        $product = static::one(Product::class, $productId);

        if ($product === []) {
            return '—';
        }

        $names = ProductNames::of($product, ProductNames::models([$product]));

        return $names['option'] === '' ? $names['article'] : $names['article'].' — '.$names['option'];
    }

    private static function locationLabel(int $locationId): string
    {
        foreach (Locations::shown() as $location) {
            if ($location['id'] === $locationId) {
                return $location['label'];
            }
        }

        $name = trim((string) (static::one(Location::class, $locationId)['name'] ?? ''));

        return $name !== '' ? $name : 'Sede #'.$locationId;
    }

    private static function userName(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        $user = static::one(User::class, $userId);
        $name = trim(trim((string) ($user['name'] ?? '')).' '.trim((string) ($user['surname'] ?? '')));

        return $name !== '' ? $name : trim((string) ($user['username'] ?? ''));
    }

    /**
     * Una riga per id, letta una volta sola per richiesta; anche cancellata, perché
     * la storia resta. Una riga che non c'è non si ricorda: si riprova.
     *
     * @param class-string<\Wonder\App\Model> $modelClass
     * @return array<string, mixed>
     */
    private static function one(string $modelClass, int $id): array
    {
        $key = $modelClass.'#'.$id;

        if (isset(self::$found[$key])) {
            return self::$found[$key];
        }

        try {
            $row = $modelClass::findById($id);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($row) || !isset($row['id'])) {
            return [];
        }

        return self::$found[$key] = $row;
    }

    /** Le righe lette si dimenticano: i test, le transazioni annullate. */
    public static function reset(): void
    {
        self::$found = [];
    }

    /** "Apri la versione": la sua scheda, con l'id della riga al posto giusto. */
    private static function productUrl(): string
    {
        $fallback = '/backend/'.ProductResource::path().'/{product_id}/edit/';

        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.ProductResource::slug().'.edit', ['id' => '__ROW_ID__']);
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? str_replace('__ROW_ID__', '{product_id}', $named) : $fallback;
    }
}
