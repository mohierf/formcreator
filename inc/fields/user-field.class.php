<?php
require_once(realpath(dirname(__FILE__ ) . '/../../../../inc/includes.php'));
require_once('glpiselect-field.class.php');

class userField extends glpiselectField
{
   public static function show($field, $datas, $edit = true)
   {
      parent::show($field, $datas, $edit = true);
   }
   
   public static function getDefaultValue($field)
   {
      $default_values = explode("\r\n", $field['default_values']);
      $default_value  = array_shift($default_values);

      if (!empty($datas['formcreator_field_' . $field['id']])) {
         $default_value = $datas['formcreator_field_' . $field['id']];
      } elseif (-1 == $default_value) {
         $default_value = $_SESSION['glpiID'];
      }
      return $default_value;
   }

   public static function getName()
   {
      return _n('User', 'Users', 1);
   }

   public static function getPrefs()
   {
      return array(
         'required'       => 1,
         'default_values' => 0,
         'values'         => 0,
         'range'          => 0,
         'show_empty'     => 1,
         'regex'          => 0,
         'show_type'      => 1,
         'dropdown_value' => 0,
         'glpi_objects'   => 1,
         'ldap_values'    => 0,
      );
   }

   public static function getJSFields()
   {
      $prefs = self::getPrefs();
      return "tab_fields_fields['user'] = 'showFields(" . implode(', ', $prefs) . ");';";
   }
}
