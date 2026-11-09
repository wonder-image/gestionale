---
icon: people
---

# Persone, aziende e fornitori

> **Inclusa.** I clienti ci sono sempre. I **fornitori** compaiono solo con la
> funzionalità *Acquisti* sbloccata.

## Persone e aziende

La rubrica ha due tipi di scheda:

- **Persone** — chi compra per sé, i referenti delle aziende, chi ha un
  accesso al sito. Solo una persona può avere un account.
- **Aziende** — le ditte a cui vendi o da cui compri, con ragione sociale,
  partita IVA, SDI e PEC. Un'azienda esiste anche senza nessuna persona.

Persone e aziende si collegano fra loro (vedi *Referenti e aziende* sotto):
la stessa persona può lavorare per più aziende e un'azienda può avere più
referenti. Non c'è più un campo *Privato o azienda*: chi è un'azienda ha la sua
scheda fra le **Aziende**.

I **fornitori** sono sempre aziende. La stessa azienda a cui vendi e da cui
compri è **una scheda sola**: la apri e scegli **Ruolo → Cliente e fornitore**.
Da lì compare in tutti e due gli elenchi e si modifica in un posto solo.

Il campo **Ruolo** ha tre risposte — *Cliente*, *Fornitore*, *Cliente e
fornitore* — e nasce già sul ruolo dell'elenco da cui hai premuto *Aggiungi*.
Se lo cambi, la scheda **si sposta**: un cliente che diventa solo fornitore
esce dall'elenco dei clienti e lo trovi fra i fornitori. Senza la funzionalità
*Acquisti* il campo non c'è: i fornitori non esistono e ogni scheda è di un
cliente.

Per questo partita IVA, codice fiscale ed email sono **unici**: se li riscrivi
su una scheda nuova, il gestionale ti dice di chi sono già e ti lascia aprire
quella invece di crearne una seconda. Partita IVA e codice fiscale li controlla
il gestionale: se sono sbagliati, il salvataggio si ferma e te lo dice. Lo
stesso vale per l'email, di cui viene controllato anche il dominio.

Un fornitore scritto fra i **fornitori** di un articolo, o di una sua
opzione, non smette di essere fornitore: da *Fornitore* o *Cliente e
fornitore* non passa a *Cliente*. Se ci provi il salvataggio si ferma e ti
dice su quanti articoli o opzioni in vendita compare. Prima toglilo dai loro
fornitori (vedi [Da chi lo compri e a
quanto](catalogo-prodotti.md#da-chi-lo-compri-e-a-quanto)), oppure lascialo
fornitore e metti la scheda su **Non attiva** (vedi sotto).

## Chi è il cliente di un documento

Su preventivi, ordini, DDT, abbonamenti e resi il cliente è **una persona** o
**un'azienda**:

- con una **persona** valgono il suo listino e i suoi pagamenti, e la fattura
  è da privato (o da azienda se la persona ha una partita IVA sua);
- con un'**azienda** valgono il **listino e le condizioni di pagamento
  dell'azienda**, la fattura è sempre all'azienda e la persona scelta è il suo
  **referente** (*Alla c.a.* sul PDF, in copia sulle email).

Nei documenti fatti prima di questa versione, i clienti che erano aziende sono
stati spostati sulle schede delle **Aziende**: il loro referente, se c'era,
resta la persona del documento.

## Dati di fatturazione

Sia le persone sia le aziende hanno i **dati di fatturazione**: una persona
può avere il suo codice fiscale e il suo indirizzo, un'azienda ha i suoi.
Online, chi compra sceglie se fatturare a sé o a una delle sue aziende.

## Indirizzi di consegna

L'indirizzo della scheda è quello **di fatturazione**. Dove si consegna si
scrive nel riquadro *Indirizzi di consegna*: uno per il magazzino, uno per
casa, uno per il cantiere. Il **destinatario** è chi il corriere deve cercare,
e spesso non è chi compra.

Gli indirizzi si gestiscono anche dalla **scheda del cliente** (il nome nell'elenco
la apre): il riquadro *Indirizzi di consegna* mostra una card per indirizzo, con
**Modifica**, **Rendi predefinito** e il cestino per eliminarlo, e **Aggiungi
indirizzo** in fondo. Ogni gesto apre una finestra; non serve passare dal form
di modifica. Uno solo è il **predefinito** e il primo che scrivi lo diventa; se
elimini il predefinito, passa al primo rimasto. Un indirizzo eliminato esce dalla
scheda ma non dagli ordini già fatti, che ne conservano una copia.

## La scheda del cliente

In alto le **statistiche** e, a destra, i **dati di fatturazione** (codice
fiscale, e per un'azienda partita IVA, SDI e PEC, più l'indirizzo). Sotto, **tutti
i suoi dati** — nome o ragione sociale, ruolo, stato, email, telefono, accesso al
sito (solo per le persone) e note — e gli **indirizzi di consegna**. La scheda di
un'azienda elenca anche i suoi **referenti**. Poi gli ordini, il carrello, gli
**abbonamenti** (con la funzionalità *Abbonamenti*) e, in fondo, i coupon.

## La scheda del fornitore

Dall'elenco dei fornitori il nome apre la sua scheda, come per i clienti:
**tutti i suoi dati**, i **dati di fatturazione**, gli ultimi **carichi**
ricevuti da lui (il numero apre il carico) e gli **articoli forniti**, con il
suo codice e il costo. Per cambiare qualcosa c'è **Modifica** in alto.

## Referenti e aziende

Un'azienda ha spesso più persone con cui parli: chi ordina, chi paga, chi
riceve la merce. Nel form di un'**azienda** il riquadro **Referenti** elenca queste persone;
nel form di una **persona** il riquadro **Aziende** elenca le aziende per cui
lavora. La stessa persona può essere referente di più aziende ed è sempre
**una scheda sola**, con un solo accesso al sito.

Per ogni referente scegli la sua scheda, oppure scrivi nome, cognome ed email
di una persona nuova: la scheda nasce da sola, senza essere né cliente né
fornitore. Poi:

- **Mansione** — per esempio *Acquisti* o *Amministrazione*;
- **Referente principale** — è il referente proposto quando fai un preventivo,
  un ordine da ufficio o un DDT per quell'azienda;
- **Riceve i documenti** — solo chi ha questa risposta si può scegliere sui
  documenti e riceve in copia le loro email.

Nell'elenco dei clienti il filtro **Referenti** mostra solo queste persone o le
nasconde, e la colonna **Referenti / aziende** dice chi è legato a chi; lo dice
anche l'export. Togliere un referente dal riquadro non elimina la sua scheda e
non cambia i documenti già fatti, che ne conservano il nome.

## Eliminare o disattivare

Una scheda con un **account sul sito** non si elimina: chi ha le credenziali
resterebbe senza scheda. E appena ci saranno ordini, varrà lo stesso per chi ha
comprato. La strada giusta è metterla su **Non attiva**: sparisce dal lavoro di
ogni giorno e la storia resta.

Lo stesso per un **fornitore scritto su articoli ancora in catalogo**, o su
una loro opzione: eliminandolo, perderebbero da chi li compri e a quanto. Il
gestionale lo rifiuta e ti dice su quanti articoli o opzioni compare, anche
quando la funzionalità *Acquisti* è spenta: codici e costi restano salvati e
li ritrovi quando la riaccendi. Gli articoli che hai già eliminato non contano.

Un fornitore **Non attivo** non si propone più a chi non lo usa, ma dove è
già scelto resta al suo posto, con scritto *(non attivo)* accanto al nome: il
suo codice e il suo costo non si perdono. Se invece vuoi proprio eliminarlo,
toglilo prima dai fornitori di quegli articoli e di quelle opzioni.

## Nella home

Il riquadro **Anagrafiche** dice quante persone e aziende ci sono e porta agli elenchi.
# Storage condiviso

Le schede contatto e gli indirizzi di consegna (`contacts`, `contact_addresses`)
sono di `wonder-image/customer-account`; il riferimento esterno
(`external_references`) è di `wonder-image/app`. I namespace del gestionale
restano compatibili e aggiungono le opzioni commerciali del contatto. Forge
update rinomina i precedenti nomi `gst_*` prima di allineare gli schemi,
conservando ID, dati e collegamenti. Aggiornare app, customer-account e
gestionale insieme. Se entrambi i nomi sono già presenti la migrazione si
ferma, senza cancellazioni automatiche.
