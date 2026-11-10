<?php
/**
 * I test non interrogano il DNS: `DomainValidator` del core chiama
 * `checkdnsrr()` e senza rete ogni email risulterebbe non valida. Nel
 * namespace dei validatori questa funzione ha la precedenza su quella di
 * PHP: ogni dominio vale, salvo `$GLOBALS['__dns_ok'] = false`. Lo carica
 * solo il test che ne ha bisogno.
 */
declare(strict_types=1);

namespace Wonder\Data\Validators;

function checkdnsrr(string $hostname, string $type = 'MX'): bool
{
    return $GLOBALS['__dns_ok'] ?? true;
}
