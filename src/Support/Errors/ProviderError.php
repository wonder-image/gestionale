<?php

namespace Wonder\Plugin\Gestionale\Support\Errors;

use RuntimeException;
use Throwable;

/**
 * Errore di un servizio esterno: fatturazione elettronica, pagamenti,
 * corrieri.
 *
 * Porta con sé chi ha sbagliato e cosa stava facendo, perché è quello che
 * serve per capire il log e per raggruppare gli errori uguali.
 */
final class ProviderError extends RuntimeException
{
    private string $provider = '';
    private string $action = '';
    private array $context = [];

    public static function make(
        string $provider,
        string $action,
        string $message,
        array $context = [],
        ?Throwable $previous = null
    ): self {
        $error = new self($message, 0, $previous);
        $error->provider = trim($provider);
        $error->action = trim($action);
        $error->context = $context;

        return $error;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function action(): string
    {
        return $this->action;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
