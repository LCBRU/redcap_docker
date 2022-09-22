<?php


require_once dirname(dirname(__FILE__)) . '/Config/init_project.php';

System::increaseMemory(2048);

// Calculate class
$cp = new Calculate();
// BranchingLogic class
$bl = new BranchingLogic();

// Initialize DAGs, if any are defined
$Proj->getGroups();

// If server-side validation is still in session somehow and wasn't removed, then remove it now
if (isset($_SESSION['serverSideValErrors']) && !isset($_GET['serverside_error_fields'])) {
	unset($_SESSION['serverSideValErrors']);
}

// FAILSAFE: If user was submitting data on form and somehow the auth session ends before it's supposed to,
// take posted data, encrypt it, and carry it over after new login.
if (isset($_POST['redcap_login_post_encrypt_e3ai09t0y2']))
{
	$post_temp = unserialize(decrypt($_POST['redcap_login_post_encrypt_e3ai09t0y2']), ['allowed_classes'=>false]);
	if (is_array($post_temp))
	{
		// Replace login post values with submitted data values
		$_POST = $post_temp;
		unset($post_temp);
	}
}

// Alter how records are saved if project is Double Data Entry (i.e. add --# to end of Study ID)
$entry_num = ($double_data_entry && $user_rights['double_data'] != 0) ? "--".$user_rights['double_data'] : "";

// Set and clean the record name ($fetched)
if (isset($_POST['submit-action'])) {
	$fetched = strip_tags(label_decode($_POST[$table_pk]));
} elseif (isset($_GET['id'])) {
	$fetched = $_GET['id'] = strip_tags(label_decode(urldecode($_GET['id'])));
}


// Check if event_id exists in URL. If not, then this is not "longitudinal" and has one event, so retrieve event_id.
if (!isset($_GET['event_id']) || $_GET['event_id'] == "" || !is_numeric($_GET['event_id']))
{
	$_GET['event_id'] = getSingleEvent(PROJECT_ID);
}

// Ensure the event_id belongs to this project, and additionally if longitudinal, can be used with this form
if (!$Proj->validateEventId($_GET['event_id'])
	// Check if form has been designated for this event
    || !$Proj->validateFormEvent($_GET['page'], $_GET['event_id'])
	// Reload page if event_id is not numeric or if id is a blank value
	|| (isset($_GET['id']) && trim($_GET['id']) == "") )
{
	if ($longitudinal) {
		redirect(APP_PATH_WEBROOT . "DataEntry/record_home.php?pid=" . PROJECT_ID);
	} else {
		redirect(APP_PATH_WEBROOT . "DataEntry/index.php?pid=" . PROJECT_ID . "&page=" . $_GET['page']);
	}
}


// Auto-number logic (pre- and post-submission of new record)
if ($auto_inc_set)
{
	// If the auto-number record submitted/selected has already been created by another user, fetch the next one to prevent overlapping data
	if ((!isset($_POST['submit-action']) && isset($_GET['id']) && isset($_GET['auto']))
		|| (isset($_POST['submit-action']) && (substr($_POST['submit-action'], 0, 11) == 'submit-btn-' || substr($_POST['submit-action'], 0, 5) == 'save-')
			&& $_POST['hidden_edit_flag'] == 0))
	{
		if (Records::recordExists(PROJECT_ID, $fetched, null, true)) {
			// Record already exists, so generate the next one (use auto and redirect in the URL to ensure we don't redirect more than once)
			$fetched = DataEntry::getAutoId(PROJECT_ID, false);
			if (isset($_POST['submit-action'])) {
				// Change submitted record value
				$_POST[$table_pk] = $fetched;
			} else {
				// If record already exists, redirect to new page with this new record value
				redirect(PAGE_FULL . "?pid=$project_id&page={$_GET['page']}&event_id={$_GET['event_id']}&id=$fetched&auto=1");
			}
		}
	}
}

// Collect all form names usable for this Event in an array for later use
$all_forms  = $Proj->eventsForms[$_GET['event_id']];
$first_form = $all_forms[0];
$last_form  = $all_forms[count($all_forms)-1];


// REPEATING FORMS/EVENTS: Check for "instance" number if the form is set to repeat
$isRepeatingFormOrEvent = $Proj->isRepeatingFormOrEvent($_GET['event_id'], $_GET['page']);
$isRepeatingForm = ($isRepeatingFormOrEvent && $Proj->isRepeatingForm($_GET['event_id'], $_GET['page']));
$isRepeatingEvent = ($isRepeatingFormOrEvent && $Proj->isRepeatingEvent($_GET['event_id']));
$hasRepeatingFormsEvents = !empty($Proj->RepeatingFormsEvents);
$instanceNum = ($isRepeatingForm && $_GET['instance'] > 1) ? "<span style='margin-left:5px;font-weight:normal;'>({$lang['data_entry_278']}{$_GET['instance']})</span>" : "";

// Set up context messages to users for actions performed
$context_msg_update = "<div class='darkgreen' style='margin:8px 0 5px;'><img src='".APP_PATH_IMAGES."tick.png'> " . strip_tags(label_decode($table_pk_label)) . " <b>" . (isset($fetched) ? $fetched.$instanceNum : '') . "</b> {$lang['data_entry_08']}</div>";
$context_msg_insert = "<div class='darkgreen'><img src='".APP_PATH_IMAGES."tick.png'> " . strip_tags(label_decode($table_pk_label)) . " <b>" . (isset($fetched) ? $fetched.$instanceNum : '') . "</b> {$lang['data_entry_09']}</div>";
$context_msg_cancel = "<div class='red'><img src='".APP_PATH_IMAGES."exclamation.png'> " . strip_tags(label_decode($table_pk_label)) . " <b>" . (isset($fetched) ? $fetched.$instanceNum : '') . "</b> {$lang['data_entry_11']}</div>";
$context_msg_edit   = "<div class='blue'><img src='".APP_PATH_IMAGES."pencil.png'> {$lang['data_entry_12']} " . strip_tags(label_decode($table_pk_label)) . " <b>" . (isset($fetched) ? $fetched.$instanceNum : '') . "</b></div>";
$context_msg_add    = "<div class='darkgreen'><img src='".APP_PATH_IMAGES."add.png'> {$lang['data_entry_14']} " . strip_tags(label_decode($table_pk_label)) . " <b>" . (isset($fetched) ? $fetched.$instanceNum : '') . "</b></div>";
$context_msg_error_existing = "<div class='red'><img src='".APP_PATH_IMAGES."exclamation.png'> " . strip_tags(label_decode($table_pk_label)) . " <b>" . (isset($fetched) ? $fetched.$instanceNum : '') . "</b> {$lang['data_entry_08']}<br/><b>{$lang['data_entry_13']} " . strip_tags(label_decode($table_pk_label)) . " {$lang['data_entry_15']}</b></div>";


################################################################################
# FORM WAS SUBMITTED - PROCESS RESULTS
if (isset($_POST['submit-action']))
{
	// ScrollTop setting for "Save and Continue" to scroll page to position
	$scrollTop = null;
	if (isset($_POST['scroll-top']) && is_numeric($_POST['scroll-top'])) {
		$scrollTop = $_POST['scroll-top'];
		unset($_POST['scroll-top']);
	}

	// ScrollTop setting for "Save and Continue" to scroll page to position
	$openDDP = null;
	if (isset($_POST['open-ddp'])) {
		$openDDP = $_POST['open-ddp'];
		unset($_POST['open-ddp']);
	}

	// Convert a normal "Save" into a "Save and Continue" so that form gets reloaded
	if (isset($_POST['save-and-continue'])) {
		unset($_POST['save-and-continue']);
		$_POST['submit-action'] = 'submit-btn-savecontinue';
	}

	// Process "Save and Redirect" so that page gets redirected
	if (isset($_POST['save-and-redirect'])) {
		$redirectUrl = $_POST['save-and-redirect'];
		unset($_POST['save-and-redirect']);
		$_POST['submit-action'] = 'save-and-redirect';
	}

	// DATA QUALITY DRW: If passed hidden field to reload DRW pop-up, get it and remove from Post
	$dqresfld = null;
	if (isset($_POST['dqres-fld']) && isset($Proj->metadata[$_POST['dqres-fld']])) {
		$dqresfld = $_POST['dqres-fld'];
		unset($_POST['dqres-fld']);
	}

	// If a new record is being created, ensure that the record name has not just been created
	// as a means of preventing merged records.
	if ($_POST['submit-action'] != 'submit-btn-cancel' && $auto_inc_set && $_POST['hidden_edit_flag'] == '0') {
		$fetched = $_POST[$table_pk] = Records::addNewAutoIdRecordToCache(PROJECT_ID, $fetched);
		if (isset($_GET['id'])) $_GET['id'] = $fetched;
	}

	// RECORD LOCKING CHECK: If user has no record locking privileges, check if this form/record/event is locked to see if they're trying to bypass security features
	// If user has e-signature privileges, then skip this check since it changes the scenario.
	if ($_POST['submit-action'] != 'submit-btn-cancel' && $user_rights['lock_record'] <= 1) 
	{
		// Check if record-event-form is locked
		$sql = "select l.username, l.timestamp, f.display_esignature from redcap_locking_data l 
				left join redcap_locking_labels f on l.project_id = f.project_id and f.form_name = l.form_name and f.display = 1
				where l.project_id = $project_id and l.record = '" . db_escape($fetched.$entry_num) . "' 
				and l.event_id = {$_GET['event_id']} and l.form_name = '" . db_escape($_GET['page']) . "'
				and l.instance = '".db_escape($_GET['instance'])."' limit 1";
		$q = db_query($sql);
		if (db_num_rows($q) > 0) {
			// Record is indeed locked
			$row = db_fetch_assoc($q);
			if ($user_rights['lock_record'] == '0') 
			{
				// If a user do not have record locking/unlocking privileges, then they should not be able to submit this form.
				exit($lang['data_entry_274']);
			}
			elseif ((strtolower(USERID) != strtolower($row['username']) || strtotime(NOW)-strtotime($row['timestamp']) > 30)
				// If form has e-signature enabled, then skip this check since it changes the scenario.
				&& $row['display_esignature'] != '1') 
			{
				// If the user just locked the form in the past 30 seconds (which is done via AJAX immediately before form submission),
				// then allow the submission. If it was locked longer ago and/or by a different user, then someone is trying to illegally modify a locked form.
				exit($lang['data_entry_273']);
			}
		}

	}

	// Check for REQUIRED FIELDS: First, check for any required fields that weren't entered (checkboxes are ignored - cannot be Required)
	if ($openDDP === null) {
		$_GET['id'] = $_POST[$Proj->table_pk] = $fetched = DataEntry::checkReqFields($fetched);
	}
	
	// Save state of 2nd save button
	$secondBtnNames = array('submit-btn-savecompresp', 'submit-btn-savecontinue', 'submit-btn-savenextform', 'submit-btn-savenextinstance', 'submit-btn-saveexitrecord', 'submit-btn-savenextrecord');
	if (in_array($_POST['submit-action'], $secondBtnNames)) {
		UIState::saveUIStateValue($project_id, 'form', 'submit-btn', str_replace('submit-btn-', '', $_POST['submit-action']));
	}

	switch ($_POST['submit-action'])
	{
		//SAVE RECORD
		case 'submit-btn-savenextrecord':
		case 'submit-btn-saveexitrecord':
		case 'submit-btn-deleteform':
		case 'submit-btn-savecompresp':
		case 'submit-btn-saverecord':
		case 'submit-btn-savecontinue':
		case 'submit-btn-savenextform':
		case 'submit-btn-savenextinstance':
		case 'save-and-redirect':

			// Set this survey response as complete in the surveys_response table
			if ($_POST['submit-action'] == "submit-btn-savecompresp")
			{
				// Set flag
				$submitMarkSurveyComplete = true;
				// Check if user has rights to do this (just in case)
				if ($enable_edit_survey_response && $user_rights['forms'][$_GET['page']] == '3') {
					// Form Status = Complete
					$_POST[$_GET['page'].'_complete'] = '2';
				} else {
					// Modify this
					$_POST['submit-action'] == 'submit-btn-saverecord';
				}
			}
			// DELETE ALL DATA ON SINGLE FORM ONLY
			elseif ($user_rights['record_delete'] && $_POST['submit-action'] == "submit-btn-deleteform")
			{
				$log_event_id = Records::deleteForm(PROJECT_ID, $fetched.$entry_num, $_GET["page"], $_GET["event_id"], $_GET["instance"]);

				// // Set any File Upload fields as deleted in the edocs table
				// if ($Proj->hasFileUploadFields) {
				// 	$sql = "update redcap_metadata m, redcap_data d, redcap_edocs_metadata e
				// 			set e.delete_date = '".NOW."' where m.project_id = $project_id
				// 			and m.project_id = d.project_id and e.project_id = m.project_id and m.element_type = 'file'
				// 			and d.field_name = m.field_name and d.value = e.doc_id and m.form_name = '".db_escape($_GET['page'])."'
				// 			and d.event_id = {$_GET['event_id']} and d.record = '".db_escape($fetched.$entry_num)."'" .
				// 			($Proj->hasRepeatingFormsEvents() ? " AND d.instance ".($_GET['instance'] == '1' ? "is NULL" : "= '".db_escape($_GET['instance'])."'") : "");
				// 	db_query($sql);
				// }
				// // Get list of all fields with data for this record on this form
				// $sql = "select distinct field_name from redcap_data where project_id = $project_id
				// 		and event_id = {$_GET['event_id']} and record = '".db_escape($fetched.$entry_num)."'
				// 		and field_name in (" . prep_implode(array_keys($Proj->forms[$_GET['page']]['fields'])) . ") and field_name != '$table_pk'" .
				// 		($Proj->hasRepeatingFormsEvents() ? " AND instance ".($_GET['instance'] == '1' ? "is NULL" : "= '".db_escape($_GET['instance'])."'") : "");
				// $q = db_query($sql);
				// $eraseFields = $eraseFieldsLogging = array();
				// while ($row = db_fetch_assoc($q)) {
				// 	// Add to field list
				// 	$eraseFields[] = $row['field_name'];
				// 	// Add default data values to logging field list
				// 	if ($Proj->isCheckbox($row['field_name'])) {
				// 		foreach (array_keys(parseEnum($Proj->metadata[$row['field_name']]['element_enum'])) as $this_code) {
				// 			$eraseFieldsLogging[] = "{$row['field_name']}($this_code) = unchecked";
				// 		}
				// 	} else {
				// 		$eraseFieldsLogging[] = "{$row['field_name']} = ''";
				// 	}
				// }
				// // Delete all responses from data table for this form (do not delete actual record name - will keep same record name)
				// $sql = "delete from redcap_data where project_id = $project_id
				// 		and event_id = {$_GET['event_id']} and record = '".db_escape($fetched.$entry_num)."'
				// 		and field_name in (" . prep_implode($eraseFields) . ")" .
				// 		($Proj->hasRepeatingFormsEvents() ? " AND instance ".($_GET['instance'] == '1' ? "is NULL" : "= '".db_escape($_GET['instance'])."'") : "");
				// db_query($sql);
				// // Longitudinal projects only
				// $sql3 = "";
				// if ($longitudinal) {
				// 	// Check if all forms on this event/instance have gray status icon (implying that we just deleted the only form with data for this event)
				// 	$formStatusValues = Records::getFormStatus(PROJECT_ID, array($fetched.$entry_num), null, null, array($_GET['event_id']=>$Proj->eventsForms[$_GET['event_id']]));
				// 	$allFormsDeletedThisEvent = true;
				// 	foreach ($formStatusValues[$fetched.$entry_num][$_GET['event_id']] as $this_form) {
				// 		if (!empty($this_form)) {
				// 			$allFormsDeletedThisEvent = false;
				// 			break;
				// 		}
				// 	}
				// 	if ($allFormsDeletedThisEvent) {
				// 		// Now check to see if other events/instances for this record have data
				// 		$sql = "select 1 from redcap_data where project_id = $project_id
				// 				and !(event_id = {$_GET['event_id']} and instance ".($_GET['instance'] == '1' ? "is NULL" : "= '".db_escape($_GET['instance'])."'").") 
				// 				and record = '".db_escape($fetched.$entry_num)."' limit 1";
				// 		$q = db_query($sql);
				// 		$otherEventsHaveData = (db_num_rows($q) > 0);
				// 		if ($otherEventsHaveData) {
				// 			// Since other events have data for this record, we should go ahead and remove ALL data from this event 
				// 			// (because we might have __GROUPID__ and record ID field stored on backend for this event still)
				// 			$sql3 = "delete from redcap_data where project_id = $project_id
				// 					and event_id = {$_GET['event_id']} and record = '".db_escape($fetched.$entry_num)."'
				// 					and instance ".($_GET['instance'] == '1' ? "is NULL" : "= '".db_escape($_GET['instance'])."'");
				// 			db_query($sql3);
				// 		}
				// 	}
				// }
				// // If this form is a survey, then set all survey response timestamps to NULL (or delete row if a non-first repeating instance)
				// $sql2 = "";
				// if ($surveys_enabled && isset($Proj->forms[$_GET['page']]['survey_id'])) 
				// {
				// 	$sql2 = "update redcap_surveys_participants p, redcap_surveys_response r
				// 			set r.first_submit_time = null, r.completion_time = null
				// 			where r.participant_id = p.participant_id and p.survey_id = {$Proj->forms[$_GET['page']]['survey_id']}
				// 			and r.record = '".db_escape($fetched.$entry_num)."' and p.event_id = {$_GET['event_id']} and r.instance = {$_GET['instance']}";
				// 	db_query($sql2);
				// 	// For repeating instruments/events, remove this instance from participant list if instance > 1
				// 	$sql4 = "";
				// 	if ($_GET['instance'] > 1 && ($Proj->isRepeatingEvent($_GET['event_id']) || $Proj->isRepeatingForm($_GET['event_id'], $_GET['page'])))
				// 	{
				// 		$sql3 = "select p.participant_id from redcap_surveys_participants p, redcap_surveys_response r
				// 				where r.participant_id = p.participant_id and p.survey_id = {$Proj->forms[$_GET['page']]['survey_id']}
				// 				and r.record = '".db_escape($fetched.$entry_num)."' and p.event_id = {$_GET['event_id']} and r.instance = {$_GET['instance']}
				// 				limit 1";
				// 		$q = db_query($sql3);
				// 		if (db_num_rows($q)) {
				// 			$participant_id = db_result($q, 0);
				// 			$sql4 = "delete from redcap_surveys_participants where participant_id = $participant_id";
				// 			db_query($sql4);
				// 		}
				// 	}
				// }
				// // Log the data change
				// $log_event_id = Logging::logEvent("$sql; $sql2; $sql3; $sql4", "redcap_data", "UPDATE", $fetched.$entry_num, implode(",\n",$eraseFieldsLogging), "Delete all record data for single form",
				// 						  "", "", "", true, null, $_GET['instance']);
				
				// Reset Post array
				$_POST = array('submit-action'=>$_POST['submit-action'], 'hidden_edit_flag'=>1);
			}
			
			// Perform server-side validation
			Form::serverSideValidation($_POST);

            // If e-Consent was performed and users should not be able to edit responses, if a survey response has been completed, then do not allow this user to overwrite the data.
		    $survey_id = isset($Proj->forms[$_GET['page']]['survey_id']) ? $Proj->forms[$_GET['page']]['survey_id'] : null;
		    $econsentWithNoUserEditing = ($survey_id != null && $Proj->surveys[$survey_id]['pdf_auto_archive'] == '2' && $Proj->surveys[$survey_id]['pdf_econsent_allow_edit'] == '0');
		    if ($econsentWithNoUserEditing) {
				$surveyResponseCompletionTime = Survey::isResponseCompleted($survey_id, $fetched, $_GET['event_id'], $_GET['instance'], true);
				if ($surveyResponseCompletionTime != '0' && $surveyResponseCompletionTime !== false) {
					include APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
				    print RCView::div(array('class'=>'red mt-5'), $lang['survey_1301']. " ".RCView::b(DateTimeRC::format_user_datetime($surveyResponseCompletionTime, 'Y-M-D_24')).$lang['period']);
					include APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
					exit;
                }
			}

			//Save the submitted data
			list ($fetched, $context_msg, $log_event_id, $dataValuesModified, $dataValuesModifiedIncludingCalcs) = DataEntry::saveRecord($fetched, true, false, false, null, true, (isset($submitMarkSurveyComplete) && $submitMarkSurveyComplete));
			
			// Set response as complete
			if (isset($submitMarkSurveyComplete) && $submitMarkSurveyComplete) 
			{
				// Call REDCap::getSurveyLink() to prefill some survey tables, first
				REDCap::getSurveyLink($fetched, $_GET['page'], $_GET['event_id'], $_GET['instance']);
				// Update survey completion time
				$sql = "update redcap_surveys_participants p, redcap_surveys_response r set r.completion_time = '".NOW."',
						r.first_submit_time = if(r.first_submit_time is null, '".NOW."', r.first_submit_time)
						where p.survey_id = ".$Proj->forms[$_GET['page']]['survey_id']."
						and p.event_id = " . $_GET['event_id'] . " and p.participant_id = r.participant_id
						and r.record = '" . db_escape($fetched) . "' and r.instance = " . $_GET['instance'];
				db_query($sql);
				// Delete any invitations/reminders
				$surveyScheduler = new SurveyScheduler(PROJECT_ID);
				$surveyScheduler->setSchedules();
				if (isset($surveyScheduler->schedules[$Proj->forms[$_GET['page']]['survey_id']][$_GET['event_id']])) {
					$schedulesToRemove = array();
					$schedulesToRemove[$Proj->forms[$_GET['page']]['survey_id']][$_GET['event_id']] = true;
					$surveyScheduler->deleteInvitationsForRecord($fetched, $schedulesToRemove);
				}
			}
			// If the form is merely saved as "Complete" but not necessarily as a Survey Complete, then still delete any invitations/reminders
			elseif (isset($Proj->forms[$_GET['page']]['survey_id']) && $_POST[$_GET['page'].'_complete'] == '2') {
				// Delete any invitations/reminders
				$surveyScheduler = new SurveyScheduler(PROJECT_ID);
				$surveyScheduler->setSchedules();
				if (isset($surveyScheduler->schedules[$Proj->forms[$_GET['page']]['survey_id']][$_GET['event_id']])) {
					$schedulesToRemove = array();
					$schedulesToRemove[$Proj->forms[$_GET['page']]['survey_id']][$_GET['event_id']] = true;
					$surveyScheduler->deleteInvitationsForRecord($fetched, $schedulesToRemove);
                }
            }

			// SET UP DATA QUALITY RUNS TO RUN IN REAL TIME WITH ANY DATA CHANGES ON FORM
			$dq_error_ruleids = '';
			// Obtain array of all user-defined DQ rules
			$dq = new DataQuality();
			// Check for any errors and return array of DQ rule_id's for those rules that were violated
			$repeat_instrument = $Proj->isRepeatingForm($_GET['event_id'], $_GET['page']) ? $_GET['page'] : "";
			$repeat_instance = ($Proj->isRepeatingEvent($_GET['event_id']) || $Proj->isRepeatingForm($_GET['event_id'], $_GET['page'])) ? $_GET['instance'] : 0;
			list ($dq_errors, $dq_errors_excluded) = $dq->checkViolationsSingleRecord($fetched, $_GET['event_id'], $_GET['page'], array(), $repeat_instance, $repeat_instrument);
			// If rules were violated, reload page and then display pop-up message about discrepancies
			if (!empty($dq_errors)) {
				// Build query string parameter
				$dq_error_ruleids = '&dq_error_ruleids=' . implode(",", array_merge($dq_errors, $dq_errors_excluded));
				// Set flag to reload the page
				$_POST['submit-action'] = "submit-btn-savecontinue";
			}
			
			// SET SERVER-SIDE VALIDATION CATCHING
			$serverside_error_fields = '';
			// If server-side validation was violated, then add to redirect URL
			if (isset($_SESSION['serverSideValErrors'])) {
				// Build query string parameter
				$serverside_error_fields = '&serverside_error_fields=' . implode(",", array_keys($_SESSION['serverSideValErrors']));
				// Remove from session
				unset($_SESSION['serverSideValErrors']);
				// Set flag to reload the page
				$_POST['submit-action'] = "submit-btn-savecontinue";
			}
			
			// MAXCHOICE ACTION TAG CATCHING
			$maxchoice_error_fields = '';
			// If server-side validation was violated, then add to redirect URL
			if (isset($_GET['maxChoiceFieldsReached'])) {
				// Build query string parameter
				$maxchoice_error_fields = '&maxchoice_error_fields=' . implode(",", $_GET['maxChoiceFieldsReached']);
				// Remove from session
				unset($_GET['maxChoiceFieldsReached']);
				// Set flag to reload the page
				$_POST['submit-action'] = "submit-btn-savecontinue";
			}

			//Adjust context_msg text if a Double Data Entry user
			$fetched_msg = ($entry_num == "") ? $fetched : substr($fetched, 0, -3);

			// Redirect to specified page
			if ($_POST['submit-action'] == "save-and-redirect" && !empty($redirectUrl))
			{
				// If $redirectUrl contains "auto" parameter, then remove it since we just saved this record
				$redirectUrlParts = parse_url($redirectUrl);
				parse_str($redirectUrlParts['query'], $redirectUrlQueryString);
				if (isset($redirectUrlQueryString['auto'])) {
					unset($redirectUrlQueryString['auto']); // Remove auto
					$redirectUrlQueryString['id'] = $fetched; // Make sure record didn't get renamed during save
					$redirectUrlParts['query'] = http_build_query($redirectUrlQueryString, '&');
					$redirectUrl = $redirectUrlParts['path']."?".$redirectUrlParts['query'];
				}
				// Redirect
				redirect($redirectUrl);
			}
			// Redirect back to home page with no record selected yet
			if ($_POST['submit-action'] == 'submit-btn-saveexitrecord') 
			{				
				redirect(APP_PATH_WEBROOT . "DataEntry/record_home.php?pid=$project_id&msg=edit&edit_id=$fetched_msg");
			}
			// Redirect back to same page if user clicked "Save and Continue" button
			elseif ($_POST['submit-action'] == "submit-btn-savecontinue")
			{
				redirect(PAGE_FULL . "?pid=$project_id&page={$_GET['page']}&id=$fetched_msg&event_id={$_GET['event_id']}&instance={$_GET['instance']}"
					. ((isset($_GET['editresp']) && $_GET['editresp']) ? "&editresp=1" : "")
					. (empty($scrollTop) ? '' : "&scrollTop=$scrollTop")
					. (empty($openDDP) ? '' : "&openDDP=1") . $dq_error_ruleids . $serverside_error_fields . $maxchoice_error_fields . "&msg=edit");
			}
			// Redirect back to same page AND pop-up the Data Resolution Workflow dialog
			elseif ($dqresfld !== null)
			{
				redirect(PAGE_FULL . "?pid=$project_id&page={$_GET['page']}&id=$fetched_msg&event_id={$_GET['event_id']}&instance={$_GET['instance']}" . ((isset($_GET['editresp']) && $_GET['editresp']) ? "&editresp=1" : "") . (empty($scrollTop) ? '' : "&scrollTop=$scrollTop") . "&fldfocus=$dqresfld&dqresfld=$dqresfld");
			}
			// If in a longitudinal project in non-mobile view if user clicked "Save Record" button, redirect back to
			elseif ($_POST['submit-action'] == 'submit-btn-saverecord' || $_POST['submit-action'] == 'submit-btn-savecompresp')
			{
				$msg = ($_POST['hidden_edit_flag']) ? 'edit' : 'add';
				if ($_POST['hidden_edit_flag'] && isset($_POST['__rename_failed__'])) $msg = '__rename_failed__';
				redirect(APP_PATH_WEBROOT . "DataEntry/record_home.php?pid=$project_id&id=$fetched_msg" . ($multiple_arms ? "&arm=".getArm() : "") . "&msg=$msg");
			}
			// Redirect to the next form if user clicked "Save and go to Next Form"
			elseif ($_POST['submit-action'] == "submit-btn-savenextform")
			{
				// Determine the next form
				$next_form = DataEntry::getNextForm($_GET['page'], $_GET['event_id']);
				// Determine the instance of the next form (will vary if a repeating form or repeating event)
				$instance = $isRepeatingEvent ? $_GET['instance'] : 1;
				// Redirect
				redirect(PAGE_FULL . "?pid=$project_id&page=$next_form&id=$fetched_msg&event_id={$_GET['event_id']}&instance=$instance");
			}
			// Redirect to the next record if user clicked "Save and go to Next Record"
			elseif ($_POST['submit-action'] == "submit-btn-savenextrecord")
			{
				// Determine the next record name in the same arm
				$next_record = Records::getNextRecord($project_id, $fetched, getArm());
				$next_record_url = ($next_record == '') ? "&msg=edit_no_next" : "&msg=edit&id=$next_record";
				// Redirect
				redirect(APP_PATH_WEBROOT . "DataEntry/record_home.php?pid=$project_id&edit_id=$fetched_msg". $next_record_url . ($multiple_arms ? "&arm=".getArm() : ""));
			}
			// Redirect to the next instance if user clicked "Save and Add New Instance"
			elseif ($isRepeatingForm && $_POST['submit-action'] == "submit-btn-savenextinstance")
			{
				redirect(PAGE_FULL . "?pid=$project_id&page={$_GET['page']}&id=$fetched_msg&event_id={$_GET['event_id']}&instance=".($_GET['instance']+1));
			}

			break;

		//CANCEL
		case 'submit-btn-cancel':
			// If the project is classic, only has 1 form, and the record doesn't exist, then alternatively return
			//  to Add/Edit Records page, otherwise return to Record Home page.
			$cancelUrl = APP_PATH_WEBROOT . "DataEntry/record_home.php?pid=$project_id";
			if (!(!$longitudinal && $Proj->numForms == 1 && !Records::recordExists(PROJECT_ID, $fetched))) {
				$cancelUrl .= "&id=$fetched&arm=" . getArm() . "&msg=cancel";
			}
			// Redirect back to Grid for this record
			redirect($cancelUrl);
			break;

	}

}



//If project has been marked as OFFLINE in Control Center, then redirect to index.php now that data has been saved first.
if ($delay_kickout)
{
	redirect(APP_PATH_WEBROOT."index.php?pid=$project_id");
}








//Make sure "page" url variable exists, else redirect to index page
if (!isset($_GET['page']) || $_GET['page'] == "" || preg_match("/[^a-z_0-9]/", $_GET['page']))
{
	redirect(APP_PATH_WEBROOT . "index.php?pid=" . $project_id);
}

// Is this form designated for use as a survey?
$setUpAsSurvey = (isset($Proj->forms[$_GET['page']]['survey_id']));




################################################################################
# PAGE HEADER
include APP_PATH_DOCROOT . 'ProjectGeneral/header.php';


// REDCap Hook injection point
if (isset($_GET['page'])) {
	if (isset($_GET['id'])) {
		// Hook: redcap_data_entry_form_top
		$group_id = (empty($Proj->groups)) ? null : Records::getRecordGroupId(PROJECT_ID, $fetched);
		if (!is_numeric($group_id)) $group_id = null;
		Hooks::call('redcap_data_entry_form_top', array(PROJECT_ID, ($hidden_edit ? $fetched : null), $_GET['page'], $_GET['event_id'], $group_id, $_GET['instance']));
	} else {
		// Hook: redcap_add_edit_records_page
		Hooks::call('redcap_add_edit_records_page', array(PROJECT_ID, $_GET['page'], $_GET['event_id']));
	}
}


// Page header and title
$formMenuAppend = $modifyInstBtn = $shareInstBtn = "";
// PROMIS: Determine if instrument is a PROMIS instrument downloaded from the Shared Library
list ($isPromisInstrument, $isAutoScoringInstrument) = PROMIS::isPromisInstrument($_GET['page']);
// Add button to edit the form if in Development (takes user to Online Designer)
if ($status < 1 && isset($_GET['id']) && $user_rights['design'] && !$isPromisInstrument)
{
	$modifyInstBtn = RCView::button(array('onclick'=>"window.location.href=app_path_webroot+'Design/online_designer.php?pid=$project_id&page={$_GET['page']}';", 'class'=>'jqbuttonmed'),
						RCView::img(array('src'=>'blog_pencil.png', 'style'=>'vertical-align:middle;position:relative;top:-1px;')) .
						RCView::span(array('style'=>'vertical-align:middle;color:#444;'), $lang['data_entry_202'])
					 );
}
// Add button to share this instrument to the Shared Library (allow Brenda Minor to always see the Share Instrument button)
$special_library_uploaders = array('minorbl', 'fernanm');
if (($status > 0 && $user_rights['design'] && $shared_library_enabled) || (isVanderbilt() && in_array(USERID, $special_library_uploaders)))
{
	// Don't allow to share if currently in Draft Mode (give notice if so)
	if ($draft_mode > 0 && !(isVanderbilt() && SUPER_USER)) {
		$shareThisInstAction = "alert('".js_escape($lang['global_03']).'\n'.js_escape($lang['setup_71']." ".$lang['data_entry_129'])."');";
	} else {
		$shareThisInstAction = "window.location.href=app_path_webroot+'SharedLibrary/index.php?pid=$project_id&page={$_GET['page']}';";
	}
	$shareInstBtn = RCView::button(array('onclick'=>$shareThisInstAction, 'class'=>'jqbuttonmed'),
						RCView::img(array('src'=>'share.png', 'style'=>'vertical-align:middle;position:relative;top:-1px;')) .
						RCView::span(array('style'=>'vertical-align:middle;color:#444;'), $lang['data_entry_264'])
					);
}
// Set language
$pdfDownloadSurveyFormText = (isset($Proj->forms[$_GET['page']]['survey_id'])) ? $lang['data_entry_133'] : $lang['data_entry_132'];
$pdfDownloadSurveyFormText2 = ($surveys_enabled) ? $lang['data_entry_136'] : $lang['data_entry_135'];
// Is survey PDF export enabled?
$end_of_survey_pdf_download = (isset($Proj->forms[$_GET['page']]['survey_id']) && $Proj->surveys[$Proj->forms[$_GET['page']]['survey_id']]['end_of_survey_pdf_download'] == '1');
// Set data entry form header text
print	RCView::div(array('id'=>'dataEntryTopOptions'),
			RCView::div(array('id'=>'dataEntryTopOptionsButtons'),
				// "Actions:" text
				RCView::span(array('style'=>'color:#777;margin-right:6px;'), $lang['edit_project_29']) .
				// Modify Instrument button (if displayed)
				$modifyInstBtn .
				// PDF button
				RCView::button(array('id'=>'pdfExportDropdownTrigger', 'onclick'=>"showBtnDropdownList(this,event,'pdfExportDropdownDiv');", 'class'=>'jqbuttonmed'),
					RCView::img(array('src'=>'pdf.gif', 'style'=>'vertical-align:middle;position:relative;top:-1px;')) .
					RCView::span(array('style'=>'vertical-align:middle;color:#800000;'), $lang['data_export_tool_158']) .
					RCView::img(array('src'=>'arrow_state_grey_expanded.png', 'style'=>'margin-left:2px;vertical-align:middle;position:relative;top:-1px;'))
				) .
				// Share Instrument button (if displayed)
				$shareInstBtn .
				// PDF button/drop-down options (initially hidden)
				RCView::div(array('id'=>'pdfExportDropdownDiv', 'style'=>'display:none;position:absolute;z-index:1000;'),
					RCView::ul(array('id'=>'pdfExportDropdown'),
						RCView::li(array(),
							RCView::a(array('href'=>'javascript:;', 'style'=>'display:block;', 'onclick'=>"window.location.href = app_path_webroot+'index.php?route=PdfController:index&pid='+pid+'&page={$_GET['page']}';"),
								RCView::img(array('src'=>'pdf.gif')) .
								"$pdfDownloadSurveyFormText {$lang['data_entry_137']}"
							)
						) .
						(!(isset($_GET['id']) && $hidden_edit && $user_rights['data_export_tool'] > 0) ? '' :
							RCView::li(array(),
								RCView::a(array('href'=>'javascript:;', 'style'=>'display:block;', 'onclick'=>"window.location.href = app_path_webroot+'index.php?route=PdfController:index&pid='+pid+'&page={$_GET['page']}&id={$_GET['id']}{$entry_num}&event_id={$_GET['event_id']}'+(getParameterByName('instance')==''?'':'&instance='+getParameterByName('instance'));"),
									RCView::img(array('src'=>'pdf.gif')) .
									"$pdfDownloadSurveyFormText {$lang['data_entry_134']}"
								)
							) .
							RCView::li(array(),
								RCView::a(array('href'=>'javascript:;', 'style'=>'display:block;', 'onclick'=>"window.location.href = app_path_webroot+'index.php?route=PdfController:index&pid='+pid+'&page={$_GET['page']}&id={$_GET['id']}{$entry_num}&event_id={$_GET['event_id']}&compact=1'+(getParameterByName('instance')==''?'':'&instance='+getParameterByName('instance'));"),
									RCView::img(array('src'=>'pdf.gif')) .
									"$pdfDownloadSurveyFormText {$lang['data_entry_134']} {$lang['data_entry_425']}"
								)
							)
						) .
						(!(isset($_GET['id']) && $hidden_edit && $user_rights['data_export_tool'] > 0 && $end_of_survey_pdf_download) ? '' :
							RCView::li(array(),
								RCView::a(array('href'=>'javascript:;', 'style'=>'display:block;', 'onclick'=>"window.location.href = app_path_webroot+'index.php?route=PdfController:index&pid='+pid+'&s=&page={$_GET['page']}&id={$_GET['id']}{$entry_num}&event_id={$_GET['event_id']}'+(getParameterByName('instance')==''?'':'&instance='+getParameterByName('instance'));"),
									RCView::img(array('src'=>'pdf.gif')) .
									"$pdfDownloadSurveyFormText {$lang['data_entry_134']} {$lang['data_entry_397']}"
								)
							)
						) .
						((count($Proj->forms) <= 1) ? '' :
							RCView::li(array(),
								RCView::a(array('href'=>'javascript:;', 'style'=>'display:block;', 'onclick'=>"window.location.href = app_path_webroot+'index.php?route=PdfController:index&pid='+pid+'&all';"),
									RCView::img(array('src'=>'pdf.gif')) .
									"$pdfDownloadSurveyFormText2 {$lang['data_entry_137']}"
								)
							) .
							(!(isset($_GET['id']) && $hidden_edit && $user_rights['data_export_tool'] > 0) ? '' :
								RCView::li(array(),
									RCView::a(array('href'=>'javascript:;', 'style'=>'display:block;', 'onclick'=>"window.location.href = app_path_webroot+'index.php?route=PdfController:index&pid='+pid+'&id={$_GET['id']}{$entry_num}';"),
										RCView::img(array('src'=>'pdf.gif')) .
										"$pdfDownloadSurveyFormText2 {$lang['data_entry_134']}"
									)
								) .								
								RCView::li(array(),
									RCView::a(array('href'=>'javascript:;', 'style'=>'display:block;', 'onclick'=>"window.location.href = app_path_webroot+'index.php?route=PdfController:index&pid='+pid+'&id={$_GET['id']}{$entry_num}&compact=1';"),
										RCView::img(array('src'=>'pdf.gif')) .
										"$pdfDownloadSurveyFormText2 {$lang['data_entry_134']} {$lang['data_entry_425']}"
									)
								)
							)
						)
					)
				) .
				RCView::span(array('class'=>'nowrap', 'style'=>'margin-left:50px;line-height:24px;'),
					// VIDEO link
                    '<i class="fas fa-film"></i> ' .
					RCView::a(array('href'=>'javascript:;', 'style'=>'font-weight:normal;text-decoration:underline;', 'onclick'=>"window.open('".CONSORTIUM_WEBSITE."videoplayer.php?video=data_entry_overview_02.mp4&referer=".SERVER_NAME."&title=Overview of Basic Data Entry','myWin','width=1050, height=800, toolbar=0, menubar=0, location=0, status=0, scrollbars=1, resizable=1');"),
						"{$lang['global_80']} {$lang['data_entry_200']}"
					)
				)
			) .
			RCView::div(array('style'=>'color:#800000;font-size:16px;font-weight:bold;padding:20px 0 5px;'),
				"<img src='".APP_PATH_IMAGES."blog.png'>
				".RCView::escape($Proj->forms[$_GET['page']]['menu'])." $formMenuAppend" .
				// "Record was edited" message for Save&Stay btn
				(isset($_GET['msg']) && $_GET['msg'] == 'edit' ? $context_msg_update : '')
			)
		);
// Javascript
?>
<script type="text/javascript">
$(function(){
	// Initialize button drop-down(s) for top of form
	$('#pdfExportDropdown, #SurveyActionDropDownUl, #repeatInstanceDropdownUl').menu();
	$('#SurveyActionDropDownDiv ul li a').click(function(){
		$('#SurveyActionDropDownDiv').hide();
	});
	$('#pdfExportDropdownDiv ul li a').click(function(){
		$('#pdfExportDropdownDiv').hide();
	});
	$('#repeatInstanceDropdown ul li a').click(function(){
		$('#repeatInstanceDropdownDiv').hide();
	});
});
</script>
<?php








## RENDERING THE RECORD DROP-DOWNS (RECORD IS NOT SELECTED YET)
if (!isset($_GET['id']))
{
	// Do not allow user to add new record unless on first form or if a child project's first form (when linked to a parent)
	// Do not allow user to add new record if user has Read-Only rights to first form
	// $first_form is the first form in the metadata table
	if ($first_form == $_GET['page'] && !$auto_inc_set && $user_rights['record_create'] && ($user_rights['forms'][$first_form] == '1' || $user_rights['forms'][$first_form] == '3'))
	{
		$search_text_label = $lang['data_entry_31'] . " <span class='notranslate'>" . strip_tags(label_decode($table_pk_label)) . "</span>";
		$search_text_header_label = $lang['data_entry_03'] . "<span class='notranslate'>$table_pk_label</span> " . $lang['data_entry_04'];
	}

	// Create array to store all Form Status values for this record for this data entry form
	$record_dropdowns = array();
	// If using DDE, then set filter logic
	$ddeFilter = ($double_data_entry && $user_rights['double_data'] != 0) ? "ends_with([$table_pk], '--{$user_rights['double_data']}')" : false;
	// Get the total record count and a list of all records
	$num_records_all = Records::getRecordCount($project_id);
	// Get array of records that will be made visible on page (might be truncated if too many)
	$records_displayed = Records::getRecordList($project_id, $user_rights['group_id'], true);
	$num_records_displayed = $num_records_group = count($records_displayed);
	// Set drop-down option cutoff
	$record_list_cutoff = DataEntry::$maxNumRecordsHideDropdowns;
	$truncate_list = ($num_records_displayed > $record_list_cutoff);
	$records_displayed = array();
	$truncate_list_text = "";
	if ($truncate_list) {
		$records_displayed = Records::getRecordList($project_id, $user_rights['group_id'], true, false, null, $record_list_cutoff, $num_records_displayed-$record_list_cutoff);
		$truncate_list_text = RCView::div(array('style'=>'padding:10px 0;font-size:10px;font-weight:normal;color:#A00000;'), 
								'<i class="fas fa-exclamation-circle"></i> '.$lang['data_entry_434'] . " " . User::number_format_user($record_list_cutoff) . " " . $lang['data_entry_435'] . 
								" " . User::number_format_user($record_list_cutoff) . " " . $lang['data_entry_173'] . $lang['period']);
		// If there are no records available, then add blank record placeholder to prevent getData from returning anything
		if (empty($records_displayed)) $records_displayed = array('');
	} else {
		// If there are no records available, then add blank record placeholder to prevent getData from returning anything
		if ($num_records_displayed == 0) $records_displayed = array('');
	}
	// Get the data
	$record_dropdowns_getData = Records::getData('array', $records_displayed, array($table_pk, "{$_GET['page']}_complete"), array(), $user_rights['group_id'],
									false, false, false, $ddeFilter);
	if (count($record_dropdowns_getData) == 1 && isset($record_dropdowns_getData[''])) $record_dropdowns_getData = array();
	// Loop through records returned and format into specific array
	foreach ($record_dropdowns_getData as $this_record=>$event_attr) {
		// Remove to save memory
		unset($record_dropdowns_getData[$this_record]);
		// Remove --# from record name if using DDE
		if ($ddeFilter !== false) $this_record = substr($this_record, 0, -3);
		// Add to array
		$record_dropdowns[$this_record] = array('form_status'=>$event_attr[$Proj->firstEventId]["{$_GET['page']}_complete"],
												'label'=>$this_record);
	}

	// Adjust queries if in a DAG or using DDE
	$group_sql_r  = "";
	if ($truncate_list || $user_rights['group_id'] != "") {
		$group_prequery = prep_implode(array_keys($record_dropdowns));
		$group_sql_r  = "and r.record in ($group_prequery)";
	}

	// If a SURVEY and surveys are ENABLED, then append timestamp (and identifier, if exists) of all responses to record name in drop-down list of records
	if ($surveys_enabled && isset($Proj->forms[$_GET['page']]['survey_id']))
	{
		$sql = "select distinct r.record, r.first_submit_time, r.completion_time, p.participant_identifier
				from redcap_surveys_participants p, redcap_surveys_response r, redcap_events_metadata m
				where survey_id = " . $Proj->forms[$_GET['page']]['survey_id'] . " and r.participant_id = p.participant_id and
				m.event_id = p.event_id and m.event_id = {$_GET['event_id']} $group_sql_r
				and r.first_submit_time is not null order by r.record, r.completion_time desc";
		$q = db_query($sql);
		// Count responses
		$num_survey_responses = 0;
		// Append timestamp (and identifier, if exists) to record in drop-down
		while ($row = db_fetch_assoc($q))
		{
			if (!isset($record_dropdowns[$row['record']])) continue;
			$row['record'] = removeDDEending($row['record']);
			// Make sure the record doesn't repeat (it really shouldn't though)
			if (isset($last_resp_rec) && $last_resp_rec == $row['record']) continue;
			// Add labels
			if ($row['participant_identifier'] != "") {
				$record_dropdowns[$row['record']]['label'] .= " (" . $row['participant_identifier'] . ")";
			}
			if ($row['completion_time'] == "") {
				$record_dropdowns[$row['record']]['label'] .= " - [not completed]"; // Do not abstruct this language because it appears in exports.
			} else {
				$record_dropdowns[$row['record']]['label'] .= " - " . DateTimeRC::format_ts_from_ymd($row['completion_time']);
			}
			// Set for next loop
			$last_resp_rec = $row['record'];
			// Increment counter
			$num_survey_responses++;
		}
		// Get last response time (either completed response or first submit time of partial response)
		$sql = "select if(first_submit_time>completion_time, first_submit_time, completion_time) as last_response_time
				from (select max(if(r.first_submit_time is null,0,r.first_submit_time)) as first_submit_time,
				max(if(r.completion_time is null,0,r.completion_time)) as completion_time
				from redcap_surveys_participants p, redcap_surveys_response r, redcap_events_metadata m
				where survey_id = " . $Proj->forms[$_GET['page']]['survey_id'] . " and r.participant_id = p.participant_id
				and m.event_id = p.event_id and m.event_id = " . $_GET['event_id'] . ") as x";
		$q = db_query($sql);
		$last_response_time = $lang['data_entry_119']; // default value (i.e. no responses yet)
		if (db_num_rows($q) > 0) {
			$last_response_time_temp = db_result($q, 0);
			if (!empty($last_response_time_temp))
			{
				$last_response_time = DateTimeRC::format_ts_from_ymd($last_response_time_temp);
			}
		}
	}


	// Obtain custom record label & secondary unique field labels for ALL records.
	$extra_record_labels = Records::getCustomRecordLabelsSecondaryFieldAllRecords(array_keys($record_dropdowns), true, getArm());

	if($extra_record_labels)
	{
		foreach ($extra_record_labels as $this_record=>$this_label) {
			// Remove ending for DDE users
			if ($entry_num != '') $this_record = substr($this_record, 0, -3);
			// Add to array
			$record_dropdowns[$this_record]['label'] .= " $this_label";
		}
	}
	unset($extra_record_labels);

	// Custom record ordering is set
	if ($order_id_by != "" && $order_id_by != $table_pk)
	{
		$ordered_arr = array();
		$orderer_arr_getData = array();
		foreach (Records::getData('array', $records_displayed, $order_id_by, $_GET['event_id'], $user_rights['group_id']) as $this_record=>$event_data) {
			$orderer_arr_getData[$this_record] = $event_data[$_GET['event_id']][$order_id_by];
		}
		natcasesort($orderer_arr_getData);
		foreach ($orderer_arr_getData as $this_record=>$this_val) {
			$ordered_arr[$this_record]['label'] = $record_dropdowns[$this_record]['label'];
			$ordered_arr[$this_record]['form_status'] = $record_dropdowns[$this_record]['form_status'];
			// Remove record from $record_dropdowns so we'll know which ones are left over because they did not have a value for this field
			unset($record_dropdowns[$this_record], $orderer_arr_getData[$this_record]);
		}
		// Loop through any remaining records that did not have a value for this field and add to ordered array
		foreach ($record_dropdowns as $this_record=>$vals) {
			$ordered_arr[$this_record] = $vals;
		}

		// Now set the ordered record array as the original and destroy the ordered one (no longer needed)
		$record_dropdowns = $ordered_arr;
		unset($ordered_arr);
	}

	// Loop through all records and place each into array for each drop-down, based upon form status value
	$record_dropdown1 = array();
	$record_dropdown2 = array();
	$record_dropdown3 = array();
	foreach ($record_dropdowns as $this_record=>$this_val)
	{
		// Set form status
		$this_status = $this_val['form_status'];
		// Replace any commas in the record or label to prevent issues when rendering the drop-down using DataEntry::render_dropdown()
		$this_label = str_replace(",", "&#44;", $this_record) . ", " . str_replace(",", "&#44;", $this_val['label']);
		// Put value in array based upon how many drop-downs are being show
		switch ($show_which_records)
		{
			// Incomplete & Complete
			case '0':
				if ($this_status == '2') {
					$record_dropdown2[$this_record] = $this_label;
				} else {
					$record_dropdown1[$this_record] = $this_label;
				}
				break;
			// Incomplete, Unverified, & Complete
			case '1':
				if ($this_status == '2') {
					$record_dropdown3[$this_record] = $this_label;
				} elseif ($this_status == '1') {
					$record_dropdown2[$this_record] = $this_label;
				} else {
					$record_dropdown1[$this_record] = $this_label;
				}
				break;
			// All records in one drop-down
			case '2':
				$record_dropdown1[$this_record] = $this_label;
				break;
		}
	}

	// Remove the original array, as it's no longer needed
	unset($record_dropdowns);

	//Decide which pulldowns to display for user to choose Study ID (for single survey projects, use 'responses' instead of 'records')
	switch ($show_which_records) {
		case '0':
			$rs_select1_label = $setUpAsSurvey ? $lang['data_entry_88'] : $lang['data_entry_16'];
			$rs_select2_label = $setUpAsSurvey ? $lang['data_entry_89'] : $lang['data_entry_17'];
			break;
		case '1':
			$rs_select1_label = $setUpAsSurvey ? $lang['data_entry_88'] : $lang['data_entry_16'];
			$rs_select2_label = $setUpAsSurvey ? $lang['data_entry_98'] : $lang['data_entry_23'];
			$rs_select3_label = $setUpAsSurvey ? $lang['data_entry_89'] : $lang['data_entry_17'];
			break;
		case '2':
			$rs_select1_label = ($setUpAsSurvey ? $lang['data_entry_124'] : $lang['data_entry_24'] . " $table_pk_label");
			break;
	}


	//Show select boxes if appropriate (no subject selected - no 'id' in URL)
	if (!$longitudinal)
	{
		// Set the label for blank drop-down value
		$blankDDlabel = ($setUpAsSurvey ? remBr($lang['data_entry_92']) : remBr($lang['data_entry_91']));
		/* 
		// If more records than a set number exist, do not render the drop-downs due to slow rendering.
		if ($truncate_list)
		{
			// Unset all the drop-downs
			unset($rs_select1_label);
			unset($rs_select2_label);
			unset($rs_select3_label);
			// If using auto-numbering, then bring back text box so users can auto-suggest to find existing records	.
			// The negative effect of this is that it also allows users to [accidentally] bypass the auto-numbering feature.
			if ($auto_inc_set) {
				$search_text_label = $lang['data_entry_121'] . " ".RCView::escape($table_pk_label);
			}
			// Give extra note about why drop-down is not being displayed
			$search_text_label .= RCView::div(array('style'=>'padding:10px 0 0;font-size:10px;font-weight:normal;color:#555;'),
									$lang['global_03'] . $lang['colon'] . " " . $lang['data_entry_172'] . " " .
									User::number_format_user(DataEntry::$maxNumRecordsHideDropdowns, 0) . " " .
									$lang['data_entry_173'] . $lang['period']
								);
		}
		*/
		// Should we show the auto-number button?
		$showAutoNumBtn = ($_GET['page'] == $first_form && $auto_inc_set);

		// If displaying "enter new record" text box, then check if record ID field should have validation
		if (isset($search_text_label))
		{
			$text_val_string = "";
			if ($Proj->metadata[$table_pk]['element_type'] == 'text' && $Proj->metadata[$table_pk]['element_validation_type'] != '')
			{
				// Apply validation function to field
				$text_val_string = "if(redcap_validate(this,'{$Proj->metadata[$table_pk]['element_validation_min']}','{$Proj->metadata[$table_pk]['element_validation_max']}','hard','".convertLegacyValidationType($Proj->metadata[$table_pk]['element_validation_type'])."',1)) ";
			}
		}



		// Page instructions and record selection table with drop-downs
		?>
		<p style="margin-bottom:20px;">
			<?php echo $lang['data_entry_95'] ?>
			<?php if ($showAutoNumBtn) echo $lang['data_entry_96'] ?>
			<?php if (isset($search_text_label)) echo $lang['data_entry_97'] ?>
		</p>
		<?php if ($truncate_list_text != '') echo $truncate_list_text ?>

		<style type="text/css">
		.data { padding: 7px;  }
		</style>

		<table class="form_border" style="width:100%;max-width:700px;">

			<!-- Header displaying record count -->
			<tr>
				<td class="header" colspan="2" style="font-weight:normal;padding:10px 5px;color:#800000;font-size:13px;">
					<?php echo $lang['graphical_view_22'] ?> <b><?php echo User::number_format_user($num_records_all) ?></b>
					<?php if (isset($num_survey_responses)) { ?>
						&nbsp;/&nbsp; <?php echo $lang['data_entry_102'] ?> <b><?php echo User::number_format_user($num_survey_responses) ?></b>
					<?php } ?>
					<?php if ($user_rights['group_id'] != '') { ?>
						&nbsp;/&nbsp; <?php echo $lang['data_entry_104'] ?> <b><?php echo User::number_format_user($num_records_group) ?></b>
					<?php } ?>
					<?php if (isset($last_response_time)) { ?>
						&nbsp;/&nbsp; <?php echo $lang['data_entry_120'] ?> <b><?php echo $last_response_time ?></b>
					<?php } ?>
				</td>
			</tr>

			<!-- Context msg (show if saved/deleted a record) -->
			<?php if (isset($context_msg) && $context_msg != "") { ?>
				<tr>
					<td colspan="2" class="context_msg"><?php echo $context_msg ?></td>
				</tr>
			<?php } ?>

			<!-- Drop-down list #1 -->
			<?php if (isset($rs_select1_label)) { ?>
				<tr>
					<td class="labelrc" style="width:275px;">
						<?php echo $rs_select1_label ?> &nbsp;<span style="font-weight:normal;color:#800000;">(<span id="record_select1_count"></span>)</span>
					</td>
					<td class="data">
						<select id="record_select1" class="x-form-text x-form-field notranslate" style="max-width:350px;"
							onchange="if(this.value.length>0){window.location.href=app_path_webroot+page+'?pid='+pid+'&page=<?php echo $_GET['page'] ?>&id='+this.value;}">
							<?php list ($ddOptionHtml, $ddDisabled) = DataEntry::render_dropdown(implode("\n", $record_dropdown1), "", $blankDDlabel); echo $ddOptionHtml; ?>
						</select>
					</td>
				</tr>
			<?php } ?>

			<!-- Drop-down list #2 -->
			<?php if (isset($rs_select2_label)) { ?>
				<tr>
					<td class="labelrc">
						<?php echo $rs_select2_label ?> &nbsp;<span style="font-weight:normal;color:#800000;">(<span id="record_select2_count"></span>)</span>
					</td>
					<td class="data">
						<select id="record_select2" class="x-form-text x-form-field notranslate" style="max-width:350px;"
							onchange="if(this.value.length>0){window.location.href=app_path_webroot+page+'?pid='+pid+'&page=<?php echo $_GET['page'] ?>&id='+this.value;}">
							<?php list ($ddOptionHtml, $ddDisabled) = DataEntry::render_dropdown(implode("\n", $record_dropdown2), "", $blankDDlabel); echo $ddOptionHtml; ?>
						</select>
					</td>
				</tr>
			<?php } ?>

			<!-- Drop-down list #3 -->
			<?php if (isset($rs_select3_label)) { ?>
				<tr>
					<td class="labelrc">
						<?php echo $rs_select3_label ?> &nbsp;<span style="font-weight:normal;color:#800000;">(<span id="record_select3_count"></span>)</span>
					</td>
					<td class="data">
						<select id="record_select3" class="x-form-text x-form-field notranslate" style="max-width:350px;"
							onchange="if(this.value.length>0){window.location.href=app_path_webroot+page+'?pid='+pid+'&page=<?php echo $_GET['page'] ?>&id='+this.value;}">
							<?php list ($ddOptionHtml, $ddDisabled) = DataEntry::render_dropdown(implode("\n", $record_dropdown3), "", $blankDDlabel); echo $ddOptionHtml; ?>
						</select>
					</td>
				</tr>
			<?php } ?>

			<!-- Text box for entering new record ids -->
			<?php if (isset($search_text_label)) { ?>
				<tr>
					<td class="labelrc"><?php echo $search_text_label ?></td>
					<td class="data">
						<input type="text" size="30" style="position: relative;" id="inputString" class="x-form-text x-form-field" autocomplete="new-password">
					</td>
				</tr>
			<?php } ?>

			<?php if ($Proj->metadata[$table_pk]['element_type'] != 'text') { ?>
			<!-- Error if first field is NOT a text field -->
				<tr>
					<td colspan="2" class="red"><?php echo RCView::b($lang['global_48'] .$lang['colon']) ." " .$lang['data_entry_180'] . " <b>$table_pk</b> (\"$table_pk_label\")".$lang['period'] ?></td>
				</tr>
			<?php } ?>

			<!-- Auto-number button(s) - if option is enabled -->
			<?php if ($showAutoNumBtn && $user_rights['record_create'] > 0) { ?>
				<tr>
					<td class="labelrc">&nbsp;</td>
					<td class="data">
						<!-- New record button -->
						<button onclick="window.location.href=app_path_webroot+page+'?pid='+pid+'&id=<?php echo DataEntry::getAutoId() . "&page=" . $_GET['page'] ?>&auto=1';return false;"><?php echo $lang['data_entry_46'] ?></button>
					</td>
				</tr>
			<?php } ?>

		</table>

		<script type="text/javascript">
		// Add counts of records next to labels for each record drop-down (count options in the drop-downs to determine)
		if (document.getElementById('record_select1') != null) {
			document.getElementById('record_select1_count').innerHTML = document.getElementById('record_select1').length - 1;
		}
		if (document.getElementById('record_select2') != null) {
			document.getElementById('record_select2_count').innerHTML = document.getElementById('record_select2').length - 1;
		}
		if (document.getElementById('record_select3') != null) {
			document.getElementById('record_select3_count').innerHTML = document.getElementById('record_select3').length - 1;
		}

		$(function(){
			// Enable validation and redirecting if hit Tab or Enter
			$('#inputString').keypress(function(e) {
				if (e.which == 13) {
					 $('#inputString').trigger('blur');
					return false;
				}
			});
			$('#inputString').blur(function() {
				var refocus = false;
				var idval = trim($('#inputString').val());
				if (idval.length < 1) {
					return;
				}
				if (idval.length > 100) {
					refocus = true;
					alert('<?php echo js_escape($lang['data_entry_186']) ?>');
				}
				if (refocus) {
					setTimeout(function(){document.getElementById('inputString').focus();},10);
				} else {
					$('#inputString').val(idval);
					<?php echo (isset($text_val_string) ? $text_val_string : ''); ?>
					setTimeout(function(){
						idval = $('#inputString').val();
						idval = idval.replace(/&quot;/g,''); // HTML char code of double quote
						var validRecordName = recordNameValid(idval);
						if (validRecordName !== true) {
							$('#inputString').val('');
							alert(validRecordName);
							$('#inputString').focus();
							return false;
						}
						// Redirect, but NOT if the validation pop-up is being displayed (for range check errors)
						if (!$('.simpleDialog.ui-dialog-content:visible').length)
							window.location.href = app_path_webroot+page+'?pid='+pid+'&page='+getParameterByName('page')+'&id=' + idval;
					},200);
				}
			});
		});
		</script>
		<?php
	}

	## RENDER PAGE INSTRUCTIONS (and any error messages) when not rendering full form
	// Build html string to display page instructions
	$page_instructions = "";

	if (!$longitudinal)
	{
		// If user is on last form, don't show the button "Save and go to Next Form"
		if (isset($fetched) && $_GET['page'] != $last_form) {
			$next_form = DataEntry::getNextForm($_GET['page'], $_GET['event_id']);
			print  "<div align='right' style='padding-top:10px;max-width:700px;'>
						<input type='button' onclick='window.location.href=\"".$_SERVER['PHP_SELF']."?pid=$project_id&page=$next_form&id=$fetched\";' value='".js_escape($lang['data_entry_175'])." ->' style='font-size:11px;'>
					</div>";
		}
		// Do not show link for single survey projects
		if ($show_which_records == '0') {
			print "<div style='text-align:right;max-width:700px;'><a href='".APP_PATH_WEBROOT."DataEntry/change_record_dropdown.php?pid=$project_id&page={$_GET['page']}&show_which_records=1' style='font-size:10px;text-decoration:underline;'>{$lang['data_entry_25']}</a></div>";
		} elseif ($show_which_records == '1') {
			print "<div style='text-align:right;max-width:700px;'><a href='".APP_PATH_WEBROOT."DataEntry/change_record_dropdown.php?pid=$project_id&page={$_GET['page']}&show_which_records=0' style='font-size:10px;text-decoration:underline;'>{$lang['data_entry_26']}</a></div>";
		}

		// Display search utility
		DataEntry::renderSearchUtility();
	}

	//Build html string to display LONGITUDINAL info on page after submitting form data
	else
	{
		// Display context message
		print $context_msg;
		$arm = getArm();
		$page_instructions =   "<br><span class='yellow' style='padding-right:15px;'>
									{$lang['global_10']}{$lang['colon']}
									<span style='font-weight:bold;color:#800000;'>{$Proj->eventInfo[$_GET['event_id']]['name_ext']}</span>
								</span>
								<p style='padding:25px 0 20px;color:#666;'>
									<button class='jqbutton' onclick=\"window.location.href=app_path_webroot+'DataEntry/record_home.php?pid=$project_id&page=&arm=$arm&id=$fetched';\">
										<i class=\"fas fa-chevron-circle-left\"></i> {$lang['data_entry_55']} $table_pk_label <b>$fetched</b>
										</button>&nbsp;{$lang['global_46']}&nbsp; 
									<button class='jqbutton' onclick=\"window.location.href=app_path_webroot+'DataEntry/record_home.php?pid=$project_id';\">
										<img src='" . APP_PATH_IMAGES . "spacer.gif' style='height:16px;width:0px;'>{$lang['data_entry_112']}
									</button>
								</p>";
	}


	//Using double data entry and auto-numbering for records at the same time can mess up how REDCap saves each record.
	//Give warning to turn one of these features off if they are both turned on.
	if ($double_data_entry && $auto_inc_set) {
		$page_instructions .= "<div class='red'><b>{$lang['global_48']}</b><br>{$lang['data_entry_56']}</div>";
	}

	//If project is a prototype, display notice for users telling them that no real data should be entered yet.
	if ($status < 1) {
		$page_instructions .=  "<br><br><div class='yellow' style='width:90%;max-width:600px;'>
									<img src='".APP_PATH_IMAGES."exclamation_orange.png'>
									<b style='font-size:14px;'>{$lang['global_03']}:</b><br>
									{$lang['data_entry_28']}
								</div>";
	}

	//Now render the page instructions (and any error messages)
	print $page_instructions;


	## AUTO-COMPLETE: Render JavaScript for record selecting auto-complete/auto-suggest (but only for first form)
	?>
	<script type="text/javascript">
	$(function(){
		if ($('#inputString').length) {
			$('#inputString').autocomplete({
				source: app_path_webroot+'DataEntry/auto_complete.php?pid='+pid+'&arm=<?php echo getArm() ?>',
				minLength: 1,
				delay: 0,
				select: function( event, ui ) {
					$(this).val(ui.item.value).trigger('blur');
					return false;
				}
			})
			.data('ui-autocomplete')._renderItem = function( ul, item ) {
				return $("<li></li>")
					.data("item", item)
					.append("<a>"+item.label+"</a>")
					.appendTo(ul);
			};
		}
	});
	</script>
	<?php

}










## RECORD IS SELECTED: BUILD FORM ELEMENTS
elseif (isset($_GET['id']))
{
	// Make sure record name in URL does not have trailing spaces
	$_GET['id'] = trim(urldecode($_GET['id']));

	// Make sure that there is a case sensitivity issue with the record name. Check value of id in URL with back-end value.
	// If doesn't match back-end case, then reload page using back-end case in URL.
	DataEntry::checkRecordNameCaseSensitive();

	// If this record has not been created yet, then do not allow record renaming (doesn't make sense to allow if not even created yet)
	if ($hidden_edit == 0) $user_rights['record_rename'] = 0;

	// Obtain form data for rendering
	list ($elements1, $calc_fields_this_form, $branch_fields_this_form, $chkbox_flds) = DataEntry::buildFormData($_GET['page']);

	// For all forms, create static element at top of page
	$elements1 = array_merge(array(array('rr_type'=>'static', 'field'=>$table_pk, 'name'=>'', 'label'=>nl2br(decode_filter_tags($Proj->metadata[$table_pk]['element_label'])))), $elements1);

	// Show study_id field as hidden on all forms (unless already displayed as editable field on first form when can rename records)
	if ((!$user_rights['record_rename'] && $_GET['page'] == $Proj->firstForm) || $_GET['page'] != $Proj->firstForm)
	{
		$elements1[] = array('rr_type'=>'hidden', 'field'=>$table_pk, 'name'=>$table_pk);
	}

	//Custom page header note
	if (trim($custom_data_entry_note) != '') {
		print "<br><div class='green notranslate' style='font-size:11px;'>" . nl2br(decode_filter_tags($custom_data_entry_note)) . "</div><br>";
	}

	//Adapt for Double Data Entry module
	if ($entry_num != "") {
		//This is #1 or #2 Double Data Entry person
		$fetched .= $entry_num;
	}

	// Check if record exists
	if ($hidden_edit) {
		//This record already exists
		$context_msg = DataEntry::render_context_msg($custom_record_label, $context_msg_edit);
	} else {
		//This record does not exist yet
        $context_msg = DataEntry::render_context_msg("", $context_msg_add);
		//Deny access if user has no create_records rights
		if (!$user_rights['record_create'])
		{
			print  "<div class='red'>
						<img src='" . APP_PATH_IMAGES . "exclamation.png'>
						<b>{$lang['global_05']}</b>
					</div>";
			include APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
			exit;
		}
	}

	//Set context msg at top of table
	if ($context_msg != "") {
		$elements[] = array('rr_type'=>'header', 'css_element_class'=>'context_msg','value'=>$context_msg);
	}

	// Set hidden element that contains the button action being done for post-processing. Set to 'Save Record' as default.
	$elements[] = array('rr_type'=>'hidden', 'id'=>'submit-action', 'name'=>'submit-action', 'value'=>$lang['data_entry_206']);

	//If hidden_edit_flag == 1, then this record already exists. If 0, it is a new record.
	$elements[] = array('rr_type'=>'hidden', 'name'=>'hidden_edit_flag', 'value'=>$hidden_edit);

	// Primary Form Fields inserted here
	$elements = array_merge($elements, $elements1);

	// CALC FIELDS AND BRANCHING LOGIC: Add fields from other forms as hidden fields if involved in calc/branching on this form
	list ($elementsOtherForms, $chkbox_flds_other_forms, $jsHideOtherFormChkbox) = DataEntry::addHiddenFieldsOtherForms($_GET['page'], array_unique(array_merge($branch_fields_this_form, $calc_fields_this_form)));
	$elements 	 = array_merge($elements, $elementsOtherForms);
	$chkbox_flds = array_merge($chkbox_flds, $chkbox_flds_other_forms);

	// Don't show locking/e-signature for mobile view
	// LOCK RECORD FIELD: If user has right to lock a record, show locking field. If user doesn't have right, all fields are disabled, so can't submit and don't show.
	if ($user_rights['lock_record'] > 0)
	{
		// If custom Locking text is set for this form
		$sql = "select label, display from redcap_locking_labels where project_id = $project_id and form_name = '{$_GET['page']}' limit 1";
		$q = db_query($sql);
		$inLabelTable = (db_num_rows($q) > 0);
		// Only show lock record option if display=1 OR if not in table
		if (($inLabelTable && db_result($q, 0, "display")) || !$inLabelTable)
		{
			// Default Locking text (when not defined)
			$locklabel = (trim(db_result($q, 0, "label") != ""))
				? '<div style="color:#A86700;padding:3px;">'.nl2br(db_result($q, 0, "label")).'</div>'
				: '<div style="color:#A86700;">'.$lang['data_entry_493'].'</div><div style="font-size:7pt;padding-top:7px;color:#555">'.$lang['data_entry_494'].'</div>';
			// Add lock record field to form elements
			$elements[] = array('rr_type'=>'lock_record', 'name'=>'__LOCKRECORD__', 'field'=>'__LOCKRECORD__', 'label'=>$locklabel);
		}
	}

	// Render buttons at bottom of page
	if ($user_rights['forms'][$_GET['page']] == '2') {

		//READ-ONLY MODE SAVE BUTTONS (disabled buttons)
		// $elements[] = array('rr_type'=>'button', 'btnclass'=>'btn btn-primaryrc disabled', 'value'=>$lang['data_entry_206'], 'disabled'=>'disabled');
		$elements[] = array('rr_type'=>'button', 'btnclass'=>'btn btn-defaultrc disabled', 'value'=>$lang['data_entry_207'], 'disabled'=>'disabled');
		
	} else {

		// NORMAL SAVE BUTTONS
		
		// Get the save state (if saved) of the 2nd button
		$secondBtnState = UIState::getUIStateValue(PROJECT_ID, 'form', 'submit-btn');
		
		// Repeating instance button: Go to next instance
		$next_instance_button = $next_instance_button_li = '';
		if ($isRepeatingForm) {			
			// Obtain all repeating data for this record-event-form
			$instances = RepeatInstance::getRepeatFormInstanceList(addDDEending($_GET['id']), $_GET['event_id'], $_GET['page'], $Proj);
			$next_instance_button_text = (!empty($instances) && (max(array_keys($instances)) == $_GET['instance'] || !isset($instances[$_GET['instance']]))) ? $lang['data_entry_275'] : $lang['data_entry_276'];
			$next_instance_button_li = '<a class="dropdown-item" href="javascript:;" id="submit-btn-savenextinstance" onclick="dataEntrySubmit(\'submit-btn-savenextinstance\');return false;">'.$next_instance_button_text.'</a>';
			$next_instance_button = '<button class="btn btn-primaryrc" id="submit-btn-savenextinstance" name="submit-btn-savenextinstance" onclick="dataEntrySubmit(this);return false;" style="margin-bottom:2px;font-size:13px !important;padding:6px 8px;" tabindex="0">'.$next_instance_button_text.'</button>';
		}

		// If user is on last form, don't show the button "Save and go to Next Form"
		$next_form_button = $next_form_button_li = '';
		if ($_GET['page'] != $last_form) {
			$next_form_button_li = '<a class="dropdown-item" href="javascript:;" id="submit-btn-savenextform" onclick="dataEntrySubmit(\'submit-btn-savenextform\');return false;">'.$lang['data_entry_210'].'</a>';
			$next_form_button = '<button class="btn btn-primaryrc" id="submit-btn-savenextform" name="submit-btn-savenextform" onclick="dataEntrySubmit(this);return false;" style="margin-bottom:2px;font-size:13px !important;padding:6px 8px;" tabindex="0">'.$lang['data_entry_210'].'</button>';
		}

		// If user has Edit Survey Response rights and is in edit mode, then give new button to make this response listed as complete (if not already)
		$comp_resp_button = $comp_resp_button_li = '';
		if (isset($Proj->forms[$_GET['page']]['survey_id']) && $user_rights['forms'][$_GET['page']] == '3')
		{
			$showSaveComplRespBtn = false;
			if (isset($_GET['editresp'])) {
				$showSaveComplRespBtn = true;
			} else {
				// Determine if survey has started yet. If not started, then display button.
				$sql = "select r.first_submit_time from redcap_surveys_participants p, redcap_surveys_response r
						where r.participant_id = p.participant_id and r.record = '".db_escape(addDDEending($_GET['id']))."'
						and p.survey_id = {$Proj->forms[$_GET['page']]['survey_id']} and p.event_id = {$_GET['event_id']}
						and p.participant_email is not null and r.first_submit_time is null limit 1";
				$q = db_query($sql);
				$showSaveComplRespBtn = (db_num_rows($q) > 0);
			}
			// First, check if response is complete or not. If not, then render button.
			$comp_resp_button_li = '<a class="dropdown-item" href="javascript:;" id="submit-btn-savecompresp" onclick="dataEntrySubmit(\'submit-btn-savecompresp\');return false;">'.$lang['data_entry_212'].'</a>';
			$comp_resp_button = '<button class="btn btn-primaryrc" id="submit-btn-savecompresp" name="submit-btn-savecompresp" onclick="dataEntrySubmit(this);return false;" style="margin-bottom:2px;font-size:13px !important;padding:6px 8px;" tabindex="0">'.$lang['data_entry_212'].'</button>';
		}
		
		// Set Save and Stay option
		$stay_form_button_li = '<a class="dropdown-item" href="javascript:;" id="submit-btn-savecontinue" onclick="dataEntrySubmit(\'submit-btn-savecontinue\');return false;">'.$lang['data_entry_292'].'</a>';
		$stay_form_button = '<button class="btn btn-primaryrc" id="submit-btn-savecontinue" name="submit-btn-savecontinue" onclick="dataEntrySubmit(this);return false;" style="margin-bottom:2px;font-size:13px !important;padding:6px 8px;" tabindex="0">'.$lang['data_entry_292'].'</button>';
		
		// Set Save and Exit Record option
		$exit_record_button_li = '<a class="dropdown-item" href="javascript:;" id="submit-btn-saveexitrecord" onclick="dataEntrySubmit(\'submit-btn-saveexitrecord\');return false;">'.$lang['data_entry_409'].'</a>';
		$exit_record_button = '<button class="btn btn-primaryrc" id="submit-btn-saveexitrecord" name="submit-btn-saveexitrecord" onclick="dataEntrySubmit(this);return false;" style="margin-bottom:2px;font-size:13px !important;padding:6px 8px;" tabindex="0">'.$lang['data_entry_409'].'</button>';
		
		// Set Save and go to Next Record option
		$save_next_record_button_li = '<a class="dropdown-item" href="javascript:;" id="submit-btn-savenextrecord" onclick="dataEntrySubmit(\'submit-btn-savenextrecord\');return false;">'.$lang['data_entry_410'].'</a>';
		$save_next_record_button = '<button class="btn btn-primaryrc" id="submit-btn-savenextrecord" name="submit-btn-savenextrecord" onclick="dataEntrySubmit(this);return false;" style="margin-bottom:2px;font-size:13px !important;padding:6px 8px;" tabindex="0">'.$lang['data_entry_410'].'</button>';
		
		// Set the drop-down save button options
		$dropdownSaveBtn = '<button id="submit-btn-dropdown" title="'.js_escape2($lang['data_entry_287']).'" class="btn btn-primaryrc btn-savedropdown dropdown-toggle" style="margin-bottom:2px;font-size:13px !important;padding:6px 8px;" tabindex="0" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" onclick="openSaveBtnDropDown(this,event);return false;">
								<span class="sr-only"></span>
							</button>
							<div class="dropdown-menu">' .
								($secondBtnState == 'savecontinue' ? '' : $stay_form_button_li) .
								($secondBtnState == 'savenextinstance' ? '' : $next_instance_button_li) .
								($secondBtnState == 'savenextform' ? '' : $next_form_button_li) .
								($secondBtnState == 'savecompresp' ? '' : $comp_resp_button_li) . 
								($secondBtnState == 'saveexitrecord' ? '' : $exit_record_button_li) .
								($secondBtnState == 'savenextrecord' ? '' : $save_next_record_button_li) .
							'</div>';
		
		// If there's no save state, then display 2nd button as "Save and ..."
		if ($secondBtnState == null) {
			$secondBtn = '<button id="submit-btn-placeholder" data-trigger="click" data-toggle="popover" data-placement="top" data-content="'.js_escape2($lang['data_entry_290']).'" title="<b>'.js_escape2($lang['data_entry_291']).'</b>" class="btn btn-primaryrc btn-saveand" onclick="return false;" style="margin-bottom:2px;font-size:13px !important;padding:6px 8px;" tabindex="0">'.$lang['data_entry_289'].'</button>';
		} elseif ($secondBtnState == 'savecompresp' && $comp_resp_button != '') {
			$secondBtn = $comp_resp_button;
		} elseif ($secondBtnState == 'savenextform' && $next_form_button != '') {
			$secondBtn = $next_form_button;
		} elseif ($secondBtnState == 'savenextinstance' && $next_instance_button != '') {
			$secondBtn = $next_instance_button;
		} elseif ($secondBtnState == 'saveexitrecord' && $exit_record_button != '') {
			$secondBtn = $exit_record_button;
		} elseif ($secondBtnState == 'savenextrecord' && $save_next_record_button != '') {
			$secondBtn = $save_next_record_button;
		} else {
			$secondBtn = $stay_form_button;
		}
		
		//Display SAVE, CANCEL, and DELETE buttons (and possibly hidden Calc fields and possibly "Save and go to Next Form" button)
		$elements[] = array('rr_type'=>'static', 'name'=>'__SUBMITBUTTONS__', 'label'=>'',
			'value'=>'<div id="__SUBMITBUTTONS__-div" style="margin:5px 0;">
						<button class="btn btn-primaryrc" id="submit-btn-saverecord" name="submit-btn-saverecord" onclick="dataEntrySubmit(this);return false;" style="margin-bottom:2px;font-size:13px !important;padding:6px 8px;" tabindex="0">'.$lang['data_entry_288'].'</button>&nbsp;
						<div class="btn-group nowrap">'.$secondBtn . $dropdownSaveBtn.'</div>
						<button class="btn btn-defaultrc btn-sm" style="display:block;margin-top:15px;font-size:13px;" name="submit-btn-cancel" onclick="if(!dataEntryFormValuesChanged || (dataEntryFormValuesChanged && confirm(\''.js_escape($lang['data_entry_469']).'\'))){dataEntrySubmit(this);}return false;" style="color:#000;font-size:13px;" tabindex="0"/>'.$lang['data_entry_207'].'</button>
					</div>');
		
		// DELETE Button
		if ($hidden_edit && $user_rights['record_delete'])
		{
			// RANDOMIZATION
			// Default delete onclick
			$delFormRandOnclick = "";
			// Has the record been randomized?
			$wasRecordRandomized = ($randomization && Randomization::setupStatus() && Randomization::wasRecordRandomized($_GET['id']));
			if ($wasRecordRandomized) {
				// Get randomization attributes
				$randAttr = Randomization::getRandomizationAttributes();
				// Form contains randomizatin field
				$formContainsRandFields = ($randAttr['targetEvent'] == $_GET['event_id'] && $Proj->metadata[$randAttr['targetField']]['form_name'] == $_GET['page']);
				// Loop through strata fields
				foreach ($randAttr['strata'] as $strata_field=>$strata_event) {
					if ($strata_event == $_GET['event_id'] && $Proj->metadata[$strata_field]['form_name'] == $_GET['page']) {
						$formContainsRandFields = true;
					}
				}
				// Set alert message to prevent user from deleting form OR event
				if ($formContainsRandFields) {
					$delFormRandOnclick = "simpleDialog('".js_escape($lang['data_entry_266'])."','".js_escape($lang['global_03'])."');return false;";
				}
			}
			
			// Delete form msg
			$delFormAlertMsg = ($longitudinal ? $lang['data_entry_243'] : $lang['data_entry_239']);
			if (isset($Proj->forms[$_GET['page']]['survey_id']) && $user_rights['forms'][$_GET['page']] == '3' && isset($_GET['editresp'])) {
				$delFormAlertMsg .= RCView::div(array('style'=>'margin-top:15px;color:#C00000;'), $lang['data_entry_241']);
			}
			if ($Proj->isRepeatingForm($_GET['event_id'], $_GET['page'])) {
				$delFormAlertMsg .= RCView::div(array('style'=>'margin-top:15px;color:#C00000;'), $lang['data_entry_432'] . " <b>" . $_GET['instance'] . "</b>".$lang['period']);
			}			
			$delFormAlertMsg .= RCView::div(array('style'=>'margin-top:15px;color:#C00000;font-weight:bold;'), $lang['data_entry_190']);
			// Delete event msg
			$anySurveysOnEvent = false;
			foreach ($Proj->eventsForms as $these_forms) {
				foreach ($these_forms as $this_form) {
					if (!isset($Proj->forms[$this_form]['survey_id'])) continue;
					$anySurveysOnEvent = true;
					break 2;
				}
			}
			$delFormOnclick = "simpleDialog('".str_replace('"', '&quot;', js_escape("<div style='margin:10px 0;font-size:13px;'>$delFormAlertMsg</div>"))."','".str_replace('"', '&quot;', js_escape("{$lang['data_entry_237']} \"{$_GET['id']}\"{$lang['questionmark']}"))."',null,600,null,'".js_escape($lang['global_53'])."',function(){ dataEntrySubmit( document.getElementsByName('submit-btn-deleteform')[0] );return false; },'".js_escape($lang['data_entry_234'])."');return false;";
			if ($delFormRandOnclick != "") $delFormOnclick = $delFormRandOnclick;
			// Set delete buttons
			$elements[] = array('rr_type'=>'static', 'name'=>'__DELETEBUTTONS__', 'label'=>'',
			'value'=>'<div id="__DELETEBUTTONS__-div" style="padding:10px 0 5px;">
						<button class="btn btn-defaultrc btn-xs" name="submit-btn-deleteform" onclick="'.$delFormOnclick.'" style="color:#C00000;margin:3px 0 1px;padding:2px 8px;" tabindex="0"/>'.$lang['data_entry_234'].'</button><br>
						<div style="margin:10px 0 2px;color:#666;font-size:11px;">'.$lang['data_entry_286'].' <a class="opacity75" style="font-size:11px;text-decoration:underline;" href="'.APP_PATH_WEBROOT.'DataEntry/record_home.php?pid='.PROJECT_ID.'&id='.$_GET['id'].($Proj->eventInfo[$_GET['event_id']]['arm_num'] > 1 ? '&arm='.$Proj->eventInfo[$_GET['event_id']]['arm_num'] : '').'">'.$lang['grid_42'].'</a>'.$lang['period'].'</div>
						'.($longitudinal ? '<div style="margin:5px 0 2px;color:#666;font-size:11px;">'.$lang['data_entry_302'].' <a class="opacity75" style="font-size:11px;text-decoration:underline;" href="'.APP_PATH_WEBROOT.'DataEntry/record_home.php?pid='.PROJECT_ID.'&id='.$_GET['id'].($Proj->eventInfo[$_GET['event_id']]['arm_num'] > 1 ? '&arm='.$Proj->eventInfo[$_GET['event_id']]['arm_num'] : '').'">'.$lang['grid_42'].'</a>'.$lang['period'].'</div>' : '').'
					  </div>');
		}
	}


	/**
	 * RENDER FORM ELEMENTS or RECORD DROPDOWNS
	*/

	//Accomodate double data entry (if needed) by appending data entry number to record id
	if ($double_data_entry && $user_rights['double_data'] != 0) {
		$this_record = $_GET['id'] . "--" . $user_rights['double_data'];
	} else {
		$this_record = $_GET['id'];
	}
	//Build query for pulling existing data to render on top of form
	$datasql = "select field_name, value, if (instance is null,1,instance) as instance 
				from redcap_data where project_id = $project_id
				and event_id = {$_GET['event_id']} and record = '".db_escape($this_record)."' and field_name in ('__GROUPID__', ";
	foreach ($elements as $fldarr) {
		if (isset($fldarr['field'])) $datasql .= "'".$fldarr['field']."', ";
	}
	$datasql = substr($datasql, 0, -2) . ")";
	//Execute query and put any existing data into an array to display on form
	$q = db_query($datasql);
	$element_data = array();
	while ($row_data = db_fetch_assoc($q)) 
	{
		// Is field on a repeating form or event?
		$this_form = $Proj->metadata[$row_data['field_name']]['form_name'];
		$isRepeatingForm = $Proj->isRepeatingForm($_GET['event_id'], $this_form);
		$isRepeatingEvent = $Proj->isRepeatingEvent($_GET['event_id']);
		if ($hasRepeatingFormsEvents && $row_data['instance'] != $_GET['instance'] && (($isRepeatingForm && $this_form == $_GET['page']) || $isRepeatingEvent)) {
			// Value exists on same form that is a repeating form but is a different instance, then don't use it here
			continue;
		} elseif (!$isRepeatingForm && !$isRepeatingEvent && $row_data['instance'] > 1) {
			// Data point might be left over if project *used* to have repeating events/forms
			continue;
		}
		//Checkbox: Add data as array
		if (isset($chkbox_flds[$row_data['field_name']])) {
			$element_data[$row_data['field_name']][] = $row_data['value'];
		//Non-checkbox fields: Add data as string
		} else {
			$element_data[$row_data['field_name']] = $row_data['value'];
		}
	}
	// Add value for record identifier when creating new record
	$element_data[$table_pk] = $_GET['id'];

	// If using DAG + Longitudinal and the group_id is not listed for this event (when it exists for at least ONE event for this record),
	// then query again to get existing Group_ID and save it for this event (because it should be there anyway).
	$dags = $Proj->getGroups();
	if (($longitudinal || $isRepeatingFormOrEvent) && !isset($element_data['__GROUPID__']) && !empty($dags))
	{
		// Get group_id value for record and insert for this event (but ONLY if the event has SOME data saved for it)
		$datasql = "select value from redcap_data where	project_id = $project_id and record = '".db_escape($this_record)."'
					and field_name = '__GROUPID__' and value != '' limit 1";
		$q = db_query($datasql);
		if (db_num_rows($q) > 0)
		{
			// Add group_id to $element_data so that the DAG drop-down gets pre-selected with this record's DAG
			$element_data['__GROUPID__'] = db_result($q, 0);
			// Only add group_id if ONLY the event has SOME data saved for it
			$sql = "select 1 from redcap_data where project_id = $project_id and event_id = {$_GET['event_id']}
					and record = '".db_escape($this_record)."' limit 1";
			$q = db_query($sql);
			if (db_num_rows($q) > 0) {
				// Add this group_id for this record-event (because it should already be there anyway)
				$sql = "INSERT INTO redcap_data (project_id, event_id, record, field_name, value) 
						VALUES ($project_id, {$_GET['event_id']}, '".db_escape($this_record)."', '__GROUPID__', '{$element_data['__GROUPID__']}')";
				db_query($sql);
			}
		}
	}

	// Set file upload dialog
	DataEntry::initFileUploadPopup();
	?>

	<style type="text/css">
	.data, .labelrc, .data_matrix, .labelmatrix {
		background:#f5f5f5;
		border:0px;
		border-bottom:1px solid #DDDDDD;
		border-top:0px solid #f5f5f5;
	}
	.header {
		border-left:0;
		border-right:0;
	}
	</style>

	<!-- SECONDARY UNIQUE FIELD JAVASCRIPT -->
	<?php DataEntry::renderSecondaryIdLang() ?>

	<script type='text/javascript'>
	// Add hidden_edit/record_exists and record_exists as javascript variables
	var record_exists = <?php echo $hidden_edit ?>;
	var require_change_reason = <?php echo $require_change_reason ?>;
	// Set event_id and instance
	var event_id = <?php echo $_GET['event_id'] ?>;
	var instance = <?php echo $_GET['instance'] ?>;
	// Language items
	var langSaveLeavePage = '<?php echo js_escape($lang['data_entry_197']) ?>';
	var langLeavePage = '<?php echo js_escape($lang['data_entry_191']) ?>';
	var langStayOnPage = '<?php echo js_escape($lang['data_entry_192']) ?>';
	var langDlgSaveDataTitle = '<?php echo js_escape($lang['data_entry_193']) ?>';
	var langDlgSaveDataTitleCaps = '<?php echo js_escape($lang['data_entry_199']) ?>';
	var langDlgSaveDataMsg = '<?php echo js_escape($lang['data_entry_198']) ?>';
	var lang_exclude = '<?php echo js_escape($lang['dataqueries_87']) ?>';
	var lang_remove_exlusion = '<?php echo js_escape($lang['dataqueries_88']) ?>';
	var langContribTitle = '<?php echo js_escape($lang['survey_1231']) ?>';
    var lang_remove_file = '<?php print js_escape($lang['form_renderer_24']) ?>';
    var lang_send_it = '<?php print js_escape($lang['form_renderer_25']) ?>';
    var lang_upload_new_version = '<?php print js_escape($lang['data_entry_459']) ?>';
    var lang_data_entry_370 = '<?php print js_escape($lang['data_entry_370']) ?>';
    var lang_data_entry_371 = '<?php print js_escape($lang['data_entry_371']) ?>';
    var lang_data_entry_473 = '<?php print js_escape($lang['data_entry_473']) ?>';
    var lang_data_entry_474 = '<?php print js_escape($lang['data_entry_474']) ?>';
	</script>
	<?php

	// Hidden dialog to REMIND USER TO SAVE DATA IF TRIES TO LEAVE PAGE
	print 	RCView::div(array('id'=>'stayOnPageReminderDialog', 'class'=>'simpleDialog', 'style'=>'display:none;'),
				RCView::div(array('style'=>'font-size:14px;line-height:1.5em;'),
					$lang['data_entry_194'] . " " . RCView::b($lang['data_entry_195']) . " " . $lang['data_entry_196']
				)
			);

	// Call JavaScript files
	loadJS('DataEntry.js');
	loadJS('Libraries/geoPosition.js');
	loadJS('Libraries/geoPositionSimulator.js');
	?>
	<script type='text/javascript'>
	// Language items
	var langCancel = '<?php print js_escape($lang['global_53']) ?>';
	var langYes = '<?php print js_escape($lang['design_100']) ?>';
	var langRemoveSpaces = '<?php print js_escape($lang['data_entry_433']) ?>';
	</script>
	<?php

	// Render form
	DataEntry::renderForm($elements, $element_data);

	// Call this JS file ONLY after DataEntry::renderForm()
	loadJS('DataEntrySurveyCommon.js');

	// Render fields and their values from other events as separate hidden forms
	if ($longitudinal) {
		print Form::addHiddenFieldsOtherEvents($_GET['id'], $_GET['event_id'], $_GET['page'], $_GET['instance']);
	}

	// Moved by RaB to fix bug
	addLangToJS(array('global_210', 'global_211', 'global_212', 'global_213', 'global_214', 'global_215', 'questionmark', 'global_216', 'global_217', 'global_218', 'global_219', 'period', 'global_220', 'global_221', 'global_222', 'global_223', 'global_224', 'global_225', 'global_226', 'global_227'));
	// Generate JavaScript equations for Calculated Fields and Branching Logic
	print $cp->exportJS();
	print $bl->exportBranchingJS();
//	addLangToJS(array('global_210', 'global_211', 'global_212', 'global_213', 'global_214', 'global_215', 'questionmark', 'global_216', 'global_217', 'global_218', 'global_219', 'period', 'global_220', 'global_221', 'global_222', 'global_223', 'global_224', 'global_225', 'global_226', 'global_227'));

	// Print javascript that hides checkbox fields from other forms, which need to be hidden
	print $jsHideOtherFormChkbox;

	?>

	<!-- Hidden field for checking if a validation error has been thrown. Used to prevent form submission. -->
	<input type="hidden" id="field_validation_error_state" value="0">

	<!-- Data history dialog pop-up -->
	<div id="data_history" style="display:none;">
		<p>
			<?php echo $lang['data_entry_66'] ?> "<b id="dh_var"></b>" <?php echo $lang['data_entry_67'] ?>
			<?php echo "$table_pk_label \"<b>" .
					(($double_data_entry && isset($user_rights) && $user_rights['double_data'] != 0) ? substr($fetched, 0, -3) : $fetched) .
					"</b>\"{$lang['period']} {$lang['dataqueries_276']}" ?>
		</p>
		<div id="data_history2" style="margin:15px 0px 20px;"></div>
	</div>

	<?php
	/**
	 * IF REQUIRING "CHANGE REASON" FOR ANY DATA CHANGES
	*/
	if ($require_change_reason)
	{
		?>
		<!-- Change reason pop-up-->
		<div id="change_reason_popup" title="Please supply reason for data changes" style="display:none;margin-bottom:25px;">
			<p>
				<?php echo $lang['data_entry_68'] ?>
			</p>
			<div style="font-weight:bold;padding:5px 0;"><?php echo $lang['data_entry_69'] ?></div>
			<!-- Textarea box for reason -->
			<div><textarea id="change_reason" onblur="charLimit('change_reason',200);" class="x-form-textarea x-form-field" style="width:400px;height:120px;"></textarea></div>
			<!-- Hidden error message -->
			<div id="change_reason_popup_error" class="red" style="display:none;margin-top:20px;">
				<img src="<?php echo APP_PATH_IMAGES ?>exclamation.png">
				<?php echo $lang['data_entry_70'] ?>
			</div>
		</div>
		<?php
	}


	/**
	 * FORM LOCKING POP-UP FOR E-SIGNATURE
	 * Only display it if user has rights AND the form is set to display the e-signature
	*/
	if ($user_rights['lock_record'] > 1)
	{
		// Query table to determine if form is set to display the e-signature
		$sql = "select 1 from redcap_locking_labels where project_id = $project_id
				and form_name = '{$_GET['page']}' and display_esignature = 1 limit 1";
		$displayEsigOption = (db_num_rows(db_query($sql)) > 0);
		// Include file for the pop-up to be displayed
		if ($displayEsigOption) {
			include APP_PATH_DOCROOT . "Locking/esignature_popup.php";
		}
	}


	// DATA QUALITY RULES pop-up message (URL variable 'dq_error_ruleids' has been passed)
	if (isset($_GET['dq_error_ruleids']))
	{
		$dq = new DataQuality();
		$repeat_instrument = $Proj->isRepeatingForm($_GET['event_id'], $_GET['page']) ? $_GET['page'] : "";
		$dq->displayViolationsSingleRecord(explode(",", $_GET['dq_error_ruleids']), $fetched, $_GET['event_id'], $_GET['page'], 0, $_GET['instance'], $repeat_instrument);
		// Div for pop-up tooltip
		print RCView::div(array('id'=>'dqRteFieldFocusTip', 'class'=>'tooltip4'),
				$lang['dataqueries_128'] .
				RCView::div(array('style'=>'text-align:center;padding:10px 0 6px;'),
					RCView::button(array('onclick'=>"$('form#form :input[name=\"submit-btn-savecontinue\"]').click();"),
						$lang['data_entry_206']
					)
				)
			  );
	}

	// REQUIRED FIELDS pop-up message (URL variable 'msg' has been passed)
	DataEntry::msgReqFields($fetched, $last_form);

	// SERVER-SIDE VALIDATION pop-up message (URL variable 'dq_error_ruleids' has been passed)
	if (isset($_GET['serverside_error_fields'])) Form::displayFailedServerSideValidationsPopup($_GET['serverside_error_fields']);
	
	// @MAXCHOICE error pop-up message (URL variable 'maxchoice_error_fields' has been passed)
	if (isset($_GET['maxchoice_error_fields'])) Form::displayFailedSaveMaxChoicePopup($_GET['maxchoice_error_fields']);

	// Put focus on a field if coming from Graphical Data View or have Required Fields not entered
	if (isset($_GET['fldfocus']) && isset($Proj->metadata[$_GET['fldfocus']]))
	{
		?>
		<script type='text/javascript'>
		$(function() {
			setTimeout(function(){
				try {
				    if ($('#form input[name="<?=$_GET['fldfocus']?>"]').hasClass('hiddenradio')) {
                        $('#form input[name="<?=$_GET['fldfocus']?>___radio"]').focus();
                    } else {
                        $('#form input[name="<?=$_GET['fldfocus']?>"]').focus();
                    }
				} catch(e) { }
			},500);
		});
		</script>
		<?php
	}

	// Floating "Save" button tooltip fixed at top-right of data entry page
	print RCView::div(array('id'=>'formSaveTip'), "");

	// Floating "Save" button tooltip for Data Resolution Workflow (Save + Open DRW dialog)
	print RCView::div(array('id'=>'tooltipDRWsave', 'class'=>'tooltip4left'), "");

	// DATA RESOLUTION WORKFLOW: Auto open popup for given field
	if (isset($_GET['dqresfld']) && isset($Proj->metadata[$_GET['dqresfld']]))
	{
		?>
		<script type='text/javascript'>
		$(function() {
			$('#dc-icon-<?php echo $_GET['dqresfld'] ?>').click();
			// Make sure the focus gets put in the Field Comment Log dialog
            focusFieldCommentLog();
			setTimeout('focusFieldCommentLog()', 500);
			setTimeout('focusFieldCommentLog()', 1500);
		});
		function focusFieldCommentLog() {
		    $('#dc-comment').focus();
        }
		</script>
		<?php
	}

	// DATA RESOLUTION WORKFLOW: Render the file upload dialog (when applicable)
	print DataQuality::renderDataResFileUploadDialog();

	// If DDP is enabled AND user has access AND external id field is on page, then trigger popup via jQuery
	$DDP->renderJsAdjudicationPopup($fetched, $_GET['event_id'], $_GET['page'], $_GET['instance']);

	// DDP auto open: Auto open popup for DDP after initial record creation
	if (isset($_GET['openDDP']) && (($DDP->isEnabledInSystem() && $DDP->isEnabledInProject()) || ($DDP->isEnabledInSystemFhir() && $DDP->isEnabledInProjectFhir())))
	{
		?>
		<script type='text/javascript'>
		$(function() {
			openAdjudicationDialog(getParameterByName('id'));
		});
		</script>
		<?php
	}

	// REDCap Hook injection point: Pass project/record/form attributes to method
	$group_id = (empty($Proj->groups)) ? null : Records::getRecordGroupId(PROJECT_ID, $fetched);
	if (!is_numeric($group_id)) $group_id = null;
	Hooks::call('redcap_data_entry_form', array(PROJECT_ID, ($hidden_edit ? $fetched : null), $_GET['page'], $_GET['event_id'], $group_id, $_GET['instance']));
}



//Finish page by including 'bottom page code (contains menus)'
include APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';