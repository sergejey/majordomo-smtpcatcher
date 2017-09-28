<?php
/*
* @version 0.1 (wizard)
*/
  if ($this->owner->name=='panel') {
   $out['CONTROLPANEL']=1;
  }
  $table_name='smtp_mails';
  $rec=SQLSelectOne("SELECT * FROM $table_name WHERE ID='$id'");
  if ($this->mode=='update') {
   $ok=1;
  //updating '<%LANG_TITLE%>' (varchar, required)
   global $title;
   $rec['TITLE']=$title;
   if ($rec['TITLE']=='') {
    $out['ERR_TITLE']=1;
    $ok=0;
   }
  //updating 'MAILTO' (varchar)
   global $mailto;
   $rec['MAILTO']=$mailto;
      if ($rec['MAILTO']=='') {
          $out['ERR_MAILTO']=1;
          $ok=0;
      }
  //updating '<%LANG_LINKED_OBJECT%>' (varchar)
   global $linked_object;
   $rec['LINKED_OBJECT']=$linked_object;
  //updating '<%LANG_LINKED_PROPERTY%>' (varchar)
   global $linked_property;
   $rec['LINKED_PROPERTY']=$linked_property;
  //updating '<%LANG_METHOD%>' (varchar)
   global $linked_method;
   $rec['LINKED_METHOD']=$linked_method;

   global $script_id;
   $rec['SCRIPT_ID']=(int)$script_id;

      global $attachement_dir;
      $rec['ATTACHEMENT_DIR']=$attachement_dir;
      if ($rec['ATTACHEMENT_DIR']!='' && !is_dir($rec['ATTACHEMENT_DIR'])) {
          $out['ERR_ATTACHEMENT_DIR']=1;
          $ok=0;
      }

  //updating '<%LANG_UPDATED%>' (datetime)
  //UPDATING RECORD
   if ($ok) {
    if ($rec['ID']) {
     SQLUpdate($table_name, $rec); // update
    } else {
     $new_rec=1;
     $rec['ID']=SQLInsert($table_name, $rec); // adding new record
    }
    $out['OK']=1;
   } else {
    $out['ERR']=1;
   }
  }

  if (is_array($rec)) {
   foreach($rec as $k=>$v) {
    if (!is_array($v)) {
     $rec[$k]=htmlspecialchars($v);
    }
   }
  }
  outHash($rec, $out);

$out['SCRIPTS']=SQLSelect("SELECT ID, TITLE FROM scripts ORDER BY TITLE");