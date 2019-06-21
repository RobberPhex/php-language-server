<?php
declare(strict_types=1);

namespace LanguageServer\Index;

/**
 * Aggregates definitions of the project and stubs
 */
class GlobalIndex extends AbstractAggregateIndex
{
    /**
     * @var Index
     */
    private $stubsIndex;

    /**
     * @var Index
     */
    private $sourceIndex;

    /**
     * @param StubsIndex $stubsIndex
     * @param Index $sourceIndex
     */
    public function __construct(StubsIndex $stubsIndex, Index $sourceIndex)
    {
        $this->stubsIndex = $stubsIndex;
        $this->sourceIndex = $sourceIndex;
        parent::__construct();
    }

    /**
     * @return ReadableIndex[]
     */
    protected function getIndexes(): array
    {
        return [$this->stubsIndex, $this->sourceIndex];
    }
}
