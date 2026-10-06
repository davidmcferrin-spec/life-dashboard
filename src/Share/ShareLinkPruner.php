<?php

declare(strict_types=1);

namespace Dashboard\Share;

use Dashboard\Config;
use Dashboard\DB\Database;

final class ShareLinkPruner
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return array{clipboard: int, file: int, cloud: int, cloudpad: int, total: int}
     */
    public function purgeExpired(?int $graceHours = null): array
    {
        $hours  = max(1, $graceHours ?? Config::int('SHARE_LINK_PURGE_HOURS', 24));
        $tz     = new \DateTimeZone(Config::get('APP_TIMEZONE', 'America/New_York'));
        $cutoff = (new \DateTimeImmutable('now', $tz))->modify('-' . $hours . ' hours');
        $before = $cutoff->format('Y-m-d H:i:s');

        $clipboard = $this->purgeTable('clipboard_share_links', $before);
        $file      = $this->purgeTable('file_share_links', $before);
        $cloud     = $this->purgeTable('storage_node_share_links', $before);
        $cloudpad  = $this->purgeTable('cloudpad_share_links', $before);

        return [
            'clipboard' => $clipboard,
            'file'      => $file,
            'cloud'     => $cloud,
            'cloudpad'  => $cloudpad,
            'total'     => $clipboard + $file + $cloud + $cloudpad,
        ];
    }

    private function purgeTable(string $table, string $cutoff): int
    {
        if (!$this->db->tableExists($table)) {
            return 0;
        }

        return $this->db->execute(
            "DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < ?",
            [$cutoff],
        );
    }
}
