<?php

namespace Laminas\Cache\Pattern;

use GlobIterator;
use Laminas\Cache\Exception;
use Laminas\Stdlib\ErrorHandler;

use function array_unshift;
use function basename;
use function chmod;
use function decoct;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function ob_implicit_flush;
use function ob_start;
use function rtrim;
use function str_ends_with;
use function str_replace;
use function umask;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;

final class CaptureCache extends AbstractPattern
{
    public function start(string|null $pageId = null): void
    {
        if ($pageId === null) {
            $pageId = $this->detectPageId();
        }

        ob_start(function ($content) use ($pageId): bool {
            $this->set($content, $pageId);

            // http://php.net/manual/function.ob-start.php
            // -> If output_callback  returns FALSE original input is sent to the browser.
            return false;
        });

        ob_implicit_flush(false);
    }

    /**
     * Write content to page identity
     *
     * @throws Exception\LogicException
     */
    public function set(string $content, string|null $pageId = null): void
    {
        $publicDir = $this->getOptions()->getPublicDir();
        if ($publicDir === null) {
            throw new Exception\LogicException("Option 'public_dir' no set");
        }

        if ($pageId === null) {
            $pageId = $this->detectPageId();
        }

        $path = $this->pageId2Path($pageId);
        $file = $path . DIRECTORY_SEPARATOR . $this->pageId2Filename($pageId);

        $this->createDirectoryStructure($publicDir . DIRECTORY_SEPARATOR . $path);
        $this->putFileContent($publicDir . DIRECTORY_SEPARATOR . $file, $content);
    }

    /**
     * Get from cache
     *
     * @throws Exception\LogicException
     * @throws Exception\RuntimeException
     */
    public function get(string|null $pageId = null): string|null
    {
        $publicDir = $this->getOptions()->getPublicDir();
        if ($publicDir === null) {
            throw new Exception\LogicException("Option 'public_dir' no set");
        }

        if ($pageId === null) {
            $pageId = $this->detectPageId();
        }

        $file = $publicDir
            . DIRECTORY_SEPARATOR . $this->pageId2Path($pageId)
            . DIRECTORY_SEPARATOR . $this->pageId2Filename($pageId);

        if (file_exists($file)) {
            ErrorHandler::start();
            $content = file_get_contents($file);
            $error   = ErrorHandler::stop();
            if ($content === false) {
                throw new Exception\RuntimeException("Failed to read cached pageId '{$pageId}'", 0, $error);
            }
            return $content;
        }

        return null;
    }

    /**
     * Checks if a cache with given id exists
     *
     * @throws Exception\LogicException
     */
    public function has(string|null $pageId = null): bool
    {
        $publicDir = $this->getOptions()->getPublicDir();
        if ($publicDir === null) {
            throw new Exception\LogicException("Option 'public_dir' no set");
        }

        if ($pageId === null) {
            $pageId = $this->detectPageId();
        }

        $file = $publicDir
            . DIRECTORY_SEPARATOR . $this->pageId2Path($pageId)
            . DIRECTORY_SEPARATOR . $this->pageId2Filename($pageId);

        return file_exists($file);
    }

    /**
     * Remove from cache
     *
     * @throws Exception\LogicException
     * @throws Exception\RuntimeException
     */
    public function remove(string|null $pageId = null): bool
    {
        $publicDir = $this->getOptions()->getPublicDir();
        if ($publicDir === null) {
            throw new Exception\LogicException("Option 'public_dir' no set");
        }

        if ($pageId === null) {
            $pageId = $this->detectPageId();
        }

        $file = $publicDir
            . DIRECTORY_SEPARATOR . $this->pageId2Path($pageId)
            . DIRECTORY_SEPARATOR . $this->pageId2Filename($pageId);

        if (file_exists($file)) {
            ErrorHandler::start();
            $res = unlink($file);
            $err = ErrorHandler::stop();
            if (! $res) {
                throw new Exception\RuntimeException("Failed to remove cached pageId '{$pageId}'", 0, $err);
            }
            return true;
        }

        return false;
    }

    /**
     * Clear cached pages matching glob pattern
     *
     * @throws Exception\LogicException
     */
    public function clearByGlob(string $pattern = '**'): void
    {
        $publicDir = $this->getOptions()->getPublicDir();
        if ($publicDir === null) {
            throw new Exception\LogicException("Option 'public_dir' no set");
        }

        $it = new GlobIterator(
            $publicDir . '/' . $pattern,
            GlobIterator::CURRENT_AS_SELF | GlobIterator::SKIP_DOTS | GlobIterator::UNIX_PATHS
        );
        foreach ($it as $pathname => $entry) {
            if ($entry->isFile()) {
                unlink($pathname);
            }
        }
    }

    /**
     * Determine the page to save from the request
     *
     * @throws Exception\RuntimeException
     */
    protected function detectPageId(): string
    {
        if (! isset($_SERVER['REQUEST_URI'])) {
            throw new Exception\RuntimeException("Can't auto-detect current page identity");
        }

        return $_SERVER['REQUEST_URI'];
    }

    /**
     * Get filename for page id
     */
    protected function pageId2Filename(string $pageId): string
    {
        if (str_ends_with($pageId, '/')) {
            return $this->getOptions()->getIndexFilename();
        }

        return basename($pageId);
    }

    /**
     * Get path for page id
     */
    protected function pageId2Path(string $pageId): string
    {
        if (str_ends_with($pageId, '/')) {
            $path = rtrim($pageId, '/');
        } else {
            $path = dirname($pageId);
        }

        // convert requested "/" to the valid local directory separator
        if (DIRECTORY_SEPARATOR !== '/') {
            $path = str_replace('/', DIRECTORY_SEPARATOR, $path);
        }

        return $path;
    }

    /**
     * Write content to a file
     *
     * @param  string  $file File complete path
     * @param  string  $data Data to write
     * @throws Exception\RuntimeException
     */
    protected function putFileContent(string $file, string $data): void
    {
        $options = $this->getOptions();
        $locking = $options->getFileLocking();
        $perm    = $options->getFilePermission();
        $umask   = $options->getUmask();
        if ($umask !== false && $perm !== false) {
            $perm &= ~$umask;
        }

        ErrorHandler::start();

        $umask = $umask !== false ? umask($umask) : false;
        $rs    = file_put_contents($file, $data, $locking ? LOCK_EX : 0);
        if ($umask !== false) {
            umask($umask);
        }

        if ($rs === false) {
            $err = ErrorHandler::stop();
            throw new Exception\RuntimeException("Error writing file '{$file}'", 0, $err);
        }

        if ($perm !== false && ! chmod($file, $perm)) {
            $oct = decoct($perm);
            $err = ErrorHandler::stop();
            throw new Exception\RuntimeException("chmod('{$file}', 0{$oct}) failed", 0, $err);
        }

        ErrorHandler::stop();
    }

    /**
     * Creates directory if not already done.
     *
     * @throws Exception\RuntimeException
     */
    protected function createDirectoryStructure(string $pathname): void
    {
        // Directory structure already exists
        if (file_exists($pathname)) {
            return;
        }

        $options = $this->getOptions();
        $perm    = $options->getDirPermission();
        $umask   = $options->getUmask();
        if ($umask !== false && $perm !== false) {
            $perm &= ~$umask;
        }

        ErrorHandler::start();

        if ($perm === false) {
            // built-in mkdir function is enough

            $umask = $umask !== false ? umask($umask) : false;
            $res   = mkdir($pathname, 0775, true);

            if ($umask !== false) {
                umask($umask);
            }

            if (! $res) {
                $oct = '775';
                $err = ErrorHandler::stop();
                throw new Exception\RuntimeException("mkdir('{$pathname}', 0{$oct}, true) failed", 0, $err);
            }
        } else {
            // built-in mkdir function sets permission together with current umask
            // which doesn't work well on multo threaded webservers
            // -> create directories one by one and set permissions

            // find existing path and missing path parts
            $parts = [];
            $path  = $pathname;
            while (! file_exists($path)) {
                array_unshift($parts, basename($path));
                $nextPath = dirname($path);
                if ($nextPath === $path) {
                    break;
                }
                $path = $nextPath;
            }

            // make all missing path parts
            foreach ($parts as $part) {
                $path .= DIRECTORY_SEPARATOR . $part;

                // create a single directory, set and reset umask immediately
                $umask = $umask !== false ? umask($umask) : false;
                $res   = mkdir($path, $perm, false);
                if ($umask !== false) {
                    umask($umask);
                }

                if (! $res) {
                    $oct = decoct($perm);
                    ErrorHandler::stop();
                    throw new Exception\RuntimeException(
                        "mkdir('{$path}', 0{$oct}, false) failed"
                    );
                }

                if (! chmod($path, $perm)) {
                    $oct = decoct($perm);
                    ErrorHandler::stop();
                    throw new Exception\RuntimeException(
                        "chmod('{$path}', 0{$oct}) failed"
                    );
                }
            }
        }

        ErrorHandler::stop();
    }

    /**
     * Returns the generated file name.
     */
    public function getFilename(string|null $pageId = null): string
    {
        if ($pageId === null) {
            $pageId = $this->detectPageId();
        }

        $publicDir = $this->getOptions()->getPublicDir();
        $path      = $this->pageId2Path($pageId);
        $file      = $path . DIRECTORY_SEPARATOR . $this->pageId2Filename($pageId);

        return $publicDir . $file;
    }
}
