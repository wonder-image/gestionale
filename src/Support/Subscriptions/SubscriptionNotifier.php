<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Resources\Subscriptions\SubscriptionResource;
use Wonder\Plugin\Gestionale\Support\Contacts\DocumentReferents;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Mail\Recipients;

/**
 * Manda le email di un abbonamento al cliente, all'indirizzo della sua
 * scheda (l'azienda, con i referenti in copia). Come per gli ordini, un'email che non parte non ferma niente: chi
 * chiama riceve l'esito e va avanti.
 */
final class SubscriptionNotifier
{
    /**
     * @param array<string, string> $attachments percorso => nome, come gli allegati del core
     * @return array{status: string, to: list<string>, sent: list<string>, failed: list<string>}
     */
    public static function send(string $key, int $subscriptionId, array $attachments = []): array
    {
        $subscription = Subscription::findById($subscriptionId);
        $empty = ['to' => [], 'sent' => [], 'failed' => []];

        if (!is_array($subscription) || (int) ($subscription['id'] ?? 0) <= 0) {
            return ['status' => 'not_found'] + $empty;
        }

        $contact = (int) ($subscription['customer_id'] ?? 0) > 0 ? Contact::findById((int) $subscription['customer_id']) : null;
        $personEmail = is_array($contact) ? (string) ($contact['email'] ?? '') : '';
        $companyId = (int) ($subscription['company_id'] ?? 0);
        $company = $companyId > 0 ? Company::findById($companyId) : null;
        $company = is_array($company) && (int) ($company['id'] ?? 0) > 0 ? $company : null;
        // Con l'azienda scrive a lei (o al referente se non ha email), gli altri in copia come per gli ordini.
        $address = $company !== null && trim((string) ($company['email'] ?? '')) !== '' ? (string) $company['email'] : $personEmail;
        $to = Recipients::parse($address)['valid'];
        // Il brand non è dell'abbonamento: contano solo le aree (`subscription.` + la chiave).
        $copies = $companyId > 0 ? DocumentReferents::copyEmails(['email' => implode(',', $to), 'company_brand_id' => 0] + $subscription, $company, 'subscription.'.$key) : [];

        if ($to === []) {
            return ['status' => 'no_recipients'] + $empty;
        }

        $email = SubscriptionEmail::compose($key, $subscription, [
            'plan' => SubscriptionResource::planLabel(SubscriptionItems::priceIdOf($subscription)),
            'account_url' => self::accountUrl(),
        ]);

        return Mailer::send('subscription.'.$key, $to, $email['subject'], $email['body'], $attachments, $copies);
    }

    /** La sezione *Abbonamenti* dell'area cliente, se il sito ha la rotta. */
    public static function accountUrl(): string
    {
        if (!function_exists('__r')) {
            return '';
        }

        try {
            return (string) __r('account.subscriptions');
        } catch (Throwable) {
            return '';
        }
    }
}
