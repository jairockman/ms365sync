<?php
include("../../../inc/includes.php");
Session::checkLoginUser();

$user_plugin = new PluginMs365syncUser();

if (isset($_POST["update"])) {
    $uid = $_POST['users_id'];
    
    // Validación de seguridad (Admin o el propio usuario)
    if ($uid != Session::getLoginUserID() && !Session::haveRight("user", UPDATE)) {
        Html::displayRightError();
    }

    // Limpiamos y preparamos el array de datos
    $input = [
        'users_id'           => (int)$_POST['users_id'],
        'is_sync_enabled'    => (int)$_POST['is_sync_enabled'],
        'sync_months_past'   => ($_POST['sync_months_past'] === '') ? 'NULL' : (int)$_POST['sync_months_past'],
        'sync_months_future' => ($_POST['sync_months_future'] === '') ? 'NULL' : (int)$_POST['sync_months_future']
    ];

    // LÓGICA DE GUARDADO DEFINITIVA
    if (isset($_POST['id']) && (int)$_POST['id'] > 0) {
        // El registro ya existe, actualizamos usando el ID de la tabla
        $input['id'] = (int)$_POST['id'];
        $user_plugin->update($input);
    } else {
        // Es un usuario nuevo en esta tabla, creamos el registro
        $user_plugin->add($input);
    }

    Toolbox::logInFile("ms365sync", "Preferencias de usuario actualizadas para ID: $uid\n", true);

    Html::back();
}
