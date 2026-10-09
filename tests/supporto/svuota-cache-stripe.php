<?php
/** php tests/supporto/svuota-cache-stripe.php — svuota `stripe_methods_cache` nel DB di prova (lo fa tests/run.php all'avvio). */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';

use Wonder\Plugin\Gestionale\Models\System\Setting;

$id = (int) (Setting::current()['id'] ?? 0);

if ($id > 0) {
    Setting::update(['stripe_methods_cache' => ''], $id);
}

echo "Cache dei tipi Stripe svuotata.\n";
