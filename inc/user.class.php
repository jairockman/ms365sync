<?php

class PluginMs365syncUser extends CommonDBTM {

   static function getTable($relative_name = "glpi_plugin_ms365sync_users") {
      return "glpi_plugin_ms365sync_users";
   }

   public function prepareInputForUpdate($input) {
      if (isset($input['is_sync_enabled'])) {
         $input['is_sync_enabled'] = (int)$input['is_sync_enabled'];
      }
      return $input;
   }

   public function prepareInputForAdd($input) {
      return $this->prepareInputForUpdate($input);
   }

   static function getFormURL($full = true) {
      return Toolbox::getItemTypeFormURL(__CLASS__, $full);
   }

   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      if ($item->getType() == 'User' || $item->getType() == 'Preference') {
         return "Microsoft 365 Sync";
      }
      return '';
   }

   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      $config = new self();
      if ($item->getType() == 'User') {
         $config->showFormForUser($item->getID());
      } else if ($item->getType() == 'Preference') {
         $config->showFormForUser(Session::getLoginUserID());
      }
      return true;
   }

   public function showFormForUser($users_id) {
      global $DB;
      
      if (!$this->getFromDBByCrit(['users_id' => $users_id])) {
         $this->fields = [
               'id'                 => 0,
               'users_id'           => $users_id,
               'is_sync_enabled'    => 0,
               'sync_months_past'   => '',
               'sync_months_future' => '',
               'prefix_tasks'       => '',
               'prefix_events'      => '',
               'teams_status_msg'   => '',
               'refresh_token'      => ''
         ];
      }

      echo "<form action='" . self::getFormURL() . "' method='post'>";
      echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
      echo Html::hidden('id', ['value' => $this->fields['id']]);
      echo Html::hidden('users_id', ['value' => $users_id]);

      echo "<div class='spaced center'><table class='tab_cadre_fixe'>";
      echo "<tr><th colspan='2'>" . __("Microsoft 365 Synchronization", "ms365sync") . "</th></tr>";
      
      echo "<tr class='tab_bg_1'><td>" . __("Enable Synchronization", "ms365sync") . "</td><td>";
      Dropdown::showYesNo("is_sync_enabled", $this->fields['is_sync_enabled']);
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'><td>" . __("Teams Personal Authorization", "ms365sync") . "</td><td>";
      if (!empty($this->fields['refresh_token'])) {
         echo "<span class='badge bg-success'><i class='fas fa-check'></i> " . __("Authorized", "ms365sync") . "</span>";
      } else {
         $sync = new PluginMs365syncMsGraph();
         $auth_url = $sync->getAuthorizationUrl($users_id);
         echo "<a href='$auth_url' class='btn btn-outline-primary btn-sm'>
                  <i class='fab fa-windows'></i> " . __("Authorize Microsoft Teams", "ms365sync") . "
               </a>";
         echo "<p><small class='text-muted'>" . __("Required for Presence and Status updates", "ms365sync") . "</small></p>";
      }
      echo "</td></tr>";
      
      echo "<tr class='tab_bg_1'><td>" . __("Force Re-sync Calendar", "ms365sync") . "</td><td>";
      echo "<a href='" . Plugin::getWebDir('ms365sync', false) . "/front/resync_user_events.php?users_id=$users_id' class='btn btn-outline-warning btn-sm' 
               onclick='return confirm(\"".__("This will force a re-synchronization of all your GLPI events with Outlook. This may take some time. Are you sure?", "ms365sync")."\")'>
               <i class='fas fa-sync-alt'></i> " . __("Re-sync My Events", "ms365sync") . "
            </a>";
      echo "<p><small class='text-muted'>" . __("Use this if your Outlook calendar events are not showing correctly in GLPI.", "ms365sync") . "</small></p></td></tr>";

      // Prefijos Personalizados
      echo "<tr class='headerrow'><td colspan='2'><b>" . __("Personalized Prefixes", "ms365sync") . "</b></td></tr>";
      echo "<tr class='tab_bg_1'><td>" . __("Task Prefix", "ms365sync") . "</td>";
      echo "<td>";
      Dropdown::showFromArray("use_prefix_tasks", [-1 => __("Inherit from tenant", "ms365sync"), 0 => __("No"), 1 => __("Yes")], [
         'value' => $this->fields['use_prefix_tasks'] ?? -1,
         'rand'  => $rand = mt_rand()
      ]);
      echo " <input type='text' id='prefix_tasks_$rand' name='prefix_tasks' value='" . $this->fields['prefix_tasks'] . "' class='form-control' style='width: 60%; display: inline-block;' placeholder='".__("Inherit from tenant", "ms365sync")."' ". (($this->fields['use_prefix_tasks'] ?? -1) == 1 ? "" : "disabled") .">";
      echo "<p class='text-muted'><small>" . __("Select 'Yes' to customize, 'No' to disable, or 'Inherit' to use the Tenant settings.", "ms365sync") . "</small></p></td></tr>";

      echo "<tr class='tab_bg_1'><td>" . __("Event Prefix", "ms365sync") . "</td>";
      echo "<td>";
      Dropdown::showFromArray("use_prefix_events", [-1 => __("Inherit from tenant", "ms365sync"), 0 => __("No"), 1 => __("Yes")], [
         'value' => $this->fields['use_prefix_events'] ?? -1,
         'rand'  => $rand_ev = mt_rand()
      ]);
      echo " <input type='text' id='prefix_events_$rand_ev' name='prefix_events' value='" . $this->fields['prefix_events'] . "' class='form-control' style='width: 60%; display: inline-block;' placeholder='".__("Inherit from tenant", "ms365sync")."' ". (($this->fields['use_prefix_events'] ?? -1) == 1 ? "" : "disabled") .">";
      echo "<p class='text-muted'><small>" . __("Select 'Yes' to customize, 'No' to disable, or 'Inherit' to use the Tenant settings.", "ms365sync") . "</small></p></td></tr>";

      // Teams Personalizado
      echo "<tr class='headerrow'><td colspan='2'><b>" . __("Teams Integration", "ms365sync") . "</b></td></tr>";
      echo "<tr class='tab_bg_1'><td>" . __("Personal Status Message", "ms365sync") . "</td>";
      echo "<td>";
      Dropdown::showFromArray("use_teams_status_prefix", [-1 => __("Inherit from tenant", "ms365sync"), 0 => __("No"), 1 => __("Yes")], [
         'value' => $this->fields['use_teams_status_prefix'] ?? -1,
         'rand'  => $rand_tm = mt_rand()
      ]);
      echo " <input type='text' id='teams_status_msg_$rand_tm' name='teams_status_msg' value='" . $this->fields['teams_status_msg'] . "' class='form-control' style='width: 60%; display: inline-block;' placeholder='".__("Inherit from tenant", "ms365sync")."' ". (($this->fields['use_teams_status_prefix'] ?? -1) == 1 ? "" : "disabled") .">";
      echo "<p class='text-muted'><small>" . __("Select 'Yes' to customize, 'No' to use the default message, or 'Inherit' for global settings.", "ms365sync") . "</small></p></td></tr>";

      // Rangos de tiempo
      echo "<tr class='headerrow'><td colspan='2'><b>" . __("Sync Range Override", "ms365sync") . "</b></td></tr>";
      echo "<tr class='tab_bg_1'><td>" . __("Months in the PAST", "ms365sync") . "</td>";
      echo "<td><input type='number' name='sync_months_past' value='" . $this->fields['sync_months_past'] . "' class='form-control' placeholder='".__("Inherit from tenant", "ms365sync")."'>";
      echo "<p class='text-muted'><small>" . __("If set to 0, it will sync only the last 7 days.", "ms365sync") . "</small></p></td></tr>";

      echo "<tr class='tab_bg_1'><td>" . __("Months in the FUTURE", "ms365sync") . "</td>";
      echo "<td><input type='number' name='sync_months_future' value='" . $this->fields['sync_months_future'] . "' class='form-control' placeholder='".__("Inherit from tenant", "ms365sync")."'>";
      echo "<p class='text-muted'><small>" . __("If set to 0, it will sync the next 7 days.", "ms365sync") . "</small></p></td></tr>";

      echo "<tr><td colspan='2' class='center'>";
      echo "<input type='submit' name='update' value='".__("Save Configuration", "ms365sync")."' class='btn btn-primary'>";
      echo "</td></tr>";

      echo "</table></div>";
      
      // Script para manejar los dropdowns del usuario
      echo Html::scriptBlock("
         $(document).on('change', 'select[name^=\"use_\"]', function() {
            var val = $(this).val();
            var input = $(this).closest('td').find('input[type=\"text\"]');
            input.prop('disabled', val != 1);
         });
      ");

      Html::closeForm();
   }
}
