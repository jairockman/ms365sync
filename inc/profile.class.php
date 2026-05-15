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
      $profile = new Profile();
      $profile->getFromDB($ID);

      $canedit = Session::haveRight('profile', UPDATE);
      $rights  = $this->getAllRights();

      echo "<div class='firstbloc'>";
      if ($canedit) {
         echo "<form method='post' action='" . $profile->getFormURL() . "'>";
      }

      $profile->displayRightsChoiceMatrix($rights, [
         'canedit' => $canedit,
         'title'   => __('MS365 Sync Permissions', 'ms365sync')
      ]);

      if ($canedit) {
         echo "<div class='center mt-2'>";
         echo Html::hidden('id', ['value' => $ID]);
         echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
         echo "</div>";
         Html::closeForm();
      }
      echo "</div>";
   }

   static function getAllRights($all = false) {
      $rights = [
         [
            'itemtype' => 'PluginMs365syncTenants',
            'label'    => __('Tenant Management', 'ms365sync'),
            'field'    => 'plugin_ms365sync_tenant',
            'rights'   => [
               READ   => __('Read'),
               UPDATE => __('Update'),
               CREATE => __('Create'),
               DELETE => __('Delete'),
               PURGE  => __('Purge')
            ]
         ]
      ];
      return $rights;
   }
}
