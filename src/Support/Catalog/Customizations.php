<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationOption;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCustomization;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;

/**
 * Le personalizzazioni di un articolo: quali sono, cosa si può scrivere, quanto
 * costano e come si salvano sulla riga d'ordine.
 *
 * Il server è l'unico a decidere: chi compra manda per ogni personalizzazione
 * un testo o l'id di un'opzione, e qui si controlla, si prezza e si prepara la
 * copia che resta sulla riga, con le etichette di allora. {@see check()} è pura
 * e si prova senza database; {@see forModel()} è la sola che legge dal
 * database.
 *
 * **Connessione latin1.** Il sito parla latin1 con MySQL: un'emoji o una
 * lettera fuori da latin1 scritta così com'è tornerebbe come `?`. {@see encode()}
 * scrive ogni carattere oltre l'ASCII come entità numerica, dopo aver scritto
 * `&` come `&amp;`, e {@see decode()} fa il percorso inverso: il testo torna
 * identico. La riga d'ordine si scrive solo con `encode()` e si legge solo
 * con `decode()`.
 */
final class Customizations
{
    public const KINDS = ['text', 'number', 'choice'];

    /** I decimali che un numero può avere. */
    public const MAX_DECIMALS = 6;

    /**
     * Le personalizzazioni attive di un articolo, nell'ordine in cui il cliente
     * le vede.
     *
     * @return list<array{id:int, name:string, label:string, help_text:string, kind:string, max_length:int, decimals:int, surcharge:string, required:bool, options:list<array{id:int, label:string, surcharge:string}>}>
     */
    public static function forModel(int $modelId): array
    {
        if ($modelId <= 0) {
            return [];
        }

        $links = ProductModelCustomization::find(['product_model_id' => $modelId, 'deleted' => 'false'], null, 'position', 'ASC');
        $out = [];

        foreach (is_array($links) ? $links : [] as $link) {
            $row = Customization::find(['id' => (int) $link['customization_id'], 'deleted' => 'false', 'active' => 'true'], 1);

            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }

            $options = [];

            if (($row['kind'] ?? '') === 'choice') {
                $rows = CustomizationOption::find(['customization_id' => (int) $row['id'], 'deleted' => 'false'], null, 'position', 'ASC');

                foreach (is_array($rows) ? $rows : [] as $option) {
                    $options[] = [
                        'id' => (int) $option['id'],
                        'label' => self::plain($option['label'] ?? ''),
                        'surcharge' => self::money($option['surcharge'] ?? 0),
                    ];
                }
            }

            $name = self::plain($row['name'] ?? '');

            $out[] = [
                'id' => (int) $row['id'],
                'name' => $name,
                'label' => self::plain($row['label'] ?? '') !== '' ? self::plain($row['label']) : $name,
                'help_text' => self::plain($row['help_text'] ?? ''),
                'kind' => (string) $row['kind'],
                'max_length' => (int) ($row['max_length'] ?? 0),
                'decimals' => (int) ($row['decimals'] ?? 0),
                'surcharge' => self::effectiveSurcharge($row['surcharge'] ?? 0, $link['surcharge'] ?? null),
                'required' => ($link['is_required'] ?? 'false') === 'true',
                'options' => $options,
            ];
        }

        return $out;
    }

    /**
     * I valori mandati dal cliente, controllati sulle personalizzazioni
     * dell'articolo.
     *
     * @param  array<int|string, mixed> $values  id della personalizzazione => testo o id dell'opzione
     * @return array{fields:list<array{customization_id:int, label:string, value:string, option_id:int, surcharge:string}>, surcharge:string}
     *
     * @throws UserError con l'id della personalizzazione sbagliata in `field()`
     */
    public static function resolve(int $modelId, array $values): array
    {
        return self::check(self::forModel($modelId), $values);
    }

    /**
     * Il controllo vero e proprio, senza database.
     *
     * @param  list<array<string, mixed>> $definitions come le dà {@see forModel()}
     * @param  array<int|string, mixed>   $values
     * @return array{fields:list<array{customization_id:int, label:string, value:string, option_id:int, surcharge:string}>, surcharge:string}
     */
    public static function check(array $definitions, array $values): array
    {
        $byId = [];

        foreach ($definitions as $definition) {
            $byId[(int) $definition['id']] = $definition;
        }

        foreach (array_keys($values) as $id) {
            if (!isset($byId[(int) $id])) {
                throw UserError::make('customization.unknown')->withField((int) $id);
            }
        }

        $fields = [];
        $total = 0.0;

        foreach ($byId as $id => $definition) {
            $raw = $values[$id] ?? $values[(string) $id] ?? '';
            $field = match ($definition['kind'] ?? 'text') {
                'choice' => self::checkChoice($definition, $raw),
                'number' => self::checkNumber($definition, $raw),
                default => self::checkText($definition, $raw),
            };

            if ($field === null) {
                if (!empty($definition['required'])) {
                    throw UserError::make('customization.required')->withField($id);
                }

                continue;
            }

            $fields[] = $field;
            $total += (float) $field['surcharge'];
        }

        return ['fields' => $fields, 'surcharge' => self::money($total)];
    }

    /**
     * Le regole del form di una personalizzazione, senza database.
     *
     * @param array<string, mixed>             $values  i campi della personalizzazione
     * @param list<array<string, mixed>>       $options le righe del repeater delle opzioni
     *
     * @throws UserError
     */
    public static function assertDefinition(array $values, array $options): void
    {
        $kind = (string) ($values['kind'] ?? '');

        if (!in_array($kind, self::KINDS, true)) {
            throw UserError::make('customization.kind');
        }

        if (self::negative($values['surcharge'] ?? 0)) {
            throw UserError::make('customization.surcharge');
        }

        if ($kind === 'number') {
            $decimals = $values['decimals'] ?? '';

            if (!is_numeric($decimals) || (float) $decimals != (int) $decimals || (int) $decimals < 0 || (int) $decimals > self::MAX_DECIMALS) {
                throw UserError::make('customization.decimals');
            }

            return;
        }

        if ($kind === 'text') {
            $max = $values['max_length'] ?? '';

            if (!is_numeric($max) || (float) $max != (int) $max || (int) $max < 1 || (int) $max > 1000) {
                throw UserError::make('customization.max_length');
            }

            return;
        }

        $named = 0;

        foreach ($options as $option) {
            if (trim((string) ($option['label'] ?? '')) === '') {
                continue;
            }

            if (self::negative($option['surcharge'] ?? 0)) {
                throw UserError::make('customization.surcharge');
            }

            $named++;
        }

        if ($named < 2) {
            throw UserError::make('customization.few_options');
        }
    }

    /**
     * La stringa da scrivere su `gst_order_items.customization`: una lista
     * JSON di soli caratteri ASCII, `''` se non c'è niente.
     *
     * @param list<array<string, mixed>> $fields
     */
    public static function encode(array $fields): string
    {
        if ($fields === []) {
            return '';
        }

        $safe = static fn (string $s): string => mb_encode_numericentity(
            str_replace('&', '&amp;', $s),
            [0x80, 0x10FFFF, 0, 0x1FFFFF],
            'UTF-8'
        );

        return (string) json_encode(array_map(static fn (array $f): array => [
            'customization_id' => (int) $f['customization_id'],
            'label' => $safe((string) $f['label']),
            'value' => $safe((string) $f['value']),
            'option_id' => (int) ($f['option_id'] ?? 0),
            'surcharge' => (string) $f['surcharge'],
        ], array_values($fields)));
    }

    /**
     * Quello che c'è su una riga, come lista di campi.
     *
     * Una stringa salvata si decodifica; una lista arriva già letta (la
     * consegna `Cart::contents()`) e si normalizza soltanto: decodificarla di
     * nuovo trasformerebbe un `&amp;` scritto dal cliente in `&`.
     *
     * @return list<array{customization_id:int, label:string, value:string, option_id:int, surcharge:string}>
     */
    public static function decode(mixed $stored): array
    {
        if (is_string($stored)) {
            $list = $stored === '' ? [] : json_decode($stored, true);
            $plain = static fn (mixed $s): string => html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            $list = $stored;
            $plain = static fn (mixed $s): string => (string) $s;
        }

        if (!is_array($list)) {
            return [];
        }

        $out = [];

        foreach ($list as $item) {
            if (!is_array($item) || (int) ($item['customization_id'] ?? 0) <= 0) {
                continue;
            }

            $out[] = [
                'customization_id' => (int) $item['customization_id'],
                'label' => $plain($item['label'] ?? ''),
                'value' => $plain($item['value'] ?? ''),
                'option_id' => (int) ($item['option_id'] ?? 0),
                'surcharge' => self::money($item['surcharge'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Uguale per gli stessi valori, qualunque sia l'ordine o il tipo degli id:
     * due righe con la stessa firma sono la stessa riga del carrello.
     *
     * @param list<array<string, mixed>> $fields
     */
    public static function signature(array $fields): string
    {
        $parts = array_map(static fn (array $f): array => [
            (int) $f['customization_id'],
            (int) ($f['option_id'] ?? 0),
            (string) $f['value'],
        ], array_values($fields));

        usort($parts, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $parts === [] ? '' : (string) json_encode($parts);
    }

    /**
     * I valori di una riga, nella forma che {@see check()} si aspetta: l'id
     * dell'opzione per una scelta, il testo per un testo.
     *
     * @param  list<array<string, mixed>> $fields
     * @return array<int, int|string>
     */
    public static function valuesOf(array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $option = (int) ($field['option_id'] ?? 0);
            $values[(int) $field['customization_id']] = $option > 0 ? $option : (string) $field['value'];
        }

        return $values;
    }

    /**
     * Le righe da mostrare sotto il nome: «Incisione: Marco». Testo semplice:
     * chi stampa lo escapa.
     *
     * @param  array<string, mixed> $item una riga d'ordine o di carrello
     * @return list<string>
     */
    public static function lines(array $item): array
    {
        return array_map(
            static fn (array $f): string => $f['label'].': '.$f['value'],
            self::decode($item['customization'] ?? '')
        );
    }

    /** @return array<string, mixed>|null */
    private static function checkText(array $definition, mixed $raw): ?array
    {
        $value = self::cleanText(is_scalar($raw) ? (string) $raw : '');

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > (int) $definition['max_length']) {
            throw UserError::make('customization.too_long', ['max' => (int) $definition['max_length']])
                ->withField((int) $definition['id']);
        }

        return [
            'customization_id' => (int) $definition['id'],
            'label' => (string) $definition['label'],
            'value' => $value,
            'option_id' => 0,
            'surcharge' => self::money($definition['surcharge'] ?? 0),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function checkNumber(array $definition, mixed $raw): ?array
    {
        $id = (int) $definition['id'];
        $decimals = max(0, min(self::MAX_DECIMALS, (int) ($definition['decimals'] ?? 0)));
        $text = is_scalar($raw) ? preg_replace('/[\s\x{a0}\x{202f}]+/u', '', (string) $raw) : '';

        if ($text === '' || $text === null) {
            return null;
        }

        // Come si scrive in Italia: «1.250,5» o «1250,5»; il punto da solo è
        // il decimale. `Numbers::fromForm` toglierebbe un'unità in coda
        // («12 cm»): qui chi compra deve scrivere un numero e basta.
        $written = str_replace(',', '.', str_contains($text, ',') ? str_replace('.', '', $text) : $text);

        // Solo cifre e un punto: niente segno, esponente o esadecimale. Con
        // più di quindici cifre un float non le tiene tutte.
        if (!preg_match('/^\d+(\.\d+)?$/', $written) || strlen(str_replace('.', '', $written)) > 15) {
            throw UserError::make('customization.not_number')->withField($id);
        }

        $fraction = strlen(rtrim(explode('.', $written)[1] ?? '', '0'));

        if ($fraction > $decimals) {
            throw ($decimals === 0
                ? UserError::make('customization.whole_number')
                : UserError::make('customization.too_many_decimals', ['max' => $decimals])
            )->withField($id);
        }

        return [
            'customization_id' => $id,
            'label' => (string) $definition['label'],
            'value' => number_format((float) $written, $decimals, ',', ''),
            'option_id' => 0,
            'surcharge' => self::money($definition['surcharge'] ?? 0),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function checkChoice(array $definition, mixed $raw): ?array
    {
        $id = is_scalar($raw) ? (int) $raw : 0;

        if ($id === 0 && (!is_scalar($raw) || trim((string) $raw) === '')) {
            return null;
        }

        foreach ($definition['options'] ?? [] as $option) {
            if ((int) $option['id'] === $id) {
                return [
                    'customization_id' => (int) $definition['id'],
                    'label' => (string) $definition['label'],
                    'value' => (string) $option['label'],
                    'option_id' => $id,
                    'surcharge' => self::money((float) ($definition['surcharge'] ?? 0) + (float) $option['surcharge']),
                ];
            }
        }

        throw UserError::make('customization.bad_option')->withField((int) $definition['id']);
    }

    /** UTF-8 valido, a capo come `\n`, niente caratteri di controllo, senza spazi ai bordi. */
    private static function cleanText(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/(?!\n)\p{Cc}/u', '', $text);

        return trim($text);
    }

    /** Un'etichetta salvata dal core, con le entità del sanitize, in testo. */
    private static function plain(mixed $stored): string
    {
        return html_entity_decode((string) $stored, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Il sovrapprezzo che vale su un articolo: quello scritto sul suo
     * collegamento, anche se è zero, o — se è vuoto — quello della
     * personalizzazione.
     */
    public static function effectiveSurcharge(mixed $own, mixed $onModel): string
    {
        $override = Numbers::fromForm($onModel);

        return self::money($override ?? Numbers::fromForm($own) ?? 0);
    }

    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Un sovrapprezzo lasciato vuoto vale zero; scritto con la virgola («1,50»)
     * si legge come in un form. Negativo o non numero è un errore.
     */
    private static function negative(mixed $amount): bool
    {
        if ($amount === null || (is_string($amount) && trim($amount) === '')) {
            return false;
        }

        $number = Numbers::fromForm($amount);

        return $number === null || (float) $number < 0;
    }
}
