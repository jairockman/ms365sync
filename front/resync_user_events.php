<?php

include ("../../../inc/includes.php");

// Verificar autenticación y permisos
Session::checkLoginUser();

$users_id = (int) ($_GET['users_id'] ?? 0);
$is_preference = (int) ($_GET['_is_preference'] ?? 0);

// El usuario solo puede re-sincronizar sus propios eventos, a menos que tenga permisos de gestión de tenants
if ($users_id !== Session::getLoginUserID() && !PluginMs365syncTenants::canUpdate()) {
    Html::displayRightError();
}

if ($users_id > 0) {
    $sync = new PluginMs365syncMsGraph();
    if ($sync->resetMsLastModified($users_id)) {
        Session::addMessageAfterRedirect(__("Re-synchronization initiated for your events. The changes will be visible after the next cron execution.", "ms365sync"), true, INFO);
    } else {
        Session::addMessageAfterRedirect(__("Failed to initiate re-synchronization for your events.", "ms365sync"), true, ERROR);
    }
} else {
    Session::addMessageAfterRedirect(__("Invalid user ID for re-synchronization.", "ms365sync"), true, ERROR);
}

if ($is_preference) {
    // Si el origen es preference.php, regresamos ahí
    Html::redirect($CFG_GLPI["root_doc"] . "/front/preference.php?forcetab=PluginMs365syncUser$1");
} else {
    // De lo contrario, regresamos al perfil del usuario
    Html::redirect(User::getFormURLWithID($users_id) . "&forcetab=PluginMs365syncUser$1");
}

?>
