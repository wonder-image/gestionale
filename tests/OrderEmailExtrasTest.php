<?php
/** php tests/OrderEmailExtrasTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Extensions\OrderEmailExtras;
use Wonder\Plugin\Gestionale\Extensions\ProvidesOrderEmailExtras;

final class PrimoModulo implements ProvidesOrderEmailExtras
{
    public static function orderEmailExtras(string $key, array $order): array
    {
        return $key === 'confirmed' ? ['account_url' => '/account/'.$order['id'].'/'] : [];
    }
}

final class SecondoModulo implements ProvidesOrderEmailExtras
{
    public static function orderEmailExtras(string $key, array $order): array
    {
        return ['account_url' => '/altro/', 'url' => '/ordine/'];
    }
}

final class NonUnModulo {}

check('dagli entrypoint restano solo quelli che danno dati', fn () => OrderEmailExtras::fromEntrypoints([
    PrimoModulo::class, NonUnModulo::class, 'Classe\\Che\\Non\\Esiste', SecondoModulo::class,
]) === [PrimoModulo::class, SecondoModulo::class]);

check('il primo modulo che dà una chiave vince, gli altri completano', function () {
    OrderEmailExtras::use([PrimoModulo::class, SecondoModulo::class]);

    try {
        return OrderEmailExtras::for('confirmed', ['id' => 7]) === ['account_url' => '/account/7/', 'url' => '/ordine/']
            && OrderEmailExtras::for('shipped', ['id' => 7]) === ['account_url' => '/altro/', 'url' => '/ordine/'];
    } finally {
        OrderEmailExtras::use(null);
    }
});

check('senza moduli niente', function () {
    OrderEmailExtras::use([]);

    try {
        return OrderEmailExtras::for('confirmed', ['id' => 1]) === [];
    } finally {
        OrderEmailExtras::use(null);
    }
});

summary();
