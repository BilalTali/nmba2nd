<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;

echo "Mimicking Slot 0 event submit GET request...\n";

// Load cookie jar for slot 0
$cookieJar = new CookieJar();
$sharedPath = '/home/u335000182/shared_sync/cookies_slot_0.json';
if (file_exists($sharedPath)) {
    echo "Loading cookies from $sharedPath...\n";
    $content = file_get_contents($sharedPath);
    echo "Cookie content: $content\n";
    $cachedCookies = json_decode($content, true);
    if (is_array($cachedCookies)) {
        foreach ($cachedCookies as $cookieArray) {
            if (isset($cookieArray['Name'], $cookieArray['Value'])) {
                $cookieJar->setCookie(new SetCookie($cookieArray));
            }
        }
    }
} else {
    echo "No cookies found at $sharedPath!\n";
}

$probeClient = new Client([
    'version'         => 2.0,
    'cookies'         => $cookieJar,
    'timeout'         => 20,
    'connect_timeout' => 10,
    'allow_redirects' => ['max' => 5, 'strict' => false],
    'headers'         => [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ],
    'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
]);

$submitUrl = 'https://nashamuktjk.org/enterprise/event_create';
echo "Sending GET request to $submitUrl...\n";
$start = microtime(true);
try {
    $response = $probeClient->get($submitUrl);
    echo "Success! Status code: " . $response->getStatusCode() . "\n";
    echo "Body length: " . strlen((string)$response->getBody()) . "\n";
    echo "Body snippet: " . substr((string)$response->getBody(), 0, 500) . "\n";
} catch (\Exception $e) {
    echo "Caught exception: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
}
echo "Total time: " . (microtime(true) - $start) . "s\n";
