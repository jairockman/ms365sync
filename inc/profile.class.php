<?php

class PluginMs365syncProfile extends CommonDBTM {

   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      if ($item->getType() == 'Profile') {
         return __('MS365 Sync', 'ms365sync');
      }
      return '';
   }

   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      if ($item->getType() == 'Profile') {
         $ID = $item->getID();
         $prof = new self();
         $prof->showForm($ID);
      }
      return true;
   }

   function showForm($ID, array $options = []) {
      global $DB;

      $rights = ProfileRight::getProfileRights($ID, ['plugin_ms365sync_tenant']);
      $right  = $rights['plugin_ms365sync_tenant'] ?? 0;

      echo "<div class='spaced'>";
      echo "<table class='tab_cadre_fixe'>";
      echo "<tr class='headerrow'><th colspan='2'>" . __('MS365 Sync Permissions', 'ms365sync') . "</th></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>" . __('Tenant Management', 'ms365sync') . "</td>";
      echo "<td>";
      
      // Selector estándar de GLPI con etiquetas amigables
      Profile::dropdownRight([
         'name'   => 'plugin_ms365sync_tenant',
         'value'  => $right,
         'rights' => [
            READ   => __('Read'),
            UPDATE => __('Update'),
            CREATE => __('Create'),
            DELETE => __('Delete'),
         ]
      ]);
      echo "</td></tr>";

      echo "<tr><td colspan='2' class='center'>";
      echo "<input type='submit' name='update' value=\"" . _sx('button', 'Save') . "\" class='btn btn-primary'>";
      echo "</td></tr>";
      echo "</table></div>";
   }

   static function getAllRights() {
      return [
         [
            'rights'    => ['plugin_ms365sync_tenant' => READ | UPDATE | CREATE | DELETE],
            'label'     => __('MS365 Sync', 'ms365sync'),
            'field'     => 'plugin_ms365sync_tenant',
            'class'     => 'PluginMs365syncTenants'
         ]
      ];
   }
}