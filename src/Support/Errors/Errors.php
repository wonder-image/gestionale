<?php

namespace Wonder\Plugin\Gestionale\Support\Errors;

use Throwable;
use Wonder\App\Logger;
use Wonder\App\Support\Errors\ErrorReporter;
use Wonder\Plugin\Gestionale\Models\System\Setting;

/**
 * Dove finiscono gli errori del gestionale.
 *
 * Tre strade diverse: quelli dell'utente si leggono e basta, quelli di un
 * servizio esterno vanno nel log e diventano una riga di `error_reports` del
 * core, quelli interni restano nel log con il contesto.
 *
 * Sono tutti guasti tecnici, e li guarda chi sviluppa: gli indirizzi stanno in
 * `developer_error_emails` delle impostazioni e si passano al core prima di
 * segnalare. Quello che deve sapere il commerciante è una notifica, non un
 * errore: la porterà il sotto-progetto che la genera (un ordine fermo, una
 * spedizione senza tracking), con parole sue.
 */
final class Errors
{
    /** Errore di un servizio esterno: log del provider e segnalazione. */
    public static function provider(ProviderError $error): void
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

        self::report($error->provider(), $error->action(), $error, $error->context());
    }

    /** Errore nostro: resta nel log, senza svegliare nessuno. */
    public static function internal(Throwable $error, string $action, array $context = []): void
    {
        Logger::log($error, 'gestionale', $action, 'ERROR', 'gestionale', $context, false);
    }

    /** Segnala al core dicendogli a chi scrivere. */
    public static function report(
        string $service,
        string $action,
        Throwable|string $error,
        array $context = []
    ): bool {
        ErrorReporter::recipientsUsing(static fn (): array => self::recipients());

        try {
            return ErrorReporter::report($service, $action, $error, $context);
        } finally {
            // Il risolutore vale per questa segnalazione, non per tutto il sito.
            ErrorReporter::recipientsUsing(null);
        }
    }

    /** Chi riceve gli avvisi tecnici, dalle impostazioni. @return list<string> */
    public static function recipients(): array
    {
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
