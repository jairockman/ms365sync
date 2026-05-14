<?php

// Aseguramos que la clase de conexión esté cargada
include_once(dirname(__FILE__) . '/inc/msgraph.class.php');
include_once(dirname(__FILE__) . '/inc/actualtimemonitor.class.php');

/**
 * Hook ejecutado cuando se crea o actualiza una tarea o evento en GLPI.
 * Se encarga estrictamente de la sincronización del Calendario (Outlook).
 */
function plugin_ms365sync_hook_update_task($item) {
   // Evitar sincronizar de vuelta si estamos en medio de una importación
   if (PluginMs365syncMsGraph::$is_importing) {
      return;
   }

   // 1. Lista de tipos soportados (Seguridad: evita procesar objetos no deseados)
   $supported_types = [
      'TicketTask', 'ProblemTask', 'ChangeTask', 'ProjectTask', 
      'PlanningExternalEvent', 'PlanningEvent'
   ];

   if (!in_array($item->getType(), $supported_types)) {
      return;
   }

   // 2. Solo sincronizamos si tiene planificación (begin) o fecha (date)
   if (empty($item->fields['begin']) && empty($item->fields['date'])) {
      return;
   }

   $sync = new PluginMs365syncMsGraph();
   $sync->syncTaskToMS($item);
}

/**
 * NOTA: La integración con Actualtime se realiza mediante un Cron Task (monitorTimers)
 * que detecta cambios en la tabla glpi_plugin_actualtime_tasks.
 * 
 * Actualtime no dispara hooks estándar de GLPI, por lo que usamos monitoreo por cron
 * en lugar de hooks.
 * Ver: PluginMs365syncActualtimeMonitor::monitorTimers()
 */

/**
 * Hook ejecutado al eliminar (o enviar a papelera) un objeto en GLPI.
 * Borra el evento correspondiente en Microsoft 365 para mantener la integridad.
 */
function plugin_ms365sync_hook_delete_task($item) {
   global $DB;
   
   $supported_types = [
      'TicketTask', 'ProblemTask', 'ChangeTask', 'ProjectTask', 
      'PlanningExternalEvent', 'PlanningEvent'
   ];
   
   if (!in_array($item->getType(), $supported_types)) {
      return;
   }

   $table_map = "glpi_plugin_ms365sync_event_map";
   
   // Buscamos la relación en nuestra tabla de mapeo
   $iterator = $DB->getIterator($table_map, [
      'glpi_itemtype' => $item->getType(),
      'glpi_items_id' => $item->fields['id']
   ]);

   foreach ($iterator as $row) {
      try {
         $graph = new PluginMs365syncMsGraph();
         
         // Intentamos borrar el evento en Outlook primero
         if ($graph->deleteOutlookEvent($row['ms_user_principal'], $row['ms_event_id'])) {
            // Solo si Microsoft confirma el borrado (o el evento ya no existe), borramos nuestro mapa
            $DB->delete($table_map, ['id' => $row['id']]);
         } else {
            Toolbox::logInFile("ms365sync", "Fallo al borrar evento en MS Graph para: " . $row['ms_user_principal'] . "\n");
         }
      } catch (Exception $e) {
         // Seguridad: el log evita que una falla de red con Microsoft bloquee el borrado en GLPI
         Toolbox::logInFile("ms365sync", "Excepción al borrar evento: " . $e->getMessage() . "\n");
      }
   }
}

/**
 * Hook ejecutado cuando un usuario es purgado (borrado definitivo).
 * Limpia todas las configuraciones y mapeos asociados para evitar colisiones de ID.
 */
function plugin_ms365sync_hook_purge_user($item) {
   global $DB;
   $uid = (int)$item->fields['id'];
   
   // Limpiar configuración de sincronización del usuario
   $DB->delete("glpi_plugin_ms365sync_users", ['users_id' => $uid]);
   
   // Limpiar estados de monitoreo de Actualtime
   $DB->delete("glpi_plugin_ms365sync_actualtime_state", ['users_id' => $uid]);
   
   Toolbox::logInFile("ms365sync", "Datos del plugin purgados para el usuario ID: $uid\n", true);
}

/**
 * Hook ejecutado cuando una entidad es purgada.
 */
function plugin_ms365sync_hook_purge_entity($item) {
   global $DB;
   $DB->delete("glpi_plugin_ms365sync_tenants", ['entities_id' => $item->fields['id']]);
   Toolbox::logInFile("ms365sync", "Tenants purgados para la entidad ID: " . $item->fields['id'] . "\n", true);
}

/**
 * Hook ejecutado cuando un perfil es purgado.
 */
function plugin_ms365sync_hook_purge_profile($item) {
   global $DB;
   // GLPI Core limpia glpi_profilerights, pero nos aseguramos de loguear la acción
   // para auditoría del plugin.
   Toolbox::logInFile("ms365sync", "Derechos del plugin purgados para el perfil ID: " . $item->fields['id'] . "\n", true);
}
