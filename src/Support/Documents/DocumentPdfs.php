<?php

namespace Wonder\Plugin\Gestionale\Support\Documents;

use Wonder\Plugin\Gestionale\Extensions\Extensions;

/**
 * Quale classe disegna un documento, e il PDF fatto. Il sito sceglie la sua
 * con l'hook `documentPdf` di `GestionaleExtension`; una classe che non esiste
 * o non estende `DocumentPdf` non vale, e resta quella del modulo.
 */
final class DocumentPdfs
{
    /** Il tipo di documento → la classe del modulo. */
    public const DEFAULTS = [
        'quote' => QuotePdf::class,
        'order_confirmation' => OrderConfirmationPdf::class,
        'proforma' => ProformaPdf::class,
    ];

    /** @return class-string<DocumentPdf> */
    public static function classFor(string $type): string
    {
        $default = self::DEFAULTS[$type] ?? throw new \InvalidArgumentException("Documento sconosciuto: {$type}");
        $chosen = Extensions::filter('documentPdf', $default, $type);

        return is_string($chosen) && class_exists($chosen) && is_subclass_of($chosen, DocumentPdf::class) ? $chosen : $default;
    }

    /**
     * I byte del PDF.
     *
     * @param array<string, mixed> $data da `DocumentData`
     */
    public static function render(string $type, array $data): string
    {
        $class = self::classFor($type);

        return (new $class($data))->render();
    }
}
