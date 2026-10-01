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
 *
 * Chi crea o cancella può lasciare una nota per chi lancia il comando — per
 * esempio i dati di prova che la pulizia ha lasciato perché qualcosa di vero
 * li usa ancora. Il comando le stampa e le svuota dopo ogni passaggio.
 */
final class DemoData
{
    /** @var array<string, array{title: string, create: callable, clear: callable}> */
    private static array $registry = [];

    /** @var list<string> */
    private static array $notes = [];

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

    /**
     * Il registro rovesciato: l'ordine in cui si cancella.
     *
     * Chi è registrato dopo dipende da chi c'è prima — gli ordini vendono gli
     * articoli del catalogo — e deve andarsene per primo.
     *
     * @return array<string, array{title: string, create: callable, clear: callable}>
     */
    public static function inClearOrder(): array
    {
        return array_reverse(self::$registry, true);
    }

    /** Una nota per chi lancia il comando; le vuote non contano. */
    public static function note(string $text): void
    {
        $text = trim($text);

        if ($text !== '') {
            self::$notes[] = $text;
        }
    }

    /**
     * Le note lasciate finora, che da qui in poi non ci sono più.
     *
     * @return list<string>
     */
    public static function notes(): array
    {
        $notes = self::$notes;
        self::$notes = [];

        return $notes;
    }

    public static function reset(): void
    {
        self::$registry = [];
        self::$notes = [];
    }
}
