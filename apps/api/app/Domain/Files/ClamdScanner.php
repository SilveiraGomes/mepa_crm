<?php

declare(strict_types=1);

namespace App\Domain\Files;

// clamd INSTREAM client over TCP (tcp://host:port) or a UNIX socket (unix:///path). Any connection, protocol or
// timeout problem is SCANNER_UNAVAILABLE (fail closed); only "stream: OK" is clean.
final class ClamdScanner implements FileScanner
{
    public function __construct(private string $address, private int $timeout = 10)
    {
    }

    public function clean(string $bytes): bool
    {
        $socket = @stream_socket_client($this->address, $errno, $error, $this->timeout);
        if ($socket === false) {
            throw new FilesError(FilesReason::SCANNER_UNAVAILABLE, ['reason' => 'connect']);
        }
        try {
            stream_set_timeout($socket, $this->timeout);
            $this->send($socket, "zINSTREAM\0");
            foreach (str_split($bytes, 8192) as $chunk) {
                $this->send($socket, pack('N', strlen($chunk)) . $chunk);
            }
            $this->send($socket, pack('N', 0));
            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
            if (str_ends_with($reply, 'OK')) {
                return true;
            }
            if (str_ends_with($reply, 'FOUND')) {
                return false;
            }
            throw new FilesError(FilesReason::SCANNER_UNAVAILABLE, ['reason' => 'protocol']);
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function send($socket, string $data): void
    {
        if (@fwrite($socket, $data) !== strlen($data)) {
            throw new FilesError(FilesReason::SCANNER_UNAVAILABLE, ['reason' => 'write']);
        }
    }
}
