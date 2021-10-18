<?php

declare(strict_types=1);

namespace OrbitaDigital\OdBydemes;

//Minimum number for floats to be different, value from PHP_FLOAT_EPSILON, which is available in PHP 7.2 and later
define("MIN_FLOAT", 2.2204460492503E-16);

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


    //Default values for the three sizes of stock.
    private $stock_values = ['Low' => "5", 'Medium' => "50", 'High' => "100"];

    private $update;
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
        $this->update = Tools::getValue('write') === date('d_m_Y');
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
        INNER JOIN `ps_supplier` su ON p.id_supplier = su.id_supplier WHERE su.name = "bydemes"');

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
     * Add information to be shown. Lang is optional if the field is multilanguage
     * @param string $ref product reference
     * @param string $field name of the field to be shown in the table
     * @param mixed $product_field information of the field of the product
     * @param mixed $field_value value which expect to update to
     * @param bool $lang default false, to format long string fields
     */
    private function addTableData(string $ref, string $field, $product_field, $field_value, bool $lang = false)
    {
        if ($this->update) {
            $this->tableData[$ref][] = ucfirst($field) . ' was changed';
            return;
        }
        if ($lang) {
            $this->tableData[$ref][] = ucfirst($field) . ' will be changed from: <textarea>' . $product_field . '</textarea> to <textarea>' . $field_value . '</textarea>';
        } else {
            $this->tableData[$ref][] = ucfirst($field) . ' will be updated from: <b>' . $product_field . '</b> to <b>' . $field_value . '</b>';
        }
    }
    /**
     * Margin price to be reduced from PVP to obtain price cost.
     * @param int $percentage flat percentage to be reduced from product PVP.
     */
    public function setCostPriceMargin(int $percentage)
    {
        $this->cost_price_margin = (100 - $percentage) / 100;
    }

    /**
     * Attempts to add products in the database if references doesnt exist or update them with values from the csv if they differs.
     */
    public function saveProducts()
    {

        try {
            $products = $this->bydemes_products;

            $lang_query = Language::getLanguages();

            foreach ($lang_query as $value) {
                $this->langs[$value['name']] = $value['id_lang'];
            }

            $default_category = 1; // default category inicio
            $i = 1;
            //insert_csv is an array with reference as key and the array of values formatted

            foreach ($this->insert_csv as $ref => $ref_values) {

                //Products that have "no" in their reference, descatalogado in the name or dont have a price are skipped
                if (stristr($ref, 'no')) {
                    $this->tableData[$ref] = ['<b>The product wont be added</b>'];
                    continue;
                }
                if (stristr($this->insert_csv[$ref]['name'], 'descatalogado')) {
                    $this->tableData[$ref] = ['<b>The product is descatalogued, ignored</b>'];
                    continue;
                }
                //For products without price which aren't added in the database
                if ($this->insert_csv[$ref]['price'] === 0.00) {
                    $this->tableData[$ref] = ['<b>Price is empty, the product wont be added</b>'];
                    continue;
                }
                if (empty($this->insert_csv[$ref]['name'])) {
                    $this->tableData[$ref] = ['<b>Name is empty, product wont be created</b>'];
                    continue;
                }
                if (strlen($this->insert_csv[$ref]['description']) < 10) {
                    $this->tableData[$ref] = ['<b>Description too short or empty. Product wont be inserted or updated</b>'];
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
                    //checking if property in the csv exist in the Product
                    if (!property_exists($new_prod, $field)) {
                        continue;
                    }
                    switch ($field) {
                        case 'manufacturer_name':
                        case 'category':
                            break;

                            //once some of them works properly would be added active always. Message is just informative
                        case 'active':
                            if (!$ref_exist) {
                                $new_prod->active = ($i + 1) % 2;  //active even and deactive odd csv references
                            }

                            if ($ref_exist && (int)$new_prod->active === 0) {
                                $this->tableData[$ref][] = '<i>Product deactivated</i>';
                            }
                            break;
                        case 'id_manufacturer':

                            if ($ref_exist) {
                                if ((int) $new_prod->$field !== $field_value) {

                                    $id_brands = array_flip($this->brands);
                                    $old_brand = $id_brands[$new_prod->id_manufacturer];
                                    $new_brand = $id_brands[$field_value];

                                    $this->addTableData($ref, "manufacturer", $old_brand, $new_brand);
                                    $ref_update = true;
                                }
                            }
                            $new_prod->id_manufacturer = $field_value;
                            break;

                        case 'quantity':
                            $csv_quantity = (int) $field_value;
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
                                
                                if (abs((float) $new_prod->$field - $field_value) > MIN_FLOAT) {

                                    $ref_update = true;
                                    $this->addTableData($ref, $field, (float) $new_prod->$field, $field_value);
                                }
                            }
                            $new_prod->$field = $field_value;
                            break;

                        case 'description':
                        case 'description_short':
                        case 'name':

                            foreach ($this->langs as $lang_name => $id_lang) {

                                if ($ref_exist) {
                                    if ($new_prod->$field[$id_lang] !== $field_value) {
                                        $ref_update = true;
                                        $this->addTableData($ref, $field . ' in ' . $lang_name, $new_prod->$field[$id_lang], $field_value, true);
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

                //wholesale price
                $old_wholesale = (float) $new_prod->wholesale_price;
                $new_prod->wholesale_price = (float) round($new_prod->price * $this->cost_price_margin, 6);

                if ($ref_exist) {
                    if (abs((float)$old_wholesale - $new_prod->wholesale_price) > MIN_FLOAT) {
                        $ref_update = true;
                        if ($this->update) {
                            $this->tableData[$ref][] = 'Wholesale price was changed';
                        } else {
                            $this->addTableData($ref, "wholesale price", $old_wholesale, $new_prod->wholesale_price);
                        }
                    }
                }

                //message if product isnt available for orders but its in the shop
                if ($new_prod->visibility !== 'both') {
                    switch ($new_prod->visibility) {
                        case "catalog":
                            $this->tableData[$ref][] = ' <i>Product shows in the catalog and searchs</i>';
                            break;

                        case "search":
                            $this->tableData[$ref][] = ' <i>Product shows only in searchs</i>';
                            break;

                        case "none":
                            $this->tableData[$ref][] = ' <i>Product is hidden from the shop</i>';
                            break;
                    }
                }
                if ((int) $new_prod->show_condition === 1) {
                    $this->tableData[$ref][] = ' <i>Product condition displayed in the shop</i>';
                }
                if ($new_prod->condition !== "new") {
                    switch ($new_prod->condition) {
                        case "used":
                            $this->tableData[$ref][] = ' <i>Used product</i>';
                            break;

                        case "refurbished":
                            $this->tableData[$ref][] = ' <i>Product refurbished</i>';
                            break;
                    }
                }
                if ($ref_exist && (int) $new_prod->online_only === 1) {
                    $this->tableData[$ref][] = ' <i>Product only available in the web</i>';
                }
                if ((int) $new_prod->available_for_order === 0) {
                    $this->tableData[$ref][] = ' <b>Not available to order</b>';
                }
                if ((int) $new_prod->show_price === 0) {
                    $this->tableData[$ref][] = ' <i>Price is hidden in the shop</i>';
                }

                if (!$ref_update && $ref_exist) {
                    $this->tableData[$ref][] = ' Up to date';
                }

                //if data is going to update
                if ($this->update) {

                    //If product is going to be updated
                    if ($ref_update) {

                        $prod_upd = $new_prod->update();
                        $this->tableData[$ref][] = $prod_upd ? 'Update info: The product was modified' : '<b>Fatal error</b>';

                        if ($new_prod->quantity != $csv_quantity) {
                            $setStock = StockAvailable::setQuantity($id_product, 0, $csv_quantity);

                            if ($setStock === false) {
                                $this->tableData[$ref][] = '<b>Error, couldnt set the stock of' . $ref . '</b>';
                            }
                            continue;
                        }
                        continue;
                    }
                    //If reference doesnt exist
                    if (!$ref_exist) {
                        //add new products
                        $new_prod->id_category_default = $default_category;
                        $new_prod->id_supplier = $this->bydemes_id;
                        $prod_add = $new_prod->add();


                        if (!$prod_add) {
                            $this->tableData[$ref][] = 'add info: <b>Error adding the product with reference ' . $ref . '</b>';
                            continue;
                        }

                        $new_prod->addSupplierReference($this->bydemes_id, 0);

                        //If the product have more than 0 in stock, quantity is added
                        if ($csv_quantity > 0) {
                            $add_stock = StockAvailable::setQuantity($new_prod->id, 0, $csv_quantity);
                            if ($add_stock === false) {
                                $this->tableData[$ref][] = 'add info: <b>Error adding stock for ' . $ref . '</b>';
                                continue;
                            }
                        }
                        //Add information in the table
                        $this->tableData[$ref][] = 'add info: product with reference ' . $ref . ' was added';
                    }
                }
                $i++;
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
    private function createProductLink(int $id_product, string $token): string
    {
        $p_controller  = 'index.php?controller=AdminProducts';
        $p_controller .= '&token=' . $token . '&id_product=' . (int) $id_product . '&updateproduct';
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
                    min-height: 75px;
                    min-width: 150px;
                    max-height: 175px;
                    max-width: 375px;
                    text-align: justify;
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
                //links for products inserted in Prestashop
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

                    //prestashop keeps <p> in the field. Values are encoded
                case 'description_short':
                    $decoded_short_desc = html_entity_decode($csv_values[$header], ENT_QUOTES, "UTF-8");
                    //Products with two spaces instead of one.
                    $decoded_short_desc = preg_replace('/\s\s/', ' ', $decoded_short_desc);
                    $csv_values[$header] = '<p>' . trim(Tools::purifyHTML($decoded_short_desc)) . '</p>';
                    break;

                    //Various changes to encode the string according the product
                case 'description':
                    $csv_values[$header] = $this->process_desc($row_value);

                    $end = '';
                    //If there's more than one paragraf, need to add a line jump to each one
                    if (preg_match_all('/<p>(.+)<\/p>/U', $csv_values[$header], $match)) {

                        foreach ($match[0] as $paraf_number => $paraf_value) {
                            if ($paraf_number === count($match[0]) - 1) {
                                $end .= $paraf_value;
                                continue;
                            }
                            $end .= $paraf_value . '
';
                        }
                        $csv_values[$header] = $end;
                    }
                    break;

                    //obtains manufacturer_id given the name of the brand
                case 'manufacturer_name':
                    $csv_values['id_manufacturer'] = (int) $this->check_brand_id($csv_values['reference'], $row_value, $this->update);
                    break;
            }
        }
        return $csv_values;
    }
    /**
     * post-processing description. Encode the string due to the existence of strings like iacute; or oacute; which needs to be at least encoded
     * Attemps to format like prestashop, which I need to compare both values to check if they are differents.
     * 
     * @param string $row_value description value to be processed
     * @return string description processed
     */
    private function process_desc($row_value): string
    {
        $utfText = html_entity_decode($row_value, ENT_QUOTES, 'UTF-8');

        //removes all the empty space, usually at the end of the description
        $desc_trim = trim($utfText);
        //br at the end of the description field is removed in prestashop
        $desc_del_end_br = preg_replace('/<br>$/', '', $desc_trim);
        //description may not have <p> open/closing the description, it's must have it always.
        stristr($desc_del_end_br, '<p>') ? $desc_p = $desc_del_end_br : $desc_p = '<p>' . $desc_del_end_br . '</p>';
        //format <br> to <br />
        $desc_fix_br = str_replace('<br>', '<br />', $desc_p);
        //Removes img tag, style, class, lang or id attributes, empty p, emtpy span, br at the beggining...
        $patterns = [
            '/<img(.+)>/U',
            '/\sstyle="(.+)"/U',
            '/\sclass="(.+)"/U',
            '/\sid="(.+)"/U',
            '/<p><\/p>/U',
            '/(<span>){2,}/',
            '/(<\/span>){2,}/',
            '/<span \/>/',
            '/<span><\/span>/',
            '/\slang="(.+)"/U',
            '/(?<=^<p>)\s?(<br \/>\s?)*|/m' //Lookbehind assertion, matches \s?(<br \/>\s?)* only if it's followed by ^<p>
        ];
        $desc_clean = preg_replace($patterns, '', $desc_fix_br);

        //htmlspecialchars converts all the special characters including the tags so I need to manage it.
        //for &, is decodified in prestashop
        $desc_clean = preg_replace('/&/', "&amp;", $desc_clean);
        //for greater and lesser than symbol, Prestashop decode it. Attemps to decode it and avoid touching the tags.
        //Pick the "\s>" followed (?=) by one or more numbers. To avoid changing open/close tags < and >. must have a space before it.
        $desc_clean = preg_replace('/\s>(?=\d+)/', "&gt;", $desc_clean);
        //For lesser than, it may not have the space.
        $desc_clean = preg_replace('/\s?<(?=\d+)/', "&lt;", $desc_clean);
        //If it have two spaces instead of one beetwen words removes one.
        $desc_clean = preg_replace('/\s\s/', ' ', $desc_clean);

        //For some products with <tag><br/> </tag>. Remove later tag and puts it before <br/>, wild card from 0 to 5 characters because may be at some distance.
        $desc_clean = preg_replace('/<br \/>.{0,5}<\/em>/', '</em><br />', $desc_clean);
        $desc_clean = preg_replace('/<br \/>.{0,5}<\/strong>/', '</strong><br />', $desc_clean);

        //changes <br/> to a new <p> (if it isn't followed by a nearing (0-20 characters) </span> tag).
        $desc_clean = preg_replace('/(<br \/>\s?)+(?!(.){0,20}<\/span)/', '</p><p>', $desc_clean);

        return $desc_clean;
    }

    /**
     * Obtain manufacturer_id from the name. If no manufacturer_id is found in the database attempts to create it.
     * @param string $ref product reference, to add information
     * @param string $brand_name, name of the brand
     */
    private function check_brand_id(string $ref, string $brand_name, bool $update)
    {
        if (empty($brand_name)) {
            return;
        }

        $id_manufacturer = $this->brands[$brand_name];
        if (empty($id_manufacturer)) {

            //if write isn't set
            if (!$update) {
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
