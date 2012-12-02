<?php
/**
 * xhrproxy.php - A simple pass-through proxy for cross-domain XHR requests
 */

function curl($url){
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}

$reqUrl = urldecode($_GET["url"]);
echo curl(str_replace(" ", "+", $reqUrl));

?>