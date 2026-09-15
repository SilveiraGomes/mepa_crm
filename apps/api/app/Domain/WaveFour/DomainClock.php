<?php

declare (strict_types=1);
namespace App\Domain\WaveFour;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
final class DomainClock
{
    public static function now(Connection $db): DateTimeImmutable
    {
        return new DateTimeImmutable($db->selectOne('SELECT UTC_TIMESTAMP(6) AS current_utc')->current_utc, new DateTimeZone('UTC'));
    }
}
