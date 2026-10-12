<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

use DateTimeImmutable;
use Throwable;
use Wonder\App\LegacyGlobals;
use Wonder\App\Models\User\User;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Http\Csrf;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentItem;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentStatusLog;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\MovementPeriod;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentForm;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocuments;
use Wonder\Sql\Query;

/**
 * Carichi, Scarichi e (piano 6) Inventari: tre Resource sullo stesso Model
 * `StockDocument`, come Clienti e Fornitori su `Contact` (G2b-bis 4.2). Ognuna
 * vede solo il suo tipo; questa base tiene tutto il resto.
 *
 * Le pagine non scrivono mai il documento: lo fa `StockDocuments`. Righe,
 * pezzi, chi e data dell'elenco nascono nella `select()`, mai una query per
 * riga.
 */
abstract class StockDocumentResource extends GestionaleResource
{
    public static string $model = StockDocument::class;
    public static string $orderColumn = 'sort_date';
    public static string $orderDirection = 'DESC';
    public static string $docsPage = 'magazzino/magazzino-carichi-scarichi';

    /** Il tipo del documento: `receipt`, `issue` (`stocktake` nel piano 6). */
    abstract public static function type(): string;

    /** «Carico», «Scarico»: il titolo della scheda e delle domande. */
    abstract public static function singular(): string;

    /** «Nuovo carico». */
    abstract public static function newLabel(): string;

    /** «Conferma carico». */
    abstract public static function confirmLabel(): string;

    public static function labelSchema(): array
    {
        return [
            'number' => 'Numero',
            'sort_date' => 'Data',
            'location_id' => 'Sede',
            'reason' => 'Causale',
            'row_count' => 'Righe',
            'piece_count' => 'Pezzi',
            'note' => 'Nota',
            'who_name' => 'Chi',
        ];
    }

    public static function tableSchema(): array
    {
        $columns = [
            TableColumn::key('number')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::numberCell($row)),
            TableColumn::key('sort_date')
                ->text()
                ->size('little')
                ->sortable()
                ->formatter(static fn (array $row): string => static::dateCell($row)),
        ];

        if (static::showsLocation()) {
            $columns[] = TableColumn::key('location_id')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(static::locationOptions()[(string) ($row['location_id'] ?? '')] ?? ''));
        }

        if (static::type() === 'issue') {
            $columns[] = TableColumn::key('reason')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(static::reasonLabel((string) ($row['reason'] ?? ''))));
        }

        foreach (['row_count', 'piece_count'] as $key) {
            $columns[] = TableColumn::key($key)
                ->text()
                ->size('little')
                ->sortable()
                ->formatter(static fn (array $row): string => static::number($row[$key] ?? 0));
        }

        $columns[] = TableColumn::key('note')
            ->text()
            ->formatter(static fn (array $row): string => static::escapeStored((string) ($row['note'] ?? '')));
        $columns[] = TableColumn::key('who_name')
            ->text()
            ->size('little')
            ->formatter(static fn (array $row): string => static::escape((string) ($row['who_name'] ?? '')));
        $columns[] = TableColumn::key('actions')->button()->actions(static::rowActions());

        return $columns;
    }

    /** Solo il tipo di questa Resource. */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        $base = Query::Conditions($schema['condition'] ?? [], false);
        $type = '`'.StockDocument::$table."`.`type` = '".static::type()."'";

        $schema['condition'] = ($base !== '' ? $base.' AND ' : '').$type;

        return $schema;
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        $schema = TableLayoutSchema::for(static::class)
            ->title(static::titleLabel())
            ->results()
            ->buttonAdd(true, static::newLabel())
            ->select(static::select())
            ->filterSearch()
            ->searchFields(['number', 'note'])
            ->filterCustom('Stato', 'status', ['' => 'Tutti', 'draft' => 'Bozze', 'completed' => 'Confermati', 'cancelled' => 'Annullati']);

        if (static::showsLocation()) {
            $schema->filterQuery('Sede', 'sede', ['' => 'Tutte'] + static::locationOptions(), static fn (array $values): string => StockMovementResource::locationCondition($values));
        }

        $schema->filterQuery(
            'Periodo',
            'periodo',
            MovementPeriod::filterOptions(),
            static fn (array $values): string => static::periodCondition($values, new DateTimeImmutable('now'))
        );

        if (static::type() === 'issue') {
            $schema->filterCustom('Causale', 'reason', ['' => 'Tutte'] + Reasons::forDocument('issue'));
        }

        return $schema;
    }

    /**
     * Le colonne calcolate dell'elenco.
     *
     * - `sort_date`: il giorno del documento (della conferma; della creazione
     *   per una bozza) e l'id, così a parità di giorno il più recente sta in
     *   cima;
     * - `row_count`, `piece_count`: righe e pezzi;
     * - `who_name`: chi ha confermato, dal log degli stati; per una bozza chi
     *   l'ha creata.
     */
    public static function select(): string
    {
        $doc = '`'.StockDocument::$table.'`';
        $items = '`'.StockDocumentItem::$table.'`';
        $logs = '`'.StockDocumentStatusLog::$table.'`';
        $users = '`'.User::$table.'`';
        $whoId = 'IF('.$doc.".`status` = 'draft', ".$doc.'.`user_id`, (SELECT l.`user_id` FROM '.$logs.' l'
            .' WHERE l.`stock_document_id` = '.$doc.".`id` AND l.`to_value` = 'completed' AND l.`deleted` = 'false'"
            .' ORDER BY l.`id` DESC LIMIT 1))';

        return implode(', ', [
            'CONCAT(COALESCE('.$doc.'.`date`, DATE('.$doc.".`creation`)), ' ', LPAD(".$doc.".`id`, 10, '0')) AS sort_date",
            '(SELECT COUNT(*) FROM '.$items.' i WHERE i.`stock_document_id` = '.$doc.".`id` AND i.`deleted` = 'false') AS row_count",
            '(SELECT COALESCE(SUM(i.`quantity`), 0) FROM '.$items.' i WHERE i.`stock_document_id` = '.$doc.".`id` AND i.`deleted` = 'false') AS piece_count",
            "(SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.`name`, u.`surname`)), ''), u.`username`) FROM ".$users.' u WHERE u.`id` = '.$whoId.' LIMIT 1) AS who_name',
        ]);
    }

    /**
     * Il filtro *Periodo* sulla *Data* dell'elenco. La chiave scelta non entra
     * mai nell'SQL: solo le date di `MovementPeriod::range()`.
     *
     * @param list<string> $values
     */
    public static function periodCondition(array $values, DateTimeImmutable $now): string
    {
        $range = MovementPeriod::range((string) ($values[0] ?? ''), $now);

        if ($range === null) {
            return '';
        }

        $doc = '`'.StockDocument::$table.'`';

        return 'COALESCE('.$doc.'.`date`, DATE('.$doc.'.`creation`)) BETWEEN \''.substr($range['from'], 0, 10).'\' AND \''.substr($range['to'], 0, 10).'\'';
    }

    /** La colonna e il filtro *Sede* servono da due sedi in su. */
    public static function showsLocation(): bool
    {
        return count(Locations::shown()) >= 2;
    }

    /** @return array<string, string> id della sede → nome */
    public static function locationOptions(): array
    {
        $options = [];

        foreach (Locations::shown() as $location) {
            $options[(string) $location['id']] = $location['label'];
        }

        return $options;
    }

    /**
     * La Resource che ha le pagine di questo tipo, o null.
     *
     * @return class-string<StockDocumentResource>|null
     */
    public static function classFor(string $type): ?string
    {
        return [
            'receipt' => StockReceiptResource::class,
            'issue' => StockIssueResource::class,
        ][$type] ?? null;
    }

    /**
     * La pagina giusta per il documento: la bozza si apre in modifica, gli
     * altri stati in sola lettura.
     *
     * @param array<string, mixed> $document una riga di `gst_stock_documents`
     */
    public static function pageUrl(array $document): string
    {
        $class = static::classFor((string) ($document['type'] ?? ''));

        if ($class === null) {
            return StockMovementResource::listUrl();
        }

        $id = (int) ($document['id'] ?? 0);

        return (string) ($document['status'] ?? '') === 'draft' ? $class::editUrl($id) : $class::viewUrl($id);
    }

    public static function viewUrl(int $id): string
    {
        return static::routeUrl('view', '/backend/'.static::path().'/'.$id.'/', ['id' => $id]);
    }

    public static function editUrl(int $id): string
    {
        return static::routeUrl('edit', '/backend/'.static::path().'/'.$id.'/edit/', ['id' => $id]);
    }

    public static function createUrl(): string
    {
        return static::routeUrl('create', '/backend/'.static::path().'/create/');
    }

    public static function listUrl(): string
    {
        return static::routeUrl('list', '/backend/'.static::path().'/');
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only(['list', 'create', 'store', 'edit', 'update', 'delete'])
            ->titles([
                'list' => static::titleLabel(),
                'create' => static::newLabel(),
                'edit' => 'Bozza di '.mb_strtolower(static::singular()),
            ])
            ->view('form', Gestionale::viewPath('pages/stock-document-form.php'));
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('magazzino', 'Magazzino', 'bi-boxes', 400, ['admin', 'administrator'])
            ->title(static::titleLabel())
            ->order(static::navigationOrder())
            ->authority(['admin', 'administrator']);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)
            ->backend(['list', 'create', 'store', 'view', 'edit', 'update', 'delete'], ['admin', 'administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /**
     * Si eliminano solo le bozze: vale per la pagina e per *Elimina* del menu
     * ⋯ (`api/backend/delete`), che trasforma il rifiuto nel messaggio.
     */
    public static function assertDeletable(int|string $id): void
    {
        StockDocuments::assertDeletable((int) $id);
    }

    /** Il servizio toglie anche le righe: il `delete()` del Model lascerebbe le righe orfane. */
    public static function deleteRecord(int|string $id): object
    {
        static::assertDeletable($id);
        StockDocuments::delete((int) $id);

        return (object) ['success' => true, 'table' => StockDocument::$table, 'id' => $id];
    }

    /**
     * Il POST di *Salva bozza* e *Conferma* (rotte `store` e `update` del
     * core). Non restituisce mai i valori: il core scriverebbe le colonne del
     * form senza righe né controlli del servizio. Qui si controlla il CSRF, si
     * passa a `StockDocumentForm` e si chiude con messaggio e redirect.
     */
    public static function mutateRequestValues(array $values, string $action, string $context = 'backend', ?array $oldValues = null): array
    {
        $id = (int) ($oldValues['id'] ?? 0);
        $result = Csrf::verify()
            ? static::handleSubmit($values, $id, static::currentUserId())
            : [
                'ok' => false,
                'message' => UserError::make('stock_document.expired')->getMessage(),
                'url' => $id > 0 ? static::editUrl($id) : static::createUrl(),
            ];

        StockDocumentForm::respond($result);
    }

    /**
     * L'invio senza HTTP, con la frase da mostrare e la pagina dove tornare.
     *
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string, url: string}
     */
    public static function handleSubmit(array $post, int $id, int $userId): array
    {
        $result = StockDocumentForm::submit(static::type(), $id, $post, $userId);

        if ($result['error'] !== '') {
            $message = $result['error'];
        } elseif ($result['number'] !== '') {
            $message = static::singular().' '.$result['number'].' confermato.';
        } else {
            $message = 'Bozza salvata.';
        }

        return ['ok' => $result['error'] === '', 'message' => $message, 'url' => static::redirectFor($result['id'])];
    }

    /**
     * Dove si torna dopo un invio: la pagina del documento per il suo stato
     * (la bozza, o la scheda di un confermato); l'elenco se non c'è più o è di
     * un altro tipo; «Nuovo» se il documento non è nato.
     */
    public static function redirectFor(int $id): string
    {
        if ($id <= 0) {
            return static::createUrl();
        }

        $document = StockDocument::findById($id);

        if (!is_array($document) || $document === []
            || (string) ($document['deleted'] ?? 'false') === 'true'
            || (string) ($document['type'] ?? '') !== static::type()) {
            return static::listUrl();
        }

        return static::pageUrl($document);
    }

    /** La bozza si modifica; un documento confermato o annullato si apre in sola lettura. */
    public static function closedRedirect(array $values, string $mode): ?string
    {
        if ($mode !== 'edit' || (string) ($values['status'] ?? 'draft') === 'draft') {
            return null;
        }

        return static::viewUrl((int) ($values['id'] ?? 0));
    }

    /**
     * La domanda prima della conferma (spec §4.2). `{pezzi}` e `{righe}` li
     * riempie la pagina con i numeri della tabella.
     */
    public static function confirmQuestion(): string
    {
        $where = static::type() === 'issue' ? 'escono dal' : 'entrano in';

        return '{pezzi} pezzi in {righe} righe '.$where.' magazzino. Dopo non si modifica più: si può solo annullare.';
    }

    /**
     * Le righe la cui versione è stata eliminata non si disegnano
     * (`StockDocumentForm::editorLines()`): la pagina lo dice, perché salvando
     * escono anche dal documento.
     */
    public static function formNotices(array $values, string $mode): array
    {
        $notices = parent::formNotices($values, $mode);
        $dropped = (int) ($values['editor']['dropped'] ?? 0);

        if ($dropped === 1) {
            $notices[] = ['level' => 'warning', 'message' => "Una riga non c'è più: la sua versione è stata eliminata. Salvando la bozza esce anche dal documento."];
        } elseif ($dropped > 1) {
            $notices[] = ['level' => 'warning', 'message' => $dropped.' righe non ci sono più: le loro versioni sono state eliminate. Salvando la bozza escono anche dal documento.'];
        }

        return $notices;
    }

    /**
     * Il form della bozza: un documento chiuso aperto in modifica (link
     * vecchio, altra scheda) va alla sua scheda; le righe arrivano descritte
     * per l'editor in `editor`.
     */
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        $closed = static::closedRedirect($values, $mode);

        if ($closed !== null) {
            if (!headers_sent()) {
                header('Location: '.$closed);
            }

            exit();
        }

        $locationId = (int) ($values['location_id'] ?? 0);
        $values['editor'] = StockDocumentForm::editorLines(
            $mode === 'edit' ? StockDocuments::items((int) ($values['id'] ?? 0)) : [],
            $locationId > 0 ? $locationId : Locations::mainId()
        );

        return $values;
    }

    protected static function currentUserId(): int
    {
        $user = LegacyGlobals::get('USER');

        return is_object($user) ? (int) ($user->id ?? 0) : 0;
    }

    /** Il posto nel menu *Magazzino*: Giacenze 10, Carichi 20, Scarichi 30, Inventari 40, Movimenti 50. */
    abstract protected static function navigationOrder(): int;

    /**
     * Il menu ⋯: *Modifica* ed *Elimina* sulle bozze, *Visualizza* sugli
     * altri, *Duplica* su tutti. *Duplica* apre la pagina del documento con
     * `?duplica=1`, che invia il form nascosto della pagina (Task 5 e 6).
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function rowActions(): array
    {
        $draft = ['row' => ['status' => 'draft']];
        $closed = ['row' => ['status' => ['completed', 'cancelled']]];

        return [
            'modify' => ['filter' => $draft],
            'view' => ['filter' => $closed],
            'duplica_bozza' => ['label' => 'Duplica', 'href' => static::rowUrl('edit', '/edit/').'?duplica=1', 'filter' => $draft],
            'duplica' => ['label' => 'Duplica', 'href' => static::rowUrl('view', '/').'?duplica=1', 'filter' => $closed],
            'delete' => ['filter' => $draft],
        ];
    }

    /** Numero, o il badge *Bozza*; *Annullato* accanto al numero. */
    protected static function numberCell(array $row): string
    {
        $number = static::escape((string) ($row['number'] ?? ''));

        return match ((string) ($row['status'] ?? '')) {
            'draft' => '<span class="badge text-bg-secondary">Bozza</span>',
            'cancelled' => $number.' <span class="badge text-bg-danger">Annullato</span>',
            default => $number,
        };
    }

    /** Il giorno della conferma; per una bozza, in grigio, quello della creazione. */
    protected static function dateCell(array $row): string
    {
        $draft = (string) ($row['status'] ?? '') === 'draft';
        $raw = $draft ? substr((string) ($row['creation'] ?? ''), 0, 10) : (string) ($row['date'] ?? '');
        $time = $raw !== '' ? strtotime($raw) : false;
        $text = $time === false ? '' : date('d/m/Y', $time);

        return $draft ? '<span class="text-muted">'.static::escape($text).'</span>' : static::escape($text);
    }

    protected static function reasonLabel(string $reason): string
    {
        return $reason === '' ? '' : Reasons::label($reason);
    }

    /** Un numero di pezzi in cella: a destra, cifre in colonna, interi senza decimali. */
    protected static function number(mixed $value): string
    {
        $number = round((float) $value, 3) + 0.0;
        $decimals = round($number, 3) === round($number, 0) ? 0 : 3;

        return '<span class="d-block text-end" style="font-variant-numeric: tabular-nums">'
            .static::escape(number_format($number, $decimals, ',', '.')).'</span>';
    }

    /** L'indirizzo di una pagina del documento con `{id}` al posto dell'id, per il menu ⋯. */
    private static function rowUrl(string $action, string $suffix): string
    {
        $url = static::routeUrl($action, '/backend/'.static::path().'/__ROW_ID__'.$suffix, ['id' => '__ROW_ID__']);

        return str_replace('__ROW_ID__', '{id}', $url);
    }

    /** @param array<string, mixed> $params */
    protected static function routeUrl(string $action, string $fallback, array $params = []): string
    {
        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.static::slug().'.'.$action, $params);
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
    }
}
