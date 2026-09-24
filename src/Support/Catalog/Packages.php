<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\Package;

/**
 * Quello che parte davvero: prodotto più scatola.
 *
 * Il peso del prodotto da solo è il dato sbagliato — nessuno spedisce una
 * maglietta nuda — e chi compila se ne accorge solo se glielo si fa vedere.
 * Per questo la scheda mostra la somma, scritta a parole.
 *
 * Le funzioni di calcolo sono pure e si provano senza database; quelle che
 * leggono le scatole passano dal modello.
 */
final class Packages
{
    /** @param array<string, mixed>|null $package */
    public static function shippingWeight(float $product, ?array $package): float
    {
        $tare = $package === null ? 0.0 : (float) ($package['weight'] ?? 0);

        return round(max(0.0, $product) + max(0.0, $tare), 3);
    }

    /**
     * Il peso di spedizione detto per esteso.
     *
     * Dice il totale e da dove viene: senza i due addendi, un peso che non
     * torna non si sa a chi darlo.
     *
     * @param array<string, mixed>|null $package
     */
    public static function describe(float $product, ?array $package): string
    {
        // Senza il peso del prodotto la somma non dice niente: "0 di prodotto
        // e 0,05 di scatola" è un numero che nessuno userebbe. Meglio dire
        // cosa manca.
        if ($product <= 0.0) {
            return 'Manca il peso del prodotto';
        }

        $total = self::kg(self::shippingWeight($product, $package));

        if ($package === null) {
            return $total.' kg — senza imballaggio scelto';
        }

        $tare = (float) ($package['weight'] ?? 0);

        if ($tare <= 0.0) {
            return $total.' kg — la scatola scelta non ha una tara';
        }

        return $total.' kg — '.self::kg($product).' di prodotto e '.self::kg($tare).' di scatola';
    }

    /** Un peso come si scrive in italiano: "0,2", non "0.200". */
    public static function kg(float $value): string
    {
        $text = number_format($value, 3, ',', '');

        return rtrim(rtrim($text, '0'), ',') ?: '0';
    }

    /**
     * Le scatole in uso, per il select della scheda.
     *
     * La voce vuota non è "nessuno": è la scatola predefinita del negozio, che
     * vale per tutto quello che non ne sceglie una sua.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = ['' => 'Imballaggio predefinito'];

        foreach (self::rows() as $row) {
            $options[(string) $row['id']] = (string) ($row['name'] ?? '');
        }

        return $options;
    }

    /**
     * La scatola di un prodotto, o quella predefinita quando non ne ha una.
     *
     * @return array<string, mixed>|null
     */
    public static function forModel(int $packageId): ?array
    {
        $default = null;

        foreach (self::rows() as $row) {
            if ($packageId > 0 && (int) $row['id'] === $packageId) {
                return $row;
            }

            if (($row['is_default'] ?? 'false') === 'true') {
                $default = $row;
            }
        }

        return $default;
    }

    /**
     * Le scatole, o niente.
     *
     * Il `formSchema()` di una Resource viene valutato anche dove un database
     * non c'è — i test degli schemi, i comandi di `forge` — e un elenco vuoto
     * è una risposta migliore di un errore di connessione.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(): array
    {
        try {
            $rows = Package::find(['deleted' => 'false', 'active' => 'true'], null, 'position', 'ASC');
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
