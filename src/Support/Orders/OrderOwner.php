<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Wonder\Plugin\Gestionale\Models\Sales\Order;

/**
 * Gli ordini fatti con un'email prima che esistesse l'account: quando chi la
 * usa prova che è sua — conferma l'email, entra con Google — gli ordini senza
 * utente passano al suo account. Quelli di un altro account restano dove sono.
 * Ordini e preventivi: mai i carrelli, che un ospite può lasciare con l'email
 * di un altro (l'anteprima del checkout la salva prima del rifiuto) e che al
 * suo ingresso sostituirebbero il suo.
 */
final class OrderOwner
{
    /**
     * Allega all'account gli ordini e i preventivi con quell'email e senza
     * utente, mai i carrelli; la scheda cliente si scrive solo dove manca.
     * Restituisce quanti ne ha presi.
     */
    public static function claim(int $userId, int $customerId, string $email): int
    {
        $email = strtolower(trim($email));

        if ($userId <= 0 || $email === '') {
            return 0;
        }

        $rows = Order::find(['email' => $email, 'user_id' => 0, 'deleted' => 'false']);

        if (!is_array($rows) || $rows === []) {
            return 0;
        }

        $claimed = 0;

        foreach (isset($rows['id']) ? [$rows] : array_filter($rows, 'is_array') as $order) {
            // Un carrello non è suo: lo lascia all'ospite che l'ha riempito.
            if ((string) ($order['stage'] ?? '') === 'cart') {
                continue;
            }

            // Il confronto del database può distinguere le maiuscole oppure no: si ricontrolla qui.
            if (strtolower(trim((string) ($order['email'] ?? ''))) !== $email) {
                continue;
            }

            $values = ['user_id' => $userId];

            if ((int) ($order['customer_id'] ?? 0) <= 0 && $customerId > 0) {
                $values['customer_id'] = $customerId;
            }

            if (Order::update($values, (int) $order['id'])->success ?? false) {
                $claimed++;
            }
        }

        return $claimed;
    }
}
