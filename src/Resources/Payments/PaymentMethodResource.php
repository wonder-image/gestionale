<?php

namespace Wonder\Plugin\Gestionale\Resources\Payments;

use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentAccount;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Positions;
use Wonder\Plugin\Gestionale\Support\Sales\Channels;

/**
 * «Metodi di pagamento»: come si paga nel negozio — bonifico, carta, contanti
 * al ritiro. Il metodo dice quando arriva il denaro, quanto costa e a quali
 * consegne si offre; il conto dice dove arrivano i bonifici.
 */
final class PaymentMethodResource extends GestionaleResource
{
    public static string $model = PaymentMethod::class;
    public static string $orderColumn = 'position';
    public static string $orderDirection = 'ASC';

    public static function path(): string
    {
        return 'app/gestionale/metodi-di-pagamento';
    }

    public static function icon(): string
    {
        return 'bi-credit-card';
    }

    public static function titleLabel(): string
    {
        return 'Metodi di pagamento';
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
            'code' => 'Codice',
            'name' => 'Nome',
            'provider' => 'Tipo',
            'timing' => 'Quando arriva il denaro',
            'payment_account_id' => 'Conto',
            'fee_type' => 'Commissione',
            'fee_value' => 'Valore',
            'available_for' => 'Disponibile per',
            'applies_online' => 'Online',
            'applies_office' => 'In ufficio',
            'applies_pos' => 'Al banco',
            'instructions' => 'Istruzioni',
            'sdi_code' => 'Codice SDI',
            'active' => 'Stato',
        ];
    }

    /** @return array<string, string> */
    public static function providers(): array
    {
        return ['manual' => 'A mano (bonifico, contanti)', 'stripe' => 'Stripe', 'paypal' => 'PayPal', 'nexi' => 'Nexi'];
    }

    /** @return array<string, string> */
    public static function timings(): array
    {
        return ['immediate' => 'Subito', 'deferred' => 'A termine', 'on_delivery' => 'Alla consegna'];
    }

    /** @return array<string, string> */
    public static function feeTypes(): array
    {
        return ['none' => 'Nessuna', 'amount' => 'Importo fisso', 'percent' => 'Percentuale'];
    }

    /** @return array<string, string> */
    public static function availableFor(): array
    {
        return ['all' => 'Tutte le consegne', 'shipping' => 'Solo spedizione', 'pickup' => 'Solo ritiro'];
    }

    public static function formSchema(): array
    {
        $conti = ['0' => 'Nessun conto'];

        foreach (static::rowsOf(PaymentAccount::class, [], 'name') as $conto) {
            $conti[(string) $conto['id']] = (string) ($conto['name'] ?? '');
        }

        $siNo = ['true' => 'Sì', 'false' => 'No'];

        return [
            FormField::key('code')->text()->label('Codice')->required(),
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('provider')->select(static::providers())->value('manual')->label('Tipo'),
            FormField::key('timing')->select(static::timings())->value('immediate')->label('Quando arriva il denaro'),
            FormField::key('payment_account_id')->select($conti)->value('0')->label('Conto'),
            FormField::key('fee_type')->select(static::feeTypes())->value('none')->label('Commissione'),
            FormField::key('fee_value')->number()->decimal(2)->value('0')->label('Valore'),
            FormField::key('available_for')->select(static::availableFor())->value('all')->label('Disponibile per'),
            FormField::key('applies_online')->select($siNo)->value(Channels::defaults()['applies_online'])->label('Online'),
            FormField::key('applies_office')->select($siNo)->value(Channels::defaults()['applies_office'])->label('In ufficio'),
            FormField::key('applies_pos')->select($siNo)->value(Channels::defaults()['applies_pos'])->label('Al banco'),
            FormField::key('instructions')->textarea()->label('Istruzioni'),
            FormField::key('sdi_code')->text()->label('Codice SDI'),
            FormField::key('active')->select(['true' => 'Attivo', 'false' => 'Non attivo'])->value('true')->label('Stato'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Metodo')
                        ->tooltip('Il codice si scrive una volta e non cambia più, per esempio «bonifico». Il tipo «A mano» vale per bonifico e contanti: l\'incasso lo registri tu dalla scheda dell\'ordine. Un metodo non attivo non si propone più, ma resta sugli ordini che lo hanno usato.')
                        ->columnSpan(12),
                    static::getInput('code')->columnSpan(3),
                    static::getInput('name')->columnSpan(5),
                    static::getInput('active')->columnSpan(4),
                    static::getInput('provider')->columnSpan(4),
                    static::getInput('timing')->columnSpan(4),
                    static::getInput('payment_account_id')->columnSpan(4),
                ])->columns(12)->columnSpan(12),
                (new Card)->components([
                    SectionTitle::make('Dove e quanto')
                        ->tooltip('La commissione diventa una riga dell\'ordine, con l\'aliquota della spedizione. Con «Solo ritiro» il metodo si offre solo a chi ritira in sede, con «Solo spedizione» solo a chi si fa spedire.')
                        ->columnSpan(12),
                    static::getInput('available_for')->columnSpan(4),
                    static::getInput('fee_type')->columnSpan(4),
                    static::getInput('fee_value')->columnSpan(4),
                    // I canali si scelgono solo se ce n'è più di uno acceso.
                    ...(Channels::choose() ? array_map(
                        static fn (string $channel) => static::getInput(Channels::column($channel))->columnSpan(4),
                        Channels::active()
                    ) : []),
                ])->columns(12)->columnSpan(12),
                (new Card)->components([
                    SectionTitle::make('Istruzioni e fattura')
                        ->tooltip('Le istruzioni arrivano al cliente nell\'email dell\'ordine. Per il bonifico non scrivere l\'IBAN: lo compone l\'email dal conto scelto. Il codice SDI è quello della fattura elettronica (MP01–MP23).')
                        ->columnSpan(12),
                    static::getInput('instructions')->columnSpan(9),
                    static::getInput('sdi_code')->columnSpan(3),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('code')->text(),
            TableColumn::key('provider')->text()->formatter(static fn (array $row): string => static::escape(static::providers()[(string) ($row['provider'] ?? '')] ?? '—')),
            TableColumn::key('timing')->text()->formatter(static fn (array $row): string => static::escape(static::timings()[(string) ($row['timing'] ?? '')] ?? '—')),
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
                'list' => 'Metodi di pagamento',
                'create' => 'Nuovo metodo',
                'edit' => 'Modifica metodo',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('set-up')
            ->group('pagamenti', 'Pagamenti', 60, ['admin'])
            ->title('Metodi di pagamento')
            ->order(10)
            ->authority(['admin']);
    }

    /** La posizione la mette il backend, in fondo all'elenco. */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        $values = Channels::keepHidden($values, $oldValues);

        if ($action === 'store') {
            $values['position'] = Positions::next(PaymentMethod::$table);
        } else {
            unset($values['position']);
        }

        return $values;
    }

    /** Un metodo già su ordini o pagamenti non si elimina: si spegne. */
    public static function assertDeletable(int|string $id): void
    {
        $id = (int) $id;
        $usi = (int) sqlCount(Order::$table, "payment_method_id = {$id} AND deleted = 'false'")
            + (int) sqlCount(Payment::$table, "payment_method_id = {$id} AND deleted = 'false'");

        if ($usi > 0) {
            throw UserError::refusal('payment_method.in_use', ['count' => $usi]);
        }
    }
}
