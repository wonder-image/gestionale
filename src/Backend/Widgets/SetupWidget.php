<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Wonder\App\Models\Config\SocietyLocation;
use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Setup\SetupChecks;

/**
 * "Primi passi": cosa manca perché il gestionale sia pronto.
 *
 * Sparisce da solo quando non resta niente da fare: una lista di cose già
 * fatte non serve a nessuno.
 */
final class SetupWidget implements HomeWidget
{
    public function title(): string
    {
        return 'Primi passi';
    }

    public function authorities(): array
    {
        return ['admin'];
    }

    public function order(): int
    {
        return 10;
    }

    public function render(): string
    {
        return self::markup(SetupChecks::pending(
            self::society(),
            self::location(),
            Setting::current()
        ));
    }

    /** @param list<array<string, string>> $pending */
    public static function markup(array $pending): string
    {
        if ($pending === []) {
            return '';
        }

        $items = '';

        foreach ($pending as $step) {
            $title = htmlspecialchars((string) ($step['title'] ?? ''), ENT_QUOTES, 'UTF-8');
            $description = htmlspecialchars((string) ($step['description'] ?? ''), ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars((string) ($step['url'] ?? ''), ENT_QUOTES, 'UTF-8');

            $items .= <<<HTML
<li class="list-group-item bg-transparent d-flex justify-content-between align-items-center gap-3">
    <span>
        <strong>{$title}</strong>
        <span class="d-block text-body-secondary small">{$description}</span>
    </span>
    <a class="btn btn-info btn-sm text-nowrap" href="{$url}">Vai</a>
</li>
HTML;
        }

        return <<<HTML
<wi-card class="col-12">
    <h5 class="mb-1"><i class="bi bi-flag"></i> Primi passi</h5>
    <p class="text-body-secondary small">Manca ancora qualcosa prima di partire.</p>
    <ul class="list-group list-group-flush">{$items}</ul>
</wi-card>
HTML;
    }

    /** @return array<string, mixed> */
    private static function society(): array
    {
        $society = $GLOBALS['SOCIETY'] ?? null;

        return is_object($society) ? (array) $society : [];
    }

    /** @return array<string, mixed> */
    private static function location(): array
    {
        $location = SocietyLocation::find(['is_default' => 'true', 'deleted' => 'false'], 1);

        return is_array($location) ? $location : [];
    }
}
