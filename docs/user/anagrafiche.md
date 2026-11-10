---
icon: people
---

# Persone, aziende e fornitori

> **Inclusa.** I clienti ci sono sempre. I **fornitori** compaiono solo con la
> funzionalità *Acquisti* sbloccata.

## Con chi lavora il gestionale

Due interruttori di **Set Up → Funzionalità**, area *Anagrafiche*, dicono a chi
vendi:

| Clienti privati | Aziende | Il gestionale lavora con… | L'elenco delle persone si chiama |
|---|---|---|---|
| accesa | accesa | persone e aziende (di serie) | **Persone** |
| accesa | spenta | solo persone | **Clienti** |
| spenta | accesa | solo aziende | **Referenti** |

Tutte e due spente non si può: il pannello lo rifiuta.

- **Solo persone** — per chi vende a privati. Spariscono dal menu e dalle
  schede le **Aziende** e quello che è solo loro: brand, referenti,
  capogruppo, reparti, e le funzionalità *Brand*, *Gruppi di aziende* e
  *Account aziendali*, che si spengono da sole perché hanno bisogno delle
  aziende. Le pagine delle aziende non si
  aprono nemmeno dall'indirizzo. I **fornitori** restano (sono sempre
  aziende, li governa *Acquisti*), con i loro dati ma senza brand,
  capogruppo e referenti.
- **Solo aziende** — per chi vende solo a ditte. Le persone ci sono come
  **referenti** delle aziende: l'elenco si chiama *Referenti*, ogni persona
  nuova va legata a un'azienda e un cliente privato non si crea più.
- **Persone e aziende** — tutto quello che c'è in questa pagina.

Cambiare modo **nasconde e non cancella**: schede, legami, brand e documenti
restano nel database e tornano quando riaccendi l'interruttore. Un ordine già
fatto con un cliente che il modo nasconde si riapre e si salva com'è; il
cliente nuovo di un ordine, invece, deve essere del tipo con cui lavori.

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

Creando una scheda il form mostra subito chi è, i contatti, la fatturazione e
gli **indirizzi di consegna**. Per un'azienda, subito sotto i contatti c'è anche
il riquadro **Referenti**: le persone con cui parli si scrivono insieme ai
recapiti dell'azienda. Pagamento, note e, per una persona, le aziende per cui
lavora stanno nel riquadro chiuso **Altri dati (facoltativi)**: aprilo se
servono, oppure aggiungili dopo. In modifica si vede tutto.

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

## Pagamento

Nel form di un cliente (persona o azienda) il riquadro **Pagamento** tiene su
una riga quello che vale per i suoi ordini: **metodo** e **condizione di
pagamento** proposti dall'ordine da ufficio, **esigibilità IVA**, **listino** e
**commerciale**, ognuno se la sua funzionalità è accesa. Sul telefono e sugli
schermi stretti vanno uno sotto l'altro.

## Contatti

Tutte le email e i telefoni di una persona, di un'azienda o di un fornitore
stanno nel riquadro **Contatti** della scheda: una riga per contatto, con il
tipo (*Email* o *Telefono*) e **Principale**. Scegli il tipo e la riga mostra
solo il suo campo: l'email, oppure prefisso e numero (il prefisso parte da
quello del paese del sito, come la nazione negli indirizzi). L'etichetta
(*Lavoro*, *Personale*, *Fatturazione*, *Altro*) sta in **Informazioni
avanzate** della riga, chiusa finché non serve. Il principale di ogni tipo è quello
che il gestionale usa (ordini, documenti, ricerche, export): uno per tipo, e se
non ne scegli uno lo è la prima riga. Cambiando principale il vecchio resta,
come riga normale. Un'email scritta male o ripetuta ferma il salvataggio, e una
email già di un'altra scheda pure (se la scheda non è fra le tue, senza dire di
chi è).

- Una persona con un **account sul sito** ha in testa l'**Email di accesso**,
  bloccata: si cambia dal suo account, non dal gestionale (nemmeno dalle
  finestre dell'ordine, che lo dicono). Le altre email non servono mai per
  accedere.
- Una scheda che ha già un contatto non resta **senza**: l'ultima riga non si
  elimina (il cestino è spento) e un riquadro svuotato non si salva. Se
  l'ultimo contatto è sbagliato, cambialo. Una scheda nuova può nascere senza
  contatti.
- Le righe di un'**azienda** hanno anche **Si occupa di**, nelle informazioni
  avanzate della riga: le aree a cui va
  quell'email (vuoto = tutte). Così *amministrazione@* riceve proforma e
  solleciti anche senza creare un referente (vedi sotto).

La scheda in lettura mostra tutti i contatti, con il principale, l'email di
accesso e le aree. Le finestre *Contatti* dell'ordine da ufficio cambiano solo
email e telefono principali.

## Dati di fatturazione

Sia le persone sia le aziende hanno i **dati di fatturazione**: una persona
può avere il suo codice fiscale e il suo indirizzo, un'azienda ha i suoi.
Online, chi compra sceglie se fatturare a sé o a una delle sue aziende.
Nell'indirizzo si cerca anche il **nome di un'attività** (un negozio, un
ufficio): Google completa l'indirizzo e, per un'azienda con la ragione sociale
vuota, la scrive con il nome dell'attività. Place ID e coordinate si salvano
senza vederli.

## Indirizzi di consegna

L'indirizzo della scheda è quello **di fatturazione**. Dove si consegna si
scrive nel riquadro *Indirizzi di consegna*: uno per il magazzino, uno per
casa, uno per il cantiere. Il **destinatario** è chi il corriere deve cercare,
e spesso non è chi compra. **Presso** è l'attività o la società a
quell'indirizzo (facoltativo): cercando nella via il nome di un'attività si
riempie da solo, se è vuoto, e resta modificabile. Vale anche per le sedi di
un'azienda, nel form e nelle finestre dell'ordine da ufficio. L'ordine copia
dalla sede scelta anche place ID e coordinate.

Gli indirizzi si gestiscono anche dalla **scheda del cliente** (il nome nell'elenco
la apre): il riquadro *Indirizzi di consegna* mostra una card per indirizzo, con
**Modifica**, **Rendi predefinito** e il cestino per eliminarlo, e **Aggiungi
indirizzo** in fondo. Ogni gesto apre una finestra; non serve passare dal form
di modifica. Uno solo è il **predefinito** e il primo che scrivi lo diventa; se
elimini il predefinito, passa al primo rimasto. Un indirizzo eliminato esce dalla
scheda ma non dagli ordini già fatti, che ne conservano una copia.

## L'elenco delle persone

Cliente è un **ruolo**, non un tipo di scheda: la stessa persona può essere
cliente, referente di un'azienda o tutte e due. Per questo, con persone e
aziende, l'elenco si chiama **Persone** e sopra la tabella c'è il filtro
**Ruolo** a vista: *Tutti*, *Clienti* (chi compra per sé) e *Referenti* (chi
è legato a un'azienda). Il nome della persona apre sempre la sua scheda, che
sia cliente o solo referente. Titoli, *Aggiungi persona*, *Scheda persona* e
il nome del file esportato seguono l'elenco (*Clienti* o *Referenti* negli
altri due modi).

## La scheda del cliente

In alto le **statistiche** e, a destra, i **dati di fatturazione** (codice
fiscale, e per un'azienda partita IVA, SDI e PEC, più l'indirizzo). Sotto, **tutti
i suoi dati** — nome o ragione sociale, ruolo, stato, email, telefono, accesso al
sito (solo per le persone) e note — e gli **indirizzi di consegna**. La scheda di
un'azienda elenca anche i suoi **referenti**, quella di una persona le **aziende**
per cui è referente: il nome apre la loro scheda, sotto c'è la mansione e se è il
principale o riceve i documenti. Poi gli ordini, il carrello, gli
**abbonamenti** (con la funzionalità *Abbonamenti*) e, in fondo, i coupon.

La scheda di una persona che è **solo referente** si apre come le altre, ma
mostra solo quello che ha senso per lei: **tutti i suoi dati** (con il ruolo
*Referente*), le **aziende** di cui è referente e gli **indirizzi di
consegna**. Statistiche, ordini, carrello, pagamento, listino, abbonamenti e
coupon sono del cliente e non ci sono. Con il gestionale *solo aziende* ogni
persona ha questa scheda.

## La scheda del fornitore

Dall'elenco dei fornitori il nome apre la sua scheda, come per i clienti:
**tutti i suoi dati**, i **dati di fatturazione**, gli ultimi **carichi**
ricevuti da lui (il numero apre il carico) e gli **articoli forniti**, con il
suo codice e il costo. Per cambiare qualcosa c'è **Modifica** in alto.

## Brand

> Con la funzionalità **Brand** (area *Anagrafiche*, serve *Aziende*, spenta
> di serie). Si accende da sola all'aggiornamento se nel gestionale c'è già
> almeno un brand e nessuno l'ha mai spenta.

Il **brand** è il nome commerciale con cui un'azienda si presenta: *RClass* può
essere il nome commerciale di due ragioni sociali, e una ragione sociale può
avere più brand (per esempio un ristorante e un albergo). Nel form di
un'**azienda** il brand è la **prima cosa** che si sceglie: cercalo scrivendo
una parte del nome e selezionane uno o più; se non c'è, il **+** lo crea. Un
brand con lo stesso nome di uno che c'è già non si duplica: si seleziona
quello.

Tutti i brand stanno in **Anagrafiche → Brand**: da lì li apri, li modifichi
e, se puoi creare schede, ne aggiungi uno nuovo. Un commerciale vede solo i
brand delle sue aziende e ne crea uno solo dal **+** (vedi
[Commerciali](commerciali.md)).

I preventivi e gli ordini da ufficio di un'azienda hanno il campo **Brand**,
fra i brand di quell'azienda: parte dal **predefinito** (o dal primo). Servirà
per legare al brand i progetti nati dal preventivo. Il documento tiene il nome
del brand com'era: se poi il brand cambia nome, si stacca dall'azienda o si
archivia, il preventivo non cambia. Unendo due brand doppioni, i documenti
passano al brand che resta. Sui **PDF** (preventivo, conferma d'ordine,
proforma e DDT, che lo prende dal suo ordine) il brand si stampa nel riquadro
del cliente, su una riga sua fra *Alla c.a.* e la ragione sociale.

La scheda del brand ha **logo**, **sito**, **domini email** (uno o più, per
riconoscere le email del brand; vuoto, si ricava dal sito) e **descrizione**.
Con la funzionalità **Brand kit** (area *Anagrafiche*, serve *Brand*) ci sono
anche i **recapiti** del brand (più email e telefoni, ognuno con la sua
etichetta), social, **colori** (scelti col selettore o scritti in HEX, per
esempio `#1a2b3c`), tono, **settore** (da una tendina con le sezioni ATECO),
**lingua** (da una tendina, di serie *Italiano*) e il link ai materiali; un
settore scritto a mano prima di questa versione resta salvato ma la tendina lo
mostra vuoto finché non ne scegli uno; spenta, quei campi non si vedono e
restano salvati. Il logo compare accanto al brand nell'ordine da ufficio e
nella scheda dell'ordine.

Con **Brand** spenta i brand **non si vedono e non si cancellano**: spariscono
la voce di menu, il riquadro nella scheda dell'azienda, il campo nei
preventivi e negli ordini, i brand seguiti dei referenti e quelli degli
indirizzi, la riga sui PDF. Un documento salvato con un brand lo tiene anche se
lo risalvi, e lo mostra di nuovo quando riaccendi la funzionalità; un documento
nuovo nasce senza brand. Senza brand le sedi proposte e i referenti in copia
non guardano il brand.

Con la funzionalità *Brand* ogni **indirizzo di consegna** di un'azienda ha il campo **Brand**: se scegli
uno o più brand l'indirizzo vale solo per loro, vuoto vale per tutti. Nei
preventivi e negli ordini da ufficio si propongono prima le sedi del brand del
documento, poi quelle per tutti; le sedi degli altri brand non compaiono.

## Gruppi di aziende

> Con la funzionalità **Gruppi di aziende** (area *Anagrafiche*, serve
> *Aziende*, spenta di serie).

Nel form di un'azienda il campo **Capogruppo** dice a capo di quale gruppo sta:
l'azienda madre. Il gruppo ha un livello solo: una capogruppo non ha a sua
volta una capogruppo. Spenta la funzionalità, il campo non si vede e la
capogruppo già scelta resta salvata.

## Referenti e aziende

Un'azienda ha spesso più persone con cui parli: chi ordina, chi paga, chi
riceve la merce. Nel form di un'**azienda** il riquadro **Referenti** elenca queste persone;
nel form di una **persona** il riquadro **Aziende** elenca le aziende per cui
lavora. La stessa persona può essere referente di più aziende ed è sempre
**una scheda sola**, con un solo accesso al sito.

Per ogni riga cerca la scheda nella tendina scrivendo una parte del nome. Se la
persona non c'è ancora, lascia la tendina su *Nuova persona* e scrivi **Nome** e
**Cognome** nella riga: al salvataggio la scheda nasce con l'**email di lavoro**
della riga come sua email. Se il gestionale vende anche alle persone, il
referente creato così è anche **cliente** e lo trovi nell'elenco dei clienti;
con *solo aziende* resta referente. In alternativa il **+** accanto alla
tendina apre una finestra: scrivi nome, cognome ed
email (o ragione sociale e partita IVA), e la scheda nasce e si seleziona nella
riga. Prima di crearla si controlla che non esista già: con la stessa **email**
o la stessa **partita IVA** si seleziona quella che c'è, senza doppioni. Il
referente nuovo non è né cliente né fornitore; l'azienda nuova è cliente.
Funziona anche quando crei un cliente o un'azienda nuovi. Poi:

- **Si occupa di** — una o più aree fra *Amministrazione*, *Acquisti*,
  *Commerciale*, *Tecnico*, *Direzione* e *Altro*; vuoto vuol dire che si
  occupa di tutto. Dice a chi mandare i documenti (vedi sotto);
- **Brand seguiti** — con la funzionalità *Brand*, i brand dell'azienda di cui
  si occupa; vuoto = tutti;
- **Mansione** — il ruolo scritto a mano, per esempio *Responsabile acquisti*;
  se manca, sui documenti e nelle tendine compaiono le aree di cui si occupa;
- **Referente principale** — è il referente proposto quando fai un preventivo,
  un ordine da ufficio o un DDT per quell'azienda;
- **Riceve i documenti** — chi ha questa risposta riceve in copia le email dei
  documenti. Sui documenti si può scegliere qualsiasi referente;
- **Email di lavoro**, **Prefisso**, **Telefono di lavoro** — i contatti del
  referente per quell'azienda (facoltativi). Se c'è l'email di lavoro i
  documenti di quell'azienda vanno lì e non alla sua email personale; la
  scheda dell'azienda e l'ordine la mostrano. Non serve mai per accedere al sito.

### A chi vanno in copia i documenti

Ogni documento ha la sua area: **preventivo, conferma dell'ordine e DDT** vanno
a chi si occupa degli **Acquisti**; **proforma, solleciti e pagamenti non
riusciti degli abbonamenti** a chi si occupa dell'**Amministrazione**. In copia
va chi **riceve i documenti** e si occupa di quell'area (o di tutto) e segue il
brand del documento (o tutti), il principale per primo. Se nessuno corrisponde,
vale la regola di sempre: tutti quelli che ricevono i documenti. Così
un'azienda per cui non compili le aree non vede cambiare niente.

Dell'azienda ricevono le email del suo riquadro *Contatti* che si occupano di
quell'area (o di tutto); se nessuna corrisponde, la sua email principale, come
prima. Nessuno riceve lo stesso documento due volte.

- **Preventivo, conferma, DDT**: destinatario l'email del documento (di solito
  quella dell'azienda); in copia le email dell'azienda per gli *Acquisti* (o la
  principale) e i referenti degli *Acquisti* che ricevono i documenti, all'email
  di lavoro se ce l'hanno.
- **Proforma, solleciti e pagamenti non riusciti degli abbonamenti**:
  destinatario la prima email dell'azienda per l'*Amministrazione* (o la
  principale; senza, il referente); in copia le altre email per
  l'*Amministrazione* e i referenti dell'*Amministrazione*.

Le copie vanno all'email del referente **per quell'azienda** (di lavoro, se
c'è, altrimenti la sua principale): gli altri contatti della persona non
ricevono mai documenti e non servono per accedere al sito.

Nell'elenco delle persone il filtro **Ruolo → Referenti** mostra solo queste
persone (*Clienti* solo chi compra per sé), e la colonna **Referenti /
aziende** dice chi è legato a chi; lo dice anche l'export. Togliere un referente dal riquadro non elimina la sua scheda e
non cambia i documenti già fatti, che ne conservano il nome.

## Comprare sul sito per un'azienda

Con la funzionalità **Account aziendali** (servono *Vendita online* e
*Aziende*) una persona
con l'account sul sito può ordinare anche per le aziende a cui è collegata.
Lo decide la colonna **Accesso all'area clienti** del riquadro Referenti:

- **Nessuno** — la persona compra solo per sé;
- **Ordina** — nel checkout sceglie in **Acquista come** se comprare per sé o
  per l'azienda;
- **Gestisce l'azienda** — come *Ordina*, e nell'area clienti vede anche gli
  ordini e gli abbonamenti fatti dagli altri per l'azienda e ne cambia i dati
  di fatturazione nella scheda **La mia azienda**.

Quando la persona compra per un'azienda, l'ordine è intestato all'azienda con
i suoi dati di fatturazione (sempre fattura, da società), il suo listino e il
suo metodo di pagamento proposto; la persona è il referente dell'ordine. Se
all'azienda mancano dati di fatturazione, il sito non fa ordinare e chiede di
completarli. Gli abbonamenti si comprano sempre per sé.

Le persone dell'azienda si vedono nella scheda **La mia azienda** ma si
cambiano solo qui, dal gestionale.

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

Il riquadro **Anagrafiche** dice quanti clienti e quante aziende ci sono e
porta agli elenchi; con un modo solo mostra solo quello con cui lavori.
# Storage condiviso

Le schede contatto e gli indirizzi di consegna (`contacts`, `contact_addresses`)
sono di `wonder-image/customer-account`; il riferimento esterno
(`external_references`) è di `wonder-image/app`. I namespace del gestionale
restano compatibili e aggiungono le opzioni commerciali del contatto. Forge
update rinomina i precedenti nomi `gst_*` prima di allineare gli schemi,
conservando ID, dati e collegamenti. Aggiornare app, customer-account e
gestionale insieme. Se entrambi i nomi sono già presenti la migrazione si
ferma, senza cancellazioni automatiche.
