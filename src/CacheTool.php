<?php

/*
 * This file is part of CacheTool.
 *
 * (c) Samuel Gordalina <samuel.gordalina@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CacheTool;

use CacheTool\Adapter\AbstractAdapter;
use CacheTool\Proxy\ProxyInterface;
use Psr\Log\LoggerInterface;
use Monolog\Logger;

class CacheTool
{
    /**
     * @var AbstractAdapter
     */
    protected $adapter;

    /**
     * @var array
     */
    protected $proxies = [];

    /**
     * @var array
     */
    protected $functions = [];

    /**
     * @var string
     */
    protected $tempDir;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param string          $tempDir
     * @param LoggerInterface $logger
     */
    public function __construct($tempDir = null, LoggerInterface $logger = null)
    {
        $this->logger = $logger ?: new Logger('cachetool');
        $this->tempDir = $this->getWritableTempDir($tempDir);
    }

    /**
     * @param  AbstractAdapter $adapter
     * @param  string          $tempDir
     * @param  LoggerInterface $logger
     * @return CacheTool
     */
    public static function factory(AbstractAdapter $adapter = null, $tempDir = null, LoggerInterface $logger = null)
    {
        $cacheTool = new static($tempDir, $logger);
        $cacheTool->addProxy(new Proxy\ApcuProxy());
        $cacheTool->addProxy(new Proxy\PhpProxy());
        $cacheTool->addProxy(new Proxy\OpcacheProxy());

        if ($adapter instanceof AbstractAdapter) {
            $cacheTool->setAdapter($adapter);
        }

        return $cacheTool;
    }


    /**
     * @param  AbstractAdapter $adapter
     * @return CacheTool
     */
    public function setAdapter(AbstractAdapter $adapter)
    {
        $this->logger->info(sprintf('Setting adapter: %s', get_class($adapter)));

        $this->adapter = $adapter;
        $this->adapter->setLogger($this->logger);
        $this->adapter->setTempDir($this->tempDir);

        return $this;
    }

    /**
     * @return AbstractAdapter
     */
    public function getAdapter()
    {
        return $this->adapter;
    }

    public function getTempDir()
    {
        return $this->tempDir;
    }

    /**
     * @param ProxyInterface $proxy
     * @return CacheTool
     */
    public function addProxy(ProxyInterface $proxy)
    {
        $this->logger->info(sprintf('Adding Proxy: %s', get_class($proxy)));

        $this->proxies[] = $proxy;

        // reset functions (to be built when needed)
        $this->functions = [];

        return $this;
    }

    /**
     * @return array
     */
    public function getProxies()
    {
        return $this->proxies;
    }

    /**
     * @param  LoggerInterface $logger
     * @return CacheTool
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        if ($this->adapter instanceof AbstractAdapter) {
            $this->adapter->setLogger($logger);
        }

        return $this;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Calls proxy functions
     *
     * @param  string $name
     * @param  array $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        $this->logger->notice(sprintf('Executing: %s(%s)', $name, implode(', ', array_map('json_encode', $arguments))));

        $function = $this->getFunction($name);
        if ($function) {
            return $function(...$arguments);
        }
    }

    /**
     * Initializes functions and return callable
     *
     * @param  string $name
     * @return callable
     */
    protected function getFunction($name)
    {
        if (empty($this->functions)) {
            foreach ($this->proxies as $proxy) {
                $this->logger->info(sprintf('Loading Proxy: %s', get_class($proxy)));

                // lazily set adapter
                $proxy->setAdapter($this->adapter);

                foreach ($proxy->getFunctions() as $fn) {
                    $this->logger->debug(sprintf('Loading Function: %s', $fn));
                    $this->functions[$fn] = [$proxy, $fn];
                }
            }
        }

        if (isset($this->functions[$name])) {
            return $this->functions[$name];
        }

        throw new \InvalidArgumentException("Function with name: {$name} is not provided by any Proxy.");
    }

    /**
     * @param  string $tempDir
     * @return string
     */
    protected function getWritableTempDir($tempDir = null) {
        if (is_null($tempDir)) {
            $tempDirs = ['/dev/shm', '/var/run', sys_get_temp_dir()];
            foreach ($tempDirs as $dir) {
                if (is_dir($dir) && is_writable($dir)) {
                    $tempDir = $dir;
                    break;
                }
            }
        }

        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0700, true);
        }

        return $tempDir;
    }
}