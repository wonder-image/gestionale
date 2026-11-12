<?php
/** php tests/integrazione/ProductImageQueueTest.php — le foto in attesa passano alla coda del core */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\App\Media\ResizeQueue;
use Wonder\Plugin\Gestionale\Migrations\ProductImageQueueMigration;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductImage;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\Connection;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

// Questo test scrive immagini vere: la transazione annulla le righe (anche
// quelle di `image_resizes`, scritte sulla stessa connessione), non i file.
// L'istantanea rimette la cartella com'era anche se il test muore a metà
// (vedi `Istantanea` in harness.php). Niente si ridimensiona: la migrazione
// consegna e basta.
Istantanea::di(SITE.'/assets/upload'.ProductImages::folder());

/** Un'immagine vera, piccola, scritta sul disco del sito. */
$scriviFile = static function (string $name): string {
    $dir = SITE.'/assets/upload'.ProductImages::folder();

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $image = imagecreatetruecolor(40, 30);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 200));
    imagejpeg($image, $dir.$name, 80);

    return $dir.$name;
};

$inAttesa = static function (): int {
    $rows = ProductImage::find(['status' => 'pending']);

    return !is_array($rows) || $rows === [] ? 0 : (isset($rows['id']) ? 1 : count(array_filter($rows, 'is_array')));
};

try {
    Transaction::run(static function () use ($scriviFile, $inAttesa): void {
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

        $nuova = static function (string $file, int $posizione) use ($modelId): int {
            $riga = ProductImage::create([
                'product_model_id' => $modelId,
                'file' => json_encode([$file]),
                'alt' => 'Prova',
                'position' => $posizione,
                // Come le scriveva la coda del modulo.
                'status' => 'pending',
                'attempts' => 0,
            ]);

            return (int) ($riga->insert_id ?? 0);
        };

        $foto = 'prova-coda-'.uniqid().'.jpg';
        $percorso = $scriviFile($foto);
        $fotoId = $nuova($foto, 1);
        $sparitaId = $nuova('prova-sparita-'.uniqid().'.jpg', 2);
        $video = 'prova-video-'.uniqid().'.mp4';
        file_put_contents(dirname($percorso).'/'.$video, 'video');
        $videoId = $nuova($video, 3);

        check('la coda del core c\'è', fn () => ResizeQueue::store()->usable());

        check('prima della migrazione la foto risulta in attesa dalla colonna', fn () =>
            ProductImages::state((array) ProductImage::findById($fotoId)) === ProductImages::PENDING
            && ResizeQueue::status($percorso) === null
        );

        $esito = ProductImageQueueMigration::run(Connection::Connect('main'));

        check('la migrazione consegna le foto, chiude i video e segna i file spariti', fn () =>
            ($esito['queued'] ?? 0) >= 1 && ($esito['ready'] ?? 0) >= 1 && ($esito['missing'] ?? 0) >= 1
        );

        check('la foto è nella coda del core e la sua colonna è chiusa', function () use ($fotoId, $percorso) {
            $riga = (array) ProductImage::findById($fotoId);

            return ($riga['status'] ?? '') === 'ready'
                && ResizeQueue::status($percorso) === ResizeQueue::PENDING
                && ProductImages::state($riga) === ProductImages::PENDING
                // Niente si ridimensiona dentro l'update.
                && !is_file(dirname($percorso).'/'.pathinfo($percorso, PATHINFO_FILENAME).'-240.jpg');
        });

        check('il video è pronto senza passare dalla coda', fn () =>
            ((array) ProductImage::findById($videoId))['status'] === 'ready'
            && ResizeQueue::status(dirname($percorso).'/'.$video) === null
        );

        check('il file sparito resta nascosto al sito, con il motivo', function () use ($sparitaId) {
            $riga = (array) ProductImage::findById($sparitaId);

            return ($riga['status'] ?? '') === 'failed'
                && ($riga['error'] ?? '') === ProductImageQueueMigration::MISSING_ERROR
                && !ProductImages::isShowable($riga);
        });

        check('non resta niente in attesa nella vecchia coda, e rilanciarla non fa niente', fn () =>
            $inAttesa() === 0 && ProductImageQueueMigration::run(Connection::Connect('main')) === []
        );

        throw new Annulla();
    });
} catch (Annulla) {
}

summary();
