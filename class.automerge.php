<?php
// [agc_vim\] vim:fenc=utf-8:ft=php:ai:si:ts=2:sw=2:et:nu:fdm=indent:fdn=1:
// [/agc_vim] 
/**
* @file
*   Work in progrogress
*   @author Erich Schulz
*   given to the universe under the same licence as CiviCRM
*/


/**
 * Class to automatically merge known duplicate contacts.
 *
 * The goal of these functions is to be simple but safe.
 * Complex merges are rejected ('blocked') and will need handling through the UI
 *
 * The primary interfaces into the class are:
 *  - reportHtml() - start here when trying to understand what the class does
 *  - autoMergeSQL() - generate either the SQL to perform a merge, or a report explaining why the automerge is unsafe
 *
 *  getContactKeyFields() scans the database to build a list of columns referencing contact_id
 *    - using the table information_schema.COLUMNS and
 *    - civicrm_custom_group to exclude entiity_id references not pointing to contacts
 *
 *  - the handling of records containing the other ID in these columns is specified by 
 *    - ignore_list: do nothing with these columns
 *    - delete_list: columns to trigger deleting of the record
 *    - update_list: columns to transfer from contact_id b -> a
 *    - examine_list: columns identifying records needing field by field comparision as defined by columnAutomergeBehaviour()
 *
 * WARNINGS:
 *  1. Unless explicitly stated, the public functions defined in this class are NOT injection attack safe.
 *  2. This class does NOT check user permissions.
 *  3. see userWarning()
 * 
 * If the automerge class finds data it doesn't understand it 'blocks' the automerge.
 * The purpose of this blocking is to trigger a traditional human overseen merge process, ie
 * blocking an automerge doesn't mean two concepts cannot be merged, but merely that the process requires human oversight.
 *
 * TODO:  Define the automerge behaviour of tables and fields in the datadictionary then...
 * TODO:  Rewrite the *_list functions and the columnAutomergeBehaviour functions to read the datadictionary 
 * TODO:  Rewrite schema() to use correct setting 
 * TODO:  db_query needs to be replaced with civicrm equivilant!
 * TODO:  ideally this should be undoable - achievable if a list of updates is made and stored as a note attached to the 'deleted' contact
 */
class AgcAutoMerge {

  /**
   * Give user-oriented warning report 
   * @returns string html
   */
  static function userWarning() {
    return 'Make sure the records you are about to automatically merge true duplicates!!  The checks this function performs only concern the feasability of automatically merging without loss of valuable data - the checks are NOT about verifying you have a true match.';
  }

  /**
   * Give a detailed report on the proposed merger of two contacts.
   * This function's role is to support debugging and development of the AutoMerge class.
   * Injection attack safe but does not check permissions.
   * @param $mainID integer contact_id of record to keep (injection attack safe)
   * @param $otherId integer contact_id of record to be assimilated (injection attack safe)
   * @returns string html
   */
  static function reportHtml($mainId, $otherId) {
    $keep = (int)$mainId;
    $lose = (int)$otherId;
    return AgcAutoMerge::explainPlanHtml($keep, $lose) . 
      '<p>(The above explains how the folowing plan was constructed).</p></hr>' .
      AgcAutoMerge::showPlanHtml($keep, $lose) .
      "<h4>Settings</h4>" . // show configuration
        json_encode(AgcAutoMerge::settings());
  } 
  
  /**
   * Explore and explain the logic behind the proposed merger of two contacts.
   * This function's role is to support debugging and development of the AutoMerge class.
   * Injection attack safe but does not check permissions.
   * @param $mainID integer contact_id of record to keep (injection attack safe)
   * @param $otherId integer contact_id of record to be assimilated (injection attack safe)
   * @returns string html
   */
  static function explainPlanHtml($mainId, $otherId) {
    $keep = (int)$mainId;
    $lose = (int)$otherId;
    $html = "<h3>List of tables containing $lose</h3>";
    $fields =  AgcAutoMerge::locate($lose);
    $html .= AgcAutoMerge::arrayFormat($fields,'sol');
    $plan =  AgcAutoMerge::tablePlan($lose);
    $html .= "<h3>Planning merge of $lose into $keep</h3>";
    if ($plan['examine']) {
      $html .= "<h4>Tables to examine field by field</h4>" . AgcAutoMerge::arrayFormat($plan['examine'],'sol');
      foreach ($plan['examine'] as $key) {
        $table = AgcAutoMerge::tableFromColumnRef($key); // = schema.table
        $field = AgcAutoMerge::field($key); // = field_name
        $html .= "<h5>Examining table: $table</h5>" .
          AgcAutoMerge::arrayFormat(
            AgcAutoMerge::examineRecord($table, $field, $keep, $lose),'sul');
        // report on actual function used to do the final check within autoMergeSQL()
        $final_check = AgcAutoMerge::CheckTwoRecordsForBlocksSQL($table,$field, $keep, $lose);
        $html .= "<p>In summary: " .($final_check ? $final_check : "no blocks found on examination of $table") .  "</p>";
      }
    }
    if ($plan['update']) {
      $html .= "<h4>Tables to update (move to kept record)</h4>" .
          AgcAutoMerge::arrayFormat($plan['update'],'sol');
    }
    if ($plan['delete']) {
      $html .= "<h4>Delete records in these tables</h4>" . AgcAutoMerge::arrayFormat($plan['delete'],'sol');
    }
    if ($plan['blockers']) {
      $html .= "<h4>Tables containing records blocking merge</h4>" . AgcAutoMerge::arrayFormat($plan['blockers'],'sol').'<hr>';
    }
  return $html;
  }

  /**
   * Tests to see there are no blocks to automatically merging two contacts, and reports the SQL to perform the merge.
   * This function's role is to support debugging and development of the AutoMerge class.
   * Injection attack safe but does not check permissions.
   * @param $mainID integer contact_id of record to keep (injection attack safe)
   * @param $otherId integer contact_id of record to be assimilated (injection attack safe)
   * @returns string html
   */
  static function showPlanHtml($mainId, $otherId) {
    $keep = (int)$mainId;
    $lose = (int)$otherId;
    $html = "<h3>Planned merger of $lose into $keep</h3>";
    $autoMergeSQL =  AgcAutoMerge::autoMergeSQL($keep, $lose);
    if ($autoMergeSQL['is_error']) {
      $html .= "<h4>Error</h4><p class='error'>$autoMergeSQL[error_message]</p><p>$autoMergeSQL[report]</p>";
    } else {
      $html .= "<h4>SQL:</h4>" . AgcAutoMerge::arrayFormat($autoMergeSQL['sql']);
    }
    return $html;
  }

  /**
   * Attempt to produce SQL to automaticlly merge two contacts.
   * todo - check both recordes exist and are not deleted
   * @param $mainID integer contact_id of record to keep (injection attack safe)
   * @param $otherId integer contact_id of record to be assimilated (injection attack safe)
   * @uses tablePlan()
   * @return array result including SQL
   */
  static public function autoMergeSQL($mainId, $otherId) {
    $schema = AgcAutoMerge::schema();
    $keep = (int)$mainId;
    $lose = (int)$otherId;
    $result = array();
    $report = '';
    $n = AgcAutoMerge::sqlToSingleValue(
      "SELECT count(*) AS value FROM $schema.civicrm_contact WHERE is_deleted = 0 AND id IN ($keep, $lose);");
    switch ($n) {
      case 2:
        // formulate a table-level plan for merging the contacts:
        $plan = AgcAutoMerge::tablePlan($otherId);
        if ($plan['blockers']) { // records found that are either in unknown tables or tables that need manual review
          $result['is_error'] = 1;
          $result['error_message']='Sorry, unable to automatically merge.';
          $report.= "Found references to $otherId in the following table(s): " . 
            implode(', ', $plan['blockers']) . '.';
        } else { // no blocking records found
          $sql = array();
          foreach ($plan['examine'] as $key) {
            $table = AgcAutoMerge::tableFromColumnRef($key); // = schema.table
            $field = AgcAutoMerge::field($key); // = field_name
            $blockers = AgcAutoMerge::CheckTwoRecordsForBlocksSQL($table,$field, $keep, $lose);
            if ($blockers) {
              $result['is_error'] = 1;
              $result['error_message']='Sorry, unable to automatically merge.';
              $report.= "Incompatible fields found in $table ($blockers)";
            }
          }
          foreach ($plan['update'] as $key) {
            $table = AgcAutoMerge::tableFromColumnRef($key); // = schema.table
            $short_table_name = AgcAutoMerge::tableFromTableRef($table); // drop schema name from table reference
            $updates = AgcAutoMerge::tableAutomergeUpdates($short_table_name);
            if ($updates) {
              $updates = ", $updates";
            }
            $field = AgcAutoMerge::field($key); // = field_name
            $sql[] = "UPDATE $table SET $field = $keep$updates WHERE $field = $lose;";
          }
          foreach ($plan['delete'] as $key) {
            $table = AgcAutoMerge::tableFromColumnRef($key); // = schema.table
            $field = AgcAutoMerge::field($key); // = field_name
            $sql[] = "DELETE FROM $table WHERE $field = $lose;";
          }
          $sql[]="UPDATE $schema.civicrm_contact SET is_deleted = 1 WHERE $field = $lose;";
          $result['is_error'] = 0;
          $result['sql'] = $sql;
        }
        break;
      case 1:
        $result['is_error'] = 1;
        $result['error_message']='Only able to find one of these contacts';
        break;
      default:
        $result['is_error'] = 1;
        $result['error_message']='Neither of these contacts located';
        break;
    }
    $result['report'] = $report;
    return $result;
  }

 /**
  * Produces a list of settings. 
  * These settings change the behaviour of this class and should be viewable by the user.
  * @return array
  */
  public function settings() {
    return array( 
      'userWarning' => AgcAutoMerge::userWarning(),
      'schema' => AgcAutoMerge::schema(),
      'includeColumnsSQL' => AgcAutoMerge::includeColumnsSQL(),
      'ignoreColumnsSQL' => AgcAutoMerge::ignoreColumnsSQL(),
      'excludeEntitiesSQLList' => AgcAutoMerge::excludeEntitiesSQLList(),
    );
  }

 /**
  * Database schema we are deduping
  * @return string
  */
  public function schema() {
    return 'au_drupal';  //fixme this should look up correct setting
  }

  /**
  * Pattern matching criteria for columns to include in initial scan of schema 
  * fixme: this data belongs as a configurable core setting
  * @return string SQL clause to filter tables from a scan of the database schema
  */
  public function includeColumnsSQL() { 
    return "((`COLUMN_NAME` LIKE '%contact_id%') OR (`COLUMN_NAME` LIKE '%entity_id%') OR (`COLUMN_NAME` = '%employer_id%'))";
  }
  /**
  * Pattern matching criteria for columns to ignore completely while auto deduping.
  * fixme: this data belongs as a configurable core setting
  * @return string SQL clause to filter tables from a scan of the database schema
  */
  public function ignoreColumnsSQL() { 
    return "(TABLE_NAME LIKE '%_cache') OR (TABLE_NAME LIKE 'temp_%') OR (TABLE_NAME LIKE 'civicrm_import_job_%')";
  }
  /**
  * List of entities to ignore completely while auto deduping.
  * fixme: this data belongs as a configurable core setting
  * @return string single quoted, comma separated list of CiviCRM entities that are not contacts
  */
  public function excludeEntitiesSQLList() {
    return "'Location','Address','Contribution','Activity', 'Relationship','Group', 'Membership','Participant','Event','Grant','Pledge','Case'";
  }
  /**
  * List of columns to ignore completely while auto deduping.
  * Any reference to redundant contacts are left alone.
  * fixme: this data belongs in the core civicrm datadictionary and should be read from there.
  * @return array of (schema_name).(table name).(key field) 
  */
  public function ignore_list($schema) { 
    return array(
      "$schema.agc_known_duplicates.contact_id_b",
      "$schema.civicrm_value_electorates.entity_id",
      "$schema.ems_geocode_addr_electorate.contact_id",
      "$schema.ems_regeocode.contact_id",
      "$schema.agc_known_duplicates.contact_id_a",
      "$schema.civicrm_log.entity_id", 
    );
  }
 /**
  * List of columns where a duplicate id should trigger a record delete during automated merging
  * fixme: this data belongs in the core civicrm datadictionary and should be read from there.
  *
  * @return array of (schema_name).(table name).(key field) 
  */
  public function delete_list($schema) { 
    return array(
      "$schema.agc_intray_matches.contact_id",
    );
  }
 /**
  * List of columns that it is safe to update during an automated merge:
  * fixme: this data belongs in the core civicrm datadictionary and should be read from there.
  * @return array of (schema_name).(table name).(key field) 
  */
  public function update_list($schema) { 
    return array(
      "$schema.civicrm_activity.source_contact_id",
      "$schema.civicrm_activity_target.target_contact_id",
      "$schema.civicrm_email.contact_id",
      "$schema.civicrm_entity_tag.entity_id",
      "$schema.civicrm_group_contact.contact_id",
      "$schema.civicrm_mailing_event_queue.contact_id",
      "$schema.civicrm_participant.contact_id",
      "$schema.civicrm_subscription_history.contact_id",
      "$schema.civicrm_activity_assignment.assignee_contact_id",
      "$schema.civicrm_address.contact_id",
      "$schema.civicrm_contribution.contact_id",
      "$schema.civicrm_dashboard_contact.contact_id",
      "$schema.civicrm_mailing_event_subscribe.contact_id",
      "$schema.civicrm_note.contact_id",
      "$schema.civicrm_phone.contact_id",
      "$schema.civicrm_preferences.contact_id",
      "$schema.civicrm_uf_match.contact_id",
      "$schema.civicrm_value_fundraising_appeals_59.contact_id_298",
      "$schema.ems_geocode_addr_electorate.contact_id  ",
    );

  }
 /**
  * List of key columns where the entire record needs field by field examination  
  * fixme: this data belongs in the core civicrm datadictionary and should be read from there.
  * @return array of (schema_name).(table name).(key field) 
  */
  public function examine_list($schema) { 
    return array(
      "$schema.civicrm_contact.id"
    );
  }

 /**
  * Make SQL to compare two proposed automerge records and report blocking fields:
  * @return string SQL that returns a single value listing any blocking columns or NULL if no blocks
  */
  public function CheckTwoRecordsForBlocksSQL($table, $key_field, $ida, $idb) { 
    $short_table_name = AgcAutoMerge::tableFromTableRef($table); // drop schema name from table reference
    $columns = AgcAutoMerge::Describe($short_table_name);
    $sql = "SELECT CONCAT_WS(', ',\n";
    foreach ($columns as $column) {
      $behaviour = AgcAutoMerge::columnAutomergeBehaviour($short_table_name, $column);
      $sql .= '  IF ('.AgcAutoMerge::automergeSuitablilityTestSQL($short_table_name, $column, $behaviour) . 
        ", CONCAT('$column /*',ca.$column,'-',cb.$column,'*/'), NULL),\n";
    }
    $sql .= "  null) AS value
    FROM $table AS ca, $table AS cb 
    WHERE ca.$key_field = $ida AND cb.$key_field = $idb;";
    $value = AgcAutoMerge::sqlToSingleValue($sql);
    return $value;
  }

 /**
  * Examines two proposed automerge records and report blocking fields:
  * The purpose of this function is poorly to allow debugging and testing of merge behaviour.
  * @return array of field reports: result name [valueA]-[valueB] (test used)
  */
  public function examineRecord($table, $key_field, $ida, $idb) { 
    $short_table_name = AgcAutoMerge::tableFromTableRef($table); // drop schema name from table reference
    $columns = AgcAutoMerge::Describe($short_table_name);
    $sql = "SELECT ";
    $columnsSQL = array();
    foreach ($columns as $c) {
      $behaviour = AgcAutoMerge::columnAutomergeBehaviour($short_table_name, $c);
      $test = AgcAutoMerge::automergeSuitablilityTestSQL($short_table_name, $c, $behaviour);
      $columnsSQL[] = " CONCAT_WS('', IF ($test, 'blocked', 'mergeable'), ' $c: [',ca.$c,']-[',cb.$c,'] ($behaviour)') AS $c";
    }
    $sql .= implode(', ', $columnsSQL);
    $sql .= " FROM $table AS ca, $table AS cb WHERE ca.$key_field = $ida AND cb.$key_field = $idb;";
    $res = db_query($sql);
    $db_row =  db_fetch_array($res);
    return $db_row;
  }

/**
 * SQL clause to tests if automerging values prevents safe automerge
 * These clauses return TRUE if the values block automerger.
 * Tables to compare are aliased by 'ca' and 'cb'.
 * @see columnAutomergeBehaviour()
 * @returns string SQL clause evaluating to TRUE or FALSE if column fails automerge suitability test
 */
  function automergeSuitablilityTestSQL($table, $column, $behaviour) {
    $verbose = false; // set true to put debugging comments into SQL
    $emailRE = '^[0-9a-z_\.-]+@(([0-9]{1,3}\.){3}[0-9]{1,3}|([0-9a-z][0-9a-z-]*[0-9a-z]\.)+[a-z]{2,3})$';
    switch ($behaviour) {
      case 'BlockOnDifferentValue':
        $sql="IFNULL(cb.$column,'')<>'' AND IFNULL(cb.$column,'')<>IFNULL(ca.$column,'')";
        break;
      case 'BlockIfGreater':
        $sql="IFNULL(cb.$column,0)>IFNULL(ca.$column,0)";
        break;
      case 'IgnoreTruncation':
        $sql="IFNULL(ca.$column,'') NOT LIKE CONCAT(IFNULL(cb.$column,''),'%')";
        break;
      case 'IgnoreEMailOrTruncation':
        // some fields may contain the emial address by default. This is pretty worthless so shouldn't force an automerge
        $sql="IFNULL(cb.$column,'')<>'' AND IFNULL(cb.$column,'')<>IFNULL(ca.$column,'') AND cb.$column NOT REGEXP '$emailRE'";
        break;
      case 'Ignore':
        $sql="FALSE";
        break;
    }
    return ($verbose ? "/* $table.$column: $behaviour */\n" : '') . $sql . "\n";
  }
/**
 * Determine effect of civicrm_contact columns on an attempted auto merge
 * If encounters an unknown table or column returns the value 'BlockOnDifferentValue'
 * fixme: this data belongs in the core civicrm datadictionary and should be read from there.
 * @returns string 
 */
  function columnAutomergeBehaviour($table, $column) {
    switch ($table) {
      //    civicrm_contact
      case 'civicrm_contact':
      switch ($column) {
        case 'do_not_email':
        case 'do_not_phone':
        case 'is_opt_out':
        case 'do_not_sms':
        case 'do_not_trade':
          $behaviour = 'BlockIfGreater';
          break;
        case 'first_name':
        case 'middle_name':
        case 'last_name':
          $behaviour = 'IgnoreTruncation';
          break;
        //case 'sort_name':
        //case 'display_name':
          $behaviour = 'IgnoreEMailOrTruncation';
          break;
        case 'sort_name': // todo consider making more sophisticate opotion to compare for hand-crafted values
        case 'display_name': //todo see above
        case 'hash':
        case 'id':
          $behaviour = 'Ignore';
          break;
        case 'legal_identifier':
        case 'contact_sub_type':
        case 'is_deceased':
        default:
          $behaviour = 'BlockOnDifferentValue';
        }
        break;
      default:
        $behaviour = "unknown table: $table.$column";
         break;
    }
    return $behaviour;
  }

/**
 * Provides an update clause for a table
 * fixme: this data belongs in the core civicrm datadictionary and should be read from there.
 * @returns string 
 */
  function tableAutomergeUpdates($table) {
    switch ($table) {
      case "civicrm_email":
      case "civicrm_address":
      case "civicrm_phone":
        $updates = 'is_primary = 0, is_billing = 0';
        break;
      default:
        $updates = '';
         break;
    }
    return $updates;
  }




  /**
   * Access the information_schema to list the columns in a table
   * 
   * @uses AgcAutoMerge::schema to determine the database schema to look in
   * @param string $table Database table name
   * @return array of (field) 
   */
  static public function Describe($table) {
    $schema = AgcAutoMerge::schema();
    // 1 find tables with 'contact_id' as a field
    $sql = <<<sql
      SELECT COLUMN_NAME as value
      FROM information_schema.COLUMNS
      WHERE `TABLE_NAME` = '$table'
        AND `TABLE_SCHEMA` = '$schema'
sql;
    return AgcAutoMerge::sqlToArray($sql);
  }

  /**
   * Access the information_schema to pull out all fields with contacts
   * 
   * This function supports deduping by providing a complete list
   * of all tables in the schema - even if CiviCRM does not know about them!
   * It includes all table.column containing contact_id or entity_id, except:
   *    - custom civitable relating to a non-contact id
   *    - columns returned by ignore_list()
   *    - tables defined by ignoreColumnsSQL() 
   * Assumes all data is found in a single schema.
   * @uses AgcAutoMerge::schema to determine the database schema to look in
   * @param boolean $clear_cache maybe useful if adding tables or testing
   * @uses $_SESSION to cache results
   * @return array of (schema_name).(table name).(key field) 
   */
  static public function getContactKeyFields($clear_cache = false) {
    // check for cached table list:
    if ($clear_cache || !array_key_exists('_agc.dedupefields',$_SESSION)) {
      $schema = AgcAutoMerge::schema();
      $includeColumnsSQL = AgcAutoMerge::includeColumnsSQL();
      $ignoreColumnsSQL = AgcAutoMerge::ignoreColumnsSQL();
      $excludeEntitiesSQLList = AgcAutoMerge::excludeEntitiesSQLList();
      // 1 find tables with 'contact_id' as a field
      // todo verify this sql finds all foreign keys into civicrm_contact.id
      // and tables with 'entity_id' as a field
      $sql = <<<sql
        SELECT concat(
          `TABLE_SCHEMA`, '.',
          `TABLE_NAME`, '.',
          `COLUMN_NAME`) as value
        FROM information_schema.COLUMNS
        WHERE ($includeColumnsSQL) 
          AND NOT ($ignoreColumnsSQL)
          AND `TABLE_SCHEMA` = '$schema'
sql;
      $FieldList['includes'] = AgcAutoMerge::sqlToArray($sql);
      // add in the root table:
      $FieldList['includes'][] = "$schema.civicrm_contact.id";
      // 3 remove civi custom tables where entity_id is not a contact
      $sql = <<<sql
        SELECT concat('$schema.',`table_name`, '.entity_id') as value
        FROM au_drupal.civicrm_custom_group
        WHERE `extends` IN ($excludeEntitiesSQLList)
sql;
      $FieldList['excludes'] = AgcAutoMerge::sqlToArray($sql);
      $_SESSION['_agc.dedupefields'] = 
        array_diff( $FieldList['includes'], 
          $FieldList['excludes'] , AgcAutoMerge::ignore_list($schema));
    }
    return $_SESSION['_agc.dedupefields']; 
  }
   /**
   * Parse (schema_name).(table name) in (table name)
   * @param string '(schema_name).(table name)' or '(table name)'
   * @return string '(table name)'
   */
  static public function tableFromTableRef($long_table_name) {
      $split = explode('.',$long_table_name);
      return array_pop($split); // = table
  }
   /**
   * Parse long form column name into table name
   * @param string column '(schema_name).(table name).(field)' 
   * @return string '(schema_name).(table name)'
   */
  static public function tableFromColumnRef($long_column_name) {
      $split = explode('.',$long_column_name);
      return $split[0].'.'.$split[1]; // = schema.table
  }
  
   /**
   * Parse long form column name into field
   * @param string column '(schema_name).(table name).(field)' 
   * @return string '(field)' 
   */
  static public function field($long_column_name) {
      $split = explode('.',$long_column_name);
      return $split[2]; // = field 
  }
  
  /**
   * Produce a list of columns where a given contact_id is found 
   * 
   * @uses getFieldList() to identify field to scan in db schema
   * @return array of (schema_name).(table name).(key field) 
   */
  static public function locate($contact_id) {
    $keys = AgcAutoMerge::getContactKeyFields(true);
    $locations = array(); // columns $contact_id is found
    foreach ($keys as $key) {
      $table = AgcAutoMerge::tableFromColumnRef($key); // = schema.table
      $field = AgcAutoMerge::field($key); // = field_name
      $sql = 
        "SELECT count(*) AS value FROM $table WHERE $field=".(int)$contact_id.';';
      $n=AgcAutoMerge::sqlToSingleValue($sql);
      if ($n) { // contact found!
        $locations[] = $key;
      }
    }
    return $locations;
  }

  /**
   * Convert SQL to a single value 
   * @param string $sql with field 'value'
   * @return string|number
   */
  static protected function sqlToSingleValue($sql) {
    $res=db_query($sql);
    $db_row =  db_fetch_object($res);
    return $db_row->value;
  }
  /**
   * Convert SQL into a one dimension array with numeric keys
   * @param string $sql with field and 'value'
   * @return array
   */
  static protected function sqlToArray($sql) {
    $res=db_query($sql);
    $oneD = array();
    while ( $db_row =  db_fetch_object($res)) {
      $oneD[] = $db_row->value;
    }
    return $oneD;
  }

  /*
   * Produces a plan for merging contact, and identifies some of the blocks to an autodedupe
   * First scans the database for references to the contact then classifies 
   * those references with:
   *  - delete_list()
   *  - update_list()
   *  - examine_list()
   * 
   * Any references to the contact that do not have a plan are added to the 
   * blocking list.
   * 
   * @return array with 3 elements: delete, update and blockers
   */
  static public function tablePlan($contact_id) {
    $schema = AgcAutoMerge::schema();
    $plan = array();
    $locations = AgcAutoMerge::locate($contact_id);
    // classify locations into either for updating or deleting
    $plan['delete'] = array_intersect($locations, AgcAutoMerge::delete_list($schema));
    $plan['update'] = array_intersect($locations, AgcAutoMerge::update_list($schema));
    $plan['examine'] = array_intersect($locations, AgcAutoMerge::examine_list($schema));
    // any column that doesn't have a plan blocks automerging
    $plan['blockers'] = array_diff($locations, $plan['delete'],$plan['update'], $plan['examine']);
    return $plan;
  }

   /**
   * Format a one dimension php array into various styles depending on mode:
   * 	- sol - simple ordered list
   * 	- sul - simple unordered list
   * @param array $a 
   * @param string $mode 
   * @return string html 
   */
  static public function arrayFormat($a, $mode='sul') {
    if (!$a) {
      return ''; // handle empty value
    }
    switch ($mode) {
      case 'sul':
      case 'sol':
        $t=($mode=='sul' ? 'u' : 'o');
        return "<{$t}l><li>".implode($a,'</li><li>')."</li></{$t}l>";
        break;
      default:
        return 'bad mode in agchtml::arrayFormat';}
  }
}


