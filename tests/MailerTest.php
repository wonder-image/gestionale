<?php
/** php tests/MailerTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
// Il vero `sanitizeEcho()` del core: `shield()` si misura contro di lui.
require __DIR__ . '/../vendor/wonder-image/app/app/function/string/sanitize.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Extensions\Extensions;
use Wonder\Plugin\Gestionale\Extensions\GestionaleExtension;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;

final class OggettoConChiave extends GestionaleExtension
{
    public function beforeEmailSend(string $key, array $message): array
    {
        $message['subject'] = '['.$key.'] '.$message['subject'];

        return $message;
    }
}

final class NessunDestinatario extends GestionaleExtension
{
    public function beforeEmailSend(string $key, array $message): array
    {
        $message['to'] = [];

        return $message;
    }
}

final class DestinatariAMano extends GestionaleExtension
{
    public function beforeEmailSend(string $key, array $message): array
    {
        $message['to'] = 'capo@negozio.test; scritto-male';

        return $message;
    }
}

final class EstensioneRotta extends GestionaleExtension
{
    public function beforeEmailSend(string $key, array $message): array
    {
        throw new RuntimeException('guasto del sito');
    }
}

/** @var list<array{0: string, 1: string, 2: string}> */
$inviate = [];

$postino = static function (bool $esito = true) use (&$inviate): callable {
    return static function (string $to, string $subject, string $body) use (&$inviate, $esito): bool {
        $inviate[] = [$to, $subject, $body];

        return $esito;
    };
};

$pulisci = static function () use (&$inviate): void {
    $inviate = [];
    Extensions::use([]);
    Mailer::useTransport(null);
};

check('dopo sanitizeEcho il corpo resta quello scritto', fn () =>
    sanitizeEcho(Mailer::shield('<p>a &lt;b&gt; \ &amp; Tè</p>')) === '<p>a &#60;b&#62; \ &#38; T&#232;</p>'
);

check('una & da sola non si mangia il testo che segue', fn () =>
    sanitizeEcho(Mailer::shield('A & B')) === 'A &#38; B'
    && sanitizeEcho(Mailer::shield('&foo; x')) === '&#38;foo; x'
);

check('accenti ed emoji arrivano interi', fn () =>
    sanitizeEcho(Mailer::shield('già è Ü 😀')) === 'gi&#224; &#232; &#220; &#128512;'
);

check('le barre non spariscono', fn () =>
    sanitizeEcho(Mailer::shield('C:\cartella\file')) === 'C:\cartella\file'
);

check('una email per destinatario, con oggetto e corpo', function () use ($postino, $pulisci, &$inviate) {
    $pulisci();
    Mailer::useTransport($postino());
    $esito = Mailer::send('prova', ['a@x.it', 'b@y.it'], 'Oggetto', '<p>Corpo</p>');

    return $esito === ['status' => Mailer::SENT, 'to' => ['a@x.it', 'b@y.it'], 'sent' => ['a@x.it', 'b@y.it'], 'failed' => []]
        && $inviate === [['a@x.it', 'Oggetto', '<p>Corpo</p>'], ['b@y.it', 'Oggetto', '<p>Corpo</p>']];
});

check('l\'hook riceve la chiave dell\'email e può cambiare il messaggio', function () use ($postino, $pulisci, &$inviate) {
    $pulisci();
    Extensions::use([OggettoConChiave::class]);
    Mailer::useTransport($postino());
    Mailer::send('stock.low_stock', ['a@x.it'], '2 prodotti sotto scorta', 'x');

    return ($inviate[0][1] ?? '') === '[stock.low_stock] 2 prodotti sotto scorta';
});

check('un hook che toglie i destinatari ferma l\'invio', function () use ($postino, $pulisci, &$inviate) {
    $pulisci();
    Extensions::use([NessunDestinatario::class]);
    Mailer::useTransport($postino());
    $esito = Mailer::send('prova', ['a@x.it'], 'Oggetto', 'x');

    return $esito['status'] === Mailer::CANCELLED && $esito['to'] === [] && $inviate === [];
});

check('i destinatari scelti dall\'hook si ripuliscono come quelli delle impostazioni', function () use ($postino, $pulisci, &$inviate) {
    $pulisci();
    Extensions::use([DestinatariAMano::class]);
    Mailer::useTransport($postino());
    $esito = Mailer::send('prova', ['a@x.it'], 'Oggetto', 'x');

    return $esito['to'] === ['capo@negozio.test'] && count($inviate) === 1;
});

check('un\'estensione rotta non ferma l\'email', function () use ($postino, $pulisci, &$inviate) {
    $pulisci();
    Extensions::use([EstensioneRotta::class]);
    Mailer::useTransport($postino());
    $esito = Mailer::send('prova', ['a@x.it'], 'Oggetto', 'x');

    return $esito['status'] === Mailer::SENT && ($inviate[0][1] ?? '') === 'Oggetto';
});

check('se il server di posta rifiuta tutto, l\'invio è fallito', function () use ($postino, $pulisci) {
    $pulisci();
    Mailer::useTransport($postino(false));
    $esito = Mailer::send('prova', ['a@x.it', 'b@y.it'], 'Oggetto', 'x');

    return $esito['status'] === Mailer::FAILED && $esito['sent'] === [] && $esito['failed'] === ['a@x.it', 'b@y.it'];
});

check('un destinatario che salta non ferma gli altri', function () use ($pulisci) {
    $pulisci();
    Mailer::useTransport(static function (string $to): bool {
        if ($to === 'a@x.it') {
            throw new RuntimeException('casella piena');
        }

        return true;
    });
    $esito = Mailer::send('prova', ['a@x.it', 'b@y.it'], 'Oggetto', 'x');

    return $esito['status'] === Mailer::SENT && $esito['sent'] === ['b@y.it'] && $esito['failed'] === ['a@x.it'];
});

check('senza il sito avviato non parte niente, e lo dice', function () use ($pulisci) {
    $pulisci();
    $esito = Mailer::send('prova', ['a@x.it'], 'Oggetto', 'x');

    // Qui `sendMail()` non c'è: il test non deve mai mandare email vere.
    return !function_exists('sendMail') && $esito['status'] === Mailer::FAILED;
});

$pulisci();
Extensions::use(null);

summary();
