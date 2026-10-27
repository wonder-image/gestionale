<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Throwable;
use Wonder\App\LegacyGlobals;
use Wonder\App\Permission\Access;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Http\Csrf;
use Wonder\Http\Exceptions\NotFoundHttpException;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Backend\RowMenu;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Charges;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderForm;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderPage;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;

/**
 * *Vendite → Ordini da ufficio* (G9 §2.2): le bozze dell'ufficio e *Nuovo
 * ordine*, la bozza composta e salvata da una pagina sola. Un ordine
 * confermato non si apre qui: si va alla sua scheda negli *Ordini*.
 *
 * Come i documenti di magazzino, il POST passa da `mutateRequestValues()` e
 * non torna mai al core: le righe viaggiano in JSON e le scrive
 * `OfficeOrders`.
 */
class OfficeOrderResource extends GestionaleResource
{
    public static string $feature = 'office_sales';
    public static string $model = Order::class;
    public static string $docsPage = 'vendite/vendite-ordini-da-ufficio';

    /** Il rifiuto dell'ultimo invio, mostrato sopra il form alla riapertura. */
    public const ERROR_KEY = 'gestionale_office_error';

    public static function path(): string
    {
        return 'app/gestionale/ordine-da-ufficio';
    }

    public static function icon(): string
    {
        return 'bi-pencil-square';
    }

    public static function titleLabel(): string
    {
        return 'Ordini da ufficio';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'ordine',
            'plural_label' => 'ordini',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'gli',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return ['order_number' => 'Numero', 'customer' => 'Cliente', 'total' => 'Totale', 'creation' => 'Creata', 'last_modified' => 'Modificata'];
    }

    /** `order` qui, `quote` per i preventivi (`QuoteResource`): la pagina e il servizio sono gli stessi. */
    public static function stage(): string
    {
        return 'order';
    }

    public static function formSchema(): array
    {
        return [];
    }

    public static function tableSchema(): array
    {
        return [
            // Il numero c'è dal primo salvataggio; le bozze di prima ne sono senza.
            TableColumn::key('order_number')
                ->text()
                ->size('little')
                ->link('edit')
                ->formatter(static fn (array $row): string => trim((string) ($row['order_number'] ?? '')) !== ''
                    ? static::escape((string) $row['order_number'])
                    : '<span class="text-body-secondary">Bozza</span>'),
            TableColumn::key('customer')
                ->text()
                ->link('edit')
                ->formatter(static fn (array $row): string => static::escape(OrderResource::customerName($row))),
            TableColumn::key('total')->price()->size('little'),
            TableColumn::key('creation')->date()->size('medium')->hiddenDevice('mobile'),
            TableColumn::key('actions')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => RowMenu::render(static::rowActions($row))),
        ];
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        return TableLayoutSchema::for(static::class)
            ->title('Bozze')
            ->results()
            ->buttonAdd(true, 'Nuovo ordine')
            ->filterSearch()
            ->searchFields(['order_number', 'billing_name', 'billing_surname', 'billing_business_name', 'email']);
    }

    /**
     * Solo le bozze dell'ufficio: gli ordini confermati stanno negli *Ordini*.
     *
     * @return array<string, mixed>
     */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        $schema['condition'] = "deleted = 'false' AND stage = 'order' AND status = 'draft' AND channel = 'office'";
        $schema['order'] = ['column' => 'creation', 'direction' => 'DESC'];

        return $schema;
    }

    public static function pageSchema(): PageSchema
    {
        return static::withFeature(parent::pageSchema()
            ->only(['list', 'create', 'store', 'edit', 'update'])
            ->titles(['list' => 'Ordini da ufficio', 'create' => 'Nuovo ordine', 'edit' => 'Bozza d\'ordine'])
            ->subtitles([
                'list' => 'Le bozze degli ordini fatti dall\'ufficio. Confermate, passano negli Ordini con lo stesso numero.',
                'create' => 'Un ordine fatto dall\'ufficio: si salva come bozza, con il suo numero, senza toccare il magazzino finché non lo confermi.',
                'edit' => 'La bozza non tocca il magazzino: alla conferma la merce esce e nascono le rate. Il numero resta lo stesso.',
            ])
            ->view('form', Gestionale::viewPath('pages/office-order-form.php'))
            ->actions('create', static fn (array $item): array => static::headerActions($item))
            ->actions('edit', static fn (array $item): array => static::headerActions($item)));
    }

    /**
     * In testa, accanto a *Guida*: il PDF da stampare e le azioni della
     * pagina (`OfficeOrderPage::headerActions()`).
     *
     * @return list<array<string, string>>
     */
    public static function headerActions(array $values): array
    {
        $id = (int) ($values['id'] ?? 0);
        $actions = [];

        if ($id > 0) {
            $actions[] = static::stage() === 'quote'
                ? ['label' => 'Stampa', 'icon' => 'bi-printer', 'class' => 'btn-outline-dark btn-sm', 'href' => QuoteResource::printUrl($id), 'target' => '_blank']
                : ['label' => 'Proforma', 'icon' => 'bi-printer', 'class' => 'btn-outline-dark btn-sm', 'href' => OrderResource::proformaUrl($id), 'target' => '_blank'];
        }

        return [...$actions, ...OfficeOrderPage::headerActions(static::stage() === 'quote', $values)];
    }

    /**
     * Il menu ⋯ di una bozza nell'elenco. Le azioni partono senza aprirla:
     * la conferma non scrive al cliente (per farlo si conferma dalla pagina).
     *
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    public static function rowActions(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);

        return [
            ['label' => 'Modifica', 'icon' => 'bi bi-pencil', 'href' => static::editUrl($id)],
            ['label' => 'Conferma ordine', 'icon' => 'bi bi-check2-circle', 'href' => static::actionUrl($id, 'confirm'), 'post' => true,
                'confirm' => 'La merce esce dal magazzino e nascono le rate; il numero resta quello della bozza. Il cliente non riceve email: per scrivergli conferma dalla pagina.',
                'confirm_title' => 'Conferma ordine', 'confirm_ok' => 'Conferma ordine', 'confirm_variant' => 'primary'],
            ['label' => 'Elimina', 'icon' => 'bi bi-trash', 'danger' => true, 'href' => static::actionUrl($id, 'delete'), 'post' => true,
                'confirm' => 'La bozza si elimina con le sue righe. Non ha movimenti di magazzino; il suo numero non si riusa.',
                'confirm_title' => 'Elimina bozza', 'confirm_ok' => 'Elimina', 'confirm_variant' => 'danger'],
        ];
    }

    /** Le azioni che il menu ⋯ dell'elenco manda in POST. @return list<string> */
    public static function listIntents(): array
    {
        return ['confirm', 'delete'];
    }

    /** L'indirizzo POST di un'azione del menu ⋯. */
    public static function actionUrl(int $id, string $intent): string
    {
        return static::routeUrl('action', '/backend/'.static::path().'/'.$id.'/azione/'.$intent.'/', ['id' => $id, 'intent' => $intent]);
    }

    /**
     * Un'azione del menu ⋯: sulla bozza com'è, senza salvarla, poi il
     * messaggio e la pagina giusta come dal form. Un errore riapre la bozza
     * con il motivo in testa.
     */
    public static function runAction(int $id, string $intent): never
    {
        $order = $id > 0 ? Order::findById($id) : null;

        if (!static::featureActive() || !in_array($intent, static::listIntents(), true) || !is_array($order) || !isset($order['id'])
            || (string) ($order['deleted'] ?? 'false') !== 'false' || (string) ($order['stage'] ?? '') !== static::stage()) {
            throw new NotFoundHttpException();
        }

        $result = Csrf::verify()
            ? static::handleSubmit(['intent' => $intent, 'notify' => $intent === 'send' ? 'true' : 'false'], $id, static::currentUserId(), false)
            : ['ok' => false, 'message' => UserError::make('stock_document.expired')->getMessage(), 'url' => static::listUrl()];

        static::finish($result);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('vendite')
            ->title('Ordini da ufficio')
            ->order(12)
            ->access(Gestionale::area('vendite'))
            ->enabled(static::featureActive());
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('vendite'), ['list', 'create', 'store', 'edit', 'update']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /**
     * La bozza in pagina: le righe per l'editor, oppure quelle rimandate da un
     * invio che non ha fatto nascere la bozza. Un ordine che non è più una
     * bozza si apre nella sua scheda.
     */
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        $closed = $mode === 'edit' ? static::closedRedirect($values) : null;

        if ($closed !== null) {
            if (!headers_sent()) {
                header('Location: '.$closed);
            }

            exit();
        }

        $rows = $mode === 'edit' ? OfficeOrderForm::items((int) ($values['id'] ?? 0)) : [];
        $restored = $mode === 'edit' ? null : OfficeOrderForm::recall(static::stage());

        if ($restored !== null) {
            $values = $restored['header'] + $values;
            $rows = $restored['lines'];
        }

        if ($mode === 'edit') {
            // Divisa sulle aliquote, la spedizione a mano è una riga per quota:
            // la casella mostra la spesa intera.
            $manual = array_values(array_filter($rows, static fn (array $row): bool => (string) ($row['type'] ?? '') === 'shipping'
                && (string) ($row['price_source'] ?? '') === 'manual'));

            if ($manual !== []) {
                $values['shipping_amount'] ??= number_format(Charges::amount($manual), 2, ',', '');
            }
        }

        $locationId = (int) ($values['location_id'] ?? 0);
        $values['editor'] = OfficeOrderForm::editorLines($rows, $locationId > 0 ? $locationId : Locations::mainId());

        return $values;
    }

    public static function formNotices(array $values, string $mode): array
    {
        $notices = parent::formNotices($values, $mode);
        $error = (string) ($_SESSION[self::ERROR_KEY] ?? '');
        unset($_SESSION[self::ERROR_KEY]);

        if ($error !== '') {
            $notices[] = ['level' => 'danger', 'message' => $error];
        }

        $dropped = (int) ($values['editor']['dropped'] ?? 0);

        if ($dropped > 0) {
            $notices[] = ['level' => 'warning', 'message' => $dropped === 1
                ? 'Una riga non c\'è più: la sua versione è stata eliminata. Salvando la bozza esce anche dall\'ordine.'
                : $dropped.' righe non ci sono più: le loro versioni sono state eliminate. Salvando la bozza escono anche dall\'ordine.'];
        }

        return $notices;
    }

    /**
     * *Salva bozza*, *Conferma* ed *Elimina bozza*. Non restituisce mai i
     * valori al core: si chiude con il messaggio e il redirect.
     */
    public static function mutateRequestValues(array $values, string $action, string $context = 'backend', ?array $oldValues = null): array
    {
        $id = (int) ($oldValues['id'] ?? 0);
        $result = Csrf::verify()
            ? static::handleSubmit($values, $id, static::currentUserId())
            : ['ok' => false, 'message' => UserError::make('stock_document.expired')->getMessage(), 'url' => $id > 0 ? static::editUrl($id) : static::createUrl()];

        if (!$result['ok'] && $result['url'] === static::createUrl()) {
            OfficeOrderForm::remember($values, static::stage());
        }

        static::finish($result);
    }

    /**
     * Il messaggio e il redirect di un invio.
     *
     * @param array{ok: bool, message: string, url: string} $result
     */
    protected static function finish(array $result): never
    {
        if ($result['ok']) {
            FlashAlert::saved($result['message']);
        } elseif (session_status() === PHP_SESSION_ACTIVE) {
            // Il rifiuto resta sopra il form finché la pagina non si riapre:
            // un toast che sparisce non basta a capire perché non si conferma.
            $_SESSION[self::ERROR_KEY] = $result['message'];
        } else {
            FlashAlert::custom('Attenzione', $result['message'], 'warning');
        }

        if (!headers_sent()) {
            header('Location: '.$result['url']);
        }

        exit();
    }

    /**
     * L'invio senza HTTP, con la frase e la pagina dove tornare.
     *
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string, url: string}
     */
    public static function handleSubmit(array $post, int $id, int $userId, bool $save = true): array
    {
        $result = OfficeOrderForm::submit($id, $post, $userId, static::stage(), $save);

        if ($result['deleted']) {
            return ['ok' => true, 'message' => 'Bozza eliminata.', 'url' => static::listUrl()];
        }

        if ($result['error'] !== '') {
            return ['ok' => false, 'message' => $result['error'], 'url' => $result['id'] > 0 ? static::editUrl($result['id']) : static::createUrl()];
        }

        if ($result['number'] !== '') {
            return ['ok' => true, 'message' => 'Ordine '.$result['number'].' confermato.', 'url' => OrderResource::detailUrl($result['id'])];
        }

        return ['ok' => true, 'message' => 'Bozza salvata.', 'url' => static::editUrl($result['id'])];
    }

    /**
     * Dove va una pagina che non è più da modificare qui: l'ordine confermato
     * alla sua scheda; null se resta.
     *
     * @param array<string, mixed> $values
     */
    public static function closedRedirect(array $values): ?string
    {
        if ((string) ($values['status'] ?? '') === 'draft' && (string) ($values['stage'] ?? '') === 'order') {
            return null;
        }

        return (string) ($values['stage'] ?? '') === 'quote'
            ? QuoteResource::editUrl((int) ($values['id'] ?? 0))
            : OrderResource::detailUrl((int) ($values['id'] ?? 0));
    }

    /** Il PDF della conferma di un ordine da ufficio confermato. */
    public static function pdfUrl(int $id): string
    {
        return static::routeUrl('pdf', '/backend/'.static::path().'/'.$id.'/pdf/', ['id' => $id]);
    }

    /** *Scarica PDF* della conferma d'ordine (G9 §3.3). */
    public static function registerBackendRoutes(string $rootApp, string $slug): void
    {
        \Wonder\Http\Route::get('/{id}/pdf/', Gestionale::handlerPath('backend/sales/order-pdf.php'), [
            'resource' => $slug,
            'resource_action' => 'pdf',
        ])->name('pdf')
            ->permit(Access::roles(Gestionale::area('vendite'), 'view'))
            ->where('id', '[0-9]+');

        static::registerActionRoute($slug);
    }

    /** Il POST delle azioni del menu ⋯ (`runAction()`). */
    protected static function registerActionRoute(string $slug): void
    {
        \Wonder\Http\Route::post('/{id}/azione/{intent}/', Gestionale::handlerPath('backend/sales/'.(static::stage() === 'quote' ? 'quote' : 'office-order').'-action.php'), [
            'resource' => $slug,
            'resource_action' => 'action',
        ])->name('action')
            ->permit(Access::roles(Gestionale::area('vendite'), 'edit'))
            ->where('id', '[0-9]+')
            ->where('intent', '[a-z]+');
    }

    /** Il metodo di pagamento si sceglie fra quelli che valgono in ufficio. @return array<int, string> */
    public static function paymentMethods(): array
    {
        return CustomerResource::officeMethods();
    }

    /** @return array<int, string> */
    public static function paymentTerms(): array
    {
        return CustomerResource::activeTerms();
    }

    /** I metodi di spedizione attivi, con *Spedizioni* accesa. @return array<int, string> */
    public static function shippingMethods(): array
    {
        if (!Gestionale::feature('shipping')) {
            return [];
        }

        $out = [];

        foreach (static::rowsOf(ShippingMethod::class, ['active' => 'true'], 'position') as $method) {
            $out[(int) $method['id']] = (string) ($method['name'] ?? '');
        }

        return $out;
    }

    /** Le sedi da cui si evade, solo con più d'una. @return array<int, string> */
    public static function locations(): array
    {
        $alive = Gestionale::feature('multi_location') ? Locations::alive() : [];

        if (count($alive) < 2) {
            return [];
        }

        $out = [];

        foreach ($alive as $location) {
            $out[(int) $location['id']] = (string) $location['label'];
        }

        return $out;
    }

    public static function listUrl(): string
    {
        return static::routeUrl('list', '/backend/'.static::path().'/');
    }

    public static function editUrl(int $id): string
    {
        return static::routeUrl('edit', '/backend/'.static::path().'/'.$id.'/edit/', ['id' => $id]);
    }

    public static function createUrl(): string
    {
        return static::routeUrl('create', '/backend/'.static::path().'/create/');
    }

    protected static function currentUserId(): int
    {
        $user = LegacyGlobals::get('USER');

        return is_object($user) ? (int) ($user->id ?? 0) : 0;
    }

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
