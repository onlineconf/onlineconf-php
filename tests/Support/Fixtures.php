<?php

declare(strict_types=1);

namespace Onlineconf\Tests\Support;

use Onlineconf\Cdb\CdbWriter;
use Onlineconf\Cdb\ConfWriter;
use Onlineconf\Source\ArraySource;

/**
 * Anonymized data modelled on a real onlineconf-updater output.
 */
final class Fixtures
{
    /**
     * All sample modules by name; examples/onlineconf/*.cdb are built from these.
     *
     * @return array<string, array<string, string|int|float|bool|array<mixed>|null>>
     */
    public static function modules(): array
    {
        return ['TREE' => self::tree(), 'legacy' => self::dotNotation()];
    }

    /**
     * A TREE-like module: child lists, empty values, JSON objects and arrays, values longer than 128 bytes,
     * an intermediate node with its own value, a node with numeric child names.
     *
     * @return array<string, string|int|float|bool|array<mixed>|null>
     */
    public static function tree(): array
    {
        return [
            '/app/database/redis/read_timeout' => '0.5',
            '/app/database/redis/hosts' => 'redis-1.example.com:6379, redis-2.example.com:6379',
            '/app/global_settings/first_test_flag' => '1',
            '/app/global_settings/some_user_id' => '1479',
            '/app/global_settings/show_message' => '0',
            '/app/global_settings/banner' => 'true',
            '/app/hosts/int-api' => 'api-int.example.com',
            '/app/hosts/main' => 'www.example.com',
            '/app/nginx/anti-ddos' => '1',
            '/app/nginx/anti-ddos/cookie-ttl' => '7200',
            '/app/nginx/anti-ddos/cooling-period' => '120',
            '/app/nginx/anti-ddos/debug' => '0',
            '/app/nginx/anti-ddos/enabled' => '1',
            '/app/nginx/anti-ddos/heating-period' => '3',
            '/app/nginx/geo/country/allow' => null,
            '/app/nginx/geo/country/deny' => '',
            '/app/services/billing/admin_emails' => ['user@example.com', 'admin@example.com'],
            '/app/services/billing/logging/config' => [
                'version' => 1,
                'disable_existing_loggers' => false,
                'formatters' => ['simple' => ['format' => '%(asctime)-1s [%(name)s] %(levelname)s %(message)s']],
                'handlers' => ['console' => ['class' => 'logging.StreamHandler', 'formatter' => 'simple', 'level' => 'DEBUG']],
                'root' => ['handlers' => ['console'], 'level' => 'INFO'],
            ],
            '/app/services/billing/oauth/google/dialog_uri' => 'https://accounts.example.com/o/oauth2/v2/auth?client_id=000000000000-abcdefghijklmnopqrstuvwxyz0123456789.apps.example.com&response_type=code&scope=openid%20email%20profile&access_type=offline&prompt=consent',
            '/app/services/billing/timeout' => '30s',
            '/app/services/billing/retry_delay' => '300ms',
            '/app/services/gateway/client_settings' => [
                'status' => 'operational',
                'apps' => [
                    'ios' => ['min_version' => '0.0.0'],
                    'android' => ['min_version' => '0.0.0', 'market_uri' => ''],
                    'desktop' => ['min_version' => '1.2.3'],
                ],
            ],
            '/app/services/gateway/admin_emails' => 'user@example.com,admin@example.com',
            '/app/services/gateway/shards' => ['a', 'b', 'c'],
            '/app/shards/0/host' => 'shard-0.example.com',
            '/app/shards/0/weight' => '2',
            '/app/shards/1/host' => 'shard-1.example.com',
            '/app/shards/1/weight' => '1',
            '/app/shards/2/host' => 'shard-2.example.com',
            '/app/shards/2/weight' => '1',
        ];
    }

    /**
     * A legacy module with dot-notation keys and no child lists.
     *
     * @return array<string, string>
     */
    public static function dotNotation(): array
    {
        return [
            'db.database' => 'sapp_listener',
            'db.host' => 'db.example.com',
            'db.port' => '3306',
            'db.user' => 'listener',
            'debug' => '0',
            'lock_timeout' => '5',
            'total_shards' => '4',
            'lib.graphite.carbon.port' => '2003',
            'lib.graphite.process_prefix' => '',
            'wss_conn_timeout' => '5',
        ];
    }

    /**
     * Writes the sample module as <basePath>.cdb and <basePath>.conf; returns its raw values.
     *
     * @return array<string, string>
     */
    public static function build(string $name, string $basePath): array
    {
        $raw = ArraySource::rawFromValues(self::modules()[$name]);
        CdbWriter::write($basePath . '.cdb', $raw);
        ConfWriter::write($basePath . '.conf', $name, $raw, '2026-09-07 00:00:00');

        return $raw;
    }
}
