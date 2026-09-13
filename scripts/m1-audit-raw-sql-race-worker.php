<?php

declare(strict_types=1);

// P0.3.2-M1-R: adversarial worker that bypasses TransferService entirely and writes directly
// via raw SQL - proves the DB constraint alone (not the application layer) preserves the
// invariant, per the audit brief's section 6-B requirement ("testar uma situação adversarial em
// que a aplicação não faz o pre-check esperado. A BD ainda deve preservar o invariant.").

[, $membershipId, $originUnitId, $destinationUnitId, $workflowInstanceId, $readyFile, $goFile, $resultFile] = $argv;

$pdo = new PDO(
    'mysql:host=' . (getenv('W2_HOST') ?: '127.0.0.1') . ';port=' . (getenv('W2_PORT') ?: 3306) . ';dbname=' . getenv('W2_DB'),
    getenv('W2_USER') ?: 'root', getenv('W2_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

touch($readyFile);
$deadline = microtime(true) + 15.0;
while (!file_exists($goFile)) {
    if (microtime(true) > $deadline) {
        file_put_contents($resultFile, json_encode(['ok' => false, 'error' => 'BARRIER_TIMEOUT']));
        exit(0);
    }
    usleep(2000);
}

try {
    $publicId = bin2hex(random_bytes(13));
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');
    $stmt = $pdo->prepare('INSERT INTO transfers (public_id, membership_id, origin_unit_id, destination_unit_id, requested_at, effective_at, closed_at, status, workflow_instance_id, created_at) VALUES (?,?,?,?,?,NULL,NULL,?,?,?)');
    $stmt->execute([$publicId, (int) $membershipId, (int) $originUnitId, (int) $destinationUnitId, $now, 'PENDING', (int) $workflowInstanceId, $now]);
    file_put_contents($resultFile, json_encode(['ok' => true, 'public_id' => $publicId]));
} catch (Throwable $e) {
    file_put_contents($resultFile, json_encode(['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()]));
}
