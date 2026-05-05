<?php

class PluginMs365syncTenants extends CommonDBTM {

   static $rightname = 'config';

   static function getTypeName($nb = 0) {
      return _n('Microsoft 365 Tenant', 'Microsoft 365 Tenants', $nb, 'ms365sync');
   }

   static function getTable($relative_name = "glpi_plugin_ms365sync_tenants") {
      return "glpi_plugin_ms365sync_tenants";
   }

   public function rawSearchOptions() : array {
      return [];
   }

   static function getIcon() {
      return "ti ti-brand-office";
   }

   static function getSearchURL($full = true) {
      return Toolbox::getItemTypeSearchURL('PluginMs365syncTenants', $full);
   }

   public function showFormConfig() {
      if (isset($_GET['id']) || isset($_GET['new'])) {
         $this->showTenantForm($_GET['id'] ?? 0);      
      } else {
         $this->showTenantsList();
      }
   }

   public function showTenantsList() {
      global $DB;
      $table = self::getTable();

      echo "<div class='center'>";
      echo "<h2>" . __("Microsoft 365 Tenant Configuration", "ms365sync") . "</h2>";
      
      echo "<div class='mb-2'>";
      echo "<a class='v-middle btn btn-primary' href='" . $this->getFormURL() . "?new=1'>
               <i class='fas fa-plus'></i> " . __("Add New Domain/Tenant", "ms365sync") . "
            </a>";
      echo "</div>";

      echo "<table class='tab_cadre_fixehov'>";
      echo "<tr>
               <th>".__("Name")."</th>
               <th>".__("Domain", "ms365sync")."</th>
               <th>".__("Tenant ID", "ms365sync")."</th>
               <th>".__("Status")."</th>
               <th>".__("Actions")."</th>
            </tr>";

      foreach ($DB->request($table) as $data) {
         echo "<tr class='tab_bg_1'>";
         echo "<td>" . $data['name'] . "</td>";
         echo "<td><b>" . $data['domain'] . "</b></td>";
         echo "<td>" . $data['tenant_id'] . "</td>";
         echo "<td>" . ($data['active'] ? __("Active") : __("Inactive")) . "</td>";
         echo "<td>
                     <a href='" . $this->getFormURL() . "?id=" . $data['id'] . "' class='btn btn-sm btn-info' title='".__("Edit")."'>
                        <i class='fas fa-edit'></i>
                     </a>
                     <form action='".$this->getFormURL()."' method='post' style='display:inline-block'>
                        <input type='hidden' name='_glpi_csrf_token' value='".Session::getNewCSRFToken()."'>
                        <input type='hidden' name='id' value='".$data['id']."'>
                        <button type='submit' name='delete' class='btn btn-sm btn-outline-danger' 
                              onclick='return confirm(\"".__("Confirm delete?")."\")' title='".__("Delete")."'>
                           <i class='fas fa-trash'></i>
                        </button>
                     </form>
                  </td>";
         echo "</tr>";
      }
      echo "</table></div>";
   }

   public function showTenantForm($id) {
      if ($id > 0) {
         $this->getFromDB($id);
      }

      echo "<form action='" . $this->getFormURL() . "' method='post'>";
      echo "<input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'>";
      echo "<div class='center'>";
      
      if ($id > 0) {
         echo "<input type='hidden' name='id' value='$id'>";
      }

      echo "<table class='tab_cadre_fixe'>";
      echo "<tr><th colspan='2'>" . ($id > 0 ? __("Edit Tenant", "ms365sync") . ": " . ($this->fields['name'] ?? '') : __("New Tenant", "ms365sync")) . "</th></tr>";
      
      // Configuración Básica
      echo "<tr class='headerrow'><td colspan='2'><b>" . __("Connection Settings", "ms365sync") . "</b></td></tr>";
      echo "<tr class='tab_bg_1'><td>".__("Descriptive Name", "ms365sync")."</td>";
      echo "<td><input type='text' name='name' value='" . ($this->fields['name'] ?? '') . "' class='form-control' required></td></tr>";
      
      echo "<tr class='tab_bg_1'><td>".__("Domain", "ms365sync")." (ej: miempresa.com)</td>";
      echo "<td><input type='text' name='domain' value='" . ($this->fields['domain'] ?? '') . "' class='form-control' required></td></tr>";

      echo "<tr class='tab_bg_1'><td>Tenant ID</td>";
      echo "<td><input type='text' name='tenant_id' value='" . ($this->fields['tenant_id'] ?? '') . "' class='form-control' required></td></tr>";

      echo "<tr class='tab_bg_1'><td>Client ID</td>";
      echo "<td><input type='text' name='client_id' value='" . ($this->fields['client_id'] ?? '') . "' class='form-control' required></td></tr>";

      echo "<tr class='tab_bg_1'><td>Client Secret</td>";
      echo "<td><input type='password' name='client_secret' value='' class='form-control' " . ($id > 0 ? "" : "required") . ">";
      if ($id > 0) echo "<small><i>" . __("Leave empty to keep current secret", "ms365sync") . "</i></small>";
      echo "</td></tr>";

      // --- NUEVA SECCIÓN: PREFIJOS Y TEAMS ---
      echo "<tr class='headerrow'><td colspan='2'><b>" . __("Default Sync Preferences", "ms365sync") . "</b></td></tr>";
      
      echo "<tr class='tab_bg_1'><td>" . __("Default Task Prefix", "ms365sync") . "</td>";
      echo "<td>" . Html::getCheckbox(['name' => 'use_prefix_tasks', 'id' => 'use_prefix_tasks', 'checked' => $this->fields['use_prefix_tasks'] ?? 1, 'value' => 1]) . " ";
      echo "<input type='text' id='prefix_tasks' name='prefix_tasks' value='" . ($this->fields['prefix_tasks'] ?? '') . "' class='form-control' style='width: 80%; display: inline-block;' placeholder='Tarea: ' ". (($this->fields['use_prefix_tasks'] ?? 1) ? "" : "disabled") .">";
      echo "<p class='text-muted'><small>" . __("If enabled, you can define a custom prefix. If disabled, no prefix will be added.", "ms365sync") . "</small></p></td></tr>";

      echo "<tr class='tab_bg_1'><td>" . __("Default Event Prefix", "ms365sync") . "</td>";
      echo "<td>" . Html::getCheckbox(['name' => 'use_prefix_events', 'id' => 'use_prefix_events', 'checked' => $this->fields['use_prefix_events'] ?? 1, 'value' => 1]) . " ";
      echo "<input type='text' id='prefix_events' name='prefix_events' value='" . ($this->fields['prefix_events'] ?? '') . "' class='form-control' style='width: 80%; display: inline-block;' placeholder='Evento: ' ". (($this->fields['use_prefix_events'] ?? 1) ? "" : "disabled") .">";
      echo "<p class='text-muted'><small>" . __("If enabled, you can define a custom prefix. If disabled, no prefix will be added.", "ms365sync") . "</small></p></td></tr>";

      echo "<tr class='tab_bg_1'><td>" . __("Default Teams Status Message", "ms365sync") . "</td>";
      echo "<td>" . Html::getCheckbox(['name' => 'use_teams_status_prefix', 'id' => 'use_teams_status_prefix', 'checked' => $this->fields['use_teams_status_prefix'] ?? 0, 'value' => 1]) . " ";
      echo "<input type='text' id='teams_status_msg' name='teams_status_msg' value='" . ($this->fields['teams_status_msg'] ?? '') . "' class='form-control' style='width: 80%; display: inline-block;' placeholder='Ocupado trabajando en: ' ". (($this->fields['use_teams_status_prefix'] ?? 0) ? "" : "disabled") .">";
      echo "<p class='text-muted'><small>" . __("If enabled, you can customize the message. If disabled, the default 'Working on:' will be used.", "ms365sync") . "</small></p></td></tr>";

      // Configuración de Sincronización
      echo "<tr class='headerrow'><td colspan='2'><b>" . __("Synchronization Range", "ms365sync") . "</b></td></tr>";
      echo "<tr class='tab_bg_1'><td>".__("Months in the PAST", "ms365sync")."</td>";
      echo "<td><input type='number' name='sync_months_past' value='" . ($this->fields['sync_months_past'] ?? 1) . "' class='form-control' min='0' max='12'>";
      echo "<p class='text-muted'><small>" . __("If set to 0, it will sync only the last 7 days.", "ms365sync") . "</small></p></td></tr>";

      echo "<tr class='tab_bg_1'><td>".__("Months in the FUTURE", "ms365sync")."</td>";
      echo "<td><input type='number' name='sync_months_future' value='" . ($this->fields['sync_months_future'] ?? 1) . "' class='form-control' min='0' max='36'>";
      echo "<p class='text-muted'><small>" . __("If set to 0, it will sync the next 7 days.", "ms365sync") . "</small></p></td></tr>";

      echo "<tr class='tab_bg_1'><td>".__("Active")."</td><td>";
      Dropdown::showYesNo('active', $this->fields['active'] ?? 1);
      echo "</td></tr>";

      echo "<tr><td colspan='2' class='center'>";
      if ($id > 0) {
         echo "<div class='btn-group'>";
         echo "<input type='submit' name='update' value='".__("Update")."' class='btn btn-primary'>";
         echo "<input type='submit' name='delete' value='".__("Delete")."' class='btn btn-danger' onclick='return confirm(\"".__("Are you sure?")."\");'>";
         echo "</div>";
      } else {
         echo "<input type='submit' name='add' value='".__("Save")."' class='btn btn-primary'>";
      }
      echo "</td></tr>";

      echo "</table></div>";
      echo "</form>";

      // Script para deshabilitar inputs según checkbox
      echo Html::scriptBlock("
         $(document).on('change', '#use_prefix_tasks, #use_prefix_events, #use_teams_status_prefix', function() {
            var targetId = $(this).attr('id').replace('use_', '');
            if (targetId === 'teams_status_prefix') targetId = 'teams_status_msg';
            $('#' + targetId).prop('disabled', !$(this).is(':checked'));
         });
      ");
   }

   public function prepareInputForAdd($input) {
      if (isset($input['client_secret']) && !empty($input['client_secret'])) {
         $key_manager = new GLPIKey();
         $input['client_secret'] = $key_manager->encrypt($input['client_secret']);
      }
      return $input;
   }

   public function prepareInputForUpdate($input) {
      // Gestión de Checkboxes (si no vienen en POST es porque están desactivados)
      $input['use_prefix_tasks'] = isset($input['use_prefix_tasks']) ? 1 : 0;
      $input['use_prefix_events'] = isset($input['use_prefix_events']) ? 1 : 0;
      $input['use_teams_status_prefix'] = isset($input['use_teams_status_prefix']) ? 1 : 0;

      // Gestión del secreto (Encriptación)
      if (isset($input['client_secret']) && !empty($input['client_secret'])) {
         $key_manager = new GLPIKey();
         $input['client_secret'] = $key_manager->encrypt($input['client_secret']);
      } else {
         // Si el campo viene vacío, no sobreescribimos el secreto anterior
         unset($input['client_secret']);
      }

      // Limpieza de espacios en blanco para evitar errores de conexión
      if (isset($input['client_id'])) $input['client_id'] = trim($input['client_id']);
      if (isset($input['tenant_id'])) $input['tenant_id'] = trim($input['tenant_id']);
      if (isset($input['domain']))    $input['domain']    = trim($input['domain']);

      return $input;
   }
}
