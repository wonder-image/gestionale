<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Throwable;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Inputs\InputRepeater;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductPlacement;
use Wonder\Plugin\Gestionale\Support\RequestMemo;

/**
 * Gli articoli di un elenco del sito, uguale per selezioni, categorie e tag:
 * ogni riga è un articolo o una sua variante, nell'ordine in cui si
 * trascinano.
 *
 * Il campo è un repeater di sole colonne nascoste (`id`, `item`), come i
 * suggeriti della scheda articolo; nella scheda si disegna con
 * {@see RelatedProductsEditor} («Cerca un articolo» e righe da trascinare),
 * che posta le stesse colonne. `getInput(PLACEMENTS_INPUT)` restituisce già
 * l'editor ({@see placementsEditor()}).
 *
 * Chi usa il trait dichiara `PLACEMENT_CONTEXT` ({@see Placements::CONTEXTS})
 * e mette `placementsField()` nel suo form; il resto (scomporre `item`,
 * togliere i doppioni, non scrivere dall'API) è qui.
 *
 * Categorie e tag usano il riquadro intero, «Ordine nel sito»: i campi, la
 * card e la pulizia dei valori, solo con «Vendita online». Spenta, i campi
 * mancano: il core non sincronizza l'elenco e le righe salvate restano.
 * Lì gli articoli in evidenza compaiono solo con `show_placements` a «Sì»;
 * a «No» l'elenco si nasconde ma le righe restano, e il sito non le usa.
 */
trait PlacementsForm
{
    public const PLACEMENTS_INPUT = 'placements';

    public const SHOW_PLACEMENTS_INPUT = 'show_placements';

    /** `$addLabel` resta per chi lo passa: l'editor non ha un bottone «Aggiungi». */
    protected static function placementsField(string $label, string $addLabel): InputRepeater
    {
        return FormField::key(self::PLACEMENTS_INPUT)
            ->repeater([
                RepeaterColumn::key('id')->hidden(),
                RepeaterColumn::key('item')->hidden(),
            ])
            ->relation(
                RepeaterRelation::make(ProductPlacement::$table, 'context_id')
                    ->model(ProductPlacement::class)
                    ->positionKey('position')
                    ->softDelete(false)
                    ->condition(['context_type' => static::PLACEMENT_CONTEXT])
            )
            ->nested()
            ->repeaterAddLabel($addLabel)
            ->label($label);
    }

    /** L'elenco nella scheda si disegna con l'editor, non col repeater. */
    public static function getInput(string $key): object
    {
        return $key === self::PLACEMENTS_INPUT ? static::placementsEditor() : parent::getInput($key);
    }

    /** «Cerca un articolo» e le righe salvate dell'elenco aperto. */
    protected static function placementsEditor(): RichText
    {
        $id = (int) (static::currentId() ?? 0);

        try {
            $rows = Placements::savedRows(static::PLACEMENT_CONTEXT, $id);
            $items = RelatedModelSearch::describeItems(array_column($rows, 'item'));
        } catch (Throwable) {
            $rows = $items = [];
        }

        $lines = [];

        foreach ($rows as $row) {
            if (isset($items[$row['item']])) {
                $lines[] = ['row' => (string) $row['id']] + $items[$row['item']];
            }
        }

        $url = RelatedProductsEditor::searchUrl();
        $url .= (str_contains($url, '?') ? '&' : '?').'items=1';
        $label = (string) static::getLabel(self::PLACEMENTS_INPUT);

        return RichText::make(
            '<div class="row">'
            .RelatedProductsEditor::renderLines(self::PLACEMENTS_INPUT, $label, $lines, 0, $url, 'item')
            .'</div>'
            .RelatedProductsEditor::scripts()
        );
    }

    /**
     * «In evidenza» sì o no, la regola degli altri articoli e l'elenco, con la
     * vendita online.
     *
     * @return list<object>
     */
    protected static function siteOrderFields(): array
    {
        if (!Gestionale::feature('online_sales')) {
            return [];
        }

        return [
            FormField::key(self::SHOW_PLACEMENTS_INPUT)
                ->select(['0' => 'No', '1' => 'Sì'])
                ->value('0')
                ->label('Mostra articoli in evidenza')
                ->required(),
            FormField::key('sort_mode')
                ->select(Placements::CONTEXT_SORT_MODES)
                ->value('catalog')
                ->label('Ordine degli altri articoli')
                ->required(),
            static::placementsField('In evidenza', 'Aggiungi un articolo in evidenza'),
        ];
    }

    /** @return list<Card> la card «Ordine nel sito», o niente senza la vendita online */
    protected static function siteOrderCards(string $tooltip): array
    {
        if (!Gestionale::feature('online_sales')) {
            return [];
        }

        return [
            (new Card)->components([
                SectionTitle::make('Ordine nel sito')->tooltip($tooltip)->columnSpan(12),
                static::getInput(self::SHOW_PLACEMENTS_INPUT)->columnSpan(6),
                static::getInput('sort_mode')->columnSpan(6),
                (new Container)->components([
                    static::getInput(self::PLACEMENTS_INPUT)->columnSpan(12),
                ])->columns(12)->columnSpan(12)->visibleWhen(self::SHOW_PLACEMENTS_INPUT, '1'),
            ])->columns(12)->columnSpan(12),
        ];
    }

    /**
     * La regola e «In evidenza» si salvano solo con la vendita online, e
     * solo con i valori noti.
     */
    protected static function siteOrderValues(array $values): array
    {
        if (!Gestionale::feature('online_sales')) {
            unset($values['sort_mode'], $values[self::SHOW_PLACEMENTS_INPUT]);

            return $values;
        }

        if (array_key_exists('sort_mode', $values)) {
            $values['sort_mode'] = Placements::contextSortMode($values['sort_mode']);
        }

        if (array_key_exists(self::SHOW_PLACEMENTS_INPUT, $values)) {
            $values[self::SHOW_PLACEMENTS_INPUT] = (string) $values[self::SHOW_PLACEMENTS_INPUT] === '1' ? '1' : '0';
        }

        return $values;
    }

    /** Articolo e variante al posto di `item`, senza righe vuote né doppioni. */
    public static function prepareRepeaterRows(
        string $inputName,
        array $rows,
        string $action = 'store',
        string $context = 'backend'
    ): array {
        if ($inputName === self::PLACEMENTS_INPUT) {
            return Placements::cleanRows($rows);
        }

        return parent::prepareRepeaterRows($inputName, $rows, $action, $context);
    }

    /**
     * Le righe si scrivono solo dalla scheda: l'API passa al repeater la
     * richiesta intera, e una richiesta senza l'elenco lo svuoterebbe.
     */
    public static function syncRepeaterRelations(
        int|string $parentId,
        array $post,
        array $files = [],
        string $action = 'store',
        string $context = 'backend'
    ): array {
        if ($context === 'api') {
            return [];
        }

        $summary = parent::syncRepeaterRelations($parentId, $post, $files, $action, $context);
        RequestMemo::forget(ProductPlacement::MEMO_GROUP);

        return $summary;
    }

    /** Il select delle righe salvate vuole la chiave `m12` / `v34`. */
    protected static function placementFormValues(array $values): array
    {
        if (is_array($values[self::PLACEMENTS_INPUT] ?? null)) {
            $values[self::PLACEMENTS_INPUT] = Placements::withItemKeys($values[self::PLACEMENTS_INPUT]);
        }

        return $values;
    }
}
