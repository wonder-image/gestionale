<?php

namespace Wonder\Plugin\Gestionale\Resources\Shipping;

use Throwable;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Alert;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRate;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRateBracket;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZone;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Positions;
use Wonder\Plugin\Gestionale\Support\Sales\Channels;
use Wonder\Plugin\Gestionale\Support\Shipping\Carriers;
use Wonder\Plugin\Gestionale\Support\Shipping\RateForm;
use Wonder\Sql\Transaction;

/**
 * «Metodi di spedizione»: Standard, Espresso… Il metodo ha il nome e i tempi
 * che il cliente vede; il prezzo sta nei **listini**, uno per zona, che si
 * scrivono nella stessa pagina: un riquadro per ogni zona, con le sue
 * regole e la tabella degli scaglioni di peso.
 *
 * Il core non annida i repeater, quindi i campi dei listini sono piatti
 * (`rate_<zona>_<campo>`) e li legge, controlla e scrive `RateForm`.
 */
final class ShippingMethodResource extends GestionaleResource
{
    public static string $feature = 'shipping';
    public static string $model = ShippingMethod::class;
    public static string $orderColumn = 'position';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'spedizioni/spedizioni-listini';

    public static function path(): string
    {
        return 'app/gestionale/metodi-di-spedizione';
    }

    public static function icon(): string
    {
        return 'bi-box-seam';
    }

    public static function titleLabel(): string
    {
        return 'Metodi di spedizione';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'metodo',
            'plural_label' => 'metodi',
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
            'description' => 'Tempi di consegna',
            'carrier_id' => 'Corriere',
            'provider_service_code' => 'Codice del servizio',
            'applies_online' => 'Sito',
            'applies_office' => 'Ufficio',
            'active' => 'Stato',
            'zones' => 'Zone',
        ];
    }

    public static function formSchema(): array
    {
        $fields = [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('description')->text()->label('Tempi di consegna'),
            FormField::key('carrier_id')
                ->select(['0' => '—'] + array_map('strval', static::carrierOptions()))
                ->value('0')
                ->label('Corriere'),
            FormField::key('provider_service_code')->text()->label('Codice del servizio'),
            FormField::key('applies_online')->toggle()->value(Channels::defaults()['applies_online'])->label('Sito'),
            FormField::key('applies_office')->toggle()->value(Channels::defaults()['applies_office'])->label('Ufficio'),
            FormField::key('active')
                ->select(['true' => 'Attivo', 'false' => 'Non attivo'])
                ->value('true')
                ->label('Stato'),
        ];

        foreach (static::zones() as $zone) {
            array_push($fields, ...static::rateFields((int) $zone['id']));
        }

        return $fields;
    }

    /**
     * I campi del listino di una zona. Tutti, tranne l'interruttore, si vedono
     * solo a listino acceso.
     *
     * @return list<object>
     */
    protected static function rateFields(int $zone): array
    {
        $name = static fn (string $field): string => 'rate_'.$zone.'_'.$field;
        $on = static fn (object $input): object => $input->visibleWhen('rate_'.$zone.'_on', 'true');

        return [
            FormField::key($name('on'))->toggle()->value('false')->label('Spedisce verso questa zona'),
            $on(FormField::key($name('excess_mode'))
                ->select(['total_weight' => 'Tutto il peso', 'excess_only' => 'Solo l\'eccedenza'])
                ->value('total_weight')
                ->label('Oltre lo scaglione più alto, la tariffa al kg si applica a')),
            $on(FormField::key($name('volumetric_divisor'))->number()->integer()->label('Divisore volumetrico')),
            $on(FormField::key($name('fuel_surcharge_percent'))->number()->decimal(2)->suffix(' %')->value('0')->label('Maggiorazione carburante')),
            $on(FormField::key($name('markup_percent'))->number()->decimal(2)->suffix(' %')->value('0')->label('Margine')),
            $on(FormField::key($name('rounding_step'))->price()->decimal(2)->label('Arrotonda al')),
            $on(FormField::key($name('min_price'))->price()->decimal(2)->value('0')->label('Prezzo minimo')),
            $on(FormField::key($name('free_over_amount'))->price()->decimal(2)->label('Gratis sopra')),
            $on(FormField::key($name('free_under_weight'))->number()->decimal(3)->suffix(' kg')->label('Gratis fino a')),
            $on(FormField::key($name('cod_fee'))->price()->decimal(2)->value('0')->label('Commissione contrassegno')),
            $on(FormField::key($name('brackets'))
                ->repeater([
                    RepeaterColumn::key('type')
                        ->select(['price' => 'Prezzo fino a', 'excess' => 'Tariffa al kg oltre'])
                        ->value('price')
                        ->label('Scaglione')
                        ->columnSpan(5),
                    RepeaterColumn::key('max_weight')
                        ->number()
                        ->decimal(3)
                        ->suffix(' kg')
                        ->label('Peso massimo')
                        ->columnSpan(3),
                    RepeaterColumn::key('amount')->price()->decimal(2)->label('Importo')->columnFill(),
                ])
                ->nested()
                ->repeaterAddLabel('Aggiungi scaglione')
                ->repeaterDeleteTitle('Togli scaglione')
                ->repeaterDeleteText('Confermi di togliere questo scaglione dal listino?')
                ->repeaterDeleteCancelLabel('Annulla')
                ->repeaterDeleteConfirmLabel('Togli')
                ->repeaterDeleteConfirmClass('btn btn-danger')
                ->label('Scaglioni')),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        $left = [];
        $zones = static::zones();
        $unweighted = RateForm::unweighted();

        if ($unweighted > 0) {
            $left[] = (new Container)->components([
                Alert::make(
                    ($unweighted === 1 ? 'Un articolo da spedire non ha il peso' : $unweighted.' articoli da spedire non hanno il peso')
                    .': per '.($unweighted === 1 ? 'lui' : 'loro').' il prezzo è quello dello scaglione più basso.',
                    'warning'
                ),
            ])->columnSpan(12);
        }

        $left[] = (new Card)->components([
            SectionTitle::make('Metodo')
                ->tooltip('Il nome e i tempi li vede il cliente («Standard», «2-3 giorni lavorativi»). Il codice del servizio è quello del corriere, per te. Un metodo non attivo non si propone più.')
                ->columnSpan(12),
            static::getInput('name')->columnSpan(5),
            static::getInput('description')->columnSpan(4),
            static::getInput('active')->columnSpan(3),
            static::getInput('carrier_id')->columnSpan(6),
            static::getInput('provider_service_code')->columnSpan(6),
        ])->columns(12)->columnSpan(12);

        if ($zones === []) {
            $left[] = (new Container)->components([
                Alert::make('Non c\'è ancora nessuna zona: creane una dalla pagina «Zone», poi torna qui a scrivere i listini.', 'info'),
            ])->columnSpan(12);
        }

        foreach ($zones as $zone) {
            $id = (int) $zone['id'];
            $name = static fn (string $field): string => 'rate_'.$id.'_'.$field;

            $left[] = (new Card)->components([
                SectionTitle::make((string) ($zone['name'] ?? ''))
                    ->tooltip('Il listino di questa zona. Il prezzo è quello dello scaglione che copre il peso del carrello; oltre l\'ultimo vale la tariffa al kg. Poi si aggiungono carburante e margine, si arrotonda e si applica il minimo. Il peso conta il maggiore tra reale e volumetrico (lunghezza × larghezza × altezza ÷ divisore). Con «Gratis sopra» la spedizione è gratuita sopra quell\'importo di prodotti, con «Gratis fino a» sotto quel peso. Spento, il listino resta salvato.')
                    ->columnSpan(12),
                static::getInput($name('on'))->columnSpan(12),
                static::getInput($name('brackets'))->columnSpan(12),
                static::getInput($name('excess_mode'))->columnSpan(12),
                static::getInput($name('min_price'))->columnSpan(3),
                static::getInput($name('rounding_step'))->columnSpan(3),
                static::getInput($name('fuel_surcharge_percent'))->columnSpan(3),
                static::getInput($name('markup_percent'))->columnSpan(3),
                static::getInput($name('free_over_amount'))->columnSpan(3),
                static::getInput($name('free_under_weight'))->columnSpan(3),
                static::getInput($name('cod_fee'))->columnSpan(3),
                static::getInput($name('volumetric_divisor'))->columnSpan(3),
            ])->columns(12)->columnSpan(12);
        }

        $side = [];

        // «Dove vale» c'è solo se il sito ha più di un canale acceso.
        if (Channels::choose()) {
            $side[] = (new Card)->components([
                SectionTitle::make('Dove vale')
                    ->tooltip('Il sito offre il metodo nel carrello. L\'ufficio lo offre negli ordini fatti dal gestionale.')
                    ->columnSpan(12),
                static::getInput('applies_online')->columnSpan(12),
                static::getInput('applies_office')->columnSpan(12),
            ])->columns(12)->columnSpan(12);
        }

        return (new Form)->components($side === [] ? [
            (new Container)->components($left)->columns(12)->columnSpan(12),
        ] : [
            (new Container)->components($left)->columns(12)->columnSpan(8),
            (new Container)->components($side)->columns(12)->columnSpan(4),
        ])->columns(12);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('carrier_id')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::carrierOptions()[(int) ($row['carrier_id'] ?? 0)] ?? '—')),
            TableColumn::key('zones')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::zonesLabel((int) ($row['id'] ?? 0)))),
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Attivo', 'bi-check-circle', 'success')
                ->badgeOff('Non attivo', 'bi-dash-circle', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Metodi di spedizione',
                'create' => 'Nuovo metodo',
                'edit' => 'Modifica metodo',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'administrator']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()
            ->section('spedizioni', 'Spedizioni', 'bi-truck', 380, ['admin', 'administrator'])
            ->title('Metodi')
            ->order(10)
            ->authority(['admin', 'administrator']);
    }

    /** I nomi delle zone con un listino acceso, per l'elenco. */
    public static function zonesLabel(int $methodId): string
    {
        $names = [];

        foreach (static::rowsOf(ShippingRate::class, ['shipping_method_id' => $methodId, 'active' => 'true']) as $rate) {
            $zone = ShippingZone::findById((int) $rate['shipping_zone_id']);

            if (is_array($zone) && ($zone['deleted'] ?? 'false') !== 'true') {
                $names[] = (string) ($zone['name'] ?? '');
            }
        }

        return $names === [] ? '—' : implode(', ', $names);
    }

    /**
     * I listini si leggono e si controllano qui, prima che si scriva qualcosa:
     * un listino che non sta in piedi ferma il salvataggio con la sua frase.
     * Nessun campo `rate_*` è una colonna: li scrive `afterStore`/`afterUpdate`.
     * La posizione la mette il backend, in fondo all'elenco.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        $values = Channels::keepHidden($values, $oldValues);
        // Il metodo non è offerto alla cassa: la colonna non esiste.
        unset($values['applies_pos']);
        $values['carrier_id'] = max(0, (int) ($values['carrier_id'] ?? 0));

        foreach (array_keys($values) as $key) {
            if (str_starts_with((string) $key, 'rate_')) {
                unset($values[$key]);
            }
        }

        $names = [];

        foreach (static::zones() as $zone) {
            $names[(int) $zone['id']] = (string) ($zone['name'] ?? '');
        }

        $rates = RateForm::readRates((array) $_POST);
        // Le zone che non esistono (più) si ignorano: non si controllano.
        RateForm::validate(array_intersect_key($rates, $names), $names);

        if ($action === 'store') {
            $values['position'] = Positions::next(ShippingMethod::$table);
        } else {
            unset($values['position']);
        }

        return $values;
    }

    /** Riempie il form con i listini salvati. */
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        $id = (int) ($values['id'] ?? 0);

        if ($mode === 'edit' && $id > 0) {
            $values = RateForm::loadRates($id) + $values;
        }

        return $values;
    }

    public static function afterStore(object $result, array $values = []): void
    {
        $id = (int) ($result->insert_id ?? 0);

        if ($id > 0) {
            RateForm::saveRates($id, RateForm::readRates((array) $_POST));
        }
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        RateForm::saveRates((int) $id, RateForm::readRates((array) $_POST));
    }

    /** Un metodo già su ordini o carrelli si spegne, non si elimina. */
    public static function assertDeletable(int|string $id): void
    {
        $count = count(static::rowsOf(Order::class, ['shipping_method_id' => (int) $id]));

        if ($count > 0) {
            // `refusal()` e non `make()`: chi cancella dall'elenco intercetta `RuntimeException`.
            throw UserError::refusal('shipping.method_in_use', ['count' => $count]);
        }
    }

    /** I listini e i loro scaglioni se ne vanno con il metodo: la chiave esterna non lascerebbe eliminarlo. */
    public static function deleteRecord(int|string $id): object
    {
        $id = (int) $id;

        static::assertDeletable($id);

        $result = null;

        Transaction::run(static function () use ($id, &$result): void {
            foreach (static::rowsOf(ShippingRate::class, ['shipping_method_id' => $id, 'deleted' => ['true', 'false']]) as $rate) {
                foreach (static::rowsOf(ShippingRateBracket::class, ['shipping_rate_id' => (int) $rate['id'], 'deleted' => ['true', 'false']]) as $bracket) {
                    ShippingRateBracket::delete((int) $bracket['id']);
                }

                ShippingRate::delete((int) $rate['id']);
            }

            $result = ShippingMethod::delete($id);
        });

        return is_object($result) ? $result : (object) ['success' => true, 'table' => ShippingMethod::$table, 'id' => $id];
    }

    /**
     * I corrieri attivi, id => nome; senza database (o senza tabella) nessuno.
     *
     * @return array<int, string>
     */
    protected static function carrierOptions(): array
    {
        try {
            return Carriers::options();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    protected static function zones(): array
    {
        return static::rowsOf(ShippingZone::class, [], 'position');
    }
}
