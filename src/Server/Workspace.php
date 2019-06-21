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
     * The symbol index for the workspace
     *
     * @var ProjectIndex
     */
    private $projectIndex;

    /**
     * @var DependenciesIndex
     */
    private $dependenciesIndex;

    /**
     * @var Index
     */
    private $sourceIndex;

    /**
     * @var \stdClass
     */
    public $composerJson;

    /**
     * @var \stdClass
     */
    public $composerLock;

    /**
     * @var PhpDocumentLoader
     */
    public $documentLoader;

    /**
     * @param LanguageClient $client LanguageClient instance used to signal updated results
     * @param ProjectIndex $projectIndex Index that is used to wait for full index completeness
     * @param DependenciesIndex $dependenciesIndex Index that is used on a workspace/xreferences request
     * @param Index $sourceIndex used on a workspace/xreferences request
     * @param PhpDocumentLoader $documentLoader PhpDocumentLoader instance to load documents
     * @param \stdClass $composerJson The parsed composer.json of the project, if any
     * @param \stdClass $composerLock The parsed composer.lock of the project, if any
     */
    public function __construct(LanguageClient $client, ProjectIndex $projectIndex, DependenciesIndex $dependenciesIndex, Index $sourceIndex, PhpDocumentLoader $documentLoader, \stdClass $composerJson = null, \stdClass $composerLock = null)
    {
        $this->client = $client;
        $this->sourceIndex = $sourceIndex;
        $this->projectIndex = $projectIndex;
        $this->dependenciesIndex = $dependenciesIndex;
        $this->documentLoader = $documentLoader;
        $this->composerJson = $composerJson;
        $this->composerLock = $composerLock;
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
