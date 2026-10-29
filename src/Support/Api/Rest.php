<?php

namespace Wonder\Plugin\Gestionale\Support\Api;

/**
 * Le API REST del gestionale (`/api/gestionale/v1/…`): ogni rotta è uno
 * strumento del server MCP, con gli stessi permessi, scope, regole e
 * registro delle scritture. Qui c'è solo la traduzione da HTTP agli
 * argomenti dello strumento e dall'esito al codice HTTP.
 */
final class Rest
{
    public const PREFIX = '/v1';

    /** I parametri della query in italiano ⇒ gli argomenti degli strumenti. */
    private const QUERY = ['q' => 'query', 'stato' => 'status', 'categoria' => 'category_id', 'attivo' => 'active'];

    /**
     * Nome della rotta ⇒ metodo, percorso, strumento e nome dell'argomento
     * che riceve `{id}`. `products.index` sceglie lo strumento da `q`.
     *
     * @return array<string, array{method: string, path: string, tool: string, id?: string}>
     */
    public static function routes(): array
    {
        return [
            'products.index' => ['method' => 'GET', 'path' => '/prodotti/', 'tool' => 'products_list'],
            'products.store' => ['method' => 'POST', 'path' => '/prodotti/', 'tool' => 'product_create'],
            'products.show' => ['method' => 'GET', 'path' => '/prodotti/{id}/', 'tool' => 'product_get', 'id' => 'id'],
            'products.update' => ['method' => 'PATCH', 'path' => '/prodotti/{id}/', 'tool' => 'product_update', 'id' => 'id'],
            'products.stock' => ['method' => 'POST', 'path' => '/prodotti/{id}/giacenza/', 'tool' => 'stock_adjust', 'id' => 'product_id'],
            'orders.index' => ['method' => 'GET', 'path' => '/ordini/', 'tool' => 'orders_list'],
            'orders.show' => ['method' => 'GET', 'path' => '/ordini/{id}/', 'tool' => 'order_get', 'id' => 'id'],
            'orders.action' => ['method' => 'POST', 'path' => '/ordini/{id}/azioni/', 'tool' => 'order_action', 'id' => 'order_id'],
            'customers.index' => ['method' => 'GET', 'path' => '/clienti/', 'tool' => 'customers_list'],
            'customers.show' => ['method' => 'GET', 'path' => '/clienti/{id}/', 'tool' => 'customer_get', 'id' => 'id'],
        ];
    }

    /**
     * Lo strumento e i suoi argomenti per una richiesta. Le GET leggono la
     * query, le altre il corpo; `{id}` del percorso vince su quello del corpo.
     *
     * @param array<string, mixed> $parameters i parametri del percorso
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function call(string $route, array $parameters, array $query, array $body): array
    {
        $definition = self::routes()[$route] ?? null;

        if ($definition === null) {
            return ['', []];
        }

        $arguments = $definition['method'] === 'GET' ? self::query($query) : $body;

        if (isset($definition['id'])) {
            $arguments[$definition['id']] = self::scalar((string) ($parameters['id'] ?? ''));
        }

        $tool = $definition['tool'];

        // Con un testo da cercare l'elenco dei prodotti diventa la ricerca.
        if ($route === 'products.index' && trim((string) ($arguments['query'] ?? '')) !== '') {
            return ['products_search', ['query' => (string) $arguments['query']]];
        }

        return [$tool, $arguments];
    }

    /** Lo stato HTTP di un esito di `Server::invoke()`; 201 per una creazione. */
    public static function status(string $route, ?string $kind): int
    {
        return match ($kind) {
            null => $route === 'products.store' ? 201 : 200,
            'forbidden' => 403,
            'not_found' => 404,
            'missing', 'invalid' => 422,
            default => 500,
        };
    }

    /**
     * La query arriva come testo: numeri e `true`/`false` tornano tipi, come
     * li vogliono gli schemi degli strumenti.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private static function query(array $query): array
    {
        $out = [];

        foreach ($query as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            $key = self::QUERY[$key] ?? $key;
            $out[$key] = $key === 'query' ? (string) $value : self::scalar((string) $value);
        }

        return $out;
    }

    private static function scalar(string $value): int|bool|string
    {
        return match (true) {
            $value === 'true' => true,
            $value === 'false' => false,
            ctype_digit($value) && $value !== '' && strlen($value) < 10 => (int) $value,
            default => $value,
        };
    }
}
