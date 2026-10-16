<?php

namespace Wonder\Plugin\Gestionale\Resources\Subscriptions;

use Throwable;
use Wonder\App\LegacyGlobals;
use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentTerm;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderPaymentResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderEditor;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OfficeSubscriptions;

/**
 * «Nuovo abbonamento» da ufficio (G10 §3, G10b): cliente, i servizi (una
 * riga per prezzo, con quantità, la riga a cui si appoggia e le
 * personalizzazioni del piano), data d'inizio, metodo e condizione di
 * pagamento (vuoti: quelli della scheda del cliente), coupon e nota. Ci si arriva dal pulsante dell'elenco
 * *Abbonamenti*; il lavoro lo fa `OfficeSubscriptions::create()`.
 */
final class OfficeSubscriptionResource extends NavigationOnlyResource
{
    public static function path(): string
    {
        return 'app/gestionale/nuovo-abbonamento';
    }

    public static function icon(): string
    {
        return 'bi-plus-circle';
    }

    public static function titleLabel(): string
    {
        return 'Nuovo abbonamento';
    }

    public static function isFormPage(): bool
    {
        return true;
    }

    public static function submitUrl(): string
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

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only([])
            ->titles(['form' => 'Nuovo abbonamento'])
            ->subtitles(['form' => 'Un abbonamento per un cliente, pagato con le rate della condizione di pagamento: a ogni periodo parte il proforma.']);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('vendite'), ['edit', 'update']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('vendite')
            ->title('Nuovo abbonamento')
            ->access(Gestionale::area('vendite'))
            ->enabled(false);
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('customer_id')->searchText(OfficeOrderEditor::customersUrl())
                ->emptyText('Nessun cliente trovato: crealo dai Contatti.')
                ->label('Cliente')->required(),
            FormField::key('items')
                ->repeater([
                    RepeaterColumn::key('plan_price_id')->select(['' => 'Scegli'] + static::priceOptions())->label('Piano e prezzo')->columnSpan(6),
                    RepeaterColumn::key('quantity')->number()->decimal(0)->value('1')->label('Quantità')->columnSpan(2),
                    RepeaterColumn::key('parent')->select(static::parentOptions())->label('Si appoggia a')->columnFill(),
                    ...array_map(
                        static fn (array $customization): RepeaterColumn => static::customizationColumn($customization),
                        static::customizationColumns()
                    ),
                ])
                ->repeaterSortable()
                ->repeaterAddLabel('Aggiungi servizio')
                // `visibleWhen` non vale dentro una riga: le personalizzazioni
                // stanno tutte nelle informazioni avanzate, ognuna dice per chi.
                ->repeaterAdvanced(...array_map(static fn (array $c): string => 'customization_'.$c['id'], static::customizationColumns()))
                ->repeaterAdvancedLabel('Personalizzazioni')
                ->repeaterDeleteTitle('Togli servizio')
                ->repeaterDeleteText('Togli questo servizio dall\'abbonamento?')
                ->repeaterDeleteCancelLabel('Annulla')
                ->repeaterDeleteConfirmLabel('Togli')
                ->repeaterDeleteConfirmClass('btn btn-danger')
                ->label(''),
            FormField::key('starts_at')->dateInput()->value(date('d/m/Y'))->label('Dal')->required(),
            FormField::key('payment_method_id')->select(['' => 'Quello del cliente'] + OrderPaymentResource::activeMethods())->label('Metodo di pagamento'),
            FormField::key('payment_term_id')->select(['' => 'Quella del cliente'] + static::termOptions())->label('Condizione di pagamento'),
            FormField::key('coupon')->text()->label('Coupon'),
            FormField::key('note')->textarea()->label('Note'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Abbonamento')
                        ->tooltip('Dal giorno scelto parte il primo periodo (dopo la prova, se il prezzo ne ha una): nascono le rate della condizione di pagamento e il cliente riceve il proforma. A ogni rinnovo lo stesso, finché non lo disdici.')
                        ->columnSpan(12),
                    static::getInput('customer_id')->columnSpan(6),
                    static::getInput('starts_at')->columnSpan(6),
                    static::getInput('payment_method_id')->columnSpan(4),
                    static::getInput('payment_term_id')->columnSpan(4),
                    static::getInput('coupon')->columnSpan(4),
                    static::getInput('note')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
                (new Card)->components([
                    SectionTitle::make('Servizi')
                        ->tooltip('Una riga per servizio: si rinnovano tutti alla stessa data, ognuno col suo intervallo. Un servizio che si appoggia a un altro (l\'hosting al dominio) dice a quale riga, contando dall\'alto. Le personalizzazioni stanno nelle informazioni avanzate della riga: fra parentesi i piani che le chiedono.')
                        ->columnSpan(12),
                    static::getInput('items')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ])->columns(12);
    }

    /**
     * @param array<string, mixed> $values
     * @return array{ok: bool, message: string, id: int}
     */
    public static function run(array $values, int $userId): array
    {
        if (!Gestionale::feature('subscriptions')) {
            return ['ok' => false, 'message' => 'Gli abbonamenti sono spenti: accendili in Funzionalità.', 'id' => 0];
        }

        try {
            $id = OfficeSubscriptions::create([
                'customer_id' => (int) ($values['customer_id'] ?? 0),
                'items' => static::itemsFrom($values['items'] ?? []),
                'starts_at' => OrderPaymentResource::dateFrom($values['starts_at'] ?? ''),
                'payment_method_id' => (int) ($values['payment_method_id'] ?? 0),
                'payment_term_id' => (int) ($values['payment_term_id'] ?? 0),
                'coupon' => trim((string) ($values['coupon'] ?? '')),
                'note' => (string) ($values['note'] ?? ''),
                'user_id' => $userId,
            ]);
        } catch (UserError $error) {
            return ['ok' => false, 'message' => $error->getMessage(), 'id' => 0];
        }

        return ['ok' => true, 'message' => 'Abbonamento creato: le rate del primo periodo sono nello Scadenzario e il proforma è partito.', 'id' => $id];
    }

    public static function submitFormPage(array $values): string
    {
        $user = LegacyGlobals::get('USER');
        $result = static::run($values, is_object($user) ? (int) ($user->id ?? 0) : 0);

        if (headers_sent()) {
            return $result['message'];
        }

        if ($result['ok']) {
            FlashAlert::saved($result['message']);
            header('Location: '.SubscriptionResource::detailUrl($result['id']));
        } else {
            FlashAlert::custom('Attenzione', $result['message'], 'warning');
            header('Location: '.static::submitUrl());
        }

        exit();
    }

    /** I prezzi attivi dei piani attivi, «Piano — Prezzo». @return array<int, string> */
    public static function priceOptions(): array
    {
        $options = [];

        foreach (static::rows(Plan::class, ['active' => 'true'], 'position') as $plan) {
            foreach (static::rows(PlanPrice::class, ['plan_id' => (int) $plan['id'], 'active' => 'true'], 'position') as $price) {
                $options[(int) $price['id']] = trim((string) $plan['name']).' — '.PlanResource::priceLabel($price);
            }
        }

        return $options;
    }

    /**
     * Le righe del repeater come le vuole `OfficeSubscriptions::create()`:
     * righe senza prezzo saltate, `parent` dal numero della riga (contando
     * solo quelle tenute) a indice, e solo le personalizzazioni del piano
     * della riga, compilate.
     *
     * @return list<array{plan_price_id: int, quantity: int, customization: array<int, string>, parent: int}>
     */
    public static function itemsFrom(mixed $rows): array
    {
        $items = [];
        $numbers = [];

        foreach (is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [] as $index => $row) {
            $priceId = (int) ($row['plan_price_id'] ?? 0);

            if ($priceId <= 0) {
                continue;
            }

            $numbers[$index + 1] = count($items);
            $price = PlanPrice::findById($priceId);
            $customization = [];

            foreach (Customizations::forPlan(is_array($price) ? (int) ($price['plan_id'] ?? 0) : 0) as $definition) {
                $value = trim((string) ($row['customization_'.$definition['id']] ?? ''));

                if ($value !== '') {
                    $customization[(int) $definition['id']] = $value;
                }
            }

            $items[] = [
                'plan_price_id' => $priceId,
                'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
                'customization' => $customization,
                'parent' => (int) ($row['parent'] ?? 0),
            ];
        }

        foreach ($items as $index => $item) {
            // Una riga indicata ma saltata (senza prezzo) non è una madre: -2 la fa rifiutare.
            $items[$index]['parent'] = $item['parent'] > 0 ? ($numbers[$item['parent']] ?? -2) : -1;
        }

        return $items;
    }

    /** «Nessuno» più «Riga 1» … «Riga 20». @return array<string, string> */
    public static function parentOptions(): array
    {
        $options = ['' => 'Nessuno'];

        for ($n = 1; $n <= 20; $n++) {
            $options[(string) $n] = 'Riga '.$n;
        }

        return $options;
    }

    /**
     * Le personalizzazioni «per gli abbonamenti» dei piani attivi, una volta
     * sola ciascuna, coi nomi dei piani che le chiedono.
     *
     * @return list<array<string, mixed>>
     */
    public static function customizationColumns(): array
    {
        $out = [];

        foreach (static::rows(Plan::class, ['active' => 'true'], 'position') as $plan) {
            foreach (Customizations::forPlan((int) $plan['id']) as $definition) {
                $id = (int) $definition['id'];
                $out[$id] ??= $definition + ['plans' => []];
                $out[$id]['plans'][] = trim((string) $plan['name']);
            }
        }

        return array_values($out);
    }

    /** @param array<string, mixed> $customization da {@see customizationColumns()} */
    private static function customizationColumn(array $customization): RepeaterColumn
    {
        $column = RepeaterColumn::key('customization_'.(int) $customization['id']);
        $column = $customization['kind'] === 'choice'
            ? $column->select(['' => 'Nessuna'] + array_column($customization['options'], 'label', 'id'))
            : ($customization['kind'] === 'number' ? $column->number()->decimal((int) $customization['decimals']) : $column->text());

        // Le colonne non hanno tooltip: per chi vale lo dice l'etichetta.
        return $column->label((string) $customization['label'].' ('.implode(', ', $customization['plans']).')')
            ->columnSpan(6);
    }

    /** Le condizioni di pagamento attive. @return array<int, string> */
    public static function termOptions(): array
    {
        $options = [];

        foreach (static::rows(PaymentTerm::class, ['active' => 'true'], 'position') as $term) {
            $options[(int) $term['id']] = (string) ($term['name'] ?? '');
        }

        return $options;
    }

    /**
     * @param class-string<\Wonder\App\Model> $model
     * @return list<array<string, mixed>>
     */
    private static function rows(string $model, array $where, ?string $order = null): array
    {
        try {
            $rows = $model::find(['deleted' => 'false'] + $where, null, $order, $order === null ? null : 'ASC');
        } catch (Throwable) {
            return [];
        }

        return is_array($rows) ? (isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'))) : [];
    }
}
