<?php
declare(strict_types=1);

namespace Wonder\Data\Validators;

// Only reserved fixture addresses bypass public DNS; production is unchanged.
function checkdnsrr(string $hostname, string $type = 'MX'): bool
{
    return strtolower($hostname) === 'example.com' ? true : \checkdnsrr($hostname, $type);
}
