<?php
declare(strict_types=1);

namespace LanguageServer\FilesFinder;


/**
 * Interface for finding files in the workspace
 */
interface FilesFinder
{
    /**
     * Returns all files in the workspace that match a glob.
     * If the client does not support workspace/xfiles, it falls back to searching the file system directly.
     *
     * @param string $glob
     * @return \Generator <File[]>
     */
    public function find(string $glob): \Generator;
}
