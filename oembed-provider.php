<?php
/*
    A simple oEmbed provider api.
*/
define('OE_PROVIDER_NAME','example-oembed-provider');
define('OE_PROVIDER_URL','https://embed.example.com/');
define('OE_PROVIDER_VERSION','1.0');
define('OE_PROVIDER_SCHEMES', ['https://*.example.com/*', 'https://example.com/*']);
define('OE_PROVIDER_DEFAULT_WIDTH', 600);
define('OE_PROVIDER_DEFAULT_HEIGHT', 400);
define('OE_PROVIDER_DEFAULT_FORMAT', 'json');

define('ERR_MSG', [
    400 => 'Bad Request',
    401 => 'Unauthorized',
    404 => 'Not Found',
    501 => 'Not Implemented'
]);

function err_response($code, $format){
    http_response_code($code);
    if($format === 'json'){
        header('Content-Type: application/json');
        echo json_encode(['error' => $code, 'message' => ERR_MSG[$code] ?? 'Unknown Error']);
    } else if($format === 'xml'){
        header('Content-Type: application/xml');
        $xml = new SimpleXMLElement('<error/>');
        $xml->addChild('code', $code);
        $xml->addChild('message', ERR_MSG[$code] ?? 'Unknown Error');
        echo $xml->asXML();
    }
    exit;
}

function convert_schemes($scheme_arr){
    $converted = [];
    foreach($scheme_arr as $scheme){
        $scheme = str_replace('\\*', '.*', preg_quote($scheme, '/'));
        $converted[] = '/^' . $scheme . '$/';
    }
    return $converted;
}

function is_valid_scheme($url, $converted_schemes){
    foreach($converted_schemes as $scheme){
        if(preg_match($scheme, $url)){
            return true;
        }
    }
    return false;
}

function info_extractor($url,$type){
    if($type === 'link' || $type === 'rich'){
        $html = file_get_contents($url);
    }
    if($type === 'link'){
        if(preg_match('/<meta\s+property=["\']og:image["\']\s+content=["\'](.*?)["\']\s*\/?>/is', $html, $matches)){
            $ogp_image = $matches[1];
        }
    }
    if($type === 'rich' || $type === 'link'){
        if(preg_match('/<title\s?.*?>(.*?)<\/title>/is', $html, $matches)){
            return [$matches[1], $ogp_image ?? ''];
        }
    }else{
        $title = basename(parse_url($url, PHP_URL_PATH));
        if($title === '' || empty($title)){
            $title = 'Untitled';
        }
        return [$title, $ogp_image ?? ''];
    }
}

if(!isset($_GET['format'])){
    $format = OE_PROVIDER_DEFAULT_FORMAT;
} else {
    $format = $_GET['format'];
    if($format !== 'json' && $format !== 'xml'){
        err_response(501, $format);
    }
}

if(!isset($_GET['url'])){
    err_response(400, $format);
}
$url = urldecode($_GET['url']);

$converted_schemes = convert_schemes(OE_PROVIDER_SCHEMES);

if(!is_valid_scheme($url, $converted_schemes)){
    err_response(400, $format);
}

$target_headers = get_headers($url, 1);
if($target_headers === false){
    err_response(400, $format);
}
if(strpos($target_headers[0], '200 OK') === false){
    if(strpos($target_headers[0], '404 Not Found') !== false) err_response(404, $format);
    if(strpos($target_headers[0], '401 Unauthorized') !== false) err_response(401, $format);
    err_response(400, $format);
}
$target_mime = $target_headers['Content-Type'] ?? '';
if($target_mime === '' || empty($target_mime)){
    err_response(400, $format);
}
$type = '';
if(str_contains($target_mime, 'text/html')){
    $type = 'rich';
    if(isset($_GET['type']) && $_GET['type'] === 'link'){
        $type = 'link';
    }
} else if(str_contains($target_mime, 'image/')){
    $type = 'photo';
} else if(str_contains($target_mime, 'video/')){
    $type = 'video';
} else {
    $type = 'link';
}

if(!isset($_GET['width']) && !isset($_GET['maxwidth'])){
    $width = OE_PROVIDER_DEFAULT_WIDTH;
} else if(is_numeric($_GET['maxwidth']) && $_GET['maxwidth'] > 0){
    $width = intval($_GET['maxwidth']);
} else if(is_numeric($_GET['width']) && $_GET['width'] > 0){
    $width = intval($_GET['width']);
} else {
    $width = OE_PROVIDER_DEFAULT_WIDTH;
}

if(!isset($_GET['height']) && !isset($_GET['maxheight'])){
    $height = OE_PROVIDER_DEFAULT_HEIGHT;
} else if(is_numeric($_GET['maxheight']) && $_GET['maxheight'] > 0){
    $height = intval($_GET['maxheight']);
} else if(is_numeric($_GET['height']) && $_GET['height'] > 0){
    $height = intval($_GET['height']);
} else {
    $height = OE_PROVIDER_DEFAULT_HEIGHT;
}

[$title, $ogp_image] = info_extractor($url, $type);

$response = [
    'version' => OE_PROVIDER_VERSION,
    'type' => $type,
    'title' => $title,
    'provider_name' => OE_PROVIDER_NAME,
    'provider_url' => OE_PROVIDER_URL
];
if($type === 'photo'){
    $response['url'] = $url;
    $response['width'] = $width;
    $response['height'] = $height;
}
if($type === 'video'){
    $response['html'] = '<video src="' . $url . '" width="' . $width . '" height="' . $height . '" controls></video>';
    $response['width'] = $width;
    $response['height'] = $height;
}
if($type === 'rich'){
    $response['html'] = '<iframe src="' . $url . '" width="' . $width . '" height="' . $height . '" frameborder="0" allowfullscreen></iframe>';
    $response['width'] = $width;
    $response['height'] = $height;
}
if($type === 'link'){
    if($ogp_image !== '' && !empty($ogp_image)){
        $response['thumbnail_url'] = $ogp_image;
        $response['thumbnail_width'] = $width;
        $response['thumbnail_height'] = $height;
    }
}

if(isset($_GET['author_name'])){
    $response['author_name'] = $_GET['author_name'];
}
if(isset($_GET['author_url'])){
    $response['author_url'] = $_GET['author_url'];
}

if($format === 'json'){
    header('Content-Type: application/json');
    echo json_encode($response);
} else if($format === 'xml'){
    header('Content-Type: application/xml');
    $xml = new SimpleXMLElement('<oembed/>');
    array_walk_recursive($response, function($value, $key) use ($xml){
        $xml->addChild($key, htmlspecialchars($value));
    });
    echo $xml->asXML();
}

?>

