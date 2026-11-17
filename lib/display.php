<?php
function displayManifest(string $surface): array {
    if(!in_array($surface,['timer','public'],true))throw new RuntimeException('Unknown display.');
    $base=dirname(__DIR__);$script=$surface==='timer'?'timer':'public';$page=$surface==='timer'?'timer.php':'index.php';
    $files=[$page,'assets/'.$script.'.js','assets/'.$script.'.css','assets/countdown.js'];$hashes=[];
    foreach($files as $file)$hashes[$file]=hash_file('sha256',$base.'/'.$file);
    return ['surface'=>$surface,'revision'=>hash('sha256',json_encode($hashes)),'css'=>'/assets/'.$script.'.css?v='.$hashes['assets/'.$script.'.css'],'scripts'=>['/assets/countdown.js?v='.$hashes['assets/countdown.js'],'/assets/'.$script.'.js?v='.$hashes['assets/'.$script.'.js']]];
}
