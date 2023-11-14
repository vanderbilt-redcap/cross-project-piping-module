<?php
namespace Vanderbilt\CrossprojectpipingExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

require_once dirname(__FILE__) . '/hooks_common.php';

class CrossprojectpipingExternalModule extends AbstractExternalModule
{
	public $pipingMode;
	public $pipeOnStatus;
	public $modSettings;
	public $hideButton;

	function __construct() {
		parent::__construct();
		$this->hideButton = false;
		if(defined("PROJECT_ID")) {
			$this->modSettings = $this->getPipingSettings(PROJECT_ID);
		}
	}
	
	function redcap_every_page_before_render($project_id) {
		$user_is_at_record_status_dashboard = $_SERVER['SCRIPT_NAME'] == APP_PATH_WEBROOT . "DataEntry/record_status_dashboard.php";
		$pipe_all_records_button_configured = $this->getProjectSetting('piping-all-records-button');
		
		if ($user_is_at_record_status_dashboard && $pipe_all_records_button_configured) {
			// add 'Pipe All Records' button to record status dashboard screen (should appear next to the '+Add New Record' button
			$pipe_all_records_ajax_url = $this->getUrl('php/pipe_all_data_ajax.php');
			$css_url = $this->getUrl('css/pipe_all_data_ajax.css');
			$javascript_file_contents = file_get_contents($this->getModulePath() . 'js/record_status_dashboard.js');
			$javascript_file_contents = str_replace("AJAX_ENDPOINT", $pipe_all_records_ajax_url, $javascript_file_contents);
			
			echo "<script type='text/javascript'>$javascript_file_contents</script>";
			echo "<link rel='stylesheet' href='$css_url'>";
		}
	}
	
	function redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
		$this->processRecord($project_id, $record, $instrument, $event_id, $repeat_instance);
	}

	function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
		$this->hideButton = true;
		$this->processRecord($project_id, $record, $instrument, $event_id, $repeat_instance);
	}

	function redcap_module_save_configuration($project_id) {
		## This function was started to prevent unauthorized users from pulling data from other projects
		## Currently, this is unneeded as only superusers can configure the module
		## At some point, it would be nice to allow non-superusers to configure
		## But further testing and development is needed
		return;

		## Don't allow users to specify project/field combinations that they don't have read access to
		## Unless that project/field combination has already been accepted by someone with read access
		$pipedProjects = $this->getProjectSetting('project-id',$project_id);

		$destinationFields = $this->getProjectSetting('data-destination-field',$project_id);
		$sourceFields = $this->getProjectSetting('data-source-field',$project_id);

		error_log("Cross Project Piping: Piped<br /><br />\n\n".var_export($pipedProjects,true));
		error_log("Cross Project Piping: Destination<br /><br />\n\n".var_export($destinationFields,true));
		error_log("Cross Project Piping: Source<br /><br />\n\n".var_export($sourceFields,true));

		foreach($pipedProjects as $projectKey => $thisProject) {
			## List of fields for this project that are already confirmed
			$confirmedFields = $this->getProjectSetting('confirmed-fields-'.$thisProject,$project_id);


			foreach($destinationFields[$projectKey] as $fieldKey => $fieldName) {
				if($sourceFields[$projectKey][$fieldKey] != "") {
					$fieldName = $sourceFields[$projectKey][$fieldKey];
				}
			}

			$userRights = \UserRights::getPrivileges($thisProject,USERID);
			$isSuperUser = \User::isSuperUser(USERID);

			$userRights = $userRights[$thisProject][USERID];

			error_log("Cross Project Piping: User Rights : $isSuperUser<br /><br />\n\n".var_export($userRights,true));
			if($isSuperUser) {

			}
		}
	}

	function redcap_module_system_change_version($version, $old_version) {
		## This function was being tested to set up a test project on module version enabling
		## It's not been tested thoroughly enough to leave in
		return;
		$this->setupTestProjects();
	}

	function redcap_module_system_enable($version) {
		## This function was being tested to set up a test project on module version enabling
		## It's not been tested thoroughly enough to leave in
		return;

		error_log("Enabling Cross Project Piping");
		$this->setupTestProjects();
	}

	private function setupTestProjects() {
		$parentProject = $this->createOrWipeTestProject("test_project_1","Cross Project Piping Test - Parent Project");
		$pipingProject = $this->createOrWipeTestProject('test_project_2',"Cross Project Piping Test - Receiving Project");

		$url = $this->getUrl("updateTestMetadata.php",true);

		if($parentProject) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_VERBOSE, 0);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_AUTOREFERER, true);
			curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
			curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, array(
					'projectType' => "parent"
			));
			$output = curl_exec($ch);
			curl_close($ch);

			if($output != "success") {
				error_log("Cross Project Piping: Error Enabling Parent:<br />\n".$output);
			}
		}

		if($parentProject && $pipingProject) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_VERBOSE, 0);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_AUTOREFERER, true);
			curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
			curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, array(
					'projectType' => "output"
			));
			$output = curl_exec($ch);
			curl_close($ch);

			if($output != "success") {
				error_log("Cross Project Piping: Error Enabling Output:<br />\n".$output);
			}
		}
	}

	function createOrWipeTestProject($settingName,$projectName) {
		$projectId = $this->getSystemSetting($settingName);

		if($projectId == "") {
			## Create testing project using API
			$apiToken = \Project::apiCreate(USERID,[
					"project_title" => $projectName,
					"record_autonumbering_enabled" => 1,
					"purpose" => 1,
					"purpose_other" => "Module Testing"
			]);

			if(!$apiToken) {
				error_log("Cross Project Piping: Error Generating Parent Project Token");
				return "";
			}

			$sql = "SELECT u.project_id
					FROM redcap_user_rights u
					WHERE u.api_token = '".db_escape($apiToken)."'";

			$q = $this->query($sql);
			$projectId = db_result($q,0,'project_id');

			if($e = db_error()) {
				error_log("Cross Project Piping: Error Enabling: $sql <br /><br />\n\n".var_export($e,true));
			}

			## Error occurred if this is empty
			if(empty($projectId)) {
				return "";
			}
			$this->setSystemSetting($settingName,$projectId);
		}
		else {
			## Wipe data so it can be re-created from scratch
			$table = $this->getDataTable($projectId);
			$sql = "DELETE FROM $table
					WHERE project_id = '".db_escape($projectId)."'";

			$q = $this->query($sql);

			if($e = db_error()) {
				error_log("Cross Project Piping: Error Enabling: $sql <br /><br />\n\n".var_export($e,true));
				return "";
			}

			\REDCap::logEvent("Data deleted from test project when enabling new version","Data Deleted",$sql,null,null,$projectId);
		}

		return $projectId;
	}

	function getDataTable($project_id){
		return method_exists('\REDCap', 'getDataTable') ? \REDCap::getDataTable($project_id) : "redcap_data"; 
	}

	function setTestMetadataAndData($projectId,$metadata,$data) {
		if($projectId != "" && !defined("PROJECT_ID")) {
			global $Proj,$AllProjObjects;

			define("PROJECT_ID",$projectId);

			$this->query("BEGIN");
			$Proj = new \Project($projectId,false);

			## Update Data Dictionary
			$metaDataResults = \MetaData::saveMetadataFlat($metadata);

			## If errors on setting metadata
			if(count($metaDataResults[1]) > 0) {
				error_log("Cross Project Piping: Error Setting Metadata ".var_export($metaDataResults[1],true));
				$this->query("ROLLBACK");
				return;
			}

			## Unset from AllProjObjects to force lookup of newly set metadata
			## Must be done before saving data or saveData will generate errors
			unset($AllProjObjects[$projectId]);

			## Save records for testing
			$eventId = $this->getFirstEventId($projectId);

			foreach($data as $recordData) {
				$result = $this->saveData($projectId,"1",$eventId,$recordData);

				## If save data successful
				if(count($result['errors']) > 0) {
					$this->query("ROLLBACK");
					error_log("Cross Project Piping: Error Enabling: ".var_export($result['errors'],true));
					return;
				}
			}

			$this->query("COMMIT");
		}
	}

	/**
	 * Generates nested array of settings keys. Used for multi-level sub_settings.
	 */
	function getKeysFromConfig($config) {
		foreach($config['sub_settings'] as $subSetting){
			if(!empty($subSetting['sub_settings'])) {
				$keys[] = array('key' => $subSetting['key'], 'sub_settings' => $this->getKeysFromConfig($subSetting));
			} else {
				$keys[] = array('key' => $subSetting['key'], 'sub_settings' => array());
			}
		}
		return $keys;
	}

	/**
	 * Used for processing nested sub_settings while generating settings data array.
	 */
	function processSubSettings($rawSettings, $key, $inc, $depth = 0) {
		$returnArr = array();
		$eachData = $rawSettings[$key['key']]['value'];
		foreach($inc AS $i) {
			$eachData = $eachData[$i];
		}
		foreach($eachData AS $k => $v) {
			foreach($key['sub_settings'] AS $skey => $sval) {
				if(!empty($sval['sub_settings'])) {
					$sinc = $inc;
					$sinc[] = $k;
					$depth++;
					$returnArr[$k][$sval['key']] = $this->processSubSettings($rawSettings, $sval, $sinc, $depth);
					$depth--;
				} else {
					$retData = $rawSettings[$sval['key']]['value'];
					foreach($inc AS $i) {
						$retData = $retData[$i];
					}
					$returnArr[$k][$sval['key']] = $retData[$k];
				}
			}
		}
		return $returnArr;
	} 

	/**
	 * Get full nested settings/sub_settings data.
	 */
	function getPipingSettings($project_id) {
		$keys = [];
		$config = $this->getSettingConfig('pipe-projects');
		$keys = $this->getKeysFromConfig($config);
		$subSettings = [];
		$rawSettings = ExternalModules::getProjectSettingsAsArray([$this->PREFIX], $project_id);
		# Quick-Fix for PHP8 Support
		$subSettingCount = count((array)$rawSettings[$keys[0]['key']]['value']);
		$this->pipingMode = (isset($rawSettings['piping-mode']['value'])) ? $rawSettings['piping-mode']['value'] : 0 ;
		$this->pipeOnStatus = (isset($rawSettings['pipe-on-status']['value'])) ? $rawSettings['pipe-on-status']['value'] : 0 ;
		for($i=0; $i<$subSettingCount; $i++){
			$subSetting = [];
			foreach($keys as $key){
				if(!empty($key['sub_settings'])) {
					$subSetting[$key['key']] = $this->processSubSettings($rawSettings, $key, array($i));
				} else {
					$subSetting[$key['key']] = $rawSettings[$key['key']]['value'][$i];
				}
			}
			$subSettings[] = $subSetting;
		}
		return $subSettings;
	}

	function processRecord($project_id, $record, $instrument, $event_id, $repeat_instance) {
		// Do not run on new records with no record ID
		if(empty($record)) {
			return;
		}
		if(!is_int($repeat_instance)) {
			$repeat_instance = 1;
		}

		// If there are specific forms specified in the config settings then check to make sure we are currently on one of those forms. If not stop piping.
		$rawSettings = ExternalModules::getProjectSettingsAsArray([$this->PREFIX], $project_id);
		if (!empty($rawSettings['active-forms']['value']) && !in_array($instrument, $rawSettings['active-forms']['value'])) {
			if(count($rawSettings['active-forms']['value']) > 1 || !empty($rawSettings['active-forms']['value'][0])) {
				return;
			}
		}

		// If this record is locked let's just stop here, no point in piping on a locked record.
		$escInst = db_real_escape_string($instrument);
		$sql = "SELECT * FROM redcap_locking_data WHERE project_id = {$project_id} AND record = '{$record}' AND event_id = {$event_id} AND form_name = '{$escInst}' AND instance = {$repeat_instance}";
		$results = $this->query($sql);
		$lockData = db_fetch_assoc($results);
		if(!empty($lockData)) {
			return;
		}

		// If this instrument's status is currently HIGHER than 'pipe-on-status' config value then DO NOT pipe data
		$fieldName = db_real_escape_string($instrument).'_complete';
		$table = $this->getDataTable($project_id);
		$sql = "SELECT * FROM $table WHERE project_id = {$project_id} AND record = '{$record}' AND event_id = {$event_id} AND field_name = '{$fieldName}'";
		if($repeat_instance >= 2) {
			$sql .= " AND instance = ".$repeat_instance;
		} else {
			$sql .= " AND instance IS NULL";
		}
		$compResults = $this->query($sql);
		$compData = db_fetch_assoc($compResults);
		if(!empty($compData) && $compData['value'] > $this->pipeOnStatus) {
			return;
		}

		// Looks like we're good to start piping
		$term = '@PROJECTPIPING';
		$matchTerm = '@FIELDMATCH';
		$matchSourceTerm = '@FIELDMATCHSOURCE';

		// If we have module settings lets overwrite $hook_functions with our module settings data
		$useConfigSettings = false;
		if(!empty($this->modSettings) && !empty($this->modSettings[0]['project-id'])) {
			$useConfigSettings = true;
			$hook_functions = array();
			foreach($this->modSettings AS $mdk => $mdv) {
				$projId = $mdv['project-id'];
				$fieldMatch = $mdv['field-match'];
				$fieldMatchSource = $mdv['field-match-source'];
				foreach($mdv['project-fields'] AS $pfk => $pfv) {
					$hook_functions[$term][$pfv['data-destination-field']] = array('params' => '['.$projId.']['.(empty($pfv['data-source-field']) ? $pfv['data-destination-field'] : $pfv['data-source-field']).']');
					$hook_functions[$matchTerm][$pfv['data-destination-field']] = array('params' => $fieldMatch);
					if(!empty($fieldMatchSource)) {
						$hook_functions[$matchSourceTerm][$pfv['data-destination-field']] = array('params' => $fieldMatchSource);
					}
				}
			}
		}

		// See if the term defined in this hook is used on this page
		if (!isset($hook_functions[$term])) {
			hook_log ("Skipping $term on $instrument of $project_id - not used.", "DEBUG");
			return;
		}
		
		$startup_vars = $hook_functions[$term];
		$match = $hook_functions[$matchTerm];
		$matchSource = $hook_functions[$matchSourceTerm];
		$choicesForFields = array();
		$cachedDataDictionaries = array();

		foreach ($startup_vars as $field => $params) {
			$nodes = preg_split("/\]\[/", $params['params']);
			for ($i=0; $i < count($nodes); $i++) {
				$nodes[$i] = preg_replace("/^\[/", "", $nodes[$i]);
				$nodes[$i] = preg_replace("/\]$/", "", $nodes[$i]);
			}
			$otherpid = $nodes[0];

			## Only lookup the DD once per project
			if(!array_key_exists($otherpid,$cachedDataDictionaries)) {
				$cachedDataDictionaries[$otherpid] = \REDCap::getDataDictionary($otherpid, 'array');
			}
			$metadata = $cachedDataDictionaries[$otherpid];

			if (count($nodes) == 2) {
				$fieldName = $nodes[1];
			} else {
				$fieldName = $nodes[2];
			}
			if (preg_match("/\*/", $fieldName)) {
				$fieldRegEx = "/^".preg_replace("/\*/", ".*", $fieldName)."$/";
				$fieldNames = array();
				foreach ($metadata as $field => $values) {
					if (preg_match($fieldRegEx, $field)) {
						$fieldNames[] = $field;
					}
				}
			} else {
				$fieldNames = array($fieldName);
			}
			foreach ($fieldNames as $fieldName) {
				if ($metadata[$fieldName] && $metadata[$fieldName]["select_choices_or_calculations"]) {
					$choices = preg_split("/\|\s*/", $metadata[$fieldName]["select_choices_or_calculations"]);
					$newChoices = array();
					for ($i=0; $i < count($choices); $i++) {
						$choices[$i] = preg_split("/,\s*/", $choices[$i]);
						if (count($choices[$i]) > 2) {
							$j = 0;
							$newChoice = array();
							$newValue = array();
							foreach ($choices as $choice) {
								if ($j === 0) {
									$newChoice[] = $choice;
								} else {
									$newValue[] = $choice;
								}
							}
							$newChoice[] = implode(",", $newValue);
							$choice[$i] = $newChoice;
						}
						$choices[$i][0] = trim($choices[$i][0]);
						$choices[$i][1] = trim($choices[$i][1]);
					}
					$choicesForFields[$fieldName] = array();
					foreach ($choices as $choice) {
						$choicesForFields[$fieldName][$choice[0]] = $choice[1];
					}
				}
			}
		}

		list($prefix, $version) = ExternalModules::getParseModuleDirectoryPrefixAndVersion($this->getModuleDirectoryName());
		$url = ExternalModules::getUrl($prefix, "getValue.php");
		$ajaxLoaderGif = APP_PATH_WEBROOT.'../modules/'.$this->getModuleDirectoryName()."/ajax-loader.gif";
		?>
			<img style="display: none;" src="<?php echo $ajaxLoaderGif; ?>">
			<script type='text/javascript'>
				<?php if($this->pipingMode != 1): ?>
					initiateLoadingOverlay();
				<?php endif; ?>

				$(document).ready(function() {
					<?php if($this->pipingMode == 1 && !$this->hideButton): ?>
						$('#form table#questiontable>tbody').prepend('<tr style="border-top: 1px solid #DDDDDD;"><td style="text-align: center; padding: 6px;" colspan="2"><button id="ccpPipeData">Initiate Data Piping</button></td></tr>');
						$('#ccpPipeData').click(function(evt){
							evt.preventDefault();
							runCrossProjectPiping();
						});
					<?php else: ?>
						runCrossProjectPiping();
					<?php endif; ?>
				});

				function initiateLoadingOverlay() {
					// Create a loading overlay to indicate piping in process
					var jsCppAjaxLoader = document.createElement('div');
					jsCppAjaxLoader.setAttribute("id", "cppAjaxLoader");
					jsCppAjaxLoader.setAttribute("style", "overflow: hidden; text-align: center; position: absolute; top: 0; right: 0; bottom: 0; left: 0; background-color: rgba(255, 255, 255, 0.7); z-index: 9999;");
					jsCppAjaxLoader.innerHTML = '<!-- x -->';
					var centerEl = document.getElementById('center');
					// On REDCap v8.4.2, centerEl was not being set on surveys
					// This fixes that and prevents piping errors on surveys
					if(typeof centerEl == "undefined" || centerEl === null) {
						centerEl = document.getElementById('container');
					}
					centerEl.insertBefore(jsCppAjaxLoader, centerEl.firstChild);
					// END loading overlay
				}

				var branchingFields = {};
				function addBranchingField(field, element) {
					branchingFields[field] = {element: element};
				}

				function branchingPipingFix(fields) {
					$.each(fields, function(field,params) {
						if(field in branchingFields) {
							branchingFields[field].element.change();
							var dblVal = doBranching(field);
						}
					});
				}

				function runCrossProjectPiping() {
					<?php if($this->pipingMode == 1): ?>
						initiateLoadingOverlay();
					<?php endif; ?>

					var fields = <?php print json_encode($startup_vars) ?>;
					var choices = <?= json_encode($choicesForFields) ?>;
					$('#form').addClass('piping-loading');
					$('#center').css('position', 'realative');
					
					<?php if($useConfigSettings): ?>
						// Lets add a little more context to the loading overlay (like text and a loading gif)
						$('#cppAjaxLoader').prepend('<div id="cppAjaxLoaderInner" style="left: 0; right: 0; text-align: center; display: inline-block; position: absolute; top: 200px;"><div style="display: inline-block; padding: 40px; background-color: #fff; border-radius: 3px; font-size: 16px; font-weight: bold; color: #424242; border: 1px solid #757575;">PIPING DATA<br><img src="<?php echo $ajaxLoaderGif; ?>"></div></div>');
						// A little quick math for a nice position
						var cppAjaxLoaderInnerOffset = Math.ceil($(window).scrollTop() + (($(window).height()-$('#cppAjaxLoaderInner').outerHeight()) * 0.5));
						$('#cppAjaxLoaderInner').css('top', cppAjaxLoaderInnerOffset+'px');
					<?php endif; ?>

					const formatDate = (data, dateFormatStr, ) => {
						var dateFormatParams = dateFormatStr.split('_');
						var dFFormatParams = dateFormatParams.slice(-1)[0].split('');
						var dFFormatArr = [];
						for (var i = 0, len = dFFormatParams.length; i < len; i++) {
							dFFormatArr.push(dFFormatParams[i]+dFFormatParams[i]);
						}
						dFFormat = dFFormatArr.join('-');

						const dataParams = data.split(' ')
						const datepickerDate = new Date(dataParams[0])
						datepickerDate.setTime(datepickerDate.getTime()+datepickerDate.getTimezoneOffset()*60000) // Fix any timezone vs. UTC related date shifting

						var newDate = $.datepicker.formatDate(dFFormat, datepickerDate);
						
						if(!newDate.includes('NaN') && data.length >= 1) {
							var dateTimeStr = '';
							if(dateFormatParams[0] == 'datetime') {
								let dateTimeData
								if(dataParams.length === 1){
									dateTimeData = []
								}
								else{
									dateTimeData = dataParams[1].split(':')
								}

								while(dateTimeData.length < 3){
									dateTimeData.push('00')
								}

								var dateTimeVal = dateTimeData[0]+':'+dateTimeData[1];
								if(dateFormatParams.length > 2) {
									var dateTimeVal = dateTimeVal+':'+dateTimeData[2];
								}
								if(dateTimeVal.length) {
									dateTimeStr = ' '+dateTimeVal;
								}
							}

							return newDate + dateTimeStr
						}

						return false
					}

					// Only run unit tests when on a dev/test server
					if(<?=json_encode(($GLOBALS['is_development_server']) === '1')?>){
						const tests = [
							{
								input: '2001-02-03',
								outputs: {
									date: {
										dmy: '03-02-2001',
										mdy: '02-03-2001',
										ymd: '2001-02-03'
									},
									time: '00:00',
									seconds: '00'
								}
							},
							{
								input: '2011-02-03 04:05',
								outputs: {
									date: {
										dmy: '03-02-2011',
										mdy: '02-03-2011',
										ymd: '2011-02-03'
									},
									time: '04:05',
									seconds: '00'
								}
							},
							{
								input: '2021-02-03 06:07:08',
								outputs: {
									date: {
										dmy: '03-02-2021',
										mdy: '02-03-2021',
										ymd: '2021-02-03'
									},
									time: '06:07',
									seconds: '08'
								}
							},
							{
								/**
								 * Make sure timezone adjustment does not shift times later in the day
								 * over into the next day
								 */
								input: '2022-11-13 19:34',
								outputs: {
									date: {
										dmy: '13-11-2022',
										mdy: '11-13-2022',
										ymd: '2022-11-13'
									},
									time: '19:34',
									seconds: '00'
								}
							}
						]

						let testOutput = ''
						tests.forEach(test => {
							[
								'date_dmy',
								'date_mdy',
								'date_ymd',
								'datetime_dmy',
								'datetime_mdy',
								'datetime_ymd',
								'datetime_seconds_dmy',
								'datetime_seconds_mdy',
								'datetime_seconds_ymd'
							].forEach(format => {
								const actualOutput = formatDate(test.input, format)
								const parts = format.split('_')
								const dateFormat = parts.pop()
								
								let expectedOutput = test.outputs.date[dateFormat]
								if(parts[0] === 'datetime'){
									expectedOutput += ' ' + test.outputs.time
								}

								if(parts.length === 2){
									expectedOutput += ':' + test.outputs.seconds
								}

								if(actualOutput !== expectedOutput){
									testOutput += 'The formateDate() function returned "' + actualOutput + '" instead of "' + expectedOutput + '" for input "' + test.input + '" and format "' + format + '"!<br>'
								}
							})
						})

						if(testOutput.length > 0){
							simpleDialog(testOutput, 'Cross Project Piping Module - Test Failures', null, 1100)
						}
					}

					var cppAjaxConnections = 0;
					var cppFoundField = false;
					var cppProcessing = true;
//console.log(fields);
					$.each(fields, function(field,params) {
						var value = params.params;
						var nodes = value.split(/\]\[/);
						for (var i=0; i < nodes.length; i++) {
							nodes[i] = nodes[i].replace(/^\[/, "");
							nodes[i] = nodes[i].replace(/\]$/, "");
						}
						if ((nodes[0].match(/^\d+$/)) && (nodes.length >= 2)) {
							var remaining;
							if (nodes.length == 2) {
								remaining = "[" + nodes[1] + "]";
							} else {
                                remaining = "[" + nodes[1] + "][" + nodes[2] + "]";
							}

							var match = <?= json_encode($match) ?>;
							var matchSource = <?= json_encode($matchSource) ?>;

							var matchSourceParam = null
							if(matchSource && matchSource[field]){
								matchSourceParam = matchSource[field]['params'];
							}

							var url = "<?= $url ?>"+"&pid="+<?= intval($_GET['pid']) ?>;
							var getLabel = 0;
							if (($('[name="'+field+'"]').attr("type") == "text") || ($('[name="'+field+'"]').attr("type") == "notes")) {
								getLabel = 1;
							}
							
							if(field.length && $('tr[sq_id='+field+']').length) {
								cppFoundField = true;
								cppAjaxConnections++;
								var ajaxCountLimit = 0;
								// console.log('++cppAjaxConnections = '+cppAjaxConnections);
								//console.log(url);
								$.post(url, { thisrecord: '<?= htmlspecialchars($_GET['id'], ENT_QUOTES) ?>', thispid: <?= intval($_GET['pid']) ?>, thisinstance: <?= intval($repeat_instance) ?>, thismatch: match[field]['params'], matchsource: matchSourceParam, getlabel: getLabel, otherpid: nodes[0], otherlogic: remaining, choices: JSON.stringify(choices) }, function(data) {
                                    //console.log(data);
									if(data.length && typeof(data) == 'string' && data.indexOf(<?=json_encode(\RCView::tt("dataqueries_352"))?>) >= 0) {
										if(ajaxCountLimit >= 1000) {
											return;
										}
										// console.log('Trying '+field+' again.');
										ajaxCountLimit++;
										$.ajax(this);

										return;
									}
									
									cppAjaxConnections--;
									var lastNode = nodes[1];
									if (nodes.length > 2) {
										lastNode = nodes[2];
									}

									var tr = $('tr[sq_id='+field+']');
									var id = lastNode.match(/\([^\s]+\)/);
									 //console.log("Setting "+field+" to "+data);
									if (typeof(data) == 'object') {    // checkbox
										$.each(data, function( index, value ) {
											var input = $('input:checkbox[code="'+index+'"]', tr);
											if ($(input).length > 0) {
												if (value == 1) {
													$(input).prop('checked', true);
													$('input:hidden[name="__chk__'+field+'_RC_'+index+'"]').val(index);
												} else {
													$(input).prop('checked', false);
													$('input:hidden[name="__chk__'+field+'_RC_'+index+'"]').val('');
												}
												addBranchingField(field, input);
												// $(input).change();
											}
										});
									} else if (id) {    // checkbox
										id = id[0].replace(/^\(/, "");
										id = id.replace(/\)$/, "");
										var input = $('input:checkbox[code="'+id+'"]', tr);
										if ($(input).length > 0) {
											if (data == 1) {
												// console.log("A Setting "+field+" to "+data);
												$(input).prop('checked', true);
											} else {    // data == 0
												// console.log("B Setting "+field+" to "+data);
												$(input).prop('checked', false);
											}
											addBranchingField(field, input);
											// $(input).change();
										} else {
											// console.log("C Setting "+field+" to "+data);
											$('[name="'+field+'"]').val(data);
											addBranchingField(field, $('[name="'+field+'"]'));
											// $('[name="'+field+'"]').change();
										}

									} else {
										// Is this a date field? If so we need to format this date correctly.
										if($('[name="'+field+'"]').hasClass('hasDatepicker') || (typeof $('[name="'+field+'"]').attr('fv') !== 'undefined' && $('[name="'+field+'"]').attr('fv').includes('date_'))) {
											var dateFormatStr = $('[name="'+field+'"]').attr('fv');
											const newDateString = formatDate(data, dateFormatStr)
											if(newDateString){
												$('[name="'+field+'"]').val(newDateString);
												addBranchingField(field, $('[name="'+field+'"]'));
												// $('[name="'+field+'"]').change();
											}
										} else {
											const unescapedData = data
												.replace(/&amp;/g, "&")
												.replace(/&lt;/g, "<")
												.replace(/&gt;/g, ">")
												.replace(/&quot;/g, "\"")
												.replace(/&#039;/g, "'")
											$('[name="'+field+'"]').val(unescapedData);
											addBranchingField(field, $('[name="'+field+'"]'));
											// $('[name="'+field+'"]').change();
										}

										 //console.log("D Setting "+field+" to "+$('[name="'+field+'"]').val());
										if ($('[name="'+field+'___radio"][value="'+data+'"]').length > 0) {
											$('[name="'+field+'___radio"][value="'+data+'"]').prop('checked', true);
											addBranchingField(field, $('[name="'+field+'___radio"][value="'+data+'"]'));
											// $('[name="'+field+'___radio"][value="'+data+'"]').change();
										}
									}
									if(cppAjaxConnections == 0) {
										// Looks like all the ajax requests might be finished. Run a couple checks and then remove loading overlay.
										if(cppProcessing) {
											cppProcessing = false;
											setTimeout(function(){
												if(cppAjaxConnections == 0 && cppProcessing == false) {
													$('#form').removeClass('piping-loading');
													$('#form').addClass('piping-complete');
													$('#cppAjaxLoader').remove();
													branchingPipingFix(fields);
												} else {
													cppProcessing == true;
												}
											}, 50);
										}
									} else if(cppProcessing == false) {
										cppProcessing = true;
									}
								});
							}
						}
					});

					if(cppFoundField == false) {
						// Looks like we never found a field. Remove loading overlay.
						cppProcessing = false;
						$('#form').removeClass('piping-loading');
						$('#form').addClass('piping-complete');
						$('#cppAjaxLoader').remove();
						branchingPipingFix(fields);
					}
				}
			</script>
		<?php
	}

	function validateSettings($settings){
		error_reporting(E_ALL);
		if(!SUPER_USER){
			if(defined('USERID')) {
				$userID = USERID;
			} else {
				return "No User ID Defined!";
			}
			$projectIds = $settings['project-id'];
			foreach($projectIds AS $proj_id) {
				if(!empty($proj_id) && $proj_id != 'null') {
					$rights = \UserRights::getPrivileges($proj_id, $userID);
					if(empty($rights) || $rights[$proj_id][$userID]['design'] != 1){
						return "You must have design rights for every source project in order to save this module's settings.";
					}
				}
			}
		}

		return parent::validateSettings($settings);
	}
	
	// the functions below are used by the Pipe All Records button (only)
	
	function getProjects() {
		// prepare array that will be returned
		$projects = [
			'destination' => [],
			'source' => [],
		];
		
		global $Proj;
		$projects['destination']['project_id'] = $this->getProjectId();
		
		$project_ids = $this->getProjectSetting('project-id');
		$dest_match_fields = $this->getProjectSetting('field-match');
		$source_match_fields = $this->getProjectSetting('field-match-source');
		$dest_fields = $this->getProjectSetting('data-destination-field');
		$source_fields = $this->getProjectSetting('data-source-field');
		
		// fill $projects['source'] array with source project info arrays
		foreach ($project_ids as $project_index => $pid) {
			$source_project = [
				'project_id' => $pid,
				'source_match_field' => $source_match_fields[$project_index],
				'dest_match_field' => $dest_match_fields[$project_index],
				'dest_fields' => $dest_fields[$project_index],
				'source_fields' => $source_fields[$project_index],
				'dest_forms_by_field_name' => []
			];
			
			// where source data/match fields are empty, use destination match/data field names
			if (empty($source_project['source_match_field'])) {
				$source_project['source_match_field'] = $source_project['dest_match_field'];
			}
			foreach ($source_project['source_fields'] as $list_index => $field_name) {
				// set to destination name if no alternate name used for source project
				$matching_destination_field_name = $source_project['dest_fields'][$list_index];
				if (empty($field_name)) {
					$source_project['source_fields'][$list_index] = $matching_destination_field_name;
				}
				
				// add an entry to dest_forms_by_field_name for this source field
				$actual_field_name = $source_project['dest_fields'][$list_index];
				$source_project['dest_forms_by_field_name'][$actual_field_name] = $Proj->metadata[$matching_destination_field_name]['form_name'];
			}
			
			// add event id/name pairs
			$source_project['events'] = [];
			$project_obj = new \Project($pid);
			foreach ($project_obj->events[1]['events'] as $event_id => $event_array) {
				$source_project['events'][$event_id] = $event_array['descrip'];
			}
			
			// add 'valid_match_events' array to this source project -- this will contain the event_id values associated with each form that contains the destinatiion match field
			$valid_match_event_ids = [];
			$dest_match_field_form = $Proj->metadata[$source_project['dest_match_field']]['form_name'] ?? null;
			foreach ($Proj->eventsForms as $eid => $formlist) {
				if (in_array($dest_match_field_form, $formlist) !== false) {
					$dst_event_name = $Proj->eventInfo[$eid]['name_ext'];
					if (!empty($dst_event_name)) {
						foreach ($project_obj->eventInfo as $eid2 => $info) {
							if ($info['name_ext'] === $dst_event_name)
								$src_eid = $eid2;
						}
						if (!empty($src_eid)) {
							$valid_match_event_ids[] = $src_eid;
						}
					}
				}
			}
			$source_project['valid_match_event_ids'] = $valid_match_event_ids;
			
			// store project info array in projects['source'] array
			$projects['source'][] = $source_project;
			unset($project_obj);
		}
		
		// for destination project, prepare list of forms to limit piping to
		// and remember which form statuses are ok to pipe on (incomplete, complete, etc)
		$active_forms = $this->getProjectSetting('active-forms');
		if (!empty($active_forms)) {
			$projects['destination']['active_forms'] = $active_forms;
		}
		$projects['destination']['pipe_on_status'] = $this->getProjectSetting('pipe-on-status');
		
		// add event id/names to destination project from global Project instance ($Proj is the destination/host project)
		foreach($Proj->events as $arm_number => $arm_details) {
			foreach ($arm_details['events'] as $event_id => $event_array) {
				$projects['destination']['events'][$event_id] = $event_array['descrip'];
				$projects['destination']['event_details'][$event_id] = [
					"arm"=>$arm_number,
					"name"=>$event_array['descrip'],
					"unique_name"=>strtolower(str_replace(" ", "_", $event_array['descrip']) . "_arm_$arm_number")
				];
			}
		}
		return $projects;
	}

	function getFormStatusAllRecords($active_forms) {
		/* for the forms given in the array above, return an array structured like
		$form_status_all_records = [
			// record id
			[1] => [
				// event id
				[393] => [
					"record_id" => 3,
					"my_form_1" => 0	// designates incomplete (raw value from [my_form_1_complete] of destination project)
					"my_form_2" => 2	// raw value 'Complete'
					...
				],
				...
			],
			...
		]
		*/
		if (empty($active_forms)) {
			global $Proj;
			$active_forms = array_keys($Proj->forms);
		}
		
		$fields = [];
		foreach($active_forms as $form_name) {
			$fields[] = $form_name . "_complete";
		}
		$data = \REDCap::getData('array', null, $fields);
		
		return $data;
	}
	
	function getSourceProjectsData() {
		if (gettype($this->projects['source']) == 'Array') {
			throw new \Exception("The Cross Project Piping module expected \$module->projects['source'] to be an array before calling pipeToRecord()");
		}
		
		// fetch pipe and match data for all records in each source project
		foreach ($this->projects['source'] as $project_index => $project) {
			$project_id = $project['project_id'];
			
			$match_field = $project['source_match_field'];
			$fields = $project['source_fields'];
			if (!in_array($match_field, $fields)) {
				$fields[] = $match_field;
			}
			
			$params = [
				'project_id' => $project_id,
				'return_format' => 'array',
				'fields' => $fields,
				'filterLogic' => "[$match_field] <> ''"
			];

			$this->projects['source'][$project_index]['source_data'] = \REDCap::getData($params);
		}
	}
	
	function getDestinationProjectData() {
		if (gettype($this->projects) == 'Array') {
			throw new \Exception("The Cross Project Piping module expected \$module->projects to be an array before calling pipeToRecord()");
		}
		
		// get all destination match field names
		$match_field_names = [];
		foreach ($this->projects['source'] as $project_index => $project) {
			$match_field_names[] = $project['dest_match_field'];
		}
		$match_field_names = array_unique($match_field_names);
		
		$params = [
			'project_id' => $this->projects['destination']['project_id'],
			'return_format' => 'array',
			'fields' => $match_field_names
		];
		$data = \REDCap::getData($params);
		
		// extract match field info from event arrays
		foreach($data as $rid => $events) {
			$match_info = [];
			foreach ($events as $eid => $recdata) {
				foreach($recdata as $field => $value) {
					$match_info["$field"] = $value;
				}
			}
			$data[$rid] = $match_info;
		}
		
		$this->projects['destination']['records_match_fields'] = $data;
	}
	
	function pipeToRecord($dst_rid) {
		if (gettype($this->projects) == 'Array') {
			throw new \Exception("The Cross Project Piping module expected \$module->projects to be an array before calling pipeToRecord()");
		}

		// return early if this record has all empty destination match field values
		$record_match_info = $this->projects['destination']['records_match_fields'][$dst_rid];
		if (empty($record_match_info)) {
			return;
		}

		// create the arrays that we'll eventually give to REDCap::saveData
		$data_to_save = [
			"$dst_rid" => []
		];
		$data_repeatable_to_save = [];
        $returnarray = $resultData = array();
        
		// for every source project:
		foreach ($this->projects['source'] as $p_index => $src_project) {
			// get the destination match field name
			$dest_match_field = $src_project['dest_match_field'];

			// is the source match field in the set of piped fields?
			$src_match_field = $src_project['source_match_field'];
			$source_match_field_is_in_pipe_fields = in_array($src_match_field, $src_project['source_fields'], true) !== false;

            //$dataToSave = $this->transferRecordData($src_project['source_data']);
			// copy pipe values from source records whose match field value matches
			foreach ($src_project['source_data'] as $src_rid => $src_rec) {
				// iterate over each event in the source record, add/overwite data for pipe fields along the way
				foreach ($src_rec as $eid => $field_data) {
                    if ($eid == "repeat_instances") {
                        foreach ($field_data as $ieid => $formData) {
                            foreach ($formData as $formName => $instanceData) {
                                foreach ($instanceData as $iNum => $subData) {
                                    if ($subData[$src_match_field] != $record_match_info[$dest_match_field]) continue;
                                    $resultData = $this->processDataTransfer($resultData,$dst_rid,$src_project,$ieid,$subData,$src_match_field,$dest_match_field,$source_match_field_is_in_pipe_fields,$iNum);
                                }
                            }
                        }
                    }
                    else {
                        if ($field_data[$src_match_field] != $record_match_info[$dest_match_field]) continue;
                        $resultData = $this->processDataTransfer($resultData,$dst_rid,$src_project,$eid,$field_data,$src_match_field,$dest_match_field,$source_match_field_is_in_pipe_fields);
                    }
				}
			}
		}

		if (!empty($resultData[$dst_rid])) {
			$result = \REDCap::saveData('array', $resultData);
			//return $result;
            $returnarray['data-push'] = $result;
            $returnarray['data-list'][$dst_rid] = $resultData;
		}

        return $returnarray;
	}

    function processDataTransfer($currentData,$dst_rid,$src_project,$eid,$field_data,$src_match_field,$dest_match_field,$source_match_field_is_in_pipe_fields,$repeat_instance = "") {
        // if this eid corresponds to a destination project event.. copy data to save to destination record
        $src_event_name = $src_project['events'][$eid];
        $dst_event_id = array_search($src_event_name, $this->projects['destination']['events'], true);
        // skip this event if a matching event name wasn't found in the destination project
        if ($dst_event_id === false) {
            return $currentData;
        }

        // skip this event if the event_id isn't valid (eid is only valid if it has the same name as the name of the event contains the form that contains the destination match field)
        # Quick-Fix for PHP8 Support
        if (in_array($eid, (array) $src_project['valid_match_event_ids']) === false) {
            return $currentData;
        }

        // create an instance of destination project
        $destProj = new \Project($this->projects['destination']['projectId']);

        foreach ($field_data as $field_name => $field_value) {
            // skip this field if it's the match field and match field isn't in the set of fields to be piped
            if (
                $field_name == $src_match_field
                &&
                !$source_match_field_is_in_pipe_fields
            ) {
                continue;
            }

            // get the destination project's name for this source pipe field
            $pipe_field_index = array_search($field_name, $src_project['source_fields'], true);
            $dst_name = $src_project['dest_fields'][$pipe_field_index];

            // skip this field if the destination record's form status for the containing form is above the pipe limit
            $form_name = $src_project['dest_forms_by_field_name'][$dst_name];

            if (intval($this->formStatuses[$dst_rid][$dst_event_id][$form_name . '_complete']) > $this->pipe_on_status) {
                continue;
            }
            // skip if this field isn't in an 'active' form
            if (!empty($this->active_forms) && !in_array($form_name, $this->active_forms)) {
                continue;
            }

            if (!empty($dst_name)) {
                $currentData = $this->updateDestinationData($currentData,$destProj,$dst_name,$field_value,$dst_rid,$dst_event_id,$repeat_instance);
            }
        }

        return $currentData;
    }

    function updateDestinationData($destData,\Project $destProject, $destFieldName, $srcFieldValue, $destRecord, $destEvent,$destRepeat = 1) {
        $destMeta = $destProject->metadata;
        $destEventForms = $destProject->eventsForms[$destEvent];

        $destInstrument = $destMeta[$destFieldName]['form_name'];
        $destRecordField = $destProject->table_pk;
        $destInstrumentRepeats = $destProject->isRepeatingForm($destEvent, $destInstrument);
        $destEventRepeats = $destProject->isRepeatingEvent($destEvent);

        if (in_array($destInstrument,$destEventForms)) {
            if ($destInstrumentRepeats) {
                $destData[$destRecord][$destEvent][$destRecordField] = $destRecord;
                //$destData[$destRecord][$destEvent]['redcap_repeat_instrument'] = "";
                //$destData[$destRecord][$destEvent]['redcap_repeat_instance'] = $destRepeat;
                $destData[$destRecord]['repeat_instances'][$destEvent][$destInstrument][$destRepeat][$destFieldName] = $srcFieldValue;
            } elseif ($destEventRepeats) {
                $destData[$destRecord][$destEvent][$destRecordField] = $destRecord;
                //$destData[$destRecord][$destEvent]['redcap_repeat_instrument'] = "";
                //$destData[$destRecord][$destEvent]['redcap_repeat_instance'] = $destRepeat;
                $destData[$destRecord]['repeat_instances'][$destEvent][''][$destRepeat][$destFieldName] = $srcFieldValue;
            } else {
                $destData[$destRecord][$destEvent][$destFieldName] = $srcFieldValue;
            }
        }
        return $destData;
    }
	
	function getProjectRecordIDs($project_id, $filter_logic = null) {
		$rid_field_name = \REDCap::getRecordIdField($project_id);
		$params = [
			"project_id" => $project_id,
			"return_format" => "array",
			"fields" => $rid_field_name
		];
		
		if (!empty($filter_logic)) {
			$params['filterLogic'] = $filter_logic;
		}
		
		$data = \REDCap::getData($params);
		$record_ids = array_keys($data);
		return $record_ids;
	}
	
	// The following method can be replaced by $user->getRights() in framework version 2.
	/*private function getRights($project_ids){
		if($project_ids === null){
			$project_ids = $this->framework->requireProjectId();
		}

		if(!is_array($project_ids)){
			$project_ids = [$project_ids];
		}

		$rightsByPid = [];
		foreach($project_ids as $project_id){
			$rights = \UserRights::getPrivileges($project_id, USERID);
			$rightsByPid[$project_id] = $rights[$project_id][USERID];
		}

		return $rightsByPid;
	}*/

	/**
	 * Copied from the EM framework.  Can be removed once redcap-version-min can be set to a version that includes this method.
	 */
	function escape($value){
		$type = gettype($value);

		/**
		 * The unnecessary casting on these first few types exists solely to inform psalm and avoid warnings.
		 */
		if($type === 'boolean'){
			return (bool) $value;
		}
		else if($type === 'integer'){
			return (int) $value;
		}
		else if($type === 'double'){
			return (float) $value;
		}
		else if($type === 'array'){
			$newValue = [];
			foreach($value as $key=>$subValue){
				$key = $this->escape($key);
				$subValue = $this->escape($subValue);
				$newValue[$key] = $subValue;
			}

			return $newValue;
		}
		else if($type === 'NULL'){
			return null;
		}
		else{
			/**
			* Handle strings, resources, and custom objects (via the __toString() method. 
			* Apart from escaping, this produces that same behavior as if the $value was echoed or appended via the "." operator.
			*/
			return htmlspecialchars(''.$value, ENT_QUOTES);
		}
	}
}
