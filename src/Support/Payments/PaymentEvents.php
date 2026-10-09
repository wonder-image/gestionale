<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use Throwable;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentEvent;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentProvider;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Providers\ProviderEvents;

/**
 * Gli eventi che il gateway manda: firma, registro e smistamento.
 *
 * Il registro (`ProviderEvents`) fa sì che un evento già elaborato non faccia
 * nulla due volte; uno ricevuto o fallito si riprende, dal webhook che lo
 * rimanda o dal riallineamento orario.
 */
final class PaymentEvents
{
    /** Il codice HTTP da rendere al gateway. */
    public static function handle(PaymentProvider $provider, string $raw, string $signature): int
    {
        $event = $provider->event($raw, $signature);

        if ($event === null) {
            return 400;
        }

        if ($event->environment === '' || $event->environment !== $provider->environment()) {
            // L'altro ambiente non si tocca: test e produzione non si mescolano.
            return 200;
        }

        if (!ProviderEvents::receive($provider->code(), $event->id, $event->type, $event->payload, $event->environment)) {
            return 200;
        }

        return self::process($provider, $event) ? 200 : 500;
    }

    /** `false` solo se l'evento va riprovato. */
    public static function process(PaymentProvider $provider, PaymentEvent $event, string $source = 'webhook'): bool
    {
        $code = $provider->code();
        $context = ['event' => $event->id, 'type' => $event->type, 'reference' => $event->reference];

        try {
            self::apply($code, $event, $source);
            ProviderEvents::markProcessed($code, $event->id, $event->environment);

            return true;
        } catch (PaymentMismatch $error) {
            // Riprovare non cambia i numeri: l'evento resta in «Da controllare».
            OnlinePayments::alert($code, 'payment.mismatch', $error->getMessage(), $context);
            ProviderEvents::markFailed($code, $event->id, $error->getMessage(), $event->environment, true);

            return true;
        } catch (Throwable $error) {
            Errors::internal($error, 'payments.event', $context);
            ProviderEvents::markFailed($code, $event->id, $error->getMessage(), $event->environment);

            return false;
        }
    }

    private static function apply(string $provider, PaymentEvent $event, string $source): void
    {
        match ($event->type) {
            'payment_intent.succeeded' => OnlinePayments::succeeded($provider, $event->reference, $event->amount, $event->currency, $event->orderId, $source),
            'payment_intent.payment_failed' => OnlinePayments::fail($provider, $event->reference, 'Pagamento non riuscito'),
            'payment_intent.canceled' => OnlinePayments::fail($provider, $event->reference, 'Pagamento annullato'),
            'charge.refunded' => OnlinePayments::refunded($provider, $event->reference, $event->refunds, $event->currency, $source),
            default => null,
        };
    }
}
