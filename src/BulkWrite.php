<?php

namespace Tequila\MongoDB;

use MongoDB\BSON\ObjectID;
use MongoDB\BSON\Serializable;
use Tequila\MongoDB\Exception\BadMethodCallException;
use Tequila\MongoDB\Exception\InvalidArgumentException;
use Tequila\MongoDB\Exception\LogicException;
use Tequila\MongoDB\Exception\UnsupportedException;

class BulkWrite
{
    /**
     * @var BulkWriteListenerInterface
     */
    private $listener;

    /**
     * @var bool
     */
    private $isCompilationStage = false;

    /**
     * @var int position of the currently compiled write model
     */
    private $currentPosition = 0;

    /**
     * @var array
     */
    private $insertedIds = [];

    /**
     * @var array
     */
    private $options;

    /**
     * @var \MongoDB\Driver\BulkWrite
     */
    private $wrappedBulk;

    /**
     * @var Server
     */
    private $server;

    /**
     * @var WriteModelInterface[]|\Traversable
     */
    private $writeModels;

    /**
     * @param WriteModelInterface[]|\Traversable $writeModels
     * @param array $options
     */
    public function __construct($writeModels, array $options = [])
    {
        if (!is_array($writeModels) && !$writeModels instanceof \Traversable) {
            throw new InvalidArgumentException(
                '$writeModels must be an array or a \Traversable instance.'
            );
        }

        if (is_array($writeModels) && empty($writeModels)) {
            throw new InvalidArgumentException('$writeModels array is empty.');
        }

        $allowedOptions = ['bypassDocumentValidation', 'ordered'];

        $unexpectedOptions = array_diff_key($options, array_flip($allowedOptions));
        if (count($unexpectedOptions)) {
            throw new InvalidArgumentException(
                sprintf(
                    (count($unexpectedOptions) > 1
                        ? 'The options "%s" do not exist.'
                        : 'The option "%s" does not exist.').' Defined options are: "%s".',
                    implode('", "', array_keys($unexpectedOptions)),
                    implode('", "', array_keys($allowedOptions))
                )
            );
        }

        $this->writeModels = $writeModels;
        $this->options = $options;
    }

    /**
     * @param Server $server
     * @return \MongoDB\Driver\BulkWrite
     */
    public function compile(Server $server)
    {
        $this->isCompilationStage = true;
        $this->server = $server;

        $expectedPosition = 0;
        foreach ($this->writeModels as $position => $writeModel) {
            if (!$expectedPosition === $position) {
                throw new InvalidArgumentException(
                    sprintf('$writeModels is not a list. Unexpected index "%s".', $position)
                );
            }

            if (!$writeModel instanceof WriteModelInterface) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Each write model must be an instance of "%s", "%s" given in $writeModels[%d].',
                        WriteModelInterface::class,
                        is_object($writeModel) ? get_class($writeModel) : \gettype($writeModel),
                        $position
                    )
                );
            }

            $writeModel->writeToBulk($this);
            ++$expectedPosition;
        }

        if (0 === $expectedPosition) {
            throw new InvalidArgumentException('$writeModels iterator is empty.');
        }

        $this->isCompilationStage = false;

        return $this->wrappedBulk;
    }

    public function __debugInfo()
    {
        return [
            'count' => $this->currentPosition,
            'insertedIds' => $this->insertedIds,
            'wrappedBulk' => $this->wrappedBulk,
        ];
    }

    /**
     * @return ObjectID[]|mixed[]
     */
    public function getInsertedIds()
    {
        return $this->insertedIds;
    }

    /**
     * Wraps @see \MongoDB\Driver\BulkWrite::insert() to save inserted id and always return it.
     *
     * @param array|object $document
     * @return ObjectID|mixed id of the inserted document
     */
    public function insert($document)
    {
        $this->ensureAllowedMethodCall(__METHOD__);

        if (null !== $this->listener) {
            $this->listener->beforeInsert($document);
        }

        $id = $this->getWrappedBulk()->insert($document);
        if (null === $id) {
            if ($document instanceof DocumentInterface && !$document->getId()) {
                throw new LogicException(
                    '$document contains an id, but it\'s method getId() does not return it.'
                );
            }

            $id = $this->extractIdFromDocument($document);
        } elseif ($document instanceof DocumentInterface) {
            if ($document->getId()) {
                throw new LogicException(
                    '$document\'s method getId() returns not the same id document will be inserted with.'
                );
            }
            $document->setId($id);
        }

        $this->insertedIds[$this->currentPosition] = $id;
        $this->currentPosition += 1;

        return $id;
    }

    /**
     * Wraps @see \MongoDB\Driver\BulkWrite::update().
     *
     * @param array|object $filter
     * @param array|object $update
     * @param array $options
     */
    public function update($filter, $update, array $options = [])
    {
        $this->ensureAllowedMethodCall(__METHOD__);

        if (isset($options['collation']) && !$this->server->supportsCollation()) {
            throw new UnsupportedException(
                'Option "collation" is not supported by the server.'
            );
        }

        if (null !== $this->listener) {
            $this->listener->beforeUpdate($filter, $update, $options);
        }

        $this->getWrappedBulk()->update($filter, $update, $options);
        $this->currentPosition += 1;
    }

    /**
     * Wraps @see \MongoDB\Driver\BulkWrite::delete().
     *
     * @param array|object $filter
     * @param array $options
     */
    public function delete($filter, array $options = [])
    {
        $this->ensureAllowedMethodCall(__METHOD__);

        if (isset($options['collation']) && !$this->server->supportsCollation()) {
            throw new UnsupportedException(
                'Option "collation" is not supported by the server.'
            );
        }

        if (null !== $this->listener) {
            $this->listener->beforeDelete($filter, $options);
        }

        $this->getWrappedBulk()->delete($filter, $options);
        $this->currentPosition += 1;
    }

    /**
     * @return int
     */
    public function count()
    {
        return count($this->writeModels);
    }

    /**
     * @param BulkWriteListenerInterface $listener
     */
    public function setListener(BulkWriteListenerInterface $listener)
    {
        $this->listener = $listener;
    }

    /**
     * @param array|object $document
     * @return ObjectID|mixed
     */
    private function extractIdFromDocument($document)
    {
        $targetDocument = $document;

        if ($document instanceof Serializable) {
            $targetDocument = $document->bsonSerialize();
        }

        return is_array($targetDocument) ? $targetDocument['_id'] : $targetDocument->_id;
    }

    /**
     * @return \MongoDB\Driver\BulkWrite
     */
    private function getWrappedBulk()
    {
        if (null === $this->wrappedBulk) {
            if (isset($this->options['bypassDocumentValidation']) && !$this->server->supportsDocumentValidation()) {
                throw new UnsupportedException(
                    'Option "bypassDocumentValidation" is not supported by the server.'
                );
            }

            $this->wrappedBulk = new \MongoDB\Driver\BulkWrite($this->options);
        }

        return $this->wrappedBulk;
    }

    private function ensureAllowedMethodCall($methodName)
    {
        // If method called not in compilation stage
        if (false === $this->isCompilationStage) {
            throw new BadMethodCallException(
                sprintf(
                    'Method "%s" is internal and can be called only during bulk compilation process.',
                    $methodName
                )
            );
        }
    }
}
