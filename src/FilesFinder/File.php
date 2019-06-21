<?php
declare(strict_types=1);

namespace LanguageServer\FilesFinder;

use LanguageServer\ContentRetriever\ContentRetriever;

class File
{
    /**
     * @var string
     */
    private $uri;

    /**
     * @var int
     */
    private $mtime;

    public function __construct($uri, $mtime = null)
    {
        $this->uri = $uri;
        if ($mtime) {
            $this->mtime = $mtime;
        } else {
            $this->mtime = time();
        }
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getMtime(): int
    {
        return $this->mtime;
    }

    public function getContent(): \Generator
    {
        return yield from $this->contentRetriever->retrieve($this->uri);
    }
}