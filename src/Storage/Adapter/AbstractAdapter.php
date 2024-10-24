<?php

namespace Laminas\Cache\Storage\Adapter;

use ArrayObject;
use InvalidArgumentException;
use Laminas\Cache\Exception;
use Laminas\Cache\Storage\Capabilities;
use Laminas\Cache\Storage\Event;
use Laminas\Cache\Storage\ExceptionEvent;
use Laminas\Cache\Storage\Plugin;
use Laminas\Cache\Storage\PluginAwareInterface;
use Laminas\Cache\Storage\PostEvent;
use Laminas\Cache\Storage\StorageInterface;
use Laminas\EventManager\EventManager;
use Laminas\EventManager\EventManagerInterface;
use Laminas\EventManager\ResponseCollection;
use SplObjectStorage;
use Throwable;
use Webmozart\Assert\Assert;

use function array_keys;
use function array_unique;
use function array_values;
use function func_num_args;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * @template TOptions of AdapterOptions
 * @template-implements StorageInterface<TOptions>
 */
abstract class AbstractAdapter implements StorageInterface, PluginAwareInterface
{
    /**
     * The used EventManager if any
     */
    protected ?EventManagerInterface $events = null;

    /**
     * Event handles of this adapter
     */
    protected array $eventHandles = [];

    /**
     * The plugin registry
     *
     * @var SplObjectStorage|null Registered plugins
     */
    protected ?SplObjectStorage $pluginRegistry = null;

    /**
     * Capabilities of this adapter
     */
    protected ?Capabilities $capabilities = null;

    /**
     * options
     *
     * @var TOptions|null
     */
    protected ?AdapterOptions $options = null;

    /**
     * @param iterable<string,mixed>|TOptions|null $options
     * @throws Exception\ExceptionInterface
     */
    public function __construct(iterable|AdapterOptions|null $options = null)
    {
        if ($options !== null) {
            $this->setOptions($options);
        }
    }

    /**
     * @psalm-assert list<non-empty-string|int> $result
     */
    private function assertListOfKeys(mixed $result): void
    {
        Assert::isList($result);
        $this->assertValidKeys($result);
    }

    /**
     * Destructor
     *
     * detach all registered plugins to free
     * event handles of event manager
     */
    public function __destruct()
    {
        foreach ($this->getPluginRegistry() as $plugin) {
            $this->removePlugin($plugin);
        }

        if ($this->eventHandles) {
            $events = $this->getEventManager();
            foreach ($this->eventHandles as $handle) {
                $events->detach($handle);
            }
        }
    }

    /* configuration */

    /**
     * Set options.
     *
     * @see    getOptions()
     *
     * @param iterable<string,mixed>|TOptions $options
     */
    public function setOptions(iterable|AdapterOptions $options): self
    {
        if ($this->options !== $options) {
            if (! $options instanceof AdapterOptions) {
                $options = new AdapterOptions($options);
            }

            if ($this->options) {
                $this->options->setAdapter(null);
            }
            $options->setAdapter($this);
            $this->options = $options;

            $event = new Event('option', $this, new ArrayObject($options->toArray()));

            $this->getEventManager()->triggerEvent($event);
        }

        return $this;
    }

    /**
     * Get options.
     *
     * @see setOptions()
     *
     * @return TOptions
     */
    public function getOptions(): AdapterOptions
    {
        if ($this->options === null) {
            $this->setOptions(new AdapterOptions());
        }

        Assert::notNull($this->options);

        return $this->options;
    }

    /**
     * Enable/Disable caching.
     *
     * Alias of setWritable and setReadable.
     *
     * @see    setWritable()
     * @see    setReadable()
     */
    public function setCaching(bool $flag): self
    {
        $options = $this->getOptions();
        $options->setWritable($flag);
        $options->setReadable($flag);

        return $this;
    }

    /**
     * Get caching enabled.
     *
     * Alias of getWritable and getReadable.
     *
     * @see    getWritable()
     * @see    getReadable()
     */
    public function getCaching(): bool
    {
        $options = $this->getOptions();

        return $options->getWritable() && $options->getReadable();
    }

    /* Event/Plugin handling */

    /**
     * Get the event manager
     */
    public function getEventManager(): EventManagerInterface
    {
        if ($this->events === null) {
            $this->events = new EventManager();
            $this->events->setIdentifiers([self::class, static::class]);
        }

        return $this->events;
    }

    /**
     * Trigger a pre event and return the event response collection
     *
     * @param ArrayObject<string,mixed> $args
     * @return ResponseCollection All handler return values
     */
    protected function triggerPre(string $eventName, ArrayObject $args): ResponseCollection
    {
        return $this->getEventManager()->triggerEvent(new Event($eventName . '.pre', $this, $args));
    }

    /**
     * Triggers the PostEvent and return the result value.
     *
     * @param ArrayObject<string,mixed> $args
     */
    protected function triggerPost(string $eventName, ArrayObject $args, mixed $result): mixed
    {
        $postEvent = new PostEvent($eventName . '.post', $this, $args, $result);
        $eventRs   = $this->getEventManager()->triggerEvent($postEvent);

        return $eventRs->stopped()
            ? $eventRs->last()
            : $postEvent->getResult();
    }

    /**
     * @param non-empty-string $eventName
     * @param ArrayObject<string,mixed> $args
     * @throws Throwable
     */
    protected function triggerThrowable(
        string $eventName,
        ArrayObject $args,
        mixed $result,
        Throwable $throwable
    ): mixed {
        $exceptionEvent = new ExceptionEvent($eventName . '.exception', $this, $args, $result, $throwable);
        $eventRs        = $this->getEventManager()->triggerEvent($exceptionEvent);

        if ($exceptionEvent->getThrowException()) {
            throw $exceptionEvent->getThrowable();
        }

        return $eventRs->stopped()
            ? $eventRs->last()
            : $exceptionEvent->getResult();
    }

    /**
     * {@inheritdoc}
     */
    public function hasPlugin(Plugin\PluginInterface $plugin): bool
    {
        $registry = $this->getPluginRegistry();

        return $registry->contains($plugin);
    }

    /**
     * {@inheritdoc}
     */
    public function addPlugin(Plugin\PluginInterface $plugin, int $priority = 1): StorageInterface&PluginAwareInterface
    {
        $registry = $this->getPluginRegistry();
        if ($registry->contains($plugin)) {
            throw new Exception\LogicException(sprintf(
                'Plugin of type "%s" already registered',
                $plugin::class
            ));
        }

        $plugin->attach($this->getEventManager(), $priority);
        $registry->attach($plugin);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function removePlugin(Plugin\PluginInterface $plugin): self
    {
        $registry = $this->getPluginRegistry();
        if ($registry->contains($plugin)) {
            $plugin->detach($this->getEventManager());
            $registry->detach($plugin);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getPluginRegistry(): SplObjectStorage
    {
        if (! $this->pluginRegistry instanceof SplObjectStorage) {
            $this->pluginRegistry = new SplObjectStorage();
        }

        return $this->pluginRegistry;
    }

    /* reading */

    /**
     * Get an item.
     *
     * @param-out bool $success
     * @return mixed Data on success, null on failure
     * @throws Exception\ExceptionInterface
     * @triggers getItem.pre(PreEvent)
     * @triggers getItem.post(PostEvent)
     * @triggers getItem.exception(ExceptionEvent)
     */
    public function getItem(string $key, ?bool &$success = null, mixed &$casToken = null): mixed
    {
        if (! $this->getOptions()->getReadable()) {
            $success = false;

            return null;
        }

        $this->assertValidKey($key);

        $argn = func_num_args();
        $args = [
            'key' => $key,
        ];
        if ($argn > 1) {
            $args['success'] = $success;
        }
        if ($argn > 2) {
            $args['casToken'] = $casToken;
        }
        $args = new ArrayObject($args);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            $key     = $args['key'];
            Assert::stringNotEmpty($key);

            if ($eventRs->stopped()) {
                $result = $eventRs->last();
            } elseif ($args->offsetExists('success') && $args->offsetExists('casToken')) {
                $success = $args['success'];
                Assert::nullOrBoolean($success);
                $casToken = $args['casToken'];
                $result   = $this->internalGetItem($key, $success, $casToken);
            } elseif ($args->offsetExists('success')) {
                $success = $args['success'];
                Assert::nullOrBoolean($success);
                $result = $this->internalGetItem($key, $success);
            } else {
                $result = $this->internalGetItem($key);
            }

            $result = $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (Throwable $throwable) {
            $result  = null;
            $success = false;

            $result = $this->triggerThrowable(__FUNCTION__, $args, null, $throwable);
        }

        return $result;
    }

    /**
     * Internal method to get an item.
     *
     * @param non-empty-string $normalizedKey
     * @param-out bool         $success
     * @return mixed Data on success, null on failure
     * @throws Exception\ExceptionInterface
     */
    abstract protected function internalGetItem(
        string $normalizedKey,
        ?bool &$success = null,
        mixed &$casToken = null
    ): mixed;

    /**
     * {@inheritDoc}
     *
     * @triggers getItems.pre(PreEvent)
     * @triggers getItems.post(PostEvent)
     * @triggers getItems.exception(ExceptionEvent)
     */
    public function getItems(array $keys): array
    {
        if (! $this->getOptions()->getReadable()) {
            return [];
        }

        $keys = $this->normalizeKeys($keys);
        $args = new ArrayObject([
            'keys' => $keys,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $keys = $args['keys'];
            $keys = $this->normalizeKeys($keys);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalGetItems($keys);

            $result = $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (Throwable $throwable) {
            $result = $this->triggerThrowable(__FUNCTION__, $args, [], $throwable);
        }

        Assert::isMap($result);
        Assert::allStringNotEmpty(array_keys($result));

        // phpcs:disable SlevomatCodingStandard.Commenting.InlineDocCommentDeclaration.MissingVariable
        /** @var array<non-empty-string,mixed> $result */
        return $result;
    }

    /**
     * Internal method to get multiple items.
     *
     * @param non-empty-list<non-empty-string|int> $normalizedKeys
     * @return array<non-empty-string|int,mixed> Associative array of keys and values
     * @throws Exception\ExceptionInterface
     */
    protected function internalGetItems(array $normalizedKeys): array
    {
        $success = null;
        $result  = [];
        foreach ($normalizedKeys as $normalizedKey) {
            $value = $this->internalGetItem((string) $normalizedKey, $success);
            if ($success) {
                $result[$normalizedKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Test if an item exists.
     *
     * @throws Exception\ExceptionInterface
     * @triggers hasItem.pre(PreEvent)
     * @triggers hasItem.post(PostEvent)
     * @triggers hasItem.exception(ExceptionEvent)
     */
    public function hasItem(string $key): bool
    {
        if (! $this->getOptions()->getReadable()) {
            return false;
        }

        $this->assertValidKey($key);
        $args = new ArrayObject([
            'key' => $key,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalHasItem($args['key']);

            $result = $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (Throwable $throwable) {
            $result = $this->triggerThrowable(__FUNCTION__, $args, false, $throwable);
        }

        Assert::boolean($result);
        return $result;
    }

    /**
     * Internal method to test if an item exists.
     *
     * @param non-empty-string $normalizedKey
     * @throws Exception\ExceptionInterface
     */
    protected function internalHasItem(string $normalizedKey): bool
    {
        $success = null;
        $this->internalGetItem($normalizedKey, $success);

        return $success;
    }

    /**
     * {@inheritDoc}
     *
     * @triggers hasItems.pre(PreEvent)
     * @triggers hasItems.post(PostEvent)
     * @triggers hasItems.exception(ExceptionEvent)
     */
    public function hasItems(array $keys): array
    {
        if (! $this->getOptions()->getReadable()) {
            return [];
        }

        $keys = $this->normalizeKeys($keys);
        $args = new ArrayObject([
            'keys' => $keys,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            $keys    = $this->normalizeKeys($args['keys']);
            $result  = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalHasItems($keys);

            $result = $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (Throwable $throwable) {
            $result = $this->triggerThrowable(__FUNCTION__, $args, [], $throwable);
        }

        self::assertListOfKeys($result);
        return $result;
    }

    /**
     * Internal method to test multiple items.
     *
     * @param non-empty-list<non-empty-string|int> $normalizedKeys
     * @return list<non-empty-string|int> Array of found keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalHasItems(array $normalizedKeys): array
    {
        $result = [];
        foreach ($normalizedKeys as $normalizedKey) {
            if ($this->internalHasItem((string) $normalizedKey)) {
                $result[] = $normalizedKey;
            }
        }

        return $result;
    }

    /* writing */

    /**
     * Store an item.
     *
     * @throws Exception\ExceptionInterface
     * @triggers setItem.pre(PreEvent)
     * @triggers setItem.post(PostEvent)
     * @triggers setItem.exception(ExceptionEvent)
     */
    public function setItem(string $key, mixed $value): bool
    {
        if (! $this->getOptions()->getWritable()) {
            return false;
        }

        $this->assertValidKey($key);
        $args = new ArrayObject([
            'key'   => $key,
            'value' => $value,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalSetItem($args['key'], $args['value']);

            $result = $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (Throwable $throwable) {
            $result = $this->triggerThrowable(__FUNCTION__, $args, false, $throwable);
        }

        Assert::boolean($result);
        return $result;
    }

    /**
     * Internal method to store an item.
     *
     * @param non-empty-string $normalizedKey
     * @throws Exception\ExceptionInterface
     */
    abstract protected function internalSetItem(string $normalizedKey, mixed $value): bool;

    /**
     * {@inheritDoc}
     */
    public function setItems(array $keyValuePairs): array
    {
        if (! $this->getOptions()->getWritable()) {
            return array_keys($keyValuePairs);
        }

        $this->assertValidKeyValuePairs($keyValuePairs);
        $args = new ArrayObject([
            'keyValuePairs' => $keyValuePairs,
        ]);

        try {
            $eventRs       = $this->triggerPre(__FUNCTION__, $args);
            $keyValuePairs = $args['keyValuePairs'];
            $this->assertValidKeyValuePairs($keyValuePairs);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalSetItems($args['keyValuePairs']);

            $result = $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (Throwable $throwable) {
            $result = $this->triggerThrowable(__FUNCTION__, $args, array_keys($keyValuePairs), $throwable);
        }

        $this->assertListOfKeys($result);
        return $result;
    }

    /**
     * Internal method to store multiple items.
     *
     * @param non-empty-array<non-empty-string|int,mixed> $normalizedKeyValuePairs
     * @return list<non-empty-string|int> Array of not stored keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalSetItems(array $normalizedKeyValuePairs): array
    {
        $failedKeys = [];
        foreach ($normalizedKeyValuePairs as $normalizedKey => $value) {
            if (! $this->internalSetItem((string) $normalizedKey, $value)) {
                $failedKeys[] = $normalizedKey;
            }
        }

        return $failedKeys;
    }

    /**
     * {@inheritDoc}
     */
    public function addItem(string $key, mixed $value): bool
    {
        if (! $this->getOptions()->getWritable()) {
            return false;
        }

        $this->assertValidKey($key);
        $args = new ArrayObject([
            'key'   => $key,
            'value' => $value,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $key = $args['key'];
            $this->assertValidKey($key);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalAddItem($key, $args['value']);

            $result = $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (Throwable $throwable) {
            $result = $this->triggerThrowable(__FUNCTION__, $args, false, $throwable);
        }

        Assert::boolean($result);
        return $result;
    }

    /**
     * Internal method to add an item.
     *
     * @param non-empty-string $normalizedKey
     * @throws Exception\ExceptionInterface
     */
    protected function internalAddItem(string $normalizedKey, mixed $value): bool
    {
        if ($this->internalHasItem($normalizedKey)) {
            return false;
        }

        return $this->internalSetItem($normalizedKey, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function addItems(array $keyValuePairs): array
    {
        if (! $this->getOptions()->getWritable()) {
            return array_keys($keyValuePairs);
        }

        $this->assertValidKeyValuePairs($keyValuePairs);
        $args = new ArrayObject([
            'keyValuePairs' => $keyValuePairs,
        ]);

        try {
            $eventRs       = $this->triggerPre(__FUNCTION__, $args);
            $keyValuePairs = $args['keyValuePairs'];
            $this->assertValidKeyValuePairs($keyValuePairs);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalAddItems($keyValuePairs);

            $result = $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (Throwable $throwable) {
            $result = $this->triggerThrowable(__FUNCTION__, $args, array_keys($keyValuePairs), $throwable);
        }

        Assert::isList($result);
        Assert::allStringNotEmpty($result);
        return $result;
    }

    /**
     * Internal method to add multiple items.
     *
     * @param non-empty-array<non-empty-string|int,mixed> $normalizedKeyValuePairs
     * @return list<non-empty-string|int> Array of not stored keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalAddItems(array $normalizedKeyValuePairs): array
    {
        $result = [];
        foreach ($normalizedKeyValuePairs as $normalizedKey => $value) {
            if (! $this->internalAddItem((string) $normalizedKey, $value)) {
                $result[] = $normalizedKey;
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function replaceItem(string $key, mixed $value): bool
    {
        if (! $this->getOptions()->getWritable()) {
            return false;
        }

        $this->assertValidKey($key);
        $args = new ArrayObject([
            'key'   => $key,
            'value' => $value,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $key = $args['key'];
            $this->assertValidKey($key);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalReplaceItem($key, $args['value']);

            $result = $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (Throwable $throwable) {
            $result = $this->triggerThrowable(__FUNCTION__, $args, false, $throwable);
        }

        Assert::boolean($result);
        return $result;
    }

    /**
     * Internal method to replace an existing item.
     *
     * @param non-empty-string $normalizedKey
     * @throws Exception\ExceptionInterface
     */
    protected function internalReplaceItem(string $normalizedKey, mixed $value): bool
    {
        if (! $this->internalhasItem($normalizedKey)) {
            return false;
        }

        return $this->internalSetItem($normalizedKey, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function replaceItems(array $keyValuePairs): array
    {
        if (! $this->getOptions()->getWritable()) {
            return array_keys($keyValuePairs);
        }

        $this->assertValidKeyValuePairs($keyValuePairs);
        $args = new ArrayObject([
            'keyValuePairs' => $keyValuePairs,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalReplaceItems($args['keyValuePairs']);

            $result = $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (Throwable $throwable) {
            $result = $this->triggerThrowable(__FUNCTION__, $args, array_keys($keyValuePairs), $throwable);
        }

        $this->assertListOfKeys($result);
        return $result;
    }

    /**
     * Internal method to replace multiple existing items.
     *
     * @param non-empty-array<non-empty-string|int,mixed> $normalizedKeyValuePairs
     * @return list<non-empty-string|int> Array of not stored keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalReplaceItems(array $normalizedKeyValuePairs): array
    {
        $result = [];
        foreach ($normalizedKeyValuePairs as $normalizedKey => $value) {
            if (! $this->internalReplaceItem((string) $normalizedKey, $value)) {
                $result[] = $normalizedKey;
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function checkAndSetItem(mixed $token, string $key, mixed $value): bool
    {
        if (! $this->getOptions()->getWritable()) {
            return false;
        }

        $this->assertValidKey($key);
        $args = new ArrayObject([
            'token' => $token,
            'key'   => $key,
            'value' => $value,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            $key     = $args['key'];
            $this->assertValidKey($key);
            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalCheckAndSetItem($args['token'], $key, $args['value']);

            $result = $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (Throwable $throwable) {
            $result = $this->triggerThrowable(__FUNCTION__, $args, false, $throwable);
        }
        Assert::boolean($result);
        return $result;
    }

    /**
     * Internal method to set an item only if token matches
     *
     * @param non-empty-string $normalizedKey
     * @throws Exception\ExceptionInterface
     */
    protected function internalCheckAndSetItem(mixed $token, string $normalizedKey, mixed $value): bool
    {
        $oldValue = $this->internalGetItem($normalizedKey);
        if ($oldValue !== $token) {
            return false;
        }

        return $this->internalSetItem($normalizedKey, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function touchItem(string $key): bool
    {
        if (! $this->getOptions()->getWritable()) {
            return false;
        }

        $this->assertValidKey($key);
        $args = new ArrayObject([
            'key' => $key,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalTouchItem($args['key']);

            $result = $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (Throwable $throwable) {
            $result = $this->triggerThrowable(__FUNCTION__, $args, false, $throwable);
        }

        Assert::boolean($result);
        return $result;
    }

    /**
     * Internal method to reset lifetime of an item
     *
     * @param non-empty-string $normalizedKey
     * @throws Exception\ExceptionInterface
     */
    protected function internalTouchItem(string $normalizedKey): bool
    {
        $success = null;
        $value   = $this->internalGetItem($normalizedKey, $success);
        if (! $success) {
            return false;
        }

        return $this->internalReplaceItem($normalizedKey, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function touchItems(array $keys): array
    {
        if (! $this->getOptions()->getWritable()) {
            return $keys;
        }

        $keys = $this->normalizeKeys($keys);
        $args = new ArrayObject([
            'keys' => $keys,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalTouchItems($args['keys']);

            $result = $this->triggerPost(__FUNCTION__, $args, $result);
            $this->assertListOfKeys($result);
            return $result;
        } catch (Throwable $throwable) {
            $result = $this->triggerThrowable(__FUNCTION__, $args, $keys, $throwable);
            $this->assertListOfKeys($result);
            return $result;
        }
    }

    /**
     * Internal method to reset lifetime of multiple items.
     *
     * @param non-empty-list<non-empty-string|int> $normalizedKeys
     * @return list<non-empty-string|int> Array of not updated keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalTouchItems(array $normalizedKeys): array
    {
        $result = [];
        foreach ($normalizedKeys as $normalizedKey) {
            if (! $this->internalTouchItem((string) $normalizedKey)) {
                $result[] = $normalizedKey;
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function removeItem(string $key): bool
    {
        if (! $this->getOptions()->getWritable()) {
            return false;
        }

        $this->assertValidKey($key);
        $args = new ArrayObject([
            'key' => $key,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            $key     = $args['key'];
            $this->assertValidKey($key);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalRemoveItem($key);

            $result = $this->triggerPost(__FUNCTION__, $args, $result);
            Assert::boolean($result);
            return $result;
        } catch (Throwable $throwable) {
            $result = $this->triggerThrowable(__FUNCTION__, $args, false, $throwable);
            Assert::boolean($result);
            return $result;
        }
    }

    /**
     * Internal method to remove an item.
     *
     * @param non-empty-string $normalizedKey
     * @throws Exception\ExceptionInterface
     */
    abstract protected function internalRemoveItem(string $normalizedKey): bool;

    /**
     * {@inheritDoc}
     */
    public function removeItems(array $keys): array
    {
        if (! $this->getOptions()->getWritable()) {
            return $keys;
        }

        $keys = $this->normalizeKeys($keys);
        $args = new ArrayObject([
            'keys' => $keys,
        ]);

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            $keys    = $args['keys'];
            $this->normalizeKeys($keys);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalRemoveItems($keys);

            $result = $this->triggerPost(__FUNCTION__, $args, $result);
            Assert::isList($result);
            Assert::allStringNotEmpty($result);
            return $result;
        } catch (Throwable $throwable) {
            $result = $this->triggerThrowable(__FUNCTION__, $args, $keys, $throwable);
            Assert::isList($result);
            Assert::allStringNotEmpty($result);
            return $result;
        }
    }

    /**
     * Internal method to remove multiple items.
     *
     * @param non-empty-list<non-empty-string|int> $normalizedKeys
     * @return list<non-empty-string|int> Array of not removed keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalRemoveItems(array $normalizedKeys): array
    {
        $result = [];
        foreach ($normalizedKeys as $normalizedKey) {
            if (! $this->internalRemoveItem((string) $normalizedKey)) {
                $result[] = $normalizedKey;
            }
        }

        return $result;
    }

    /* status */

    /**
     * {@inheritDoc}
     */
    public function getCapabilities(): Capabilities
    {
        /** @var ArrayObject<string,mixed> $args */
        $args = new ArrayObject();

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);

            $result = $eventRs->stopped()
                ? $eventRs->last()
                : $this->internalGetCapabilities();

            $result = $this->triggerPost(__FUNCTION__, $args, $result);
            Assert::isInstanceOf($result, Capabilities::class);
            return $result;
        } catch (Throwable $throwable) {
            $result = $this->triggerThrowable(__FUNCTION__, $args, new Capabilities(), $throwable);
            Assert::isInstanceOf($result, Capabilities::class);

            return $result;
        }
    }

    /**
     * Internal method to get capabilities of this adapter
     */
    protected function internalGetCapabilities(): Capabilities
    {
        return $this->capabilities ??= new Capabilities();
    }

    /**
     * Validates and normalizes multiple keys
     *
     * @param non-empty-list<non-empty-string|int> $keys
     * @return non-empty-list<non-empty-string|int> $keys
     * @throws Exception\InvalidArgumentException On an invalid key.
     */
    protected function normalizeKeys(array $keys): array
    {
        foreach ($keys as $key) {
            $this->assertValidKey($key);
        }

        return array_values(array_unique($keys));
    }

    /**
     * @template TKey
     * @param TKey $key
     * @psalm-assert (TKey is string ? non-empty-string : non-empty-string|int) $key
     */
    protected function assertValidKey(mixed $key): void
    {
        if (! is_int($key) && ! is_string($key)) {
            throw new Exception\InvalidArgumentException(
                "Key has to be either string or int"
            );
        }

        if ($key === '') {
            throw new Exception\InvalidArgumentException(
                "An empty key isn't allowed"
            );
        }

        $key = (string) $key;

        $pattern = $this->getOptions()->getKeyPattern();
        if ($pattern !== '' && ! preg_match($pattern, $key)) {
            throw new Exception\InvalidArgumentException(
                sprintf(
                    "The key '%s' doesn't match against pattern '%s'",
                    $key,
                    $pattern,
                ),
            );
        }
    }

    /**
     * @psalm-assert non-empty-array<non-empty-string,mixed> $keyValuePairs
     */
    protected function assertValidKeyValuePairs(mixed $keyValuePairs): void
    {
        if (! is_array($keyValuePairs)) {
            throw new Exception\InvalidArgumentException(
                "Key/Value pairs have to be an array"
            );
        }

        if ($keyValuePairs === []) {
            throw new InvalidArgumentException('Key/Value pairs must not be empty.');
        }

        foreach (array_keys($keyValuePairs) as $key) {
            $this->assertValidKey($key);
        }
    }

    /**
     * @psalm-assert list<non-empty-string|int> $keys
     */
    protected function assertValidKeys(array $keys): void
    {
        foreach ($keys as $key) {
            $this->assertValidKey($key);
        }
    }
}
