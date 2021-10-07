<?php

declare(strict_types=1);

namespace OrbitaDigital\OdBydemes;

use Db;
use Language;
use Manufacturer;
use Product;
use StockAvailable;
use Tools;

/**
 * Format and process products from Bydemes into the database
 */
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

    private $bydemes_id;
    //Message to identify the errors that may happen when calling the database
    private $queryError = '';

    //Default values for the three sizes of stock.
    private $stock_values = ['Low' => "5", 'Medium' => "50", 'High' => "100"];

    /**
     * constructor
     * @param array $csv_data data obtained from reading the csv
     */
    function __construct(array $csv_data)
    {
        $this->csv_data = $csv_data;

        $this->bydemes_products = $this->getBydemesProducts();
        $this->brands = $this->getBrands();

        $this->bydemes_id = Db::getInstance()->getValue('SELECT `id_supplier` FROM `ps_supplier` WHERE `name` = "bydemes"');
        if (!$this->bydemes_id) {
            die('<h3>Error trying to obtain the data</h3><p>Couldnt get bydemes supplier id</p>');
        }
        if ($this->brands === false) {
            die('<h3>Error trying to obtain the data</h3><p>Couldnt get the brands</p>');
        }
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
            return false;
        }
        $bydemes_product = [];
        foreach ($query as $value) {
            $bydemes_product[$value['reference']] = $value['id_product'];
        }
        return $bydemes_product;
    }
    /**
     * Get all the brands from the database
     * @return bool|array array with the brands, false if sql have an error.
     */
    private function getBrands()
    {
        //obtains all the brands from the database
        $brands_query = Db::getInstance()->executeS('SELECT `id_manufacturer`,`name` FROM `ps_manufacturer`');
        if ($brands_query === false) {
            return false;
        }
        $brands = [];
        foreach ($brands_query as $brand) {
            $brands[$brand['name']] = $brand['id_manufacturer'];
        }
        return $brands;
    }
    /**
     * Get queryError
     * @return string $queryError Shows the error of the query
     */
    public function getQueryError(): string
    {
        return $this->queryError;
    }
    /**
     * Attempts to add products in the database if references doesnt exist or update them with new values from the csv
     */
    public function saveProducts()
    {
        try {
            $products = $this->bydemes_products;

            $lang_query = Language::getIsoIds();

            foreach ($lang_query as $value) {
                $this->langs[$value['iso_code']] = $value['id_lang'];
            }

            $default_category = "1"; // default category inicio

            //insert_csv is an array with reference as key and the array of values formatted

            foreach ($this->insert_csv as $ref => $ref_values) {

                //For products that have "no" in their reference or descatalogado in the name, are skipped
                if (stristr($ref, 'no')) {
                    $this->tableData[$ref] = ['<b>this product wont be added</b>'];
                    continue;
                }
                if (stristr($this->insert_csv[$ref]['name'], 'descatalogado')) {
                    $this->tableData[$ref] = ['<b>this product is descatalogued, ignored</b>'];
                    continue;
                }
                //For products without price which aren't added in the database
                if ($this->insert_csv[$ref]['price'] == 0.00) {
                    $this->tableData[$ref] = ['<b>Price is 0, it wont be added</b>'];
                    continue;
                }

                //bool to check if reference exist or no in the database
                $ref_exist = (bool) $this->tableData[$ref];

                //check if the product is being updated
                $ref_update = false;

                if (isset($products[$ref])) {
                    $id_product = $products[$ref];
                    $new_prod = new Product($id_product);
                } else {
                    $new_prod = new Product();
                }

                //prepare the Product, ready to be inserted in the database
                foreach ($ref_values as $field => $field_value) {
                    //checking if property in the csv exist in the product class
                    if (!property_exists($new_prod, $field)) {
                        continue;
                    }

                    if ($field == 'category' || $field == 'manufacturer_name') {
                        continue;
                    }

                    if ($field == 'description' || $field == 'description_short' || $field == 'name') {
                        foreach ($this->langs as $value) {
                            if ($ref_exist) {
                                if ($new_prod->$field[$value] !== $field_value) {
                                    $ref_update = true;
                                    $this->tableData[$ref][] = $field . ' changed: ' . substr($field_value, 0, 200) . ' ...';
                                }
                            }
                            $new_prod->$field[$value] = $field_value;
                        }
                        continue;
                    }
                    if ($field == 'price' || $field == 'width' || $field == 'height' || $field == 'depth' || $field == 'weight') {
                        if ($ref_exist) {
                            $prod_field = (float) $new_prod->$field;

                            if (abs($prod_field - $field_value) < PHP_FLOAT_EPSILON) {
                                $ref_update = true;
                                $this->tableData[$ref][] = $field . ' changed: ' . $field_value;
                            }
                        }
                        $new_prod->$field = $field_value;

                        continue;
                    }
                    if ($ref_exist) {
                        if ($new_prod->$field !== $field_value) {

                            $ref_update = true;
                            $this->tableData[$ref][] = $field . ' changed: ' . $field_value;
                        }
                    }
                    $new_prod->$field = $field_value;
                }

                //if write is written in the header

                $write_date = Tools::getValue('write');
                if ($write_date === date('d_m_Y')) {
                    if ($ref_exist) {
                        //Only update products with one or more changes
                        if ($ref_update) {
                            //Add new info in the table
                            $prod_upd = $new_prod->update();

                            $this->tableData[$ref][] = $prod_upd ? 'Update info: product was modified' : '<b>Fatal error</b>';
                            if ($field === 'quantity' && $ref_exist === true) {

                                $new_prod->$field = StockAvailable::getQuantityAvailableByProduct($id_product);

                                if ($new_prod->$field != $field_value) {
                                    $setStock = StockAvailable::setQuantity($id_product, 0, $new_prod->quantity);
                                    if (!$setStock) {
                                        $this->tableData[$ref][] = '<b>Error, couldnt set the stock of' . $ref . '</b>';
                                    }
                                    $this->tableData[$ref][] = $field . ' changed: ' . $field_value;
                                    continue;
                                }
                            }

                            continue;
                        }
                        $this->tableData[$ref][] = 'Product up to date';
                    } else {
                        $new_prod->id_supplier = $this->bydemes_id;
                        $new_prod->id_category_default = $default_category;
                        $prod_add = $new_prod->add();

                        if (!$prod_add) {
                            $this->tableData[$ref][] = 'add info: <b>Error adding the product with reference ' . $ref . '</b>';
                            continue;
                        }
                        $new_prod->addSupplierReference($this->bydemes_id, 0);

                        //If it have more than 0, quantity is added (after creating the Product because id is needed)
                        if ($new_prod->quantity > 0) {

                            $add_stock = StockAvailable::setQuantity($new_prod->id, 0, $new_prod->quantity);
                            if (!$add_stock) {
                                $this->tableData[$ref][] = 'add info: <b>Error adding stock for ' . $ref . '</b>';
                                continue;
                            }
                        }
                        //Add information in the table
                        $this->tableData[$ref][] = 'add info: product with reference ' . $ref . ' was added';
                    }
                }
            }
        } catch (\Throwable $th) {
            //catch the exception if update throws an error
            $this->tableData[$ref][] = '<b>Error ' . $th->getMessage() . '</b>, it wont be added';
        }
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
        foreach ($this->tableData as $ref => $ref_changes) {

            /**
             * False - product isnt added (changes are no-existant)
             * no empty - Product have information
             */
            if ($ref_changes === false) {
                $tableBody .= '<tr><td>' . $ref . '</td><td> Dont exist</td><td>Product will be created</td></tr>';
                continue;
            }
            $tableBody .= '<tr><td>' . $ref . '</td>';
            if (empty($ref_changes)) {
                $tableBody .= '<td>Product up to date</td>';
                continue;
            }
            foreach ($ref_changes as $changed_values) {
                $tableBody .= '<td>' . $changed_values . '</td>';
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
    public function processCsv()
    {
        //Data = array with the references
        //bydemes_products = array with the bydemes products in the database key = reference
        if (!$this->bydemes_products) {
            return false;
        }

        foreach ($this->csv_data as $csv_values) {
            //obtain product reference
            $csv_ref = $csv_values['reference'];
            //Assign and formats values from csv so they can be compared with the database ones
            $this->insert_csv[$csv_ref] = $this->formatCsv($csv_values);

            /**
             * Stores values to show information in the table
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
                    $csv_values[$header] = (float) str_replace(",", ".", $row_value);
                    if ($csv_values[$header] == 0.00) {
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
                    $csv_values[$header] = (float) preg_replace('/[a-z]+/i', '', trim($row_value));
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

                    //obtains manufacturer_id given the name of the brand
                case 'manufacturer_name':
                    $csv_values['id_manufacturer'] = $this->find_brand_id($csv_values['reference'], $row_value);
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
    /**
     * Obtain manufacturer_id from the name. If no manufacturer_id is found in the database attempts to create it.
     * @param string $ref product reference, to add information
     * @param string $brand_name, name of the brand
     */
    private function find_brand_id(string $ref, string $brand_name)
    {
        if (empty($brand_name)) {
            return;
        }

        $id_manufacturer = $this->brands[$brand_name];
        if (empty($id_manufacturer)) {
            $write_date = Tools::getValue('write');
            //if write isn't set
            if ($write_date != date('d_m_Y')) {
                $this->tableData[$ref][] = 'brand <b>' . $brand_name . '</b> not found<td>Product will be created</td>';
                return;
            }
            $this->tableData[$ref][] = 'brand <b>' . $brand_name . '</b> not found creating...<td>Product will be created</td>';
            $new_brand = new Manufacturer();
            $new_brand->name = $brand_name;
            $new_brand->active = 1;
            try {
                $add_brand = $new_brand->add();
            } catch (\Throwable $th) {
                $this->tableData[$ref][] = '<b>Error ' . $th->getMessage() . '</b>, it wont be added</td>';
            }
            if (!$add_brand) {
                $this->tableData[$ref][] = '<b>Error, brand: ' . $brand_name . ' couldnt be created</b></td>';
                return;
            }

            $this->brands[$brand_name] = $new_brand->id;
            $id_manufacturer = $this->brands[$brand_name];
        }
        return $id_manufacturer;
    }
}
