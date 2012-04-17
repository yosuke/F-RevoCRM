<?php
/*+**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.0
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 ************************************************************************************/
require_once('data/CRMEntity.php');
require_once('data/Tracker.php');

class Dailyreports extends CRMEntity {
	var $db, $log; // Used in class functions of CRMEntity

	var $table_name = 'vtiger_dailyreports';
	var $table_index= 'dailyreportsid';
	var $column_fields = Array();

	/** Indicator if this is a custom module or standard module */
	var $IsCustomModule = true;

	/**
	 * Mandatory table for supporting custom fields.
	 */
	var $customFieldTable = Array('vtiger_dailyreportscf', 'dailyreportsid');

	/**
	 * Mandatory for Saving, Include tables related to this module.
	 */
	var $tab_name = Array('vtiger_crmentity', 'vtiger_dailyreports', 'vtiger_dailyreportscf');

	/**
	 * Mandatory for Saving, Include tablename and tablekey columnname here.
	 */
	var $tab_name_index = Array(
		'vtiger_crmentity' => 'crmid',
		'vtiger_dailyreports'   => 'dailyreportsid',
		'vtiger_dailyreportscf' => 'dailyreportsid',
		'vtiger_drcomments'=>'dailyreportsid'
	);

	/**
	 * Mandatory for Listing (Related listview)
	 */
	var $list_fields = Array (
		/* Format: Field Label => Array(tablename, columnname) */
		// tablename should not have prefix 'vtiger_'
	);
	var $list_fields_name = Array(
		/* Format: Field Label => fieldname */
	);

	// Make the field link to detail view from list view (Fieldname)
	var $list_link_field = 'DailyreportsName';

	// For Popup listview and UI type support
	var $search_fields = Array(
		/* Format: Field Label => Array(tablename, columnname) */
		// tablename should not have prefix 'vtiger_'
	);
	var $search_fields_name = Array(
		/* Format: Field Label => fieldname */
	);

	// For Popup window record selection
	var $popup_fields = Array('dailyreportname');

	// Placeholder for sort fields - All the fields will be initialized for Sorting through initSortFields
	var $sortby_fields = Array('reportsdate','reports_to_id');

	// For Alphabetical search
	var $def_basicsearch_col = 'dailyreportname';

	// Column value to use on detail view record text display
	var $def_detailview_recname = 'dailyreportname';

	// Required Information for enabling Import feature
	var $required_fields = Array('dailyreportname'=>1);

	// Callback function list during Importing
	var $special_functions = Array('set_import_assigned_user');

	var $default_order_by = 'reportsdate';
	var $default_sort_order='ASC';
	// Used when enabling/disabling the mandatory fields for the module.
	// Refers to vtiger_field.fieldname values.
	var $mandatory_fields = Array('createdtime', 'modifiedtime', 'dailyreportname', 'reportsdate');
	
	function __construct() {
		global $log, $currentModule;
		$this->column_fields = getColumnFields($currentModule);
		$this->db = PearDatabase::getInstance();
		$this->log = $log;
	}

	function getSortOrder() {
		global $currentModule;

		$sortorder = $this->default_sort_order;
		if($_REQUEST['sorder']) $sortorder = $this->db->sql_escape_string($_REQUEST['sorder']);
		else if($_SESSION[$currentModule.'_Sort_Order']) 
			$sortorder = $_SESSION[$currentModule.'_Sort_Order'];

		return $sortorder;
	}

	function getOrderBy() {
		global $currentModule;
		
		$use_default_order_by = '';		
		if(PerformancePrefs::getBoolean('LISTVIEW_DEFAULT_SORTING', true)) {
			$use_default_order_by = $this->default_order_by;
		}
		
		$orderby = $use_default_order_by;
		if($_REQUEST['order_by']) $orderby = $this->db->sql_escape_string($_REQUEST['order_by']);
		else if($_SESSION[$currentModule.'_Order_By'])
			$orderby = $_SESSION[$currentModule.'_Order_By'];
		return $orderby;
	}

	function save_module($module) {
		//Inserting into Dailyreports Comment Table
		$this->insertIntoDailyreportsCommentTable("vtiger_drcomments",'Dailyreports');
	}

	/**
	 * Return query to use based on given modulename, fieldname
	 * Useful to handle specific case handling for Popup
	 */
	function getQueryByModuleField($module, $fieldname, $srcrecord) {
		// $srcrecord could be empty
	}

	/**
	 * Get list view query (send more WHERE clause condition if required)
	 */
	function getListQuery($module, $where='') {
		$query = "SELECT vtiger_crmentity.*, $this->table_name.*";

		// Select Custom Field Table Columns if present
		if(!empty($this->customFieldTable)) $query .= ", " . $this->customFieldTable[0] . ".* ";

		$query .= " FROM $this->table_name";

		$query .= "	INNER JOIN vtiger_crmentity ON vtiger_crmentity.crmid = $this->table_name.$this->table_index";

		// Consider custom table join as well.
		if(!empty($this->customFieldTable)) {
			$query .= " INNER JOIN ".$this->customFieldTable[0]." ON ".$this->customFieldTable[0].'.'.$this->customFieldTable[1] .
				      " = $this->table_name.$this->table_index"; 
		}
		$query .= " LEFT JOIN vtiger_users ON vtiger_users.id = vtiger_crmentity.smownerid";
		$query .= " LEFT JOIN vtiger_groups ON vtiger_groups.groupid = vtiger_crmentity.smownerid";
		$query .= " LEFT JOIN vtiger_users as vtuser ON vtuser.id = vtiger_dailyreports.reports_to_id";

		$linkedModulesQuery = $this->db->pquery("SELECT distinct fieldname, columnname, relmodule FROM vtiger_field" .
				" INNER JOIN vtiger_fieldmodulerel ON vtiger_fieldmodulerel.fieldid = vtiger_field.fieldid" .
				" WHERE uitype='10' AND vtiger_fieldmodulerel.module=?", array($module));
		$linkedFieldsCount = $this->db->num_rows($linkedModulesQuery);
		
		for($i=0; $i<$linkedFieldsCount; $i++) {
			$related_module = $this->db->query_result($linkedModulesQuery, $i, 'relmodule');
			$fieldname = $this->db->query_result($linkedModulesQuery, $i, 'fieldname');
			$columnname = $this->db->query_result($linkedModulesQuery, $i, 'columnname');
			
			$other = CRMEntity::getInstance($related_module);
			vtlib_setup_modulevars($related_module, $other);
			
			$query .= " LEFT JOIN $other->table_name ON $other->table_name.$other->table_index = $this->table_name.$columnname";
		}

		$query .= "	WHERE vtiger_crmentity.deleted = 0 ".$where;
		$query .= $this->getListViewSecurityParameter($module);
		return $query;
	}

	/**
	 * Apply security restriction (sharing privilege) query part for List view.
	 */
	function getListViewSecurityParameter($module) {
		global $current_user;
		require('user_privileges/user_privileges_'.$current_user->id.'.php');
		require('user_privileges/sharing_privileges_'.$current_user->id.'.php');

		$sec_query = '';
		$tabid = getTabid($module);

		if($is_admin==false && $profileGlobalPermission[1] == 1 && $profileGlobalPermission[2] == 1 
			&& $defaultOrgSharingPermission[$tabid] == 3) {

				$sec_query .= " AND (vtiger_crmentity.smownerid in($current_user->id) OR vtiger_crmentity.smownerid IN 
					(
						SELECT vtiger_user2role.userid FROM vtiger_user2role 
						INNER JOIN vtiger_users ON vtiger_users.id=vtiger_user2role.userid 
						INNER JOIN vtiger_role ON vtiger_role.roleid=vtiger_user2role.roleid 
						WHERE vtiger_role.parentrole LIKE '".$current_user_parent_role_seq."::%'
					) 
					OR vtiger_crmentity.smownerid IN 
					(
						SELECT shareduserid FROM vtiger_tmp_read_user_sharing_per 
						WHERE userid=".$current_user->id." AND tabid=".$tabid."
					) 
					OR 
						(";
		
					// Build the query based on the group association of current user.
					if(sizeof($current_user_groups) > 0) {
						$sec_query .= " vtiger_groups.groupid IN (". implode(",", $current_user_groups) .") OR ";
					}
					$sec_query .= " vtiger_groups.groupid IN 
						(
							SELECT vtiger_tmp_read_group_sharing_per.sharedgroupid 
							FROM vtiger_tmp_read_group_sharing_per
							WHERE userid=".$current_user->id." and tabid=".$tabid."
						)";
				$sec_query .= ")
				)";
		}
		return $sec_query;
	}

	/**
	 * Create query to export the records.
	 */
	function create_export_query($where)
	{
		global $current_user;
		$thismodule = $_REQUEST['module'];
		
		include("include/utils/ExportUtils.php");

		//To get the Permitted fields query and the permitted fields list
		$sql = getPermittedFieldsQuery($thismodule, "detail_view");
		
		$fields_list = getFieldsListFromQuery($sql);

		$query = "SELECT $fields_list, vtiger_users.user_name AS user_name 
					FROM vtiger_crmentity INNER JOIN $this->table_name ON vtiger_crmentity.crmid=$this->table_name.$this->table_index";

		if(!empty($this->customFieldTable)) {
			$query .= " INNER JOIN ".$this->customFieldTable[0]." ON ".$this->customFieldTable[0].'.'.$this->customFieldTable[1] .
				      " = $this->table_name.$this->table_index"; 
		}

		$query .= " LEFT JOIN vtiger_groups ON vtiger_groups.groupid = vtiger_crmentity.smownerid";
		$query .= " LEFT JOIN vtiger_users ON vtiger_crmentity.smownerid = vtiger_users.id and vtiger_users.status='Active'";
		$query .= " LEFT JOIN vtiger_users as vtuser ON vtuser.id = vtiger_dailyreports.reports_to_id";
		$query .= " LEFT JOIN vtiger_drcomments ON vtiger_drcomments.dailyreportsid = vtiger_dailyreports.dailyreportsid";
		
		$linkedModulesQuery = $this->db->pquery("SELECT distinct fieldname, columnname, relmodule FROM vtiger_field" .
				" INNER JOIN vtiger_fieldmodulerel ON vtiger_fieldmodulerel.fieldid = vtiger_field.fieldid" .
				" WHERE uitype='10' AND vtiger_fieldmodulerel.module=?", array($thismodule));
		$linkedFieldsCount = $this->db->num_rows($linkedModulesQuery);

		for($i=0; $i<$linkedFieldsCount; $i++) {
			$related_module = $this->db->query_result($linkedModulesQuery, $i, 'relmodule');
			$fieldname = $this->db->query_result($linkedModulesQuery, $i, 'fieldname');
			$columnname = $this->db->query_result($linkedModulesQuery, $i, 'columnname');
			
			$other = CRMEntity::getInstance($related_module);
			vtlib_setup_modulevars($related_module, $other);
			
			$query .= " LEFT JOIN $other->table_name ON $other->table_name.$other->table_index = $this->table_name.$columnname";
		}

		$where_auto = " vtiger_crmentity.deleted=0";

		if($where != '') $query .= " WHERE ($where) AND $where_auto";
		else $query .= " WHERE $where_auto";

		require('user_privileges/user_privileges_'.$current_user->id.'.php');
		require('user_privileges/sharing_privileges_'.$current_user->id.'.php');

		// Security Check for Field Access
		if($is_admin==false && $profileGlobalPermission[1] == 1 && $profileGlobalPermission[2] == 1 && $defaultOrgSharingPermission[7] == 3)
		{
			//Added security check to get the permitted records only
			$query = $query." ".getListViewSecurityParameter($thismodule);
		}
		return $query;
	}

	/**
	 * Initialize this instance for importing.
	 */
	function initImport($module) {
		$this->db = PearDatabase::getInstance();
		$this->initImportableFields($module);
	}

	/**
	 * Create list query to be shown at the last step of the import.
	 * Called From: modules/Import/UserLastImport.php
	 */
	function create_import_query($module) {
		global $current_user;
		$query = "SELECT vtiger_crmentity.crmid, case when (vtiger_users.user_name not like '') then vtiger_users.user_name else vtiger_groups.groupname end as user_name, $this->table_name.* FROM $this->table_name
			INNER JOIN vtiger_crmentity ON vtiger_crmentity.crmid = $this->table_name.$this->table_index
			LEFT JOIN vtiger_users_last_import ON vtiger_users_last_import.bean_id=vtiger_crmentity.crmid
			LEFT JOIN vtiger_users ON vtiger_users.id = vtiger_crmentity.smownerid
			LEFT JOIN vtiger_groups ON vtiger_groups.groupid = vtiger_crmentity.smownerid
			WHERE vtiger_users_last_import.assigned_user_id='$current_user->id'
			AND vtiger_users_last_import.bean_type='$module'
			AND vtiger_users_last_import.deleted=0";
		return $query;
	}

	/**
	 * Delete the last imported records.
	 */
	function undo_import($module, $user_id) {
		global $adb;
		$count = 0;
		$query1 = "select bean_id from vtiger_users_last_import where assigned_user_id=? AND bean_type='$module' AND deleted=0";
		$result1 = $adb->pquery($query1, array($user_id)) or die("Error getting last import for undo: ".mysql_error()); 
		while ( $row1 = $adb->fetchByAssoc($result1))
		{
			$query2 = "update vtiger_crmentity set deleted=1 where crmid=?";
			$result2 = $adb->pquery($query2, array($row1['bean_id'])) or die("Error undoing last import: ".mysql_error()); 
			$count++;			
		}
		return $count;
	}
	
	/**
	 * Transform the value while exporting
	 */
	function transform_export_value($key, $value) {
		return parent::transform_export_value($key, $value);
	}

	/**
	 * Function which will set the assigned user id for import record.
	 */
	function set_import_assigned_user()
	{
		global $current_user, $adb;
		$record_user = $this->column_fields["assigned_user_id"];
		
		if($record_user != $current_user->id){
			$sqlresult = $adb->pquery("select id from vtiger_users where id = ? union select groupid as id from vtiger_groups where groupid = ?", array($record_user, $record_user));
			if($this->db->num_rows($sqlresult)!= 1) {
				$this->column_fields["assigned_user_id"] = $current_user->id;
			} else {			
				$row = $adb->fetchByAssoc($sqlresult, -1, false);
				if (isset($row['id']) && $row['id'] != -1) {
					$this->column_fields["assigned_user_id"] = $row['id'];
				} else {
					$this->column_fields["assigned_user_id"] = $current_user->id;
				}
			}
		}
	}
	
	/** 
	 * Function which will give the basic query to find duplicates
	 */
	function getDuplicatesQuery($module,$table_cols,$field_values,$ui_type_arr,$select_cols='') {
		$select_clause = "SELECT ". $this->table_name .".".$this->table_index ." AS recordid, vtiger_users_last_import.deleted,".$table_cols;

		// Select Custom Field Table Columns if present
		if(isset($this->customFieldTable)) $query .= ", " . $this->customFieldTable[0] . ".* ";

		$from_clause = " FROM $this->table_name";

		$from_clause .= "	INNER JOIN vtiger_crmentity ON vtiger_crmentity.crmid = $this->table_name.$this->table_index";

		// Consider custom table join as well.
		if(isset($this->customFieldTable)) {
			$from_clause .= " INNER JOIN ".$this->customFieldTable[0]." ON ".$this->customFieldTable[0].'.'.$this->customFieldTable[1] .
				      " = $this->table_name.$this->table_index"; 
		}
		$from_clause .= " LEFT JOIN vtiger_users ON vtiger_users.id = vtiger_crmentity.smownerid
						LEFT JOIN vtiger_groups ON vtiger_groups.groupid = vtiger_crmentity.smownerid";
		
		$where_clause = "	WHERE vtiger_crmentity.deleted = 0";
		$where_clause .= $this->getListViewSecurityParameter($module);
					
		if (isset($select_cols) && trim($select_cols) != '') {
			$sub_query = "SELECT $select_cols FROM  $this->table_name AS t " .
				" INNER JOIN vtiger_crmentity AS crm ON crm.crmid = t.".$this->table_index;
			// Consider custom table join as well.
			if(isset($this->customFieldTable)) {
				$sub_query .= " LEFT JOIN ".$this->customFieldTable[0]." tcf ON tcf.".$this->customFieldTable[1]." = t.$this->table_index";
			}
			$sub_query .= " WHERE crm.deleted=0 GROUP BY $select_cols HAVING COUNT(*)>1";	
		} else {
			$sub_query = "SELECT $table_cols $from_clause $where_clause GROUP BY $table_cols HAVING COUNT(*)>1";
		}	
		
		
		$query = $select_clause . $from_clause .
					" LEFT JOIN vtiger_users_last_import ON vtiger_users_last_import.bean_id=" . $this->table_name .".".$this->table_index .
					" INNER JOIN (" . $sub_query . ") AS temp ON ".get_on_clause($field_values,$ui_type_arr,$module) .
					$where_clause .
					" ORDER BY $table_cols,". $this->table_name .".".$this->table_index ." ASC";
					
		return $query;		
	}

	/**
	 * Invoked when special actions are performed on the module.
	 * @param String Module name
	 * @param String Event Type (module.postinstall, module.disabled, module.enabled, module.preuninstall)
	 */
	function vtlib_handler($modulename, $event_type) {
		if($event_type == 'module.postinstall') {
			// TODO Handle post installation actions
		} else if($event_type == 'module.disabled') {
			// TODO Handle actions when this module is disabled.
		} else if($event_type == 'module.enabled') {
			// TODO Handle actions when this module is enabled.
		} else if($event_type == 'module.preuninstall') {
			// TODO Handle actions when this module is about to be deleted.
		} else if($event_type == 'module.preupdate') {
			// TODO Handle actions before this module is updated.
		} else if($event_type == 'module.postupdate') {
			// TODO Handle actions after this module is updated.
		}
	}

	/** Function to insert values in vtiger_drcomments  for the specified tablename and  module
  	  * @param $table_name -- table name:: Type varchar
  	  * @param $module -- module:: Type varchar
 	 */	
	function insertIntoDailyreportsCommentTable($table_name, $module)
	{
		global $log;
		$log->info("in insertIntoDailyreportsCommentTable  ".$table_name."    module is  ".$module);
        	global $adb;
		global $current_user;

        	$current_time = $adb->formatDate(date('YmdHis'), true);
		if($this->column_fields['assigned_user_id'] != '')
			$ownertype = 'user';
		else
			$ownertype = 'customer';

		if($this->column_fields['comments'] != '')
			$comment = $this->column_fields['comments'];
		else
			$comment = $_REQUEST['comments'];
		
		if($comment != '')
		{
			$sql = "insert into vtiger_drcomments values(?,?,?,?,?,?)";	
	        	$params = array('', $this->id, from_html($comment), $current_user->id, $ownertype, $current_time);
			$adb->pquery($sql, $params);
		}
	}

	/**	Function to get the dailyreports comments as a array
	 *	@param  int   $dailyreportsid - dailyreportsid
	 *	@return array $output - array(	
						[$i][comments]    => comments
						[$i][owner]       => name of the user or customer who made the comment
						[$i][createdtime] => the comment created time
					     ) 
				where $i = 0,1,..n which are all made for the dailyreports	**/
	function get_dr_comments_list($dailyreportsid)
	{
		global $log;
		$log->debug("Entering get_dr_comments_list(".$dailyreportsid.") method ...");
		 $sql = "select * from vtiger_drcomment where dailyreportsid=? order by createdtime DESC";
		 $result = $this->db->pquery($sql, array($dailyreportsid));
		 $noofrows = $this->db->num_rows($result);
		 for($i=0;$i<$noofrows;$i++)
		 {
			 $ownerid = $this->db->query_result($result,$i,"ownerid");
			 $ownertype = $this->db->query_result($result,$i,"ownertype");
			 if($ownertype == 'user')
				 $name = getUserName($ownerid);
			 elseif($ownertype == 'customer')
			 {
				 $sql1 = 'select * from vtiger_portalinfo where id=?';
				 $name = $this->db->query_result($this->db->pquery($sql1, array($ownerid)),0,'user_name');
			 }

			 $output[$i]['comments'] = nl2br($this->db->query_result($result,$i,"comments"));
			 $output[$i]['owner'] = $name;
			 $output[$i]['createdtime'] = $this->db->query_result($result,$i,"createdtime");
		 }
		$log->debug("Exiting get_dr_comments_list method ...");
		 return $output;
	 }

	/**     Function to get the list of comments for the given dailyreports id 
	 *      @param  int  $dailyreportsid - Dailyreports id
	 *      @return list $list - return the list of comments and comment informations as a html output where as these comments and comments informations will be formed in div tag.
	**/
	function getCommentInformation($dailyreportsid)
	{
		global $log;
		$log->debug("Entering getCommentInformation(".$dailyreportsid.") method ...");
		global $adb;
		global $mod_strings, $default_charset;
		$sql = "select * from vtiger_drcomments where dailyreportsid=?";
		$result = $adb->pquery($sql, array($dailyreportsid));
		$noofrows = $adb->num_rows($result);

		//In ajax save we should not add this div
		if($_REQUEST['action'] != 'DialyreportsAjax')
		{
			$list .= '<div id="comments_div" style="overflow: auto;height:200px;width:100%;">';
			$enddiv = '</div>';
		}
		for($i=0;$i<$noofrows;$i++)
		{
			if($adb->query_result($result,$i,'comments') != '')
			{
				//this div is to display the comment
				$comment = $adb->query_result($result,$i,'comments');
				// Asha: Fix for ticket #4478 . Need to escape html tags during ajax save.
				if($_REQUEST['action'] == 'DailyreportsAjax') {
					$comment = htmlentities($comment, ENT_QUOTES, $default_charset);
				}
				$list .= '<div valign="top" style="width:99%;padding-top:10px;" class="dataField">';
				$list .= make_clickable(nl2br($comment));

				$list .= '</div>';

				//this div is to display the author and time
				$list .= '<div valign="top" style="width:99%;border-bottom:1px dotted #CCCCCC;padding-bottom:5px;" class="dataLabel"><font color=darkred>';
				$list .= $mod_strings['LBL_AUTHOR'].' : ';

				if($adb->query_result($result,$i,'ownertype') == 'user')
					$list .= getUserName($adb->query_result($result,$i,'ownerid'));
				elseif($adb->query_result($result,$i,'ownertype') == 'customer') {
					$contactid = $adb->query_result($result,$i,'ownerid');
					$list .= getContactName($contactid);
				}
				$list .= ' on '.$adb->query_result($result,$i,'createdtime').' &nbsp;';

				$list .= '</font></div>';
			}
		}
		
		$list .= $enddiv;

		$log->debug("Exiting getCommentInformation method ...");
		return $list;
	}

        function get_history($id)
        {
                        global $log;
                        $log->debug("Entering get_history(".$id.") method ...");
                        $query = "SELECT
					vtiger_activity.activityid,
					vtiger_activity.subject,
					vtiger_activity.status,
					vtiger_activity.eventstatus,
					vtiger_activity.activitytype,
					vtiger_activity.date_start,
					vtiger_activity.due_date,
					vtiger_activity.time_start,
					vtiger_activity.time_end,
					vtiger_crmentity.modifiedtime,
					vtiger_crmentity.createdtime,
					vtiger_crmentity.description,
					case when (vtiger_users.user_name not like '') then vtiger_users.user_name else vtiger_groups.groupname end as user_name
				FROM	vtiger_activity
					inner join vtiger_crmentity on vtiger_crmentity.crmid=vtiger_activity.activityid
					left join vtiger_groups on vtiger_groups.groupid=vtiger_crmentity.smownerid
					left join vtiger_users on vtiger_users.id=vtiger_crmentity.smownerid
				WHERE (vtiger_activity.activitytype != 'Emails')
				AND
					(vtiger_activity.status = 'Completed' or vtiger_activity.status = 'Deferred' or (vtiger_activity.eventstatus = 'Held' and vtiger_activity.eventstatus != ''))
				AND
					vtiger_crmentity.deleted = 0
				AND
					date(vtiger_activity.date_start) ='" .$this->column_fields['ReportsDate']."'
				AND
					vtiger_crmentity.smownerid =". $this->column_fields['assigned_user_id'];

                //Don't add order by, because, for security, one more condition will be added with this query in include/RelatedListView.php

                $log->debug("Exiting get_history method ...");
                return getHistory('Dailyreports',$query,$id);
        }

	/** 
	 * Handle saving related module information.
	 * NOTE: This function has been added to CRMEntity (base class).
	 * You can override the behavior by re-defining it here.
	 */
	// function save_related_module($module, $crmid, $with_module, $with_crmid) { }
	
	/**
	 * Handle deleting related module information.
	 * NOTE: This function has been added to CRMEntity (base class).
	 * You can override the behavior by re-defining it here.
	 */
	//function delete_related_module($module, $crmid, $with_module, $with_crmid) { }

	/**
	 * Handle getting related list information.
	 * NOTE: This function has been added to CRMEntity (base class).
	 * You can override the behavior by re-defining it here.
	 */
	//function get_related_list($id, $cur_tab_id, $rel_tab_id, $actions=false) { }

	/**
	 * Handle getting dependents list information.
	 * NOTE: This function has been added to CRMEntity (base class).
	 * You can override the behavior by re-defining it here.
	 */
	//function get_dependents_list($id, $cur_tab_id, $rel_tab_id, $actions=false) { }
}
?>
