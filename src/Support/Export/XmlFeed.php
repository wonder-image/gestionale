<?php

namespace Wonder\Plugin\Gestionale\Support\Export;

use XMLWriter;

/**
 * Un feed XML RSS 2.0, quello che leggono Google Merchant Center e il
 * catalogo Meta: un `<channel>` con titolo, link e descrizione, poi un
 * `<item>` per prodotto, un campo per riga.
 *
 * I campi con prefisso (`g:id`) usano gli spazi dei nomi passati; un campo
 * con più valori (`g:additional_image_link`) si ripete. I campi vuoti non
 * si scrivono: per i feed «assente» e «vuoto» non sono la stessa cosa.
 *
 * Classe pura.
 */
final class XmlFeed
{
    public const GOOGLE = 'http://base.google.com/ns/1.0';

    /**
     * @param array{title?: string, link?: string, description?: string} $channel
     * @param iterable<array<string, string|list<string>|null>> $items
     * @param array<string, string> $namespaces prefisso → indirizzo
     */
    public static function rss(array $channel, iterable $items, array $namespaces = ['g' => self::GOOGLE]): string
    {
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');

        foreach ($namespaces as $prefix => $uri) {
            $xml->writeAttribute('xmlns:'.$prefix, $uri);
        }

        $xml->startElement('channel');

        foreach (['title', 'link', 'description'] as $key) {
            $xml->writeElement($key, self::clean((string) ($channel[$key] ?? '')));
        }

        foreach ($items as $item) {
            $xml->startElement('item');

            foreach ($item as $name => $value) {
                foreach (is_array($value) ? $value : [$value] as $single) {
                    $single = self::clean((string) $single);

                    if ($single !== '') {
                        $xml->writeElement((string) $name, $single);
                    }
                }
            }

            $xml->endElement();
        }

        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /** Toglie i caratteri di controllo che l'XML non ammette. */
    private static function clean(string $value): string
    {
        return trim(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '');
    }
}
