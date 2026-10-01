<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

/**
 * Le azioni che il backend offre su un ordine, e la frase che dice cosa
 * faranno.
 *
 * Pura: non legge e non scrive. Dice *quali* azioni valgono per un ordine; il
 * lavoro lo fa `Lifecycle`, che resta l'unico a cambiare lo stato.
 */
final class OrderActions
{
    public const CONFIRM = 'confirm';
    public const CANCEL = 'cancel';
    public const FULFILL = 'fulfill';

    private const LABELS = [self::CONFIRM => 'Conferma', self::FULFILL => 'Segna evaso', self::CANCEL => 'Annulla'];
    private const CLASSES = [self::CONFIRM => 'btn-success', self::FULFILL => 'btn-primary', self::CANCEL => 'btn-outline-danger'];
    private const ICONS = [self::CONFIRM => 'bi-check2-circle', self::FULFILL => 'bi-box-seam', self::CANCEL => 'bi-x-circle'];

    /**
     * Le azioni che valgono adesso, nell'ordine dei pulsanti: annullare sta
     * sempre per ultimo.
     *
     * `$features` entra perché le voci che dipendono da una funzionalità
     * (il reso, nel Piano 5) si aggiungano senza cambiare la firma.
     *
     * @param array<string, mixed> $order
     * @param array<string, bool> $features
     * @return list<string>
     */
    public static function available(array $order, array $features = []): array
    {
        $status = (string) ($order['status'] ?? '');
        $azioni = [];

        if (in_array($status, ['draft', 'pending'], true)) {
            $azioni[] = self::CONFIRM;
        }

        if (in_array($status, ['confirmed', 'processing'], true)
            && (string) ($order['fulfillment_status'] ?? 'unfulfilled') === 'unfulfilled') {
            $azioni[] = self::FULFILL;
        }

        if (in_array($status, ['draft', 'pending', 'confirmed', 'processing'], true)) {
            $azioni[] = self::CANCEL;
        }

        return $azioni;
    }

    /**
     * «Registra pagamento» vale finché l'ordine è vivo e c'è ancora qualcosa
     * da incassare.
     *
     * Non sta in `available()`: quelle azioni aprono una finestra di
     * conferma, questa porta a una pagina con i suoi campi.
     *
     * @param array<string, mixed> $order
     */
    public static function canRegisterPayment(array $order): bool
    {
        return in_array((string) ($order['status'] ?? ''), ['pending', 'confirmed', 'processing'], true)
            && in_array((string) ($order['payment_status'] ?? 'unpaid'), ['unpaid', 'pending', 'partially_paid'], true);
    }

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? '';
    }

    public static function buttonClass(string $action): string
    {
        return self::CLASSES[$action] ?? '';
    }

    public static function icon(string $action): string
    {
        return self::ICONS[$action] ?? '';
    }

    /**
     * La frase della finestra: cosa succede al magazzino e al denaro.
     *
     * I pezzi si contano dalle righe `product`. Se l'ordine ha incassato
     * qualcosa, chi chiama mette l'importo in `paid_total`.
     *
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $items
     */
    public static function summary(string $action, array $order, array $items): string
    {
        $pezzi = 0.0;

        foreach ($items as $item) {
            if ((string) ($item['type'] ?? '') === 'product') {
                $pezzi += (float) ($item['quantity'] ?? 0);
            }
        }

        $singolare = abs($pezzi - 1.0) < 0.0005;
        $quanti = OrderSheet::number($pezzi).($singolare ? ' pezzo' : ' pezzi');

        return match ($action) {
            self::CONFIRM => 'Conferma l\'ordine e scarica '.$quanti.'.',
            self::CANCEL => self::cancelSentence($order, $quanti, $singolare),
            self::FULFILL => 'Segna l\'ordine come evaso: il magazzino non cambia, la merce è già uscita alla conferma.',
            default => '',
        };
    }

    /** @param array<string, mixed> $order */
    private static function cancelSentence(array $order, string $quanti, bool $singolare): string
    {
        $merce = in_array((string) ($order['status'] ?? ''), ['confirmed', 'processing'], true)
            ? 'rimette '.$quanti.' in magazzino'
            : 'libera '.$quanti.($singolare ? ' prenotato' : ' prenotati');
        $frase = 'Annulla l\'ordine e '.$merce.'.';
        $incassato = (float) ($order['paid_total'] ?? 0);

        if ($incassato > 0.004) {
            $frase .= ' Il denaro già incassato ('.OrderSheet::money($incassato).') non si rimborsa da qui.';
        }

        return $frase;
    }
}
