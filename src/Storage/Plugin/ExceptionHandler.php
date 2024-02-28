<?php

namespace Laminas\Cache\Storage\Plugin;

use Laminas\Cache\Storage\ExceptionEvent;
use Laminas\EventManager\EventManagerInterface;

use function call_user_func;

class ExceptionHandler extends AbstractPlugin
{
    /**
     * {@inheritDoc}
     */
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $callback = [$this, 'onException'];

        // read
        $this->listeners[] = $events->attach('getItem.exception', $callback, $priority);
        $this->listeners[] = $events->attach('getItems.exception', $callback, $priority);

        $this->listeners[] = $events->attach('hasItem.exception', $callback, $priority);
        $this->listeners[] = $events->attach('hasItems.exception', $callback, $priority);

        $this->listeners[] = $events->attach('getMetadata.exception', $callback, $priority);
        $this->listeners[] = $events->attach('getMetadatas.exception', $callback, $priority);

        // write
        $this->listeners[] = $events->attach('setItem.exception', $callback, $priority);
        $this->listeners[] = $events->attach('setItems.exception', $callback, $priority);

        $this->listeners[] = $events->attach('addItem.exception', $callback, $priority);
        $this->listeners[] = $events->attach('addItems.exception', $callback, $priority);

        $this->listeners[] = $events->attach('replaceItem.exception', $callback, $priority);
        $this->listeners[] = $events->attach('replaceItems.exception', $callback, $priority);

        $this->listeners[] = $events->attach('touchItem.exception', $callback, $priority);
        $this->listeners[] = $events->attach('touchItems.exception', $callback, $priority);

        $this->listeners[] = $events->attach('removeItem.exception', $callback, $priority);
        $this->listeners[] = $events->attach('removeItems.exception', $callback, $priority);

        $this->listeners[] = $events->attach('checkAndSetItem.exception', $callback, $priority);

        // utility
        $this->listeners[] = $events->attach('clearExpired.exception', $callback, $priority);
    }

    /**
     * On exception
     *
     * @return void
     */
    public function onException(ExceptionEvent $event)
    {
        $options  = $this->getOptions();
        $callback = $options->getExceptionCallback();
        if ($callback !== null) {
            call_user_func($callback, $event->getException());
        }

        $event->setThrowException($options->getThrowExceptions());
    }
}
