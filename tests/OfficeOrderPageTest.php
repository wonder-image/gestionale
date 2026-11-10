<?php
/** php tests/OfficeOrderPageTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Theme;
use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Http\Exceptions\NotFoundHttpException;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\Sales\OfficeOrderResource;
use Wonder\Plugin\Gestionale\Resources\Sales\QuoteResource;
use Wonder\Plugin\Gestionale\Support\Contacts\DocumentReferents;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeCustomerPanel;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderForm;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderPage;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeSearch;

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
        && preg_match('/<input[^>]*name="customer"[^>]*data-wi-remote-search-value/', $html) === 1
        && str_contains($html, 'mixed=1')
        && preg_match('/<input[^>]*type="hidden"[^>]*name="customer_id"[^>]*data-wi-oo-field="customer_id"/', $html) === 1
        && preg_match('/<input[^>]*type="hidden"[^>]*name="company_id"[^>]*value="0"[^>]*data-wi-oo-field="company_id"/', $html) === 1
        && !str_contains($html, 'name="referent_id"')
        && str_contains($html, 'data-wi-remote-search') && str_contains($html, 'data-wi-remote-search-results')
        && str_contains($html, 'data-wi-oo-panel="contacts"') && str_contains($html, 'data-wi-oo-panel="billing"')
        && str_contains($html, 'data-wi-oo-panel="addresses"') && substr_count($html, 'data-wi-oo-edit') === 2
        && !str_contains($html, 'data-wi-oo-customer-')
        && str_contains($html, 'name="lines"') && str_contains($html, 'data-wi-oo-lines')
        && str_contains($html, 'data-wi-office-editor')
        && str_contains($html, 'value="5,00"')
        && str_contains($html, 'Consegna &lt;b&gt;presto&lt;/b&gt;') && !str_contains($html, '<b>presto</b>');
});

check('la chiave composta del cliente: persona, azienda, numero da solo', function () {
    return OfficeSearch::customerKey(12, 0) === 'contact:12'
        && OfficeSearch::customerKey(3, 7) === 'company:7'
        && OfficeSearch::customerKey(0, 0) === ''
        && OfficeSearch::parseCustomerKey('contact:12') === [12, 0]
        && OfficeSearch::parseCustomerKey('company:7') === [0, 7]
        && OfficeSearch::parseCustomerKey(' 15 ') === [15, 0]
        && OfficeSearch::customerKey(3, 7, true) === 'company:7:3'
        && OfficeSearch::customerKey(0, 7, true) === 'company:7'
        && OfficeSearch::parseCustomerKey('company:7:3') === [3, 7]
        && OfficeSearch::parseCustomerKey('contact:12:3') === [0, 0]
        && OfficeSearch::parseCustomerKey('company:x') === [0, 0]
        && OfficeSearch::parseCustomerKey('') === [0, 0]
        && OfficeSearch::companyDetail(['pi' => '01234567890', 'email' => 'a@b.it', 'city' => '']) === 'Azienda · P.IVA 01234567890 · a@b.it'
        && OfficeSearch::companyDetail([]) === 'Azienda';
});

check('un\'azienda cliente: ragione sociale e P.IVA in fatturazione, sedi gestite come per i privati, senza «Modifica azienda»', function () {
    $sedi = OfficeCustomerPanel::addressesHtml([['id' => 4, 'label' => 'Magazzino', 'is_default' => 'true', 'street' => 'Via Roma', 'number' => '1', 'city' => 'Milano']]);
    $fatturazione = OfficeCustomerPanel::companyBillingHtml(['business_name' => 'Rossi srl', 'pi' => '01234567890', 'cf' => '01234567890', 'sdi' => 'ABC1234', 'pec' => 'rossi@pec.it']);

    return str_contains($sedi, 'name="oo_address" value="4"') && str_contains($sedi, 'Magazzino · Predefinito')
        && str_contains($sedi, 'data-wi-oo-address-delete') && !str_contains($sedi, 'Modifica azienda')
        && str_contains($fatturazione, 'Ragione sociale') && str_contains($fatturazione, 'Rossi srl')
        && str_contains($fatturazione, '01234567890') && str_contains($fatturazione, 'ABC1234') && str_contains($fatturazione, 'rossi@pec.it');
});

check('con le relazioni accese, il Referente dell\'azienda: tutte le sue persone, scelto quello del documento, nascosto senza azienda', function () use ($bozza, $pagina) {
    ContactRelations::useEnabled(true);
    DocumentReferents::useReader(static fn (int $companyId): array => [
        ['contact_id' => 3, 'receives_documents' => 'true', 'is_primary' => 'true', 'job_title' => 'Acquisti', 'contact' => ['id' => 3, 'name' => 'Anna', 'surname' => 'Bianchi']],
        ['contact_id' => 5, 'receives_documents' => 'false', 'contact' => ['id' => 5, 'name' => 'Luca', 'surname' => 'Verdi']],
    ]);

    try {
        $html = $pagina(OfficeOrderResource::class, $bozza + ['company_id' => 7, 'customer_id' => 3]);
        $select = preg_match('/<select[^>]*name="referent"[^>]*>.*?<\/select>/s', $html, $m) === 1 ? $m[0] : '';
    } finally {
        ContactRelations::useEnabled(null);
        DocumentReferents::useReader(null);
    }

    return $select !== ''
        && str_contains($select, 'data-wi-oo-field="referent"')
        && preg_match('/<option[^>]*value="3"[^>]*selected/', $select) === 1
        && str_contains($select, 'Acquisti') && str_contains($select, 'value="5"')
        && str_contains($html, 'company_id') && preg_match('/data-[a-z-]*hidden[a-z-]*=/', $html) === 1;
});

check('il Brand dell\'azienda: tendina `company_brand_id`, il brand salvato resta anche se l\'azienda non l\'ha più', function () use ($bozza, $pagina) {
    $salvata = ['id' => 9, 'company_id' => 7, 'customer_id' => 0, 'company_brand_id' => 5, 'company_brand_name' => 'Rossi & <C>'] + $bozza;
    DocumentReferents::useReader(static fn (): array => []);
    $funzionalita = new ReflectionProperty(Gestionale::class, 'features');

    try {
        $funzionalita->setValue(null, ['private_customers' => true, 'companies' => true, 'brands' => true]);
        $html = $pagina(OfficeOrderResource::class, $salvata);
        // Senza «Brand» la tendina non c'è: il documento non posta il brand e tiene il suo.
        $funzionalita->setValue(null, ['private_customers' => true, 'companies' => true]);
        $spenta = $pagina(OfficeOrderResource::class, $salvata);
    } finally {
        $funzionalita->setValue(null, null);
        DocumentReferents::useReader(null);
    }

    $select = preg_match('/<select[^>]*name="company_brand_id"[^>]*>.*?<\/select>/s', $html, $m) === 1 ? $m[0] : '';

    return $select !== '' && str_contains($select, 'data-wi-oo-field="company_brand_id"')
        && preg_match('/<option[^>]*value="5"[^>]*selected[^>]*>Rossi &amp; &lt;C&gt; \(non più dell/', $select) === 1
        && !str_contains($html, 'name="brand_id"') && !str_contains($html, '<C>')
        // Su un documento nuovo, o di un'altra azienda, un id qualunque non diventa una scelta.
        && OfficeOrderPage::brandOptions(7, ['company_id' => 7, 'company_brand_id' => 5]) === ['0' => 'Nessun brand']
        && OfficeOrderPage::brandOptions(8, $salvata) === ['0' => 'Nessun brand']
        && !str_contains($spenta, 'company_brand_id') && !str_contains($spenta, 'Rossi &amp; &lt;C&gt;');
});

check('i Modifica dei riquadri dicono quale finestra aprono', function () use ($bozza, $pagina) {
    $html = $pagina(OfficeOrderResource::class, $bozza);

    return str_contains($html, 'data-wi-oo-edit="contacts"') && str_contains($html, 'data-wi-oo-edit="billing"');
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

check('in testa vicino a Guida: i bottoni premono il gemello nascosto del form', function () use ($bozza, $pagina) {
    $intenti = static fn (array $azioni): array => array_map(static fn (array $a): string => preg_replace('/.*value=(\w+).*/', '$1', $a['onclick']), $azioni);
    $preventivo = OfficeOrderPage::headerActions(true, ['id' => 7] + $bozza);
    $inviato = OfficeOrderPage::headerActions(true, ['id' => 7, 'status' => 'sent', 'quote_revision' => 1] + $bozza);
    $ordine = OfficeOrderPage::headerActions(false, ['id' => 7] + $bozza);
    $form = $pagina(QuoteResource::class, ['id' => 7] + $bozza);

    return $intenti($preventivo) === ['accept', 'send', 'delete']
        && $intenti($inviato) === ['accept', 'send']
        && $intenti($ordine) === ['confirm', 'delete']
        && $intenti(OfficeOrderPage::headerActions(false, $bozza)) === ['confirm']
        && preg_match('/<button[^>]*value="send"[^>]*class="[^"]*d-none|class="[^"]*d-none[^"]*"[^>]*value="send"/', $form) === 1;
});

check('le azioni che non salvano le righe sono Rifiutato ed Elimina', fn () =>
    OfficeOrderPage::WITHOUT_LINES === ['reject', 'delete']
);

// Una bozza che non c'è (o un'azione sconosciuta) è la pagina 404 del backend, non una pagina bianca.
check('l\'azione del menu ⋯ su una bozza inesistente lancia il 404 del core', function () {
    foreach ([OfficeOrderResource::class, QuoteResource::class] as $resource) {
        try {
            $resource::runAction(0, 'delete');

            return false;
        } catch (NotFoundHttpException $e) {
            if ($e->statusCode() !== 404) {
                return false;
            }
        }
    }

    return true;
});

check('la testata posta porta anche l\'azienda, non solo la persona', fn () =>
    OfficeOrderForm::header(['customer' => 'company:7', 'customer_id' => '3', 'company_id' => '7'])
        === ['customer_id' => '3', 'company_id' => '7']
);

summary();
