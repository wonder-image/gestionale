<?php

namespace Wonder\Plugin\Gestionale\Resources\System;

use Wonder\App\LegacyGlobals;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Models\System\FeatureLog;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;

/**
 * Pannello "Funzionalità": elenco di ciò che il gestionale sa fare e scheda per
 * sbloccare o bloccare. Si usa in locale; in produzione è in sola lettura,
 * perché la tabella si sincronizza con il deploy.
 */
final class FeatureResource extends GestionaleResource
{
    public static string $model = Feature::class;
    public static string $docsPage = 'funzionalita';
    public static string $orderColumn = 'feature_key';
    public static string $orderDirection = 'ASC';

    /** Stato prima del salvataggio, per lo storico. */
    private static string $previousState = '';

    public static function path(): string
    {
        return 'app/gestionale/funzionalita';
    }

    public static function icon(): string
    {
        return 'bi-toggles';
    }

    public static function titleLabel(): string
    {
        return 'Funzionalità';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'funzionalità',
            'plural_label' => 'funzionalità',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'la',
            'full' => 'sbloccata',
            'empty' => 'bloccata',
            'this' => 'questa',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'feature_key' => 'Funzionalità',
            'area' => 'Area',
            'enabled' => 'Stato',
            'requires' => 'Richiede',
            'changed_at' => 'Ultimo cambio',
            'actions' => 'Azioni',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('enabled')
                ->select(['true' => 'Sbloccata', 'false' => 'Bloccata'])
                ->label('Stato')
                ->required(),
            // Campo informativo: non è una colonna, il prepare lo scarta.
            FormField::key('consequences')->text()->label('Conseguenze del salvataggio')->readonly(),
        ];
    }

    /** Spiega, prima del salvataggio, cosa cambierà insieme a questa funzionalità. */
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        $key = (string) ($values['feature_key'] ?? '');
        $catalog = FeatureCatalog::all();

        $values['feature_key'] = $catalog[$key]['name'] ?? $key;
        $unlocked = self::unlockedRows();
        $missing = self::missingDependencies($key, $unlocked);
        $dependents = array_values(array_filter(
            self::dependents($key),
            static fn (string $candidate): bool => ($unlocked[$candidate] ?? false) === true
        ));

        $values['consequences'] = match (true) {
            $missing !== [] && $dependents !== [] => 'Sbloccandola si sbloccano anche: '.self::names($missing)
                .'. Bloccandola si bloccano anche: '.self::names($dependents).'.',
            $missing !== [] => 'Sbloccandola si sbloccano anche: '.self::names($missing).'.',
            $dependents !== [] => 'Bloccandola si bloccano anche: '.self::names($dependents).'.',
            default => 'Nessun\'altra funzionalità cambia.',
        };

        return $values;
    }

    public static function tableSchema(): array
    {
        // Nome, area e dipendenze vivono nel catalogo: la tabella li calcola
        // dalla chiave, perché nel database c'è solo lo stato.
        return [
            TableColumn::key('feature_key')
                ->formatter(static fn (array $row): string => self::feature($row)['name'] ?? (string) ($row['feature_key'] ?? ''))
                ->link('edit'),
            TableColumn::key('area')
                ->formatter(static fn (array $row): string => self::feature($row)['area'] ?? ''),
            TableColumn::key('requires')
                ->formatter(static fn (array $row): string => self::names(self::feature($row)['requires'] ?? [])),
            TableColumn::key('enabled')
                ->booleanBadge()
                ->badgeOn('Sbloccata', 'bi bi-unlock', 'success')
                ->badgeOff('Bloccata', 'bi bi-lock', 'secondary')
                ->size('little'),
            TableColumn::key('changed_at')->datetime(),
            TableColumn::key('actions')->button()->actions(['edit']),
        ];
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        return TableLayoutSchema::for(static::class)
            ->title('Funzionalità')
            ->results()
            ->filters();
    }

    public static function pageSchema(): PageSchema
    {
        return static::withDocs(PageSchema::for(static::class))
            ->only(['list', 'edit', 'update'])
            ->titles([
                'list' => 'Funzionalità',
                'edit' => 'Funzionalità',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('set-up', 'Set Up', 'bi-gear', 1020, ['admin'])
            ->title('Funzionalità')
            ->order(20)
            ->authority(['admin']);
    }

    /** Chi salva è sempre tracciato; la catena delle dipendenze scatta dopo. */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        self::$previousState = (string) ($oldValues['enabled'] ?? 'false');

        // Campo solo informativo: non è una colonna e finirebbe nella query.
        unset($values['consequences'], $values['feature_key']);

        $values['changed_at'] = date('Y-m-d H:i:s');
        $values['changed_by'] = self::userId();

        return $values;
    }

    /**
     * Sbloccando si sbloccano anche le dipendenze mancanti; bloccando si
     * bloccano anche le funzionalità che dipendono da questa. Succede dopo il
     * salvataggio, così una riga non si tocca se quella principale non è andata.
     */
    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        $row = Feature::findById($id);
        $key = is_array($row) ? (string) ($row['feature_key'] ?? '') : '';
        $to = (string) ($values['enabled'] ?? 'false');

        if ($to !== self::$previousState) {
            self::log((int) $id, self::$previousState, $to, 'user', '');
        }

        if ($to === 'true') {
            self::setUnlocked(self::missingDependencies($key, self::unlockedRows()), true, 'richiesta da '.$key);
        } else {
            $unlocked = self::unlockedRows();
            $dependents = array_values(array_filter(
                self::dependents($key),
                static fn (string $candidate): bool => ($unlocked[$candidate] ?? false) === true
            ));

            self::setUnlocked($dependents, false, 'dipende da '.$key);
        }

        Gestionale::reset();
    }

    /** @return list<string> chiavi che devono essere sbloccate prima */
    public static function missingDependencies(string $key, array $unlocked): array
    {
        $catalog = FeatureCatalog::all();
        $missing = [];

        foreach ($catalog[$key]['requires'] ?? [] as $required) {
            if (($unlocked[$required] ?? false) !== true) {
                $missing[] = $required;
                $missing = array_merge($missing, self::missingDependencies($required, $unlocked));
            }
        }

        return array_values(array_unique($missing));
    }

    /** @return list<string> chiavi che smettono di funzionare se questa si blocca */
    public static function dependents(string $key): array
    {
        $dependents = [];

        foreach (FeatureCatalog::all() as $candidate => $feature) {
            if (in_array($key, $feature['requires'], true)) {
                $dependents[] = $candidate;
                $dependents = array_merge($dependents, self::dependents($candidate));
            }
        }

        return array_values(array_unique($dependents));
    }

    /** Voce del catalogo di una riga della tabella. */
    private static function feature(array $row): array
    {
        return FeatureCatalog::all()[(string) ($row['feature_key'] ?? '')] ?? [];
    }

    /** Stato sbloccato letto dal database: chiave → bool. */
    private static function unlockedRows(): array
    {
        $unlocked = [];

        foreach (Feature::find(['deleted' => 'false']) ?: [] as $row) {
            if (is_array($row)) {
                $unlocked[(string) ($row['feature_key'] ?? '')] = ($row['enabled'] ?? 'false') === 'true';
            }
        }

        return $unlocked;
    }

    /** Sblocca o blocca altre funzionalità insieme a quella salvata, con lo storico. */
    private static function setUnlocked(array $keys, bool $enabled, string $note): void
    {
        foreach ($keys as $key) {
            $row = (array) sqlSelect(Feature::$table, ['feature_key' => $key], 1)->row;
            $id = (int) ($row['id'] ?? 0);
            $from = (string) ($row['enabled'] ?? 'false');
            $to = $enabled ? 'true' : 'false';

            if ($id === 0 || $from === $to) {
                continue;
            }

            sqlModify(Feature::$table, [
                'enabled' => $to,
                'changed_at' => date('Y-m-d H:i:s'),
                'changed_by' => self::userId(),
            ], 'id', $id);

            self::log($id, $from, $to, 'system', $note);
        }
    }

    /** Nomi leggibili di un elenco di chiavi. */
    private static function names(array $keys): string
    {
        $catalog = FeatureCatalog::all();

        return implode(', ', array_map(
            static fn (string $key): string => $catalog[$key]['name'] ?? $key,
            $keys
        ));
    }

    /** Riga di storico del cambio di stato. */
    private static function log(int $featureId, string $from, string $to, string $source, string $note): void
    {
        sqlInsert(FeatureLog::$table, [
            'feature_id' => $featureId,
            'from_value' => $from,
            'to_value' => $to,
            'source' => $source,
            'user_id' => self::userId(),
            'note' => $note,
        ]);
    }

    private static function userId(): int
    {
        $user = LegacyGlobals::get('USER');

        return is_object($user) ? (int) ($user->id ?? 0) : 0;
    }
}
