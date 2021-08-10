<?php
// http://localhost/redcap/redcap_v10.9.4/ExternalModules/?prefix=cross_project_piping&page=php%2Fpipe_all_data_ajax&pid=101#


// get information about configured source projects
$projects = $module->getProjects();

$destination = $projects['destination'];
$destination_events_by_name = array_flip($destination['events']);
$error_message = "The Cross Project Piping module encountered these errors from REDCap while piping data:<br>";
$log_message = "Imported the following records from the source projects:\n";
$map_of_record_ids_imported = [];
$errors_set = false;

// prepare the information necessary to implement active form filtering and form status filtering (as configured in module)
$active_forms = $module->getProjectSetting('active-forms');
if (count($active_forms) == 1 && empty($active_forms[0])) {		// framework-version 2 can return an array that's not quite empty ([[0] => null])
	$active_forms = [];
}
$no_fields_piped = true;

$pipe_on_status = $module->getProjectSetting('pipe-on-status');
$form_statuses_all_records = $module->getFormStatusAllRecords($active_forms);

// iterate through each project
foreach ($projects['source'] as $project_index => $source_project) {
	// // prepare parameters for getting data from source project
	
	// first, remove source fields that don't correlate to an 'active form' from configuration
	if (!empty($active_forms)) {
		$fields_to_remove = [];
		foreach ($source_project['dest_forms_by_field_name'] as $field_name => $dest_form_name) {
			if (array_search($dest_form_name, $active_forms, true) === false) {
				$fields_to_remove[] = $field_name;
			}
		}
		
		foreach ($source_project['dest_fields'] as $index => $dest_field) {
			if (array_search($dest_field, $fields_to_remove, true) !== false) {
				unset($source_project['dest_fields'][$index]);
				unset($source_project['source_fields'][$index]);
				
			}
		}
		$source_project['dest_fields'] = array_values($source_project['dest_fields']);
		$source_project['source_fields'] = array_values($source_project['source_fields']);
	}
	
	if (empty($source_project['dest_fields'])) {
		// don't pipe
		continue;
	} else {
		$no_fields_piped = false;
	}
	
	// build param array
	$get_data_params = [
		"project_id" => $source_project['project_id'],
		"fields" => $source_project['source_fields'],
		"filterLogic" => "[" . $source_project['source_match_field'] . "] != ''"
	];
	
	// pull relevant record data
	$source_project['record_data'] = \REDCap::getData($get_data_params);
	
	// translate source FIELD names to destination FIELD names
	// and
	// translate source EVENT names to destination EVENT names
	foreach ($source_project['record_data'] as $record_id => $record) {
		foreach ($record as $event_id => $data) {
			foreach ($data as $field_name => $field_value) {
				$dest_field_index = array_search($field_name, $source_project['source_fields'], true);
				$dest_field_name = null;
				if ($dest_field_index) {
					$dest_field_name = $source_project['dest_fields'][$dest_field_index];
					unset($source_project['record_data'][$record_id][$event_id][$field_name]);
					$source_project['record_data'][$record_id][$event_id][$dest_field_name] = $field_value;
				}
				
				// handle the case where $field_name is the record ID field name (which would get missed otherwise)
				if (
					$field_name == $source_project['source_match_field']
					&&
					$field_name != $source_project['dest_match_field']
				) {
					$dest_record_id_field_name = $source_project['dest_match_field'];
					$source_project['record_data'][$record_id][$event_id][$dest_record_id_field_name] = $field_value;
					
					// remove source match field name
					unset($source_project['record_data'][$record_id][$event_id][$field_name]);
				}
			}
			
			$event_name = $source_project['events'][$event_id];
			$dest_event_id = $destination_events_by_name[$event_name];
			if ($dest_event_id) {
				$source_project['record_data'][$record_id][$dest_event_id] = $source_project['record_data'][$record_id][$event_id];
				if ($dest_event_id != $event_id) {
					unset($source_project['record_data'][$record_id][$event_id]);
				}
			}
			
			if (is_numeric($pipe_on_status)) {
				// iterate again, this time removing fields whose destination record's matching form is above pipe-on-status limit
				foreach ($data as $field_name => $field_value) {
					$destination_form_complete_field = $Proj->metadata[$field_name]['form_name'] . "_complete";
					
					// if form status for receiving record is above the pipe-on-status threshold, skip importing data for this event-form
					if (
						is_numeric($form_statuses_all_records[$record_id][$dest_event_id][$destination_form_complete_field]) &&
						$form_statuses_all_records[$record_id][$dest_event_id][$destination_form_complete_field] > $pipe_on_status
					) {
						unset($source_project['record_data'][$record_id][$dest_event_id][$field_name]);
					}
				}
			}
		}
	}
	
	// save to destination (host) project
	$source_project['save_results'] = \REDCap::saveData('array', $source_project['record_data']);
	
	if (!empty($source_project['save_results']['errors'])) {
		$errors_set = true;
		$error_message .= "project_id: " . $source_project['project_id'] . " -- " . print_r($source_project['save_results']['errors'], true);
	}
	foreach ($source_project['save_results']['ids'] as $rid) {
		$log_message .= "Record $rid imported from project (PID: " . $source_project['project_id'] . ")\n";
		$map_of_record_ids_imported[$rid] = true;
	}
}

if ($no_fields_piped) {
	$log_message = "The Cross Project Piping module ran successfully but the combination of active forms and pipe fields configured resulted in 0 data fields being piped.";
}

// return OK or error to the user waiting on the record status dashboard
header('Content-Type: application/json');
$response = [];
$response['success'] = !$errors_set;
if ($errors_set) {
	\REDCap::logEvent("Cross Project Piping: Pipe All Records Failure", $error_message);
	$response['error'] = $error_message;
} else {
	\REDCap::logEvent("Cross Project Piping: Pipe All Records Success", $log_message);
	// $response['log_message'] = $log_message; // verbose
	$response['log_message'] = "The Cross Project Piping module imported " . count($map_of_record_ids_imported) . " records from source projects.";
}

echo json_encode($response);