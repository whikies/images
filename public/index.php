<?php
/**
 * Source code of images.weserv.nl, to be used on your own server(s).
 *
 * PHP version 7.1+
 *
 * @category  Images
 * @package   Weserv\Images
 * @author    Andries Louw Wolthuizen <info@andrieslouw.nl>
 * @author    Kleis Auke Wolthuizen   <info@kleisauke.nl>
 * @license   https://opensource.org/licenses/bsd-license.php New BSD License
 * @link      https://images.weserv.nl
 * @copyright 2018
 */

error_reporting(E_ALL);
set_time_limit(180);
ini_set('display_errors', 0);

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Jcupitt\Vips\Exception as VipsException;
use League\Uri\Components\HierarchicalPath as Path;
use League\Uri\Components\Query;
use League\Uri\Http as HttpUri;
use Weserv\Images\Exception\ImageNotReadableException;
use Weserv\Images\Exception\ImageNotValidException;
use Weserv\Images\Exception\ImageTooBigException;
use Weserv\Images\Exception\ImageTooLargeException;
use Weserv\Images\Exception\RateExceededException;
use Weserv\Images\Manipulators\Helpers\Utils;

// See for an example: config.example.php
/** @noinspection PhpIncludeInspection */
$config = @include(__DIR__ . '/../config.php') ?: [];

$error_messages = [
    'invalid_url' => [
        'header' => '404 Not Found',
        'content-type' => 'text/plain',
        'message' => 'Error 404: Server couldn\'t parse the ?url= that you were looking for, because it isn\'t a valid url.',
    ],
    'invalid_redirect_url' => [
        'header' => '404 Not Found',
        'content-type' => 'text/plain',
        'message' => 'Error 404: Unable to parse the redirection URL.',
    ],
    'invalid_image' => [
        'header' => '400 Bad Request',
        'content-type' => 'text/plain',
        'log' => 'Non-supported image. URL: %s',
    ],
    'image_too_big' => [
        'header' => '400 Bad Request',
        'content-type' => 'text/plain',
        'message' => 'The image is too big to be downloaded.' . PHP_EOL . 'Image size %s'
            . PHP_EOL . 'Max image size: %s',
        'log' => 'Image too big. URL: %s',
    ],
    'curl_error' => [
        'header' => '404 Not Found',
        'content-type' => 'text/html',
        'message' => 'Error 404: Server couldn\'t parse the ?url= that you were looking for, error it got: The requested URL returned error: %s',
        'log' => 'cURL Request error: %s URL: %s',
    ],
    'dns_error' => [
        'header' => '410 Gone',
        'content-type' => 'text/plain',
        'message' => 'Error 410: Server couldn\'t parse the ?url= that you were looking for, because the hostname of the origin is unresolvable (DNS) or blocked by policy.',
        'log' => 'cURL Request error: %s URL: %s',
    ],
    'rate_exceeded' => [
        'header' => '429 Too Many Requests',
        'content-type' => 'text/plain',
    ],
    'image_not_readable' => [
        'header' => '400 Bad Request',
        'content-type' => 'text/plain',
        'log' => 'Image not readable. URL: %s Message: %s',
    ],
    'image_too_large' => [
        'header' => '400 Bad Request',
        'content-type' => 'text/plain',
        'log' => 'Image too large. URL: %s',
    ],
    'libvips_error' => [
        'header' => '400 Bad Request',
        'content-type' => 'text/plain',
        'log' => 'libvips error. URL: %s Message: %s',
    ],
    'unknown' => [
        'header' => '500 Internal Server Error',
        'content-type' => 'text/plain',
        'message' => 'Something\'s wrong!' . PHP_EOL .
            'It looks as though we\'ve broken something on our system.' . PHP_EOL .
            'Don\'t panic, we are fixing it! Please come back in a while.. ',
        'log' => 'URL: %s, Message: %s, Instance: %s',
    ]
];

/**
 * Create a new HttpUri instance from a string.
 * The string must comply with the following requirements:
 *  - Starting without 'http:' or 'https:'
 *  - HTTPS origin hosts must be prefixed with 'ssl:'
 *  - Valid according RFC3986 and RFC3987
 *
 * @param string $url
 *
 * @throws InvalidArgumentException if the URI is invalid
 * @throws League\Uri\UriException if the URI is in an invalid state according to RFC3986
 *
 * @return HttpUri parsed URI
 */
function parseUrl(string $url): HttpUri
{
    // Check for HTTPS origin hosts
    if (strpos($url, 'ssl:') === 0) {
        return HttpUri::new('https://' . ltrim(substr($url, 4), '/'));
    }

    // Check if a valid URL is given. Therefore starting without 'http:' or 'https:'.
    if (strpos($url, 'http:') !== 0 && strpos($url, 'https:') !== 0) {
        return HttpUri::new('http://' . ltrim($url, '/'));
    }

    // Not a valid URL; throw InvalidArgumentException
    throw new InvalidArgumentException('Invalid URL');
}

/**
 * Sanitize the 'errorredirect' GET variable after parsing.
 * The HttpUri instance must comply with the following requirements:
 *  - Must not include a 'errorredirect' querystring (if it does, it will be ignored)
 *
 * @param HttpUri $errorUrl
 *
 * @return string sanitized URI
 */
function sanitizeErrorRedirect(HttpUri $errorUrl): string
{
    $queryStr = $errorUrl->getQuery();
    if (!empty($queryStr)) {
        $query = new Query($queryStr);
        if ($query->hasPair('errorredirect')) {
            $newQuery = $query->withoutPairs(['errorredirect']);
            return $errorUrl->withQuery($newQuery->__toString())->__toString();
        }
    }
    return $errorUrl->__toString();
}

if (!empty($_GET['url']) && \is_string($_GET['url'])) {
    try {
        $uri = parseUrl($_GET['url']);
    } catch (Exception $e) {
        $error = $error_messages['invalid_url'];
        header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
        header('Content-type: ' . $error['content-type']);
        echo $error['message'];
        die;
    }

    // Get (potential) extension from path
    $extension = (new Path($uri->getPath()))->getExtension() ?? 'png';

    // Create a unique file (starting with 'imo_') in our shared memory
    $tmpFileName = tempnam('/dev/shm', 'imo_');

    // We need to add the extension to the temporary file for certain image types.
    // This ensures that the image is correctly recognized.
    if ($extension === 'svg' || $extension === 'ico') {
        // Rename our unique file
        rename($tmpFileName, $tmpFileName .= '.' . $extension);
    }

    $defaultClientConfig = [
        // User agent for this client
        'user_agent' => 'Mozilla/5.0 (compatible; ImageFetcher/7.0; +http://images.weserv.nl/)',
        // Float describing the number of seconds to wait while trying to connect to a server.
        // Use 0 to wait indefinitely.
        'connect_timeout' => 5,
        // Float describing the timeout of the request in seconds. Use 0 to wait indefinitely.
        'timeout' => 10,
        // Integer describing the max image size to receive (in bytes). Use 0 for no limits.
        'max_image_size' => 0,
        // Integer describing the maximum number of allowed redirects.
        'max_redirects' => 10,
        // Allowed mime types. Use empty array to allow all mime types
        'allowed_mime_types' => [
            /*'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'image/webp' => 'webp',
            'image/x-icon' => 'ico',
            'image/vnd.microsoft.icon' => 'ico'*/
        ]
    ];

    $clientConfig = isset($config['client']) ?
        array_merge($defaultClientConfig, $config['client']) :
        $defaultClientConfig;
    $guzzleConfig = $config['guzzle'] ?? [];

    // Create an PHP HTTP client
    $client = new Weserv\Images\Client($tmpFileName, $clientConfig, $guzzleConfig);

    // If config throttler is set and IP isn't on the throttler whitelist
    if (isset($config['throttler']) && !isset($config['throttler-whitelist'][$_SERVER['REMOTE_ADDR']])) {
        $policyConfig = $config['throttling-policy'];

        $cloudFlareEmail = $policyConfig['cloudflare']['email'] ?? '';
        $cloudFlareAuthKey = $policyConfig['cloudflare']['auth_key'] ?? '';

        $key = new Cloudflare\API\Auth\APIKey($cloudFlareEmail, $cloudFlareAuthKey);
        $adapter = new Cloudflare\API\Adapter\Guzzle($key);

        // Firewall access rules
        $accessRules = new Cloudflare\API\Endpoints\AccessRules($adapter);

        $throttlingPolicy = new Weserv\Images\Throttler\ThrottlingPolicy(
            $accessRules,
            $policyConfig
        );

        // Defaulting to Redis
        $driver = $config['throttler']['driver'] ?? 'redis';

        if ($driver === 'memcached') {
            // Memcached throttler
            $memcached = new Memcached('mc');

            // When using persistent connections, it's important to not re-add servers.
            if (empty($memcached->getServerList())) {
                $memcached->setOptions([
                    Memcached::OPT_BINARY_PROTOCOL => true,
                    Memcached::OPT_COMPRESSION => false
                ]);

                $memcached->addServer($config['memcached']['host'], $config['memcached']['port']);
            }

            /*if ($memcached->getVersion() === false) {
                trigger_error('MemcachedException. Message: Could not establish Memcached connection', E_USER_WARNING);
            }*/

            // Create an new Memcached throttler instance
            $throttler = new Weserv\Images\Throttler\MemcachedThrottler(
                $memcached,
                $throttlingPolicy,
                $config['throttler']
            );
        } elseif ($driver === 'redis') {
            $redis = new Predis\Client($config['redis']);

            // Create an new Redis throttler instance
            $throttler = new Weserv\Images\Throttler\RedisThrottler(
                $redis,
                $throttlingPolicy,
                $config['throttler']
            );
        }
    }

    // Set manipulators
    $manipulators = [
        new Weserv\Images\Manipulators\Trim(),
        new Weserv\Images\Manipulators\Thumbnail(71000000),
        new Weserv\Images\Manipulators\Orientation(),
        new Weserv\Images\Manipulators\Crop(),
        new Weserv\Images\Manipulators\Letterbox(),
        new Weserv\Images\Manipulators\Shape,
        new Weserv\Images\Manipulators\Brightness(),
        new Weserv\Images\Manipulators\Contrast(),
        new Weserv\Images\Manipulators\Gamma(),
        new Weserv\Images\Manipulators\Sharpen(),
        new Weserv\Images\Manipulators\Filter(),
        new Weserv\Images\Manipulators\Blur(),
        new Weserv\Images\Manipulators\Background(),
    ];

    // Set API
    $api = new Weserv\Images\Api\Api($client, $manipulators);

    // Setup server
    $server = new Weserv\Images\Server(
        $api,
        $throttler ?? null
    );

    /*$server->setDefaults([
        'output' => 'png'
    ]);*/
    /*$server->setPresets([
        'small' => [
            'w' => 200,
            'h' => 200,
            'fit' => 'crop'
        ],
        'medium' => [
            'w' => 600,
            'h' => 400,
            'fit' => 'crop'
        ]
    ]);*/

    try {
        /**
         * Generate and output image.
         */
        $server->outputImage($uri->__toString(), array_filter($_GET, '\is_string'));
    } catch (ImageTooLargeException $e) {
        $error = $error_messages['image_too_large'];
        header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
        header('Content-type: ' . $error['content-type']);

        echo $error['header'] . ' - ' . $e->getMessage();
    } catch (ImageNotValidException $e) {
        $error = $error_messages['invalid_image'];

        header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
        header('Content-type: ' . $error['content-type']);

        trigger_error(sprintf($error['log'], $uri->__toString()), E_USER_WARNING);

        echo $e->getMessage();
    } catch (ImageTooBigException $e) {
        $clientOptions = $client->getOptions();

        $error = $error_messages['image_too_big'];
        $imageSize = $e->getMessage();
        $maxImageSize = Utils::formatBytes($clientOptions['max_image_size']);

        header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
        header('Content-type: ' . $error['content-type']);

        trigger_error(sprintf($error['log'], $uri->__toString()), E_USER_WARNING);

        echo sprintf($error['message'], $imageSize, $maxImageSize);
    } catch (GuzzleException $e) {
        $hasResponse = false;
        $isDnsError = false;

        $statusCode = $e->getCode();
        $reasonPhrase = $e->getMessage();

        if ($e instanceof RequestException) {
            $curlHandler = $e->getHandlerContext();
            $response = $e->getResponse();
            $hasResponse = $response !== null && $e->hasResponse();

            $isDnsError = (isset($curlHandler['errno']) && $curlHandler['errno'] === CURLE_COULDNT_RESOLVE_HOST) ||
                ($hasResponse && strpos($response->getHeaderLine('X-Squid-Error'), 'ERR_DNS_FAIL') !== false);

            if ($hasResponse) {
                $statusCode = $response->getStatusCode();
                $reasonPhrase = $response->getReasonPhrase();
            }
        }

        $error = $isDnsError ? $error_messages['dns_error'] : $error_messages['curl_error'];

        header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
        header('Content-type: ' . $error['content-type']);

        $errorMessage = "$statusCode $reasonPhrase";

        if (!$isDnsError && !empty($_GET['errorredirect']) && \is_string($_GET['errorredirect'])) {
            try {
                $uri = parseUrl($_GET['errorredirect']);
                $sanitizedUri = sanitizeErrorRedirect($uri);

                header('Location: ' . $sanitizedUri);
            } catch (Exception $ignored) {
                $message = sprintf($error['message'], $errorMessage);

                echo $message;
            }
        } else {
            $message = $isDnsError ? $error['message'] : sprintf($error['message'], $errorMessage);

            echo $message;
        }
    } catch (RateExceededException $e) {
        $error = $error_messages['rate_exceeded'];
        header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
        header('Content-type: ' . $error['content-type']);

        echo $error['header'] . ' - ' . $e->getMessage();
    } catch (ImageNotReadableException $e) {
        $error = $error_messages['image_not_readable'];
        header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
        header('Content-type: ' . $error['content-type']);

        echo $error['header'] . ' - ' . $e->getMessage();
    } catch (VipsException $e) {
        $error = $error_messages['libvips_error'];
        header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
        header('Content-type: ' . $error['content-type']);

        // Log libvips exceptions
        trigger_error(
            sprintf(
                $error['log'],
                $uri->__toString(),
                $e->getMessage()
            ),
            E_USER_WARNING
        );

        echo $error['header'] . ' - ' . $e->getMessage();
    } catch (InvalidArgumentException $e) {
        $error = $error_messages['invalid_redirect_url'];
        header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
        header('Content-type: ' . $error['content-type']);

        echo $error['message'];
    } catch (Exception $e) {
        // If there's an exception which is not already caught.
        // Then it's a unknown exception.
        $error = $error_messages['unknown'];

        // Log unknown exceptions
        trigger_error(
            sprintf(
                $error['log'],
                $uri->__toString(),
                $e->getMessage(),
                get_class($e)
            ),
            E_USER_WARNING
        );

        header($_SERVER['SERVER_PROTOCOL'] . ' ' . $error['header']);
        header('Content-type: ' . $error['content-type']);

        echo $error['message'];
    }

    // Still here? Unlink the temporary file.
    @unlink($tmpFileName);
} else {
    $name = $config['name'] ?? 'API 3 - GitHub, DEMO';
    $url = $config['url'] ?? 'images.weserv.nl';

    $exampleImage = $config['exampleImage'] ?? 'ory.weserv.nl/lichtenstein.jpg';
    $exampleTransparentImage = $config['exampleTransparentImage'] ?? 'ory.weserv.nl/transparency_demo.png';
    $exampleSmartcropImage = $config['exampleSmartcropImage'] ?? 'ory.weserv.nl/zebra.jpg';

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Image cache &amp; resize proxy</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/github-fork-ribbon-css/0.2.2/gh-fork-ribbon.min.css" integrity="sha384-jUHnRx457Q15HVKSx5g+6jqsItdcFcR0BBu729dDIMmTM4HT1sbXZuxxOpuiaM/p" crossorigin="anonymous">
    <link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" integrity="sha384-wvfXpqpZZVQGK6TAh5PVlGOfQNHSoD2xbE+QkPxCAFlNEevoEH3Sl0sibVcOQVnN" crossorigin="anonymous">
    <link rel="stylesheet" href="//cdn.jsdelivr.net/npm/docsearch.js@2/dist/cdn/docsearch.min.css">
    <link rel="stylesheet" href="//static.weserv.nl/images-v3d.css" integrity="sha384-fEHMtdq65tYjYhByCic8VIU71uMnMsa2p7gbfnM3oVrsRqStpQezE3bwA7/1Riwh" crossorigin="anonymous">
    <!--[if lte IE 9]>
        <link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/github-fork-ribbon-css/0.2.2/gh-fork-ribbon.ie.min.css">
        <script src="//static.weserv.nl/html5shiv-printshiv.min.js" type="text/javascript"></script>
    <![endif]-->
</head>
<body>
    <nav id="sidebar">
        <div id="header-wrapper">
            <div id="header">
                <a id="logo" href="//$url/">
                    <div id="weserv-logo">Images.<strong>weserv</strong>.nl</div>
                    <span>Image cache &amp; resize proxy</span>
                </a>
                <div class="search-wrapper">
                    <label for="search-by"><i class="fa fa-search"></i></label>
                    <input id="search-by" type="text" placeholder="Search Documentation">
                </div>
            </div>
        </div>
        <div class="scrollbar-inner">
            <ul id="nav" class="nav topics" data-gumshoe-header>
                <li class="dd-item active">
                    <a href="#image-api" class="cen"><span>$name</span></a>
                    <ul class="nav inner" data-gumshoe>
                        <li class="dd-item"><a href="#quick-reference"><span>Quick reference</span></a></li>
                        <li class="dd-item"><a href="#size"><span>Size</span></a></li>
                        <li class="dd-item"><a href="#orientation"><span>Orientation</span></a></li>
                        <li class="dd-item"><a href="#trans"><span>Transformation</span></a></li>
                        <li class="dd-item"><a href="#crop-position"><span>Crop position</span></a></li>
                        <li class="dd-item"><a href="#shape"><span>Shape</span></a></li>
                        <li class="dd-item"><a href="#adjustments"><span>Adjustments</span></a></li>
                        <li class="dd-item"><a href="#effects"><span>Effects</span></a></li>
                        <li class="dd-item"><a href="#encoding"><span>Encoding</span></a></li>
                        <li class="dd-item"><a href="#misc"><span>Miscellaneous</span></a></li>
                    </ul>
                </li>
            </ul>
            <br />
            <div id="footer">
                <p>
                    <a href="https://github.com/weserv/images">Source code available on GitHub</a><br />
                    <a href="https://getgrav.org/">Design inspired by Grav</a><br />
                    <a href="https://github.com/weserv/images/blob/3.x/Privacy-Policy.md">Privacy Policy</a>
                </p>
            </div>
        </div>
    </nav>
    <div id="body">
        <section id="image-api">
            <h1 style="display: none;">Documentation</h1><p>Images.<b>weserv</b>.nl is an image <b>cache</b> &amp; <b>resize</b> proxy. Our servers resize your image, cache it worldwide, and display it. <a class="github-fork-ribbon right-top" href="https://github.com/weserv/images/issues" data-ribbon="Feedback? Github!" title="Feedback? Github!">Feedback? GitHub!</a></p>
            <ul>
                <li>We don't support animated images (yet), but we do support GIF, JPEG, PNG, BMP, XBM, WebP and other filetypes, even transparent images.</li>
                <li>We do support IPv6, <a href="http://ipv6-test.com/validate.php?url=$url" rel="nofollow">serving dual stack</a>, and supporting <a href="https://$url/?url=ipv6.google.com/logos/logo.gif">IPv6-only origin hosts</a>.</li>
                <li>For secure connections over TLS/SSL, you can use <a href="https://$url/"><b>https</b>://$url/</a>. <br /><small class="sslnote">This can be very useful for embedding HTTP images on HTTPS websites. HTTPS origin hosts can be used by <a href="https://github.com/weserv/images/issues/33">prefixing the hostname with ssl:</a></small></li>
                <li>We're part of the <a href="https://www.cloudflare.com/">Cloudflare</a> community. Images are being cached and delivered straight from <a href="https://www.cloudflare.com/network/">150+ global datacenters</a>. This ensures the fastest load times and best performance.</li>
                <li>On average, we resize 2 million (2&times;10<sup>6</sup>) images per hour, which generates around 100TB of outbound traffic per month.</li>
            </ul>
            <p>Requesting an image:</p>
            <ul>
                <li><code>?url=</code> (URL encoded) link to your image, without http://</li>
            </ul>
        </section>
        <section>
            <h1 id="quick-reference">Quick reference</h1>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>GET</th>
                        <th>Description</th>
                        <th>&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Width</td>
                        <td><code>w</code></td>
                        <td>Sets the width of the image, in pixels.</td>
                        <td><a href="#width">info</a></td>
                    </tr>
                    <tr>
                        <td>Height</td>
                        <td><code>h</code></td>
                        <td>Sets the height of the image, in pixels.</td>
                        <td><a href="#height">info</a></td>
                    </tr>
                    <tr>
                        <td>Device pixel ratio</td>
                        <td><code>dpr</code></td>
                        <td>Sets the output density of the image.</td>
                        <td><a href="#dpr">info</a></td>
                    </tr>
                    <tr>
                        <td>Orientation</td>
                        <td><code>or</code></td>
                        <td>Rotates the image.</td>
                        <td><a href="#orientation">info</a></td>
                    </tr>
                    <tr>
                        <td>Transformation</td>
                        <td><code>t</code></td>
                        <td>Sets how the image is fitted to its target dimensions.</td>
                        <td><a href="#trans">info</a></td>
                    </tr>
                    <tr>
                        <td>Crop</td>
                        <td><code>crop</code></td>
                        <td>Crops the image to specific dimensions.</td>
                        <td><a href="#manual-crop">info</a></td>
                    </tr>
                    <tr>
                        <td>Crop alignment</td>
                        <td><code>a</code></td>
                        <td>Sets how the crop is aligned.</td>
                        <td><a href="#crop-position">info</a></td>
                    </tr>
                    <tr>
                        <td>Shape</td>
                        <td><code>shape</code></td>
                        <td>Crops the image to a specific shape.</td>
                        <td><a href="#shape">info</a></td>
                    </tr>
                    <tr>
                        <td>Brightness</td>
                        <td><code>bri</code></td>
                        <td>Adjusts the image brightness.</td>
                        <td><a href="#brightness">info</a></td>
                    </tr>
                    <tr>
                        <td>Contrast</td>
                        <td><code>con</code></td>
                        <td>Adjusts the image contrast.</td>
                        <td><a href="#contrast">info</a></td>
                    </tr>
                    <tr>
                        <td>Gamma</td>
                        <td><code>gam</code></td>
                        <td>Adjusts the image gamma.</td>
                        <td><a href="#gamma">info</a></td>
                    </tr>
                    <tr>
                        <td>Sharpen</td>
                        <td><code>sharp</code></td>
                        <td>Sharpen the image.</td>
                        <td><a href="#sharpen">info</a></td>
                    </tr>
                    <tr>
                        <td>Trim</td>
                        <td><code>trim</code></td>
                        <td>Trim "boring" pixels from all edges.</td>
                        <td><a href="#trim">info</a></td>
                    </tr>
                    <tr>
                        <td>Blur</td>
                        <td><code>blur</code></td>
                        <td>Adds a blur effect to the image.</td>
                        <td><a href="#blur">info</a></td>
                    </tr>
                    <tr>
                        <td>Filter</td>
                        <td><code>filt</code></td>
                        <td>Applies a filter effect to the image.</td>
                        <td><a href="#filter">info</a></td>
                    </tr>
                    <tr>
                        <td>Background</td>
                        <td><code>bg</code></td>
                        <td>Sets the background color of the image.</td>
                        <td><a href="#background">info</a></td>
                    </tr>
                    <tr>
                        <td>Quality</td>
                        <td><code>q</code></td>
                        <td>Defines the quality of the image.</td>
                        <td><a href="#quality">info</a></td>
                    </tr>
                    <tr>
                        <td>Output</td>
                        <td><code>output</code></td>
                        <td>Encodes the image to a specific format.</td>
                        <td><a href="#output">info</a></td>
                    </tr>
                    <tr>
                        <td>Interlace / progressive</td>
                        <td><code>il</code></td>
                        <td>Adds interlacing to GIF and PNG. JPEG's become progressive.</td>
                        <td><a href="#interlace-progressive">info</a></td>
                    </tr>
                    <tr>
                        <td>Base64 (data URL)</td>
                        <td><code>encoding</code></td>
                        <td>Encodes the image to be used directly in the src= of the &lt;img&gt;-tag.</td>
                        <td><a href="#base64">info</a></td>
                    </tr>
                    <tr>
                        <td>Default image</td>
                        <td><code>errorredirect</code></td>
                        <td>Redirects to a default image when there is a problem loading an image.</td>
                        <td><a href="#default">info</a></td>
                    </tr>
                    <tr>
                        <td>Page</td>
                        <td><code>page</code></td>
                        <td>To load a given page.</td>
                        <td><a href="#page">info</a></td>
                    </tr>
                    <tr>
                        <td>Filename</td>
                        <td><code>filename</code></td>
                        <td>To specify the filename.</td>
                        <td><a href="#filename">info</a></td>
                    </tr>
                </tbody>
            </table>
        </section>
        <section>
            <h1 id="size">Size</h1>
            <section>
                <h2 id="width" class="param">Width</h2><code>&amp;w=</code>
                <p>Sets the width of the image, in pixels.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300"&gt;</code></pre>
                <a href="//$url/?url=$exampleImage&amp;w=300"><img src="//$url/?url=$exampleImage&amp;w=300" alt=""/></a>
            </section>
            <section>
                <h2 id="height"  class="param">Height</h2><code>&amp;h=</code>
                <p>Sets the height of the image, in pixels.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;h=300"&gt;</code></pre>
                <a href="//$url/?url=$exampleImage&amp;h=300"><img src="//$url/?url=$exampleImage&amp;h=300" alt=""/></a>
            </section>
            <section>
                <h2 id="dpr" class="param">Device pixel ratio</h2><code>&amp;dpr=</code><span class="new">New!</span>
                <p>The device pixel ratio is used to easily convert between CSS pixels and device pixels. This makes it possible to display images at the correct pixel density on a variety of devices such as Apple devices with Retina Displays and Android devices. You must specify either a width, a height, or both for this parameter to work. Use values between <code>1</code> and <code>8</code>.</p>
                <p>More info: <a href="https://github.com/weserv/images/issues/115">Issue #115 - DPI support</a>.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;h=144&amp;dpr=2"&gt;</code></pre>
                <a href="//$url/?url=$exampleImage&amp;h=144&amp;dpr=2"><img src="//$url/?url=$exampleImage&amp;h=144&amp;dpr=2" alt=""/></a>
            </section>
        </section>
        <section>
            <h1 id="orientation" class="param">Orientation</h1><code>&amp;or=</code><span class="new">New!</span>
            <p>Rotates the image. Accepts <code>auto</code> or if an angle is specified, it is converted to a valid <code>90</code>/<code>180</code>/<code>270</code> degree rotation. For example, <code>-450</code> will produce a <code>270</code> degree rotation. Default is <code>auto</code>. The <code>auto</code> option uses Exif data to automatically orient images correctly.</p>
            <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;h=300&amp;or=90"&gt;</code></pre>
            <a href="//$url/?url=$exampleImage&amp;h=300&amp;or=90"><img src="//$url/?url=$exampleImage&amp;h=300&amp;or=90" alt=""/></a>
        </section>
        <section>
            <h1 id="trans" class="param">Transformation</h1><code>&amp;t=</code>
            <p>Sets how the image is fitted to its target dimensions. Below are a couple of examples.</p>
            <section>
                <h2 id="fit" class="param">Fit</h2><code>&amp;t=fit</code>
                <p>Default. Resizes the image to fit within the width and height boundaries without cropping, distorting or altering the aspect ratio. <b>Will not</b> oversample the image if the requested size is larger than that of the original.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=fit"&gt;</code></pre>
                <a href="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=fit"><img src="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=fit" alt=""/></a>
            </section>
            <section>
                <h2 id="fitup" class="param">Fitup</h2><code>&amp;t=fitup</code>
                <p>Resizes the image to fit within the width and height boundaries without cropping, distorting or altering the aspect ratio. <b>Will</b> increase the size of the image if it is smaller than the output size.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=fitup"&gt;</code></pre>
            </section>
            <section>
                <h2 id="square" class="param">Square</h2><code>&amp;t=square</code>
                <p>Resizes the image to fill the width and height boundaries and crops any excess image data. The resulting image will match the width and height constraints without distorting the image. <b>Will</b> increase the size of the image if it is smaller than the output size.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=square"&gt;</code></pre>
                <a href="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=square"><img src="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=square" alt=""/></a>
            </section>
            <section>
                <h2 id="squaredown" class="param">Squaredown</h2><code>&amp;t=squaredown</code>
                <p>Resizes the image to fill the width and height boundaries and crops any excess image data. The resulting image will match the width and height constraints without distorting the image. <b>Will not</b> oversample the image if the requested size is larger than that of the original.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=squaredown"&gt;</code></pre>
            </section>
            <section>
                <h2 id="absolute" class="param">Absolute</h2><code>&amp;t=absolute</code>
                <p>Stretches the image to fit the constraining dimensions exactly. The resulting image will fill the dimensions, and will not maintain the aspect ratio of the input image.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=absolute"&gt;</code></pre>
                <a href="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=absolute"><img src="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=absolute" alt=""/></a>
            </section>
            <section>
                <h2 id="letterbox" class="param">Letterbox</h2><code>&amp;t=letterbox</code><span class="new">New!</span>
                <p>Resizes the image to fit within the width and height boundaries without cropping or distorting the image, and the remaining space is filled with the background color. The resulting image will match the constraining dimensions.</p>
                <p>More info: <a href="https://github.com/weserv/images/issues/80">Issue #80 - letterbox images that need to fit</a>.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=letterbox&amp;bg=black"&gt;</code></pre>
                <a href="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=letterbox&amp;bg=black"><img src="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=letterbox&amp;bg=black" alt=""/></a>
            </section>
        </section>
        <section>
            <h1 id="crop-position" class="param">Crop position</h1><code>&amp;a=</code>
            <p>You can also set where the image is cropped by adding a crop position. Only works when <code>t=square</code>. Accepts <code>top</code>, <code>left</code>, <code>center</code>, <code>right</code> or <code>bottom</code>. Default is <code>center</code>. For more information, please see the suggestion on our GitHub issue tracker: <a href="https://github.com/weserv/images/issues/24">Issue #24 - Aligning</a>.</p>
            <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=square&amp;a=top"&gt;</code></pre>
            <a href="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=square&amp;a=top"><img src="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=square&amp;a=top" alt=""/></a>
            <section>
                <h2 id="focal-point" class="param">Crop Focal Point</h2><code>&amp;a=crop-x%-y%</code><span class="new">New!</span>
                <p>In addition to the crop position, you can be more specific about the exact crop position using a focal point. Only works when <code>t=square</code>. This is defined using two offset percentages: <code>crop-x%-y%</code>.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=square&amp;a=crop-0-20"&gt;</code></pre>
                <a href="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=square&amp;a=crop-0-20"><img src="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=square&amp;a=crop-0-20" alt=""/></a>
            </section>
            <section>
                <h2 id="smartcrop" class="param">Smart crop</h2><code>&amp;a=entropy</code> or <code>&amp;a=attention</code><span class="new">New!</span>
                <p>Crops the image down to specific dimensions by removing boring parts. Only works when <code>t=square</code>. More info: <a href="https://github.com/weserv/images/issues/90">Issue #90 - Add support for smart crop</a>.</p>
                <h3 id="smartcrop-accepts">Accepts:</h3>
                <ul>
                    <li><code>entropy</code>: focus on the region with the highest <a href="https://en.wikipedia.org/wiki/Entropy_%28information_theory%29">Shannon entropy</a>.</li>
                    <li><code>attention</code>: focus on the region with the highest luminance frequency, colour saturation and presence of skin tones.</li>
                </ul>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleSmartcropImage&amp;w=300&amp;h=300&amp;t=square&amp;a=attention"&gt;</code></pre>
                <a href="//$url/?url=$exampleSmartcropImage&amp;w=300&amp;h=300&amp;t=square&amp;a=attention"><img src="//$url/?url=$exampleSmartcropImage&amp;w=300&amp;h=300&amp;t=square&amp;a=attention" alt=""/></a>
            </section>
            <section>
                <h2 id="manual-crop" class="param">Manual crop</h2><code>&amp;crop=</code>
                <p>Crops the image to specific dimensions after any other resize operations. Required format: <code>width,height,x,y</code>.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;crop=300,300,680,500"&gt;</code></pre>
                <a href="//$url/?url=$exampleImage&amp;crop=300,300,680,500"><img src="//$url/?url=$exampleImage&amp;crop=300,300,680,500" alt=""/></a>
            </section>
        </section>
        <section>
            <h1 id="shape" class="param">Shape</h1><code>&amp;shape=</code>
            <p>Crops the image to a specific shape. Use <code>&amp;strim</code> to also remove the remaining whitespace. More info: <a href="https://github.com/weserv/images/issues/49">Issue #49 - Add circle effect to photos</a>.</p>
            <div class="notices note">
                <div class="heading">Note</div>
                <p>Previously the <code>&amp;strim</code> parameter was enabled by default. In December 2017 it was changed to an optional parameter to be more consistent with other features.</p>
            </div>
            <h2 id="shape-accepts">Accepts:</h2>
            <ul>
                <li><code>circle</code></li>
                <li><code>ellipse</code></li>
                <li><code>triangle</code></li>
                <li><code>triangle-180</code>: Triangle tilted upside down</li>
                <li><code>pentagon</code></li>
                <li><code>pentagon-180</code>: Pentagon tilted upside down</li>
                <li><code>hexagon</code></li>
                <li><code>square</code>: Square tilted 45 degrees</li>
                <li><code>star</code>: 5-point star</li>
                <li><code>heart</code></li>
            </ul>
            <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=square&amp;shape=circle"&gt;</code></pre>
            <a href="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=square&amp;shape=circle"><img src="//$url/?url=$exampleImage&amp;w=300&amp;h=300&amp;t=square&amp;shape=circle" alt=""/></a>
        </section>
        <section>
            <h1 id="adjustments">Adjustments</h1>
            <section>
                <h2 id="brightness" class="param">Brightness</h2><code>&amp;bri=</code><span class="new">New!</span>
                <p>Adjusts the image brightness. Use values between <code>-100</code> and <code>+100</code>, where <code>0</code> represents no change.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300&amp;bri=-25"&gt;</code></pre>
                <a href="//$url/?url=$exampleImage&amp;w=300&amp;bri=-25"><img src="//$url/?url=$exampleImage&amp;w=300&amp;bri=-25" alt=""/></a>
            </section>
            <section>
                <h2 id="contrast" class="param">Contrast</h2><code>&amp;con=</code><span class="new">New!</span>
                <p>Adjusts the image contrast. Use values between <code>-100</code> and <code>+100</code>, where <code>0</code> represents no change.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300&amp;con=25"&gt;</code></pre>
                <a href="//$url/?url=$exampleImage&amp;w=300&amp;con=25"><img src="//$url/?url=$exampleImage&amp;w=300&amp;con=25" alt=""/></a>
            </section>
            <section>
                <h2 id="gamma" class="param">Gamma</h2><code>&amp;gam=</code><span class="new">New!</span>
                <p>Adjusts the image gamma. Use values between <code>1</code> and <code>3</code>. The default value is <code>2.2</code>, a suitable approximation for sRGB images.</p>
                <div class="notices note">
                    <div class="heading">Note</div>
                    <p>The behavior of adjusting the image gamma was changed in December 2017. We apologize for any inconvenience caused.</p>
                </div>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300&amp;gam=3"&gt;</code></pre>
                <a href="//$url/?url=$exampleImage&amp;w=300&amp;gam=3"><img src="//$url/?url=$exampleImage&amp;w=300&amp;gam=3" alt=""/></a>
            </section>
            <section>
                <h2 id="sharpen" class="param">Sharpen</h2><code>&amp;sharp=</code><span class="new">New!</span>
                <p>Sharpen the image. Required format: <code>f,j,r</code></p>
                <h3 id="sharpen-arguments">Arguments:</h3>
                <ul>
                    <li>Flat <code>f</code> - Sharpening to apply to flat areas. (Default: 1.0)</li>
                    <li>Jagged <code>j</code> - Sharpening to apply to jagged areas. (Default: 2.0)</li>
                    <li>Radius <code>r</code> - Sharpening mask to apply in pixels, but comes at a performance cost. (optional)</li>
                </ul>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300&amp;sharp=5,5,3"&gt;</code></pre>
                <a href="//$url/?url=$exampleImage&amp;w=300&amp;sharp=5,5,3"><img src="//$url/?url=$exampleImage&amp;w=300&amp;sharp=5,5,3" alt=""/></a>
            </section>
            <section>
                <h2 id="trim" class="param">Trim</h2><code>&amp;trim=</code>
                <p>Trim "boring" pixels from all edges that contain values within a similarity of the top-left pixel. Trimming occurs before any resize operation. Use values between <code>1</code> and <code>254</code> to define a tolerance level to trim away similar color values. You also can specify just &trim, which defaults to a tolerance level of 10.</p>
                <p>More info: <a href="https://github.com/weserv/images/issues/39">Issue #39 - able to remove black/white whitespace</a>.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleTransparentImage&amp;w=300&amp;trim=10"&gt;</code></pre>
                <a class="trimedges" href="//$url/?url=$exampleTransparentImage&amp;w=300&amp;trim=10"><img src="//$url/?url=$exampleTransparentImage&amp;w=300&amp;trim=10" alt=""/></a>
            </section>
            <section>
                <h2 id="background" class="param">Background</h2><code>&amp;bg=</code><span class="new">New!</span>
                <p>Sets the background color of the image. Supports a variety of color formats. In addition to the 140 color names supported by all modern browsers (listed <a href="//$url/colors.html">here</a>), it also accepts hexadecimal RGB and RBG alpha formats. More info: <a href="https://github.com/weserv/images/issues/81">Issue #81 - Background setting</a>.</p>
                <h3 id="background-hexdec">Hexadecimal</h3>
                <ul>
                    <li>3 digit RGB: <code>CCC</code></li>
                    <li>4 digit ARGB (alpha): <code>5CCC</code></li>
                    <li>6 digit RGB: <code>CCCCCC</code></li>
                    <li>8 digit ARGB (alpha): <code>55CCCCCC</code></li>
                </ul>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleTransparentImage&amp;w=400&amp;bg=black"&gt;</code></pre>
                <a href="//$url/?url=$exampleTransparentImage&amp;w=400&amp;bg=black"><img src="//$url/?url=$exampleTransparentImage&amp;w=400&amp;bg=black" alt=""/></a>
            </section>
        </section>
        <section>
            <h1 id="effects">Effects</h1>
            <section>
                <h2 id="blur" class="param">Blur</h2><code>&amp;blur=</code><span class="new">New!</span>
                <p>Adds a blur effect to the image. Use values between <code>0</code> and <code>100</code>.</p>
                <p>More info: <a href="https://github.com/weserv/images/issues/69">Issue #69 - Allow blur transformation (with radius parameter)</a>.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300&amp;blur=5"&gt;</code></pre>
                <a href="//$url/?url=$exampleImage&amp;w=300&amp;blur=5"><img src="//$url/?url=$exampleImage&amp;w=300&amp;blur=5" alt=""/></a>
            </section>
            <section>
                <h2 id="filter" class="param">Filter</h2><code>&amp;filt=</code><span class="new">New!</span>
                <p>Applies a filter effect to the image. Accepts <code>greyscale</code>, <code>sepia</code> or <code>negate</code>.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300&amp;filt=greyscale"&gt;</code></pre>
                <a href="//$url/?url=$exampleImage&amp;w=300&amp;filt=greyscale"><img src="//$url/?url=$exampleImage&amp;w=300&amp;filt=greyscale" alt=""/></a>
            </section>
        </section>
        <section>
            <h1 id="encoding">Encoding</h1>
            <section>
                <h2 id="quality" class="param">Quality</h2><code>&amp;q=</code>
                <p>Defines the quality of the image. Use values between <code>0</code> and <code>100</code>. Defaults to <code>85</code>. Only relevant if the format is set to <code>jpg</code>.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300&amp;q=20"&gt;</code></pre>
                <a href="//$url/?url=$exampleImage&amp;w=300&amp;q=20"><img src="//$url/?url=$exampleImage&amp;w=300&amp;q=20" alt=""/></a>
            </section>
            <section>
                <h2 id="output" class="param">Output</h2><code>&amp;output=</code>
                <p>Encodes the image to a specific format. Accepts <code>jpg</code>, <code>png</code>, <code>gif</code>, <code>tiff</code> or <code>webp</code>. If none is given, it will honor the origin image format.</p>
                <p>More info: <a href="https://github.com/weserv/images/issues/62">Issue #62 - Format conversion</a>.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300&amp;output=webp"&gt;</code></pre>
                <a href="//$url/?url=$exampleImage&amp;w=300&amp;output=webp"><img src="//$url/?url=$exampleImage&amp;w=300&amp;output=webp" alt=""/></a>
            </section>
            <section>
                <h2 id="interlace-progressive" class="param">Interlace / progressive</h2><code>&amp;il</code>
                <p>Adds interlacing to GIF and PNG. JPEG's become progressive.</p>
                <p>More info: <a href="https://github.com/weserv/images/issues/50">Issue #50 - Add parameter to use progressive JPEGs</a>.</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=$exampleImage&amp;w=300&amp;il"&gt;</code></pre>
                <a href="//$url/?url=$exampleImage&amp;w=300&amp;il"><img src="//$url/?url=$exampleImage&amp;w=300&amp;il" alt=""/></a>
            </section>
            <section>
                <h2 id="base64" class="param">Base64 (data URL)</h2><code>&amp;encoding=base64</code>
                <p>Encodes the image to be used directly in the src= of the <code>&lt;img&gt;</code>-tag. <a href="//$url/?url=$exampleImage&amp;crop=100,100,680,500&amp;encoding=base64">Use this link to see the output result</a>.</p>
                <p>More info: <a href="https://github.com/weserv/images/issues/59">Issue #59 - Return image base64 encoded</a>.</p>
                <pre><code>//$url/?url=$exampleImage&amp;crop=100,100,680,500&amp;encoding=base64</code></pre>
            </section>
        </section>
        <section>
            <h1 id="misc">Miscellaneous</h1>
            <section>
                <h2 id="default" class="param">Default image</h2><code>&amp;errorredirect=</code><span class="new">New!</span>
                <p>If there is a problem loading an image, then a error is shown. However, there might be a need where instead of giving a broken image to the user, you want a default image to be delivered.</p>
                <p>More info: <a href="https://github.com/weserv/images/issues/37">Issue #37 - Return default image if the image's URL not found</a>.</p>
                <p>The URL must not include a <code>errorredirect</code> querystring (if it does, it will be ignored).</p>
                <pre><code class="language-html">&lt;img src="//$url/?url=example.org/noimage.jpg&amp;errorredirect=ssl:$url%2F%3Furl%3D$exampleImage%26w%3D300"&gt;</code></pre>
                <a href="//$url/?url=example.org/noimage.jpg&amp;errorredirect=ssl:$url%2F%3Furl%3D$exampleImage%26w%3D300"><img src="//$url/?url=example.org/noimage.jpg&amp;errorredirect=ssl:$url%2F%3Furl%3D$exampleImage%26w%3D300" alt=""/></a>
            </section>
            <section>
                <h2 id="page" class="param">Page</h2><code>&amp;page=</code><span class="new">New!</span>
                <p>To load a given page (for an PDF, TIFF and multi-size ICO file). The value is numbered from zero.</p>
            </section>
            <section>
                <h2 id="filename" class="param">Filename</h2><code>&amp;filename=</code><span class="new">New!</span>
                <p>To specify the filename returned in the <code>Content-Disposition</code> header. The filename must only contain alphanumeric characters.</p>
                <p>More info: <a href="https://github.com/weserv/images/issues/122">Issue #122 - Specify filename</a>.</p>
            </section>
        </section>
    </div>
    <script src="//cdnjs.cloudflare.com/ajax/libs/gumshoe/3.5.1/js/gumshoe.min.js" integrity="sha384-FoThb3jd5WP5FOlZTJjhjrN9LHoCTPQV9syukPgH4P+NUfvdZfgpHwH/VsQOlx4b" crossorigin="anonymous"></script>
    <script src="//cdn.jsdelivr.net/npm/docsearch.js@2/dist/cdn/docsearch.min.js"></script>
    <script>
        docsearch({
            apiKey: '206416be8208020aafb1730fd2c7d813',
            indexName: 'images_weserv',
            inputSelector: '#search-by',
            debug: false
        });

        document.addEventListener('DOMContentLoaded', function () {
            var header = document.getElementById('image-api');

            gumshoe.init({
                offset: -header.clientHeight,
                callback: function nav(nav) {
                    if (nav) {
                        var title = document.title + ' - ' + nav.target.textContent;
                        var hash = '#' + nav.target.id;
                        var hasChild = window.location.hash ? nav.target.parentNode.querySelector(window.location.hash) != null : false;
                        if (window.location.hash !== hash && !hasChild) {
                            history.replaceState(null, title, window.location.pathname + hash);
                        }
                    }
                }
            });
        });

        window.addEventListener('load', function () {
            gumshoe.setDistances();
        });
    </script>
</body>
</html>
HTML;
    echo $html;
}
