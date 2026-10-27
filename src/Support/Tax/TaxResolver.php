<?php

namespace Wonder\Plugin\Gestionale\Support\Tax;

use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * Sceglie l'aliquota di una riga: tipo fiscale dal prodotto, paese e tipo dal
 * cliente, regola con corrispondenza esatta.
 *
 * Niente regole a scaglioni, caratteri jolly o aliquote di ripiego: o la
 * combinazione c'è, o la vendita si ferma con un errore che dice quale regola
 * manca. Un'aliquota "prudente" scelta a caso finirebbe in fattura sbagliata.
 * Una regola disattiva è come se non ci fosse. Classe pura, le regole arrivano
 * già lette dal database.
 */
final class TaxResolver
{
    /**
     * L'aliquota della combinazione; senza regola un `UserError` che nomina
     * prodotto, paese e tipo di cliente.
     *
     * @param list<array<string, mixed>> $rules righe di `gst_tax_rules`
     * @return int id dell'aliquota
     */
    public static function resolve(
        array $rules,
        string $country,
        string $customerType,
        int $taxCategoryId,
        string $product = ''
    ): int {
        $taxId = static::find($rules, $country, $customerType, $taxCategoryId);

        if ($taxId === null) {
            throw static::missing($product, $country, $customerType);
        }

        return $taxId;
    }

    /**
     * Come `resolve()`, ma senza regola ridà `null`: per chi può lasciare il
     * valore vuoto (export del catalogo, proposta d'acquisto).
     *
     * @param list<array<string, mixed>> $rules
     */
    public static function find(array $rules, string $country, string $customerType, int $taxCategoryId): ?int
    {
        $country = strtoupper(trim($country));
        $customerType = strtolower(trim($customerType));

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $taxId = (int) ($rule['tax_id'] ?? 0);

            if ($taxId <= 0 || (string) ($rule['active'] ?? 'true') === 'false') {
                continue;
            }

            if (strtoupper(trim((string) ($rule['country'] ?? ''))) !== $country) {
                continue;
            }

            if (strtolower(trim((string) ($rule['customer_type'] ?? ''))) !== $customerType) {
                continue;
            }

            if ((int) ($rule['tax_category_id'] ?? 0) !== $taxCategoryId) {
                continue;
            }

            return $taxId;
        }

        return null;
    }

    public static function missing(string $product, string $country, string $customerType): UserError
    {
        $type = strtolower(trim($customerType));

        return UserError::make('tax.rule_missing', [
            'product' => trim($product) !== '' ? trim($product) : '—',
            'country' => strtoupper(trim($country)),
            'customer_type' => match ($type) {
                'business' => 'azienda',
                'private' => 'privato',
                default => $type,
            },
        ]);
    }
}
