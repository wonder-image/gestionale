<?php
/** php tests/CustomerSheetTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Contacts\CustomerSheet;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerStats;

$azienda = [
    'type' => 'business', 'business_name' => 'Rossi <b>Srl</b>', 'name' => 'Marco', 'surname' => 'Rossi',
    'email' => 'a@rossi.it', 'phone_prefix' => '+39', 'phone' => '3401234567',
    'cf' => 'RSSMRC80A01F205X', 'pi' => '00743110157', 'sdi' => 'ABCDEFG', 'pec' => 'p@rossi.it',
    'country' => 'IT', 'province' => 'MI', 'city' => 'Milano', 'cap' => '20100', 'street' => 'Via Roma', 'number' => '1',
    'is_customer' => 'true', 'is_supplier' => 'false', 'active' => 'true', 'note' => "Paga a 30 giorni\n<script>x</script>",
];

check('le statistiche sono caselle con etichetta e valore all\'italiana', function () {
    $html = CustomerSheet::stats(CustomerStats::of([
        ['stage' => 'order', 'status' => 'confirmed', 'payment_status' => 'paid', 'total' => '1250.00', 'ordered_at' => '2026-09-10 10:00:00'],
    ], []));

    return str_contains($html, 'Ordini') && str_contains($html, 'Speso') && str_contains($html, '1.250,00 €')
        && str_contains($html, 'Scontrino medio') && str_contains($html, '10/09/2026');
});

check('senza ordini le date sono un trattino e il carrello dice «vuoto»', function () {
    $html = CustomerSheet::stats(CustomerStats::of([], []));

    return str_contains($html, '—') && str_contains($html, 'Carrello') && str_contains($html, 'vuoto');
});

check('il carrello pieno dice quanti pezzi e quanto vale', function () {
    $html = CustomerSheet::stats(CustomerStats::of([], [['type' => 'product', 'quantity' => '3.000', 'line_total' => '30.00']]));

    return str_contains($html, '30,00 €') && str_contains($html, '3 pezzi');
});

check('i dati: anagrafica, fatturazione e note ci sono tutti, e il testo è escapato', function () use ($azienda) {
    $html = CustomerSheet::details($azienda, [], 'Email e password');

    return str_contains($html, 'Rossi &lt;b&gt;Srl&lt;/b&gt;') && !str_contains($html, '<b>Srl')
        && str_contains($html, 'Azienda') && str_contains($html, '+39 3401234567')
        && str_contains($html, '00743110157') && str_contains($html, 'ABCDEFG') && str_contains($html, 'p@rossi.it')
        && str_contains($html, 'Via Roma 1') && str_contains($html, '20100 Milano (MI)')
        && str_contains($html, 'Email e password') && str_contains($html, 'Paga a 30 giorni<br')
        && !str_contains($html, '<script>') && str_contains($html, 'Cliente') && str_contains($html, 'Attiva');
});

check('un privato non mostra i campi aziendali', function () use ($azienda) {
    $privato = [...$azienda, 'type' => 'private', 'business_name' => '', 'pi' => '', 'sdi' => '', 'pec' => ''];
    $html = CustomerSheet::details($privato, [], 'Nessun account');

    return str_contains($html, 'Privato') && !str_contains($html, 'SDI') && !str_contains($html, 'PEC') && !str_contains($html, 'Partita IVA');
});

check('gli indirizzi di consegna si elencano con la loro etichetta; senza, c\'è la frase', function () use ($azienda) {
    $con = CustomerSheet::details($azienda, [[
        'label' => 'Magazzino', 'name' => 'Giulia', 'surname' => 'Verdi', 'street' => 'Via delle Industrie', 'number' => '8',
        'cap' => '20096', 'city' => 'Pioltello', 'province' => 'MI', 'is_default' => 'true',
    ]], 'Nessun account');
    $senza = CustomerSheet::details($azienda, [], 'Nessun account');

    return str_contains($con, 'Magazzino') && str_contains($con, 'Via delle Industrie 8') && str_contains($con, 'Predefinito')
        && str_contains($senza, 'Nessun indirizzo di consegna');
});

check('una scheda non attiva si legge «Non attiva»', fn () =>
    str_contains(CustomerSheet::details([...$azienda, 'active' => 'false'], [], '—'), 'Non attiva')
);

check('i coupon: finché non esistono la frase lo dice', fn () =>
    str_contains(CustomerSheet::couponsEmpty(), 'coupon')
);

summary();
