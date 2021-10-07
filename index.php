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
    die('header is not the same than the file');
}
$data_file = $reader->read();
if (!$data_file) {
    die($reader->getLastError());
}
$bydemes = new Bydemes($data_file);

$process = $bydemes->processCsv();
if (!$process) {
    die('error recovering the products in the database');
}
//TODO catch later (caution cuz info is added in the table)
    $bydemes->saveProducts();


$result = $bydemes->getTable();

echo '<p>Write today date to update the list</p>';
echo $result;
