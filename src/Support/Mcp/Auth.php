<?php

namespace Wonder\Plugin\Gestionale\Support\Mcp;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;
use Wonder\App\Credentials;
use Wonder\Http\UrlParser;

/**
 * Il token del server MCP è quello di `api_users`: gli stessi controlli di
 * `Wonder\Api\Endpoint` (firma, utente attivo, autorità `api_internal_user`,
 * token attivo, IP e domini ammessi, HTTPS), più il confronto con il token
 * salvato, così un token rigenerato o revocato smette subito di valere.
 */
final class Auth
{
    public const AUTHORITY = 'api_internal_user';
    private const RESOURCE = '/api/gestionale/mcp';

    /**
     * @param array<string, mixed> $server
     * @throws McpError con il codice HTTP del rifiuto
     */
    public static function fromRequest(array $server): Caller
    {
        if (!UrlParser::isHttpsRequest($server)) {
            throw McpError::make('auth.https', 403);
        }

        $bearer = self::bearer($server);

        if ($bearer === '') {
            throw McpError::make('auth.missing', 401);
        }

        if (str_starts_with($bearer, 'wio_at_')) {
            try {
                $oauth = \Wonder\OAuth\ResourceGuard::authenticate(self::RESOURCE, $server);
            } catch (\Wonder\OAuth\OAuthException) {
                // Token OAuth non valido, scaduto, revocato o per un'altra risorsa.
                // L'header WWW-Authenticate lo costruisce mcp.php.
                throw McpError::make('auth.invalid', 401);
            }

            // I permessi vengono dall'utente del grant, non dal client.
            $authority = array_values(array_map('strval', (array) ($oauth->user->authority ?? [])));

            return new Caller($oauth->userId, null, $authority, (string) UrlParser::requestIp($server), $oauth->grantId, $oauth->scopes);
        }

        try {
            $decoded = JWT::decode($bearer, new Key(Credentials::appKey(), 'HS256'));
        } catch (Throwable) {
            throw McpError::make('auth.invalid', 401);
        }

        $user = infoUser($decoded->sub ?? 0);

        if (!is_object($user) || empty($user->exists) || !in_array($user->active ?? null, [true, 'true'], true)) {
            throw McpError::make('auth.user', 401);
        }

        $authority = array_values(array_map('strval', (array) ($user->authority ?? [])));
        $token = in_array(self::AUTHORITY, $authority, true) ? ($user->{self::AUTHORITY} ?? null) : null;

        if (!is_object($token) || empty($token->exists) || (string) ($token->active ?? '') !== 'true'
            || !hash_equals((string) ($token->token ?? ''), $bearer)) {
            throw McpError::make('auth.token', 401);
        }

        $ip = UrlParser::requestIp($server);
        $ips = self::list($token->allowed_ips ?? []);

        if ($ips !== [] && !in_array($ip, $ips, true)) {
            throw McpError::make('auth.ip', 403);
        }

        $domains = self::list($token->allowed_domains ?? []);

        if ($domains !== [] && !UrlParser::matchesAnyDomain(UrlParser::normalizeDomain(UrlParser::requestHost($server)), $domains)) {
            throw McpError::make('auth.domain', 403);
        }

        return new Caller((int) $user->id, (int) ($token->id ?? 0), $authority, (string) $ip);
    }

    /** @param array<string, mixed> $server */
    public static function bearer(array $server): string
    {
        return \Wonder\OAuth\ResourceGuard::bearer($server);
    }

    /** @return list<string> */
    private static function list(mixed $values): array
    {
        if (is_string($values)) {
            $decoded = json_decode($values, true);
            $values = is_array($decoded) ? $decoded : [$values];
        }

        $out = [];

        foreach (is_array($values) ? $values : [] as $value) {
            if ((is_string($value) || is_numeric($value)) && trim((string) $value) !== '') {
                $out[] = trim((string) $value);
            }
        }

        return $out;
    }
}
