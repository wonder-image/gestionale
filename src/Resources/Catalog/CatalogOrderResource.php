<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use Throwable;
use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Backend\Support\RowDrag;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Catalog\CatalogOrder;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * «Ordina»: marchi, categorie, tag e attributi nell'ordine in cui il sito li
 * elenca, trascinando le righe.
 *
 * Non è un elenco CRUD: l'elenco da ordinare arriva dall'indirizzo
 * (`?elenco=marchi`, e per le categorie `&padre=` il livello), e ci si arriva
 * dal bottone «Ordina» dei quattro elenchi. Per questo è una pagina-form, come
 * «Rettifica». Le righe sono in HTML con un `order[]` nascosto ciascuna: il
 * form le posta nell'ordine in cui stanno, e {@see CatalogOrder::save()}
 * riscrive le posizioni.
 */
final class CatalogOrderResource extends NavigationOnlyResource
{
    public static function path(): string
    {
        return 'app/gestionale/ordina';
    }

    public static function icon(): string
    {
        return 'bi-sort-down';
    }

    public static function titleLabel(): string
    {
        return 'Ordina';
    }

    public static function isFormPage(): bool
    {
        return true;
    }

    /** Il link alla pagina su un elenco; per le categorie anche il livello. */
    public static function urlFor(string $list, int $parentId = 0): string
    {
        $url = static::pageUrl().'?elenco='.rawurlencode($list);

        return $list === 'categorie' && $parentId > 0 ? $url.'&padre='.$parentId : $url;
    }

    /** Il bottone «Ordina» dell'header di un elenco. */
    public static function listAction(string $list): array
    {
        return [
            'label' => 'Ordina',
            'icon' => 'bi bi-sort-down',
            'href' => static::urlFor($list),
            'class' => 'btn-outline-secondary',
        ];
    }

    /** L'elenco dell'indirizzo; i marchi se manca o non si conosce. */
    public static function currentList(): string
    {
        $list = is_string($_GET['elenco'] ?? null) ? trim($_GET['elenco']) : '';

        return CatalogOrder::known($list) ? $list : 'marchi';
    }

    public static function currentParent(): int
    {
        return static::currentList() === 'categorie' ? max(0, (int) ($_GET['padre'] ?? 0)) : 0;
    }

    public static function formSchema(): array
    {
        return [
            // La rotta del salvataggio non ha query string: l'elenco e il
            // livello viaggiano con il form.
            FormField::key('elenco')->hidden()->value(static::currentList()),
            FormField::key('padre')->hidden()->value((string) static::currentParent()),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        $list = static::currentList();
        $parent = static::currentParent();

        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make(static::heading($list, $parent))
                        ->tooltip('Trascina le righe dalla maniglia, o spostale con le frecce su e giù da tastiera, poi salva. Il sito li elenca in quest\'ordine: menu, filtri e schede. Una riga nuova si mette in fondo.')
                        ->columnSpan(12),
                    RichText::make(static::switcher($list).static::table($list, $parent))->columnSpan(12),
                    static::getInput('elenco')->columnSpan(12),
                    static::getInput('padre')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ])->columns(12);
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only([])
            ->titles(['form' => 'Ordina'])
            ->subtitles(['form' => 'L\'ordine in cui il sito mostra marchi, categorie, tag e attributi.'])
            ->docs(Gestionale::docsUrl('catalogo/catalogo-tassonomie'), 'form');
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('catalogo'));
    }

    public static function navigationSchema(): NavigationSchema
    {
        // Fuori dal menu: ci si arriva dal bottone «Ordina» degli elenchi.
        return NavigationSchema::for(static::class)
            ->inSection('catalogo')
            ->title('Ordina')
            ->access(Gestionale::area('catalogo'))
            ->enabled(false);
    }

    /**
     * Salva l'ordine e torna sulla stessa pagina, sullo stesso elenco: il
     * core tornerebbe alla pagina senza indirizzo, cioè sui marchi.
     *
     * I rifiuti si trattano qui dentro: il controller delle pagine-form non
     * intercetta niente, e un `UserError` diventerebbe una pagina 500.
     */
    public static function submitFormPage(array $values): string
    {
        $list = is_string($values['elenco'] ?? null) ? trim($values['elenco']) : '';
        $parent = $list === 'categorie' ? max(0, (int) ($values['padre'] ?? 0)) : 0;
        $order = is_array($values['order'] ?? null) ? array_values($values['order']) : [];

        try {
            $count = CatalogOrder::save($list, $parent, $order);
        } catch (UserError $error) {
            static::back($list, $parent, $error->getMessage(), false);

            return $error->getMessage();
        }

        $label = CatalogOrder::LISTS[$list]['label'];
        $message = $count === 0 ? 'Niente da ordinare.' : 'Ordine salvato: '.mb_strtolower($label).' nella sequenza scelta.';
        static::back($list, $parent, $message, true);

        return $message;
    }

    /** «Ordina i marchi», o per le categorie il livello: «Ordina le categorie in Abbigliamento». */
    public static function heading(string $list, int $parentId = 0): string
    {
        $label = mb_strtolower(CatalogOrder::LISTS[$list]['label'] ?? 'righe');
        $article = in_array($list, ['categorie'], true) ? 'le' : 'i';
        $title = 'Ordina '.$article.' '.$label;

        if ($list !== 'categorie' || $parentId <= 0) {
            return $list === 'categorie' ? $title.' in cima all\'albero' : $title;
        }

        $names = array_map(static fn (array $row): string => static::plain((string) ($row['name'] ?? '')), CatalogOrder::categoryPath($parentId));

        return $names === [] ? $title : $title.' in '.implode(' › ', $names);
    }

    /** I quattro elenchi come pulsanti, quello aperto evidenziato. */
    private static function switcher(string $current): string
    {
        $html = '<div class="btn-group flex-wrap mb-3" role="group" aria-label="Cosa ordinare">';

        foreach (CatalogOrder::LISTS as $key => $list) {
            $active = $key === $current;
            $html .= '<a class="btn btn-sm '.($active ? 'btn-dark' : 'btn-outline-dark').'" href="'.static::e(static::urlFor($key)).'"'
                .($active ? ' aria-current="page"' : '').'>'.static::e($list['label']).'</a>';
        }

        return $html.'</div>';
    }

    /** Le righe da trascinare, ognuna con il suo `order[]`. */
    private static function table(string $list, int $parentId): string
    {
        try {
            $rows = CatalogOrder::rows($list, $parentId);
        } catch (Throwable) {
            $rows = [];
        }

        $isCategory = $list === 'categorie';
        $children = $isCategory ? CatalogOrder::childCounts(array_map(static fn (array $row): int => (int) $row['id'], $rows)) : [];
        $html = '';

        if ($isCategory && $parentId > 0) {
            $path = CatalogOrder::categoryPath($parentId);
            $up = count($path) > 1 ? (int) $path[count($path) - 2]['id'] : 0;
            $html .= '<p class="mb-2"><a href="'.static::e(static::urlFor('categorie', $up)).'"><i class="bi bi-arrow-up" aria-hidden="true"></i> Su di un livello</a></p>';
        }

        if ($rows === []) {
            $one = CatalogOrder::LISTS[$list]['one'] ?? 'riga';

            return $html.'<p class="text-body-secondary my-2">'.static::e($isCategory && $parentId > 0 ? 'Questa categoria non ha sottocategorie.' : 'Nessun '.$one.' da ordinare.').'</p>';
        }

        $html .= '<div class="table-responsive"><table class="table table-sm align-middle mb-0">'
            .'<thead><tr><th style="width: 1.5rem"><span class="visually-hidden">Sposta</span></th><th>Nome</th>'
            .($isCategory ? '<th class="text-end"><span class="visually-hidden">Sottocategorie</span></th>' : '')
            .'</tr></thead><tbody data-wi-catalog-order>';

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $hidden = ($row['visible'] ?? $row['is_visible'] ?? 'true') !== 'true';
            $html .= '<tr><td class="pe-0">'.RowDrag::handle().'</td><td>'
                .static::e(static::plain((string) ($row['name'] ?? '')))
                .($hidden ? ' <span class="badge text-bg-secondary">nascosto</span>' : '')
                .'<input type="hidden" name="order[]" value="'.$id.'"></td>';

            if ($isCategory) {
                $count = $children[$id] ?? 0;
                $html .= '<td class="text-end">'.($count > 0
                    ? '<a class="small" href="'.static::e(static::urlFor('categorie', $id)).'">Ordina le sottocategorie ('.$count.')</a>'
                    : '').'</td>';
            }

            $html .= '</tr>';
        }

        return $html.'</tbody></table></div>'.RowDrag::script().'<script>'.static::script().'</script>';
    }

    /** Arma il trascinamento una volta, quando il backend è pronto. */
    private static function script(): string
    {
        return <<<'JS'
(function () {
    'use strict';

    function init() {
        var body = document.querySelector('[data-wi-catalog-order]');

        if (body && window.wiRowDrag && !body.wiCatalogOrder) {
            body.wiCatalogOrder = true;
            window.wiRowDrag(body, function () {});
        }
    }

    window.addEventListener('loaded', init, {once: true});

    if (document.readyState === 'complete') {
        init();
    }
})();
JS;
    }

    private static function back(string $list, int $parent, string $message, bool $ok): void
    {
        if (headers_sent()) {
            return;
        }

        $ok ? FlashAlert::saved($message) : FlashAlert::custom('Attenzione', $message, 'warning');
        header('Location: '.static::urlFor(CatalogOrder::known($list) ? $list : 'marchi', $parent));
        exit();
    }

    /** L'indirizzo della pagina, dalla rotta quando il sito è avviato. */
    private static function pageUrl(): string
    {
        $fallback = '/backend/'.static::path().'/';

        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $url = (string) __r('backend.resource.'.static::slug().'.form');
        } catch (Throwable) {
            return $fallback;
        }

        return $url !== '' ? $url : $fallback;
    }

    private static function plain(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
