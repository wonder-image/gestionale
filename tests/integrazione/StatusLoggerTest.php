<?php
/** php tests/integrazione/StatusLoggerTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Extensions\Extensions;
use Wonder\Plugin\Gestionale\Extensions\GestionaleExtension;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Models\System\StatusLog;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Sql\Connection;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** Estensione finta: prova che l'hook del sito viene chiamato. */
final class EstensioneDiProva extends GestionaleExtension
{
    public static array $cambi = [];

    public function onStatusChanged(string $entity, int $entityId, string $field, string $from, string $to): void
    {
        self::$cambi[] = [$entity, $entityId, $field, $from, $to];
    }
}

/** Log finto: le tabelle vere nascono con i loro sotto-progetti. */
final class ProvaStatusLog extends StatusLog
{
    public static string $table = 'gst_test_status_logs';
    public static string $folder = 'gestionale/test';

    public static function entityColumn(): string
    {
        return 'feature_id';
    }

    public static function entityTable(): string
    {
        return Feature::$table;
    }
}

// La tabella si crea fuori dalla transazione: in MySQL una DDL chiude da sola
// la transazione aperta, e le righe scritte prima resterebbero.
sqlTable(ProvaStatusLog::$table, ProvaStatusLog::rawTableSchema());

$feature = Feature::find(['deleted' => 'false'], 1);
$featureId = (int) ($feature['id'] ?? 0);

try {
    Transaction::run(static function () use ($featureId): void {
        check('la riga di log si scrive', fn () =>
            StatusLogger::record(
                ProvaStatusLog::class,
                $featureId,
                'status',
                'draft',
                'confirmed',
                'webhook',
                7,
                'sent_to_sdi',
                ['id' => 'abc', 'esito' => 'ok']
            ) === true
        );

        $riga = ProvaStatusLog::find(['feature_id' => $featureId], 1, 'id', 'DESC');

        check('la riga tiene tutti i dati del cambio', fn () =>
            ($riga['field'] ?? '') === 'status'
            && ($riga['from_value'] ?? '') === 'draft'
            && ($riga['to_value'] ?? '') === 'confirmed'
            && ($riga['source'] ?? '') === 'webhook'
            && (int) ($riga['user_id'] ?? 0) === 7
            && ($riga['message'] ?? '') === 'sent_to_sdi'
        );

        check('la risposta esterna resta in JSON', function () use ($riga) {
            $risposta = json_decode((string) ($riga['response'] ?? ''), true);

            return is_array($risposta) && ($risposta['esito'] ?? '') === 'ok';
        });

        check('il sito viene avvisato del cambio di stato', function () use ($featureId) {
            Extensions::use([EstensioneDiProva::class]);
            EstensioneDiProva::$cambi = [];

            StatusLogger::record(ProvaStatusLog::class, $featureId, 'status', 'a', 'b');

            Extensions::use(null);

            return EstensioneDiProva::$cambi === [[Feature::$table, $featureId, 'status', 'a', 'b']];
        });

        check('un\'origine sconosciuta finisce come system', function () use ($featureId) {
            StatusLogger::record(ProvaStatusLog::class, $featureId, 'status', 'a', 'b', 'marziano');
            $ultima = ProvaStatusLog::find(['feature_id' => $featureId], 1, 'id', 'DESC');

            return ($ultima['source'] ?? '') === 'system';
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento il log è vuoto', function () use ($featureId) {
    $righe = ProvaStatusLog::find(['feature_id' => $featureId]);

    return $righe === [] || $righe === null || $righe === false;
});

check('una classe che non è un log viene rifiutata', function () use ($featureId) {
    try {
        StatusLogger::record(Feature::class, $featureId, 'status', 'a', 'b');
    } catch (RuntimeException) {
        return true;
    }

    return false;
});

Connection::Connect()->query('DROP TABLE IF EXISTS `'.ProvaStatusLog::$table.'`');

summary();
