---
icon: toggle-on
---

# Funzionalità e ruoli

Il gestionale contiene molto più di quello che un singolo commerciante usa. Le
funzionalità stanno tutte nel codice e si **sbloccano dal pannello**: finché una
funzionalità è bloccata, le sue pagine non esistono per il backend.

## Dove sta cosa

| Cosa | Dove |
|---|---|
| Catalogo delle funzionalità | `config/features.php` del pacchetto |
| Funzionalità aggiunte dal sito | `features.extra` nella configurazione del modulo |
| Funzionalità sbloccate alla prima installazione | `features.unlock` nella configurazione del modulo |
| Stato | tabella `gst_features`, storico in `gst_feature_logs` |
| Pannello | Set Up → Funzionalità, solo `admin` |

Una voce del catalogo ha chiave, nome, descrizione, area, dipendenze
(`requires`) e, per le funzionalità dell'ecommerce, il modulo richiesto
(`module`).

```php
'returns' => [
    'name' => 'Resi',
    'description' => 'Richiesta, approvazione, motivo e ricarico a magazzino.',
    'area' => 'Vendite',
    'requires' => ['orders'],
    'release' => 'G4',
],
```

## Stato effettivo

Una funzionalità è attiva solo se è sbloccata, **tutte** le sue dipendenze sono
attive e il modulo richiesto è abilitato. Si legge sempre così:

```php
if (Gestionale::feature('orders')) { … }
```

Il calcolo si fa una volta per richiesta. `Gestionale::reset()` lo azzera (serve
nei test e dopo un salvataggio del pannello).

## Pagine legate a una funzionalità

Ogni Resource del modulo dichiara la funzionalità che la governa:

```php
final class OrderResource extends GestionaleResource
{
    public static string $feature = 'orders';
    public static string $docsPage = 'ordini';
}
```

Con la funzionalità bloccata spariscono voce di menu, pagine e API: il router
non registra nemmeno le route. Un test di convenzione controlla che nessuna
Resource con dati resti senza `$feature`.

## Sblocco e blocco

Il pannello è una pagina sola con un interruttore per funzionalità, raggruppati
per area. Al salvataggio valgono due regole:

- **sbloccando** una funzionalità si sbloccano anche le dipendenze che mancano;
- **bloccando** una funzionalità si bloccano anche quelle che dipendono da lei.

Il messaggio dopo il salvataggio dice cosa è cambiato, e ogni cambio finisce in
`gst_feature_logs` con autore, valore precedente e nuovo, e la nota
"dipendenze" quando il cambio è arrivato di conseguenza.

Bloccare **non cancella mai i dati**: risbloccando si ritrova tutto.

## Ambienti

`gst_features` si sincronizza con `id` stabili e si modifica **solo in locale**:
in produzione il pannello è in sola lettura e un salvataggio forzato riceve 403.
Lo stato viaggia con `shared/sync-data.json` al deploy.

## Ruoli

| Ruolo | Chi | Cosa fa |
|---|---|---|
| `admin` | Wonder Image | sblocca le funzionalità, configura la parte tecnica |
| `administrator` | commerciante | usa solo le funzionalità attive |
