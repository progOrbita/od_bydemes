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

        //Data = array with the references
        //bydemes_products = array with the bydemes products in the database key = reference

        $bydemes_products = $this->getBydemesProducts();
        if(!$bydemes_products){
            return false;
        }
        $tableContent = '';
        $tableContent .= '<table>
        <thead><th>Referencia</th><th>Existe</th><th>Values</th></thead><tbody>';
        foreach ($data as $csv_values) {
            $csv_ref = $csv_values['reference'];
            if(array_key_exists($csv_ref,$bydemes_products)){
                $tableContent .= '<tr><td>'.$csv_values['reference'].'</td><td>existe</td>';
                //obtaining the fields if exists in both. should be done here. Before, managing the different data.
                foreach ($csv_values as $key => $value) {
                    $formatedValues = $this->formatCsv($csv_values);
                    if(!isset($bydemes_products[$csv_ref][$key])){
                        continue;
                    }
                    if($bydemes_products[$csv_ref][$key] != $formatedValues[$key]){
                        $tableContent .= '<td>'.$key.' valor diferente</td>';
                    }
                    else{
                        $tableContent .= '<td>'.$key.' valor igual</td>';
                    }
                }
                $tableContent .= '</tr>';
            }
            else{
                $tableContent .= '<tr><td>'.$csv_ref.'</td><td>no existe</td></tr>';
            }
        }
        $tableContent .= '</tbody></table>';
        return $tableContent;
    }
    public function getRowInfo(string $ref){
        return Db::getInstance()->executeS('SELECT p.reference, p.width, p.height, p.depth AS volume, p.reference FROM `ps_product` p WHERE p.reference = "'.$ref.'"');
    }
}
