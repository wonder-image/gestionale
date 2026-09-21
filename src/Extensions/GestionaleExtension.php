<?php

namespace Wonder\Plugin\Gestionale\Extensions;

/**
 * Punto di aggancio del sito al gestionale.
 *
 * Il sito scrive una sottoclasse, la dichiara in `extensions` nella
 * configurazione del modulo e riscrive solo i metodi che gli servono: qui sono
 * tutti vuoti apposta.
 *
 * Gli hook che arrivano dopo l'operazione (`on…`) non possono fermarla: un
 * errore dentro uno di loro finisce nel log e il gestionale va avanti. Quelli
 * che arriveranno prima (`before…`) potranno bloccare con un `UserError`.
 */
abstract class GestionaleExtension
{
    /** Uno stato è cambiato: `$entity` è il tipo di documento. */
    public function onStatusChanged(string $entity, int $entityId, string $field, string $from, string $to): void
    {
    }

    /**
     * Ultima parola sul messaggio prima dell'invio: torna il messaggio, anche
     * cambiato. `$key` dice quale email è.
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    public function beforeEmailSend(string $key, array $message): array
    {
        return $message;
    }
}
