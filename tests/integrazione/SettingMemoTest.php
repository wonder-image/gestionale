<?php
/** php tests/integrazione/SettingMemoTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\RequestMemo;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    return $esito;
}

$id = (int) (Setting::fresh()['id'] ?? 0);
$originale = (string) (Setting::fresh()['merchant_notification_emails'] ?? '');

check('current: una lettura per richiesta, fresh legge il database', function () use ($originale) {
    RequestMemo::reset();
    Setting::current();

    return prova(static function () use ($originale): bool {
        sqlModify(Setting::$table, ['merchant_notification_emails' => 'memo@example.test'], 'id', '1');

        return (string) (Setting::current()['merchant_notification_emails'] ?? '') === $originale
            && (string) (Setting::fresh()['merchant_notification_emails'] ?? '') === 'memo@example.test';
    }) === true;
});

check('Setting::update in transazione: dentro si vede, dopo il rollback no', function () use ($id, $originale) {
    RequestMemo::reset();
    Setting::current();

    $dentro = prova(static function () use ($id): bool {
        Setting::update(['merchant_notification_emails' => 'memo@example.test'], $id);

        return (string) (Setting::current()['merchant_notification_emails'] ?? '') === 'memo@example.test'
            && (string) (Setting::current()['merchant_notification_emails'] ?? '') === 'memo@example.test';
    });

    return $dentro === true
        && (string) (Setting::current()['merchant_notification_emails'] ?? '') === $originale;
});

check('SettingResource::afterUpdate butta la copia', function () use ($originale) {
    RequestMemo::reset();
    Setting::current();

    return prova(static function (): bool {
        sqlModify(Setting::$table, ['merchant_notification_emails' => 'memo@example.test'], 'id', '1');
        \Wonder\Plugin\Gestionale\Resources\System\SettingResource::afterUpdate(1, (object) ['success' => true], []);

        return (string) (Setting::current()['merchant_notification_emails'] ?? '') === 'memo@example.test';
    }) === true
        && (string) (Setting::current()['merchant_notification_emails'] ?? '') === $originale;
});

summary();
