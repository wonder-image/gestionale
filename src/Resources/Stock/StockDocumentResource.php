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
use Wonder\Plugin\Gestionale\Support\Backend\HeaderActions;
use Wonder\Plugin\Gestionale\Support\Stock\StockValue;
use Wonder\Plugin\Gestionale\Support\Contacts\SupplierNames;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
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

    /** Il tipo del documento: `receipt`, `issue`, `stocktake`. */
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
            'counted_count' => 'Contate',
            'difference_count' => 'Con differenza',
            'note' => 'Nota',
            'who_name' => 'Chi',
            'status' => 'Stato',
            'supplier_id' => 'Fornitore',
            'total_cost' => 'Costo',
        ];
    }

    public static function tableSchema(): array
    {
        $columns = [
            TableColumn::key('number')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::numberCell($row)),
            // Lo stato in una colonna sua: il numero resta un link pulito.
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::statusCell($row)),
            TableColumn::key('sort_date')
                ->text()
                ->size('little')
                ->sortable()
                ->formatter(static fn (array $row): string => static::dateCell($row)),
        ];

        if (static::purchases()) {
            $columns[] = TableColumn::key('supplier_id')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(SupplierNames::of((int) ($row['supplier_id'] ?? 0))));
        }

        if (static::showsLocation() || static::type() === 'transfer') {
            $columns[] = TableColumn::key('location_id')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::locationOptions()[(string) ($row['location_id'] ?? '')] ?? ''));
        }

        // G3.16: il trasferimento ha anche la sede d'arrivo.
        if (static::type() === 'transfer') {
            $columns[] = TableColumn::key('to_location_id')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::locationOptions()[(string) ($row['to_location_id'] ?? '')] ?? ''));
        }

        if (static::type() === 'issue') {
            $columns[] = TableColumn::key('reason')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(static::reasonLabel((string) ($row['reason'] ?? ''))));
        }

        // Negli inventari i pezzi non dicono niente: contano le righe contate
        // e quelle dove contati e attesi non coincidono.
        $counts = static::type() === 'stocktake' ? ['row_count', 'counted_count', 'difference_count'] : ['row_count', 'piece_count'];

        foreach ($counts as $key) {
            $columns[] = TableColumn::key($key)
                ->text()
                ->size('little')
                ->sortable()
                ->formatter(static fn (array $row): string => static::number($row[$key] ?? 0));
        }

        if (static::purchases()) {
            $columns[] = TableColumn::key('total_cost')
                ->text()
                ->size('little')
                ->sortable()
                ->hiddenDevice('mobile')
                ->formatter(static fn (array $row): string => StockValue::money((float) ($row['total_cost'] ?? 0)));
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
            ->filterCustom('Stato', 'status', static::statusOptions());

        if (static::showsLocation()) {
            $schema->filterQuery('Sede', 'sede', ['' => 'Tutte'] + static::locationOptions(), static fn (array $values): string => static::locationFilter($values));
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

        if (static::purchases()) {
            $schema->filterQuery(
                'Fornitore',
                'fornitore',
                ['' => 'Tutti', '0' => 'Senza fornitore'] + SupplierNames::all(),
                static fn (array $values): string => SupplierNames::condition($values, '`'.StockDocument::$table.'`.`supplier_id`')
            );
        }

        return $schema;
    }

    /** @return array<string, string> le voci del filtro *Stato* */
    public static function statusOptions(): array
    {
        return ['' => 'Tutti', 'draft' => 'Bozze', 'completed' => 'Confermati', 'cancelled' => 'Annullati'];
    }

    /**
     * La condizione del filtro *Sede*: la sede del documento.
     *
     * @param list<string> $values
     */
    public static function locationFilter(array $values): string
    {
        return StockMovementResource::locationCondition($values);
    }

    /**
     * Le colonne calcolate dell'elenco.
     *
     * - `sort_date`: il giorno del documento (della conferma; della creazione
     *   per una bozza) e l'id, così a parità di giorno il più recente sta in
     *   cima;
     * - `row_count`, `piece_count`: righe e pezzi; negli inventari
     *   `counted_count` e `difference_count` al posto dei pezzi;
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
            .' WHERE l.`stock_document_id` = '.$doc.".`id` AND l.`to_value` IN ('completed', 'in_transit') AND l.`deleted` = 'false'"
            .' ORDER BY l.`id` DESC LIMIT 1))';

        $of = static fn (string $expression, string $where, string $alias): string => '(SELECT '.$expression.' FROM '.$items
            .' i WHERE i.`stock_document_id` = '.$doc.".`id` AND i.`deleted` = 'false'".$where.') AS '.$alias;
        $counts = static::type() === 'stocktake'
            ? [
                $of('COUNT(*)', ' AND i.`quantity` IS NOT NULL', 'counted_count'),
                $of('COUNT(*)', ' AND i.`quantity` IS NOT NULL AND ROUND(i.`quantity`, 3) <> ROUND(COALESCE(i.`expected_quantity`, 0), 3)', 'difference_count'),
            ]
            : [$of('COALESCE(SUM(i.`quantity`), 0)', '', 'piece_count')];

        return implode(', ', [
            'CONCAT(COALESCE('.$doc.'.`date`, DATE('.$doc.".`creation`)), ' ', LPAD(".$doc.".`id`, 10, '0')) AS sort_date",
            $of('COUNT(*)', '', 'row_count'),
            ...$counts,
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

    /**
     * Fornitore, documento del fornitore e costi (G3.7): solo nel carico e
     * solo con «Acquisti».
     */
    public static function purchases(): bool
    {
        return static::type() === 'receipt' && Gestionale::feature('purchasing');
    }

    /**
     * I fornitori della tendina del carico, più quello già scelto anche se
     * non è più attivo.
     *
     * @return array<int, string>
     */
    public static function supplierOptions(int $keep = 0): array
    {
        return Contacts::supplierOptions($keep > 0 ? [$keep] : []);
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
            'stocktake' => StockStocktakeResource::class,
            'transfer' => StockTransferResource::class,
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

    /**
     * L'indirizzo della scheda con un segnaposto al posto dell'id: per le
     * azioni-array (`{colonna}`) e per l'indirizzo calcolato dei Movimenti.
     */
    public static function viewUrlPattern(string $placeholder): string
    {
        $url = static::routeUrl('view', '/backend/'.static::path().'/__ROW_ID__/', ['id' => '__ROW_ID__']);

        return str_replace('__ROW_ID__', $placeholder, $url);
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
            ->only(['list', 'create', 'store', 'edit', 'update', 'view', 'delete'])
            ->titles([
                'list' => static::titleLabel(),
                'create' => static::newLabel(),
                'edit' => 'Bozza di '.mb_strtolower(static::singular()),
                'view' => static::singular(),
            ])
            ->view('form', Gestionale::viewPath('pages/stock-document-form.php'))
            ->view('show', Gestionale::viewPath('pages/stock-document-show.php'))
            ->actions('view', static fn (array $item): array => HeaderActions::group(static::headerActions($item)));
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

        // Si torna a «Nuovo» senza una bozza: le righe scritte non vanno perse.
        if (!$result['ok'] && $result['url'] === static::createUrl()) {
            StockDocumentForm::remember(static::type(), $values);
        }

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
     * La domanda prima della conferma (spec §4.2). Ogni `{chiave}` la riempie
     * la pagina con le parole dell'editor (`words()` nel JS): `{pezzi}` e
     * `{righe}` per carichi e scarichi, `{contate}`, `{differenza}` e
     * `{correzione}` per l'inventario.
     */
    public static function confirmQuestion(): string
    {
        $end = 'Dopo non si modifica più: si può solo annullare.';

        if (static::type() === 'stocktake') {
            return '{contate}, {differenza}: {correzione}. '.$end;
        }

        if (static::type() === 'transfer') {
            return '{pezzi} pezzi in {righe} righe partono per la sede d\'arrivo e restano in viaggio finché non li ricevi. Dopo non si modifica più: si può ricevere o annullare.';
        }

        $where = static::type() === 'issue' ? 'escono dal' : 'entrano in';

        return '{pezzi} pezzi in {righe} righe '.$where.' magazzino. '.$end;
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

    /** «Il carico», «Lo scarico»: il soggetto delle frasi su un documento. */
    public static function named(): string
    {
        return match (static::type()) {
            'issue' => 'Lo scarico',
            'stocktake' => 'L\'inventario',
            'transfer' => 'Il trasferimento',
            default => 'Il carico',
        };
    }

    /**
     * La domanda prima di *Annulla* (spec §4.2); `{numero}` lo riempie
     * `hiddenForms()`. Il trasferimento dice dove torna la merce (G3.16).
     */
    public static function cancelQuestion(string $status = 'completed'): string
    {
        if (static::type() === 'transfer') {
            return $status === 'in_transit'
                ? 'Il trasferimento {numero} si annulla: la merce in viaggio torna nella sede di partenza.'
                : 'Il trasferimento {numero} si annulla: la merce esce dalla sede d\'arrivo e torna in quella di partenza. Se all\'arrivo una parte è già uscita, non si annulla.';
        }

        return static::named().' {numero} si annulla: i suoi movimenti si stornano e il documento resta, annullato.';
    }

    /** Si annulla un documento confermato; un trasferimento anche in viaggio. */
    public static function cancellable(string $status): bool
    {
        return $status === 'completed' || (static::type() === 'transfer' && $status === 'in_transit');
    }

    /**
     * I bottoni dell'intestazione della scheda: *Duplica* sempre, *Annulla*
     * solo sui confermati. Inviano i form di `hiddenForms()`.
     *
     * @param array<string, mixed> $item
     * @return list<array<string, string>>
     */
    public static function headerActions(array $item): array
    {
        $actions = [[
            'label' => 'Duplica',
            'icon' => 'bi bi-files',
            'class' => 'btn-outline-dark btn-sm',
            'onclick' => "document.getElementById('wi-doc-duplicate').requestSubmit()",
        ]];

        if (static::type() === 'transfer' && (string) ($item['status'] ?? '') === 'in_transit') {
            $actions[] = [
                'label' => 'Ricevi',
                'icon' => 'bi bi-box-arrow-in-down',
                'class' => 'btn-dark btn-sm',
                'onclick' => "document.getElementById('wi-doc-receive').requestSubmit()",
            ];
        }

        if (static::cancellable((string) ($item['status'] ?? ''))) {
            $actions[] = [
                'label' => 'Annulla',
                'icon' => 'bi bi-x-circle',
                'class' => 'btn-outline-danger btn-sm',
                'onclick' => "document.getElementById('wi-doc-cancel').requestSubmit()",
            ];
        }

        return $actions;
    }

    /**
     * I form nascosti di *Duplica* e *Annulla*, fuori da ogni altro form, e
     * lo script di `?duplica=1` (il menu ⋯ dell'elenco). *Annulla* chiede
     * conferma con `data-wi-confirm` del core; `?duplica=1` chiede con
     * `wi.confirm()` prima di inviare, così un link mandato da altri non crea
     * bozze da solo. Un secondo invio di *Duplica* si ignora.
     *
     * @param array<string, mixed> $document
     */
    public static function hiddenForms(array $document): string
    {
        $id = (int) ($document['id'] ?? 0);

        if ($id <= 0) {
            return '';
        }

        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $json = static fn (string $value): string => (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $action = $e(StockDocumentActionResource::submitUrl());
        $fields = static fn (string $what): string => Csrf::fieldFor('post')
            .'<input type="hidden" name="action" value="'.$what.'">'
            .'<input type="hidden" name="document_id" value="'.$id.'">';
        $html = '<form id="wi-doc-duplicate" method="POST" action="'.$action.'" hidden>'
            .$fields(StockDocumentActionResource::DUPLICATE).'</form>';

        $status = (string) ($document['status'] ?? '');
        $number = html_entity_decode((string) ($document['number'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // G3.16: *Ricevi* il trasferimento in viaggio, con la sua domanda.
        if (static::type() === 'transfer' && $status === 'in_transit') {
            $html .= '<form id="wi-doc-receive" method="POST" action="'.$action.'" hidden'
                .' data-wi-confirm="'.$e('Il trasferimento '.$number.' arriva: tutta la merce entra nella sede d\'arrivo.').'"'
                .' data-wi-confirm-title="Ricevi trasferimento"'
                .' data-wi-confirm-ok="Ricevi"'
                .' data-wi-confirm-cancel="Torna indietro">'
                .$fields(StockDocumentActionResource::RECEIVE).'</form>';
        }

        if (static::cancellable($status)) {
            $label = 'Annulla '.mb_strtolower(static::named());

            $html .= '<form id="wi-doc-cancel" method="POST" action="'.$action.'" hidden'
                .' data-wi-confirm="'.$e(str_replace('{numero}', $number, static::cancelQuestion($status))).'"'
                .' data-wi-confirm-title="'.$e('Annulla '.mb_strtolower(static::singular())).'"'
                .' data-wi-confirm-ok="'.$e($label).'"'
                .' data-wi-confirm-cancel="Torna indietro"'
                .' data-wi-confirm-variant="danger">'
                .$fields(StockDocumentActionResource::CANCEL).'</form>';
        }

        return $html.'<script>
(function () {
    \'use strict\';

    var form = document.getElementById(\'wi-doc-duplicate\');
    var text = '.$json('Nasce una bozza nuova con la stessa sede, causale, nota e righe.').';
    var params = new URLSearchParams(window.location.search);
    var rest;

    form.addEventListener(\'submit\', function (event) {
        if (form.getAttribute(\'data-sent\') === \'1\') {
            event.preventDefault();

            return;
        }

        form.setAttribute(\'data-sent\', \'1\');
    });

    if (params.get(\'duplica\') !== \'1\') {
        return;
    }

    // Il parametro esce subito: ricaricando la pagina non si chiede di nuovo.
    params.delete(\'duplica\');
    rest = params.toString();
    window.history.replaceState(null, \'\', window.location.pathname + (rest !== \'\' ? \'?\' + rest : \'\') + window.location.hash);

    window.addEventListener(\'load\', function () {
        var ask = window.wi && typeof window.wi.confirm === \'function\'
            ? window.wi.confirm({title: \'Duplica\', text: text, ok: \'Duplica\', cancel: \'Torna indietro\'})
            : Promise.resolve(window.confirm(text));

        ask.then(function (ok) {
            if (ok) {
                form.requestSubmit();
            }
        });
    });
})();
</script>';
    }

    /**
     * Il form della bozza: un documento chiuso aperto in modifica (link
     * vecchio, altra scheda) va alla sua scheda; le righe arrivano descritte
     * per l'editor in `editor`. «Nuovo» dopo un invio che non ha fatto
     * nascere il documento riprende righe, sede, causale e nota di quell'invio
     * (`StockDocumentForm::recall()`); una sede che non c'è più si lascia.
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

        $items = $mode === 'edit' ? StockDocuments::items((int) ($values['id'] ?? 0)) : [];
        $restored = $mode === 'edit' ? null : StockDocumentForm::recall(static::type());

        if ($restored !== null) {
            $header = $restored['header'];

            if (!array_key_exists((string) ($header['location_id'] ?? ''), static::locationOptions())) {
                unset($header['location_id']);
            }

            $values = $header + $values;
            $items = $restored['items'];
        }

        $locationId = (int) ($values['location_id'] ?? 0);
        $values['editor'] = StockDocumentForm::editorLines(
            $items,
            $locationId > 0 ? $locationId : Locations::mainId()
        );

        return $values;
    }

    protected static function currentUserId(): int
    {
        $user = LegacyGlobals::get('USER');

        return is_object($user) ? (int) ($user->id ?? 0) : 0;
    }

    /** Il posto nel menu *Magazzino*: Giacenze 10, Carichi 20, Scarichi 30, Inventari 40, Trasferimenti 42, Lotti 45, Movimenti 50. */
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
        $closed = ['row' => ['status' => ['in_transit', 'completed', 'cancelled']]];

        return [
            'modify' => ['filter' => $draft],
            'view' => ['filter' => $closed],
            'duplica_bozza' => ['label' => 'Duplica', 'href' => static::rowUrl('edit', '/edit/').'?duplica=1', 'filter' => $draft],
            'duplica' => ['label' => 'Duplica', 'href' => static::rowUrl('view', '/').'?duplica=1', 'filter' => $closed],
            'delete' => ['filter' => $draft],
        ];
    }

    /**
     * Il numero, che apre il documento: la bozza in modifica, gli altri nella
     * scheda. Le bozze di prima della numerazione al salvataggio non l'hanno.
     */
    public static function numberCell(array $row): string
    {
        $number = trim((string) ($row['number'] ?? ''));
        $draft = (string) ($row['status'] ?? '') === 'draft';
        $text = $number !== '' ? static::escape($number) : ($draft ? 'Bozza' : '—');
        $url = (int) ($row['id'] ?? 0) > 0 ? static::pageUrl(['type' => static::type()] + $row) : '';

        if ($url === '') {
            return $text;
        }

        return '<a href="'.static::escape($url).'"'.($draft && $number === '' ? ' class="text-body-secondary"' : '').'>'.$text.'</a>';
    }

    /** Lo stato del documento, un badge nella sua colonna. */
    public static function statusCell(array $row): string
    {
        $status = (string) ($row['status'] ?? '');
        [$label, $color] = match ($status) {
            'draft' => ['Bozza', 'secondary'],
            'in_transit' => ['In viaggio', 'warning'],
            'cancelled' => ['Annullato', 'danger'],
            'completed' => [static::type() === 'transfer' ? 'Ricevuto' : 'Confermato', 'success'],
            default => [$status, 'light'],
        };

        return $label === '' ? '' : '<span class="badge text-bg-'.$color.'">'.static::escape($label).'</span>';
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
