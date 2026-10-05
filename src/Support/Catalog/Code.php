<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

/** Compatibility facade for the shared model-code generator. */
final class Code
{
    public static function make(string $modelClass, string $prefix, string $column = 'code'): string
    {
        return \Wonder\App\Support\ModelCode::make($modelClass, $prefix, $column);
    }
}
