<?php

namespace Wonder\Plugin\Gestionale\Support;

use Throwable;
use Wonder\Sql\Transaction;

/**
 * Letture tenute da parte per la durata della richiesta, per gruppo.
 *
 * Chi scrive i dati di un gruppo chiama forget(). Dentro una transazione non
 * si tiene niente di nuovo: un rollback non deve lasciare in memoria righe mai
 * scritte. Un gruppo scritto nella transazione si rilegge fino alla chiusura.
 */
final class RequestMemo
{
    /** @var array<string, array<string, mixed>> */
    private static array $values = [];

    /** @var array<string, true> */
    private static array $tainted = [];

    public static function remember(string $group, string $key, callable $read): mixed
    {
        if (isset(self::$tainted[$group])) {
            if (self::inTransaction()) {
                return $read();
            }

            unset(self::$tainted[$group], self::$values[$group]);
        }

        if (isset(self::$values[$group]) && array_key_exists($key, self::$values[$group])) {
            return self::$values[$group][$key];
        }

        if (self::inTransaction()) {
            return $read();
        }

        return self::$values[$group][$key] = $read();
    }

    public static function forget(string $group): void
    {
        unset(self::$values[$group]);

        if (self::inTransaction()) {
            self::$tainted[$group] = true;
        }
    }

    /** Svuota tutto: per i test e per i processi lunghi. */
    public static function reset(): void
    {
        self::$values = [];
        self::$tainted = [];
    }

    private static function inTransaction(): bool
    {
        try {
            return Transaction::active();
        } catch (Throwable) {
            return false;
        }
    }
}
