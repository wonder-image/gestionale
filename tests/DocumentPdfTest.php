<?php
/** php tests/DocumentPdfTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Extensions\Extensions;
use Wonder\Plugin\Gestionale\Extensions\GestionaleExtension;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentFiles;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentPdf;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentPdfs;
use Wonder\Plugin\Gestionale\Support\Documents\OrderProforma;
use Wonder\Plugin\Gestionale\Support\Documents\OrderConfirmationPdf;
use Wonder\Plugin\Gestionale\Support\Documents\QuotePdf;

/** La classe del sito per il preventivo. */
final class PreventivoDelSito extends QuotePdf
{
    public function title(): string
    {
        return 'Offerta';
    }
}

final class EstensioneDiProva extends GestionaleExtension
{
    public function documentPdf(string $type, string $class): string
    {
        return $type === 'quote' ? PreventivoDelSito::class : 'NonEsiste';
    }
}

$dati = [
    'society' => ['name' => 'Negozio', 'legal_name' => 'Negozio Srl', 'address' => 'Via Roma 1, Milano', 'vat' => 'P. IVA 01234567890', 'contacts' => 'info@example.com'],
    'order' => [
        'stage' => 'quote', 'quote_number' => 'PRV-2026-0001', 'quote_revision' => 2, 'quote_sent_at' => '2026-10-13 10:00:00', 'quote_expires_at' => '2026-11-12',
        'billing_business_name' => 'Rossi & Figli Srl', 'billing_street' => 'Viale Italia', 'billing_number' => '40', 'billing_cap' => '20099', 'billing_city' => 'Sesto San Giovanni',
        'billing_province' => 'MI', 'billing_country' => 'IT', 'billing_pi' => '00743110157', 'fulfillment_type' => 'shipping',
        'shipping_name' => 'Marco', 'shipping_street' => 'Via Milano', 'shipping_number' => '2', 'shipping_city' => 'Monza', 'shipping_country' => 'IT',
        'products_total' => '120.00', 'discount_total' => '10.00', 'shipping_total' => '7.50', 'taxable_total' => '96.31', 'tax_total' => '21.19', 'total' => '117.50',
        'document_note' => 'Consegna al piano: è richiesto l\'appuntamento.',
    ],
    'items' => [
        ['type' => 'product', 'name' => 'Maglia — Blu / M', 'sku' => 'MAG-B-M', 'quantity' => '2.000', 'unit_price' => '45.00', 'manual_unit_price' => '50.00', 'discount_type' => 'percent', 'discount_value' => '10.00', 'tax_rate' => '22.00', 'line_total' => '90.00'],
        ['type' => 'custom', 'name' => 'Montaggio', 'quantity' => '1.000', 'unit_price' => '30.00', 'discount_type' => 'none', 'tax_rate' => '22.00', 'line_total' => '30.00'],
        ['type' => 'text', 'name' => 'Tempi di consegna: due settimane dall\'ordine.'],
    ],
    'taxes' => [['rate' => '22.00', 'taxable' => '96.31', 'tax' => '21.19', 'total' => '117.50', 'nature' => '']],
    'payment' => ['method' => 'Bonifico', 'term' => 'RiBa 30/60', 'iban' => 'IT60X0542811101000000123456', 'holder' => 'Negozio Srl'],
];

check('il preventivo è un PDF', function () use ($dati) {
    $pdf = (new QuotePdf($dati))->render();

    return str_starts_with($pdf, '%PDF') && strlen($pdf) > 1000;
});

check('la conferma d\'ordine è un PDF, anche con molte righe su più pagine', function () use ($dati) {
    $dati['items'] = array_fill(0, 80, $dati['items'][0]);
    $dati['payment']['installments'] = [['due_date' => '2026-11-30', 'amount' => 58.75], ['due_date' => '2026-12-31', 'amount' => 58.75]];
    $pdf = (new OrderConfirmationPdf($dati))->render();

    return str_starts_with($pdf, '%PDF') && substr_count($pdf, '/Type /Page') >= 3;
});

check('il DDT è un PDF senza prezzi, con lotto e trasporto', function () use ($dati) {
    $ddt = [
        'society' => $dati['society'],
        'order' => ['order_number' => '2026-10-0012'],
        'note' => [
            'number' => '2026-10-0003', 'date' => '2026-10-13', 'reason' => 'sale', 'transport_by' => 'carrier', 'carrier_name' => 'BRT',
            'transport_started_at' => '2026-10-13 09:30:00', 'goods_appearance' => 'Scatole', 'packages_count' => 2, 'gross_weight' => '12.500',
            'freight_terms' => 'prepaid', 'billing_business_name' => 'Rossi & Figli Srl', 'billing_city' => 'Sesto', 'billing_country' => 'IT',
            'shipping_street' => 'Via Milano', 'shipping_number' => '2', 'shipping_city' => 'Monza', 'shipping_country' => 'IT',
        ],
        'items' => [
            ['description' => 'Maglia — Blu / M', 'sku' => 'MAG-B-M', 'batch_number' => 'L2026-01', 'quantity' => '2.000', 'unit' => 'pz'],
            ['description' => 'Maglia — Blu / M', 'sku' => 'MAG-B-M', 'batch_number' => 'L2026-02', 'quantity' => '1.000', 'unit' => 'pz'],
        ],
        'taxes' => [], 'payment' => [],
    ];
    $pdf = DocumentPdfs::render('delivery_note', $ddt);

    return str_starts_with($pdf, '%PDF') && DocumentPdfs::classFor('delivery_note') === Wonder\Plugin\Gestionale\Support\Documents\DeliveryNotePdf::class;
});

check('euro e accenti passano in Windows-1252, il resto si traslittera', fn () =>
    DocumentPdf::text('1.234,50 € è già') === "1.234,50 \x80 \xE8 gi\xE0"
    && DocumentPdf::text('Rossi &amp; Figli') === 'Rossi & Figli'
    && DocumentPdf::text('—') !== ''
);

check('importi, quantità e date all\'italiana', fn () =>
    DocumentPdf::money('1234.5') === '1.234,50 €'
    && DocumentPdf::quantity('2.500') === '2,5' && DocumentPdf::quantity('3.000') === '3'
    && DocumentPdf::date('2026-10-13 10:00:00') === '13/10/2026' && DocumentPdf::date('0000-00-00') === ''
);

check('il sito sceglie la sua classe; una che non esiste non vale', function () use ($dati) {
    Extensions::use([EstensioneDiProva::class]);

    try {
        return DocumentPdfs::classFor('quote') === PreventivoDelSito::class
            && DocumentPdfs::classFor('order_confirmation') === OrderConfirmationPdf::class
            && str_starts_with(DocumentPdfs::render('quote', $dati), '%PDF');
    } finally {
        Extensions::use(null);
    }
});

check('i file stanno nella loro cartella, e un percorso con .. non passa', function () {
    $base = sys_get_temp_dir().'/gst-doc-'.bin2hex(random_bytes(4));
    DocumentFiles::useBase($base);

    try {
        $relative = DocumentFiles::save('quotes/12-2.pdf', '%PDF-prova');
        $temporaneo = DocumentFiles::temporary('Conferma ORD 1.pdf', '%PDF');
        $rifiutato = false;

        try {
            DocumentFiles::save('../fuori.pdf', 'x');
        } catch (RuntimeException) {
            $rifiutato = true;
        }

        return $relative === 'quotes/12-2.pdf'
            && file_get_contents((string) DocumentFiles::path($relative)) === '%PDF-prova'
            && DocumentFiles::path('quotes/nessuno.pdf') === null
            && DocumentFiles::path('../etc/passwd') === null
            && str_ends_with($temporaneo, '/Conferma-ORD-1.pdf') && is_file($temporaneo)
            && $rifiutato;
    } finally {
        DocumentFiles::useBase(null);
    }
});

check('il proforma di un abbonamento: una voce per riga col periodo, crediti in negativo, IVA per aliquota, senza numero', function () {
    $dati = Wonder\Plugin\Gestionale\Support\Documents\DocumentData::forSubscription([
        'id' => 9, 'code' => 'sub_abc1234', 'billing_name' => 'Lucia', 'billing_surname' => 'Rossi', 'billing_city' => 'Torino',
        'subtotal' => '34.40', 'discount_total' => '0.00', 'tax_rate' => '0.00', 'tax_total' => '6.00', 'total' => '34.40',
        'payment_method_id' => 0, 'payment_term_id' => 0,
        'tax_summaries' => [
            ['rate' => 22.0, 'nature' => '', 'taxable' => 20.0, 'tax' => 4.4, 'total' => 24.4],
            ['rate' => 10.0, 'nature' => '', 'taxable' => 16.0, 'tax' => 1.6, 'total' => 17.6],
        ],
    ], [
        ['name' => 'Pro - Mensile - dal 01/11/2026 al 01/12/2026', 'quantity' => 1, 'unit_price' => 24.4, 'line_total' => 24.4, 'tax_rate' => 22.0],
        ['name' => 'Assistenza - Mensile - dal 01/11/2026 al 01/12/2026', 'quantity' => 2, 'unit_price' => 8.8, 'line_total' => 17.6, 'tax_rate' => 10.0],
    ], [['name' => 'Credito per Backup tolto', 'amount' => 7.6]], '2026-11-01 00:00:00', '2026-12-01 00:00:00', [['due_date' => '2026-11-30', 'amount' => 34.4]]);
    $pdf = DocumentPdfs::render('proforma', $dati);

    return DocumentPdfs::classFor('proforma') === Wonder\Plugin\Gestionale\Support\Documents\ProformaPdf::class
        && str_starts_with($pdf, '%PDF')
        && count($dati['items']) === 3
        && $dati['items'][0]['name'] === 'Pro - Mensile - dal 01/11/2026 al 01/12/2026'
        && $dati['items'][1]['quantity'] === 2 && $dati['items'][1]['tax_rate'] === 10.0
        && $dati['items'][2]['type'] === 'discount' && $dati['items'][2]['line_total'] === -7.6
        && count($dati['taxes']) === 2 && $dati['taxes'][1]['rate'] === 10.0
        && !isset($dati['order']['tax_summaries'])
        && $dati['order']['taxable_total'] === 28.4 && $dati['order']['fulfillment_type'] === 'none'
        && $dati['payment']['installments'] === [['due_date' => '2026-11-30', 'amount' => 34.4]]
        && $dati['meta']['Abbonamento'] === 'sub_abc1234'
        && str_contains((string) $dati['order']['document_note'], 'non valido ai fini fiscali');
});

check('il proforma di un ordine è un PDF; c\'è per gli ordini, non per bozze, annullati e preventivi', function () use ($dati) {
    $dati['order'] = ['stage' => 'order', 'order_number' => '2026/100321', 'ordered_at' => '2026-10-14 09:00:00'] + $dati['order'];
    $pdf = DocumentPdfs::render('order_proforma', $dati);
    $ordine = ['id' => 7, 'stage' => 'order', 'status' => 'pending', 'deleted' => 'false', 'order_number' => '2026/100321'];

    return DocumentPdfs::classFor('order_proforma') === Wonder\Plugin\Gestionale\Support\Documents\OrderProformaPdf::class
        && str_starts_with($pdf, '%PDF')
        && OrderProforma::available($ordine)
        && OrderProforma::available(['status' => 'completed'] + $ordine)
        && !OrderProforma::available(['status' => 'draft'] + $ordine)
        && !OrderProforma::available(['status' => 'cancelled'] + $ordine)
        && !OrderProforma::available(['stage' => 'quote'] + $ordine)
        && !OrderProforma::available(['stage' => 'cart'] + $ordine)
        && OrderProforma::fileName($ordine) === 'proforma-ordine-2026-100321.pdf';
});

check('l\'azienda è la prima riga dell\'indirizzo di spedizione, e manca se non c\'è', function () use ($dati) {
    $righe = new ReflectionMethod(DocumentPdf::class, 'shippingLines');
    $pdf = new QuotePdf($dati);
    $con = $righe->invoke($pdf, [...$dati['order'], 'shipping_business_name' => 'Rossi &amp; Figli Srl']);
    $senza = $righe->invoke($pdf, $dati['order']);

    return $con[0] === 'Rossi & Figli Srl' && $con[1] === 'Marco' && $senza[0] === 'Marco';
});

summary();
