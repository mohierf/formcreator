<?php
/**
 * ---------------------------------------------------------------------
 * Formcreator is a plugin which allows creation of custom forms of
 * easy access.
 * ---------------------------------------------------------------------
 * LICENSE
 *
 * This file is part of Formcreator.
 *
 * Formcreator is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Formcreator is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Formcreator. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 * @author    Thierry Bugier
 * @author    Jérémy Moreau
 * @copyright Copyright © 2011 - 2018 Teclib'
 * @license   http://www.gnu.org/licenses/gpl.txt GPLv3+
 * @link      https://github.com/pluginsGLPI/formcreator/
 * @link      https://pluginsglpi.github.io/formcreator/
 * @link      http://plugins.glpi-project.org/#/plugin/formcreator
 * ---------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginFormcreatorInstall {
   protected $migration;

   /**
    * array of upgrade steps key => value
    * key   is the version to upgrade from
    * value is the version to upgrade to
    *
    * Exemple: an entry '2.0' => '2.1' tells that versions 2.0
    * are upgradable to 2.1
    *
    * When posible avoid schema upgrade between bugfix releases. The schema
    * version iscontains major.minor numbers only. If an upgrade of the schema
    * occurs between bugfix releases, then the upgrade will start from the
    * major.minor.0 version up to the end of the the versions list.
    * Exemple: if previous version is 2.6.1 and current code is 2.6.3 then
    * the upgrade will start from 2.6.0 to 2.6.3 and replay schema changes
    * between 2.6.0 and 2.6.1. This means that upgrade must be _repeatable_.
    *
    * @var array
    */
   private $upgradeSteps = [
      '0.0'    => '2.5',
      '2.5'    => '2.6',
      '2.6'    => '2.6.1',
      '2.6.1'  => '2.6.3',
      '2.6.3'  => '2.7',
   ];

   /**
    * Install the plugin
    * @param Migration $migration
    *
    * @return void
    */
   public function install(Migration $migration) {
      $this->migration = $migration;
      $this->installSchema();

      $this->configureExistingEntities();
      $this->createRequestType();
      $this->createDefaultDisplayPreferences();
      $this->createCronTasks();
      $this->createNotifications();
      Config::setConfigurationValues('formcreator', ['schema_version' => PLUGIN_FORMCREATOR_SCHEMA_VERSION]);

      $task = new CronTask();
      PluginFormcreatorIssue::cronSyncIssues($task);

      return true;
   }

   /**
    * Upgrade the plugin
    */
   public function upgrade(Migration $migration) {
      $this->migration = $migration;
      if (isset($_SESSION['plugin_formcreator']['cli']) && $_SESSION['plugin_formcreator']['cli'] == 'force-upgrade') {
         // Might return false
         $fromSchemaVersion = array_search(PLUGIN_FORMCREATOR_SCHEMA_VERSION, $this->upgradeSteps);
      } else {
         $fromSchemaVersion = $this->getSchemaVersion();
      }

      while ($fromSchemaVersion && isset($this->upgradeSteps[$fromSchemaVersion])) {
         $this->upgradeOneStep($this->upgradeSteps[$fromSchemaVersion]);
         $fromSchemaVersion = $this->upgradeSteps[$fromSchemaVersion];
      }

      $this->migration->executeMigration();
      // if the schema contains new tables
      $this->installSchema();
      $this->configureExistingEntities();
      $this->createRequestType();
      $this->createDefaultDisplayPreferences();
      $this->createCronTasks();
      Config::setConfigurationValues('formcreator', ['schema_version' => PLUGIN_FORMCREATOR_SCHEMA_VERSION]);

      return true;
   }

   /**
    * Proceed to upgrade of the plugin to the given version
    *
    * @param string $toVersion
    */
   protected function upgradeOneStep($toVersion) {
      ini_set("max_execution_time", "0");
      ini_set("memory_limit", "-1");

      $suffix = str_replace('.', '_', $toVersion);
      $includeFile = __DIR__ . "/upgrade_to_$toVersion.php";
      if (is_readable($includeFile) && is_file($includeFile)) {
         include_once $includeFile;
         $updateClass = "PluginFormcreatorUpgradeTo$suffix";
         $this->migration->addNewMessageArea("Upgrade to $toVersion");
         $upgradeStep = new $updateClass();
         $upgradeStep->upgrade($this->migration);
         $this->migration->executeMigration();
         $this->migration->displayMessage('Done');
      }
   }

   /**
    * Find the version of the plugin
    *
    * @return string|NULL
    */
   protected function getSchemaVersion() {
      if ($this->isPluginInstalled()) {
         return $this->getSchemaVersionFromGlpiConfig();
      }

      return null;
   }

   /**
    * Find version of the plugin in GLPI's config
    *
    * @return string
    */
   protected function getSchemaVersionFromGlpiConfig() {
      global $DB;

      $config = Config::getConfigurationValues('formcreator', ['schema_version']);
      if (!isset($config['schema_version'])) {
         // No schema version in GLPI config, then this is older than 2.5
         if ($DB->tableExists('glpi_plugin_formcreator_items_targettickets')) {
            // Workaround bug #794 where schema version was not saved
            return '2.6';
         }
         return '0.0';
      }

      // Version found in GLPI config
      return $config['schema_version'];
   }

   /**
    * is the plugin already installed ?
    *
    * @return boolean
    */
   public function isPluginInstalled() {
      global $DB;

      $result = $DB->query("SHOW TABLES LIKE 'glpi_plugin_formcreator_%'");
      if ($result) {
         if ($DB->numrows($result) > 0) {
            return true;
         }
      }

      return false;
   }

   protected function installSchema() {
      global $DB;

      $this->migration->displayMessage("create database schema");

      $dbFile = __DIR__ . '/mysql/plugin_formcreator_empty.sql';
      if (!$DB->runFile($dbFile)) {
         $this->migration->displayWarning("Error creating tables : " . $DB->error(), true);
         die('Giving up');
      }
   }

   protected function configureExistingEntities() {
      global $DB;

      $this->migration->displayMessage("Configure existing entities");

      $query = "SELECT `id` FROM `glpi_entities`
                WHERE `id` NOT IN (
                   SELECT `id` FROM `glpi_plugin_formcreator_entityconfigs`
                )";
      $result = $DB->query($query);
      if (!$result) {
         Toolbox::logInFile('sql-errors', $DB->error());
         die ($DB->error());
      }
      while ($row = $DB->fetch_assoc($result)) {
         $entityConfig = new PluginFormcreatorEntityconfig();
         $entityConfig->add([
               'id'                 => $row['id'],
               'replace_helpdesk'   => ($row['id'] == 0) ? 0 : PluginFormcreatorEntityconfig::CONFIG_PARENT
         ]);
      }
   }

   protected function createRequestType() {
      global $DB;

      $this->migration->displayMessage("create request type");

      $query  = "SELECT id FROM `glpi_requesttypes` WHERE `name` LIKE 'Formcreator';";
      $result = $DB->query($query) or die ($DB->error());

      if (!$DB->numrows($result) > 0) {
         $query = "INSERT INTO `glpi_requesttypes` SET `name` = 'Formcreator';";
         $DB->query($query) or die ($DB->error());
         $DB->insert_id();
      }
   }

   protected function createDefaultDisplayPreferences() {
      global $DB;
      $this->migration->displayMessage("create default display preferences");

      // Create standard display preferences
      $displayprefs = new DisplayPreference();
      $found_dprefs = $displayprefs->find("`itemtype` = 'PluginFormcreatorFormAnswer'");
      if (count($found_dprefs) == 0) {
         $query = "INSERT IGNORE INTO `glpi_displaypreferences`
                   (`id`, `itemtype`, `num`, `rank`, `users_id`) VALUES
                   (NULL, 'PluginFormcreatorFormAnswer', 2, 2, 0),
                   (NULL, 'PluginFormcreatorFormAnswer', 3, 3, 0),
                   (NULL, 'PluginFormcreatorFormAnswer', 4, 4, 0),
                   (NULL, 'PluginFormcreatorFormAnswer', 5, 5, 0),
                   (NULL, 'PluginFormcreatorFormAnswer', 6, 6, 0)";
         $DB->query($query) or die ($DB->error());
      }

      $displayprefs = new DisplayPreference;
      $found_dprefs = $displayprefs->find("`itemtype` = 'PluginFormcreatorForm'");
      if (count($found_dprefs) == 0) {
         $query = "INSERT IGNORE INTO `glpi_displaypreferences`
                   (`id`, `itemtype`, `num`, `rank`, `users_id`) VALUES
                   (NULL, 'PluginFormcreatorForm', 30, 1, 0),
                   (NULL, 'PluginFormcreatorForm', 3, 2, 0),
                   (NULL, 'PluginFormcreatorForm', 10, 3, 0),
                   (NULL, 'PluginFormcreatorForm', 7, 4, 0),
                   (NULL, 'PluginFormcreatorForm', 8, 5, 0),
                   (NULL, 'PluginFormcreatorForm', 9, 6, 0);";
         $DB->query($query) or die ($DB->error());
      }

      $displayprefs = new DisplayPreference;
      $found_dprefs = $displayprefs->find("`itemtype` = 'PluginFormcreatorIssue'");
      if (count($found_dprefs) == 0) {
         $query = "INSERT IGNORE INTO `glpi_displaypreferences`
                   (`id`, `itemtype`, `num`, `rank`, `users_id`) VALUES
                   (NULL, 'PluginFormcreatorIssue', 1, 1, 0),
                   (NULL, 'PluginFormcreatorIssue', 2, 2, 0),
                   (NULL, 'PluginFormcreatorIssue', 4, 3, 0),
                   (NULL, 'PluginFormcreatorIssue', 5, 4, 0),
                   (NULL, 'PluginFormcreatorIssue', 6, 5, 0),
                   (NULL, 'PluginFormcreatorIssue', 7, 6, 0),
                   (NULL, 'PluginFormcreatorIssue', 8, 7, 0)";
         $DB->query($query) or die ($DB->error());
      }
   }

   /**
    * Declares the notifications that the plugin handles
    */
   protected function createNotifications() {
      $this->migration->displayMessage("create notifications");

      $notifications = [
            'plugin_formcreator_form_created' => [
               'name'     => __('A form has been created', 'formcreator'),
               'subject'  => __('Your request has been saved', 'formcreator'),
               'content'  => __('Hi,\nYour request from GLPI has been successfully saved with number ##formcreator.request_id## and transmitted to the helpdesk team.\nYou can see your answers onto the following link:\n##formcreator.validation_link##', 'formcreator'),
               'notified' => PluginFormcreatorNotificationTargetFormAnswer::AUTHOR,
            ],
            'plugin_formcreator_need_validation' => [
               'name'     => __('A form need to be validate', 'formcreator'),
               'subject'  => __('A form from GLPI need to be validate', 'formcreator'),
               'content'  => __('Hi,\nA form from GLPI need to be validate and you have been choosen as the validator.\nYou can access it by clicking onto this link:\n##formcreator.validation_link##', 'formcreator'),
               'notified' => PluginFormcreatorNotificationTargetFormAnswer::APPROVER,
            ],
            'plugin_formcreator_refused'         => [
               'name'     => __('The form is refused', 'formcreator'),
               'subject'  => __('Your form has been refused by the validator', 'formcreator'),
               'content'  => __('Hi,\nWe are sorry to inform you that your form has been refused by the validator for the reason below:\n##formcreator.validation_comment##\n\nYou can still modify and resubmit it by clicking onto this link:\n##formcreator.validation_link##', 'formcreator'),
               'notified' => PluginFormcreatorNotificationTargetFormAnswer::AUTHOR,
            ],
            'plugin_formcreator_accepted'        => [
               'name'     => __('The form is accepted', 'formcreator'),
               'subject'  => __('Your form has been accepted by the validator', 'formcreator'),
               'content'  => __('Hi,\nWe are pleased to inform you that your form has been accepted by the validator.\nYour request will be considered soon.', 'formcreator'),
               'notified' => PluginFormcreatorNotificationTargetFormAnswer::AUTHOR,
            ],
            'plugin_formcreator_deleted'         => [
               'name'     => __('The form is deleted', 'formcreator'),
               'subject'  => __('Your form has been deleted by an administrator', 'formcreator'),
               'content'  => __('Hi,\nWe are sorry to inform you that your request cannot be considered and has been deleted by an administrator.', 'formcreator'),
               'notified' => PluginFormcreatorNotificationTargetFormAnswer::AUTHOR,
            ],
      ];

      // Create the notification template
      $notification                       = new Notification();
      $template                           = new NotificationTemplate();
      $translation                        = new NotificationTemplateTranslation();
      $notification_target                = new NotificationTarget();
      $notification_notificationTemplate  = new Notification_NotificationTemplate();

      foreach ($notifications as $event => $data) {
         // Check if notification already exists
         $exists = $notification->find("itemtype = 'PluginFormcreatorFormAnswer' AND event = '$event'");

         // If it doesn't exists, create it
         if (count($exists) == 0) {
            $template_id = $template->add([
               'name'     => Toolbox::addslashes_deep($data['name']),
               'comment'  => '',
               'itemtype' => PluginFormcreatorFormAnswer::class,
            ]);

            // Add a default translation for the template
            $translation->add([
               'notificationtemplates_id' => $template_id,
               'language'                 => '',
               'subject'                  => Toolbox::addslashes_deep($data['subject']),
               'content_text'             => Toolbox::addslashes_deep($data['content']),
               'content_html'             => '<p>'.str_replace('\n', '<br />', Toolbox::addslashes_deep($data['content'])).'</p>',
            ]);

            // Create the notification
            $notification_id = $notification->add([
               'name'                     => Toolbox::addslashes_deep($data['name']),
               'comment'                  => '',
               'entities_id'              => 0,
               'is_recursive'             => 1,
               'is_active'                => 1,
               'itemtype'                 => PluginFormcreatorFormAnswer::class,
               'notificationtemplates_id' => $template_id,
               'event'                    => $event,
               'mode'                     => 'mail',
            ]);

            $notification_notificationTemplate->add([
               'notifications_id'         => $notification_id,
               'notificationtemplates_id' => $template_id,
               'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
            ]);

            // Add default notification targets
            $notification_target->add([
               "items_id"         => $data['notified'],
               "type"             => Notification::USER_TYPE,
               "notifications_id" => $notification_id,
            ]);
         }
      }
   }

   protected function deleteNotifications() {
      // Delete translations
      $translation = new NotificationTemplateTranslation();
      $translation->deleteByCriteria([
         'INNER JOIN' => [
            NotificationTemplate::getTable() => [
               'FKEY' => [
                  NotificationTemplateTranslation::getTable() => NotificationTemplate::getForeignKeyField(),
                  NotificationTemplate::getTable() => NotificationTemplate::getIndexName()
               ]
            ]
         ],
         'WHERE' => [
            NotificationTemplate::getTable() . '.itemtype' => PluginFormcreatorFormAnswer::class
         ]
      ]);

      // Delete notification templates
      $template = new NotificationTemplate();
      $template->deleteByCriteria(['itemtype' => PluginFormcreatorFormAnswer::class]);

      // Delete notification targets
      $target = new NotificationTarget();
      $target->deleteByCriteria([
         'INNER JOIN' => [
            Notification::getTable() => [
               'FKEY' => [
                  NotificationTarget::getTable() => Notification::getForeignKeyField(),
                  Notification::getTable() => Notification::getIndexName()
               ]
            ]
         ],
         'WHERE' => [
            Notification::getTable() . '.itemtype' => PluginFormcreatorFormAnswer::class
         ],
      ]);

      // Delete notifications and their templates
      $notification = new Notification();
      $notification_notificationTemplate = new Notification_NotificationTemplate();
      $rows = $notification->find("`itemtype` = 'PluginFormcreatorFormAnswer'");
      foreach ($rows as $row) {
         $notification_notificationTemplate->deleteByCriteria(['notifications_id' => $row['id']]);
         $notification->delete($row);
      }

      $notification = new Notification();
      $notification->deleteByCriteria(['itemtype' => PluginFormcreatorFormAnswer::class]);
   }

   protected function deleteTicketRelation() {
      global $CFG_GLPI;

      // Delete relations with tickets with email notifications disabled
      $use_mailing = PluginFormcreatorCommon::isNotificationEnabled();
      PluginFormcreatorCommon::setNotification(false);

      $item_ticket = new Item_Ticket();
      $item_ticket->deleteByCriteria(['itemtype' => PluginFormcreatorFormAnswer::class]);

      PluginFormcreatorCommon::setNotification($use_mailing);
   }

   /**
    * Cleanups the database from plugin's itemtypes (tables and relations)
    */
   protected function deleteTables() {
      global $DB;

      // Keep  these itemtypes as string because classes might not be available (if plugin is inactive)
      $itemtypes = [
         'PluginFormcreatorAnswer',
         'PluginFormcreatorCategory',
         'PluginFormcreatorEntityconfig',
         'PluginFormcreatorFormAnswer',
         'PluginFormcreatorForm_Profile',
         'PluginFormcreatorForm_Validator',
         'PluginFormcreatorForm',
         'PluginFormcreatorQuestion_Condition',
         'PluginFormcreatorQuestion',
         'PluginFormcreatorSection',
         'PluginFormcreatorTarget',
         'PluginFormcreatorTargetChange_Actor',
         'PluginFormcreatorTargetChange',
         'PluginFormcreatorTargetTicket_Actor',
         'PluginFormcreatorTargetTicket',
         'PluginFormcreatorItem_TargetTicket',
         'PluginFormcreatorIssue',
         'PluginFormcreatorQuestionDependency',
         'PluginFormcreatorQuestionRange',
         'PluginFormcreatorQuestionRegex',
      ];

      foreach ($itemtypes as $itemtype) {
         $table = getTableForItemType($itemtype);
         $log = new Log();
         $log->deleteByCriteria(['itemtype' => $itemtype]);

         $displayPreference = new DisplayPreference();
         $displayPreference->deleteByCriteria(['itemtype' => $itemtype]);

         $DB->query("DROP TABLE IF EXISTS `$table`");
      }

      // Drop views
      $DB->query('DROP VIEW IF EXISTS `glpi_plugin_formcreator_issues`');

      $displayPreference = new DisplayPreference();
      $displayPreference->deleteByCriteria(['itemtype' => PluginFormCreatorIssue::class]);
   }

   /**
    * http://stackoverflow.com/questions/834303/startswith-and-endswith-functions-in-php
    * @param string $haystack
    * @param string $needle
    */
   protected function endsWith($haystack, $needle) {
      // search foreward starting from end minus needle length characters
      return $needle === '' || (($temp = strlen($haystack) - strlen($needle)) >= 0 && strpos($haystack, $needle, $temp) !== false);
   }

   /**
    *
    */
   public function uninstall() {
      $this->deleteTicketRelation();
      $this->deleteTables();
      $this->deleteNotifications();

      $config = new Config();
      $config->deleteByCriteria(['context' => 'formcreator']);
   }

   /**
    * Create cron tasks
    */
   protected function createCronTasks() {
      CronTask::Register(PluginFormcreatorIssue::class, 'SyncIssues', HOUR_TIMESTAMP,
         [
            'comment'   => __('Formcreator - Sync service catalog issues', 'formcreator'),
            'mode'      => CronTask::MODE_EXTERNAL
         ]
      );
   }
}
