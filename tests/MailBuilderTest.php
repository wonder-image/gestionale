<?php
/**
 * php tests/MailBuilderTest.php — le email del gestionale composte con
 * `MailMessage` del core. Con un core che non ce l'ha ancora, i corpi restano
 * quelli delle viste: si controlla solo quello.
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Support\Mail\MailMessage;
use Wonder\Plugin\Gestionale\Support\Mail\MailBuilder;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Orders\OrderEmail;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockEmail;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionEmail;

define('APP_URL', 'https://negozio.test/');

$ordine = ['order_number' => '2026-11-0001', 'total' => '42.50'];
$righe = [
    ['name' => 'Tè <b>verde</b> & co.', 'sku' => 'TE-1', 'quantity' => '2', 'line_total' => '30.00'],
    ['name' => 'Scelta A', 'parent_item_id' => 1, 'bundle_option_id' => 3, 'sku' => 'SC-A'],
    ['name' => 'Nota <i>libera</i>', 'type' => 'text'],
];
$extra = [
    'order_url' => '/account/ordini/1',
    'account_url' => '/account/password?t=1&x=2',
    'instructions' => 'Paga entro <b>7 giorni</b>',
    'bank' => ['iban' => 'IT60X0542811101000000123456', 'holder' => 'Negozio'],
];
$riga = ['product_id' => 1, 'location_id' => 1, 'location' => '', 'article' => 'Tè <b>verde</b> & co.', 'option' => '', 'sku' => 'TE-1', 'threshold' => 5.0, 'available' => 2.5];

if (!MailBuilder::available()) {
    check('senza MailMessage nel core i corpi vengono dalle viste', fn () =>
        !str_contains(OrderEmail::compose('received', $ordine, $righe, $extra)['body'], '<!--wi-mail')
        && !str_contains(LowStockEmail::compose([$riga], 'https://negozio.test/x')['body'], '<!--wi-mail')
    );
    check('senza MailMessage prepare() difende sempre il corpo', fn () => Mailer::prepare('<p>a</p>') === Mailer::shield('<p>a</p>'));
    summary();
}

check('ogni email degli ordini è composta con il builder', function () use ($ordine, $righe, $extra) {
    foreach (OrderEmail::KEYS as $key) {
        $body = OrderEmail::compose($key, $ordine, $righe, $extra)['body'];

        if (!is_string($body) || !MailMessage::isBuilt($body)) {
            throw new RuntimeException("non composta: {$key}");
        }
    }

    return true;
});

check('ogni email degli abbonamenti è composta con il builder', function () {
    foreach (SubscriptionEmail::KEYS as $key) {
        $body = SubscriptionEmail::compose($key, ['total' => '9.90'], ['plan' => 'Base', 'account_url' => '/account'])['body'];

        if (!MailMessage::isBuilt($body)) {
            throw new RuntimeException("non composta: {$key}");
        }
    }

    return true;
});

check('l\'email sotto scorta è composta con il builder, con il pulsante', function () use ($riga) {
    $body = LowStockEmail::compose([$riga], 'https://negozio.test/backend/giacenze?sotto=1')['body'];

    return MailMessage::isBuilt($body) && str_contains($body, 'Apri le giacenze sotto scorta');
});

check('righe, istruzioni e note arrivano come testo, escapate una volta', function () use ($ordine, $righe, $extra) {
    $body = OrderEmail::compose('received', $ordine, $righe, $extra)['body'];

    return str_contains($body, 'Tè &lt;b&gt;verde&lt;/b&gt; &amp; co.')
        && str_contains($body, 'Nota &lt;i&gt;libera&lt;/i&gt;')
        && str_contains($body, 'Paga entro &lt;b&gt;7 giorni&lt;/b&gt;')
        && !str_contains($body, '<b>verde</b>')
        && !str_contains($body, '&amp;lt;');
});

check('il cliente vede pulsanti, totale e scelte del bundle, non lo SKU', function () use ($ordine, $righe, $extra) {
    $body = OrderEmail::compose('received', $ordine, $righe, $extra)['body'];

    return str_contains($body, 'href="https://negozio.test/account/ordini/1"')
        && str_contains($body, 'href="https://negozio.test/account/password?t=1&amp;x=2"')
        && str_contains($body, '42,50 €')
        && str_contains($body, 'Scelta: Scelta A')
        && !str_contains($body, 'TE-1');
});

check('il commerciante vede lo SKU e niente bonifico', function () use ($ordine, $righe, $extra) {
    $body = OrderEmail::compose('merchant_new', $ordine, $righe, $extra)['body'];

    return str_contains($body, 'TE-1') && str_contains($body, 'SC-A') && !str_contains($body, 'IT60X');
});

check('prepare(): il corpo composto passa intatto, quello scritto a mano si difende', fn () =>
    Mailer::prepare((string) MailMessage::make()->text('a & b')) === (string) MailMessage::make()->text('a & b')
    && Mailer::prepare('<p>a &lt;b&gt;</p>') === Mailer::shield('<p>a &lt;b&gt;</p>')
);

check('il sito che ha copiato la vista tiene la sua', function () use ($ordine) {
    $root = sys_get_temp_dir().'/gst-email-'.uniqid();
    $vista = $root.'/custom/modules/gestionale/view/'.OrderEmail::CUSTOMER_VIEW;
    mkdir(dirname($vista), 0777, true);
    file_put_contents($vista, '<p>Mia: <?= $e($title) ?></p>');

    $prima = $GLOBALS['ROOT'] ?? null;
    $GLOBALS['ROOT'] = $root;

    try {
        $body = OrderEmail::compose('paid', $ordine, [])['body'];
    } finally {
        $GLOBALS['ROOT'] = $prima;
        unlink($vista);
    }

    return str_starts_with($body, '<p>Mia: ') && !MailMessage::isBuilt($body);
});

// Foto: una cartella finta con originale grande e misura da 240px.
$foto = function (array $nomi): string {
    $root = sys_get_temp_dir().'/gst-foto-'.uniqid();
    mkdir($root.'/assets/upload/products', 0777, true);

    foreach ($nomi as $nome => $conVariante) {
        $img = imagecreatetruecolor(1200, 1200);
        imagejpeg($img, $root.'/assets/upload/products/'.$nome.'.jpg');

        if ($conVariante) {
            imagejpeg(imagescale($img, 240), $root.'/assets/upload/products/'.$nome.'-240.jpg');
        }
    }

    return $root;
};
$conFoto = function (string $root, callable $fn) {
    $prima = $GLOBALS['ROOT'] ?? null;
    $GLOBALS['ROOT'] = $root;

    try {
        return $fn();
    } finally {
        $GLOBALS['ROOT'] = $prima;
    }
};
$righeFoto = [
    ['id' => 1, 'name' => 'Tè', 'image' => '/assets/upload/products/a.jpg', 'quantity' => '1', 'unit_price' => '10', 'line_total' => '10'],
    ['id' => 2, 'name' => 'Tazza', 'image' => 'https://negozio.test/assets/upload/products/b.jpg', 'quantity' => '1', 'unit_price' => '5', 'line_total' => '5'],
];

check('al cliente le foto piccole da 240px, mai l\'originale', function () use ($foto, $conFoto, $righeFoto, $ordine) {
    $body = $conFoto($foto(['a' => true, 'b' => true]), fn () => OrderEmail::compose('received', $ordine, $righeFoto)['body']);

    return substr_count($body, '<img') >= 2
        && str_contains($body, 'https://negozio.test/assets/upload/products/a-240.jpg')
        && str_contains($body, 'https://negozio.test/assets/upload/products/b-240.jpg')
        && !str_contains($body, 'products/a.jpg');
});

check('se un prodotto non ha la foto, nessuna foto e nessun segnaposto', function () use ($foto, $conFoto, $righeFoto, $ordine) {
    $righe = $righeFoto;
    $righe[1]['image'] = '';
    $body = $conFoto($foto(['a' => true]), fn () => OrderEmail::compose('received', $ordine, $righe)['body']);

    return !str_contains($body, 'a-240.jpg') && !str_contains($body, 'wi-mail-item-placeholder');
});

check('un originale grande senza la misura piccola non entra', function () use ($foto, $conFoto, $righeFoto, $ordine) {
    $body = $conFoto($foto(['a' => true, 'b' => false]), fn () => OrderEmail::compose('received', $ordine, $righeFoto)['body']);

    return !str_contains($body, 'products/a-240.jpg') && !str_contains($body, 'products/b.jpg');
});

check('le email interne non hanno foto', function () use ($foto, $conFoto, $righeFoto, $ordine) {
    $body = $conFoto($foto(['a' => true, 'b' => true]), fn () => OrderEmail::compose('merchant_new', $ordine, $righeFoto)['body']);

    return !str_contains($body, 'a-240.jpg') && !str_contains($body, 'wi-mail-item-placeholder');
});

check('bundle con foto: le scelte senza foto non tolgono le foto', function () use ($foto, $conFoto, $righeFoto, $ordine) {
    $righe = $righeFoto;
    $righe[] = ['id' => 3, 'name' => 'Scelta A', 'parent_item_id' => 1, 'bundle_option_id' => 3, 'image' => ''];
    $body = $conFoto($foto(['a' => true, 'b' => true]), fn () => OrderEmail::compose('received', $ordine, $righe)['body']);

    return str_contains($body, 'a-240.jpg') && str_contains($body, 'b-240.jpg') && str_contains($body, 'Scelta: Scelta A');
});

check('le righe senza foto copiata prendono quella del prodotto, le scelte no', function () {
    $chiesti = [];
    $righe = \Wonder\Plugin\Gestionale\Support\Orders\OrderNotifier::withPhotos([
        ['id' => 1, 'product_id' => 7, 'image' => ''],
        ['id' => 2, 'product_id' => 8, 'image' => '/assets/upload/products/x.jpg'],
        ['id' => 3, 'product_id' => 9, 'parent_item_id' => 1, 'image' => ''],
        ['id' => 4, 'type' => 'text', 'image' => ''],
    ], function (int $id) use (&$chiesti): string {
        $chiesti[] = $id;

        return '/assets/upload/products/p'.$id.'.jpg';
    });

    return $chiesti === [7]
        && $righe[0]['image'] === '/assets/upload/products/p7.jpg'
        && $righe[1]['image'] === '/assets/upload/products/x.jpg'
        && $righe[2]['image'] === '';
});

check('i totali sono scomposti: prodotti, spedizione, IVA inclusa, totale', function () use ($righe, $extra) {
    $ordine = ['order_number' => '1', 'products_total' => '30', 'discount_total' => '0', 'shipping_total' => '7.50',
        'fees_total' => '0', 'tax_total' => '6.76', 'total' => '37.50', 'prices_include_tax' => 'true'];
    $body = OrderEmail::compose('received', $ordine, $righe, $extra)['body'];

    return str_contains($body, 'Prodotti') && str_contains($body, 'Spedizione') && str_contains($body, '7,50 €')
        && str_contains($body, 'di cui IVA') && str_contains($body, '37,50 €') && !str_contains($body, 'Sconto');
});

check('l\'email sotto scorta è una tabella con le colonne', function () use ($riga) {
    $body = LowStockEmail::compose([$riga], 'https://negozio.test/x')['body'];

    return str_contains($body, '<table') && str_contains($body, 'Disponibili') && str_contains($body, 'Scorta minima')
        && str_contains($body, 'Tè &lt;b&gt;verde&lt;/b&gt; &amp; co.');
});

summary();
