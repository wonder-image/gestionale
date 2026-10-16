<?php

namespace Wonder\Plugin\Gestionale\Providers\Payments;

/**
 * Le chiamate a Stripe degli abbonamenti (G10 §2), su un ambiente solo.
 *
 * Tutto entra ed esce come array (`toArray()` degli oggetti di stripe-php):
 * così il finto dei test (`FakeSubscriptionGateway`) è un array in memoria, e
 * nessuna classe del gestionale dipende dagli oggetti dell'SDK.
 *
 * Le notifiche non passano di qui: si leggono dal loro contenuto.
 */
interface SubscriptionGateway
{
    /** `live` o `test`: l'ambiente delle chiavi. */
    public function environment(): string;

    /** Le chiavi dell'ambiente ci sono. */
    public function connected(): bool;

    /** @return array<string, mixed> */
    public function createProduct(array $params, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function createPrice(array $params, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function createTaxRate(array $params, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function createCoupon(array $params, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function createPromotionCode(array $params, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function updatePromotionCode(string $id, array $params): array;

    /** @return array<string, mixed> */
    public function createCustomer(array $params, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function createCheckoutSession(array $params, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function retrieveCheckoutSession(string $id): array;

    /** @return array<string, mixed> */
    public function retrieveSubscription(string $id): array;

    /** @return array<string, mixed> */
    public function updateSubscription(string $id, array $params): array;

    /** @return array<string, mixed> */
    public function createPortalSession(array $params): array;

    /** @return array<string, mixed> un servizio in più sull'abbonamento */
    public function createSubscriptionItem(array $params, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function updateSubscriptionItem(string $id, array $params): array;

    /** @return array<string, mixed> */
    public function deleteSubscriptionItem(string $id, array $params): array;

    /** @return array<string, mixed> le fasi di un abbonamento (un cambio dal prossimo rinnovo) */
    public function createSubscriptionSchedule(array $params, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function updateSubscriptionSchedule(string $id, array $params): array;

    /** @return array<string, mixed> */
    public function retrieveSubscriptionSchedule(string $id): array;

    /** @return array<string, mixed> lo schedule staccato: l'abbonamento resta com'è */
    public function releaseSubscriptionSchedule(string $id): array;

    /** @return array<string, mixed> la fattura che uscirebbe, senza crearla: `amount_due` è il conguaglio */
    public function previewInvoice(array $params): array;
}
