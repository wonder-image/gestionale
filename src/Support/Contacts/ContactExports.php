<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Wonder\Plugin\CustomerAccount\Support\ContactExport;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * Le esportazioni di clienti e fornitori che l'«Esporta» dell'elenco può
 * offrire: ognuna è una funzionalità dell'area «Esportazioni», come quelle
 * del catalogo. Colonne e righe sono quelle di `ContactExport`, caricate dal
 * core secondo l'elenco da cui si esporta.
 */
final class ContactExports
{
    /** formato → funzionalità */
    public const FEATURES = [
        'xlsx' => 'export_contacts_xlsx',
        'csv' => 'export_contacts_csv',
    ];

    /**
     * I formati concessi, chiave → etichetta, come li vuole `download()` della tabella.
     *
     * @param (callable(string): bool)|null $enabled per i test; di serie `Gestionale::feature`
     * @return array<string, string>
     */
    public static function granted(?callable $enabled = null): array
    {
        $enabled ??= static fn (string $feature): bool => Gestionale::feature($feature);

        return array_filter(
            ContactExport::FORMATS,
            static fn (string $format): bool => isset(self::FEATURES[$format]) && $enabled(self::FEATURES[$format]),
            ARRAY_FILTER_USE_KEY
        );
    }
}
