<?php

declare(strict_types=1);

namespace OrbitaDigital\OdBydemes;

use Db;

class Bydemes
{
    //available stock -> quantity in ps_stock_available table, requires id_product (from ps_product table)
    // id_stock_available must be grouped when id_product is the same
    public static function getBydemesProducts()
    {
        return Db::getInstance()->executeS('SELECT p.reference, sa.quantity, pl.description, pl.description_short, pl.name, p.width, p.height, p.depth AS volume, p.reference, ma.name AS manufacturer
        FROM `ps_product` p 
        INNER JOIN `ps_stock_available` sa ON p.id_product = sa.id_product
        INNER JOIN  `ps_product_lang` pl ON p.id_product = pl.id_product 
        INNER JOIN `ps_manufacturer` ma ON p.id_manufacturer = ma.id_manufacturer WHERE p.id_supplier = 1 AND id_lang = 1');
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
