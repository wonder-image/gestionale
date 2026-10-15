<?php

namespace Wonder\Plugin\Gestionale\Support\Mcp;

/**
 * Chi chiama il server MCP: l'utente del token di `api_users`, con i ruoli del
 * suo profilo. I permessi degli strumenti si leggono da qui.
 */
final class Caller
{
    /** @param list<string> $authority */
    public function __construct(
        public readonly int $userId,
        public readonly int $apiUserId,
        public readonly array $authority,
        public readonly string $ip = '',
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
}
