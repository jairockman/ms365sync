<?php

include ("../../../inc/includes.php");

// Verificar permisos de administrador
if (!PluginMs365syncTenants::canUpdate()) {
   Html::displayRightError();
}

$sync = new PluginMs365syncMsGraph();

// Llamar a la función sin users_id para que afecte a todos
if ($sync->resetMsLastModified()) {
    Session::addMessageAfterRedirect(__("Re-synchronization initiated for ALL events. The changes will be visible after the next cron execution.", "ms365sync"), true, INFO);
} else {
    Session::addMessageAfterRedirect(__("Failed to initiate re-synchronization for ALL events.", "ms365sync"), true, ERROR);
}

// Redirigir de vuelta a la página de configuración del plugin
global $CFG_GLPI;
Html::redirect($CFG_GLPI["root_doc"] . "/" . Plugin::getWebDir('ms365sync', false) . "/front/tenants.php");

?>
