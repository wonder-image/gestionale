<?php
declare(strict_types=1);

/**
 * Eventi Stripe di prova: la fixture con i campi cambiati dal test, e la
 * firma come la manda Stripe (`t=…,v1=…`).
 */
function eventoStripe(string $nome, array $oggetto = [], array $evento = []): string
{
    $dati = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/stripe/'.$nome.'.json'), true);
    $dati = array_replace($dati, $evento);
    $dati['data']['object'] = array_replace($dati['data']['object'], $oggetto);

    return (string) json_encode($dati);
}

function firmaStripe(string $corpo, string $segreto, ?int $tempo = null): string
{
    $tempo ??= time();

    return 't='.$tempo.',v1='.hash_hmac('sha256', $tempo.'.'.$corpo, $segreto);
}
