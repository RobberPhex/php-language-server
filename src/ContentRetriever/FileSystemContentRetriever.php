<?php
declare(strict_types=1);

namespace LanguageServer\ContentRetriever;

use Amp\File;
use LanguageServer\ContentTooLargeException;
use function LanguageServer\uriToPath;

/**
 * Retrieves document content from the file system
 */
class FileSystemContentRetriever implements ContentRetriever
{
    /**
     * Retrieves the content of a text document identified by the URI from the file system
     *
     * @param string $uri The URI of the document
     * @return \Generator <string> Resolved with the content as a string
     * @throws ContentTooLargeException
     */
    public function retrieve(string $uri): \Generator
    {
        $limit = 150000;
        $path = uriToPath($uri);
        $size = filesize($path);
        if ($limit < $size) {
            throw new ContentTooLargeException($uri, $size, $limit);
        }

        return yield File\get($path);
    }
}
