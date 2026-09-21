<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Wonder\App\LegacyGlobals;
use Wonder\App\Support\Errors\ErrorReporter;
use Wonder\Backend\Contracts\HomeWidget;

/**
 * "Da controllare": gli errori ancora aperti.
 *
 * Il commerciante vede solo quelli scritti per lui; chi installa li vede
 * tutti. Da G4 si aggiungono i documenti rimasti in errore.
 */
final class AttentionWidget implements HomeWidget
{
    public function title(): string
    {
        return 'Da controllare';
    }

    public function authorities(): array
    {
        return ['admin', 'administrator'];
    }

    public function order(): int
    {
        return 20;
    }

    public function render(): string
    {
        return self::markup(ErrorReporter::open(self::audience()));
    }

    /** @param list<array<string, mixed>> $errors */
    public static function markup(array $errors): string
    {
        if ($errors === []) {
            return <<<HTML
<wi-card class="col-12">
    <h5 class="mb-1"><i class="bi bi-check-circle"></i> Da controllare</h5>
    <p class="text-body-secondary small mb-0">Non c'è niente da controllare.</p>
</wi-card>
HTML;
        }

        $items = '';

        foreach ($errors as $error) {
            $service = htmlspecialchars((string) ($error['service'] ?? ''), ENT_QUOTES, 'UTF-8');
            $action = htmlspecialchars((string) ($error['action'] ?? ''), ENT_QUOTES, 'UTF-8');
            $message = htmlspecialchars((string) ($error['message'] ?? ''), ENT_QUOTES, 'UTF-8');
            $occurrences = (int) ($error['occurrences'] ?? 0);
            $last = htmlspecialchars((string) ($error['last_seen_at'] ?? ''), ENT_QUOTES, 'UTF-8');
            $id = (int) ($error['id'] ?? 0);

            $items .= <<<HTML
<li class="list-group-item bg-transparent d-flex justify-content-between align-items-center gap-3">
    <span>
        <strong>{$service}</strong> — {$action}
        <span class="d-block text-body-secondary small">{$message}</span>
        <span class="d-block text-body-secondary small">{$occurrences} volte, ultima {$last}</span>
    </span>
    <a class="btn btn-info btn-sm text-nowrap" href="/backend/app/config/errori/{$id}/edit/">Apri</a>
</li>
HTML;
        }

        return <<<HTML
<wi-card class="col-12">
    <h5 class="mb-1"><i class="bi bi-exclamation-triangle"></i> Da controllare</h5>
    <ul class="list-group list-group-flush">{$items}</ul>
</wi-card>
HTML;
    }

    /** Il commerciante vede i suoi, chi installa tutti. */
    private static function audience(): ?string
    {
        $user = LegacyGlobals::get('USER');
        $authority = is_object($user) && isset($user->authority) && is_array($user->authority)
            ? $user->authority
            : [];

        return in_array('admin', $authority, true) ? null : 'merchant';
    }
}
