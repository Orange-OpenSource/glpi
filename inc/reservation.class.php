<?php
/*
 * @version $Id$
 -------------------------------------------------------------------------
 GLPI - Gestionnaire Libre de Parc Informatique
 Copyright (C) 2003-2011 by the INDEPNET Development Team.

 http://indepnet.net/   http://glpi-project.org
 -------------------------------------------------------------------------

 LICENSE

 This file is part of GLPI.

 GLPI is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 GLPI is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

// ----------------------------------------------------------------------
// Original Author of file: Julien Dombre
// Purpose of file:
// ----------------------------------------------------------------------

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

/// Reservation class
class Reservation extends CommonDBChild {

   // From CommonDBChild
   public $itemtype = 'ReservationItem';
   public $items_id = 'reservationitems_id';


   static function getTypeName($nb=0) {
      global $LANG;

      if ($nb>1) {
         return $LANG['Menu'][17];
      }
      return $LANG['log'][42];
   }


   function getTabNameForItem(CommonGLPI $item, $withtemplate=0) {
      global $LANG;

      if (!$withtemplate && Session::haveRight("reservation_central","r")) {
         return $LANG['Menu'][17];
      }
      return '';
   }


      static function displayTabContentForItem(CommonGLPI $item, $tabnum=1, $withtemplate=0) {

      if ($item->getType() == 'User') {
         self::showForUser($_POST["id"]);
      } else {
         self::showForItem($item);
      }
      return true;
   }


   function pre_deleteItem() {
      global $CFG_GLPI;

      if (isset($this->fields["users_id"])
          && ($this->fields["users_id"]===Session::getLoginUserID()
              || Session::haveRight("reservation_central","w"))) {

         // Processing Email
         if ($CFG_GLPI["use_mailing"]) {
            NotificationEvent::raiseEvent("delete",$this);
         }
      }
      return true;
   }


   function prepareInputForUpdate($input) {

      $item = 0;
      if (isset($input['_item'])) {
         $item = $_POST['_item'];
      }

      $this->getFromDB($input["id"]);
      // Save fields
      $oldfields = $this->fields;
      // Needed for test already planned
      $this->fields["begin"] = $input["begin"];
      $this->fields["end"]   = $input["end"];

      if (!$this->test_valid_date()) {
         $this->displayError("date", $item);
         return false;
      }

      if ($this->is_reserved()) {
         $this->displayError("is_res", $item);
         return false;
      }

      // Restore fields
      $this->fields=$oldfields;

      return $input;
   }


   function post_updateItem($history=1) {
      global $CFG_GLPI;

      if (count($this->updates) && $CFG_GLPI["use_mailing"]) {
         NotificationEvent::raiseEvent("update",$this);
         //$mail = new MailingResa($this,"update");
         //$mail->send();
      }
   }


   function prepareInputForAdd($input) {

      // Error on previous added reservation on several add
      if (isset($input['_ok']) && !$input['_ok']) {
         return false;
      }

      // set new date.
      $this->fields["reservationitems_id"] = $input["reservationitems_id"];
      $this->fields["begin"] = $input["begin"];
      $this->fields["end"]   = $input["end"];

      if (!$this->test_valid_date()) {
         $this->displayError("date",$input["reservationitems_id"]);
         return false;
      }

      if ($this->is_reserved()) {
         $this->displayError("is_res",$input["reservationitems_id"]);
         return false;
      }

      return $input;
   }


   function post_addItem() {
      global $CFG_GLPI;

      if ($CFG_GLPI["use_mailing"]) {
         NotificationEvent::raiseEvent("new",$this);
      }
   }


   // SPECIFIC FUNCTIONS
   function getUniqueGroupFor($reservationitems_id) {
      global $DB;

      do {
         $rand = mt_rand(1,mt_getrandmax());

         $query = "SELECT COUNT(*) AS CPT
                   FROM `glpi_reservations`
                   WHERE `reservationitems_id` = '$reservationitems_id'
                         AND `group` = '$rand';";
         $result = $DB->query($query);
         $count  = $DB->result($result,0,0);
      } while ($count > 0);

      return $rand;
   }


   /**
    * Is the item already reserved ?
    *
    *@return boolean
   **/
   function is_reserved() {
      global $DB;

      if (!isset($this->fields["reservationitems_id"])
          || empty($this->fields["reservationitems_id"])) {
         return true;
      }

      // When modify a reservation do not itself take into account
      $ID_where = "";
      if (isset($this->fields["id"])) {
         $ID_where = " `id` <> '".$this->fields["id"]."' AND ";
      }
      $query = "SELECT *
                FROM `".$this->getTable()."`
                WHERE $ID_where
                         `reservationitems_id` = '".$this->fields["reservationitems_id"]."'
                      AND '".$this->fields["begin"]."' < `end`
                      AND '".$this->fields["end"]."' > `begin`";
      if ($result=$DB->query($query)) {
         return ($DB->numrows($result)>0);
      }
      return true;
   }


   /**
    * Current dates are valid ? begin before end
    *
    *@return boolean
   **/
   function test_valid_date() {

      return (!empty($this->fields["begin"])
              && !empty($this->fields["end"])
              && strtotime($this->fields["begin"])<strtotime($this->fields["end"]));
   }


   /**
    * display error message
    * @param $type error type : date / is_res / other
    * @param $ID ID of the item
    *
    * @return nothing
   **/
   function displayError($type,$ID) {
      global $LANG;

      echo "<br><div class='center'>";
      switch ($type) {
         case "date" :
            echo $LANG['planning'][1];
            break;

         case "is_res" :
            echo __('The required item is already reserved for this timeframe');
            break;

         default :
            echo "Unknown error";
      }

      echo "<br><a href='reservation.php?reservationitems_id=$ID'>".__('Back to planning')."</a>";
      echo "</div>";
   }


   function can($ID, $right, &$input=NULL) {

      if (empty($ID) || $ID<=0) {
         return Session::haveRight("reservation_helpdesk", "1");
      }

      if (!isset($this->fields['id']) || $this->fields['id']!=$ID) {
         // Item not found : no right
         if (!$this->getFromDB($ID)) {
            return false;
         }
      }

      // Original user always have right
      if ($this->fields['users_id']===Session::getLoginUserID()) {
         return true;
      }

      if (!Session::haveRight("reservation_central",$right)) {
         return false;
      }

      $ri = new ReservationItem();
      if (!$ri->getFromDB($this->fields["reservationitems_id"])) {
         return false;
      }

      if (!($item = getItemForItemtype($ri->fields["itemtype"]))) {
         return false;
      }

      if (!$item->getFromDB($ri->fields["items_id"])) {
         return false;
      }

      return Session::haveAccessToEntity($item->getEntityID());
   }


   function post_purgeItem() {
      global $DB;

      if (isset($this->input['_delete_group']) && $this->input['_delete_group']) {
         $query = "SELECT *
                   FROM `glpi_reservations`
                   WHERE `reservationitems_id` = '".$this->fields['reservationitems_id']."'
                         AND `group` = '".$this->fields['group']."' ";
         $rr = clone $this;
         foreach($DB->request($query) as $data) {
            $rr->delete(array('id' => $data['id']));
         }
      }
   }


   /**
   * Show reservation calendar
   *
   * @param $ID ID of the reservation item (if empty display all)
   **/
   static function showCalendar($ID="") {
      global $LANG, $CFG_GLPI;

      if (!Session::haveRight("reservation_helpdesk","1")) {
         return false;
      }

      if (!isset($_GET["mois_courant"])) {
         $mois_courant = strftime("%m");
      } else {
         $mois_courant = $_GET["mois_courant"];
      }

      if (!isset($_GET["annee_courante"])) {
         $annee_courante = strftime("%Y");
      } else {
         $annee_courante = $_GET["annee_courante"];
      }

      $mois_suivant     = $mois_courant+1;
      $mois_precedent   = $mois_courant-1;
      $annee_suivante   = $annee_courante;
      $annee_precedente = $annee_courante;

      if ($mois_precedent==0) {
         $mois_precedent = 12;
         $annee_precedente--;
      }

      if ($mois_suivant==13) {
         $mois_suivant = 1;
         $annee_suivante++;
      }

      $monthsarray = Toolbox::getMonthsOfYearArray();

      $str_suivant = "?reservationitems_id=$ID&amp;mois_courant=$mois_suivant&amp;".
                     "annee_courante=$annee_suivante";
      $str_precedent = "?reservationitems_id=$ID&amp;mois_courant=$mois_precedent&amp;".
                       "annee_courante=$annee_precedente";

      if (!empty($ID)) {
         $m = new ReservationItem();
         $m->getFromDB($ID);

         if ((!isset($m->fields['is_active'])) || !$m->fields['is_active']) {
            echo "<div class='center'>";
            echo "<table class='tab_cadre_fixe'>";
            echo "<tr class='tab_bg_2'>";
            echo "<td class='center b'>".__('Device temporarily unavailable')."</td></tr>";
            echo "<tr class='tab_bg_1'><td class='center b'>";
            Html::displayBackLink();
            echo "</td></tr>";
            echo "</table>";
            echo "</div>";
            return false;
         }
         $type = $m->fields["itemtype"];
         $name = NOT_AVAILABLE;
         if ($item = getItemForItemtype($m->fields["itemtype"])) {
            $type  =$item->getTypeName();

            if ($item->getFromDB($m->fields["items_id"])) {
               $name = $item->getName();
            }
            $name = $type." - ".$name;
         }

         $all = "<a href='reservation.php?reservationitems_id=&amp;mois_courant=".
                  "$mois_courant&amp;annee_courante=$annee_courante'>".__('Show all')."</a>";

      } else {
         $type = "";
         $name = __('All reservable device');
         $all  = "&nbsp;";
      }

      echo "<div class='center'><table class='tab_glpi'><tr><td>";
      echo "<img src='".$CFG_GLPI["root_doc"]."/pics/reservation.png' alt='' title=''></td>";
      echo "<td class ='b'><span class='icon_consol'>".$name."</span></td></tr>";
      echo "<tr><td colspan='2' class ='center'>$all</td></tr></table></div>\n";

      // Check bisextile years
      if (($annee_courante%4)==0) {
         $fev = 29;
      } else {
         $fev = 28;
      }
      $nb_jour = array(31, $fev, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);

      // Datas used to put right informations in columns
      $jour_debut_mois = strftime("%w", mktime(0, 0, 0, $mois_courant, 1, $annee_courante));
      if ($jour_debut_mois==0) {
         $jour_debut_mois = 7;
      }
      $jour_fin_mois = strftime("%w", mktime(0, 0, 0, $mois_courant, $nb_jour[$mois_courant-1],
                                             $annee_courante));

      echo "<div class='center'>";
      echo "<table class='tab_glpi'><tr><td><a href='reservation.php".$str_precedent."'>";
      echo "<img src='".$CFG_GLPI["root_doc"]."/pics/left.png' alt=\"".__s('Previous').
             "\" title=\"".__s('Previous')."\"></a></td>";
      echo "<td class='b'>".$monthsarray[$mois_courant]."&nbsp;".$annee_courante."</td>";
      echo "<td><a href='reservation.php".$str_suivant."'>";
      echo "<img src='".$CFG_GLPI["root_doc"]."/pics/right.png' alt=\"".__s('Next').
             "\" title=\"".__s('Next')."\"></a></td></tr></table>\n";

      // test
      echo "<table width='90%' class='tab_glpi'><tr><td class='top' width='100px'>";

      echo "<table><tr><td width='100px' class='top'>";

      // today date
      $today = getdate(time());
      $mois  = $today["mon"];
      $annee = $today["year"];

      $annee_avant = $annee_courante - 1;
      $annee_apres = $annee_courante + 1;

      echo "<div class='calendrier_mois'>";
      echo "<div class='center b'>$annee_avant</div>";

      for ($i=$mois_courant ; $i<13 ; $i++) {
         echo "<div class='calendrier_case2'>";
         echo "<a href='reservation.php?reservationitems_id=$ID&amp;mois_courant=$i&amp;".
               "annee_courante=$annee_avant'>".$monthsarray[$i]."</a></div>";
      }

      echo "<div class='center b'>$annee_courante</div>";

      for ($i=1 ; $i<13 ; $i++) {
         if ($i == $mois_courant) {
            echo "<div class='calendrier_case1 b'>".$monthsarray[$i]."</div>\n";
         } else {
            echo "<div class='calendrier_case2'>";
            echo "<a href='reservation.php?reservationitems_id=$ID&amp;mois_courant=$i&amp;".
                  "annee_courante=$annee_courante'>".$monthsarray[$i]."</a></div>\n";
         }
      }
      echo "<div class='center b'>$annee_apres</div>\n";

      for ($i=1 ; $i<$mois_courant+1 ; $i++) {
         echo "<div class='calendrier_case2'>";
         echo "<a href='reservation.php?reservationitems_id=$ID&amp;mois_courant=$i&amp;".
               "annee_courante=$annee_apres'>".$monthsarray[$i]."</a></div>\n";
      }
      echo "</div>";
      echo "</td></tr></table>";
      echo "</td><td class='top' width='100%'>";

      // test
      echo "<table width='100%' class='tab_cadre'><tr>";
      echo "<th width='14%'>".__('Monday')."</th>";
      echo "<th width='14%'>".__('Tuesday')."</th>";
      echo "<th width='14%'>".__('Wednesday')."</th>";
      echo "<th width='14%'>".__('Thursday')."</th>";
      echo "<th width='14%'>".__('Friday')."</th>";
      echo "<th width='14%'>".__('Sunday')."</th>";
      echo "<th width='14%'>".__('Saturday')."</th>";
      echo "</tr>\n";
      echo "<tr class='tab_bg_3' >";

      // Insert blank cell before the first day of the month
      for ($i=1 ; $i<$jour_debut_mois ; $i++) {
         echo "<td class='calendrier_case_white'>&nbsp;</td>";
      }

      // voici le remplissage proprement dit
      if ($mois_courant<10&&strlen($mois_courant)==1) {
         $mois_courant = "0".$mois_courant;
      }

      for ($i=1 ; $i<$nb_jour[$mois_courant-1]+1 ; $i++) {
         if ($i<10) {
            $ii = "0".$i;
         } else {
            $ii = $i;
         }

         echo "<td class='top' height='100px'>";
         echo "<table class='center' width='100%'><tr><td class='center'>";
         echo "<span class='calendrier_jour'>".$i."</span></td></tr>\n";

         if (!empty($ID)) {
            echo "<tr><td class='center'>";
            echo "<a href='reservation.form.php?id=&amp;item[$ID]=$ID&amp;".
                  "date=".$annee_courante."-".$mois_courant."-".$ii."'>";
            echo "<img  src='".$CFG_GLPI["root_doc"]."/pics/addresa.png' alt=\"".
                  __s('Reserve')."\" title=\"".__s('Reserve')."\"></a></td></tr>\n";
         }

         echo "<tr><td>";
         self::displayReservationDay($ID, $annee_courante."-".$mois_courant."-".$ii);
         echo "</td></tr></table>\n";
         echo "</td>";

         // il ne faut pas oublie d'aller a la ligne suivante en fin de semaine
         if (($i+$jour_debut_mois)%7==1) {
            echo "</tr>\n";
            if ($i!=$nb_jour[$mois_courant-1]) {
               echo "<tr class='tab_bg_3'>";
            }
         }
      }

      // on recommence pour finir le tableau proprement pour les m�es raisons
      if ($jour_fin_mois!=0) {
         for ($i=0 ; $i<7-$jour_fin_mois ; $i++) {
            echo "<td class='calendrier_case_white'>&nbsp;</td>";
         }
      }

      echo "</tr></table>\n";
      echo "</td></tr></table></div>\n";
   }


   /**
    * Display for reservation
    *
    * @param $ID ID of the reservation (empty for create new)
    * @param $options array
    *     - item  reservation items ID for creation process
    *     - date date for creation process
   **/
   function showForm($ID, $options=array()) {
      global $LANG;

      if (!Session::haveRight("reservation_helpdesk","1")) {
         return false;
      }

      $resa = new Reservation();

      if (!empty($ID)) {
         if (!$resa->getFromDB($ID)) {
            return false;
         }

         if (!$resa->can($ID,"w")) {
            return false;
         }
         // Set item if not set
         if ((!isset($options['item']) || count($options['item'])==0)
             && $itemid=$resa->getField('reservationitems_id')) {
            $options['item'][$itemid] = $itemid;
         }

      } else {
         $resa->getEmpty();
         $resa->fields["begin"] = $options['date']." 12:00:00";
         $resa->fields["end"]   = $options['date']." 13:00:00";
      }

      // No item : problem
      if (!isset($options['item']) || count($options['item'])==0) {
         return false;
      }

      echo "<div class='center'><form method='post' name=form action='reservation.form.php'>";

      if (!empty($ID)) {
         echo "<input type='hidden' name='id' value='$ID'>";
      }

      echo "<table class='tab_cadre'>";
      echo "<tr><th colspan='2'>".__('Reserve an item')."</th></tr>\n";

      // Add Hardware name
      $r = new ReservationItem();

      echo "<tr class='tab_bg_1'><td>".__('Item')."</td>";
      echo "<td>";
      foreach ($options['item'] as $itemID) {
         $r->getFromDB($itemID);
         $type = $r->fields["itemtype"];
         $name = NOT_AVAILABLE;
         $item = NULL;

         if ($item = getItemForItemtype($r->fields["itemtype"])) {
            $type = $item->getTypeName();

            if ($item->getFromDB($r->fields["items_id"])) {
               $name = $item->getName();
            } else {
               $item = NULL;
            }
         }

         echo "<span class='b'>$type - $name</span><br>";
         echo "<input type='hidden' name='items[$itemID]' value='$itemID'>";
      }

      echo "</td></tr>\n";
      if (!Session::haveRight("reservation_central","w")
          || is_null($item)
          || !Session::haveAccessToEntity($item->fields["entities_id"])) {

         echo "<input type='hidden' name='users_id' value='".Session::getLoginUserID()."'>";

      } else {
         echo "<tr class='tab_bg_2'><td>".$LANG['common'][95]."&nbsp;:</td>";
         echo "<td>";
         if (empty($ID)) {
            User::dropdown(array('value'  => Session::getLoginUserID(),
                                 'entity' => $item->getEntityID(),
                                 'right'  => 'all'));
         } else {
            User::dropdown(array('value'  => $resa->fields["users_id"],
                                 'entity' => $item->getEntityID(),
                                 'right'  => 'all'));
         }
         echo "</td></tr>\n";
      }
      echo "<tr class='tab_bg_2'><td>".__('Start date')."</td><td>";
      Html::showDateTimeFormItem("begin", $resa->fields["begin"], -1, false);
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_2'><td>".__('End date')."</td><td>";
      Html::showDateTimeFormItem("end", $resa->fields["end"], -1, false);
      Alert::displayLastAlert('Reservation', $ID);
      echo "</td></tr>\n";

      if (empty($ID)) {
         echo "<tr class='tab_bg_2'><td>".__('Rehearsal')."&nbsp;:</td>";
         echo "<td>";
         echo "<select name='periodicity'>";
         echo "<option value='day'>".__('By day')."</option>\n";
         echo "<option value='week'>".__('By week')."</option>\n";
         echo "</select>";
         Dropdown::showInteger('periodicity_times', 1, 1, 60);
         echo "</td></tr>\n";
      }

      echo "<tr class='tab_bg_2'><td>".__('Comments')."</td>";
      echo "<td><textarea name='comment'rows='8' cols='30'>".$resa->fields["comment"]."</textarea>";
      echo "</td></tr>\n";

      if (empty($ID)) {
         echo "<tr class='tab_bg_2'>";
         echo "<td colspan='2' class='top center'>";
         echo "<input type='submit' name='add' value=\"".__s('Add')."\" class='submit'>";
         echo "</td></tr>\n";

      } else {
         echo "<tr class='tab_bg_2'>";
         echo "<td class='top center'>";
         echo "<input type='submit' name='delete' value=\"".__s('Purge')."\" class='submit'>";
         if ($resa->fields["group"] > 0) {
            echo "<br><input type='checkbox' name='_delete_group'>&nbsp;".__s('Delete all rehearsals');
         }
         echo "</td><td class='top center'>";
         echo "<input type='submit' name='update' value=\"".__s('Update')."\" class='submit'>";
         echo "</td></tr>\n";
      }
      echo "</table></form></div>\n";
   }


   /**
    * Display for reservation
    *
    * @param $ID ID a the reservation item (empty to show all)
    * @param $date date to display
   **/
   static function displayReservationDay($ID,$date) {
      global $DB;

      if (!empty($ID)) {
         self::displayReservationsForAnItem($ID, $date);

      } else {
         $debut = $date." 00:00:00";
         $fin   = $date." 23:59:59";

         $query = "SELECT DISTINCT `glpi_reservationitems`.`id`
                   FROM `glpi_reservationitems`
                   INNER JOIN `glpi_reservations`
                     ON (`glpi_reservationitems`.`id` = `glpi_reservations`.`reservationitems_id`)
                   WHERE `is_active` = '1'
                         AND '".$debut."' < `end`
                         AND '".$fin."' > `begin`
                   ORDER BY `begin`";
         $result = $DB->query($query);

         if ($DB->numrows($result)>0) {
            $m = new ReservationItem();
            while ($data=$DB->fetch_array($result)) {
               $m->getFromDB($data['id']);

               if (!($item = getItemForItemtype($m->fields["itemtype"]))) {
                  continue;
               }

               if ($item->getFromDB($m->fields["items_id"])
                  && Session::haveAccessToEntity($item->fields["entities_id"])) {

                  $typename = $item->getTypeName();

                  if ($m->fields["itemtype"] == 'Peripheral') {
                     if (isset($item->fields["peripheraltypes_id"])
                        && $item->fields["peripheraltypes_id"]!=0) {

                        $typename=Dropdown::getDropdownName("glpi_peripheraltypes",
                                                            $item->fields["peripheraltypes_id"]);
                     }
                  }

                  list($annee,$mois,$jour)=explode("-",$date);
                  echo "<tr class='tab_bg_1'><td>";
                  echo "<a href='reservation.php?reservationitems_id=".$data['id'].
                        "&amp;mois_courant=$mois&amp;annee_courante=$annee'>$typename - ".
                        $item->getName()."</a></td></tr>\n";
                  echo "<tr><td>";
                  self::displayReservationsForAnItem($data['id'],$date);
                  echo "</td></tr>\n";
               }
            }
         }
      }
   }


   /**
    * Display a reservation
    *
    * @param $ID ID a the reservation item
    * @param $date date to display
   **/
   static function displayReservationsForAnItem($ID,$date) {
      global $DB, $LANG;

      $users_id = Session::getLoginUserID();
      $resa     = new self();
      $user     = new User;
      list($year, $month, $day) = explode("-", $date);
      $debut    = $date." 00:00:00";
      $fin      = $date." 23:59:59";

      $query = "SELECT *
                FROM `glpi_reservations`
                WHERE '$debut' < `end`
                      AND '$fin' > `begin`
                      AND `reservationitems_id` = '$ID'
                ORDER BY `begin`";

      if ($result=$DB->query($query)) {
         if ($DB->numrows($result)>0) {
            echo "<table width='100%'>";
            while ($row=$DB->fetch_array($result)) {
               echo "<tr>";
               $user->getFromDB($row["users_id"]);
               $display = "";

               if ($debut>$row['begin']) {
                  $heure_debut = "00:00";
               } else {
                  $heure_debut = get_hour_from_sql($row['begin']);
               }

               if ($fin<$row['end']) {
                  $heure_fin = "24:00";
               } else {
                  $heure_fin = get_hour_from_sql($row['end']);
               }

               if (strcmp($heure_debut,"00:00")==0 && strcmp($heure_fin,"24:00")==0) {
                  $display = $LANG['planning'][5];

               } else if (strcmp($heure_debut,"00:00")==0) {
                  $display = sprintf(__('To %s'), $heure_fin);

               } else if (strcmp($heure_fin,"24:00")==0) {
                  $display = sprintf(__('From %s'), $heure_debut);

               } else {
                  $display = $heure_debut."-".$heure_fin;
               }

               $rand  = mt_rand();
               $modif = $modif_end = "";
               if ($resa->can($row['id'],"w")) {
                  $modif = "<a id='content_".$ID.$rand."'
                             href='reservation.form.php?id=".$row['id']."'>";
                  $modif_end = "</a>";
                  $modif_end .= Html::showToolTip($row["comment"],
                                                  array('applyto' => "content_".$ID.$rand,
                                                        'display' => false));
               }

               echo "<td class='tab_resa center'>". $modif."<span>".$display."<br><span class='b'>".
               formatUserName($user->fields["id"], $user->fields["name"], $user->fields["realname"],
                              $user->fields["firstname"]);
               echo "</span></span>";
               echo $modif_end;
               echo "</td></tr>\n";
            }
            echo "</table>\n";
         }
      }
   }


   /**
    * Display reservations for an item
    *
    * @param $item CommonDBTM object for which the reservation tab need to be displayed
    * @param $withtemplate integer : withtemplate param
   **/
   static function showForItem(CommonDBTM $item, $withtemplate='') {
      global $DB, $LANG, $CFG_GLPI;

      $resaID = 0;
      if (!Session::haveRight("reservation_central", "r")) {
         return false;
      }

      echo "<div class='firstbloc'>";
      ReservationItem::showActivationFormForItem($item);

      $ri = new ReservationItem();
      if ($ri->getFromDBbyItem($item->getType(),$item->getID())) {
         $now = $_SESSION["glpi_currenttime"];

         // Print reservation in progress
         $query = "SELECT *
                   FROM `glpi_reservations`
                   WHERE `end` > '$now'
                         AND `reservationitems_id` = '".$ri->fields['id']."'
                   ORDER BY `begin`";
         $result = $DB->query($query);

         echo "<table class='tab_cadre_fixehov'><tr><th colspan='5'>";

         if ($ri->fields["is_active"]) {
            echo "<a href='".$CFG_GLPI["root_doc"]."/front/reservation.php?reservationitems_id=".
                   $ri->fields['id']."' >".__('Current and future reservations')."</a>";
         } else {
            _e('Current and future reservations');
         }
         echo "</th></tr>\n";

         if ($DB->numrows($result) == 0) {
            echo "<tr class='tab_bg_2'>";
            echo "<td class='center' colspan='5'>".__('No reservation')."</td></tr>\n";

         } else {
            echo "<tr><th>".__('Start date')."</th>";
            echo "<th>".__('End date')."</th>";
            echo "<th>".$LANG['common'][95]."</th>";
            echo "<th>".__('Comments')."</th><th>&nbsp;</th></tr>\n";

            while ($data=$DB->fetch_assoc($result)) {
               echo "<tr class='tab_bg_2'>";
               echo "<td class='center'>".Html::convDateTime($data["begin"])."</td>";
               echo "<td class='center'>".Html::convDateTime($data["end"])."</td>";
               echo "<td class='center'>";
               echo "<a href='".$CFG_GLPI["root_doc"]."/front/user.form.php?id=".$data["users_id"]."'>".
                     getUserName($data["users_id"])."</a></td>";
               echo "<td class='center'>".nl2br($data["comment"])."</td>";
               echo "<td class='center'>";
               list($annee, $mois, $jour) = explode("-", $data["begin"]);
               echo "<a href='".$CFG_GLPI["root_doc"]."/front/reservation.php?reservationitems_id=".
                     $ri->fields['id']."&amp;mois_courant=$mois&amp;annee_courante=$annee' title=\"".
                     __s('See planning')."\">";
               echo "<img src=\"".$CFG_GLPI["root_doc"]."/pics/reservation-3.png\" alt='' title=''></a>";
               echo "</td></tr>\n";
            }
         }
         echo "</table></div>\n";

         // Print old reservations
         $query = "SELECT *
                   FROM `glpi_reservations`
                   WHERE `end` <= '$now'
                         AND `reservationitems_id` = '".$ri->fields['id']."'
                   ORDER BY `begin` DESC";
         $result = $DB->query($query);

         echo "<div class='spaced'><table class='tab_cadre_fixehov'><tr><th colspan='5'>";

         if ($ri->fields["is_active"]) {
            echo "<a href='".$CFG_GLPI["root_doc"]."/front/reservation.php?reservationitems_id=".
                   $ri->fields['id']."' >".__('Past reservations')."</a>";
         } else {
            _e('Past reservations');
         }
         echo "</th></tr>\n";

         if ($DB->numrows($result)==0) {
            echo "<tr class='tab_bg_2'>";
            echo "<td class='center' colspan='5'>".__('No reservation')."</td></tr>\n";

         } else {
            echo "<tr><th>".__('Start date')."</th>";
            echo "<th>".__('End date')."</th>";
            echo "<th>".$LANG['common'][95]."</th>";
            echo "<th>".__('Comments')."</th><th>&nbsp;</th></tr>\n";

            while ($data=$DB->fetch_assoc($result)) {
               echo "<tr class='tab_bg_2'>";
               echo "<td class='center'>".Html::convDateTime($data["begin"])."</td>";
               echo "<td class='center'>".Html::convDateTime($data["end"])."</td>";
               echo "<td class='center'>";
               echo "<a href='".$CFG_GLPI["root_doc"]."/front/user.form.php?id=".$data["users_id"]."'>".
                     getUserName($data["users_id"])."</a></td>";
               echo "<td class='center'>".nl2br($data["comment"])."</td>";
               echo "<td class='center'>";
               list($annee, $mois ,$jour) = explode("-", $data["begin"]);
               echo "<a href='".$CFG_GLPI["root_doc"]."/front/reservation.php?reservationitems_id=".
                     $ri->fields['id']."&amp;mois_courant=$mois&amp;annee_courante=$annee' title=\"".
                     __s('See planning')."\">";
               echo "<img src=\"".$CFG_GLPI["root_doc"]."/pics/reservation-3.png\" alt='' title=''>";
               echo "</a></td></tr>\n";
            }
         }
         echo "</table>\n";
      }
      echo "</div>\n";
   }


   /**
    * Display reservations for an user
    *
    * @param $ID ID a the user
   **/
   static function showForUser($ID) {
      global $DB, $LANG, $CFG_GLPI;

      $resaID = 0;

      if (!Session::haveRight("reservation_central", "r")) {
         return false;
      }

      echo "<div class='firstbloc'>";
      $now = $_SESSION["glpi_currenttime"];

      // Print reservation in progress
      $query = "SELECT *
                FROM `glpi_reservations`
                WHERE `end` > '$now'
                      AND `users_id` = '$ID'
                ORDER BY `begin`";
      $result = $DB->query($query);

      $ri = new ReservationItem();
      echo "<table class='tab_cadre_fixehov'>";
      echo "<tr><th colspan='6'>".__('Current and future reservations')."</th></tr>\n";

      if ($DB->numrows($result)==0) {
         echo "<tr class='tab_bg_2'>";
         echo "<td class='center' colspan='6'>".__('No reservation')."</td></tr\n>";

      } else {
         echo "<tr><th>".__('Start date')."</th>";
         echo "<th>".__('End date')."</th>";
         echo "<th>".__('Item')."</th>";
         echo "<th>".$LANG['common'][95]."</th>";
         echo "<th>".__('Comments')."</th><th>&nbsp;</th></tr>\n";

         while ($data=$DB->fetch_assoc($result)) {
            echo "<tr class='tab_bg_2'>";
            echo "<td class='center'>".Html::convDateTime($data["begin"])."</td>";
            echo "<td class='center'>".Html::convDateTime($data["end"])."</td>";

            if ($ri->getFromDB($data["reservationitems_id"])) {
               $link = "&nbsp;";

               if ($item = getItemForItemtype($ri->fields['itemtype'])) {
                  if ($item->getFromDB($ri->fields['items_id'])) {
                     $link = $item->getLink();
                  }
               }
               echo "<td class='center'>$link</td>";

            } else {
               echo "<td class='center'>&nbsp;</td>";
            }

            echo "<td class='center'>".getUserName($data["users_id"])."</td>";
            echo "<td class='center'>".nl2br($data["comment"])."</td>";
            echo "<td class='center'>";
            list($annee, $mois, $jour) = explode("-", $data["begin"]);
            echo "<a href='".$CFG_GLPI["root_doc"]."/front/reservation.php?reservationitems_id=".
                  $data["reservationitems_id"]."&amp;mois_courant=$mois&amp;".
                  "annee_courante=$annee' title=\"".__s('See planning')."\"><img src=\"".
                  $CFG_GLPI["root_doc"]."/pics/reservation-3.png\" alt='' title=''></a>";
            echo "</td></tr>\n";
         }
      }
      echo "</table></div>\n";

      // Print old reservations
      $query = "SELECT *
                FROM `glpi_reservations`
                WHERE `end` <= '$now'
                      AND `users_id` = '$ID'
                ORDER BY `begin` DESC";
      $result = $DB->query($query);

      echo "<div class='spaced'>";
      echo "<table class='tab_cadre_fixehov'>";
      echo "<tr><th colspan='6'>".__('Past reservations')."</th></tr>\n";

      if ($DB->numrows($result)==0) {
         echo "<tr class='tab_bg_2'>";
         echo "<td class='center' colspan='6'>".__('No reservation')."</td></tr>\n";

      } else {
         echo "<tr><th>".__('Start date')."</th>";
         echo "<th>".__('End date')."</th>";
         echo "<th>".__('Item')."</th>";
         echo "<th>".$LANG['common'][95]."</th>";
         echo "<th>".__('Comments')."</th><th>&nbsp;</th></tr>\n";

         while ($data=$DB->fetch_assoc($result)) {
            echo "<tr class='tab_bg_2'>";
            echo "<td class='center'>".Html::convDateTime($data["begin"])."</td>";
            echo "<td class='center'>".Html::convDateTime($data["end"])."</td>";

            if ($ri->getFromDB($data["reservationitems_id"])) {
               $link = "&nbsp;";

               if ($item = getItemForItemtype($ri->fields['itemtype'])) {
                  if ($item->getFromDB($ri->fields['items_id'])) {
                     $link=$item->getLink();
                  }
               }
               echo "<td class='center'>$link</td>";

            } else {
               echo "<td class='center'>&nbsp;</td>";
            }

            echo "<td class='center'>".getUserName($data["users_id"])."</td>";
            echo "<td class='center'>".nl2br($data["comment"])."</td>";
            echo "<td class='center'>";
            list($annee, $mois, $jour) = explode("-", $data["begin"]);
            echo "<a href='".$CFG_GLPI["root_doc"]."/front/reservation.php?reservationitems_id=".
                  $data["reservationitems_id"]."&amp;mois_courant=$mois&amp;annee_courante=$annee' ".
                  "title=\"".__s('See planning')."\">";
            echo "<img src='".$CFG_GLPI["root_doc"]."/pics/reservation-3.png' alt='' title=''></a>";
            echo "</td></tr>\n";
         }
      }
      echo "</table></div>\n";
   }


}

?>
