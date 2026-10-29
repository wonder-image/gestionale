<?php

namespace Wonder\Plugin\Gestionale\Support\Mcp;

use RuntimeException;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * Un rifiuto del server MCP. Nell'autenticazione `code` è lo stato HTTP;
 * negli strumenti è un errore dello strumento (`isError`), e il codice resta 0.
 *
 * Il testo sta nei file di lingua, sotto `gestionale.errors.mcp`; `key` resta
 * per chi deve distinguere i casi, come le API REST con i `*_not_found` (404).
 */
final class McpError extends RuntimeException
{
    public const PREFIX = 'mcp.';

    public function __construct(string $message = '', int $code = 0, public readonly string $key = '')
    {
        parent::__construct($message, $code);
    }

    /** @param array<string, string|int|float> $replacements */
    public static function make(string $key, int $code = 0, array $replacements = []): self
    {
        return new self(UserError::make(self::PREFIX.$key, $replacements)->getMessage(), $code, $key);
    }

    public function notFound(): bool
    {
        return str_ends_with($this->key, '_not_found');
    }
}
