<?php

declare(strict_types=1);

namespace OrbitaDigital\OdBydemes;

use Db;

if (!defined('_PS_VERSION_')) {
    require_once '../../config/config.inc.php';
    require_once '../../init.php';
}

class Bydemes{
//el stock disponible es: quantity en ps_stock_available. Requiere del id_product (de ps_product)
// el id_stock_available deberia sumarse si el id_product es el mismo
    public static function seeStock(){
        return(Db::getInstance()->executeS('SELECT pp.quantity FROM `ps_product` p INNER JOIN `ps_stock_available` pp ON p.id_product = pp.id_product WHERE p.id_supplier = 1'));
    }
}
