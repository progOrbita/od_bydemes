<?php

declare(strict_types=1);

namespace OrbitaDigital\OdBydemes;

use Db;
use Product;

class Bydemes
{
    private $csv_data = [];
    private $changed_csv = [];
    private $brands = [];
    /**
     * constructor
     */
    function __construct(array $csv_data)
    {
        $this->csv_data = $csv_data;

        //obtains all the brands from the database
        $brands_query = Db::getInstance()->executeS('SELECT `id_manufacturer`,`name` FROM `ps_manufacturer`');

        foreach ($brands_query as $brand) {
            $this->brands[$brand['id_manufacturer']] = $brand['name'];
        }
    }
    /**
     * Obtains the database products based on the reference
     * @return array array with the references
     */
    private function getBydemesProducts()
    {
        $id_es = Db::getInstance()->getValue('SELECT `id_lang` FROM `ps_lang` WHERE `language_code` = "es" ');
        $bydemes_products = [];
        $ref_values = array_column($this->csv_data, 'reference');

        foreach ($ref_values as $value) {
            //Obtains products which reference exist on Prestashop
            $id = Product::getIdByReference($value);
            if ($id) {
                $bydemes_products[$value] = new Product($id, false, $id_es);
            }
        }
        return $bydemes_products;
    }
    /**
     * Update the reference values from the csv into the database
     * @return array $update, array with pairs of references/booleans if they were or no updated
     */
    public function saveProducts()
    {
        $save = [];
        //obtains id => ref from all bydemes products. 
        $bydemes_refs = Db::getInstance()->executeS('SELECT `id_product`,`reference` FROM `ps_product` WHERE `id_supplier` = 1');
        //adds prestashop id if reference exist in the array
        foreach ($bydemes_refs as $value) {
            if (array_key_exists($value['reference'], $this->changed_csv)) {
                $this->changed_csv[$value['reference']]['id_product'] = $value['id_product'];
            }
        }
        $bydemes_id = Db::getInstance()->getValue('SELECT `id_supplier` FROM `ps_supplier` WHERE `name` = "bydemes"');
        $default_category = "2"; // default category inicio

        //changed_csv is ref as key with the array of values changed
        foreach ($this->changed_csv as $ref => $ref_values) {

            //If no id is found in database, add the product
            if (!isset($ref_values['id_product'])) {

                $new_prod = new Product();
                foreach ($ref_values as $field => $field_value) {
                    //checking if property exist in the product
                    if (!property_exists($new_prod, $field)) {
                        continue;
                    }
                    if ($field === 'active') {
                        $field_value === 'false' ? $field_value = 0 : $field_value = 1;
                    }
                    $new_prod->$field = $field_value;
                }
                $new_prod->id_supplier = $bydemes_id;
                $new_prod->id_category_default = $default_category;
            } else {
                $id_product = $ref_values['id_product'];
                $object = new Product($id_product);
                foreach ($ref_values as $field => $field_value) {
                    $object->$field = $field_value;
                }
                $save[$ref] = $object->update();
            }

            //All the values that are modified added onto the object then update
            $save[$ref] = $object->update();
        }
        //return reference with true/false if query was done
        return $save;
    }
    /**
     * creates the table with the information obtained from processing the csv information within the database
     * @return bool|string false if there was an error processing the information, string with the table otherwise
     */
    public function getProcessTable()
    {
        $csv_processed = $this->processCsv();
        if (!$csv_processed) {
            return false;
        }
        $tableBase = '<html><head>
            <style>
                td {
                    border: 1px solid black;
                    padding: 4px;
                    min-width: 100px;
                }
            </style>
        </head>
        <body>
            <h2>Update products</h2>
            <table>
        <thead><th>Reference</th><th>In database?</th><th>Information</th></thead>
        <tbody>';
        $tableBody = '';
        foreach ($csv_processed as $ref => $value) {
            if ($value === false) {
                $tableBody .= '<tr><td>' . $ref . '</td><td> Dont exist</td><td>Product will be created</td></tr>';
                continue;
            }
            $tableBody .= '<tr><td>' . $ref . '</td><td>exist</td>';
            //Shows wrong values beetwen database and csv
            if (empty($value)) {
                $tableBody .= '<td>Product up to date</td>';
            }
            foreach ($value as $ref_header => $ref_value) {
                $tableBody .= '<td>' . $ref_header . ' ' . $ref_value . '</td>';
            }
            $tableBody .= '</tr>';
        }
        $tableEnd = '</tbody></table></body></html>';

        return $tableBase . $tableBody . $tableEnd;
    }
    /**
     * Process the csv information, checking if fields exist or if they are different within the database
     * @return bool|array false if there's an error in the query. Array with the processed information
     */
    private function processCsv()
    {
        /**
         * Process the csv information, checking if fields exist or if they are different within the database
         * @return bool|array false if there's an error in the query. Array with the processed information
         */

        //Data = array with the references
        //bydemes_products = array with the bydemes products in the database key = reference

        $bydemes_products = $this->getBydemesProducts();
        if (!$bydemes_products) {
            return false;
        }
        $processedValues = [];

        foreach ($this->csv_data as $csv_values) {
            //obtain product reference
            $csv_ref = $csv_values['reference'];
            //formats values from csv to be compared with the database ones
            $formatedValues = $this->formatCsv($csv_values);
            
            //For products without reference (in database), no comparation is needed
            if (!isset($bydemes_products[$formatedValues['reference']])) {
                $processedValues[$csv_ref] = false;
                foreach ($csv_values as $field => $value) {

                    if ($field === 'manufacturer_name') {
                        $id_manufacturer = array_search($formatedValues[$field], $this->brands);
                        $this->changed_csv[$csv_ref]['id_manufacturer'] = (string) $id_manufacturer;
                        continue;
                    }
                    $this->changed_csv[$csv_ref][$field] = $formatedValues[$field];
                }
            } else {
                
                //For products already inserted
                
                $processedValues[$csv_ref] = [];
                foreach ($csv_values as $field => $value) {
                    //If field in csv dont exist in database skip it (id_csv, image URL and so)
                    if (!property_exists($bydemes_products[$csv_ref], $field)) {
                        continue;
                    }
                    
                    if ($field === 'manufacturer_name') {
                        //TODO remade for the product, manufacturer name is null in database, from id obtain name and then compare or so
                        continue;
                    }
                    if (trim($bydemes_products[$csv_ref]->$field) != $formatedValues[$field]) {

                        $this->changed_csv[$csv_ref][$field] = $formatedValues[$field];
                        if (strlen($bydemes_products[$csv_ref]->$field) > 40) {
                            $processedValues[$csv_ref][$field] = 'is changed <b>' . substr($bydemes_products[$csv_ref]->$field, 0, 255) . ' ...</b>';
                            continue;
                        }
                        $processedValues[$csv_ref][$field] = 'from : <b>' . trim($bydemes_products[$csv_ref]->$field, '\0') . '</b> to <b>' . $formatedValues[$field] . '</b>';
                    }
                }
            }
        }
        
        return $processedValues;
    }
    /**
     * Format the Csv values so they can be compared with the values on the database.
     * @param array $csv_values array with the values of the csv of a row (chosed by reference)
     * @return array $csv_values array with the formated values
     */
    private function formatCsv(array $csv_values): array
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
                case 'weight':
                    $csv_values[$header] = preg_replace('/[a-z]+/i', '', trim($row_value));
                    if (empty($row_value)) {
                        $csv_values[$header] = "0";
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
