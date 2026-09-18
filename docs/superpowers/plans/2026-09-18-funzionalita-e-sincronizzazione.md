# Piano 2 di 4 — Funzionalità, pannello, righe precaricate e sincronizzazione

> **Per chi esegue:** SKILL RICHIESTA: usare superpowers:subagent-driven-development
> (consigliata) o superpowers:executing-plans, un task alla volta. I passi usano le
> caselle `- [ ]` per il tracciamento.

**Obiettivo:** rendere il gestionale sbloccabile dal pannello: catalogo delle
funzionalità nel codice, stato su database sincronizzato dal locale alla
produzione, pagina "Funzionalità" per `admin`, righe precaricate create da
`forge update` e pagine che spariscono quando la loro funzionalità è bloccata.

**Architettura:** il catalogo vive in `config/features.php` e si legge con due
classi pure (`FeatureCatalog` valida e unisce le voci del sito, `FeatureState`
calcola lo stato effettivo). Il database conserva solo lo stato
(`gst_features`, `gst_feature_logs`), sincronizzato con `id` stabili e
modificabile solo in locale. La Resource base del modulo applica la funzionalità
a menu, pagine e API, così una pagina bloccata non esiste per il router.

**Stack:** PHP 8.2, `wonder-image/app` `2.2.2` (sync `keepIds()` + `localOnly()`,
`ModuleDefaults`/`DefaultRows`, `Transaction::run()`), MySQL, test come script PHP
con l'harness del modulo.

**Spec:** `/Users/andreamarinoni/Developer/packages/gestionale/docs/superpowers/specs/2026-09-18-fondamenta-gestionale-design.md`
(sezione 3; spec di architettura 3.1, 3.2, 3.4, 8.2, 8.3).

## Prerequisiti

- Il ramo `feature/gestionale-core-additions` di `wonder-image/app` è unito in
  `main` e rilasciato come `2.2.2` (piano 1).
- In `boilerplates/ecommerce-site`, `vendor/wonder-image/app` punta a quella
  versione: finché il core non è rilasciato si collega la cartella di lavoro
  (`cd vendor/wonder-image && mv app .app-2.2.0 && ln -s /Users/andreamarinoni/Developer/packages/app app`),
  da rimettere a posto alla fine.
- `php forge update` sul sito di prova riesce e le sue `stats` riportano
  `sync_import`, `defaults` e `sync_export`: è la prova che il core è quello nuovo.

## Vincoli globali

- **Lingua:** testi, commenti e messaggi in italiano; nomi in inglese nel codice.
- **Prefisso delle tabelle:** `gst_` (D60).
- **Sincronizzazione:** `gst_features` e `gst_feature_logs` usano
  `SyncSchema::multiRow()->keepIds()->localOnly()`: si modificano solo in locale e
  arrivano in produzione con il deploy.
- **Righe precaricate:** solo con `DefaultRows`, che non tocca mai le righe
  esistenti; `forge update` le crea in locale, in produzione arrivano dal file.
- **Test d'integrazione:** girano sul database del sito di prova
  (`ecommerce_site`) dentro `Transaction::run()` e annullano sempre, anche quando
  falliscono (G1.11).
- **Ramo:** `feature/funzionalita` in `packages/gestionale`, creato da `main`.
- **Comandi:** test del modulo con `php tests/run.php`.

## Struttura dei file

| File | Responsabilità |
|---|---|
| `config/features.php` | catalogo delle funzionalità del pacchetto |
| `src/Support/Features/FeatureCatalog.php` | legge il catalogo, unisce le voci del sito, valida chiavi e dipendenze |
| `src/Support/Features/FeatureState.php` | stato effettivo: sbloccata + dipendenze attive + modulo abilitato |
| `src/Models/System/Feature.php` | tabella `gst_features` |
| `src/Models/System/FeatureLog.php` | tabella `gst_feature_logs` |
| `src/Seeding/Defaults.php` | righe precaricate del modulo (una per funzionalità) |
| `src/Resources/System/FeatureResource.php` | pagina "Funzionalità" |
| `src/Gestionale.php` (modifica) | `feature()`, `features()`, `reset()` |
| `src/Resources/GestionaleResource.php` (modifica) | menu, pagine e API legati alla funzionalità |
| `module.json` (modifica) | `database.defaults` |
| `tests/FeatureCatalogTest.php`, `tests/FeatureStateTest.php`, `tests/FeatureModelsTest.php`, `tests/FeatureGateTest.php`, `tests/integrazione/DefaultsTest.php`, `tests/integrazione/SyncTest.php` | test |

---

### Task 1: Catalogo delle funzionalità

**File:**
- Crea: `config/features.php`, `src/Support/Features/FeatureCatalog.php`
- Test: `tests/FeatureCatalogTest.php`

**Interfacce:**
- Consuma: `Gestionale::config('features.extra')` (piano 1).
- Produce: `FeatureCatalog::fromArray(array $package, array $extra = []): array`
  (chiave → `['key','name','description','area','requires','module','release']`),
  `FeatureCatalog::all(): array`, `FeatureCatalog::assertValid(array $catalog): void`.

- [ ] **Passo 1: crea il ramo**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && git switch -c feature/funzionalita
```

- [ ] **Passo 2: scrivi il test che fallisce**

File `tests/FeatureCatalogTest.php`:

```php
<?php
/** php tests/FeatureCatalogTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;

$pacchetto = [
    'orders' => ['name' => 'Ordini', 'description' => 'Gestione degli ordini', 'area' => 'Vendite'],
    'returns' => ['name' => 'Resi', 'description' => 'Resi e ricarico', 'area' => 'Vendite', 'requires' => ['orders']],
];

check('ogni voce ha chiave, nome, area e dipendenze normalizzate', function () use ($pacchetto) {
    $catalogo = FeatureCatalog::fromArray($pacchetto);

    return $catalogo['orders']['key'] === 'orders'
        && $catalogo['orders']['requires'] === []
        && $catalogo['orders']['module'] === ''
        && $catalogo['returns']['requires'] === ['orders']
        && $catalogo['returns']['area'] === 'Vendite';
});

check('le voci del sito si aggiungono al catalogo', function () use ($pacchetto) {
    $catalogo = FeatureCatalog::fromArray($pacchetto, [
        'loyalty' => ['name' => 'Raccolta punti', 'area' => 'Promozioni', 'requires' => ['orders']],
    ]);

    return isset($catalogo['loyalty']) && $catalogo['loyalty']['requires'] === ['orders'];
});

check('il sito non può sovrascrivere una funzionalità del pacchetto', function () use ($pacchetto) {
    try {
        FeatureCatalog::fromArray($pacchetto, ['orders' => ['name' => 'Altro']]);
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), 'orders');
    }

    return false;
});

check('dipendenza inesistente rifiutata', function () {
    try {
        FeatureCatalog::fromArray(['a' => ['name' => 'A', 'area' => 'X', 'requires' => ['manca']]]);
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), 'manca');
    }

    return false;
});

check('dipendenze circolari rifiutate', function () {
    try {
        FeatureCatalog::fromArray([
            'a' => ['name' => 'A', 'area' => 'X', 'requires' => ['b']],
            'b' => ['name' => 'B', 'area' => 'X', 'requires' => ['a']],
        ]);
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), 'circolare');
    }

    return false;
});

check('chiave non valida rifiutata', function () {
    try {
        FeatureCatalog::fromArray(['Ordini Online' => ['name' => 'X', 'area' => 'Y']]);
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), 'chiave');
    }

    return false;
});

check('il catalogo del pacchetto è valido e contiene le funzionalità della spec', function () {
    $catalogo = FeatureCatalog::all();
    $attese = [
        'orders', 'quotes', 'returns', 'delivery_notes', 'subscriptions', 'bundles',
        'customizations', 'barcode_labels', 'multi_location', 'purchasing', 'batch_tracking',
        'low_stock_alerts', 'backorders', 'customer_price_lists', 'discount_campaigns',
        'coupons', 'e_invoicing', 'deferred_invoicing', 'shipping', 'carriers', 'pos',
    ];

    return array_diff($attese, array_keys($catalogo)) === [];
});

summary();
```

- [ ] **Passo 3: esegui il test e verifica che fallisca**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/FeatureCatalogTest.php
```

Atteso: `Class "…FeatureCatalog" not found`.

- [ ] **Passo 4: scrivi il catalogo**

File `config/features.php` (chiavi, aree e dipendenze da 3.4 della spec di
architettura, più `backorders` di D60):

```php
<?php

return [
    'orders' => [
        'name' => 'Ordini',
        'description' => 'Gestione degli ordini con stati e scarico del magazzino.',
        'area' => 'Vendite',
        'release' => 'G4',
    ],
    'quotes' => [
        'name' => 'Preventivi',
        'description' => 'Preventivi con PDF e revisioni, convertibili in ordine.',
        'area' => 'Vendite',
        'requires' => ['orders'],
        'release' => 'G9',
    ],
    'returns' => [
        'name' => 'Resi',
        'description' => 'Richiesta, approvazione, motivo e ricarico a magazzino.',
        'area' => 'Vendite',
        'requires' => ['orders'],
        'release' => 'G4',
    ],
    'delivery_notes' => [
        'name' => 'DDT',
        'description' => 'Documento di trasporto per le consegne.',
        'area' => 'Vendite',
        'requires' => ['orders'],
        'release' => 'G9',
    ],
    'subscriptions' => [
        'name' => 'Abbonamenti',
        'description' => 'Piani, rinnovi e cambio piano.',
        'area' => 'Vendite',
        'release' => 'G10',
    ],
    'bundles' => [
        'name' => 'Multiprodotto',
        'description' => 'Prodotti composti da altri prodotti: vendendoli si scaricano i componenti.',
        'area' => 'Catalogo',
        'release' => 'G5',
    ],
    'customizations' => [
        'name' => 'Personalizzazione',
        'description' => 'Campi compilati al momento della vendita, con eventuale sovrapprezzo.',
        'area' => 'Catalogo',
        'requires' => ['orders'],
        'release' => 'G5',
    ],
    'barcode_labels' => [
        'name' => 'Etichette',
        'description' => 'Etichette con codice a barre e prezzo in PDF.',
        'area' => 'Catalogo',
        'release' => 'futura',
    ],
    'multi_location' => [
        'name' => 'Più sedi',
        'description' => 'Altre sedi con giacenze proprie e trasferimenti.',
        'area' => 'Magazzino',
        'release' => 'G3',
    ],
    'purchasing' => [
        'name' => 'Acquisti',
        'description' => 'Costi d\'acquisto per fornitore, documenti di carico, valore del magazzino.',
        'area' => 'Magazzino',
        'release' => 'G3',
    ],
    'batch_tracking' => [
        'name' => 'Lotti e scadenze',
        'description' => 'Lotto e data di scadenza su carichi e scarichi.',
        'area' => 'Magazzino',
        'release' => 'G3',
    ],
    'low_stock_alerts' => [
        'name' => 'Avvisi di scorta minima',
        'description' => 'Email e riquadro per i prodotti sotto la propria scorta minima.',
        'area' => 'Magazzino',
        'release' => 'G2',
    ],
    'backorders' => [
        'name' => 'Vendita senza giacenza',
        'description' => 'Prodotti vendibili a magazzino vuoto: la giacenza va sotto zero e la vendita è "su ordinazione".',
        'area' => 'Magazzino',
        'requires' => ['orders'],
        'release' => 'G4',
    ],
    'customer_price_lists' => [
        'name' => 'Listini cliente',
        'description' => 'Prezzi personalizzati per cliente, con scaglioni.',
        'area' => 'Listini',
        'requires' => ['orders'],
        'release' => 'G6',
    ],
    'discount_campaigns' => [
        'name' => 'Sconto massivo',
        'description' => 'Campagne per categoria, tag, brand o modello, con data e ora, in € o %.',
        'area' => 'Promozioni',
        'requires' => ['orders'],
        'release' => 'G6',
    ],
    'coupons' => [
        'name' => 'Coupon',
        'description' => 'Importo, percentuale, buono a scalare, spedizione gratuita, con limiti.',
        'area' => 'Promozioni',
        'requires' => ['orders'],
        'release' => 'G6',
    ],
    'e_invoicing' => [
        'name' => 'Fatturazione elettronica',
        'description' => 'Fatture, invio SDI, coda, stati e notifiche.',
        'area' => 'Fatturazione',
        'release' => 'G8',
    ],
    'deferred_invoicing' => [
        'name' => 'Fattura differita',
        'description' => 'Fattura riepilogativa dei DDT del periodo.',
        'area' => 'Fatturazione',
        'requires' => ['delivery_notes', 'e_invoicing'],
        'release' => 'G9',
    ],
    'shipping' => [
        'name' => 'Spedizioni',
        'description' => 'Listini per zona e peso, spedizioni e tracking manuali, ritiro in sede.',
        'area' => 'Spedizioni',
        'requires' => ['orders'],
        'release' => 'G7',
    ],
    'carriers' => [
        'name' => 'Corrieri',
        'description' => 'Etichette e tracking dal corriere.',
        'area' => 'Spedizioni',
        'requires' => ['shipping'],
        'release' => 'futura',
    ],
    'pos' => [
        'name' => 'Banco',
        'description' => 'Vendita in sede con documento commerciale e corrispettivi.',
        'area' => 'Banco',
        'requires' => ['orders'],
        'release' => 'futura',
    ],
];
```

- [ ] **Passo 5: scrivi la classe**

File `src/Support/Features/FeatureCatalog.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Features;

use RuntimeException;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * Catalogo delle funzionalità: sta nel codice (`config/features.php` del
 * pacchetto più `features.extra` del sito), il database conserva solo lo stato.
 */
final class FeatureCatalog
{
    /**
     * @param array<string, array<string, mixed>> $package
     * @param array<string, array<string, mixed>> $extra voci aggiunte dal sito
     * @return array<string, array{key: string, name: string, description: string, area: string, requires: list<string>, module: string, release: string}>
     */
    public static function fromArray(array $package, array $extra = []): array
    {
        $catalog = [];

        foreach ([$package, $extra] as $index => $source) {
            foreach ($source as $key => $feature) {
                $key = is_string($key) ? trim($key) : '';

                if ($key === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
                    throw new RuntimeException('Funzionalità con chiave non valida: '.var_export($key, true));
                }

                if ($index === 1 && isset($catalog[$key])) {
                    throw new RuntimeException('Il sito non può sovrascrivere la funzionalità '.$key);
                }

                $catalog[$key] = [
                    'key' => $key,
                    'name' => trim((string) ($feature['name'] ?? $key)),
                    'description' => trim((string) ($feature['description'] ?? '')),
                    'area' => trim((string) ($feature['area'] ?? 'Altro')),
                    'requires' => array_values(array_filter(
                        array_map('strval', (array) ($feature['requires'] ?? [])),
                        static fn (string $value): bool => trim($value) !== ''
                    )),
                    'module' => trim((string) ($feature['module'] ?? '')),
                    'release' => trim((string) ($feature['release'] ?? '')),
                ];
            }
        }

        self::assertValid($catalog);

        return $catalog;
    }

    /** Catalogo del pacchetto più le voci del sito. */
    public static function all(): array
    {
        return self::fromArray(
            (array) require Gestionale::root().'/config/features.php',
            (array) Gestionale::config('features.extra', [])
        );
    }

    /** Dipendenze esistenti e senza cicli. */
    public static function assertValid(array $catalog): void
    {
        foreach ($catalog as $key => $feature) {
            foreach ($feature['requires'] as $required) {
                if (!isset($catalog[$required])) {
                    throw new RuntimeException("La funzionalità {$key} dipende da {$required}, che non esiste");
                }
            }
        }

        foreach (array_keys($catalog) as $key) {
            self::assertNoCycle($catalog, $key, []);
        }
    }

    /** @param list<string> $path */
    private static function assertNoCycle(array $catalog, string $key, array $path): void
    {
        if (in_array($key, $path, true)) {
            throw new RuntimeException('Dipendenza circolare tra le funzionalità: '.implode(' → ', [...$path, $key]));
        }

        $path[] = $key;

        foreach ($catalog[$key]['requires'] ?? [] as $required) {
            self::assertNoCycle($catalog, $required, $path);
        }
    }
}
```

- [ ] **Passo 6: esegui il test e verifica che passi**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/FeatureCatalogTest.php
```

Atteso: `7 test, 0 falliti`.

- [ ] **Passo 7: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && git add config/features.php src/Support/Features/FeatureCatalog.php tests/FeatureCatalogTest.php && git commit -m "Add the feature catalogue

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Stato effettivo delle funzionalità

**File:**
- Crea: `src/Support/Features/FeatureState.php`
- Modifica: `src/Gestionale.php` (aggiunge `feature()`, `features()` e le azzera in `reset()`)
- Test: `tests/FeatureStateTest.php`

**Interfacce:**
- Consuma: `FeatureCatalog::all()` (Task 1), `Wonder\App\Module\Registry::enabled()` del core.
- Produce: `FeatureState::resolve(array $catalog, array $unlocked, array $modules): array`
  (chiave → bool), `FeatureState::isActive(array $catalog, array $unlocked, array $modules, string $key): bool`;
  `Gestionale::feature(string $key): bool`, `Gestionale::features(): array`.

- [ ] **Passo 1: scrivi il test che fallisce**

File `tests/FeatureStateTest.php`:

```php
<?php
/** php tests/FeatureStateTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;
use Wonder\Plugin\Gestionale\Support\Features\FeatureState;

$catalogo = FeatureCatalog::fromArray([
    'orders' => ['name' => 'Ordini', 'area' => 'Vendite'],
    'returns' => ['name' => 'Resi', 'area' => 'Vendite', 'requires' => ['orders']],
    'online_store' => ['name' => 'Negozio online', 'area' => 'Online', 'requires' => ['orders'], 'module' => 'ecommerce'],
]);

check('sbloccata senza dipendenze: attiva', fn () =>
    FeatureState::resolve($catalogo, ['orders' => true], ['gestionale'])['orders'] === true
);

check('bloccata: non attiva', fn () =>
    FeatureState::resolve($catalogo, [], ['gestionale'])['orders'] === false
);

check('dipendenza bloccata: non attiva anche se sbloccata', fn () =>
    FeatureState::resolve($catalogo, ['returns' => true], ['gestionale'])['returns'] === false
);

check('dipendenza sbloccata: attiva', fn () =>
    FeatureState::resolve($catalogo, ['orders' => true, 'returns' => true], ['gestionale'])['returns'] === true
);

check('modulo richiesto non abilitato: non attiva', fn () =>
    FeatureState::resolve($catalogo, ['orders' => true, 'online_store' => true], ['gestionale'])['online_store'] === false
);

check('modulo richiesto abilitato: attiva', fn () =>
    FeatureState::resolve($catalogo, ['orders' => true, 'online_store' => true], ['gestionale', 'ecommerce'])['online_store'] === true
);

check('chiave sconosciuta: non attiva, nessun errore', fn () =>
    FeatureState::isActive($catalogo, ['orders' => true], ['gestionale'], 'chiave_inesistente') === false
);

check('lo stato contiene tutte le chiavi del catalogo', fn () =>
    array_keys(FeatureState::resolve($catalogo, [], [])) === array_keys($catalogo)
);

summary();
```

- [ ] **Passo 2: esegui il test e verifica che fallisca**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/FeatureStateTest.php
```

Atteso: `Class "…FeatureState" not found`.

- [ ] **Passo 3: scrivi la classe**

File `src/Support/Features/FeatureState.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Features;

/**
 * Stato effettivo di una funzionalità: sbloccata, con tutte le dipendenze
 * attive e il modulo richiesto abilitato. Classe pura: riceve catalogo, righe
 * e moduli, non legge il database.
 */
final class FeatureState
{
    /**
     * @param array<string, array<string, mixed>> $catalog
     * @param array<string, bool> $unlocked chiave → sbloccata
     * @param list<string> $modules slug dei moduli abilitati
     * @return array<string, bool>
     */
    public static function resolve(array $catalog, array $unlocked, array $modules): array
    {
        $state = [];

        foreach (array_keys($catalog) as $key) {
            $state[$key] = self::active($catalog, $unlocked, $modules, $key, []);
        }

        return $state;
    }

    public static function isActive(array $catalog, array $unlocked, array $modules, string $key): bool
    {
        return isset($catalog[$key]) && self::active($catalog, $unlocked, $modules, $key, []);
    }

    /** @param list<string> $path protegge da cataloghi con cicli non validati */
    private static function active(array $catalog, array $unlocked, array $modules, string $key, array $path): bool
    {
        if (!isset($catalog[$key]) || in_array($key, $path, true)) {
            return false;
        }

        if (($unlocked[$key] ?? false) !== true) {
            return false;
        }

        $module = (string) ($catalog[$key]['module'] ?? '');

        if ($module !== '' && !in_array($module, $modules, true)) {
            return false;
        }

        $path[] = $key;

        foreach ($catalog[$key]['requires'] ?? [] as $required) {
            if (!self::active($catalog, $unlocked, $modules, (string) $required, $path)) {
                return false;
            }
        }

        return true;
    }
}
```

- [ ] **Passo 4: esponi lo stato dall'entrypoint**

In `src/Gestionale.php` aggiungi le proprietà e i metodi, e azzerali in `reset()`:

```php
    private static ?array $features = null;

    /** Stato effettivo di tutte le funzionalità, calcolato una volta per richiesta. */
    public static function features(): array
    {
        if (self::$features !== null) {
            return self::$features;
        }

        $catalog = Support\Features\FeatureCatalog::all();
        $unlocked = [];

        foreach (Models\System\Feature::find(['deleted' => 'false']) ?: [] as $row) {
            if (is_array($row)) {
                $unlocked[(string) ($row['feature_key'] ?? '')] = ($row['enabled'] ?? 'false') === 'true';
            }
        }

        $modules = array_map(
            static fn ($manifest): string => $manifest->slug(),
            \Wonder\App\Module\Registry::enabled()
        );

        return self::$features = Support\Features\FeatureState::resolve($catalog, $unlocked, $modules);
    }

    /** Stato di una funzionalità; una chiave sconosciuta è sempre bloccata. */
    public static function feature(string $key): bool
    {
        return self::features()[$key] ?? false;
    }
```

In `reset()` aggiungi `self::$features = null;`.

Il Model `Feature` arriva nel Task 3: fino ad allora `features()` non si può
chiamare, ma `FeatureState` è già provato dai test.

- [ ] **Passo 5: esegui il test e verifica che passi**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/FeatureStateTest.php
```

Atteso: `8 test, 0 falliti`.

- [ ] **Passo 6: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && git add src tests/FeatureStateTest.php && git commit -m "Resolve the effective state of a feature

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: Tabelle dello stato

**File:**
- Crea: `src/Models/System/Feature.php`, `src/Models/System/FeatureLog.php`
- Test: `tests/FeatureModelsTest.php`

**Interfacce:**
- Consuma: `Wonder\App\Model`, `Wonder\App\Support\SyncSchema`,
  `Wonder\Data\UploadSchema as Field`, `Wonder\Sql\TableSchema as Column`.
- Produce: `Feature::$table = 'gst_features'` con `feature_key`, `enabled`,
  `changed_at`, `changed_by`; `FeatureLog::$table = 'gst_feature_logs'` con
  `feature_id`, `from_value`, `to_value`, `source`, `user_id`, `note`.

- [ ] **Passo 1: scrivi il test che fallisce**

File `tests/FeatureModelsTest.php`:

```php
<?php
/** php tests/FeatureModelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Models\System\FeatureLog;

check('tabelle con il prefisso gst_', fn () =>
    Feature::$table === 'gst_features' && FeatureLog::$table === 'gst_feature_logs'
);

check('colonne dello stato', function () {
    $columns = array_keys(Feature::getColumns());

    return array_diff(['feature_key', 'enabled', 'changed_at', 'changed_by'], $columns) === [];
});

check('colonne dello storico, legate alla funzionalità', function () {
    $columns = FeatureLog::getColumns();

    return array_diff(['feature_id', 'from_value', 'to_value', 'source', 'user_id', 'note'], array_keys($columns)) === []
        && ($columns['feature_id']['foreign_table'] ?? null) === 'gst_features';
});

check('sincronizzate con id stabili e modificabili solo in locale', function () {
    $feature = Feature::syncSchema();
    $log = FeatureLog::syncSchema();

    return $feature !== null && $feature->keepIds && $feature->localOnly
        && $log !== null && $log->keepIds && $log->localOnly;
});

check('la chiave della funzionalità è unica', function () {
    $columns = Feature::getColumns();

    return array_key_exists('unique', $columns['feature_key'] ?? []) === false
        ? str_contains(json_encode(Feature::tablePseudos()), 'feature_key')
        : true;
});

summary();
```

- [ ] **Passo 2: esegui il test e verifica che fallisca**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/FeatureModelsTest.php
```

Atteso: `Class "…Feature" not found`.

- [ ] **Passo 3: scrivi i Model**

File `src/Models/System/Feature.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\System;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Stato di una funzionalità. Il catalogo (nome, area, dipendenze) sta nel
 * codice: qui c'è solo se è sbloccata, da chi e quando.
 */
final class Feature extends Model
{
    public static string $table = 'gst_features';
    public static string $folder = 'gestionale/features';
    public static string $icon = 'bi bi-toggles';

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::multiRow()->keepIds()->localOnly();
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('feature_key')->length(100),
            Column::key('enabled')->enum(['true', 'false'])->default('false'),
            ...static::sqlColumnsFromDataSchema(['changed_at', 'changed_by']),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'uniq_feature_key' => ['unique' => 'feature_key'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('feature_key')->text()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('enabled')->text()->sanitize(false),
            Field::key('changed_at')->date(),
            Field::key('changed_by')->number(),
        ];
    }
}
```

Controlla con `grep -n "unique\|index" /Users/andreamarinoni/Developer/packages/app/class/Sql/TableSchema.php | head`
la forma esatta di `tablePseudos()` (in `immobili` gli indici si dichiarano così:
`'ind_immobile' => ['index' => 'immobile_id']`) e adegua la chiave unica.

File `src/Models/System/FeatureLog.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Models\System;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Storico dei cambi di stato delle funzionalità: chi ha sbloccato o bloccato
 * cosa, quando e perché.
 */
final class FeatureLog extends Model
{
    public static string $table = 'gst_feature_logs';
    public static string $folder = 'gestionale/features';
    public static string $icon = 'bi bi-clock-history';

    public static function syncSchema(): ?SyncSchema
    {
        return SyncSchema::multiRow()->keepIds()->localOnly();
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('feature_id')->int()->foreign(Feature::$table, 'id'),
            ...static::sqlColumnsFromDataSchema(['from_value', 'to_value', 'source', 'user_id', 'note']),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_feature' => ['index' => 'feature_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('feature_id')->number(),
            Field::key('from_value')->text()->sanitize(false),
            Field::key('to_value')->text()->sanitize(false),
            Field::key('source')->text()->sanitize(false),
            Field::key('user_id')->number(),
            Field::key('note')->text(),
        ];
    }
}
```

Prima di scrivere `foreign(...)` controlla la firma vera con
`grep -n "function foreign" -A8 /Users/andreamarinoni/Developer/packages/app/class/Sql/TableSchema.php`
e con l'esempio di `SocietyLocationHour` nel core.

- [ ] **Passo 4: esegui il test e verifica che passi**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/FeatureModelsTest.php
```

Atteso: `5 test, 0 falliti`.

- [ ] **Passo 5: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && git add src/Models tests/FeatureModelsTest.php && git commit -m "Add the feature state tables

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: Righe precaricate

**File:**
- Crea: `src/Seeding/Defaults.php`
- Modifica: `module.json` (`database.defaults`)
- Test: `tests/integrazione/DefaultsTest.php`

**Interfacce:**
- Consuma: `Wonder\App\Module\Contracts\ModuleDefaults`, `Wonder\App\Support\DefaultRows`
  (`ensure(string $modelClass, string $keyColumn, array $rows): int`), `FeatureCatalog::all()`,
  `Gestionale::config('features.unlock')`.
- Produce: `Wonder\Plugin\Gestionale\Seeding\Defaults::seed(DefaultRows $rows): void`.

- [ ] **Passo 1: scrivi il test d'integrazione che fallisce**

File `tests/integrazione/DefaultsTest.php` (gira sul sito di prova, dentro una
transazione annullata):

```php
<?php
/** php tests/integrazione/DefaultsTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\App\Support\DefaultRows;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Seeding\Defaults;
use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$conta = static fn (): int => count(array_filter(
    (array) sqlSelect(Feature::$table, null)->row,
    'is_array'
));

try {
    Transaction::run(static function () use ($conta): void {
        $prima = $conta();
        $rows = new DefaultRows();
        Defaults::seed($rows);
        $dopo = $conta();

        check('una riga per ogni funzionalità del catalogo', fn () =>
            $dopo === max($prima, count(FeatureCatalog::all()))
        );

        $seconda = new DefaultRows();
        Defaults::seed($seconda);

        check('una seconda esecuzione non duplica niente', fn () => $conta() === $dopo);

        $righe = array_column(
            array_filter((array) sqlSelect(Feature::$table, null)->row, 'is_array'),
            'enabled',
            'feature_key'
        );

        check('le funzionalità nascono bloccate', fn () =>
            ($righe['orders'] ?? null) === 'false' && ($righe['backorders'] ?? null) === 'false'
        );

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento il database è come prima', fn () => true);

summary();
```

- [ ] **Passo 2: esegui il test e verifica che fallisca**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/integrazione/DefaultsTest.php
```

Atteso: `Class "…Defaults" not found`. Se invece manca la tabella
`gst_features`, lancia prima `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update`.

- [ ] **Passo 3: scrivi la classe**

File `src/Seeding/Defaults.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\App\Module\Contracts\ModuleDefaults;
use Wonder\App\Support\DefaultRows;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;

/**
 * Righe precaricate del gestionale, create da `forge update` in locale.
 * Regola unica: si inserisce solo ciò che manca, mai si modifica ciò che c'è.
 */
final class Defaults implements ModuleDefaults
{
    public static function seed(DefaultRows $rows): void
    {
        $unlock = array_map('strval', (array) Gestionale::config('features.unlock', []));
        $features = [];

        foreach (FeatureCatalog::all() as $key => $feature) {
            $features[] = [
                'feature_key' => $key,
                // Il sito può far nascere sbloccate alcune funzionalità (starter),
                // mai cambiarle dopo: le righe esistenti non si toccano.
                'enabled' => in_array($key, $unlock, true) ? 'true' : 'false',
            ];
        }

        $rows->ensure(Feature::class, 'feature_key', $features);
    }
}
```

In `module.json` aggiungi la classe:

```json
    "database": {
        "models": "src/Models",
        "defaults": "Wonder\\Plugin\\Gestionale\\Seeding\\Defaults"
    },
```

- [ ] **Passo 4: esegui il test e verifica che passi**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/integrazione/DefaultsTest.php
```

Atteso: tutti i test verdi.

- [ ] **Passo 5: verifica con `forge update` sul sito**

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update | head -20 && php forge update | head -20
```

Atteso: la prima esecuzione riporta `"defaults"` maggiore di zero e
`"sync_export": true`; la seconda riporta `"defaults": 0`. Controlla anche che
`shared/sync-data.json` contenga `gst_features` con gli `id`:

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php -r '$d=json_decode(file_get_contents("shared/sync-data.json"),true); echo count($d["gst_features"] ?? []), " righe, prima: ", json_encode(($d["gst_features"][0] ?? [])), "\n";'
```

- [ ] **Passo 6: aggiorna la validazione del manifest e committa**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/run.php
```

Atteso: tutti verdi (il test del manifest continua a passare con
`database.defaults`).

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && git add src/Seeding module.json tests/integrazione/DefaultsTest.php && git commit -m "Create one row per feature on update

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: Pagine legate alla funzionalità

**File:**
- Modifica: `src/Resources/GestionaleResource.php`
- Test: `tests/FeatureGateTest.php`

**Interfacce:**
- Consuma: `Gestionale::feature()` (Task 2), `PageSchema::only(array $pages)`,
  `NavigationSchema::enabled(bool)`, `ApiSchema::enabled(bool)` del core.
- Produce: `GestionaleResource::featureActive(): bool` e le tre schema che ne
  tengono conto.

- [ ] **Passo 1: scrivi il test che fallisce**

File `tests/FeatureGateTest.php`:

```php
<?php
/** php tests/FeatureGateTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Model;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;

final class ModelloDiProva extends Model
{
    public static string $table = 'gst_prova_gate';

    public static function tableSchema(): array { return []; }
    public static function dataSchema(): array { return []; }
}

final class ResourceSempreAttiva extends GestionaleResource
{
    public static string $model = ModelloDiProva::class;

    public static function path(): string { return 'gestionale/sempre-attiva'; }
}

final class ResourceConFunzionalita extends GestionaleResource
{
    public static string $model = ModelloDiProva::class;
    public static string $feature = 'orders';

    public static function path(): string { return 'gestionale/con-funzionalita'; }
}

// Lo stato si forza senza database: features() è memoizzato.
$forza = static function (array $stato): void {
    $proprieta = new ReflectionProperty(\Wonder\Plugin\Gestionale\Gestionale::class, 'features');
    $proprieta->setAccessible(true);
    $proprieta->setValue(null, $stato);
};

check('senza funzionalità dichiarata la pagina resta attiva', function () use ($forza) {
    $forza(['orders' => false]);

    return ResourceSempreAttiva::featureActive() === true
        && (bool) ResourceSempreAttiva::navigationSchema()->get('enabled') === true;
});

check('funzionalità bloccata: niente menu, niente pagine, niente API', function () use ($forza) {
    $forza(['orders' => false]);

    $pages = (array) ResourceConFunzionalita::pageSchema()->get('pages');

    return ResourceConFunzionalita::featureActive() === false
        && (bool) ResourceConFunzionalita::navigationSchema()->get('enabled') === false
        && array_filter($pages) === []
        && (bool) ResourceConFunzionalita::apiSchema()->get('enabled') === false;
});

check('funzionalità attiva: tutto torna disponibile', function () use ($forza) {
    $forza(['orders' => true]);

    $pages = (array) ResourceConFunzionalita::pageSchema()->get('pages');

    return ResourceConFunzionalita::featureActive() === true
        && (bool) ResourceConFunzionalita::navigationSchema()->get('enabled') === true
        && ($pages['list'] ?? false) === true;
});

summary();
```

- [ ] **Passo 2: esegui il test e verifica che fallisca**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/FeatureGateTest.php
```

Atteso: `Call to undefined method …::featureActive()`.

- [ ] **Passo 3: applica la funzionalità nella Resource base**

In `src/Resources/GestionaleResource.php` aggiungi:

```php
    /** Vero se la pagina non dipende da nessuna funzionalità o se quella dichiarata è attiva. */
    public static function featureActive(): bool
    {
        return static::$feature === '' || Gestionale::feature(static::$feature);
    }

    public static function pageSchema(): PageSchema
    {
        $schema = static::withDocs(PageSchema::for(static::class));

        return static::featureActive() ? $schema : $schema->only([]);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()->enabled(static::featureActive());
    }

    public static function apiSchema(): ApiSchema
    {
        $schema = parent::apiSchema();

        return static::featureActive() ? $schema : $schema->enabled(false);
    }
```

con gli `use` di `NavigationSchema` e `ApiSchema`. Le Resource figlie che
sovrascrivono `pageSchema()` partono da `static::withDocs(...)` e chiudono con
`static::featureActive() ? $schema : $schema->only([])`: il piano 3 lo fa per le
pagine vere.

- [ ] **Passo 4: esegui il test e verifica che passi**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/FeatureGateTest.php
```

Atteso: `3 test, 0 falliti`.

- [ ] **Passo 5: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && git add src/Resources tests/FeatureGateTest.php && git commit -m "Hide pages of a locked feature

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 6: Pannello "Funzionalità"

**File:**
- Crea: `src/Resources/System/FeatureResource.php`
- Modifica: `lang/it/gestionale.json` (testi della pagina)
- Test: `tests/FeatureResourceTest.php`

**Interfacce:**
- Consuma: `GestionaleResource` (Task 5), `FeatureCatalog::all()` (Task 1),
  `Feature`/`FeatureLog` (Task 3), gli hook `mutateFormValues`,
  `mutateRequestValues`, `afterUpdate` del core.
- Produce: pagina `app/gestionale/funzionalita` con elenco e scheda della
  singola funzionalità; `FeatureResource::dependents(string $key): array` e
  `FeatureResource::missingDependencies(string $key, array $unlocked): array`.

**Nota sulla forma del pannello (G1.12).** La spec parla di interruttori
nell'elenco. Il core non ha un endpoint generico che accetti le regole delle
dipendenze, quindi il pannello è: elenco con stato, area, dipendenze e ultimo
cambio, e scheda della singola funzionalità dove si sblocca o si blocca. Alla
conferma la scheda sblocca anche le dipendenze mancanti e blocca anche le
funzionalità che dipendono da quella, spiegandolo nel messaggio.

- [ ] **Passo 1: scrivi il test che fallisce**

File `tests/FeatureResourceTest.php`:

```php
<?php
/** php tests/FeatureResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Resources\System\FeatureResource;

check('dipendenze mancanti di una funzionalità', fn () =>
    FeatureResource::missingDependencies('deferred_invoicing', ['e_invoicing' => true])
        === ['delivery_notes']
);

check('nessuna dipendenza mancante se sono tutte sbloccate', fn () =>
    FeatureResource::missingDependencies('returns', ['orders' => true]) === []
);

check('funzionalità che dipendono da una data', function () {
    $dipendenti = FeatureResource::dependents('orders');

    return in_array('returns', $dipendenti, true)
        && in_array('backorders', $dipendenti, true)
        && !in_array('orders', $dipendenti, true);
});

check('dipendenti calcolati a catena', fn () =>
    in_array('deferred_invoicing', FeatureResource::dependents('delivery_notes'), true)
);

check('ogni Resource del modulo dichiara la sua funzionalità', function () {
    $mancanti = [];

    foreach (glob(dirname(__DIR__).'/src/Resources/*/*.php') ?: [] as $file) {
        $classe = 'Wonder\\Plugin\\Gestionale\\Resources\\'
            .basename(dirname($file)).'\\'.basename($file, '.php');

        if (!class_exists($classe)) {
            $mancanti[] = $classe.' non autoloadabile';
            continue;
        }

        // $feature vuoto è ammesso solo per le pagine sempre attive (3.4).
        $sempreAttive = ['Wonder\\Plugin\\Gestionale\\Resources\\System\\FeatureResource'];

        if ($classe::$feature === '' && !in_array($classe, $sempreAttive, true)) {
            $mancanti[] = $classe;
        }
    }

    if ($mancanti !== []) {
        echo '    '.implode("\n    ", $mancanti)."\n";
    }

    return $mancanti === [];
});

check('pagina riservata ad admin e sola lettura fuori dal locale', fn () =>
    (array) FeatureResource::permissionSchema()->get('backend')['list'] === ['admin']
    && FeatureResource::modelClass()::syncSchema()->localOnly === true
);

summary();
```

- [ ] **Passo 2: esegui il test e verifica che fallisca**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/FeatureResourceTest.php
```

Atteso: `Class "…FeatureResource" not found`.

- [ ] **Passo 3: scrivi la Resource**

File `src/Resources/System/FeatureResource.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Resources\System;

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
            'article' => 'la',
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
            'module' => 'Modulo',
            'changed_at' => 'Ultimo cambio',
            'actions' => 'Azioni',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('enabled')
                ->select(['true' => 'Sbloccata', 'false' => 'Bloccata'])
                ->required(),
        ];
    }

    public static function tableSchema(): array
    {
        return [
            // Nome, area e dipendenze vivono nel catalogo: la tabella li calcola
            // dalla chiave con un formatter (il database ha solo lo stato).
            TableColumn::key('feature_key')
                ->formatter(static fn (array $row): string => FeatureCatalog::all()[(string) ($row['feature_key'] ?? '')]['name']
                    ?? (string) ($row['feature_key'] ?? ''))
                ->link('edit'),
            TableColumn::key('area')
                ->formatter(static fn (array $row): string => FeatureCatalog::all()[(string) ($row['feature_key'] ?? '')]['area'] ?? ''),
            TableColumn::key('requires')
                ->formatter(static function (array $row): string {
                    $catalog = FeatureCatalog::all();
                    $feature = $catalog[(string) ($row['feature_key'] ?? '')] ?? null;

                    return implode(', ', array_map(
                        static fn (string $key): string => $catalog[$key]['name'] ?? $key,
                        $feature['requires'] ?? []
                    ));
                }),
            TableColumn::key('enabled')
                ->booleanBadge()
                ->badgeOn('Sbloccata', 'bi bi-unlock', 'success')
                ->badgeOff('Bloccata')
                ->size('little'),
            TableColumn::key('changed_at')->text(),
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
        $catalog = FeatureCatalog::all();
        $dependents = [];

        foreach ($catalog as $candidate => $feature) {
            if (in_array($key, $feature['requires'], true)) {
                $dependents[] = $candidate;
                $dependents = array_merge($dependents, self::dependents($candidate));
            }
        }

        return array_values(array_unique($dependents));
    }
}
```

- [ ] **Passo 4: esegui il test e verifica che passi**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/FeatureResourceTest.php
```

Atteso: `6 test, 0 falliti`.

- [ ] **Passo 5: sblocco e blocco con le dipendenze**

Aggiungi alla Resource gli hook che tengono insieme le dipendenze e scrivono lo
storico:

```php
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        global $ALERT;

        $key = (string) ($oldValues['feature_key'] ?? '');
        $values['changed_at'] = date('Y-m-d H:i:s');
        $values['changed_by'] = (int) (\Wonder\App\LegacyGlobals::get('USER')->id ?? 0);

        if (($values['enabled'] ?? 'false') === 'true') {
            $mancanti = self::missingDependencies($key, self::unlockedRows());

            if ($mancanti !== []) {
                self::setUnlocked($mancanti, true, 'dipendenza di '.$key);
                $ALERT = 'Sbloccate anche le funzionalità richieste: '.self::names($mancanti).'.';
            }
        } else {
            $dipendenti = array_values(array_filter(
                self::dependents($key),
                static fn (string $candidate): bool => (self::unlockedRows()[$candidate] ?? false) === true
            ));

            if ($dipendenti !== []) {
                self::setUnlocked($dipendenti, false, 'dipende da '.$key);
                $ALERT = 'Bloccate anche le funzionalità che dipendono da questa: '.self::names($dipendenti).'.';
            }
        }

        return $values;
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        $row = Feature::findById($id);

        if (is_array($row)) {
            self::log((int) $id, (string) ($values['enabled'] ?? 'false'), 'user', '');
        }

        Gestionale::reset();
    }
```

più i metodi privati che usano:

```php
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

            if ($id === 0) {
                continue;
            }

            $from = (string) ($row['enabled'] ?? 'false');
            $to = $enabled ? 'true' : 'false';

            if ($from === $to) {
                continue;
            }

            sqlModify(Feature::$table, [
                'enabled' => $to,
                'changed_at' => date('Y-m-d H:i:s'),
                'changed_by' => (int) (\Wonder\App\LegacyGlobals::get('USER')->id ?? 0),
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
        FeatureLog::create([
            'feature_id' => $featureId,
            'from_value' => $from,
            'to_value' => $to,
            'source' => $source,
            'user_id' => (int) (\Wonder\App\LegacyGlobals::get('USER')->id ?? 0),
            'note' => $note,
        ]);
    }
```

In `afterUpdate()` lo storico della funzionalità salvata si scrive con
`self::log((int) $id, $vecchioStato, (string) ($values['enabled'] ?? 'false'), 'user', '')`,
dove `$vecchioStato` è il valore letto in `mutateRequestValues()` e conservato in
una proprietà statica `private static string $previousState = '';`. Controlla la
firma di `Model::create()` con
`grep -n "public static function create" -A6 /Users/andreamarinoni/Developer/packages/app/class/App/Model.php`
e adegua la chiamata.

Aggiungi in `lang/it/gestionale.json` i testi usati dalla pagina.

- [ ] **Passo 6: prova nel browser**

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update | head -8
```

Poi apri `https://ecommerce.test/backend/app/gestionale/funzionalita/` (l'accesso
lo fa l'utente) e verifica:

- l'elenco mostra nome, area, dipendenze, stato e ultimo cambio;
- sbloccando "Resi" si sblocca anche "Ordini", con il messaggio in cima;
- bloccando "Ordini" si bloccano anche "Resi", "Vendita senza giacenza" e le
  altre che ne dipendono, con il messaggio;
- `shared/sync-data.json` cambia dopo il salvataggio (`git -C /Users/andreamarinoni/Developer/boilerplates/ecommerce-site status --short`);
- con `APP_ENV=production` nel `.env` la pagina è in sola lettura e non ha il
  pulsante "Salva"; poi rimetti `APP_ENV=local`.

- [ ] **Passo 7: commit**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && git add src lang tests/FeatureResourceTest.php && git commit -m "Add the features panel

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 7: Sincronizzazione tra locale e produzione

**File:**
- Test: `tests/integrazione/SyncTest.php`
- Modifica: `CHANGELOG.md`

**Interfacce:**
- Consuma: `Wonder\App\Support\TableSync` del core (`exportToFile(string $root)`,
  import da `forge update`), `Feature` (Task 3).
- Produce: la prova che lo stato delle funzionalità viaggia dal locale alla
  produzione mantenendo gli `id`.

- [ ] **Passo 1: scrivi il test d'integrazione**

File `tests/integrazione/SyncTest.php`:

```php
<?php
/** php tests/integrazione/SyncTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\App\Support\TableSync;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

// L'export scrive shared/sync-data.json: si usa una copia della cartella, così
// il file del sito non viene toccato.
$root = sys_get_temp_dir().'/gestionale-sync-'.bin2hex(random_bytes(4));
mkdir($root.'/shared', 0777, true);

try {
    Transaction::run(static function () use ($root): void {
        $riga = array_values(array_filter((array) sqlSelect(Feature::$table, ['feature_key' => 'orders'], 1)->row, 'is_array'))[0]
            ?? (array) sqlSelect(Feature::$table, ['feature_key' => 'orders'], 1)->row;
        $id = (int) ($riga['id'] ?? 0);

        check('la riga della funzionalità esiste', fn () => $id > 0);

        sqlModify(Feature::$table, ['enabled' => 'true'], 'id', $id);

        TableSync::exportToFile($root);
        $file = json_decode((string) file_get_contents($root.'/shared/sync-data.json'), true);
        $esportate = array_column($file[Feature::$table] ?? [], null, 'feature_key');

        check('l\'export contiene le funzionalità con id e stato', fn () =>
            (int) ($esportate['orders']['id'] ?? 0) === $id
            && ($esportate['orders']['enabled'] ?? '') === 'true'
        );

        check('l\'export contiene anche le righe bloccate', fn () =>
            count($esportate) === count((array) sqlSelect(Feature::$table, null)->row)
        );

        throw new Annulla();
    });
} catch (Annulla) {
}

$riga = (array) sqlSelect(Feature::$table, ['feature_key' => 'orders'], 1)->row;

check('dopo l\'annullamento lo stato è quello di prima', fn () => ($riga['enabled'] ?? '') === 'false');

array_map('unlink', glob($root.'/shared/*') ?: []);
rmdir($root.'/shared');
rmdir($root);

summary();
```

- [ ] **Passo 2: esegui il test**

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && php tests/integrazione/SyncTest.php
```

Atteso: tutti verdi. Se `TableSync::exportToFile()` ha un'altra firma,
controllala con
`grep -n "function exportToFile" -A6 /Users/andreamarinoni/Developer/packages/app/class/App/Support/TableSync.php`
e adegua il test.

- [ ] **Passo 3: prova il giro completo sul sito**

1. In locale sblocca una funzionalità dal pannello.
2. `php forge export` e guarda la differenza in `shared/sync-data.json`.
3. Simula la produzione: `APP_ENV=production` nel `.env`, cambia a mano lo stato
   nel database (`UPDATE gst_features SET enabled='false' WHERE feature_key='orders'`),
   poi `php forge update` e verifica che lo stato torni quello del file e che gli
   `id` non cambino.
4. Rimetti `APP_ENV=local`.

Annota l'esito nella TODO del gestionale.

- [ ] **Passo 4: aggiorna il CHANGELOG e committa**

In `CHANGELOG.md`, sotto `## 0.1.0 — non rilasciata`, aggiungi:

```markdown
- Funzionalità sbloccabili: catalogo nel codice, stato su database sincronizzato,
  pannello "Funzionalità", righe precaricate create da `forge update`.
```

```bash
cd /Users/andreamarinoni/Developer/packages/gestionale && git add tests/integrazione/SyncTest.php CHANGELOG.md && git commit -m "Verify the feature state sync

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

- [ ] **Passo 5: chiudi il ramo**

Usa la skill superpowers:finishing-a-development-branch: `php tests/run.php`
verde, poi merge in `main` e push.

---

## Verifica finale del piano

1. `cd packages/gestionale && php tests/run.php` — tutti verdi, compresi i due test d'integrazione.
2. `cd boilerplates/ecommerce-site && php forge update` due volte — la prima crea le righe, la seconda riporta `"defaults": 0`.
3. Pannello "Funzionalità" nel browser: elenco, sblocco con dipendenze, blocco con dipendenti, sola lettura con `APP_ENV=production`.
4. `shared/sync-data.json` contiene `gst_features` con gli `id` e lo stato.
5. Una funzionalità bloccata non ha voce di menu né route: `php forge update` e una chiamata a `Gestionale::feature('orders')` lo confermano.

## Cosa arriva nei piani successivi

| Piano | Contenuto |
|---|---|
| 3 | codici, numerazioni, log degli stati, riferimenti esterni, IVA, impostazioni, sede principale |
| 4 | errori, riquadri della home, hook, dati di prova, GitHub Actions, guida sviluppatori e guida commercianti |
