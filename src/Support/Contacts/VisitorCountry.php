<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Throwable;
use Wonder\Plugin\GeoPlugin\IPInfo;

/**
 * Il paese di chi sta usando il backend, e il prefisso telefonico che ne segue.
 *
 * «IT» nei form è il paese dell'azienda; il prefisso, invece, deve essere
 * quello di chi sta compilando: un'operatrice a Madrid che aggiunge un
 * indirizzo non vuole togliere il «+39» a ogni scheda. Il paese lo trova
 * `IPInfo` dall'indirizzo IP, e quando non si trova (rete locale, servizio che
 * non risponde) si resta su «IT», come prima.
 *
 * La ricerca chiama servizi esterni, quindi si fa una volta per sessione.
 */
final class VisitorCountry
{
    public const FALLBACK = 'IT';

    private const SESSION_KEY = 'gestionale_visitor_country';

    public static function code(): string
    {
        $cached = $_SESSION[self::SESSION_KEY] ?? null;

        if (is_string($cached) && preg_match('/^[A-Z]{2}$/', $cached) === 1) {
            return $cached;
        }

        $code = self::lookup();

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_KEY] = $code;
        }

        return $code;
    }

    /** Il prefisso con il «+», o stringa vuota se il paese non ne ha uno noto. */
    public static function phonePrefix(): string
    {
        $prefix = function_exists('countryPhonePrefix') ? trim((string) countryPhonePrefix(self::code())) : '';

        return $prefix === '' ? '' : '+'.ltrim($prefix, '+');
    }

    private static function lookup(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        // Un indirizzo locale non dice dove si è: inutile chiederlo fuori.
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;

        if (!$isPublic || !class_exists(IPInfo::class)) {
            return self::FALLBACK;
        }

        try {
            $code = strtoupper(trim((string) (new IPInfo($ip))->Country()));
        } catch (Throwable) {
            return self::FALLBACK;
        }

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : self::FALLBACK;
    }
}
