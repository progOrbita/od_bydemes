<?php

use OrbitaDigital\OdBydemes\Bydemes;

if(!defined('_PS_VERSION_')){
    require_once '../../config/config.inc.php';
    require_once '../../init.php';
}
include('src/Bydemes.php');
//el stock disponible es: quantity en ps_stock_available. Requiere del id_product (de ps_product)
// el id_stock_available deberia sumarse si el id_product es el mismo
var_dump(Bydemes::seeStock());