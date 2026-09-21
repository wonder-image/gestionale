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
