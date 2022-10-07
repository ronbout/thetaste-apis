<?php

// Load the Google API PHP Client Library.
require_once __DIR__ . '/vendor/autoload.php';

$analytics = initializeAnalytics();
$response = getReport($analytics);
echo "<pre>";
print_r($response);
echo "</pre>";
die;
printResults($response);


/**
 * Initializes an Analytics Reporting API V4 service object.
 *
 * @return An authorized Analytics Reporting API V4 service object.
 */
function initializeAnalytics()
{

  // Use the developers console and download your service account
  // credentials in JSON format. Place them in this directory or
  // change the key file location if necessary.
  $KEY_FILE_LOCATION = __DIR__ . '/pure-girder-363400-ed2fa1affe53.json';

  // Create and configure a new client object.
  $client = new Google_Client();
  $client->setApplicationName("Hello Analytics Reporting");
  $client->setAuthConfig($KEY_FILE_LOCATION);
  $client->setScopes(['https://www.googleapis.com/auth/analytics.readonly']);
  $analytics = new Google_Service_AnalyticsReporting($client);

  return $analytics;
}


/**
 * Queries the Analytics Reporting API V4.
 *
 * @param service An authorized Analytics Reporting API V4 service object.
 * @return The Analytics Reporting API V4 response.
 */
function getReport($analytics) {

  // Replace with your view ID, for example XXXX.
  $VIEW_ID = "123294317";

  // Create the DateRange object.
  $dateRange = new Google_Service_AnalyticsReporting_DateRange();
  $dateRange->setStartDate("2021-09-23");
  $dateRange->setEndDate("2022-09-24");

  // Create the Metrics object.
  $sessions = new Google_Service_AnalyticsReporting_Metric();
  $sessions->setExpression("ga:sessions");
  $sessions->setAlias("sessions");

  // Create the Metrics object.
  $views = new Google_Service_AnalyticsReporting_Metric();
  $views->setExpression("ga:pageviews");
  $views->setAlias("pageviews");

  // Create the Metrics object.
  $users = new Google_Service_AnalyticsReporting_Metric();
  $users->setExpression("ga:users");
  $users->setAlias("users");

  // Create the ReportRequest object.
  $request = new Google_Service_AnalyticsReporting_ReportRequest();
  $request->setViewId($VIEW_ID);
  $request->setDateRanges($dateRange);
  $request->setMetrics(array($sessions, $views, $users));

  $body = new Google_Service_AnalyticsReporting_GetReportsRequest();
	$page = new Google_Service_AnalyticsReporting_Dimension();
	$dateView = new Google_Service_AnalyticsReporting_Dimension();
	$dateView->setName("ga:day");
	$page->setName("ga:pagepath");
	// $request->setDimensions(array($page, $dateView));
	$request->setDimensions(array( $page, $dateView));
  $body->setReportRequests( array( $request) );
  return $analytics->reports->batchGet( $body );
}


/**
 * Parses and prints the Analytics Reporting API V4 response.
 *
 * @param An Analytics Reporting API V4 response.
 */
function printResults($reports) {
	// var_dump($reports);
	// die;
  for ( $reportIndex = 0; $reportIndex < count( $reports ); $reportIndex++ ) {

    $report = $reports[ $reportIndex ];
    $header = $report->getColumnHeader();
    $dimensionHeaders = $header->getDimensions();
		// var_dump($dimensionHeaders);
		// die;
    $metricHeaders = $header->getMetricHeader()->getMetricHeaderEntries();
    $rows = $report->getData()->getRows();

    for ( $rowIndex = 0; $rowIndex < count($rows); $rowIndex++) {
      $row = $rows[ $rowIndex ];
      $dimensions = $row->getDimensions();
      $metrics = $row->getMetrics();
			if ($dimensionHeaders) {
				for ($i = 0; $i < count($dimensionHeaders) && $i < count($dimensions); $i++) {
					print($dimensionHeaders[$i] . ": " . $dimensions[$i] . "\n");
				}
			}

      for ($j = 0; $j < count($metrics); $j++) {
        $values = $metrics[$j]->getValues();
        for ($k = 0; $k < count($values); $k++) {
          $entry = $metricHeaders[$k];
          print($entry->getName() . ": " . $values[$k] . "\n");
        }
      }
    }
  }
}
