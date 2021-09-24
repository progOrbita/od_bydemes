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
                //formats values from csv to be compared with the database ones
                $formatedValues = $this->formatCsv($csv_values);
                foreach ($csv_values as $key => $value) {
                    if(!isset($bydemes_products[$csv_ref][$key])){
                        continue;
                    }
                    //removes 0 from database fields. Shows if values are different
                    if(trim($bydemes_products[$csv_ref][$key]) != $formatedValues[$key]){
                        $tableContent .= '<td>'.$key.' valor diferente</td>';
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
    /**
     * Format the Csv values so they can be compared with the ones inserted on the database.
     * @param array $csv_values array with the values of the csv of a row (chosed by reference)
     * @return array $csv_values array with the formated values
     */
    public function formatCsv(array $csv_values){
        foreach ($csv_values as $header => $row_value) {
            switch($header){
                //replace needed because numbers use . not ,
                case 'price':
                $csv_values[$header] = str_replace(",",".",$row_value);
                break;
                //For dimensions, changes letters to 0 (after removing lots of empty space)
                case 'width':
                case 'length':
                case 'height':
                case 'depth':
                    $csv_values[$header] = preg_replace('/[a-z]+/i','',trim($row_value));   
                    if(empty($row_value)){
                        $csv_values[$header] = 0;
                    }
                break;
                //replace if there's "" to only one. Then removes " at the beggining and the end if they exists.
                case 'name':
                $inches = str_replace('""','"',$row_value);
                $csv_values[$header] = preg_replace('/^"|"$/','',$inches);
                break;
                //database keeps <p> in the field
                case 'description_short':
                $csv_values[$header] = '<p>'.trim($row_value).'</p>';
                break;
                //Encode the string due to the existence of strings like iacute; or oacute; which needs to be encoded
                //It may have empty spaces and isn't closed in csv with <p>, which I need to add to compare both values
                case 'description':
                    $desc_clean = trim($row_value);
                    stristr($desc_clean,'<p>') ? $desc_encoded = $desc_clean : $desc_encoded = '<p>'.$desc_clean.'</p>' ;

                $csv_values[$header] = html_entity_decode($desc_encoded,ENT_NOQUOTES,'UTF-8');
            }
        }
            //  if($csv_values['reference']== 'FOC-301'){
            //      Tools::dieObject($csv_values);
            //  }
        return $csv_values;
    }
}
