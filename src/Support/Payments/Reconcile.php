<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\System\ProviderEvent;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentProvider;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentState;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Providers\ProviderEvents;

/**
 * La rete sotto il webhook: ogni ora chiede al fornitore come sono finiti i
 * pagamenti ancora aperti e rielabora gli eventi rimasti indietro.
 *
 * Interroga solo l'ambiente in cui il fornitore gira adesso. Un errore su un
 * pagamento o su un evento finisce nel log e non ferma gli altri.
 */
final class Reconcile
{
    /** Secondi dall'ultimo tentativo prima di riprovare, per tentativi già fatti. */
    public const BACKOFF = [1 => 300, 2 => 1800, 3 => 7200];

    /** Un evento ricevuto da meno di così lo sta ancora elaborando il webhook. */
    public const GRACE = 600;

    /** @return array{payments: int, events: int} */
    public static function run(PaymentProvider $provider, ?string $now = null): array
    {
        if (!$provider->connected()) {
            return ['payments' => 0, 'events' => 0];
        }

        $now ??= date('Y-m-d H:i:s');

        return [
            'payments' => self::payments($provider),
            'events' => self::events($provider, $now),
        ];
    }

    private static function payments(PaymentProvider $provider): int
    {
        $code = $provider->code();
        $done = 0;
        $rows = Payment::find(
            "provider = '".addslashes($code)."' AND type = 'payment' AND status IN ('pending', 'failed')"
            ." AND provider_reference <> '' AND environment = '".addslashes($provider->environment())."'"
            ." AND deleted = 'false'"
        );

        foreach (self::rows($rows) as $payment) {
            $orderId = (int) $payment['order_id'];
            $reference = (string) $payment['provider_reference'];
            $pending = (string) $payment['status'] === 'pending';
            $context = ['order' => $orderId, 'reference' => $reference];

            if ((string) (Order::findById($orderId)['status'] ?? '') !== 'pending') {
                continue;
            }

            try {
                $state = $provider->status($reference);

                if ($state->status === PaymentState::SUCCEEDED) {
                    OnlinePayments::succeeded($code, $reference, $state->amount, $state->currency, $state->orderId, 'cron');
                    ++$done;
                } elseif ($state->status === PaymentState::CANCELED && $pending) {
                    OnlinePayments::fail($code, $reference, 'Pagamento annullato');
                    ++$done;
                }
            } catch (PaymentMismatch $error) {
                if ($pending) {
                    // Una volta sola: la riga passa a «fallito» e dal giro dopo
                    // l'incoerenza va solo nel log.
                    OnlinePayments::alert($code, 'payment.mismatch', $error->getMessage(), $context);
                    Ledger::fail((int) $payment['id'], 'Importo o ordine diversi da quelli di '.$code);
                } else {
                    Errors::internal($error, 'payments.reconcile', $context);
                }
            } catch (Throwable $error) {
                Errors::internal($error, 'payments.reconcile', $context);
            }
        }

        return $done;
    }

    private static function events(PaymentProvider $provider, string $now): int
    {
        $code = $provider->code();
        $environment = $provider->environment();
        $received = date('Y-m-d H:i:s', strtotime($now) - self::GRACE);
        $done = 0;
        $rows = ProviderEvent::find(
            "provider = '".addslashes($code)."' AND environment = '".addslashes($environment)."' AND deleted = 'false'"
            ." AND ((status = 'received' AND creation <= '".addslashes($received)."')"
            ." OR (status = 'failed' AND attempts < ".ProviderEvents::MAX_ATTEMPTS.'))'
        );

        foreach (self::rows($rows) as $row) {
            $eventId = (string) $row['event_id'];

            if ((string) $row['status'] === 'failed' && !self::due($row, $now)) {
                continue;
            }

            try {
                $payload = json_decode((string) $row['payload'], true);
                $event = is_array($payload) ? $provider->replay($payload, $environment) : null;

                if ($event === null) {
                    // Il payload ha già passato la firma: se non si rilegge,
                    // riprovare non serve.
                    ProviderEvents::markFailed($code, $eventId, 'Evento non rileggibile', $environment, true);

                    continue;
                }

                if (PaymentEvents::process($provider, $event, 'cron')) {
                    ++$done;
                }
            } catch (Throwable $error) {
                Errors::internal($error, 'payments.reconcile', ['event' => $eventId]);
            }
        }

        return $done;
    }

    /** Se è passato abbastanza dall'ultimo tentativo dell'evento fallito. */
    private static function due(array $row, string $now): bool
    {
        $wait = self::BACKOFF[(int) ($row['attempts'] ?? 0)] ?? null;

        return $wait !== null && strtotime((string) $row['last_modified']) + $wait <= strtotime($now);
    }

    /** @return list<array> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
