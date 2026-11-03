<?php

namespace Wonder\Plugin\Gestionale\Support\Promotions;

use Wonder\Backend\Support\SpreadsheetText;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponCode;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Sql\Transaction;

/**
 * I codici in blocco di un coupon (gestionale#113): N codici monouso sotto un
 * coupon modello, che tiene le regole.
 *
 * Un codice è `PREFISSO-XXXXXXXX`, con la parte casuale presa da un alfabeto
 * senza caratteri che si confondono (niente 0/O, 1/I/L). È unico fra i coupon
 * e i codici generati, eliminati compresi: si controlla prima di scrivere,
 * l'indice unico di `gst_coupon_codes` è l'ultima difesa.
 */
final class CouponCodes
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const MAX_QUANTITY = 10000;
    public const MAX_PREFIX = 20;

    /** Quanti codici si controllano nel database con una query. */
    private const CHUNK = 500;

    /**
     * Genera `$quantity` codici sotto il coupon, li scrive e accende `batch`
     * sul coupon: da qui il suo codice non vale più, valgono solo i generati.
     * ecommerce#51 chiama `generate($couponId, 1, 'CARRELLO')`.
     *
     * @return list<string> i codici scritti
     */
    public static function generate(int $couponId, int $quantity, string $prefix = '', int $length = 8): array
    {
        $prefix = self::prefix($prefix);
        self::checkQuantity($quantity);

        return Transaction::run(static function () use ($couponId, $quantity, $prefix, $length): array {
            $coupon = Coupon::find(['id' => $couponId, 'deleted' => 'false'], 1);

            if (!is_array($coupon) || (int) ($coupon['id'] ?? 0) <= 0) {
                throw UserError::make('coupon.unknown');
            }

            if ((string) ($coupon['applies_subscriptions'] ?? 'false') === 'true') {
                throw UserError::make('coupon.batch_subscriptions');
            }

            $codes = self::make($quantity, $prefix, $length, null, self::taken(...));

            foreach ($codes as $code) {
                CouponCode::create(['coupon_id' => $couponId, 'code' => $code]);
            }

            if ((string) ($coupon['batch'] ?? 'false') !== 'true') {
                Coupon::update(['batch' => 'true'], $couponId);
            }

            return $codes;
        });
    }

    /**
     * I codici, diversi fra loro e da quelli che `$taken` dice già presi.
     * Pura: il caso e il database arrivano da fuori (i test li sostituiscono).
     *
     * @param callable(int): int|null $random un intero in [0, $max]
     * @param callable(list<string>): list<string>|null $taken quelli già presi fra i candidati
     * @return list<string>
     */
    public static function make(int $quantity, string $prefix = '', int $length = 8, ?callable $random = null, ?callable $taken = null): array
    {
        $prefix = self::prefix($prefix);
        self::checkQuantity($quantity);
        $length = max(6, min(16, $length));
        $random ??= static fn (int $max): int => random_int(0, $max);
        $codes = [];
        $attempts = 0;

        while (count($codes) < $quantity) {
            // Con 32^8 combinazioni un giro a vuoto è un caso raro; tanti di fila vogliono dire che il caso è rotto.
            if (++$attempts > 50) {
                throw UserError::make('coupon.batch_failed');
            }

            $candidates = [];

            while (count($candidates) < min(self::CHUNK, $quantity - count($codes))) {
                $code = self::code($prefix, $length, $random);

                if (!isset($codes[$code]) && !isset($candidates[$code])) {
                    $candidates[$code] = true;
                }
            }

            $busy = $taken === null ? [] : array_flip(array_map('strtoupper', $taken(array_keys($candidates))));

            foreach (array_keys($candidates) as $code) {
                if (!isset($busy[$code])) {
                    $codes[$code] = true;
                }
            }
        }

        return array_keys($codes);
    }

    /**
     * Il prefisso in maiuscolo; vuoto va bene. Solo lettere, cifre, `-` e `_`,
     * al più 20 caratteri.
     */
    public static function prefix(string $prefix): string
    {
        $prefix = strtoupper(trim($prefix));

        if (strlen($prefix) > self::MAX_PREFIX || preg_match('/^[A-Z0-9_-]*$/', $prefix) !== 1) {
            throw UserError::make('coupon.batch_prefix');
        }

        return $prefix;
    }

    /**
     * I codici del coupon, con chi li ha usati: il codice è usato se ha un
     * utilizzo non rilasciato.
     *
     * @return list<array{code: string, used: bool, order_number: string, used_at: string}>
     */
    public static function of(int $couponId): array
    {
        $used = [];

        foreach (self::rows(CouponRedemption::find(['coupon_id' => $couponId])) as $redemption) {
            $released = trim((string) ($redemption['released_at'] ?? ''));

            if ((int) ($redemption['coupon_code_id'] ?? 0) > 0 && ($released === '' || str_starts_with($released, '0000-00-00'))) {
                $used[(int) $redemption['coupon_code_id']] = $redemption;
            }
        }

        $rows = [];

        foreach (self::rows(CouponCode::find(['coupon_id' => $couponId], null, 'id', 'ASC')) as $code) {
            $redemption = $used[(int) $code['id']] ?? null;
            $order = $redemption === null ? null : Order::findById((int) $redemption['order_id']);
            $rows[] = [
                'code' => (string) $code['code'],
                'used' => $redemption !== null,
                'order_number' => is_array($order) ? (string) ($order['order_number'] ?? '') : '',
                'used_at' => $redemption === null ? '' : substr((string) ($redemption['redeemed_at'] ?? ''), 0, 10),
            ];
        }

        return $rows;
    }

    /**
     * Il CSV di «Scarica i codici»: `;`, BOM per Excel, celle di testo.
     *
     * @param list<array{code: string, used: bool, order_number: string, used_at: string}> $rows
     */
    public static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ['Codice', 'Usato', 'Ordine', 'Data'], ';', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, [
                SpreadsheetText::cell($row['code']),
                $row['used'] ? 'Sì' : 'No',
                SpreadsheetText::cell($row['order_number']),
                $row['used_at'],
            ], ';', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return "\u{FEFF}".$csv;
    }

    private static function checkQuantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw UserError::make('coupon.batch_quantity');
        }
    }

    /** @param callable(int): int $random */
    private static function code(string $prefix, int $length, callable $random): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[$random($max)];
        }

        return $prefix === '' ? $code : $prefix.'-'.$code;
    }

    /**
     * Fra i candidati, quelli già presi da un coupon o da un codice generato,
     * eliminati compresi. I candidati hanno solo lettere, cifre, `-` e `_`.
     *
     * @param list<string> $candidates
     * @return list<string>
     */
    private static function taken(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $in = "'".implode("', '", $candidates)."'";
        $taken = [];

        foreach ([Coupon::$table, CouponCode::$table] as $table) {
            foreach (self::rows(sqlSelect($table, '`code` IN ('.$in.')', null, null, null, '`code`')->row) as $row) {
                $taken[] = (string) $row['code'];
            }
        }

        return $taken;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        return array_key_exists('id', $found) || array_key_exists('code', $found) ? [$found] : array_values(array_filter($found, 'is_array'));
    }
}
