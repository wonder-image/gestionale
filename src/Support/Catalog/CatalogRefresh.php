<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Throwable;
use Wonder\App\Scheduler\Repository;
use Wonder\Plugin\Gestionale\Support\Catalog\Exports\ProductFeeds;

/**
 * Feed e sitemap rifatti al prossimo giro dello scheduler, quando gli
 * indirizzi di un articolo online cambiano (#85). Come «Esegui ora»: si
 * chiede soltanto, il lavoro non parte nella richiesta web.
 */
final class CatalogRefresh
{
    /** Le attività da chiedere: i feed del gestionale e la sitemap del core. */
    public const TASKS = ['gestionale.product_feeds', 'wonder.sitemap'];

    /** @return list<string> le attività chieste */
    public static function request(): array
    {
        ProductFeeds::expire();
        $requested = [];

        try {
            $scheduler = new Repository();

            foreach (self::TASKS as $key) {
                $rows = $scheduler->rows("SELECT id FROM scheduler_schedules WHERE task_key = ? AND enabled = 'true'", [$key]);

                foreach ($rows as $row) {
                    $scheduler->request((int) $row['id']);
                    $requested[] = $key;
                }
            }
        } catch (Throwable) {
            // Senza scheduler i feed scaduti si rifanno comunque al giro dopo.
        }

        return $requested;
    }
}
