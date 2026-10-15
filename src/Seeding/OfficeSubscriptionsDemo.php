<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use DateTimeImmutable;
use Wonder\App\Support\DefaultRows;
use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentStatusLog;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentTerm;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItem;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItemChange;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionStatusLog;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OfficeSubscriptions;

/**
 * L'abbonamento da ufficio di prova (G10 §3): l'azienda «Rossi Abbigliamento»
 * sul mensile di «Base», con «Bonifico 30 gg fine mese», dal primo del mese
 * scorso. Il primo periodo è aperto, con la sua rata e il proforma; il
 * prossimo lo apre l'attività giornaliera.
 *
 * Ha bisogno dei contatti e dei piani di prova, che vengono prima. Nessuna
 * email parte: la posta del comando è finta. Il segno di prova sta nel codice
 * (`sub_demo-ufficio`); la pulizia toglie l'abbonamento con le sue rate e la
 * sua storia.
 */
final class OfficeSubscriptionsDemo
{
    public const KEY = 'abbonamenti-ufficio';

    public static function register(): void
    {
        DemoData::register(
            self::KEY,
            'Un abbonamento da ufficio sul piano Base, con le rate',
            static fn (): int => self::create(),
            static fn (): int => self::clear()
        );
    }

    /** @return int righe create */
    public static function create(): int
    {
        $code = DemoCode::forModel(Subscription::class, 'ufficio');

        $existing = Subscription::find(['code' => $code, 'deleted' => 'false'], 1);

        if (is_array($existing) && (int) ($existing['id'] ?? 0) > 0) {
            return 0;
        }

        $customer = Contact::find(['code' => DemoCode::forModel(Contact::class, 'rossi-abbigliamento'), 'deleted' => 'false'], 1);
        $price = PlanPrice::find(['code' => DemoCode::forModel(PlanPrice::class, 'base-mensile'), 'deleted' => 'false'], 1);

        if (!is_array($customer) || !isset($customer['id']) || !is_array($price) || !isset($price['id'])) {
            DemoData::note('L\'abbonamento da ufficio di prova vuole i contatti e i piani di prova: creali e rilancia il comando.');

            return 0;
        }

        $term = PaymentTerm::find(['code' => 'bonifico-30-fm', 'deleted' => 'false'], 1);

        if (!is_array($term) || !isset($term['id'])) {
            Defaults::seed(new DefaultRows());
            $term = PaymentTerm::find(['code' => 'bonifico-30-fm', 'deleted' => 'false'], 1);
        }

        $method = PaymentMethod::find(['provider' => 'bank_transfer', 'active' => 'true', 'deleted' => 'false'], 1);
        Mailer::useTransport(static fn (): bool => true);

        try {
            $id = OfficeSubscriptions::create([
                'customer_id' => (int) $customer['id'],
                'plan_price_id' => (int) $price['id'],
                // Dal primo del mese scorso: la rata del primo periodo scade a fine di questo mese.
                'starts_at' => (new DateTimeImmutable('first day of last month'))->format('Y-m-d'),
                'payment_method_id' => is_array($method) ? (int) ($method['id'] ?? 0) : 0,
                'payment_term_id' => is_array($term) ? (int) ($term['id'] ?? 0) : 0,
                'note' => 'Dato di prova.',
            ]);
        } finally {
            Mailer::useTransport(null);
        }

        Subscription::update(['code' => $code], $id);

        return 1 + count(self::rows(Payment::find(['subscription_id' => $id, 'deleted' => 'false'])));
    }

    /** @return int righe tolte */
    public static function clear(): int
    {
        $removed = 0;

        foreach (self::rows(Subscription::find("code LIKE 'sub\\_demo-%' AND (deleted = 'false' OR deleted = 'true')")) as $row) {
            if (!DemoCode::is((string) ($row['code'] ?? ''))) {
                continue;
            }

            $id = (int) $row['id'];
            $payments = self::rows(Payment::find("subscription_id = ".$id." AND (deleted = 'false' OR deleted = 'true')"));
            $removed += count($payments);

            // Prima la storia delle rate: la chiave esterna non lascia andare il pagamento.
            foreach ($payments as $payment) {
                sqlDelete(PaymentStatusLog::$table, 'payment_id = '.(int) $payment['id']);
            }

            sqlDelete(Payment::$table, 'subscription_id = '.$id);
            sqlDelete(SubscriptionStatusLog::$table, 'subscription_id = '.$id);
            sqlDelete(SubscriptionItemChange::$table, 'subscription_id = '.$id);
            sqlDelete(SubscriptionItem::$table, 'subscription_id = '.$id);
            sqlDelete(Subscription::$table, 'id = '.$id);
            $removed++;
        }

        return $removed;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        return array_key_exists('id', $found) ? [$found] : array_values(array_filter($found, 'is_array'));
    }
}
