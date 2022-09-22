<?php



// Set flag for no authentication for survey pages
define("NOAUTH", true);
// Call config_functions before config file in this case since we need some setup before calling config
require_once dirname(dirname(__FILE__)) . '/Config/init_functions.php';
// Initialize REDCap
System::init();
// Determine if Twilio API is making the request to REDCap
$isTwilio = isset($_SERVER['HTTP_X_TWILIO_SIGNATURE']);

// Twilio Two-Factor Auth: Check for user's response to 2FA SMS
// db_query("insert into aaa (mytext) values ('".db_escape($_SERVER['REQUEST_URI']."\n".print_r($_POST, true))."')");
if ($two_factor_auth_enabled && $isTwilio
	// Make sure we have one of these two flags in the survey URL's query string
	&& (   (isset($_POST['CallSid']) && isset($_GET[Authentication::TWILIO_2FA_PHONECALL_FLAG]))
		|| ((isset($_POST['CallSid']) || isset($_POST['MessageSid'])) && isset($_GET[Authentication::TWILIO_2FA_SUCCESS_FLAG])))
) {
	// User is responding to SMS or phone call, so set phone number as verified
	if (isset($_GET[Authentication::TWILIO_2FA_SUCCESS_FLAG])
		// Also validate that this is truly the Twilio server making the request
		&& TwilioRC::verifyTwilioServerSignature($two_factor_auth_twilio_auth_token, Authentication::getTwilioTwoFactorSuccessSmsUrl())
	) {
		// If using phone call method, then use the To number. For SMS, the user's phone will be From.
		Authentication::verifyTwoFactorCodeForPhoneNumber(isset($_POST['CallSid']) ? $_POST['To'] : $_POST['From']);
		// For phone call method, return Twml
		if (isset($_POST['CallSid'])) {
			// Return valid TWIML to say Thank You and hang up
			Authentication::outputTwoFactorPhoneCallTwimlThankYou();
		}
	}
	// Return Twiml to Twilio to be spoken to user performing phone call 2FA
	elseif (isset($_GET[Authentication::TWILIO_2FA_PHONECALL_FLAG]))  {
		Authentication::outputTwoFactorPhoneCallTwiml();
	}
	exit;
}

// Twilio: Add to cron for erasing the log of this event (either SMS or call)
if ($isTwilio && (isset($_POST['CallSid']) || isset($_POST['MessageSid']))) {
	TwilioRC::addEraseCall('', (isset($_POST['CallSid']) ? $_POST['CallSid'] : $_POST['MessageSid']), '', (isset($_POST['AccountSid']) ? $_POST['AccountSid'] : null));
}

// TWILIO CALL LOG REMOVAL
elseif ($isTwilio && isset($_GET['__sid_hash']))
{
	// Obtain the SID of this Twilio event that was just completed
	require_once dirname(dirname(__FILE__)) . "/Config/init_global.php";
	list ($project_id, $sid) = TwilioRC::eraseCallLog($_GET['__sid_hash']);
	if ($sid === false) exit;
	// Now set $_GET['pid'] before calling init_project
	$_GET['pid'] = $project_id;
	// Init Twilio
	require_once dirname(dirname(__FILE__)) . "/Config/init_project.php";
	TwilioRC::init();
	$twilioClient = TwilioRC::client();
	try {
		// If an error occurred for the Twilio call (does not include SMS), then obtain its notification sid and delete the notification log
		if (isset($_GET['__error'])) {
			foreach ($twilioClient->account->notifications->getIterator(0, 50, array("MessageDate" => date("Y-m-d"), "Log" => "0")) as $notification) {
				// Skip all except the one we're looking for
				if ($notification->call_sid != $sid) continue;
				// Remove the notification now that we've tested it
				$twilioClient->account->notifications->delete($notification->sid);
				break;
			}
		}
		// Erase the log of this event (either SMS or call)
		if (substr($sid, 0, 2) == 'SM') {
			$twilioClient->account->messages->delete($sid);
		} else {
			$twilioClient->account->calls->delete($sid);
		}
	} catch (Exception $e) { }
	// Return valid TWIML to just hang up
	$twiml = new Services_Twilio_Twiml();
	$twiml->hangup();
	exit($twiml);
}
// CLOSE TAB/WINDOW MESSAGE
elseif (isset($_GET['__closewindow']))
{
	// Call init
    if (isset($_GET['pid'])) {
		require_once dirname(dirname(__FILE__)) . "/Config/init_project.php";
    } else {
		require_once dirname(dirname(__FILE__)) . "/Config/init_global.php";
	}
	Survey::exitSurvey($lang['survey_1241']."<br>", true, false, false);
}
// ALERTS & NOTIFICATIONS: TWILIO VOICE CALL
if (isset($_GET['a']) && !isset($_GET['sq']) && !isset($_GET['s']) && !isset($_GET['hash']) && is_numeric(decrypt(base64_decode($_GET['a']))) && $isTwilio)
{
	// Get the alert sent log id
	$alert = new Alerts();
	$alert_sent_log_id = decrypt(base64_decode($_GET['a']));
	$label = trim(replaceNBSP(strip_tags($alert->getAlertMessageByAlertSentLogId($alert_sent_log_id))));
	// Now set $_GET['pid'] before calling init_project
	$_GET['pid'] = $project_id = $alert->getAlertProjectIdByAlertSentLogId($alert_sent_log_id);
	// Init Twilio
	require_once dirname(dirname(__FILE__)) . "/Config/init_project.php";
	TwilioRC::init();
    // Set voice and language attributes for all Say commands
	$language = TwilioRC::getLanguage();
	$voice = TwilioRC::getVoiceGender();
	$say_array = array('voice' => $voice, 'language' => $language);
    // Set header to output TWIML/XML
	header('Content-Type: text/xml');
	$twiml = new Services_Twilio_Twiml();
    // Output the text
	$twiml->pause("");
	$twiml->say($label, $say_array);
	exit($twiml);
}
// SURVEY ACCESS CODES: Validate the survey access code entered and redirect to survey OR display access code login form
elseif (!isset($_GET['sq']) && !isset($_GET['s']) && !isset($_GET['hash']))
{
	// Initialize
	$validAccessCode = null;
	// Call init_global
	require_once dirname(dirname(__FILE__)) . "/Config/init_global.php";
	// If using Twilio, then initialize and start session for SMS
	if ($isTwilio) {
		// Init Twilio
		TwilioRC::init();
		$twilioClient = TwilioRC::client();
		// Start survey session here to allow continuity via SMS from here to actual survey pages
		Session::init("survey");
		// If access code is somehow false, the unset it
		if (isset($_SESSION['survey_access_code']) && $_SESSION['survey_access_code'] === false) unset($_SESSION['survey_access_code']);
		// Check if this project has surveys enabled for Twilio. If not, then must be a reply to an Alert, so do nothing.
		if (!isset($_SESSION['survey_access_code']) && isset($_POST['From']) && isset($_POST['To'])) {
			// Get project_id
			$twilioNumberPid = TwilioRC::getProjectIdFromTwilioPhoneNumber($_POST['To']);
			$Proj = new Project($twilioNumberPid);
			// If surveys are not enabled for Twilio, then stop here and send back informational SMS that this number is not being monitored
			if ($Proj->twilio_enabled_alerts && !$Proj->twilio_enabled_surveys) {
				TwilioRC::sendSMS($lang['survey_1286'], $_POST['From'], $twilioClient, null, false);
				exit;
			}
		}
		// Check if we have an access code stored for this phone number
		if (!isset($_SESSION['survey_access_code']) && isset($_POST['From']) && isset($_POST['To']))
		{
			$phone_code = TwilioRC::getSmsAccessCodeFromPhoneNumber($_POST['From'], $_POST['To']);
			if ($phone_code !== null) {
				if (is_array($phone_code)) {
					// MULTIPLE ACCESS CODES HAVE BEEN SENT VIA SMS

					// Obtain project_id via the access codes
					$_GET['pid'] = TwilioRC::getProjectIdFromNumericAccessCode($phone_code);
					// Config
					require_once dirname(dirname(__FILE__)) . "/Config/init_project.php";

					// SMS only: Multiple access codes
					if (!isset($_POST['CallSid'])) {
						// If multiple access codes exist and user just submitted a valid one, then set code in session
						if (isset($_POST['Body']) && in_array($_POST['Body'], $phone_code)) {
							// Set submitted access code in session
							$_SESSION['survey_access_code'] = $_POST['Body'];
						} else {
							// Multiple access codes exist, so return list to participant via SMS message
							// Build text to send so that it includes beginning of survey name
							$phone_codes_titles_text = array();
							foreach (TwilioRC::getSurveyTitlesFromNumericAccessCode($phone_code) as $access_code_numeral=>$title) {
								$phone_codes_titles_text[] = "$access_code_numeral = $title";
							}
							// Send SMS with list of surveys and their corresponding access codes
							TwilioRC::sendSMS($lang['survey_960'] . " " . trim(implode(", ", $phone_codes_titles_text)), $_POST['From'], $twilioClient, null, true, $_GET['pid']);
							exit;
						}
					}
					// Call only: Multiple access codes
					else {
						// Set voice and language attributes for all Say commands
						$language = TwilioRC::getLanguage();
						$voice = TwilioRC::getVoiceGender();
						$say_array = array('voice'=>$voice, 'language'=>$language);
						// Get access codes with associated survey titles
						$phone_codes_titles = TwilioRC::getSurveyTitlesFromNumericAccessCode($phone_code, false);
						// Build text to say
						$phone_codes_titles_text = array();
						$phone_code_title_num = 1;
						foreach ($phone_codes_titles as $access_code_numeral=>$title) {
							// If this survey was just chosen, then set access code in session and redirect it to the survey page
							if (isset($_POST['Digits']) && $_POST['Digits'] == $phone_code_title_num) {
								// Add code to session
								$_SESSION['survey_access_code'] = $access_code_numeral;
								// Redirect to survey page
								TwilioRC::redirectSurvey();
							}
							// Add to array
							$phone_codes_titles_text[$phone_code_title_num] = "$title, {$lang['survey_951']} $phone_code_title_num.";
							$phone_code_title_num++;
						}
						$label = $lang['survey_961']." ".implode(" ", $phone_codes_titles_text);
						// Set header to output TWIML/XML
						header('Content-Type: text/xml');
						$twiml = new Services_Twilio_Twiml();
						$gather_params = array('method'=>'POST', 'action'=>APP_PATH_SURVEY_FULL, 'timeout'=>3, 'numDigits'=>strlen("".($phone_code_title_num-1)));
						// Ask question and repeat
						$gather = $twiml->gather($gather_params);
						$gather->say($label, $say_array);
						$gather = $twiml->gather($gather_params);
						$gather->say("", $say_array);
						$gather2 = $twiml->gather($gather_params);
						$gather2->say($label, $say_array);
						$gather2 = $twiml->gather($gather_params);
						$gather2->say("", $say_array);
						exit($twiml);
					}
				}
				// SINGLE ACCESS CODE: Add to session to redirect to the correct survey
				else {
					$_SESSION['survey_access_code'] = $phone_code;
				}
			}
		}
		// Initialize session variable as blank if not exists
		if (!isset($_SESSION['survey_access_code'])) {
			$_SESSION['survey_access_code'] = null;
		}
	}
	// Get the code
	if (isset($_SESSION['survey_access_code'])) {
		$code = $_SESSION['survey_access_code'];
	} elseif (isset($_GET['code'])) {
		$code = $_GET['code'];
	} elseif (isset($_POST['code'])) {
		$code = $_POST['code'];
	} else {
		$code = '';
	}

	// If using Twilio voice call or SMS, prompt for survey access code
	if ($code == '' && $isTwilio && $_SESSION['survey_access_code'] == null) {
		if (!isset($_POST['Body']) && !isset($_POST['Digits'])) {
			// Ask for survey access code
			TwilioRC::promptSurveyCode(isset($_POST['CallSid']), $_POST['From'], $_POST['To']);
		} else {
			// If just submitted survey access code
			$code = (isset($_POST['Body'])) ? $_POST['Body'] : $_POST['Digits'];
		}
	}
	// Validate code, if just submitted
	if ($code != '') {
		$validAccessCode = $hash = Survey::validateAccessCodeForm($code);
		if ($validAccessCode !== false) {
			// Valid code, so redirect to survey
			if ($isTwilio) {
				// TWILIO: Do redirect
				// SMS: Save code to session
				if (!isset($_POST['CallSid'])) $_SESSION['survey_access_code'] = $code;
				// Redirect to survey page
				TwilioRC::redirectSurvey($hash);
			} else {
				// Normal web redirect
				redirect(APP_PATH_SURVEY . "index.php?s=$validAccessCode");
			}
		} elseif ($isTwilio) {
			// TWILIO: Not a valid code, so repeat and ask for survey access code again
			TwilioRC::promptSurveyCode(isset($_POST['CallSid']), $_POST['From'], $_POST['To']);
		}
	}
	// Display Quick login form
	if ($validAccessCode !== true) {
		Survey::exitSurvey(Survey::displayAccessCodeForm($validAccessCode===false), false, false, false);
	}
}
// SURVEY QUEUE: If this is a Survey Queue page and not a survey page to be displayed, then display the Survey Queue
if (isset($_GET['sq']))
{
	// Validate the survey queue hash
	list ($project_id, $record) = Survey::checkSurveyQueueHash($_GET['sq']);
	// Now set $_GET['pid'] before calling init_project
	$_GET['pid'] = $project_id;
	// Config
	require_once dirname(dirname(__FILE__)) . "/Config/init_project.php";
	// If survey queue is not enabled, then stop here with error
	if (!Survey::surveyQueueEnabled()) {
		Survey::exitSurvey($lang['survey_508'], true, $lang['survey_509']);
	}
	// If sending an email with survey queue link to the respondent, then send the email
	if ($isAjax && isset($_POST['to'])) {
		## SEND EMAIL
		// Set email body
		$emailContents = '<html><body style="font-family:arial,helvetica;font-size:10pt;">' .
			$lang['survey_520'] . "<br>" . APP_PATH_SURVEY_FULL . '?sq=' . $_GET['sq'] .
			'</body></html>';
		//Send email
		$email = new Message ();
		$email->setTo($_POST['to']);
		$email->setFrom($project_contact_email);
		$email->setFromName($GLOBALS['project_contact_name']);
		$email->setSubject($lang['survey_523']);
		$email->setBody($emailContents);
		// Return "0" for failure or email if successful
		exit($email->send() ? "1" : "0");
	} else {
		// Display Survey Queue (don't render page header/footer if Ajax)
		$survey_queue = Survey::displaySurveyQueueForRecord($record, false);
		if ($isAjax) {
			exit($survey_queue);
		} else {
			Survey::exitSurvey(RCView::div(array('style'=>'margin:0 0 0 -11px;'), $survey_queue), true, $lang['survey_509'], false);
		}
	}
}

// Validate and clean the survey hash, while also returning if a legacy hash
$hash = $_GET['s'] = Survey::checkSurveyHash();
// Set all survey attributes as global variables
Survey::setSurveyVals($hash);
// Now set $_GET['pid'] before calling init_project
$_GET['pid'] = $project_id;
// Config
require_once dirname(dirname(__FILE__)) . "/Config/init_project.php";
// Set survey values
$_GET['event_id'] = $event_id;
$arm_id = $Proj->eventInfo[$event_id]['arm_id'];
$_GET['page'] = $form_name = (empty($form_name) ? $Proj->firstForm : $form_name);
// Set constants to designate voice vs. sms
define("VOICE", ($isTwilio &&  isset($_POST['CallSid'])));
define("SMS", 	($isTwilio && !isset($_POST['CallSid'])));
// If this link *used* to be a public survey link but then another instrument was later set as the first instrument and thus became
// the new public survey link, then give an error that this link is not valid (it would allow repsondents to create records while on
// non-first instruments - could cause data issues downstream).
if ($participant_email === null && ($form_name != $Proj->firstForm || !in_array($Proj->firstForm, $Proj->eventsForms[$Proj->firstEventId]))) {
	Survey::exitSurvey($lang['survey_14']);
}
// Is this a public survey (vs. invited via Participant List)?
$public_survey = ($participant_email === null && $form_name == $Proj->firstForm && $Proj->isFirstEventIdInArm($event_id));
// If the first instrument in a longitudinal project, in which the instrument is not designated for this event, then display an error.
if ($longitudinal && !in_array($form_name, $Proj->eventsForms[$event_id])) {
	Survey::exitSurvey(RCView::b($lang['survey_550'])."<br>".$lang['survey_551'], false);
}

// If survey is enabled, check if its access has expired.
if ($survey_enabled > 0 && $survey_expiration != '' && $survey_expiration <= NOW) {
	// Survey has expired, so set it as inactive
	$survey_enabled = 0;
	db_query("update redcap_surveys set survey_enabled = 0 where survey_id = $survey_id");
}

// If survey is disabled OR project is inactive or archived OR if project has been scheduled for deletion, then do not display survey.
if (!$surveys_enabled || $survey_enabled < 1 || $date_deleted != '' || $status == 2 || $completed_time != '') {
	Survey::exitSurvey($lang['survey_219']);
}

// Check time limit for survey completion, if enabled (for private survey links only)
if (!$public_survey && !Survey::checkSurveyTimeLimit($participant_id, $survey_time_limit_days, $survey_time_limit_hours, $survey_time_limit_minutes)) 
{
	// We've hit the time limit, so display message to respondent
	Survey::exitSurvey($lang['survey_1105']);
}

// REPEATING FORMS/EVENTS: Check for "instance" number if the form is set to repeat
$isRepeatingFormOrEvent = $Proj->isRepeatingFormOrEvent($_GET['event_id'], $_GET['page']);
$repeatInstrument = ($repeat_survey_enabled && $Proj->isRepeatingForm($_GET['event_id'], $_GET['page'])) ? $_GET['page'] : "";
$hasRepeatingFormsEvents = !empty($Proj->RepeatingFormsEvents);
if ($isRepeatingFormOrEvent && !$public_survey) {
	// Obtain instance from response table
	$_GET['instance'] = Survey::getInstanceNumFromParticipantId($participant_id);
}

// Set custom text for response limit
if (trim($response_limit_custom_text) == "") {
	$response_limit_custom_text = $lang['survey_1101'];
} else {
	$response_limit_custom_text = decode_filter_tags($response_limit_custom_text);
}

// If this survey has Save & Return disabled, make sure that the sub-options for Save & Return are also disabled
if (!$save_and_return) {
	$save_and_return_code_bypass = $edit_completed_response = 0;
}


// Make sure any CSRF tokens get unset here (just in case)
unset($_POST['redcap_csrf_token']);
// PASSTHRU: Use this page as a passthru for certain files used by the survey page (e.g., file uploading/downloading)
if (isset($_GET['__passthru']) && !empty($_GET['__passthru']))
{
	// Set array of allowed passthru files
	$passthruFiles = array(
		"DataEntry/file_download.php", "DataEntry/file_upload.php", "DataEntry/file_delete.php",
		"DataEntry/image_view.php", "Surveys/email_participant_return_code.php",
		"DataEntry/empty.php", "DataEntry/check_unique_ajax.php", "DataEntry/piping_dropdown_replace.php",
		"DataExport/plot_chart.php", "Surveys/email_participant_confirmation.php", "Surveys/speak.php",
		"DataEntry/web_service_auto_suggest.php", "DataEntry/web_service_cache_item.php", "PdfController:index",
		"Graphical/image_base64_download.php", "Surveys/twilio_initiate_call_sms.php", "index.php"
	);
	// Check if a valid passthru file
	$passthruFileKey = array_search(urldecode($_GET['__passthru']), $passthruFiles);
	if ($passthruFileKey === false) exit("ERROR");
	// If we're calling the index.php end-point, then it must have a route
    if ($_GET['__passthru'] == 'index.php' && !isset($_GET['route'])) exit("ERROR");
	// Include the file
	require_once APP_PATH_DOCROOT . $passthruFiles[$passthruFileKey];
	exit;
}


// VOICE/SMS
if (VOICE || SMS)
{
    // Call Twilio question file to handle question-by-question operations
    require_once APP_PATH_DOCROOT . 'Surveys/twilio_question.php';
    exit;
}


// Initialize DAGs, if any are defined
$Proj->getGroups();


// Class for html page display system
$objHtmlPage = new HtmlPage();
$objHtmlPage->addExternalJS(APP_PATH_JS . "Survey.js");
$objHtmlPage->addExternalJS(APP_PATH_JS . "FontSize.js");
$objHtmlPage->addStylesheet("survey.css", 'screen,print');
$objHtmlPage->setPageTitle(strip_tags($title));
// Set the font family
$objHtmlPage = Survey::applyFont($font_family, $objHtmlPage);
// Set the size of survey text
$objHtmlPage = Survey::setTextSize($text_size, $objHtmlPage);
// If survey theme is being used, then apply it here
$custom_theme_attr = array();
if ($theme == '' && $theme_bg_page != '') {
	$custom_theme_attr = array(
		'theme_text_buttons'=>$theme_text_buttons, 'theme_bg_page'=>$theme_bg_page,
		'theme_text_title'=>$theme_text_title, 'theme_bg_title'=>$theme_bg_title,
		'theme_text_sectionheader'=>$theme_text_sectionheader, 'theme_bg_sectionheader'=>$theme_bg_sectionheader,
		'theme_text_question'=>$theme_text_question, 'theme_bg_question'=>$theme_bg_question
	);
}
$objHtmlPage = Survey::applyTheme($theme, $objHtmlPage, $custom_theme_attr);


## SET SURVEY TITLE AND LOGO
$title_logo = "";
// LOGO: Render, if logo is provided
if (is_numeric($logo)) {
	//Set max-width for logo (include for mobile devices)
	$logo_width = (isset($isMobileDevice) && $isMobileDevice) ? '300' : '600';
	// Get img dimensions (local file storage only)
	$thisImgMaxWidth = $logo_width;
	$styleDim = "max-width:{$thisImgMaxWidth}px;";
	list ($thisImgWidth, $thisImgHeight) = Files::getImgWidthHeightByDocId($logo);
	if (is_numeric($thisImgHeight)) {
		$thisImgMaxHeight = round($thisImgMaxWidth/$thisImgWidth*$thisImgHeight);
		if ($thisImgWidth < $thisImgMaxWidth) {
			// Use native dimensions
			$styleDim = "width:{$thisImgWidth}px;max-width:{$thisImgWidth}px;height:{$thisImgHeight}px;max-height:{$thisImgHeight}px;";
		} else {
			// Shrink size
			$styleDim = "width:{$thisImgMaxWidth}px;max-width:{$thisImgMaxWidth}px;height:{$thisImgMaxHeight}px;max-height:{$thisImgMaxHeight}px;";
		}
	}
	$title_logo .= "<div style='padding:10px 0 0;'><img id='survey_logo' onload='try{reloadSpeakIconsForLogo()}catch(e){}' src='" . APP_PATH_SURVEY . "index.php?pid=$project_id&doc_id_hash=".Files::docIdHash($logo)."&__passthru=".urlencode("DataEntry/image_view.php")."&s=$hash&id=$logo' alt='".js_escape($lang['survey_1140'])."' title='".js_escape($lang['survey_1140'])."' style='max-width:{$logo_width}px;$styleDim'></div>";
}
// SURVEY TITLE
if (!$hide_title) {
	$title_logo .= "<h1 id='surveytitle'>".filter_tags($title)."</h1>";
}

// Create array of field names designating their survey page with page number as key, and the number of total pages for survey
list ($pageFields, $totalPages) = Survey::getPageFields($form_name, $question_by_section);

// GET RESPONSE ID: If $_POST['__response_hash__'] exists and is not empty, then set $_POST['__response_id__']
Survey::initResponseId();

// CHECK POSTED PAGE NUMBER (verify if correct to prevent gaming the system)
Survey::initPageNumCheck();

// If posting to survey from other webpage and using __prefill flag, then unset $_POST['submit-action'] to prevent issues downstream
if (isset($_POST['__prefill'])) unset($_POST['submit-action']);

// PROMIS: Determine if instrument is a PROMIS instrument downloaded from the Shared Library
list ($isPromisInstrument, $isAutoScoringInstrument) = PROMIS::isPromisInstrument($_GET['page']);


/**
 * START OVER: For non-public surveys where the user returned later and decided to "start over" (delete existing response)
 */
if (!$public_survey && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_GET['__startover']) && isset($_POST['__response_hash__']))
{
	// Get record name from response table
	$sql = "select record, completion_time from redcap_surveys_response where response_id = ".$_POST['__response_id__'];
	$q = db_query($sql);
	$_GET['id'] = $_POST[$table_pk] = $fetched = db_result($q, 0, 'record');
	$this_completion_time = db_result($q, 0, 'completion_time');
	// Check if the response has been completed and participants are not allowed to edit completed responses, then redirect back to Return Code page
    if ($this_completion_time != '' && !$edit_completed_response) {
        redirect(str_replace("__startover=", "__return=", $_SERVER['REQUEST_URI']));
    }
	// Get list of all fields with data for this record
	$sql = "select distinct field_name from redcap_data where project_id = $project_id and event_id = $event_id and record = '".db_escape($fetched)."'
			and field_name in (" . prep_implode(array_keys($Proj->forms[$form_name]['fields'])) . ") and field_name != '$table_pk'";
	if ($hasRepeatingFormsEvents) {
		$sql .= " and instance ".($_GET['instance'] == '1' ? "is NULL" : "= '".db_escape($_GET['instance'])."'");
	}
	$q = db_query($sql);
	$eraseFields = $eraseFieldsLogging = array();
	while ($row = db_fetch_assoc($q)) {
		// Add to field list
		$eraseFields[] = $row['field_name'];
		// Add default data values to logging field list
		if ($Proj->isCheckbox($row['field_name'])) {
			foreach (array_keys(parseEnum($Proj->metadata[$row['field_name']]['element_enum'])) as $this_code) {
				$eraseFieldsLogging[] = "{$row['field_name']}($this_code) = unchecked";
			}
		} else {
			$eraseFieldsLogging[] = "{$row['field_name']} = ''";
		}
	}
	// Delete all responses from data table for this form (do not delete actual record name - will keep same record name)
	$sql = "delete from redcap_data where project_id = $project_id and event_id = $event_id and record = '".db_escape($fetched)."'
			and field_name in (" . prep_implode($eraseFields) . ")";
	if ($hasRepeatingFormsEvents) {
		$sql .= " and instance ".($_GET['instance'] == '1' ? "is NULL" : "= '".db_escape($_GET['instance'])."'");
	}
	db_query($sql);
	// Log the data change
	Logging::logEvent($sql, "redcap_data", "UPDATE", $fetched, implode(",\n",$eraseFieldsLogging), "Erase survey responses and start survey over");
	// Reset the page number to 1
	$_GET['__page__'] = 1;
	// Set hidden edit
	$hidden_edit = 1;
}




/**
 * SURVEY LOGIN - RETURNING PARTICIPANT: Participant is "Returning Later" and will enter data value to return
 */
// Show page for entering validation code OR validate code and determine response_id from it
if (Survey::surveyLoginEnabled() && !$public_survey && ($survey_auth_apply_all_surveys || $survey_auth_enabled_single))
{
	// Two cookies are used to maintain the login session, so if one is missing or if their values are different, then delete them both.
	if (!(isset($_COOKIE['survey_login_pid'.$project_id]) && isset($_COOKIE['survey_login_session_pid'.$project_id])
		&& $_COOKIE['survey_login_pid'.$project_id] == $_COOKIE['survey_login_session_pid'.$project_id])) {
		// Destroy cookies
		deletecookie('survey_login_pid'.$project_id);
		deletecookie('survey_login_session_pid'.$project_id);
	}
	// Set array of fields/events
	$surveyLoginFieldsEvents = Survey::getSurveyLoginFieldsEvents();
	// Count auth fields
	$loginFieldCount = count($surveyLoginFieldsEvents);
	// Set flag (null by default, then boolean when set later)
	$surveyLoginFailed = null;

	// GET RECORD NAME: Get the record name from participant_id	(if the record exists yet)
	$record_array = Survey::getRecordFromPartId(array($participant_id));
	if (isset($record_array[$participant_id]))
	{
		// Record name
		$_GET['id'] = $fetched = $_POST[$table_pk] = $record_array[$participant_id];

		// Get response_id
		$sql = "select r.response_id, r.first_submit_time, r.completion_time
				from redcap_surveys_response r, redcap_surveys_participants p
				where p.participant_id = $participant_id and r.record = '".db_escape($fetched)."'
				and p.participant_id = r.participant_id and p.participant_email is not null
				and r.instance = '{$_GET['instance']}' limit 1";
		$q = db_query($sql);
		$response_id = db_result($q, 0, 'response_id');
		if (!is_numeric($response_id)) exit("ERROR: Could not find response_id!");
		// Check if survey response is complete
		$responseCompleted = (db_result($q, 0, 'completion_time') != '');
		$responsePartiallyCompleted = (!$responseCompleted && db_result($q, 0, 'first_submit_time') != '');
		// Set hidden edit
		$hidden_edit = 1;

		// CHECK FAILED LOGIN ATTEMPTS
		if (Survey::surveyLoginFailedAttemptsEnabled())
		{
			// Get window of time to query
			$YminAgo = date("Y-m-d H:i:s", mktime(date("H"),date("i")-$survey_auth_fail_window,date("s"),date("m"),date("d"),date("Y")));
			// Get timestamp of last successful login in our window of time
			$sql = "select ts from redcap_surveys_login where ts >= '$YminAgo' and response_id = $response_id
					and login_success = 1 order by ts desc limit 1";
			$tsLastSuccessfulLogin = db_result(db_query($sql), 0);
			$subsql = ($tsLastSuccessfulLogin == '') ? "" : "and ts > '$tsLastSuccessfulLogin'";
			// Get count of failed logins in window of time
			$sql = "select count(1) from redcap_surveys_login where ts >= '$YminAgo' and response_id = $response_id
					and login_success = 0 $subsql";
			$failedLogins = db_result(db_query($sql), 0);
			// If failed logins in window of time exceeds set limit
			if ($failedLogins >= $survey_auth_fail_limit) {
				// Exceeded max failed login attempts, so don't let user see login form and display "access denied!" message
				Survey::exitSurvey(	RCView::div(array('class'=>'red survey-login-error-msg', 'style'=>'margin:30px 0;'),
							"<b>{$lang['global_05']}</b><br><br>{$lang['survey_607']} (<b>$survey_auth_fail_window
							{$lang['config_functions_72']}</b>){$lang['period']} {$lang['survey_608']}" .
							// Display custom message (if set)
							(trim($survey_auth_custom_message) == '' ? '' :
								RCView::div(array('style'=>'margin:10px 0 0;'),
									nl2br(filter_tags(br2nl(trim($survey_auth_custom_message))))
								)
							)));
			}
		}

		// POST: If record exists and respondent is trying to log in, then validate the login credentials
		if (isset($_POST['survey-auth-submit']) && (!$responseCompleted || ($responseCompleted && $edit_completed_response)))
		{
			// Remove unneeded element from Post
			unset($_POST['survey-auth-submit']);

			// If respondent is logging in, then make sure we convert any date/time fields first
			// Put field names and event_ids of login fields into array for usage downstream
			$data_fields = $data_events = array();
			foreach ($surveyLoginFieldsEvents as $fieldEvent) {
				$data_fields[] = $key = $fieldEvent['field'];
				$data_events[] = $fieldEvent['event_id'];
				// If field is a date/time field, then convert Post value date format if field is a Text field with MDY or DMY date validation.
				if (isset($_POST[$key]) && $Proj->metadata[$key]['element_type'] == 'text'
					&& (substr($Proj->metadata[$key]['element_validation_type'], -4) == "_dmy" || substr($Proj->metadata[$key]['element_validation_type'], -4) == "_mdy"))
				{
					// Convert
					$_POST[$key] = DateTimeRC::datetimeConvert($_POST[$key], substr($Proj->metadata[$key]['element_validation_type'], -3), 'ymd');
				}
			}

			// POST: Process the survey login credentials just submitted
			// Get data for record
			$survey_login_data = Records::getData('array', $fetched, $data_fields, $data_events);
			// Loop through the fields and count the matches with saved data
			$numMatches = 0;
			foreach ($surveyLoginFieldsEvents as $fieldEvent) {
				// Is the submitted value the same as the saved value?
				if (isset($_POST[$fieldEvent['field']]) && strtolower($_POST[$fieldEvent['field']]."") === strtolower($survey_login_data[$fetched][$fieldEvent['event_id']][$fieldEvent['field']]."")) {
					$numMatches++;
				}
			}
			// Do we have enough matches?
			if ($numMatches >= $survey_auth_min_fields) {
				// Successful login!
				// Set post array as empty to clear out login values
				// Add return code so Save & Return processes will catch it and utilize it to allow respondent to return
				$_POST = array('__code'=>Survey::getSurveyReturnCode($fetched, $_GET['page'], $event_id, $_GET['instance']),
							   '__response_id__'=>$response_id);
				// Remove __return in query string to prevent issues
				unset($_GET['__return']);
				// Set flag
				$surveyLoginFailed = false;
				// If save and return is not really enabled, then set a constant for an extra check because we'll be manually changing $save_and_return's value right below
				if ($save_and_return == '0') {
					define('save_and_return_disabled', true);
				}
				// Make sure $save_and_return is set to 1 to allow survey login to function
				$save_and_return = 1;
				// Add cookie to preserve the respondent's login "session" across multiple surveys in a project
				setcookie('survey_login_pid'.$project_id, hash($password_algo, "$project_id|$fetched|$salt"),
						  time()+(Survey::getSurveyLoginAutoLogoutTimer()*60), '/', '', false, true);
				// Add second cookie that expires when the browser is closed (BOTH cookies must exist to auto-login respondent)
				setcookie('survey_login_session_pid'.$project_id, hash($password_algo, "$project_id|$fetched|$salt"), 0, '/', '', false, true);
			} else {
				// Error: Login failed!
				$surveyLoginFailed = true;
				// Destroy cookies
				deletecookie('survey_login_pid'.$project_id);
				deletecookie('survey_login_session_pid'.$project_id);
			}
			// Log the survey login success/fail
			$sql = "insert into redcap_surveys_login (ts, response_id, login_success)
					values ('".NOW."', $response_id, ".($surveyLoginFailed ? '0' : '1').")";
			db_query($sql);
			// If respondent *just* exceeded max failed login attempts, don't let user see login form and display "access denied!" message
			if ($surveyLoginFailed && Survey::surveyLoginFailedAttemptsEnabled() && ($failedLogins+1) >= $survey_auth_fail_limit) {
				Survey::exitSurvey(	RCView::div(array('class'=>'red survey-login-error-msg', 'style'=>'margin:30px 0;'),
							"<b>{$lang['global_05']}</b><br><br>{$lang['survey_607']} (<b>$survey_auth_fail_window
							{$lang['config_functions_72']}</b>){$lang['period']} {$lang['survey_608']}" .
							// Display custom message (if set)
							(trim($survey_auth_custom_message) == '' ? '' :
								RCView::div(array('style'=>'margin:10px 0 0;'),
									nl2br(filter_tags(br2nl(trim($survey_auth_custom_message))))
								)
							)));
			}
		}

		// SURVEY LOGIN AUTO-LOGIN COOKIE: If user previously did login successfully and thus has hashed cookie, verify the cookie's value.
		// If cookie is verified and has not expired, do not force a survey login but do an auto-form-post
		// of the Return Code to create a Post request (to get around a redirect loop)
		if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_COOKIE['survey_login_pid'.$project_id]) && isset($_COOKIE['survey_login_session_pid'.$project_id])
			&& hash($password_algo, "$project_id|$fetched|$salt") == $_COOKIE['survey_login_pid'.$project_id]
			&& $_COOKIE['survey_login_session_pid'.$project_id] == $_COOKIE['survey_login_pid'.$project_id])
		{
			// If this was a non-ajax post request, then preserve the submitted values by building
			// an invisible form that posts itself to same page in the new version.
			if (
				// Do this if not begun survey yet
				(!$responseCompleted && !$responsePartiallyCompleted)
				// Or if returning to a partially completed response
				|| ($save_and_return && $responsePartiallyCompleted)
				// Or if returning to a fully completed response (with Edit Completed Response option enabled)
				|| ($save_and_return && $responseCompleted && $edit_completed_response)
			) {
				?>
				<html><body>
				<form action="<?php echo $_SERVER['REQUEST_URI'] ?>" method="post" name="form" enctype="multipart/form-data">
					<input type="hidden" name="__code" value="<?php echo Survey::getSurveyReturnCode($fetched, $_GET['page'], $event_id, $_GET['instance']) ?>">
					<input type="hidden" name="__response_hash__" value="<?php echo Survey::encryptResponseHash($response_id, $participant_id) ?>">
				</form>
				<script type='text/javascript'>document.form.submit();</script>
				</body></html>
				<?php
				exit;
			}
		}

		// GET: Display submit form to enter survey login credentials
		if (($surveyLoginFailed === true || $_SERVER['REQUEST_METHOD'] == 'GET')
			// If they've not started the survey yet...
			&& ((!$responsePartiallyCompleted && !$responseCompleted)
			// ... or if they are returning to a partial response when Save & Return is enabled...
			|| ($responsePartiallyCompleted && $save_and_return)
			// ... or if they are returning to a completed response when Save & Return is enabled AND "edit completed response" is enabled.
			|| ($responseCompleted && $save_and_return && $edit_completed_response)))
		{
			// Obtain the HTML login form
			$loginFormHtml = Survey::getSurveyLoginForm($fetched, $surveyLoginFailed, $Proj->surveys[$survey_id]['title']);
			if ($loginFormHtml !== false) {
				// Output page with survey login dialog
				$objHtmlPage->addExternalJS(APP_PATH_JS . "Survey.js");
				$objHtmlPage->addExternalJS(APP_PATH_JS . "DataEntrySurveyCommon.js");
				$objHtmlPage->PrintHeader();
				?><style type="text/css">#pagecontainer { display:none; } </style><?php
				print RCView::div(array('style'=>'margin:50px 0;'),
						$loginFormHtml
					  );
				?>
				<script type="text/javascript">
				var survey_auth_min_fields = <?php echo $survey_auth_min_fields ?>;
				var langSurveyLoginForm1 = '<?php echo js_escape($lang['config_functions_45']) ?>';
				var langSurveyLoginForm2 = '<?php echo js_escape($lang['survey_588']) ?>';
				var langSurveyLoginForm3 = '<?php echo js_escape($lang['global_01']) ?>';
				var langSurveyLoginForm4 = '<?php echo js_escape($lang['survey_573']) ?>';
				$(function(){
					displaySurveyLoginDialog();
				});
				</script>
				<?php
				$objHtmlPage->PrintFooter();
				exit;
			}
		}
	}
}


/**
 * RETURNING PARTICIPANT: Participant is "Returning Later" and entering return code
 */
$enteredReturnCodeSuccessfully = false;
// Show page for entering validation code OR validate code and determine response_id from it
if ($save_and_return && !isset($_POST['submit-action']) && (isset($_GET['__return']) || isset($_POST['__code'])))
{
	// If a respondent from the Participant List is returning via Save&Return link to a completed survey,
	// then show the "survey already completed" message.
	if (isset($_GET['__return']) && !$public_survey) {
		// Obtain the record number, if exists
		$partRecArray = Survey::getRecordFromPartId(array($participant_id));
		// Determine if survey was completed
		if (!empty($partRecArray) && !$edit_completed_response && Survey::isResponseCompleted($survey_id, $partRecArray[$participant_id], $event_id, $_GET['instance'])) {
			// Redirect back to regular survey page (without &__return=1 in URL) if Edit Completed Response option is not enabled
			redirect(APP_PATH_SURVEY."index.php?s={$_GET['s']}");
		}
	}

	// Set error message for entering code
	$codeErrorMsg = "";

	// If return code was posted, set as variable for later checking
	if (isset($_POST['__code']))
	{
		$return_code = trim($_POST['__code']);
		unset($_POST['__code']);
	}
	// If we're bypassing the return code
	elseif ($save_and_return_code_bypass == '1' && !$public_survey) {
		$return_code = Survey::getReturnCodeFromHash($hash);
	}

	// CODE WAS SUBMITTED: If we have a return code submitted, validate it
	if (isset($return_code))
	{
		// Default
		$responseExists = false;
		// QUIRK: If we're on the first form/first event, there might be a return code for a unique link response AND the public survey response
		// for the same record. So use some fancy SQL logic to check both and use the valid one (assuming the return code was entered correctly).
		if ($form_name == $Proj->firstForm && $Proj->isFirstEventIdInArm($event_id)) {
			// Is a public survey?
			if ($public_survey) {
				// Set where clause
				$sql_participant_id = "and pub.participant_id = $participant_id";
			} else {
				// Get participant_id of the public survey
				$pub_participant_id = Survey::getParticipantIdFromHash(Survey::getSurveyHash($survey_id, $event_id));
				// Set where clause
				$sql_participant_id = "and pub.participant_id = $pub_participant_id and p.participant_id = $participant_id";
			}
			// Query if code is correct for this survey/participant
			$sql = "select rpub.record, if (rpub.return_code = '" . db_escape($return_code) . "', rpub.response_id, r.response_id) as response_id,
					if (rpub.return_code = '" . db_escape($return_code) . "', rpub.completion_time, r.completion_time) as completion_time,
					if (rpub.return_code = '" . db_escape($return_code) . "', rpub.participant_id, r.participant_id) as participant_id,
					if (rpub.return_code = '" . db_escape($return_code) . "', pub.hash, p.hash) as hash
					from redcap_surveys_participants pub, redcap_surveys_response rpub, redcap_surveys_participants p, redcap_surveys_response r
					where pub.participant_email is null and p.participant_email is not null
					and rpub.first_submit_time is not null and r.record = rpub.record
					and pub.participant_id = rpub.participant_id and p.participant_id = r.participant_id
					and pub.survey_id = p.survey_id and pub.event_id = p.event_id
					$sql_participant_id and p.event_id = $event_id and p.survey_id = $survey_id
					and (r.return_code = '" . db_escape($return_code) . "' or rpub.return_code = '" . db_escape($return_code) . "')
					limit 1";
			$q = db_query($sql);
			$responseExists = (db_num_rows($q) > 0);
			// Reset participant_id and hash for this response_id
			if ($responseExists) {
				$participant_id = db_result($q, 0, "participant_id");
				$hash_original = $_GET['s'];
				$_GET['s'] = $hash = db_result($q, 0, "hash");
			}
		}
		// Set hidden edit
		if ($responseExists) $hidden_edit = 1;
		// If the query above failed or if it wasn't used, check code this way
		if (!$responseExists) {
			// Query if code is correct for this survey/participant
			$sql = "select record, response_id, completion_time from redcap_surveys_response
					where return_code = '" . db_escape($return_code) . "' and participant_id = $participant_id limit 1";
			$q = db_query($sql);
			$responseExists = (db_num_rows($q) > 0);
		}
		if (!$responseExists) {
			// Code is not valid, so set error msg
			$codeErrorMsg = RCView::b($lang['survey_161']);
			// If the code entered is the same length as a Survey Access code, then let the user know that this is different from a SAC.
			if (strlen($return_code) == Survey::ACCESS_CODE_LENGTH || strlen($return_code) == Survey::SHORT_CODE_LENGTH) {
				$codeErrorMsg .= RCView::div(array('style'=>'margin-top:10px;'), $lang['survey_663']);
			}
			// Unset return_code so that user will be prompted to enter it again
			unset($return_code);
		} elseif (db_result($q, 0, "completion_time") != "" && !$edit_completed_response) {
			// This survey response has already been completed (nothing to do) - assumming that Edit Completed Response option is not enabled
            Survey::exitSurvey($lang['survey_111'], true, null, false);
		} else {
			// Code is valid, so set response_id and record name
			$_POST['__response_id__'] = db_result($q, 0, "response_id");
			// Set response_hash
			$_POST['__response_hash__'] = Survey::encryptResponseHash($_POST['__response_id__'], $participant_id);
			// Record exists AND is a non-public survey, so set record name for this page for pre-filling fields
			$_GET['id'] = $_POST[$table_pk] = $fetched = db_result($q, 0, "record");
			// Set flag
			$enteredReturnCodeSuccessfully = true;
		}
	}

	// PROMPT FOR CODE: Code has not been entered yet or was entered incorrectly
	if (!isset($return_code))
	{
		// Header and title
		$objHtmlPage->PrintHeader();
		print  "<div style='padding:0 10px 20px;'>$title_logo<br>";
		// Show error msg if entered incorrectly
		if (!empty($codeErrorMsg)) {
			print  "<div class='red'><img src='".APP_PATH_IMAGES."exclamation.png'>
					$codeErrorMsg
					</div><br>";
		}
		print  "<p id='return_code_form_instructions' style='margin-bottom:20px;font-size:14px;'>{$lang['survey_661']} {$lang['survey_641']}</p>
				<form id='return_code_form' action='".PAGE_FULL."?s=$hash' method='post' enctype='multipart/form-data'>
					<input type='password' maxlength='15' size='8' class='x-form-text x-form-field' name='__code' style='padding: 4px 6px;font-size:16px;'> &nbsp;
					<button class='jqbutton' onclick=\"$('#return_code_form').submit();\">{$lang['survey_662']}</button>
				</form>
				<script type='text/javascript'>
				$(function(){
					$('input[name=\"__code\"]').focus();
				});
				</script>";
		// START OVER: For emailed one-time surveys, allow them to erase all previous answers and start over
		if (!$public_survey)
		{
			// First get response_id so we can put response_hash in the form
			$sql = "select r.response_id, r.record from redcap_surveys_response r, redcap_surveys_participants p
					where p.participant_id = $participant_id and p.participant_id = r.participant_id
					and p.participant_email is not null limit 1";
			$q = db_query($sql);
			if (db_num_rows($q))
			{
				// response_id
				$rowr = db_fetch_assoc($q);
				$_POST['__response_id__'] = $rowr['response_id'];
				## RECORD-LEVEL LOCKING: Check if record has been locked at record level
				$lockingWhole = new Locking();
				$lockingWhole->findLockedWholeRecord($project_id, $rowr['record'], getArm());
                ## LOCKING: Check if form has been locked for this record before allowing them to start over
                $sql = "select l.username, l.timestamp, u.user_firstname, u.user_lastname from redcap_locking_data l
                        left outer join redcap_user_information u on l.username = u.username
                        where l.project_id = $project_id and l.record = '" . db_escape($rowr['record']) . "'
                        and l.event_id = {$_GET['event_id']} and l.form_name = '{$_GET['page']}' and l.instance = '{$_GET['instance']}' limit 1";
                if (isset($lockingWhole->lockedWhole[$rowr['record']]) || db_num_rows(db_query($sql))) {
                    // Lock the screen
                    print 	RCView::div(array('class'=>'yellow', 'style'=>'max-width:97%;'),
                            RCView::img(array( 'src'=>'exclamation_orange.png')) .
                            $lang['survey_674']
                        ) .
                        "<style type='text/css'>#return_code_form_instructions, #return_code_form { display:none; }</style>";
                } else {
                    // Output Start Over button and text
                    print  "<div id='start_over_form'>
                            <p style='font-size:14px;border-top:1px solid #aaa;padding-top:20px;margin:30px 0 15px;'>
                                {$lang['survey_110']}
                            </p>
                            <form action='".PAGE_FULL."?s=$hash&__startover=1' method='post' enctype='multipart/form-data'>
                                <input class='jqbutton' type='submit' value=' ".js_escape($lang['control_center_422'])." ' style='padding: 3px 5px !important;' onclick=\"return confirm('".js_escape($lang['survey_982'])."');\">
                                <input type='hidden' name='__response_hash__' value='".Survey::encryptResponseHash($_POST['__response_id__'], $participant_id)."'>
                            </form>
                        </div>";
                }
			}
		}
		print "</div>";
		print "<style type='text/css'>#container{border: 1px solid #ccc;}</style>";
		$objHtmlPage->PrintFooter();
		exit;
	}
}



/**
 * VIEW GRAPHICAL RESULTS & STATS
 * Display results to participant if they have completed the survey
 */
if ($enable_plotting_survey_results && $view_results && isset($_GET['__results']))
{
	include APP_PATH_DOCROOT . "Surveys/view_results.php";
}




/**
 * GET THE RECORD NAME (i.e. $fetched)
 */
// GET METHOD
if ($_SERVER['REQUEST_METHOD'] == 'GET' && $save_and_return_code_bypass != '1')
{
	// FIRST PAGE OF A SURVEY (i.e. request method = GET)
	if ($public_survey || $participant_email === null) {
		$response_exists = false;
	} else {
		// Check if responses exist already for this participant AND is non-public survey
		$sql = "select r.response_id, r.record, r.first_submit_time, r.completion_time, r.return_code
				from redcap_surveys_response r, redcap_surveys_participants p
				where p.participant_id = $participant_id and p.participant_id = r.participant_id
				and p.participant_email is not null
				order by r.return_code desc, r.completion_time desc, r.response_id limit 1";
		$q = db_query($sql);
		$response_exists = (db_num_rows($q) > 0);
	}
	// Determine if survey was completed fully or partially (if so, then stop here)
	$first_submit_time  = ($response_exists ? db_result($q, 0, "first_submit_time") : "");
	$completion_time    = ($response_exists ? db_result($q, 0, "completion_time")   : "");
	$return_code 	    = ($response_exists ? db_result($q, 0, "return_code")       : "");
	$this_record 		= ($response_exists ? db_result($q, 0, "record")			: "");
	$this_response_id	= ($response_exists ? db_result($q, 0, "response_id")		: "");
	// Existing record on NON-public survey
	if ($response_exists)
	{
		// Set hidden edit
		$hidden_edit = 1;
		// Determine if this non-public survey response is partially completed and also if it's a follow-up survey (i.e., non-first instrument survey)
		$partiallyCompleted = ($completion_time == "");
		$fullyCompleted = ($completion_time != "");
		$isNonPublicFollowupSurvey = ($first_submit_time == "");
		// Save and Return: If this is a non-public survey link BUT the response was originally created via Public Survey,
		// then use the submission times of the public survey response for this record/survey/event (ONLY FOR NON-FOLLOW-UP SURVEYS)
		if ($save_and_return && $form_name == $Proj->firstForm && $Proj->isFirstEventIdInArm($event_id))
		{
			$sql = "select r.first_submit_time, r.completion_time from redcap_surveys_participants p, redcap_surveys_response r
					where p.survey_id = $survey_id and p.participant_id = r.participant_id
					and r.record = '".db_escape($this_record)."' and p.event_id = $event_id
					and r.instance = '{$_GET['instance']}' order by r.first_submit_time desc, p.participant_email desc limit 1";
			$q2 = db_query($sql);
			if (db_num_rows($q2) > 0) {
				// Get return code that already exists in table
				$first_submit_time = db_result($q2, 0, 'first_submit_time');
				$completion_time = db_result($q2, 0, 'completion_time');
				$partiallyCompleted = ($completion_time == "");
				$fullyCompleted = ($completion_time != "");
				$isNonPublicFollowupSurvey = ($first_submit_time == "");
			}
		}
		// Create return code if not generated yet
		if ($save_and_return && $return_code == "") {
			$return_code = Survey::getSurveyReturnCode($this_record, $_GET['page'], $_GET['event_id'], $_GET['instance']);
		}
		// Survey is for a non-first form for an existing record (i.e. followup survey), which has no first_submit_time
		if ($isNonPublicFollowupSurvey)
		{
			// Set response_id
			$_POST['__response_id__'] = $this_response_id;
			// Set record name
			$_GET['id'] = $fetched = $this_record;
		}
		// Save & Return was used, so redirect them to enter their return code
		elseif ($save_and_return && $return_code != "" && ($partiallyCompleted || ($fullyCompleted && $edit_completed_response)))
		{
			// Redirect to Return Code page so they can enter their return code
			redirect(PAGE_FULL . "?s=$hash&__return=1");
		}
		// Whether using Save&Return or not, give participant option to start over if only partially completed
		elseif ($partiallyCompleted)
		{
			// Set response_id
			$_POST['__response_id__'] = $this_response_id;
			// If form is locked, then prevent participant from starting over
			$Locking = new Locking();
			$Locking->findLocked($Proj, $this_record, array(), $_GET['event_id']);
			$formIsLocked = isset($Locking->locked[$this_record][$_GET['event_id']][$_GET['instance']][$_GET['page']."_complete"]);
			// Give participant the option to delete their responses and start over
			$objHtmlPage->PrintHeader();
			print  "$title_logo
					<div style='margin:20px 10px;'>";
			if ($formIsLocked) {
				print  "<h4 style='font-weight:bold;'>{$lang['survey_1156']}</h4><p>{$lang['survey_1155']}</p>";			
			} else {
				print  "<h4 style='font-weight:bold;'>{$lang['survey_163']}</h4><p>{$lang['survey_162']}</p>
						<form action='".PAGE_FULL."?s=$hash&__startover=1' method='post' enctype='multipart/form-data'>
							<input class='jqbutton' type='submit' value=' ".js_escape($lang['control_center_422'])." ' style='padding: 3px 5px !important;' onclick=\"return confirm('".js_escape($lang['survey_982'])."');\">
							<input type='hidden' name='__response_hash__' value='".Survey::encryptResponseHash($_POST['__response_id__'], $participant_id)."'>
						</form>
						</div>";
			}
			print "<style type='text/css'>#container{border: 1px solid #ccc;}</style>";
			$objHtmlPage->PrintFooter();
			exit;
		}
		// else
		elseif (!isset($_GET['__endsurvey']))
		{
			// Participant is not allowed to complete the survey because it has been completed
			$exitText = $lang['survey_111'];
			// AutoContinue - Addition to enable redirect to next in aborted chain
			if ($end_survey_redirect_next_survey)
			{
				// Get the next survey url
				$next_survey_url = Survey::getAutoContinueSurveyUrl($this_record, $form_name, $event_id, $_GET['instance']);
				if ($next_survey_url) {
					redirect($next_survey_url);
				}
			}
			// SURVEY QUEUE LINK (if not a public survey and only if record already exists)
			if (Survey::surveyQueueEnabled())
			{
				// Set record name
				$_GET['id'] = $fetched = $this_record;
				// Display Survey Queue, if applicable
				$survey_queue_html = Survey::displaySurveyQueueForRecord($_GET['id'], true);
				if ($survey_queue_html != '') {
					$exitText .= RCView::div(array('style'=>'margin:50px 0 10px -24px;'), $survey_queue_html);
				}
			}
			Survey::exitSurvey($exitText, true, null, false);
		}
	}
	// Either a public survey OR non-public survey when record does not exist
	else
	{
		// Is this a non-existing record on a public survey?
		$autoIdNonExistingRecordPublicSurvey = ($_SERVER['REQUEST_METHOD'] == 'GET' && $public_survey && !$hidden_edit);
		if ($autoIdNonExistingRecordPublicSurvey) {
			// Set as arbitray tentative record
			$_GET['id'] = $fetched = "1";
			// Build record list cache if not yet built for this project
			Records::buildRecordListCacheCurl(PROJECT_ID);
        } else {
			// Set current record as auto-numbered value
			$_GET['id'] = $fetched = DataEntry::getAutoId();
        }
	}
}
// POST METHOD
if ($save_and_return_code_bypass == '1' || isset($_POST['submit-action']) || isset($_POST['__prefill']))
{
	if ($save_and_return_code_bypass == '1' && isset($_GET['id'])) {
		$_POST[$table_pk] = $fetched = $_GET['id'];
	}
	// Set flag to retrieve record name via response_id or via auto-numbering
	$getRecordNameFlag = true;
	// TWO-TAB CHECK FOR EXISTING RECORD: For participant list participant, make sure they're not taking survey in 2 windows simultaneously.
	// If record exists before we even save responses from page 1, then we know the survey was started in another tab,
	// so set the response_id so that this second tab instance doesn't create a duplicate record.
	if (!$public_survey)
	{
        // Get participant_id of the this private link (in case we've somehow ended up with a public survey's participant_id,
        // which can happen when returning via Save&Return's Continue button)
        if (isset($hash_original) && $_GET['s'] != $hash_original) {
            $participant_id = Survey::getParticipantIdFromHash($hash_original);
        }
		// Get record name (if is existing record)
		$partIdRecArray = Survey::getRecordFromPartId(array($participant_id));
		if (isset($partIdRecArray[$participant_id]))
		{
			// Set flag to false so we don't run redundant queries below
			$getRecordNameFlag = false;
			// Set record name since it alreay exists in the table
			$_GET['id'] = $fetched = $_POST[$table_pk] = $partIdRecArray[$participant_id];
			// Set hidden edit
			$hidden_edit = 1;
			// Record exists, so use record name to get response_id and check if survey is completed
			$sql = "select response_id, completion_time from redcap_surveys_response
					where record = '" . db_escape($fetched) . "' and participant_id = $participant_id limit 1";
			$q = db_query($sql);
			if (db_num_rows($q)) {
				// Set response_id
				$_POST['__response_id__'] = db_result($q, 0, 'response_id');
				// If the completion_time is not null (i.e. the survey was completed), then stop here (if dont' have the Edit Completed Response enabled)
				$completion_time_existing_record = db_result($q, 0, 'completion_time');
				if ($completion_time_existing_record != "" && !$edit_completed_response) {
					// This survey response has already been completed (nothing to do)
					Survey::exitSurvey($lang['survey_111'], true, null, isset($_POST['submit-action']));
				}
			}
		}
	}

	// RECORD EXISTS ALREADY and we have response_id, so use response_id to obtain the current record name
	if ($getRecordNameFlag)
	{
		if (isset($_POST['__response_id__']))
		{
			// Use response_id to get record name
			$sql = "select record, completion_time from redcap_surveys_response where response_id = {$_POST['__response_id__']}
					and participant_id = $participant_id limit 1";
			$q = db_query($sql);
			// Set record name since it alreay exists in the table
			$_GET['id'] = $fetched = $_POST[$table_pk] = db_result($q, 0, 'record');
			// Set hidden edit
			$hidden_edit = 1;
			// If the completion_time is not null (i.e. the survey was completed), then stop here (if dont' have the Edit Completed Response enabled)
			$completion_time_existing_record = db_result($q, 0, 'completion_time');
			if ($completion_time_existing_record != "" && !$edit_completed_response) {
				// This survey response has already been completed (nothing to do)
				Survey::exitSurvey($lang['survey_111']);
			}
		}
		// RECORD DOES NOT YET EXIST: Get record using auto id since doesn't exist yet
		else
		{
			// Is this a non-existing record on a public survey?
			$autoIdNonExistingRecordPublicSurvey = ($_SERVER['REQUEST_METHOD'] == 'GET' && $public_survey && !$hidden_edit);
			if ($autoIdNonExistingRecordPublicSurvey) {
				// Set as arbitray tentative record
				$_GET['id'] = $fetched = $_POST[$table_pk] = "1";
                // Build record list cache if not yet built for this project
				Records::buildRecordListCacheCurl(PROJECT_ID);
			} else {
				// Set current record as auto-numbered value
				$_GET['id'] = $fetched = $_POST[$table_pk] = DataEntry::getAutoId();
			}
		}
	}
}


// Check for Required fields that weren't entered (checkboxes are ignored - cannot be Required)
if (!isset($_GET['__prevpage']) && !isset($_GET['__endsurvey']))
{
	$_GET['id'] = $_POST[$Proj->table_pk] = $fetched = DataEntry::checkReqFields($fetched, true);
}

// e-Consent Framework: Erase all signature field values before loading the survey page
Survey::eraseEconsentSignatures(PROJECT_ID, $_GET['id'], $_GET['page'], $_GET['event_id'], $_GET['instance']);

// Determine the current page number and set as a query string variable, and return label for Save button
list ($saveBtnText, $hideFields, $isLastPage) = Survey::setPageNum($pageFields, $totalPages);

// Create array of fields to be auto-numbered (same as $pageFields, but exclude Descriptive fields)
if ($question_auto_numbering)
{
	$autoNumFields = array();
	$this_qnum = 1;
	foreach ($pageFields as $this_page=>$these_fields) {
		foreach ($these_fields as $this_field) {
			// Ignore descriptive fields, which don't receive a question number
			if ($Proj->metadata[$this_field]['element_type'] != 'descriptive'
				// Ignore fields hidden with @HIDDEN or @HIDDEN-SURVEY action tag
				&& !Form::hasHiddenOrHiddenSurveyActionTag($Proj->metadata[$this_field]['misc']))
			{
				$autoNumFields[$this_page][$this_qnum++] = $this_field;
			}
		}
	}
}




/**
 * SAVE RESPONSES: Do not save data while in Preview mode
 */
if (isset($_POST['submit-action']))
{
	// Parameters for determining if survey has ended and if nothing is left to be done
	$returningToSurvey = isset($_GET['__return']);
	$reqFieldsLeft = isset($_GET['__reqmsg']);
	$surveyEnded = (isset($_GET['__endsurvey']) || ($_GET['__page__'] > $totalPages) 
					|| (!$question_by_section && $pdf_auto_archive < 2) || $totalPages == 1);
	
	// Perform server-side validation
	if (!isset($_GET['__reqmsg']))
	{
		// Perform server-side validation
		Form::serverSideValidation($_POST);
		// If server-side validation was violated, then add to redirect URL
		if (isset($_SESSION['serverSideValErrors'])) {
			// Build query string parameter
			$_GET['serverside_error_fields'] = implode(",", array_keys($_SESSION['serverSideValErrors']));
			// Remove from session
			unset($_SESSION['serverSideValErrors']);
			// Reset various values that are already set
			$surveyEnded = false;
			// Re-run Survey::setPageNum() so that things get reset in order to reload the page again
			list ($saveBtnText, $hideFields, $isLastPage) = Survey::setPageNum($pageFields, $totalPages);
		}
		// MAXCHOICE ACTION TAG CATCHING
		// Check if MAXCHOICE action tag is used and if exceeded the value just submitted
		Form::hasReachedMaxChoiceInPostFields($_POST, $fetched, $_GET['event_id']);
		// If server-side validation was violated, then add to redirect URL
		if (isset($_GET['maxChoiceFieldsReached'])) {
			// Build query string parameter
			$_GET['maxchoice_error_fields'] = implode(",", $_GET['maxChoiceFieldsReached']);
			// Remove from session
			unset($_GET['maxChoiceFieldsReached']);
			// Reset various values that are already set
			$surveyEnded = false;
			// Re-run Survey::setPageNum() so that things get reset in order to reload the page again
			list ($saveBtnText, $hideFields, $isLastPage) = Survey::setPageNum($pageFields, $totalPages);
		}
	}

	// Has survey now been compeleted?
	$survey_completed = ($surveyEnded && !$reqFieldsLeft && !$returningToSurvey);

	// END OF SURVEY
	if ($survey_completed)
	{
		// Set survey completion time as now
		$completion_time = "'".NOW."'";
		// Form Status = Complete
		$_POST[$_GET['page'].'_complete'] = '2';
	}
	// NOT END OF SURVEY (PARTIALLY COMPLETED)
	else
	{
		// If the Edit Completed Response option is enabled, then make sure we don't overwrite the original completion_time
		if ($edit_completed_response && isset($_POST['__response_id__'])) {
			// Get existing completion_time value
			$responseStatuses = Survey::getResponseStatus(PROJECT_ID, $_GET['id'], $_GET['event_id'], true);			
			$completion_time = isset($responseStatuses[$_GET['id']][$_GET['event_id']][$_GET['page']][$_GET['instance']]) ? $responseStatuses[$_GET['id']][$_GET['event_id']][$_GET['page']][$_GET['instance']] : '';
			if ($completion_time == '' || $completion_time == "NULL") {
				// Still just partial
				$completion_time = "null";
				$_POST[$_GET['page'].'_complete'] = '0';
			} else {
				// Completed
				$_POST[$_GET['page'].'_complete'] = '2';
			}
		} else {
			// Set survey completion time as null
			$completion_time = "null";
			// Form Status = Incomplete
			$_POST[$_GET['page'].'_complete'] = '0';
		}
	}

	// INSERT/UPDATE RESPONSE TABLE
	if (isset($_POST['__response_id__'])) {
		// Confirm that the response exists using response_id
		$sql  = "select response_id from redcap_surveys_response where participant_id = '" . db_escape($participant_id) . "'
				and response_id = {$_POST['__response_id__']}";
		$q = db_query($sql);
	} elseif (!$public_survey) {
		// Obtain response using the record name for non-public survey if we don't have the response_id
		$sql  = "select response_id from redcap_surveys_response where participant_id = '" . db_escape($participant_id) . "'
				and record = '" . db_escape($fetched) . "' limit 1";
		$q = db_query($sql);
	} else {
		// Set false for an uncreated record on public surveys so that it will know to generate a new record name
		$q = false;
	}
	## RESPONSE EXISTS
	if ($q && db_num_rows($q) > 0) {
		// Set response_id if we don't have it yet
		$_POST['__response_id__'] = db_result($q, 0);
		// UPDATE existing response
		$sql = "update redcap_surveys_response set completion_time = $completion_time
				where response_id = {$_POST['__response_id__']}";
		db_query($sql);
		// Set hidden edit
		$hidden_edit = 1;
	}
	## RESPONSE DOES NOT EXIST YET (will need to dynamically obtain new record name)
	elseif ($fetched != '') {
		// If survey has Save & Return Later enabled, then generate a return code (regardless of it they clicked the Save&Return button)
		$return_code = ($save_and_return) ? Survey::getUniqueReturnCode($survey_id) : "";
		// Get true new record name (puts record name in cache table to ensure it hasn't already been used)
		if (!isset($GLOBALS['__addNewRecordToCache'])) {
			$_GET['id'] = $fetched = $_POST[$table_pk] = Records::addNewAutoIdRecordToCache(PROJECT_ID, $fetched);
        }
		// Insert into responses table
		$sql = "insert into redcap_surveys_response (participant_id, record, first_submit_time, completion_time, return_code, instance) values
				(" . checkNull($participant_id) . ", " . checkNull($fetched) . ", '".NOW."', $completion_time, " . checkNull($return_code) . ", {$_GET['instance']})";
		if (db_query($sql)) {
			// Set response_id
			$_POST['__response_id__'] = db_insert_id();
		}
	}

	// FOLLOWUP SURVEYS, which begin with first_submit_time=NULL, set first_submit_time as NOW (or completion_time, if just completed)
	if (isset($_POST['__response_id__']))
	{
		// Set first_submit_time in response table
		$sql = "update redcap_surveys_response set first_submit_time = if(completion_time is null, '".NOW."', completion_time)
				where response_id = {$_POST['__response_id__']} and first_submit_time is null";
		$q = db_query($sql);
		// Set hidden edit
		$hidden_edit = 1;
	}

	// Save the submitted data (if a required field was triggered, then we've already saved it once, so don't do it twice)
	if (!isset($_GET['__reqmsg']))
	{
		// Save record/response
		list ($fetched, $context_msg, $log_event_id, $dataValuesModified, $dataValuesModifiedIncludingCalcs) = DataEntry::saveRecord($fetched, true, false, false, $_POST['__response_id__'], true, $survey_completed);
		// Set hidden edit
		$hidden_edit = 1;
	}

	// If survey is officially completed, then send an email to survey admins AND send confirmation email to respondent, if enabled.
	if ($survey_completed)
	{
		Survey::sendSurveyConfirmationEmail($survey_id, $_GET['event_id'], $fetched);
		Survey::sendEndSurveyEmails($survey_id, $_GET['event_id'], $participant_id, $fetched, $_GET['instance']);
	}

	/**
	 * SAVE & RETURN LATER button was clicked at bottom of survey page
	 */
	// If user clicked "Save & Return Later", then provide validation code for returning
	if ($save_and_return && isset($_GET['__return']))
	{
		// Check if return code exists already
		$sql = "select return_code from redcap_surveys_response where return_code is not null
				and response_id = {$_POST['__response_id__']} limit 1";
		$q = db_query($sql);
		if (db_num_rows($q) > 0) {
			// Get return code that already exists in table
			$return_code = strtoupper(db_result($q, 0));
		} else {
			// Create a return code for the participant since one does not exist yet
			$return_code = Survey::getUniqueReturnCode($survey_id);
			// Add return code to response table (but only if it does not exist yet)
			$sql = "update redcap_surveys_response set completion_time = null, return_code = '$return_code'
					where response_id = ".$_POST['__response_id__'];
			db_query($sql);
		}
		// Set the URL of the page called via AJAX to send the participant's email to themself
		$return_email_page = APP_PATH_SURVEY . "index.php?pid=$project_id&__passthru=".urlencode("Surveys/email_participant_return_code.php");
		// Instructions for returning
		$objHtmlPage->PrintHeader();
		// Set flag
		$showSurveyLoginText = (!$public_survey && $survey_auth_enabled && ($survey_auth_apply_all_surveys || $survey_auth_enabled_single));
		// Return link
		if ($public_survey) {
			$returnLink = REDCap::getSurveyLink($_GET['id'], $_GET['page'], $event_id, $_GET['instance']);			
			parse_str(parse_url($returnLink, PHP_URL_QUERY), $urlParts);
			$hashReturn = $urlParts['s'];
            $return_code = REDCap::getSurveyReturnCode($_GET['id'], $_GET['page'], $event_id, $_GET['instance']);
		} else {
			$returnLink = PAGE_FULL . "?s=$hash";
			$hashReturn = $hash;
		}
		?>
		<br><br>
		<div id="return_instructions" style="margin:10px 0 30px 0;">
			<h4><b><?php echo $lang['survey_112'] ?></b></h4>
		<?php if ($showSurveyLoginText || $save_and_return_code_bypass == '1') { ?>
			<?php echo RCView::div(array('style'=>'margin-bottom:15px;'), $lang['survey_581']) ?>
		<?php } else { ?>
			<div>
				<?php echo $lang['survey_113'] ?> <i style="color:#800000;"><?php echo $lang['survey_114'] ?></i> <?php echo $lang['survey_115'] ?>
				<i style="color:#800000;"><?php echo $lang['survey_116'] ?></i><?php echo $lang['period'] ?> <?php echo $lang['survey_117'] ?><br>
				<div style="padding:20px 20px;margin-left:2em;text-indent:-2em;">
					<b>1.) <u><?php echo $lang['survey_118'] ?></u></b><br>
                    <label id="return-step1" style="text-indent: 0;"><?php echo $lang['survey_119'] ?></label><br>
					<?php echo $lang['survey_118'] ?>&nbsp;
					<?php echo RCView::span(array('style'=>'display:none;'), $return_code) ?>
					<input readonly class="staticInput" style="margin:5px;letter-spacing:1px;margin-left:10px;color:#111;font-size:16px;width:140px;"
					onclick="this.select();" value="<?php echo $return_code ?>" aria-labelledby="return-step1"><br>
					<span style="color:#800000;font-size:10px;font-family:tahoma;">
						* <?php echo $lang['survey_120'] ?>
					</span>
				</div>
		<?php } ?>
				<div style="<?php if ($save_and_return_code_bypass != '1') print "padding:5px 20px;margin-left:2em;text-indent:-2em;"; ?>">
						<b><?php if (!($showSurveyLoginText || $save_and_return_code_bypass == '1')) { ?>2.)<?php } ?> <u><?php echo $lang['survey_121'] ?></u></b><br>
						<span id="provideEmail" style="<?php echo (!$public_survey ? "display:none;" : "") ?>">
                            <label id="return-step2" style="text-indent: 0;">
							    <?php echo (($showSurveyLoginText || $save_and_return_code_bypass == '1') ? $lang['survey_583'] : $lang['survey_123']) ?>
                            </label>
                            <br><br>
							<input type="text" id="email" class="x-form-text x-form-field " style="color:#777;width:180px;" aria-labelledby="return-step2"
								value="<?php echo js_escape2($lang['survey_515']) ?>"
								onblur="if(this.value==''){this.value='<?php echo js_escape($lang['survey_515']) ?>';this.style.color='#777777';} if(this.value != '<?php echo js_escape($lang['survey_515']) ?>'){redcap_validate(this,'','','soft_typed','email')}"
								onfocus="if(this.value=='<?php echo js_escape($lang['survey_515']) ?>'){this.value='';this.style.color='#000000';}"
								onclick="if(this.value=='<?php echo js_escape($lang['survey_515']) ?>'){this.value='';this.style.color='#000000';}"
							>
							<button id="sendLinkBtn" class="jqbuttonmed" style="text-indent:0;" onclick="
								if (document.getElementById('email').value == '<?php echo js_escape($lang['survey_515']) ?>') {
									simpleDialog('<?php echo js_escape($lang['survey_515']) ?>',null,null,null,'document.getElementById(\'email\').focus();');
								} else if (redcap_validate(document.getElementById('email'), '', '', '', 'email')) {
									emailReturning(<?php echo "$survey_id, $event_id, $participant_id, '$hashReturn'" ?>, $('#email').val(), '<?php echo $return_email_page ?>', '<?php echo js_escape($lang['survey_1287']) ?>', '<?php echo js_escape($lang['survey_1288']) ?>');
								}
							"><?php echo $lang['survey_124'] ?></button>
							<span id="progress_email" style="visibility:hidden;">
								<img src="<?php echo APP_PATH_IMAGES ?>progress_circle.gif" alt="<?=js_escape2($lang['data_entry_64'])?>">
							</span><br>
							<span style="font-size:10px;color:#800000;font-family:tahoma;">* <?php echo $lang['survey_125'] ?></span>
						</span>
						<span id="autoEmail" style="<?php echo ($public_survey ? "display:none;" : "") ?>">
							<?php echo ($showSurveyLoginText || $save_and_return_code_bypass) ? $lang['survey_582'] : $lang['survey_122'];  ?>
						</span>
						<?php if (!$public_survey) { ?>
						<script type="text/javascript">
						emailReturning(<?php echo "$survey_id, $event_id, $participant_id, '$hashReturn'" ?>, '', '<?php echo $return_email_page ?>', '<?php echo js_escape($lang['survey_1287']) ?>', '<?php echo js_escape($lang['survey_1288']) ?>');
						</script>
					<?php } ?>
				</div>
				<div style="border-top:1px solid #aaa;padding:10px;margin-top:40px;">
					<form id="return_continue_form" action="<?php echo $returnLink ?>" method="post" enctype="multipart/form-data">
					<b><?php echo $lang['survey_126'] ?></b>
					<input type="hidden" maxlength="8" size="8" name="__code" value="<?php echo $return_code ?>">
					<div style="padding-top:10px;"><button class="jqbutton" onclick="$('#return_continue_form').submit();"><?php echo $lang['survey_127'] ?></button></div>
					</form>
				</div>
			</div>
		</div>
		<?php if (!$showSurveyLoginText && $save_and_return_code_bypass != '1') {
			?>
			<div id="codePopupReminder" class="simpleDialog" style="font-size:14px;" title="<?php echo js_escape2($lang['survey_658']) ?>">
				<span id="codePopupReminderText">
					<?php echo $lang['survey_659'] ?>
				</span><br><br>
				<span id="codePopupReminderTextCode">
					<b><?php echo $lang['survey_657'] ?></b>&nbsp;
					<?php echo RCView::span(array('style'=>'display:none;'), $return_code) ?>
					<input readonly class="staticInput" style="letter-spacing:1px;margin-left:10px;color:#111;font-size:16px;width:140px;"
						onclick="this.select();" value="<?php echo $return_code ?>">
				</span>
			</div>
			<script type="text/javascript">
			// Give dialog on page load to make sure participant writes it down
			$(function(){
				$('#codePopupReminder').dialog({ bgiframe: true, modal: true, width: (isMobileDevice ? $(window).width() : 450), buttons: {
					'<?php echo js_escape($lang['calendar_popup_01']) ?>': function() { $(this).dialog('close'); }
				}});
			});
			</script>
			<?php
		} elseif ($public_survey && $save_and_return_code_bypass == '1') {
			// Set the browser's web address to the private link
			?>
			<script type="text/javascript">
			modifyURL('<?php echo $returnLink ?>');
			</script>
			<?php
		}
		$objHtmlPage->PrintFooter();
		exit;
	}
}



// SKIP PAGE? Determine if ALL questions will be hidden by branching logic based upon existing data.
if ($question_by_section && !$isPromisInstrument)
{
	// Set a maximum for how many pages we can skip (to prevent possible infinite looping)
	$maxPageSkipLoops = 5000;
	$numPageSkipLoops = 1;
	do {
		// Determine if all fields are hidden for this page (also considers @HIDDEN and @HIDDEN-SURVEY)
		$allFieldsHidden = BranchingLogic::allFieldsHidden($fetched, $_GET['event_id'], $_GET['page'], $_GET['instance'], $pageFields[$_GET['__page__']]);
		// Save data if there are any calc fields on this page so that they don't get missed when we skip the page
        if ($allFieldsHidden) {
            // Are any fields a calc?
            $pageHasCalcs = false;
            foreach ($pageFields[$_GET['__page__']] as $this_field) {
                if ($Proj->metadata[$this_field]['element_type'] == 'calc') {
                    $pageHasCalcs = true;
                    break;
                }
            }
            if ($pageHasCalcs) {
                // If we have calc fields, do a save in case they need to be triggered
                list ($fetched, $context_msg, $log_event_id, $dataValuesModified, $dataValuesModifiedIncludingCalcs) = DataEntry::saveRecord($fetched, true, true, true);
                // After the save, re-run the test to see if all fields are still hidden on the page
                $allFieldsHidden = BranchingLogic::allFieldsHidden($fetched, $_GET['event_id'], $_GET['page'], $_GET['instance'], $pageFields[$_GET['__page__']]);
            }
        }

		// If ALL fields on survey page are hidden, then increment $_POST['__page__'] and then reset the page number
		if ($allFieldsHidden) {
			if ($_GET['__page__'] < $totalPages) {
				// Increment page from Post if going to Next page (else decrement if going to Previous page)
				if (isset($_GET['__prevpage'])) {
					$_POST['__page__']--;
				} else {
					$_POST['__page__']++;
				}
				// Get new page number and other settings
				list ($saveBtnText, $hideFields, $isLastPage) = Survey::setPageNum($pageFields, $totalPages, true);
				// print " - Now going to page ".$_GET['__page__'];
				// Set array of auto numbered question numbers to empty (they shouldn't display anyway since we're using branching - but just in case)
				$autoNumFields = array();
				// If we're on the first page still, then stop looping
                if ($_GET['__page__'] == '1') $allFieldsHidden = false;
			} else {
				// If we're on the past page, then display it (even though
				$allFieldsHidden = false;
			}
		}
		// Increment loop counter
		$numPageSkipLoops++;
	}
	while ($allFieldsHidden && $numPageSkipLoops < $maxPageSkipLoops);
}



// ACKNOWLEDGEMENT OR SURVEY REDIRECT: If just finished the last page, then end survey and show acknowledgement
if (((isset($_POST['submit-action']) || $isPromisInstrument) && ($_GET['__page__'] > $totalPages || isset($_GET['__endsurvey'])))
    || ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['__endpublicsurvey']) && isset($_GET['__rh'])))
{
    // If we just redirected after completing a public survey, then fetch the record name
    if (isset($_GET['__endpublicsurvey']) && isset($_GET['__rh'])) {
		$_POST['__response_id__'] = $response_id = Survey::decryptResponseHash($_GET['__rh'], $participant_id);
        $sql = "select r.record from redcap_surveys_response r, redcap_surveys_participants p
				where p.participant_id = $participant_id and r.response_id= '".db_escape($response_id)."'";
		$q = db_query($sql);
		$_GET['id'] = $fetched = db_result($q, 0);
    }
	// If record name is stored in session and doesn't already exist as $fetched, then get it
	if ($isPromisInstrument && isset($_SESSION['record'])) {
		$_GET['id'] = $fetched = $_SESSION['record'];
		unset($_SESSION['record']);
	}
	// Repeat Survey? (if repeating instrument enabled)
	$repeatSurveyBtn = '';
	if ($repeat_survey_enabled && isset($fetched) && $Proj->isRepeatingForm($event_id, $form_name)
		 && $repeat_survey_btn_location == 'AFTER_SUBMIT')
	{
		// Get count of existing instances and find next instance number
		list ($instanceTotal, $instanceMax) = RepeatInstance::getRepeatFormInstanceMaxCount($fetched, $event_id, $form_name, $Proj);
		$instanceNext = max(array($instanceMax, $_GET['instance'])) + 1;
		// Get the next instance's survey url
		$repeatSurveyLink = REDCap::getSurveyLink($fetched, $form_name, $event_id, $instanceNext);
		$repeatSurveyBtn =  RCView::div(array('style'=>'text-align:center;border-top:1px solid #ccc;padding:15px 0 20px;margin-top:50px;'),
								RCView::div(array('style'=>'color:#777;margin:0px 0 10px;'),
									$instanceTotal . " " . ($instanceTotal > 1 ? $lang['survey_1092'] : $lang['survey_1093'])
								) .
								RCView::button(array('class'=>'btn btn-defaultrc', 'style'=>'color:#000;background-color:#f0f0f0;', 'onclick'=>"window.location.href='$repeatSurveyLink';"),
									RCView::span(array('class'=>'fas fa-sync-alt', 'style'=>'top:2px;margin-right:5px;'), '') .
									(trim($repeat_survey_btn_text) == '' ? $lang['survey_1090'] : $repeat_survey_btn_text)
								)
							);
	}
	// AutoContinue
	elseif ($end_survey_redirect_next_survey && isset($fetched))
	{
		// Get the next survey url
		$next_survey_url = Survey::getAutoContinueSurveyUrl($fetched, $form_name, $event_id, $_GET['instance']);
		// If there is another survey - hijack the redirect
		if ($next_survey_url) $end_survey_redirect_url = $next_survey_url;
	}
	## REDIRECT TO ANOTHER WEBPAGE
	if ($end_survey_redirect_url != '')
	{
		// Store a completed survey response as a PDF in the File Repository
		if ($pdf_auto_archive > 0) {
			Survey::archiveResponseAsPDF($fetched, $_GET['event_id'], $_GET['page'], $_GET['instance']);
		}
		// REDCap Hook injection point: Pass project/record/survey attributes to method
		$group_id = (empty($Proj->groups)) ? null : Records::getRecordGroupId(PROJECT_ID, $fetched);
		if (!is_numeric($group_id)) $group_id = null;
		Hooks::call('redcap_survey_complete', array(PROJECT_ID, (is_numeric($_POST['__response_id__']) ? $fetched : null), $_GET['page'], $_GET['event_id'], $group_id, $_GET['s'], $_POST['__response_id__'], $_GET['instance']));
		Survey::outputCustomJavascriptProjectStatusPublicSurveyCompleted(PROJECT_ID, (is_numeric($_POST['__response_id__']) ? $fetched : null));
		// Apply piping to URL, if needed
		$end_survey_redirect_url = br2nl(Piping::replaceVariablesInLabel($end_survey_redirect_url, $fetched, $_GET['event_id'], $_GET['instance'], array(), false,
            null, false, $repeatInstrument, 1, false,
            false, $_GET['page'], ($public_survey ? null : $participant_id)));
		// Replace line breaks (if any due to piping) with single spaces
		$end_survey_redirect_url = str_replace(array("\r\n", "\n", "\r", "\t"), array(" ", " ", " ", " "), $end_survey_redirect_url);
		// Redirect to other page
		redirect($end_survey_redirect_url);
	}
	## DISPLAY ACKNOWLEDGEMENT TEXT
	else
	{
		// Determine if we should show the View Survey Results button
		$surveyResultsBtn = "";
		if ($enable_plotting_survey_results && $view_results)
		{
			// Generate and save a results code for this participant
			$results_code = Survey::getUniqueResultsCode($survey_id);
			// Save the code
			$sql = "update redcap_surveys_response set results_code = " . checkNull($results_code) . "
					where response_id = {$_POST['__response_id__']}";
			if (db_query($sql))
			{
				// HTML for View Survey Results button form with the results code (and its hash) embedded
				$surveyResultsBtnMargin = ($repeatSurveyBtn == '') ? 'margin-top:50px;' : '';
				$surveyResultsBtn = "<div style='text-align:center;border-top:1px solid #ccc;padding:20px 0;$surveyResultsBtnMargin'>
										<form id='results_code_form' action='".APP_PATH_SURVEY_FULL."index.php?s={$_GET['s']}&__results=$results_code' method='post' enctype='multipart/form-data'>
											<input type='hidden' name='results_code_hash' value='".strtoupper(DataExport::getResultsCodeHash($results_code))."'>
											<input type='hidden' name='__response_hash__' value='".Survey::encryptResponseHash($_POST['__response_id__'], $participant_id)."'>
											<button class='btn btn-defaultrc' style='color:#000066;background-color:#f0f0f0;' onclick=\"\$('#results_code_form').submit();\">
												<span class='fas fa-chart-bar' style='top:2px;margin-right:5px;'></span>{$lang['survey_167']}
											</button>
										</form>
									 </div>";
			}
		}
		// Get full acknowledgement text (perform piping, if applicable)
		$full_acknowledgement_text = RCView::div(array('id'=>'surveyacknowledgment'),
										Piping::replaceVariablesInLabel(filter_tags($acknowledgement), $_GET['id'], $_GET['event_id'], $_GET['instance'], array(),
                                        true, null, true, $repeatInstrument, 1, false,
                                        false, $_GET['page'], ($public_survey ? null : $participant_id))
									 );
		// CAN SEND EMAIL CONFIRMATION? If we don't have an email address for this respondent, then display place for them
		// to enter their email to send them the email confirmation, if it has been enabled for this survey.
		if ($confirmation_email_subject != '' && $confirmation_email_content != '')
		{
			// Get respondent's email, if we have it
			$emailsIdents = Survey::getResponsesEmailsIdentifiers(array($_GET['id']), $survey_id);
			if ($emailsIdents[$_GET['id']]['email'] == '') {
				// Display block for them to enter their email address
				$full_acknowledgement_text .= RCView::div(array('style'=>'background-color:#EFF6E8;font-size:12px;margin:60px -11px 10px -11px;text-indent:-24px;padding:8px 12px 5px 36px;color:#333;border:1px solid #ccc;'),
												RCView::img(array('src'=>'email_go.png', 'style'=>'margin-right:4px;')) .
												RCView::b($lang['survey_764']) . RCView::br() . $lang['survey_765'] . RCView::br() . RCView::br() .
												RCView::text(array('id'=>'confirmation_email_address', 'class'=>'x-form-text x-form-field', 'style'=>'color:#777;width:180px;',
													'value'=>$lang['survey_515'], 'onblur'=>"if(this.value==''){this.value='".js_escape($lang['survey_515'])."';this.style.color='#777777';} if(this.value != '".js_escape($lang['survey_515'])."'){redcap_validate(this,'','','soft_typed','email')}",
													'onfocus'=>"if(this.value=='".js_escape($lang['survey_515'])."'){this.value='';this.style.color='#000000';}",
													'onclick'=>"if(this.value=='".js_escape($lang['survey_515'])."'){this.value='';this.style.color='#000000';}")) .
												RCView::button(array('class'=>'jqbuttonmed', 'style'=>'text-indent:0px;', 'onclick'=>"
													var emlfld = $('#confirmation_email_address');
													if (emlfld.val() == '".js_escape($lang['survey_515'])."') {
														simpleDialog('".js_escape($lang['survey_515'])."',null,null,null,'$(\'#confirmation_email_address\').focus();');
													} else if (redcap_validate(document.getElementById('confirmation_email_address'), '', '', '', 'email')) {
														sendConfirmationEmail('".js_escape(js_escape2($_GET['id']))."','".js_escape(js_escape2($_GET['s']))."');
													}
												"), $lang['survey_766']).
												RCView::span(array('id'=>'confirmation_email_sent', 'style'=>'margin-left:15px;color:green;display:none;'),
													RCView::img(array('src'=>'tick.png')) .
													$lang['survey_181']
												) .
												RCView::br() .
												RCView::span(array('style'=>'color:#800000;font-size:10px;font-family:tahoma;'), "* " . $lang['survey_125'])
											 );
			}
		}
		// EDIT COMPLETED RESPONSE: If respondents are able to return to edit their completed response, then display either
		// the return code or a note about Survey Login (if enabled).
		if ($save_and_return && $edit_completed_response && $save_and_return_code_bypass != '1') 
		{
			$return_code = Survey::getSurveyReturnCode($_GET['id'], $_GET['page'], $_GET['event_id'], $_GET['instance']);
			$returnTextReturnCodeOrLogin = (!$public_survey && $survey_auth_enabled && ($survey_auth_apply_all_surveys || $survey_auth_enabled_single))
											? $lang['survey_666']
											: $lang['survey_667'] .
												RCView::div(array('style'=>'font-weight:bold;margin: 5px 0 0 24px;'),
													$lang['survey_657'] .
													RCView::span(array('style'=>'display:none;'), $return_code) .
													RCView::text(array('value'=>$return_code, 'class'=>'staticInput', 'readonly'=>'readonly', 'style'=>'letter-spacing:1px;margin-left:10px;color:#111;font-size:12px;width:110px;padding:2px 6px;', 'onclick'=>'this.select();'))
												);
			$full_acknowledgement_text .= 	RCView::div(array('style'=>'margin-top:50px;'), '&nbsp;') .
											RCView::div(array('id'=>'return_code_completed_survey_div', 'style'=>'background-color:#F1F1FF;font-size:12px;margin:0px -1px 10px -1px;text-indent:-24px;padding:8px 12px 8px 36px;color:#000066;border:1px solid #ccc;'),
												RCView::img(array('src'=>'information_frame.png', 'style'=>'margin-right:4px;')) .
												$lang['survey_665'] . " " .
												$returnTextReturnCodeOrLogin
											 );
		}
		if ($public_survey)
		{
			// Set the browser's web address to the private link
		    if ($save_and_return_code_bypass == '1') {
				$full_acknowledgement_text .= '<script type="text/javascript">modifyURL(\''.REDCap::getSurveyLink($_GET['id'], $_GET['page'], $_GET['event_id'], $_GET['instance']).'\');</script>';
            }
			// Remove the extra stuff in the URL
            else {
				$full_acknowledgement_text .= '<script type="text/javascript">modifyURL(app_path_webroot_full+\'surveys/?s=\'+getParameterByName("s"));</script>';
            }
		}
		// PDF Download: If option is enabled to allow respondents to download PDF of their responses
		if ($end_of_survey_pdf_download)
		{
			$private_link = REDCap::getSurveyLink($_GET['id'], $_GET['page'], $_GET['event_id'], $_GET['instance']);
			$return_code = Survey::getSurveyReturnCode($_GET['id'], $_GET['page'], $_GET['event_id'], $_GET['instance'], true);
			$compactPDF = ($pdf_econsent_system_enabled && $pdf_auto_archive == 2) ? "&compact=1&appendEconsentFooter=1" : "";
			$full_acknowledgement_text .= 	RCView::div(array('style'=>'background-color:#f6eeee;font-size:13px;margin:60px -11px 10px -11px;text-indent:-24px;padding:8px 12px 8px 36px;color:#333;border:1px solid #ccc;'),
												RCView::b($lang['survey_1139']) . 
												RCView::button(array('class'=>'jqbuttonmed', 'style'=>'text-indent:0px;margin-left:20px;', 'onclick'=>"
														window.open('$private_link&return_code=$return_code&instance={$_GET['instance']}&route=PdfController:index&__passthru=index.php{$compactPDF}','_blank');
													"), 
													RCView::img(array('src'=>'pdf.gif', 'style'=>'margin-right:2px;')) .
													RCView::span(array('style'=>'vertical-align:middle;color:#A00000;'), $lang['design_121'])
												)
											);
		}
		// Add the Repeat Survey button or the View Survey Results button, if applicable
		$full_acknowledgement_text .= $repeatSurveyBtn . $surveyResultsBtn;
		// Display Survey Queue, if applicable
		if (Survey::surveyQueueEnabled()) {
			$survey_queue_html = Survey::displaySurveyQueueForRecord($_GET['id'], true, true);
			if ($survey_queue_html != '') {
				$full_acknowledgement_text .= RCView::div(array('style'=>'margin:50px 0 0px -11px;'.($save_and_return && $edit_completed_response ? 'margin-top:25px;' : '')), $survey_queue_html);
			}
		}
		// Add CSS just for PROMIS instruments
		if ($isPromisInstrument) {
			?>
				<style type='text/css'>
				#surveyacknowledgment, #surveyacknowledgment p { font-size:15px; }
				</style>
			<?php
		}
		// Display acknowledgement text page
		Survey::exitSurvey($full_acknowledgement_text, false, null, true);
	}
}





/**
 * BUILD FORM METADATA
 */
// Determine fields on this instrument that should not be displayed (i.e. not on this page AND not used in branching/calculations)
$fieldsDoNotDisplay = array();
if ($question_by_section) {
	// Loop through all fields on this survey page and obtain all fields usedin branching/calcs on this survey page
	$usedInBranchingCalc = getDependentFields($pageFields[$_GET['__page__']]);
	// Determine fields from instrument that should NOT be displayed (even as hidden) on this survey page
	$fieldsDoNotDisplay = array_diff(array_keys($Proj->metadata), array($table_pk), $usedInBranchingCalc, $pageFields[$_GET['__page__']]);
}
$fieldsDoNotDisplay[] = $_GET['page'].'_complete'; // Form Status field will never be shown on survey pages.
// Set pre-fill data array as empty (will be used to fill survey form with existing values)
$element_data = array();
// Calculate Parser class (object $cp used in DataEntry::buildFormData() )
$cp = new Calculate();
// Branching Logic class (object $bl used in DataEntry::buildFormData() )
$bl = new BranchingLogic();
// If server-side validation is still in session somehow and wasn't removed, then remove it now
if (isset($_SESSION['serverSideValErrors']) && !isset($_GET['serverside_error_fields'])) {
	unset($_SESSION['serverSideValErrors']);
}
// Obtain form/survey metadata for rendering
list ($elements, $calc_fields_this_form, $branch_fields_this_form, $chkbox_flds) = DataEntry::buildFormData($form_name, $fieldsDoNotDisplay);
// If survey's first field is record identifier field, remove it since we're adding it later as a hidden field.
if (isset($elements[0]['name']) && $elements[0]['name'] == $table_pk) array_shift($elements);
// Add hidden survey fields and their data
$elements[] = array('rr_type'=>'hidden', 'id'=>'submit-action', 'name'=>'submit-action', 'value'=>$lang['data_entry_206']);
$elements[] = array('rr_type'=>'hidden', 'id'=>$table_pk, 'name'=>$table_pk, 'value'=>$fetched);
$elements[] = array('rr_type'=>'hidden', 'name'=>'__page__');
$elements[] = array('rr_type'=>'hidden', 'name'=>'__page_hash__');
$elements[] = array('rr_type'=>'hidden', 'name'=>'__response_hash__');
$elements[] = array('rr_type'=>'hidden', 'name'=>$form_name.'_complete', 'field'=>$form_name.'_complete');
$element_data[$table_pk] = $fetched;
$element_data['__page__'] = $_GET['__page__'];
$element_data['__page_hash__'] = Survey::getPageNumHash($_GET['__page__']);
$element_data['__response_hash__'] = (isset($_POST['__response_id__']) ? Survey::encryptResponseHash($_POST['__response_id__'], $participant_id) : '');

// ADD THE SAVE BUTTONS
$saveBtn = RCView::button(array('name'=>'submit-btn-saverecord', 'tabindex'=>'0', 'class'=>'jqbutton nowrap','style'=>'color:#800000;width:100%;max-width:140px;','onclick'=>'$(this).button("disable");dataEntrySubmit(this);return false;'), $saveBtnText);
// Repeat Survey? (if repeating instrument enabled)
$repeatSurveyBtn = '';
if ($repeat_survey_enabled && $isLastPage && isset($fetched) && $Proj->isRepeatingForm($event_id, $form_name)
	 && $repeat_survey_btn_location == 'BEFORE_SUBMIT')
{
	$saveBtn =  RCView::div(array('style'=>'font-weight:normal;color:#888;'),
					$lang['survey_1097']
				) .
				RCView::div(array('style'=>'margin:5px 0;'),
					RCView::button(array('name'=>'submit-btn-saverepeat', 'tabindex'=>'0', 'class'=>'jqbutton', 'style'=>'color:#000;background-color:#f0f0f0;', 'onclick'=>'$(this).button("disable");dataEntrySubmit(this);return false;'),
						RCView::span(array('class'=>'fas fa-sync-alt', 'style'=>'top:2px;margin-right:5px;'), '') .
						(trim($repeat_survey_btn_text) == '' ? $lang['survey_1090'] : RCView::escape($repeat_survey_btn_text))
					)
				) .
				RCView::div(array('style'=>'font-weight:normal;margin:4px 0;color:#888;'),
					"&ndash; ".$lang['global_47']." &ndash;"
				) .
				RCView::div(array(),
					$saveBtn
				);
}
// Prev page button or just submit button?
if ((($pdf_econsent_system_enabled && $pdf_auto_archive == 2) || $question_by_section) && $_GET['__page__'] > 1 && !$hide_back_button) {
	// Display "previous page" button? (survey-level setting)
	// "Previous page" and "Next page"/"Submit" buttons
	$saveBtnRow = RCView::td(array('colspan'=>'2','style'=>'padding:15px 0;'),
					RCView::div(array('class'=>'col-12 col-md-6 text-center float-left', 'style'=>'margin-bottom:5px;'),
						RCView::button(array('name'=>'submit-btn-saveprevpage', 'tabindex'=>'0', 'class'=>'jqbutton nowrap','style'=>'color:#800000;width:100%;max-width:140px;','onclick'=>'$(this).button("disable");dataEntrySubmit(this);return false;'),
							"<< ".$lang['data_entry_214']
						)
					) .
					RCView::div(array('class'=>'col-12 col-md-6 text-center float-right', 'style'=>'margin-bottom:5px;'),
						$saveBtn
					)
				  );
} else {
	// "Submit" button
	$saveBtnRow = RCView::td(array('colspan'=>'2','style'=>'text-align:center;padding:15px 0;'), $saveBtn);
}
// Show "save and return later" button if setting is enabled for the survey
$saveReturnRow = "";
if ($save_and_return && !defined('save_and_return_disabled')) {
	$saveReturnRow = RCView::tr(array(),
						RCView::td(array('colspan'=>'2','style'=>'text-align:center;padding: 1px 0 10px;'),
							RCView::button(array('name'=>'submit-btn-savereturnlater', 'tabindex'=>'0', 'class'=>'jqbutton','onclick'=>'$(this).button("disable");dataEntrySubmit(this);return false;'), $lang['data_entry_215'])
						)
					);
}
$elements[] = array('rr_type'=>'surveysubmit', 'label'=>RCView::table(array('cellspacing'=>'0'), RCView::tr(array(), $saveBtnRow) . $saveReturnRow));


/**
 * ADD CALC FIELDS AND BRANCHING LOGIC FROM OTHER FORMS
 * Add fields from other forms as hidden fields if involved in calc/branching on this form
 */
list ($elementsOtherForms, $chkbox_flds_other_forms, $jsHideOtherFormChkbox) = DataEntry::addHiddenFieldsOtherForms($form_name, array_merge($branch_fields_this_form, $calc_fields_this_form));
$elements 	 = array_merge($elements, $elementsOtherForms);
$chkbox_flds = array_merge($chkbox_flds, $chkbox_flds_other_forms);


/**
 * PRE-FILL DATA FOR EXISTING SAVED RESPONSE (from previous pages or previous session)
 */
if ($_SERVER['REQUEST_METHOD'] == 'POST' || ($_SERVER['REQUEST_METHOD'] == 'GET' && !$public_survey
	&& ($save_and_return_code_bypass == '1' || (isset($isNonPublicFollowupSurvey) && $isNonPublicFollowupSurvey !== false))))
{
	//Build query for pulling existing data to render on top of form
	$sql = "select field_name, value, if (instance is null,1,instance) as instance
			from redcap_data where project_id = $project_id and event_id = {$_GET['event_id']}
			and record = '".db_escape($fetched)."' and field_name in (";
	foreach ($elements as $fldarr) {
		if (isset($fldarr['field'])) $sql .= "'".$fldarr['field']."', ";
	}
	$sql = substr($sql, 0, -2) . ")";
	$q = db_query($sql);
	while ($row_data = db_fetch_array($q))
	{
		// Is field on a repeating form or event?
		$this_form = $Proj->metadata[$row_data['field_name']]['form_name'];
		if ($hasRepeatingFormsEvents && $row_data['instance'] != $_GET['instance'] 
			&& (($Proj->isRepeatingForm($_GET['event_id'], $this_form) && $this_form == $_GET['page']) || $Proj->isRepeatingEvent($_GET['event_id']))) 
		{
			// Value exists on same form that is a repeating form but is a different instance, then don't use it here
			continue;
		} elseif (!$hasRepeatingFormsEvents && $row_data['instance'] > 1) {
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
}



/**
 * PRE-FILL QUESTIONS VIA QUERY STRING OR VIA __prefill flag FROM POST REQUEST
 * Catch any URL variables passed to use for pre-filling fields (i.e. plug into $element_data array for viewing)
 */
$reservedParams = array();
$usingSurveyPrefill = false;
// If a GET request with variables in query string
if ($_SERVER['REQUEST_METHOD'] == 'GET')
{
	// Ignore certain GET variables that are currently used in the application
	$reservedParams = array("s", "hash", "page", "event_id", "pid", "pnid", "preview", "id", "sq");
	// Loop through all query string variables
	foreach ($_GET as $key=>$value) {
		// Ignore reserved fields
		if (in_array($key, $reservedParams)) continue;
		// First check if field is a checkbox field ($key will be formatted as "fieldname___codedvalue" and $value as "1" or "0")
		$prefillFldIsChkbox = false;
		if (!isset($Proj->metadata[$key]) && $value == '1' && strpos($key, '___') !== false) {
			// Is possibly a checkbox, but parse into true field name and value to be sure
			list ($keychkboxcode, $keychkboxname) = explode('___', strrev($key), 2);
			$keychkboxname = strrev($keychkboxname);
			$keychkboxcode = strrev($keychkboxcode);
			// Verify checkbox field name
			if (isset($Proj->metadata[$keychkboxname])) {
				// Is a real field, so reset key/value
				$prefillFldIsChkbox = true;
				$key = $keychkboxname;
				$value = $keychkboxcode;
			}
		}
		// Now verify the field name
		if (!isset($Proj->metadata[$key])) continue;
		// Skip calc fields
        if ($Proj->metadata[$key]['element_type'] == 'calc') continue;
		// Add to pre-fill data
		if ($prefillFldIsChkbox) {
			$element_data[$key][] = $value;
		} else {
			$element_data[$key] = urldecode($value);
		}
		// Set flag
		$usingSurveyPrefill = true;
	}
}
// If a POST request with variable as Post values (__prefill flag was set)
elseif (isset($_POST['__prefill']))
{
	// Ignore special fields that only occur for surveys
	$postIgnore = array('__page__', '__response_hash__', '__response_id__');
	// Loop through all Post variables
	foreach ($_POST as $key=>$value)
	{
		// Ignore special Post fields
		if (in_array($key, $postIgnore)) continue;
		// First check if field is a checkbox field ($key will be formatted as "fieldname___codedvalue" and $value as "1" or "0")
		$prefillFldIsChkbox = false;
		if (!isset($Proj->metadata[$key]) && $value == '1' && strpos($key, '___') !== false) {
			// Is possibly a checkbox, but parse into true field name and value to be sure
			list ($keychkboxcode, $keychkboxname) = explode('___', strrev($key), 2);
			$keychkboxname = strrev($keychkboxname);
			$keychkboxcode = strrev($keychkboxcode);
			// Verify checkbox field name
			if (isset($Proj->metadata[$keychkboxname])) {
				// Is a real field, so reset key/value
				$prefillFldIsChkbox = true;
				$key = $keychkboxname;
				$value = $keychkboxcode;
			}
		}
		// Now verify the field name
		if (!isset($Proj->metadata[$key])) continue;
        // Skip calc fields
        if ($Proj->metadata[$key]['element_type'] == 'calc') continue;
		// Add to pre-fill data
		if ($prefillFldIsChkbox) {
			$element_data[$key][] = $value;
		} else {
			$element_data[$key] = $value;
		}
		// Set flag
		$usingSurveyPrefill = true;
	}
}











// Check response limit, if enabled
if (($_GET['__page__'] == 1 && $_SERVER['REQUEST_METHOD'] != 'POST') || isset($_POST['__prefill'])) {
	if (Survey::reachedResponseLimit($project_id, $survey_id, $event_id)) {
		Survey::exitSurvey($response_limit_custom_text);
	}
}

// Page header
$objHtmlPage->PrintHeader();

// REDCaptcha
if ($public_survey && $allow_outbound_http && $google_recaptcha_enabled && $google_recaptcha_site_key != '' && $google_recaptcha_secret_key != '')
{
    // If user has already passed reCAPTCHA and has cookie, then skip
    $displayCaptcha = !(isset($_COOKIE['redcap_survey_recaptcha']) && decrypt($_COOKIE['redcap_survey_recaptcha']) == TODAY);

    if ($displayCaptcha)
    {
        // Is this a recaptcha post-back
        if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['g-recaptcha-response'])) {
            $response = http_get("https://www.google.com/recaptcha/api/siteverify?secret=" . htmlspecialchars($google_recaptcha_secret_key, ENT_QUOTES) . "&response=" . $_POST['g-recaptcha-response'] . "&remoteip=" . System::clientIpAddress(), 5);
            $responseKeys = json_decode($response, true);
            if ($response !== false && $response != '' && isset($responseKeys['success']) && $responseKeys['success'] !== true) {
                echo "<div class='alert alert-danger text-center'><b>{$lang['survey_1244']}</b></div>";
            } else {
                // Set cookie to remember passing reCAPTCHA just for today
                savecookie('redcap_survey_recaptcha', encrypt(TODAY), 86400);
                // Redirect to survey page to reset page to a GET request
                redirect($_SERVER['REQUEST_URI']);
            }
        }
        // Render recaptcha form
        ?>
        <form id="frm" method="POST">
            <?php echo $title_logo ?>
            <p class="text-center mb-4 mx-3"><b><?php echo $lang['survey_1242'] ?></b></p>
            <div class="g-recaptcha"></div>
            <div class="text-center surveysubmit mt-3">
                 <button class="jqbutton" type="submit"><?php echo $lang['survey_1243'] ?></button>
            </div>
        </form>
        <script type="text/javascript">
            var onloadCallback = function() {
                var e = $('.g-recaptcha')[0];
                grecaptcha.render(e, { 'sitekey' : '<?php echo htmlspecialchars($google_recaptcha_site_key, ENT_QUOTES); ?>' });
                $('#container').show();
            };
        </script>
        <script type="text/javascript" src="https://www.google.com/recaptcha/api.js?onload=onloadCallback&render=explicit" async defer></script>
        <style type="text/css">
            #frm { margin-top: 10px; }
            #pagecontainer { max-width: 600px;}
            #container { display:none; margin: 50px 0 0;  border-radius: 15px; }
            #pagecontent {  padding: 10px 10px 20px; }
            .g-recaptcha > div{  margin 10px auto !important; text-align: center; width: auto !important; height: auto !important; }
        </style>
        <?php
        $objHtmlPage->PrintFooter();
        exit;
    }
}

// REDCap Hook injection point: Pass project/record/survey attributes to method
$group_id = (empty($Proj->groups)) ? null : Records::getRecordGroupId(PROJECT_ID, $fetched);
if (!is_numeric($group_id)) $group_id = null;
Hooks::call('redcap_survey_page_top', array(PROJECT_ID, (is_numeric(isset($_POST['__response_id__']) ? $_POST['__response_id__'] : '') ? $fetched : null), $_GET['page'], $_GET['event_id'], $group_id, $_GET['s'], (isset($_POST['__response_id__']) ? $_POST['__response_id__'] : ''), $_GET['instance']));

// SURVEY LOGIN: If respondent was just auto-logged-in via cookie for Survey Login, then display message
// at top of screen to denote that their survey login session is still active.
if (Survey::surveyLoginEnabled() && ($enteredReturnCodeSuccessfully || isset($_POST['__code'])) && ($survey_auth_apply_all_surveys || $survey_auth_enabled_single)
	&& !$public_survey && isset($_COOKIE['survey_login_pid'.$project_id]) && hash($password_algo, "$project_id|$fetched|$salt") == $_COOKIE['survey_login_pid'.$project_id]
	 && isset($_COOKIE['survey_login_session_pid'.$project_id]) && $_COOKIE['survey_login_session_pid'.$project_id] == $_COOKIE['survey_login_pid'.$project_id])
{
	print 	RCView::div(array('id'=>'survey_login_active_session_div', 'class'=>'darkgreen', 'style'=>'padding:2px 15px;font-size:12px;display:none;position:absolute;'),
				RCView::img(array('src'=>'tick_shield_small.png')) .
				$lang['survey_675']
			);
	?>
	<script type='text/javascript'>
	$(function(){
		setTimeout(function(){
			$('#survey_login_active_session_div').center().css({'top':'0px'}).show('fade','slow');
			setTimeout(function(){
				$('#survey_login_active_session_div').hide('fade','slow');
			},4000);
		},700);
	});
	</script>
	<?php
}

// If survey-setting set to hide Required Field red text, then add CSS to hide them
if (!$show_required_field_text) {
	?><style type="text/css">.requiredlabel, .requiredlabelmatrix { display:none; } </style><?php
}

// Call JavaScript files
loadJS('Libraries/geoPosition.js');
loadJS('Libraries/geoPositionSimulator.js');
?>
<script type="text/javascript">
// Set variables
var record_exists = <?php echo $hidden_edit ?>;
var require_change_reason = 0;
var event_id = <?php echo $_GET['event_id'] ?>;
// Set language variables
var stopAction1 = '<?php echo js_escape($lang['survey_564']) ?>';
var stopAction2 = '<?php echo js_escape($lang['survey_565']) ?>';
var stopAction3 = '<?php echo js_escape($lang['survey_566']) ?>';
var langDlgSaveDataTitleCaps = '<?php echo js_escape($lang['data_entry_199']) ?>';
var langDlgSaveDataMsg = '<?php echo js_escape($lang['data_entry_265']) ?>';
var langCancel = '<?php print js_escape($lang['global_53']) ?>';
var langYes = '<?php print js_escape($lang['design_100']) ?>';
var langRemoveSpaces = '<?php print js_escape($lang['data_entry_433']) ?>';
var lang_remove_file = '<?php print js_escape($lang['form_renderer_24']) ?>';
var lang_send_it = '<?php print js_escape($lang['form_renderer_25']) ?>';
var lang_upload_new_version = '<?php print js_escape($lang['data_entry_459']) ?>';
$(function() {
	// Check for any reserved parameters in query string
	checkReservedSurveyParams(new Array('<?php echo implode("','", $reservedParams) ?>'));
	<?php if ($question_auto_numbering) { ?>
	// AUTO QUESTION NUMBERING: Add page number values where needed
	var qnums = new Array('<?php if (isset($autoNumFields[$_GET['__page__']])) echo implode("','", array_keys($autoNumFields[$_GET['__page__']])); ?>');
	var qvars = new Array('<?php if (isset($autoNumFields[$_GET['__page__']])) echo implode("','", $autoNumFields[$_GET['__page__']]); ?>');
	for (x in qnums) $('#'+qvars[x]+'-tr').find('td:first').prepend(qnums[x]+')');
	<?php } ?>
	// Enable green row highlight for data entry form table
	enableDataEntryRowHighlight();
});
</script>
<?php
// Text-to-speech javascript file
if (($text_to_speech == '1' && (!isset($_COOKIE['texttospeech']) || $_COOKIE['texttospeech'] == '1'))
	|| ($text_to_speech == '2' && isset($_COOKIE['texttospeech']) && $_COOKIE['texttospeech'] == '1')) {
	?><script type="text/javascript" src="<?php echo APP_PATH_JS ?>TextToSpeech.js"></script><?php
}
?>

<!-- Title and/or Logo -->
<div id="surveytitlelogo">
	<table cellspacing="0" style="width:100%;max-width:100%;">
		<tr>
			<td valign="top">
				<?php echo $title_logo ?>
			</td>
			<!-- Increase/decrease font -->
			<td valign="top" id="changeFont" aria-hidden="true">
				<div class="nowrap"><?php echo $lang['survey_218'] ?></div>
				<div class="nowrap"><button class="increaseFont"><i class="far fa-plus-square" alt="<?php echo js_escape2($lang['survey_1129']) ?>"></i></button><span style="margin:0 5px;">|</span><button class="decreaseFont"><i class="far fa-minus-square" alt="<?php echo js_escape2($lang['survey_1130']) ?>"></i></button></div>
			</td>
			<?php
			// TEXT-TO-SPEECH BUTTON
			$text_to_speech_button = "";
			if ($text_to_speech > 0) {
				// If initially turned off or if user turned off
				if (($text_to_speech == '2' && (!isset($_COOKIE['texttospeech']) || $_COOKIE['texttospeech'] == '0'))
					|| ($text_to_speech == '1' && isset($_COOKIE['texttospeech']) && $_COOKIE['texttospeech'] == '0')) {
					$text_to_speech_enable_button_style = '';
					$text_to_speech_disable_button_style = 'display:none;';
				}
				// If initially turned on or if user turned on
				else {
					$text_to_speech_enable_button_style = 'display:none;';
					$text_to_speech_disable_button_style = '';
				}
				// Buttons
				$text_to_speech_button = RCView::div(array('id'=>'text_to_speech_button', 'style'=>'margin-top:7px;text-align:center;position:relative;left:15px;'),
											// Enable button
											RCView::button(array('id'=>'enable_text-to-speech', 'class'=>'jqbuttonmed', 'alt'=>$lang['survey_997'], 'style'=>'font-size:11px;'.$text_to_speech_enable_button_style,
												'onclick'=>"addSpeakIconsToSurveyViaBtnClick(1);"),
                                                '<i class="fas fa-volume-up" style="text-indent:0;"></i> ' .$lang['survey_997']
											) .
											// Disable button
											RCView::button(array('id'=>'disable_text-to-speech', 'class'=>'jqbuttonmed', 'alt'=>$lang['survey_998'], 'style'=>'font-size:11px;'.$text_to_speech_disable_button_style,
												'onclick'=>"addSpeakIconsToSurveyViaBtnClick(0);"),
                                                '<i class="fas fa-volume-up" style="text-indent:0;"></i> ' .$lang['survey_998']
											)
										);
			}
			// TEXT-TO-SPEECH BUTTON and/or SAVE & RETURN LATER: Give note at top for public surveys if user is returning
			$show_save_and_return_link = ($save_and_return && $public_survey && $_SERVER['REQUEST_METHOD'] == 'GET' && $save_and_return_code_bypass != '1');
			$show_survey_queue_link = (!$survey_queue_hide && isset($_POST['__response_id__']) && ($_SERVER['REQUEST_METHOD'] == 'GET' || isset($return_code))
										&& Survey::surveyQueueEnabled() && Survey::getSurveyQueueForRecord($_GET['id'], true));
			$text_to_speech_already_displayed_btn = false;
			if ($show_save_and_return_link || ($text_to_speech > 0 && !$show_survey_queue_link))
			{
				// Display cell
				print '<td valign="top" class="bubbleInfo" style="width:125px;position:relative;">';
				// Display Save and Return bubble widget
				if ($show_save_and_return_link) {
					include APP_PATH_DOCROOT . "Surveys/return_code_widget.php";
				}
				// Display Text-to-Speech button
				if ($text_to_speech > 0) {
					print $text_to_speech_button;
					$text_to_speech_already_displayed_btn = true;
				}
				print '</td>';
			}
			// SURVEY QUEUE LINK (if not a public survey and only if record already exists)
			if ($show_survey_queue_link || ($text_to_speech > 0 && !$show_save_and_return_link && !$text_to_speech_already_displayed_btn))
			{
				// Display cell
				print '<td valign="top" style="width:125px;position:relative;padding-top:2px;">';
				// Display icon and link
				if ($show_survey_queue_link) {
					print 	RCView::div(array('id'=>'survey_queue_corner'),
								RCView::a(array('href'=>'javascript:;', 'style'=>'color:#800000;vertical-align:middle;text-decoration:underline;font-weight:bold;',
									'onclick'=>"$.get('" . APP_PATH_SURVEY_FULL . '?sq=' . Survey::getRecordSurveyQueueHash($_GET['id']) . "',{},function(data){
										$('#overlay').height( $(document).height() ).width( $(document).width() ).show();
										$('#survey_queue_corner_dialog').html(data).show();
										if (isMobileDevice) $('#survey_queue_corner_dialog').width($(window).width());
										$('#survey_queue_corner_dialog').position({ my: 'center', at: 'center', of: window });
										$('#survey_queue_corner_dialog .jqbuttonmed, #survey_queue_corner_dialog .jqbutton').button();
										if ($('#survey_queue_corner_dialog').height() > $(window).height()-100) {
										    $('#survey_queue_corner_dialog').height($(window).height()-100);
										    $('#survey_queue_corner_dialog').css('overflow-y', 'auto');
										    $('#survey_queue_corner_dialog').width( $('#survey_queue_corner_dialog').width()+getScrollBarWidth());
										    $('#survey_queue_corner_dialog').position({ my: 'center', at: 'center', of: window });
										}
									});"),
									'<span class="fas fa-tasks" style="font-size:15px;margin-right:3px;" aria-hidden="true"></span>'.
									$lang['survey_505']
								)
							);
				}
				// Display Text-to-Speech button
				if ($text_to_speech > 0 && !$text_to_speech_already_displayed_btn) {
					print $text_to_speech_button;
				}
				print '</td>';
			}
			?>
		</tr>
	</table>
</div>

<?php
// Is this a non-existing record on a public survey?
Survey::$nonExistingRecordPublicSurvey = ($public_survey && !$hidden_edit);
// Survey Instructions (display for first page only)
if (($_GET['__page__'] == 1 && $_SERVER['REQUEST_METHOD'] != 'POST') || isset($_POST['__prefill'])
	// Also show survey instructions if returning via Save & Return Later and still on the first page
	|| ($_GET['__page__'] == 1 && $_SERVER['REQUEST_METHOD'] == 'POST' && (isset($return_code) || isset($_GET['__startover']))))
{
	print RCView::div(array('id'=>'surveyinstructions'),
		// (perform piping, if applicable)
		Piping::replaceVariablesInLabel(filter_tags($instructions), $_GET['id'], $_GET['event_id'], $_GET['instance'], array(),
            true, null, true, $repeatInstrument, 1, false,
            false, $_GET['page'], ($public_survey ? null : $participant_id))
	);
}
// PROMIS: Determine if instrument is a PROMIS instrument downloaded from the Shared Library
if ($isPromisInstrument) {
	// Render PROMIS instrument
	PROMIS::renderPromisForm(PROJECT_ID, $_GET['page'], $participant_id);
    // Call this JS file ONLY after DataEntry::renderForm()
	loadJS('DataEntrySurveyCommon.js');
} else {
	// Display page number (if multi-page enabled AND display_page_number=1)
	if ($question_by_section && $display_page_number) {
		print RCView::p(array('id'=>'surveypagenum'), "{$lang['survey_132']} {$_GET['__page__']} {$lang['survey_133']} $totalPages");
	}
	// Display e-Consent PDF confirmation page, if applicable
	if ($pdf_econsent_system_enabled && $pdf_auto_archive == 2 && $totalPages == $_GET['__page__']) {
		Survey::renderEconsentPdfFrame();
	}
	// Normal survey Questions
	DataEntry::renderForm($elements, $element_data, $hideFields);
    // Call this JS file ONLY after DataEntry::renderForm()
	loadJS('DataEntrySurveyCommon.js');
    // If the survey prefill is being performed (via GET or POST) *and* the Secondary Unique Field is enabled and on this page, run a check on that field
	if ($usingSurveyPrefill && isset($secondary_pk) && $secondary_pk != '' && isset($element_data[$secondary_pk]) && (isset($_POST[$secondary_pk]) || isset($_GET[$secondary_pk])))
	{
		?><script type="text/javascript">$(function(){ $(':input[name="<?=$secondary_pk?>"]').trigger('blur'); });</script><?php
	}
	// JavaScript for Calculated Fields and Branching Logic
	if ($longitudinal) echo Form::addHiddenFieldsOtherEvents($_GET['id'], $_GET['event_id'], $_GET['page'], $_GET['instance']);
	// Output JavaScript for branching and calculations
	// Moved by RaB to fix bug
	addLangToJS(array('global_210', 'global_211', 'global_212', 'global_213', 'global_214', 'global_215', 'questionmark', 'global_216', 'global_217', 'global_218', 'global_219', 'period', 'global_220', 'global_221', 'global_222', 'global_223', 'global_224', 'global_225', 'global_226', 'global_227'));
	print $cp->exportJS() . $bl->exportBranchingJS();
//	addLangToJS(array('global_210', 'global_211', 'global_212', 'global_213', 'global_214', 'global_215', 'questionmark', 'global_216', 'global_217', 'global_218', 'global_219', 'period', 'global_220', 'global_221', 'global_222', 'global_223', 'global_224', 'global_225', 'global_226', 'global_227'));
	// JavaScript that hides checkbox fields from other forms, which need to be hidden
	print $jsHideOtherFormChkbox;
	// Stop Action text and JavaScript, if applicable
	print DataEntry::enableStopActions();
	print RCView::div(array('id'=>'stopActionPrompt','title'=>$lang['survey_01']), RCView::b($lang['survey_02']) . RCView::SP . $lang['survey_03']);
	print RCView::div(array('id'=>'stopActionReturn','title'=>$lang['survey_05']), $lang['survey_04']);
	// Hidden div dialog for Survey Queue popup
    print RCView::div(array('id'=>'survey_queue_corner_dialog', 'style'=>'position: absolute; z-index: 100; width: 802px; display: none;border:1px solid #800000;'), '');
	print RCView::div(array('id'=>'overlay', 'class'=>'ui-widget-overlay', 'style'=>'position: absolute; background-color:#333;z-index:99;display:none;'), '');
	// Required fields pop-up message
	DataEntry::msgReqFields($fetched, '', true);
	// SERVER-SIDE VALIDATION pop-up message (URL variable 'dq_error_ruleids' has been passed)
	if (isset($_GET['serverside_error_fields'])) Form::displayFailedServerSideValidationsPopup($_GET['serverside_error_fields']);
	// @MAXCHOICE error pop-up message (URL variable 'maxchoice_error_fields' has been passed)
	if (isset($_GET['maxchoice_error_fields'])) Form::displayFailedSaveMaxChoicePopup($_GET['maxchoice_error_fields']);
	// Set file upload dialog
	DataEntry::initFileUploadPopup();
	// Secondary unique field javascript
	DataEntry::renderSecondaryIdLang();
	// if Survey Email Participant Field is on this survey page, and the participant is in the Participant List,
	// then pre-fill the email field with the email address from the Participant List and disable the field.
	if (!$public_survey
		&& (($survey_email_participant_field != '' && isset($Proj->forms[$_GET['page']]['fields'][$survey_email_participant_field]) && in_array($survey_email_participant_field, $pageFields[$_GET['__page__']]))
		|| ($survey_phone_participant_field != '' && isset($Proj->forms[$_GET['page']]['fields'][$survey_phone_participant_field]) && in_array($survey_phone_participant_field, $pageFields[$_GET['__page__']]))))
	{
		// If $participant_email is empty because this is not an initial survey, then obtain it from initial survey's Participant List value
		$thisPartEmailTrue = $participant_email;
		$thisPartPhoneTrue = $participant_phone;
		if ($thisPartEmailTrue != '') {
			?>
			<script type="text/javascript">
			$(function(){
				$('form#form :input[name="<?php echo $survey_email_participant_field ?>"]').css('color','gray').attr('readonly', true)
					.val('<?php echo js_escape($thisPartEmailTrue) ?>')
					.attr('title', '<?php echo js_escape($lang['survey_1131']) ?>');
			})
			</script>
			<?php
		}
		if ($thisPartPhoneTrue != '') {
			?>
			<script type="text/javascript">
			$(function(){
				$('form#form :input[name="<?php echo $survey_phone_participant_field ?>"]').css('color','gray').attr('readonly', true)
					.val('<?php echo js_escape($thisPartPhoneTrue) ?>')
					.attr('title', '<?php echo js_escape($lang['survey_1131']) ?>')
					.trigger('blur');
			})
			</script>
			<?php
		}
	}
}

// REDCap Hook injection point: Pass project/record/survey attributes to method
$group_id = (empty($Proj->groups)) ? null : Records::getRecordGroupId(PROJECT_ID, $fetched);
if (!is_numeric($group_id)) $group_id = null;
Hooks::call('redcap_survey_page', array(PROJECT_ID, (is_numeric(isset($_POST['__response_id__']) ? $_POST['__response_id__'] : '') ? $fetched : null), $_GET['page'], $_GET['event_id'], $group_id, $_GET['s'], (isset($_POST['__response_id__']) ? $_POST['__response_id__'] : ''), $_GET['instance']));

// Custom survey footer text (e.g., to display data privacy notice)
if ($custom_project_footer_text != '') 
{
	if ($custom_project_footer_text_link == '') {
		// Inline display		
		print RCView::div(array('class'=>'text-right p-2'), RCView::button(array('class'=>'btn btn-xs btn-defaultrc', 'style'=>'color:#555;', 'onclick'=>"printDiv('custom_project_footer_text');"), '<i class="fas fa-print"></i> '.$lang['system_config_623']));
		print RCView::div(array('id'=>'custom_project_footer_text', 'class'=>'px-4 pb-4 fs13'), nl2br(filter_tags($custom_project_footer_text)));
	} else {
		// Modal dialog display
		print RCView::div(array('class'=>'text-center p-3'), 
			RCView::a(array('href'=>'javascript:;', 'class'=>'fs14', 'onclick'=>"simpleDialog(null,null,'custom_project_footer_text',600,null,'".js_escape($lang['calendar_popup_01'])."',\"printDiv('custom_project_footer_text');\",'".js_escape($lang['scheduling_35'])."');fitDialog($('#custom_project_footer_text'));"), filter_tags($custom_project_footer_text_link))
		);
		print RCView::div(array('id'=>'custom_project_footer_text', 'title'=>$custom_project_footer_text_link, 'class'=>'simpleDialog fs13'), nl2br(filter_tags($custom_project_footer_text)));
	}
}

// Page footer
$objHtmlPage->PrintFooter();
