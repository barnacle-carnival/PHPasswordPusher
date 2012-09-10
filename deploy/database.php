<?php
//Insert the credential into the database
function InsertCred($id, $encrypted, $xtime, $xviews) {
  //Connect to database and insert credential
  $query = "insert into phpasspush(id,seccred,ctime,views,xtime,xviews) values
      (:id, :seccred, UTC_TIMESTAMP(), 0, UTC_TIMESTAMP()+ INTERVAL :xtime MINUTE, :xviews)";
  $params = array(
      'id'        => $id,
      'seccred'  => $encrypted,
      'xtime'     => "+" . (is_numeric($xtime) ? $xtime : $xtime_default) . " minutes",
      'xviews'    => is_numeric($xviews) ? $xviews : $xviews_default,
  );
  
  //Connect to database and insert data
  try{
    $db = ConnectDB();
    $statement = $db->prepare($query);
    $statement->execute($params);
    EraseOldRecords($db);
  } catch (PDOException $e) {
    error_log('PHPassword DB Error: ' . $e->getMessage() . "\n");
  }
}

//Retrieve credentials from database
// Update the view count first (this should be atomic), so we can avoid race conditions allowing extra views.
// Updates should be attomic unless we are within a transaction (so don't do that!).
$DB_SUPPORTS_UPDATED_ROW_COUNT = true;  // MySQL (and possibly others)
function RetrieveCred($id) {
  if ($DB_SUPPORTS_UPDATED_ROW_COUNT) {
    // If the DB supports updated row counts:
    // We try to update our row if it's under the view limit.
    // If we succeeded in updating something, we know that this call was under the limit, so
    // we select the record if it is still under its expiration date.
    // This should result in no race conditions.
    $update_query = "update phpasspush set views=views+1 where id=:id and xviews>views";
    $select_query = "select seccred,views from phpasspush where id=:id and xtime>UTC_TIMESTAMP()";
    $params = array('id' => $id);
    try{
      $db = ConnectDB();
      $statement = $db->prepare($update_query);
      $statement->execute($params);
      if (! $statement->rowCount()) {
        return false;
      }
      $statement = $db->prepare($select_query);
      $statement->execute($params);
      $result = $statement->fetchAll();
      EraseOldRecords($db);
    } catch (PDOException $e) {
      error_log('PHPassword DB Error: ' . $e->getMessage() . "\n");
    }
    return $result;
  } else {
    // If the DB does not support updated row counts:
    // We update our row's view count, first, ensuring that the view count is always incremented.
    // Then we select the record if it is still under its expiration date and at or under the view limit.
    // The race condition here is that if two requests come at once for a row, which is one view away
    // from the limit, both will increment the view count before the select is called, and thus, neither
    // will be able to view the record.  Better to error on the conservitive side though.
    $update_query = "update phpasspush set views=views+1 where id=:id";
    $select_query = "select seccred,views from phpasspush where id=:id and xtime>UTC_TIMESTAMP() and xviews>=views";
    $params = array('id' => $id);
    try{
      $db = ConnectDB();
      $statement = $db->prepare($update_query);
      $statement->execute($params);
      $statement = $db->prepare($select_query);
      $statement->execute($params);
      $result = $statement->fetchAll();
      EraseOldRecords($db);
    } catch (PDOException $e) {
      error_log('PHPassword DB Error: ' . $e->getMessage() . "\n");
    }
    return $result;
  }
  // Not reached...
  return false;
}

// Erase all records which have expired times or expired view limits.
// This should be done after every transaction.
// We don't update records already set to NULL though.
function EraseOldRecords($db) {
  $query = "update phpasspush set seccred = NULL where seccred != NULL and ( xtime>UTC_TIMESTAMP() or xviews>=views )";
  // Why not:
  // $query = "delete from phpasspush where xtime>UTC_TIMESTAMP() or xviews>=views";
  $params = array('id' => $id);
  try{
    $statement = $db->prepare($query);
    $statement->execute($params);
  } catch (PDOException $e) {
    error_log('PHPassword DB Error: ' . $e->getMessage() . "\n");
  }
 
}

//Connect to the database
function ConnectDB() {
  require 'config.php';
  $db = new PDO('mysql:dbname=' . $dbname . ';host=localhost', $dbuser, $dbpass) or die('Connect Failed');
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  return $db;
}
?>