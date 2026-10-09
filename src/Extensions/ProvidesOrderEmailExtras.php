<?php

namespace Wonder\Plugin\Gestionale\Extensions;

/**
 * L'entrypoint di un modulo che aggiunge dati alle email dell'ordine: le
 * chiavi di `OrderEmail::compose` (`account_url`, `url`, …) che solo lui sa
 * calcolare, anche quando l'email parte da un webhook senza sessione.
 */
interface ProvidesOrderEmailExtras
{
    /**
     * @param array<string, mixed> $order la riga dell'ordine
     * @return array<string, string>
     */
    public static function orderEmailExtras(string $key, array $order): array;
}
