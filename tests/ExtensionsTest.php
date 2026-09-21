<?php
/** php tests/ExtensionsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Extensions\Extensions;
use Wonder\Plugin\Gestionale\Extensions\GestionaleExtension;

final class PrimaEstensione extends GestionaleExtension
{
    public static array $chiamate = [];

    public function onStatusChanged(string $entity, int $entityId, string $field, string $from, string $to): void
    {
        self::$chiamate[] = 'prima';
    }

    public function beforeEmailSend(string $key, array $message): array
    {
        $message['subject'] = ($message['subject'] ?? '').' [prima]';

        return $message;
    }
}

final class SecondaEstensione extends GestionaleExtension
{
    public static array $chiamate = [];

    public function onStatusChanged(string $entity, int $entityId, string $field, string $from, string $to): void
    {
        self::$chiamate[] = 'seconda';
        PrimaEstensione::$chiamate[] = 'seconda';
    }

    public function beforeEmailSend(string $key, array $message): array
    {
        $message['subject'] = ($message['subject'] ?? '').' [seconda]';

        return $message;
    }
}

final class EstensioneChePerde extends GestionaleExtension
{
    public function onStatusChanged(string $entity, int $entityId, string $field, string $from, string $to): void
    {
        throw new RuntimeException('qualcosa è andato storto');
    }
}

final class NonUnaEstensione
{
    public function onStatusChanged(): void {}
}

check('senza estensioni non succede niente', function () {
    Extensions::use([]);

    return Extensions::run('onStatusChanged', 'order', 1, 'status', 'a', 'b') === null;
});

check('gli hook girano nell\'ordine dichiarato', function () {
    PrimaEstensione::$chiamate = [];
    Extensions::use([PrimaEstensione::class, SecondaEstensione::class]);
    Extensions::run('onStatusChanged', 'order', 1, 'status', 'a', 'b');

    return PrimaEstensione::$chiamate === ['prima', 'seconda'];
});

check('una classe che non estende la base viene saltata', function () {
    Extensions::use([NonUnaEstensione::class, 'ClasseCheNonEsiste', PrimaEstensione::class]);
    PrimaEstensione::$chiamate = [];
    Extensions::run('onStatusChanged', 'order', 1, 'status', 'a', 'b');

    return PrimaEstensione::$chiamate === ['prima'];
});

check('un hook che esplode non ferma gli altri', function () {
    PrimaEstensione::$chiamate = [];
    Extensions::use([EstensioneChePerde::class, PrimaEstensione::class]);
    Extensions::run('onStatusChanged', 'order', 1, 'status', 'a', 'b');

    return PrimaEstensione::$chiamate === ['prima'];
});

check('beforeEmailSend passa il messaggio da una all\'altra', function () {
    Extensions::use([PrimaEstensione::class, SecondaEstensione::class]);
    $messaggio = Extensions::filter('beforeEmailSend', ['subject' => 'Ordine'], 'order.confirmed');

    return ($messaggio['subject'] ?? '') === 'Ordine [prima] [seconda]';
});

check('un hook che non c\'è nella base non si esegue', function () {
    Extensions::use([PrimaEstensione::class]);

    return Extensions::run('hookInventato', 1) === null;
});

Extensions::use(null);

summary();
