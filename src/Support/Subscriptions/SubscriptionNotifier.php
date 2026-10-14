<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Resources\Subscriptions\SubscriptionResource;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Mail\Recipients;

/**
 * Manda le email di un abbonamento al cliente, all'indirizzo della sua
 * scheda. Come per gli ordini, un'email che non parte non ferma niente: chi
 * chiama riceve l'esito e va avanti.
 */
final class SubscriptionNotifier
{
    /** @return array{status: string, to: list<string>, sent: list<string>, failed: list<string>} */
    public static function send(string $key, int $subscriptionId): array
    {
        $subscription = Subscription::findById($subscriptionId);
        $empty = ['to' => [], 'sent' => [], 'failed' => []];

        if (!is_array($subscription) || (int) ($subscription['id'] ?? 0) <= 0) {
            return ['status' => 'not_found'] + $empty;
        }

        $contact = (int) ($subscription['customer_id'] ?? 0) > 0 ? Contact::findById((int) $subscription['customer_id']) : null;
        $to = Recipients::parse(is_array($contact) ? (string) ($contact['email'] ?? '') : '')['valid'];

        if ($to === []) {
            return ['status' => 'no_recipients'] + $empty;
        }

        $email = SubscriptionEmail::compose($key, $subscription, [
            'plan' => SubscriptionResource::planLabel((int) ($subscription['plan_price_id'] ?? 0)),
            'account_url' => self::accountUrl(),
        ]);

        return Mailer::send('subscription.'.$key, $to, $email['subject'], $email['body']);
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
