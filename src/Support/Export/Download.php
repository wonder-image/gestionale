<?php

namespace Wonder\Plugin\Gestionale\Support\Export;

/** Manda un file al browser come scaricamento. */
final class Download
{
    public static function send(string $body, string $contentType, string $fileName): void
    {
        $fileName = str_replace(['"', '\\', "\r", "\n"], '', $fileName);

        header('Content-Type: '.$contentType);
        header('Content-Disposition: attachment; filename="'.$fileName.'"');
        header('Content-Length: '.strlen($body));
        header('Cache-Control: no-store');

        echo $body;
    }
}
