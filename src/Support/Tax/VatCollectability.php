<?php

namespace Wonder\Plugin\Gestionale\Support\Tax;

use Wonder\Plugin\Custom\Fattura\Fattura;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\System\Setting;

/**
 * Esigibilità IVA (I immediata, D differita, S scissione dei pagamenti).
 *
 * Il cliente può averne una sua; vuota vale quella delle impostazioni. Alla
 * vendita si copia sull'ordine, che la porta in fattura. Con la scissione
 * l'IVA la versa il cliente all'Erario: si incassa solo il totale meno l'IVA.
 */
final class VatCollectability
{
    public const VALUES = ['I', 'D', 'S'];

    /** Quella delle impostazioni; senza, immediata. */
    public static function default(): string
    {
        return self::valid((string) (Setting::current()['vat_collectability'] ?? '')) ?? 'I';
    }

    /** Quella del cliente, o la predefinita. */
    public static function forCustomer(int $customerId): string
    {
        $contact = $customerId > 0 ? Contact::findById($customerId) : null;

        return self::valid((string) (is_array($contact) ? ($contact['vat_collectability'] ?? '') : '')) ?? self::default();
    }

    /** Quella scritta sull'ordine, o la predefinita per le righe di prima. */
    public static function of(array $order): string
    {
        return self::valid((string) ($order['vat_collectability'] ?? '')) ?? self::default();
    }

    /**
     * Quanto il cliente paga: con la scissione il totale senza IVA. Un ordine
     * di prima, senza esigibilità scritta, si incassava tutto e resta così.
     */
    public static function payable(array $order): float
    {
        $total = (float) ($order['total'] ?? 0);

        return self::splits($order)
            ? round($total - (float) ($order['tax_total'] ?? 0), 2)
            : round($total, 2);
    }

    /** Scissione dei pagamenti scritta sull'ordine. */
    public static function splits(array $order): bool
    {
        return self::valid((string) ($order['vat_collectability'] ?? '')) === 'S';
    }

    public static function toInvoice(Fattura $invoice, array $order): Fattura
    {
        $invoice->EsigibilitaIVA(self::of($order));

        return $invoice;
    }

    public static function valid(string $value): ?string
    {
        $value = strtoupper(trim($value));

        return in_array($value, self::VALUES, true) ? $value : null;
    }
}
