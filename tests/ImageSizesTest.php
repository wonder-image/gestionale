<?php
/** php tests/ImageSizesTest.php — le misure delle foto passano dalla coda del core (nessun database). */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Media\MemoryResizeStore;
use Wonder\App\Media\ResizeQueue;
use Wonder\Plugin\Gestionale\Support\Catalog\ImageSizes;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;

$root = sys_get_temp_dir().'/wi-gst-image-sizes-'.getmypid();
$dir = $root.'/assets/upload'.ProductImages::folder();
mkdir($dir, 0777, true);

register_shutdown_function(static function () use ($root, $dir): void {
    foreach (glob($dir.'*') ?: [] as $file) {
        @unlink($file);
    }

    for ($path = rtrim($dir, '/'); strlen($path) >= strlen($root); $path = dirname($path)) {
        @rmdir($path);
    }
});

// Un jpg vero: senza coda le misure si generano subito, con GD.
$jpg = static function (string $name) use ($dir): string {
    $image = imagecreatetruecolor(600, 400);
    imagefill($image, 0, 0, imagecolorallocate($image, 31, 78, 216));
    imagejpeg($image, $dir.$name, 80);

    return $dir.$name;
};

$viva = static fn (): MemoryResizeStore => new MemoryResizeStore(gmdate('Y-m-d H:i:s'));
$ferma = static fn (): MemoryResizeStore => new MemoryResizeStore(null);

ResizeQueue::useRoot($root);

check('le misure sono quelle del core, anche dove il sito non le dichiara', fn () =>
    ImageSizes::widths() === (defined('RESPONSIVE_IMAGE_SIZES') ? array_values(RESPONSIVE_IMAGE_SIZES) : [240, 480, 620, 960, 1200, 1440, 1920, 2400])
    && ImageSizes::webp() === true
);

check('solo png, jpg e webp hanno misure: un video no', fn () =>
    ImageSizes::resizable('/x/foto.JPG') && ImageSizes::resizable('/x/foto.png') && ImageSizes::resizable('/x/foto.webp')
    && !ImageSizes::resizable('/x/spot.mp4') && !ImageSizes::resizable('/x/logo.svg') && !ImageSizes::resizable('/x/senza-estensione')
);

check('con lo scheduler vivo una foto va nella coda del core, con le misure del sito', function () use ($jpg, $viva) {
    ResizeQueue::useStore($store = $viva());
    $path = $jpg('coda.jpg');

    return ImageSizes::prepare($path) === ImageSizes::QUEUED
        && count($store->rows) === 1
        && $store->rows[1]['path'] === 'assets/upload'.ProductImages::folder().'coda.jpg'
        && $store->rows[1]['widths'] === ImageSizes::widths()
        && $store->rows[1]['webp'] === true
        && ResizeQueue::status($path) === ResizeQueue::PENDING
        && glob(dirname($path).'/coda-*') === [];
});

check('un video non si accoda e non si ridimensiona', function () use ($dir, $viva) {
    ResizeQueue::useStore($store = $viva());
    file_put_contents($dir.'spot.mp4', 'video');

    return ImageSizes::prepare($dir.'spot.mp4') === ImageSizes::SKIPPED
        && ImageSizes::prepare($dir.'non-esiste.jpg') === ImageSizes::SKIPPED
        && $store->rows === [];
});

check('con lo scheduler fermo le misure si generano subito, come fa uploadFiles()', function () use ($jpg, $ferma) {
    ResizeQueue::useStore($store = $ferma());
    $path = $jpg('subito.jpg');

    return ImageSizes::prepare($path) === ImageSizes::RESIZED
        && $store->rows === []
        && is_file(dirname($path).'/subito-240.jpg')
        && ResizeQueue::status($path) === null;
});

check('una foto che non si lascia ridimensionare non fa fallire chi la stava salvando', function () use ($dir, $ferma) {
    ResizeQueue::useStore($ferma());
    file_put_contents($dir.'rotta.jpg', 'non è un jpg');

    return ImageSizes::prepare($dir.'rotta.jpg') === ImageSizes::FAILED;
});

check('la consegna accoda anche con lo scheduler fermo: niente si ridimensiona adesso', function () use ($jpg, $ferma) {
    ResizeQueue::useStore($store = $ferma());
    $path = $jpg('consegna.jpg');

    return ImageSizes::handOver($path) === true
        && count($store->rows) === 1
        && $store->rows[1]['status'] === ResizeQueue::PENDING
        && glob(dirname($path).'/consegna-*') === [];
});

check('una riga che la coda ha già non si rimette in attesa', function () use ($jpg, $viva) {
    ResizeQueue::useStore($store = $viva());
    $path = $jpg('gia.jpg');
    ImageSizes::handOver($path);
    $store->rows[1]['status'] = ResizeQueue::READY;
    $store->rows[1]['attempts'] = 1;

    return ImageSizes::handOver($path) === true
        && count($store->rows) === 1
        && $store->rows[1]['status'] === ResizeQueue::READY
        && $store->rows[1]['attempts'] === 1;
});

check('senza la tabella della coda, o fuori dal sito, la consegna dice di no', function () use ($jpg, $viva) {
    $store = $viva();
    $store->usable = false;
    ResizeQueue::useStore($store);
    $path = $jpg('senza-coda.jpg');

    $senzaTabella = ImageSizes::handOver($path);

    ResizeQueue::useStore($viva());

    return $senzaTabella === false && ImageSizes::handOver('/altrove/foto.jpg') === false;
});

// ── Lo stato di una foto, letto dalla coda del core ──────────────────────────

$foto = static fn (string $file, string $status = 'ready', string $error = ''): array =>
    ['id' => 1, 'file' => json_encode([$file]), 'status' => $status, 'error' => $error];

check('lo stato di una foto lo dice la coda del core, non la colonna', function () use ($jpg, $viva, $foto, $root) {
    ResizeQueue::useStore($store = $viva());
    $jpg('stato.jpg');
    ImageSizes::prepare($root.'/assets/upload'.ProductImages::folder().'stato.jpg');

    $inCoda = ProductImages::state($foto('stato.jpg', 'ready'), $root);

    $store->rows[1]['status'] = ResizeQueue::FAILED;
    $store->rows[1]['error'] = 'Formato non gestito.';
    $fallita = ProductImages::state($foto('stato.jpg', 'ready'), $root);
    $errore = ProductImages::error($foto('stato.jpg', 'ready'), $root);

    $store->rows[1]['status'] = ResizeQueue::READY;
    $store->rows[1]['error'] = '';

    return $inCoda === ProductImages::PENDING
        && $fallita === ProductImages::FAILED
        && $errore === 'Formato non gestito.'
        // La colonna può dire quello che vuole: vince la coda.
        && ProductImages::state($foto('stato.jpg', 'pending'), $root) === ProductImages::READY;
});

check('dove la coda non sa niente del file vale la colonna della vecchia coda', function () use ($viva, $foto, $root) {
    ResizeQueue::useStore($viva());

    return ProductImages::state($foto('mai-accodata.jpg', 'pending'), $root) === ProductImages::PENDING
        && ProductImages::state($foto('mai-accodata.jpg', 'failed', 'File rotto'), $root) === ProductImages::FAILED
        && ProductImages::error($foto('mai-accodata.jpg', 'failed', 'File rotto'), $root) === 'File rotto'
        && ProductImages::state($foto('mai-accodata.jpg', 'ready'), $root) === ProductImages::READY;
});

check('un video è sempre pronto, e una riga senza file anche', function () use ($viva, $foto, $root) {
    ResizeQueue::useStore($viva());

    return ProductImages::state($foto('spot.mp4', 'pending'), $root) === ProductImages::READY
        && ProductImages::state(['file' => '[]', 'status' => 'pending'], $root) === ProductImages::READY;
});

check('la scheda avvisa delle foto in elaborazione e di quelle non riuscite', function () use ($viva, $foto, $root) {
    ResizeQueue::useStore($viva());

    $avvisi = ProductImages::notices([
        $foto('a.jpg', 'pending'),
        $foto('b.jpg', 'pending'),
        $foto('c.jpg', 'failed', 'File rotto'),
        $foto('d.jpg', 'ready'),
        $foto('spot.mp4', 'pending'),
    ], $root);

    return count($avvisi) === 2
        && $avvisi[0]['level'] === 'info' && str_contains($avvisi[0]['message'], '2 foto sono in elaborazione')
        && $avvisi[1]['level'] === 'warning' && str_contains($avvisi[1]['message'], 'c.jpg (File rotto)')
        && ProductImages::notices([$foto('d.jpg', 'ready')], $root) === []
        && str_contains(ProductImages::notices([$foto('a.jpg', 'pending')], $root)[0]['message'], 'Una foto è in elaborazione');
});

check('in vetrina una foto si mostra anche in coda; restano fuori le fallite di prima e i video', fn () =>
    ProductImages::isShowable($foto('a.jpg', 'pending'))
    && ProductImages::isShowable($foto('a.jpg', 'ready'))
    && !ProductImages::isShowable($foto('a.jpg', 'failed'))
    && !ProductImages::isShowable($foto('spot.mp4', 'ready'))
);

ResizeQueue::useStore(null);
ResizeQueue::useRoot(null);

summary();
