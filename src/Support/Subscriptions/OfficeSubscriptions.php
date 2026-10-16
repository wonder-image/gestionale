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
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItemChange;
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
     * gli addebiti dalla data di rinnovo), le `pending` diventano attive, i
     * crediti dei servizi tolti scalano il totale ({@see useCredits()}), poi
     * rate in attesa dalla condizione di pagamento sul resto e proforma per
     * email (anche quando il credito copre tutto). Un periodo già aperto non
     * si riapre.
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
                return ['payments' => [], 'credits' => [], 'row' => $row, 'end' => (string) $row['current_period_end']];
            }

            $period = SubscriptionItems::forPeriod($row, $start);

            if ($period['items'] === []) {
                throw new RuntimeException('Abbonamento senza righe in corso.');
            }

            $end = $period['end'];
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
            $credits = self::useCredits($subscriptionId, (float) $amounts['total']);
            $due = round((float) $amounts['total'] - array_sum(array_column($credits, 'amount')), 2);
            $ids = $due > 0.0
                ? Installments::createForSubscription($row, $due, $start, $label)
                : [];

            return ['payments' => $ids, 'credits' => $credits, 'row' => $row, 'end' => $end->format('Y-m-d H:i:s')];
        });

        $file = '';
        $email = 'skipped';

        if ($result['payments'] !== [] || $result['credits'] !== []) {
            try {
                $file = self::proforma($result['row'], (string) $result['row']['current_period_start'], $result['end'], $result['credits']);
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
     * Il proforma del periodo in corso, archiviato: una voce per riga
     * addebitata, col periodo della riga, e una voce di sconto per ogni
     * credito usato. Lo scrive l'apertura del periodo; rimandato, si usa
     * quello archiviato ({@see archivedProforma()}).
     *
     * @param array<string, mixed> $row
     * @param list<array{name: string, amount: float}> $credits
     * @return string il percorso relativo in `DocumentFiles`
     */
    public static function proforma(array $row, string $periodStart, string $periodEnd, array $credits = []): string
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

        $data = DocumentData::forSubscription(['tax_summaries' => $amounts['tax_summaries']] + $row, $lines, $credits, $periodStart, $periodEnd, $installments);

        return DocumentFiles::save(self::proformaFile((int) $row['id'], $periodStart), DocumentPdfs::render('proforma', $data));
    }

    /**
     * Il proforma archiviato del periodo, o uno nuovo se manca (senza i
     * crediti: quelli li conosce solo l'apertura del periodo).
     *
     * @param array<string, mixed> $row
     */
    public static function archivedProforma(array $row): string
    {
        $file = self::proformaFile((int) $row['id'], (string) $row['current_period_start']);

        return DocumentFiles::path($file) !== null
            ? $file
            : self::proforma($row, (string) $row['current_period_start'], (string) $row['current_period_end']);
    }

    /**
     * Usa i crediti aperti dell'abbonamento, dal più vecchio, fino a
     * `$total`: scala `credit_left` di ognuno e torna le voci di sconto per
     * il proforma. Va chiamata dentro la transazione che blocca
     * l'abbonamento.
     *
     * @return list<array{name: string, amount: float}>
     */
    private static function useCredits(int $subscriptionId, float $total): array
    {
        $left = round($total, 2);
        $used = [];

        foreach (self::openCredits($subscriptionId) as $change) {
            if ($left <= 0.0) {
                break;
            }

            $amount = min($left, round((float) $change['credit_left'], 2));
            SubscriptionItemChange::update(['credit_left' => number_format((float) $change['credit_left'] - $amount, 2, '.', '')], (int) $change['id']);
            $left = round($left - $amount, 2);
            $price = PlanPrice::findById((int) $change['old_plan_price_id']);
            $plan = is_array($price) ? Plan::findById((int) ($price['plan_id'] ?? 0)) : null;
            $used[] = [
                'name' => 'Credito '.(is_array($plan) ? (string) ($plan['name'] ?? '') : 'di un servizio').' del '.self::day((string) $change['effective_at'])?->format('d/m/Y'),
                'amount' => $amount,
            ];
        }

        return $used;
    }

    /**
     * I cambi con credito ancora da usare, dal più vecchio.
     *
     * @return list<array<string, mixed>>
     */
    private static function openCredits(int $subscriptionId): array
    {
        return self::rows(SubscriptionItemChange::find(
            '`subscription_id` = '.$subscriptionId." AND `deleted` = 'false' AND `credit_left` > 0",
            null,
            'effective_at',
            'ASC'
        ));
    }

    /** Un abbonamento chiuso con crediti non usati va in *Da controllare*. */
    private static function reportCredits(int $subscriptionId): void
    {
        $left = round(array_sum(array_map(static fn (array $c): float => (float) $c['credit_left'], self::openCredits($subscriptionId))), 2);

        if ($left > 0.0) {
            Errors::report('subscriptions', 'credit_left', 'Credito di '.number_format($left, 2, ',', '.').' € non usato', ['subscription' => $subscriptionId]);
        }
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
        $coupon = (int) ($row['coupon_id'] ?? 0) > 0 ? Coupon::findById((int) $row['coupon_id']) : null;
        $lines = [];

        foreach ($period['charged'] as $charged) {
            // Il sovrapprezzo resta quello della riga; una riga che parte a metà del suo periodo paga la quota.
            $lines[] = SubscriptionItems::amountLine(
                $charged['item'],
                $charged['price'],
                isset($charged['row_start']) ? [$charged['start'], $charged['row_start'], $charged['end']] : null
            );
        }

        return SubscriptionAmounts::ofItems(
            $lines,
            is_array($coupon) && $coupon !== [] ? $coupon : null,
            (string) ($row['prices_include_tax'] ?? 'true') === 'true',
            SubscriptionProration::chargeIndex(SubscriptionItems::anchor($row), $period['term'], $start),
            $period['term']
        );
    }

    /**
     * I conti di una riga aggiunta o cambiata a periodo in corso (§4.1, §4.3):
     * il periodo dell'abbonamento si accorcia se la riga rinnova prima, e con
     * `prorate` la quota fino alla fine del periodo della riga diventa una
     * rata. In prova e con `free` o `next_period` niente rate. Va chiamata
     * dentro la transazione che blocca l'abbonamento.
     *
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $item la riga, già scritta col prezzo nuovo
     * @return array{amount: string, payments: list<int>}
     */
    public static function itemAdded(array $subscription, array $item, string $billing, DateTimeImmutable $at): array
    {
        $none = ['amount' => '0.00', 'payments' => []];

        if ((string) $subscription['status'] === 'trialing' || $billing === 'next_period') {
            return $none;
        }

        $price = PlanPrice::findById((int) $item['plan_price_id']);

        if (!is_array($price)) {
            return $none;
        }

        $term = max(1, (int) ($price['term_months'] ?? 1));
        [$rowStart, $rowEnd] = SubscriptionProration::rowPeriod(SubscriptionItems::anchor($subscription), $term, $at);
        $periodEnd = self::day((string) ($subscription['current_period_end'] ?? ''));

        if ($periodEnd !== null && $periodEnd > $rowEnd) {
            // La riga nuova rinnova prima: il periodo dell'abbonamento finisce con lei.
            $values = ['current_period_end' => $rowEnd->format('Y-m-d H:i:s')];

            if ((string) ($subscription['auto_renew'] ?? 'true') === 'true') {
                $values['next_renewal_at'] = $rowEnd->format('Y-m-d H:i:s');
            }

            Subscription::update($values, (int) $subscription['id']);
        }

        if ($billing !== 'prorate') {
            return $none;
        }

        $amounts = self::quotaAmounts($subscription, $item, $price, $at, $rowStart, $rowEnd);

        if ((float) $amounts['total'] <= 0.0) {
            return $none;
        }

        $label = 'Abbonamento '.(string) ($subscription['code'] ?? '').', '.SubscriptionItems::label($item)
            .' dal '.$at->format('d/m/Y').' al '.$rowEnd->format('d/m/Y');

        return [
            'amount' => $amounts['total'],
            'payments' => Installments::createForSubscription($subscription, (float) $amounts['total'], $at, $label),
        ];
    }

    /**
     * Il proforma della quota di una riga: le sue rate, archiviato a parte e
     * spedito al cliente. Un PDF o un'email che non partono non disfano le
     * rate: l'errore va nel registro.
     *
     * @param list<int> $paymentIds
     * @return array{file: string, email: string}
     */
    public static function quotaProforma(int $subscriptionId, int $itemId, DateTimeImmutable $at, array $paymentIds): array
    {
        try {
            $row = Subscription::findById($subscriptionId);
            $item = SubscriptionItem::findById($itemId);
            $price = is_array($item) ? PlanPrice::findById((int) $item['plan_price_id']) : null;

            if (!is_array($row) || !is_array($item) || !is_array($price)) {
                return ['file' => '', 'email' => 'skipped'];
            }

            [$rowStart, $rowEnd] = SubscriptionProration::rowPeriod(SubscriptionItems::anchor($row), max(1, (int) ($price['term_months'] ?? 1)), $at);
            $amounts = self::quotaAmounts($row, $item, $price, $at, $rowStart, $rowEnd);
            $line = $amounts['lines'][0];
            $installments = [];

            foreach ($paymentIds as $paymentId) {
                $payment = Payment::findById($paymentId);

                if (is_array($payment)) {
                    $installments[] = ['due_date' => $payment['due_date'], 'amount' => $payment['amount']];
                }
            }

            $data = DocumentData::forSubscription(
                ['tax_summaries' => $amounts['tax_summaries']] + $row,
                [[
                    'name' => SubscriptionItems::label($item).' - dal '.$at->format('d/m/Y').' al '.$rowEnd->format('d/m/Y'),
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'unit_price' => (float) $line['unit_price'],
                    'line_total' => (float) $line['line_total'],
                    'tax_rate' => (float) $line['tax_rate'],
                ]],
                [],
                $at->format('Y-m-d H:i:s'),
                $rowEnd->format('Y-m-d H:i:s'),
                $installments
            );
            $stamp = $at->format('Ymd');
            $file = DocumentFiles::save('proformas/'.$subscriptionId.'-'.$stamp.'-'.$itemId.'.pdf', DocumentPdfs::render('proforma', $data));
            $path = DocumentFiles::path($file);
            $name = 'Proforma-'.preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $row['code']).'-'.$stamp.'-'.$itemId.'.pdf';

            return ['file' => $file, 'email' => SubscriptionNotifier::send('proforma', $subscriptionId, $path === null ? [] : [$path => $name])['status']];
        } catch (Throwable $error) {
            Errors::internal($error, 'subscriptions.quota_proforma', ['subscription' => $subscriptionId, 'item' => $itemId]);

            return ['file' => '', 'email' => 'failed'];
        }
    }

    /**
     * La quota di una riga da `$at` alla fine del suo periodo, senza coupon.
     *
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $item
     * @param array<string, mixed> $price
     * @return array<string, mixed> come `SubscriptionAmounts::ofItems()`
     */
    private static function quotaAmounts(array $subscription, array $item, array $price, DateTimeImmutable $at, DateTimeImmutable $rowStart, DateTimeImmutable $rowEnd): array
    {
        return SubscriptionAmounts::ofItems(
            [SubscriptionItems::amountLine($item, $price, [$at, $rowStart, $rowEnd])],
            null,
            (string) ($subscription['prices_include_tax'] ?? 'true') === 'true',
            0,
            max(1, (int) ($price['term_months'] ?? 1))
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
        self::reportCredits($subscriptionId);
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

                if (self::dailyItems($row, $today)) {
                    $done['ended']++;

                    continue;
                }

                $row = Subscription::findById($id) ?: $row;
                $renewal = self::day((string) ($row['next_renewal_at'] ?? ''));
                $periodEnd = self::day((string) ($row['current_period_end'] ?? ''));

                // Disdetto: alla fine del periodo (e del vincolo) finisce, se nessun servizio va oltre.
                if ((string) $row['auto_renew'] !== 'true' && $periodEnd !== null && $periodEnd <= $today && !self::committed($row, $periodEnd)
                    && !self::endingAfter($id, $periodEnd)) {
                    Subscription::update(['ended_at' => $periodEnd->format('Y-m-d H:i:s'), 'cancelled_at' => date('Y-m-d H:i:s')], $id);
                    SubscriptionStatus::move($id, 'cancelled', 'cron', 'Fine del periodo dopo la disdetta');
                    self::reportCredits($id);
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

    /**
     * Le righe nel giro giornaliero, prima dei periodi: le `ending` arrivate
     * alla fine si chiudono, i prezzi di rinnovo a fine vincolo e i cambi
     * «dal prossimo periodo» arrivati al giorno si applicano (con le loro
     * quote). Senza più righe in corso l'abbonamento finisce.
     *
     * @param array<string, mixed> $row
     * @return bool true se l'abbonamento è finito
     */
    private static function dailyItems(array $row, DateTimeImmutable $today): bool
    {
        $id = (int) $row['id'];

        foreach (SubscriptionItems::forSubscription($id, ['ending']) as $item) {
            $ends = self::day((string) ($item['ends_at'] ?? ''));

            if ($ends !== null && $ends <= $today) {
                SubscriptionItem::update(['status' => 'ended'], (int) $item['id']);
            }
        }

        if ((string) $row['status'] !== 'unpaid') {
            $applied = Transaction::run(static function () use ($id, $today): array {
                $locked = self::lock($id);
                SubscriptionItems::scheduleRenewals($locked, $today);

                return SubscriptionItems::applyScheduled($locked, $today);
            });

            foreach ($applied as $change) {
                if ($change['payments'] !== []) {
                    self::quotaProforma($id, $change['item'], $change['at'], $change['payments']);
                }
            }
        }

        if (SubscriptionItems::forSubscription($id, ['pending', 'active', 'ending']) !== []) {
            return false;
        }

        Subscription::update(['auto_renew' => 'false', 'next_renewal_at' => '', 'ended_at' => $today->format('Y-m-d H:i:s'), 'cancelled_at' => date('Y-m-d H:i:s')], $id);
        SubscriptionStatus::move($id, 'cancelled', 'cron', 'Nessun servizio in corso');
        self::reportCredits($id);

        return true;
    }

    /** Una riga che si sta chiudendo dopo `$date`: l'abbonamento va avanti fino a lei. */
    private static function endingAfter(int $subscriptionId, DateTimeImmutable $date): bool
    {
        foreach (SubscriptionItems::forSubscription($subscriptionId, ['ending']) as $item) {
            $ends = self::day((string) ($item['ends_at'] ?? ''));

            if ($ends !== null && $ends > $date) {
                return true;
            }
        }

        return false;
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
