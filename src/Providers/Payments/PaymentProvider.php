<?php

namespace Wonder\Plugin\Gestionale\Providers\Payments;

/**
 * Un gateway di pagamento online, visto dal gestionale.
 *
 * Il gestionale non conosce Stripe: chiede a questo contratto di aprire un
 * pagamento, di dirne lo stato, di leggere un evento firmato e di cancellare
 * un intento. Tutto quello che il gateway restituisce arriva già tradotto in
 * valori nostri, con gli importi in centesimi.
 */
interface PaymentProvider
{
    /** Il codice scritto su `gst_payments.provider` (es. `stripe`). */
    public function code(): string;

    /** L'ambiente attivo adesso: `live` o `test`. */
    public function environment(): string;

    /** Le credenziali dell'ambiente attivo ci sono tutte. */
    public function connected(): bool;

    /**
     * Apre (o riprende) l'intento per il pagamento in attesa dell'ordine.
     *
     * @param array<string, mixed> $order riga di `gst_orders`
     * @param array<string, mixed> $payment riga di `gst_payments` in attesa
     */
    public function start(array $order, array $payment): PaymentStart;

    /** Lo stato dell'intento, riletto dal gateway: mai dalla query del browser. */
    public function status(string $reference): PaymentState;

    /** L'evento, se la firma regge; `null` se non regge. */
    public function event(string $raw, string $signature): ?PaymentEvent;

    /**
     * L'evento rifatto dal payload salvato, che la firma l'ha già passata
     * all'arrivo: lo usa il riallineamento per riprovare. `null` se il
     * payload non è un evento.
     *
     * @param array<string, mixed> $payload
     */
    public function replay(array $payload, string $environment): ?PaymentEvent;

    /** Cancella l'intento, così un pagamento tardivo non può più arrivare. */
    public function cancel(string $reference): void;
}
