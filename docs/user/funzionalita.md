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
| [Clienti privati](anagrafiche.md#con-chi-lavora-il-gestionale) | Anagrafiche | Si vende anche alle persone, senza azienda. Spenta, il gestionale lavora solo con aziende: le persone sono i loro referenti, l'elenco si chiama «Referenti» e un cliente privato non si crea più. Le schede già salvate restano. | — |
| [Aziende](anagrafiche.md#con-chi-lavora-il-gestionale) | Anagrafiche | Clienti con partita IVA e i loro referenti (con le aree di cui si occupano); con «Brand» e «Gruppi di aziende» anche brand e capogruppo. Spenta, il gestionale lavora solo con persone: aziende e referenti spariscono da menu, schede e ordini, e i dati restano. I fornitori restano con gli Acquisti. | — |
| [Brand](anagrafiche.md#brand) | Anagrafiche | Il nome commerciale delle aziende, anche condiviso fra più ragioni sociali: voce «Brand» in Anagrafiche, brand nella scheda dell'azienda, nei preventivi, negli ordini e nei documenti stampati, brand seguiti dai referenti e sedi per brand. Spenta, i brand non si vedono e restano salvati, anche sui documenti. Si accende da sola dove i brand ci sono già. | Aziende |
| [Brand kit](anagrafiche.md#brand) | Anagrafiche | Nella scheda di un brand, oltre a logo, sito e descrizione: email e telefono, social, colori, tono, settore, lingua e il link ai materiali. Spenta, quei campi non si vedono e restano salvati. | Brand |
| [Gruppi di aziende](anagrafiche.md#gruppi-di-aziende) | Anagrafiche | Nella scheda di un'azienda il campo «Capogruppo»: l'azienda a capo del gruppo di cui fa parte, su un livello solo. Spenta, il campo non si vede e la capogruppo già scelta resta salvata. | Aziende |
| Ordini | Vendite | Gestione degli ordini con stati e scarico del magazzino. | — |
| Preventivi | Vendite | Preventivi con PDF e revisioni: accettato, il preventivo diventa un ordine da ufficio. | Ordini, Vendita in ufficio |
| Resi | Vendite | Richiesta, approvazione, motivo e ricarico a magazzino. | Ordini |
| DDT | Vendite | Documento di trasporto per le consegne, con i lotti e il PDF. | Ordini |
| [Commerciali](commerciali.md) | Vendite | Ogni commerciale ha il suo accesso e lavora solo sui suoi clienti: li crea e li modifica, fa preventivi e ordini da ufficio. L'ufficio assegna i clienti e filtra gli elenchi per commerciale. | Ordini, Vendita in ufficio |
| Rete di commerciali | Vendite | Commerciali sotto altri commerciali, su quanti livelli servono: ognuno vede anche i clienti e i documenti della sua rete. | Commerciali |
| Provvigioni | Vendite | Percentuale per commerciale con eccezioni per categoria e prodotto, maturata su pagato o evaso, stornata sui resi, con estratti conto ed export. | Commerciali |
| Provvigioni di rete | Vendite | Chi sta sopra prende una provvigione sulle vendite della sua rete, per livello o per differenza. | Rete di commerciali, Provvigioni |
| Sconto massimo dei commerciali | Vendite | Un ordine con uno sconto oltre il limite del commerciale resta da approvare finché l'ufficio non lo conferma. | Commerciali |
| Listini dei commerciali | Vendite | L'ufficio assegna i listini a ogni commerciale: lui li applica ai suoi clienti, senza cambiare i prezzi. | Commerciali, Listini cliente |
| Codici dei commerciali | Vendite | Ogni commerciale ha i suoi codici sconto: un ordine sul sito con il suo codice gli viene attribuito. | Commerciali, Coupon |
| Obiettivi dei commerciali | Vendite | Obiettivi mensili e annuali per commerciale, con l'avanzamento e le statistiche del venduto. | Commerciali |
| Prodotto | Catalogo | Prodotti singoli nel catalogo. Spento, in Catalogo → Prodotti restano multiprodotti e abbonamenti. | — |
| Abbonamenti | Catalogo | Piani, rinnovi e cambio piano: il tipo Abbonamento in Catalogo → Prodotti. | — |
| Multiprodotto | Catalogo | Prodotti composti da altri prodotti: vendendoli si scaricano i componenti. | Prodotto |
| Personalizzazione | Catalogo | Campi compilati al momento della vendita, con eventuale sovrapprezzo, sui prodotti e sui piani degli abbonamenti. | Ordini o Abbonamenti |
| Articoli suggeriti | Scheda prodotto | Articoli da comprare insieme, scelti a mano nella scheda: il sito li propone nella scheda e nel carrello. | — |
| Articoli alternativi | Scheda prodotto | Articoli da proporre al posto di un altro, scelti a mano nella scheda. | — |
| Varianti | Scheda prodotto | Articoli in più versioni (taglia, colore…) con prezzo, codice e giacenza per versione. | — |
| Misure | Scheda prodotto | Unità di misura, peso e dimensioni dell'articolo. Spenta, il peso conta come non inserito. | — |
| Aliquote | Scheda prodotto | Tipo fiscale scelto per articolo. Spenta, ogni articolo usa il tipo fiscale predefinito. | — |
| Scheda tecnica | Scheda prodotto | Caratteristiche dell'articolo e delle sue opzioni (materiale, simboli di lavaggio…). Spenta, la scheda non le chiede, la voce Catalogo › Attributi sparisce e quelle già scritte restano salvate. | — |
| FAQ | Catalogo | Domande frequenti con la risposta, nella scheda dei prodotti scelti e nella home. Spenta, il sito non le mostra. | — |
| [Etichette](catalogo-etichette.md) | Catalogo | Etichette con codice a barre e prezzo in PDF. | — |
| Più sedi | Magazzino | Altre sedi con giacenze proprie e trasferimenti. | — |
| Acquisti | Magazzino | Fornitori e costi d'acquisto nei prodotti e nei carichi, valore del magazzino. | — |
| Lotti e scadenze | Magazzino | Lotto e scadenza nei carichi, vendita dal lotto che scade prima, pagina Lotti con i clienti per i richiami. | — |
| Avvisi di scorta minima | Magazzino | Email e riquadro per i prodotti sotto la propria scorta minima. | — |
| Vendita senza giacenza | Magazzino | Prodotti vendibili a magazzino vuoto: la giacenza va sotto zero e la vendita è "su ordinazione". | Ordini |
| Listini cliente | Listini | Prezzi personalizzati per cliente, con scaglioni. | Ordini |
| Sconto massivo | Promozioni | Campagne per categoria, tag, brand o modello, con data e ora, in € o %. | Ordini |
| Coupon | Promozioni | Importo, percentuale, buono a scalare, spedizione gratuita, con limiti. | Ordini |
| Fatturazione elettronica | Fatturazione | Fatture, invio SDI, coda, stati e notifiche. | — |
| Fattura differita | Fatturazione | Fattura riepilogativa dei DDT del periodo. | DDT, Fatturazione elettronica |
| Spedizioni | Spedizioni | Listini per zona e peso, spedizioni e tracking manuali, ritiro in sede. | Ordini |
| Imballaggi | Spedizioni | Le scatole in cui si spedisce, con il loro peso, da scegliere nella scheda prodotto. | Spedizioni |
| Corrieri | Spedizioni | Etichette e tracking dal corriere. | Spedizioni |
| Vendita online | Canali di vendita | Il sito vende: coupon, campagne e metodi di pagamento valgono per il sito. | Ordini |
| [Account aziendali](anagrafiche.md#comprare-sul-sito-per-unazienda) | Anagrafiche | Sul sito la persona compra anche per le aziende a cui è collegata, con i dati di fatturazione, il listino e le condizioni dell'azienda; chi la gestisce vede ordini e abbonamenti di tutti. | Vendita online, Aziende |
| Vendita in ufficio | Canali di vendita | Gli ordini si fanno dal gestionale: coupon, campagne e metodi di pagamento valgono anche per l'ufficio; le rate si seguono nello Scadenzario. | Ordini |
| Vendita in cassa | Canali di vendita | Vendita in sede con documento commerciale e corrispettivi. | Ordini |
| Server MCP | Integrazioni | Collega gli assistenti IA (Claude, ChatGPT, Cursor) a prodotti, prezzi, giacenze, categorie, ordini e preventivi, con i permessi del backend. | — |
| Esportazione prodotti | Esportazioni | Scarica i prodotti in Excel (.xlsx) o CSV (.csv): una riga per opzione, intestazioni in italiano. | — |
| Caricamento del catalogo | Importazioni | Carica prodotti, prezzi, giacenze e foto da un file Excel o CSV dalla scheda **Importa** del pulsante **File**; l'ordine delle colonne si salva in Dev → File → Tracciati file, e un tracciato salvato si sceglie anche nella scheda **Esporta** del pulsante **File**. | — |
| Esportazione contatti | Esportazioni | Scarica clienti e fornitori in Excel (.xlsx) o CSV (.csv): anagrafica, recapiti e fatturazione, celle come testo. | — |
| Catalogo Meta | Esportazioni | Feed a indirizzo fisso per il catalogo di Facebook e Instagram (.csv con i campi di Meta): indirizzo, ultimo aggiornamento e «Scarica ora» nella scheda **Feed prodotti** del pulsante **File**. | — |
| Google Merchant Center | Esportazioni | Feed a indirizzo fisso per Google Merchant Center (.xml RSS con i campi g:): indirizzo, ultimo aggiornamento e «Scarica ora» nella scheda **Feed prodotti** del pulsante **File**. | — |
| TikTok Shop | Esportazioni | Sincronizzazione del catalogo con TikTok Shop. | — |
| Amazon | Esportazioni | Sincronizzazione del catalogo con Amazon. | — |
<!-- funzionalita:fine -->

## Cosa accendono oggi Acquisti e Vendita senza giacenza

Alcune funzionalità arrivano un pezzo alla volta: la tabella dice a cosa
serviranno da complete, qui c'è quello che trovi già accendendole.

**Acquisti**

- Il ruolo **Fornitore** nella rubrica e l'elenco **Anagrafiche → Fornitori**
  (vedi [Clienti e fornitori](anagrafiche.md)).
- I **fornitori** di ogni opzione in vendita: da chi la compri, con quale
  codice e a quanto. Si scrivono nella scheda dell'articolo, dietro «Compila
  le informazioni avanzate»: due caselle se hai un fornitore solo, il bottone
  **Fornitori** con la sua finestra se ne hai di più (vedi [Da chi lo compri e
  a quanto](catalogo-prodotti.md#da-chi-lo-compri-e-a-quanto)).

I [carichi](magazzino-carichi-scarichi.md) non chiedono Acquisti: ci sono
sempre. Fornitore e costi sui carichi, e il valore del magazzino, arriveranno
dopo e partiranno da questi costi. Spegnendola, fornitori e costi spariscono
dalle pagine ma restano salvati: un fornitore che ha dei costi, per esempio,
non si elimina lo stesso.

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

Alcune non si spengono finché servono: **Prodotto** (il box *Catalogo*) si
spegne solo quando nel catalogo non ci sono più prodotti singoli. Spento,
«+ Aggiungi» non crea più prodotti;
restano gli abbonamenti, nello stesso elenco *Catalogo → Prodotti*. Il tipo
**Abbonamento** si sceglie solo con **Abbonamenti** acceso. Anche **Multiprodotto**
ha bisogno di Prodotto.
