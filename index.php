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
    ['id_product', 'reference', 'model', 'manufacturer_name', 'stock', 'active', 'price', 'description', 'description_short', 'name', 'category', 'family', 'subfamily', 'comptaible_products', 'imageURL', 'EAN', 'length', 'width', 'height', 'depth', 'weight', 'product_url'],
    ',',
    100
);

if (!$reader->checkHeader(['id', 'referencia', 'Model', 'Brand', 'Stock', 'activo', 'PVP', 'Description', 'Short description', 'Title', 'Category', 'Family', 'SubFamily', 'Compatible products', 'imageURL', 'EAN', 'length', 'width', 'height', 'volume', 'weight', 'Product URL'])) {
    die($this->lastError = 'header is not fine');
}
$data_file = $reader->read();
$bydemes = new Bydemes($data_file);

$result = $bydemes->processCsv();
if (!$result) {
    die('query couldnt be done');
}
echo $bydemes->getProcessTable();

if(isset($_GET['write'])){
    $date = $_GET['write'];
    $currentDate = date('d_m_Y');
    if($date !== $currentDate){
        die('write today to update the values');
    }
    else{
        $save = $bydemes->saveProducts();
        if($save === false){
            die('Values couldnt be updated');
        }
        else{
            die('Values were updated');
        }
    }
}

