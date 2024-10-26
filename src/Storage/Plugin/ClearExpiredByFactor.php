<?php

namespace Laminas\Cache\Storage\Plugin;

use Laminas\Cache\Storage\ClearExpiredInterface;
use Laminas\Cache\Storage\PostEvent;
use Laminas\EventManager\EventManagerInterface;

use function random_int;

final class ClearExpiredByFactor extends AbstractPlugin
{
    /**
     * {@inheritDoc}
     */
    public function attach(EventManagerInterface $events, $priority = 1): void
    {
        $callback = [$this, 'clearExpiredByFactor'];

        $this->listeners[] = $events->attach('setItem.post', $callback, $priority);
        $this->listeners[] = $events->attach('setItems.post', $callback, $priority);
        $this->listeners[] = $events->attach('addItem.post', $callback, $priority);
        $this->listeners[] = $events->attach('addItems.post', $callback, $priority);
    }

    /**
     * Clear expired items by factor after writing new item(s)
     *
     * @phpcs:disable Generic.NamingConventions.ConstructorName.OldStyle
     */
    public function clearExpiredByFactor(PostEvent $event): void
    {
        $storage = $event->getStorage();
        if (! $storage instanceof ClearExpiredInterface) {
            return;
        }

        $factor = $this->getOptions()->getClearingFactor();
        if ($factor && random_int(1, $factor) === 1) {
            $storage->clearExpired();
        }
    }
}
