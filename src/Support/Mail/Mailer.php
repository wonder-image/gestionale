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
 * tutti i destinatari. Se ne lascia solo di non validi l'invio si ferma lo
 * stesso, ma resta una segnalazione: somiglia più a uno sbaglio che a una
 * scelta. Poi parte un'email per destinatario; un indirizzo che salta non
 * ferma gli altri. Le copie (`cc`) viaggiano sulla prima email accettata, nel
 * CC vero del core (wonder-image/app#202); con un core più vecchio partono a
 * parte, una per indirizzo.
 *
 * Chi chiama decide cosa fare di un invio fallito: qui si mette solo nel log,
 * indirizzo per indirizzo.
 */
final class Mailer
{
    public const SENT = 'sent';
    public const CANCELLED = 'cancelled';
    public const FAILED = 'failed';

    /** Sempre lo stesso: il registro del core conta le ripetizioni sulla stessa riga. */
    private const ONLY_INVALID = 'L\'hook beforeEmailSend ha lasciato solo destinatari non validi.';

    /** @var (callable(string, string, string, array<string, string>, list<string>): bool)|null */
    private static $transport = null;

    /** @var (callable(string, array): void)|null */
    private static $reporter = null;

    /**
     * `$attachments` sono i file da allegare, percorso => nome con cui arriva
     * (G9: il PDF del preventivo e della conferma). L'hook li vede e li può
     * togliere. `$cc` sono le copie: anche loro passano dall'hook, e in
     * `cc` del risultato restano quelle partite davvero.
     *
     * @param list<string> $to
     * @param array<string, string> $attachments
     * @param list<string> $cc
     * @return array{status: string, to: list<string>, cc: list<string>, sent: list<string>, failed: list<string>}
     */
    public static function send(string $key, array $to, string $subject, string $body, array $attachments = [], array $cc = []): array
    {
        $original = ['to' => $to, 'cc' => $cc, 'subject' => $subject, 'body' => $body, 'attachments' => $attachments];
        $message = Extensions::filter('beforeEmailSend', $original, $key);
        $message = is_array($message) ? $message + $original : $original;

        $parsed = self::addresses($message['to']);
        $recipients = $parsed['valid'];

        // Chi è già destinatario non riceve anche la copia.
        $main = array_map(static fn (string $a): string => mb_strtolower($a, 'UTF-8'), $recipients);
        $copies = array_values(array_filter(
            self::addresses($message['cc'] ?? [])['valid'],
            static fn (string $a): bool => !in_array(mb_strtolower($a, 'UTF-8'), $main, true)
        ));

        if ($recipients === []) {
            // Un `to` vuoto è uno stop voluto; solo indirizzi storti, no.
            if ($parsed['invalid'] !== []) {
                (self::$reporter ?? self::defaultReporter($key))(self::ONLY_INVALID, [
                    'key' => $key,
                    'to' => $parsed['invalid'],
                ]);
            }

            return ['status' => self::CANCELLED, 'to' => [], 'cc' => [], 'sent' => [], 'failed' => []];
        }

        $subject = (string) $message['subject'];
        $body = (string) $message['body'];
        $files = is_array($message['attachments'] ?? null) ? array_map('strval', $message['attachments']) : [];
        $transport = self::$transport ?? self::defaultTransport();
        $sent = [];
        $failed = [];
        $pending = $copies;
        $copied = [];

        foreach ($recipients as $address) {
            try {
                if ($transport($address, $subject, $body, $files, $pending) !== true) {
                    throw new RuntimeException('Il server di posta non ha accettato l\'email.');
                }

                $sent[] = $address;
                // Le copie una volta sola: le altre email partono senza.
                $copied = [...$copied, ...$pending];
                $pending = [];
            } catch (Throwable $error) {
                $failed[] = $address;
                Errors::internal($error, 'mail.'.$key, ['to' => $address, 'cc' => $pending]);
            }
        }

        return [
            'status' => $sent === [] ? self::FAILED : self::SENT,
            'to' => $recipients,
            'cc' => $copied,
            'sent' => $sent,
            // Le copie non partite contano come fallite.
            'failed' => [...$failed, ...$pending],
        ];
    }

    /** Cambia il modo di spedire; `null` torna a `sendMail()` del core. Serve ai test. */
    public static function useTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /** Cambia chi riceve le segnalazioni; `null` torna al registro del core. Serve ai test. */
    public static function reportUsing(?callable $reporter): void
    {
        self::$reporter = $reporter;
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
     * Il corpo come arriva a `sendMail()`: quello composto con `MailMessage`
     * passa intatto (il core lo riconosce e non lo ripulisce), gli altri si
     * difendono da `sanitizeEcho()` come prima.
     */
    public static function prepare(string $body): string
    {
        return MailBuilder::isBuilt($body) ? $body : self::shield($body);
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

    /** @return array{valid: list<string>, invalid: list<string>} */
    private static function addresses(mixed $value): array
    {
        return Recipients::parse(is_array($value) ? implode(',', array_map('strval', $value)) : (string) $value);
    }

    /** Il core sa mettere in copia? Solo da wonder-image/app#202 `sendMail()` ha `$options`. */
    private static function coreSupportsCc(): bool
    {
        try {
            foreach ((new \ReflectionFunction('sendMail'))->getParameters() as $parameter) {
                if ($parameter->getName() === 'options') {
                    return true;
                }
            }
        } catch (Throwable) {
        }

        return false;
    }

    /** @return callable(string, array): void */
    private static function defaultReporter(string $key): callable
    {
        return static function (string $message, array $context) use ($key): void {
            Errors::report('gestionale', 'mail.'.$key, $message, $context);
        };
    }

    /** @return callable(string, string, string, array<string, string>, list<string>): bool */
    private static function defaultTransport(): callable
    {
        return static function (string $to, string $subject, string $body, array $attachments = [], array $cc = []): bool {
            // Le funzioni globali del core ci sono solo nel sito avviato:
            // non in un comando `forge`, non nei test.
            if (!function_exists('sendMail')) {
                throw new RuntimeException('Le email partono solo dal sito avviato: qui manca sendMail().');
            }

            // Su Brevo il core manda la risposta-a senza guardarla, e Brevo ne
            // rifiuta una vuota: l'email non partirebbe mai.
            $from = self::replyTo();
            $html = self::prepare($body);
            $files = $attachments === [] ? null : $attachments;

            if ($cc === [] || self::coreSupportsCc()) {
                return (bool) ($cc === []
                    ? sendMail($from, $to, $subject, $html, $files)
                    : sendMail($from, $to, $subject, $html, $files, options: ['cc' => $cc]));
            }

            // Core senza CC: la copia parte a parte, solo se l'originale è partita.
            if (!sendMail($from, $to, $subject, $html, $files)) {
                return false;
            }

            foreach ($cc as $address) {
                try {
                    sendMail($from, $address, $subject, $html, $files);
                } catch (Throwable $error) {
                    Errors::internal($error, 'mail.cc', ['to' => $address]);
                }
            }

            return true;
        };
    }
}
