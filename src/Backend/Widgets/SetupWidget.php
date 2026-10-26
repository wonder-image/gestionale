<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Wonder\App\Credentials;
use Wonder\App\Models\Config\SocietyLocation;
use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Elements\Components\LinkListCard;
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
            Setting::current(),
            Credentials::storedApi()
        ));
    }

    /** @param list<array<string, string>> $pending */
    public static function markup(array $pending): string
    {
        if ($pending === []) {
            return '';
        }

        $card = LinkListCard::make('Primi passi')->icon('bi-flag')->count(false);

        foreach ($pending as $step) {
            $card->link(
                (string) ($step['title'] ?? ''),
                (string) ($step['url'] ?? ''),
                '',
                '',
                (string) ($step['description'] ?? '')
            );
        }

        return WidgetCard::column($card);
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
