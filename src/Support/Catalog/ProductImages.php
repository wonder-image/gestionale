<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\App\Media\ResizeQueue;

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

    /** Il prodotto da solo, su fondo neutro: la foto della card e della scheda. */
    public const PACKSHOT = 'packshot';

    /** Il prodotto in uso, con le persone o l'ambiente: la foto di contesto. */
    public const LIFESTYLE = 'lifestyle';

    public const TYPES = [self::PACKSHOT => 'Prodotto', self::LIFESTYLE => 'In uso'];

    /** Il tipo di una foto; una riga senza tipo (o con uno sconosciuto) è packshot. */
    public static function type(array $image): string
    {
        $type = (string) ($image['type'] ?? '');

        return isset(self::TYPES[$type]) ? $type : self::PACKSHOT;
    }

    /**
     * Solo le foto di un tipo, nel loro ordine. Si usa dopo {@see for()}:
     * prima si sceglie di chi sono le foto, poi quali.
     *
     * @param list<array<string, mixed>> $images
     * @return list<array<string, mixed>>
     */
    public static function ofType(array $images, string $type): array
    {
        return array_values(array_filter($images, static fn ($image): bool => is_array($image) && self::type($image) === $type));
    }

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

    /** Le misure ci sono, o non servono (un video, un file ridimensionato al salvataggio). */
    public const READY = 'ready';

    /** Le misure sono nella coda del core: intanto il sito mostra l'originale. */
    public const PENDING = 'pending';

    /** Le misure non si sono generate: l'errore lo dà {@see error()}. */
    public const FAILED = 'failed';

    /**
     * Lo stato delle misure di una foto, letto dalla coda del core
     * (`ResizeQueue::status()` del percorso del file).
     *
     * La colonna `status` della riga è della vecchia coda del modulo e non
     * si scrive più: vale solo dove la coda del core non sa niente del file,
     * cioè per le righe `pending` non ancora consegnate dalla migrazione e
     * per quelle rimaste `failed`.
     *
     * Legge la coda: una query per foto. Va bene nella scheda di un
     * articolo, non in un elenco del sito.
     *
     * @return self::READY|self::PENDING|self::FAILED
     */
    public static function state(array $image, ?string $root = null): string
    {
        $path = self::path($image, $root);

        if ($path === '' || !ImageSizes::resizable($path)) {
            return self::READY;
        }

        $queued = ResizeQueue::status($path);

        if ($queued !== null) {
            return match ($queued) {
                ResizeQueue::PENDING => self::PENDING,
                ResizeQueue::FAILED => self::FAILED,
                default => self::READY,
            };
        }

        return match ((string) ($image['status'] ?? '')) {
            self::PENDING => self::PENDING,
            self::FAILED => self::FAILED,
            default => self::READY,
        };
    }

    /** Perché le misure non si sono generate; vuoto se non c'è un errore. */
    public static function error(array $image, ?string $root = null): string
    {
        $path = self::path($image, $root);
        $queued = $path !== '' ? ResizeQueue::error($path) : null;

        return $queued ?? trim((string) ($image['error'] ?? ''));
    }

    /**
     * Gli avvisi sulle misure delle foto di un articolo, per la sua scheda:
     * quante sono ancora in preparazione e quali non si sono generate.
     *
     * @param list<array<string, mixed>> $images
     * @return list<array{level: string, message: string}>
     */
    public static function notices(array $images, ?string $root = null): array
    {
        $pending = 0;
        $failed = [];

        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }

            $state = self::state($image, $root);

            if ($state === self::PENDING) {
                $pending++;
            } elseif ($state === self::FAILED) {
                $error = self::error($image, $root);
                $failed[] = self::fileName($image).($error !== '' ? ' ('.$error.')' : '');
            }
        }

        $notices = [];

        if ($pending > 0) {
            $notices[] = [
                'level' => 'info',
                'message' => $pending === 1
                    ? 'Una foto è in elaborazione: le sue misure per il sito si stanno preparando. Intanto il sito mostra l\'originale.'
                    : $pending.' foto sono in elaborazione: le loro misure per il sito si stanno preparando. Intanto il sito mostra gli originali.',
            ];
        }

        if ($failed !== []) {
            $notices[] = [
                'level' => 'warning',
                'message' => 'Le misure di '.(count($failed) === 1 ? 'questa foto' : 'queste foto')
                    .' non si sono generate: '.implode(', ', $failed)
                    .'. Il sito mostra l\'originale. Togli la foto e ricaricala, magari riesportata in JPG.',
            ];
        }

        return $notices;
    }

    /**
     * Vero quando le misure sono pronte (o non servono).
     *
     * @deprecated Legge la coda del core con {@see state()}: una query per
     *             foto. Chi mostra una foto non ne ha bisogno, perché il
     *             motore delle immagini ripiega sull'originale.
     */
    public static function isReady(array $image): bool
    {
        return self::state($image) === self::READY;
    }

    /**
     * Vero quando la foto si può mostrare in vetrina.
     *
     * Non serve che le misure siano pronte: finché la coda non le genera il
     * motore delle immagini ripiega sul file originale. Restano fuori i file
     * che non sono immagini e le foto che la vecchia coda del modulo aveva
     * segnato `failed` (file rotti o spariti): la colonna non si scrive più,
     * ma quelle righe restano nascoste come prima. Non legge la coda del
     * core: è pura, e si chiama foto per foto negli elenchi.
     */
    public static function isShowable(array $image): bool
    {
        if ((string) ($image['status'] ?? '') === self::FAILED) {
            return false;
        }

        $extension = strtolower(pathinfo(self::fileName($image), PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'svg'], true);
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
