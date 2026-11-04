<?php
/**
 * 雪花算法（Snowflake）ID 生成：保证并发下不重复。
 *
 * 布局（64-bit，最高位为 0）：
 * | 1 bit unused | 41 bit timestamp(ms) | 10 bit worker | 12 bit sequence |
 */

defined('ABSPATH') || exit;

class Paykka_Snowflake
{
    /** @var int 自定义纪元：2024-01-01 00:00:00 UTC（毫秒） */
    const EPOCH_MS = 1704067200000;

    const WORKER_BITS = 10;
    const SEQ_BITS    = 12;

    const WORKER_MAX = 1023;  // 2^10 - 1
    const SEQ_MAX    = 4095;  // 2^12 - 1

    const OPTION_STATE = 'paykka_snowflake_state';
    const LOCK_NAME    = 'paykka_snowflake';

    /**
     * 生成下一个唯一雪花 ID（十进制字符串）。
     *
     * @return string
     */
    public static function next_id()
    {
        if (PHP_INT_SIZE < 8) {
            return self::fallback_unique_id();
        }

        $locked = self::acquire_lock(3);
        if (!$locked) {
            // 拿不到锁绝不走共享 state，避免并发撞号
            return self::fallback_unique_id();
        }

        try {
            $state = get_option(self::OPTION_STATE, array());
            if (!is_array($state)) {
                $state = array();
            }
            $last_ms  = isset($state['ms']) ? (int) $state['ms'] : 0;
            $last_seq = isset($state['seq']) ? (int) $state['seq'] : 0;

            $ms = self::current_ms();
            if ($ms < $last_ms) {
                $ms = self::wait_until_ms($last_ms);
            }

            if ($ms === $last_ms) {
                $seq = ($last_seq + 1) & self::SEQ_MAX;
                if ($seq === 0) {
                    $ms  = self::wait_until_ms($last_ms + 1);
                    $seq = 0;
                }
            } else {
                $seq = 0;
            }

            update_option(
                self::OPTION_STATE,
                array(
                    'ms'  => $ms,
                    'seq' => $seq,
                ),
                false
            );
        } finally {
            self::release_lock();
        }

        $worker    = self::worker_id();
        $timestamp = $ms - self::EPOCH_MS;
        if ($timestamp < 0) {
            $timestamp = 0;
        }

        $id = ($timestamp << (self::WORKER_BITS + self::SEQ_BITS))
            | (($worker & self::WORKER_MAX) << self::SEQ_BITS)
            | ($seq & self::SEQ_MAX);

        return (string) $id;
    }

    /**
     * worker：机器标识（不含 pid，避免 FPM 进程回收后误判）。
     *
     * @return int 0–1023
     */
    private static function worker_id()
    {
        $parts = array(
            isset($_SERVER['SERVER_ADDR']) ? (string) $_SERVER['SERVER_ADDR'] : '127.0.0.1',
            function_exists('gethostname') ? (string) gethostname() : 'host',
            defined('DB_NAME') ? (string) DB_NAME : 'wp',
        );
        return (int) (abs(crc32(implode('|', $parts))) % (self::WORKER_MAX + 1));
    }

    private static function current_ms()
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * @param int $target_ms
     * @return int
     */
    private static function wait_until_ms($target_ms)
    {
        $target_ms = (int) $target_ms;
        $ms = self::current_ms();
        $guard = 0;
        while ($ms < $target_ms && $guard < 5000) {
            usleep(200);
            $ms = self::current_ms();
            $guard++;
        }
        return $ms < $target_ms ? $target_ms : $ms;
    }

    /**
     * @param int $timeout_sec
     * @return bool
     */
    private static function acquire_lock($timeout_sec = 3)
    {
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
            $got = $wpdb->get_var(
                $wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::LOCK_NAME, (int) $timeout_sec)
            );
            return (string) $got === '1';
        }

        // 无 $wpdb 时（极少）：option 占坑
        $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('lk', true);
        $key   = 'paykka_snowflake_lock';
        for ($i = 0; $i < 30; $i++) {
            if (add_option($key, $token, '', 'no')) {
                update_option($key . '_ts', time(), false);
                return true;
            }
            $ts = (int) get_option($key . '_ts', 0);
            if ($ts <= 0 || (time() - $ts) > 2) {
                delete_option($key);
                delete_option($key . '_ts');
                continue;
            }
            usleep(2000);
        }
        return false;
    }

    private static function release_lock()
    {
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'query')) {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK_NAME));
            return;
        }
        delete_option('paykka_snowflake_lock');
        delete_option('paykka_snowflake_lock_ts');
    }

    /**
     * 锁失败 / 32 位环境兜底：不与雪花共享 state，带高熵后缀。
     *
     * @return string
     */
    private static function fallback_unique_id()
    {
        return '9' . str_replace('.', '', uniqid((string) self::current_ms(), true))
            . (string) wp_rand(10000, 99999);
    }
}
