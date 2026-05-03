<?php

define('PLUGIN_MS365SYNC_VERSION', '1.0.0');
define('PLUGIN_MS365SYNC_MIN_GLPI', '10.0.0');
define('PLUGIN_MS365SYNC_MAX_GLPI', '10.0.99');
define('PLUGIN_MS365SYNC_ROOT', Plugin::getPhpDir('ms365sync'));
define('PLUGIN_MS365SYNC_DATE_FORMAT', 'Y-m-d H:i:s');

function plugin_init_ms365sync() {
   global $PLUGIN_HOOKS;

   $PLUGIN_HOOKS['csrf_compliant']['ms365sync'] = true;

   if (Plugin::isPluginActive('ms365sync')) {

      Plugin::registerClass('PluginMs365syncUser', [
         'addtabon' => ['User', 'Preference']
      ]);

      // Registrar la clase para monitoreo de Actualtime
      if (Plugin::isPluginActive('actualtime')) {
         Plugin::registerClass('PluginMs365syncActualtimeMonitor');
      }

      $PLUGIN_HOOKS['config_page']['ms365sync'] = 'front/tenants.php';

      $PLUGIN_HOOKS['menu_toadd']['ms365sync'] = [
         'config' => 'PluginMs365syncTenants',
      ];

      // Objetos soportados (Incluyendo ProjectTask)
      $types = ['TicketTask', 'ProblemTask', 'ChangeTask', 'ProjectTask', 'PlanningExternalEvent', 'PlanningEvent'];

      foreach ($types as $type) {
         $PLUGIN_HOOKS['item_add']['ms365sync'][$type]    = 'plugin_ms365sync_hook_update_task';
         $PLUGIN_HOOKS['item_update']['ms365sync'][$type] = 'plugin_ms365sync_hook_update_task';
         $PLUGIN_HOOKS['item_delete']['ms365sync'][$type] = 'plugin_ms365sync_hook_delete_task';
      }
   }
   
   // Sincronizar eventos en Outlook
   CronTask::register('PluginMs365syncMsGraph', 'syncEvents', 300, [
      'param' => 24,
      'mode'  => CronTask::MODE_EXTERNAL
   ]);

   // Monitorear timers de Actualtime e integrar con Teams
   if (Plugin::isPluginActive('actualtime')) {
      CronTask::register('PluginMs365syncActualtimeMonitor', 'monitorTimers', 60, [
         'param' => 1,
         'mode'  => CronTask::MODE_EXTERNAL
      ]);
   }
}

function plugin_ms365sync_install() {
   global $DB;

   // Tabla de Tenants
   $table_tenants = "glpi_plugin_ms365sync_tenants";
   if (!$DB->tableExists($table_tenants)) {
      $query = "CREATE TABLE `$table_tenants` (
         `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
         `name` VARCHAR(255),
         `domain` VARCHAR(255),
         `tenant_id` VARCHAR(255),
         `client_id` VARCHAR(255),
         `client_secret` TEXT,
         `active` TINYINT(1) DEFAULT 1,
         `sync_months_past` INT(11) DEFAULT 1,
         `sync_months_future` INT(11) DEFAULT 12,
         `prefix_tasks` VARCHAR(50) DEFAULT 'Task: ',
         `prefix_events` VARCHAR(50) DEFAULT 'Event: ',
         `teams_status_msg` VARCHAR(255) DEFAULT 'Working on: ',
         PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
      $DB->query($query);
   }

   // Tabla de Usuarios
   $table_users = "glpi_plugin_ms365sync_users";
   if (!$DB->tableExists($table_users)) {
      $query = "CREATE TABLE `$table_users` (
         `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
         `users_id` INT(11) NOT NULL,
         `is_sync_enabled` TINYINT(1) DEFAULT 0,
         `sync_months_past` INT(11) DEFAULT NULL,
         `sync_months_future` INT(11) DEFAULT NULL,
         `prefix_tasks` VARCHAR(50) DEFAULT NULL,
         `prefix_events` VARCHAR(50) DEFAULT NULL,
         `teams_status_msg` VARCHAR(255) DEFAULT NULL,
         `refresh_token` TEXT DEFAULT NULL,
         PRIMARY KEY (`id`),
         UNIQUE KEY `users_id` (`users_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
      $DB->query($query);
   }

   // Tabla de Mapeo de Eventos
   $table_map = "glpi_plugin_ms365sync_event_map";
   if (!$DB->tableExists($table_map)) {
      $query = "CREATE TABLE `$table_map` (
         `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
         `glpi_itemtype` VARCHAR(100) NOT NULL,
         `glpi_items_id` INT(11) NOT NULL,
         `ms_event_id` VARCHAR(255) NOT NULL,
         `ms_user_principal` VARCHAR(255) NOT NULL,
         `last_sync_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         `ms_last_modified` TIMESTAMP DEFAULT NULL,
         `hash` VARCHAR(32),
         PRIMARY KEY (`id`),
         KEY `glpi_item` (`glpi_itemtype`, `glpi_items_id`),
         KEY `ms_event` (`ms_event_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
      $DB->query($query) or die($DB->error());
   }

   // Tabla de Estado de Timers de Actualtime
   $table_state = "glpi_plugin_ms365sync_actualtime_state";
   if (!$DB->tableExists($table_state)) {
      $query = "CREATE TABLE `$table_state` (
         `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
         `actualtime_id` INT(11) UNSIGNED NOT NULL,
         `itemtype` VARCHAR(100),
         `items_id` INT(11),
         `users_id` INT(11),
         `status` ENUM('started', 'stopped') DEFAULT 'started',
         `processed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
         PRIMARY KEY (`id`),
         KEY `actualtime_id` (`actualtime_id`),
         KEY `status` (`status`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
      $DB->query($query);
   }

   return true;
}

/**
 * Desinstalación del plugin
 */
function plugin_ms365sync_uninstall() {
   global $DB;

   $tables = [
      "glpi_plugin_ms365sync_tenants",
      "glpi_plugin_ms365sync_event_map",
      "glpi_plugin_ms365sync_users",
      "glpi_plugin_ms365sync_actualtime_state",
   ];

   foreach ($tables as $table) {
      if ($DB->tableExists($table)) {
         $DB->dropTable($table);
      }
   }

   return true;
}

function plugin_ms365sync_check_prerequisites() {
   if (!extension_loaded('curl')) {
      echo "El plugin requiere la extensión cURL de PHP.";
      return false;
   }
   return true;
}

function plugin_ms365sync_getConfigURL() {
   return 'front/tenants.php';
}

function plugin_version_ms365sync() {
   return [
      'name'           => 'MS365 Sync',
      'version'        => PLUGIN_MS365SYNC_VERSION,
      'author'         => 'Jairo Cervantes',
      'license'        => 'GPLv3+',
      'homepage'       => 'https://github.com/jairockman/ms365sync',
      'requirements'   => [
         'glpi' => [
            'min' => PLUGIN_MS365SYNC_MIN_GLPI,
            'max' => PLUGIN_MS365SYNC_MAX_GLPI,
         ],
         'php' => [
            'min' => '8.1',
         ]
      ]
   ];
}
