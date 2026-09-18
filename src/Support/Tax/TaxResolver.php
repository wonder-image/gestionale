<?php

namespace Wonder\Plugin\Gestionale\Support\Tax;

/**
 * Sceglie l'aliquota di una riga: tipo fiscale dal prodotto, paese e tipo dal
 * cliente, regola con corrispondenza esatta.
 *
 * Niente regole a scaglioni o con caratteri jolly: o la combinazione c'è, o si
 * usa l'aliquota di ripiego scelta nelle impostazioni fiscali. Classe pura, le
 * regole arrivano già lette dal database.
 */
final class TaxResolver
{
    /**
     * @param list<array<string, mixed>> $rules righe di `gst_tax_rules`
     * @return int id dell'aliquota
     */
    public static function resolve(
        array $rules,
        string $country,
        string $customerType,
        int $taxCategoryId,
        int $fallbackTaxId
    ): int {
        $country = strtoupper(trim($country));
        $customerType = strtolower(trim($customerType));

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $taxId = (int) ($rule['tax_id'] ?? 0);

            if ($taxId <= 0) {
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

        return $fallbackTaxId;
    }
}
