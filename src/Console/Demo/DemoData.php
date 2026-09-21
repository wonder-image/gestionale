<?php

namespace Wonder\Plugin\Gestionale\Console\Demo;

/**
 * Registro dei dati di prova.
 *
 * Ogni sotto-progetto registra i propri: una chiave, un titolo da mostrare e
 * due funzioni, una che crea e una che cancella. In G1 è vuoto — catalogo e
 * ordini non esistono ancora — e il comando lo dice invece di fingere.
 *
 * Regola: si scrive solo in tabelle che non si sincronizzano, altrimenti i
 * dati finti finirebbero in produzione col deploy.
 */
final class DemoData
{
    /** @var array<string, array{title: string, create: callable, clear: callable}> */
    private static array $registry = [];

    public static function register(string $key, string $title, callable $create, callable $clear): void
    {
        self::$registry[trim($key)] = [
            'title' => $title,
            'create' => $create,
            'clear' => $clear,
        ];
    }

    /** @return array<string, array{title: string, create: callable, clear: callable}> */
    public static function all(): array
    {
        return self::$registry;
    }

    public static function reset(): void
    {
        self::$registry = [];
    }
}
