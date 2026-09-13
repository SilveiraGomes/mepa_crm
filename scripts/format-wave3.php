<?php
require dirname(__DIR__).'/apps/api/vendor/autoload.php';
$parser=(new PhpParser\ParserFactory)->createForHostVersion();
$printer=new PhpParser\PrettyPrinter\Standard;
$files=[...glob(dirname(__DIR__).'/apps/api/app/Domain/Events/*.php'),...glob(dirname(__DIR__).'/apps/api/tests/Database/WaveThree*Test.php'),dirname(__DIR__).'/apps/api/tests/Database/Support/WaveThreeCase.php',__DIR__.'/wave3-checkin-worker.php'];
foreach($files as $file)file_put_contents($file,$printer->prettyPrintFile($parser->parse(file_get_contents($file))).PHP_EOL);
