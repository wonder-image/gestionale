<?php
/** php tests/StatusLogTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\System\StatusLog;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Sql\TableSchema as Column;

final class ProvaStatusLog extends StatusLog
{
    public static string $table = 'gst_test_status_logs';
    public static string $folder = 'gestionale/test';

    public static function entityColumn(): string
    {
        return 'test_id';
    }

    public static function entityTable(): string
    {
        return 'gst_features';
    }
}

check('le colonne comuni sono quelle dei log degli stati', function () {
    $nomi = array_map(
        static fn (Column $column): string => (string) $column->name,
        StatusLog::commonColumns()
    );

    return $nomi === ['field', 'from_value', 'to_value', 'source', 'user_id', 'message', 'response'];
});

check('lo schema della tabella parte dalla colonna dell\'entità', function () {
    $nomi = array_map(
        static fn (Column $column): string => (string) $column->name,
        ProvaStatusLog::tableSchema()
    );

    return $nomi[0] === 'test_id' && in_array('response', $nomi, true);
});

check('il log non si sincronizza mai', fn () => ProvaStatusLog::syncSchema() === null);

check('le origini ammesse sono quelle di 4.1', fn () =>
    StatusLogger::SOURCES === ['user', 'system', 'cron', 'webhook', 'api']
);

check('un\'origine sconosciuta diventa system', fn () =>
    StatusLogger::normalizeSource('webhook') === 'webhook'
    && StatusLogger::normalizeSource('USER') === 'user'
    && StatusLogger::normalizeSource('marziano') === 'system'
    && StatusLogger::normalizeSource('') === 'system'
);

summary();
