<?php

namespace Wonder\Plugin\Gestionale\Resources\Subscriptions;

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
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanFeature;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Features\FeatureGuards;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Positions;
use Wonder\Plugin\Gestionale\Support\Subscriptions\PlanPriceRules;
use Wonder\Plugin\Gestionale\Support\Subscriptions\Subscriptions;
use Wonder\Plugin\Gestionale\Support\Tax\TaxCategories;

/**
 * «Piani» (G10 §1.2): i piani di abbonamento con i loro prezzi e i limiti.
 *
 * I prezzi stanno in un repeater (nome, intervallo, mesi per addebito,
 * vincolo, prova, prezzo, prezzo scontato, rinnovo, stato), i limiti in un
 * altro, a coppie chiave/valore. Un prezzo usato da un abbonamento, anche
 * chiuso, non esce dal repeater: si spegne. Un piano con abbonamenti vivi non
 * si elimina.
 *
 * Il prezzo è IVA inclusa o esclusa come il listino base (G10.4); l'aliquota
 * viene dal tipo fiscale del piano.
 */
final class PlanResource extends GestionaleResource
{
    public static string $feature = 'subscriptions';
    public static string $model = Plan::class;
    public static string $orderColumn = 'position';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'vendite/vendite-abbonamenti';

    public static function path(): string
    {
        return 'app/gestionale/piani';
    }

    public static function icon(): string
    {
        return 'bi-layers';
    }

    public static function titleLabel(): string
    {
        return 'Piani';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'piano',
            'plural_label' => 'piani',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'full' => 'attivo',
            'empty' => 'spento',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'name' => 'Nome',
            'description' => 'Descrizione',
            'tax_category_id' => 'Tipo fiscale',
            'visible_online' => 'Sul sito',
            'active' => 'Stato',
            'prices' => 'Prezzi',
            'features' => 'Limiti',
        ];
    }

    /** @return array<string, string> */
    public static function intervalOptions(): array
    {
        return ['month' => 'Mese', 'year' => 'Anno'];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('description')->textarea()->label('Descrizione'),
            FormField::key('tax_category_id')
                ->select(['' => "Nessuno: vale l'aliquota di ripiego"] + TaxCategories::options())
                ->label('Tipo fiscale'),
            FormField::key('visible_online')
                ->select(['true' => 'Visibile', 'false' => 'Nascosto'])
                ->value('true')
                ->label('Sul sito'),
            FormField::key('active')
                ->select(['true' => 'Attivo', 'false' => 'Spento'])
                ->value('true')
                ->label('Stato'),
            FormField::key('prices')
                ->repeater([
                    RepeaterColumn::key('id')->hidden(),
                    RepeaterColumn::key('name')->text()->label('Nome')->columnSpan(3),
                    RepeaterColumn::key('billing_interval')->select(static::intervalOptions())->label('Ogni')->columnSpan(2),
                    RepeaterColumn::key('term_months')->number()->decimal(0)->label('Mesi per addebito')->columnSpan(2),
                    RepeaterColumn::key('price')->number()->decimal(2)->label('Prezzo')->columnSpan(2),
                    RepeaterColumn::key('sale_price')->number()->decimal(2)->label('Prezzo scontato')->columnFill(),
                    RepeaterColumn::key('commitment_months')->number()->decimal(0)->label('Vincolo (mesi)')->columnSpan(3),
                    RepeaterColumn::key('trial_days')->number()->decimal(0)->label('Prova (giorni)')->columnSpan(2),
                    RepeaterColumn::key('renewal_plan_price_id')
                        ->select(['0' => 'Nessuno'] + static::renewalOptions())
                        ->label('A fine vincolo passa a')
                        ->columnSpan(4),
                    RepeaterColumn::key('active')->select(['true' => 'Attivo', 'false' => 'Spento'])->label('Stato')->columnFill(),
                ])
                ->relation(
                    RepeaterRelation::make(PlanPrice::$table, 'plan_id')
                        ->model(PlanPrice::class)
                        ->positionKey('position')
                )
                ->nested()
                ->repeaterSortable()
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
                    RepeaterRelation::make(PlanFeature::$table, 'plan_id')
                        ->model(PlanFeature::class)
                        ->positionKey('position')
                        ->softDelete(false)
                )
                ->nested()
                ->repeaterSortable()
                ->repeaterAddLabel('Aggiungi limite')
                ->repeaterDeleteTitle('Elimina limite')
                ->repeaterDeleteText('Confermi l\'eliminazione di questo limite?')
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
                    SectionTitle::make('Piano')
                        ->tooltip('Il nome e la descrizione li vede il cliente nella pagina dei piani. Il tipo fiscale dà l\'aliquota (solo IVA italiana). «Nascosto» toglie il piano dalla pagina pubblica ma lo lascia agli abbonati e all\'ufficio; «Spento» non si propone più a nessuno.')
                        ->columnSpan(12),
                    static::getInput('name')->columnSpan(6),
                    static::getInput('tax_category_id')->columnSpan(6),
                    static::getInput('description')->columnSpan(12),
                    static::getInput('visible_online')->columnSpan(6),
                    static::getInput('active')->columnSpan(6),
                ])->columns(12)->columnSpan(12),
                (new Card)->components([
                    SectionTitle::make('Prezzi')
                        ->tooltip('Un prezzo per riga: «Mensile» ogni mese con 1 mese per addebito, «Annuale» ogni anno con 12. Il prezzo è IVA inclusa o esclusa come il listino; il prezzo scontato, se più basso, vince. Vincolo zero vuol dire nessun vincolo. «A fine vincolo passa a» propone solo i prezzi già salvati: per un prezzo nuovo, salva e poi sceglilo. Un prezzo cambiato vale per i nuovi abbonati.')
                        ->columnSpan(12),
                    static::getInput('prices')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
                (new Card)->components([
                    SectionTitle::make('Limiti')
                        ->tooltip('Quello che il piano permette sul sito, a coppie: la chiave la decide chi ha fatto il sito (ad esempio «max_progetti»), il valore è un numero o una parola («5», «illimitato»). Il sito li controlla quando il cliente crea qualcosa; passare a un piano più basso non toglie niente.')
                        ->columnSpan(12),
                    static::getInput('features')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('prices')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::pricesSummary((int) ($row['id'] ?? 0)))),
            TableColumn::key('visible_online')
                ->booleanBadge()
                ->badgeOn('Visibile', 'bi-globe', 'primary')
                ->badgeOff('Nascosto', 'bi-eye-slash', 'secondary')
                ->size('little'),
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Attivo', 'bi-check-circle', 'success')
                ->badgeOff('Spento', 'bi-dash-circle', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Piani',
                'create' => 'Nuovo piano',
                'edit' => 'Modifica piano',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('vendite', 'Vendite', 'bi-receipt', 300, ['admin', 'administrator'])
            ->title('Piani')
            ->order(60)
            ->authority(['admin', 'administrator'])
            ->enabled(static::featureActive());
    }

    /** «Mensile 19,00 € · Annuale 190,00 €», solo i prezzi attivi. */
    public static function pricesSummary(int $planId): string
    {
        $parts = [];

        foreach (static::rowsOf(PlanPrice::class, ['plan_id' => $planId, 'active' => 'true'], 'position') as $price) {
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
     * I prezzi che possono fare da rinnovo: quelli salvati dei piani attivi,
     * «Piano — Prezzo».
     *
     * @return array<string, string>
     */
    public static function renewalOptions(): array
    {
        $options = [];

        foreach (static::rowsOf(Plan::class, ['active' => 'true'], 'position') as $plan) {
            foreach (static::rowsOf(PlanPrice::class, ['plan_id' => (int) $plan['id']], 'position') as $price) {
                $options[(string) $price['id']] = trim((string) $plan['name']).' — '.trim((string) $price['name']);
            }
        }

        return $options;
    }

    /**
     * Prima di scrivere: un nome, almeno un prezzo, ogni prezzo in regola,
     * nessun prezzo in uso tolto, nessuna chiave dei limiti ripetuta. Slug e
     * posizione alla creazione.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        if (trim((string) ($values['name'] ?? '')) === '') {
            throw UserError::make('plan.name_required');
        }

        $planId = (int) ($oldValues['id'] ?? 0);
        $prices = static::prepareRepeaterRows('prices', Repeater::rowsFromRequest('prices', (array) $_POST), $action);

        if ($prices === []) {
            throw UserError::make('plan.no_prices');
        }

        $renewalIds = array_map('intval', array_keys(static::renewalOptions()));

        foreach ($prices as $price) {
            PlanPriceRules::assert($price, $renewalIds);
        }

        static::assertKeptPrices($planId, $prices);
        static::assertFeatures(static::prepareRepeaterRows('features', Repeater::rowsFromRequest('features', (array) $_POST), $action));

        $values['tax_category_id'] = (int) ($values['tax_category_id'] ?? 0) > 0 ? (int) $values['tax_category_id'] : '';

        if ($action === 'store') {
            $values['slug'] = Slug::make((string) $values['name'], Plan::$table);
            $values['position'] = Positions::next(Plan::$table);
        } else {
            unset($values['slug'], $values['position']);
        }

        return $values;
    }

    /**
     * Le righe vuote non sono prezzi né limiti; le altre si scrivono come le
     * regole le hanno lette: numeri col punto, mesi interi, interruttori sì o no.
     */
    public static function prepareRepeaterRows(
        string $inputName,
        array $rows,
        string $action = 'store',
        string $context = 'backend'
    ): array {
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

            if ($inputName !== 'prices') {
                $clean[] = $row;

                continue;
            }

            if (trim((string) ($row['name'] ?? '')) === '' && trim((string) ($row['price'] ?? '')) === '') {
                continue;
            }

            $interval = in_array((string) ($row['billing_interval'] ?? ''), PlanPrice::INTERVALS, true) ? (string) $row['billing_interval'] : 'month';
            $term = trim((string) ($row['term_months'] ?? ''));
            $row['name'] = trim((string) ($row['name'] ?? ''));
            $row['billing_interval'] = $interval;
            $row['term_months'] = $term === '' ? PlanPriceRules::defaultTerm($interval) : (int) $term;
            $row['price'] = Numbers::fromForm($row['price'] ?? null) ?? '';
            $row['sale_price'] = Numbers::fromForm($row['sale_price'] ?? null) ?? '0.00';
            $row['commitment_months'] = (int) trim((string) ($row['commitment_months'] ?? '0'));
            $row['trial_days'] = (int) trim((string) ($row['trial_days'] ?? '0'));
            $row['renewal_plan_price_id'] = max(0, (int) ($row['renewal_plan_price_id'] ?? 0));
            $row['active'] = ($row['active'] ?? 'true') === 'false' ? 'false' : 'true';
            $clean[] = $row;
        }

        return $clean;
    }

    /** Un piano con abbonamenti vivi non si elimina: si spegne. */
    public static function assertDeletable(int|string $id): void
    {
        $count = Subscriptions::liveCount(Subscriptions::priceIds((int) $id));

        if ($count > 0) {
            throw UserError::refusal('plan.in_use', ['count' => FeatureGuards::subscriptionsLabel($count)]);
        }
    }

    /**
     * I prezzi già salvati che non tornano nel repeater e che un abbonamento
     * usa: il salvataggio si rifiuta, con il nome del primo.
     *
     * @param list<array<string, mixed>> $submitted
     */
    protected static function assertKeptPrices(int $planId, array $submitted): void
    {
        if ($planId <= 0) {
            return;
        }

        $kept = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $submitted);
        $removed = array_values(array_diff(Subscriptions::priceIds($planId), $kept));
        $used = Subscriptions::usedPrices($removed);

        if ($used !== []) {
            $price = PlanPrice::findById($used[0]);

            throw UserError::make('plan.price_in_use', ['name' => is_array($price) ? (string) ($price['name'] ?? '') : '#'.$used[0]]);
        }
    }

    /** @param list<array<string, mixed>> $features */
    protected static function assertFeatures(array $features): void
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
}
