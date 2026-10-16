<?php

namespace Wonder\Plugin\Gestionale\Support\Mcp;

use Wonder\Plugin\Gestionale\Models\Catalog\Product;

/**
 * Quello che serve a ogni gruppo di strumenti (`Tools`, `CatalogTools`,
 * `QuoteTools`): gli schemi, la lettura degli argomenti, l'esito di un
 * salvataggio. Gli errori sono tutti `McpError`: arrivano al client come
 * errore dello strumento, con il campo che non va.
 */
trait ToolSupport
{
    /**
     * @param array<string, mixed> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private static function schema(array $properties, array $required): array
    {
        // Un oggetto vuoto deve restare `{}` nel JSON, non `[]`.
        return ['type' => 'object', 'properties' => (object) $properties, 'additionalProperties' => false]
            + ($required !== [] ? ['required' => $required] : []);
    }

    private static function int(array $args, string $key): int
    {
        $value = $args[$key] ?? null;

        if (!is_int($value) && !(is_string($value) && ctype_digit($value)) || (int) $value <= 0) {
            throw McpError::make('id', 0, ['field' => $key]);
        }

        return (int) $value;
    }

    private static function string(array $args, string $key): string
    {
        $value = $args[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw McpError::make('required', 0, ['field' => $key]);
        }

        return $value;
    }

    private static function flag(mixed $value, string $key): string
    {
        if (!is_bool($value)) {
            throw McpError::make('boolean', 0, ['field' => $key]);
        }

        return $value ? 'true' : 'false';
    }

    private static function price(mixed $value, string $key): ?float
    {
        if ($value === null && $key === 'sale_price') {
            return null;
        }

        if (!is_int($value) && !is_float($value) || $value < 0) {
            throw McpError::make('not_negative', 0, ['field' => $key]);
        }

        return round((float) $value, 2);
    }

    private static function nullable(mixed $value): ?float
    {
        return $value === null || trim((string) $value) === '' ? null : round((float) $value, 2);
    }

    private static function saved(object $result): object
    {
        if (empty($result->success)) {
            throw McpError::make('save_failed', 0, ['fields' => self::reason($result)]);
        }

        return $result;
    }

    private static function reason(object $result): string
    {
        $messages = [];

        foreach ((array) ($result->response ?? []) as $key => $field) {
            if (is_object($field) && method_exists($field, 'isValid') && !$field->isValid()) {
                $messages[] = $key;
            }
        }

        return $messages !== [] ? implode(', ', $messages) : '-';
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /** @return array<string, mixed> */
    private static function productRow(int $id): array
    {
        $row = Product::findById($id);

        if (!is_array($row) || $row === [] || (string) ($row['deleted'] ?? 'false') === 'true') {
            throw McpError::make('product_not_found', 0, ['id' => $id]);
        }

        return $row;
    }

    /**
     * Una riga di un Model, viva: altrimenti `$error` con l'id.
     *
     * @param class-string $model
     * @return array<string, mixed>
     */
    private static function live(string $model, int $id, string $error): array
    {
        $row = $id > 0 ? $model::findById($id) : null;

        if (!is_array($row) || $row === [] || (string) ($row['deleted'] ?? 'false') === 'true') {
            throw McpError::make($error, 0, ['id' => $id]);
        }

        return $row;
    }
}
