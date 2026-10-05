<?php
/** php tests/DiscountCampaignResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaign;
use Wonder\Plugin\Gestionale\Resources\Promotions\DiscountCampaignResource;

/** I nomi dei campi del form, nell'ordine dichiarato. @return list<string> */
$campi = static fn (): array => array_map(
    static fn ($input): string => (string) $input->name,
    DiscountCampaignResource::formSchema()
);

check('la pagina sta sotto Promozioni, dietro la funzionalità', function () {
    $menu = DiscountCampaignResource::navigationSchema()->toArray();

    return DiscountCampaignResource::$feature === 'discount_campaigns'
        && DiscountCampaignResource::$model === DiscountCampaign::class
        && DiscountCampaignResource::$docsPage === 'promozioni/promozioni-campagne'
        && DiscountCampaignResource::path() === 'app/gestionale/campagne-sconto'
        && ($menu['section_key'] ?? '') === 'promozioni'
        && ($menu['section_title'] ?? 'Promozioni') === 'Promozioni';
});

check('il form ha i campi della campagna e quelli del selettore', function () use ($campi) {
    $attesi = [
        'name', 'discount_type', 'discount_value', 'starts_at', 'ends_at', 'active',
        'exclude_sale_products', 'applies_online', 'applies_office', 'applies_pos',
        'applies_to_all', 'categories', 'tags', 'brands', 'models', 'excluded_models', 'note',
    ];

    return array_diff($attesi, $campi()) === [];
});

check('categorie, tag, marchi e articoli scelti si vedono solo con «Solo la selezione»', function () {
    foreach (['categories', 'tags', 'brands', 'models'] as $campo) {
        $regola = DiscountCampaignResource::getInput($campo)->conditionalAttributes();

        if (($regola['data-visible-when'] ?? '') !== 'applies_to_all' || ($regola['data-visible-when-values'] ?? '') !== 'false') {
            return false;
        }
    }

    return true;
});

check('gli articoli esclusi si vedono sempre: «tutto il catalogo meno questi» è una scelta legittima', function () {
    return DiscountCampaignResource::getInput('excluded_models')->conditionalAttributes() === [];
});

check('il selettore offre «Tutto il catalogo» e «Solo la selezione»', function () {
    $campo = DiscountCampaignResource::getInput('applies_to_all');
    $schema = (new ReflectionProperty($campo, 'schema'))->getValue($campo);
    $opzioni = $schema['options'] ?? [];

    return array_keys($opzioni) === ['true', 'false']
        && $opzioni['true'] === 'Tutto il catalogo'
        && $opzioni['false'] === 'Solo la selezione';
});

check('le etichette dell\'elenco e del form ci sono tutte', function () {
    $etichette = DiscountCampaignResource::labelSchema();

    return array_diff(['name', 'discount_value', 'period', 'channels', 'status'], array_keys($etichette)) === [];
});

check('con la funzionalità spenta la pagina non c\'è e il menu la nasconde', function () {
    // Senza database la funzionalità risulta spenta.
    return DiscountCampaignResource::featureActive() === false
        && (DiscountCampaignResource::navigationSchema()->toArray()['enabled'] ?? true) === false
        && DiscountCampaignResource::pageSchema()->toArray() !== [];
});

check('lo sconto si legge «20 %» o «10,00 €»', function () {
    return DiscountCampaignResource::discountLabel(['discount_type' => 'percent', 'discount_value' => '20.00']) === '20 %'
        && DiscountCampaignResource::discountLabel(['discount_type' => 'percent', 'discount_value' => '12.50']) === '12,5 %'
        && DiscountCampaignResource::discountLabel(['discount_type' => 'amount', 'discount_value' => '10.00']) === '10,00 €';
});

check('lo stato si ricava dalle date e dall\'interruttore', function () {
    $riga = ['active' => 'true', 'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-10 23:59:59'];

    return DiscountCampaignResource::statusLabel($riga, '2026-10-05 12:00:00') === 'In corso'
        && DiscountCampaignResource::statusLabel($riga, '2026-09-30 12:00:00') === 'Programmata'
        && DiscountCampaignResource::statusLabel($riga, '2026-10-11 00:00:00') === 'Terminata'
        && DiscountCampaignResource::statusLabel(['active' => 'false'] + $riga, '2026-10-05 12:00:00') === 'Disattivata';
});

check('i canali si leggono per nome', function () {
    return DiscountCampaignResource::channelsLabel(['applies_online' => 'true', 'applies_office' => 'false', 'applies_pos' => 'true']) === 'Online, Cassa'
        && DiscountCampaignResource::channelsLabel(['applies_online' => 'false', 'applies_office' => 'false', 'applies_pos' => 'false']) === '—';
});

check('il periodo si legge dalle date, «sempre» se mancano', function () {
    return DiscountCampaignResource::periodLabel(['starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-10 23:59:59']) === 'dal 01/10/2026 al 10/10/2026'
        && DiscountCampaignResource::periodLabel(['starts_at' => '2026-10-01 00:00:00', 'ends_at' => '']) === 'dal 01/10/2026'
        && DiscountCampaignResource::periodLabel(['starts_at' => '', 'ends_at' => '2026-10-10 23:59:59']) === 'fino al 10/10/2026'
        && DiscountCampaignResource::periodLabel(['starts_at' => '', 'ends_at' => '']) === 'sempre';
});

check('il selettore letto dalla richiesta ha id interi, senza doppioni né zeri', function () {
    $scope = DiscountCampaignResource::readScope([
        'applies_to_all' => 'false',
        'categories' => ['3', '3', '0', 'x', '7'],
        'tags' => '5',
        'brands' => [],
        'models' => ['9', 9, '-2'],
    ]);

    return $scope === [
        'all' => false,
        'categories' => [3, 7],
        'tags' => [],
        'brands' => [],
        'models' => [9],
        'excluded_models' => [],
    ];
});

check('«Tutto il catalogo» si legge dalla richiesta, e un valore storto vale «Solo la selezione»', function () {
    return DiscountCampaignResource::readScope(['applies_to_all' => 'true', 'excluded_models' => ['4']])['all'] === true
        && DiscountCampaignResource::readScope(['applies_to_all' => 'true', 'excluded_models' => ['4']])['excluded_models'] === [4]
        && DiscountCampaignResource::readScope(['applies_to_all' => 'forse'])['all'] === false
        && DiscountCampaignResource::readScope([])['all'] === false;
});

check('la campagna si apre in una scheda di lettura, con «Modifica» in testata', function () {
    $pagine = DiscountCampaignResource::pageSchema()->toArray();
    $azioni = $pagine['actions']['view'] ?? null;
    $pulsanti = is_callable($azioni) ? $azioni(['id' => 7]) : (array) $azioni;

    return !empty($pagine['pages']['view'])
        && str_ends_with((string) ($pagine['views']['show'] ?? ''), 'pages/campaign-show.php')
        && ($pulsanti[0]['label'] ?? '') === 'Modifica'
        && str_contains((string) ($pulsanti[0]['href'] ?? ''), '7');
});

check('l\'elenco porta alla scheda: il nome la apre e «Visualizza» sta tra le azioni', function () {
    $colonne = [];

    foreach (DiscountCampaignResource::tableSchema() as $colonna) {
        $colonne[(string) $colonna->name] = $colonna;
    }

    $nome = (new ReflectionProperty($colonne['name'], 'schema'))->getValue($colonne['name']);
    $azioni = (new ReflectionProperty($colonne['actions'], 'schema'))->getValue($colonne['actions']);

    return str_contains(json_encode($nome), 'view') && array_key_exists('view', (array) ($azioni['actions'] ?? []));
});

check('la scheda ha dettagli, prodotti, anteprima e, di lato, dove vale', function () {
    $layout = DiscountCampaignResource::showLayoutSchema(['id' => 0, 'name' => 'Saldi', 'discount_type' => 'percent', 'discount_value' => '20.00', 'note' => 'Autunno']);
    $titoli = [];
    $scendi = function (array $componenti) use (&$scendi, &$titoli): void {
        foreach ($componenti as $c) {
            if ($c instanceof Wonder\Elements\Components\Container) {
                $scendi($c->components);
            } elseif (($c->components[0] ?? null) instanceof Wonder\Elements\Components\SectionTitle) {
                $titoli[] = (string) (new ReflectionProperty($c->components[0], 'text'))->getValue($c->components[0]);
            }
        }
    };
    $scendi($layout->components);

    return $titoli === ['Campagna', 'Prodotti', 'Anteprima', 'Dove vale', 'Note'];
});

summary();
