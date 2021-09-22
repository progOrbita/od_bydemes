<?php

use OrbitaDigital\OdBydemes\Bydemes;
use OrbitaDigital\OdBydemes\Csv;

require_once __DIR__ . '/vendor/autoload.php';

if (!defined('_PS_VERSION_')) {
    require_once '../../config/config.inc.php';
    require_once '../../init.php';
}
include('src/Bydemes.php');
//el stock disponible es: quantity en ps_stock_available. Requiere del id_product (de ps_product)
// el id_stock_available deberia sumarse si el id_product es el mismo
var_dump(Bydemes::seeStock());$file = 'rates/CL131545_product_list.csv';
$reader = new Csv(['id','referencia','Model','Brand','Stock','activo','PVP','Description','Short description','Title','Category','Family','SubFamily','Compatible products','imageURL','EAN','length','width','height','volume','weight','Product URL']);
$reader->process([$file]);

