<?php

declare(strict_types=1);

namespace OrbitaDigital\OdBydemes;

use Db;

class Bydemes
{
    //get all products from Bydemes supplier

    public function getBydemesProducts()
    {
        $query = Db::getInstance()->executeS('SELECT p.reference, pl.description, pl.description_short, pl.name, p.price, p.width, p.height, p.depth, p.reference, ma.name AS ma_name
        FROM `ps_product` p 
        INNER JOIN `ps_stock_available` sa ON p.id_product = sa.id_product
        INNER JOIN `ps_product_lang` pl ON p.id_product = pl.id_product 
        INNER JOIN `ps_manufacturer` ma ON p.id_manufacturer = ma.id_manufacturer
        INNER JOIN `ps_supplier` su ON p.id_supplier = su.id_supplier WHERE su.name = "bydemes" AND id_lang = 1');
        if($query === false){
            return false;
        }
        foreach ($query as $value) {
            foreach ($value as $key2 => $value2) {
                $bydemes_product[$value['reference']][$key2] = $value2;
            }
        }
        return $bydemes_product;
    }
    
    public function processCsv(array $data)
    {
        //var_dump($data);
        //key2 -> header values
        //value2 ->  row value
        foreach ($data as $row => $row_values) {
            $full[] = $this->getRowInfo($row_values['reference']);
        }
        return $full;
    }
    public function getRowInfo(string $ref){
        return Db::getInstance()->executeS('SELECT p.reference, p.width, p.height, p.depth AS volume, p.reference FROM `ps_product` p WHERE p.reference = "'.$ref.'"');
    }
}
