<?php

declare(strict_types=1);

namespace plugin\sandadmin\app\cache\driver;

use RuntimeException;
use Webman\ThinkCache\TagSet as ThinkTagSet;

class TagSet extends ThinkTagSet
{
    public function __construct(array $tag, private File $file)
    {
        parent::__construct($tag, $file);
    }

    public function set($name, $value, $expire = null): bool
    {
        return $this->file->synchronized(function () use ($name, $value, $expire): bool {
            // 先恢复损坏标签，防止恢复时连同本次新值一起清除。
            foreach ($this->tag as $tag) {
                $this->file->getTagItems($tag);
            }
            if (!$this->file->set($name, $value, $expire)) {
                return false;
            }
            $this->append($name);
            return true;
        });
    }

    public function append(string $name): void
    {
        $this->file->synchronized(fn () => parent::append($name));
    }

    public function remember($name, $value, $expire = null)
    {
        // 上游负责计算；业务闭包不持有 store 锁，避免等待其他 worker 时互锁。
        $result = $this->file->remember($name, $value, $expire);
        return $this->file->synchronized(function () use ($name, $result, $expire) {
            foreach ($this->tag as $tag) {
                $this->file->getTagItems($tag);
            }
            $current = $this->file->get($name);
            if ($current === null) {
                if (!$this->file->set($name, $result, $expire)) {
                    throw new RuntimeException('Unable to write remembered file-cache value');
                }
                $current = $result;
            }
            $this->append($name);
            return $current;
        });
    }

    public function clear(): bool
    {
        return $this->file->synchronized(fn () => parent::clear());
    }
}
