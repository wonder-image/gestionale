<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use DateTimeImmutable;
use Wonder\App\Support\DefaultRows;
use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
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
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Subscriptions\OfficeSubscriptions;

/**
 * L'abbonamento da ufficio di prova (G10 §3): l'azienda «Rossi Abbigliamento»
 * sul mensile di «Base», con «Bonifico 30 gg fine mese», dal primo del mese
 * scorso. Il primo periodo è aperto, con la sua rata e il proforma; il
 * prossimo lo apre l'attività giornaliera.
 *
 * Il secondo (G10b), per «Anna Verdi» (un cliente ha un abbonamento solo),
 * compone i tre servizi di prova: «Dominio .it» col nome del dominio,
 * «Hosting» legato al dominio e «Assistenza», dalla stessa data.
 *
 * Ha bisogno dei contatti e dei piani di prova, che vengono prima. Nessuna
 * email parte: la posta del comando è finta. Il segno di prova sta nel codice
 * (`sub_demo-ufficio`, `sub_demo-servizi`); la pulizia toglie gli abbonamenti
 * con le loro rate e la loro storia.
 */
final class OfficeSubscriptionsDemo
{
    public const KEY = 'abbonamenti-ufficio';

    public static function register(): void
    {
        DemoData::register(
            self::KEY,
            'Due abbonamenti da ufficio (Base; dominio, hosting e assistenza), con le rate',
            static fn (): int => self::create(),
            static fn (): int => self::clear()
        );
    }

    /** @return int righe create */
    public static function create(): int
    {
        $customer = Contact::find(['code' => DemoCode::forModel(Contact::class, 'rossi-abbigliamento'), 'deleted' => 'false'], 1);
        $base = self::priceId('base-mensile');

        if (!is_array($customer) || !isset($customer['id']) || $base <= 0) {
            if (!self::exists('ufficio') || !self::exists('servizi')) {
                DemoData::note('Gli abbonamenti da ufficio di prova vogliono i contatti e i piani di prova: creali e rilancia il comando.');
            }

            return 0;
        }

        $created = self::subscription('ufficio', (int) $customer['id'], ['plan_price_id' => $base]);
        $domain = self::priceId('dominio-annuale');
        $hosting = self::priceId('hosting-annuale');
        $support = self::priceId('assistenza-mensile');
        $name = Customization::find(['code' => DemoCode::forModel(Customization::class, 'nome-dominio'), 'deleted' => 'false'], 1);

        $private = Contact::find(['code' => DemoCode::forModel(Contact::class, 'verdi'), 'deleted' => 'false'], 1);

        if ($domain > 0 && $hosting > 0 && $support > 0 && is_array($name) && isset($name['id']) && is_array($private) && isset($private['id'])) {
            $created += self::subscription('servizi', (int) $private['id'], ['items' => [
                ['plan_price_id' => $domain, 'customization' => [(int) $name['id'] => 'annaverdi.it']],
                ['plan_price_id' => $hosting, 'parent' => 0],
                ['plan_price_id' => $support],
            ]]);
        }

        return $created;
    }

    /**
     * Un abbonamento da ufficio col segno `$ref`, se manca.
     *
     * @param array<string, mixed> $values il prezzo o le righe
     * @return int righe create: l'abbonamento e le sue rate
     */
    private static function subscription(string $ref, int $customerId, array $values): int
    {
        $code = DemoCode::forModel(Subscription::class, $ref);

        if (self::exists($ref)) {
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
            $id = OfficeSubscriptions::create($values + [
                'customer_id' => $customerId,
                // Dal primo del mese scorso: la rata del primo periodo scade a fine di questo mese.
                'starts_at' => (new DateTimeImmutable('first day of last month'))->format('Y-m-d'),
                'payment_method_id' => is_array($method) ? (int) ($method['id'] ?? 0) : 0,
                'payment_term_id' => is_array($term) ? (int) ($term['id'] ?? 0) : 0,
                'note' => 'Dato di prova.',
            ]);
        } catch (UserError $error) {
            // Il cliente ha già un abbonamento suo: quello di prova non si fa.
            DemoData::note('Abbonamento di prova «'.$ref.'» non creato: '.$error->getMessage());

            return 0;
        } finally {
            Mailer::useTransport(null);
        }

        Subscription::update(['code' => $code], $id);

        return 1 + count(self::rows(Payment::find(['subscription_id' => $id, 'deleted' => 'false'])));
    }

    private static function exists(string $ref): bool
    {
        $row = Subscription::find(['code' => DemoCode::forModel(Subscription::class, $ref), 'deleted' => 'false'], 1);

        return is_array($row) && (int) ($row['id'] ?? 0) > 0;
    }

    /** Il prezzo di prova `$ref` (`base-mensile`), 0 se manca. */
    private static function priceId(string $ref): int
    {
        $row = PlanPrice::find(['code' => DemoCode::forModel(PlanPrice::class, $ref), 'deleted' => 'false'], 1);

        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
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
