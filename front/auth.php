<?php
include ("../../../inc/includes.php");
Session::checkLoginUser();

if (isset($_GET['code']) && isset($_GET['state'])) {
    $users_id = (int)$_GET['state'];
    $code = $_GET['code'];
    
    $user_email = (new PluginMs365syncMsGraph())->getUserEmail($users_id);
    $domain = explode('@', $user_email)[1];
    
    global $DB;
    $tenant = $DB->request("glpi_plugin_ms365sync_tenants", ['domain' => $domain, 'active' => 1])->current();
    $key_manager = new GLPIKey();

    // Intercambio de código por token
    $url = "https://login.microsoftonline.com/" . $tenant['tenant_id'] . "/oauth2/v2.0/token";
    global $CFG_GLPI;

    $base_url = rtrim($CFG_GLPI['url_base'] ?? '', '/');
    if (empty($base_url)) {
        $base_url = rtrim(Toolbox::getWebBaseUrl(), '/');
    }
    $redirect_uri = $base_url . '/' . ltrim(Plugin::getWebDir('ms365sync', false), '/') . "/front/auth.php";

    $post_fields = [
        'client_id'     => $tenant['client_id'],
        'client_secret' => $key_manager->decrypt($tenant['client_secret']),
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $redirect_uri,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    curl_close($ch);

    if (isset($data['refresh_token'])) {
        $user_plugin = new PluginMs365syncUser();
        $encrypted_token = $key_manager->encrypt($data['refresh_token']);
        
        if ($user_plugin->getFromDBByCrit(['users_id' => $users_id])) {
            $user_plugin->update(['id' => $user_plugin->fields['id'], 'refresh_token' => $encrypted_token]);
        } else {
            $user_plugin->add(['users_id' => $users_id, 'refresh_token' => $encrypted_token, 'is_sync_enabled' => 1]);
        }
        Toolbox::logInFile("ms365sync", "Autorización OAuth2 exitosa para el usuario ID: $users_id\n");
        Session::addMessageAfterRedirect(__("Microsoft Teams authorized successfully!", "ms365sync"), true, INFO);
    } else {
        Session::addMessageAfterRedirect(__("Authorization failed", "ms365sync"), true, ERROR);
        Toolbox::logInFile("ms365sync", "Auth Error: " . $response);
    }
}

Html::redirect(Toolbox::getItemTypeFormURL('User') . "?id=" .
Session::getLoginUserID() . "&forcetab=PluginMs365syncUser$1");
