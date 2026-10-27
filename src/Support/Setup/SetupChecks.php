<?php

namespace Wonder\Plugin\Gestionale\Support\Setup;

use Throwable;
use Wonder\App\Support\GoogleCloud\GoogleApi;
use Wonder\App\Support\GoogleCloud\GoogleApis;
use Wonder\Plugin\Gestionale\Gestionale;

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
     * @param array<string, mixed>|null $credentials credenziali API; null non controlla Google
     * @return list<array{key: string, title: string, description: string, url: string}>
     */
    public static function pending(array $society, array $location, array $settings, ?array $credentials = null): array
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
                'description' => 'Regime, esigibilità IVA e prezzi del catalogo sono precaricati: vanno guardati una volta e salvati.',
                // La pagina è a riga unica: l'elenco porta dritto alla scheda.
                'url' => self::route('backend.resource.app-gestionale-impostazioni.list', [], '/backend/app/gestionale/impostazioni/'),
            ];
        }

        if ($credentials !== null) {
            array_push($pending, ...self::google($credentials));
        }

        return $pending;
    }

    /**
     * Cosa serve da Google Cloud: le API obbligatorie del registro del core
     * (core, moduli, sito) con una credenziale vuota. Le API che dipendono
     * dagli stessi campi formano un passo solo: Maps JavaScript e Places
     * chiedono la stessa chiave pubblica.
     *
     * @param array<string, mixed> $credentials
     * @return list<array{key: string, title: string, description: string, url: string}>
     */
    private static function google(array $credentials): array
    {
        $url = self::route('backend.resource.app-config-credentials.list', [], '/backend/app/config/credentials/');
        $groups = [];

        foreach (GoogleApis::missing($credentials) as $api) {
            $groups[$api->getKeyType().'|'.implode(',', $api->getFields())][] = $api;
        }

        $pending = [];

        foreach ($groups as $apis) {
            $first = $apis[0];
            $names = implode(', ', array_unique(array_map(static fn (GoogleApi $api): string => $api->getName(), $apis)));
            $purposes = array_filter(array_map(static fn (GoogleApi $api): string => self::coreText($api->getPurpose()), $apis));

            $pending[] = [
                'key' => str_starts_with($first->key(), 'google_') ? $first->key() : 'google_'.$first->key(),
                'title' => self::text('setup.google.title', ['apis' => $names]),
                'description' => trim(implode(' ', $purposes).' '.self::text('setup.google.'.$first->getKeyType(), ['apis' => $names])),
                'url' => $url,
            ];
        }

        return $pending;
    }

    /**
     * Testo del modulo: dal framework se è avviato, altrimenti dal file lang
     * del modulo (test, comandi).
     *
     * @param array<string, string> $replacements
     */
    private static function text(string $path, array $replacements = []): string
    {
        $text = self::coreText('gestionale.'.$path);

        if ($text === '') {
            $data = json_decode((string) @file_get_contents(Gestionale::root().'/lang/it/gestionale.json'), true);
            $node = $data['gestionale'] ?? null;

            foreach (explode('.', $path) as $step) {
                $node = is_array($node) ? ($node[$step] ?? null) : null;
            }

            $text = is_string($node) ? $node : $path;
        }

        foreach ($replacements as $name => $value) {
            $text = str_replace('{{'.$name.'}}', $value, $text);
        }

        return $text;
    }

    /** Testo dal framework, vuoto se non è avviato o la chiave manca. */
    private static function coreText(string $key): string
    {
        if (!function_exists('__t')) {
            return '';
        }

        try {
            $text = __t($key);
        } catch (Throwable) {
            return '';
        }

        return is_string($text) && $text !== $key ? trim($text) : '';
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
