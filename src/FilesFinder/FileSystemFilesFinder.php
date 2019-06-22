<?php
declare(strict_types=1);

namespace LanguageServer\FilesFinder;

use Amp\Delayed;
use function Amp\File\isdir;
use function Amp\File\scandir;
use Webmozart\Glob\Glob;
use function LanguageServer\{pathToUri};

class FileSystemFilesFinder implements FilesFinder
{
    /**
     * Returns all files in the workspace that match a glob.
     * If the client does not support workspace/xfiles, it falls back to searching the file system directly.
     *
     * @param string $glob
     * @return \Generator <File[]>
     */
    public function find(string $glob): \Generator
    {
        $files = [];
        $paths = glob($glob);
        yield new Delayed(0);
        foreach ($paths as $path) {
            if (\is_file($path)) {
                $mtime = stat($path)['mtime'] ?? time();
                $uri = pathToUri($path);
                $files[] = new File($uri, $mtime);
            }
        }
        return $files;
    }
}
