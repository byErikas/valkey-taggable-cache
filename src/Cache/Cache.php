<?php

namespace ByErikas\ClassicTaggableCache\Cache;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\WritingKey;
use Symfony\Contracts\Cache\ItemInterface;
use Illuminate\Support\InteractsWithTime;
use Illuminate\Cache\RedisTaggedCache as BaseTaggedCache;
use Illuminate\Support\Carbon;
use function Illuminate\Support\defer;

class Cache extends BaseTaggedCache
{
    use InteractsWithTime;

    public const DEFAULT_CACHE_TTL = 8640000;

    protected const RESERVED_CHARACTERS_MAP = [
        ":"     => ".",
        "@"     => "\1",
        "("     => "\2",
        ")"     => "\3",
        "{"     => "\4",
        "}"     => "\5",
        "/"     => "\6",
        "\\"    => "\7"
    ];

    /**
     * {@inheritDoc}
     */
    public function get($key, $default = null): mixed
    {
        if (is_array($key)) {
            return $this->many($key);
        }

        $key = self::cleanKey($key);

        $this->event(new RetrievingKey($this->getName(), $key, $this->tags->getNames()));
        $value = null;

        /**
         * @disregard P1013 - @var \TagSet
         */
        $this->tags->entries()->each(function ($item) use ($key, &$value) {
            $itemKey = str($item)->after(TagSet::KEY_PREFIX);

            if ($itemKey == $key) {
                $value = $this->store->get($item);
                return false;
            }
        });

        if (!is_null($value)) {
            $this->event(new CacheHit($this->getName(), $key, $value, $this->tags->getNames()));
            return $value;
        }

        $this->event(new CacheMissed($this->getName(), $key, $this->tags->getNames()));
        return value($default);
    }

    /**
     * {@inheritDoc}
     */
    public function add($key, $value, $ttl = null)
    {
        $existingKey = false;
        /**
         * @disregard P1013 - @var \TagSet
         */
        $this->tags->entries()->each(function ($item) use (&$key, &$existingKey) {
            $itemKey = str($item)->after(TagSet::KEY_PREFIX);

            if ($itemKey == $key) {
                $key = $item;
                $existingKey = true;
                return false;
            }
        });

        if ($existingKey) {
            return false;
        }

        return $this->put($key, $value, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function put($key, $value, $ttl = null)
    {
        $baseKey = self::cleanKey($key);
        $key = self::cleanKey($baseKey);

        $existingKey = false;
        /**
         * @disregard P1013 - @var \TagSet
         */
        $this->tags->entries()->each(function ($item) use (&$key, &$existingKey) {
            $itemKey = str($item)->after(TagSet::KEY_PREFIX);

            if ($itemKey == $key) {
                $key = $item;
                $existingKey = true;
                return false;
            }
        });

        $key = $existingKey == true ? $key : $this->itemKey($baseKey);

        if (is_null($ttl)) {
            return $this->forever($key, $value);
        }

        $seconds = $this->getSeconds($ttl);

        if ($seconds > 0) {
            /**
             * @disregard P1013 - @var \TagSet
             */
            $this->tags->addEntry($key, $seconds);
        }

        return $this->putCache($key, $value, $ttl);
    }

    /**
     * Retrieve an item from the cache by key, refreshing it in the background if it is stale.
     *
     * @template TCacheValue
     *
     * @param  string  $key
     * @param  array{ 0: \DateTimeInterface|\DateInterval|int, 1: \DateTimeInterface|\DateInterval|int }  $ttl
     * @param  (callable(): TCacheValue)  $callback
     * @param  array{ seconds?: int, owner?: string }|null  $lock
     * @return TCacheValue
     */
    public function flexible($key, $ttl, $callback, $lock = null)
    {
        $key = self::cleanKey($key);

        [
            $key => $value,
            "illuminate.cache.flexible.created.{$key}" => $created,
        ] = $this->many([$key, "illuminate.cache.flexible.created.{$key}"]);

        if (in_array(null, [$value, $created], true)) {
            return tap(value($callback), fn($value) => $this->putMany([
                $key => $value,
                "illuminate.cache.flexible.created.{$key}" => Carbon::now()->getTimestamp(),
            ], $ttl[1]));
        }

        if (($created + $this->getSeconds($ttl[0])) > Carbon::now()->getTimestamp()) {
            return $value;
        }

        $refresh = function () use ($key, $ttl, $callback, $lock, $created) {
            /** @disregard P1013 */
            $this->store->lock(
                "illuminate.cache.flexible.lock.{$key}",
                $lock['seconds'] ?? 0,
                $lock['owner'] ?? null,
            )->get(function () use ($key, $callback, $created, $ttl) {
                if ($created !== $this->get("illuminate.cache.flexible.created.{$key}")) {
                    return;
                }

                $this->putMany([
                    $key => value($callback),
                    "illuminate.cache.flexible.created.{$key}" => Carbon::now()->getTimestamp(),
                ], $ttl[1]);
            });
        };

        defer($refresh, "illuminate.cache.flexible.{$key}");

        return $value;
    }

    #region Helpers

    /**
     * {@inheritdoc}
     */
    protected function itemKey($key)
    {
        /** @disregard P1013 */
        return "\0tagged" . $this->tags->tagNamePrefix() . TagSet::KEY_PREFIX . "{$key}";
    }

    /**
     * PSR-6 doesn't allow '{}()/\@:' as cache keys, replace with unique map.
     */
    public static function cleanKey(?string $key): string
    {
        return str_replace(str_split(ItemInterface::RESERVED_CHARACTERS), Cache::RESERVED_CHARACTERS_MAP, $key);
    }

    #endregion

    #region logic overrides

    /**
     * {@inheritDoc}
     */
    public function forever($key, $value)
    {
        /**@disregard P1013 */
        $this->tags->addEntry($key);

        $this->event(new WritingKey($this->getName(), $key, $value, null, $this->tags->getNames()));

        $result = $this->store->forever($key, $value);

        if ($result) {
            $this->event(new KeyWritten($this->getName(), $key, $value, null, $this->tags->getNames()));
        } else {
            $this->event(new KeyWriteFailed($this->getName(), $key, $value, null, $this->tags->getNames()));
        }

        return $result;
    }

    /**
     * Store an item in the cache.
     *
     * @param  array|string  $key
     * @param  mixed  $value
     * @param  \DateTimeInterface|\DateInterval|int|null  $ttl
     * @return bool
     */
    public function putCache($key, $value, $ttl = null)
    {
        if (is_array($key)) {
            return $this->putMany($key, $value);
        }

        if ($ttl === null) {
            return $this->forever($key, $value);
        }

        $seconds = $this->getSeconds($ttl);

        if ($seconds <= 0) {
            return $this->forget($key);
        }

        $this->event(new WritingKey($this->getName(), $key, $value, $seconds, $this->tags->getNames()));

        $result = $this->store->put($key, $value, $seconds);

        if ($result) {
            $this->event(new KeyWritten($this->getName(), $key, $value, $seconds), $this->tags->getNames());
        } else {
            $this->event(new KeyWriteFailed($this->getName(), $key, $value, $seconds, $this->tags->getNames()));
        }

        return $result;
    }
    #endregion
}
