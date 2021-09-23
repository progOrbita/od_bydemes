<?php

use OrbitaDigital\OdBydemes\Bydemes;
use OrbitaDigital\OdBydemes\Csv;

require_once __DIR__ . '/vendor/autoload.php';

if (!defined('_PS_VERSION_')) {
    require_once '../../config/config.inc.php';
    require_once '../../init.php';
}

$reader = new Csv('rates/CL131545_product_list.csv',
    ['id_product','reference','model','manufacturer_name','stock','active','precio','description','description_short','name','category','family','subfamily','comptaible_products','imageURL','EAN','length','width','height','depth','weight','product_url'],
',',100);

if (!$reader->checkHeader(['id','referencia','Model','Brand','Stock','activo','PVP','Description','Short description','Title','Category','Family','SubFamily','Compatible products','imageURL','EAN','length','width','height','volume','weight','Product URL'])) {
    die($this->lastError = 'header is not fine');
}
$bydemes = new Bydemes();
$data_file = $reader->read();
$bydemes_products = Bydemes::getBydemesProducts();

