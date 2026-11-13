<?php

namespace Wonder\Plugin\Gestionale\Support\Mail;

use Wonder\App\Support\Mail\MailMessage;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Catalog\ImageSizes;

/**
 * Quando un'email del gestionale si compone con `MailMessage` del core.
 *
 * Il builder, con l'elenco articoli e la tabella, c'è solo nei core recenti; e la vista che il sito ha copiato in
 * `custom/modules/gestionale/view/` resta la sua: in quei due casi il corpo
 * viene ancora dalla vista, scritto a mano.
 */
final class MailBuilder
{
    /** Il doppio dei 64px dell'anteprima: nitida anche sugli schermi retina. */
    private const THUMBNAIL_MIN = 128;

    /** Serve il core con l'elenco articoli e la tabella (`items()`, `table()`). */
    public static function available(): bool
    {
        return class_exists(MailMessage::class) && method_exists(MailMessage::class, 'items');
    }

    /** Il builder per questa vista, o `null` se il corpo va preso dalla vista. */
    public static function forView(string $view): ?MailMessage
    {
        if (!self::available() || Gestionale::viewPath($view) !== Gestionale::root().'/view/'.ltrim($view, '/')) {
            return null;
        }

        return MailMessage::make();
    }

    /** Un corpo già composto dal builder: non va più difeso per `sanitizeEcho()`. */
    public static function isBuilt(string $body): bool
    {
        return class_exists(MailMessage::class) && method_exists(MailMessage::class, 'isBuilt') && MailMessage::isBuilt($body);
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * La foto più leggera di un'immagine caricata, per un'anteprima da 64px:
     * la misura più piccola che resta nitida sugli schermi retina (almeno
     * 128px), nel formato dell'originale perché Outlook non legge il webp.
     * Senza quella misura su disco va bene solo un originale già piccolo;
     * altrimenti `''`, e l'email resta senza foto piuttosto che pesante.
     */
    public static function thumbnail(string $url): string
    {
        $base = defined('APP_URL') ? rtrim((string) constant('APP_URL'), '/') : '';
        $path = trim($url);

        if ($base !== '' && str_starts_with($path, $base.'/')) {
            $path = substr($path, strlen($base));
        }

        $root = rtrim((string) ($GLOBALS['ROOT'] ?? ''), '/');
        $info = pathinfo($path);

        if ($root === '' || !str_starts_with($path, '/assets/upload/') || str_contains($path, '..') || !ImageSizes::resizable($path)) {
            return '';
        }

        $widths = array_filter(ImageSizes::widths(), static fn (int $width): bool => $width >= self::THUMBNAIL_MIN);
        sort($widths);
        $width = $widths === [] ? self::THUMBNAIL_MIN : reset($widths);
        $variant = $info['dirname'].'/'.$info['filename'].'-'.$width.'.'.$info['extension'];

        if (is_file($root.$variant)) {
            return $variant;
        }

        $size = is_file($root.$path) ? @getimagesize($root.$path) : false;

        return is_array($size) && $size[0] > 0 && $size[0] <= $width ? $path : '';
    }

    /** Un link in chiaro, per gli indirizzi che non hanno un'etichetta. */
    public static function link(string $url): string
    {
        $url = trim($url);

        return preg_match('#^https?://#i', $url) === 1
            ? '<a href="'.self::e($url).'" target="_blank" rel="noopener" style="color:#71717a;word-break:break-all;">'.self::e($url).'</a>'
            : self::e($url);
    }
}
