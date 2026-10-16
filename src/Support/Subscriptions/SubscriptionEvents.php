<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItem;
use Wonder\Plugin\Gestionale\Models\System\ExternalReference;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentEvent;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Sql\Transaction;

/**
 * Le notifiche di Stripe degli abbonamenti (G10 §2.3), lette dal loro
 * contenuto: qui non si chiama mai Stripe. Le smista `PaymentEvents`, con lo
 * stesso registro che impedisce di elaborare due volte lo stesso evento.
 *
 * Un evento di un abbonamento che il gestionale non conosce (creato a mano
 * nella dashboard) non è un errore: si lascia stare.
 */
final class SubscriptionEvents
{
    public const TYPES = [
        'checkout.session.completed',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.paid',
        'invoice.payment_failed',
    ];

    public static function handles(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public static function apply(PaymentEvent $event, string $source = 'webhook'): void
    {
        $object = $event->payload['data']['object'] ?? null;

        if (!is_array($object)) {
            return;
        }

        match ($event->type) {
            'checkout.session.completed' => self::checkoutCompleted($object, $event->environment),
            'customer.subscription.created', 'customer.subscription.updated' => self::synced($object, $event->environment, $source, false),
            'customer.subscription.deleted' => self::synced($object, $event->environment, $source, true),
            'invoice.paid' => self::invoicePaid($object, $event->environment, $source),
            'invoice.payment_failed' => self::invoiceFailed($object, $event->environment, $source),
            default => null,
        };
    }

    /**
     * Allinea l'abbonamento a un oggetto `subscription` di Stripe: stato,
     * periodo, prova, rinnovo automatico, prezzo. Lo usano le notifiche e il
     * riallineamento. Ridà l'id dell'abbonamento, 0 se non è nostro.
     *
     * @param array<string, mixed> $object
     */
    public static function synced(array $object, string $environment, string $source, bool $deleted): int
    {
        $data = StripeSubscriptionData::subscription($object, $deleted);
        $row = self::find($data['reference'], $data['local_id'], $environment);

        if ($row === null) {
            return 0;
        }

        $id = (int) $row['id'];

        Transaction::run(static function () use ($row, $id, $data, $source, $deleted): void {
            // Una disdetta chiesta durante il vincolo resta tale: Stripe la riceve a fine vincolo.
            $renews = $data['auto_renew'] && self::empty($row['cancel_requested_at'] ?? '');
            $values = [
                'provider_reference' => $data['reference'],
                'auto_renew' => $renews ? 'true' : 'false',
            ];

            foreach (['current_period_start', 'current_period_end', 'trial_ends_at', 'cancelled_at', 'ended_at'] as $column) {
                if ($data[$column] !== null) {
                    $values[$column] = $data[$column];
                }
            }

            $live = in_array($data['status'], Subscription::LIVE, true);
            // Un abbonamento chiuso non tocca le righe: le chiude `SubscriptionStatus`.
            $changed = !$deleted && self::syncItems($row, $data['items'], $source);

            if ($live && self::empty($row['started_at'] ?? '')) {
                $values['started_at'] = $data['started_at'] ?? date('Y-m-d H:i:s');
                $commitment = self::startItems($id, $values['started_at']);

                if ($commitment !== '') {
                    $values['commitment_ends_at'] = $commitment;
                }
            }

            // Il prossimo rinnovo c'è solo se l'abbonamento rinnova davvero.
            $values['next_renewal_at'] = $live && $renews && $data['current_period_end'] !== null
                ? $data['current_period_end']
                : '';

            if ($changed) {
                $values += OnlineSubscriptions::amountsFor($row);
            }

            Subscription::update($values, $id);
            SubscriptionStatus::move($id, $data['status'], $source);
        });

        $fresh = Subscription::findById($id);

        if (self::empty($row['started_at'] ?? '') && is_array($fresh) && !self::empty($fresh['started_at'] ?? '')) {
            // La prima attivazione, con o senza prova: una volta sola.
            SubscriptionNotifier::send('confirmed', $id);
        }

        return $id;
    }

    /** @param array<string, mixed> $session */
    private static function checkoutCompleted(array $session, string $environment): void
    {
        if ((string) ($session['mode'] ?? '') !== 'subscription') {
            return;
        }

        $reference = is_array($session['subscription'] ?? null) ? (string) ($session['subscription']['id'] ?? '') : (string) ($session['subscription'] ?? '');
        $row = self::find('', (int) ($session['metadata']['subscription_id'] ?? 0), $environment)
            ?? self::byCode((string) ($session['client_reference_id'] ?? ''), $environment);

        if ($row === null || $reference === '') {
            return;
        }

        Subscription::update(['provider_reference' => $reference], (int) $row['id']);
    }

    /** @param array<string, mixed> $invoice */
    private static function invoicePaid(array $invoice, string $environment, string $source): void
    {
        $row = self::find(StripeSubscriptionData::invoiceSubscription($invoice), StripeSubscriptionData::invoiceLocalId($invoice), $environment);

        if ($row === null) {
            return;
        }

        $id = (int) $row['id'];
        $paid = StripeSubscriptionData::invoicePayment($invoice);
        $reference = (string) ($invoice['id'] ?? '');

        // La prova e gli importi a zero non sono pagamenti (§4).
        if ($paid['amount'] > 0.0 && $reference !== '' && Ledger::byReference('stripe', $reference) === null) {
            // La verità dell'importo è Stripe: si scrive quello della fattura.
            Payment::create([
                'code' => Code::make(Payment::class, Codes::PAYMENT),
                'type' => 'payment',
                'subscription_id' => $id,
                'customer_id' => (int) ($row['customer_id'] ?? 0),
                'amount' => number_format($paid['amount'], 2, '.', ''),
                'currency' => $paid['currency'],
                'status' => 'paid',
                'provider' => 'stripe',
                'provider_reference' => $reference,
                'environment' => $environment,
                'paid_at' => $paid['paid_at'],
                'note' => 'Abbonamento '.(string) ($row['code'] ?? ''),
            ]);
        }

        // Il primo addebito dopo la prova chiude la prova: la notifica dello
        // stato nuovo può arrivare dopo quella della fattura.
        if (in_array((string) $row['status'], ['pending', 'trialing', 'past_due', 'unpaid'], true) && $paid['amount'] > 0.0) {
            SubscriptionStatus::move($id, 'active', $source, 'Pagamento riuscito');
        }
    }

    /** @param array<string, mixed> $invoice */
    private static function invoiceFailed(array $invoice, string $environment, string $source): void
    {
        $row = self::find(StripeSubscriptionData::invoiceSubscription($invoice), StripeSubscriptionData::invoiceLocalId($invoice), $environment);

        if ($row === null || !in_array((string) $row['status'], ['trialing', 'active', 'past_due'], true)) {
            return;
        }

        // Stripe riprova la stessa fattura più volte, ognuna con un evento suo:
        // l'email parte al primo fallimento, non a ogni tentativo.
        if (SubscriptionStatus::move((int) $row['id'], 'past_due', $source, 'Rinnovo non riuscito')) {
            SubscriptionNotifier::send('payment_failed', (int) $row['id']);
        }
    }

    /**
     * L'abbonamento dal riferimento Stripe o dall'id nei metadati, nello
     * stesso ambiente; `null` se non è nostro.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $reference, int $localId, string $environment): ?array
    {
        if ($reference !== '') {
            $row = Subscription::find(['provider_reference' => $reference, 'environment' => $environment, 'provider' => 'stripe', 'deleted' => 'false'], 1);

            if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                return $row;
            }
        }

        if ($localId > 0) {
            $row = Subscription::findById($localId);

            if (is_array($row) && (int) ($row['id'] ?? 0) > 0 && (string) ($row['environment'] ?? '') === $environment
                && (string) ($row['provider'] ?? '') === 'stripe' && (string) ($row['deleted'] ?? 'false') === 'false') {
                return $row;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private static function byCode(string $code, string $environment): ?array
    {
        if ($code === '') {
            return null;
        }

        $row = Subscription::find(['code' => $code, 'environment' => $environment, 'deleted' => 'false'], 1);

        return is_array($row) && (int) ($row['id'] ?? 0) > 0 ? $row : null;
    }

    /**
     * Allinea le righe agli item di Stripe. Ogni item trova la sua riga dal
     * `metadata.item_id`, poi dal suo `si_`, poi dal prezzo Stripe scritto al
     * checkout (le righe del checkout non hanno metadati); un item solo e una
     * riga sola rimasti si abbinano (abbonamenti di prima delle righe). Un
     * prezzo diverso cambia la riga; un item senza riga ne crea una; una riga
     * già legata a Stripe che non ha più il suo item si chiude. Le righe in
     * attesa senza item (un cambio dal prossimo periodo) restano. Ridà `true`
     * se gli importi vanno rifatti.
     *
     * @param array<string, mixed> $row
     * @param list<array{id: string, price: string, quantity: int, item_id: int}> $remote
     */
    private static function syncItems(array $row, array $remote, string $source): bool
    {
        $open = [];

        foreach (SubscriptionItems::forSubscription((int) $row['id']) as $item) {
            if ((string) $item['status'] !== 'ended') {
                $open[(int) $item['id']] = $item;
            }
        }

        $matched = [];
        $left = [];

        foreach ($remote as $one) {
            $hit = $one['item_id'] > 0 && isset($open[$one['item_id']]) && !isset($matched[$one['item_id']]) ? $one['item_id'] : 0;

            foreach ($hit === 0 && $one['id'] !== '' ? $open : [] as $itemId => $item) {
                if (!isset($matched[$itemId]) && (string) ($item['provider_reference'] ?? '') === $one['id']) {
                    $hit = $itemId;
                    break;
                }
            }

            if ($hit > 0) {
                $matched[$hit] = $one;
            } else {
                $left[] = $one;
            }
        }

        $free = static function (int $itemId, array $item) use (&$matched): bool {
            return !isset($matched[$itemId]) && trim((string) ($item['provider_reference'] ?? '')) === '';
        };

        foreach ($left as $index => $one) {
            foreach ($open as $itemId => $item) {
                if ($one['price'] !== '' && $free($itemId, $item) && (string) ($item['provider_price'] ?? '') === $one['price']) {
                    $matched[$itemId] = $one;
                    unset($left[$index]);
                    break;
                }
            }
        }

        if (count($left) === 1) {
            $candidates = array_filter($open, static fn (array $item, int $itemId): bool => $free($itemId, $item) && in_array((string) $item['status'], SubscriptionItem::LIVE, true), ARRAY_FILTER_USE_BOTH);

            if (count($candidates) === 1) {
                $matched[(int) array_key_first($candidates)] = array_shift($left);
            }
        }

        $changed = false;

        foreach ($matched as $itemId => $one) {
            $item = $open[$itemId];
            $values = ['provider_reference' => $one['id'], 'provider_price' => $one['price']];

            // Una riga in attesa che Stripe ha messo (lo schedule al rinnovo) parte adesso.
            if ((string) $item['status'] === 'pending' && !self::empty($row['started_at'] ?? '')) {
                $values['status'] = 'active';
            }

            SubscriptionItem::update($values, $itemId);
            $priceId = $one['price'] !== '' ? self::planPriceOf($one['price']) : 0;
            $price = $priceId > 0 && $priceId !== (int) $item['plan_price_id'] ? PlanPrice::findById($priceId) : null;

            // Cambiato altrove (portale, dashboard): si registra come un cambio della riga.
            if (is_array($price) && SubscriptionItems::changePrice($row, $price, $source, 0, $item)) {
                $changed = true;
            }
        }

        foreach ($left as $one) {
            $priceId = $one['price'] !== '' ? self::planPriceOf($one['price']) : 0;
            $price = $priceId > 0 ? PlanPrice::findById($priceId) : null;

            if (is_array($price) && (int) ($price['id'] ?? 0) > 0) {
                SubscriptionItems::fromProvider($row, $price, $one['quantity'], $one['id'], $one['price'], $source);
                $changed = true;
            }
        }

        foreach ($open as $itemId => $item) {
            if (!isset($matched[$itemId]) && trim((string) ($item['provider_reference'] ?? '')) !== ''
                && in_array((string) $item['status'], SubscriptionItem::LIVE, true)) {
                SubscriptionItems::endFromProvider($item, $source);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * La prima attivazione: le righe non chiuse partono da `$startedAt`, col
     * vincolo del loro prezzo. Ridà il vincolo più lontano, '' se nessuno.
     */
    private static function startItems(int $subscriptionId, string $startedAt): string
    {
        $latest = '';

        foreach (SubscriptionItems::forSubscription($subscriptionId, ['pending', 'active', 'ending']) as $item) {
            $price = PlanPrice::findById((int) $item['plan_price_id']);
            $months = is_array($price) ? (int) ($price['commitment_months'] ?? 0) : 0;
            $itemValues = ['starts_at' => $startedAt];

            if ($months > 0) {
                $itemValues['commitment_ends_at'] = date('Y-m-d H:i:s', (int) strtotime('+'.$months.' months', (int) strtotime($startedAt)));
                $latest = max($latest, $itemValues['commitment_ends_at']);
            }

            SubscriptionItem::update($itemValues, (int) $item['id']);
        }

        return $latest;
    }

    /** Il prezzo del piano che ha quel prezzo Stripe; 0 se nessuno. */
    private static function planPriceOf(string $stripePrice): int
    {
        $row = ExternalReference::find(['provider' => 'stripe', 'entity_type' => 'plan_price', 'external_id' => $stripePrice, 'deleted' => 'false'], 1);

        return is_array($row) ? (int) ($row['entity_id'] ?? 0) : 0;
    }

    private static function empty(mixed $date): bool
    {
        $date = trim((string) $date);

        return $date === '' || str_starts_with($date, '0000');
    }
}
