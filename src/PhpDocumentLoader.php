<?php
declare(strict_types=1);

namespace LanguageServer;

use LanguageServer\ContentRetriever\ContentRetriever;
use LanguageServer\FilesFinder\File;
use LanguageServer\Index\Index;
use LanguageServer\Index\ProjectIndex;
use Microsoft\PhpParser;
use Microsoft\PhpParser\Parser;
use phpDocumentor\Reflection\DocBlockFactory;

/**
 * Takes care of loading documents and managing "open" documents
 */
class PhpDocumentLoader
{
    /**
     * A map from URI => PhpDocument of open documents that should be kept in memory
     *
     * @var PhpDocument[]
     */
    private $documents = [];

    /**
     * @var ContentRetriever
     */
    public $contentRetriever;

    /**
     * @var Index
     */
    private $sourceIndex;

    /**
     * @var Parser
     */
    private $parser;

    /**
     * @var DocBlockFactory
     */
    private $docBlockFactory;

    /**
     * @var DefinitionResolver
     */
    private $definitionResolver;

    /**
     * @param ContentRetriever $contentRetriever
     * @param Index $sourceIndex
     * @param DefinitionResolver $definitionResolver
     * @internal param ProjectIndex $project
     */
    public function __construct(
        ContentRetriever $contentRetriever,
        Index $sourceIndex,
        DefinitionResolver $definitionResolver
    )
    {
        $this->contentRetriever = $contentRetriever;
        $this->sourceIndex = $sourceIndex;
        $this->definitionResolver = $definitionResolver;
        $this->parser = new PhpParser\Parser();
        $this->docBlockFactory = DocBlockFactory::createInstance();
    }

    /**
     * Returns the document indicated by uri.
     * Returns null if the document if not loaded.
     *
     * @param string $uri
     * @return PhpDocument|null
     */
    public function get(string $uri)
    {
        return $this->documents[$uri] ?? null;
    }

    /**
     * Returns the document indicated by uri.
     * If the document is not open, loads it.
     *
     * @param File $file
     * @return \Generator <PhpDocument>
     * @throws ContentTooLargeException
     */
    public function getOrLoad(File $file): \Generator
    {
        $uri = $file->getUri();
        if (isset($this->documents[$uri])) {
            return $this->documents[$uri];
        } else {
            return yield from $this->load($file);
        }
    }

    /**
     * Loads the document by doing a textDocument/xcontent request to the client.
     * If the client does not support textDocument/xcontent, tries to read the file from the file system.
     * The document is NOT added to the list of open documents, but definitions are registered.
     *
     * @param File $file
     * @return \Generator <PhpDocument>
     */
    public function load(File $file): \Generator
    {
        $uri = $file->getUri();

        $content = yield from $this->contentRetriever->retrieve($uri);
        if (isset($this->documents[$uri])) {
            $document = $this->documents[$uri];
            $document->updateContent($content);
        } else {
            $document = $this->create($uri, $content);
        }
        $this->sourceIndex->markIndexed($file);
        return $document;
    }

    /**
     * Builds a PhpDocument instance
     *
     * @param string $uri
     * @param string $content
     * @return PhpDocument
     */
    public function create(string $uri, string $content): PhpDocument
    {
        return new PhpDocument(
            $uri,
            $content,
            $this->sourceIndex,
            $this->parser,
            $this->docBlockFactory,
            $this->definitionResolver
        );
    }

    /**
     * Ensures a document is loaded and added to the list of open documents.
     *
     * @param string $uri
     * @param string $content
     * @return PhpDocument
     */
    public function open(string $uri, string $content)
    {
        if (isset($this->documents[$uri])) {
            $document = $this->documents[$uri];
            $document->updateContent($content);
        } else {
            $document = $this->create($uri, $content);
            $this->documents[$uri] = $document;
        }
        $this->sourceIndex->markIndexed(new File($uri, time()));
        return $document;
    }

    /**
     * Removes the document with the specified URI from the list of open documents
     *
     * @param string $uri
     * @return void
     */
    public function close(string $uri)
    {
        unset($this->documents[$uri]);
    }

    /**
     * Returns true if the document is open (and loaded)
     *
     * @param string $uri
     * @return bool
     */
    public function isOpen(string $uri): bool
    {
        return isset($this->documents[$uri]);
    }
}
