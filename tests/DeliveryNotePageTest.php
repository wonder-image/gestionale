<?php
/** php tests/DeliveryNotePageTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Plugin\Gestionale\Resources\Sales\DeliveryNoteResource;
use Wonder\App\Theme;

Theme::set('bootstrap');

/*
 * La pagina del DDT si disegna con i componenti del core: `Card`,
 * `FormField`, `Button`. Niente campi scritti a mano e niente script della
 * pagina: le conferme sono quelle della lib.
 */

$bozza = [
    'id' => 12, 'status' => 'draft', 'number' => '', 'date' => '',
    'shipping_name' => 'Ada &amp; C.', 'shipping_city' => 'Milano', 'shipping_country' => '',
    'transport_by' => 'carrier', 'carrier_id' => '0', 'transport_started_at' => '2026-10-14 09:30:00',
    'freight_terms' => 'collect', 'packages_count' => '2', 'gross_weight' => '1.500', 'net_weight' => '0',
    'document_note' => 'Fragile', 'order' => [],
    'lines' => [
        ['id' => 31, 'description' => 'Tazza <b>blu</b>', 'sku' => 'TZ-1', 'batch_number' => 'L7', 'quantity' => '2.000', 'unit' => 'pz'],
    ],
];
$html = static fn (array $values): string => ResourceFormLayoutRenderer::renderLayout(DeliveryNoteResource::formLayoutFor($values));

check('la bozza ha i campi del core, con i valori del DDT e il paese che parte dall\'Italia', function () use ($bozza, $html) {
    $pagina = $html($bozza);

    return str_contains($pagina, 'name="shipping_name"') && str_contains($pagina, 'value="Ada &amp; C."')
        && preg_match('/name="shipping_country"[^>]*value="IT"|value="IT"[^>]*name="shipping_country"/', $pagina) === 1
        && preg_match('/<select[^>]*name="transport_by"/', $pagina) === 1
        && str_contains($pagina, 'type="datetime-local"') && str_contains($pagina, '2026-10-14T09:30')
        && str_contains($pagina, 'name="document_note"')
        && str_contains($pagina, 'name="qty_31"') && str_contains($pagina, 'value="2"')
        && str_contains($pagina, 'Tazza &lt;b&gt;blu&lt;/b&gt;') && !str_contains($pagina, '<b>blu</b>')
        && str_contains($pagina, 'Lotto L7');
});

check('la bozza ha Salva per primo, Emetti ed Elimina con la conferma della lib', function () use ($bozza, $html) {
    $pagina = $html($bozza);
    $salva = strpos($pagina, 'value="save"');

    return $salva !== false && $salva < strpos($pagina, 'value="issue"') && $salva < strpos($pagina, 'value="delete"')
        && substr_count($pagina, 'name="intent"') === 3
        && substr_count($pagina, 'data-wi-confirm') >= 2
        && !str_contains($pagina, '<script')
        && !str_contains($pagina, 'disabled');
});

check('l\'emesso ha i campi spenti, niente quantità da scrivere, Annulla, Invia e il PDF', function () use ($bozza, $html) {
    $pagina = $html(['status' => 'issued', 'number' => '2026/0004', 'pdf_file' => 'delivery-notes/12.pdf'] + $bozza);

    return str_contains($pagina, '2026/0004')
        && str_contains($pagina, 'disabled')
        && !str_contains($pagina, 'name="qty_31"')
        && str_contains($pagina, 'value="cancel"') && str_contains($pagina, 'value="send"')
        && !str_contains($pagina, 'value="issue"') && !str_contains($pagina, 'value="save"')
        && str_contains($pagina, '/12/pdf/');
});

check('un DDT annullato non ha pulsanti d\'azione', function () use ($bozza, $html) {
    $pagina = $html(['status' => 'cancelled'] + $bozza);

    return !str_contains($pagina, 'name="intent"');
});

check('senza righe la pagina lo dice', fn () => str_contains($html(['lines' => []] + $bozza), 'Nessuna riga.'));

summary();
