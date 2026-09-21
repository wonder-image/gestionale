<?php

namespace Wonder\Plugin\Gestionale\Support\Setup;

use Throwable;

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
                'description' => 'Servono ragione sociale, partita IVA o codice fiscale ed email: stanno nella sede predefinita e finiscono su ordini e fatture.',
                'url' => self::locationUrl($location),
            ];
        }

        if (!self::locationReady($location)) {
            $pending[] = [
                'key' => 'location',
                'title' => 'Completa l\'indirizzo della sede',
                'description' => 'Via, numero, CAP e città della sede principale: senza, spedizioni e documenti restano a metà.',
                'url' => self::locationUrl($location),
            ];
        }

        if (!self::fiscalReady($settings)) {
            $pending[] = [
                'key' => 'fiscal',
                'title' => 'Conferma le impostazioni fiscali',
                'description' => 'Regime, IVA di ripiego e prezzi del catalogo sono precaricati: vanno guardati una volta e salvati.',
                // La pagina è a riga unica: l'elenco porta dritto alla scheda.
                'url' => self::route('backend.resource.app-gestionale-impostazioni.list', [], '/backend/app/gestionale/impostazioni/'),
            ];
        }

        return $pending;
    }

    /**
     * La scheda della sede predefinita: dati della società e indirizzo stanno
     * lì dentro, non su una pagina "Società" che non esiste più.
     *
     * @param array<string, mixed> $location
     */
    private static function locationUrl(array $location): string
    {
        $id = (int) ($location['id'] ?? 0);

        if ($id <= 0) {
            return self::route('backend.resource.app-config-locations.list', [], '/backend/app/config/locations/');
        }

        return self::route(
            'backend.resource.app-config-locations.edit',
            ['id' => $id],
            '/backend/app/config/locations/'.$id.'/edit/'
        );
    }

    /**
     * L'indirizzo dalla rotta del core, con un ripiego per i test, che girano
     * senza il framework acceso.
     *
     * @param array<string, mixed> $parameters
     */
    private static function route(string $name, array $parameters, string $fallback): string
    {
        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $url = (string) __r($name, $parameters);
        } catch (Throwable) {
            return $fallback;
        }

        return trim($url) !== '' ? $url : $fallback;
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
