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

class PluginFormcreatorIssue extends CommonDBTM {
   static $rightname = 'ticket';

   public static function getTypeName($nb = 0) {
      return _n('Issue', 'Issues', $nb, 'formcreator');
   }

   /**
    * get Cron description parameter for this class
    *
    * @param $name string name of the task
    *
    * @return array of string
    */
   static function cronInfo($name) {
      switch ($name) {
         case 'SyncIssues':
            return ['description' => __('Update issue data from tickets and form answers', 'formcreator')];
      }
   }

   /**
    *
    * @param CronTask $task
    *
    * @return number
    */
   public static function cronSyncIssues(CronTask $task) {
      global $DB;

      $task->log("Sync issues from forms answers and tickets");
      $volume = 0;

      // Request which merges tickets and formanswers
      // 1 ticket not linked to a form_answer => 1 issue which is the ticket sub_itemtype
      // 1 form_answer not linked to a ticket => 1 issue which is the form_answer sub_itemtype
      // 1 ticket linked to 1 form_answer => 1 issue which is the ticket sub_itemtype
      // several tickets linked to the same form_answer => 1 issue which is the form_answer sub_itemtype
      $query = "SELECT DISTINCT
                  NULL                           AS `id`,
                  CONCAT('f_',`fanswer`.`id`)    AS `display_id`,
                  `fanswer`.`id`                 AS `original_id`,
                  'PluginFormcreatorForm_Answer' AS `sub_itemtype`,
                  `f`.`name`                     AS `name`,
                  `fanswer`.`status`             AS `status`,
                  `fanswer`.`request_date`       AS `date_creation`,
                  `fanswer`.`request_date`       AS `date_mod`,
                  `fanswer`.`entities_id`        AS `entities_id`,
                  `fanswer`.`is_recursive`       AS `is_recursive`,
                  `fanswer`.`requester_id`       AS `requester_id`,
                  `fanswer`.`users_id_validator` AS `validator_id`,
                  `fanswer`.`comment`            AS `comment`
               FROM `glpi_plugin_formcreator_forms_answers` AS `fanswer`
               LEFT JOIN `glpi_plugin_formcreator_forms` AS `f`
                  ON`f`.`id` = `fanswer`.`plugin_formcreator_forms_id`
               LEFT JOIN `glpi_items_tickets` AS `itic`
                  ON `itic`.`items_id` = `fanswer`.`id`
                  AND `itic`.`itemtype` = 'PluginFormcreatorForm_Answer'
               WHERE `fanswer`.`is_deleted` = '0'
               GROUP BY `original_id`
               HAVING COUNT(`itic`.`tickets_id`) != 1

               UNION

               SELECT DISTINCT
                  NULL                          AS `id`,
                  CONCAT('t_',`tic`.`id`)       AS `display_id`,
                  `tic`.`id`                    AS `original_id`,
                  'Ticket'                      AS `sub_itemtype`,
                  `tic`.`name`                  AS `name`,
                  `tic`.`status`                AS `status`,
                  `tic`.`date`                  AS `date_creation`,
                  `tic`.`date_mod`              AS `date_mod`,
                  `tic`.`entities_id`           AS `entities_id`,
                  0                             AS `is_recursive`,
                  `tic`.`users_id_recipient`    AS `requester_id`,
                  0                             AS `validator_id`,
                  `tic`.`content`               AS `comment`
               FROM `glpi_tickets` AS `tic`
               LEFT JOIN `glpi_items_tickets` AS `itic`
                  ON `itic`.`tickets_id` = `tic`.`id`
                  AND `itic`.`itemtype` = 'PluginFormcreatorForm_Answer'
               WHERE `tic`.`is_deleted` = 0
               GROUP BY `original_id`
               HAVING COUNT(`itic`.`items_id`) <= 1";

      $countQuery = "SELECT COUNT(*) AS `cpt` FROM ($query) AS `issues`";
      $result = $DB->query($countQuery);
      if ($result !== false) {
         $count = $DB->fetch_assoc($result);
         $table = static::getTable();
         if (countElementsInTable($table) != $count['cpt']) {
            if ($DB->query("TRUNCATE `$table`")) {
               $DB->query("INSERT INTO `$table` SELECT * FROM ($query) as `dt`");
               $volume = 1;
            }
         }
      }
      $task->setVolume($volume);

      return 1;
   }

   public static function hook_update_ticket(CommonDBTM $item) {

   }

   /**
    * @see CommonGLPI::display()
    */
   public function display($options = []) {
      global $CFG_GLPI;

      $itemtype = $options['sub_itemtype'];
      if (!in_array($itemtype, ['Ticket', 'PluginFormcreatorForm_Answer'])) {
         html::displayRightError();
      }
      if (version_compare(PluginFormcreatorCommon::getGlpiVersion(), 9.4) >= 0 || $CFG_GLPI['use_rich_text']) {
         Html::requireJs('tinymce');
      }
      if (plugin_formcreator_replaceHelpdesk() == PluginFormcreatorEntityconfig::CONFIG_SIMPLIFIED_SERVICE_CATALOG) {
         $this->displaySimplified($options);
      } else {
         $this->displayExtended($options);
      }
   }

   public function displayExtended($options = []) {
      $item = new $options['sub_itemtype'];

      if (isset($options['id'])
            && !$item->isNewID($options['id'])) {
         if (!$item->getFromDB($options['id'])) {
            Html::displayNotFoundError();
         }
      }

      // if ticket(s) exist(s), show it/them
      $options['_item'] = $item;
      if ($item Instanceof PluginFormcreatorForm_Answer) {
         $item = $this->getTicketsForDisplay($options);
      }

      $item->showTabsContent();

   }


   /**
    * @since 0.90
    *
    * @param $rand
    **/
   function showTimeline($the_ticket, $rand) {
      global $CFG_GLPI, $DB, $autolink_options;

      $user              = new User();
      $group             = new Group();
      $followup_obj      = new TicketFollowup();
      $pics_url          = $CFG_GLPI['root_doc']."/pics/timeline";
      $timeline          = $the_ticket->getTimelineItems();

      $autolink_options['strip_protocols'] = false;

      //display timeline
      echo "<div class='timeline_history'>";

//      // show approbation form on top when ticket is solved
//      if ($the_ticket->fields["status"] == CommonITILObject::SOLVED) {
//         echo "<div class='approbation_form' id='approbation_form$rand'>";
//         $followup_obj->showApprobationForm($the_ticket);
//         echo "</div>";
//      }

      // show title for timeline
//      self::showTimelineHeader();

      $timeline_index = 0;
      foreach ($timeline as $item) {
         $options = [ 'parent' => $the_ticket,
            'rand' => $rand
         ];
         if ($obj = getItemForItemtype($item['type'])) {
            $obj->fields = $item['item'];
         } else {
            $obj = $item;
         }
         Plugin::doHook('pre_show_item', ['item' => $obj, 'options' => &$options]);

         if (is_array($obj)) {
            $item_i = $obj['item'];
         } else {
            $item_i = $obj->fields;
         }

         $date = "";
         if (isset($item_i['date'])) {
            $date = $item_i['date'];
         } else if (isset($item_i['date_mod'])) {
            $date = $item_i['date_mod'];
         }

         // set item position depending on field timeline_position
         $user_position = 'left'; // default position
         if (isset($item_i['timeline_position'])) {
            switch ($item_i['timeline_position']) {
               case Ticket::TIMELINE_LEFT:
                  $user_position = 'left';
                  break;
               case Ticket::TIMELINE_MIDLEFT:
                  $user_position = 'left middle';
                  break;
               case Ticket::TIMELINE_MIDRIGHT:
                  $user_position = 'right middle';
                  break;
               case Ticket::TIMELINE_RIGHT:
                  $user_position = 'right';
                  break;
            }
         }

         //display solution in middle
         if (($item['type'] == "Solution") && $item_i['status'] != CommonITILValidation::REFUSED
            && in_array($the_ticket->fields["status"], [CommonITILObject::SOLVED, CommonITILObject::CLOSED])) {
            $user_position.= ' middle';
         }

         echo "<div class='h_item $user_position'>";

         echo "<div class='h_info'>";

         echo "<div class='h_date'><i class='fa fa-clock-o'></i>".Html::convDateTime($date)."</div>";
         if ($item_i['users_id'] !== false) {
            echo "<div class='h_user'>";
//            if (isset($item_i['users_id']) && ($item_i['users_id'] != 0)) {
//               $user->getFromDB($item_i['users_id']);
//
//               echo "<div class='tooltip_picture_border'>";
//               echo "<img class='user_picture' alt=\"".__s('Picture')."\" src='".
//                      User::getThumbnailURLForPicture($user->fields['picture'])."'>";
//               echo "</div>";

//               echo "<span class='h_user_name'>";
//               $userdata = getUserName($item_i['users_id'], 2);
//               echo $user->getLink()."&nbsp;";
//               echo Html::showToolTip($userdata["comment"],
//                                      ['link' => $userdata['link']]);
//               echo "</span>";
//            } else {
//               echo __("Requester");
//            }
            echo __("Requester");
            echo "</div>"; // h_user
         }

         echo "</div>"; //h_info

         $domid = "viewitem{$item['type']}{$item_i['id']}";
         if ($item['type'] == 'TicketValidation' && isset($item_i['status'])) {
            $domid .= $item_i['status'];
         }
         $domid .= $rand;

         $fa = null;
         $class = "h_content {$item['type']}";
         if ($item['type'] == 'Solution') {
            switch ($item_i['status']) {
               case CommonITILValidation::WAITING:
                  $fa = 'question';
                  $class .= ' waiting';
                  break;
               case CommonITILValidation::ACCEPTED:
                  $fa = 'thumbs-up';
                  $class .= ' accepted';
                  break;
               case CommonITILValidation::REFUSED:
                  $fa = 'thumbs-down';
                  $class .= ' refused';
                  break;
            }
         } else if (isset($item_i['status'])) {
            $class .= " {$item_i['status']}";
         }

         echo "<div class='$class' id='$domid'>";
         if ($fa !== null) {
            echo "<i class='solimg fa fa-$fa fa-5x'></i>";
         }
         if (isset($item_i['can_edit']) && $item_i['can_edit']) {
            echo "<div class='edit_item_content'></div>";
            echo "<span class='cancel_edit_item_content'></span>";
         }
         echo "<div class='displayed_content'>";
         if (!in_array($item['type'], ['Document_Item', 'Assign'])
            && $item_i['can_edit']) {
            echo "<span class='fa fa-pencil-square-o edit_item' ";
            echo "onclick='javascript:viewEditSubitem".$the_ticket->fields['id']."$rand(event, \"".$item['type']."\", ".$item_i['id'].", this, \"$domid\")'";
            echo "></span>";
         }
         if (isset($item_i['requesttypes_id'])
            && file_exists("$pics_url/".$item_i['requesttypes_id'].".png")) {
            echo "<img src='$pics_url/".$item_i['requesttypes_id'].".png' class='h_requesttype' />";
         }

         if (isset($item_i['content'])) {
            $content = $item_i['content'];
            $content = Toolbox::getHtmlToDisplay($content);
            $content = autolink($content, false);

            $long_text = "";
            if ((substr_count($content, "<br") > 30) || (strlen($content) > 2000)) {
               $long_text = "long_text";
            }

            echo "<div class='item_content $long_text'>";
            echo "<p>";
            if (isset($item_i['state'])) {
               $onClick = "onclick='change_task_state(".$item_i['id'].", this)'";
               if (!$item_i['can_edit']) {
                  $onClick = "style='cursor: not-allowed;'";
               }
               echo "<span class='state state_".$item_i['state']."'
                           $onClick
                           title='".Planning::getState($item_i['state'])."'>";
               echo "</span>";
            }
            echo "</p>";

            if ($CFG_GLPI["use_rich_text"]) {
               echo "<div class='rich_text_container'>";
               echo html_entity_decode($content);
               echo "</div>";
            } else {
               echo "<p>$content</p>";
            }

            if (!empty($long_text)) {
               echo "<p class='read_more'>";
               echo "<a class='read_more_button'>.....</a>";
               echo "</p>";
            }
            echo "</div>";
         }

         echo "<div class='b_right'>";
         if (isset($item_i['solutiontypes_id']) && !empty($item_i['solutiontypes_id'])) {
            echo Dropdown::getDropdownName("glpi_solutiontypes", $item_i['solutiontypes_id'])."<br>";
         }
         if (isset($item_i['taskcategories_id']) && !empty($item_i['taskcategories_id'])) {
            echo Dropdown::getDropdownName("glpi_taskcategories", $item_i['taskcategories_id'])."<br>";
         }
         if (isset($item_i['requesttypes_id']) && !empty($item_i['requesttypes_id'])) {
            echo Dropdown::getDropdownName("glpi_requesttypes", $item_i['requesttypes_id'])."<br>";
         }

         if (isset($item_i['actiontime']) && !empty($item_i['actiontime'])) {
            echo "<span class='actiontime'>";
            echo Html::timestampToString($item_i['actiontime'], false);
            echo "</span>";
         }
         if (isset($item_i['begin'])) {
            echo "<span class='planification'>";
            echo Html::convDateTime($item_i["begin"]);
            echo " &rArr; ";
            echo Html::convDateTime($item_i["end"]);
            echo "</span>";
         }
//         if (isset($item_i['users_id_tech']) && ($item_i['users_id_tech'] > 0)) {
//            echo "<div class='users_id_tech' id='users_id_tech_".$item_i['users_id_tech']."'>";
//            $user->getFromDB($item_i['users_id_tech']);
//            echo Html::image($CFG_GLPI['root_doc']."/pics/user.png")."&nbsp;";
//            $userdata = getUserName($item_i['users_id_tech'], 2);
//            echo $user->getLink()."&nbsp;";
//            echo Html::showToolTip($userdata["comment"],
//                                   ['link' => $userdata['link']]);
//            echo "</div>";
//         }
//         if (isset($item_i['groups_id_tech']) && ($item_i['groups_id_tech'] > 0)) {
//            echo "<div class='groups_id_tech'>";
//            $group->getFromDB($item_i['groups_id_tech']);
//            echo Html::image($CFG_GLPI['root_doc']."/pics/group.png")."&nbsp;";
//            echo $group->getLink()."&nbsp;";
//            echo Html::showToolTip($group->getComments(),
//                                   ['link' => $group->getLinkURL()]);
//            echo "</div>";
//         }
//         if (isset($item_i['users_id_editor']) && $item_i['users_id_editor'] > 0) {
//            echo "<div class='users_id_editor' id='users_id_editor_".$item_i['users_id_editor']."'>";
//            $user->getFromDB($item_i['users_id_editor']);
//            $userdata = getUserName($item_i['users_id_editor'], 2);
//            echo sprintf(
//               __('Last edited on %1$s by %2$s'),
//               Html::convDateTime($item_i['date_mod']),
//               $user->getLink()
//            );
//            echo Html::showToolTip($userdata["comment"],
//                                   ['link' => $userdata['link']]);
//            echo "</div>";
//         }
//         if ($item['type'] == 'Solution' && $item_i['status'] != CommonITILValidation::WAITING && $item_i['status'] != CommonITILValidation::NONE) {
//            echo "<div class='users_id_approval' id='users_id_approval_".$item_i['users_id_approval']."'>";
//            $user->getFromDB($item_i['users_id_approval']);
//            $userdata = getUserName($item_i['users_id_editor'], 2);
//            $message = __('%1$s on %2$s by %3$s');
//            $action = $item_i['status'] == CommonITILValidation::ACCEPTED ? __('Accepted') : __('Refused');
//            echo sprintf(
//               $message,
//               $action,
//               Html::convDateTime($item_i['date_approval']),
//               $user->getLink()
//            );
//            echo Html::showToolTip($userdata["comment"],
//               ['link' => $userdata['link']]);
//            echo "</div>";
//         }

         // show "is_private" icon
         if (isset($item_i['is_private']) && $item_i['is_private']) {
            echo "<div class='private'>".__('Private')."</div>";
         }

         echo "</div>"; // b_right

//         if ($item['type'] == 'Document_Item') {
//            if ($item_i['filename']) {
//               $filename = $item_i['filename'];
//               $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
//               echo "<img src='";
//               if (empty($filename)) {
//                  $filename = $item_i['name'];
//               }
//               if (file_exists(GLPI_ROOT."/pics/icones/$ext-dist.png")) {
//                  echo $CFG_GLPI['root_doc']."/pics/icones/$ext-dist.png";
//               } else {
//                  echo "$pics_url/file.png";
//               }
//               echo "'/>&nbsp;";
//
//               echo "<a href='".$CFG_GLPI['root_doc']."/front/document.send.php?docid=".$item_i['id']
//                  ."&tickets_id=".$the_ticket->getID()."' target='_blank'>$filename";
//               if (Document::isImage($filename)) {
//                  echo "<div class='timeline_img_preview'>";
//                  echo "<img src='".$CFG_GLPI['root_doc']."/front/document.send.php?docid=".$item_i['id']
//                     ."&tickets_id=".$the_ticket->getID()."&context=timeline'/>";
//                  echo "</div>";
//               }
//               echo "</a>";
//            }
//            if ($item_i['link']) {
//               echo "<a href='{$item_i['link']}' target='_blank'><i class='fa fa-external-link'></i>{$item_i['name']}</a>";
//            }
//            if (!empty($item_i['mime'])) {
//               echo "&nbsp;(".$item_i['mime'].")";
//            }
//            echo "<span class='buttons'>";
//            echo "<a href='".Document::getFormURLWithID($item_i['id'])."' class='edit_document fa fa-eye pointer' title='".
//               _sx("button", "Show")."'>";
//            echo "<span class='sr-only'>" . _sx('button', 'Show') . "</span></a>";
//
//            $doc = new Document();
//            $doc->getFromDB($item_i['id']);
//            if ($doc->can($item_i['id'], UPDATE)) {
//               echo "<a href='".Ticket::getFormURL().
//                  "?delete_document&documents_id=".$item_i['id'].
//                  "&tickets_id=".$the_ticket->getID()."' class='delete_document fa fa-trash-o pointer' title='".
//                  _sx("button", "Delete permanently")."'>";
//               echo "<span class='sr-only'>" . _sx('button', 'Delete permanently')  . "</span></a>";
//            }
//            echo "</span>";
//         }

         echo "</div>"; // displayed_content
         echo "</div>"; //end h_content

         echo "</div>"; //end  h_info

         $timeline_index++;

         Plugin::doHook('post_show_item', ['item' => $obj, 'options' => $options]);

      } // end foreach timeline

      echo "<div class='break'></div>";

      // recall ticket content (not needed in classic and splitted layout)
      if (!CommonGLPI::isLayoutWithMain()) {

         echo "<div class='h_item middle'>";

         echo "<div class='h_info'>";
         echo "<div class='h_date'><i class='fa fa-clock-o'></i>".Html::convDateTime($the_ticket->fields['date'])."</div>";
         echo "<div class='h_user'>";

         echo __('Requester');

         echo "</div>"; // h_user
         echo "</div>"; //h_info

         echo "<div class='h_content TicketContent'>";

         echo "<div class='b_right'>".sprintf(__("Ticket# %s description"), $the_ticket->getID())."</div>";

         echo "<div class='ticket_title'>";
         echo Html::setSimpleTextContent($the_ticket->fields['name']);
         echo "</div>";

         if ($CFG_GLPI["use_rich_text"]) {
            echo "<div class='rich_text_container'>";
            echo Html::setRichTextContent('', $the_ticket->fields['content'], '', true);
            echo "</div>";
         } else {
            echo "<div>";
            echo Toolbox::getHtmlToDisplay(Html::setSimpleTextContent($the_ticket->fields['content']));
            echo "</div>";
         }

         echo "</div>"; // h_content TicketContent

         echo "</div>"; // h_item middle

         echo "<div class='break'></div>";
      }

      // end timeline
      echo "</div>"; // h_item $user_position
      echo "<script type='text/javascript'>$(function() {read_more();});</script>";
   }

   /**
    * @see CommonGLPI::display()
    */
   public function displaySimplified($options = []) {
      global $CFG_GLPI;

      $item = new $options['sub_itemtype'];

      if (isset($options['id'])
          && !$item->isNewID($options['id'])) {
         if (!$item->getFromDB($options['id'])) {
            Html::displayNotFoundError();
         }
      }

      // in case of left tab layout, we couldn't see "right error" message
      if ($item->get_item_to_display_tab) {
         if (isset($options["id"])
             && $options["id"]
             && !$item->can($options["id"], READ)) {
            // This triggers from a profile switch.
            // If we don't have right, redirect instead to central page
            if (isset($_SESSION['_redirected_from_profile_selector'])
                && $_SESSION['_redirected_from_profile_selector']) {
               unset($_SESSION['_redirected_from_profile_selector']);
               Html::redirect($CFG_GLPI['root_doc']."/front/central.php");
            }

            html::displayRightError();
         }
      }

      if (!isset($options['id'])) {
         $options['id'] = 0;
      }

      // Header if the item + link to the list of items
//      $this->showNavigationHeader($options);

      // retrieve associated tickets
      $options['_item'] = $item;
      if ($item Instanceof PluginFormcreatorForm_Answer) {
         $item = $this->getTicketsForDisplay($options);
      }

      // force recall of ticket in layout
      $old_layout = $_SESSION['glpilayout'];
      $_SESSION['glpilayout'] = "lefttab";

      if ($item instanceof Ticket) {
         //Tickets without form associated or single ticket for an answer
         echo "<div class='timeline_box'>";
         $rand = mt_rand();
//         $item->showTimelineForm($rand);
         self::showTimeline($item, $rand);
         echo "</div>";
      } else {
         // No ticket associated to this issue or multiple tickets
         // Show the form answers
         echo '<div class"center">';
         $item->showTabsContent();
         echo '</div>';
      }

      // restore layout
      $_SESSION['glpilayout'] = $old_layout;
   }

   /**
    * Retrieve how many ticket associated to the current answer
    * @param  array $options must contains at least an _item key, instance for answer
    * @return mixed the provide _item key replaced if needed
    */
   public function getTicketsForDisplay($options) {
      $item = $options['_item'];
      $formanswerId = $options['id'];
      $item_ticket = new Item_Ticket();
      $rows = $item_ticket->find("`itemtype` = 'PluginFormcreatorForm_Answer'
                                  AND `items_id` = $formanswerId", "`tickets_id` ASC");

      if (count($rows) == 1) {
         // one ticket, replace item
         $ticket = array_shift($rows);
         $item = new Ticket;
         $item->getFromDB($ticket['tickets_id']);
      } else if (count($rows) > 1) {
         // multiple tickets, force ticket tab in form anser
         Session::setActiveTab('PluginFormcreatorForm_Answer', 'Ticket$1');
      }

      return $item;
   }

   /**
    * Define search options for forms
    *
    * @return Array Array of fields to show in search engine and options for each fields
    */
   public function getSearchOptionsNew() {
      return $this->rawSearchOptions();
   }

   public function rawSearchOptions() {
      $tab = [];

      $tab[] = [
         'id'                 => 'common',
         'name'               => __('Issue', 'formcreator')
      ];

      $tab[] = [
         'id'                 => '1',
         'table'              => $this::getTable(),
         'field'              => 'name',
         'name'               => __('Name'),
         'datatype'           => 'itemlink',
         'massiveaction'      => false,
         'forcegroupby'       => true,
         'additionalfields'   => [
            '0'                  => 'display_id'
         ]
      ];

      $tab[] = [
         'id'                 => '2',
         'table'              => $this::getTable(),
         'field'              => 'display_id',
         'name'               => __('ID'),
         'datatype'           => 'string',
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '3',
         'table'              => $this::getTable(),
         'field'              => 'sub_itemtype',
         'name'               => __('Type'),
         'searchtype'         => [
            '0'                  => 'equals',
            '1'                  => 'notequals'
         ],
         'datatype'           => 'specific',
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '4',
         'table'              => $this::getTable(),
         'field'              => 'status',
         'name'               => __('Status'),
         'searchtype'         => [
            '0'                  => 'equals',
            '1'                  => 'notequals'
         ],
         'datatype'           => 'specific',
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '5',
         'table'              => $this::getTable(),
         'field'              => 'date_creation',
         'name'               => __('Opening date'),
         'datatype'           => 'datetime',
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '6',
         'table'              => $this::getTable(),
         'field'              => 'date_mod',
         'name'               => __('Last update'),
         'datatype'           => 'datetime',
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '7',
         'table'              => 'glpi_entities',
         'field'              => 'completename',
         'name'               => __('Entity'),
         'datatype'           => 'dropdown',
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '8',
         'table'              => 'glpi_users',
         'field'              => 'name',
         'linkfield'          => 'requester_id',
         'name'               => __('Requester'),
         'datatype'           => 'dropdown',
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '9',
         'table'              => 'glpi_users',
         'field'              => 'name',
         'linkfield'          => 'validator_id',
         'name'               => __('Form approver', 'formcreator'),
         'datatype'           => 'dropdown',
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '10',
         'table'              => $this::getTable(),
         'field'              => 'comment',
         'name'               => __('Comment'),
         'datatype'           => 'text',
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '11',
         'table'              => 'glpi_users',
         'field'              => 'name',
         'linkfield'          => 'users_id_validate',
         'name'               => __('Ticket approver', 'formcreator'),
         'datatype'           => 'dropdown',
         'right'              => [
            '0'                  => 'validate_request',
            '1'                  => 'validate_incident'
         ],
         'forcegroupby'       => false,
         'massiveaction'      => false,
         'joinparams'         => [
            'beforejoin'         => [
               '0'                  => [
                  'table'              => 'glpi_items_tickets',
                  'joinparams'         => [
                     'jointype'           => 'itemtypeonly',
                     'specific_itemtype'  => 'PluginFormcreatorForm_Answer',
                     'condition'          => 'AND `REFTABLE`.`original_id` = `NEWTABLE`.`items_id`'
                  ]
               ],
               '1'                  => [
                  'table'              => 'glpi_ticketvalidations'
               ]
            ]
         ]
      ];

      return $tab;
   }

   public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = []) {

      if (!is_array($values)) {
         $values = [$field => $values];
      }
      switch ($field) {
         case 'sub_itemtype':
            return Dropdown::showFromArray($name,
                                           ['Ticket'                      => __('Ticket'),
                                            'PluginFormcreatorForm_Answer' => __('Form answer', 'formcreator')],
                                           ['display' => false,
                                            'value'   => $values[$field]]);
         case 'status' :
            $ticket_opts = Ticket::getAllStatusArray(true);
            $ticket_opts['waiting'] = __('Not validated');
            $ticket_opts['refused'] = __('Refused');
            return Dropdown::showFromArray($name, $ticket_opts, ['display' => false,
                                                                 'value'   => $values[$field]]);
            break;

      }

      return parent::getSpecificValueToSelect($field, $name, $values, $options);
   }

   static function getDefaultSearchRequest() {

      $search = ['criteria' => [0 => ['field'      => 4,
                                      'searchtype' => 'equals',
                                      'value'      => 'notclosed']],
                 'sort'     => 6,
                 'order'    => 'DESC'];

      if (Session::haveRight(self::$rightname, Ticket::READALL)) {
         $search['criteria'][0]['value'] = 'notold';
      }
      return $search;
   }

   public static function giveItem($itemtype, $option_id, $data, $num) {
      $searchopt = &Search::getOptions($itemtype);
      $table = $searchopt[$option_id]["table"];
      $field = $searchopt[$option_id]["field"];

      if (isset($data['raw']['ITEM_0_display_id'])) {
         $matches = null;
         preg_match('/[tf]+_([0-9]*)/', $data['raw']['ITEM_0_display_id'], $matches);
         $id = $matches[1];
      }

      switch ("$table.$field") {
         case "glpi_plugin_formcreator_issues.name":
            $name = $data[$num][0]['name'];
            return "<a href='".FORMCREATOR_ROOTDOC."/front/issue.form.php?id=".$id."&sub_itemtype=".$data['raw']['sub_itemtype']."'>$name</a>";
            break;

         case "glpi_plugin_formcreator_issues.id":
            return $data['raw']['id'];
            break;

         case "glpi_plugin_formcreator_issues.status":
            switch ($data['raw']['sub_itemtype']) {
               case 'Ticket':
                  $status = Ticket::getStatus($data['raw']["ITEM_$num"]);
                  return Ticket::getStatusIcon($data['raw']["ITEM_$num"]);
                  break;

               case 'PluginFormcreatorForm_Answer':
                  return PluginFormcreatorForm_Answer::getSpecificValueToDisplay('status', $data['raw']["ITEM_$num"]);
                  break;
            }
            break;
      }

      return "";
   }

   static function getClosedStatusArray() {
      return Ticket::getClosedStatusArray();
   }

   static function getSolvedStatusArray() {
      return Ticket::getSolvedStatusArray();
   }

   static function getNewStatusArray() {
      return [Ticket::INCOMING, 'waiting', 'accepted', 'refused'];
   }

   static function getProcessStatusArray() {
      return Ticket::getProcessStatusArray();
   }

   static function getReopenableStatusArray() {
      return Ticket::getReopenableStatusArray();
   }

   static function getAllStatusArray($withmetaforsearch = false) {
      $ticket_status = Ticket::getAllStatusArray($withmetaforsearch);
      $form_status = ['waiting', 'accepted', 'refused'];
      $form_status = array_combine($form_status, $form_status);
      $all_status = $ticket_status + $form_status;
      return $all_status;
   }

   static function getIncomingCriteria() {
      return ['criteria' => [['field' => 4,
                              'searchtype' => 'equals',
                              'value'      => 'process',
                              'value'      => 'notold']],
              'reset'    => 'reset'];
   }

   static function getWaitingCriteria() {
      return ['criteria' => [['field' => 4,
                              'searchtype' => 'equals',
                              'value'      => 'process',
                              'value'      => Ticket::WAITING]],
              'reset'    => 'reset'];
   }

   static function getValidateCriteria() {
      return ['criteria' => [['field' => 4,
                              'searchtype' => 'equals',
                              'value'      => 'process',
                              'value'      => 'notclosed',
                              'link'       => 'AND'],
                             ['field' => 9,
                              'searchtype' => 'equals',
                              'value'      => 'process',
                              'value'      => $_SESSION['glpiID'],
                              'link'       => 'AND'],
                             ['field' => 4,
                              'searchtype' => 'equals',
                              'value'      => 'process',
                              'value'      => 'notclosed',
                              'link'       => 'OR'],
                             ['field' => 11,
                              'searchtype' => 'equals',
                              'value'      => 'process',
                              'value'      => $_SESSION['glpiID'],
                              'link'       => 'AND']],
              'reset'    => 'reset'];
   }

   static function getSolvedCriteria() {
      return ['criteria' => [['field' => 4,
                              'searchtype' => 'equals',
                              'value'      => 'old']],
              'reset'    => 'reset'];
   }

   static function getTicketSummary() {
      $status = [
         Ticket::INCOMING => 0,
         Ticket::WAITING => 0,
         'to_validate' => 0,
         Ticket::SOLVED => 0
      ];

      $searchIncoming = Search::getDatas('PluginFormcreatorIssue',
                                         self::getIncomingCriteria());
      if ($searchIncoming['data']['totalcount'] > 0) {
         $status[Ticket::INCOMING] = $searchIncoming['data']['totalcount'];
      }

      $searchWaiting = Search::getDatas('PluginFormcreatorIssue',
                                         self::getWaitingCriteria());
      if ($searchWaiting['data']['totalcount'] > 0) {
         $status[Ticket::WAITING] = $searchWaiting['data']['totalcount'];
      }

      $searchValidate = Search::getDatas('PluginFormcreatorIssue',
                                         self::getValidateCriteria());
      if ($searchValidate['data']['totalcount'] > 0) {
         $status['to_validate'] = $searchValidate['data']['totalcount'];
      }

      $searchSolved = Search::getDatas('PluginFormcreatorIssue',
                                         self::getSolvedCriteria());
      if ($searchSolved['data']['totalcount'] > 0) {
         $status[Ticket::SOLVED] = $searchSolved['data']['totalcount'];
      }

      return $status;
   }

   /**
    *
    */
   public function prepareInputForAdd($input) {
      if (!isset($input['original_id']) || !isset($input['sub_itemtype'])) {
         return false;
      }

      if ($input['sub_itemtype'] == 'PluginFormcreatorForm_Answer') {
         $input['display_id'] = 'f_' . $input['original_id'];
      } else if ($input['sub_itemtype'] == 'Ticket') {
         $input['display_id'] = 't_' . $input['original_id'];
      } else {
         return false;
      }

      return $input;
   }

   public function prepareInputForUpdate($input) {
      if (!isset($input['original_id']) || !isset($input['sub_itemtype'])) {
         return false;
      }

      if ($input['sub_itemtype'] == 'PluginFormcreatorForm_Answer') {
         $input['display_id'] = 'f_' . $input['original_id'];
      } else if ($input['sub_itemtype'] == 'Ticket') {
         $input['display_id'] = 't_' . $input['original_id'];
      } else {
         return false;
      }

      return $input;
   }
}
