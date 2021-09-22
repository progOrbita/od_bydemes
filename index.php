<?php

use OrbitaDigital\OdBydemes\Bydemes;
use OrbitaDigital\OdBydemes\Csv;

require_once __DIR__ . '/vendor/autoload.php';

if (!defined('_PS_VERSION_')) {
    require_once '../../config/config.inc.php';
    require_once '../../init.php';
}

$file = 'rates/CL131545_product_list.csv';
$reader = new Csv(['id','referencia','Model','Brand','Stock','activo','PVP','Description','Short description','Title','Category','Family','SubFamily','Compatible products','imageURL','EAN','length','width','height','volume','weight','Product URL']);
$first = $reader->process([1 => $file]);
var_dump($first);
$bydemes_products = Bydemes::getBydemesProducts();

