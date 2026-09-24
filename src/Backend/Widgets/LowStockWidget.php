<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Throwable;
use Wonder\App\LegacyGlobals;
use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\Plugin\Gestionale\Resources\Stock\StockLevelResource;
use Wonder\Plugin\Gestionale\Resources\System\MerchantSettingResource;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Mail\Recipients;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockEmail;
use Wonder\Plugin\Gestionale\Support\Stock\LowStockReport;

/**
 * "Sotto scorta": i prodotti da riordinare.
 *
 * Gli stessi dell'email, letti adesso: un prodotto tornato sopra la soglia
 * sparisce subito, anche se il suo avviso si chiude solo al prossimo giro
 * dell'attività. Dieci righe al massimo; il resto si conta e si apre nelle
 * Giacenze filtrate.
 *
 * A funzionalità bloccata il riquadro non esiste.
 */
final class LowStockWidget implements HomeWidget
{
    /** Le righe che si vedono prima di "e altri N". */
    public const LIMIT = 10;

    public function title(): string
    {
        return 'Sotto scorta';
    }

    public function authorities(): array
    {
        return ['admin', 'administrator'];
    }

    public function order(): int
    {
        return 25;
    }

    public function render(): string
    {
        if (!Gestionale::feature('low_stock_alerts')) {
            return '';
        }

        try {
            $alerts = LowStockReport::open();
            $items = LowStockReport::items($alerts, LowStockReport::products($alerts));
            $recipients = Recipients::parse((string) (MerchantSetting::current()['low_stock_emails'] ?? ''))['valid'];
        } catch (Throwable $error) {
            // La home si apre lo stesso: il guasto va nel log, non in pagina.
            Errors::internal($error, 'widget.low_stock');

            return '';
        }

        return self::markup($items, $recipients !== [], self::canEditRecipients());
    }

    /**
     * @param list<array{product_id: int, article: string, option: string, sku: string, threshold: float, available: float}> $items
     * @param bool $canEditRecipients se chi guarda può aprire le Impostazioni: solo allora l'avviso porta il link
     */
    public static function markup(array $items, bool $hasRecipients, bool $canEditRecipients = true): string
    {
        $warning = $hasRecipients ? '' : self::noRecipients($canEditRecipients);

        if ($items === []) {
            return <<<HTML
<wi-card class="col-12">
    <h5 class="mb-1"><i class="bi bi-box-seam"></i> Sotto scorta</h5>
    <p class="text-body-secondary small mb-0">Nessun prodotto sotto la scorta minima.</p>
    {$warning}
</wi-card>
HTML;
        }

        $rows = '';

        foreach (array_slice($items, 0, self::LIMIT) as $item) {
            $name = htmlspecialchars(
                $item['option'] !== '' ? $item['article'].' — '.$item['option'] : $item['article'],
                ENT_QUOTES,
                'UTF-8'
            );
            $sku = htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8');
            $available = LowStockEmail::quantity((float) $item['available']);
            $threshold = LowStockEmail::quantity((float) $item['threshold']);

            $rows .= <<<HTML
<li class="list-group-item bg-transparent d-flex justify-content-between align-items-center gap-3">
    <span>
        <strong>{$name}</strong>
        <span class="d-block text-body-secondary small">{$sku}</span>
    </span>
    <span class="small text-nowrap">Disponibili {$available} · scorta minima {$threshold}</span>
</li>
HTML;
        }

        $others = count($items) - self::LIMIT;
        $more = match (true) {
            $others === 1 => '<p class="text-body-secondary small mt-2 mb-0">e un altro</p>',
            $others > 1 => '<p class="text-body-secondary small mt-2 mb-0">e altri '.$others.'</p>',
            default => '',
        };
        $url = htmlspecialchars(StockLevelResource::lowStockUrl(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<wi-card class="col-12">
    <h5 class="mb-1 d-flex justify-content-between align-items-center gap-3">
        <span><i class="bi bi-box-seam"></i> Sotto scorta</span>
        <a class="btn btn-sm btn-secondary" href="{$url}">Apri le giacenze</a>
    </h5>
    <ul class="list-group list-group-flush">{$rows}</ul>
    {$more}
    {$warning}
</wi-card>
HTML;
    }

    /**
     * L'attività tace se nessuno riceve l'email: qui è l'unico posto dove si vede.
     * Il link solo a chi può aprire la pagina; agli altri, dove li aggiunge il commerciante.
     */
    private static function noRecipients(bool $canEditRecipients): string
    {
        if (!$canEditRecipients) {
            return <<<HTML
<p class="small mt-2 mb-0"><i class="bi bi-envelope-exclamation"></i> Nessuno riceve l'email degli avvisi: il commerciante li aggiunge in Gestionale → Impostazioni.</p>
HTML;
        }

        $url = htmlspecialchars('/backend/'.MerchantSettingResource::path(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<p class="small mt-2 mb-0"><i class="bi bi-envelope-exclamation"></i> Nessuno riceve l'email degli avvisi. <a href="{$url}">Aggiungi i destinatari</a></p>
HTML;
    }

    /**
     * Vero se chi guarda ha un'autorità ammessa dalle Impostazioni. Il riquadro
     * lo vede anche chi installa (`admin`), ma la pagina è del commerciante:
     * per chi installa il link finirebbe sul Login.
     */
    private static function canEditRecipients(): bool
    {
        $user = LegacyGlobals::get('USER');
        $authority = is_object($user) && isset($user->authority) && is_array($user->authority)
            ? $user->authority
            : [];
        $allowed = (array) (MerchantSettingResource::permissionSchema()->get('backend')['edit'] ?? []);

        return array_intersect($authority, $allowed) !== [];
    }
}
