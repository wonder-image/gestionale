<?php
declare(strict_types=1);

/**
 * Il client HTTP di stripe-php che i test hanno di serie: non tocca mai la rete.
 *
 * - `GET /v1/payment_method_configurations` risponde con una configurazione
 *   fissa che accende la sola carta;
 * - ogni altra richiesta riceve un errore che dice quale era e che nei test
 *   serve un finto con le risposte in coda (es. `FakeStripeHttp`).
 *
 * Si carica con `require_once` dopo l'autoload di composer (lo fa
 * `tests/harness.php`, quindi ogni test): l'installazione è fatta qui. Chi
 * vuole risposte sue installa il proprio client e questo resta sostituito.
 * L'harness dell'ecommerce lo può includere allo stesso modo:
 * `require_once <gestionale>/tests/supporto/StripeSenzaRete.php;`.
 */
final class StripeSenzaRete implements \Stripe\HttpClient\ClientInterface
{
    /** @var list<string> le richieste ricevute, «METODO percorso» */
    public array $requests = [];

    public static function install(): self
    {
        $client = new self();
        \Stripe\ApiRequestor::setHttpClient($client);

        return $client;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url((string) $absUrl, PHP_URL_PATH);
        $this->requests[] = strtoupper((string) $method).' '.$path;

        if (strtoupper((string) $method) === 'GET' && $path === '/v1/payment_method_configurations') {
            return [json_encode([
                'object' => 'list',
                'url' => '/v1/payment_method_configurations',
                'has_more' => false,
                'data' => [[
                    'id' => 'pmc_prova',
                    'object' => 'payment_method_configuration',
                    'active' => true,
                    'is_default' => true,
                    'card' => ['available' => true],
                ]],
            ]), 200, []];
        }

        return [json_encode(['error' => [
            'type' => 'invalid_request_error',
            'message' => 'Test senza rete: richiesta Stripe non prevista ('.strtoupper((string) $method).' '.$path.'). Installa un FakeStripeHttp con le risposte in coda.',
        ]]), 400, []];
    }
}

if (class_exists(\Stripe\ApiRequestor::class)) {
    StripeSenzaRete::install();
}
