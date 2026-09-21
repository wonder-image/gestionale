---
icon: percent
---

# IVA, impostazioni e sedi

## Le tre tabelle dell'IVA

| Tabella | A cosa serve |
|---|---|
| `gst_taxes` | le aliquote: valore, natura per le operazioni a zero, descrizione in fattura |
| `gst_tax_categories` | il tipo fiscale del prodotto (ordinario, alimentare, libri, servizi) |
| `gst_tax_rules` | paese × tipo di cliente × tipo fiscale → aliquota |

**A cosa serve il tipo fiscale.** L'aliquota non è una proprietà del prodotto:
lo stesso libro è al 4% in Italia e a un'altra aliquota per un privato di un
altro paese. Quello che il prodotto ha davvero è *come va tassato* — beni
ordinari, alimentari, libri, servizi — e questo è il tipo fiscale. La regola
mette insieme tipo fiscale, paese e tipo di cliente e ne tira fuori l'aliquota.

Finché il tipo fiscale è uno solo la scheda prodotto non lo mostra nemmeno: si
comincia a vederlo quando il negozio vende cose tassate in modo diverso.

Il codice della regola lo compone la pagina da sé, nel formato
`{paese}-{tipo cliente}-{tipo fiscale}` (`it-private-ordinaria`): due regole
non possono chiamarsi allo stesso modo e nessuno deve inventarsi una sigla.

Si modificano solo in locale e arrivano in produzione con il deploy, con gli
`id` stabili. Le aliquote **non si eliminano**: si nascondono, perché i
documenti già emessi puntano alla loro.

Le righe precaricate sono le quattro aliquote italiane visibili (22, 10, 5, 4) e
una riga nascosta per ogni natura valida, il tipo fiscale "Aliquota ordinaria" e
le due regole italiane, privato e azienda, al 22%.

## Scegliere l'aliquota e fare i totali

```php
$taxId = TaxResolver::resolve($regole, 'IT', 'private', $tipoFiscaleId, $ripiego);
$riepiloghi = TaxTotals::summaries($righe, $prezziIvaInclusa);
```

Due classi pure, senza database. `TaxResolver` vuole una corrispondenza esatta:
se la combinazione non c'è, torna l'aliquota di ripiego delle impostazioni.

`TaxTotals` calcola l'imposta **sul totale imponibile di ogni aliquota, non riga
per riga**: è la regola dei riepiloghi FatturaPA. Con i prezzi IVA inclusa
l'imposta si scorpora, così il cliente paga sempre la cifra esposta e cambia
solo l'imponibile. Ogni riepilogo ha aliquota, natura, imponibile, imposta e
totale: sono le righe che i sotto-progetti salveranno accanto a ordini e
fatture.

## Le due righe di impostazioni

| Tabella | Chi la scrive | Dove | Sync |
|---|---|---|---|
| `gst_settings` | `admin` | in locale | sì, riga unica |
| `gst_merchant_settings` | `administrator` | dove si lavora | mai |

La regola è questa: la configurazione tecnica e fiscale, quella che si decide
una volta con il commercialista, sta in `settings`; le scelte quotidiane del
commerciante stanno nelle sue. Ogni sotto-progetto aggiunge le proprie colonne
alla riga giusta.

Salvare la pagina fiscale scrive `fiscal_confirmed_at`: i valori precaricati
vanno bene per partire, ma i Primi passi devono sapere che una persona li ha
guardati.

## Sedi

La sede resta una sola cosa in due tabelle: nome, indirizzo, contatti, dati
legali, orari e chiusure in `society_locations` del core, giacenza, ritiro e
banco in `gst_locations`. Nessuna copia di dati tra le due.

La pagina "Sedi" del gestionale **estende** quella del core e si registra con lo
stesso percorso: il `ResourceRegistry` preferisce la Resource del modulo, quindi
il commerciante continua a vedere una voce sola, con in più il riquadro
Magazzino. "Punto di ritiro" compare solo con la funzionalità `shipping`, "Banco"
solo con `pos`.

Fuori dal locale la scheda è in sola lettura tranne orari e chiusure
(`editableWhenReadonly()`): sono l'unica cosa che il commerciante deve poter
correggere subito. Una sede collegata al magazzino non si elimina: si disattiva.
