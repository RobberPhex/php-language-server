<?php
declare(strict_types=1);

namespace LanguageServer\Server;

use Amp\Deferred;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;
use LanguageServer\{LanguageClient, PhpDocumentLoader};
use LanguageServer\Factory\LocationFactory;
use LanguageServer\Index\{DependenciesIndex, Index, ProjectIndex};
use LanguageServerProtocol\{DependencyReference, FileChangeType, FileEvent, ReferenceInformation, SymbolDescriptor};

/**
 * Provides method handlers for all workspace/* methods
 */
class Workspace
{
    /**
     * @var LanguageClient
     */
    public $client;

    /**
     * @var Index
     */
    private $sourceIndex;

    /**
     * @var PhpDocumentLoader
     */
    public $documentLoader;

    /**
     * @param LanguageClient $client LanguageClient instance used to signal updated results
     * @param Index $sourceIndex used on a workspace/xreferences request
     * @param PhpDocumentLoader $documentLoader PhpDocumentLoader instance to load documents
     */
    public function __construct(LanguageClient $client, Index $sourceIndex, PhpDocumentLoader $documentLoader)
    {
        $this->client = $client;
        $this->sourceIndex = $sourceIndex;
        $this->documentLoader = $documentLoader;
    }

    /**
     * The workspace symbol request is sent from the client to the server to list project-wide symbols matching the query string.
     *
     * @param string $query
     * @return Promise <SymbolInformation[]>
     */
    public function symbol(string $query): Promise
    {
        $symbols = [];
        foreach ($this->sourceIndex->getDefinitions() as $fqn => $definition) {
            if ($query === '' || stripos($fqn, $query) !== false) {
                $symbols[] = $definition->symbolInformation;
            }
        }
        return new Success($symbols);
    }

    /**
     * The watched files notification is sent from the client to the server when the client detects changes to files watched by the language client.
     *
     * @param FileEvent[] $changes
     * @return void
     */
    public function didChangeWatchedFiles(array $changes)
    {
        Loop::defer(function () use ($changes) {
            foreach ($changes as $change) {
                if ($change->type === FileChangeType::DELETED) {
                    yield from $this->client->textDocument->publishDiagnostics($change->uri, []);
                }
            }
        });
    }

}
