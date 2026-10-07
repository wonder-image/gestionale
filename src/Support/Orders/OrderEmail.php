<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * Oggetto e corpo delle otto email di un ordine.
 *
 * Sei al cliente — ricevuto, confermato, promemoria, annullato, spedito, pronto
 * per il ritiro — e due al commerciante — ordine nuovo, ordine annullato per
 * mancato pagamento. Otto momenti, due viste: quello che cambia è il titolo e
 * la frase, che stanno nel file di lingua e si correggono senza toccare il
 * codice.
 *
 * Classe pura: prende array, restituisce stringhe. Non legge il database e non
 * manda niente — di quello si occupa `OrderNotifier`.
 */
final class OrderEmail
{
    public const CUSTOMER_VIEW = 'emails/order.php';
    public const MERCHANT_VIEW = 'emails/order-merchant.php';

    public const KEYS = [
        'received', 'confirmed', 'reminder', 'cancelled', 'shipped', 'ready_for_pickup',
        'merchant_new', 'merchant_cancelled',
    ];

    /** Le righe di dettaglio sotto la frase, in ordine: parte del file di lingua → segnaposto. */
    private const DETAILS = ['carrier_line' => ':carrier', 'tracking_line' => ':tracking', 'location_line' => ':location'];

    /** Le sole in cui ha senso dire come si paga: dopo, o senza, sarebbero rumore. */
    public const INSTRUCTION_KEYS = ['received', 'reminder'];

    /** Quelle che vanno al commerciante, non al cliente. */
    public const MERCHANT_KEYS = ['merchant_new', 'merchant_cancelled'];

    /**
     * @param array<string, mixed> $order riga di `gst_orders`
     * @param list<array<string, mixed>> $items righe di `gst_order_items`
     * @param array{instructions?: string, deadline?: string, method?: string, url?: string, account_url?: string, carrier?: string, tracking?: string, location?: string} $extra
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
            ':carrier' => trim((string) ($extra['carrier'] ?? '')),
            ':tracking' => trim((string) ($extra['tracking'] ?? '')),
            ':location' => trim((string) ($extra['location'] ?? '')),
            ':url' => '',
        ];

        return [
            'subject' => self::text($key, 'subject', $values),
            'body' => self::render($merchant ? self::MERCHANT_VIEW : self::CUSTOMER_VIEW, [
                'title' => self::text($key, 'title', $values),
                'intro' => self::text($key, 'intro', $values),
                'details' => self::details($key, $values),
                'instructions' => in_array($key, self::INSTRUCTION_KEYS, true) ? (string) ($extra['instructions'] ?? '') : '',
                'order' => $order,
                'items' => $items,
                'url' => self::absoluteUrl((string) ($extra['url'] ?? '')),
                // L'ospite sceglie la password dal link: solo nelle email che riceve all'ordine.
                'account_url' => in_array($key, ['received', 'confirmed'], true) ? self::absoluteUrl((string) ($extra['account_url'] ?? '')) : '',
                'account_title' => self::text('account', 'title', []),
                'account_button' => self::text('account', 'button', []),
                'e' => static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'),
                'money' => static fn (mixed $v): string => self::money($v),
                'qty' => static fn (mixed $v): string => self::quantity((float) $v),
            ]),
        ];
    }

    /**
     * Le righe sotto la frase («Corriere: BRT»): solo quelle di cui si ha il
     * dato, così senza tracking non resta un «Numero di tracking:» vuoto.
     *
     * @param array<string, string> $values
     * @return list<string>
     */
    private static function details(string $key, array $values): array
    {
        $lines = [];

        foreach (self::DETAILS as $part => $placeholder) {
            if ($values[$placeholder] === '') {
                continue;
            }

            $line = self::text($key, $part, $values);

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
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
