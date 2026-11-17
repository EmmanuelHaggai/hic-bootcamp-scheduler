<?php
header('Content-Type: application/json');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
require __DIR__.'/lib/display.php';
$surface=$_GET['surface']??'timer';if(!in_array($surface,['timer','public'],true)){http_response_code(400);echo '{"error":"Unknown display"}';exit;}
echo json_encode(displayManifest($surface),JSON_THROW_ON_ERROR);
