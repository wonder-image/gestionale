---
icon: download
---

# Installazione

Il gestionale è un modulo di `wonder-image/app`: si installa con Composer e si
abilita dalla configurazione del sito.

## Requisiti

- PHP 8.2
- `wonder-image/app` `^2.2.2`
- un database MySQL, quello del sito

## In sviluppo

Nel `composer.json` del sito, con il pacchetto preso dalla cartella locale:

```json
"repositories": [
    { "type": "path", "url": "../../packages/gestionale", "options": { "symlink": true } }
],
"require": { "wonder-image/gestionale": "@dev" }
```

```bash
composer update wonder-image/gestionale
```

## Abilitazione

In `custom/config/modules.php` del sito:

```php
<?php

    return [
        'gestionale' => [
            'enabled' => true,
        ],
    ];
```

Poi:

```bash
php forge update
```

`forge update` crea le tabelle del modulo (prefisso `gst_`) e, **solo in
locale**, le righe precaricate: una riga per ogni funzionalità, tutte bloccate.
In produzione le righe arrivano dal file `shared/sync-data.json` con il deploy.

## Controllo

```bash
php forge status:modules
```

La riga `gestionale` deve risultare abilitata e valida. Nel backend compare
**Set Up → Funzionalità**.
