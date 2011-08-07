<?php
// [agc_vim\] vim:fenc=utf-8:ft=php:ai:si:ts=2:sw=2:et:nu:fdm=indent:fdn=1:
// [/agc_vim] 
/**
* @file
*   Work in progress
*   @author Erich Schulz (with help gratefully received from Xavier)
*   given to the universe under the same licence as CiviCRM
*/


/**
 * Class to automatically merge known duplicate contacts.
 *
 * The goal of these functions is to be simple but safe.
 *
 * Complex merges are rejected ('blocked') and will need handling through the UI
 *
 * The primary interface into the class is AgcAutoMerge::autoMergeSQL() which generates either:
 *    - the SQL to perform a merge, or 
 *    - a report explaining why the automerge is unsafe
 *  
 * Use AgcAutoMergeDevWindow::reportHtml() to see the internal workings 
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
 * COMPOUND KEYS
 * $_SESSION['_agc.compound_key_partners'] is an array where the index is a compound key referencing civcrm_contact.id.
 *  The value of this array is field name of the secondary column that indicates when the reference is to a contact.
 *
 * TODO:  Define the automerge behaviour of tables and fields in the datadictionary then...
 * TODO:  Rewrite the *_list functions and the columnAutomergeBehaviour functions to read the datadictionary 
 * TODO:  ideally this should be undoable - achievable if a list of updates is made and stored as a note attached to the 'deleted' contact
 * TODO:  Eliminate use of direct $_SESSION access
 * TODO:  Make the MySQL schema scan an optional action
 * TODO:  Make parameters configurable
 */

class AgcAutoMerge {

 /****************************************************************************/
 /* report class warmings, behaviour, limitations and settings */
 /****************************************************************************/

  /**
   * Give user-oriented warning report 
   * @returns string html
   */
  static function userWarning() {
    return 'Make sure the records you are about to automatically merge true duplicates!!  The checks this function performs only concern the feasability of automatically merging without loss of valuable data - the checks are NOT about verifying you have a true match.';
  }

 /**
  * Produces a list of settings. 
  * These settings change the behaviour of this class and should be viewable by the user.
  * todo most of these parameters should be pulled out into configurable settings
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

 /****************************************************************************/
 /* core functionality */
 /****************************************************************************/

  /**
   * Attempt to produce SQL to automaticlly merge two contacts.
   * 
   * First checks both recordes exist and are not deleted, then scans the database looking for affected tables,
   * then classifies the foreign keys. If no blocks found does a detailed (field by field examination of some tables.
   * If still no blocks found then produces a the sql to perform the merge.
   *
   * @param $mainID integer contact_id of record to keep (injection attack safe)
   * @param $otherId integer contact_id of record to be assimilated (injection attack safe)
   * @uses tablePlan()
   * @return array result including SQL
   */
  static public function autoMergeSQL($mainId, $otherId) {
    $keep = (int)$mainId;
    $lose = (int)$otherId;
    $result = array();
    $result['is_error'] = 0;
    $report = '';
    // check both records exist:
    $n = AgcAutoMerge::sqlToSingleValue(
      "SELECT count(*) AS value FROM civicrm_contact WHERE is_deleted = 0 AND id IN ($keep, $lose);");
    switch ($n) {
      case 2: // 
        // formulate a table-level plan for merging the contacts:
        $plan = AgcAutoMerge::tablePlan($otherId);
        if ($plan['blockers']) { // records found that are either in unknown tables or tables that need manual review
          $result['is_error'] = 1;
          $result['error_message']='Sorry, unable to automatically merge.';
          $report.= "Found references to $otherId in the following table(s): " . 
            implode(', ', $plan['blockers']) . '.';
        } else { // no records found in blocking tables, 
          foreach ($plan['examine'] as $key) { // examine selected tables field by field 
            $table = AgcAutoMerge::tableFromColumnRef($key); 
            $field = AgcAutoMerge::field($key); 
            $blockers = AgcAutoMerge::CheckTwoRecordsForBlocksSQL($table,$field, $keep, $lose);
            if ($blockers) {
              $result['is_error'] = 1;
              $result['error_message']='Sorry, unable to automatically merge.';
              $report.= "Incompatible fields found in $table ($blockers)";
            }
          }
          if (!$result['is_error']) { // we are safe to merge!
            $sql = array();
            foreach ($plan['update'] as $key) {
              $table = AgcAutoMerge::tableFromColumnRef($key); 
              $field = AgcAutoMerge::field($key); 
              // build list of fields to update during transfer to new contact
              $updates = AgcAutoMerge::tableAutomergeUpdates($table);
              if ($updates) {
                $updates = ", $updates";
              }
              $where = AgcAutoMerge::whereClauseSQL($lose, $table, $field);
              $sql[] = "UPDATE $table SET $field = $keep$updates WHERE $where;";
            }
            foreach ($plan['delete'] as $key) {
              $table = AgcAutoMerge::tableFromColumnRef($key);
              $field = AgcAutoMerge::field($key);
              $where = AgcAutoMerge::whereClauseSQL($lose, $table, $field);
              $sql[] = "DELETE FROM $table WHERE $where;";
            }
            $sql[]="UPDATE civicrm_contact SET is_deleted = 1 WHERE id = $lose;";
            $result['sql'] = $sql;
          }
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
    $plan = array();
    $locations = AgcAutoMerge::locate($contact_id);
    // classify locations into either for updating or deleting
    $plan['delete'] = array_intersect($locations, AgcAutoMerge::delete_list());
    $plan['update'] = array_intersect($locations, AgcAutoMerge::update_list());
    $plan['examine'] = array_intersect($locations, AgcAutoMerge::examine_list());
    // any column that doesn't have a plan blocks automerging
    $plan['blockers'] = array_diff($locations, $plan['delete'],$plan['update'], $plan['examine']);
    return $plan;
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
      $table = AgcAutoMerge::tableFromColumnRef($key);
      $field = AgcAutoMerge::field($key); 
      $where = AgcAutoMerge::whereClauseSQL($contact_id, $table, $field);
      $sql = 
        "SELECT count(*) AS value FROM $table WHERE $where;";
      $n=AgcAutoMerge::sqlToSingleValue($sql);
      if ($n) { // contact found!
        $locations[] = $key;
      }
    }
    return $locations;
  }


 /****************************************************************************/
 /* settings interface - this section groups all configuration settings */
 /****************************************************************************/

 /**
  * Database schema we are deduping, as read from CRM_Core_Config
  * @return string
  */
  public function schema() {
    static $schema = '';
    if ( !$schema ) {
      civicrm_initialize();
      //require_once drupal_get_path('module', 'civicrm').'/../api/api.php';
      $dsn = CRM_Core_Config::singleton()->dsn;
      // extract mysql database schema name from dsn string:
      // ie the bit between (the first / after the @) and the question mark
      $schema = preg_replace('/^.*@.*\/(.*)\?.*$/', '\1', $dsn);
    }
    return $schema;
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

 /****************************************************************************/
 /* meta data */
 /****************************************************************************/

  /**
  * List of columns to ignore completely while auto deduping.
  * Any reference to redundant contacts are left alone.
  * fixme: this data belongs in the core civicrm datadictionary and should be read from there.
  * @return array of (table name).(key field) 
  */
  public function ignore_list() { 
    return array(
      "agc_known_duplicates.contact_id_b",
      "civicrm_value_electorates.entity_id",
      "ems_geocode_addr_electorate.contact_id",
      "ems_regeocode.contact_id",
      "agc_known_duplicates.contact_id_a",
      "civicrm_log.entity_id", 
    );
  }
 /**
  * List of columns where a duplicate id should trigger a record delete during automated merging
  * fixme: this data belongs in the core civicrm datadictionary and should be read from there.
  *
  * @return array of (table name).(key field) 
  */
  public function delete_list() { 
    return array(
      "agc_intray_matches.contact_id",
    );
  }
 /**
  * List of columns that it is safe to update during an automated merge:
  * fixme: this data belongs in the core civicrm datadictionary and should be read from there.
  * @return array of (table name).(key field) 
  */
  public function update_list() { 
    return array(
      "civicrm_activity.source_contact_id",
      "civicrm_activity_target.target_contact_id",
      "civicrm_email.contact_id",
      "civicrm_entity_tag.entity_id",
      "civicrm_group_contact.contact_id",
      "civicrm_mailing_event_queue.contact_id",
      "civicrm_participant.contact_id",
      "civicrm_subscription_history.contact_id",
      "civicrm_activity_assignment.assignee_contact_id",
      "civicrm_address.contact_id",
      "civicrm_contribution.contact_id",
      "civicrm_dashboard_contact.contact_id",
      "civicrm_mailing_event_subscribe.contact_id",
      "civicrm_note.contact_id",
      "civicrm_phone.contact_id",
      "civicrm_preferences.contact_id",
      "civicrm_uf_match.contact_id",
      "civicrm_value_fundraising_appeals_59.contact_id_298",
      "ems_geocode_addr_electorate.contact_id  ",
    );

  }
 /**
  * List of key columns where the entire record needs field by field examination  
  * fixme: this data belongs in the core civicrm datadictionary and should be read from there.
  * @return array of (table name).(key field) 
  */
  public function examine_list() { 
    return array(
      "civicrm_contact.id"
    );
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
          $behaviour = 'AllowSingleCharBlankOrMatch';
          break;
        case 'middle_name':
        case 'last_name':
          $behaviour = 'IgnoreTruncation';
          break;
        //case 'sort_name':
        //case 'display_name':
          $behaviour = 'IgnoreEMaiTurrubal, Jagera and YuggeralOrTruncation';
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

     
 /****************************************************************************/
 /* implement desired field comparision behaviour  */
 /****************************************************************************/


 /**
  * Make SQL to compare two proposed automerge records and report blocking fields:
  * @uses automergeSuitablilityTestSQL()
  * @return string listing any blocking columns or NULL if no blocks
  */
  public function CheckTwoRecordsForBlocksSQL($table, $key_field, $ida, $idb) { 
    $columns = AgcAutoMerge::Describe($table);
    $sql = "SELECT CONCAT_WS(', ',\n";
    foreach ($columns as $column) {
      $behaviour = AgcAutoMerge::columnAutomergeBehaviour($table, $column);
      $sql .= '  IF ('.AgcAutoMerge::automergeSuitablilityTestSQL($table, $column, $behaviour) . 
        ", CONCAT('$column /*',ca.$column,'-',cb.$column,'*/'), NULL),\n";
    }
    $sql .= "  null) AS value
    FROM $table AS ca, $table AS cb 
    WHERE " .
      AgcAutoMerge::whereClauseSQL($ida, $table, $key_field, 'ca') . ' AND ' .
      AgcAutoMerge::whereClauseSQL($idb, $table, $key_field, 'cb') . ';';
    $value = AgcAutoMerge::sqlToSingleValue($sql);
    return $value;
  }

/**
 * SQL clause to test if automerging values prevents safe automerge
 *
 * These clauses return TRUE if the values block automerger.
 * 
 * Tables to compare are aliased by 'ca' and 'cb'.
 *
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
      case 'AllowSingleCharBlankOrMatch':
        $sql="IFNULL(ca.$column,'') NOT LIKE CONCAT(IFNULL(cb.$column,''),'%') 
                AND UPPER(SUBSTR(ca.$column,1,1))<>UPPER(cb.$column)";
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

 /****************************************************************************/
 /* schema reading functions */
 /****************************************************************************/

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
   * Access the MySQL information_schema to scan for foreign key fields referencing civicrm_contact.id
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
   * @return array of (table name).(key field) 
   */
  static public function getContactKeyFields($clear_cache = false) {
    // check for cached table list:
    if ($clear_cache || !array_key_exists('_agc.dedupefields',$_SESSION)) {
      AgcAutoMerge::prepareContactKeyFields();
    }
    return $_SESSION['_agc.dedupefields']; 
  }
  /**
   * Returns an SQL where clause for a contact related table.
   *
   * Uses $_SESSION['_agc.compound_key_partners'] to determine if $key is simple or compound,
   * then builds a simple or compound join accordingly.
   *
   * Assumes that prepareContactKeyFields() has been called
   *
   * @param int $contact_id
   * @param string $table
   * @param string $key field
   * @param string $alias SQL table alias (required because some queries compare two records in the same table)
   * @return string SQL fragment
   */
  static public function whereClauseSQL($contact_id, $table, $key_field, $alias = '') {
    $x = '_agc.compound_key_partners'; // session key to compound key secondary field list
    // construct proper SQL alias. If no alias use table name
    $alias = ($alias ? $alias : $table ) . '.';
    // create (table).(field) string for referencing meta-data
    $key ="$table.$key_field";
    // construct and returen SQL
    return "($alias$key_field = $contact_id" . 
      ( array_key_exists($key,$_SESSION[$x])
      ? " /* AND $_SESSION[$x][$key] = 'civicrm_contact' */ )"
      : ')');
  }


  /**
   * Read table schema etc to prepare key list
   * todo: consider making this is into a class constructor then change most functions from static
   * @uses $_SESSION to cache results
   */
  static public function prepareContactKeyFields() {
    $merger_keys = array(); // list of (table.columns) read from merger.php
    $schema_keys = array(); // list of (table.columns) read from MySQL schema directly
    //
    // access foriegn keys defined by /crm/dedupe/merger.php:
    //
    civicrm_initialize();
    require_once drupal_get_path('module', 'civicrm').'/../CRM/Dedupe/Merger.php';
    $compound_key_partners = array(); // columns that = 'civicrm_contact' 
    $refs = crm_dedupe_merger::cidrefs(); // simple keys
    foreach ($refs as $table => $key_cols) {
      foreach ($key_cols as $key_col) {
        $merger_keys[] = "$table.$key_col";
      }
    }
    $refs = crm_dedupe_merger::eidrefs(); // compound keys
    foreach ($refs as $table => $key_cols) {
      foreach ($key_cols as $table_field => $key_col) {
        $key = "$table.$key_col";
        $merger_keys[] = $key;
        $compound_key_partners[$key] = $table_field;
      }
    }
    //
    // scan MySQL information_schema.COLUMNS for additional references:
    //
    // read settings
    $schema = AgcAutoMerge::schema();
    $includeColumnsSQL = AgcAutoMerge::includeColumnsSQL();
    $ignoreColumnsSQL = AgcAutoMerge::ignoreColumnsSQL();
    $excludeEntitiesSQLList = AgcAutoMerge::excludeEntitiesSQLList();
    // find tables with 'contact_id' etc as a field
    $sql = <<<sql
      SELECT concat(
        /*`TABLE_SCHEMA`, '.', */
        `TABLE_NAME`, '.',
        `COLUMN_NAME`) as value
      FROM information_schema.COLUMNS
      WHERE ($includeColumnsSQL) 
        AND NOT ($ignoreColumnsSQL)
        AND `TABLE_SCHEMA` = '$schema'
sql;
    $schema_keys['includes'] = AgcAutoMerge::sqlToArray($sql);
    // add in the root table:
    $schema_keys['includes'][] = "civicrm_contact.id";
    // remove civi custom tables where entity_id is not a contact
    $sql = <<<sql
      SELECT concat(`table_name`, '.entity_id') as value
      FROM au_drupal.civicrm_custom_group
      WHERE `extends` IN ($excludeEntitiesSQLList)
sql;
    $schema_keys['excludes'] = AgcAutoMerge::sqlToArray($sql);
    //
    // Combine keys from both mysql schema and code, then store:
    //
    $combined_keys = $merger_keys +
        array_diff( $schema_keys['includes'], 
          $schema_keys['excludes'] , AgcAutoMerge::ignore_list());
    $_SESSION['_agc.dedupefields'] = array_unique($combined_keys);
    // store compound key secondary columns:
    $_SESSION['_agc.compound_key_partners'] = $compound_key_partners;
  }



 /****************************************************************************/
 /* utilities **/
 /****************************************************************************/
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
   * Trim column name to leave a table reference
   * @param string column '(schema_name).(table name).(field)' or  '(table name).(field)'
   * @return string '(schema_name).(table name)' or '(table name)'
   */
  static public function tableFromColumnRef($long_column_name) {
      $split = explode('.',$long_column_name);
      array_pop($split);
      return implode('.', $split); // = schema.table
  }
  
   /**
   * Parse long form column name into field
   * @param string column '(schema_name).(table name).(field)' 
   * @return string '(field)' 
   */
  static public function field($long_column_name) {
      $split = explode('.',$long_column_name);
      return array_pop($split); // = field 
  }

  /**
   * Convert SQL to a single value 
   * @param string $sql with field 'value'
   * @return string|number 
   */
  static protected function sqlToSingleValue($sql) {
    $res=CRM_Core_DAO::executeQuery($sql);
    if ($res->fetch()) {
      return $res->value;
    }
  }
  /**
   * Convert SQL into a one dimension array with numeric keys
   * @param string $sql with field and 'value'
   * @return array
   */
  static protected function sqlToArray($sql) {
    $res=CRM_Core_DAO::executeQuery($sql);
    $oneD = array();
    while ( $res->fetch()) {
      $oneD[] = $res->value;
    }
    return $oneD;
  }
}


/**
 * This class's role is to support debugging and development of the AutoMerge class.
 */
class AgcAutoMergeDevWindow {

  /**
   * Give a detailed report on the proposed merger of two contacts.
   * Injection attack safe but does not check permissions.
   * @param $mainID integer contact_id of record to keep (injection attack safe)
   * @param $otherId integer contact_id of record to be assimilated (injection attack safe)
   * @returns string html
   */
  static function reportHtml($mainId, $otherId) {
    $keep = (int)$mainId;
    $lose = (int)$otherId;
    return AgcAutoMergeDevWindow::explainPlanHtml($keep, $lose) . 
      '<p>(The above explains how the folowing plan was constructed).</p></hr>' .
      AgcAutoMergeDevWindow::showPlanHtml($keep, $lose) .
      "<h4>Settings</h4>" . // show configuration
        json_encode(AgcAutoMerge::settings());
  } 
  
  /**
   * Explore and explain the logic behind the proposed merger of two contacts.
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
    $html .= AgcAutoMergeDevWindow::arrayFormat($fields,'sol');
    $plan =  AgcAutoMerge::tablePlan($lose);
    $html .= "<h3>Planning merge of $lose into $keep</h3>";
    if ($plan['examine']) {
      $html .= "<h4>Tables to examine field by field</h4>" . AgcAutoMergeDevWindow::arrayFormat($plan['examine'],'sol');
      foreach ($plan['examine'] as $key) {
        $table = AgcAutoMerge::tableFromColumnRef($key); // = schema.table
        $field = AgcAutoMerge::field($key); // = field_name
        $html .= "<h5>Examining table: $table</h5>" .
          AgcAutoMergeDevWindow::arrayFormat(
            AgcAutoMergeDevWindow::examineRecord($table, $field, $keep, $lose),'sul');
        // report on actual function used to do the final check within autoMergeSQL()
        $final_check = AgcAutoMerge::CheckTwoRecordsForBlocksSQL($table,$field, $keep, $lose);
        $html .= "<p>In summary: " .($final_check ? $final_check : "no blocks found on examination of $table") .  "</p>";
      }
    }
    if ($plan['update']) {
      $html .= "<h4>Tables to update (move to kept record)</h4>" .
          AgcAutoMergeDevWindow::arrayFormat($plan['update'],'sol');
    }
    if ($plan['delete']) {
      $html .= "<h4>Delete records in these tables</h4>" . AgcAutoMergeDevWindow::arrayFormat($plan['delete'],'sol');
    }
    if ($plan['blockers']) {
      $html .= "<h4>Tables containing records blocking merge</h4>" . AgcAutoMergeDevWindow::arrayFormat($plan['blockers'],'sol').'<hr>';
    }
  return $html;
  }

  /**
   * Tests to see there are no blocks to automatically merging two contacts, and reports the SQL to perform the merge.
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
      $html .= "<h4>SQL:</h4>" . AgcAutoMergeDevWindow::arrayFormat($autoMergeSQL['sql']);
    }
    return $html;
  }

 /**
  * Examines two proposed automerge records and report blocking fields:
  * The purpose of this function is purely to allow debugging and testing of merge behaviour.
  * @return array of field reports: result name [valueA]-[valueB] (test used)
  */
  public function examineRecord($table, $key_field, $ida, $idb) { 
    $columns = AgcAutoMerge::Describe($table);
    $sql = "SELECT ";
    $columnsSQL = array();
    foreach ($columns as $c) {
      $behaviour = AgcAutoMerge::columnAutomergeBehaviour($table, $c);
      $test = AgcAutoMerge::automergeSuitablilityTestSQL($table, $c, $behaviour);
      $columnsSQL[] = " CONCAT_WS('', IF ($test, 'blocked', 'mergeable'), ' $c: [',ca.$c,']-[',cb.$c,'] ($behaviour)') AS $c";
    }
    $sql .= implode(', ', $columnsSQL);
    $sql .= " FROM $table AS ca, $table AS cb 
              WHERE " .
              AgcAutoMerge::whereClauseSQL($ida, $table, $key_field, 'ca') . ' AND ' .
              AgcAutoMerge::whereClauseSQL($idb, $table, $key_field, 'cb') . ';';
    $res = CRM_Core_DAO::executeQuery($sql);
    $res->fetch();
    // fixme: this following line throws in a few extra properties and isn't a clean swayp for drupal's db_fetch_array()
    $db_row =  (array)$res;
    return $db_row;
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

