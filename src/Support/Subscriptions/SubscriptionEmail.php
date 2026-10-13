<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Orders\OrderEmail;

/**
 * Oggetto e corpo delle tre email di un abbonamento (G10 §2.6): attivato,
 * rinnovo non riuscito, disdetta registrata. I testi stanno nel file di
 * lingua (`gestionale.emails.subscription`) e si correggono senza toccare il
 * codice, come quelli degli ordini.
 *
 * Classe pura: prende array, restituisce stringhe.
 */
final class SubscriptionEmail
{
    public const VIEW = 'emails/subscription.php';

    public const KEYS = ['confirmed', 'payment_failed', 'cancel_requested'];

    /**
     * @param array<string, mixed> $subscription riga di `gst_subscriptions`
     * @param array{plan?: string, account_url?: string} $extra
     * @return array{subject: string, body: string}
     */
    public static function compose(string $key, array $subscription, array $extra = []): array
    {
        $key = in_array($key, self::KEYS, true) ? $key : 'confirmed';
        $values = [
            ':plan' => trim((string) ($extra['plan'] ?? '')),
            ':total' => number_format((float) ($subscription['total'] ?? 0), 2, ',', '.').' €',
            ':renewal' => self::date((string) ($subscription['next_renewal_at'] ?? '')),
            ':trial' => self::date((string) ($subscription['trial_ends_at'] ?? '')),
            ':end' => self::date(self::endOf($subscription)),
        ];
        $lines = [];

        foreach (['renewal_line' => ':renewal', 'trial_line' => ':trial'] as $part => $placeholder) {
            if ($values[$placeholder] !== '' && ($line = self::text($key, $part, $values)) !== '') {
                $lines[] = $line;
            }
        }

        return [
            'subject' => self::text($key, 'subject', $values),
            'body' => self::render([
                'title' => self::text($key, 'title', $values),
                'intro' => self::text($key, 'intro', $values),
                'details' => $lines,
                'url' => OrderEmail::absoluteUrl((string) ($extra['account_url'] ?? '')),
                'button' => self::text($key, 'button', $values),
                'e' => static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'),
            ]),
        ];
    }

    /**
     * Quando finisce un abbonamento disdetto: a fine vincolo se è ancora in
     * corso, altrimenti a fine periodo.
     *
     * @param array<string, mixed> $subscription
     */
    public static function endOf(array $subscription): string
    {
        $commitment = (string) ($subscription['commitment_ends_at'] ?? '');

        if (self::date($commitment) !== '' && strtotime($commitment) > time()) {
            return $commitment;
        }

        return (string) ($subscription['current_period_end'] ?? '');
    }

    private static function text(string $key, string $part, array $values): string
    {
        $path = "gestionale.emails.subscription.{$key}.{$part}";

        try {
            $text = (string) __t($path);
        } catch (Throwable) {
            $text = self::fromModuleFile($key, $part);
        }

        if ($text === $path) {
            $text = '';
        }

        return trim(strtr($text, $values));
    }

    private static function fromModuleFile(string $key, string $part): string
    {
        $file = Gestionale::root().'/lang/it/gestionale.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $text = is_array($data) ? ($data['gestionale']['emails']['subscription'][$key][$part] ?? '') : '';

        return is_string($text) ? $text : '';
    }

    private static function date(string $value): string
    {
        $time = trim($value) === '' || str_starts_with($value, '0000-00-00') ? false : strtotime($value);

        return $time === false ? '' : date('d/m/Y', $time);
    }

    /** @param array<string, mixed> $variables */
    private static function render(array $variables): string
    {
        extract($variables, EXTR_SKIP);
        ob_start();

        try {
            require Gestionale::viewPath(self::VIEW);
        } catch (Throwable $error) {
            ob_end_clean();

            throw $error;
        }

        return (string) ob_get_clean();
    }
}
