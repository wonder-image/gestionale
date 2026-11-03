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

    /**
     * La classe che disegna un PDF di vendita (G9): `$type` è `quote` o
     * `order_confirmation` o `delivery_note`. Torna `$class`
     * per lasciare quella del modulo, o il nome di una classe del sito che
     * estende `Support\Documents\DocumentPdf`.
     */
    public function documentPdf(string $type, string $class): string
    {
        return $class;
    }

    /**
     * I formati delle etichette con codice a barre: torna `$formats`, chiave
     * => `Support\Labels\LabelFormat`, con quelli del sito aggiunti o tolti.
     *
     * @param array<string, \Wonder\Plugin\Gestionale\Support\Labels\LabelFormat> $formats
     * @return array<string, \Wonder\Plugin\Gestionale\Support\Labels\LabelFormat>
     */
    public function labelFormats(array $formats): array
    {
        return $formats;
    }

    /**
     * I blocchi del sito che possono mostrare una selezione: torna `$blocks`,
     * con i propri aggiunti o cambiati ({@see \Wonder\Plugin\Gestionale\Support\Catalog\SelectionBlocks}).
     *
     * @param array<string, array<string, mixed>> $blocks
     * @return array<string, array<string, mixed>>
     */
    public function selectionBlocks(array $blocks): array
    {
        return $blocks;
    }
}
