---
icon: toggle-on
---

# Funzionalità incluse e da attivare

> **Configurata da Wonder Image.** Le funzionalità si sbloccano in fase di
> configurazione: dal tuo pannello in produzione la pagina si vede ma non si
> modifica.

## A cosa serve

Il gestionale contiene molte funzioni: ordini, resi, magazzino su più sedi,
lotti e scadenze, listini, coupon, fatturazione elettronica, spedizioni. Non
sono tutte accese: il tuo pannello mostra solo quelle che ti servono, così resta
semplice.

La pagina **Set Up → Funzionalità** elenca tutto quello che il gestionale sa
fare, diviso per area, con una riga di spiegazione sotto ogni voce.

## Come leggere la pagina

- **Interruttore acceso:** la funzione è attiva e trovi le sue pagine nel menu.
- **Interruttore spento:** la funzione esiste ma non è attiva. Nessun dato viene
  perso quando resta spenta.
- **"Richiede: …":** per accendere quella funzione ne serve un'altra. Per
  esempio i Resi richiedono gli Ordini.
- **Interruttore grigio:** serve un modulo che il tuo sito non ha, per esempio il
  negozio online.

## Tutte le funzionalità

<!-- funzionalita:inizio -->

| Funzionalità | Area | A cosa serve | Serve prima |
|---|---|---|---|
| Ordini | Vendite | Gestione degli ordini con stati e scarico del magazzino. | — |
| Preventivi | Vendite | Preventivi con PDF e revisioni, convertibili in ordine. | Ordini |
| Resi | Vendite | Richiesta, approvazione, motivo e ricarico a magazzino. | Ordini |
| DDT | Vendite | Documento di trasporto per le consegne. | Ordini |
| Abbonamenti | Vendite | Piani, rinnovi e cambio piano. | — |
| Multiprodotto | Catalogo | Prodotti composti da altri prodotti: vendendoli si scaricano i componenti. | — |
| Personalizzazione | Catalogo | Campi compilati al momento della vendita, con eventuale sovrapprezzo. | Ordini |
| Etichette | Catalogo | Etichette con codice a barre e prezzo in PDF. | — |
| Più sedi | Magazzino | Altre sedi con giacenze proprie e trasferimenti. | — |
| Acquisti | Magazzino | Costi d'acquisto per fornitore, documenti di carico, valore del magazzino. | — |
| Lotti e scadenze | Magazzino | Lotto e data di scadenza su carichi e scarichi. | — |
| Avvisi di scorta minima | Magazzino | Email e riquadro per i prodotti sotto la propria scorta minima. | — |
| Vendita senza giacenza | Magazzino | Prodotti vendibili a magazzino vuoto: la giacenza va sotto zero e la vendita è "su ordinazione". | Ordini |
| Listini cliente | Listini | Prezzi personalizzati per cliente, con scaglioni. | Ordini |
| Sconto massivo | Promozioni | Campagne per categoria, tag, brand o modello, con data e ora, in € o %. | Ordini |
| Coupon | Promozioni | Importo, percentuale, buono a scalare, spedizione gratuita, con limiti. | Ordini |
| Fatturazione elettronica | Fatturazione | Fatture, invio SDI, coda, stati e notifiche. | — |
| Fattura differita | Fatturazione | Fattura riepilogativa dei DDT del periodo. | DDT, Fatturazione elettronica |
| Spedizioni | Spedizioni | Listini per zona e peso, spedizioni e tracking manuali, ritiro in sede. | Ordini |
| Corrieri | Spedizioni | Etichette e tracking dal corriere. | Spedizioni |
| Banco | Banco | Vendita in sede con documento commerciale e corrispettivi. | Ordini |
<!-- funzionalita:fine -->

## Cosa accendono oggi Acquisti e Vendita senza giacenza

Alcune funzionalità arrivano un pezzo alla volta: la tabella dice a cosa
serviranno da complete, qui c'è quello che trovi già accendendole.

**Acquisti**

- Il ruolo **Fornitore** nella rubrica e l'elenco **Anagrafiche → Fornitori**
  (vedi [Clienti e fornitori](anagrafiche.md)).
- Il **costo d'acquisto** di ogni opzione in vendita: da chi la compri, con
  quale codice e a quanto. Si scrive nella scheda dell'articolo e nel riquadro
  **Fornitori** della pagina dell'opzione (vedi [Da chi lo compri e a
  quanto](catalogo-prodotti.md#da-chi-lo-compri-e-a-quanto)).

Documenti di carico e valore del magazzino arriveranno dopo, e partiranno da
questi costi. Spegnendola, fornitori e costi spariscono dalle pagine ma restano
salvati: un fornitore che ha dei costi, per esempio, non si elimina lo stesso.

**Vendita senza giacenza** (richiede gli *Ordini*)

- L'interruttore **Vendita senza giacenza** nel riquadro **Come si vende** di
  ogni articolo, con i **Giorni di attesa** (vedi [Stato, come si vende e tipo
  fiscale](catalogo-prodotti.md#stato-come-si-vende-e-tipo-fiscale)).
- La giacenza che va **sotto zero**, ma solo sugli articoli con l'interruttore
  acceso: gli altri restano come prima (vedi [Sotto
  zero](magazzino-giacenze.md#sotto-zero)).

Spegnendola l'interruttore sparisce e nessuna giacenza va più sotto zero;
riaccendendola, ogni articolo lo ritrova com'era.

## Se ti serve una funzione in più

Scrivici: attivarla è questione di minuti e non richiede di rifare niente. Se la
funzione ha bisogno di altre funzioni, si accendono insieme.

## Se una funzione non ti serve più

Si può spegnere: le pagine spariscono dal menu e i dati restano dove sono. Se
un domani la riaccendi, ritrovi tutto com'era.
