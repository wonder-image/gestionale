<?php

namespace Wonder\Plugin\Gestionale\Support\Sales;

/**
 * Quanto di ogni riga d'ordine è evaso (G9 §5, G9.15). Pura.
 *
 * Una riga è evasa per la quantità che sta in un DDT emesso o in una
 * spedizione partita (o ritiro completato). DDT e spedizione di solito
 * descrivono la stessa merce — il DDT accompagna il pacco — quindi per riga si
 * prende il **massimo** dei due, mai la somma: nessun pezzo si conta due volte.
 *
 * Il limite, scritto nella guida: se per lo stesso ordine una parte parte con
 * un DDT senza spedizione e un'altra con una spedizione senza DDT, il massimo
 * conta meno del vero. Per un ordine si usa un tipo di documento solo.
 */
final class FulfilledLines
{
    /**
     * @param array<int, float|int|string> $ordered riga => quantità ordinata
     * @param array<int, float|int|string> $inNotes riga => quantità nei DDT emessi
     * @param array<int, float|int|string> $shipped riga => quantità spedita o assegnata
     * @return array<int, float> riga => evaso, mai oltre l'ordinato
     */
    public static function of(array $ordered, array $inNotes, array $shipped): array
    {
        $out = [];

        foreach ($ordered as $id => $quantity) {
            $quantity = round((float) $quantity, 3);
            $done = max((float) ($inNotes[$id] ?? 0), (float) ($shipped[$id] ?? 0));
            $out[(int) $id] = round(max(0.0, min($quantity, $done)), 3);
        }

        return $out;
    }

    /**
     * Quanto resta di ogni riga, dopo l'evaso.
     *
     * @param array<int, float|int|string> $ordered
     * @param array<int, float|int|string> $inNotes
     * @param array<int, float|int|string> $shipped
     * @return array<int, float>
     */
    public static function remaining(array $ordered, array $inNotes, array $shipped): array
    {
        $done = self::of($ordered, $inNotes, $shipped);
        $out = [];

        foreach ($ordered as $id => $quantity) {
            $out[(int) $id] = round(max(0.0, (float) $quantity - $done[(int) $id]), 3);
        }

        return $out;
    }
}
