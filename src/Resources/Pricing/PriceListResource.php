<?php

namespace Wonder\Plugin\Gestionale\Resources\Pricing;

use Throwable;
use Wonder\App\Permission\Access;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\Support\Repeater;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Http\Route;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceList;
use Wonder\Plugin\Gestionale\Models\Pricing\PriceListItem;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Pricing\PriceListTransfer;
use Wonder\Plugin\Gestionale\Support\Stock\ProductNames;

/**
 * «Listini»: i prezzi riservati a un cliente (G1, `customer_price_lists`).
 * Le righe stanno in un repeater: prodotto, quantità minima (lo scaglione) e
 * prezzo. Si scaricano e si ricaricano in csv o xlsx.
 *
 * Un listino assegnato a clienti o ordini non si elimina: si spegne.
 */
final class PriceListResource extends GestionaleResource
{
    public static string $model = PriceList::class;
    public static string $feature = 'customer_price_lists';

    /** Le righe lette dal file caricato, scritte dopo il salvataggio. */
    private static ?array $imported = null;

    public static function path(): string
    {
        return 'app/gestionale/listini';
    }

    public static function icon(): string
    {
        return 'bi-tags';
    }

    public static function titleLabel(): string
    {
        return 'Listini';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'listino',
            'plural_label' => 'listini',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'full' => 'attivo',
            'empty' => 'non attivo',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'name' => 'Nome',
            'currency' => 'Valuta',
            'prices_include_tax' => 'Prezzi',
            'valid_from' => 'Dal',
            'valid_to' => 'Al',
            'active' => 'Stato',
            'items' => 'Righe',
            'customers' => 'Clienti',
            'note' => 'Nota',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('currency')->text()->value('EUR')->label('Valuta')->required(),
            FormField::key('prices_include_tax')
                ->select(['true' => 'IVA inclusa', 'false' => 'IVA esclusa'])
                ->value('true')
                ->label('Prezzi'),
            FormField::key('valid_from')->dateInput()->label('Dal'),
            FormField::key('valid_to')->dateInput()->label('Al'),
            FormField::key('active')
                ->select(['true' => 'Attivo', 'false' => 'Non attivo'])
                ->value('true')
                ->label('Stato'),
            FormField::key('note')->textarea()->label('Nota'),
            FormField::key('items')
                ->repeater([
                    RepeaterColumn::key('id')->hidden(),
                    RepeaterColumn::key('product_id')->select(['' => '—'] + static::productOptions())->label('Prodotto')->columnFill(),
                    RepeaterColumn::key('min_quantity')->number()->decimal(0)->value('1')->label('Da quantità')->columnSpan(2),
                    RepeaterColumn::key('price')->number()->decimal(2)->label('Prezzo')->columnSpan(3),
                ])
                ->relation(
                    RepeaterRelation::make(PriceListItem::$table, 'price_list_id')
                        ->model(PriceListItem::class)
                        ->positionKey('position')
                        ->softDelete(false)
                )
                ->nested()
                ->repeaterSortable()
                ->repeaterAddLabel('Aggiungi riga')
                ->repeaterDeleteTitle('Elimina riga')
                ->repeaterDeleteText('Confermi l\'eliminazione di questa riga?')
                ->repeaterDeleteCancelLabel('Annulla')
                ->repeaterDeleteConfirmLabel('Elimina')
                ->repeaterDeleteConfirmClass('btn btn-danger')
                ->label(''),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Listino')
                        ->tooltip('Si assegna dalla scheda del cliente. Vale se è attivo, fra le date (vuote = sempre) e se la valuta è quella dell\'ordine; altrimenti il cliente paga i prezzi di catalogo. «IVA inclusa» o «esclusa» dice come sono scritti i prezzi qui sotto.')
                        ->columnSpan(12),
                    static::getInput('name')->columnSpan(6),
                    static::getInput('currency')->columnSpan(2),
                    static::getInput('prices_include_tax')->columnSpan(2),
                    static::getInput('active')->columnSpan(2),
                    static::getInput('valid_from')->columnSpan(3),
                    static::getInput('valid_to')->columnSpan(3),
                    static::getInput('note')->columnSpan(6),
                ])->columns(12)->columnSpan(12),
                (new Card)->components([
                    SectionTitle::make('Prezzi')
                        ->tooltip('Una riga per prodotto e scaglione: «Da quantità» è la quantità minima da cui vale il prezzo. Con più righe dello stesso prodotto vale quella con la quantità più alta raggiunta. Un prodotto che non c\'è si paga a prezzo di catalogo. Il listino sostituisce le campagne; coupon e sconto dell\'ordine si aggiungono.')
                        ->columnSpan(12),
                    static::getInput('items')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
                (new Card)->components([
                    SectionTitle::make('Carica da file')
                        ->tooltip('Un file .csv o .xlsx con le colonne SKU, Prodotto, Quantità minima e Prezzo, come quello che scarichi da qui. Il prodotto lo dice lo SKU. Al salvataggio le righe del file sostituiscono tutte le righe del listino.')
                        ->columnSpan(12),
                    '<div class="col-12"><input type="file" name="price_list_import" accept=".csv,.xlsx" class="form-control"></div>',
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('items')
                ->text()
                ->formatter(static fn (array $row): string => (string) (int) sqlCount(PriceListItem::$table, 'price_list_id = '.(int) ($row['id'] ?? 0)." AND deleted = 'false'")),
            TableColumn::key('customers')
                ->text()
                ->formatter(static fn (array $row): string => (string) (int) sqlCount(Contact::$table, 'price_list_id = '.(int) ($row['id'] ?? 0)." AND deleted = 'false'")),
            TableColumn::key('valid_to')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::validity($row))),
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Attivo', 'bi-check-circle', 'success')
                ->badgeOff('Non attivo', 'bi-dash-circle', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions([
                'edit',
                'active' => ['label' => ['true' => 'Disattiva', 'false' => 'Attiva'], 'request' => 'boolean'],
                'delete',
            ]),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Listini',
                'create' => 'Nuovo listino',
                'edit' => 'Modifica listino',
            ])
            ->actions('edit', static fn (array $item): array => (int) ($item['id'] ?? 0) > 0 ? [
                ['label' => 'Scarica (Excel)', 'icon' => 'bi-file-earmark-spreadsheet', 'class' => 'btn-outline-dark btn-sm', 'href' => static::itemsUrl((int) $item['id'], 'xlsx')],
                ['label' => 'Scarica (CSV)', 'icon' => 'bi-filetype-csv', 'class' => 'btn-outline-dark btn-sm', 'href' => static::itemsUrl((int) $item['id'], 'csv')],
            ] : []);
    }

    /** Le righe del listino come file. */
    public static function registerBackendRoutes(string $rootApp, string $slug): void
    {
        Route::get('/{id}/righe/{format}/', Gestionale::handlerPath('backend/pricing/price-list-items.php'), [
            'resource' => $slug,
            'resource_action' => 'items',
        ])->name('items')
            ->permit(Access::roles(Gestionale::area('promozioni'), 'edit'))
            ->where('id', '[0-9]+')
            ->where('format', 'xlsx|csv');
    }

    public static function itemsUrl(int $id, string $format): string
    {
        return static::routeUrl('items', '/backend/'.static::path().'/'.$id.'/righe/'.$format.'/', ['id' => $id, 'format' => $format]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('promozioni'));
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()
            ->section('promozioni', 'Promozioni', 'bi-percent', 360)
            ->title('Listini')
            ->order(30)
            ->access(Gestionale::area('promozioni'));
    }

    /**
     * I listini da scegliere su cliente e ordine; quelli spenti solo se già
     * scelti o con `$all` (la scheda cliente non sa quale ha).
     *
     * @return array<int, string>
     */
    public static function options(int $selected = 0, bool $all = false): array
    {
        if (!static::featureActive()) {
            return [];
        }

        $options = [];

        foreach (static::rowsOf(PriceList::class, [], 'name') as $row) {
            $id = (int) $row['id'];
            $active = ($row['active'] ?? 'false') === 'true';

            if ($active || $all || $id === $selected) {
                $options[$id] = html_entity_decode((string) ($row['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8').($active ? '' : ' (non attivo)');
            }
        }

        return $options;
    }

    /**
     * I prodotti da mettere in listino: «Nome — SKU».
     *
     * @return array<int, string>
     */
    public static function productOptions(): array
    {
        $products = static::rowsOf(Product::class, [], 'id');
        $names = ProductNames::models($products);
        $options = [];

        foreach ($products as $product) {
            $sku = trim((string) ($product['sku'] ?? ''));
            $label = ProductNames::full($product, $names).($sku !== '' ? ' — '.$sku : '');
            $options[(int) $product['id']] = ($product['active'] ?? 'false') === 'true' ? $label : $label.' (disattivato)';
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /** «Dal 01/01/2027 al 31/12/2027», «Sempre». @param array<string, mixed> $row */
    public static function validity(array $row): string
    {
        $from = static::date((string) ($row['valid_from'] ?? ''));
        $to = static::date((string) ($row['valid_to'] ?? ''));

        return match (true) {
            $from !== '' && $to !== '' => 'Dal '.$from.' al '.$to,
            $from !== '' => 'Dal '.$from,
            $to !== '' => 'Fino al '.$to,
            default => 'Sempre',
        };
    }

    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        $currency = strtoupper(trim((string) ($values['currency'] ?? 'EUR')));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw UserError::refusal('price_list.invalid_currency');
        }

        $values['currency'] = $currency;
        $from = static::day($values['valid_from'] ?? '');
        $to = static::day($values['valid_to'] ?? '');
        $values['valid_from'] = $from;
        $values['valid_to'] = $to;

        if ($from !== '' && $to !== '' && $to < $from) {
            throw UserError::refusal('price_list.invalid_dates');
        }

        static::checkItems(Repeater::rowsFromRequest('items', (array) $_POST));
        static::$imported = static::uploadedRows();

        return $values;
    }

    /** Le date senza ora; quelle «zero» di MySQL vuote. */
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        foreach (['valid_from', 'valid_to'] as $column) {
            if (array_key_exists($column, $values)) {
                $values[$column] = static::day($values[$column]);
            }
        }

        return $values;
    }

    /**
     * Le righe senza prodotto né prezzo non sono righe; le altre col punto
     * decimale e la quantità minima almeno 1.
     */
    public static function prepareRepeaterRows(
        string $inputName,
        array $rows,
        string $action = 'store',
        string $context = 'backend'
    ): array {
        if ($inputName !== 'items') {
            return $rows;
        }

        $items = [];

        foreach ($rows as $row) {
            if (!is_array($row) || static::emptyRow($row)) {
                continue;
            }

            $row['product_id'] = (int) ($row['product_id'] ?? 0);
            $row['min_quantity'] = Numbers::fromForm($row['min_quantity'] ?? null) ?? '1';
            $row['price'] = Numbers::fromForm($row['price'] ?? null) ?? '0';
            $items[] = $row;
        }

        return $items;
    }

    public static function afterStore(object $result, array $values = []): void
    {
        static::writeImported((int) ($result->insert_id ?? 0));
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        static::writeImported((int) $id);
    }

    /** Quanti clienti e ordini lo usano. */
    public static function usage(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }

        return (int) sqlCount(Order::$table, 'price_list_id = '.$id." AND deleted = 'false'")
            + (int) sqlCount(Contact::$table, 'price_list_id = '.$id." AND deleted = 'false'");
    }

    /** Un listino in uso non si elimina: si spegne. */
    public static function assertDeletable(int|string $id): void
    {
        $count = static::usage((int) $id);

        if ($count > 0) {
            throw UserError::refusal('price_list.in_use', ['count' => $count]);
        }
    }

    /**
     * Ogni riga ha prodotto e prezzo; lo stesso prodotto non torna con la
     * stessa quantità minima.
     *
     * @param list<array<string, mixed>> $rows
     */
    private static function checkItems(array $rows): void
    {
        $seen = [];

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row) || static::emptyRow($row)) {
                continue;
            }

            $line = $index + 1;
            $productId = (int) ($row['product_id'] ?? 0);
            $min = Numbers::fromForm($row['min_quantity'] ?? null) ?? '1';
            $price = Numbers::fromForm($row['price'] ?? null);

            if ($productId <= 0) {
                throw UserError::refusal('price_list.item_no_product', ['row' => $line]);
            }

            if ((float) $min <= 0 || $price === null || (float) $price < 0) {
                throw UserError::refusal('price_list.item_invalid', ['row' => $line]);
            }

            $key = $productId.'|'.round((float) $min, 3);

            if (isset($seen[$key])) {
                throw UserError::refusal('price_list.item_duplicate', ['row' => $line]);
            }

            $seen[$key] = true;
        }
    }

    /** @param array<string, mixed> $row */
    private static function emptyRow(array $row): bool
    {
        return (int) ($row['product_id'] ?? 0) <= 0 && trim((string) ($row['price'] ?? '')) === '';
    }

    /** Le righe del file caricato, `null` se non c'è un file. */
    private static function uploadedRows(): ?array
    {
        $file = $_FILES['price_list_import'] ?? null;

        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw UserError::refusal('price_list.import_unreadable');
        }

        return PriceListTransfer::fromTable(
            PriceListTransfer::readFile((string) $file['tmp_name'], (string) ($file['name'] ?? '')),
            PriceListTransfer::skuIds()
        );
    }

    private static function writeImported(int $priceListId): void
    {
        if ($priceListId > 0 && static::$imported !== null) {
            PriceListTransfer::replace($priceListId, static::$imported);
        }

        static::$imported = null;
    }

    /** Una data come `Y-m-d` (dal form anche `d/m/Y`); vuota se non lo è. */
    private static function day(mixed $value): string
    {
        $value = trim((string) $value);

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) === 1) {
            return $m[1] === '0000-00-00' ? '' : $m[1];
        }

        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $m) === 1) {
            return $m[3].'-'.$m[2].'-'.$m[1];
        }

        return '';
    }

    private static function date(string $value): string
    {
        $day = static::day($value);

        return $day === '' ? '' : date('d/m/Y', (int) strtotime($day));
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
