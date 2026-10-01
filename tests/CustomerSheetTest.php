<?php
/** php tests/CustomerSheetTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Theme;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerSheet;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerStats;

Theme::set('bootstrap');

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

/** I `DataItem` come li vedrebbe il browser. */
function resa(array $items): string
{
    return implode('', array_map(static fn ($i): string => $i->render('bootstrap'), $items));
}

check('i dati: anagrafica, contatti e note ci sono, e il testo è escapato', function () use ($azienda) {
    $html = resa(CustomerSheet::data($azienda, 'Email e password'));

    return str_contains($html, 'Rossi &lt;b&gt;Srl&lt;/b&gt;') && !str_contains($html, '<b>Srl')
        && str_contains($html, 'Azienda') && str_contains($html, '+39 3401234567') && str_contains($html, 'a@rossi.it')
        && str_contains($html, 'Email e password') && str_contains($html, 'Paga a 30 giorni<br')
        && !str_contains($html, '<script>') && str_contains($html, 'Cliente') && str_contains($html, 'Attiva');
});

check('i dati non portano più la fatturazione né il titolo «Chi è»', function () use ($azienda) {
    $html = resa(CustomerSheet::data($azienda, 'Nessun account'));

    return !str_contains($html, 'Chi è') && !str_contains($html, 'Codice fiscale') && !str_contains($html, '00743110157')
        && !str_contains($html, 'Via Roma') && !str_contains($html, 'SDI');
});

check('la fatturazione: codice fiscale, partita IVA, SDI, PEC e indirizzo', function () use ($azienda) {
    $html = resa(CustomerSheet::billing($azienda));

    return str_contains($html, 'RSSMRC80A01F205X') && str_contains($html, '00743110157') && str_contains($html, 'ABCDEFG')
        && str_contains($html, 'p@rossi.it') && str_contains($html, 'Via Roma 1') && str_contains($html, '20100 Milano (MI)');
});

check('un privato non mostra i campi aziendali', function () use ($azienda) {
    $privato = [...$azienda, 'type' => 'private', 'business_name' => '', 'pi' => '', 'sdi' => '', 'pec' => ''];
    $dati = resa(CustomerSheet::data($privato, 'Nessun account'));
    $fatt = resa(CustomerSheet::billing($privato));

    return str_contains($dati, 'Privato') && !str_contains($dati, 'Ragione sociale')
        && str_contains($fatt, 'Codice fiscale') && !str_contains($fatt, 'SDI') && !str_contains($fatt, 'PEC') && !str_contains($fatt, 'Partita IVA');
});

check('dove un dato manca c\'è il trattino', function () use ($azienda) {
    $html = resa(CustomerSheet::data([...$azienda, 'note' => '', 'email' => ''], '—'));

    return substr_count($html, '<span class="text-muted">—</span>') >= 2;
});

check('la card di un indirizzo di consegna: etichetta, predefinito, indirizzo e telefono', function () {
    $html = CustomerSheet::delivery([
        'label' => 'Magazzino <b>', 'name' => 'Giulia', 'surname' => 'Verdi', 'street' => 'Via delle Industrie', 'number' => '8',
        'cap' => '20096', 'city' => 'Pioltello', 'province' => 'MI', 'is_default' => 'true', 'phone_prefix' => '+39', 'phone' => '3331112222',
    ]);

    return str_contains($html, 'Magazzino &lt;b&gt;') && !str_contains($html, '<b>') && str_contains($html, 'Via delle Industrie 8')
        && str_contains($html, 'Predefinito') && str_contains($html, '+39 3331112222') && str_contains($html, 'Giulia Verdi');
});

check('senza etichetta l\'indirizzo si chiama «Indirizzo», e senza predefinito non c\'è il badge', function () {
    $html = CustomerSheet::delivery(['street' => 'Via X', 'is_default' => 'false']);

    return str_contains($html, '>Indirizzo<') && !str_contains($html, 'Predefinito');
});

check('senza indirizzi la frase dice che si spedisce alla fatturazione', fn () =>
    str_contains(CustomerSheet::noDelivery(), 'Nessun indirizzo di consegna')
);

check('una scheda non attiva si legge «Non attiva»', fn () =>
    str_contains(resa(CustomerSheet::data([...$azienda, 'active' => 'false'], '—')), 'Non attiva')
);

check('i coupon: finché non esistono la frase lo dice', fn () =>
    str_contains(CustomerSheet::couponsEmpty(), 'coupon')
);

summary();
