<?php
/** php tests/integrazione/ImageQueueTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Support\Catalog\ImageQueue;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$prima = ImageQueue::count();

/** Un'immagine vera, piccola, scritta sul disco del sito. */
$scriviFile = static function (string $name): string {
    $dir = SITE.'/assets/upload'.ProductImages::DIR;

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $image = imagecreatetruecolor(40, 30);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 200));
    imagejpeg($image, $dir.$name, 80);

    return $dir.$name;
};

try {
    Transaction::run(static function () use ($scriviFile): void {
        $modello = ProductModel::create([
            'code' => Code::make(ProductModel::class, Codes::MODEL),
            'name' => 'Prova immagini',
            'slug' => Slug::make('prova-immagini-'.uniqid()),
            'unit' => 'pz',
            'type' => 'simple',
            'visible' => 'true',
            'visible_online' => 'true',
            'position' => 1,
        ]);
        $modelId = (int) ($modello->insert_id ?? 0);

        $file = 'prova-coda-'.uniqid().'.jpg';
        $percorso = $scriviFile($file);

        $riga = ProductImage::create([
            'product_model_id' => $modelId,
            'file' => json_encode([$file]),
            'alt' => 'Prova',
            'position' => 1,
            'status' => 'pending',
            'attempts' => 0,
        ]);
        $imageId = (int) ($riga->insert_id ?? 0);

        check('la riga nasce in attesa', function () use ($imageId) {
            $row = ProductImage::find(['id' => $imageId], 1);

            return ($row['status'] ?? '') === 'pending';
        });

        check('la coda la vede', fn () =>
            in_array($imageId, array_map('intval', array_column(ImageQueue::pending(), 'id')), true)
        );

        $esito = ImageQueue::work(5);

        check('lavorarla la porta a pronta', function () use ($imageId, $esito) {
            $row = ProductImage::find(['id' => $imageId], 1);

            return ($row['status'] ?? '') === 'ready'
                && ($row['processed_at'] ?? '') !== ''
                && $esito['done'] >= 1;
        });

        check('le misure sono state generate davvero', function () use ($percorso) {
            // `imageResize()` scrive i file accanto all'originale.
            $generati = glob(dirname($percorso).'/'.pathinfo($percorso, PATHINFO_FILENAME).'*');

            return is_array($generati) && count($generati) > 1;
        });

        // Una riga che punta a un file che non c'è: tre tentativi e si arrende.
        $rotta = ProductImage::create([
            'product_model_id' => $modelId,
            'file' => json_encode(['questa-non-esiste.jpg']),
            'alt' => '',
            'position' => 2,
            'status' => 'pending',
            'attempts' => 0,
        ]);
        $rottaId = (int) ($rotta->insert_id ?? 0);

        check('un file che non c\'è non si arrende al primo colpo', function () use ($rottaId) {
            ImageQueue::work(5);
            $row = ProductImage::find(['id' => $rottaId], 1);

            return ($row['status'] ?? '') === 'pending'
                && (int) ($row['attempts'] ?? 0) === 1
                && str_contains((string) ($row['error'] ?? ''), 'non c\'è più');
        });

        check('al terzo tentativo resta ferma', function () use ($rottaId) {
            ImageQueue::work(5);
            ImageQueue::work(5);
            $row = ProductImage::find(['id' => $rottaId], 1);

            return ($row['status'] ?? '') === 'failed' && (int) ($row['attempts'] ?? 0) === 3;
        });

        check('una coda vuota non fa niente', function () {
            $esito = ImageQueue::work(5);

            return $esito['done'] === 0 && $esito['failed'] === 0;
        });

        // I file generati restano fuori dalla transazione: si tolgono a mano.
        foreach (glob(dirname($percorso).'/'.pathinfo($percorso, PATHINFO_FILENAME).'*') ?: [] as $generato) {
            @unlink($generato);
        }

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento la coda è come prima', fn () => ImageQueue::count() === $prima);

summary();
