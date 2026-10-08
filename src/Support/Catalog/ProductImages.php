<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

/**
 * Quali immagini valgono per una variante, e dove sta il file.
 *
 * La regola dell'ereditarietà è una sola riga di buon senso — *se la variante
 * ha le sue foto si usano quelle, altrimenti quelle del modello* — ma la devono
 * applicare uguale il pannello, la vetrina e domani il feed di Google. Per
 * questo sta qui, pura, e si prova senza database.
 */
final class ProductImages
{
    /**
     * La cartella delle foto, sotto `assets/upload`.
     *
     * È il percorso della pagina dei prodotti, e non è un vezzo: il repeater
     * **scrive** i file nella cartella del Model e li **rilegge** in quella
     * della Resource che ospita il form. Finché le due non coincidono
     * l'anteprima di una foto già caricata non si vede. Per questo il Model
     * dichiara questa stessa cartella e il campo non ne aggiunge un'altra —
     * e per questo rinominare la pagina vuol dire rinominare anche questa.
     */
    public const DIR = '/app/gestionale/prodotti/';

    /** La cartella vera sotto `assets/upload`. */
    public static function folder(): string
    {
        return self::DIR;
    }

    /**
     * Le immagini da mostrare, in ordine di posizione.
     *
     * Tre livelli, dal più preciso al più generale: le foto di quella singola
     * opzione in vendita, se ne ha; altrimenti quelle del suo colore;
     * altrimenti quelle dell'articolo. Il primo livello che ha qualcosa vince
     * **intero**: non si mescolano, o una maglietta blu mostrerebbe in mezzo
     * la foto di quella rossa.
     *
     * Chi passa due soli argomenti continua a vedere quello di prima — le
     * foto del colore — e **non** vede quelle delle singole opzioni: è la
     * risposta giusta per chi sta guardando un colore, non una taglia.
     *
     * @param list<array<string, mixed>> $images tutte le immagini del modello
     * @return list<array<string, mixed>>
     */
    public static function for(array $images, ?int $variantId, ?int $productId = null): array
    {
        $ofModel = [];
        $ofVariant = [];
        $ofProduct = [];

        foreach ($images as $image) {
            $product = (int) ($image['product_id'] ?? 0);
            $variant = (int) ($image['product_variant_id'] ?? 0);

            if ($product > 0) {
                if ($productId !== null && $product === $productId) {
                    $ofProduct[] = $image;
                }

                // La foto di un'altra opzione non vale né per il colore né per
                // l'articolo: è di quella riga e basta.
                continue;
            }

            if ($variant === 0) {
                $ofModel[] = $image;
                continue;
            }

            if ($variantId !== null && $variant === $variantId) {
                $ofVariant[] = $image;
            }
        }

        if ($ofProduct !== []) {
            return self::sorted($ofProduct);
        }

        return self::sorted($ofVariant !== [] ? $ofVariant : $ofModel);
    }

    /**
     * L'inizio del nome di un file caricato: dice di chi è la foto.
     *
     * `maglietta-girocollo-`, `maglietta-girocollo-blu-`,
     * `maglietta-girocollo-blu-s-cotone-`: il core ci attacca le sue tre
     * lettere a caso. L'opzione si chiama «Blu / S / Cotone», ma il colore
     * c'è già: se il suo slug comincia con quello della variante, lo si toglie.
     */
    public static function filePrefix(string $modelSlug, string $variantSlug = '', string $optionName = ''): string
    {
        $variantSlug = trim($variantSlug);
        $option = trim($optionName) !== '' ? Slug::base($optionName) : '';

        if ($variantSlug !== '' && str_starts_with($option, $variantSlug.'-')) {
            $option = substr($option, strlen($variantSlug) + 1);
        }

        $parts = array_filter([trim($modelSlug), $variantSlug, $option], static fn (string $part): bool => $part !== '');

        return $parts === [] ? '' : implode('-', $parts).'-';
    }

    /**
     * Il nome del file.
     *
     * L'upload del core scrive un JSON con l'elenco dei nomi, anche quando il
     * file è uno solo: qui si accetta sia quello sia un nome scritto a mano.
     */
    public static function fileName(array $image): string
    {
        $raw = $image['file'] ?? '';

        if (is_array($raw)) {
            return (string) (reset($raw) ?: '');
        }

        $raw = trim((string) $raw);

        if ($raw === '') {
            return '';
        }

        if (str_starts_with($raw, '[')) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) && $decoded !== [] ? (string) reset($decoded) : '';
        }

        return $raw;
    }

    /** Il percorso sul disco, vuoto quando il file non c'è. */
    public static function path(array $image, ?string $root = null): string
    {
        $name = self::fileName($image);

        if ($name === '') {
            return '';
        }

        $root = rtrim($root ?? (string) ($GLOBALS['ROOT'] ?? ''), '/');

        return $root.'/assets/upload'.self::folder().$name;
    }

    /** L'indirizzo pubblico, vuoto quando il file non c'è. */
    public static function url(array $image): string
    {
        $name = self::fileName($image);

        return $name === '' ? '' : '/assets/upload'.self::folder().$name;
    }

    /** Vero quando le misure sono state generate. */
    public static function isReady(array $image): bool
    {
        return (string) ($image['status'] ?? '') === 'ready';
    }

    /**
     * @param list<array<string, mixed>> $images
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $images): array
    {
        usort($images, static fn (array $a, array $b): int =>
            [(int) ($a['position'] ?? 0), (int) ($a['id'] ?? 0)]
            <=> [(int) ($b['position'] ?? 0), (int) ($b['id'] ?? 0)]);

        return array_values($images);
    }
}
