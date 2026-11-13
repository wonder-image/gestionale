<?php
/** php tests/HomeSummaryTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Plugin\Gestionale\Backend\Widgets\AttentionWidget;
use Wonder\Plugin\Gestionale\Backend\Widgets\CustomersWidget;
use Wonder\Plugin\Gestionale\Backend\Widgets\QuotesWidget;
use Wonder\Plugin\Gestionale\Backend\Widgets\ShippedNotDeliveredWidget;
use Wonder\Plugin\Gestionale\Backend\Widgets\ShipmentsToCheckWidget;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerCounts;
use Wonder\Plugin\Gestionale\Support\Sales\QuoteCounts;

$adesso = new DateTimeImmutable('2026-11-13 10:00:00');

check('clienti e preventivi stanno sotto le spedizioni, prima di «Da controllare»', function () {
    $widgets = (require __DIR__ . '/../config/module.php')['backend']['home_widgets'];
    $dopo = array_search(ShippedNotDeliveredWidget::class, $widgets, true);

    return is_subclass_of(CustomersWidget::class, HomeWidget::class)
        && is_subclass_of(QuotesWidget::class, HomeWidget::class)
        && array_search(CustomersWidget::class, $widgets, true) === $dopo + 1
        && array_search(QuotesWidget::class, $widgets, true) === $dopo + 2
        && (new CustomersWidget)->order() > (new ShippedNotDeliveredWidget)->order()
        && (new QuotesWidget)->order() > (new CustomersWidget)->order()
        && (new QuotesWidget)->order() < (new AttentionWidget)->order();
});

check('i totali non li vede il commerciale', fn () =>
    !in_array('sales_agent', (new CustomersWidget)->authorities(), true)
    && !in_array('sales_agent', (new QuotesWidget)->authorities(), true)
    && in_array('admin', (new CustomersWidget)->authorities(), true)
    && in_array('admin', (new QuotesWidget)->authorities(), true)
);

check('i clienti si contano in una query: solo clienti, non eliminati, nuovi per data di creazione', function () use ($adesso) {
    $sql = CustomerCounts::countSql('companies', $adesso);

    return substr_count($sql, 'SELECT') === 1
        && str_contains($sql, 'FROM `companies`')
        && str_contains($sql, "`is_customer` = 'true'")
        && str_contains($sql, "`deleted` = 'false'")
        && str_contains($sql, '`creation`')
        && str_contains($sql, '2026-11-01');
});

check('i preventivi si contano in una query sulle righe dell\'elenco Preventivi', function () use ($adesso) {
    $sql = QuoteCounts::countSql($adesso);

    return substr_count($sql, 'SELECT') === 1
        && str_contains($sql, "`stage` = 'quote'")
        && str_contains($sql, "`status` = 'draft'")
        && str_contains($sql, "`status` = 'sent'")
        && str_contains($sql, "`quote_expires_at` < '2026-11-21 00:00:00'");
});

check('valori strani diventano zero', fn () =>
    CustomerCounts::normalize(['totale' => '-3']) === ['totale' => 0, 'mese' => 0]
    && QuoteCounts::normalize(['da_inviare' => '4']) === ['da_inviare' => 4, 'in_attesa' => 0, 'in_scadenza' => 0]
);

check('clienti e aziende: numero, nuovi del mese e link all\'elenco', function () {
    $html = CustomersWidget::markup(['totale' => 23, 'mese' => 9], ['totale' => 6, 'mese' => 1], ['clienti' => '/c/', 'aziende' => '/a/']);
    $zero = CustomersWidget::markup(['totale' => 0, 'mese' => 0], ['totale' => 0, 'mese' => 0]);

    return str_contains($html, '23') && str_contains($html, '9 nuovi questo mese.')
        && str_contains($html, 'Aziende clienti') && str_contains($html, '1 nuova questo mese.')
        && str_contains($html, 'href="/c/"') && str_contains($html, 'href="/a/"')
        && str_contains($html, 'col-xl-6')
        && str_contains($zero, 'Nessuno nuovo questo mese.') && str_contains($zero, 'Nessuna nuova questo mese.');
});

check('senza aziende attive la card delle aziende non c\'è; senza nessuno dei due niente', function () {
    $persone = CustomersWidget::markup(['totale' => 2, 'mese' => 0], null);
    $aziende = CustomersWidget::markup(null, ['totale' => 2, 'mese' => 0]);

    return !str_contains($persone, 'Aziende') && str_contains($persone, 'Clienti')
        && str_contains($aziende, 'Aziende clienti') && !str_contains($aziende, '>Clienti<')
        && CustomersWidget::markup(null, null) === '';
});

check('preventivi: bozze da inviare e inviati in attesa, con quelli in scadenza', function () {
    $html = QuotesWidget::markup(['da_inviare' => 4, 'in_attesa' => 1, 'in_scadenza' => 0], QuotesWidget::urls());
    $scade = QuotesWidget::markup(['da_inviare' => 0, 'in_attesa' => 3, 'in_scadenza' => 2]);

    return str_contains($html, '4 preventivi') && str_contains($html, '1 preventivo')
        && str_contains($html, 'gst_orders__status=draft') && str_contains($html, 'gst_orders__status=sent')
        && str_contains($html, 'aspettano la risposta del cliente')
        && str_contains($scade, '2 scadono entro 7 giorni.')
        && str_contains($scade, '0 preventivi');
});

check('senza la funzionalità Preventivi il riquadro non legge e non disegna niente', function () {
    $stato = new ReflectionProperty(Gestionale::class, 'features');
    $prima = $stato->getValue();
    $stato->setValue(null, ['quotes' => false]);

    try {
        return (new QuotesWidget)->render() === '';
    } finally {
        $stato->setValue(null, $prima);
    }
});

check('le spedizioni restano a coppia subito prima dei clienti', fn () =>
    (new ShipmentsToCheckWidget)->order() + 1 === (new ShippedNotDeliveredWidget)->order()
    && (new ShippedNotDeliveredWidget)->order() + 1 === (new CustomersWidget)->order()
);

summary();
