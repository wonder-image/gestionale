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
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Adjustment;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Plugin\Gestionale\Support\Stock\Stocktake;

/**
 * "Rettifica": cambiare la giacenza di **una** versione, lasciando scritto il
 * perché.
 *
 * È la porta del caso singolo — il pezzo rotto, il regalo, l'errore di conta —
 * e l'unica che chiede una nota. L'elenco *Giacenze* serve invece a sistemare
 * molte righe insieme, e lì la causale è una sola per tutta la schermata.
 *
 * Non è un elenco CRUD: non c'è niente da elencare, la riga da cambiare arriva
 * dall'indirizzo (`?versione=`). Per questo è una pagina-form.
 */
final class StockAdjustmentResource extends NavigationOnlyResource
{
    public static function path(): string
    {
        return 'app/gestionale/rettifica';
    }

    public static function icon(): string
    {
        return 'bi-pencil-square';
    }

    public static function titleLabel(): string
    {
        return 'Rettifica';
    }

    public static function isFormPage(): bool
    {
        return true;
    }

    /** Il link che apre la pagina su una versione, con la strada del ritorno. */
    public static function urlFor(int $productId, string $back = ''): string
    {
        $url = static::pageUrl().'?versione='.$productId;

        return $back === '' ? $url : $url.'&torna='.rawurlencode($back);
    }

    /**
     * L'indirizzo del ritorno, se è di questo backend.
     *
     * Un `torna=` che arriva dalla query string è testo di chiunque: accettare
     * un indirizzo esterno vorrebbe dire spedire il commerciante altrove dopo
     * un salvataggio andato a buon fine.
     */
    public static function backUrlFrom(mixed $value): string
    {
        $url = trim((string) ($value ?? ''));

        if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return '';
        }

        return $url;
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('mode')
                ->select([
                    'target' => 'Adesso ce ne sono',
                    'delta' => 'Aggiungi o togli',
                ])
                ->value('target')
                ->label('Come la scrivi')
                ->required(),
            FormField::key('quantity')
                ->number()
                ->decimal(3)
                ->label('Quantità')
                ->required(),
            FormField::key('reason')
                ->select(Reasons::all())
                ->value(Reasons::DEFAULT)
                ->label('Causale')
                ->required(),
            FormField::key('note')->textarea()->label('Nota'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make(static::productTitle())
                        ->tooltip('Ogni rettifica lascia un movimento con la sua causale: è quello che poi spiega la giacenza di oggi.')
                        ->columnSpan(12),
                    RichText::make(static::currentLine())->columnSpan(12),
                    static::getInput('mode')->columnSpan(4),
                    static::getInput('quantity')->columnSpan(4),
                    static::getInput('reason')->columnSpan(4),
                    static::getInput('note')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ])->columns(12);
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only([])
            ->titles(['form' => 'Rettifica la giacenza'])
            ->subtitles(['form' => 'Scrivi quanti pezzi ci sono adesso, oppure quanti ne aggiungi o ne togli. La causale e la nota restano scritte nei movimenti.'])
            ->docs(Gestionale::docsUrl('magazzino/magazzino-giacenze'), 'form');
    }

    public static function navigationSchema(): NavigationSchema
    {
        // Fuori dal menu: ci si arriva dalla riga di una versione.
        return NavigationSchema::for(static::class)
            ->inSection('magazzino')
            ->title('Rettifica')
            ->authority(['admin', 'administrator'])
            ->enabled(false);
    }

    /** La versione su cui si sta lavorando, `0` se l'indirizzo non la dice. */
    public static function productId(): int
    {
        return (int) ($_GET['versione'] ?? 0);
    }

    public static function submitFormPage(array $values): string
    {
        $productId = static::productId();
        $product = $productId > 0 ? Product::findById($productId) : null;

        if (!is_array($product) || $product === []) {
            throw UserError::make('stock.product_missing');
        }

        $quantity = Stocktake::quantity($values['quantity'] ?? null);

        if ($quantity === null) {
            throw UserError::make('stock.quantity_missing');
        }

        $current = Levels::of($productId)['quantity'];
        $change = ($values['mode'] ?? 'target') === 'delta'
            ? Adjustment::fromDelta($current, $quantity)
            : Adjustment::fromTarget($current, $quantity);

        if ($change['delta'] === 0.0) {
            throw UserError::make('stock.zero_quantity');
        }

        $user = LegacyGlobals::get('USER');

        Stock::apply([
            'product_id' => $productId,
            'quantity' => $change['delta'],
            'reason' => (string) ($values['reason'] ?? Reasons::DEFAULT),
            'note' => (string) ($values['note'] ?? ''),
            'user_id' => is_object($user) ? (int) ($user->id ?? 0) : 0,
        ]);

        $message = 'Giacenza di '.static::productName($product).': da '
            .static::number($change['before']).' a '.static::number($change['after']).'.';

        static::goBack($message);

        return $message;
    }

    /**
     * Torna da dove si era arrivati.
     *
     * Il core, dopo una pagina-form, rimanda sempre alla pagina stessa: qui
     * vorrebbe dire restare su una rettifica già fatta. Con `torna=` si torna
     * invece all'elenco o alla scheda, nel punto in cui si era.
     */
    private static function goBack(string $message): void
    {
        $back = static::backUrlFrom($_GET['torna'] ?? '');

        if ($back === '' || headers_sent()) {
            return;
        }

        FlashAlert::saved($message);
        header('Location: '.$back);
        exit();
    }

    /** L'indirizzo della pagina, dalla rotta quando il sito è avviato. */
    private static function pageUrl(): string
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

    private static function productTitle(): string
    {
        $product = Product::findById(static::productId());

        return is_array($product) && $product !== []
            ? 'Rettifica: '.static::productName($product)
            : 'Rettifica';
    }

    /** La riga che dice cosa c'è adesso, sopra le caselle. */
    private static function currentLine(): string
    {
        $productId = static::productId();

        if ($productId <= 0) {
            return '<span class="text-danger">Apri questa pagina dalla riga di una versione.</span>';
        }

        $levels = Levels::of($productId);
        $parts = ['<b>Adesso:</b> '.static::escape(static::number($levels['quantity'])).' pezzi'];

        if (Gestionale::feature('orders')) {
            $parts[] = 'impegnati '.static::escape(static::number($levels['reserved']));
            $parts[] = 'disponibili '.static::escape(static::number($levels['available']));
        }

        return implode(' · ', $parts);
    }

    /** @param array<string, mixed> $product */
    private static function productName(array $product): string
    {
        $model = ProductModel::findById((int) ($product['product_model_id'] ?? 0));
        $article = is_array($model) ? trim((string) ($model['name'] ?? '')) : '';
        $version = trim((string) ($product['name'] ?? ''));

        if ($article === '') {
            $article = trim((string) ($product['sku'] ?? ''));
        }

        return $version === '' ? $article : $article.' — '.$version;
    }

    /** I pezzi interi si scrivono interi: "3", non "3,000". */
    private static function number(float $value): string
    {
        $decimals = round($value, 3) === round($value, 0) ? 0 : 3;

        return number_format($value, $decimals, ',', '.');
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
