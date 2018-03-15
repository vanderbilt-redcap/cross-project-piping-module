<?php
namespace Vanderbilt\CrossprojectpipingExternalModule;
/**

	This is a utility script that builds an array of hook functions based on a convention of using
	@FUNCTION=PARAMS in the notes field of certain questions.

	Since the release of the Big Dumb Box (Annotations Field), this is the preferred location for this tags, but this script will take both

	Currently, examples include:

	@IMAGEMAP=PAINMAP_MALE
	@MEDIAPLAYER={json parameters}

	The format for adding additional parmaeters to your hook tag is in flux...
	The json is nice since you can include multiple parameters easier, but I currently don't support linefeeds and spaces, as some questions could have multiple functions...  I'll try to patch this in the future and settle on a final convention.

	Andrew Martin
	Stanford University
**/

function getHookFunctions($pid) {
	# This is an array of found functions as keys and arrays of matching fields as values
	# 'function' => 'parameters'
	$hook_functions = array();
	
	# This is an array of with fields as keys and then functions (with parameters and values)
	# This hasn't really been used much and should probably be deleted
	$hook_fields = array();
	
	# Scan through pages rendered on this page searching for @terms
	if ($pid) {
		$metadataArray = \REDCap::getDataDictionary($_GET['pid'], 'array');
		foreach ($metadataArray as $fieldName => $fieldAttrs) {
			if ($fieldAttrs['field_note'] || $fieldAttrs['field_annotation']) {
				# Check both field_note and field_annotation fields
				$note = $fieldAttrs['field_note'];
				$misc = $fieldAttrs['field_annotation'];
				
				# Concatenate these together for one search
				$search = "$note $misc";
				
				# Using a strpos search initially as it is faster than regex search
				if (strpos($search,'@') !== false) {
					# We have a potential match - lets get all terms (separated by spaces)
					preg_match_all('/@\S+/', $search, $matches);
					if ($matches) {
						# We have found matches - let's parse them
						$matches = reset($matches);
						$hook_fields[$element['field']] = $matches;
						foreach ($matches as $match) {
							# Some terms have a name=params format, if so, break out params
							list($hook_name,$hook_details) = explode('=',$match);
							$hook_functions[$hook_name] = array_merge(
									isset($hook_functions[$hook_name]) ? $hook_functions[$hook_name] : array(),
									array($fieldName => array(
										'params' => $hook_details)
									)
							);
						}
					}
				}
			}
		}
	}
	return $hook_functions;
}
