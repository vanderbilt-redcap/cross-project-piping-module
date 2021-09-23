<?php
header('Content-Type: application/json');

// get information about configured source projects
$module->projects = $module->getProjects();
$module->getSourceProjectsData();
$module->getDestinationProjectData();

// prepare the information necessary to implement active form filtering and form status filtering (as configured in module)
$module->active_forms = $module->getProjectSetting('active-forms');
if (count($module->active_forms) == 1 && empty($module->active_forms[0])) {		// framework-version 2 can return an array that's not quite empty ([[0] => null])
	$module->active_forms = [];
}
$module->pipe_on_status = $module->getProjectSetting('pipe-on-status');
$module->formStatuses = $module->getFormStatusAllRecords($module->active_forms);

$failures = 0;
$successes = 0;

foreach ($module->projects['destination']['records_match_fields'] as $rid => $info) {
	$save_result = $module->pipeToRecord($rid);
	
	if ($save_result['ids'][1] == $rid) {
		$successes++;
	} elseif (!empty($save_result['errors'])) {
		$failures++;
	}
}

\REDCap::logEvent("Cross Project Piping: Pipe All Records",
	"The module succeeded in importing $successes records and failed to import $failures records.");

$response = [];
if (empty($errors)) {
	$response['success'] = true;
} else {
	$response['error'] = implode('. ', $errors);
}

echo json_encode($response);