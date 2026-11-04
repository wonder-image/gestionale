<?php
/** php tests/CouponCodesTest.php — gestionale#113, i codici in blocco */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Promotions\CouponCodes;

$errore = static function (callable $fn): string {
    try {
        $fn();
    } catch (UserError $e) {
        return $e->key();
    }

    return '';
};

check('mille codici tutti diversi, col prefisso e l\'alfabeto senza ambigui', function () {
    $codici = CouponCodes::make(1000, 'natale');

    return count($codici) === 1000
        && count(array_unique($codici)) === 1000
        && array_filter($codici, static fn (string $c): bool => preg_match('/^NATALE-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/', $c) !== 1) === [];
});

check('senza prefisso il codice è solo la parte casuale', fn () =>
    preg_match('/^[A-Z2-9]{8}$/', CouponCodes::make(1)[0]) === 1
);

check('un caso che ripete i codici non dà doppioni', function () {
    $giro = 0;
    // Ogni codice esce due volte di fila: il secondo va scartato.
    $caso = static function (int $max) use (&$giro): int {
        return intdiv($giro++, 16) % ($max + 1);
    };
    $codici = CouponCodes::make(5, 'X', 8, $caso);

    return count($codici) === 5 && count(array_unique($codici)) === 5;
});

check('i codici già presi si scartano e se ne fanno altri', function () {
    $presi = [];
    $codici = CouponCodes::make(20, 'P', 8, null, static function (array $candidati) use (&$presi): array {
        // Al primo giro metà sono «già presi».
        if ($presi === []) {
            $presi = array_slice($candidati, 0, 10);

            return array_map('strtolower', $presi);
        }

        return [];
    });

    return count($codici) === 20 && array_intersect($codici, $presi) === [] && count(array_unique($codici)) === 20;
});

check('se il caso non dà mai codici liberi si ferma con batch_failed', fn () =>
    $errore(fn () => CouponCodes::make(3, 'Z', 8, null, static fn (array $c): array => $c)) === 'coupon.batch_failed'
);

check('quantità fuori da 1..10000 e prefissi sbagliati si rifiutano', fn () =>
    $errore(fn () => CouponCodes::make(0)) === 'coupon.batch_quantity'
    && $errore(fn () => CouponCodes::make(10001)) === 'coupon.batch_quantity'
    && $errore(fn () => CouponCodes::make(1, 'con spazio')) === 'coupon.batch_prefix'
    && $errore(fn () => CouponCodes::make(1, str_repeat('A', 21))) === 'coupon.batch_prefix'
    && CouponCodes::prefix(' promo_2-x ') === 'PROMO_2-X'
);

check('csv: punto e virgola, BOM, celle di testo', function () {
    $csv = CouponCodes::csv([
        ['code' => 'NATALE-ABCD2345', 'used' => true, 'order_number' => '2026/100001', 'used_at' => '2026-11-03'],
        ['code' => '=SOMMA', 'used' => false, 'order_number' => '', 'used_at' => ''],
    ]);
    $righe = explode("\n", trim($csv));

    return str_starts_with($csv, "\u{FEFF}Codice;Usato;Ordine;Data")
        && $righe[1] === 'NATALE-ABCD2345;Sì;2026/100001;2026-11-03'
        && $righe[2] === "'=SOMMA;No;;";
});

summary();
