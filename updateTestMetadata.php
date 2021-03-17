<?php
namespace Vanderbilt\CrossprojectpipingExternalModuleRI;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

$projectType = $_POST['projectType'];

/** @var $module CrossprojectpipingExternalModuleRI */
if($projectType == "parent") {
	$parentProject = $module->getSystemSetting('test_project_1');

	if($parentProject) {
		$module->setTestMetadataAndData($parentProject,
		[
				["record_id_input","test_piping_instrument","","text","Record ID"],
				["pipe_field_1","test_piping_instrument","","text","Test Piping 1"],
				["pipe_field_2","test_piping_instrument","","radio","Test Piping 2","0, Piped Value Null|1, Test Success|2, What?"],
				["pipe_field_3","test_piping_instrument","","text","Test Piping 3","","MM-DD-YYYY","date_mdy"],
				["pipe_field_4","test_piping_instrument","","calc","Test Piping 4",'rounddown(datediff("01-01-1970",[pipe_field_3],"y","mdy",true))']
		],
		[
			[
				"record_id_input" => "1",
				"pipe_field_1" => "Pipe Successful",
				"pipe_field_2" => "1",
				"pipe_field_3" => "2018-01-01"
			]
		]);

		echo "success";
	}
	else {
		echo "Error: Parent project doesn't exist";
	}
}
else if($projectType == "output") {
	$parentProject = $module->getSystemSetting('test_project_1');
	$pipingProject = $module->getSystemSetting('test_project_2');

	if($parentProject && $pipingProject) {
		$module->setTestMetadataAndData($pipingProject,
		[
				["record_id_output","test_piping_instrument","","text","Record ID"],
				["pipe_field_1","test_piping_instrument","","text","Test Piping 1"],
				["pipe_field_2","test_piping_instrument","","radio","Test Piping 2","0, Piped Value Null|1, Test Success|2, What?"],
				["pipe_field_3_2","test_piping_instrument","","text","Test Piping 3","","MM-DD-YYYY","date_mdy"],
				["pipe_field_4_2","test_piping_instrument","","text","Test Piping 4"]
		],
		[
			[
				"record_id_output" => "1"
			]
		]);

		define("CRON",true);

	//	ExternalModules::setProjectSetting($moduleDirectoryPrefix, $project_id, self::KEY_ENABLED, true);
		$module->setProjectSetting(ExternalModules::KEY_ENABLED,true,$pipingProject);
		$module->setProjectSetting("project-id",[$parentProject],$pipingProject);
		$module->setProjectSetting("field-match",["record_id_output"],$pipingProject);
		$module->setProjectSetting("field-match-source",["record_id_input"],$pipingProject);
		$module->setProjectSetting("data-destination-field",[["pipe_field_1","pipe_field_2","pipe_field_3_2","pipe_field_4_2"]],$pipingProject);
		$module->setProjectSetting("data-source-field",[[null,"pipe_field_2","pipe_field_3","pipe_field_4"]],$pipingProject);
		$module->setProjectSetting("piping-mode",[0],$pipingProject);
		$module->setProjectSetting("pipe-projects",["true"],$pipingProject);
		$module->setProjectSetting("project-fields",[["true","true","true","true"]],$pipingProject);

		echo "success";
	}
	else {
		echo "Error: Parent and/or piping project don't exist";
	}
}