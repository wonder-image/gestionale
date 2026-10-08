<?php

namespace Wonder\Plugin\Gestionale\Extensions;

use Closure;
use Wonder\Elements\Components\Card;

/**
 * Un riquadro delle Impostazioni di Set Up portato da un modulo: le sue
 * colonne stanno nella riga di `gst_settings` e viaggiano col deploy come le
 * altre; in produzione si leggono e basta, come il resto della pagina.
 *
 * Le colonne vivono finché il modulo è acceso: spento, `forge update` le
 * toglie insieme ai valori.
 */
abstract class SettingsSection
{
    /** @return list<\Wonder\Sql\TableSchema> */
    abstract public function columns(): array;

    /** @return list<\Wonder\Data\UploadSchema> */
    abstract public function data(): array;

    /** @return list<\Wonder\App\ResourceSchema\FormField> */
    abstract public function fields(): array;

    /** @return array<string, string> */
    abstract public function labels(): array;

    /** Il riquadro; `$input` dà il campo della pagina per chiave. */
    abstract public function card(Closure $input): Card;

    /**
     * I valori prima del salvataggio, da pulire: arrivano tutti, anche quelli
     * degli altri riquadri.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function mutate(array $values): array
    {
        return $values;
    }
}
