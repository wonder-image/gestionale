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
use Wonder\Plugin\Custom\Fattura\Valori\Pagamento;
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
            'fee_value' => 'Importo (€)',
            'fee_percent' => 'Percentuale (%)',
            'available_for' => 'Disponibile per',
            'applies_online' => 'Online',
            'applies_office' => 'In ufficio',
            'applies_pos' => 'Al banco',
            'instructions' => 'Istruzioni',
            'icons' => 'Icone',
            'sdi_code' => 'Modalità di pagamento in fattura',
            'active' => 'Stato',
        ];
    }

    /** @return array<string, string> */
    public static function providers(): array
    {
        return [
            'bank_transfer' => 'Bonifico bancario',
            'cash' => 'Contanti',
            'stripe' => 'Stripe',
            'paypal' => 'PayPal',
            'nexi' => 'Nexi',
        ];
    }

    /** @return array<string, string> */
    public static function timings(): array
    {
        return [
            'immediate' => 'Subito, al momento dell\'ordine',
            'deferred' => 'Dopo l\'ordine, entro qualche giorno',
            'on_delivery' => 'Alla consegna o al ritiro',
        ];
    }

    /** @return array<string, string> */
    public static function feeTypes(): array
    {
        return [
            'none' => 'Nessuna',
            'amount' => 'Importo fisso',
            'percent' => 'Percentuale',
            'amount_percent' => 'Importo fisso + percentuale',
        ];
    }

    /** @return array<string, string> */
    public static function availableFor(): array
    {
        return ['all' => 'Tutte le consegne', 'shipping' => 'Solo spedizione', 'pickup' => 'Solo ritiro'];
    }

    /**
     * Le modalità di pagamento della fattura elettronica, «MP05 - Bonifico».
     *
     * @return array<string, string>
     */
    public static function sdiCodes(): array
    {
        $codes = ['' => 'Non indicata'];

        foreach (Pagamento::Valori as $code => $name) {
            $codes[$code] = $code.' - '.$name;
        }

        return $codes;
    }

    public static function formSchema(): array
    {
        $conti = ['0' => 'Nessun conto'];

        foreach (static::rowsOf(PaymentAccount::class, [], 'name') as $conto) {
            $conti[(string) $conto['id']] = (string) ($conto['name'] ?? '');
        }

        $siNo = ['true' => 'Sì', 'false' => 'No'];

        return [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('provider')->select(static::providers())->value('bank_transfer')->label('Tipo'),
            FormField::key('timing')->select(static::timings())->value('deferred')->label('Quando arriva il denaro'),
            FormField::key('payment_account_id')->select($conti)->value('0')->label('Conto')->visibleWhen('provider', 'bank_transfer'),
            FormField::key('fee_type')->select(static::feeTypes())->value('none')->label('Commissione'),
            FormField::key('fee_value')->number()->decimal(2)->value('0')->label('Importo (€)')->visibleWhen('fee_type', ['amount', 'amount_percent']),
            FormField::key('fee_percent')->number()->decimal(2)->value('0')->label('Percentuale (%)')->visibleWhen('fee_type', ['percent', 'amount_percent']),
            FormField::key('available_for')->select(static::availableFor())->value('all')->label('Disponibile per'),
            FormField::key('applies_online')->select($siNo)->value(Channels::defaults()['applies_online'])->label('Online'),
            FormField::key('applies_office')->select($siNo)->value(Channels::defaults()['applies_office'])->label('In ufficio'),
            FormField::key('applies_pos')->select($siNo)->value(Channels::defaults()['applies_pos'])->label('Al banco'),
            FormField::key('instructions')->textarea()->label('Istruzioni'),
            FormField::key('icons')->selectSearch(PaymentMethod::ICONS, true)->label('Icone'),
            FormField::key('sdi_code')->select(static::sdiCodes())->value('')->label('Modalità di pagamento in fattura'),
            FormField::key('active')->select(['true' => 'Attivo', 'false' => 'Non attivo'])->value('true')->label('Stato'),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Metodo')
                        ->tooltip('Bonifico e contanti li incassi tu: lo registri dalla scheda dell\'ordine. Stripe, PayPal e Nexi incassano da soli. «Quando arriva il denaro» decide quanto aspetta la merce: «Subito» è la carta, e la merce resta prenotata pochi minuti; «Dopo l\'ordine, entro qualche giorno» è il bonifico, e la merce resta prenotata per i giorni di attesa delle Impostazioni, poi l\'ordine si annulla; «Alla consegna o al ritiro» è il contrassegno o il pagamento in negozio, e la merce non scade. Il conto serve al bonifico: finisce nell\'email al cliente. Un metodo non attivo non si propone più, ma resta sugli ordini che lo hanno usato.')
                        ->columnSpan(12),
                    static::getInput('name')->columnSpan(5),
                    static::getInput('provider')->columnSpan(4),
                    static::getInput('active')->columnSpan(3),
                    static::getInput('timing')->columnSpan(6),
                    static::getInput('payment_account_id')->columnSpan(6),
                ])->columns(12)->columnSpan(12),
                (new Card)->components([
                    SectionTitle::make('Dove e quanto')
                        ->tooltip('La commissione diventa una riga dell\'ordine, con l\'aliquota della spedizione. Può essere un importo fisso, una percentuale sul totale dei prodotti, o tutte e due (per esempio 0,25 € + 1,4 %). Con «Solo ritiro» il metodo si offre solo a chi ritira in sede, con «Solo spedizione» solo a chi si fa spedire.')
                        ->columnSpan(12),
                    static::getInput('available_for')->columnSpan(3),
                    static::getInput('fee_type')->columnSpan(3),
                    static::getInput('fee_value')->columnSpan(3),
                    static::getInput('fee_percent')->columnSpan(3),
                    // I canali si scelgono solo se ce n'è più di uno acceso.
                    ...(Channels::choose() ? array_map(
                        static fn (string $channel) => static::getInput(Channels::column($channel))->columnSpan(4),
                        Channels::active()
                    ) : []),
                ])->columns(12)->columnSpan(12),
                (new Card)->components([
                    SectionTitle::make('Istruzioni e fattura')
                        ->tooltip('Le icone sono i loghi mostrati accanto al metodo nel checkout. Le istruzioni arrivano al cliente nell\'email dell\'ordine. Per il bonifico non scrivere l\'IBAN: lo compone l\'email dal conto scelto. La modalità di pagamento è quella che la fattura elettronica porta con sé (codici MP01–MP23).')
                        ->columnSpan(12),
                    static::getInput('icons')->columnSpan(12),
                    static::getInput('instructions')->columnSpan(7),
                    static::getInput('sdi_code')->columnSpan(5),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit'),
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

        // Vuote all'inserimento: quelle del tipo. Tolte a mano: «none», così non tornano.
        if (array_key_exists('icons', $values) || $action === 'store' || array_key_exists('provider', $values)) {
            $icons = PaymentMethod::iconsOf(implode(',', array_map('strval', (array) ($values['icons'] ?? []))));
            if ($icons === [] && $action === 'store') {
                $icons = PaymentMethod::defaultIcons((string) ($values['provider'] ?? ''));
            }
            $values['icons'] = $icons === [] ? 'none' : implode(',', $icons);
        }

        // Quello che il form nasconde non resta a metà: il conto serve al
        // bonifico, e la commissione che non c'è non ha cifre.
        if (array_key_exists('provider', $values) && (string) $values['provider'] !== 'bank_transfer') {
            $values['payment_account_id'] = '0';
        }

        if (array_key_exists('fee_type', $values)) {
            $type = (string) $values['fee_type'];

            if (!in_array($type, ['amount', 'amount_percent'], true)) {
                $values['fee_value'] = '0';
            }

            if (!in_array($type, ['percent', 'amount_percent'], true)) {
                $values['fee_percent'] = '0';
            }
        }

        if ($action === 'store') {
            $values['position'] = Positions::next(PaymentMethod::$table);
        } else {
            unset($values['position']);
        }

        return $values;
    }

    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        $values['icons'] = PaymentMethod::iconsOf((string) ($values['icons'] ?? ''));

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
