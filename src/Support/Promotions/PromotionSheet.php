<?php

namespace Wonder\Plugin\Gestionale\Support\Promotions;

use Throwable;
use Wonder\Elements\Components\RichText;

/**
 * Le parti che la scheda di una campagna e quella di un coupon hanno in
 * comune: una riga «etichetta, valore», i canali dove vale e il selettore
 * dei prodotti in parole.
 *
 * Come `ScopeForm` sta in un trait perché le due pagine sono di chi le usa ma
 * il disegno è lo stesso. Serve `escape()` e `rowsOf()` della Resource.
 */
trait PromotionSheet
{
    /** Una riga della scheda: etichetta piccola sopra, valore sotto; «—» se manca. */
    protected static function sheetRow(string $label, string $value, bool $html = false): RichText
    {
        $value = trim($value) !== ''
            ? ($html ? $value : static::escape($value))
            : '<span class="text-muted">—</span>';

        return RichText::make(
            '<div class="small text-muted">'.static::escape($label).'</div><div>'.$value.'</div>'
        )->tag('div')->columnSpan(12);
    }

    /** I tre canali: quelli dove vale pieni, gli altri spenti. */
    protected static function channelsHtml(array $row): string
    {
        $html = '';

        foreach (['applies_online' => 'Sito', 'applies_office' => 'Ufficio', 'applies_pos' => 'Cassa'] as $column => $name) {
            $on = ($row[$column] ?? 'false') === 'true';
            $html .= '<span class="badge '.($on ? 'text-bg-primary' : 'text-bg-light text-muted').' me-1">'.static::escape($name).'</span>';
        }

        return $html;
    }

    /**
     * Il selettore salvato in parole: «Tutto il catalogo» o «Solo la
     * selezione» con le liste che ci sono, poi gli articoli esclusi.
     */
    protected static function scopeHtml(string $owner, int $ownerId): string
    {
        try {
            $scope = ProductScope::of($owner, $ownerId);
        } catch (Throwable) {
            $scope = ['all' => true, 'categories' => [], 'tags' => [], 'brands' => [], 'models' => [], 'excluded_models' => []];
        }

        $html = '<div>'.($scope['all'] ? 'Tutto il catalogo' : 'Solo la selezione').'</div>';
        $lists = [
            'Categorie' => [$scope['categories'], static fn (): array => static::scopeCategoryOptions()],
            'Tag' => [$scope['tags'], static fn (): array => static::scopeOptions(\Wonder\Plugin\Gestionale\Models\Catalog\Tag::class)],
            'Marchi' => [$scope['brands'], static fn (): array => static::scopeOptions(\Wonder\Plugin\Gestionale\Models\Catalog\Brand::class)],
            'Articoli' => [$scope['models'], static fn (): array => static::scopeOptions(\Wonder\Plugin\Gestionale\Models\Catalog\ProductModel::class)],
            'Articoli esclusi' => [$scope['excluded_models'], static fn (): array => static::scopeOptions(\Wonder\Plugin\Gestionale\Models\Catalog\ProductModel::class)],
        ];

        foreach ($lists as $label => [$ids, $options]) {
            if ($ids === []) {
                continue;
            }

            $names = $options();
            $items = array_map(
                static fn (int $id): string => static::escape(trim((string) ($names[(string) $id] ?? '#'.$id), " \t\n\r\0\x0B—-")),
                $ids
            );
            $html .= '<div class="mt-2"><span class="small text-muted">'.static::escape($label).'</span><br>'.implode(', ', $items).'</div>';
        }

        return $html;
    }

    /** L'indirizzo della modifica, dalla rotta con il nome; il percorso è il ripiego. */
    public static function editUrlFor(int $id): string
    {
        $fallback = '/backend/'.static::path().'/'.$id.'/edit/';

        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.static::slug().'.edit', ['id' => $id]);
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
    }
}
