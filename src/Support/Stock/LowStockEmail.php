<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Throwable;
use Wonder\App\Support\Mail\MailMessage;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Mail\MailBuilder;

/**
 * L'email dei prodotti sotto scorta minima: oggetto e corpo.
 *
 * Il corpo viene dalla vista `view/emails/low-stock.php`, che il sito
 * sostituisce copiandola in `custom/modules/gestionale/view/`. Qui si
 * preparano le righe e il link; la vista decide solo come mostrarli.
 * Senza copia del sito, e con un core che ha `MailMessage`, il corpo si
 * compone con il builder del core.
 */
final class LowStockEmail
{
    public const VIEW = 'emails/low-stock.php';

    public static function subject(int $count): string
    {
        return $count === 1 ? '1 prodotto sotto scorta' : $count.' prodotti sotto scorta';
    }

    /**
     * @param list<array{product_id: int, article: string, option: string, sku: string, threshold: float, available: float}> $items
     * @return array{subject: string, body: string}
     */
    public static function compose(array $items, string $url): array
    {
        $mail = MailBuilder::forView(self::VIEW);

        if ($mail !== null) {
            return ['subject' => self::subject(count($items)), 'body' => self::build($mail, $items, $url)];
        }

        return [
            'subject' => self::subject(count($items)),
            'body' => self::render(Gestionale::viewPath(self::VIEW), [
                'items' => $items,
                'count' => count($items),
                'url' => $url,
                'e' => static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
                'qty' => static fn (mixed $value): string => self::quantity((float) $value),
            ]),
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private static function build(MailMessage $mail, array $items, string $url): string
    {
        $intro = (count($items) === 1 ? 'Questo prodotto è sceso' : 'Questi prodotti sono scesi').' sotto la scorta minima.';
        $rows = [];

        foreach ($items as $item) {
            $name = implode(' · ', array_filter([trim((string) ($item['article'] ?? '')), trim((string) ($item['option'] ?? ''))]));
            $meta = implode(' · ', array_filter([trim((string) ($item['sku'] ?? '')), trim((string) ($item['location'] ?? ''))]));
            $rows[] = [
                $meta !== '' ? $name."\n".$meta : $name,
                self::quantity((float) ($item['available'] ?? 0)),
                self::quantity((float) ($item['threshold'] ?? 0)),
            ];
        }

        $mail->preheader($intro)->heading(self::subject(count($items)))->text($intro)
            ->table(['Prodotto', 'Disponibili', 'Scorta minima'], $rows, null, [1, 2]);

        if (trim($url) !== '') {
            $mail->button('Apri le giacenze sotto scorta', $url);
        }

        return (string) $mail->small("Per ogni prodotto, e per ogni sede, l'avviso arriva una volta sola: torna solo se il prodotto risale sopra la soglia e poi ci ricade.");
    }

    /** Un'email si apre fuori dal sito: il link deve avere il dominio. */
    public static function absoluteUrl(string $path): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        $base = defined('APP_URL') ? rtrim((string) constant('APP_URL'), '/') : '';

        return $base.'/'.ltrim($path, '/');
    }

    /** I pezzi interi si scrivono interi: "3", non "3,000"; gli altri senza zeri in coda. */
    public static function quantity(float $value): string
    {
        if (round($value, 3) === round($value, 0)) {
            return number_format($value, 0, ',', '');
        }

        return rtrim(rtrim(number_format($value, 3, ',', ''), '0'), ',');
    }

    /** @param array<string, mixed> $variables */
    private static function render(string $file, array $variables): string
    {
        extract($variables, EXTR_SKIP);
        ob_start();

        try {
            require $file;
        } catch (Throwable $error) {
            ob_end_clean();

            throw $error;
        }

        return (string) ob_get_clean();
    }
}
