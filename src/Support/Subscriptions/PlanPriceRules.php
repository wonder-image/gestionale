<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * Le regole di un prezzo di un piano (G10 §1.2), pure: niente database.
 *
 * - un nome;
 * - prezzo non negativo, prezzo scontato (se c'è) più basso del prezzo;
 * - mesi per addebito coerenti con l'intervallo: da 1 a 12 per il mensile,
 *   multipli di 12 per l'annuale;
 * - vincolo e giorni di prova non negativi;
 * - il prezzo di rinnovo, se c'è, è un altro prezzo di un piano vivo: chi
 *   chiama passa gli id ammessi.
 *
 * I numeri arrivano già letti dal form, col punto decimale.
 */
final class PlanPriceRules
{
    /**
     * @param array<string, mixed> $price una riga di `gst_plan_prices`
     * @param list<int> $renewalIds i prezzi che possono fare da rinnovo
     */
    public static function assert(array $price, array $renewalIds = []): void
    {
        if (trim((string) ($price['name'] ?? '')) === '') {
            throw UserError::make('plan.price_name_required');
        }

        $amount = trim((string) ($price['price'] ?? ''));

        if ($amount === '' || !is_numeric($amount) || (float) $amount < 0.0) {
            throw UserError::make('plan.price_invalid', ['name' => (string) $price['name']]);
        }

        $sale = trim((string) ($price['sale_price'] ?? ''));

        if ($sale !== '' && (float) $sale > 0.0 && (float) $sale >= (float) $amount) {
            throw UserError::make('plan.sale_not_lower', ['name' => (string) $price['name']]);
        }

        $interval = (string) ($price['billing_interval'] ?? 'month');
        $term = (int) ($price['term_months'] ?? 0);
        $coherent = match ($interval) {
            'month' => $term >= 1 && $term <= 12,
            'year' => $term >= 12 && $term % 12 === 0,
            default => false,
        };

        if (!$coherent) {
            throw UserError::make('plan.term_mismatch', ['name' => (string) $price['name']]);
        }

        if ((int) ($price['commitment_months'] ?? 0) < 0 || (int) ($price['trial_days'] ?? 0) < 0) {
            throw UserError::make('plan.negative_period', ['name' => (string) $price['name']]);
        }

        $renewal = (int) ($price['renewal_plan_price_id'] ?? 0);

        if ($renewal > 0 && ($renewal === (int) ($price['id'] ?? 0) || !in_array($renewal, $renewalIds, true))) {
            throw UserError::make('plan.renewal_invalid', ['name' => (string) $price['name']]);
        }
    }

    /** I mesi di un addebito proposti per un intervallo, se il form non li dice. */
    public static function defaultTerm(string $interval): int
    {
        return $interval === 'year' ? 12 : 1;
    }
}
