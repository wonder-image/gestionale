<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Inputs\InputRepeater;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductPlacement;
use Wonder\Plugin\Gestionale\Support\RequestMemo;

/**
 * Il repeater degli articoli di un elenco del sito, uguale per selezioni,
 * categorie e tag: ogni riga è un articolo o una sua variante, con la foto di
 * contesto facoltativa, nell'ordine in cui si trascinano.
 *
 * Chi usa il trait dichiara `PLACEMENT_CONTEXT` ({@see Placements::CONTEXTS})
 * e mette `placementsField()` nel suo form; il resto (scomporre `item`,
 * togliere i doppioni, riempire il form, non scrivere dall'API) è qui.
 *
 * Categorie e tag usano il riquadro intero, «Ordine nel sito»: i campi, la
 * card e la pulizia dei valori, solo con «Vendita online». Spenta, i campi
 * mancano: il core non sincronizza l'elenco e le righe salvate restano.
 */
trait PlacementsForm
{
    public const PLACEMENTS_INPUT = 'placements';

    protected static function placementsField(string $label, string $addLabel): InputRepeater
    {
        return FormField::key(self::PLACEMENTS_INPUT)
            ->repeater([
                RepeaterColumn::key('id')->hidden(),
                RepeaterColumn::key('item')
                    ->selectSearch(Placements::itemOptions())
                    ->label('Articolo o variante')
                    ->required()
                    ->columnSpan(12),
                RepeaterColumn::key('image')->fileDragDrop('image')->label('Foto di contesto')->columnSpan(12),
            ])
            ->relation(
                RepeaterRelation::make(ProductPlacement::$table, 'context_id')
                    ->model(ProductPlacement::class)
                    ->positionKey('position')
                    ->softDelete(false)
                    ->condition(['context_type' => static::PLACEMENT_CONTEXT])
            )
            ->nested()
            ->repeaterSortable()
            ->repeaterAdvanced('image')
            ->repeaterAdvancedLabel('Foto di contesto')
            ->repeaterAddLabel($addLabel)
            ->repeaterDeleteTitle('Togli dall\'elenco')
            ->repeaterDeleteText('Togli questo articolo dall\'elenco? L\'articolo resta nel catalogo.')
            ->repeaterDeleteCancelLabel('Annulla')
            ->repeaterDeleteConfirmLabel('Togli')
            ->repeaterDeleteConfirmClass('btn btn-danger')
            ->label($label);
    }

    /**
     * «In evidenza» e la regola degli altri articoli, con la vendita online.
     *
     * @return list<object>
     */
    protected static function siteOrderFields(): array
    {
        if (!Gestionale::feature('online_sales')) {
            return [];
        }

        return [
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
                static::getInput(self::PLACEMENTS_INPUT)->columnSpan(12),
                static::getInput('sort_mode')->columnSpan(6),
            ])->columns(12)->columnSpan(12),
        ];
    }

    /** La regola si salva solo con la vendita online, e solo se è una di quelle note. */
    protected static function siteOrderValues(array $values): array
    {
        if (!Gestionale::feature('online_sales')) {
            unset($values['sort_mode']);
        } elseif (array_key_exists('sort_mode', $values)) {
            $values['sort_mode'] = Placements::contextSortMode($values['sort_mode']);
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
