<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use GuzzleHttp\Client;

echo "Testing Guzzle Timeout...\n";
$client = new Client([
    'timeout' => 3.0,
    'connect_timeout' => 2.0,
]);

$start = microtime(true);
try {
    echo "Sending request to httpbin.org/delay/10 (should time out in 3 seconds)...\n";
    $response = $client->get('https://httpbin.org/delay/10');
    echo "Success! Status code: " . $response->getStatusCode() . "\n";
} catch (\Exception $e) {
    echo "Caught exception: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
}
echo "Elapsed time: " . (microtime(true) - $start) . "s\n";
