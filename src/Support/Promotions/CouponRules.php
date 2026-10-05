<?php

namespace Wonder\Plugin\Gestionale\Support\Promotions;

/**
 * Le regole di un coupon: dato il coupon (con quello che il database ne sa) e
 * il carrello, dice se vale e quanto toglie (§4 della spec di G6).
 *
 * I controlli vanno in un ordine fisso e **il primo che fallisce vince**: il
 * motivo che il cliente legge è sempre uno solo e non dipende da come il
 * database ha restituito le righe. I motivi sono le chiavi di
 * `gestionale.errors.coupon`.
 *
 * Classe pura: niente database, niente `date()`, niente eccezioni. Chi chiama
 * porta con sé i fatti che servono — selettore, clienti riservati, usi già
 * consumati, ordini precedenti — e l'ora. Un coupon con campi mancanti non
 * rompe niente: al massimo si rifiuta.
 *
 * Righe adatte: prodotti (non spedizione né commissioni), dentro il selettore,
 * con un totale da scontare, e — se il coupon lo chiede — a prezzo base, cioè
 * senza campagna né offerta. La spedizione gratuita non guarda le righe
 * adatte: conta solo la spesa minima sull'insieme dei prodotti.
 */
final class CouponRules
{
    /**
     * @param array<string, mixed> $coupon la riga del coupon più `scope`, `customers`, `used_total`, `used_by_customer`, `has_previous_orders`
     * @param array{channel?: string, customer_id?: int, email?: string, has_manual_discount?: bool, lines?: list<array{facts?: array<string, mixed>, price_source?: string, line_total?: float|int|string, is_product?: bool}>} $cart
     * @return array{ok: true, eligible: list<int>, eligible_total: float, amount: float}|array{ok: false, reason: string}
     */
    public static function check(array $coupon, array $cart, string $now): array
    {
        $type = (string) ($coupon['discount_type'] ?? '');
        $channel = (string) ($cart['channel'] ?? '');

        // Il credito non si spende nel carrello: ha un'altra strada.
        if ($type === 'store_credit' || !static::flag($coupon['active'] ?? false)) {
            return static::refuse('inactive');
        }

        if ($channel === '' || !static::flag($coupon['applies_'.$channel] ?? false)) {
            return static::refuse('channel');
        }

        $starts = static::date($coupon['starts_at'] ?? null);
        $ends = static::date($coupon['ends_at'] ?? null);

        if ($starts !== null && $now < $starts) {
            return static::refuse('not_started');
        }

        if ($ends !== null && $now > $ends) {
            return static::refuse('expired');
        }

        if (!empty($cart['has_manual_discount'])) {
            return static::refuse('has_manual_discount');
        }

        $reserved = array_map('intval', (array) ($coupon['customers'] ?? []));

        if ($reserved !== [] && !in_array((int) ($cart['customer_id'] ?? 0), $reserved, true)) {
            return static::refuse('not_yours');
        }

        $limit = (int) ($coupon['usage_limit'] ?? 0);

        if ($limit > 0 && (int) ($coupon['used_total'] ?? 0) >= $limit) {
            return static::refuse('exhausted');
        }

        $perCustomer = (int) ($coupon['usage_limit_per_customer'] ?? 0);

        if ($perCustomer > 0 && (int) ($coupon['used_by_customer'] ?? 0) >= $perCustomer) {
            return static::refuse('already_used');
        }

        if (static::flag($coupon['first_order_only'] ?? false) && !empty($coupon['has_previous_orders'])) {
            return static::refuse('not_first_order');
        }

        $freeShipping = $type === 'free_shipping';
        $lines = array_values((array) ($cart['lines'] ?? []));
        $eligible = $freeShipping ? [] : static::eligible($coupon, $lines);

        if (!$freeShipping && $eligible === []) {
            return static::refuse('no_eligible_products');
        }

        $total = $freeShipping ? static::productsTotal($lines) : static::total($lines, $eligible);

        if ($total < round((float) ($coupon['min_order_amount'] ?? 0), 2)) {
            return static::refuse('min_order');
        }

        return [
            'ok' => true,
            'eligible' => $eligible,
            'eligible_total' => $total,
            'amount' => $freeShipping ? 0.0 : static::amount($type, (float) ($coupon['discount_value'] ?? 0), $total),
        ];
    }

    /** @return array{ok: false, reason: string} */
    private static function refuse(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }

    /**
     * Gli indici delle righe che lo sconto può toccare.
     *
     * @param array<string, mixed> $coupon
     * @param list<array<string, mixed>> $lines
     * @return list<int>
     */
    private static function eligible(array $coupon, array $lines): array
    {
        $scope = (array) ($coupon['scope'] ?? []);
        $skipDiscounted = static::flag($coupon['exclude_discounted_products'] ?? false);
        $eligible = [];

        foreach ($lines as $index => $line) {
            if (empty($line['is_product']) || static::money($line['line_total'] ?? 0) <= 0.0) {
                continue;
            }

            if ($skipDiscounted && (string) ($line['price_source'] ?? 'base') !== 'base') {
                continue;
            }

            if (ScopeMatcher::matches($scope, (array) ($line['facts'] ?? []))) {
                $eligible[] = $index;
            }
        }

        return $eligible;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param list<int> $indexes
     */
    private static function total(array $lines, array $indexes): float
    {
        $total = 0.0;

        foreach ($indexes as $index) {
            $total += static::money($lines[$index]['line_total'] ?? 0);
        }

        return round($total, 2);
    }

    /** @param list<array<string, mixed>> $lines */
    private static function productsTotal(array $lines): float
    {
        $total = 0.0;

        foreach ($lines as $line) {
            if (!empty($line['is_product'])) {
                $total += max(0.0, static::money($line['line_total'] ?? 0));
            }
        }

        return round($total, 2);
    }

    /** Lo sconto sulla merce adatta, mai più grande della merce. */
    private static function amount(string $type, float $value, float $base): float
    {
        if ($value <= 0.0 || $base <= 0.0) {
            return 0.0;
        }

        $amount = match ($type) {
            'percent' => round($base * min($value, 100.0) / 100, 2),
            'amount' => round($value, 2),
            default => 0.0,
        };

        return min($amount, $base);
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /** La data come testo confrontabile, `null` se manca o è quella «vuota» di MySQL. */
    private static function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || str_starts_with($value, '0000-00-00') ? null : $value;
    }

    private static function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
