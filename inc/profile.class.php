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
      
      $matrix_rights = [
         READ   => __('Read'),
         UPDATE => __('Update'),
         CREATE => __('Create'),
         DELETE => __('Delete'),
         PURGE  => __('Purge')
      ];

      $profile = new Profile();
      $profile->displayRightsChoiceMatrix($matrix_rights, [
         'plugin_ms365sync_tenant' => $right
      ], [
         'title' => __('MS365 Sync Permissions', 'ms365sync'),
         'row_labels' => [
            'plugin_ms365sync_tenant' => __('Tenant Management', 'ms365sync')
         ]
      ]);

      echo "<div class='center mt-2'>";
      echo "<input type='submit' name='update' value=\"" . _sx('button', 'Save') . "\" class='btn btn-primary'>";
      echo "</div>";
      echo "</div>";
   }

   static function getAllRights() {
      return [
         [
            'rights'    => ['plugin_ms365sync_tenant' => READ | UPDATE | CREATE | DELETE | PURGE],
            'label'     => __('MS365 Sync', 'ms365sync'),
            'field'     => 'plugin_ms365sync_tenant',
            'class'     => 'PluginMs365syncTenants'
         ]
      ];
   }
}