<?php

namespace Wonder\Plugin\Gestionale\Support\Documents;

use RuntimeException;

/**
 * I PDF dei documenti di vendita sul disco (G9 §3.3), in una cartella **non
 * pubblica**: `storage/gestionale/documents` sotto la radice del sito. Si
 * scaricano solo dalla rotta del backend, che controlla i permessi.
 *
 * I percorsi passati sono relativi a quella cartella (`quotes/12-2.pdf`): mai
 * `..`, mai assoluti.
 */
final class DocumentFiles
{
    public const DIR = 'storage/gestionale/documents';

    private static ?string $base = null;

    /** La cartella, assoluta. */
    public static function base(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }

        $root = defined('ROOT') ? (string) constant('ROOT') : (string) getcwd();

        return rtrim($root, '/').'/'.self::DIR;
    }

    /** Cambia la cartella; `null` torna a quella del sito. Serve ai test. */
    public static function useBase(?string $base): void
    {
        self::$base = $base === null ? null : rtrim($base, '/');
    }

    /** Scrive il file e restituisce il percorso relativo. */
    public static function save(string $relative, string $bytes): string
    {
        $relative = self::clean($relative);
        $path = self::base().'/'.$relative;
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cartella dei documenti non scrivibile: '.$dir);
        }

        if (@file_put_contents($path, $bytes) === false) {
            throw new RuntimeException('Documento non scritto: '.$path);
        }

        return $relative;
    }

    /** Il percorso assoluto di un file che c'è; null altrimenti. */
    public static function path(string $relative): ?string
    {
        try {
            $path = self::base().'/'.self::clean($relative);
        } catch (RuntimeException) {
            return null;
        }

        return is_file($path) ? $path : null;
    }

    /**
     * Un file da allegare a un'email, per un documento che non si archivia
     * (la conferma d'ordine): sta in `tmp/` e il nome è quello che il cliente
     * vede.
     */
    public static function temporary(string $name, string $bytes): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?: 'documento.pdf';

        return (string) self::path(self::save('tmp/'.bin2hex(random_bytes(6)).'/'.$name, $bytes));
    }

    private static function clean(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        if ($relative === '' || str_contains($relative, '..') || preg_match('#^[A-Za-z0-9._/-]+$#', $relative) !== 1) {
            throw new RuntimeException('Percorso del documento non valido: '.$relative);
        }

        return $relative;
    }
}
