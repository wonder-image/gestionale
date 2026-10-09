<?php
declare(strict_types=1);

use Wonder\Plugin\Gestionale\Extensions\Extensions;
use Wonder\Plugin\Gestionale\Extensions\GestionaleExtension;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;

/** Mette la chiave dell'email davanti all'oggetto, così il test la riconosce. */
final class PrefissoChiave extends GestionaleExtension
{
    public function beforeEmailSend(string $key, array $message): array
    {
        $message['subject'] = '['.$key.'] '.$message['subject'];

        return $message;
    }
}

/** Gli indirizzi a cui il commerciante vuole gli avvisi (vuoto: nessuno). */
function destinatariCommerciante(string $indirizzi): void
{
    Setting::update(['merchant_notification_emails' => $indirizzi], (int) (Setting::current()['id'] ?? 1));
}

/**
 * Esegue il corpo senza spedire niente e rende le email che avrebbe mandato.
 *
 * @return list<array{to: string, subject: string, body: string}> l'oggetto porta «[chiave] » davanti
 */
function conPosta(callable $corpo): array
{
    $partite = [];
    Extensions::use([PrefissoChiave::class]);
    Mailer::useTransport(static function (string $to, string $subject, string $body) use (&$partite): bool {
        $partite[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

        return true;
    });

    try {
        $corpo();
    } finally {
        Mailer::useTransport(null);
        Extensions::use(null);
    }

    return $partite;
}

/** Solo gli avvisi «Pagamento da controllare». */
function avvisiPagamento(array $partite): array
{
    return array_values(array_filter($partite, static fn (array $p): bool => str_starts_with($p['subject'], '[payment.review] ')));
}
