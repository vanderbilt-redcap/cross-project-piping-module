<?php
namespace Vanderbilt\CrossprojectpipingExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

require_once dirname(__FILE__) . '/hooks_common.php';
require_once dirname(__FILE__) . '/init_hook_functions.php';

class CrossprojectpipingExternalModule extends AbstractExternalModule
{
	function redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
		$this->processRecord($project_id, $record, $instrument, $event_id, $repeat_instance);
	}

	function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
		$this->processRecord($project_id, $record, $instrument, $event_id, $repeat_instance);
	}

	/**
	 * Nicely formatted var_export for checking output .
	 */
	function pDump($value, $die = false) {
		highlight_string("<?php\n\$data =\n" . var_export($value, true) . ";\n?>");
		echo '<hr>';
		if($die) {
			die();
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

		// If this record is currently marked 'Complete' do not pipe data
		$fieldName = db_real_escape_string($instrument).'_complete';
		$sql = "SELECT * FROM redcap_data WHERE project_id = {$project_id} AND record = '{$record}' AND event_id = {$event_id} AND field_name = '{$fieldName}'";
		if($repeat_instance >= 2) {
			$sql .= " AND instance = ".$repeat_instance;
		} else {
			$sql .= " AND instance IS NULL";
		}
		$compResults = $this->query($sql);
		$compData = db_fetch_assoc($compResults);
		if(!empty($compData) && $compData['value'] == 2) {
			return;
		}

		// Looks like we're good to start piping
		$term = '@PROJECTPIPING';
		$matchTerm = '@FIELDMATCH';
		$matchSourceTerm = '@FIELDMATCHSOURCE';

		$module_data = $this->getPipingSettings($project_id);

		$settingTerm = ExternalModules::getProjectSetting("vanderbilt_crossplatformpiping", $project_id, "term");
		if ($settingTerm != "") {
			if ($settingTerm[0] == "@") {
				$term = $settingTerm;
			} else {
				$term = "@".$settingTerm;
			}
		}

		$settingMatchTerm = ExternalModules::getProjectSetting("vanderbilt_crossplatformpiping", $project_id, "match-term");

		hook_log("Starting $term and $matchTerm for project $project_id", "DEBUG");
		
		///////////////////////////////
		//	Enable hook_functions and hook_fields for this plugin (if not already done)
		if (!isset($hook_functions)) {
			$file = dirname(__FILE__).'/init_hook_functions.php';
			if (file_exists($file)) {
				include_once $file;

				$hook_functions = getHookFunctions($project_id);

				// Verify it has been loaded
				if (!isset($hook_functions)) { hook_log("ERROR: Unable to load required init_hook_functions."); return; }
			} else {
				hook_log ("ERROR: In Hooks - unable to include required file $file while in " . __FILE__);
			}
		}
		
		//////////////////////////////

		// If we have module settings lets overwrite $hook_functions with our module settings data
		$useConfigSettings = false;
		if(!empty($module_data) && !empty($module_data[0]['project-id'])) {
			$useConfigSettings = true;
			$hook_functions = array();
			foreach($module_data AS $mdk => $mdv) {
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
		foreach ($startup_vars as $field => $params) {
			$nodes = preg_split("/\]\[/", $params['params']);
			for ($i=0; $i < count($nodes); $i++) {
				$nodes[$i] = preg_replace("/^\[/", "", $nodes[$i]);
				$nodes[$i] = preg_replace("/\]$/", "", $nodes[$i]);
			}
			$otherpid = $nodes[0];
			$metadata = \REDCap::getDataDictionary($otherpid, 'array');
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
				<?php if($useConfigSettings): ?>
					// Create a loading overlay to indicate piping in process
					var jsCppAjaxLoader = document.createElement('div');
					jsCppAjaxLoader.setAttribute("id", "cppAjaxLoader");
					jsCppAjaxLoader.setAttribute("style", "overflow: hidden; text-align: center; position: absolute; top: 0; right: 0; bottom: 0; left: 0; background-color: rgba(255, 255, 255, 0.7); z-index: 9999;");
					jsCppAjaxLoader.innerHTML = '<!-- x -->';
					var centerEl = document.getElementById('center');
					centerEl.insertBefore(jsCppAjaxLoader, centerEl.firstChild);
					// END loading overlay
				<?php endif; ?>

				window.onload = function() {
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
								$.post(url, { thisrecord: '<?= $_GET['id'] ?>', thispid: <?= $_GET['pid'] ?>, thismatch: match[field]['params'], matchsource: matchSourceParam, getlabel: getLabel, otherpid: nodes[0], otherlogic: remaining, choices: JSON.stringify(choices) }, function(data) {
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
												$(input).change();
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
											$(input).change();
										} else {
											// console.log("C Setting "+field+" to "+data);
											$('[name="'+field+'"]').val(data);
											$('[name="'+field+'"]').change();
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
												$('[name="'+field+'"]').change();
											}
										} else {
											$('[name="'+field+'"]').val(data);
											$('[name="'+field+'"]').change();
										}

										// console.log("D Setting "+field+" to "+$('[name="'+field+'"]').val());
										if ($('[name="'+field+'___radio"][value="'+data+'"]').length > 0) {
											$('[name="'+field+'___radio"][value="'+data+'"]').prop('checked', true);
											$('[name="'+field+'___radio"][value="'+data+'"]').change();
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
													branchingPipingFix();
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
						branchingPipingFix();
					}
					function branchingPipingFix() {
						$.each(fields, function(field,params) {
							var dblVal = doBranching(field);
						});
					}
				}
			</script>
		<?php
	}
}
