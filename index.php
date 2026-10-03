<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\RunRealtimeReportRequest;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Metric;

header('Content-Type: application/json; charset=utf-8');

// route -> GA4 property id. Новая property = одна строка здесь.
$properties = [
    'canada-goose' => getenv('GA_PROPERTY_ID') ?: '557233023',
];

$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', '/');

try {
    if ($path === 'health') {
        out(['status' => 'ok', 'time' => gmdate('c')]);
    }

    if (isset($properties[$path])) {
        out(realtime($properties[$path], $path));
    }

    http_response_code(404);
    out([
        'error'  => 'not found',
        'routes' => array_merge(['health'], array_keys($properties)),
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    error_log('[analytics-ga] ' . $e::class . ': ' . $e->getMessage());
    out(['error' => $e->getMessage()]);
}

function realtime(string $propertyId, string $label): array
{
    $client = new BetaAnalyticsDataClient([
        'credentials' => credentials(),
    ]);

    $request = (new RunRealtimeReportRequest())
        ->setProperty("properties/{$propertyId}")
        ->setMetrics([
            new Metric(['name' => 'activeUsers']),
            new Metric(['name' => 'eventCount']),
        ])
        ->setDimensions([
            new Dimension(['name' => 'country']),
            new Dimension(['name' => 'unifiedScreenName']),
        ])
        ->setLimit(100);

    $response = $client->runRealtimeReport($request);

    $rows = [];
    $totalUsers = 0;

    foreach ($response->getRows() as $row) {
        $dims = [];
        foreach ($row->getDimensionValues() as $d) {
            $dims[] = $d->getValue();
        }
        $mets = [];
        foreach ($row->getMetricValues() as $m) {
            $mets[] = $m->getValue();
        }

        $users = (int) ($mets[0] ?? 0);
        $totalUsers += $users;

        $rows[] = [
            'country' => $dims[0] ?? null,
            'page'    => $dims[1] ?? null,
            'users'   => $users,
            'events'  => (int) ($mets[1] ?? 0),
        ];
    }

    return [
        'property'     => $label,
        'property_id'  => $propertyId,
        'timestamp'    => gmdate('c'),
        'active_users' => $totalUsers,
        'row_count'    => count($rows),
        'rows'         => $rows,
    ];
}

// service-account JSON из env, декодируется только в память
function credentials(): array
{
    $b64 = getenv('GOOGLE_CREDENTIALS_BASE64');
    if (!$b64) {
        throw new RuntimeException('GOOGLE_CREDENTIALS_BASE64 is not set');
    }

    $json = base64_decode($b64, true);
    if ($json === false) {
        throw new RuntimeException('GOOGLE_CREDENTIALS_BASE64 is not valid base64');
    }

    $creds = json_decode($json, true);
    if (!is_array($creds) || empty($creds['client_email'])) {
        throw new RuntimeException('credentials JSON is malformed');
    }

    return $creds;
}

function out(array $data): never
{
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}
