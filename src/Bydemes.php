<?php

declare(strict_types=1);

namespace OrbitaDigital\OdBydemes;

use Db;
use Product;
class Bydemes
{
    private $csv_data = [];
    private $insert_csv = [];
    private $brands = [];
    private $tableData = [];
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
     * Obtains the products in the database with reference - id_product
     * @return array array with the references
     */
    private function getBydemesProducts()
    {
        $query = Db::getInstance()->executeS('SELECT p.reference, p.id_product
        FROM `ps_product` p 
        INNER JOIN `ps_product_lang` pl ON p.id_product = pl.id_product 
        INNER JOIN `ps_supplier` su ON p.id_supplier = su.id_supplier WHERE su.name = "bydemes" AND id_lang = 1');
        if ($query === false) {
            return false;
        }
        foreach ($query as $value) {
            $bydemes_product[$value['reference']] = $value['id_product'];
        }

        return $bydemes_product;
    }
    /**
     * Update the reference values from the csv into the database
     * @return array $update, array with pairs of references/booleans if they were or no updated
     */
    public function saveProducts()
    {

        $products = $this->getBydemesProducts();
        $bydemes_id = Db::getInstance()->getValue('SELECT `id_supplier` FROM `ps_supplier` WHERE `name` = "bydemes"');
        $default_category = "2"; // default category inicio

        //insert_csv is ref as key with the array of values changed
        foreach ($this->insert_csv as $ref => $ref_values) {
            //If no id is found in database, add the product
            if (!isset($products[$ref])) {
                $new_prod = new Product();
                foreach ($ref_values as $field => $field_value) {
                    //checking if property exist in the product
                    if (!property_exists($new_prod, $field)) {
                        continue;
                    }
                    $new_prod->$field = $field_value;
                }
                $new_prod->supplier_name = 'bydemes';
                $new_prod->id_supplier = $bydemes_id;
                $new_prod->id_category_default = $default_category;
                //$new_prod->add();
                if (isset($_GET['write'])) {
                    $date = $_GET['write'];
                    $currentDate = date('d_m_Y');
                    if ($date === $currentDate) {
                        $new_prod->add();
                        $new_prod->addSupplierReference($bydemes_id, 0);
                        //Add new info in the table
                        $this->tableData[$ref]['add info: '] = 'product with reference ' . $ref . ' was added';
                    }
                }
                $save[] = $new_prod;
            } else {

                $id_product = $products[$ref];
                $object = new Product($id_product);
                foreach ($ref_values as $field => $field_value) {
                    if (!property_exists($object, $field)) {
                        continue;
                    }
                    if ($field == 'category') {
                        continue;
                    }
                    if ($field == 'description' || $field == 'description_short' || $field == 'name') {
                        if ($object->$field[1] !== $field_value) {
                            $this->tableData[$ref][$field] = substr($field_value,0,200);
                        }
                        continue;
                    }
                    if ($object->$field !== $field_value) {
                        $this->tableData[$ref][$field] = $field_value;
                    }
                }
                $save[] = $object;

                //All the values that are modified added onto the object then update
                $save = $object->update();

                $this->tableData[$ref]['update info: '] = 'product was modified';
            }
        }
        //return reference with true/false if query was done
        return true;
    }
    /**
     * creates the table with the information obtained from processing the csv information within the database
     * @return string string with the table otherwise
     */
    public function getTable()
    {
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
            <h2>List of products</h2>
            <table>
        <thead><th>Reference</th><th>In database?</th><th>Information</th></thead>
        <tbody>';

        if (!empty($this->save_info)) {
        }
        $tableBody = '';
        foreach ($this->tableData as $ref => $value) {
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
    public function processCsv()
    {

        //Data = array with the references
        //bydemes_products = array with the bydemes products in the database key = reference
        $bydemes_products = $this->getBydemesProducts();
        if (!$bydemes_products) {
            return false;
        }

        foreach ($this->csv_data as $csv_values) {
            //obtain product reference
            $csv_ref = $csv_values['reference'];
            //formats values from csv to be compared with the database ones
            $formatedValues = $this->formatCsv($csv_values);

            //For products without reference (in database), no comparation is needed
            //TODO various checks so data is fine (price cant be 0, reference and so). Or at least, show odd information in the table
            if (!isset($bydemes_products[$formatedValues['reference']])) {
                $this->tableData[$csv_ref] = false;
            } else {
                //For products already inserted
                $this->tableData[$csv_ref] = [];
            }
            foreach ($csv_values as $field => $value) {
                if ($field === 'manufacturer_name') {
                    $id_manufacturer = array_search($formatedValues[$field], $this->brands);
                    $this->insert_csv[$csv_ref]['id_manufacturer'] = (string) $id_manufacturer;
                    continue;
                }
                $this->insert_csv[$csv_ref][$field] = $formatedValues[$field];
            }
        }
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
                    //replace needed because numbers use . not ,. cast and decimals to be compared with the database
                case 'price':
                    $float = (float) str_replace(",", ".", $row_value);
                    $csv_values[$header] = number_format($float,6);
                    break;

                    //csv active is false (if 0). Need to convert it
                case 'active':
                    $row_value === 'False' ? $csv_values[$header] = "0" : $csv_values[$header] = "1";
                    break;

                    //For dimensions, changes letters to 0 (after removing lots of empty space)
                case 'width':
                case 'length':
                case 'height':
                case 'depth':
                case 'weight':
                    $float = (float) preg_replace('/[a-z]+/i', '', trim($row_value));
                    $csv_values[$header] = number_format($float,6);
                    if (empty($row_value)) {
                        $csv_values[$header] = "0.000000";
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
                    $csv_values[$header] = $this->process_desc($row_value);
            }
        }
        return $csv_values;
    }
    /**
     * post-processing description
     */
    private function process_desc($row_value)
    {
        $desc_clean = trim($row_value);
        stristr($desc_clean, '<p>') ? $desc_encoded = $desc_clean : $desc_encoded = '<p>' . $desc_clean . '</p>';
        //format <br> to <br />
        $desc_processed = str_replace('<br>', '<br />', $desc_encoded);
        //Should change no alt at <img ... > to <img ... alt="" />
        return html_entity_decode($desc_processed, ENT_NOQUOTES, 'UTF-8');
    }
}
