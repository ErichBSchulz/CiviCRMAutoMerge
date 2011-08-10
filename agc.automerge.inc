<?php
// [agc_vim\] vim:fenc=utf-8:ft=php:ai:si:ts=2:sw=2:et:nu:fdm=indent:fdn=1:
// [/agc_vim] 
/**
* @file
*   Work in progrogress. This file contains working interfaces to support development of the automerge class
*   This is an exmample of a quick and dirty interface into the automerge class.
*/
/**
 * Query to populate known duplicates with matching email query
 * @return nothing
 * @todo:
 *    make executable
 *    put unique key on agc_known_duplicates
 *    put 'ignore' clause in query
 */
function _agc_quick_find_dupes_on_email() {
  $sql = <<<sql
    INSERT INTO agc_known_duplicates (contact_id_a, contact_id_b, status, note)
    SELECT
     cae.contact_id AS contact_id_a,
     cbe.contact_id AS contact_id_b,
     'Duplicate' AS status,
     concat('Autoadded, joined on email: ',  cae.email,
       ', name A: [',
          CONCAT_WS('][', ca.first_name,ca.middle_name,ca.last_name),
       '], Name B: [',
          CONCAT_WS('][', cb.first_name,cb.middle_name,cb.last_name),
   ']') AS Note
    FROM civicrm_contact AS ca
    INNER JOIN civicrm_email AS cae ON ca.id = cae.contact_id
    INNER JOIN civicrm_email AS cbe  ON cae.email = cbe.email
    INNER JOIN civicrm_contact AS cb ON cbe.contact_id = cb.id
    WHERE cae.contact_id < cbe.contact_id
     AND ca.is_deleted = 0 AND ca.contact_type = 'Individual'
     AND cb.is_deleted = 0 AND cb.contact_type = 'Individual'
     AND ifnull(ca.first_name,'') like concat(ifnull(cb.first_name,''),'%')
     AND ifnull(ca.middle_name,'') like concat(ifnull(cb.middle_name,''),'%')
     AND ifnull(ca.last_name,'') like concat(ifnull(cb.last_name,''),'%')
sql;
  }
/**
 * quick & dirty duplicte window 
 */
function _agc_known_duplicates_all() {
  $table = 'au_drupal.agc_known_duplicates';
  $sql = <<<SQL
    SELECT id, 
      CONCAT( '<a href="/agc/dedupe/merge/', 
      contact_id_a,'/',contact_id_b,'">Automerge',
      '</a> | ',
      '<a href="/agc/dedupe/plan/', 
      contact_id_a,'/',contact_id_b,'">Plan',
      '</a> | ',
      '<a href="/civicrm/contact/merge?reset=1&cid=', 
      contact_id_a,'&oid=',contact_id_b,'">CiviCRM merge screen',
      '</a>'
      ) as url,
      contact_id_a, contact_id_b
    FROM $table
    WHERE 1=1
SQL;
  $header=array( 
    array( 'data' => 'Links', 'field' => 'url', 'sort' => 'asc'),
    array(
      'data' => 'CiviCRM ID A', 
      'field' => 'contact_id_a'), 
    array(
      'data' => 'CiviCRM ID B', 
      'field' => 'contact_id_b'), 
        array( 'data' => 'Duplicate ID', 'field' => 'id')
);

  $html =
    AgcDb::drupalTable(
      $header, 
      $sql, 
      'Known duplicate contacts', 
      $table, 
      10);
  return "<h2>Automerging test panel</h2><p>Please check the SQL provided by the 'plan' link. The link on the right is to the tradditional CiviCRM dedupe screen, so you can look at what you are merging in the traditional manner. If you are feeling brave you could even run the SQL against the live data by clicking on Automerge. NB: some of these links may no longer work because the merge has been processed! (FYI these dupes are cached in $table)</p>" .
    $html;
  }

/**
 * Give a detailed report on the proposed merger of two contacts.
 * @returns string html
 */
function _agc_dedupe_report_plan($mainId, $otherId) {
  require_once 'class.automerge.php'; 
  return AgcAutoMergeDevWindow::reportHtml($mainId, $otherId);
}

/**
 * Automatically merge two contacts.
 * 
 * Creates a note in both main and outher contact 
 *
 * @returns string html
 */
function _agc_dedupe_merge($mainId, $otherId) {
  require_once 'class.automerge.php'; 
  $params = array( 
    'id' => $mainId,
    'id_duplicate' => $otherId);
  $result = AgcAutoMerge::merge($params);
  $html = "<h3>Planned merger of $otherId into $mainId</h3>";
  if ($result['is_error']) {
    $html .= "<h4>Error</h4><p class='error'>$result[error_message]</p>";
  } else {
    $html .= "<h4>SQL:</h4>" . AgcAutoMergeDevWindow::arrayFormat($result['sql']);
  }
  return $html . '<p>Report:' . $result['report'] . '</p>';
}

/**
 * Automatic bulk contact merge.
 * 
 * @param string SQL to generate list of mainId and otherId
 * @returns string html
 */
function _agc_dedupe_bulk_SQL_merge($sql) {
  require_once 'class.automerge.php'; 
  $html = '';
  $res = db_query($sql);
  $row = db_fetch_object($res);
  // loop through rows:
  while ($row) { 
    $mainId = $row->mainId;
    $otherId = $row->otherId;
    $html .= _agc_dedupe_merge($mainId, $otherId);
    $row =  db_fetch_object($res);
  }
  return $html . '<p>Report:' . $result['report'] . '</p>';
}

/**
 * Batch duplicate merger 
 * @param int $page zero based page number 
 * @param int $n number of records to process per page
 * @returns string html
 */
function _agc_bulk_merge_known_duplicates($page, $n) {
  $table = 'au_drupal.agc_known_duplicates';
  $n = (int)$n;
  $start = (int)$page * $n;
  $sql = <<<SQL
    SELECT 
      contact_id_a AS mainId,
      contact_id_b AS otherId
    FROM $table
    ORDER BY id
    LIMIT $start, $n;
SQL;
  return _agc_dedupe_bulk_SQL_merge($sql);
}


