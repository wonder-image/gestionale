<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use Throwable;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\App\Support\Repeater;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationLink;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanFeature;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItem;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Features\FeatureGuards;
use Wonder\Plugin\Gestionale\Support\Numbers;

/**
 * La parte «abbonamento» della scheda prodotto (G10): i prezzi ricorrenti, i
 * limiti («Metadata») e «Si lega a». Prima era la scheda dei piani.
 *
 * I prezzi stanno in un repeater (nome, prezzo, addebito, una tantum, stato;
 * dietro «Compila le informazioni avanzate» prezzo scontato, vincolo, prova,
 * rinnovo). La ricorrenza non si sceglie: si ricava dall'addebito. Un prezzo usato da un abbonamento, anche
 * chiuso, non esce dal repeater: si spegne.
 */
final class SubscriptionProductForm
{
    /** Ogni quanto si paga un prezzo: i mesi coperti da un addebito. @return array<string, string> */
    public static function termOptions(): array
    {
        return ['1' => 'Mensile', '2' => 'Bimestrale', '3' => 'Trimestrale', '6' => 'Semestrale', '12' => 'Annuale'];
    }

    /**
     * I campi dell'abbonamento: prezzi, metadata e «Si lega a».
     *
     * @return list<object>
     */
    public static function fields(int $modelId): array
    {
        return [
            FormField::key('parent_plans')
                ->selectSearch(static::parentOptions($modelId), true)
                ->label('Si lega a'),
            FormField::key('prices')
                ->repeater([
                    RepeaterColumn::key('id')->hidden(),
                    RepeaterColumn::key('name')->text()->label('Nome')->columnSpan(3),
                    RepeaterColumn::key('price')->number()->decimal(2)->label('Prezzo')->columnSpan(2),
                    RepeaterColumn::key('term_months')->select(static::termOptions())->label('Addebito')->columnSpan(2),
                    RepeaterColumn::key('one_time_price')->number()->decimal(2)->label('Una tantum')->columnSpan(2),
                    RepeaterColumn::key('active')->select(['true' => 'Attivo', 'false' => 'Spento'])->label('Stato')->columnFill(),
                    RepeaterColumn::key('sale_price')->number()->decimal(2)->label('Prezzo scontato')->columnSpan(3),
                    RepeaterColumn::key('commitment_months')->number()->decimal(0)->label('Vincolo (mesi)')->columnSpan(2),
                    RepeaterColumn::key('trial_days')->number()->decimal(0)->label('Prova (giorni)')->columnSpan(2),
                    RepeaterColumn::key('renewal_plan_price_id')
                        ->select(['0' => 'Nessuno'] + static::renewalOptions())
                        ->label('A fine vincolo passa a')
                        ->columnFill(),
                ])
                ->relation(
                    RepeaterRelation::make(PlanPrice::$table, 'product_model_id')
                        ->model(PlanPrice::class)
                        ->positionKey('position')
                )
                ->nested()
                ->repeaterSortable()
                ->repeaterAdvanced('sale_price', 'commitment_months', 'trial_days', 'renewal_plan_price_id')
                ->repeaterAdvancedLabel('Compila le informazioni avanzate')
                ->repeaterAddLabel('Aggiungi prezzo')
                ->repeaterDeleteTitle('Elimina prezzo')
                ->repeaterDeleteText('Confermi l\'eliminazione di questo prezzo? Se un abbonamento lo usa, il salvataggio lo rifiuta: spegnilo.')
                ->repeaterDeleteCancelLabel('Annulla')
                ->repeaterDeleteConfirmLabel('Elimina')
                ->repeaterDeleteConfirmClass('btn btn-danger')
                ->label(''),
            FormField::key('features')
                ->repeater([
                    RepeaterColumn::key('id')->hidden(),
                    RepeaterColumn::key('feature_key')->text()->label('Chiave')->columnSpan(6),
                    RepeaterColumn::key('feature_value')->text()->label('Valore')->columnFill(),
                ])
                ->relation(
                    RepeaterRelation::make(PlanFeature::$table, 'product_model_id')
                        ->model(PlanFeature::class)
                        ->positionKey('position')
                        ->softDelete(false)
                )
                ->nested()
                ->repeaterSortable()
                ->repeaterAddLabel('Aggiungi metadato')
                ->repeaterDeleteTitle('Elimina metadato')
                ->repeaterDeleteText('Confermi l\'eliminazione di questo metadato?')
                ->repeaterDeleteCancelLabel('Annulla')
                ->repeaterDeleteConfirmLabel('Elimina')
                ->repeaterDeleteConfirmClass('btn btn-danger')
                ->label(''),
        ];
    }

    public const PRICES_TOOLTIP = 'Un prezzo per riga. «Addebito» dice ogni quanto si paga il prezzo: mensile, trimestrale… annuale. Il prezzo è IVA inclusa o esclusa come il listino; il prezzo scontato, se più basso, vince. Vincolo zero vuol dire nessun vincolo. «A fine vincolo passa a» propone solo i prezzi già salvati: per un prezzo nuovo, salva e poi sceglilo. Un prezzo cambiato vale per i nuovi abbonati.';

    public const MAIN_TOOLTIP = 'Il nome, le descrizioni e le foto li vede il cliente nella pagina dell\'abbonamento. «Bozza» lo toglie dal sito e dall\'ufficio, ma lo lascia agli abbonati. «Si lega a»: si compra solo insieme a un abbonamento di uno di questi, come l\'hosting col dominio, e va sullo stesso abbonamento. Un livello solo: si scelgono abbonamenti che non si legano a nulla.';

    public const FEATURES_TOOLTIP = 'Quello che l\'abbonamento permette sul sito, a coppie: la chiave la decide chi ha fatto il sito (ad esempio «max_progetti»), il valore è un numero o una parola («5», «illimitato»). Il sito li controlla quando il cliente crea qualcosa; passare a un abbonamento più basso non toglie niente.';

    /**
     * Gli abbonamenti a cui questo si può legare: quelli vendibili che non si
     * legano a nulla, lui escluso, per posizione. Le madri già salvate restano
     * anche se nel frattempo spente, con la scritta.
     *
     * @return array<string, string>
     */
    public static function parentOptions(int $modelId): array
    {
        $links = PlanLinks::existing();
        $current = $links[$modelId] ?? [];
        $options = [];

        foreach (static::rows(ProductModel::class, ['type' => SubscriptionModels::TYPE], 'position') as $model) {
            $id = (int) $model['id'];
            $name = trim((string) ($model['name'] ?? ''));
            $live = SubscriptionModels::isLive($model);

            if ($id === $modelId) {
                continue;
            }

            if (in_array($id, $current, true)) {
                $options[(string) $id] = $live ? $name : $name.' (spento)';
            } elseif ($live && ($links[$id] ?? []) === []) {
                $options[(string) $id] = $name;
            }
        }

        return $options;
    }

    /** «Mensile 19,00 € · Annuale 190,00 €», solo i prezzi attivi. */
    public static function pricesSummary(int $modelId): string
    {
        $parts = [];

        foreach (static::rows(PlanPrice::class, ['product_model_id' => $modelId, 'active' => 'true'], 'position') as $price) {
            $parts[] = static::priceLabel($price);
        }

        return implode(' · ', $parts);
    }

    /** «Mensile 19,00 €», con il prezzo scontato se vale. @param array<string, mixed> $price */
    public static function priceLabel(array $price): string
    {
        $amount = (float) ($price['price'] ?? 0);
        $sale = (float) ($price['sale_price'] ?? 0);
        $shown = $sale > 0.0 && $sale < $amount ? $sale : $amount;

        return trim((string) ($price['name'] ?? '')).' '.number_format($shown, 2, ',', '.').' €';
    }

    /**
     * I prezzi che possono fare da rinnovo: quelli salvati degli abbonamenti
     * vendibili, «Abbonamento — Prezzo».
     *
     * @return array<string, string>
     */
    public static function renewalOptions(): array
    {
        $options = [];

        foreach (SubscriptionModels::live() as $model) {
            foreach (static::rows(PlanPrice::class, ['product_model_id' => (int) $model['id']], 'position') as $price) {
                $options[(string) $price['id']] = trim((string) $model['name']).' — '.trim((string) $price['name']);
            }
        }

        return $options;
    }

    /**
     * Prima di scrivere: almeno un prezzo, ogni prezzo in regola, nessun
     * prezzo in uso tolto, nessuna chiave dei limiti ripetuta, legami validi.
     *
     * @param array<string, mixed> $post
     */
    public static function assertRequest(int $modelId, array $post, string $action): void
    {
        $prices = static::prepareRows('prices', Repeater::rowsFromRequest('prices', $post));

        if ($prices === []) {
            throw UserError::make('plan.no_prices');
        }

        $renewalIds = array_map('intval', array_keys(static::renewalOptions()));

        foreach ($prices as $price) {
            PlanPriceRules::assert($price, $renewalIds);
        }

        static::assertKeptPrices($modelId, $prices);
        static::assertFeatures(static::prepareRows('features', Repeater::rowsFromRequest('features', $post)));
        PlanLinks::assert($modelId, PlanLinks::normalize((array) ($post['parent_plans'] ?? [])), PlanLinks::existing());
    }

    /** I legami «Si lega a», dopo il salvataggio. @param array<string, mixed> $post */
    public static function syncLinks(int $modelId, array $post): void
    {
        if ($modelId > 0) {
            PlanLinks::sync($modelId, PlanLinks::normalize((array) ($post['parent_plans'] ?? [])));
        }
    }

    /**
     * Un prezzo nuovo nasce col suo codice (`ppr_…`): il repeater non lo fa
     * da sé. Un prezzo che c'era già tiene il suo.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $existingRow
     * @return array<string, mixed>
     */
    public static function relationRow(array $payload, ?array $existingRow): array
    {
        $code = trim((string) ($existingRow['code'] ?? ''));
        $payload['code'] = $code !== '' ? $code : Code::make(PlanPrice::class, Codes::PLAN_PRICE);

        return $payload;
    }

    /**
     * Le righe vuote non sono prezzi né limiti; le altre si scrivono come le
     * regole le hanno lette: numeri col punto, mesi interi, interruttori sì o no.
     *
     * @param array<int|string, mixed> $rows
     * @return list<array<string, mixed>>
     */
    public static function prepareRows(string $inputName, array $rows): array
    {
        $clean = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($inputName === 'features') {
                $row['feature_key'] = trim((string) ($row['feature_key'] ?? ''));
                $row['feature_value'] = trim((string) ($row['feature_value'] ?? ''));

                if ($row['feature_key'] !== '') {
                    $clean[] = $row;
                }

                continue;
            }

            if (trim((string) ($row['name'] ?? '')) === '' && trim((string) ($row['price'] ?? '')) === '') {
                continue;
            }

            // La ricorrenza non si sceglie: si ricava dall'addebito, così
            // le due non si contraddicono mai. Un addebito fuori elenco lo
            // ferma `PlanPriceRules` al salvataggio.
            $term = trim((string) ($row['term_months'] ?? ''));
            $row['name'] = trim((string) ($row['name'] ?? ''));
            $row['term_months'] = $term === '' ? PlanPriceRules::defaultTerm('month') : (int) $term;
            $row['billing_interval'] = PlanPriceRules::intervalOf($row['term_months']);

            $row['price'] = Numbers::fromForm($row['price'] ?? null) ?? '';
            $row['sale_price'] = Numbers::fromForm($row['sale_price'] ?? null) ?? '0.00';
            $row['one_time_price'] = Numbers::fromForm($row['one_time_price'] ?? null) ?? '0.00';
            $row['commitment_months'] = (int) trim((string) ($row['commitment_months'] ?? '0'));
            $row['trial_days'] = (int) trim((string) ($row['trial_days'] ?? '0'));
            $row['renewal_plan_price_id'] = max(0, (int) ($row['renewal_plan_price_id'] ?? 0));
            $row['active'] = ($row['active'] ?? 'true') === 'false' ? 'false' : 'true';
            $clean[] = $row;
        }

        return $clean;
    }

    /**
     * Un abbonamento con abbonati vivi non si elimina: si spegne. Nemmeno uno
     * a cui altri si legano: il legame si toglie dalla loro scheda.
     */
    public static function assertDeletable(int $modelId): void
    {
        $count = Subscriptions::liveCount(Subscriptions::priceIds($modelId));

        if ($count > 0) {
            throw UserError::refusal('plan.in_use', ['count' => FeatureGuards::subscriptionsLabel($count)]);
        }

        $children = [];

        foreach (PlanLinks::existing() as $child => $parents) {
            if (in_array($modelId, $parents, true)) {
                $model = ProductModel::findById($child);
                $children[] = '«'.(is_array($model) ? trim((string) ($model['name'] ?? '')) : '#'.$child).'»';
            }
        }

        if ($children !== []) {
            throw UserError::refusal('plan.has_children', ['names' => implode(', ', $children)]);
        }
    }

    /**
     * Toglie legami, limiti e prezzi dell'abbonamento, dentro la transazione
     * di chi elimina. Un prezzo che la riga di un abbonamento chiuso ancora
     * nomina resta, segnato eliminato.
     *
     * @return bool true se un prezzo è rimasto: allora il prodotto si segna eliminato
     */
    public static function deleteParts(int $modelId): bool
    {
        PlanLinks::forget($modelId);

        foreach (static::rows(PlanFeature::class, ['product_model_id' => $modelId, 'deleted' => ['true', 'false']]) as $feature) {
            PlanFeature::delete((int) $feature['id']);
        }

        $kept = false;

        foreach (static::rows(PlanPrice::class, ['product_model_id' => $modelId, 'deleted' => ['true', 'false']]) as $price) {
            $priceId = (int) $price['id'];

            if (static::rows(SubscriptionItem::class, ['plan_price_id' => $priceId, 'deleted' => ['true', 'false']]) === []) {
                PlanPrice::delete($priceId);
            } else {
                PlanPrice::query()->Update(PlanPrice::$table, ['deleted' => 'true'], 'id', $priceId);
                $kept = true;
            }
        }

        return $kept;
    }

    /** Riempie «Si lega a», che non sta nella tabella del prodotto. @param array<string, mixed> $values @return array<string, mixed> */
    public static function formValues(int $modelId, array $values): array
    {
        $values['parent_plans'] = array_map('strval', PlanLinks::parentsOf($modelId));

        return $values;
    }

    /**
     * I prezzi già salvati che non tornano nel repeater e che un abbonamento
     * usa: il salvataggio si rifiuta, con il nome del primo.
     *
     * @param list<array<string, mixed>> $submitted
     */
    private static function assertKeptPrices(int $modelId, array $submitted): void
    {
        if ($modelId <= 0) {
            return;
        }

        $kept = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $submitted);
        $removed = array_values(array_diff(Subscriptions::priceIds($modelId), $kept));
        $used = Subscriptions::usedPrices($removed);

        if ($used !== []) {
            $price = PlanPrice::findById($used[0]);

            throw UserError::make('plan.price_in_use', ['name' => is_array($price) ? (string) ($price['name'] ?? '') : '#'.$used[0]]);
        }
    }

    /** @param list<array<string, mixed>> $features */
    private static function assertFeatures(array $features): void
    {
        $seen = [];

        foreach ($features as $feature) {
            $key = mb_strtolower((string) $feature['feature_key']);

            if (isset($seen[$key])) {
                throw UserError::make('plan.feature_duplicate', ['key' => (string) $feature['feature_key']]);
            }

            $seen[$key] = true;
        }
    }

    /**
     * Le righe non cancellate di un modello, come `GestionaleResource::rowsOf()`.
     *
     * @param array<string, mixed> $where
     * @return list<array<string, mixed>>
     */
    private static function rows(string $modelClass, array $where = [], ?string $order = null): array
    {
        try {
            $rows = $modelClass::find(array_merge(['deleted' => 'false'], $where), null, $order, $order === null ? null : 'ASC');
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
