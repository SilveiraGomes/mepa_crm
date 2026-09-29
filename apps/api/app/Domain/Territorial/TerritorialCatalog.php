<?php

declare(strict_types=1);

namespace App\Domain\Territorial;

use Illuminate\Database\Connection;

final class TerritorialCatalog
{
    public const DATA_TYPE = 'TERRITORIAL';
    public const VIEW = 'TERRITORIAL_VIEW';
    public const MANAGE = 'TERRITORIAL_MANAGE';
    public const MOVE = 'TERRITORIAL_MOVE';
    public const LIFECYCLE = 'TERRITORIAL_LIFECYCLE';
    public const PERMISSIONS = [self::VIEW, self::MANAGE, self::MOVE, self::LIFECYCLE];
    public const STATUSES = ['DRAFT', 'ACTIVE', 'CLOSED'];
    public const TYPE_LABELS = [
        'GENERAL_DIRECTION' => 'Direcção Geral',
        'REGIONAL_DIRECTION' => 'Direcção Regional',
        'PROVINCIAL_DIRECTION' => 'Direcção Provincial',
        'MUNICIPAL_DIRECTION' => 'Direcção Municipal',
        'GENERAL_CENTER' => 'Centro Geral',
        'CENTER' => 'Centro',
        'CONGREGATION' => 'Congregação',
    ];

    /** Installs only application permissions and the pre-approved serialization anchor. */
    public static function install(Connection $db): array
    {
        return $db->transaction(function () use ($db): array {
            $now = (string) $db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
            $inserted = [];
            foreach (self::PERMISSIONS as $code) {
                if (!$db->table('permissions')->where('code', $code)->exists()) {
                    $db->table('permissions')->insert([
                        'code' => $code, 'action' => $code, 'data_type' => self::DATA_TYPE,
                        'maximum_classification' => 'INTERNAL', 'created_at' => $now, 'lock_version' => 0,
                    ]);
                    $inserted[] = 'permissions:' . $code;
                }
            }
            if (!$db->table('organizational_structure_lock')->where('code', 'NATIONAL_TREE')->exists()) {
                $db->table('organizational_structure_lock')->insert(['code' => 'NATIONAL_TREE', 'created_at' => $now, 'lock_version' => 0]);
                $inserted[] = 'organizational_structure_lock:NATIONAL_TREE';
            }
            return $inserted;
        });
    }
}
