<?php

namespace Wonder\Plugin\Gestionale\Support\Setup;

/**
 * Controlli dei "Primi passi": cosa manca perché il gestionale sia pronto.
 *
 * Classe pura, si calcola al momento: niente da salvare e niente che possa
 * restare indietro. Ogni sotto-progetto aggiunge qui i propri controlli.
 */
final class SetupChecks
{
    /**
     * Passi ancora aperti, in ordine.
     *
     * @param array<string, mixed> $society dati della società
     * @param array<string, mixed> $location sede predefinita
     * @param array<string, mixed> $settings impostazioni tecniche
     * @return list<array{key: string, title: string, description: string, url: string}>
     */
    public static function pending(array $society, array $location, array $settings): array
    {
        $pending = [];

        if (!self::societyReady($society)) {
            $pending[] = [
                'key' => 'society',
                'title' => 'Completa i dati della società',
                'description' => 'Servono ragione sociale, partita IVA o codice fiscale ed email: finiscono su ordini e fatture.',
                'url' => '/backend/app/config/society/',
            ];
        }

        if (!self::locationReady($location)) {
            $pending[] = [
                'key' => 'location',
                'title' => 'Completa l\'indirizzo della sede',
                'description' => 'Via, numero, CAP e città della sede principale: senza, spedizioni e documenti restano a metà.',
                'url' => '/backend/app/config/locations/',
            ];
        }

        if (!self::fiscalReady($settings)) {
            $pending[] = [
                'key' => 'fiscal',
                'title' => 'Conferma le impostazioni fiscali',
                'description' => 'Regime, IVA di ripiego e prezzi del catalogo sono precaricati: vanno guardati una volta e salvati.',
                'url' => '/backend/app/gestionale/impostazioni/',
            ];
        }

        return $pending;
    }

    private static function societyReady(array $society): bool
    {
        $identifier = trim((string) ($society['pi'] ?? '')) !== ''
            || trim((string) ($society['cf'] ?? '')) !== '';

        return trim((string) ($society['legal_name'] ?? $society['name'] ?? '')) !== ''
            && $identifier
            && trim((string) ($society['email'] ?? '')) !== '';
    }

    private static function locationReady(array $location): bool
    {
        foreach (['street', 'number', 'cap', 'city'] as $field) {
            if (trim((string) ($location[$field] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    private static function fiscalReady(array $settings): bool
    {
        $confirmed = trim((string) ($settings['fiscal_confirmed_at'] ?? ''));

        return $confirmed !== '' && $confirmed !== '0000-00-00 00:00:00';
    }
}
