# Wonder Gestionale

Modulo per `wonder-image/app` con catalogo, magazzino, anagrafiche, vendite,
fatturazione e spedizioni. Le funzionalità si sbloccano dal pannello: un sito
installa il modulo e usa solo ciò che gli serve.

## Installazione in sviluppo

Nel `composer.json` del sito:

```json
"repositories": [ { "type": "path", "url": "../../packages/gestionale", "options": { "symlink": true } } ],
"require": { "wonder-image/gestionale": "@dev" }
```

Poi `composer update wonder-image/gestionale` e, in `custom/config/modules.php`:

```php
return [ 'gestionale' => [ 'enabled' => true ] ];
```

## Test

```bash
php tests/run.php
```

## Documentazione

- Guida sviluppatori: `docs/`
- Guida commercianti: `guide/`
- Architettura e spec dei sotto-progetti: `docs/superpowers/`
