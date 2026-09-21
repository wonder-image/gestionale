<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use RuntimeException;

/**
 * La coda non può lavorare qui.
 *
 * Non è un guasto di una singola immagine: è l'ambiente che non ce la fa —
 * tipicamente un comando che gira senza le costanti che il sito dichiara solo
 * durante una richiesta web. Chi la riceve si ferma e lo racconta, e le righe
 * restano in attesa con i loro tentativi intatti.
 */
final class Unavailable extends RuntimeException
{
}
