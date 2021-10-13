<?php

declare(strict_types=1);

namespace OrbitaDigital\OdBydemes;

use Db;
use Language;
use Manufacturer;
use Product;
use SpecificPrice;
use StockAvailable;
use Tools;

/**
 * Format and process products from Bydemes into the database
 */
class Bydemes
{
    //Data obtained from the csv
    private $csv_data = [];

    //admin url for products links
    private $urlAdm = '';

    //Reference-id of products included in the database
    private $bydemes_products = [];

    //Contains the brands from the database
    private $brands = [];

    //Formatted csv values
    private $insert_csv = [];

    //Sorted information by reference that is shown to the user
    private $tableData = [];

    //langs
    private $langs = [];

    //bydemes identifier
    private $bydemes_id;

    //to create a discount
    private $discount;

    //Margin from PVP
    private $cost_price_margin;

    //Message to identify the errors that may happen when calling the database
    private $queryError = '';

    //Default values for the three sizes of stock.
    private $stock_values = ['Low' => "5", 'Medium' => "50", 'High' => "100"];

    /**
     * constructor
     * @param array $csv_data data obtained from reading the csv
     * @param string $urlAdmin admin url(folder) of Prestashop
     */
    function __construct(array $csv_data, string $urlAdmin)
    {
        $this->csv_data = $csv_data;
        $this->urlAdm = $urlAdmin;

        $this->bydemes_id = Db::getInstance()->getValue('SELECT `id_supplier` FROM `ps_supplier` WHERE `name` = "bydemes"');
        if (!$this->bydemes_id) {
            die('<h3>Error trying to obtain the data</h3><p>Couldnt get bydemes supplier id</p>');
        }

        $this->bydemes_products = $this->getBydemesProducts();

        if ($this->bydemes_products === false) {
            die('<h3>Error trying to obtain the data</h3><p>Couldnt obtain bydemes products</p>');
        }

        $this->brands = $this->getBrands();

        if ($this->brands === false) {
            die('<h3>Error trying to obtain the data</h3><p>Couldnt get the brands</p>');
        }

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
     * Try to obtains the products in the database with reference - id_product
     * @return bool|array array with the references and ids. False if there's an error in the query
     */
    private function getBydemesProducts()
    {
        $products_query = Db::getInstance()->executeS('SELECT p.reference, p.id_product
        FROM `ps_product` p 
        INNER JOIN `ps_product_supplier` su ON p.id_product = su.id_product WHERE su.id_supplier = '. $this->bydemes_id);

        if ($products_query === false) {
            return false;
        }
        $bydemes_products = [];
        foreach ($products_query as $product) {
            $bydemes_products[$product['reference']] = $product['id_product'];
        }

        return $bydemes_products;
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
     * To add discounts
     * @param bool $create false if no discounts are going to be created
     * @param string $flat_discount default percentage 40%
     * @param int $days How much last the discount ( default 15 days)
     */
    public function addDiscount(bool $create = true, string $string_discount = "40%", int $days = 15)
    {
        if ($create === false) {
            return;
        }
        preg_match('/\d+/', $string_discount, $flat_discount);

        $this->discount = new SpecificPrice();
        $this->discount->id_shop = 1;
        $this->discount->id_currency = 1;
        $this->discount->id_country = 0;
        $this->discount->id_group = 0;
        $this->discount->id_customer = 0;
        $this->discount->id_product_attribute = 0;
        $this->discount->from_quantity = 1;
        $this->discount->reduction_tax = 1;
        $this->discount->reduction_type = 'percentage';
        $this->discount->reduction = ((float)$flat_discount[0] / 100);
        $this->discount->from = date('Y-m-d');
        $this->discount->to = date('Y-m-d', strtotime("+" . $days . " days"));
    }

    /**
     * Add information to be shown. Lang is optional if the field is multilanguage
     * @param string $ref product reference
     * @param string $field name of the field to be added
     * @param Product $product information of the product inserted
     * @param mixed $field_value value of the csv
     * @param string $lang optional
     */
    private function addTableData(string $ref, string $field, $product_field, $field_value, int $lang = null)
    {
        if (Tools::getValue('write') === date('d_m_Y')) {
            $this->tableData[$ref][] = $field . ' was changed';
            return;
        }
        if ($lang) {
            $this->tableData[$ref][] = $field . ' will be changed from: <textarea>' . $product_field . '</textarea> to <textarea>' . $field_value . '</textarea>';
        } else {
            $this->tableData[$ref][] = $field . ' will be updated from: <b>' . $product_field . '</b> to <b>' . $field_value . '</b>';
        }
    }
    
    public function setCostPriceMargin(int $percentage)
    {
        $this->cost_price_margin = (100 - $percentage) / 100;
    }
    /**
     * Attempts to add products in the database if references doesnt exist or update them with new values from the csv
     */
    public function saveProducts()
    {

        try {
            $products = $this->bydemes_products;

            $lang_query = Language::getLanguages();

            foreach ($lang_query as $value) {
                $this->langs[$value['name']] = $value['id_lang'];
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
                if ($this->insert_csv[$ref]['price'] === 0.00) {
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
                    switch ($field) {
                        case 'manufacturer_name':
                        case 'category':
                            break;

                            //once some of them works properly would be added active always. Message is just informative
                        case 'active':
                            if ($ref_exist && (int)$new_prod->$field === 0) {
                                $this->tableData[$ref][] = 'The product is deactivated';
                            }
                            break;
                        case 'id_manufacturer':

                            if ($ref_exist) {
                                if ((int) $new_prod->$field !== $field_value) {
                                    $old_brand = array_search($new_prod->id_manufacturer, $this->brands);
                                    $new_brand = array_search($field_value, $this->brands);

                                    $this->addTableData($ref, "manufacturer", $new_brand, $old_brand);
                                    $ref_update = true;
                                }
                            }
                            $new_prod->id_manufacturer = $field_value;
                            break;

                        case 'quantity':
                            if (!$ref_exist) {
                                break;
                            }
                            $new_prod->$field = StockAvailable::getQuantityAvailableByProduct($id_product);
                            if ($new_prod->$field !== (int) $field_value) {
                                $this->addTableData($ref, $field, $new_prod->$field, $field_value);
                                $ref_update = true;
                            }
                            break;

                        case 'price':
                        case 'width':
                        case 'height':
                        case 'depth':
                        case 'weight':
                            if ($ref_exist) {
                                $prod_field = (float) $new_prod->$field;

                                if (abs($prod_field - $field_value) > 'PHP_FLOAT_EPSILON') {

                                    $ref_update = true;
                                    $this->addTableData($ref, $field, $new_prod->$field, $field_value);
                                }
                            }
                            $new_prod->$field = $field_value;
                            break;

                        case 'description':
                        case 'description_short':
                        case 'name':
                            foreach ($this->langs as $id_lang) {

                                if ($ref_exist) {
                                    if ($new_prod->$field[$id_lang] !== $field_value) {
                                        $ref_update = true;
                                        $this->addTableData($ref, $field, $new_prod->$field, $field_value, (int)$id_lang);
                                    }
                                }
                                $new_prod->$field[$id_lang] = $field_value;
                            }
                            break;

                        default:
                            if ($ref_exist) {
                                if ($new_prod->$field !== $field_value) {

                                    $ref_update = true;
                                    $this->addTableData($ref, $field, $new_prod->$field, $field_value);
                                }
                            }
                            $new_prod->$field = $field_value;
                            break;
                    }
                }
                if ((int) $new_prod->available_for_order === 0) {
                    $this->tableData[$ref][] = ' <b>Product isnt available to order</b>';
                }
                if (!$ref_update && $ref_exist) {
                    $this->tableData[$ref][] = 'Csv data already updated';
                }
                //wholesale price.

                $old_wholesale = (float) $new_prod->wholesale_price;
                $new_prod->wholesale_price = (float) round($new_prod->price * $this->cost_price_margin, 6);

                if ($ref_exist) {
                    if (abs((float)$old_wholesale - $new_prod->wholesale_price) > 'PHP_FLOAT_EPSILON') {
                        $ref_update = true;
                        $this->tableData[$ref][] = 'Update info: wholesale price will be updated from ' . $old_wholesale . ' to ' . $new_prod->wholesale_price;
                    }
                }

                //if write with the date is written in the header

                if (Tools::getValue('write') === date('d_m_Y')) {
                    if ($ref_exist) {
                        //Only update products with one or more changes

                        if ($ref_update) {
                            //Add new info in the table
                            $prod_upd = $new_prod->update();

                            $this->tableData[$ref][] = $prod_upd ? 'Update info: product was modified' : '<b>Fatal error</b>';


                            $csv_quantity = (int) $this->insert_csv['quantity'];
                            if ($new_prod->quantity != $csv_quantity) {
                                $setStock = StockAvailable::setQuantity($id_product, 0, $csv_quantity);

                                if ($setStock === false) {
                                    $this->tableData[$ref][] = '<b>Error, couldnt set the stock of' . $ref . '</b>';
                                }
                                continue;
                            }
                            continue;
                        }
                    } else {

                        $new_prod->id_category_default = $default_category;
                        $new_prod->active = 1;

                        $prod_add = $new_prod->add();
                        if ($this->discount) {
                            $this->discount->id_product = $new_prod->id;
                            $this->discount->price = $new_prod->price; //either the price of the product which shouldn't change or -1 (which takes current price)
                            $this->discount->add();
                            $days = round((strtotime($this->discount->to) - strtotime($this->discount->from)) / 86400);

                            $this->tableData[$ref][] = 'discount of ' . ($this->discount->reduction * 100) . '% was added for ' . $days . ' days';
                        }

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
     * Creates a link for each reference to the product in admin page
     * @param int $id_product id of the product
     * @param string $token security token to access
     * return string link to the admin page
     */
    public function createProductLink(int $id_product, string $token): string
    {
        $p_controller  = 'index.php?controller=AdminProducts';
        $p_controller .= '&token=' . $token;
        $p_controller .= '&id_product=' . (int) $id_product;
        $p_controller .= '&updateproduct';
        return _PS_BASE_URL_ . __PS_BASE_URI__ . $this->urlAdm . '/' . $p_controller;
    }
    
    /**
     * generates the string of the table with the information obtained from the products and csv proccesing
     * @return string string with the table
     */
    public function getTable(): string
    {

        $token = Tools::getAdminTokenLite('AdminProducts');

        $tableBase = '<html><head>
            <style>
                td {
                    border: 1px solid black;
                    padding: 4px;
                    min-width: 100px;
                }
                li{
                    list-style-type: circle;
                }
                textarea{
                    min-height: 175px;
                    min-width: 375px;
                }
            </style>
        </head>
        <body>
            <h2>List of products</h2>
            <table>
        <thead><th>Reference</th><th>Information</th></thead>
        <tbody>';
        $tableBody = '';
        foreach ($this->tableData as $ref => $ref_changes) {
            /**
             * False - product isnt added (changes are no-existant)
             * no empty - Product have information
             */
            if ($ref_changes === false) {
                $tableBody .= '<tr><td>' . $ref . '</td><td><li> Reference not found, will be created</td></tr>';
                continue;
            }
            //Products not added or new ones.
            if (empty((int) $this->bydemes_products[$ref])) {
                $tableBody .= '<tr><td>' . $ref . '</td><td>';
            } else {
                $tableBody .= '<tr><td><a href="' . $this->createProductLink((int) $this->bydemes_products[$ref], $token) . '">' . $ref . '</a></td><td>';
            }
            foreach ($ref_changes as $changed_values) {
                if (!is_array($changed_values)) {
                    $tableBody .= '<li>' . $changed_values . '</li>';
                }
            }
            $tableBody .= '</td></tr>';
        }
        $tableEnd = '</tbody></table></body></html>';
        return $tableBase . $tableBody . $tableEnd;
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
                    //Products with two spaces instead of one.
                    $decoded_short_desc = preg_replace('/\s\s/', ' ', $decoded_short_desc);
                    $csv_values[$header] = '<p>' . trim($decoded_short_desc) . '</p>';
                    break;

                    //Various changes to encode the string according the product
                case 'description':
                    $csv_values[$header] = $this->process_desc($row_value);
                    break;

                    //obtains manufacturer_id given the name of the brand
                case 'manufacturer_name':
                    $csv_values['id_manufacturer'] = (int) $this->find_brand_id($csv_values['reference'], $row_value);
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
        try {
            if (empty($brand_name)) {
                return;
            }

            $id_manufacturer = $this->brands[$brand_name];
            if (empty($id_manufacturer)) {
                $write_date = Tools::getValue('write');
                //if write isn't set
                if ($write_date != date('d_m_Y')) {
                    $this->tableData[$ref][] = 'brand <b>' . $brand_name . '</b> not found</td>';
                    return;
                }
                $this->tableData[$ref][] = 'brand <b>' . $brand_name . '</b> not found creating...</td>';
                $new_brand = new Manufacturer();
                $new_brand->name = $brand_name;
                $new_brand->active = 1;
                $add_brand = $new_brand->add();

                if (!$add_brand) {
                    $this->tableData[$ref][] = '<b>Error, brand: ' . $brand_name . ' couldnt be created</b></td>';
                    return;
                }

                $this->brands[$brand_name] = $new_brand->id;
                $id_manufacturer = $this->brands[$brand_name];
            }
            return $id_manufacturer;
        } catch (\Throwable $th) {
            $this->tableData[$ref][] = '<b>Error ' . $th->getMessage() . '</b>, brand couldnt be created</td>';
        }
    }

    /**
     * Process the csv information, formating the fields so they can be compared to update the product, or add a fresh one.
     */
    public function processCsv()
    {


        foreach ($this->csv_data as $csv_values) {
            //obtain product reference
            $csv_ref = $csv_values['reference'];
            //Assign and formats values from csv so they can be compared with the database ones
            $this->insert_csv[$csv_ref] = $this->formatCsv($csv_values);

            /**
             * Stores values to show information in the table
             * False - product isnt added
             * emtpy - Product is on database
             */
            if (!isset($this->bydemes_products[$csv_ref])) {
                $this->tableData[$csv_ref] = false;
            } else {
                //For products already inserted
                $this->tableData[$csv_ref][] = [];
            }
        }
    }

            }

        }
    }
}
