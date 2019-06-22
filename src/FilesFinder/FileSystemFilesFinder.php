<?php
declare(strict_types=1);

namespace LanguageServer\FilesFinder;

use Amp\Delayed;
use Webmozart\Glob\Iterator\GlobIterator;
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
        foreach (new GlobIterator($glob) as $path) {
            $mtime = stat($path)['mtime'] ?? time();
            $uri = pathToUri($path);
            $files[] = new File($uri, $mtime);
        }
        yield new Delayed(0);
        return $files;
    }
}
