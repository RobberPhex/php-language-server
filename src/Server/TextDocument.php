<?php
declare(strict_types=1);

namespace LanguageServer\Server;

use Amp\Coroutine;
use Amp\Deferred;
use Amp\Loop;
use Amp\Promise;
use LanguageServer\{CompletionProvider,
    DefinitionResolver,
    FilesFinder\File,
    Index\GlobalIndex,
    Index\Index,
    LanguageClient,
    PhpDocument,
    PhpDocumentLoader,
    SignatureHelpProvider};
use LanguageServer\Factory\LocationFactory;
use LanguageServer\Factory\RangeFactory;
use LanguageServer\Index\ReadableIndex;
use LanguageServerProtocol\{CompletionContext,
    Hover,
    MarkedString,
    PackageDescriptor,
    Position,
    ReferenceContext,
    SymbolDescriptor,
    SymbolLocationInformation,
    TextDocumentIdentifier,
    TextDocumentItem,
    VersionedTextDocumentIdentifier};
use Microsoft\PhpParser\Node;
use function LanguageServer\{getPackageName, isVendored};
use function League\Uri\parse;
use Webmozart\Glob\Glob;

/**
 * Provides method handlers for all textDocument/* methods
 */
class TextDocument
{
    /**
     * The lanugage client object to call methods on the client
     *
     * @var \LanguageServer\LanguageClient
     */
    protected $client;

    /**
     * @var Project
     */
    protected $project;

    /**
     * @var DefinitionResolver
     */
    protected $definitionResolver;

    /**
     * @var CompletionProvider
     */
    protected $completionProvider;

    /**
     * @var SignatureHelpProvider
     */
    protected $signatureHelpProvider;

    /**
     * @var ReadableIndex
     */
    protected $index;

    /**
     * @var Index
     */
    private $sourceIndex;

    /**
     * @var PhpDocumentLoader
     */
    private $documentLoader;

    /**
     * @param PhpDocumentLoader $documentLoader
     * @param DefinitionResolver $definitionResolver
     * @param LanguageClient $client
     * @param Index $index
     * @param Index $sourceIndex
     */
    public function __construct(
        PhpDocumentLoader $documentLoader,
        DefinitionResolver $definitionResolver,
        LanguageClient $client,
        ReadableIndex $index,
        Index $sourceIndex
    )
    {
        $this->documentLoader = $documentLoader;
        $this->client = $client;
        $this->definitionResolver = $definitionResolver;
        $this->completionProvider = new CompletionProvider($this->definitionResolver, $index);
        $this->signatureHelpProvider = new SignatureHelpProvider($this->definitionResolver, $index, $documentLoader);
        $this->index = $index;
        $this->sourceIndex = $sourceIndex;
    }

    /**
     * The document symbol request is sent from the client to the server to list all symbols found in a given text
     * document.
     *
     * @param \LanguageServerProtocol\TextDocumentIdentifier $textDocument
     * @return Promise <SymbolInformation[]>
     */
    public function documentSymbol(TextDocumentIdentifier $textDocument): Promise
    {
        $deferred = new Deferred();
        Loop::defer(function () use ($textDocument, $deferred) {
            /** @var PhpDocument $document */
            $document = yield from $this->documentLoader->getOrLoad($textDocument->uri);

            $symbols = [];
            foreach ($document->getDefinitions() as $fqn => $definition) {
                $symbols[] = $definition->symbolInformation;
            }
            $deferred->resolve($symbols);
        });
        return $deferred->promise();
    }

    /**
     * The document open notification is sent from the client to the server to signal newly opened text documents. The
     * document's truth is now managed by the client and the server must not try to read the document's truth using the
     * document's uri.
     *
     * @param \LanguageServerProtocol\TextDocumentItem $textDocument The document that was opened.
     * @return void
     */
    public function didOpen(TextDocumentItem $textDocument)
    {
        Loop::defer(function () use ($textDocument) {
            $document = $this->documentLoader->open($textDocument->uri, $textDocument->text);
            //if (!isVendored($document, $this->composerJson)) {
            yield from $this->client->textDocument->publishDiagnostics($textDocument->uri, $document->getDiagnostics());
        });
    }

    /**
     * The document change notification is sent from the client to the server to signal changes to a text document.
     *
     * @param \LanguageServerProtocol\VersionedTextDocumentIdentifier $textDocument
     * @param \LanguageServerProtocol\TextDocumentContentChangeEvent[] $contentChanges
     * @return Promise
     */
    public function didChange(VersionedTextDocumentIdentifier $textDocument, array $contentChanges)
    {
        $deferred = new Deferred();
        Loop::defer(function () use ($deferred, $textDocument, $contentChanges) {
            $document = $this->documentLoader->get($textDocument->uri);
            $document->updateContent($contentChanges[0]->text);
            $this->sourceIndex->markIndexed(new File($textDocument->uri, time()));
            yield from $this->client->textDocument->publishDiagnostics($textDocument->uri, $document->getDiagnostics());
            $deferred->resolve();
        });
        return $deferred->promise();
    }

    /**
     * The document close notification is sent from the client to the server when the document got closed in the client.
     * The document's truth now exists where the document's uri points to (e.g. if the document's uri is a file uri the
     * truth now exists on disk).
     *
     * @param \LanguageServerProtocol\TextDocumentIdentifier $textDocument The document that was closed
     * @return void
     */
    public function didClose(TextDocumentIdentifier $textDocument)
    {
        $this->documentLoader->close($textDocument->uri);
    }

    /**
     * The references request is sent from the client to the server to resolve project-wide references for the symbol
     * denoted by the given text document position.
     *
     * @param ReferenceContext $context
     * @return Promise <Location[]>
     */
    public function references(
        ReferenceContext $context,
        TextDocumentIdentifier $textDocument,
        Position $position
    ): Promise
    {
        $deferred = new Deferred();
        Loop::defer(function () use ($deferred, $textDocument, $position) {
            $document = yield from $this->documentLoader->getOrLoad($textDocument->uri);
            $node = $document->getNodeAtPosition($position);
            if ($node === null) {
                return [];
            }
            $locations = [];
            // Variables always stay in the boundary of the file and need to be searched inside their function scope
            // by traversing the AST
            if (
                ($node instanceof Node\Expression\Variable && !($node->getParent()->getParent() instanceof Node\PropertyDeclaration))
                || $node instanceof Node\Parameter
                || $node instanceof Node\UseVariableName
            ) {
                if (isset($node->name) && $node->name instanceof Node\Expression) {
                    return null;
                }
                // Find function/method/closure scope
                $n = $node;

                $n = $n->getFirstAncestor(Node\Statement\FunctionDeclaration::class, Node\MethodDeclaration::class, Node\Expression\AnonymousFunctionCreationExpression::class, Node\SourceFileNode::class);

                if ($n === null) {
                    $n = $node->getFirstAncestor(Node\Statement\ExpressionStatement::class)->getParent();
                }

                foreach ($n->getDescendantNodes() as $descendantNode) {
                    if ($descendantNode instanceof Node\Expression\Variable &&
                        $descendantNode->getName() === $node->getName()
                    ) {
                        $locations[] = LocationFactory::fromNode($descendantNode);
                    }
                }
            } else {
                // Definition with a global FQN
                $fqn = DefinitionResolver::getDefinedFqn($node);

                if ($fqn === null) {
                    $fqn = $this->definitionResolver->resolveReferenceNodeToFqn($node);
                    if ($fqn === null) {
                        $deferred->resolve([]);
                        return;
                    }
                }
                $refDocumentPromises = [];
                foreach ($this->index->getReferenceUris($fqn) as $uri) {
                    $refDocumentPromises[] = new Coroutine($this->documentLoader->getOrLoad($uri));
                }
                $refDocuments = yield \Amp\Promise\all($refDocumentPromises);
                foreach ($refDocuments as $document) {
                    $refs = $document->getReferenceNodesByFqn($fqn);
                    if ($refs !== null) {
                        foreach ($refs as $ref) {
                            $locations[] = LocationFactory::fromNode($ref);
                        }
                    }
                }
            }
            $deferred->resolve($locations);
        });
        return $deferred->promise();
    }

    /**
     * The signature help request is sent from the client to the server to request signature information at a given
     * cursor position.
     *
     * @param TextDocumentIdentifier $textDocument The text document
     * @param Position $position The position inside the text document
     *
     * @return Promise <SignatureHelp>
     */
    public function signatureHelp(TextDocumentIdentifier $textDocument, Position $position): Promise
    {
        $deferred = new Deferred();
        Loop::defer(function () use ($deferred, $textDocument, $position) {
            $document = yield from $this->documentLoader->getOrLoad($textDocument->uri);
            $deferred->resolve(
                yield from $this->signatureHelpProvider->getSignatureHelp($document, $position)
            );
        });
        return $deferred->promise();
    }

    /**
     * The goto definition request is sent from the client to the server to resolve the definition location of a symbol
     * at a given text document position.
     *
     * @param TextDocumentIdentifier $textDocument The text document
     * @param Position $position The position inside the text document
     * @return Promise <Location|Location[]>
     * @throws \LanguageServer\ContentTooLargeException
     */
    public function definition(TextDocumentIdentifier $textDocument, Position $position): Promise
    {
        $deferred = new Deferred();
        Loop::defer(function () use ($deferred, $textDocument, $position) {
            $document = yield from $this->documentLoader->getOrLoad($textDocument->uri);
            $node = $document->getNodeAtPosition($position);
            if ($node === null) {
                return [];
            }
            // Handle definition nodes
            $fqn = DefinitionResolver::getDefinedFqn($node);
            while (true) {
                if ($fqn) {
                    $def = $this->index->getDefinition($fqn);
                } else {
                    // Handle reference nodes
                    $def = $this->definitionResolver->resolveReferenceNodeToDefinition($node);
                }
                // If no result was found and we are still indexing, try again after the index was updated
                if ($def !== null || $this->index->isComplete()) {
                    break;
                }
            }
            if (
                $def === null
                || $def->symbolInformation === null
                || parse($def->symbolInformation->location->uri)['scheme'] === 'phpstubs'
            ) {
                $deferred->resolve([]);
            } else {
                $deferred->resolve($def->symbolInformation->location);
            }
        });
        return $deferred->promise();
    }

    /**
     * The hover request is sent from the client to the server to request hover information at a given text document position.
     *
     * @param TextDocumentIdentifier $textDocument The text document
     * @param Position $position The position inside the text document
     * @return Promise <Hover>
     */
    public function hover(TextDocumentIdentifier $textDocument, Position $position): Promise
    {
        $deferred = new Deferred();
        Loop::defer(function () use ($deferred, $textDocument, $position) {
            $document = yield from $this->documentLoader->getOrLoad($textDocument->uri);
            // Find the node under the cursor
            $node = $document->getNodeAtPosition($position);
            if ($node === null) {
                return new Hover([]);
            }
            $definedFqn = DefinitionResolver::getDefinedFqn($node);
            while (true) {
                if ($definedFqn) {
                    // Support hover for definitions
                    $def = $this->index->getDefinition($definedFqn);
                } else {
                    // Get the definition for whatever node is under the cursor
                    $def = $this->definitionResolver->resolveReferenceNodeToDefinition($node);
                }
                // If no result was found and we are still indexing, try again after the index was updated
                if ($def !== null || $this->index->isComplete()) {
                    break;
                }
            }
            $range = RangeFactory::fromNode($node);
            if ($def === null) {
                return new Hover([], $range);
            }
            $contents = [];
            if ($def->declarationLine) {
                $contents[] = new MarkedString('php', "<?php\n" . $def->declarationLine);
            }
            if ($def->documentation) {
                $contents[] = $def->documentation;
            }
            $deferred->resolve(new Hover($contents, $range));
        });
        return $deferred->promise();
    }

    /**
     * The Completion request is sent from the client to the server to compute completion items at a given cursor
     * position. Completion items are presented in the IntelliSense user interface. If computing full completion items
     * is expensive, servers can additionally provide a handler for the completion item resolve request
     * ('completionItem/resolve'). This request is sent when a completion item is selected in the user interface. A
     * typically use case is for example: the 'textDocument/completion' request doesn't fill in the documentation
     * property for returned completion items since it is expensive to compute. When the item is selected in the user
     * interface then a 'completionItem/resolve' request is sent with the selected completion item as a param. The
     * returned completion item should have the documentation property filled in.
     *
     * @param TextDocumentIdentifier $textDocument
     * @param Position $position The position
     * @param CompletionContext|null $context The completion context
     * @return Promise <CompletionItem[]|CompletionList>
     */
    public function completion(TextDocumentIdentifier $textDocument, Position $position, CompletionContext $context = null): Promise
    {
        $deferred = new Deferred();
        Loop::defer(function () use ($deferred, $context, $position, $textDocument) {
            $file = new File($textDocument->uri, 0);
            /** @var PhpDocument $document */
            $document = yield from $this->documentLoader->getOrLoad($file);
            $deferred->resolve($this->completionProvider->provideCompletion($document, $position, $context));
        });
        return $deferred->promise();
    }
}
