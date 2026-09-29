<?php

namespace Wonder\Plugin\Gestionale\Models\Payments;

use Wonder\Plugin\Gestionale\Models\System\StatusLog;

/**
 * La storia degli stati di un pagamento (4.1).
 *
 * Qui finisce anche la risposta del gateway: `response` è la colonna JSON
 * della base, ed è quella che si guarda quando un incasso non torna.
 */
final class PaymentStatusLog extends StatusLog
{
    public static string $table = 'gst_payment_status_logs';
    public static string $folder = 'gestionale/payments';

    public static function entityColumn(): string
    {
        return 'payment_id';
    }

    public static function entityTable(): string
    {
        return Payment::$table;
    }
}
