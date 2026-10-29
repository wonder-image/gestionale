<?php

namespace Wonder\Plugin\Gestionale\Support\Mcp;

/**
 * Chi chiama il server MCP: l'utente del token di `api_users` oppure del grant OAuth
 * (`oauthGrantId`, senza `apiUserId`), con i ruoli del suo profilo. I permessi degli strumenti si leggono da qui.
 * Un token OAuth porta anche gli scope approvati (`scopes`); `null` è il token di `api_users`, senza limiti.
 */
final class Caller
{
    /**
     * @param list<string> $authority
     * @param list<string>|null $scopes
     */
    public function __construct(
        public readonly int $userId,
        public readonly ?int $apiUserId,
        public readonly array $authority,
        public readonly string $ip = '',
        public readonly ?int $oauthGrantId = null,
        public readonly ?array $scopes = null,
    ) {}

    /**
     * Un elenco di ruoli vuoto non apre niente: un permesso che manca nella
     * risorsa chiude lo strumento invece di aprirlo a tutti.
     *
     * @param list<string> $roles
     */
    public function allows(array $roles): bool
    {
        return $roles !== [] && array_intersect($roles, $this->authority) !== [];
    }

    /** `area:write` vale anche come `area:read`. */
    public function grants(string $scope): bool
    {
        if ($this->scopes === null || in_array($scope, $this->scopes, true)) {
            return true;
        }

        return str_ends_with($scope, ':read')
            && in_array(substr($scope, 0, -5).':write', $this->scopes, true);
    }
}
