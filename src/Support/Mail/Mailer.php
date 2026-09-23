<?php

namespace Wonder\Plugin\Gestionale\Support\Mail;

use RuntimeException;
use Throwable;
use Wonder\App\Credentials;
use Wonder\Plugin\Gestionale\Extensions\Extensions;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;

/**
 * Le email del gestionale, tutte da qui.
 *
 * Prima di partire il messaggio passa dall'hook `beforeEmailSend`: il sito
 * può cambiare oggetto, corpo e destinatari, o fermare l'invio togliendo
 * tutti i destinatari. Poi parte un'email per destinatario, perché così la
 * manda il core; un indirizzo che salta non ferma gli altri.
 *
 * Chi chiama decide cosa fare di un invio fallito: qui si mette solo nel log,
 * indirizzo per indirizzo.
 */
final class Mailer
{
    public const SENT = 'sent';
    public const CANCELLED = 'cancelled';
    public const FAILED = 'failed';

    /** @var (callable(string, string, string): bool)|null */
    private static $transport = null;

    /**
     * @param list<string> $to
     * @return array{status: string, to: list<string>, sent: list<string>, failed: list<string>}
     */
    public static function send(string $key, array $to, string $subject, string $body): array
    {
        $original = ['to' => $to, 'subject' => $subject, 'body' => $body];
        $message = Extensions::filter('beforeEmailSend', $original, $key);
        $message = is_array($message) ? $message + $original : $original;

        $recipients = Recipients::parse(is_array($message['to'])
            ? implode(',', array_map('strval', $message['to']))
            : (string) $message['to'])['valid'];

        if ($recipients === []) {
            return ['status' => self::CANCELLED, 'to' => [], 'sent' => [], 'failed' => []];
        }

        $subject = (string) $message['subject'];
        $body = (string) $message['body'];
        $transport = self::$transport ?? self::defaultTransport();
        $sent = [];
        $failed = [];

        foreach ($recipients as $address) {
            try {
                if ($transport($address, $subject, $body) !== true) {
                    throw new RuntimeException('Il server di posta non ha accettato l\'email.');
                }

                $sent[] = $address;
            } catch (Throwable $error) {
                $failed[] = $address;
                Errors::internal($error, 'mail.'.$key, ['to' => $address]);
            }
        }

        return [
            'status' => $sent === [] ? self::FAILED : self::SENT,
            'to' => $recipients,
            'sent' => $sent,
            'failed' => $failed,
        ];
    }

    /** Cambia il modo di spedire; `null` torna a `sendMail()` del core. Serve ai test. */
    public static function useTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /** A chi si risponde: l'email del negozio, se no il mittente del sito, se no niente. */
    public static function replyTo(): string
    {
        $society = $GLOBALS['SOCIETY'] ?? null;
        $email = is_object($society) && is_string($society->email ?? null) ? trim($society->email) : '';

        if ($email !== '') {
            return $email;
        }

        // Le credenziali solo se il core le ha già caricate, cioè nel sito
        // avviato: fuori (test, comandi) leggerle aprirebbe il .env e il database.
        if (!class_exists(Credentials::class, false)) {
            return '';
        }

        try {
            $username = Credentials::mail()->username ?? '';

            return is_string($username) ? trim($username) : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Prepara il corpo per `sanitizeEcho()`, che `emailTemplate()` gli passa
     * sopra: toglie le barre e decodifica le entità, e senza questa difesa un
     * `&lt;b&gt;` scritto apposta tornerebbe `<b>`.
     *
     * Dopo `sanitizeEcho()` il corpo resta quello scritto: ogni entità diventa
     * numerica (`&amp;#60;` → `&#60;`), ogni carattere non ASCII anche, ogni
     * barra raddoppia (`stripslashes` ne toglie una).
     */
    public static function shield(string $html): string
    {
        $html = mb_scrub($html, 'UTF-8');
        $html = str_replace('\\', '\\\\', $html);
        $html = (string) preg_replace_callback(
            '/&(#[0-9]+|#[xX][0-9a-fA-F]+|[A-Za-z][A-Za-z0-9]*);|&/',
            static function (array $m): string {
                if ($m[0] === '&') {
                    return '&amp;#38;';
                }

                $decoded = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // Un'entità che nessuno conosce è testo: resta com'è scritta.
                if ($decoded === $m[0]) {
                    return '&amp;#38;'.substr($m[0], 1);
                }

                $out = '';

                foreach (mb_str_split($decoded, 1, 'UTF-8') as $char) {
                    $out .= '&amp;#'.mb_ord($char, 'UTF-8').';';
                }

                return $out;
            },
            $html
        );

        return (string) preg_replace_callback(
            '/[^\x00-\x7F]/u',
            static fn (array $m): string => '&amp;#'.mb_ord($m[0], 'UTF-8').';',
            $html
        );
    }

    /** @return callable(string, string, string): bool */
    private static function defaultTransport(): callable
    {
        return static function (string $to, string $subject, string $body): bool {
            // Le funzioni globali del core ci sono solo nel sito avviato:
            // non in un comando `forge`, non nei test.
            if (!function_exists('sendMail')) {
                throw new RuntimeException('Le email partono solo dal sito avviato: qui manca sendMail().');
            }

            // Su Brevo il core manda la risposta-a senza guardarla, e Brevo ne
            // rifiuta una vuota: l'email non partirebbe mai.
            return (bool) sendMail(self::replyTo(), $to, $subject, self::shield($body));
        };
    }
}
