<?php
// Incluimos el cargador de GLPI (esto carga todas las clases y verifica la sesión)
include ("../../../inc/includes.php");

// Verificamos que el usuario tenga permisos de configuración (Administrador)
Session::checkRight("config", UPDATE);

$config = new PluginMs365syncTenants();

// Generamos la cabecera de la página web
Html::header("Microsoft 365 Tenants", $_SERVER['PHP_SELF'], "config", "PluginMs365syncTenants");

// Mostramos el formulario o la lista de la clase
$config->showFormConfig();

// Mostramos el formulario o la lista de la clase
//Search::show('PluginMs365syncTenants');

Html::footer();

