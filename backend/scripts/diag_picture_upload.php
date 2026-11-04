<?php
// One-off diagnostic for the "404 Resource not found" on UploadSiteHostedPictures.
// Run inside the backend container:  php scripts/diag_picture_upload.php
// Prints eBay's raw answers; uploads one tiny test picture (it is never attached to a listing).

require __DIR__ . '/../vendor/autoload.php';

use App\eBay\EbayClient;
use App\Storage\Json\TokenRepository;
use GuzzleHttp\Client;

$client = new EbayClient(new TokenRepository(__DIR__ . '/../data'));
$ref    = new ReflectionClass($client);
$m      = $ref->getMethod('getValidAccessToken');
$m->setAccessible(true);
$token  = $m->invoke($client);

$sandbox = filter_var($_ENV['EBAY_SANDBOX'] ?? getenv('EBAY_SANDBOX') ?: 'true', FILTER_VALIDATE_BOOLEAN);
$url     = $sandbox ? 'https://api.sandbox.ebay.com/ws/api.dll' : 'https://api.ebay.com/ws/api.dll';
$env     = fn (string $k, string $d = '') => $_ENV[$k] ?? (getenv($k) ?: $d);

echo "sandbox=" . var_export($sandbox, true) . "  url=$url\n";
echo "SITEID=" . $env('EBAY_SITE_ID', '77') . "  APP=" . substr($env('EBAY_CLIENT_ID'), 0, 12) . "…"
    . "  DEV set=" . ($env('EBAY_DEV_ID') !== '' ? 'yes' : 'NO') . "  token=" . substr($token, 0, 6) . "… (len " . strlen($token) . ")\n\n";

$headers = fn (string $call) => [
    'X-EBAY-API-CALL-NAME'           => $call,
    'X-EBAY-API-SITEID'              => $env('EBAY_SITE_ID', '77'),
    'X-EBAY-API-APP-NAME'            => $env('EBAY_CLIENT_ID'),
    'X-EBAY-API-DEV-NAME'            => $env('EBAY_DEV_ID'),
    'X-EBAY-API-CERT-NAME'           => $env('EBAY_CLIENT_SECRET'),
    'X-EBAY-API-COMPATIBILITY-LEVEL' => '1155',
];
$creds = "<RequesterCredentials><eBayAuthToken>{$token}</eBayAuthToken></RequesterCredentials>";
$http  = new Client(['timeout' => 30, 'http_errors' => false]);

$show = function (string $label, $resp) {
    echo "=== $label → HTTP " . $resp->getStatusCode() . "\n";
    foreach ($resp->getHeaders() as $k => $v) {
        echo "  $k: " . implode(', ', $v) . "\n";
    }
    echo "  body: " . substr(preg_replace('/\s+/', ' ', (string)$resp->getBody()), 0, 600) . "\n\n";
};

// 1. Plain Trading call — does api.dll answer at all?
$show('GeteBayOfficialTime', $http->post($url, [
    'headers' => $headers('GeteBayOfficialTime') + ['Content-Type' => 'text/xml'],
    'body'    => '<?xml version="1.0" encoding="utf-8"?><GeteBayOfficialTimeRequest xmlns="urn:ebay:apis:eBLBaseComponents">'
        . $creds . '</GeteBayOfficialTimeRequest>',
]));

// 2. Same call with the OAuth token in the IAF header instead of the XML body.
$show('GeteBayOfficialTime (IAF header)', $http->post($url, [
    'headers' => $headers('GeteBayOfficialTime') + ['Content-Type' => 'text/xml', 'X-EBAY-API-IAF-TOKEN' => $token],
    'body'    => '<?xml version="1.0" encoding="utf-8"?><GeteBayOfficialTimeRequest xmlns="urn:ebay:apis:eBLBaseComponents"></GeteBayOfficialTimeRequest>',
]));

// 3. Picture upload by URL (eBay fetches a public test image).
$show('UploadSiteHostedPictures by URL', $http->post($url, [
    'headers' => $headers('UploadSiteHostedPictures') + ['Content-Type' => 'text/xml'],
    'body'    => '<?xml version="1.0" encoding="utf-8"?><UploadSiteHostedPicturesRequest xmlns="urn:ebay:apis:eBLBaseComponents">'
        . $creds . '<PictureName>diag</PictureName><PictureSet>Standard</PictureSet>'
        . '<ExternalPictureURL>https://upload.wikimedia.org/wikipedia/commons/4/47/PNG_transparency_demonstration_1.png</ExternalPictureURL>'
        . '</UploadSiteHostedPicturesRequest>',
]));

// 4. Picture upload as multipart attachment (the path files from the computer take).
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');
$show('UploadSiteHostedPictures multipart', $http->post($url, [
    'headers'   => $headers('UploadSiteHostedPictures'),
    'expect'    => false,
    'multipart' => [
        ['name' => 'XML Payload', 'headers' => ['Content-Type' => 'text/xml; charset=utf-8'],
         'contents' => '<?xml version="1.0" encoding="utf-8"?><UploadSiteHostedPicturesRequest xmlns="urn:ebay:apis:eBLBaseComponents">'
            . $creds . '<PictureName>diag</PictureName><PictureSet>Standard</PictureSet></UploadSiteHostedPicturesRequest>'],
        ['name' => 'image', 'filename' => 'diag.png', 'headers' => ['Content-Type' => 'image/png'], 'contents' => $png],
    ],
]));
