<?php
declare(strict_types=1);

namespace LanguageServer;

use LanguageServer\Cache\Cache;
use LanguageServer\FilesFinder\File;
use LanguageServer\FilesFinder\FilesFinder;
use LanguageServer\Index\Index;
use LanguageServerProtocol\MessageType;
use Webmozart\PathUtil\Path;

class Indexer
{
    /**
     * @var int The prefix for every cache item
     */
    const CACHE_VERSION = 3;

    /**
     * @var FilesFinder
     */
    private $filesFinder;

    /**
     * @var string
     */
    private $rootPath;

    /**
     * @var LanguageClient
     */
    private $client;

    /**
     * @var Cache
     */
    private $cache;

    /**
     * @var Index
     */
    private $sourceIndex;

    /**
     * @var PhpDocumentLoader
     */
    private $documentLoader;

    /**
     * @param FilesFinder $filesFinder
     * @param string $rootPath
     * @param LanguageClient $client
     * @param Cache $cache
     * @param Index $sourceIndex
     * @param PhpDocumentLoader $documentLoader
     */
    public function __construct(
        FilesFinder $filesFinder,
        string $rootPath,
        LanguageClient $client,
        Cache $cache,
        Index $sourceIndex,
        PhpDocumentLoader $documentLoader
    )
    {
        $this->filesFinder = $filesFinder;
        $this->rootPath = $rootPath;
        $this->client = $client;
        $this->cache = $cache;
        $this->sourceIndex = $sourceIndex;
        $this->documentLoader = $documentLoader;
    }

    /**
     * Will read and parse the passed source files in the project and add them to the appropiate indexes
     *
     * @return \Generator <void>
     */
    public function index(): \Generator
    {
        $pattern = Path::makeAbsolute('**/*.php', $this->rootPath);
        /** @var File[] $files */
        $files = yield from $this->filesFinder->find($pattern);

        $count = count($files);
        $startTime = microtime(true);
        yield from $this->client->window->logMessage(MessageType::INFO, "$count files total");

        // Index source
        // Definitions and static references
        yield from $this->indexFiles($files);
        $this->sourceIndex->setStaticComplete();
        // Dynamic references
        $this->sourceIndex->setComplete();

        $duration = (int)(microtime(true) - $startTime);
        $mem = (int)(memory_get_usage(true) / (1024 * 1024));
        yield from $this->client->window->logMessage(
            MessageType::INFO,
            "All $count PHP files parsed in $duration seconds. $mem MiB allocated."
        );
        yield from $this->cache->set($this->rootPath, serialize($this->sourceIndex));
    }

    /**
     * @param File[] $files
     * @return \Generator
     */
    private function indexFiles(array $files): \Generator
    {
        foreach ($files as $file) {
            if (!$this->sourceIndex->needIndex($file)) {
                continue;
            }
            $uri = $file->getUri();

            yield from $this->client->window->logMessage(MessageType::LOG, "Parsing $uri");
            try {
                $document = yield from $this->documentLoader->load($file);
//                if (!isVendored($document, $this->composerJson)) {
//                    yield from $this->client->textDocument->publishDiagnostics($uri, $document->getDiagnostics());
//                }
            } catch (ContentTooLargeException $e) {
                yield from $this->client->window->logMessage(
                    MessageType::INFO,
                    "Ignoring file {$uri} because it exceeds size limit of {$e->limit} bytes ({$e->size})"
                );
            } catch (\Exception $e) {
                yield from $this->client->window->logMessage(MessageType::ERROR, "Error parsing $uri: " . (string)$e);
            }
        }
    }
}
