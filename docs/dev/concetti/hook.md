---
icon: plug
---

# Hook del sito

Un sito può agganciarsi al gestionale senza toccarne il codice: scrive una
classe, la dichiara nella configurazione del modulo e riscrive solo i metodi
che gli servono.

```php
// custom/class/Gestionale/NotificheMagazzino.php
final class NotificheMagazzino extends Wonder\Plugin\Gestionale\Extensions\GestionaleExtension
{
    public function onStatusChanged(string $entity, int $entityId, string $field, string $from, string $to): void
    {
        // avvisa il capo magazzino
    }
}
```

```php
// custom/config/modules/gestionale.php
'extensions' => [
    App\Gestionale\NotificheMagazzino::class,
],
```

## Hook di G1

| Hook | Quando | Può bloccare |
|---|---|---|
| `onStatusChanged($entity, $entityId, $field, $from, $to)` | dopo un cambio di stato registrato da `StatusLogger` | no |
| `beforeEmailSend($key, $message): array` | prima di mandare un'email; torna il messaggio, anche cambiato | no |

Gli altri hook nascono con il sotto-progetto che li usa.

### Le email che passano da `beforeEmailSend`

| Chiave | Email | Messaggio |
|---|---|---|
| `stock.low_stock` | i prodotti sotto la scorta minima | `['to' => list<string>, 'subject' => string, 'body' => string]` |

- `to` può tornare come lista o come stringa separata da virgole: il gestionale
  lo rilegge e scarta gli indirizzi non validi.
- Un `to` vuoto **ferma l'invio**, e gli avvisi contano come mandati: è una
  scelta del sito, non un guasto.
- Un `to` fatto solo di indirizzi non validi ferma l'invio allo stesso modo, ma
  lascia una segnalazione per lo sviluppatore (`error_reports`) con gli
  indirizzi scartati: somiglia più a uno sbaglio che a una scelta.
- Un'estensione che solleva un'eccezione finisce nel log e l'email parte con il
  messaggio com'era prima di lei.

```php
public function beforeEmailSend(string $key, array $message): array
{
    if ($key === 'stock.low_stock') {
        $message['to'][] = 'magazzino@example.com';
    }

    return $message;
}
```

## Regole

- Le estensioni girano **nell'ordine in cui sono dichiarate**.
- Una classe che non esiste, o che non estende la base, viene saltata: la
  configurazione di un sito non deve poter rompere il gestionale.
- Un hook che solleva un'eccezione finisce nel log e **non ferma** il flusso:
  gli hook `on…` arrivano a operazione già fatta, tirarla indietro sarebbe
  peggio.
- Gli hook `before…` che arriveranno potranno fermare l'operazione sollevando un
  `UserError`.
- `Extensions::run()` avvisa e basta; `Extensions::filter()` fa passare un
  valore da un'estensione all'altra.

## Riquadri dei moduli nelle Impostazioni

Un modulo aggiunge un riquadro alle Impostazioni di Set Up senza toccare il
gestionale: il suo entrypoint (quello del `module.json`) implementa
`ProvidesSettings` e dà una o più `SettingsSection`.

```php
final class Ecommerce implements ModuleInterface, ProvidesSettings
{
    public static function settingsSections(): iterable
    {
        yield new OnlineShopSettings();
    }
}
```

Una `SettingsSection` dice colonne (`columns()`) e dati (`data()`) che entrano
nella riga di `gst_settings`, campi (`fields()`) ed etichette (`labels()`) della
pagina, il riquadro (`card($input)`, dove `$input('chiave')` dà il campo) e la
pulizia dei valori prima del salvataggio (`mutate()`, che li riceve tutti).

- Il riquadro sta fra «Vendite» ed «Email», nell'ordine dei moduli accesi.
- Le colonne viaggiano col deploy come le altre e in produzione si leggono e
  basta, come il resto della pagina.
- Spento il modulo, `forge update` toglie le sue colonne insieme ai valori.
- Un modulo che si rompe dando i suoi riquadri ferma la pagina e `forge update`:
  tacerlo vorrebbe dire togliere le sue colonne. Senza registro dei moduli
  (test, comandi fuori dal sito) i riquadri non ci sono.
- Nei test `SettingsSections::use([...])` forza i riquadri; `use(null)` torna a
  leggerli dai moduli.

