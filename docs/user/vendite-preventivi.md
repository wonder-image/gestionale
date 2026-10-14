---
icon: file-lines
---

# Preventivi

> **Da attivare.** Funzionalità **Preventivi**, che chiede anche **Vendita in
> ufficio**: un preventivo accettato diventa un ordine da ufficio.

## A cosa serve

Mandare al cliente un'offerta in PDF, con prezzi e condizioni, e trasformarla
in ordine quando dice sì. Un preventivo **non tocca il magazzino**: non
impegna la merce e non la scarica.

## Come nasce

*Vendite → Preventivi → Nuovo preventivo*. La pagina è quella del
[nuovo ordine da ufficio](vendite-ordini-da-ufficio.md): cliente, fatturazione,
consegna, righe, pagamento, sconto e note, più la data **Valido fino al**.
Parte dai giorni scelti in *Impostazioni → Vendite → Preventivi* (30 se non li
cambi); su ogni preventivo la puoi spostare.

**Salva bozza** lo salva senza numero.

## Inviare

**Invia al cliente**:

1. dà al preventivo il **numero** (al primo invio);
2. crea il **PDF** e lo archivia: è quello che il cliente riceve, e non
   cambia più;
3. con l'interruttore **Manda l'email con il PDF** acceso, il cliente riceve
   l'email con il PDF allegato. Spento, o se il cliente non ha un'email, scarichi il PDF
   da **Revisioni inviate** e lo mandi tu.

Il preventivo passa a **Inviato**.

### Le revisioni

Un preventivo inviato si può ancora modificare: torna **da inviare**, e al
prossimo invio diventa la **revisione 2** (poi 3, e così via) con il suo PDF.
In **Revisioni inviate** trovi ogni PDF partito, con la data e a chi è andato.
Rimandare un preventivo senza averlo cambiato rimanda l'ultimo PDF, senza una
revisione nuova.

## Accettato, rifiutato, scaduto

* **Accettato** — il preventivo diventa una **bozza d'ordine** con le stesse
  righe e il numero di preventivo. Ti porta alla sua pagina: controlla
  consegna e pagamento e premi **Conferma ordine**, come per ogni
  [ordine da ufficio](vendite-ordini-da-ufficio.md).
* **Rifiutato** — il cliente ha detto no. Se cambia idea, modificalo: torna da
  inviare.
* **Scaduto** — un preventivo inviato e non accettato entro la data di
  validità diventa scaduto da solo, ogni mattina. Serve l'attività *Gestionale:
  preventivi scaduti* accesa nella pagina delle attività. Modificandolo lo
  riapri, con una data nuova.

Un preventivo **mai inviato** si elimina; uno inviato no, perché il cliente ha
il suo PDF: rifiutalo o lascialo scadere.

## L'elenco

*Vendite → Preventivi* mostra numero e revisione, cliente, totale, stato e
validità, con il filtro per stato e la ricerca per numero, nome o email.

## La conferma d'ordine in PDF

Sulla scheda di un ordine da ufficio confermato, **PDF** scarica la conferma
d'ordine con le righe, i totali, l'IVA e le scadenze delle rate. Alla conferma,
con **Alla conferma scrivi al cliente**, la stessa conferma parte allegata
all'email.
