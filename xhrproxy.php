<?php
/**
 * xhrproxy.php - A simple pass-through proxy for cross-domain XHR requests
 */

echo file_get_contents(urldecode($_GET['url']));
?>