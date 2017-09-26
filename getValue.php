<?php
	namespace Vanderbilt\CrossprojectpipingExternalModule;
    
    use ExternalModules\AbstractExternalModule;
    use ExternalModules\ExternalModules;

	require_once APP_PATH_DOCROOT.'Classes/LogicTester.php';

	\REDCap::allowProjects(array($_POST['otherpid'], $_POST['thispid']));

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
	}
	else if(empty($matchSource)){
		$data = \REDCap::getData($_POST['otherpid'], 'array', array($matchRecord));
	}
	else{
		$data = \REDCap::getData($_POST['otherpid'], 'array', null, null, null, null, false, false, false, "([$matchSource] = '$matchRecord')");
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

	$found = false;
	foreach ($data as $record => $recData) {
		$Proj = new \Project($_POST['otherpid']);
		foreach ($logicItems as $field => $logicItem) {
			if (\LogicTester::isValid($logicItem)) {
				$result = \LogicTester::apply($logicItem, $recData, $Proj, true);
				if ($result) {
					$choices = json_decode($_POST['choices'], true);
					if (isset($choices[$field])) {
						if (isset($choices[$field][$result])) {
							if ($_POST['getlabel'] != 0) {
								echo $choices[$field][$result];
							} else {
								echo $result;
							}
						} else {
							echo $result;
						}
					} else {
						echo $result;
					}
					$found = true;
					break;
				}
			}
			if ($found) {
				$break;
			}
		}
	}
?>
