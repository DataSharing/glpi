<?php
/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2018 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Log Class
**/
class Log extends CommonDBTM {

   const HISTORY_ADD_DEVICE         = 1;
   const HISTORY_UPDATE_DEVICE      = 2;
   const HISTORY_DELETE_DEVICE      = 3;
   const HISTORY_INSTALL_SOFTWARE   = 4;
   const HISTORY_UNINSTALL_SOFTWARE = 5;
   const HISTORY_DISCONNECT_DEVICE  = 6;
   const HISTORY_CONNECT_DEVICE     = 7;
   const HISTORY_LOCK_DEVICE        = 8;
   const HISTORY_UNLOCK_DEVICE      = 9;

   const HISTORY_LOG_SIMPLE_MESSAGE = 12;
   const HISTORY_DELETE_ITEM        = 13;
   const HISTORY_RESTORE_ITEM       = 14;
   const HISTORY_ADD_RELATION       = 15;
   const HISTORY_DEL_RELATION       = 16;
   const HISTORY_ADD_SUBITEM        = 17;
   const HISTORY_UPDATE_SUBITEM     = 18;
   const HISTORY_DELETE_SUBITEM     = 19;
   const HISTORY_CREATE_ITEM        = 20;
   const HISTORY_UPDATE_RELATION    = 21;
   const HISTORY_LOCK_RELATION      = 22;
   const HISTORY_LOCK_SUBITEM       = 23;
   const HISTORY_UNLOCK_RELATION    = 24;
   const HISTORY_UNLOCK_SUBITEM     = 25;
   const HISTORY_LOCK_ITEM          = 26;
   const HISTORY_UNLOCK_ITEM        = 27;

   // Plugin must use value starting from
   const HISTORY_PLUGIN             = 1000;

   static $rightname = 'logs';



   static function getTypeName($nb = 0) {
      return __('Historical');
   }


   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {

      if (!$withtemplate) {
         $nb = 0;
         if ($_SESSION['glpishow_count_on_tabs']) {
            $nb = countElementsInTable('glpi_logs',
                                       ['itemtype' => $item->getType(),
                                        'items_id' => $item->getID()]);
         }
         return self::createTabEntry(self::getTypeName(1), $nb);
      }
      return '';
   }


   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {

      self::showForItem($item);
      return true;
   }


   /**
    * Construct  history for an item
    *
    * @param $item               CommonDBTM object
    * @param $oldvalues    array of old values updated
    * @param $values       array of all values of the item
    *
    * @return boolean for success (at least 1 log entry added)
   **/
   static function constructHistory(CommonDBTM $item, &$oldvalues, &$values) {

      if (!count($oldvalues)) {
         return false;
      }
      // needed to have  $SEARCHOPTION
      list($real_type, $real_id) = $item->getLogTypeID();
      $searchopt                 = Search::getOptions($real_type);
      if (!is_array($searchopt)) {
         return false;
      }
      $result = 0;
      // type for which getValueToDisplay() could be used (fully tested)
      $oktype = ['Entity'];

      foreach ($oldvalues as $key => $oldval) {
         $changes = [];

         // Parsing $SEARCHOPTION to find changed field
         foreach ($searchopt as $key2 => $val2) {
            if (!isset($val2['table'])) {
               // skip sub-title
               continue;
            }
            // specific for profile
            if (($item->getType() == 'ProfileRight')
                && ($key == 'rights')) {
               if (isset($val2['rightname'])
                   && ($val2['rightname'] == $item->fields['name'])) {

                  $id_search_option = $key2;
                  $changes          =  [$id_search_option, addslashes($oldval), $values[$key]];
               }

            } else if (($val2['linkfield'] == $key)
                || (($key == $val2['field'])
                    && ($val2['table'] == $item->getTable()))) {
               // Linkfield or standard field not massive action enable
               $id_search_option = $key2; // Give ID of the $SEARCHOPTION

               if ($val2['table'] == $item->getTable()) {
                  $changes = [$id_search_option, addslashes($oldval), $values[$key]];
               } else {
                  // other cases; link field -> get data from dropdown
                  if ($val2["table"] != 'glpi_auth_tables') {
                     $changes = [$id_search_option,
                                      addslashes(sprintf(__('%1$s (%2$s)'),
                                                         Dropdown::getDropdownName($val2["table"],
                                                                                   $oldval),
                                                         $oldval)),
                                      addslashes(sprintf(__('%1$s (%2$s)'),
                                                         Dropdown::getDropdownName($val2["table"],
                                                                                   $values[$key]),
                                                         $values[$key]))];
                  }
               }
               break;
            }
            //

         }
         if (count($changes)) {
            $result = self::history($real_id, $real_type, $changes);
         }
      }
      return $result;
   } // function construct_history


   /**
    * Log history
    *
    * @param $items_id
    * @param $itemtype
    * @param $changes
    * @param $itemtype_link   (default '')
    * @param $linked_action   (default '0')
    *
    * @return boolean success
   **/
   static function history ($items_id, $itemtype, $changes, $itemtype_link = '', $linked_action = '0') {
      global $DB;

      $date_mod = $_SESSION["glpi_currenttime"];
      if (empty($changes)) {
         return false;
      }

      // create a query to insert history
      $id_search_option = $changes[0];
      $old_value        = $changes[1];
      $new_value        = $changes[2];

      if ($uid = Session::getLoginUserID(false)) {
         if (is_numeric($uid)) {
            $username = sprintf(__('%1$s (%2$s)'), getUserName($uid), $uid);
         } else { // For cron management
            $username = $uid;
         }

      } else {
         $username = "";
      }

      $old_value = $DB->escape(Toolbox::substr(stripslashes($old_value), 0, 180));
      $new_value = $DB->escape(Toolbox::substr(stripslashes($new_value), 0, 180));

      // Security to be sure that values do not pass over the max length
      if (Toolbox::strlen($old_value) > 255) {
         $old_value = Toolbox::substr($old_value, 0, 250);
      }
      if (Toolbox::strlen($new_value) > 255) {
         $new_value = Toolbox::substr($new_value, 0, 250);
      }

      $params = [
         'items_id'          => $items_id,
         'itemtype'          => $itemtype,
         'itemtype_link'     => $itemtype_link,
         'linked_action'     => $linked_action,
         'user_name'         => addslashes($username),
         'date_mod'          => $date_mod,
         'id_search_option'  => $id_search_option,
         'old_value'         => $old_value,
         'new_value'         => $new_value
      ];
      $result = $DB->insert(self::getTable(), $params);

      if ($result && $DB->affected_rows() > 0) {
         return $_SESSION['glpi_maxhistory'] = $DB->insert_id();
      }
      return false;
   }


   /**
    * Show History of an item
    *
    * @param $item                     CommonDBTM object
    * @param $withtemplate    integer  withtemplate param (default 0)

   **/
   static function showForItem(CommonDBTM $item, $withtemplate = 0) {
      global $DB;

      $itemtype = $item->getType();
      $items_id = $item->getField('id');

      $SEARCHOPTION = Search::getOptions($itemtype);
      if (isset($_GET["start"])) {
         $start = intval($_GET["start"]);
      } else {
         $start = 0;
      }

      // Total Number of events
      $number = countElementsInTable("glpi_logs", ['items_id' => $items_id, 'itemtype' => $itemtype ]);

      // No Events in database
      if ($number < 1) {
         echo "<div class='center'>";
         echo "<table class='tab_cadre_fixe'>";
         echo "<tr><th>".__('No historical')."</th></tr>";
         echo "</table>";
         echo "</div><br>";
         return;
      }

      // Display the pager
      Html::printAjaxPager(self::getTypeName(1), $start, $number);

      // Output events
      echo "<div class='center'><table class='tab_cadre_fixehov'>";

      $header = "<tr><th>".__('ID')."</th>";
      $header .= "<th>".__('Date')."</th>";
      $header .= "<th>".__('User')."</th>";
      $header .= "<th>".__('Field')."</th>";
      //TRANS: a noun, modification, change
      $header .= "<th>"._x('name', 'Update')."</th></tr>";
      echo $header;

      foreach (self::getHistoryData($item, $start, $_SESSION['glpilist_limit']) as $data) {
         if ($data['display_history']) {
            // show line
            echo "<tr class='tab_bg_2'>";
            echo "<td>".$data['id']."</td>".
                 "<td class='tab_date'>".$data['date_mod']."</td>".
                 "<td>".$data['user_name']."</td>".
                 "<td>".$data['field']."</td>";
            echo "<td width='60%'>".$data['change']."</td></tr>";
         }
      }
      echo $header;
      echo "</table></div>";
      Html::printAjaxPager(self::getTypeName(1), $start, $number);

   }


   /**
    * Retrieve last history Data for an item
    *
    * @param CommonDBTM $item      Object instance
    * @param integer    $start     first line to retrieve (default 0)
    * @param integer    $limit     max number of line to retrive (0 for all) (default 0)
    * @param array      $sqlfilter to add an SQL filter (default '')
    *
    * @return array of localized log entry (TEXT only, no HTML)
   **/
   static function getHistoryData(CommonDBTM $item, $start = 0, $limit = 0, array $sqlfilter = []) {
      global $DB;

      $itemtype  = $item->getType();
      $items_id  = $item->getField('id');
      $itemtable = $item->getTable();

      $SEARCHOPTION = Search::getOptions($itemtype);

      $query = [
         'FROM'   => self::getTable(),
         'WHERE'  => [
            'items_id'  => $items_id,
            'itemtype'  => $itemtype
         ] + $sqlfilter,
         'ORDER'  => 'id DESC'
      ];

      if ($limit) {
         $query['START'] = (int)$start;
         $query['LIMIT'] = (int)$limit;
      }

      $iterator = $DB->request($query);

      $changes = [];
      while ($data = $iterator->next()) {
         $tmp = [];
         $tmp['display_history'] = true;
         $tmp['id']              = $data["id"];
         $tmp['date_mod']        = Html::convDateTime($data["date_mod"]);
         $tmp['user_name']       = $data["user_name"];
         $tmp['field']           = "";
         $tmp['change']          = "";
         $tmp['datatype']        = "";

         // This is an internal device ?
         if ($data["linked_action"]) {
            // Yes it is an internal device
            switch ($data["linked_action"]) {
               case self::HISTORY_CREATE_ITEM :
                  $tmp['change'] = __('Add the item');
                  break;

               case self::HISTORY_DELETE_ITEM :
                  $tmp['change'] = __('Delete the item');
                  break;

               case self::HISTORY_LOCK_ITEM :
                  $tmp['change'] = __('Lock the item');
                  break;

               case self::HISTORY_UNLOCK_ITEM :
                  $tmp['change'] = __('Unlock the item');
                  break;

               case self::HISTORY_RESTORE_ITEM :
                  $tmp['change'] = __('Restore the item');
                  break;

               case self::HISTORY_ADD_DEVICE :
                  $tmp['field'] = NOT_AVAILABLE;
                  if ($item2 = getItemForItemtype($data["itemtype_link"])) {
                     $tmp['field'] = $item2->getTypeName(1);
                  }
                  //TRANS: %s is the component name
                  $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Add the component'),
                                           $data["new_value"]);
                  break;

               case self::HISTORY_UPDATE_DEVICE :
                  $tmp['field'] = NOT_AVAILABLE;
                  $change = '';
                  $linktype_field = explode('#', $data["itemtype_link"]);
                  $linktype       = $linktype_field[0];
                  $field          = $linktype_field[1];
                  $devicetype     = $linktype::getDeviceType();
                  $tmp['field']   = $devicetype;
                  $specif_fields  = $linktype::getSpecificities();
                  if (isset($specif_fields[$field]['short name'])) {
                     $tmp['field']   = $devicetype;
                     $tmp['field']  .= " (".$specif_fields[$field]['short name'].")";
                  }
                  //TRANS: %1$s is the old_value, %2$s is the new_value
                  $tmp['change']  = sprintf(__('Change the component %1$s: %2$s'),
                                            $tmp['field'],
                                            sprintf(__('%1$s by %2$s'), $data["old_value"],
                                                    $data[ "new_value"]));
                  break;

               case self::HISTORY_DELETE_DEVICE :
                  $tmp['field']=NOT_AVAILABLE;
                  if ($item2 = getItemForItemtype($data["itemtype_link"])) {
                     $tmp['field'] = $item2->getTypeName(1);
                  }
                  //TRANS: %s is the component name
                  $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Delete the component'),
                                           $data["old_value"]);
                  break;

               case self::HISTORY_LOCK_DEVICE :
                  $tmp['field'] = NOT_AVAILABLE;
                  if ($item2 = getItemForItemtype($data["itemtype_link"])) {
                     $tmp['field'] = $item2->getTypeName(1);
                  }
                  //TRANS: %s is the component name
                  $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Lock the component'),
                                           $data["old_value"]);
                  break;

               case self::HISTORY_UNLOCK_DEVICE :
                  $tmp['field'] = NOT_AVAILABLE;
                  if ($item2 = getItemForItemtype($data["itemtype_link"])) {
                     $tmp['field'] = $item2->getTypeName(1);
                  }
                  //TRANS: %s is the component name
                  $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Unlock the component'),
                                           $data["new_value"]);
                  break;

               case self::HISTORY_INSTALL_SOFTWARE :
                  $tmp['field']  = _n('Software', 'Software', 1);
                  //TRANS: %s is the software name
                  $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Install the software'),
                                           $data["new_value"]);
                  break;

               case self::HISTORY_UNINSTALL_SOFTWARE :
                  $tmp['field']  = _n('Software', 'Software', 1);
                  //TRANS: %s is the software name
                  $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Uninstall the software'),
                                           $data["old_value"]);
                  break;

               case self::HISTORY_DISCONNECT_DEVICE :
                  $tmp['field'] = NOT_AVAILABLE;
                  if ($item2 = getItemForItemtype($data["itemtype_link"])) {
                     $tmp['field'] = $item2->getTypeName(1);
                  }
                  //TRANS: %s is the item name
                  $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Disconnect the item'),
                                           $data["old_value"]);
                  break;

               case self::HISTORY_CONNECT_DEVICE :
                  $tmp['field'] = NOT_AVAILABLE;
                  if ($item2 = getItemForItemtype($data["itemtype_link"])) {
                     $tmp['field'] = $item2->getTypeName(1);
                  }
                  //TRANS: %s is the item name
                  $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Connect the item'),
                                           $data["new_value"]);
                  break;

               case self::HISTORY_LOG_SIMPLE_MESSAGE :
                  $tmp['field']  = "";
                  $tmp['change'] = $data["new_value"];
                  break;

               case self::HISTORY_ADD_RELATION :
                  $tmp['field'] = NOT_AVAILABLE;
                  if ($item2 = getItemForItemtype($data["itemtype_link"])) {
                     $tmp['field'] = $item2->getTypeName(1);
                  }
                  $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Add a link with an item'),
                                           $data["new_value"]);

                  if ($data['itemtype'] == 'Ticket') {
                     if ($data['id_search_option']) { // Recent record - see CommonITILObject::getSearchOptionsActors()
                        $as = $SEARCHOPTION[$data['id_search_option']]['name'];
                     } else { // Old record
                        switch ($data['itemtype_link']) {
                           case 'Group':
                              $is = 'isGroup';
                              break;

                           case 'User':
                              $is = 'isUser';
                              break;

                           case 'Supplier':
                              $is = 'isSupplier';
                              break;

                           default:
                              $is = $isr = $isa = $iso = false;
                              break;
                        }
                        if ($is) {
                           $iditem = intval(substr($data['new_value'], strrpos($data['new_value'], '(')+1)); // This is terrible idea
                           $isr = $item->$is(CommonITILActor::REQUESTER, $iditem);
                           $isa = $item->$is(CommonITILActor::ASSIGN, $iditem);
                           $iso = $item->$is(CommonITILActor::OBSERVER, $iditem);
                        }
                        // Simple Heuristic, of course not enough
                        if ($isr && !$isa && !$iso) {
                           $as = __('Requester');
                        } else if (!$isr && $isa && !$iso) {
                           $as = __('Assigned to');
                        } else if (!$isr && !$isa && $iso) {
                           $as = __('Watcher');
                        } else {
                           // Deleted or Ambiguous
                           $as = false;
                        }
                     }
                     if ($as) {
                        $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Add a link with an item'),
                              sprintf(__('%1$s (%2$s)'), $data["new_value"], $as));
                     } else {
                        $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Add a link with an item'), $data["new_value"]);
                     }
                  }
                  break;

               case self::HISTORY_UPDATE_RELATION :
                  $tmp['field']   = NOT_AVAILABLE;
                  if ($linktype_field = explode('#', $data["itemtype_link"])) {
                     $linktype     = $linktype_field[0];
                     $tmp['field'] = $linktype::getTypeName();
                  }
                  $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Update a link with an item'),
                                       sprintf(__('%1$s (%2$s)'), $data["old_value"],
                                          $data["new_value"]));
                  break;

               case self::HISTORY_DEL_RELATION :
                  $tmp['field'] = NOT_AVAILABLE;
                  if ($item2 = getItemForItemtype($data["itemtype_link"])) {
                     $tmp['field'] = $item2->getTypeName(1);
                  }
                  $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Delete a link with an item'),
                                           $data["old_value"]);
                  break;

               case self::HISTORY_LOCK_RELATION :
                  $tmp['field'] = NOT_AVAILABLE;
                  if ($item2 = getItemForItemtype($data["itemtype_link"])) {
                     $tmp['field'] = $item2->getTypeName(1);
                  }
                  $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Lock a link with an item'),
                                           $data["old_value"]);
                  break;

               case self::HISTORY_UNLOCK_RELATION :
                  $tmp['field'] = NOT_AVAILABLE;
                  if ($item2 = getItemForItemtype($data["itemtype_link"])) {
                     $tmp['field'] = $item2->getTypeName(1);
                  }
                  $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Unlock a link with an item'),
                                           $data["new_value"]);
                  break;

               case self::HISTORY_ADD_SUBITEM :
                  $tmp['field'] = '';
                  if ($item2 = getItemForItemtype($data["itemtype_link"])) {
                     $tmp['field'] = $item2->getTypeName(1);
                  }
                  $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Add an item'),
                                           sprintf(__('%1$s (%2$s)'), $tmp['field'],
                                                   $data["new_value"]));

                  break;

               case self::HISTORY_UPDATE_SUBITEM :
                  $tmp['field'] = '';
                  if ($item2 = getItemForItemtype($data["itemtype_link"])) {
                     $tmp['field'] = $item2->getTypeName(1);
                  }
                  $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Update an item'),
                                           sprintf(__('%1$s (%2$s)'), $tmp['field'],
                                                   $data["new_value"]));
                  break;

               case self::HISTORY_DELETE_SUBITEM :
                  $tmp['field'] = '';
                  if ($item2 = getItemForItemtype($data["itemtype_link"])) {
                     $tmp['field'] = $item2->getTypeName(1);
                  }
                  $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Delete an item'),
                                           sprintf(__('%1$s (%2$s)'), $tmp['field'],
                                                   $data["old_value"]));
                  break;

               case self::HISTORY_LOCK_SUBITEM :
                  $tmp['field'] = '';
                  if ($item2 = getItemForItemtype($data["itemtype_link"])) {
                     $tmp['field'] = $item2->getTypeName(1);
                  }
                  $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Lock an item'),
                                           sprintf(__('%1$s (%2$s)'), $tmp['field'],
                                                   $data["old_value"]));
                  break;

               case self::HISTORY_UNLOCK_SUBITEM :
                  $tmp['field'] = '';
                  if ($item2 = getItemForItemtype($data["itemtype_link"])) {
                     $tmp['field'] = $item2->getTypeName(1);
                  }
                  $tmp['change'] = sprintf(__('%1$s: %2$s'), __('Unlock an item'),
                                           sprintf(__('%1$s (%2$s)'), $tmp['field'],
                                                   $data["new_value"]));
                  break;

               default :
                  $fct = [$data['itemtype_link'], 'getHistoryEntry'];
                  if (($data['linked_action'] >= self::HISTORY_PLUGIN)
                      && $data['itemtype_link']
                      && is_callable($fct)) {
                     $tmp['field']  = $data['itemtype_link']::getTypeName(1);
                     $tmp['change'] = call_user_func($fct, $data);
                  }
                  $tmp['display_history'] = !empty($tmp['change']);
            }

         } else {
            $fieldname = "";
            $searchopt = [];
            $tablename = '';
            // It's not an internal device
            foreach ($SEARCHOPTION as $key2 => $val2) {
               if ($key2 == $data["id_search_option"]) {
                  $tmp['field'] =  $val2["name"];
                  $tablename    =  $val2["table"];
                  $fieldname    = $val2["field"];
                  $searchopt    = $val2;
                  if (isset($val2['datatype'])) {
                     $tmp['datatype'] = $val2["datatype"];
                  }
                  break;
               }
            }
            if (($itemtable == $tablename)
                || ($tmp['datatype'] == 'right')) {
               switch ($tmp['datatype']) {
                  // specific case for text field
                  case 'text' :
                     $tmp['change'] = __('Update of the field');
                     break;

                  default :
                     $data["old_value"] = $item->getValueToDisplay($searchopt, $data["old_value"]);
                     $data["new_value"] = $item->getValueToDisplay($searchopt, $data["new_value"]);
                     break;
               }
            }

            if (empty($tmp['change'])) {
               $newval = $data["new_value"];
               $oldval = $data["old_value"];

               if ($data['id_search_option'] == '70') {
                  $newval = explode(' ', $newval);
                  $oldval = explode(' ', $oldval);

                  if ($oldval[0] == '&nbsp;') {
                     $oldval = $data["old_value"];
                  } else {
                     foreach ($DB->request('glpi_users', "`name` = '".$oldval[0]."'") as $val) {
                        $oldval = sprintf(__('%1$s %2$s'),
                              formatUserName($val['id'], $oldval[0], $val['realname'],
                                    $val['firstname']),
                              $oldval[1]);
                     }
                  }

                  if ($newval[0] == '&nbsp;') {
                     $newval = $data["new_value"];
                  } else {
                     foreach ($DB->request('glpi_users', "`name` = '".$newval[0]."'") as $val) {
                        $newval = sprintf(__('%1$s %2$s'),
                              formatUserName($val['id'], $newval[0], $val['realname'],
                                    $val['firstname']),
                              $newval[1]);
                     }
                  }
               }
               $tmp['change'] = sprintf(__('Change %1$s to %2$s'), $oldval, $newval);
            }
         }
         $changes[] = $tmp;
      }
      return $changes;
   }


   /**
    * Actions done after the ADD of the item in the database
    *
    * @since 0.83
    *
    * @see CommonDBTM::post_addItem()
   **/
   function post_addItem() {
      $_SESSION['glpi_maxhistory'] = $this->fields['id'];
   }


   /**
    * @since 0.85
    *
    * @see commonDBTM::getRights()
   **/
   function getRights($interface = 'central') {

      $values = [ READ => __('Read')];
      return $values;
   }

}

