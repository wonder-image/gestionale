<?php

/**
 * Pagina "Funzionalità": un solo form con un interruttore per funzionalità.
 * In produzione è in sola lettura, perché la tabella arriva dal deploy.
 */

use Wonder\App\Environment;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;
use Wonder\Plugin\Gestionale\Support\Features\FeaturePanel;

$readonly = !Environment::isLocal();
$message = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    if ($readonly) {
        http_response_code(403);
        exit('Le funzionalità si modificano in locale e si pubblicano con il deploy.');
    }

    $requested = [];

    foreach (array_keys(FeatureCatalog::all()) as $key) {
        // Una casella non spuntata non viene inviata: qui vale "bloccata".
        $requested[$key] = isset($_POST['features'][$key]);
    }

    $user = $USER ?? null;
    $changes = FeaturePanel::save($requested, is_object($user) ? (int) ($user->id ?? 0) : 0);

    $message = match (true) {
        $changes['unlocked'] !== [] && $changes['locked'] !== [] => 'Sbloccate: '.implode(', ', $changes['unlocked'])
            .'. Bloccate: '.implode(', ', $changes['locked']).'.',
        $changes['unlocked'] !== [] => 'Sbloccate: '.implode(', ', $changes['unlocked']).'.',
        $changes['locked'] !== [] => 'Bloccate: '.implode(', ', $changes['locked']).'.',
        default => 'Nessun cambiamento.',
    };
}

\Wonder\View\View::make(Gestionale::viewPath('backend/features.php'), [
    'TITLE' => 'Funzionalità',
    'AREAS' => FeaturePanel::byArea(),
    'READONLY' => $readonly,
    'MESSAGE' => $message,
    'DOCS_URL' => Gestionale::docsUrl('funzionalita'),
])->render();
