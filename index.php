<?php
require_once __DIR__ . '/vendor/autoload.php';

use Google\Analytics\Data\V1beta\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\RunRealtimeReportRequest;

$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

if ($path === 'canada-goose') {
    getAnalyticsData();
} elseif ($path === 'health') {
    echo json_encode(['status' => 'ok', 'time' => date('Y-m-d H:i:s')]);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}

function getAnalyticsData() {
    try {
        $credentials = json_decode(
            base64_decode($_ENV['GOOGLE_CREDENTIALS_BASE64']),
            true
        );
        
        $tmpfile = tempnam('/tmp', 'ga_');
        file_put_contents($tmpfile, json_encode($credentials));
        
        $client = new BetaAnalyticsDataClient(['credentials' => $tmpfile]);
        
        $propertyId = $_ENV['GA_PROPERTY_ID'];
        $request = new RunRealtimeReportRequest();
        $request->setProperty("properties/{$propertyId}");
        $request->setMetrics([['name' => 'activeUsers'], ['name' => 'eventCount']]);
        $request->setDimensions([['name' => 'country'], ['name' => 'pagePath']]);
        
        $response = $client->runRealtimeReport($request);
        
        $data = ['timestamp' => date('Y-m-d H:i:s'), 'property' => 'canada_goose', 'events' => []];
        
        if ($response->getRowCount() > 0) {
            foreach ($response->getRows() as $row) {
                $dimensions = array_map(fn($d) => $d->getValue(), $row->getDimensionValues());
                $metrics = array_map(fn($m) => $m->getValue(), $row->getMetricValues());
                
                $data['events'][] = [
                    'country' => $dimensions[0],
                    'page' => $dimensions[1],
                    'users' => (int)$metrics[0],
                    'count' => (int)$metrics[1],
                ];
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        unlink($tmpfile);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>