<?php
namespace ExternalModules;

require_once dirname(__FILE__) . '/../../external_modules/classes/ExternalModules.php';
require_once dirname(__FILE__) . '/hooks_common.php';
require_once dirname(__FILE__) . '/init_hook_functions.php';

class CrossprojectpipingExternalModule extends AbstractExternalModule
{
	function hook_data_entry_form_top($project_id, $record) {
		$this->process($project_id, $record);
	}

	function hook_survey_page_top($project_id, $record) {
		$this->process($project_id, $record);
	}

	function process($project_id, $record) {
		$term = '@PROJECTPIPING';
		$matchTerm = '@FIELDMATCH';

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
		
		$startup_vars = $hook_functions[$term];
		$match = $hook_functions[$matchTerm];
        
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
                        var url = "<?= $url ?>"+"&pid="+<?= $_GET['pid'] ?>;
						$.post(url, { thisrecord: <?= $record ?>, thispid: <?= $_GET['pid'] ?>, thismatch: match[field]['params'], otherpid: nodes[0], otherlogic: remaining, choices: JSON.stringify(choices) }, function(data) { 
							var lastNode = nodes[1];
							if (nodes.length > 2) {
								lastNode = nodes[2];
							}

							var tr = $('tr[sq_id='+field+']');
							var id = lastNode.match(/\([^\s]+\)/);
							if (id) {    // checkbox
								id = id[0].replace(/^\(/, "");
								id = id.replace(/\)$/, "");
								var input = $('input:checkbox[code="'+id+'"]', tr);
								if ($(input).length > 0) {
									if (data == 1) {
										$(input).prop('checked', true);
									} else {    // data == 0
										$(input).prop('checked', false);
									}
								} else {
									$('[name="'+field+'"]').val(data);
								}
							} else {
								$('[name="'+field+'"]').val(data);
							}
						});
					}
				});
			}
		</script>
<?php
	}
}
