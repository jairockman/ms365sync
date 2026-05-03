<?php

/**
 * Clase para monitorear timers de Actualtime e integrar con Microsoft Teams
 */
class PluginMs365syncActualtimeMonitor extends CommonDBTM {

   /**
    * Nombre de la tabla
    */
   public static function getTable($classname = null) {
      return 'glpi_plugin_ms365sync_actualtime_state';
   }

   /**
    * Ejecutar el monitoreo de timers
    */
   public static function cronMonitorTimers(CronTask $crontask) {
      global $DB;

      // Si actualtime no está activo, no hacer nada
      if (!Plugin::isPluginActive('actualtime')) {
         return 1;
      }

      try {
         $monitor = new self();
         $monitor->checkAndSync();
         
         $crontask->setVolume(1);
         return 1; // Success
      } catch (Exception $e) {
         Toolbox::logInFile("ms365sync", "Error en monitoreo de timers: " . $e->getMessage() . "\n");
         return 0; // Failed
      }
   }

   /**
    * Verificar cambios en timers y sincronizar con Teams
    */
   private function checkAndSync() {
      global $DB;

      $table_actualtime = 'glpi_plugin_actualtime_tasks';
      $table_state = self::getTable();

      // 1. Buscar timers ACTIVOS (recién iniciados)
      $iterator = $DB->request([
         'FROM'  => $table_actualtime,
         'WHERE' => [
            ['NOT' => ['actual_begin' => null]],
            'actual_end'  => null,
         ]
      ]);

      foreach ($iterator as $timer) {
         $this->handleActiveTimer($timer);
      }

      // 2. Buscar timers COMPLETADOS (recién detenidos)
      $iterator_completed = $DB->request([
         'FROM'  => $table_actualtime,
         'WHERE' => [
            ['NOT' => ['actual_begin' => null]],
            ['NOT' => ['actual_end' => null]],
         ],
         'ORDER' => 'actual_end DESC',
         'LIMIT' => 100
      ]);

      foreach ($iterator_completed as $timer) {
         $this->handleCompletedTimer($timer);
      }
   }

   /**
    * Manejar timer activo (iniciado recientemente)
    */
   private function handleActiveTimer($timer) {
      global $DB;

      $table_state = self::getTable();
      $timer_id = $timer['id'];
      $users_id = $timer['users_id'];

      // Verificar si ya se procesó este timer
      $exists = countElementsInTable(
         $table_state,
         ['actualtime_id' => $timer_id, 'status' => 'started']
      );

      if ($exists === 0) {
         // Timer no procesado. Registrarlo y ejecutar hook
         $taskItem = new $timer['itemtype']();
         if ($taskItem->getFromDB($timer['items_id'])) {
            try {
               $sync = new PluginMs365syncMsGraph();
               
               // Cambiar estado en Teams a "Ocupado" con el enlace
               $sync->setTeamsPresence($users_id, true, $taskItem);

               // Registrar como procesado
               $DB->insert($table_state, [
                  'actualtime_id'  => $timer_id,
                  'itemtype'       => $timer['itemtype'],
                  'items_id'       => $timer['items_id'],
                  'users_id'       => $users_id,
                  'status'         => 'started',
                  'processed_at'   => date('Y-m-d H:i:s')
               ]);

               Toolbox::logInFile("ms365sync", "Timer iniciado interceptado para usuario: $users_id (Timer ID: $timer_id)\n");
            } catch (Exception $e) {
               Toolbox::logInFile("ms365sync", "Error al procesar timer iniciado: " . $e->getMessage() . "\n");
            }
         }
      }
   }

   /**
    * Manejar timer completado (detenido recientemente)
    */
   private function handleCompletedTimer($timer) {
      global $DB;

      $table_state = self::getTable();
      $timer_id = $timer['id'];
      $users_id = $timer['users_id'];

      // Verificar si ya se procesó este timer como completado
      $exists = countElementsInTable(
         $table_state,
         ['actualtime_id' => $timer_id, 'status' => 'stopped']
      );

      if ($exists === 0) {
         try {
            // Obtener el estado anterior
            $prev_state = $DB->request([
               'SELECT' => 'id',
               'FROM'   => $table_state,
               'WHERE'  => ['actualtime_id' => $timer_id, 'status' => 'started'],
               'LIMIT'  => 1
            ])->current();

            if ($prev_state) {
               $sync = new PluginMs365syncMsGraph();

               // 1. Cambiar estado en Teams a "Disponible"
               $sync->setTeamsPresence($users_id, false);

               // 2. Sincronizar tarea en Outlook (actualizar duración y fecha fin)
               $taskItem = new $timer['itemtype']();
               if ($taskItem->getFromDB($timer['items_id'])) {
                  $sync->syncTaskToMS($taskItem);
               }

               // Actualizar registro de estado
               $DB->update($table_state, 
                  ['status' => 'stopped', 'processed_at' => date('Y-m-d H:i:s')],
                  ['id' => $prev_state['id']]
               );

               Toolbox::logInFile("ms365sync", "Timer detenido interceptado para usuario: $users_id (Timer ID: $timer_id)\n");
            }
         } catch (Exception $e) {
            Toolbox::logInFile("ms365sync", "Error al procesar timer detenido: " . $e->getMessage() . "\n");
         }
      }
   }
}
