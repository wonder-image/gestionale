<?php

/**
 * Pagina "Funzionalità": un solo form con un interruttore per funzionalità.
 * Form e layout stanno nella Resource; qui restano il salvataggio e il
 * rendering. In produzione la pagina è in sola lettura, perché la tabella
 * arriva dal deploy.
 */

use Wonder\App\Environment;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\System\FeatureResource;
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
        // L'interruttore staccato manda comunque il valore "false" (campo nascosto).
        $requested[$key] = ($_POST[$key] ?? 'false') === 'true';
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
    'TITLE' => FeatureResource::titleLabel(),
    'SUBTITLE' => 'Il gestionale è predisposto al massimo: qui si sblocca solo ciò che serve. Bloccare non cancella mai i dati.',
    // Lo schema si rilegge dopo il salvataggio, così gli interruttori mostrano lo stato nuovo.
    'FORM_LAYOUT' => FeatureResource::formLayoutSchema(),
    'DOCS_URL' => FeatureResource::pageSchema()->docsUrl('list'),
    'READONLY' => $readonly,
    'READONLY_NOTICE' => FeatureResource::readonlyNotice(),
    'MESSAGE' => $message,
])->render();
