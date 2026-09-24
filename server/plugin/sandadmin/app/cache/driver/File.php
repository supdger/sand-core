<?php

declare(strict_types=1);

namespace plugin\sandadmin\app\cache\driver;

use RuntimeException;
use Webman\ThinkCache\driver\File as ThinkFile;

/**
 * 保留 ThinkCache 文件格式，用同一把锁保护文件读写和标签事务。
 */
class File extends ThinkFile
{
    private bool $locked = false;

    public function synchronized(callable $operation): mixed
    {
        if ($this->locked) {
            return $operation();
        }

        $path = rtrim($this->options['path'], '/\\');
        $parent = dirname($path);
        if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException('Unable to create file-cache lock directory');
        }

        // 放在缓存目录外，clear() 不得删除仍被其他 worker 使用的锁 inode。
        $handle = fopen($path . '.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('Unable to open file-cache lock');
        }

        try {
            $deadline = hrtime(true) + 5_000_000_000;
            while (!flock($handle, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out acquiring file-cache lock');
                }
                usleep(1000);
            }
            $this->locked = true;
            // 长驻进程可能保留其他 worker 清理或更新前的文件状态。
            clearstatcache();
            return $operation();
        } finally {
            $this->locked = false;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    protected function getRaw(string $name)
    {
        // getRaw 也会删除过期文件，必须与 set/clear 共用独占锁。
        return $this->synchronized(fn () => parent::getRaw($name));
    }

    public function set($name, $value, $expire = null): bool
    {
        return $this->synchronized(fn () => parent::set($name, $value, $expire));
    }

    public function delete($name): bool
    {
        return $this->synchronized(fn () => parent::delete($name));
    }

    public function clear(): bool
    {
        return $this->synchronized(fn () => parent::clear());
    }

    public function clearTag($keys): void
    {
        $this->synchronized(fn () => parent::clearTag($keys));
    }

    public function inc($name, $step = 1)
    {
        return $this->synchronized(fn () => parent::inc($name, $step));
    }

    public function push($name, $value): void
    {
        $this->synchronized(fn () => parent::push($name, $value));
    }

    public function append($name, $value): void
    {
        $this->synchronized(function () use ($name, $value): void {
            $items = $this->readTagItems($name);
            if (!in_array($value, $items, true)) {
                $items[] = $value;
                if (!parent::set($name, $items)) {
                    throw new RuntimeException('Unable to write file-cache tag');
                }
            }
        });
    }

    public function getTagItems(string $tag): array
    {
        return $this->synchronized(fn () => $this->readTagItems($this->getTagKey($tag)));
    }

    private function readTagItems(string $name): array
    {
        // 损坏序列化值由下方统一恢复，告警不得包含缓存内容或身份信息。
        $missing = new \stdClass();
        $items = @parent::get($name, $missing);
        if ($items === $missing && !is_file($this->getCacheKey($name))) {
            return [];
        }
        if (is_array($items) && count(array_filter($items, 'is_string')) === count($items)) {
            return $items;
        }

        // 只重置标签会留下无法清理的权限缓存；失效当前文件缓存命名空间。
        parent::clear();
        error_log('[sandadmin] Corrupt file-cache tag rebuilt; file-cache namespace invalidated.');
        return [];
    }

    public function tag($name)
    {
        return new TagSet((array) $name, $this);
    }
}
