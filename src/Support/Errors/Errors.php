<?php

namespace Wonder\Plugin\Gestionale\Support\Errors;

use Throwable;
use Wonder\App\Logger;
use Wonder\App\Support\Errors\ErrorReporter;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\Plugin\Gestionale\Models\System\Setting;

/**
 * Dove finiscono gli errori del gestionale.
 *
 * Tre strade diverse, come dice la spec: quelli dell'utente si leggono e
 * basta, quelli di un servizio esterno vanno nel log e diventano una riga di
 * `error_reports` del core, quelli interni restano nel log con il contesto.
 *
 * I destinatari li sa solo il gestionale — stanno nelle due righe di
 * impostazioni — quindi li passa al core prima di segnalare.
 */
final class Errors
{
    /** Chi può ricevere un avviso. */
    public const AUDIENCES = ['developer', 'merchant'];

    /** Errore di un servizio esterno: log del provider e segnalazione. */
    public static function provider(ProviderError $error, string $audience = 'developer'): void
    {
        Logger::log(
            $error,
            $error->provider(),
            $error->action(),
            'ERROR',
            $error->provider(),
            $error->context(),
            // In webhook, cron e comandi il log non deve interrompere niente.
            false
        );

        self::report($audience, $error->provider(), $error->action(), $error, $error->context());
    }

    /** Errore nostro: resta nel log, senza svegliare nessuno. */
    public static function internal(Throwable $error, string $action, array $context = []): void
    {
        Logger::log($error, 'gestionale', $action, 'ERROR', 'gestionale', $context, false);
    }

    /** Segnala al core dicendogli a chi scrivere. */
    public static function report(
        string $audience,
        string $service,
        string $action,
        Throwable|string $error,
        array $context = []
    ): bool {
        ErrorReporter::recipientsUsing(static fn (string $group): array => self::recipients($group));

        try {
            return ErrorReporter::report($audience, $service, $action, $error, $context);
        } finally {
            // Il risolutore vale per questa segnalazione, non per tutto il sito.
            ErrorReporter::recipientsUsing(null);
        }
    }

    /** Destinatari di un gruppo, dalle impostazioni. @return list<string> */
    public static function recipients(string $audience): array
    {
        if ($audience === 'merchant') {
            return self::parseRecipients((string) (MerchantSetting::current()['merchant_error_emails'] ?? ''));
        }

        return self::parseRecipients((string) (Setting::current()['developer_error_emails'] ?? ''));
    }

    /** Indirizzi separati da virgola. @return list<string> */
    public static function parseRecipients(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $address): bool => $address !== ''
        ));
    }
}
