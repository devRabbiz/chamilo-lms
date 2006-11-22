<?php // $Id: document.php 10124 2006-11-22 17:19:37Z elixir_inter $
/*
==============================================================================
	Dokeos - elearning and course management software

	Copyright (c) 2004-2005 Dokeos S.A.
	Copyright (c) 2003 Ghent University (UGent)
	Copyright (c) 2001 Universite catholique de Louvain (UCL)
	Copyright (c) various contributors

	For a full list of contributors, see "credits.txt".
	The full license can be read in "license.txt".

	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU General Public License
	as published by the Free Software Foundation; either version 2
	of the License, or (at your option) any later version.

	See the GNU General Public License for more details.

	Contact address: Dokeos, 44 rue des palais, B-1030 Brussels, Belgium
	Mail: info@dokeos.com
==============================================================================
*/
/**
==============================================================================
* Main script for the documents tool
*
* This script allows the user to manage files and directories on a remote http server.
*
* The user can : - navigate through files and directories.
*				 - upload a file
*				 - delete, copy a file or a directory
*				 - edit properties & content (name, comments, html content)
*
* The script is organised in four sections.
*
* 1) Execute the command called by the user
*				Note: somme commands of this section are organised in two steps.
*			    The script always begins with the second step,
*			    so it allows to return more easily to the first step.
*
*				Note (March 2004) some editing functions (renaming, commenting)
*				are moved to a separate page, edit_document.php. This is also
*				where xml and other stuff should be added.
*
* 2) Define the directory to display
*
* 3) Read files and directories from the directory defined in part 2
* 4) Display all of that on an HTML page
*
* @todo eliminate code duplication between
* document/document.php, scormdocument.php
*
* @package dokeos.document
==============================================================================
*/

/*
==============================================================================
		INIT SECTION
==============================================================================
*/

$langFile = "document";
include("../inc/global.inc.php");
$this_section=SECTION_COURSES;

include('document.inc.php');
include(api_get_path(LIBRARY_PATH).'/xajax/xajax.inc.php');


$xajax = new Xajax();
//$xajax -> debugOn();
$xajax -> registerFunction('launch_ppt2lp');

api_protect_course_script();

//session
if(isset($_GET['id_session']))
	$_SESSION['id_session'] = $_GET['id_session'];

$htmlHeadXtra[] =
"<script type=\"text/javascript\">
function confirmation (name)
{
	if (confirm(\" ". get_lang("AreYouSureToDelete") ." \"+ name + \" ?\"))
		{return true;}
	else
		{return false;}
}
</script>";

/*
-----------------------------------------------------------
	Variables
	- some need defining before inclusion of libraries
-----------------------------------------------------------
*/

//what's the current path?
//we will verify this a bit further down
if(isset($_GET['curdirpath']) && $_GET['curdirpath']!='')
{
	$curdirpath = $_GET['curdirpath'];
}
elseif (isset($_POST['curdirpath']) && $_POST['curdirpath']!='')
{
	$curdirpath = $_POST['curdirpath'];
}
else
{
	$curdirpath = '/';
}
$curdirpathurl = urlencode($curdirpath);

$course_dir   = $_course['path']."/document";
$sys_course_path = api_get_path(SYS_COURSE_PATH);
$base_work_dir = $sys_course_path.$course_dir;
$http_www = api_get_path('WEB_COURSE_PATH').$_course['path'].'/document';
$dbl_click_id = 0; // used to avoid double-click
$is_allowed_to_edit = api_is_allowed_to_edit();
$group_member_with_upload_rights = false;

//if the group id is set, we show them group documents
if(isset($_SESSION['_gid']) && $_SESSION['_gid']!='')
{
	//needed for group related stuff
	include_once(api_get_path(LIBRARY_PATH) . 'groupmanager.lib.php');
	//get group info
	$group_properties = GroupManager::get_group_properties($_SESSION['_gid']);
	$noPHP_SELF=true;
	//let's assume the user cannot upload files for the group
	$group_member_with_upload_rights = false;

	if($group_properties['doc_state']==2) //documents are private
	{
		if($is_allowed_to_edit || GroupManager :: is_user_in_group($_user['user_id'],$_SESSION['_gid'])) //only courseadmin or group members (members + tutors) allowed
		{
			$to_group_id = $_SESSION['_gid'];
			$req_gid = '&amp;gidReq='.$_SESSION['_gid'];
			$interbreadcrumb[]= array ("url"=>"../group/group.php", "name"=> get_lang('Groups'));
			$interbreadcrumb[]= array ("url"=>"../group/group_space.php?gidReq=".$_SESSION['_gid'], "name"=> get_lang('GroupSpace'));
			//they are allowed to upload
			$group_member_with_upload_rights = true;
		}
		else
		{
			$to_group_id = 0;
			$req_gid = '';
		}
	}
	elseif($group_properties['doc_state']==1)  //documents are public
	{
		$to_group_id = $_SESSION['_gid'];
		$req_gid = '&amp;gidReq='.$_SESSION['_gid'];
		$interbreadcrumb[]= array ("url"=>"../group/group_space.php?gidReq=".$_SESSION['_gid'], "name"=> get_lang('GroupSpace'));
		//allowed to upload?
		if($is_allowed_to_edit || GroupManager::is_subscribed($_user['user_id'],$_SESSION['_gid'])) //only courseadmin or group members can upload
		{
			$group_member_with_upload_rights = true;
		}
	}
	else //documents not active for this group
	{
		$to_group_id = 0;
		$req_gid = '';
	}
	$_SESSION['group_member_with_upload_rights'] = $group_member_with_upload_rights;
}
else
{
	$to_group_id = 0;
	$req_gid = '';
}

/*
-----------------------------------------------------------
	Libraries
-----------------------------------------------------------
*/
//the main_api.lib.php, database.lib.php and display.lib.php
//libraries are included by default

include_once(api_get_path(LIBRARY_PATH) . 'fileDisplay.lib.php');
include_once(api_get_path(LIBRARY_PATH) . 'events.lib.inc.php');
include_once(api_get_path(LIBRARY_PATH) . 'document.lib.php');
include_once(api_get_path(LIBRARY_PATH) . 'tablesort.lib.php');
include_once(api_get_path(LIBRARY_PATH) . 'fileUpload.lib.php');


//-----------------------------------------------------------
//check the path
//if the path is not found (no document id), set the path to /
if(!DocumentManager::get_document_id($_course,$curdirpath)){
	$curdirpath = '/';
	//urlencoded version
	$curdirpathurl = '%2F';
}
//if they are looking at group documents they can't see the root
if($to_group_id!=0 && $curdirpath=='/')
{
	$curdirpath = $group_properties['directory'];
	$curdirpathurl = urlencode($group_properties['directory']);
}
//-----------------------------------------------------------

/*
-----------------------------------------------------------
	Constants and variables
-----------------------------------------------------------
*/

$course_quota = DocumentManager::get_course_quota();

/*
==============================================================================
		MAIN SECTION
==============================================================================
*/



/**
 * Function called with xajax.
 * It create a directory named like the powerpoint file and generate slides from it.
 * @param $file the path of the powerpoint document
 * @return XajaxResponse object
 */
function launch_ppt2lp($file){

	global $_course,$_user, $openoffice_conf, $base_work_dir;

	$response = new XajaxResponse();
	// lp tables
	$tbl_learnpath_item     = Database::get_course_table(LEARNPATH_ITEM_TABLE);
	$tbl_learnpath_chapter  = Database::get_course_table(LEARNPATH_CHAPTER_TABLE);
	$tbl_learnpath_main     = Database::get_course_table(LEARNPATH_MAIN_TABLE);
	$tbl_tool               = Database::get_course_table(TOOL_LIST_TABLE);

	$file = urldecode($file);

	// get properties of ppt file
	$document_datas = DocumentManager::get_all_document_data($_course, $file);
	$to_group_id = (empty($document_datas['to_group_id'])) ? 0 : $document_datas['to_group_id'];
	$to_user_id = (empty($document_datas['to_user_id'])) ? null : $document_datas['to_user_id'];

	//create the directory
	$added_slash = '/';
	$dir_name = $added_slash.substr($file, 1, strrpos($file,'.')-1);
	$created_dir = create_unexisting_directory($_course,$_user['user_id'],$to_group_id,$to_user_id,$base_work_dir,$dir_name);

	chmod ($base_work_dir.$created_dir,0777);
	/*
	 * exec java application
	 * the parameters of the program are :
	 * - javacommand on this server ;
	 * - host where openoffice is running;
	 * - port with which openoffice is listening
	 * - file to convert
	 * - folder where put the slides
	 * - ftppassword if required
	 * The program fills $files with the list of slides created
	 */
	$cmd = 'cd '.api_get_path(LIBRARY_PATH).'/ppt2png && ./launch_ppt2png.sh '.$openoffice_conf['javacommand'].' '.$openoffice_conf['host'].' '.$openoffice_conf['port'].' "'.$base_work_dir.$file.'" "'.$base_work_dir.$created_dir.'" '.$openoffice_conf['ftppasswd'];

	$files = array();

	$shell = exec($cmd, $files, $return);

	chmod ($base_work_dir.$created_dir,0744);
	if($return != 0) { //if the java application returns an error code
		DocumentManager::delete_document($_course, $dir_name, $base_work_dir);

		$response -> addAssign('ajax_response', 'innerHTML', '<div class="error-message">'.get_lang('nookppt2lp').' : '.$return.$cmd.'</div>');
		$response -> addScript('document.getElementById("documents_content").style.display="block"');
	}
	else {
		// create lp
		$learnpath_name = 'lp_';
		$learnpath_name .= substr($_GET['file'], strrpos($_GET['file'],'/')+1);
		$learnpath_name = substr($learnpath_name,0, strrpos($learnpath_name,'.'));
		$learnpath_description = '';
		$sql ="INSERT INTO $tbl_learnpath_main (learnpath_name, learnpath_description) VALUES ('".domesticate($learnpath_name)."','".domesticate($learnpath_description)."')";
		api_sql_query($sql,__FILE__,__LINE__);
		$my_lp_id = Database::get_last_insert_id();

		$sql ="INSERT INTO $tbl_tool (name, link, image, visibility, admin, address, added_tool) VALUES ('".domesticate($learnpath_name)."','learnpath/learnpath_handler.php?learnpath_id=$my_lp_id','scormbuilder.gif','1','0','squaregrey.gif',0)";
		api_sql_query($sql,__FILE__,__LINE__);

		// insert a new module
		$title = 'slides';
		$omschrijving = '';
		$order = 1;
		$sql ="INSERT INTO $tbl_learnpath_chapter (learnpath_id, chapter_name, chapter_description, display_order) VALUES ('".domesticate($my_lp_id)."', '".domesticate(htmlspecialchars($title))."','".domesticate(htmlspecialchars($omschrijving))."', '".domesticate($order)."')";
		$result=api_sql_query($sql,__FILE__,__LINE__);
		$chapter_id = Database::get_last_insert_id();



		$order = 1;
		foreach($files as $file){
			$document_id = add_document($_course,$created_dir.'/'.$file,'file',filesize($base_work_dir.$created_dir.'/'.$file),$file);
			if ($document_id){
				//put the document in item_property update
				api_item_property_update($_course,TOOL_DOCUMENT,$document_id,'DocumentAdded',$_SESSION['_uid'],$to_group_id,$to_user_id);
				$sql = "INSERT INTO $tbl_learnpath_item (chapter_id, item_type, item_id, display_order) VALUES ('$chapter_id', 'Document','".$document_id."','".$order."')";
				$result = api_sql_query($sql, __FILE__, __LINE__);
				$order++;
			}
		}
		// redirect on the lp view
		$response -> addScript('document.location="../learnpath/showinframes.php?source_id=5&action=&learnpath_id='.$my_lp_id.'&chapter_id=&originalresource=no";');


	}
	return $response;
}
$xajax -> processRequests();




//-------------------------------------------------------------------//
if ($_GET['action']=="download")
{
	//check if the document is in the database
	if(!DocumentManager::get_document_id($_course,$_GET['id']))
	{
		//file not found!
		header("HTTP/1.0 404 Not Found");
		$error404 = '<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">';
		$error404 .= '<html><head>';
		$error404 .= '<title>404 Not Found</title>';
		$error404 .= '</head><body>';
		$error404 .= '<h1>Not Found</h1>';
		$error404 .= '<p>The requested URL was not found on this server.</p>';
		$error404 .= '<hr>';
		$error404 .= '</body></html>';
		echo($error404);
		exit;
	}
	// launch event
	event_download($_GET['id']);
	$doc_url=$_GET['id'];
	$full_file_name = $base_work_dir.$doc_url;
	DocumentManager::file_send_for_download($full_file_name,true);
	exit;
 }
//-------------------------------------------------------------------//

//download of an completed folder
if($_GET['action']=="downloadfolder")
{
	include('downloadfolder.inc.php');
}
//-------------------------------------------------------------------//

// slideshow inititalisation
$_SESSION["image_files_only"]="";
$image_files_only="";

/*
-----------------------------------------------------------
	Header
-----------------------------------------------------------
*/

$tool_name = get_lang("Doc"); // title of the page (should come from the language file)
Display::display_header($tool_name,"Doc");
$is_allowed_to_edit  = api_is_allowed_to_edit();



/*
 * Lib for event log, stats & tracking
 * plus record of the access
 */
event_access_tool(TOOL_DOCUMENT);

/*
==============================================================================
		DISPLAY
==============================================================================
*/
if($to_group_id !=0) //add group name after for group documents
{
	$add_group_to_title = ' ('.$group_properties['name'].')';
}
//api_display_tool_title($tool_name.$add_group_to_title);



	/*======================================
				PPT2LP SECTION
	  ======================================*/


$xajax -> printJavascript('../inc/lib/xajax/');

echo '<script type="text/javascript">
			function prepare_ppt2lp(file){
				document.getElementById("ajax_response").style.display = "block";
				document.getElementById("documents_content").style.display="none";
				xajax_launch_ppt2lp(file);
			}
		</script>';

echo '<div id="ajax_response" align="center" width="100%" style="display:none">
		'.get_lang('Wait').'<br /><br />
		<img src="../img/anim-loader.gif" />
	  </div>';



echo '<div id="documents_content">';



/*
-----------------------------------------------------------
	Introduction section
	(editable by course admins)
-----------------------------------------------------------
*/

Display::display_introduction_section(TOOL_DOCUMENT);

/*============================================================================*/

if($is_allowed_to_edit || $group_member_with_upload_rights) // TEACHER ONLY
{

	/*======================================
				MOVE FILE OR DIRECTORY
	  ======================================*/

	if (isset($_GET['move']) && $_GET['move']!='')
	{
		if(DocumentManager::get_document_id($_course,$_GET['move']))
		{
			$folders = DocumentManager::get_all_document_folders($_course,$to_group_id,$is_allowed_to_edit || $group_member_with_upload_rights);
			Display::display_normal_message(build_move_to_selector($folders,$_GET['curdirpath'],$_GET['move'],$group_properties['directory']));
		}
	}

	if (isset($_POST['move_to']) && isset($_POST['move_file']))
	{
		include_once(api_get_path(LIBRARY_PATH) . 'fileManage.lib.php');
		//this is needed for the update_db_info function
		//$dbTable = $_course['dbNameGlu']."document";
		$dbTable = Database::get_course_table(DOCUMENT_TABLE);

		//security fix: make sure they can't move files that are not in the document table
		if(DocumentManager::get_document_id($_course,$_POST['move_file'])) {

			//Display::display_normal_message('We want to move '.$_POST['move_file'].' to '.$_POST['move_to']);
			if ( move($base_work_dir.$_POST['move_file'],$base_work_dir.$_POST['move_to']) )
			{

				update_db_info("update", $_POST['move_file'], $_POST['move_to']."/".basename($_POST['move_file']));
				//set the current path
				$curdirpath = $_POST['move_to'];
				$curdirpathurl = urlencode($_POST['move_to']);
				Display::display_normal_message(get_lang('DirMv'));
			}
			else
			{
				Display::display_error_message(get_lang('Impossible'));
			}
		}
		else
		{
			Display::display_error_message(get_lang('Impossible'));
		}
	}


	/*======================================
			DELETE FILE OR DIRECTORY
	  ======================================*/

	if ( isset($_GET['delete']) )
	{
		include_once(api_get_path(LIBRARY_PATH) . 'fileManage.lib.php');
		if(DocumentManager::delete_document($_course,$_GET['delete'],$base_work_dir))
		{
			Display::display_normal_message(get_lang('DocDeleted'));
		}
		else
		{
			Display::display_normal_message(get_lang('DocDeleteError'));
		}
	}

	if( isset($_POST['action']))
	{
		switch($_POST['action'])
		{
			case 'delete':
				foreach($_POST['path'] as $index => $path)
				{
					DocumentManager::delete_document($_course,$path,$base_work_dir);
				}
				Display::display_normal_message(get_lang('DocDeleted'));
				break;
		}
	}

	/*======================================
	   		CREATE DIRECTORY
	  ======================================*/

	//create directory with $_POST data
	if(isset($_POST['create_dir']) && $_POST['dirname']!='')
	{
		//needed for directory creation
		include_once(api_get_path(LIBRARY_PATH) . 'fileUpload.lib.php');
		$added_slash = ($curdirpath=='/')?'':'/';
		$dir_name = $curdirpath.$added_slash.replace_dangerous_char($_POST['dirname']);
		$created_dir = create_unexisting_directory($_course,$_user['user_id'],$to_group_id,$to_user_id,$base_work_dir,$dir_name,$_POST['dirname']);
		if($created_dir)
		{
			//Display::display_normal_message("<strong>".$created_dir."</strong> was created!");
			Display::display_normal_message('<span title="'.$created_dir.'">'.get_lang('DirCr').'</span>');
			//uncomment if you want to enter the created dir
			//$curdirpath = $created_dir;
			//$curdirpathurl = urlencode($curdirpath);
		}
		else
		{
			Display::display_error_message(get_lang('CannotCreateDir'));
		}
	}
	//show them the form for the directory name
	if(isset($_GET['createdir']))
	{
		//create the form that asks for the directory name
		$new_folder_text = '<form action="'.$_SERVER['PHP_SELF'].'" method="post">';
		$new_folder_text .= '<input type="hidden" name="curdirpath" value="'.$curdirpath.'" />';
		$new_folder_text .= get_lang('NewDir') .' ';
		$new_folder_text .= '<input type="text" name="dirname" />';
		$new_folder_text .= '<input type="submit" name="create_dir" value="'.get_lang('Ok').'" />';
		$new_folder_text .= '</form>';
		//show the form
		Display::display_normal_message($new_folder_text);
	}


	/*======================================
	   	  VISIBILITY COMMANDS
	  ======================================*/

	if ((isset($_GET['set_invisible']) && !empty($_GET['set_invisible'])) || (isset($_GET['set_visible']) && !empty($_GET['set_visible'])) AND $_GET['set_visible']<>'*' AND $_GET['set_invisible']<>'*')
	{
		//make visible or invisible?
		if(isset($_GET['set_visible']))
		{
			$update_id = $_GET['set_visible'];
			$visibility_command = 'visible';
		}
		else
		{
			$update_id = $_GET['set_invisible'];
			$visibility_command = 'invisible';
		}
		//update item_property to change visibility
		if(api_item_property_update($_course, TOOL_DOCUMENT, $update_id, $visibility_command, $_user['user_id']))
		{
			Display::display_normal_message(get_lang("ViMod"));
		}
		else
		{
			Display::display_error_message(get_lang("ViModProb"));
		}

	}
} // END is allowed to edit

/*
-----------------------------------------------------------
	GET ALL DOCUMENT DATA FOR CURDIRPATH
-----------------------------------------------------------
*/

$docs_and_folders = DocumentManager::get_all_document_data($_course,$curdirpath,$to_group_id,NULL,$is_allowed_to_edit || $group_member_with_upload_rights);

?>


<div id="folderselector" style="float:left;margin-right:10px;margin-top:5px;">
<?php
$folders = DocumentManager::get_all_document_folders($_course,$to_group_id,$is_allowed_to_edit || $group_member_with_upload_rights);
echo(build_directory_selector($folders,$curdirpath,$group_properties['directory']));
?>
</div>

	<?php
	echo "<div id=\"doc_links\">";
	
	
	/* GO TO PARENT DIRECTORY */

	if ($curdirpath!= '/'&& $curdirpath!=$group_properties['directory'])
	{
	?>
		 <a href="<?php echo $_SERVER['PHP_SELF']; ?>?curdirpath=<?php echo urlencode((dirname($curdirpath)=='\\')?'/':dirname($curdirpath)) ?>">
				<img src="../img/parent.gif" border="0" align="absbottom" hspace="5" alt="" />
				<?php echo get_lang("Up"); ?></a>&nbsp;
	<?php
	}

	if ($is_allowed_to_edit || $group_member_with_upload_rights)
	{
		/* CREATE NEW DOCUMENT OR NEW DIRECTORY / GO TO UPLOAD / DOWNLOAD ZIPPED FOLDER */
		?>
			<!-- create new document or directory -->
			<a href="create_document.php?dir=<?php echo $curdirpathurl; ?>"><img src="../img/filenew.gif" border="0" alt="" title="<?php echo get_lang('CreateDoc'); ?>" /></a>
			<a href="create_document.php?dir=<?php echo $curdirpathurl; ?>"><?php echo get_lang("CreateDoc"); ?></a>&nbsp;&nbsp;
			<!-- file upload link -->
			<a href="upload.php?path=<?php echo $curdirpathurl.$req_gid; ?>"><img src="../img/submit_file.gif" border="0" title="<?php echo get_lang('UplUploadDocument'); ?>" alt="" /></a>
			<a href="upload.php?path=<?php echo $curdirpathurl.$req_gid; ?>"><?php echo get_lang('UplUploadDocument'); ?></a>&nbsp;
			<!-- create directory -->
			<a href="<?php echo $_SERVER['PHP_SELF']; ?>?curdirpath=<?php echo $curdirpathurl; ?>&amp;createdir=1"><img src="../img/folder_new.gif" border="0" alt ="" /></a>
			<a href="<?php echo $_SERVER['PHP_SELF']; ?>?curdirpath=<?php echo $curdirpathurl; ?>&amp;createdir=1"><?php echo get_lang("CreateDir"); ?></a>&nbsp;
		<?php
	}
	?>
	<!-- download zipped folder -->
	<a href="<?php echo $_SERVER['PHP_SELF']; ?>?action=downloadfolder&amp;path=<?php echo $curdirpathurl; ?>"><img src="../img/zip_save.gif" border="0" title="<?php echo get_lang("Save"); ?> (ZIP)" alt="" /></a>
	<a href="<?php echo $_SERVER['PHP_SELF']; ?>?action=downloadfolder&amp;path=<?php echo $curdirpathurl; ?>"><?php echo get_lang("Save"); ?> (ZIP)</a>&nbsp;
	<?php
	// Slideshow by Patrick Cool, May 2004
	include("document_slideshow.inc.php");
	// including the language file for the slideshow
	include(api_get_path(SYS_LANG_PATH).$languageInterface."/"."slideshow.inc.php");
	if ($image_present)
	{
		echo "<a href=\"slideshow.php?curdirpath=".$curdirpathurl."\"><img src=\"../img/images_gallery.gif\" border=\"0\" title=\"".get_lang('ViewSlideshow')."\"/>&nbsp;". get_lang('ViewSlideshow') . "</a>";
	}
	echo "</div>";

//==============================================================================

if($docs_and_folders)
{
	//echo('<pre>');
	//print_r($docs_and_folders);
	//echo('</pre>');
	//*************************************************************************************************
	//do we need the title field for the document name or not?
	//we get the setting here, so we only have to do it once
	$use_document_title = get_setting('use_document_title');
	//create a sortable table with our data
	$sortable_data = array();
	while (list ($key, $id) = each($docs_and_folders))
	{
		$row = array ();

		//if the item is invisible, wrap it in a span with class invisible
		$invisibility_span_open = ($id['visibility']==0)?'<span class="invisible">':'';
		$invisibility_span_close = ($id['visibility']==0)?'</span>':'';
		//size (or total size of a directory)
		$size = $id['filetype']=='folder' ? get_total_folder_size($id['path'],$is_allowed_to_edit) : $id[size];
		//get the title or the basename depending on what we're using
		if ($use_document_title=='true' AND $id['title']<>'')
		{
			$document_name=$id['title'];
		}
		else
		{
			$document_name=basename($id['path']);
		}
		//$row[] = $key; //testing
		//data for checkbox
		if (($is_allowed_to_edit || $group_member_with_upload_rights) AND count($docs_and_folders)>1)
		{
			$row[] = $id['path'];
		}
		//icons
		$row[]= build_document_icon_tag($id['filetype'],$id['path']);
		//document title with hyperlink
		$row[] = create_document_link($http_www,$document_name,$id['path'],$id['filetype'],$size,$id['visibility']).'<br />'.$invisibility_span_open.nl2br(htmlspecialchars($id['comment'])).$invisibility_span_close;
		//comments => display comment under the document name
		//$row[] = $invisibility_span_open.nl2br(htmlspecialchars($id['comment'])).$invisibility_span_close;
		$display_size = format_file_size($size);
		$row[] = '<span style="display:none;">'.$size.'</span>'.$invisibility_span_open.$display_size.$invisibility_span_close;
		//last edit date
		$display_date = format_date(strtotime($id['lastedit_date']));
		$row[] = '<span style="display:none;">'.$id['lastedit_date'].'</span>'.$invisibility_span_open.$display_date.$invisibility_span_close;

		//admins get an edit column
		if ($is_allowed_to_edit || $group_member_with_upload_rights)
		{
			$edit_icons = build_edit_icons($curdirpath,$id['filetype'],$id['path'],$id['visibility'],$key);
			// if ppt2lp is actived and it's a ppt-like document, we display a link on which an ajax event is linked : prepare_ppt2lp (see upper)
			if(in_array(substr($id['path'],strrpos($id['path'],'.')+1),array("ppt","odp"))){
				$edit_icons .= '<a href="#" onclick="prepare_ppt2lp(\''.urlencode($id['path']).'\')" title="'.get_lang('Ppt2lp').'" alt="'.get_lang('Ppt2lp').'"><img src="../img/recycle.gif" border="0" /></a>';
			}
			$row[] = $edit_icons;
		}
		$sortable_data[] = $row;
	}
	//*******************************************************************************************

}
else
{
	$sortable_data='';
	$table_footer='<div style="text-align:center;"><strong>'.get_lang('NoDocsInFolder').'</strong></div>';
	//echo('<p><strong>'.get_lang('NoDocsInFolder').'</strong></p>');
}




$table = new SortableTableFromArray($sortable_data,2,100);
$query_vars['curdirpath'] = $curdirpath;
if(isset($_SESSION['_gid']))
{
	$query_vars['gidReq'] = $_SESSION['_gid'];
}
$table->set_additional_parameters($query_vars);
$column = 0;
if (($is_allowed_to_edit || $group_member_with_upload_rights) AND count($docs_and_folders)>1)
{
	$table->set_header($column++,'',false);
}
$table->set_header($column++,get_lang('Type'));
$table->set_header($column++,get_lang('Name'));

//$column_header[] = array(get_lang('Comment'),true);  => display comment under the document name
$table->set_header($column++,get_lang('Size'));
$table->set_header($column++,get_lang('Date'));
//admins get an edit column
if ($is_allowed_to_edit || $group_member_with_upload_rights)
{
	$table->set_header($column++,'',false);
}

//actions on multiple selected documents
//currently only delete action -> take only DELETE right into account
if (count($docs_and_folders)>1)
{
	if ($is_allowed_to_edit || $group_member_with_upload_rights)
	{
		$form_actions = array();
		$form_action['delete'] = get_lang('Delete');
		$table->set_form_actions($form_action,'path');
	}
}

$table->display();
echo $table_footer;


/*
==============================================================================
		Quota section

		Proposal: perhaps move/add the quota display to another section, e.g. course info
==============================================================================
*/
if ($is_allowed_to_edit) display_document_options();

/*
==============================================================================
		Footer
==============================================================================
*/
echo '</div>';
Display::display_footer();
?>
