<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use DateTimeImmutable;
use RuntimeException;
use Throwable;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItem;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionStatusLog;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentData;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentFiles;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentPdfs;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Payments\Installments;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Sql\Transaction;

/**
 * Gli abbonamenti da ufficio (G10 §3): canale `office`, provider `manual`,
 * niente Stripe.
 *
 * A ogni periodo nascono le rate in attesa secondo la condizione di pagamento
 * (`Installments::createForSubscription()`, che usa `PaymentSchedule`) e parte
 * il proforma per email. Le rate si incassano come quelle degli ordini, dallo
 * Scadenzario o dalla scheda. Il giro giornaliero (`daily()`) apre i periodi,
 * chiude i disdetti e segue le rate scadute: `past_due` con un promemoria,
 * `unpaid` oltre i giorni dell'impostazione. L'annullamento lo decide il
 * commerciante (`terminate()`).
 *
 * Scelta rispetto alla spec: l'abbonamento parte attivo (o in prova) alla data
 * d'inizio, senza aspettare il primo pagamento, come un ordine da ufficio con
 * le sue rate.
 */
final class OfficeSubscriptions
{
    /** Senza impostazione: dopo quanti giorni dalla scadenza una rata non pagata rende l'abbonamento `unpaid`. */
    public const UNPAID_DAYS = 15;

    /**
     * Crea l'abbonamento con le sue righe e, se non c'è prova, apre il primo
     * periodo. La prova è la più lunga fra i prezzi scelti; ogni riga ha il
     * vincolo del suo prezzo e quello dell'abbonamento è il più lontano.
     * `plan_price_id` da solo vale come una riga sola.
     *
     * @param array{customer_id: int, items?: list<array{plan_price_id: int, quantity?: int, customization?: array<int|string, mixed>, parent?: int}>, plan_price_id?: int, starts_at?: string, payment_method_id?: int, payment_term_id?: int, coupon?: string, note?: string, user_id?: int} $input
     */
    public static function create(array $input): int
    {
        $customerId = (int) ($input['customer_id'] ?? 0);
        $contact = $customerId > 0 ? Contact::findById($customerId) : null;

        if (!is_array($contact) || (int) ($contact['id'] ?? 0) <= 0 || (string) ($contact['deleted'] ?? 'false') !== 'false') {
            throw UserError::make('subscription.customer_required');
        }

        Subscriptions::assertRoom($customerId);

        $items = $input['items'] ?? null;

        if (!is_array($items) && (int) ($input['plan_price_id'] ?? 0) > 0) {
            $items = [['plan_price_id' => (int) $input['plan_price_id']]];
        }

        $start = self::day((string) ($input['starts_at'] ?? '')) ?? new DateTimeImmutable('today');
        $billing = OnlineSubscriptions::billingFor([], $contact);
        $includeTax = (string) (Setting::current()['catalog_prices_include_tax'] ?? 'true') === 'true';
        $lines = SubscriptionItems::prepare(is_array($items) ? $items : [], $billing, $includeTax);
        $coupon = trim((string) ($input['coupon'] ?? '')) !== ''
            ? OnlineSubscriptions::couponFor((string) $input['coupon'], $customerId, $start->format('Y-m-d H:i:s'), 'office')
            : null;
        $term = min(array_column($lines, 'term'));
        $amounts = SubscriptionAmounts::ofItems($lines, $coupon, $includeTax, 0, $term);
        $rates = array_values(array_unique(array_map(static fn (array $line): string => number_format((float) $line['tax_rate'], 2, '.', ''), $lines)));
        // L'aliquota dell'abbonamento è un riepilogo: quella delle righe se è una sola, altrimenti 0.
        [$taxId, $taxRate] = count($rates) === 1 ? [(int) $lines[0]['tax_id'], (float) $rates[0]] : [0, 0.0];
        $trial = max(array_column($lines, 'trial_days'));
        $firstPeriod = $start->modify('+'.$trial.' days');
        $commitment = max(array_column($lines, 'commitment_months'));
        $userId = (int) ($input['user_id'] ?? 0);
        $methodId = (int) ($input['payment_method_id'] ?? 0) ?: (int) ($contact['payment_method_id'] ?? 0);
        $termId = (int) ($input['payment_term_id'] ?? 0) ?: (int) ($contact['payment_term_id'] ?? 0);

        $id = Transaction::run(static function () use ($billing, $customerId, $lines, $coupon, $includeTax, $amounts, $taxId, $taxRate, $start, $trial, $firstPeriod, $commitment, $input, $userId, $methodId, $termId): int {
            // Il codice si dà qui: `create()` non lo genera, e il proforma prende il nome da lui.
            $created = Subscription::create($billing + [
                'code' => Code::make(Subscription::class, Codes::SUBSCRIPTION),
                'customer_id' => $customerId,
                'channel' => 'office',
                'status' => $trial > 0 ? 'trialing' : 'active',
                'provider' => 'manual',
                'environment' => 'live',
                'payment_method_id' => $methodId,
                'payment_term_id' => $termId,
                'coupon_id' => $coupon !== null ? (int) $coupon['id'] : 0,
                'coupon_code' => $coupon !== null ? (string) $coupon['code'] : '',
                'prices_include_tax' => $includeTax ? 'true' : 'false',
                'subtotal' => $amounts['subtotal'],
                'discount_total' => $amounts['discount_total'],
                'tax_id' => $taxId,
                'tax_country' => 'IT',
                'tax_rate' => number_format($taxRate, 2, '.', ''),
                'tax_total' => $amounts['tax_total'],
                'total' => $amounts['total'],
                'currency' => 'EUR',
                'started_at' => $start->format('Y-m-d H:i:s'),
                'trial_ends_at' => $trial > 0 ? $firstPeriod->format('Y-m-d H:i:s') : '',
                'commitment_ends_at' => $commitment > 0 ? SubscriptionProration::addMonths($firstPeriod, $commitment)->format('Y-m-d H:i:s') : '',
                'next_renewal_at' => $firstPeriod->format('Y-m-d H:i:s'),
                'auto_renew' => 'true',
                'note' => trim((string) ($input['note'] ?? '')),
            ]);
            $id = (int) ($created->insert_id ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('Abbonamento non scritto.');
            }

            // In prova i servizi sono già in uso (e contano per i limiti): le righe nascono attive.
            SubscriptionItems::insert($id, $lines, 'active', $start, $firstPeriod, 'user', $userId);
            StatusLogger::record(SubscriptionStatusLog::class, $id, 'status', '', $trial > 0 ? 'trialing' : 'active', 'user', $userId ?: null, 'Abbonamento da ufficio');

            return $id;
        });

        if ($trial === 0) {
            self::openPeriod($id, $firstPeriod);
        }

        return $id;
    }

    /**
     * Apre il periodo che comincia in `$from`: dura quanto la riga più corta,
     * si addebitano le righe il cui periodo parte quel giorno (il coupon conta
     * gli addebiti dalla data di rinnovo), le `pending` diventano attive, poi
     * rate in attesa dalla condizione di pagamento e proforma per email. Un
     * periodo già aperto non si riapre.
     *
     * @return array{payments: list<int>, file: string, email: string}
     */
    public static function openPeriod(int $subscriptionId, DateTimeImmutable $from): array
    {
        $result = Transaction::run(static function () use ($subscriptionId, $from): array {
            $row = self::lock($subscriptionId);
            $start = $from->setTime(0, 0);
            $current = self::day((string) ($row['current_period_start'] ?? ''));

            if ($current !== null && $current->format('Y-m-d') === $start->format('Y-m-d')) {
                return ['payments' => [], 'row' => $row, 'end' => (string) $row['current_period_end']];
            }

            $period = SubscriptionItems::forPeriod($row, $start);

            if ($period['items'] === []) {
                throw new RuntimeException('Abbonamento senza righe in corso.');
            }

            $end = SubscriptionProration::addMonths($start, $period['term']);
            $amounts = self::periodAmounts($row, $start, $period);

            $values = [
                'current_period_start' => $start->format('Y-m-d H:i:s'),
                'current_period_end' => $end->format('Y-m-d H:i:s'),
                'next_renewal_at' => (string) $row['auto_renew'] === 'true' ? $end->format('Y-m-d H:i:s') : '',
                'subtotal' => $amounts['subtotal'],
                'discount_total' => $amounts['discount_total'],
                'tax_total' => $amounts['tax_total'],
                'total' => $amounts['total'],
            ];

            if ((string) $row['status'] === 'trialing') {
                $values['status'] = 'active';
                StatusLogger::record(SubscriptionStatusLog::class, $subscriptionId, 'status', 'trialing', 'active', 'cron', null, 'Fine della prova');
            }

            foreach ($period['items'] as $item) {
                if ((string) $item['status'] === 'pending') {
                    SubscriptionItem::update(['status' => 'active'], (int) $item['id']);
                }
            }

            Subscription::update($values, $subscriptionId);
            $row = $values + $row;
            $label = 'Abbonamento '.(string) ($row['code'] ?? '').', dal '.$start->format('d/m/Y').' al '.$end->format('d/m/Y');
            $ids = (float) $amounts['total'] > 0.0
                ? Installments::createForSubscription($row, (float) $amounts['total'], $start, $label)
                : [];

            return ['payments' => $ids, 'row' => $row, 'end' => $end->format('Y-m-d H:i:s')];
        });

        $file = '';
        $email = 'skipped';

        if ($result['payments'] !== []) {
            try {
                $file = self::proforma($result['row'], (string) $result['row']['current_period_start'], $result['end']);
                $path = DocumentFiles::path($file);
                $email = SubscriptionNotifier::send('proforma', $subscriptionId, $path === null ? [] : [$path => self::proformaName($result['row'])])['status'];
            } catch (Throwable $error) {
                // Il periodo è aperto e le rate ci sono: un PDF o un'email che non
                // partono non lo disfano, e la scheda lo rimanda a mano.
                Errors::internal($error, 'subscriptions.proforma', ['subscription' => $subscriptionId]);
            }
        }

        return ['payments' => $result['payments'], 'file' => $file, 'email' => $email];
    }

    /**
     * Il proforma del periodo in corso, archiviato e ristampato se manca: una
     * voce per riga addebitata, col periodo della riga.
     *
     * @param array<string, mixed> $row
     * @return string il percorso relativo in `DocumentFiles`
     */
    public static function proforma(array $row, string $periodStart, string $periodEnd): string
    {
        $installments = array_values(array_map(
            static fn (array $r): array => ['due_date' => $r['due_date'], 'amount' => $r['amount']],
            array_filter(
                Installments::pendingForSubscription((int) $row['id']),
                static fn (array $r): bool => $r['due_date'] >= substr($periodStart, 0, 10)
            )
        ));
        $start = self::day($periodStart) ?? new DateTimeImmutable('today');
        $period = SubscriptionItems::forPeriod($row, $start);
        $amounts = self::periodAmounts($row, $start, $period);
        $lines = [];

        foreach ($period['charged'] as $index => $charged) {
            $line = $amounts['lines'][$index];
            $lines[] = [
                'name' => SubscriptionItems::label($charged['item']).' - dal '.$charged['start']->format('d/m/Y').' al '.$charged['end']->format('d/m/Y'),
                'quantity' => max(1, (int) ($charged['item']['quantity'] ?? 1)),
                'unit_price' => (float) $line['unit_price'],
                'line_total' => (float) $line['line_total'],
                'tax_rate' => (float) $line['tax_rate'],
            ];
        }

        $data = DocumentData::forSubscription(['tax_summaries' => $amounts['tax_summaries']] + $row, $lines, [], $periodStart, $periodEnd, $installments);

        return DocumentFiles::save(self::proformaFile((int) $row['id'], $periodStart), DocumentPdfs::render('proforma', $data));
    }

    public static function proformaFile(int $subscriptionId, string $periodStart): string
    {
        return 'proformas/'.$subscriptionId.'-'.str_replace('-', '', substr($periodStart, 0, 10)).'.pdf';
    }

    /**
     * Gli importi delle righe addebitate nel periodo che parte in `$start`,
     * col coupon dell'abbonamento contato sugli addebiti della riga più corta.
     *
     * @param array<string, mixed> $row
     * @param array{term: int, items: list<array<string, mixed>>, charged: list<array<string, mixed>>} $period da `SubscriptionItems::forPeriod()`
     * @return array<string, mixed> come `SubscriptionAmounts::ofItems()`
     */
    private static function periodAmounts(array $row, DateTimeImmutable $start, array $period): array
    {
        $anchor = self::day((string) (($row['trial_ends_at'] ?? '') ?: ($row['started_at'] ?? ''))) ?? $start;
        $coupon = (int) ($row['coupon_id'] ?? 0) > 0 ? Coupon::findById((int) $row['coupon_id']) : null;
        $lines = [];

        foreach ($period['charged'] as $charged) {
            $quantity = max(1, (int) ($charged['item']['quantity'] ?? 1));
            // Il sovrapprezzo resta quello scelto alla nascita della riga: la riga lo tiene per quantità.
            $lines[] = [
                'price' => $charged['price'],
                'quantity' => $quantity,
                'surcharge' => round((float) ($charged['item']['customization_total'] ?? 0) / $quantity, 2),
                'tax_rate' => (float) ($charged['item']['tax_rate'] ?? 0),
            ];
        }

        return SubscriptionAmounts::ofItems(
            $lines,
            is_array($coupon) && $coupon !== [] ? $coupon : null,
            (string) ($row['prices_include_tax'] ?? 'true') === 'true',
            SubscriptionProration::chargeIndex($anchor, $period['term'], $start),
            $period['term']
        );
    }

    /** @param array<string, mixed> $row */
    public static function proformaName(array $row): string
    {
        return 'Proforma-'.preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $row['code']).'-'.str_replace('-', '', substr((string) $row['current_period_start'], 0, 10)).'.pdf';
    }

    /**
     * Incassa una rata dell'abbonamento: i conti sono quelli delle rate degli
     * ordini. Senza più rate scadute, un abbonamento da pagare torna attivo.
     *
     * @param array<string, mixed> $options paid_at, payment_method_id, reference, user_id
     */
    public static function registerPayment(int $subscriptionId, int $paymentId, float $amount, array $options = []): void
    {
        $payment = Payment::findById($paymentId);

        if (!is_array($payment) || (int) ($payment['subscription_id'] ?? 0) !== $subscriptionId) {
            throw UserError::make('payment.installment_not_pending');
        }

        Installments::register($paymentId, $amount, $options + ['source' => 'user']);
        self::settle($subscriptionId, (int) ($options['user_id'] ?? 0));
    }

    /**
     * Cambia piano: il nuovo prezzo vale dal periodo dopo (G10.13), senza
     * conguagli; le rate del periodo in corso restano.
     */
    public static function changePlan(int $subscriptionId, int $newPriceId, int $userId = 0): void
    {
        $row = self::live($subscriptionId);
        $price = PlanPrice::findById($newPriceId);
        $plan = is_array($price) ? Plan::findById((int) $price['plan_id']) : null;

        if (!is_array($price) || (string) ($price['active'] ?? 'false') !== 'true' || !is_array($plan) || (string) ($plan['active'] ?? 'false') !== 'true') {
            throw UserError::make('subscription.price_unavailable');
        }

        SubscriptionItems::changePrice($row, $price, 'user', $userId);
    }

    /**
     * Disdetta: niente rinnovo. Finisce alla chiusura del periodo, o alla fine
     * del vincolo se dura ancora: fino ad allora i periodi si aprono.
     *
     * @return string quando finisce
     */
    public static function cancel(int $subscriptionId, int $userId = 0): string
    {
        $row = self::live($subscriptionId);

        if ((string) $row['auto_renew'] !== 'true') {
            throw UserError::make('subscription.already_cancelled');
        }

        Subscription::update(['auto_renew' => 'false', 'cancel_requested_at' => date('Y-m-d H:i:s'), 'next_renewal_at' => ''], $subscriptionId);
        StatusLogger::record(SubscriptionStatusLog::class, $subscriptionId, 'auto_renew', 'true', 'false', 'user', $userId ?: null, 'Disdetta registrata');
        SubscriptionNotifier::send('cancel_requested', $subscriptionId);

        return SubscriptionEmail::endOf($row);
    }

    /**
     * Annulla subito, per decisione del commerciante: le rate in attesa si
     * annullano, i pagamenti già arrivati restano.
     */
    public static function terminate(int $subscriptionId, int $userId = 0, string $reason = ''): void
    {
        Transaction::run(static function () use ($subscriptionId, $userId, $reason): void {
            $row = self::lock($subscriptionId);

            if ((string) $row['channel'] !== 'office' || in_array((string) $row['status'], ['cancelled'], true)) {
                throw UserError::make('subscription.not_live');
            }

            foreach (Installments::pendingForSubscription($subscriptionId) as $rata) {
                Payment::update(['status' => 'cancelled'], $rata['id']);
            }

            Subscription::update([
                'auto_renew' => 'false',
                'next_renewal_at' => '',
                'cancelled_at' => date('Y-m-d H:i:s'),
                'ended_at' => date('Y-m-d H:i:s'),
            ], $subscriptionId);
            SubscriptionStatus::move($subscriptionId, 'cancelled', 'user', $reason !== '' ? $reason : 'Annullato dal commerciante', $userId ?: null);
        });
    }

    /**
     * Il giro giornaliero degli abbonamenti da ufficio.
     *
     * @return array{opened: int, ended: int, overdue: int, unpaid: int}
     */
    public static function daily(?DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $done = ['opened' => 0, 'ended' => 0, 'overdue' => 0, 'unpaid' => 0];
        $rows = self::rows(Subscription::find(
            "`channel` = 'office' AND `deleted` = 'false' AND `status` IN ('".implode("','", Subscription::LIVE)."', 'unpaid')"
        ));

        foreach ($rows as $row) {
            try {
                $id = (int) $row['id'];
                $renewal = self::day((string) ($row['next_renewal_at'] ?? ''));
                $periodEnd = self::day((string) ($row['current_period_end'] ?? ''));

                // Disdetto: alla fine del periodo (e del vincolo) finisce.
                if ((string) $row['auto_renew'] !== 'true' && $periodEnd !== null && $periodEnd <= $today && !self::committed($row, $periodEnd)) {
                    Subscription::update(['ended_at' => $periodEnd->format('Y-m-d H:i:s'), 'cancelled_at' => date('Y-m-d H:i:s')], $id);
                    SubscriptionStatus::move($id, 'cancelled', 'cron', 'Fine del periodo dopo la disdetta');
                    $done['ended']++;

                    continue;
                }

                // Un periodo nuovo: alla fine della prova o del periodo in corso.
                $next = $renewal ?? ((string) $row['auto_renew'] !== 'true' ? $periodEnd : null);

                if ((string) $row['status'] !== 'unpaid' && $next !== null && $next <= $today) {
                    self::openPeriod($id, $next);
                    $done['opened']++;
                }

                $overdue = self::oldestOverdue($id, $today);

                if ($overdue !== null) {
                    $limit = self::unpaidDays();

                    if (self::daysBetween($overdue, $today) >= $limit && SubscriptionStatus::move($id, 'unpaid', 'cron', 'Rata scaduta da più di '.$limit.' giorni')) {
                        $done['unpaid']++;
                    } elseif (in_array((string) (Subscription::findById($id)['status'] ?? ''), ['active', 'trialing'], true)
                        && SubscriptionStatus::move($id, 'past_due', 'cron', 'Rata scaduta il '.$overdue->format('d/m/Y'))) {
                        SubscriptionNotifier::send('reminder', $id);
                        $done['overdue']++;
                    }
                }
            } catch (Throwable $error) {
                Errors::internal($error, 'subscriptions.office_daily', ['subscription' => (int) $row['id']]);
            }
        }

        return $done;
    }

    /** I giorni dell'impostazione «Giorni prima di non pagato». */
    public static function unpaidDays(): int
    {
        try {
            $days = (int) (Setting::current()['subscription_unpaid_days'] ?? 0);
        } catch (Throwable) {
            $days = 0;
        }

        return $days > 0 ? $days : self::UNPAID_DAYS;
    }

    /** Senza rate scadute, un abbonamento da pagare o non pagato torna attivo. */
    private static function settle(int $subscriptionId, int $userId): void
    {
        $row = Subscription::findById($subscriptionId);

        if (!is_array($row) || !in_array((string) $row['status'], ['past_due', 'unpaid'], true)) {
            return;
        }

        if (self::oldestOverdue($subscriptionId, new DateTimeImmutable('today')) === null) {
            SubscriptionStatus::move($subscriptionId, 'active', 'user', 'Rate scadute pagate', $userId ?: null);
        }
    }

    /** La scadenza più vecchia tra le rate in attesa già passate; `null` se nessuna. */
    private static function oldestOverdue(int $subscriptionId, DateTimeImmutable $today): ?DateTimeImmutable
    {
        foreach (Installments::pendingForSubscription($subscriptionId) as $rata) {
            if ($rata['due_date'] < $today->format('Y-m-d')) {
                return new DateTimeImmutable($rata['due_date']);
            }
        }

        return null;
    }

    /** Il vincolo dura oltre quella data? @param array<string, mixed> $row */
    private static function committed(array $row, DateTimeImmutable $date): bool
    {
        $end = self::day((string) ($row['commitment_ends_at'] ?? ''));

        return $end !== null && $end > $date;
    }

    /** @return array<string, mixed> */
    private static function live(int $subscriptionId): array
    {
        $row = Subscription::findById($subscriptionId);

        if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0 || (string) ($row['channel'] ?? '') !== 'office'
            || !in_array((string) ($row['status'] ?? ''), [...Subscription::LIVE, 'unpaid'], true)) {
            throw UserError::make('subscription.not_live');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private static function lock(int $subscriptionId): array
    {
        $row = Subscription::findForUpdate(['id' => $subscriptionId], 1);

        if (!is_array($row) || $row === [] || (string) ($row['deleted'] ?? 'false') === 'true') {
            throw UserError::make('subscription.not_found');
        }

        return $row;
    }

    private static function day(string $value): ?DateTimeImmutable
    {
        $value = substr(trim($value), 0, 10);

        if ($value === '' || str_starts_with($value, '0000') || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return new DateTimeImmutable($value);
    }

    private static function daysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) $from->diff($to)->format('%r%a');
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        return isset($found['id']) ? [$found] : array_values(array_filter($found, 'is_array'));
    }
}
