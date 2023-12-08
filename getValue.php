<?php
	namespace Vanderbilt\CrossprojectpipingExternalModule;

	use ExternalModules\AbstractExternalModule;
	use ExternalModules\ExternalModules;

	require_once APP_PATH_DOCROOT.'Classes/LogicTester.php';

	$thismatch = trim($_POST['thismatch']);
	$thismatch = preg_replace("/^[\'\"]/", "", $thismatch);
	$thismatch = preg_replace("/[\'\"]$/", "", $thismatch);

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

	$module->verifyPermissions(
		$_POST['thispid'],
		$thismatch,
		$_POST['otherpid'],
		$_POST['matchsource'],
		$fieldName,
	);

	$thisjson = \REDCap::getData($_POST['thispid'], 'json', array($_POST['thisrecord']), array($_POST['thismatch']));
	$thisdata = json_decode($thisjson, true);
	$matchRecord = "";
	foreach ($thisdata as $line) {
		if ($line[$thismatch]) {
			$matchRecord = $line[$thismatch];
		}
	}

	$matchSource = $_POST['matchsource'];
	$repeat_instance = intval($_POST['thisinstance']);

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

	$data = \Records::getData($_POST['otherpid'], 'array', array($recordId));
	if(empty($data)) {
		return;
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

	$found = false;

	foreach ($data as $record => $recData) {
		$Proj = new \Project($_POST['otherpid']);
		foreach ($logicItems as $field => $logicItem) {
			if (\LogicTester::isValid($logicItem)) {
				$result = \LogicTester::apply($logicItem, $recData, $Proj, true);

				$fieldName = substr($logicItem, 1, -1);

				if ($result || $result === "0" || $result === 0) {
					// escape choice key/values
					$insecure_choices = json_decode($_POST['choices'], true);

					foreach ($insecure_choices as $k1 => $v1) {
						foreach ($v1 as $k2 => $v2) {
							$choices[$k1][$k2] = $v2;
						}
					}

					if (isset($choices[$field])) {
						if (isset($choices[$field][$result])) {
							if ($_POST['getlabel'] != 0) {
								$returnVal = $choices[$field][$result];
							} else {
								$returnVal = $result;
							}
						} else {
							$returnVal = $result;
						}
					} else {
						$returnVal = $result;
					}
					echo $module->escape($returnVal);
					$found = true;
					break;
				} else if(!empty($recData[$Proj->firstEventId][$fieldName]) && is_array($recData[$Proj->firstEventId][$fieldName])) {
					header('Content-Type: application/json');
					echo json_encode($module->escape($recData[$Proj->firstEventId][$fieldName]));
					$found = true;
					break;
				}
				elseif (isset($recData['repeat_instances'][$Proj->firstEventId][$Proj->metadata[$fieldName]['form_name']][$repeat_instance][$fieldName])) {
					echo $module->escape($recData['repeat_instances'][$Proj->firstEventId][$Proj->metadata[$fieldName]['form_name']][$repeat_instance][$fieldName]);
					$found = true;
					break;
				}
				else {
					//echo "Got a screw up on $fieldName";
				}
			}
			if ($found) {
				break;
			}
		}
	}
?>
