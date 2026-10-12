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
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\DataItem;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\MovementPeriod;
use Wonder\Plugin\Gestionale\Support\Stock\ProductNames;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentRules;

/**
 * "Movimenti": la storia del magazzino, in sola lettura.
 *
 * Risponde a una domanda sola — "perché qui c'è scritto 3?" — e per farlo non
 * serve nessun pulsante: un movimento non si modifica e non si cancella, si
 * corregge con un'altra rettifica. Per questo ci sono l'elenco e la scheda di
 * ogni movimento, e basta.
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
            ->formatter(static fn (array $row): string => static::typeCellOf($row));
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
            // `view` e `ordine` sono quelli della #25. La chiave del documento
            // è la colonna calcolata di select(): l'etichetta si
            // sceglie sul suo valore, e senza documento (NULL) o con un tipo
            // senza pagina la voce non compare.
            'view',
            'document_type' => [
                'label' => ['receipt' => 'Visualizza carico', 'issue' => 'Visualizza scarico', 'stocktake' => 'Visualizza inventario'],
                'href' => '{document_url}',
            ],
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
            ->select(static::select())
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
            ->filterQuery('Tipo', 'tipo', static::typeOptions(), static fn (array $values): string => static::typeCondition($values))
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

    /**
     * Le colonne calcolate dell'elenco: tipo, numero e indirizzo della scheda
     * del documento di magazzino che ha causato il movimento, storni compresi.
     * Valgono NULL sulle altre righe. Una sottoquery per colonna nella stessa
     * query della pagina, mai una lettura per riga.
     */
    public static function select(): string
    {
        $movements = '`'.StockMovement::$table.'`';
        $of = static fn (string $expression, string $alias): string => '(SELECT '.$expression
            .' FROM `'.StockDocument::$table.'` d WHERE d.`id` = '.$movements.'.`reference_id`'
            .' AND '.$movements.'.`reference_type` IN ('.self::DOCUMENT_REFERENCES.') LIMIT 1) AS '.$alias;

        return implode(', ', [
            $of('d.`type`', 'document_type'),
            $of('d.`number`', 'document_number'),
            $of(static::documentUrlSql(), 'document_url'),
        ]);
    }

    /**
     * L'indirizzo della scheda in SQL, sul tipo del documento `d`: un ramo per
     * ogni tipo che ha una pagina (`StockDocumentResource::classFor()`), stringa
     * vuota per gli altri. I pezzi dell'indirizzo vengono dalle rotte, non
     * dall'utente; si quotano lo stesso.
     */
    private static function documentUrlSql(): string
    {
        $quote = static fn (string $text): string => "'".addcslashes($text, "\\'")."'";
        $cases = '';

        foreach (StockDocumentRules::TYPES as $type) {
            $class = StockDocumentResource::classFor($type);

            if ($class === null) {
                continue;
            }

            [$before, $after] = explode('__DOC_ID__', $class::viewUrlPattern('__DOC_ID__'), 2) + ['', ''];
            $cases .= ' WHEN '.$quote($type).' THEN CONCAT('.$quote($before).', d.`id`, '.$quote($after).')';
        }

        return $cases === '' ? "''" : 'CASE d.`type`'.$cases." ELSE '' END";
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

    /** I movimenti che nascono da un documento di magazzino o dal suo annullamento. */
    private const DOCUMENT_REFERENCES = "'stock_document', 'stock_document_cancel'";

    /**
     * Le voci del filtro *Tipo*: al posto del carico registrato ci sono i tre
     * documenti, perché uno scarico è registrato come rettifica.
     *
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        $options = ['' => 'Tutti'];

        foreach (StockMovement::typeLabels() as $type => $label) {
            if ($type !== 'purchase') {
                $options[$type] = $label;

                continue;
            }

            foreach (StockDocumentRules::labels() as $document => $documentLabel) {
                $options['doc_'.$document] = $documentLabel;
            }
        }

        return $options;
    }

    /**
     * La condizione del filtro *Tipo*, con la stessa regola della colonna:
     * *Carico*, *Scarico* e *Inventario* guardano il documento, storni
     * compresi (*Carico* anche i carichi senza documento, che la colonna
     * chiama così); *Rettifica* è una rettifica senza documento. Solo voci
     * che esistono: è testo che arriva dall'utente.
     *
     * La condizione è per l'elenco (SSP), che la mette in una WHERE sua: non
     * va passata a `Model::find()`, dove la sottoquery rompe l'SQL.
     *
     * @param list<string> $values
     */
    public static function typeCondition(array $values): string
    {
        $withoutDocument = '(`reference_type` IS NULL OR `reference_type` NOT IN ('.self::DOCUMENT_REFERENCES.'))';
        $parts = [];

        foreach (array_unique(array_map('strval', $values)) as $value) {
            $document = str_starts_with($value, 'doc_') ? substr($value, 4) : '';

            if ($document !== '' && StockDocumentRules::exists($document)) {
                $part = '(`reference_type` IN ('.self::DOCUMENT_REFERENCES.') AND `reference_id` IN (SELECT `id` FROM `'
                    .StockDocument::$table."` WHERE `type` = '".$document."'))";
                $parts[] = $document === 'receipt' ? '('.$part." OR (`type` = 'purchase' AND ".$withoutDocument.'))' : $part;
            } elseif ($value === 'adjustment') {
                $parts[] = "(`type` = 'adjustment' AND ".$withoutDocument.')';
            } elseif ($value !== 'purchase' && in_array($value, StockMovement::TYPES, true)) {
                $parts[] = "`type` = '".$value."'";
            }
        }

        return match (count($parts)) {
            0 => '',
            1 => $parts[0],
            default => '('.implode(' OR ', $parts).')',
        };
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

    /** La cella *Tipo* di una riga, con il documento letto dal database. */
    public static function typeCellOf(array $row): string
    {
        return static::typeCell($row, ...static::documentOf($row));
    }

    /**
     * La cella *Tipo*: l'etichetta (quella del documento, se c'è, altrimenti
     * quella del tipo registrato) e il numero, con il link quando c'è.
     */
    public static function typeCell(array $row, string $number, string $url, string $label = ''): string
    {
        $label = static::escape($label !== '' ? $label : (StockMovement::typeLabels()[(string) ($row['type'] ?? '')] ?? ''));

        if ($number === '') {
            return $label;
        }

        $number = static::escape($number);

        return $label.' · '.($url === '' ? $number : '<a href="'.static::escape($url).'">'.$number.'</a>');
    }

    /**
     * Il numero, il link e l'etichetta del documento che ha causato il
     * movimento. Il numero di un documento di magazzino resta testo: la sua
     * scheda si apre dal menu ⋯ (spec §3). Tipo e numero arrivano da select();
     * una riga senza quelle colonne (letta fuori dall'elenco) li legge dal
     * database.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function documentOf(array $row): array
    {
        $id = (int) ($row['reference_id'] ?? 0);
        $reference = (string) ($row['reference_type'] ?? '');

        if ($id <= 0) {
            return ['', '', ''];
        }

        if ($reference === 'order') {
            $number = trim((string) (static::one(Order::class, $id)['order_number'] ?? ''));

            return $number === '' ? ['', '', ''] : [$number, OrderResource::detailUrl($id), ''];
        }

        if ($reference !== 'stock_document' && $reference !== 'stock_document_cancel') {
            return ['', '', ''];
        }

        $document = array_key_exists('document_type', $row)
            ? ['type' => $row['document_type'], 'number' => $row['document_number'] ?? '']
            : static::one(StockDocument::class, $id);
        $number = trim((string) ($document['number'] ?? ''));
        $type = (string) ($document['type'] ?? '');

        if ($number === '') {
            return ['', '', ''];
        }

        return [
            $number,
            '',
            $reference === 'stock_document' ? StockDocumentRules::label($type) : StockDocumentRules::cancelLabel($type),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        // Un movimento non si modifica e non si cancella: si corregge con
        // un'altra rettifica.
        return parent::pageSchema()
            ->only(['list'])
            ->enable(['view'])
            ->titles(['list' => static::listTitle(), 'view' => 'Movimento'])
            ->subtitles(['list' => 'Ogni pezzo entrato o uscito, con la sua causale. Si legge e basta: una quantità sbagliata si corregge con una rettifica.'])
            ->view('show', Gestionale::viewPath('pages/stock-movement-show.php'));
    }

    /**
     * La scheda di un movimento: tutto quello che la riga dell'elenco dice, più
     * quello che non ci sta (codice, costo, lotto, fornitore, nota intera).
     *
     * @param array<string, mixed> $movement
     */
    public static function showLayoutSchema(array $movement): Container
    {
        $dato = static fn (string $etichetta, string $valore, bool $html = false): DataItem => DataItem::make($etichetta, $valore)
            ->html($html)
            ->columnSpan(['default' => 12, 'sm' => 4]);
        $productId = (int) ($movement['product_id'] ?? 0);
        $costo = (float) ($movement['unit_cost'] ?? 0);
        $lotto = (int) ($movement['batch_id'] ?? 0);
        $fornitore = (int) ($movement['supplier_id'] ?? 0);

        $dati = [
            $dato('Codice', (string) ($movement['code'] ?? '')),
            $dato('Quando', OrderResource::date((string) ($movement['creation'] ?? ''))),
            $dato('Versione', '<a href="'.static::escape(str_replace('{product_id}', (string) $productId, static::productUrl())).'">'
                .static::escape(static::productLabel($productId)).'</a>', true),
        ];

        if (static::showsLocation()) {
            $dati[] = $dato('Sede', static::locationLabel((int) ($movement['location_id'] ?? 0)));
        }

        array_push(
            $dati,
            $dato('Tipo', static::typeCellOf($movement), true),
            $dato('Causale', Reasons::label((string) ($movement['reason'] ?? ''))),
            $dato('Chi', static::who($movement, static::userName((int) ($movement['user_id'] ?? 0)))),
            $dato('Prima', static::plain((float) ($movement['quantity_before'] ?? 0))),
            $dato('Pezzi', static::signed((float) ($movement['quantity'] ?? 0))),
            $dato('Dopo', static::plain((float) ($movement['quantity_after'] ?? 0))),
            $dato('Costo unitario', $costo > 0 ? OrderResource::money($costo) : '—'),
        );

        // Lotti e fornitori arrivano con gli acquisti: fino ad allora sono 0 e non si mostrano.
        if ($lotto > 0) {
            $dati[] = $dato('Lotto', '#'.$lotto);
        }

        if ($fornitore > 0) {
            $contatto = static::one(Contact::class, $fornitore);
            $dati[] = $dato('Fornitore', $contatto === [] ? '#'.$fornitore : Contacts::displayName($contatto));
        }

        $nota = html_entity_decode((string) ($movement['note'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $dati[] = DataItem::make('Nota', nl2br(static::escape($nota)))->html()->columnSpan(12);

        return (new Container)->columns(12)->components([
            (new Card)->components($dati)->columns(12)->columnSpan(12),
        ]);
    }

    /** L'indirizzo della scheda di un movimento, dalla rotta con il nome; il percorso è il ripiego. */
    public static function detailUrl(int $id): string
    {
        $fallback = '/backend/'.static::path().'/'.$id.'/';

        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.static::slug().'.view', ['id' => $id]);
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('magazzino', 'Magazzino', 'bi-boxes', 400, ['admin', 'administrator'])
            ->title('Movimenti')
            ->order(50)
            ->authority(['admin', 'administrator']);
    }

    public static function permissionSchema(): PermissionSchema
    {
        // Solo elenco e scheda: le altre azioni non esistono nemmeno come rotta.
        return PermissionSchema::for(static::class)->backend(['list', 'view'], ['admin', 'administrator']);
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

    /** La scheda della versione, con `{product_id}` al posto dell'id. */
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
