<?php
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

$project_id = $_GET['pid'];
$report_index = (is_numeric($_POST['report_index']) ? $_POST['report_index'] : null);
if (is_numeric($project_id)) {
	$module = new \Vanderbilt\ReportsWithRecordSums\ReportsWithRecordSums();
	$module->loadTwigExtensions();
	echo $module->loadDataReportTwig($project_id, $report_index);
}
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
