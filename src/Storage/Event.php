<?php

namespace Laminas\Cache\Storage;

use ArrayObject;
use Laminas\Cache\Storage\Adapter\AdapterOptions;
use Laminas\EventManager\Event as BaseEvent;

/** @extends BaseEvent<StorageInterface, ArrayObject> */
class Event extends BaseEvent
{
    /**
     * Accept a storage adapter and its parameters.
     *
     * @param non-empty-string $name Event name
     * @param StorageInterface<AdapterOptions> $storage
     * @param ArrayObject<string,mixed> $params
     */
    public function __construct(string $name, StorageInterface $storage, ArrayObject $params)
    {
        parent::__construct($name, $storage, $params);
    }

    /**
     * Set the event target/context
     *
     * @see    \Laminas\EventManager\Event::setTarget()
     *
     * @param StorageInterface<AdapterOptions> $target
     */
    public function setTarget($target): void
    {
        parent::setTarget($target);
        $this->setStorage($target);
    }

    /**
     * Alias of setTarget
     *
     * @see    \Laminas\EventManager\Event::setTarget()
     *
     * @param StorageInterface<AdapterOptions> $storage
     */
    public function setStorage(StorageInterface $storage): self
    {
        $this->target = $storage;
        return $this;
    }

    /**
     * Alias of getTarget
     *
     * @return StorageInterface<AdapterOptions>
     */
    public function getStorage(): StorageInterface
    {
        return $this->getTarget();
    }
}
