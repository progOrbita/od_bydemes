<?php

declare(strict_types=1);

namespace OrbitaDigital\OdBydemes;

use Db;

class Bydemes
{
    private $csv_data = [];
    /**
     * constructor
     */
    function __construct(array $csv_data)
    {
        $this->csv_data = $csv_data;
    }
    /**
     * Query that obtains the products from bydemes supplier
     * @return bool|array false if query have an error, array obtained from the query
     */
    public function getBydemesProducts()
    {
        $query = Db::getInstance()->executeS('SELECT p.reference, pl.description, pl.description_short, pl.name, p.price, p.width, p.height, p.depth, p.weight, p.reference ,ma.name AS manufacturer_name, sa.quantity AS stock
        FROM `ps_product` p 
        INNER JOIN `ps_stock_available` sa ON p.id_product = sa.id_product
        INNER JOIN `ps_product_lang` pl ON p.id_product = pl.id_product 
        INNER JOIN `ps_manufacturer` ma ON p.id_manufacturer = ma.id_manufacturer
        INNER JOIN `ps_supplier` su ON p.id_supplier = su.id_supplier WHERE su.name = "bydemes" AND id_lang = 1');
        if ($query === false) {
            return false;
        }
        foreach ($query as $value) {
            foreach ($value as $key2 => $value2) {
                $bydemes_product[$value['reference']][$key2] = $value2;
            }
        }
        return $bydemes_product;
    }
    /**
     * creates the table with the information
     * @return string html of the table
     */
    public function getProcessTable()
    {
        $csv_processed = $this->processCsv();
        $tableBase = '<head>
            <style>
                td {
                    border: 1px solid black;
                    padding: 4px;
                }
            </style>
        </head><table>
        <thead><th>Referencia</th><th>In database?</th><th>Changed values</th></thead>
        <tbody>';
        $tableBody = '';
        foreach ($csv_processed as $key => $value) {
            if ($value === false) {
                $tableBody .= '<tr><td>' . $key . '</td><td>doesnt exist</td></tr>';
            } else {
                $tableBody .= '<tr><td>' . $key . '</td><td>exist</td>';
                //Shows differents values from the database
                foreach ($value as $key2 => $value2) {
                    $tableBody .= '<td>' . $key2 . ': ' . $value2 . '</td>';
                }
                $tableBody .= '</tr>';
            }
        }
        $tableEnd = '</tbody></table>';

        return $tableBase . $tableBody . $tableEnd;
    }
    /**
     * checks if reference must be saved in the database
     */
    public function saveCsv()
    {
        //It saves an array with the references and their row values
        $bydemes_products = $this->getBydemesProducts();
        foreach ($this->csv_data as $csv_values) {
            $csv_ref = $csv_values['reference'];

            //if reference dont exist in the database
            if (!array_key_exists($csv_ref, $bydemes_products)) {
                //echo $csv_ref.' ni esta <br/>';
                $refs_info[$csv_ref] = $csv_values;
            }
            $processedValues[$csv_ref] = 'no hace falta';
        }
        return $refs_info;
    }
    /**
     * Process the csv information, checking if fields exist or if they are different within the database
     * @return bool|array false if there's an error in the query. Array with the processed information
     */
    public function processCsv()
    {

        //Data = array with the references
        //bydemes_products = array with the bydemes products in the database key = reference

        $bydemes_products = $this->getBydemesProducts();
        if (!$bydemes_products) {
            return false;
        }
        $processedValues = [];

        foreach ($this->csv_data as $csv_values) {
            $csv_ref = $csv_values['reference'];

            //if reference dont exist in the database
            if (!array_key_exists($csv_ref, $bydemes_products)) {
                $processedValues[$csv_ref] = false;
                continue;
            }


            //formats values from csv to be compared with the database ones
            $formatedValues = $this->formatCsv($csv_values);

            foreach ($csv_values as $field => $value) {
                if (!isset($bydemes_products[$csv_ref][$field])) {
                    continue;
                }
                //removes 0 from database fields. Store if values are different
                if (trim($bydemes_products[$csv_ref][$field]) != $formatedValues[$field]) {
                    $processedValues[$csv_ref][$field] = trim($bydemes_products[$csv_ref][$field], '\0') . ' to ' . $formatedValues[$field];
                }
            }
        }
        return $processedValues;
    }
    /**
     * Format the Csv values so they can be compared with the ones inserted on the database.
     * @param array $csv_values array with the values of the csv of a row (chosed by reference)
     * @return array $csv_values array with the formated values
     */
    public function formatCsv(array $csv_values)
    {
        foreach ($csv_values as $header => $row_value) {
            switch ($header) {
                    //replace needed because numbers use . not ,
                case 'price':
                    $csv_values[$header] = str_replace(",", ".", $row_value);
                    break;
                    //For dimensions, changes letters to 0 (after removing lots of empty space)
                case 'width':
                case 'length':
                case 'height':
                case 'depth':
                    $csv_values[$header] = preg_replace('/[a-z]+/i', '', trim($row_value));
                    if (empty($row_value)) {
                        $csv_values[$header] = 0;
                    }
                    break;
                    //replace if there's "" to only one and all the emtpy space. Then removes " at the beggining and the end if they exists.
                case 'name':
                    $inches = trim(str_replace('""', '"', $row_value));
                    $csv_values[$header] = preg_replace('/^"|"$/', '', $inches);
                    break;
                    //database keeps <p> in the field
                case 'description_short':
                    $csv_values[$header] = '<p>' . trim($row_value) . '</p>';
                    break;
                    //Encode the string due to the existence of strings like iacute; or oacute; which needs to be encoded
                    //It may have empty spaces and may not be closed in csv with <p>, which I need to add to compare both values
                case 'description':
                    $desc_clean = trim($row_value);
                    stristr($desc_clean, '<p>') ? $desc_encoded = $desc_clean : $desc_encoded = '<p>' . $desc_clean . '</p>';

                    $csv_values[$header] = html_entity_decode($desc_encoded, ENT_NOQUOTES, 'UTF-8');
            }
        }
        return $csv_values;
    }
}
