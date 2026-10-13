<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Throwable;
use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Stock\Batch;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Resources\Stock\BatchResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockMovementResource;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Stock\BatchExpiry;

/**
 * "Lotti in scadenza" (G3.20): i lotti con pezzi già scaduti o che scadono
 * entro `batch_expiry_days` giorni, dalla scadenza più vicina; i primi dieci.
 * Senza lotti da guardare il riquadro non c'è.
 *
 * A «Lotti e scadenze» bloccata il riquadro non esiste.
 */
final class ExpiringBatchesWidget implements HomeWidget
{
    public const LIMIT = 10;

    public function title(): string
    {
        return 'Lotti in scadenza';
    }

    public function authorities(): array
    {
        return ['admin', 'administrator'];
    }

    public function order(): int
    {
        return 29;
    }

    public function render(): string
    {
        if (!Gestionale::feature('batch_tracking')) {
            return '';
        }

        $today = date('Y-m-d');
        $days = BatchExpiry::days();

        try {
            $batches = self::batches(BatchExpiry::plus($today, $days));
        } catch (Throwable $error) {
            Errors::internal($error, 'widget.expiring_batches');

            return '';
        }

        $rows = [];

        foreach (array_slice($batches, 0, self::LIMIT) as $batch) {
            $rows[] = [
                'number' => html_entity_decode((string) $batch['number'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'product' => StockMovementResource::productLabel((int) $batch['product_id']),
                'expiry' => (string) $batch['expiry_date'],
                'pieces' => BatchResource::pieces((float) $batch['pieces']),
                'url' => BatchResource::viewUrl((int) $batch['id']),
            ];
        }

        return self::markup($rows, count($batches), $today, $days, BatchResource::listUrl());
    }

    /**
     * I lotti con pezzi che scadono entro `$limit` (gli scaduti compresi),
     * dalla scadenza più vicina: i lotti per data, poi i loro pezzi.
     *
     * @return list<array<string, mixed>>
     */
    private static function batches(string $limit): array
    {
        $batches = self::rows(Batch::find(
            "`expiry_date` IS NOT NULL AND `expiry_date` <= '".$limit."' AND `deleted` = 'false'",
            null,
            'expiry_date',
            'ASC'
        ));

        if ($batches === []) {
            return [];
        }

        $pieces = [];

        foreach (self::rows(StockRow::find('`batch_id` IN ('.implode(', ', array_map(static fn (array $b): int => (int) $b['id'], $batches)).") AND `deleted` = 'false'")) as $row) {
            $pieces[(int) $row['batch_id']] = ($pieces[(int) $row['batch_id']] ?? 0.0) + (float) $row['quantity'];
        }

        $out = [];

        foreach ($batches as $batch) {
            $quantity = round($pieces[(int) $batch['id']] ?? 0.0, 3);

            if ($quantity > 0) {
                $out[] = $batch + ['pieces' => $quantity];
            }
        }

        return $out;
    }

    /**
     * @param list<array{number: string, product: string, expiry: string, pieces: string, url: string}> $rows
     */
    public static function markup(array $rows, int $count, string $today, int $days, string $listUrl): string
    {
        if ($rows === []) {
            return '';
        }

        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $items = '';

        foreach ($rows as $row) {
            $items .= '<li class="d-flex justify-content-between gap-2 py-1 border-bottom">'
                .'<span class="min-w-0"><a href="'.$e($row['url']).'">'.$e($row['number']).'</a>'
                .' <span class="small text-body-secondary">'.$e($row['product']).'</span></span>'
                .'<span class="text-nowrap small">'.$e($row['pieces']).' pz · '.BatchExpiry::badge($row['expiry'], $today, $days).'</span>'
                .'</li>';
        }

        $more = $count > count($rows) ? ' e altri '.($count - count($rows)) : '';

        return '<wi-card class="col-12 col-md-6">'
            .'<h5 class="mb-1"><i class="bi bi-hourglass-split"></i> Lotti in scadenza</h5>'
            .'<p class="text-body-secondary small mb-2">Con pezzi, scaduti o entro '.$days.' '.($days === 1 ? 'giorno' : 'giorni').'.</p>'
            .'<ul class="list-unstyled mb-2">'.$items.'</ul>'
            .'<a class="small" href="'.$e($listUrl).'">Apri i Lotti'.$e($more).'</a>'
            .'</wi-card>';
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
