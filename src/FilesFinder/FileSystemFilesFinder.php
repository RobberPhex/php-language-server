<?php
declare(strict_types=1);

namespace LanguageServer\FilesFinder;

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
        $basePath = Glob::getBasePath($glob);
        $pathList = [$basePath];
        while ($pathList) {
            $path = array_pop($pathList);
            if (yield isdir($path)) {
                $subFileList = yield scandir($path);
                foreach ($subFileList as $subFile) {
                    $pathList[] = $path . DIRECTORY_SEPARATOR . $subFile;
                }
            } elseif (Glob::match($path, $glob)) {
                $mtime = stat($path)['mtime'] ?? time();
                $uri = pathToUri($path);
                $files[] = new File($uri, $mtime);
            }
        }
        return $files;
    }
}
