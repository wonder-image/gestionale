<?php

namespace Wonder\Plugin\Gestionale\Support\Mail;

/**
 * Da una casella di indirizzi scritta a mano a un elenco su cui contare.
 *
 * Chi compila separa con la virgola, col punto e virgola, a capo o con uno
 * spazio: vanno bene tutti. I doppioni si tolgono senza guardare le
 * maiuscole, perché nessuno vuole la stessa email due volte; resta la prima
 * scrittura.
 */
final class Recipients
{
    /** @return array{valid: list<string>, invalid: list<string>} */
    public static function parse(string $value): array
    {
        $valid = [];
        $invalid = [];
        $seen = [];

        foreach (preg_split('/[,;\s]+/u', $value) ?: [] as $address) {
            $address = trim($address);

            if ($address === '') {
                continue;
            }

            $key = mb_strtolower($address, 'UTF-8');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $invalid[] = $address;
                continue;
            }

            $valid[] = $address;
        }

        return ['valid' => $valid, 'invalid' => $invalid];
    }

    /**
     * Gli indirizzi come si salvano e si rileggono nella casella.
     *
     * @param list<string> $addresses
     */
    public static function join(array $addresses): string
    {
        return implode(', ', $addresses);
    }
}
