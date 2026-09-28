<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Stock\StockAlert;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\Plugin\Gestionale\Resources\Stock\StockLevelResource;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Mail\Recipients;

/**
 * Il giro degli avvisi di scorta minima: una email sola per tutti i prodotti
 * scesi sotto la soglia dall'ultimo giro.
 *
 * Lo fa girare l'attività `gestionale.stock_alerts` dello scheduler, mai il
 * salvataggio: dieci rettifiche di fila fanno un'email, e il magazzino non
 * aspetta il server di posta.
 *
 * Ogni avviso si manda una volta: un avviso è di un prodotto in una sede, e
 * la stessa sede non torna nell'email finché non risale e ricade. Se la
 * posta non parte si riprova al giro dopo; se il sito ferma l'email con l'hook, è una scelta sua e l'avviso si
 * considera mandato.
 */
final class LowStockNotifier
{
    /** La chiave che l'hook `beforeEmailSend` riceve per questa email. */
    public const KEY = 'stock.low_stock';

    public const DISABLED = 'disabled';
    public const NOTHING = 'nothing';
    public const NO_RECIPIENTS = 'no_recipients';
    public const PREVIEW = 'preview';

    /** Sempre lo stesso: il registro del core conta le ripetizioni sulla stessa riga. */
    private const FAILURE = 'L\'email degli avvisi di scorta minima non è partita.';

    /** @var (callable(string, array): void)|null */
    private static $reporter = null;

    /**
     * @return array{status: string, items: list<array>, resolved: int, closed: int, to: list<string>, sent: list<string>, failed: list<string>, subject: string}
     */
    public static function run(bool $preview = false): array
    {
        $result = [
            'status' => self::NOTHING,
            'items' => [],
            'resolved' => 0,
            'closed' => 0,
            'to' => [],
            'sent' => [],
            'failed' => [],
            'subject' => '',
        ];

        if (!Gestionale::feature('low_stock_alerts')) {
            return ['status' => self::DISABLED] + $result;
        }

        $open = LowStockReport::open();

        if ($open === []) {
            return $result;
        }

        $products = LowStockReport::products($open);
        $orphans = LowStockReport::orphans($open, $products);
        $current = LowStockReport::items($open, $products);
        $low = [];

        foreach ($current as $item) {
            $low[self::key($item)] = true;
        }

        /** @var array<string, list<int>> $pending avvisi mai mandati, per prodotto e sede */
        $pending = [];
        /** @var array<int, true> $stale prodotti con un avviso aperto che non è più vero */
        $stale = [];

        foreach ($open as $alert) {
            $alertId = (int) $alert['id'];
            $productId = (int) ($alert['product_id'] ?? 0);
            $key = self::key($alert);

            if (in_array($alertId, $orphans, true)) {
                continue;
            }

            if (!isset($low[$key])) {
                $stale[$productId] = true;

                continue;
            }

            if (trim((string) ($alert['notified_at'] ?? '')) === '') {
                $pending[$key][] = $alertId;
            }
        }

        $result['resolved'] = count($orphans);
        $result['closed'] = count($stale);

        if (!$preview) {
            $now = date('Y-m-d H:i:s');

            foreach ($orphans as $alertId) {
                StockAlert::update(['resolved_at' => $now], $alertId);
            }

            foreach (array_keys($stale) as $productId) {
                Alerts::refresh($productId);
            }
        }

        $items = array_values(array_filter(
            $current,
            static fn (array $item): bool => isset($pending[self::key($item)])
        ));

        if ($items === []) {
            return $result;
        }

        $result['items'] = $items;
        $result['to'] = Recipients::parse((string) (MerchantSetting::current()['low_stock_emails'] ?? ''))['valid'];

        if ($result['to'] === []) {
            return ['status' => self::NO_RECIPIENTS] + $result;
        }

        $email = LowStockEmail::compose($items, LowStockEmail::absoluteUrl(StockLevelResource::lowStockUrl()));
        $result['subject'] = $email['subject'];

        if ($preview) {
            return ['status' => self::PREVIEW] + $result;
        }

        $sent = Mailer::send(self::KEY, $result['to'], $email['subject'], $email['body']);
        $result = array_merge($result, [
            'status' => $sent['status'],
            'to' => $sent['to'],
            'sent' => $sent['sent'],
            'failed' => $sent['failed'],
        ]);

        if ($sent['status'] !== Mailer::FAILED) {
            $now = date('Y-m-d H:i:s');

            foreach ($items as $item) {
                foreach ($pending[self::key($item)] as $alertId) {
                    StockAlert::update(['notified_at' => $now], $alertId);
                }
            }
        }

        if ($sent['failed'] !== []) {
            (self::$reporter ?? self::defaultReporter())(self::FAILURE, [
                'to' => $sent['failed'],
                'products' => count($items),
                'retry' => $sent['status'] === Mailer::FAILED,
            ]);
        }

        return $result;
    }

    /** La chiave di un avviso o di una riga: prodotto e sede. @param array<string, mixed> $row */
    private static function key(array $row): string
    {
        return (int) ($row['product_id'] ?? 0).':'.(int) ($row['location_id'] ?? 0);
    }

    /** Cambia chi riceve i guasti; `null` torna al registro del core. Serve ai test. */
    public static function reportUsing(?callable $reporter): void
    {
        self::$reporter = $reporter;
    }

    /** @return callable(string, array): void */
    private static function defaultReporter(): callable
    {
        return static function (string $message, array $context): void {
            Errors::report('gestionale', self::KEY, $message, $context);
        };
    }
}
