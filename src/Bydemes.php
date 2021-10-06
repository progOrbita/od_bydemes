<?php

declare(strict_types=1);

namespace OrbitaDigital\OdBydemes;

use Db;
use Manufacturer;
use Product;
use StockAvailable;
class Bydemes
{
    //Data obtained from the csv
    private $csv_data = [];

    //Formatted csv values
    private $insert_csv = [];

    //Contains the brands from the database
    private $brands = [];

    //Assorted information to be shown to the user
    private $tableData = [];

    //Reference-id of products included in the database
    private $bydemes_products = [];

    //langs
    private $langs = [];

    //Default values for the three sizes of stock.
    private $stock_values = ['Low' => "5", 'Medium' => "50", 'High' => "100"];

    private $message = '';

    /**
     * constructor
     * @param array $csv_data data obtained from reading the csv
     */
    function __construct(array $csv_data)
    {
        $this->csv_data = $csv_data;

        $this->bydemes_products = $this->getBydemesProducts();
        $this->brands = $this->getBrands();
    }
    /**
     * Try to obtains the products in the database with reference - id_product
     * @return bool|array array with the references and ids. False if there's an error in the query
     */
    private function getBydemesProducts()
    {
        $query = Db::getInstance()->executeS('SELECT p.reference, p.id_product
        FROM `ps_product` p 
        INNER JOIN `ps_supplier` su ON p.id_supplier = su.id_supplier WHERE su.name = "bydemes"');

        if ($query === false) {
            $this->message = '<h3>Error obtaining the data</h3>';
            return false;
        }
        $bydemes_product = [];
        foreach ($query as $value) {
            $bydemes_product[$value['reference']] = $value['id_product'];
        }
        return $bydemes_product;
    }
    private function getBrands()
    {
        //obtains all the brands from the database
        $brands_query = Db::getInstance()->executeS('SELECT `id_manufacturer`,`name` FROM `ps_manufacturer`');
        $brands = [];
        foreach ($brands_query as $brand) {
            $brands[$brand['name']] = $brand['id_manufacturer'];
        }
        return $brands;
    }
    public function getMessage()
    {
        return $this->message;
    }
    /**
     * Attempts to add products in the database if references doesnt exist or update them with new values from the csv
     * @return bool false if there's an error in a query
     */
    public function saveProducts()
    {
        $products = $this->bydemes_products;
        if ($products === false) {
            return false;
        }

        $lang_query = Db::getInstance()->executeS('SELECT `iso_code`, `id_lang` FROM `ps_lang`');
        foreach ($lang_query as $key => $value) {
            $this->langs[$value['iso_code']] = $value['id_lang'];
        }

        $bydemes_id = Db::getInstance()->getValue('SELECT `id_supplier` FROM `ps_supplier` WHERE `name` = "bydemes"');

        $default_category = "1"; // default category inicio

        //insert_csv is an array with reference as key and the array of values formatted

        foreach ($this->insert_csv as $ref => $ref_values) {

            //For products that have "no" in their reference or descatalogado in the name, are skipped
            if (stristr($ref, 'no') || stristr($this->insert_csv[$ref]['name'], 'descatalogado')) {
                $this->tableData[$ref] = ['<b>this product wont be added</b>'];
                continue;
            }
            if (stristr($this->insert_csv[$ref]['price'], '0.000000')) {
                $this->tableData[$ref] = ['<b>Price is 0, it wont be added or modified</b>'];
                continue;
            }


            //bool to check if reference exist or no in the database
            $ref_exist = false;

            if (isset($products[$ref])) {
                $id_product = $products[$ref];
                $new_prod = new Product($id_product);
                $ref_exist = true;
            } else {
                $new_prod = new Product();
            }

            //prepare the Product, ready to be inserted in the database
            foreach ($ref_values as $field => $field_value) {
                //checking if property in the csv exist in the product class
                if (!property_exists($new_prod, $field)) {
                    continue;
                }
                if ($field == 'category') {
                    continue;
                }
                if ($field === 'quantity' && $ref_exist === true) {
                    $new_prod->$field = StockAvailable::getQuantityAvailableByProduct($id_product);
                    if ($new_prod->$field != $field_value) {
                        $getStock = StockAvailable::setQuantity($id_product, 0, $new_prod->quantity);
                        if (!$getStock) {
                            $this->message = '<b>Error, couldnt get the stock of' . $ref . '</b>';
                            return false;
                        }
                        $this->tableData[$ref][] = $field . ' changed: ' . $field_value;
                        continue;
                    }
                }

                if ($field == 'description' || $field == 'description_short' || $field == 'name') {
                    foreach ($this->langs as $value) {
                        if ($ref_exist) {
                            if ($new_prod->$field[$value] != $field_value) {
                                $this->tableData[$ref][] = $field . ' changed: ' . substr($field_value, 0, 200) . ' ...';
                            }
                        }
                        $new_prod->$field[$value] = $field_value;
                    }
                    continue;
                }
                if ($ref_exist) {
                    if ($new_prod->$field != $field_value) {
                        $this->tableData[$ref][] = $field . ' changed: ' . $field_value;
                    }
                }
                $new_prod->$field = $field_value;
            }

            //if write is written in the header
            $write_date = Tools::getValue('write');
            if ($write_date === date('d_m_Y')) {
                if ($ref_exist) {
                    if (count($this->tableData[$ref]) > 1) {
                        //Add new info in the table
                        $prod_upd = $new_prod->update();
                        if (!$prod_upd) {
                            $this->message = 'update info: <b>Error trying to update the product<b>';
                            return false;
                        }
                        $this->tableData[$ref][] = 'update info: product was modified';
                    }
                    $this->tableData[$ref][] = 'Product up to date';
                } else {
                    $new_prod->id_supplier = $bydemes_id;
                    $new_prod->id_category_default = $default_category;

                    $prod_add = $new_prod->add();
                    if (!$prod_add) {
                        $this->message = 'add info: <b>Error adding the product with reference ' . $ref . '</b>';
                        return false;
                    }
                    $new_prod->addSupplierReference($bydemes_id, 0);

                    //If it have more than 0, quantity is added (after creating the Product because id is needed)
                    if ($new_prod->quantity > 0) {

                        $add_stock = StockAvailable::setQuantity($new_prod->id, 0, $new_prod->quantity);
                        if (!$add_stock) {
                            $this->message = 'add info: <b>Error adding stock for ' . $ref . '</b>';
                            return false;
                        }
                    }
                    //Add information in the table
                    $this->tableData[$ref][] = 'add info: product with reference ' . $ref . ' was added';
                }
            }
        }
        return true;
    }
    /**
     * generates the string of the table with the information obtained from the products and csv proccesing
     * @return string string with the table
     */
    public function getTable(): string
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
        $tableBody = '';
        foreach ($this->tableData as $ref => $value) {
            /**
             * False - product isnt added
             * emtpy - Product doesnt have changes
             * no empty - Product have additional information
             */
            if ($value === false) {
                $tableBody .= '<tr><td>' . $ref . '</td><td> Dont exist</td><td>Product will be created</td></tr>';
                continue;
            }
            $tableBody .= '<tr><td>' . $ref . '</td>';
            if (empty($value)) {
                $tableBody .= '<td>Product up to date</td>';
                continue;
            }
            foreach ($value as $ref_value) {
                $tableBody .= '<td>' . $ref_value . '</td>';
            }
            $tableBody .= '</tr>';
        }
        $tableEnd = '</tbody></table></body></html>';
        return $tableBase . $tableBody . $tableEnd;
    }
    /**
     * Process the csv information, formating the fields so they can be compared to update the product, or add a fresh one.
     * @return bool false if there's an error in the query. true if there's no errors.
     */
    public function processCsv(): bool
    {

        //Data = array with the references
        //bydemes_products = array with the bydemes products in the database key = reference
        if (!$this->bydemes_products) {
            return false;
        }

        foreach ($this->csv_data as $csv_values) {
            //obtain product reference
            $csv_ref = $csv_values['reference'];
            //formats values from csv to be compared with the database ones
            $formatedValues = $this->formatCsv($csv_values);

            /**
             * False - product isnt added
             * emtpy - Product doesnt have changes
             * no empty - Product have changes
             */
            if (!isset($this->bydemes_products[$csv_ref])) {
                $this->tableData[$csv_ref] = false;
            } else {
                //For products already inserted
                $this->tableData[$csv_ref] = [];
                $this->tableData[$csv_ref][] = 'exist';
            }
            foreach ($csv_values as $field => $value) {
                /**
                 * Attemps to find the brand/manufacturer, if not found it will create it
                 */
                if ($field === 'manufacturer_name') {
                    if (empty($formatedValues[$field])) {
                        continue;
                    }

                    $id_manufacturer = $this->brands[$formatedValues[$field]];
                    if (empty($id_manufacturer)) {
                        $write_date = Tools::getValue('write');
                        //if write isn't set
                        if ($write_date != date('d_m_Y')) {
                            $this->tableData[$csv_ref][] = 'brand <b>' . $value . '</b> not found<td>Product will be created</td>';
                            continue;
                        }
                        $this->tableData[$csv_ref][] = 'brand <b>' . $value . '</b> not found creating...<td>Product will be created</td>';
                        $new_brand = new Manufacturer();
                        $new_brand->name = $value;
                        $new_brand->active = 1;
                        $add_brand = $new_brand->add();
                        if (!$add_brand) {
                            $this->message = '<b>Error, brand: ' . $value . ' couldnt be created</b>';
                            return false;
                        }
                        $this->brands[$value] = $new_brand->id;
                        $id_manufacturer = $this->brands[$formatedValues[$field]];
                    }
                    $this->insert_csv[$csv_ref]['id_manufacturer'] = $id_manufacturer;
                    continue;
                }

                $this->insert_csv[$csv_ref][$field] = $formatedValues[$field];
            }
        }
        return true;
    }
    /**
     * Format the Csv values so they can be compared with the values of Prestashop
     * @param array $csv_values array with the values of the csv of a row (chosed by reference)
     * @return array $csv_values array with the formated values
     */
    private function formatCsv(array $csv_values): array
    {
        foreach ($csv_values as $header => $row_value) {
            switch ($header) {

                    //replace needed because numbers use . not ,. cast and decimals needed to be compared with the database, 6 digits as prestashop.
                case 'price':
                    $float = (float) str_replace(",", ".", $row_value);
                    $csv_values[$header] = number_format($float, 6, '.', '');
                    if ($csv_values[$header] == '0.000000') {
                        $this->tableData[$csv_values][0] .= ' <b>price is emtpy</b>';
                    }
                    break;

                    //csv active is false (if 0). Need to convert it
                case 'active':
                    $row_value === 'False' ? $csv_values[$header] = "0" : $csv_values[$header] = "1";
                    break;

                    //For dimensions, changes letters to 0, removes lots of empty space and needs 6 digits like prestashop
                case 'width':
                case 'length':
                case 'height':
                case 'depth':
                case 'weight':
                    $float = (float) preg_replace('/[a-z]+/i', '', trim($row_value));
                    $csv_values[$header] = number_format($float, 6, '.', '');
                    if (empty($row_value)) {
                        $csv_values[$header] = "0.000000";
                    }
                    break;

                    //low/medium/high values, turned values into numbers.
                case 'quantity':
                    if ($row_value != "0") {
                        $csv_values[$header] = $this->stock_values[$row_value];
                    }
                    break;

                    //replace if there's "" to only one and removes all the emtpy space. Then removes " at the beggining and the end if they exists.
                case 'name':
                    $inches = trim(str_replace('""', '"', $row_value));
                    $csv_values[$header] = preg_replace('/^"|"$/', '', $inches);
                    break;

                    //prestashop keeps <p> in the field. Values aren't decoded
                case 'description_short':
                    $decoded_short_desc = html_entity_decode($csv_values[$header], ENT_QUOTES, "UTF-8");
                    $csv_values[$header] = '<p>' . trim($decoded_short_desc) . '</p>';
                    break;

                    //Various changes to encode the string according the product
                case 'description':
                    $csv_values[$header] = $this->process_desc($row_value);
                    break;
            }
        }
        return $csv_values;
    }
    /**
     * post-processing description. Encode the string due to the existence of strings like iacute; or oacute; which needs to be encoded
     * It may have empty spaces and may not be closed in csv with <p>, which I need to add to compare both values
     * @param string $row_value description value to be processed
     * @return string description processed
     */
    private function process_desc($row_value): string
    {
        //removes all the empty space, usually at the end of the description
        $desc_clean = trim($row_value);
        //br at the end of the description field is removed in prestashop
        $desc_clean = preg_replace('/<br>$/', '', $desc_clean);
        //description may not have <p> at the beggining and the end. It's added if it's needed
        stristr($desc_clean, '<p>') ? $desc_encoded = $desc_clean : $desc_encoded = '<p>' . $desc_clean . '</p>';
        //format <br> to <br />
        $desc_processed = str_replace('<br>', '<br />', $desc_encoded);
        //if alt isn't added in img. Prestashop format the img tag to add alt attribute with the img name on it if doesn't have one. Added a default one.
        if (preg_match('/<img/', $desc_processed)) {
            if (!preg_match('/alt=""/', $desc_processed)) {
                $desc_processed = preg_replace('/">/', '" alt="" />', $desc_processed);
            }
        }
        //decoded text for acute; ncute; and more symbols.
        $utfText = html_entity_decode($desc_processed, ENT_QUOTES, 'UTF-8');
        //for &, is decodified to &amp; in prestashop
        $utfText = preg_replace('/&/', "&amp;", $utfText);
        //for greater than symbol, Prestashop decode it. Regex is pick the " >" followed (?=) by one or more numbers. To avoid changing tags >
        $utfText = preg_replace('/\s>(?=\d+)/', "&gt;", $utfText);

        //for styles, in database without spaces.
        //Check if there's a style, if so whenever a empty space is after letters and : or ;, removes the empty space after. Regex only picks the empty space.
        return preg_replace('/(?<=[style="\w+][:;])\s/', '', $utfText);
    }
}
