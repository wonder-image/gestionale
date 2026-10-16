<?php

namespace Wonder\Plugin\Gestionale\Resources\Subscriptions;

use Throwable;
use Wonder\App\LegacyGlobals;
use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderPaymentResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Payments\Installments;
use Wonder\Plugin\Gestionale\Support\Stock\BatchExpiry;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OfficeSubscriptions;

/**
 * «Registra pagamento» su una rata di un abbonamento da ufficio (G10 §3):
 * dallo Scadenzario o dalla scheda dell'abbonamento, con `?abbonamento=` e
 * `?rata=`. I conti sono quelli delle rate degli ordini (`Installments`):
 * uguale chiude, minore lascia il resto, maggiore scala le successive.
 */
final class SubscriptionPaymentResource extends NavigationOnlyResource
{
    public static function path(): string
    {
        return 'app/gestionale/abbonamento-pagamento';
    }

    public static function icon(): string
    {
        return 'bi-cash-coin';
    }

    public static function titleLabel(): string
    {
        return 'Registra pagamento';
    }

    public static function isFormPage(): bool
    {
        return true;
    }

    /** Il link sulla rata, con la strada del ritorno; i segnaposto del menu di riga vanno bene. */
    public static function urlFor(string $subscription, string $payment, string $back = ''): string
    {
        $url = static::submitUrl().'?abbonamento='.$subscription.'&rata='.$payment;

        return $back === '' ? $url : $url.'&torna='.rawurlencode($back);
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
            ->titles(['form' => 'Registra pagamento'])
            ->subtitles(['form' => 'Scrivi il denaro arrivato su questa rata dell\'abbonamento.']);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('vendite'), ['edit', 'update']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('vendite')
            ->title('Registra pagamento')
            ->access(Gestionale::area('vendite'))
            ->enabled(false);
    }

    public static function formSchema(): array
    {
        $abbonamento = static::currentSubscription();
        $rata = static::currentInstallment($abbonamento);
        $metodi = OrderPaymentResource::activeMethods();
        $predefinito = (int) ($abbonamento['payment_method_id'] ?? 0);

        return [
            FormField::key('amount')->text()->label('Importo (€)')->value(number_format((float) ($rata['amount'] ?? 0), 2, ',', '.'))->required(),
            FormField::key('payment_method_id')
                ->select($metodi)
                ->value((string) (isset($metodi[$predefinito]) ? $predefinito : (array_key_first($metodi) ?? '')))
                ->label('Metodo'),
            FormField::key('paid_at')->dateInput()->value(date('d/m/Y'))->label('Data')->required(),
            FormField::key('reference')->text()->label('Riferimento'),
            FormField::key('subscription_id')->hidden()->value((string) ($abbonamento['id'] ?? 0)),
            FormField::key('payment_id')->hidden()->value((string) ($rata['id'] ?? '')),
            FormField::key('back')->hidden()->value(StockAdjustmentResource::backUrlFrom($_GET['torna'] ?? '')),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        $abbonamento = static::currentSubscription();
        $rata = static::currentInstallment($abbonamento);
        $titolo = 'Abbonamento '.(string) ($abbonamento['code'] ?? '');

        if ($rata !== null) {
            $titolo .= ', '.lcfirst($rata['note'] !== '' ? $rata['note'] : 'rata').' del '.BatchExpiry::label($rata['due_date']);
        }

        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make($titolo)
                        ->tooltip('L\'importo è quello della rata: scrivine meno per un acconto (il resto resta con la stessa scadenza), di più per pagare anche le rate dopo. La data è quella dell\'arrivo del denaro.')
                        ->columnSpan(12),
                    RichText::make($rata === null
                        ? '<p class="text-muted mb-0">Questa rata non è più in attesa.</p>'
                        : '<b>Rata</b> '.OrderSheet::esc(OrderSheet::money($rata['amount'])).' · <b>Scadenza</b> '.OrderSheet::esc(BatchExpiry::label($rata['due_date'])))
                        ->columnSpan(12),
                    static::getInput('amount')->columnSpan(3),
                    static::getInput('payment_method_id')->columnSpan(3),
                    static::getInput('paid_at')->columnSpan(3),
                    static::getInput('reference')->columnSpan(3),
                    static::getInput('subscription_id')->columnSpan(12),
                    static::getInput('payment_id')->columnSpan(12),
                    static::getInput('back')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ])->columns(12);
    }

    /**
     * @param array<string, mixed> $values
     * @return array{ok: bool, message: string}
     */
    public static function run(array $values, int $userId): array
    {
        $subscriptionId = (int) ($values['subscription_id'] ?? 0);
        $paymentId = (int) ($values['payment_id'] ?? 0);
        $amount = OrderPaymentResource::amountFrom($values['amount'] ?? null);

        try {
            if ($amount === null) {
                throw UserError::make('payment.zero_amount');
            }

            OfficeSubscriptions::registerPayment($subscriptionId, $paymentId, $amount, [
                'payment_method_id' => (int) ($values['payment_method_id'] ?? 0),
                'reference' => trim((string) ($values['reference'] ?? '')),
                'paid_at' => OrderPaymentResource::dateFrom($values['paid_at'] ?? ''),
                'user_id' => $userId,
            ]);
        } catch (UserError $error) {
            return ['ok' => false, 'message' => $error->getMessage()];
        }

        return ['ok' => true, 'message' => 'Registrato l\'incasso di '.OrderSheet::money($amount).' sull\'abbonamento.'];
    }

    public static function submitFormPage(array $values): string
    {
        $user = LegacyGlobals::get('USER');
        $result = static::run($values, is_object($user) ? (int) ($user->id ?? 0) : 0);
        $subscriptionId = (int) ($values['subscription_id'] ?? 0);
        $back = StockAdjustmentResource::backUrlFrom($values['back'] ?? '');

        if (headers_sent()) {
            return $result['message'];
        }

        if ($result['ok']) {
            FlashAlert::saved($result['message']);
            header('Location: '.($back !== '' ? $back : SubscriptionResource::detailUrl($subscriptionId)));
        } else {
            FlashAlert::custom('Attenzione', $result['message'], 'warning');
            header('Location: '.static::urlFor((string) $subscriptionId, (string) (int) ($values['payment_id'] ?? 0), $back));
        }

        exit();
    }

    /** @return array<string, mixed> */
    private static function currentSubscription(): array
    {
        $id = (int) ($_GET['abbonamento'] ?? $_POST['subscription_id'] ?? 0);
        $row = $id > 0 ? Subscription::findById($id) : null;

        return is_array($row) && $row !== [] ? $row : [];
    }

    /**
     * La rata scelta, se è ancora in attesa e dell'abbonamento.
     *
     * @param array<string, mixed> $subscription
     * @return array{id: int, amount: float, due_date: string, note: string}|null
     */
    private static function currentInstallment(array $subscription): ?array
    {
        $id = (int) ($_GET['rata'] ?? $_POST['payment_id'] ?? 0);

        // Senza abbonamento o senza rata non si chiede niente al database:
        // il form si costruisce anche dove un database non c'è.
        if ($id <= 0 || (int) ($subscription['id'] ?? 0) <= 0) {
            return null;
        }

        foreach (Installments::pendingForSubscription((int) $subscription['id']) as $rata) {
            if ($rata['id'] === $id) {
                return $rata;
            }
        }

        return null;
    }
}
