<?php
include ("../../../inc/includes.php");

// Seguridad de nivel superior
Session::checkLoginUser();
Session::checkRight("config", UPDATE);

$tenant = new PluginMs365syncTenants();

if (isset($_POST["add"])) {
    // En lugar de $tenant->check(), validamos el derecho general de GLPI
    if (!Session::haveRight("config", UPDATE)) {
        Html::displayRightError();
    }
    $newID = $tenant->add($_POST);
    Html::redirect($tenant->getFormURL($newID));
}elseif (isset($_POST["update"])) {
    if (!Session::haveRight("config", UPDATE)) {
        Html::displayRightError();
    }
    $tenant->update($_POST);
    Html::back();
}elseif (isset($_POST["delete"])) {
    if (!Session::haveRight("config", UPDATE)) {
        Html::displayRightError();
    }
    if (isset($_POST['id'])) {
        // Cargamos el objeto primero para asegurar que existe
        if ($tenant->getFromDB($_POST['id'])) {
            // Pasamos el ID para borrar
            if ($tenant->delete(['id' => $_POST['id']])) {
                Html::redirect($tenant->getSearchURL());
            }
        }
    }
    Html::back();
}else {
    Html::header("Microsoft 365 Tenants", $_SERVER['PHP_SELF'], "config", "PluginMs365syncTenants");
    
    if (isset($_GET['id']) || isset($_GET['new'])) {
        $tenant->showFormConfig();
    } else {
        Html::redirect($tenant->getSearchURL());
    }

    Html::footer();
}

