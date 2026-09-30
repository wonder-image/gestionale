<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * Oggetto e corpo delle sei email di un ordine.
 *
 * Quattro al cliente — ricevuto, confermato, promemoria, annullato — e due al
 * commerciante — ordine nuovo, ordine annullato per mancato pagamento. Sei
 * momenti, due viste: quello che cambia è il titolo e la frase, che stanno nel
 * file di lingua e si correggono senza toccare il codice.
 *
 * Classe pura: prende array, restituisce stringhe. Non legge il database e non
 * manda niente — di quello si occupa `OrderNotifier`.
 */
final class OrderEmail
{
    public const CUSTOMER_VIEW = 'emails/order.php';
    public const MERCHANT_VIEW = 'emails/order-merchant.php';

    public const KEYS = ['received', 'confirmed', 'reminder', 'cancelled', 'merchant_new', 'merchant_cancelled'];

    /** Quelle che vanno al commerciante, non al cliente. */
    public const MERCHANT_KEYS = ['merchant_new', 'merchant_cancelled'];

    /**
     * @param array<string, mixed> $order riga di `gst_orders`
     * @param list<array<string, mixed>> $items righe di `gst_order_items`
     * @param array{instructions?: string, deadline?: string, method?: string, url?: string} $extra
     * @return array{subject: string, body: string}
     */
    public static function compose(string $key, array $order, array $items, array $extra = []): array
    {
        $key = in_array($key, self::KEYS, true) ? $key : 'received';
        $merchant = in_array($key, self::MERCHANT_KEYS, true);
        $values = [
            ':number' => (string) ($order['order_number'] ?? ''),
            ':date' => self::date((string) ($order['ordered_at'] ?? '')),
            ':total' => self::money($order['total'] ?? 0).' €',
            ':deadline' => self::date((string) ($extra['deadline'] ?? '')),
            ':method' => (string) ($extra['method'] ?? ''),
        ];

        return [
            'subject' => self::text($key, 'subject', $values),
            'body' => self::render($merchant ? self::MERCHANT_VIEW : self::CUSTOMER_VIEW, [
                'title' => self::text($key, 'title', $values),
                'intro' => self::text($key, 'intro', $values),
                'instructions' => (string) ($extra['instructions'] ?? ''),
                'order' => $order,
                'items' => $items,
                'url' => self::absoluteUrl((string) ($extra['url'] ?? '')),
                'e' => static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'),
                'money' => static fn (mixed $v): string => self::money($v),
                'qty' => static fn (mixed $v): string => self::quantity((float) $v),
            ]),
        ];
    }

    /** Il testo dal file di lingua, con i segnaposto sostituiti. */
    private static function text(string $key, string $part, array $values): string
    {
        $path = "gestionale.emails.order.{$key}.{$part}";

        try {
            $text = (string) __t($path);
        } catch (Throwable) {
            // Il framework non conosce la chiave (test, comandi, sito che non ha
            // ancora caricato la lingua del modulo): si legge il file del modulo.
            $text = self::fromModuleFile($key, $part);
        }

        // `__t()` senza frase può restituire la chiave: meglio niente che una
        // riga di puntini in mezzo a un'email.
        if ($text === $path) {
            $text = '';
        }

        return trim(strtr($text, $values));
    }

    private static function fromModuleFile(string $key, string $part): string
    {
        $file = Gestionale::root().'/lang/it/gestionale.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $text = is_array($data) ? ($data['gestionale']['emails']['order'][$key][$part] ?? '') : '';

        return is_string($text) ? $text : '';
    }

    /** Un'email si apre fuori dal sito: il link deve avere il dominio. */
    public static function absoluteUrl(string $path): string
    {
        if (trim($path) === '' || preg_match('#^https?://#i', $path) === 1) {
            return trim($path);
        }

        $base = defined('APP_URL') ? rtrim((string) constant('APP_URL'), '/') : '';

        return $base.'/'.ltrim($path, '/');
    }

    /** Le date si leggono come le scrive un italiano. */
    private static function date(string $value): string
    {
        $time = trim($value) === '' || str_starts_with($value, '0000-00-00') ? false : strtotime($value);

        return $time === false ? '' : date('d/m/Y', $time);
    }

    private static function money(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }

    /** I pezzi interi si scrivono interi: "3", non "3,000". */
    private static function quantity(float $value): string
    {
        if (round($value, 3) === round($value, 0)) {
            return number_format($value, 0, ',', '');
        }

        return rtrim(rtrim(number_format($value, 3, ',', ''), '0'), ',');
    }

    /** @param array<string, mixed> $variables */
    private static function render(string $view, array $variables): string
    {
        extract($variables, EXTR_SKIP);
        ob_start();

        try {
            require Gestionale::viewPath($view);
        } catch (Throwable $error) {
            ob_end_clean();

            throw $error;
        }

        return (string) ob_get_clean();
    }
}
