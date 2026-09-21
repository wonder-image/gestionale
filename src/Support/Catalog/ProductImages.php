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
     * È il percorso della pagina dei modelli, e non è un vezzo: il repeater
     * **scrive** i file nella cartella del Model e li **rilegge** in quella
     * della Resource che ospita il form. Finché le due non coincidono
     * l'anteprima di una foto già caricata non si vede. Per questo il Model
     * dichiara questa stessa cartella e il campo non ne aggiunge un'altra.
     */
    public const DIR = '/app/gestionale/modelli/';

    /** La cartella vera sotto `assets/upload`. */
    public static function folder(): string
    {
        return self::DIR;
    }

    /**
     * Le immagini da mostrare per una variante, in ordine di posizione.
     *
     * @param list<array<string, mixed>> $images tutte le immagini del modello
     * @return list<array<string, mixed>>
     */
    public static function for(array $images, ?int $variantId): array
    {
        $ofModel = [];
        $ofVariant = [];

        foreach ($images as $image) {
            $id = (int) ($image['product_variant_id'] ?? 0);

            if ($id === 0) {
                $ofModel[] = $image;
                continue;
            }

            if ($variantId !== null && $id === $variantId) {
                $ofVariant[] = $image;
            }
        }

        return self::sorted($ofVariant !== [] ? $ofVariant : $ofModel);
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
