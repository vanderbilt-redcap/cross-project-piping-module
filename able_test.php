<?php

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

echo "<pre>";

$pid = $module->getProjectId();

$active_forms = $module->getProjectSetting('active-forms');
print_r($active_forms);
echo "\n";

$val = in_array('target_repeating_instrument', $active_forms, true);
echo ("in array: " . print_r($val, true));
echo "\n";

echo "pipe-on-status setting: " . $module->getProjectSetting('pipe-on-status') . "\n";

// set project settings data-destination-field and data-source-field
$dest = [
	0 => [
			'field1',
			'field2',
			'field3',
			'field4',
			'field5',
			'field6',
			'field7',
			'field8',
			'field9',
			'field10',
			'field11',
			'field1_r',
			'field2_r',
			'field3_r',
			'field4_r',
			'field5_r',
			'field6_r',
			'field7_r',
			'field8_r',
			'field9_r',
			'field10_r',
			'field11_r'
		]
];
$src = [
	0 => [
			'field1',
			'field2',
			'field3',
			'field4',
			'field5',
			'field6',
			'field7',
			'field8',
			'field9',
			'field10',
			'field11',
			'field1_r',
			'field2_r',
			'field3_r',
			'field4_r',
			'field5_r',
			'field6_r',
			'field7_r',
			'field8_r',
			'field9_r',
			'field10_r',
			'field11_r'
		]
];
$module->setProjectSetting('data-destination-field', $dest);
$module->setProjectSetting('data-source-field', $src);

print_r($module->getProjectSettings());

echo "</pre>";
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
?>