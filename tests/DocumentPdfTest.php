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

summary();
