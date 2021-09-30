<?php

use OrbitaDigital\OdBydemes\Bydemes;
use OrbitaDigital\OdBydemes\Csv;

require_once __DIR__ . '/vendor/autoload.php';

if (!defined('_PS_VERSION_')) {
    require_once '../../config/config.inc.php';
    require_once '../../init.php';
}

$reader = new Csv(
    'rates/CL131545_product_list.csv',
    ['id_csv', 'reference', 'model', 'manufacturer_name', 'quantity', 'active', 'price', 'description', 'description_short', 'name', 'category', 'family', 'subfamily', 'compatible_products', 'imageURL', 'EAN', 'length', 'width', 'height', 'depth', 'weight', 'product_url'],
    ',',
    10000
);

if (!$reader->checkHeader(['id', 'referencia', 'Model', 'Brand', 'Stock', 'activo', 'PVP', 'Description', 'Short description', 'Title', 'Category', 'Family', 'SubFamily', 'Compatible products', 'imageURL', 'EAN', 'length', 'width', 'height', 'volume', 'weight', 'Product URL'])) {
    die($this->lastError = 'header is not fine');
}
$data_file = $reader->read();
$bydemes = new Bydemes($data_file);
$process_csv = $bydemes->processCsv();
$save = $bydemes->saveProducts();
if($save==false){
    die('<h3>Error obtaining the data</h3>');
}
$result = $bydemes->getTable();
if (!$result) {
    die('<p>query couldnt be done</p>');
}
echo '<p>Write today date to update the list</p>';
echo $result;