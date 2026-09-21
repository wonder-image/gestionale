<?php
/** php tests/ErrorsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\ProviderError;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

check('l\'errore dell\'utente parla la sua lingua', function () {
    $errore = UserError::make('stock.insufficient', ['product' => 'Maglia rossa']);

    return str_contains($errore->getMessage(), 'Maglia rossa')
        && !str_contains($errore->getMessage(), '{{product}}');
});

check('una chiave senza traduzione non fa esplodere niente', function () {
    $errore = UserError::make('chiave.che.non.esiste');

    return $errore->getMessage() !== '' && $errore instanceof Throwable;
});

check('l\'errore dell\'utente è quello che il backend sa fermare', function () {
    // Il controller del backend intercetta InvalidArgumentException e la
    // trasforma in `$ALERT`: il salvataggio non parte e il messaggio torna sul
    // form. Con un'altra eccezione l'utente vedrebbe una pagina 500.
    return UserError::make('category.loop') instanceof InvalidArgumentException;
});

check('l\'errore del provider porta con sé provider, azione e contesto', function () {
    $errore = ProviderError::make('fatture-in-cloud', 'invoice.send', 'Timeout', ['invoice' => 12]);

    return $errore->provider() === 'fatture-in-cloud'
        && $errore->action() === 'invoice.send'
        && $errore->context() === ['invoice' => 12]
        && $errore->getMessage() === 'Timeout';
});

check('l\'errore del provider tiene l\'eccezione che l\'ha causato', function () {
    $causa = new RuntimeException('connessione chiusa');
    $errore = ProviderError::make('stripe', 'charge', 'Pagamento non riuscito', [], $causa);

    return $errore->getPrevious() === $causa;
});

check('i destinatari si leggono dalle impostazioni, uno per virgola', fn () =>
    Errors::parseRecipients(' dev@esempio.it , , secondo@esempio.it ') === ['dev@esempio.it', 'secondo@esempio.it']
    && Errors::parseRecipients('') === []
);

check('gli avvisi tecnici hanno un destinatario solo', fn () =>
    (new ReflectionMethod(Errors::class, 'recipients'))->getNumberOfParameters() === 0
);

summary();
