<?php
/** php tests/DocsPagesTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Resources\System\MerchantSettingResource;

/**
 * I percorsi che GitBook pubblica per una guida: `gruppo/nome-del-file`, dove
 * il gruppo è il titolo `##` del SUMMARY reso a trattini e il nome è quello
 * del file senza `.md`.
 *
 * @return list<string>
 */
$pagine = static function (string $space): array {
    $summary = dirname(__DIR__).'/docs/'.$space.'/SUMMARY.md';

    if (!is_file($summary)) {
        return [];
    }

    $gruppo = '';
    $percorsi = [];

    foreach (file($summary, FILE_IGNORE_NEW_LINES) ?: [] as $riga) {
        if (preg_match('/^##\s+(.+)$/', trim($riga), $titolo) === 1) {
            $gruppo = strtolower(trim($titolo[1]));
            $gruppo = trim((string) preg_replace('/[^a-z0-9]+/', '-', $gruppo), '-');
            continue;
        }

        if (preg_match('/^\*\s+\[[^\]]*\]\(([^)]+)\)/', trim($riga), $link) !== 1) {
            continue;
        }

        $file = basename(trim($link[1]), '.md');

        // README è la copertina dello spazio, senza gruppo.
        if (strtolower($file) === 'readme' || $gruppo === '') {
            continue;
        }

        $percorsi[] = $gruppo.'/'.$file;
    }

    return $percorsi;
};

/** @return list<class-string<GestionaleResource>> */
$resources = static function (): array {
    $classi = [];

    foreach (glob(dirname(__DIR__).'/src/Resources/*/*.php') ?: [] as $file) {
        $classe = 'Wonder\\Plugin\\Gestionale\\Resources\\'
            .basename(dirname($file)).'\\'.basename($file, '.php');

        if (class_exists($classe) && is_subclass_of($classe, GestionaleResource::class)) {
            $classi[] = $classe;
        }
    }

    return $classi;
};

check('il SUMMARY si legge come lo legge GitBook', function () use ($pagine) {
    $user = $pagine('user');

    return in_array('catalogo/catalogo-tassonomie', $user, true)
        && in_array('catalogo/catalogo-attributi', $user, true)
        && in_array('ogni-giorno/sedi', $user, true)
        && in_array('primi-passi/accedere', $user, true);
});

check('ogni pulsante "Guida" punta a una pagina che esiste', function () use ($pagine, $resources) {
    // Un link rotto non si vede finché qualcuno non lo clicca: qui si vede
    // subito, senza aprire il browser.
    $disponibili = ['user' => $pagine('user'), 'dev' => $pagine('dev')];
    $rotte = [];

    foreach ($resources() as $classe) {
        $pagina = $classe::$docsPage;

        if ($pagina === '') {
            continue;
        }

        if (!in_array($pagina, $disponibili[$classe::$docsSpace] ?? [], true)) {
            $rotte[] = $classe.' → '.$classe::$docsSpace.':'.$pagina;
        }
    }

    if ($rotte !== []) {
        echo '    '.implode("\n    ", $rotte)."\n";
    }

    return $rotte === [];
});

check('ogni Resource dichiara una guida che esiste davvero', function () use ($resources) {
    foreach ($resources() as $classe) {
        if (!in_array($classe::$docsSpace, ['user', 'dev'], true)) {
            echo '    '.$classe.' → spazio sconosciuto: '.$classe::$docsSpace."\n";

            return false;
        }
    }

    return true;
});

check('le due guide hanno il loro indirizzo di partenza', fn () =>
    Gestionale::docsUrl('catalogo/catalogo-attributi')
        === 'https://wonder-image.gitbook.io/wonder-image-gestionale/user/catalogo/catalogo-attributi'
    && Gestionale::docsUrl('concetti/iva-e-impostazioni', 'dev')
        === 'https://wonder-image.gitbook.io/wonder-image-gestionale/concetti/iva-e-impostazioni'
);

check('anche le impostazioni del commerciante puntano a una guida che esiste', fn () =>
    in_array(MerchantSettingResource::DOCS_PAGE, $pagine('user'), true)
);

summary();
