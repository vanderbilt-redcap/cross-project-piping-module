<?php
namespace Vanderbilt\CrossprojectpipingExternalModuleRI;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

require_once dirname(__FILE__) . '/hooks_common.php';

class CrossprojectpipingExternalModuleRI extends AbstractExternalModule
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
	
	function redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
		$this->processRecord($project_id, $record, $instrument, $event_id, $repeat_instance);
	}

	function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
		if ($this->getProjectSetting('allow-piping-on-survey-pages')) {
			$this->hideButton = true;
			$this->processRecord($project_id, $record, $instrument, $event_id, $repeat_instance);
		}
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
			$sql = "DELETE FROM redcap_data
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
		$subSettingCount = count($rawSettings[$keys[0]['key']]['value']);
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
		$sql = "SELECT * FROM redcap_data WHERE project_id = {$project_id} AND record = '{$record}' AND event_id = {$event_id} AND field_name = '{$fieldName}'";
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

					var cppAjaxConnections = 0;
					var cppFoundField = false;
					var cppProcessing = true;
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

							var url = "<?= $url ?>"+"&pid="+<?= $_GET['pid'] ?>;
							var getLabel = 0;
							if (($('[name="'+field+'"]').attr("type") == "text") || ($('[name="'+field+'"]').attr("type") == "notes")) {
								getLabel = 1;
							}
							
							if(field.length && $('tr[sq_id='+field+']').length) {
								cppFoundField = true;
								cppAjaxConnections++;
								var ajaxCountLimit = 0;
								// console.log('++cppAjaxConnections = '+cppAjaxConnections);
								$.post(url, {
									thisrecord: '<?= $_GET['id'] ?>',
									thispid: <?= $_GET['pid'] ?>,
									thismatch: match[field]['params'],
									matchsource: matchSourceParam,
									getlabel: getLabel,
									otherpid: nodes[0],
									otherlogic: remaining,
									choices: JSON.stringify(choices),
									thisinstance: '<?= $_GET['instance'] ?>'
									},
									function(data) {
									if(data.length && typeof(data) == 'string' && data.indexOf('multiple browser tabs of the same REDCap page. If that is not the case') >= 0) {
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
									// console.log("Setting "+field+" to "+data);
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
											var dateFormatParams = dateFormatStr.split('_');
											var dFFKey = 1;
											var dFFormatParams = dateFormatParams.slice(-1)[0].split('');
											var dFFormatArr = [];
											for (var i = 0, len = dFFormatParams.length; i < len; i++) {
												dFFormatArr.push(dFFormatParams[i]+dFFormatParams[i]);
											}
											dFFormat = dFFormatArr.join('-');

											var newDate = $.datepicker.formatDate(dFFormat, new Date(data.replace(/\-/g,' ')));
											
											if(!newDate.includes('NaN') && data.length >= 1) {
												var dateTimeStr = '';
												var dateTimeData = [];
												if(dateFormatParams[0] == 'datetime') {
													dataParams = data.split(' ');
													data = dataParams[0];
													
													if(dataParams.length <= 1 || dataParams[1].length < 3) {
														dateTimeData = ['00', '00', '00'];
													} else {
														dateTimeData = dataParams[1].split(':');
													}
													var dateTimeVal = dateTimeData[0]+':'+dateTimeData[1];
													if(dateFormatParams.length > 2) {
														var dateTimeVal = dateTimeVal+':'+dateTimeData[2];
													}
													if(dateTimeVal.length) {
														dateTimeStr = ' '+dateTimeVal;
													}
													
												}

												$('[name="'+field+'"]').val(newDate + dateTimeStr);
												addBranchingField(field, $('[name="'+field+'"]'));
												// $('[name="'+field+'"]').change();
											}
										} else {
											$('[name="'+field+'"]').val(data);
											addBranchingField(field, $('[name="'+field+'"]'));
											// $('[name="'+field+'"]').change();
										}

										// console.log("D Setting "+field+" to "+$('[name="'+field+'"]').val());
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
}
