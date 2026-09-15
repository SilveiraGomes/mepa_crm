<?php
require dirname(__DIR__,2).'/apps/api/vendor/autoload.php';
$parser=(new PhpParser\ParserFactory)->createForHostVersion(); $printer=new PhpParser\PrettyPrinter\Standard;
foreach(['apps/api/app/Domain/WaveFour/ChildParticipationSafetyGate.php', 'apps/api/app/Domain/WaveFour/ChildrenService.php', 'apps/api/app/Domain/WaveFour/DiscipleshipEnrollmentScope.php', 'apps/api/app/Domain/WaveFour/DomainAccess.php', 'apps/api/app/Domain/WaveFour/DomainClock.php', 'apps/api/app/Domain/WaveFour/DomainError.php', 'apps/api/app/Domain/WaveFour/DomainPolicy.php', 'apps/api/app/Domain/WaveFour/EvangelismService.php', 'apps/api/app/Domain/Events/CheckinService.php'] as $file) file_put_contents($file,$printer->prettyPrintFile($parser->parse(file_get_contents($file))).PHP_EOL);
