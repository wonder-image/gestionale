<?php
/** php tests/StripeBillingTest.php */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/harness.php';
require __DIR__.'/supporto/FakeSubscriptionGateway.php';

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Wonder\Plugin\Gestionale\Providers\Payments\StripeBilling;
use Wonder\Plugin\Stripe\Stripe;

/** Il client HTTP di stripe-php sostituito: ricorda le richieste e risponde con un oggetto. */
final class RegistroHttp implements ClientInterface
{
    /** @var list<array{method: string, url: string, headers: list<string>, params: array<string, mixed>, retries: mixed}> */
    public array $requests = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params, 'retries' => $maxNetworkRetries];
        $id = basename(parse_url($absUrl, PHP_URL_PATH) ?: '');

        return [json_encode(['id' => $id, 'object' => 'finto', 'amount_due' => 1200]), 200, []];
    }

    /** @return array{method: string, url: string, headers: list<string>, params: array<string, mixed>} */
    public function last(): array
    {
        return $this->requests[array_key_last($this->requests)];
    }

    public function header(string $name): ?string
    {
        foreach ($this->last()['headers'] as $header) {
            if (stripos($header, $name.':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }
}

$http = new RegistroHttp();
ApiRequestor::setHttpClient($http);
$billing = new StripeBilling('sk_test_finta', 'acct_1', 'test');

$chiamata = static function (string $method, string $path) use ($http): bool {
    $last = $http->last();

    return $last['method'] === $method && $last['url'] === 'https://api.stripe.com'.$path;
};

check('un servizio in più: POST /v1/subscription_items, con il conto e la chiave di idempotenza', function () use ($billing, $http, $chiamata) {
    $made = $billing->createSubscriptionItem(['subscription' => 'sub_1', 'price' => 'price_1'], 'chiave-1');

    return $chiamata('post', '/v1/subscription_items') && $http->last()['params']['price'] === 'price_1'
        && $http->header('Stripe-Account') === 'acct_1' && $http->header('Idempotency-Key') === 'chiave-1'
        && is_array($made) && $made['object'] === 'finto';
});

check('cambiare un servizio: POST /v1/subscription_items/{id}', function () use ($billing, $http, $chiamata) {
    $billing->updateSubscriptionItem('si_1', ['price' => 'price_2', 'proration_behavior' => 'none']);

    return $chiamata('post', '/v1/subscription_items/si_1') && $http->last()['params']['proration_behavior'] === 'none'
        && $http->header('Stripe-Account') === 'acct_1';
});

check('togliere un servizio: DELETE /v1/subscription_items/{id}', function () use ($billing, $chiamata) {
    $billing->deleteSubscriptionItem('si_1', ['proration_behavior' => 'none']);

    return $chiamata('delete', '/v1/subscription_items/si_1');
});

check('gli schedule: crea, cambia, rileggi', function () use ($billing, $http, $chiamata) {
    $billing->createSubscriptionSchedule(['from_subscription' => 'sub_1'], 'chiave-2');
    $creato = $chiamata('post', '/v1/subscription_schedules') && $http->header('Idempotency-Key') === 'chiave-2';
    $billing->updateSubscriptionSchedule('sub_sched_1', ['end_behavior' => 'release']);
    $cambiato = $chiamata('post', '/v1/subscription_schedules/sub_sched_1');
    $billing->retrieveSubscriptionSchedule('sub_sched_1');

    return $creato && $cambiato && $chiamata('get', '/v1/subscription_schedules/sub_sched_1');
});

check('staccare uno schedule: POST /v1/subscription_schedules/{id}/release', function () use ($billing, $http, $chiamata) {
    $billing->releaseSubscriptionSchedule('sub_sched_1');

    return $chiamata('post', '/v1/subscription_schedules/sub_sched_1/release') && $http->header('Stripe-Account') === 'acct_1';
});

check('l\'anteprima della fattura: POST /v1/invoices/create_preview', function () use ($billing, $chiamata) {
    $anteprima = $billing->previewInvoice(['subscription' => 'sub_1']);

    return $chiamata('post', '/v1/invoices/create_preview') && $anteprima['amount_due'] === 1200;
});

check('le chiamate portano retry 2 e la versione API del core', function () use ($billing, $http) {
    $billing->deleteSubscriptionItem('si_9', []);

    return $http->last()['retries'] === 2
        && $http->header('Stripe-Version') === Stripe::API_VERSION
        && Stripe::API_VERSION === '2025-08-27.basil';
});

ApiRequestor::setHttpClient(null);

$fake = new FakeSubscriptionGateway();

check('il finto: un servizio in più ha un id si_fake_N e rispetta l\'idempotenza', function () use ($fake) {
    $a = $fake->createSubscriptionItem(['subscription' => 'sub_1', 'price' => 'price_1'], 'k1');
    $b = $fake->createSubscriptionItem(['subscription' => 'sub_1', 'price' => 'price_1'], 'k1');

    return str_starts_with($a['id'], 'si_fake_') && $a['id'] === $b['id'] && $a['object'] === 'subscription_item'
        && $fake->count('subscription_item.create') === 2 && $fake->last('subscription_item.create')['price'] === 'price_1';
});

check('il finto: cambiare e togliere un servizio si registrano', function () use ($fake) {
    $id = $fake->createSubscriptionItem(['price' => 'price_1'], '')['id'];
    $cambiato = $fake->updateSubscriptionItem($id, ['price' => 'price_2']);
    $tolto = $fake->deleteSubscriptionItem($id, ['proration_behavior' => 'none']);

    return $cambiato['price'] === 'price_2' && $fake->last('subscription_item.update') === ['id' => $id, 'price' => 'price_2']
        && $tolto === ['id' => $id, 'object' => 'subscription_item', 'deleted' => true]
        && $fake->last('subscription_item.delete') === ['id' => $id, 'proration_behavior' => 'none'];
});

check('il finto: gli schedule hanno un id sub_sched_fake_N, si cambiano e si rileggono', function () use ($fake) {
    $schedule = $fake->createSubscriptionSchedule(['from_subscription' => 'sub_1'], 'k2');
    $fake->updateSubscriptionSchedule($schedule['id'], ['end_behavior' => 'release']);
    $riletto = $fake->retrieveSubscriptionSchedule($schedule['id']);

    return str_starts_with($schedule['id'], 'sub_sched_fake_') && $riletto['end_behavior'] === 'release'
        && $riletto['from_subscription'] === 'sub_1' && $fake->count('subscription_schedule.update') === 1
        && $fake->count('subscription_schedule.retrieve') === 1;
});

check('il finto: lo schedule staccato è released, failNext fa fallire una chiamata sola', function () use ($fake) {
    $schedule = $fake->createSubscriptionSchedule(['from_subscription' => 'sub_2'], 'k3');
    $fake->releaseSubscriptionSchedule($schedule['id']);
    $fake->failNext = 'subscription_item.create';
    try {
        $fake->createSubscriptionItem(['price' => 'price_1'], 'k4');
        $fallita = false;
    } catch (RuntimeException) {
        $fallita = true;
    }

    return $fake->retrieveSubscriptionSchedule($schedule['id'])['status'] === 'released' && $fallita
        && $fake->failNext === null && isset($fake->createSubscriptionItem(['price' => 'price_1'], 'k5')['id']);
});

check('il finto: l\'anteprima ha amount_due, quello impostato dal test', function () use ($fake) {
    $fake->previewAmountDue = 850;
    $anteprima = $fake->previewInvoice(['subscription' => 'sub_1']);

    return $anteprima['object'] === 'invoice' && $anteprima['amount_due'] === 850
        && $fake->last('invoice.preview') === ['subscription' => 'sub_1'];
});

summary();
