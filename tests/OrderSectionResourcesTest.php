<?php
/** php tests/OrderSectionResourcesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderHistoryTableResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderItemTableResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderPaymentTableResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderReturnTableResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductPhotos;

/**
 * Le quattro tabelle della scheda ordine — righe, pagamenti, resi, storico —
 * sono Resource senza pagina e senza menu: esistono per essere incorporate.
 */
$sezioni = [
    OrderItemTableResource::class => [OrderItem::class, 'ordine-righe', ['photo', 'name', 'quantity', 'unit_price', 'discount_value', 'tax_rate', 'line_total']],
    OrderPaymentTableResource::class => [Payment::class, 'ordine-pagamenti', ['code', 'type', 'amount', 'status', 'paid_at', 'payment_method_id', 'provider', 'provider_reference']],
    OrderReturnTableResource::class => [SalesReturn::class, 'ordine-resi', ['number', 'status', 'requested_at', 'lines', 'actions']],
    OrderHistoryTableResource::class => [OrderStatusLog::class, 'ordine-storico', ['creation', 'field', 'from_value', 'to_value', 'source', 'user_id']],
];

foreach ($sezioni as $classe => [$model, $percorso, $colonne]) {
    $nome = substr($classe, strrpos($classe, '\\') + 1);

    check("{$nome}: ha il suo model e il suo percorso", fn () =>
        $classe::$model === $model && $classe::path() === 'app/gestionale/'.$percorso
    );

    check("{$nome}: nessuna pagina e nessuna voce di menu", function () use ($classe) {
        $pagine = array_filter((array) ($classe::pageSchema()->toArray()['pages'] ?? []));
        $nav = $classe::navigationSchema()->toArray();

        return $pagine === [] && empty($nav['enabled']);
    });

    check("{$nome}: si legge solo da amministratore e non ha API", function () use ($classe) {
        $permessi = $classe::permissionSchema()->toArray();

        return json_encode($permessi) !== false
            && str_contains(json_encode($permessi), 'administrator')
            && empty($classe::apiSchema()->toArray()['enabled']);
    });

    check("{$nome}: le colonne sono quelle attese, tutte con la loro etichetta", function () use ($classe, $colonne) {
        $nomi = array_map(static fn ($c): string => (string) $c->name, $classe::tableSchema());
        $etichette = $classe::labelSchema();

        foreach ($colonne as $c) {
            if (!isset($etichette[$c])) {
                return false;
            }
        }

        return $nomi === $colonne;
    });
}

check('Righe: la prima colonna è la foto, e arriva da un formatter', function () {
    $foto = OrderItemTableResource::tableSchema()[0];

    return $foto->type === 'image' && ($foto->schema['formatter'] ?? null) instanceof Closure;
});

check('Righe: prezzo e totale sono importi', function () {
    $tipi = [];

    foreach (OrderItemTableResource::tableSchema() as $c) {
        $tipi[(string) $c->name] = $c->type;
    }

    return $tipi['unit_price'] === 'price' && $tipi['line_total'] === 'price';
});

check('Righe: il nome porta lo SKU sotto, escapato; la riga di sola nota è in corsivo', function () {
    $nome = array_values(array_filter(
        OrderItemTableResource::tableSchema(),
        static fn ($c): bool => (string) $c->name === 'name'
    ))[0]->schema['formatter'];

    $prodotto = $nome(['type' => 'product', 'name' => '<b>Maglia</b>', 'sku' => 'MG-1']);
    $nota = $nome(['type' => 'text', 'name' => 'Ritiro in sede', 'sku' => '']);

    return str_contains($prodotto, '&lt;b&gt;Maglia&lt;/b&gt;') && !str_contains($prodotto, '<b>Maglia')
        && str_contains($prodotto, 'MG-1')
        && str_contains($nota, 'fst-italic') && str_contains($nota, 'Ritiro in sede');
});

check('Righe: la personalizzazione sta sotto lo SKU, escapata una volta sola; senza, l\'HTML è quello di prima', function () {
    $nome = array_values(array_filter(
        OrderItemTableResource::tableSchema(),
        static fn ($c): bool => (string) $c->name === 'name'
    ))[0]->schema['formatter'];
    $base = ['type' => 'product', 'name' => 'Maglia', 'sku' => 'MG-1'];

    $con = $nome($base + ['customization' => Customizations::encode([['customization_id' => 1, 'label' => 'Incisione', 'value' => '<b>Marco</b> & co', 'option_id' => 0, 'surcharge' => '5.00']])]);
    $senza = $nome($base);

    return str_contains($con, 'Incisione: &lt;b&gt;Marco&lt;/b&gt; &amp; co')
        && !str_contains($con, '<b>Marco')
        && !str_contains($con, '&amp;amp;')
        && strpos($con, 'MG-1') < strpos($con, 'Incisione:')
        && $senza === $nome($base + ['customization' => ''])
        && !str_contains($senza, 'Incisione');
});

check('Righe: il nome come sta nel database (con le entità) si legge una volta sola, senza doppio escape', function () {
    $nome = array_values(array_filter(
        OrderItemTableResource::tableSchema(),
        static fn ($c): bool => (string) $c->name === 'name'
    ))[0]->schema['formatter'];

    $trattino = $nome(['type' => 'product', 'name' => 'Maglietta &#8212; Blu', 'sku' => 'MG-1']);
    $tag = $nome(['type' => 'product', 'name' => '&lt;b&gt;Maglia&lt;/b&gt;', 'sku' => '']);
    $grezzo = $nome(['type' => 'product', 'name' => '<script>x</script>', 'sku' => '']);

    return str_contains($trattino, 'Maglietta — Blu') && !str_contains($trattino, '&amp;')
        && str_contains($tag, '&lt;b&gt;Maglia&lt;/b&gt;') && !str_contains($tag, '&amp;')
        && !str_contains($grezzo, '<script>');
});

check('Righe: lo sconto si legge in percentuale, in euro o con un trattino', function () {
    $sconto = array_values(array_filter(
        OrderItemTableResource::tableSchema(),
        static fn ($c): bool => (string) $c->name === 'discount_value'
    ))[0]->schema['formatter'];

    return str_contains($sconto(['discount_type' => 'percent', 'discount_value' => '10.000']), '10%')
        && str_contains($sconto(['discount_type' => 'amount', 'discount_value' => '5.00']), '5,00 €')
        && str_contains($sconto(['discount_type' => 'none', 'discount_value' => '0']), '—');
});

check('Righe: la riga di sola nota non mostra quantità né importi', function () {
    $quantita = array_values(array_filter(
        OrderItemTableResource::tableSchema(),
        static fn ($c): bool => (string) $c->name === 'quantity'
    ))[0]->schema['formatter'];

    return $quantita(['type' => 'text', 'quantity' => '1.000']) === ''
        && str_contains($quantita(['type' => 'product', 'quantity' => '2.000']), '2');
});

check('Pagamenti: tipo e stato si leggono in italiano, con l\'importo all\'italiana', function () {
    $per = [];

    foreach (OrderPaymentTableResource::tableSchema() as $c) {
        $per[(string) $c->name] = $c;
    }

    $tipo = $per['type']->schema['formatter'];
    $stato = $per['status']->schema['formatter'];

    return str_contains($tipo(['type' => 'refund']), 'Rimborso')
        && str_contains($tipo(['type' => 'payment']), 'Incasso')
        && str_contains($stato(['status' => 'paid']), 'Pagato')
        && $per['amount']->type === 'price';
});

check('Storico: gli stati si leggono con le parole del gestionale', function () {
    $per = [];

    foreach (OrderHistoryTableResource::tableSchema() as $c) {
        $per[(string) $c->name] = $c;
    }

    $cosa = $per['field']->schema['formatter'];
    $a = $per['to_value']->schema['formatter'];

    return str_contains($cosa(['field' => 'payment_status']), 'Pagamento')
        && str_contains($a(['field' => 'status', 'to_value' => 'confirmed']), 'Confermato')
        && str_contains($a(['field' => 'status', 'to_value' => '']), '—');
});

check('Righe: vale la foto copiata sulla riga; le righe che non sono prodotti non ne hanno', fn () =>
    OrderItemTableResource::photoOf(['type' => 'product', 'product_id' => 0, 'image' => '/assets/upload/x.jpg']) === '/assets/upload/x.jpg'
    && OrderItemTableResource::photoOf(['type' => 'shipping', 'product_id' => 5, 'image' => '/assets/upload/x.jpg']) === ''
    && OrderItemTableResource::photoOf(['type' => 'product', 'product_id' => 0, 'image' => '']) === ''
);

check('la tabella dei resi segue la funzionalità «returns»', fn () => OrderReturnTableResource::$feature === 'returns');

check('la foto: vince quella dell\'opzione, poi del colore, poi dell\'articolo', function () {
    $img = static fn (int $id, int $product, int $variant, string $file): array => [
        'id' => $id, 'product_id' => $product, 'product_variant_id' => $variant, 'position' => $id,
        'status' => 'ready', 'file' => $file,
    ];
    $tutte = [$img(1, 0, 0, 'modello.jpg'), $img(2, 0, 5, 'colore.jpg'), $img(3, 9, 0, 'opzione.jpg')];

    return ProductPhotos::pick($tutte, 5, 9) === '/assets/upload/app/gestionale/prodotti/opzione.jpg'
        && ProductPhotos::pick($tutte, 5, 8) === '/assets/upload/app/gestionale/prodotti/colore.jpg'
        && ProductPhotos::pick($tutte, 6, 8) === '/assets/upload/app/gestionale/prodotti/modello.jpg'
        && ProductPhotos::pick([], 6, 8) === '';
});

check('la foto: un\'immagine non ancora pronta non si mostra', function () {
    $non = ['id' => 1, 'product_id' => 0, 'product_variant_id' => 0, 'position' => 1, 'status' => 'queued', 'file' => 'x.jpg'];

    return ProductPhotos::pick([$non], 0, 1) === '';
});

$colonna = static fn (string $nome): Closure => array_values(array_filter(
    OrderItemTableResource::tableSchema(),
    static fn ($c): bool => (string) $c->name === $nome
))[0]->schema['formatter'];

check('Righe: una figlia di confezione è rientrata, senza prezzo, quantità né totale; «Scelta: » solo per le opzioni', function () use ($colonna) {
    $nome = $colonna('name');
    $madre = ['id' => 5, 'type' => 'product', 'name' => 'Confezione', 'sku' => 'CF-1', 'parent_item_id' => 0, 'bundle_option_id' => 0, 'quantity' => '2.000', 'unit_price' => '30.00', 'line_total' => '60.00', 'tax_rate' => '22.00'];
    $fissa = ['id' => 6, 'type' => 'product', 'name' => '<b>Crema</b> & co', 'sku' => 'CR-1', 'parent_item_id' => 5, 'bundle_option_id' => 0, 'quantity' => '2.000', 'unit_price' => '0.00', 'line_total' => '0.00', 'tax_rate' => '22.00'];
    $scelta = ['id' => 7, 'type' => 'product', 'name' => 'Vino rosso', 'sku' => 'VR-1', 'parent_item_id' => 5, 'bundle_option_id' => 9, 'quantity' => '2.000', 'unit_price' => '0.00', 'line_total' => '0.00', 'tax_rate' => '22.00'];

    $htmlFissa = $nome($fissa);
    $htmlScelta = $nome($scelta);

    return str_contains($htmlFissa, 'ms-3') && str_contains($htmlFissa, '&lt;b&gt;Crema&lt;/b&gt;') && !str_contains($htmlFissa, '<b>Crema')
        && !str_contains($htmlFissa, 'Scelta:') && !str_contains($htmlFissa, '&amp;amp;')
        && str_contains($htmlScelta, 'Scelta: Vino rosso') && str_contains($htmlScelta, 'ms-3')
        && !str_contains($nome($madre), 'ms-3') && !str_contains($nome($madre), 'Scelta:')
        && $colonna('quantity')($scelta) === '' && $colonna('discount_value')($scelta) === '' && $colonna('tax_rate')($scelta) === ''
        && $colonna('unit_price')($scelta) === '' && $colonna('line_total')($scelta) === ''
        && str_contains($colonna('unit_price')($madre), '30,00 €') && str_contains($colonna('line_total')($madre), '60,00 €')
        && str_contains($colonna('quantity')($madre), '2');
});

check('Righe: una riga di prima, senza figlie né confezioni, ha le celle di sempre', function () use ($colonna) {
    $riga = ['type' => 'product', 'name' => 'Maglia', 'sku' => 'MG-1', 'quantity' => '1.000', 'unit_price' => '10.50', 'line_total' => '10.50', 'tax_rate' => '22.00'];

    return !str_contains($colonna('name')($riga), 'ms-3')
        && str_contains($colonna('unit_price')($riga), '10,50 €')
        && str_contains($colonna('line_total')($riga), '10,50 €')
        && $colonna('quantity')($riga) === '1';
});

check('Pagamenti: il metodo è quello scelto in Stripe (Klarna), e chi l\'ha gestito è il fornitore', function () {
    $per = [];

    foreach (OrderPaymentTableResource::tableSchema() as $c) {
        $per[(string) $c->name] = $c;
    }

    $metodo = $per['payment_method_id']->schema['formatter'];
    $gestore = $per['provider']->schema['formatter'];

    return $metodo(['payment_method_id' => 0, 'provider_method' => 'klarna']) === 'Klarna'
        && $gestore(['provider' => 'stripe']) === 'Stripe'
        && $gestore(['provider' => 'manual']) === '—'
        && OrderPaymentTableResource::labelSchema()['provider'] === 'Gestito da';
});

check('il nome del metodo: il tipo Stripe se non è la carta, altrimenti il nome del metodo', fn () =>
    \Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod::choiceLabel('klarna', 'Carta di credito') === 'Klarna'
    && \Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod::choiceLabel('card', 'Carta di credito') === 'Carta di credito'
    && \Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod::choiceLabel('', 'Bonifico') === 'Bonifico'
    && \Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod::choiceLabel('kakao_pay', 'Carta di credito') === 'Carta di credito');

summary();
