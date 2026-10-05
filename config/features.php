<?php

/**
 * Catalogo delle funzionalità del gestionale (3.4 della spec di architettura).
 * Il database conserva solo lo stato: nome, area e dipendenze stanno qui.
 *
 * `created => false` segna una funzionalità prevista ma non ancora costruita:
 * il pannello la mostra in sola lettura. Si toglie la riga quando esce.
 */
return [
    'orders' => [
        'name' => 'Ordini',
        'description' => 'Gestione degli ordini con stati e scarico del magazzino.',
        'area' => 'Vendite',
        'release' => 'G4',
    ],
    'quotes' => [
        'name' => 'Preventivi',
        'description' => 'Preventivi con PDF e revisioni, convertibili in ordine.',
        'area' => 'Vendite',
        'requires' => ['orders'],
        'release' => 'G9',
        'created' => false,
    ],
    'returns' => [
        'name' => 'Resi',
        'description' => 'Richiesta, approvazione, motivo e ricarico a magazzino.',
        'area' => 'Vendite',
        'requires' => ['orders'],
        'release' => 'G4',
    ],
    'delivery_notes' => [
        'name' => 'DDT',
        'description' => 'Documento di trasporto per le consegne.',
        'area' => 'Vendite',
        'requires' => ['orders'],
        'release' => 'G9',
        'created' => false,
    ],
    'subscriptions' => [
        'name' => 'Abbonamenti',
        'description' => 'Piani, rinnovi e cambio piano.',
        'area' => 'Vendite',
        'release' => 'G10',
        'created' => false,
    ],
    'bundles' => [
        'name' => 'Multiprodotto',
        'description' => 'Prodotti composti da altri prodotti: vendendoli si scaricano i componenti.',
        'area' => 'Catalogo',
        'release' => 'G5',
    ],
    'customizations' => [
        'name' => 'Personalizzazione',
        'description' => 'Campi compilati al momento della vendita, con eventuale sovrapprezzo.',
        'area' => 'Catalogo',
        'requires' => ['orders'],
        'release' => 'G5',
    ],
    'barcode_labels' => [
        'name' => 'Etichette',
        'description' => 'Etichette con codice a barre e prezzo in PDF.',
        'area' => 'Catalogo',
        'release' => 'futura',
        'created' => false,
    ],
    'multi_location' => [
        'name' => 'Più sedi',
        'description' => 'Altre sedi con giacenze proprie e trasferimenti.',
        'area' => 'Magazzino',
        'release' => 'G3',
    ],
    'purchasing' => [
        'name' => 'Acquisti',
        'description' => 'Costi d\'acquisto per fornitore, documenti di carico, valore del magazzino.',
        'area' => 'Magazzino',
        'release' => 'G3',
    ],
    'batch_tracking' => [
        'name' => 'Lotti e scadenze',
        'description' => 'Lotto e data di scadenza su carichi e scarichi.',
        'area' => 'Magazzino',
        'release' => 'G3',
        'created' => false,
    ],
    'low_stock_alerts' => [
        'name' => 'Avvisi di scorta minima',
        'description' => 'Email e riquadro per i prodotti sotto la propria scorta minima.',
        'area' => 'Magazzino',
        'release' => 'G2',
    ],
    'backorders' => [
        'name' => 'Vendita senza giacenza',
        'description' => 'Prodotti vendibili a magazzino vuoto: la giacenza va sotto zero e la vendita è "su ordinazione".',
        'area' => 'Magazzino',
        'requires' => ['orders'],
        'release' => 'G4',
    ],
    'customer_price_lists' => [
        'name' => 'Listini cliente',
        'description' => 'Prezzi personalizzati per cliente, con scaglioni.',
        'area' => 'Listini',
        'requires' => ['orders'],
        'release' => 'G6',
        'created' => false,
    ],
    'discount_campaigns' => [
        'name' => 'Sconto massivo',
        'description' => 'Campagne per categoria, tag, brand o modello, con data e ora, in € o %.',
        'area' => 'Promozioni',
        'requires' => ['orders'],
        'release' => 'G6',
    ],
    'coupons' => [
        'name' => 'Coupon',
        'description' => 'Importo, percentuale, buono a scalare, spedizione gratuita, con limiti.',
        'area' => 'Promozioni',
        'requires' => ['orders'],
        'release' => 'G6',
    ],
    'e_invoicing' => [
        'name' => 'Fatturazione elettronica',
        'description' => 'Fatture, invio SDI, coda, stati e notifiche.',
        'area' => 'Fatturazione',
        'release' => 'G8',
        'created' => false,
    ],
    'deferred_invoicing' => [
        'name' => 'Fattura differita',
        'description' => 'Fattura riepilogativa dei DDT del periodo.',
        'area' => 'Fatturazione',
        'requires' => ['delivery_notes', 'e_invoicing'],
        'release' => 'G9',
        'created' => false,
    ],
    'shipping' => [
        'name' => 'Spedizioni',
        'description' => 'Listini per zona e peso, spedizioni e tracking manuali, ritiro in sede.',
        'area' => 'Spedizioni',
        'requires' => ['orders'],
        'release' => 'G7',
    ],
    'carriers' => [
        'name' => 'Corrieri',
        'description' => 'Etichette e tracking dal corriere.',
        'area' => 'Spedizioni',
        'requires' => ['shipping'],
        'release' => 'futura',
        'created' => false,
    ],
    'online_sales' => [
        'name' => 'Vendita online',
        'description' => 'Il sito vende: coupon, campagne e metodi di pagamento valgono per il sito.',
        'area' => 'Canali di vendita',
        'requires' => ['orders'],
        'release' => 'G6',
    ],
    'office_sales' => [
        'name' => 'Vendita in ufficio',
        'description' => 'Gli ordini si fanno dal gestionale: coupon, campagne e metodi di pagamento valgono anche per l\'ufficio.',
        'area' => 'Canali di vendita',
        'requires' => ['orders'],
        'release' => 'G6',
    ],
    'pos' => [
        'name' => 'Vendita in cassa',
        'description' => 'Vendita in sede con documento commerciale e corrispettivi.',
        'area' => 'Canali di vendita',
        'requires' => ['orders'],
        'release' => 'futura',
        'created' => false,
    ],
];
