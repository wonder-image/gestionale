<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Throwable;
use Wonder\App\LegacyGlobals;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Http\Csrf;
use Wonder\Http\Route;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\DeliveryNote;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Sales\DeliveryNotes;

/**
 * *Vendite → DDT* (G9 §4.2): l'elenco dei documenti di trasporto e la loro
 * pagina, bozza da completare o emesso da consultare. Un DDT nasce dalla
 * scheda dell'ordine (*Crea DDT*), mai da qui.
 *
 * Il POST passa da `mutateRequestValues()` e non torna al core: lo scrive
 * `DeliveryNotes`.
 */
final class DeliveryNoteResource extends GestionaleResource
{
    public static string $feature = 'delivery_notes';
    public static string $model = DeliveryNote::class;
    public static string $docsPage = 'vendite/vendite-ddt';

    /** Gli stati, come si leggono. */
    public const STATUS_LABELS = [
        'draft' => ['Bozza', 'secondary'],
        'issued' => ['Emesso', 'success'],
        'cancelled' => ['Annullato', 'danger'],
    ];

    /** @var array<int, array<string, mixed>> */
    private static array $orders = [];

    public static function path(): string
    {
        return 'app/gestionale/ddt';
    }

    public static function icon(): string
    {
        return 'bi-file-earmark-text';
    }

    public static function titleLabel(): string
    {
        return 'DDT';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'DDT',
            'plural_label' => 'DDT',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'number' => 'Numero',
            'date' => 'Data',
            'customer' => 'Cliente',
            'order_id' => 'Ordine',
            'status' => 'Stato',
        ];
    }

    public static function formSchema(): array
    {
        return [];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('number')
                ->text()
                ->size('little')
                ->link('edit')
                ->formatter(static fn (array $row): string => trim((string) ($row['number'] ?? '')) !== ''
                    ? static::escape((string) $row['number'])
                    : '<span class="text-body-secondary">Bozza</span>'),
            TableColumn::key('date')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(substr(OrderSheet::date((string) ($row['date'] ?? '')), 0, 10))),
            TableColumn::key('customer')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::customerOf($row))),
            TableColumn::key('order_id')
                ->text()
                ->size('little')
                ->hiddenDevice('mobile')
                ->formatter(static fn (array $row): string => static::orderLink((int) ($row['order_id'] ?? 0))),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::badge((string) ($row['status'] ?? ''))),
            TableColumn::key('actions')->button()->actions(['edit']),
        ];
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        $stati = ['' => 'Tutti'];

        foreach (self::STATUS_LABELS as $key => [$label]) {
            $stati[$key] = $label;
        }

        return TableLayoutSchema::for(static::class)
            ->title('DDT')
            ->results()
            ->hideButtonAdd()
            ->filterSearch()
            ->searchFields(['number', 'billing_name', 'billing_surname', 'billing_business_name'])
            ->filterCustom('Stato', 'status', $stati);
    }

    /** @return array<string, mixed> */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        $schema['condition'] = "deleted = 'false'";
        $schema['order'] = ['column' => 'creation', 'direction' => 'DESC'];

        return $schema;
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only(['list', 'edit', 'update'])
            ->titles(['list' => 'DDT', 'edit' => 'DDT'])
            ->subtitles(['list' => 'I documenti di trasporto: nascono dalla scheda di un ordine confermato con «Crea DDT». Non muovono il magazzino.'])
            ->view('form', Gestionale::viewPath('pages/delivery-note-form.php'));
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('vendite')
            ->title('DDT')
            ->order(25)
            ->authority(['admin', 'administrator'])
            ->enabled(static::featureActive());
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backend(['list', 'edit', 'update'], ['admin', 'administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /** Le righe e l'ordine per la pagina. */
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        $id = (int) ($values['id'] ?? 0);
        $values['lines'] = $id > 0 ? DeliveryNotes::items($id) : [];
        $values['order'] = static::orderOf((int) ($values['order_id'] ?? 0));

        return $values;
    }

    /**
     * *Salva*, *Emetti*, *Elimina bozza*, *Annulla DDT*, *Invia per email*.
     * Non restituisce mai i valori al core.
     */
    public static function mutateRequestValues(array $values, string $action, string $context = 'backend', ?array $oldValues = null): array
    {
        $id = (int) ($oldValues['id'] ?? 0);
        $result = Csrf::verify()
            ? static::handleSubmit($values, $id, static::currentUserId())
            : ['ok' => false, 'message' => UserError::make('stock_document.expired')->getMessage(), 'url' => static::editUrl($id)];

        if ($result['ok']) {
            FlashAlert::saved($result['message']);
        } else {
            FlashAlert::custom('Attenzione', $result['message'], 'warning');
        }

        if (!headers_sent()) {
            header('Location: '.$result['url']);
        }

        exit();
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string, url: string}
     */
    public static function handleSubmit(array $post, int $id, int $userId): array
    {
        $intent = (string) ($post['intent'] ?? 'save');
        $edit = static::editUrl($id);
        $options = ['user_id' => $userId, 'source' => 'user'];

        try {
            if ($intent === 'delete') {
                DeliveryNotes::delete($id);

                return ['ok' => true, 'message' => 'Bozza del DDT eliminata.', 'url' => static::listUrl()];
            }

            if ($intent === 'cancel') {
                DeliveryNotes::cancel($id, $options);

                return ['ok' => true, 'message' => 'DDT annullato: le sue righe tornano da consegnare.', 'url' => $edit];
            }

            if ($intent === 'send') {
                return ['ok' => true, 'url' => $edit, 'message' => match (DeliveryNotes::send($id)) {
                    'sent' => 'DDT inviato al cliente con il PDF.',
                    'no_recipients' => 'Il cliente non ha un\'email: scarica il PDF e mandalo tu.',
                    default => 'L\'email non è partita: scarica il PDF e mandalo tu.',
                }];
            }

            DeliveryNotes::saveDraft($id, static::header($post), static::quantities($post));

            if ($intent === 'issue') {
                $issued = DeliveryNotes::issue($id, $options);

                return ['ok' => true, 'message' => 'DDT '.$issued['number'].' emesso.', 'url' => $edit];
            }

            return ['ok' => true, 'message' => 'Bozza del DDT salvata.', 'url' => $edit];
        } catch (UserError $error) {
            return ['ok' => false, 'message' => $error->getMessage(), 'url' => $id > 0 ? $edit : static::listUrl()];
        }
    }

    /** Il trasporto e la destinazione dalla pagina. @return array<string, string> */
    public static function header(array $post): array
    {
        $out = [];

        foreach ($post as $key => $value) {
            $key = (string) $key;

            if (!is_scalar($value)) {
                continue;
            }

            if (in_array($key, ['transport_by', 'carrier_id', 'transport_started_at', 'goods_appearance', 'packages_count', 'gross_weight', 'net_weight', 'freight_terms', 'document_note'], true)
                || str_starts_with($key, 'shipping_')) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /** Le quantità delle righe: `qty_<riga>`. @return array<int, string> */
    public static function quantities(array $post): array
    {
        $out = [];

        foreach ($post as $key => $value) {
            if (is_string($key) && preg_match('/^qty_(\d+)$/', $key, $m) === 1 && is_scalar($value)) {
                $out[(int) $m[1]] = trim((string) $value);
            }
        }

        return $out;
    }

    public static function badge(string $status): string
    {
        [$label, $color] = self::STATUS_LABELS[$status] ?? [$status, 'secondary'];

        return '<span class="badge text-bg-'.$color.'">'.static::escape($label).'</span>';
    }

    /** Il destinatario, come nel DDT. */
    public static function customerOf(array $row): string
    {
        $business = trim(html_entity_decode((string) ($row['billing_business_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $business !== '' ? $business : trim(html_entity_decode((string) ($row['billing_name'] ?? '').' '.(string) ($row['billing_surname'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function editUrl(int $id): string
    {
        return static::routeUrl('edit', '/backend/'.static::path().'/'.$id.'/edit/', ['id' => $id]);
    }

    public static function listUrl(): string
    {
        return static::routeUrl('list', '/backend/'.static::path().'/');
    }

    public static function pdfUrl(int $id): string
    {
        return static::routeUrl('pdf', '/backend/'.static::path().'/'.$id.'/pdf/', ['id' => $id]);
    }

    /** Il PDF del DDT emesso, dall'archivio. */
    public static function registerBackendRoutes(string $rootApp, string $slug): void
    {
        Route::get('/{id}/pdf/', Gestionale::handlerPath('backend/sales/delivery-note-pdf.php'), [
            'resource' => $slug,
            'resource_action' => 'pdf',
        ])->name('pdf')
            ->permit(['admin', 'administrator'])
            ->where('id', '[0-9]+');
    }

    private static function orderLink(int $orderId): string
    {
        $order = static::orderOf($orderId);

        if ($order === []) {
            return '—';
        }

        return '<a href="'.static::escape(OrderResource::detailUrl($orderId)).'">'.static::escape((string) ($order['order_number'] ?? '#'.$orderId)).'</a>';
    }

    /** @return array<string, mixed> */
    private static function orderOf(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        if (!array_key_exists($orderId, static::$orders)) {
            try {
                $order = Order::findById($orderId);
            } catch (Throwable) {
                $order = null;
            }

            static::$orders[$orderId] = is_array($order) ? $order : [];
        }

        return static::$orders[$orderId];
    }

    private static function currentUserId(): int
    {
        $user = LegacyGlobals::get('USER');

        return is_object($user) ? (int) ($user->id ?? 0) : 0;
    }

    private static function routeUrl(string $action, string $fallback, array $params = []): string
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
