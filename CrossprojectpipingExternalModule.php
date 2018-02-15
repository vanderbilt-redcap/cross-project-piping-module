<?php
namespace Vanderbilt\CrossprojectpipingExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

require_once dirname(__FILE__) . '/hooks_common.php';
require_once dirname(__FILE__) . '/init_hook_functions.php';

class CrossprojectpipingExternalModule extends AbstractExternalModule
{
	function hook_data_entry_form_top($project_id, $record) {
		$this->processRecord($project_id, $record);
	}

	function hook_survey_page_top($project_id, $record) {
		$this->processRecord($project_id, $record);
	}

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

	function processRecord($project_id, $record) {
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
		
		// See if the term defined in this hook is used on this page
		if (!isset($hook_functions[$term])) {
			hook_log ("Skipping $term on $instrument of $project_id - not used.", "DEBUG");
			return;
		}
		//////////////////////////////

		if(!empty($module_data)) {
			$hook_functions = array();
			foreach($module_data AS $mdk => $mdv) {
				$projId = $mdv['project-id'];
				$fieldMatch = $mdv['field-match'];
				$fieldMatchSource = $mdv['field-match-source'];
				foreach($mdv['project-fields'] AS $pfk => $pfv) {
					$hook_functions[$term][$pfv['data-destination-field']] = array('params' => '['.$projId.']['.(empty($pfv['data-source-field']) ? $pfv['data-destination-field'] : $pfv['data-source-field']).']');
					$hook_functions[$matchTerm][$pfv['data-destination-field']] = array('params' => $fieldMatch);
					if(!empty($fieldMatchSource)) {
						$hook_functions[$matchSourceTerm][$pfv['data-destination-field']] = $fieldMatchSource;
					}
				}
			}
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
		?>
			<script type='text/javascript'>
				window.onload = function() {
					var fields = <?php print json_encode($startup_vars) ?>;
					var choices = <?= json_encode($choicesForFields) ?>;
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
							$.post(url, { thisrecord: '<?= $_GET['id'] ?>', thispid: <?= $_GET['pid'] ?>, thismatch: match[field]['params'], matchsource: matchSourceParam, getlabel: getLabel, otherpid: nodes[0], otherlogic: remaining, choices: JSON.stringify(choices) }, function(data) {
								var lastNode = nodes[1];
								if (nodes.length > 2) {
									lastNode = nodes[2];
								}

								var tr = $('tr[sq_id='+field+']');
								var id = lastNode.match(/\([^\s]+\)/);
								console.log("Setting "+field+" to "+data);
								if (id) {    // checkbox
									id = id[0].replace(/^\(/, "");
									id = id.replace(/\)$/, "");
									var input = $('input:checkbox[code="'+id+'"]', tr);
									if ($(input).length > 0) {
										if (data == 1) {
											console.log("A Setting "+field+" to "+data);
											$(input).prop('checked', true);
										} else {    // data == 0
											console.log("B Setting "+field+" to "+data);
											$(input).prop('checked', false);
										}
									} else {
										console.log("C Setting "+field+" to "+data);
										$('[name="'+field+'"]').val(data);
									}
								} else {
									$('[name="'+field+'"]').val(data);
									console.log("D Setting "+field+" to "+$('[name="'+field+'"]').val());
									if ($('[name="'+field+'___radio"][value="'+data+'"]').length > 0) {
										$('[name="'+field+'___radio"][value="'+data+'"]').prop('checked', true);
									}
								}
							});
						}
					});
				}
			</script>
		<?php
	}
}
