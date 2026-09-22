<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

/**
 * Quali varianti e quali prodotti mancano, dati i valori spuntati.
 *
 * Un asse può avere pagina e foto proprie — è quello che diventa una variante;
 * gli altri si moltiplicano fra loro. Due colori, tre taglie e due lunghezze
 * sono due varianti e dodici prodotti. Rifarlo con le stesse spunte non deve
 * creare niente: si guarda cosa c'è già e si dice solo cosa manca.
 *
 * Prima gli assi erano due, fissi: uno di variante per uno di prodotto.
 * Spuntare due opzioni dello stesso livello — la vita e la lunghezza di un
 * jeans — produceva righe sbagliate senza dirlo. Con N assi non c'è nessuna
 * regola da spiegare a chi compila, che è il punto.
 *
 * Classe pura: riceve i valori e l'esistente, non tocca il database.
 */
final class Combinations
{
    /**
     * @param list<array{id: int, label: string}> $variantValues l'asse con
     *        pagina propria; vuoto se l'articolo non ne usa
     * @param list<list<array{id: int, label: string}>> $axes gli altri assi,
     *        uno per opzione spuntata
     * @param array{variants: array<int, int>, products: array<string, bool>} $existing
     *        `variants`: id del valore => id della variante che lo usa già;
     *        `products`: chiavi già presenti, nella forma di `key()`
     * @return array{
     *     variants: list<array{value_id: int, label: string}>,
     *     products: list<array{variant_value_id: int, value_ids: list<int>, labels: list<string>}>
     * }
     */
    public static function plan(array $variantValues, array $axes, array $existing): array
    {
        $axes = array_values(array_filter($axes, static fn (array $axis): bool => $axis !== []));

        if ($variantValues === [] && $axes === []) {
            return ['variants' => [], 'products' => []];
        }

        $knownVariants = (array) ($existing['variants'] ?? []);
        $knownProducts = (array) ($existing['products'] ?? []);

        $variants = [];

        foreach ($variantValues as $value) {
            $id = (int) ($value['id'] ?? 0);

            if ($id === 0 || isset($knownVariants[$id])) {
                continue;
            }

            $variants[] = ['value_id' => $id, 'label' => (string) ($value['label'] ?? '')];
        }

        // Nessun colore spuntato: si lavora sulla variante che c'è già, che qui
        // vale zero perché il suo id lo conosce solo chi scrive le righe.
        $variantSide = $variantValues === []
            ? [['id' => 0, 'label' => '']]
            : $variantValues;

        $combinations = self::cartesian($axes);
        $products = [];

        foreach ($variantSide as $variant) {
            $variantId = (int) ($variant['id'] ?? 0);

            foreach ($combinations as $combination) {
                $valueIds = array_map(
                    static fn (array $value): int => (int) ($value['id'] ?? 0),
                    $combination
                );

                if (isset($knownProducts[self::key($variantId, $valueIds)])) {
                    continue;
                }

                $labels = [];

                foreach ([$variant, ...$combination] as $value) {
                    $label = (string) ($value['label'] ?? '');

                    if ($label !== '') {
                        $labels[] = $label;
                    }
                }

                $products[] = [
                    'variant_value_id' => $variantId,
                    'value_ids' => $valueIds,
                    'labels' => $labels,
                ];
            }
        }

        return ['variants' => $variants, 'products' => $products];
    }

    /**
     * La chiave di una combinazione.
     *
     * Gli id si ordinano: chi ha spuntato prima la lunghezza e poi la taglia
     * deve ritrovare la stessa riga di chi ha fatto il contrario, altrimenti
     * risalvare crea doppioni.
     *
     * @param list<int> $valueIds
     */
    public static function key(int $variantValueId, array $valueIds): string
    {
        sort($valueIds);

        return $variantValueId.':'.implode('-', $valueIds);
    }

    /**
     * La chiave con cui il browser chiama una combinazione.
     *
     * Diversa da `key()`: quella distingue l'asse con pagina propria, questa
     * no — perché chi la compone nel browser vede solo delle spunte, e non sa
     * quale opzione diventerà una variante. Tutti gli id insieme, ordinati.
     *
     * @param list<int> $valueIds
     */
    public static function clientKey(int $variantValueId, array $valueIds): string
    {
        $ids = array_values(array_filter(
            array_map('intval', [...$valueIds, $variantValueId]),
            static fn (int $id): bool => $id > 0
        ));

        sort($ids);

        return implode('-', $ids);
    }

    /**
     * Il prodotto cartesiano degli assi.
     *
     * Senza assi torna **una** combinazione vuota, non zero: vuol dire "un
     * prodotto per variante, senza altre scelte".
     *
     * @param list<list<array{id: int, label: string}>> $axes
     * @return list<list<array{id: int, label: string}>>
     */
    private static function cartesian(array $axes): array
    {
        $rows = [[]];

        foreach ($axes as $axis) {
            $next = [];

            foreach ($rows as $row) {
                foreach ($axis as $value) {
                    $next[] = [...$row, $value];
                }
            }

            $rows = $next;
        }

        return $rows;
    }
}
