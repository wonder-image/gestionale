<?php
/** php tests/SettingsSectionsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\ResourceSchema\FormField;
use Wonder\Data\UploadSchema as Field;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Extensions\ProvidesSettings;
use Wonder\Plugin\Gestionale\Extensions\SettingsSection;
use Wonder\Plugin\Gestionale\Extensions\SettingsSections;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Resources\System\SettingResource;
use Wonder\Sql\TableSchema as Column;

/** Il riquadro di un modulo finto: una colonna, un campo, una pulizia. */
final class RiquadroDiProva extends SettingsSection
{
    public function columns(): array
    {
        return [Column::key('demo_motto')->length(40)->default('ciao')];
    }

    public function data(): array
    {
        return [Field::key('demo_motto')->text()->sanitize(false)];
    }

    public function fields(): array
    {
        return [FormField::key('demo_motto')->text()->label('Motto')];
    }

    public function labels(): array
    {
        return ['demo_motto' => 'Motto'];
    }

    public function card(Closure $input): Card
    {
        return (new Card)->components([
            SectionTitle::make('Prova')->columnSpan(12),
            $input('demo_motto')->columnSpan(12),
        ])->columns(12)->columnSpan(12);
    }

    public function mutate(array $values): array
    {
        if (array_key_exists('demo_motto', $values)) {
            $values['demo_motto'] = strtoupper(trim((string) $values['demo_motto']));
        }

        return $values;
    }
}

/** Un modulo finto che porta il riquadro. */
final class ModuloDiProva implements ProvidesSettings
{
    public static function settingsSections(): iterable
    {
        yield new RiquadroDiProva();
    }
}

$nomi = static function (iterable $cose): array {
    $nomi = [];
    foreach ($cose as $cosa) {
        $nomi[] = (string) ($cosa->name ?? $cosa->key);
    }

    return $nomi;
};

$titoli = static function (): array {
    $titoli = [];
    foreach (SettingResource::formLayoutSchema()->components[0]->components ?? [] as $card) {
        $primo = $card->components[0] ?? null;
        $titoli[] = $primo instanceof SectionTitle ? $primo->getText() : '';
    }

    return $titoli;
};

check('senza moduli nessun riquadro in più', function () use ($titoli) {
    SettingsSections::use([]);

    return SettingsSections::all() === []
        && $titoli() === ['Fiscale', 'Documenti', 'Vendite', 'Email'];
});

check('i riquadri arrivano dai moduli che li offrono, gli altri si saltano', function () {
    $riquadri = SettingsSections::fromEntrypoints([ModuloDiProva::class, stdClass::class, '', 'Classe\\Che\\Non\\Esiste']);

    return count($riquadri) === 1 && $riquadri[0] instanceof RiquadroDiProva;
});

check('le colonne e i dati del modulo entrano nella riga delle impostazioni', function () use ($nomi) {
    SettingsSections::use([new RiquadroDiProva()]);

    return in_array('demo_motto', $nomi(Setting::tableSchema()), true)
        && in_array('demo_motto', $nomi(Setting::dataSchema()), true)
        && in_array('tax_regime', $nomi(Setting::tableSchema()), true);
});

check('campo ed etichetta del modulo stanno nella pagina', function () use ($nomi) {
    SettingsSections::use([new RiquadroDiProva()]);

    return in_array('demo_motto', $nomi(SettingResource::formSchema()), true)
        && (SettingResource::labelSchema()['demo_motto'] ?? '') === 'Motto'
        && (SettingResource::labelSchema()['tax_regime'] ?? '') === 'Regime fiscale';
});

check('il riquadro del modulo sta dopo Vendite e prima di Email', function () use ($titoli) {
    SettingsSections::use([new RiquadroDiProva()]);

    return $titoli() === ['Fiscale', 'Documenti', 'Vendite', 'Prova', 'Email'];
});

check('il modulo pulisce i suoi valori al salvataggio', function () {
    SettingsSections::use([new RiquadroDiProva()]);
    $valori = SettingResource::mutateRequestValues(['demo_motto' => ' ciao '], 'update');

    return $valori['demo_motto'] === 'CIAO' && trim((string) ($valori['fiscal_confirmed_at'] ?? '')) !== '';
});

check('i campi del modulo seguono il resto della pagina: in produzione non si cambiano', function () {
    SettingsSections::use([new RiquadroDiProva()]);

    return SettingResource::editableWhenReadonly() === ['merchant_notification_emails'];
});

SettingsSections::use(null);

summary();
