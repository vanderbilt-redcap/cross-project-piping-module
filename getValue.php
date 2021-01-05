<?php
	namespace Vanderbilt\CrossprojectpipingExternalModule;
	
	use ExternalModules\AbstractExternalModule;
	use ExternalModules\ExternalModules;
	
	$module->llog("\$_GET: " . print_r($_GET, true) . "\n");
	$module->llog("\$_POST: " . print_r($_POST, true) . "\n");
	
	require_once APP_PATH_DOCROOT.'Classes/LogicTester.php';

	if($_POST['otherpid'] != $_POST['thispid']) {
		\REDCap::allowProjects(array($_POST['otherpid'], $_POST['thispid']));
	}
	$sourceProject = new \Project(intval($_POST['otherpid']));
	try {
		$choices_obj = json_decode($_POST['choices'], true);
	} catch(\Exception $e) {
		$choices_obj = new \stdClass();;
	}
	// $active_forms = $module->getProjectSetting('active-forms');
	$instance_i = intval($_GET['instance']);
	
	$thisjson = \REDCap::getData($_POST['thispid'], 'json', array($_POST['thisrecord']), array($_POST['thismatch'])); 
	$thismatch = trim($_POST['thismatch']);
	$thismatch = preg_replace("/^[\'\"]/", "", $thismatch);
	$thismatch = preg_replace("/[\'\"]$/", "", $thismatch);
	$thisdata = json_decode($thisjson, true);
	$matchRecord = "";
	foreach ($thisdata as $line) {
		if ($line[$thismatch]) {
			$matchRecord = $line[$thismatch];
		}
	}

	$matchSource = $_POST['matchsource'];
	if(empty($matchRecord)){
		// Return without echo-ing any values.
		return;
	} else if(empty($matchSource) && $thismatch == 'record_id') {
		$recordId = $matchRecord;
	} else {
		$matchSource = (empty($matchSource)) ? $thismatch : $matchSource ;
		$filterData = \REDCap::getData($_POST['otherpid'], 'array', null, null, null, null, false, false, false, "([$matchSource] = '$matchRecord')");
		if(count($filterData) != 1){
			// Either there were no matches or multiple matches.  Either way, we want to return without echo-ing any values.
			return;
		}

		reset($filterData);
		$recordId = key($filterData);
	}

	$data = \REDCap::getData($_POST['otherpid'], 'array', array($recordId));
	if(empty($data)) {
		return;
	}

	$logic = $_POST['otherlogic'];
	$nodes = preg_split("/\]\[/", $logic);
	for ($i=0; $i < count($nodes); $i++) {
		$nodes[$i] = preg_replace("/^\[/", "", $nodes[$i]);
		$nodes[$i] = preg_replace("/\]$/", "",$nodes[$i]);
	}
	if (count($nodes) == 1) {
		$fieldName = $nodes[0];
	} else {
		$fieldName = $nodes[1];
	}
	if (preg_match("/\*/", $logic)) {
		$fieldNameRegExFull = "/^".preg_replace("/\*/", ".*", $fieldName)."$/";
		$fieldNameRegExMiddle = "/".preg_replace("/\*/", ".*", $fieldName)."/";

		$fieldNames = array();
		foreach ($data as $record => $recData) {
			foreach ($recData as $evID => $evData) {
				foreach ($evData as $field => $value) {
					if (preg_match($fieldNameRegExFull, $field)) {
						$fieldNames[] = $field;
					}
				}
			}
		}
		$logicItems = array();
		foreach ($fieldNames as $field) {
			$newLogic = preg_replace($fieldNameRegExMiddle, $field, $logic);
			if (!preg_match("/\]$/", $newLogic)) {    # in case wildcard took it off
				$newLogic .= "]";
			}
			$logicItems[$field] = $newLogic;
		}
	} else {
		$logicItems = array($fieldName => $logic);
	}
	
	// this function returns
	// return field value or NULL
	function exitInstanceValue($instance) {
		global $sourceProject;
		global $logicItems;
		global $choices_obj;
		global $module;
		
		// $module->llog("exitInstanceValue instance argument: " . print_r($instance, true));
		$module->llog("exitInstanceValue logicItems: " . print_r($logicItems, true));
		
		$eid = $sourceProject->firstEventId;
		foreach ($logicItems as $field => $logicItem) {
			$module->llog("field $field, logicItem $logicItem");
			if (\LogicTester::isValid($logicItem)) {
				$result = \LogicTester::apply($logicItem, $instance, $sourceProject, true);
				
				$inst_field = $instance[$eid][substr($logicItem, 1, -1)];
				
				if ($result || $result === "0" || $result === 0) {
					if (isset($choices_obj[$field])) {
						if (isset($choices_obj[$field][$result])) {
							if ($_POST['getlabel'] != 0) {
								$returnVal = $choices_obj[$field][$result];
							} else {
								$returnVal = $result;
							}
						} else {
							$returnVal = $result;
						}
					} else {
						$returnVal = $result;
					}
					$module->llog('exit a: ' . print_r($returnVal, true));
					exit($returnVal);
				// } else if(!empty($instance[$eid][substr($logicItem, 1, -1)]) && is_array($instance[$eid][substr($logicItem, 1, -1)]) && !empty(reset($instance[$eid][substr($logicItem, 1, -1)]))) {
				} else if(!empty($inst_field) && is_array($inst_field) && (reset($inst_field) == '0' or reset($inst_field) == '1')) {
					header('Content-Type: application/json');
					$module->llog('exit b: ' . print_r(json_encode($instance[$eid][substr($logicItem, 1, -1)]), true));
					$module->llog("exitInstanceValue instance argument: " . print_r($instance, true));
					exit(json_encode($instance[$eid][substr($logicItem, 1, -1)]));
				}
			}
		}
	}
	
	foreach ($data as $record => $recData) {
		// $module->llog("calling exitInstanceValue on recData: " . print_r($recData, true));
		exitInstanceValue($recData);
		
		// if we didn't exit a value, didn't find one, check repeating instances
		if ($instance_i <= 0) {
			$module->llog('exit c: ""');
			exit("");
		}
		$module->llog('depth level a');
		foreach($recData['repeat_instances'] as $eid => $instruments) {
			$module->llog('depth level b');
			foreach ($instruments as $instrument_name => $instances) {
				$module->llog('depth level c');
				// ensure instrument is in active_forms setting
				$instance = $instances[$instance_i];
				// $module->llog("instances[instance_i: $instance_i]: " . print_r($instance, true));
				if (!empty($instance)) {
					$module->llog('depth level d');
					// shim instance so it looks like regular, non-repeating instrument data
					// $module->llog("calling exitInstanceValue on: " . print_r([$eid => $instance], true));
					exitInstanceValue([$eid => $instance]);
				}
			}
		}
	}
	
	// send empty value response
	$module->llog('exit d: ""');
	exit("");
?>
