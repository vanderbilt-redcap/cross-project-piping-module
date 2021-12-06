

# Cross Project Piping - External Module
<h2 style='color: #33B9FF;'>Configure Cross Project Piping</h2>
This module must be enabled on the DESTINATION project. Once enabled the configuration is done with in the
external module tab on the project.

![The module's 'Configure' button can be found under the 'Currently Enabled Modules' header in the project's External Modules page](/docs/readme_img_1.png)

<span style='font-weight: 600; text-decoration: underline;'>Project configurations that must be set up prior to field configurations.<span>
1. Go to your DESTINATION project.
2. In the Destination project, click on External Modules on the left-hand navigation bar. Then click on the
Configure button.
**3. Source Project:**
This field is a drop down list of all the projects the configuring user has access to.

![This picture shows project settings and values columns in the module's configuration view](/docs/readme_img_2.png)

**4. Unique Match Field:**
Select the unique field on the destination project that represents the record (first field of project). REDCap
uses record_id as the default value but can be different on every project.

![This picture shows the Unique Match Field setting](/docs/readme_img_3.png)

**5. Alternate Source Match Field:**
This is used if the unique match field on the destination project is different from the source project. For
example if Project A records are subject_id but Project B records are record_id.

![This picture shows the Alternate Source Match Field setting](/docs/readme_img_4.png)

<span style='color: #ff0000;'>Note all configurations can repeat, in the instance you need to pipe values from multiple projects into one. Simply select the + icon in the gray space at the top.</span>

<span style='font-weight: 600; text-decoration: underline;'>Setting up your piped field.<span>
1. Select the destination field from the drop down list.

![This picture shows the Destinatino Field setting](/docs/readme_img_5.png)

2. You will only need to enter a value in the Source field if the variable name on the destination field is
different from the source field.

![This picture shows the Source Field setting](/docs/readme_img_6.png)

<span style='color: #ff0000;'>Note to add more pipied fields select the + icon in the gray space to the right of Pipe Field:</span>

<span style='font-weight: 600; text-decoration: underline;'>Forms to allow piping<span>
Here you will select the instument piping will occur on. To add more then one instument select the + icon to the
right.

![This picture shows the active form setting](/docs/readme_img_7.png)

Once all configurations have been set make sure to select save at the bottom.
<span style='font-weight: 600; text-decoration: underline;'>Piping Mode<span>
There are two different ways to use piping:
4. Auto pipe on incomplete- This method will always load the piping screen and bring data into the
destination instrument every time the page is loaded unless the form is marked as complete.
5. Piping Button at top- This method will place an “Initiate Data Piping” button at the top of the
designated instument and only activate piping when selected.

<span style='color: #ff0000;'>Note: Auto piping will only run on 'Incomplete' status, and the piping button will only appear on instruments with an 'Incomplete' status. Once a record has moved from incomplete to any other status piping will not be available.
The status can always be reverted to incomplete to utilize this function.</span>

![This picture shows the Piping Mode setting (with Auto pipe selected)](/docs/readme_img_8.png)

![This picture shows the Piping Mode setting (with Piping Button selected)](/docs/readme_img_9.png)

<span style='color: #ff0000; font-size: 1.25rem;'>Please note that if cross project piping is used there is a risk of overwriting data
in an instrument. Any record saved with data on it weather piped or not will save on that record.</span>

<h3 style='color: #33B9FF;'>Support for Repeating Instances</h3>
This module supports repeating instances in the following way:
'Unique Match Field' and 'Destination Field' settings may both (or either) be set to fields that exist in repeating instruments of the destination project.

The module cannot, however, support configurations that require the 'Alternate Source Match Field' or 'Source Field' settings to point to fields that exist in repeating instruments.

### Note: Field Embedding
The Cross-Project Piping module may not work correctly when interacting with embedded fields.
