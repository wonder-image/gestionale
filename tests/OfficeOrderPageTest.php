<?php
/** php tests/OfficeOrderPageTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Theme;
use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Plugin\Gestionale\Resources\Sales\OfficeOrderResource;
use Wonder\Plugin\Gestionale\Resources\Sales\QuoteResource;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderPage;

Theme::set('bootstrap');

/*
 * La bozza d'ordine da ufficio e il preventivo si disegnano con i componenti
 * del core: i campi della testata sono `FormField` (con l'aggancio
 * `data-wi-oo-field` per lo script che li riempie dal cliente), i pulsanti
 * `Button` che postano `intent` con la conferma della lib.
 */

$bozza = [
    'id' => 0, 'status' => 'draft', 'billing_type' => 'business', 'billing_business_name' => 'Rossi &amp; figli',
    'fulfillment_type' => 'pickup', 'manual_discount_type' => 'percent', 'manual_discount_value' => '5.00',
    'document_note' => 'Consegna <b>presto</b>', 'editor' => ['lines' => []],
];
// L'editor delle righe legge le categorie fiscali dal database: qui ne basta il contenitore.
$pagina = static fn (string $resource, array $values): string => ResourceFormLayoutRenderer::renderLayout(OfficeOrderPage::layout($resource, $values, '<div data-wi-office-editor></div>'));

check('la testata: ricerca del cliente, dati nascosti per lo script, i tre riquadri del cliente', function () use ($bozza, $pagina) {
    $html = $pagina(OfficeOrderResource::class, $bozza);

    return preg_match('/<input[^>]*type="hidden"[^>]*name="billing_business_name"[^>]*value="Rossi &amp; figli"[^>]*data-wi-oo-field="billing_business_name"/', $html) === 1
        && str_contains($html, 'data-wi-oo-field="billing_country"') && str_contains($html, 'data-wi-oo-field="shipping_street"')
        && str_contains($html, 'name="customer_id"') && str_contains($html, 'data-wi-remote-search-value')
        && str_contains($html, 'data-wi-remote-search') && str_contains($html, 'data-wi-remote-search-results')
        && str_contains($html, 'data-wi-oo-panel="contacts"') && str_contains($html, 'data-wi-oo-panel="billing"')
        && str_contains($html, 'data-wi-oo-panel="addresses"') && substr_count($html, 'data-wi-oo-edit') === 2
        && !str_contains($html, 'data-wi-oo-customer-')
        && str_contains($html, 'name="lines"') && str_contains($html, 'data-wi-oo-lines')
        && str_contains($html, 'data-wi-office-editor')
        && str_contains($html, 'value="5,00"')
        && str_contains($html, 'Consegna &lt;b&gt;presto&lt;/b&gt;') && !str_contains($html, '<b>presto</b>');
});

check('la consegna sta sotto le righe e sopra il pagamento, l\'indirizzo non è più a vista', function () use ($bozza, $pagina) {
    $html = $pagina(OfficeOrderResource::class, $bozza);
    $righe = strpos($html, 'data-wi-office-editor');
    $consegna = strpos($html, 'name="fulfillment_type"');
    $pagamento = strpos($html, 'name="payment_method_id"');

    return $righe !== false && $consegna !== false && $pagamento !== false
        && $righe < $consegna && $consegna < $pagamento
        && !preg_match('/<input[^>]*type="text"[^>]*name="shipping_street"/', $html)
        && str_contains($html, 'name="shipping_amount"')
        && str_contains($html, 'manual_discount_type');
});

check('la bozza d\'ordine: Salva per primo, Conferma con la conferma della lib, l\'interruttore spento', function () use ($bozza, $pagina) {
    $nuova = $pagina(OfficeOrderResource::class, $bozza);
    $salvata = $pagina(OfficeOrderResource::class, ['id' => 7] + $bozza);
    $salva = strpos($nuova, 'value="save"');

    return $salva !== false && $salva < strpos($nuova, 'value="confirm"')
        && !str_contains($nuova, 'value="delete"') && str_contains($salvata, 'value="delete"')
        && str_contains($nuova, 'data-wi-confirm')
        && str_contains($nuova, 'name="notify"') && !preg_match('/name="notify" value="true"[^>]*checked/', $nuova)
        && !str_contains($nuova, 'onclick=');
});

check('il preventivo: Invia, Trasforma in ordine, Elimina o Rifiutato secondo lo stato, la scadenza, l\'email accesa', function () use ($bozza, $pagina) {
    $nuovo = $pagina(QuoteResource::class, $bozza);
    $bozzaSalvata = $pagina(QuoteResource::class, ['id' => 7, 'quote_number' => ''] + $bozza);
    $inviato = $pagina(QuoteResource::class, ['id' => 7, 'status' => 'sent', 'quote_number' => 'P-2026-1'] + $bozza);

    return str_contains($nuovo, 'value="send"') && str_contains($nuovo, 'Invia al cliente')
        && str_contains($nuovo, 'value="accept"') && str_contains($nuovo, 'Trasforma in ordine') && str_contains($nuovo, 'name="quote_expires_at"')
        && preg_match('/name="notify" value="true"[^>]*checked/', $nuovo) === 1
        && str_contains($bozzaSalvata, 'value="accept"') && str_contains($bozzaSalvata, 'value="delete"') && !str_contains($bozzaSalvata, 'value="reject"')
        && str_contains($inviato, 'value="reject"') && !str_contains($inviato, 'value="delete"') && str_contains($inviato, 'Invia di nuovo');
});

check('le azioni che non salvano le righe sono Rifiutato ed Elimina', fn () =>
    OfficeOrderPage::WITHOUT_LINES === ['reject', 'delete']
);

summary();
