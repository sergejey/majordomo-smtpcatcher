<?php
/**
* SMTP Catcher 
* @package project
* @author Wizard <sergejey@gmail.com>
* @copyright http://majordomo.smartliving.ru/ (c)
* @version 0.1 (wizard, 13:09:56 [Sep 28, 2017])
*/
//
//
class smtpcatcher extends module {
/**
* smtpcatcher
*
* Module class constructor
*
* @access private
*/
function smtpcatcher() {
  $this->name="smtpcatcher";
  $this->title="SMTP Catcher";
  $this->module_category="<#LANG_SECTION_APPLICATIONS#>";
  $this->checkInstalled();
}
/**
* saveParams
*
* Saving module parameters
*
* @access public
*/
function saveParams($data=0) {
 $p=array();
 if (IsSet($this->id)) {
  $p["id"]=$this->id;
 }
 if (IsSet($this->view_mode)) {
  $p["view_mode"]=$this->view_mode;
 }
 if (IsSet($this->edit_mode)) {
  $p["edit_mode"]=$this->edit_mode;
 }
 if (IsSet($this->tab)) {
  $p["tab"]=$this->tab;
 }
 return parent::saveParams($p);
}
/**
* getParams
*
* Getting module parameters from query string
*
* @access public
*/
function getParams() {
  global $id;
  global $mode;
  global $view_mode;
  global $edit_mode;
  global $tab;
  if (isset($id)) {
   $this->id=$id;
  }
  if (isset($mode)) {
   $this->mode=$mode;
  }
  if (isset($view_mode)) {
   $this->view_mode=$view_mode;
  }
  if (isset($edit_mode)) {
   $this->edit_mode=$edit_mode;
  }
  if (isset($tab)) {
   $this->tab=$tab;
  }
}
/**
* Run
*
* Description
*
* @access public
*/
function run() {
 global $session;
  $out=array();
  if ($this->action=='admin') {
   $this->admin($out);
  } else {
   $this->usual($out);
  }
  if (IsSet($this->owner->action)) {
   $out['PARENT_ACTION']=$this->owner->action;
  }
  if (IsSet($this->owner->name)) {
   $out['PARENT_NAME']=$this->owner->name;
  }
  $out['VIEW_MODE']=$this->view_mode;
  $out['EDIT_MODE']=$this->edit_mode;
  $out['MODE']=$this->mode;
  $out['ACTION']=$this->action;
  $out['TAB']=$this->tab;
  $this->data=$out;
  $p=new parser(DIR_TEMPLATES.$this->name."/".$this->name.".html", $this->data, $this);
  $this->result=$p->result;
}
/**
* BackEnd
*
* Module backend
*
* @access public
*/
function admin(&$out) {
 $this->getConfig();
 $out['API_PORT']=$this->config['API_PORT'];
 if (!$out['API_PORT']) {
  $out['API_PORT']='2525';
 }
 if ($this->view_mode=='update_settings') {
   global $api_port;
   $this->config['API_PORT']=$api_port;
   $this->saveConfig();
   setGlobal('cycle_smtpcatcherControl', 'restart');
   $this->redirect("?");
 }
 if (isset($this->data_source) && !$_GET['data_source'] && !$_POST['data_source']) {
  $out['SET_DATASOURCE']=1;
 }
 if ($this->data_source=='smtp_mails' || $this->data_source=='') {
  if ($this->view_mode=='' || $this->view_mode=='search_smtp_mails') {
   $this->search_smtp_mails($out);
  }
  if ($this->view_mode=='edit_smtp_mails') {
   $this->edit_smtp_mails($out, $this->id);
  }
  if ($this->view_mode=='delete_smtp_mails') {
   $this->delete_smtp_mails($this->id);
   $this->redirect("?");
  }
 }
}
/**
* FrontEnd
*
* Module frontend
*
* @access public
*/
function usual(&$out) {
 $this->admin($out);
}
/**
* smtp_mails search
*
* @access public
*/
 function search_smtp_mails(&$out) {
  require(DIR_MODULES.$this->name.'/smtp_mails_search.inc.php');
 }
/**
* smtp_mails edit/add
*
* @access public
*/
 function edit_smtp_mails(&$out, $id) {
  require(DIR_MODULES.$this->name.'/smtp_mails_edit.inc.php');
 }
/**
* smtp_mails delete record
*
* @access public
*/
 function delete_smtp_mails($id) {
  $rec=SQLSelectOne("SELECT * FROM smtp_mails WHERE ID='$id'");
  // some action for related tables
  SQLExec("DELETE FROM smtp_mails WHERE ID='".$rec['ID']."'");
 }
 function propertySetHandle($object, $property, $value) {
  $this->getConfig();
   $table='smtp_mails';
   $properties=SQLSelect("SELECT ID FROM $table WHERE LINKED_OBJECT LIKE '".DBSafe($object)."' AND LINKED_PROPERTY LIKE '".DBSafe($property)."'");
   $total=count($properties);
   if ($total) {
    for($i=0;$i<$total;$i++) {
     //to-do
    }
   }
 }
 function processCycle() {
 $this->getConfig();
  //to-do
 }
/**
* Install
*
* Module installation routine
*
* @access private
*/
 function install($data='') {
  parent::install();
  setGlobal('cycle_smtpcatcherControl', 'restart');
 }
/**
* Uninstall
*
* Module uninstall routine
*
* @access public
*/
 function uninstall() {
  SQLExec('DROP TABLE IF EXISTS smtp_mails');
  parent::uninstall();
 }
/**
* dbInstall
*
* Database installation routine
*
* @access private
*/
 function dbInstall($data = '') {
/*
smtp_mails - 
*/
  $data = <<<EOD
 smtp_mails: ID int(10) unsigned NOT NULL auto_increment
 smtp_mails: TITLE varchar(100) NOT NULL DEFAULT ''
 smtp_mails: MAILTO varchar(255) NOT NULL DEFAULT ''
 smtp_mails: LINKED_OBJECT varchar(100) NOT NULL DEFAULT ''
 smtp_mails: LINKED_PROPERTY varchar(100) NOT NULL DEFAULT ''
 smtp_mails: LINKED_METHOD varchar(100) NOT NULL DEFAULT ''
 smtp_mails: ATTACHEMENT_DIR varchar(255) NOT NULL DEFAULT '' 
 smtp_mails: SCRIPT_ID int(10) NOT NULL DEFAULT '0' 
 smtp_mails: UPDATED datetime
EOD;
  parent::dbInstall($data);
 }
// --------------------------------------------------------------------
}
/*
*
* TW9kdWxlIGNyZWF0ZWQgU2VwIDI4LCAyMDE3IHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
*
*/
