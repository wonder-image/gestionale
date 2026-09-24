<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Throwable;
use Wonder\App\LegacyGlobals;
use Wonder\App\Support\Errors\ErrorReporter;
use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockEmail;
use Wonder\Plugin\Gestionale\Support\Stock\NegativeStock;

/**
 * "Da controllare": le cose ferme che qualcuno deve guardare.
 *
 * Le giacenze sotto zero sono dati del negozio: le vedono tutti e due i ruoli,
 * ciascuna con il pulsante per rettificarla. Gli errori tecnici di
 * `error_reports` li vede solo chi installa: per il commerciante sono
 * notifiche, non errori, e arriveranno dal sotto-progetto che le genera (un
 * ordine fermo, una spedizione senza tracking).
 */
final class AttentionWidget implements HomeWidget
{
    /** Le giacenze negative che si vedono prima di "e altre N". */
    public const LIMIT = 10;

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
        return self::markup(
            self::isDeveloper() ? ErrorReporter::open() : [],
            self::negatives()
        );
    }

    /**
     * @param list<array<string, mixed>> $errors
     * @param list<array{product_id: int, article: string, option: string, sku: string, quantity: float, locations: int}> $negatives
     */
    public static function markup(array $errors, array $negatives = []): string
    {
        if ($errors === [] && $negatives === []) {
            return <<<HTML
<wi-card class="col-12">
    <h5 class="mb-1"><i class="bi bi-check-circle"></i> Da controllare</h5>
    <p class="text-body-secondary small mb-0">Non c'è niente da controllare.</p>
</wi-card>
HTML;
        }

        $items = '';

        foreach (array_slice($negatives, 0, self::LIMIT) as $negative) {
            $items .= self::negativeLine($negative);
        }

        $others = count($negatives) - self::LIMIT;

        if ($others > 0) {
            $items .= '<li class="list-group-item bg-transparent text-body-secondary small">'
                .($others === 1 ? 'e un\'altra giacenza sotto zero' : 'e altre '.$others.' giacenze sotto zero')
                .'</li>';
        }

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

    /** @param array{product_id: int, article: string, option: string, sku: string, quantity: float, locations: int} $negative */
    private static function negativeLine(array $negative): string
    {
        $name = htmlspecialchars(
            $negative['option'] !== '' ? $negative['article'].' — '.$negative['option'] : $negative['article'],
            ENT_QUOTES,
            'UTF-8'
        );
        $sku = htmlspecialchars($negative['sku'], ENT_QUOTES, 'UTF-8');
        $quantity = LowStockEmail::quantity((float) $negative['quantity']);
        // Con *Più sedi* bloccata le sedi sono una: la parola non compare.
        $where = $negative['locations'] > 1 ? ' in '.$negative['locations'].' sedi' : '';
        // Dopo il salvataggio si torna qui, alla home (`backend.home`).
        $url = htmlspecialchars(StockAdjustmentResource::urlFor($negative['product_id'], '/backend/'), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<li class="list-group-item bg-transparent d-flex justify-content-between align-items-center gap-3">
    <span>
        <strong>{$name}</strong>
        <span class="d-block text-body-secondary small">{$sku} · Giacenza {$quantity}{$where}</span>
    </span>
    <a class="btn btn-info btn-sm text-nowrap" href="{$url}">Rettifica</a>
</li>
HTML;
    }

    /** Le giacenze negative; nessuna se il database non risponde. */
    private static function negatives(): array
    {
        try {
            return NegativeStock::items();
        } catch (Throwable) {
            return [];
        }
    }

    /** Vero per chi installa e assiste il sito. */
    private static function isDeveloper(): bool
    {
        $user = LegacyGlobals::get('USER');
        $authority = is_object($user) && isset($user->authority) && is_array($user->authority)
            ? $user->authority
            : [];

        return in_array('admin', $authority, true);
    }
}
