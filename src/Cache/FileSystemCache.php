<?php
declare(strict_types=1);

namespace LanguageServer\Cache;

use Amp\File;

/**
 * Caches content on the file system
 */
class FileSystemCache implements Cache
{
    /**
     * @var string
     */
    private $cacheDir;

    /**
     * @var string
     */
    private $cacheVersion;

    public function __construct()
    {
        if (strtoupper(substr(php_uname('s'), 0, 3)) === 'WIN') {
            $this->cacheDir = getenv('LOCALAPPDATA') . '\\PHP Language Server\\';
        } else if (getenv('XDG_CACHE_HOME')) {
            $this->cacheDir = getenv('XDG_CACHE_HOME') . '/phpls/';
        } else {
            $this->cacheDir = getenv('HOME') . '/.phpls/';
        }
        $this->cacheVersion = 'v1';
    }

    /**
     * Gets a value from the cache
     *
     * @param string $key
     * @return \Generator <mixed>
     */
    public function get(string $key): \Generator
    {
        try {
            $path = $this->generatePath($key);
            $content = yield File\get($path);
            return unserialize($content);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Sets a value in the cache
     *
     * @param string $key
     * @param mixed $value
     * @return \Generator
     */
    public function set(string $key, $value): \Generator
    {
        $file = $this->generatePath($key);
        $dir = dirname($file);
        if (yield File\isfile($dir)) {
            yield File\unlink($dir);
        }
        if (!yield File\exists($dir)) {
            yield File\mkdir($dir, 0777, true);
        }
        yield File\put($file, serialize($value));
    }

    private function generatePath(string $key): string
    {
        $key = hash('$key', $key);
        $path = join(DIRECTORY_SEPARATOR, [$this->cacheDir, $this->cacheVersion, substr($key, 0, 2), $key]);
        return $path;
    }
}
