<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

use Throwable;
use Wonder\App\LegacyGlobals;
use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Plugin\Gestionale\Support\Stock\Stocktake;
use Wonder\Sql\Transaction;

/**
 * "Giacenze": quante ne hai di ogni opzione in vendita, e la casella per
 * scriverlo.
 *
 * Non è un elenco CRUD ma una **pagina-form**: è l'unico modo per scrivere
 * cinquanta quantità e salvarle in un colpo solo, che è come si carica un
 * catalogo la prima volta e come si chiude un inventario.
 *
 * Il prezzo di questa scelta è che ricerca, filtri e paginazione non arrivano
 * dall'elenco del core: sono scritti qui, e passano dall'indirizzo. I filtri
 * sono link perché un modulo GET dentro il form del salvataggio non si può
 * annidare.
 *
 * Nessuna riga viene scritta direttamente: le differenze diventano movimenti
 * con `Stock::apply()`, tutte dentro una transazione sola.
 */
final class StockLevelResource extends NavigationOnlyResource
{
    public const PER_PAGE = 50;

    /** @var list<array<string, mixed>>|null */
    private static ?array $rows = null;

    public static function path(): string
    {
        return 'app/gestionale/giacenze';
    }

    public static function icon(): string
    {
        return 'bi-boxes';
    }

    public static function titleLabel(): string
    {
        return 'Giacenze';
    }

    public static function isFormPage(): bool
    {
        return true;
    }

    public static function formSchema(): array
    {
        $fields = [
            FormField::key('reason')
                ->select(Reasons::all())
                ->value(Reasons::DEFAULT)
                ->label('Causale di questa schermata')
                ->required(),
            // I filtri viaggiano con il form: la rotta del salvataggio non ha
            // query string, e senza questo si tornerebbe alla prima pagina.
            FormField::key('back')->hidden()->value(static::currentUrl()),
        ];

        foreach (static::rows() as $row) {
            $fields[] = FormField::key('quantity_'.(int) $row['id'])
                ->number()
                ->decimal(3)
                ->label('Giacenza')
                ->value(static::plain((float) $row['quantity']));
        }

        return $fields;
    }

    public static function formLayoutSchema(): ?Form
    {
        $rows = static::rows();
        $components = [
            SectionTitle::make('Giacenze')
                ->tooltip('Scrivi quante ne hai e salva: ogni riga cambiata diventa un movimento con la causale qui sopra. Le righe che non tocchi restano come sono.')
                ->columnSpan(12),
            RichText::make(static::filtersBar())->columnSpan(12),
            static::getInput('reason')->columnSpan(4),
            static::getInput('back')->columnSpan(12),
        ];

        if ($rows === []) {
            $components[] = RichText::make(
                '<p class="mb-0">Nessuna opzione in vendita con questi filtri.</p>'
            )->columnSpan(12);
        }

        foreach ($rows as $row) {
            $components[] = RichText::make(static::rowLabel($row))->columnSpan(7);
            $components[] = static::getInput('quantity_'.(int) $row['id'])->columnSpan(2);
            $components[] = RichText::make(static::rowSide($row))->columnSpan(3);
        }

        $components[] = RichText::make(static::pagination())->columnSpan(12);

        return (new Form)->components([
            (new Container)->components([
                (new Card)->components($components)->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ])->columns(12);
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only([])
            ->titles(['form' => 'Giacenze'])
            ->subtitles(['form' => 'Quante ne hai di ogni opzione in vendita. Scrivi le quantità che hai contato e salva: nascono i movimenti, con la causale scelta qui sopra.'])
            ->docs(Gestionale::docsUrl('magazzino/magazzino-giacenze'), 'form');
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('magazzino', 'Magazzino', 'bi-boxes', 400, ['admin', 'administrator'])
            ->title('Giacenze')
            ->order(10)
            ->authority(['admin', 'administrator']);
    }

    /**
     * Salva: ogni riga cambiata diventa un movimento.
     *
     * Tutte dentro una transazione sola, così un rifiuto a metà elenco non
     * lascia venti righe sistemate e trenta no.
     */
    public static function submitFormPage(array $values): string
    {
        // Le righe da salvare arrivano da **quello che è stato spedito**, non
        // da `rows()`: il form si posta su una rotta senza query string, e lì
        // i filtri e la pagina non ci sono più. Chiederli di nuovo a `rows()`
        // vorrebbe dire salvare la prima pagina invece di quella che si stava
        // guardando.
        $posted = [];

        foreach ($values as $key => $value) {
            if (!str_starts_with((string) $key, 'quantity_')) {
                continue;
            }

            $id = (int) substr((string) $key, strlen('quantity_'));
            $quantity = Stocktake::quantity($value);

            if ($id > 0 && $quantity !== null) {
                $posted[$id] = $quantity;
            }
        }

        if ($posted === []) {
            return 'Nessuna giacenza cambiata.';
        }

        $current = array_map(
            static fn (array $level): float => $level['quantity'],
            Levels::forProducts(array_keys($posted))
        );

        $changes = Stocktake::changes($current, $posted);

        if ($changes === []) {
            return 'Nessuna giacenza cambiata.';
        }

        $reason = (string) ($values['reason'] ?? Reasons::DEFAULT);
        $user = LegacyGlobals::get('USER');
        $userId = is_object($user) ? (int) ($user->id ?? 0) : 0;
        $back = StockAdjustmentResource::backUrlFrom($values['back'] ?? '');

        try {
            Transaction::run(static function () use ($changes, $reason, $userId): void {
                foreach ($changes as $productId => $change) {
                    Stock::apply([
                        'product_id' => $productId,
                        'quantity' => $change['delta'],
                        'reason' => $reason,
                        'user_id' => $userId,
                    ]);
                }
            });
        } catch (UserError $error) {
            // Il controller delle pagine-form non intercetta niente: un
            // rifiuto che vola via diventa una pagina 500 invece di una frase.
            // La transazione ha già riportato indietro tutte le righe.
            static::refuse($error->getMessage(), $back);

            return $error->getMessage();
        }

        $count = count($changes);
        $message = $count === 1
            ? 'Una giacenza aggiornata.'
            : $count.' giacenze aggiornate.';

        static::goBack($back, $message);

        return $message;
    }

    /** Il rifiuto torna sull'elenco com'era, con la frase in evidenza. */
    private static function refuse(string $message, string $back): void
    {
        if (headers_sent()) {
            return;
        }

        FlashAlert::custom('Attenzione', $message, 'warning');
        header('Location: '.($back !== '' ? $back : static::pageUrl([])));
        exit();
    }

    /**
     * Torna all'elenco com'era: stessi filtri, stessa pagina.
     *
     * Il core, dopo una pagina-form, rimanda alla pagina nuda. Chi stava
     * sistemando la pagina tre si ritroverebbe sulla uno, senza filtri.
     */
    private static function goBack(string $back, string $message): void
    {
        if ($back === '' || headers_sent()) {
            return;
        }

        FlashAlert::saved($message);
        header('Location: '.$back);
        exit();
    }

    /**
     * Le righe della pagina: le opzioni in vendita con la loro giacenza.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }

        $products = static::products();
        $levels = Levels::forProducts(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $products
        ));
        $names = static::modelNames();
        $rows = [];

        foreach ($products as $product) {
            $id = (int) ($product['id'] ?? 0);
            $level = $levels[$id] ?? ['quantity' => 0.0, 'reserved' => 0.0, 'available' => 0.0];

            $rows[] = [
                'id' => $id,
                'article' => $names[(int) ($product['product_model_id'] ?? 0)] ?? '—',
                'option' => trim((string) ($product['name'] ?? '')),
                'sku' => (string) ($product['sku'] ?? ''),
                'threshold' => round((float) ($product['min_stock_quantity'] ?? 0), 3),
                'quantity' => $level['quantity'],
                'reserved' => $level['reserved'],
                'available' => $level['available'],
            ];
        }

        return self::$rows = $rows;
    }

    /** Svuota la cache delle righe: serve ai test, che cambiano i filtri. */
    public static function forget(): void
    {
        self::$rows = null;
    }

    /** Il testo cercato, ripulito di tutto quello che non è testo. */
    public static function searchTerm(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));

        // Solo lettere, numeri, spazi e i segni che stanno negli SKU.
        return trim((string) preg_replace('/[^\p{L}\p{N} _.\-]+/u', '', $text));
    }

    /** La pagina chiesta, mai sotto la prima. */
    public static function pageNumber(mixed $value): int
    {
        $page = (int) trim((string) ($value ?? ''));

        return $page > 0 ? $page : 1;
    }

    /**
     * Le opzioni in vendita di questa pagina, già filtrate.
     *
     * @return list<array<string, mixed>>
     */
    private static function products(): array
    {
        $parts = ["deleted = 'false'"];
        $search = static::searchTerm($_GET['cerca'] ?? '');

        if ($search !== '') {
            $like = str_replace(['%', '_'], ['\%', '\_'], $search);
            $parts[] = "(name LIKE '%".$like."%' OR sku LIKE '%".$like."%' OR ean LIKE '%".$like."%')";
        }

        $modelIds = static::filteredModelIds();

        if ($modelIds !== null) {
            $parts[] = $modelIds === []
                ? '1 = 0'
                : 'product_model_id IN ('.implode(',', $modelIds).')';
        }

        // Senza la funzionalità il filtro non c'è: un vecchio link con
        // `sotto=1` mostra tutte le righe invece di una pagina vuota.
        if (($_GET['sotto'] ?? '') === '1' && Gestionale::feature('low_stock_alerts')) {
            $alerted = static::alertedProductIds();
            $parts[] = $alerted === [] ? '1 = 0' : 'id IN ('.implode(',', $alerted).')';
        }

        $offset = (static::pageNumber($_GET['p'] ?? 1) - 1) * self::PER_PAGE;

        try {
            $rows = Product::find(
                implode(' AND ', $parts),
                $offset.','.self::PER_PAGE,
                'sku',
                'ASC'
            );
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /**
     * Gli id degli articoli che passano i filtri di categoria e marchio,
     * `null` quando non c'è nessun filtro.
     *
     * @return list<int>|null
     */
    private static function filteredModelIds(): ?array
    {
        $brandId = (int) ($_GET['marchio'] ?? 0);
        $categoryId = (int) ($_GET['categoria'] ?? 0);

        if ($brandId <= 0 && $categoryId <= 0) {
            return null;
        }

        $ids = null;

        if ($brandId > 0) {
            $ids = array_map(
                static fn (array $row): int => (int) $row['id'],
                static::rowsOf(ProductModel::class, ['brand_id' => $brandId])
            );
        }

        if ($categoryId > 0) {
            $inCategory = array_map(
                static fn (array $row): int => (int) $row['product_model_id'],
                static::rowsOf(ProductModelCategory::class, ['category_id' => $categoryId])
            );

            $ids = $ids === null ? $inCategory : array_intersect($ids, $inCategory);
        }

        return array_values(array_unique(array_map('intval', (array) $ids)));
    }

    /** @return list<int> */
    private static function alertedProductIds(): array
    {
        try {
            $rows = StockAlert::find("deleted = 'false' AND resolved_at IS NULL");
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $rows = isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));

        return array_values(array_unique(array_map(
            static fn (array $row): int => (int) ($row['product_id'] ?? 0),
            $rows
        )));
    }

    /** @return array<int, string> */
    private static function modelNames(): array
    {
        $names = [];

        foreach (static::rowsOf(ProductModel::class) as $row) {
            $names[(int) $row['id']] = trim((string) ($row['name'] ?? ''));
        }

        return $names;
    }

    /** @param array<string, mixed> $row */
    private static function rowLabel(array $row): string
    {
        $label = '<b>'.static::escape((string) $row['article']).'</b>';

        if ($row['option'] !== '') {
            $label .= ' — '.static::escape((string) $row['option']);
        }

        if ($row['sku'] !== '') {
            $label .= ' <span class="text-muted">'.static::escape((string) $row['sku']).'</span>';
        }

        return $label;
    }

    /** @param array<string, mixed> $row */
    private static function rowSide(array $row): string
    {
        $parts = [];

        if (Gestionale::feature('orders')) {
            $parts[] = 'impegnati '.static::escape(static::plain((float) $row['reserved']));
            $parts[] = 'disponibili '.static::escape(static::plain((float) $row['available']));
        }

        if ((float) $row['threshold'] > 0 && Gestionale::feature('low_stock_alerts')) {
            $parts[] = 'scorta minima '.static::escape(static::plain((float) $row['threshold']));
        }

        $parts[] = '<a href="'.static::escape(
            StockAdjustmentResource::urlFor((int) $row['id'], static::currentUrl())
        ).'">Rettifica</a>';

        return implode(' · ', $parts);
    }

    /** La barra dei filtri: link, perché un form dentro un form non si annida. */
    private static function filtersBar(): string
    {
        $search = static::searchTerm($_GET['cerca'] ?? '');
        $sotto = ($_GET['sotto'] ?? '') === '1';
        $base = static::pageUrl([]);

        $html = '<div class="d-flex flex-wrap gap-2 align-items-center mb-2">';
        $html .= '<input type="text" class="form-control form-control-sm w-auto"'
            .' id="gst-stock-search" placeholder="Nome, SKU o EAN"'
            .' value="'.static::escape($search).'">';

        if (Gestionale::feature('low_stock_alerts')) {
            $html .= '<a class="btn btn-sm btn-secondary" href="'
                .static::escape(static::pageUrl(['sotto' => $sotto ? null : '1', 'p' => null])).'">'
                .($sotto ? 'Tutte le opzioni' : 'Solo sotto scorta').'</a>';
        }

        $html .= '<a class="btn btn-sm btn-light" href="'.static::escape($base).'">Azzera i filtri</a>';
        $html .= '</div>';

        // La casella di ricerca naviga: il form della pagina serve a salvare le
        // quantità, e un secondo form dentro non si può annidare.
        $html .= '<script>(function(){var b='.json_encode(static::pageUrl(['cerca' => null, 'p' => null]))
            .',i=document.getElementById("gst-stock-search");'
            .'if(!i)return;i.addEventListener("keydown",function(e){if(e.key!=="Enter")return;'
            .'e.preventDefault();window.location.href=b+(b.indexOf("?")>-1?"&":"?")+"cerca="'
            .'+encodeURIComponent(i.value);});})();</script>';

        return $html;
    }

    private static function pagination(): string
    {
        $page = static::pageNumber($_GET['p'] ?? 1);
        $full = count(static::rows()) === self::PER_PAGE;

        if ($page === 1 && !$full) {
            return '';
        }

        $html = '<div class="d-flex gap-2 mt-2">';

        if ($page > 1) {
            $html .= '<a class="btn btn-sm btn-secondary" href="'
                .static::escape(static::pageUrl(['p' => $page - 1])).'">Indietro</a>';
        }

        if ($full) {
            $html .= '<a class="btn btn-sm btn-secondary" href="'
                .static::escape(static::pageUrl(['p' => $page + 1])).'">Avanti</a>';
        }

        return $html.'<span class="align-self-center text-muted">Pagina '.$page.'</span></div>';
    }

    /**
     * L'indirizzo di questa pagina con i filtri di adesso più quelli passati.
     *
     * @param array<string, string|null> $changes
     */
    private static function pageUrl(array $changes): string
    {
        $query = [];

        foreach (['cerca', 'categoria', 'marchio', 'sotto', 'p'] as $key) {
            $value = trim((string) ($_GET[$key] ?? ''));

            if ($value !== '') {
                $query[$key] = $value;
            }
        }

        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($query[$key]);
                continue;
            }

            $query[$key] = (string) $value;
        }

        $base = self::baseUrl();

        return $query === [] ? $base : $base.'?'.http_build_query($query);
    }

    /** L'elenco con le sole righe sotto la scorta minima: ci porta la home. */
    public static function lowStockUrl(): string
    {
        return self::baseUrl().'?sotto=1';
    }

    /** L'indirizzo della pagina, senza filtri. */
    private static function baseUrl(): string
    {
        $base = '/backend/'.static::path();

        if (function_exists('__r')) {
            try {
                $named = (string) __r('backend.resource.'.static::slug().'.form');
                $base = $named !== '' ? $named : $base;
            } catch (Throwable) {
                // Rotta non registrata (test, comandi): resta il percorso.
            }
        }

        return $base;
    }

    private static function currentUrl(): string
    {
        return static::pageUrl([]);
    }

    /** I pezzi interi si scrivono interi: "3", non "3,000". */
    private static function plain(float $value): string
    {
        $decimals = round($value, 3) === round($value, 0) ? 0 : 3;

        return number_format($value, $decimals, ',', '');
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Le righe vive di un Model, sempre come lista.
     *
     * `NavigationOnlyResource` non è una `GestionaleResource`: l'aiuto che sta
     * lì non arriva fin qui.
     *
     * @param class-string<\Wonder\App\Model> $modelClass
     * @param array<string, mixed> $where
     * @return list<array<string, mixed>>
     */
    private static function rowsOf(string $modelClass, array $where = []): array
    {
        try {
            $rows = $modelClass::find(array_merge(['deleted' => 'false'], $where));
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
