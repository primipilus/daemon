<?php

namespace primipilus\daemon;

use DateTimeImmutable;
use primipilus\daemon\exceptions\DaemonAlreadyRunException;
use primipilus\daemon\exceptions\DaemonNotActiveException;
use primipilus\daemon\exceptions\FailureForkProcessException;
use primipilus\daemon\exceptions\FailureGetPidException;
use primipilus\daemon\exceptions\FailureGetPidFileException;
use primipilus\daemon\exceptions\FailureOpenPidFileException;
use primipilus\daemon\exceptions\FailureStopException;
use primipilus\daemon\exceptions\FailureWritePidFileException;
use primipilus\daemon\exceptions\InvalidOptionException;
use RuntimeException;
use Throwable;

/**
 * Class BaseDaemon
 *
 * options:
 *  runtimeDir: string (required)
 *  daemonize: bool
 *  name: string
 *  dirPermissions: string
 *
 * @package primipilus\daemon
 */
abstract class BaseDaemon
{

    /** @var bool */
    protected $stopProcess = false;
    /** @var string */
    protected $pidFile;
    /** @var int */
    protected $pid = 0;
    /** @var bool */
    protected $daemonize = true;
    /** @var string */
    protected $runtimeDir;
    /** @var string */
    protected $name;
    /** @var int */
    protected $dirPermissions = 0775;
    /** @var int */
    protected $exitStatus = 0;
    /** @var int */
    private $poolSize = 0;
    /** @var ProcessCollection */
    private $subProcesses;
    /** @var int|null */
    private $serialNumber;

    /**
     * BaseDaemon constructor.
     * @param array $options
     * @throws InvalidOptionException
     */
    public function __construct($options = [])
    {
        foreach ($options as $option => $value) {
            $method = 'set' . $option;
            if (method_exists($this, $method)) {
                $this->$method($value);
            } else {
                throw new InvalidOptionException('option ' . $option . ' is invalid');
            }
        }
        $this->subProcesses = new ProcessCollection($this->poolSize);
    }

    /**
     * @return int|null
     */
    public function serialNumber() : ?int
    {
        return $this->serialNumber;
    }

    /**
     * @throws DaemonAlreadyRunException
     * @throws DaemonNotActiveException
     * @throws FailureForkProcessException
     * @throws FailureGetPidException
     * @throws FailureGetPidFileException
     * @throws FailureOpenPidFileException
     * @throws FailureStopException
     * @throws FailureWritePidFileException
     * @throws InvalidOptionException
     */
    public function restart() : void
    {
        $this->stop();
        $this->start();
    }

    /**
     * @return bool
     * @throws DaemonNotActiveException
     * @throws FailureGetPidFileException
     * @throws FailureStopException
     * @throws InvalidOptionException
     */
    public function stop() : bool
    {
        if ($this->isActive()) {
            if ($this->stopPid($this->pid())) {
                return true;
            }
            throw new FailureStopException('pid: ' . $this->pid());
        }

        throw new DaemonNotActiveException('Daemon ' . $this->name() . ' not active');
    }

    /**
     * @return bool
     * @throws FailureGetPidFileException
     * @throws InvalidOptionException
     */
    private function isActive() : bool
    {
        if (!$this->pid() && file_exists($this->pidFile())) {
            $this->setPidFromPidFile();
            if (!$this->pid()) {
                throw new FailureGetPidFileException("Failure ge pid from file {$this->pidFile()}");
            }
        }
        return $this->pid() && posix_kill($this->pid(), 0);
    }

    /**
     * @return int
     */
    public function pid() : int
    {
        return $this->pid;
    }

    /**
     * @return string
     * @throws InvalidOptionException
     */
    public function pidFile() : string
    {
        if (null === $this->pidFile) {
            $this->pidFile = $this->runtimeDir() . '/' . $this->name() . '.pid';
        }
        return $this->pidFile;
    }

    /**
     * @return string
     * @throws InvalidOptionException
     */
    public function runtimeDir() : string
    {
        if (null === $this->runtimeDir) {
            throw new InvalidOptionException('option runtimeDir is invalid');
        }
        return $this->runtimeDir;
    }

    /**
     * @return string
     */
    public function name() : string
    {
        if (null === $this->name) {
            $this->name = implode('.', array_reverse(explode('\\', static::class)));
        }
        return $this->name;
    }

    /**
     * @throws InvalidOptionException
     */
    protected function setPidFromPidFile() : void
    {
        $pid = (int)file_get_contents($this->pidFile());
        $this->setPid($pid);
    }

    /**
     * @param int $pid
     */
    protected function setPid(int $pid) : void
    {
        $this->pid = $pid;
    }

    /**
     * @param int $pid
     * @param int $attempts
     * @return bool
     */
    public function stopPid(int $pid, int $attempts = 0) : bool
    {
        if ($pid > 0) {
            if ($attempts > 0) {
                for ($k = $attempts; $k || !$attempts; $k--) {
                    if (!posix_kill($pid, SIGTERM)) {
                        return true;
                    }
                    sleep(1);
                }
            } else {
                while (true) {
                    if (!posix_kill($pid, SIGTERM)) {
                        return true;
                    }
                    sleep(1);
                }
            }
        }

        return false;
    }

    /**
     * @throws DaemonAlreadyRunException
     * @throws FailureForkProcessException
     * @throws FailureGetPidException
     * @throws FailureGetPidFileException
     * @throws FailureOpenPidFileException
     * @throws FailureWritePidFileException
     * @throws InvalidOptionException
     */
    public function start() : void
    {
        if ($this->isActive()) {
            throw new DaemonAlreadyRunException("Daemon `{$this->name()}` already run`");
        }
        if (!$this->daemonize()) {
            $this->process();
            $this->afterStop();
            $this->end();
        }

        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);

        if ($this->fork()) {
            $this->end();
        }
        $this->setPid((int)getmypid());
        if (!$this->pid()) {
            throw new FailureGetPidException('Failure get pid ');
        }
        $this->savePid();

        $this->setMainProcess();

        $this->setErrorLog();
        $GLOBALS['STDIN'] = fopen('/dev/null', 'rb');
        $GLOBALS['STDOUT'] = fopen('/dev/null', 'ab');
        $this->signals();

        try {
            while (!$this->isStopProcess()) {
                // выполняем работу
                $this->process();
                $this->dispatch();
            }
        } catch (Throwable $e) {
            $this->putErrorLog($e);
        }
        if ($this->isParent()) {
            $this->afterStop();
            $this->removePidFile();
        }
        $this->end();
    }

    /**
     * @return bool
     */
    public function daemonize() : bool
    {
        return $this->daemonize;
    }

    abstract protected function process() : void;

    /**
     * action after daemon stop
     */
    protected function afterStop() : void
    {
    }

    /**
     * End of process
     */
    protected function end() : void
    {
        exit($this->exitStatus);
    }

    /**
     * @return int
     * @throws FailureForkProcessException
     */
    final protected function fork() : int
    {
        $processId = pcntl_fork();
        if (-1 === $processId) {
            throw new FailureForkProcessException('Failure fork process');
        }
        return $processId;
    }

    /**
     * @throws FailureOpenPidFileException
     * @throws FailureWritePidFileException
     * @throws InvalidOptionException
     */
    protected function savePid() : void
    {
        if ($handle = @fopen($this->pidFile(), 'wb')) {
            if (@flock($handle, LOCK_EX)) {
                $result = @fwrite($handle, $this->pid());
                @flock($handle, LOCK_UN);
                if ($result) {
                    @fclose($handle);
                    return;
                }
            }
            @fclose($handle);
            throw new FailureWritePidFileException("Failure write pid to file {$this->pidFile()}");
        }

        throw new FailureOpenPidFileException("Failure open pid file {$this->pidFile()}");
    }

    /**
     * set process as main process
     */
    protected function setMainProcess() : void
    {
        posix_setsid();
    }

    /**
     * @throws InvalidOptionException
     */
    protected function setErrorLog() : void
    {
        ini_set('error_log', $this->errorLog());
    }

    /**
     * @return string
     * @throws InvalidOptionException
     */
    public function errorLog() : string
    {
        return $this->runtimeDir() . '/' . $this->name() . '-error.log';
    }

    /**
     *
     */
    protected function signals() : void
    {
        pcntl_signal(SIGTERM, [$this, 'signalHandler']);
        pcntl_signal(SIGCHLD, [$this, 'signalHandler']);
    }

    /**
     * @return bool
     */
    public function isStopProcess() : bool
    {
        return $this->stopProcess;
    }

    /**
     *
     */
    protected function dispatch() : void
    {
        pcntl_signal_dispatch();
    }

    /**
     * @param string $message
     * @throws InvalidOptionException
     */
    protected function putErrorLog(string $message) : void
    {
        file_put_contents($this->errorLog(),
            (new DateTimeImmutable())->format('[Y-m-d H:i:s] [') . $this->pid() . '] ' . $message . PHP_EOL,
            FILE_APPEND);
    }

    /**
     * @return bool
     */
    public function isParent() : bool
    {
        return null === $this->serialNumber;
    }

    /**
     * Remove file with pid
     * @throws InvalidOptionException
     */
    protected function removePidFile() : void
    {
        @unlink($this->pidFile());
    }

    /**
     * @param int $dirPermissions
     */
    public function setDirPermissions(int $dirPermissions) : void
    {
        $this->dirPermissions = $dirPermissions;
    }

    /**
     * @return ProcessCollection
     */
    public function subProcesses() : ProcessCollection
    {
        return $this->subProcesses;
    }

    /**
     * @return int max count of sub processes
     */
    public function poolSize() : int
    {
        return $this->poolSize;
    }

    /**
     * @return bool
     * @throws FailureForkProcessException
     */
    final protected function forkChild() : bool
    {
        if ($this->daemonize() && $this->isParent() && $this->poolSize() > 0) {
            $serialNumber = $this->subProcesses->getNextId();
            if (null !== $serialNumber) {
                $pid = $this->fork();
                if ($pid === 0) {
                    $this->serialNumber = $serialNumber;
                    $this->subProcesses = new ProcessCollection(0);
                    $this->setPid((int)getmypid());
                    return true;
                }

                return $this->subProcesses->add(new Process($serialNumber, $pid));
            }
        }

        return false;
    }

    /**
     * @param bool $daemonize
     */
    protected function setDaemonize(bool $daemonize) : void
    {
        $this->daemonize = $daemonize;
    }

    /**
     * @param string $runtimeDir
     */
    protected function setRuntimeDir(string $runtimeDir) : void
    {
        $this->runtimeDir = rtrim($runtimeDir, '/');

        if (!file_exists($this->runtimeDir)) {
            $mask = umask();
            umask(0);
            if (!mkdir($concurrentDirectory = $this->runtimeDir, $this->dirPermissions(),
                    true) && !is_dir($concurrentDirectory)) {
                umask($mask);
                throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
            }
            umask($mask);
        }
    }

    /**
     * @return int
     */
    public function dirPermissions() : int
    {
        return $this->dirPermissions;
    }

    /**
     * @param string $name
     */
    protected function setName(string $name) : void
    {
        $this->name = $name;
    }

    /**
     * @param int $exitStatus
     */
    protected function setExitStatus(int $exitStatus) : void
    {
        $this->exitStatus = $exitStatus;
    }

    /**
     * @param int   $signalNumber
     * @param mixed $signalInfo
     */
    protected function signalHandler(
        int $signalNumber,
        /** @noinspection PhpUnusedParameterInspection */ $signalInfo
    ) : void {
        switch ($signalNumber) {
            case SIGTERM:
                $this->stopProcess();
                break;
            case SIGCHLD:
                $this->removeChildProcess();
                break;
        }
    }

    /**
     * Stop current daemon
     */
    protected function stopProcess() : void
    {
        $this->poolSize = 0;
        $this->stopProcess = $this->isParent()
            ? $this->killAllChildrenProcesses()
            : true;
    }

    /**
     * @return bool
     */
    protected function killAllChildrenProcesses() : bool
    {
        do {
            foreach ($this->subProcesses->getElements() as $process) {
                posix_kill($process->pid(), SIGTERM);
            }
            sleep(1);
            $this->removeChildProcess();
        } while ($this->subProcesses->count());

        return true;
    }

    /**
     *
     */
    private function removeChildProcess() : void
    {
        $childPid = pcntl_waitpid(-1, $status, WNOHANG);
        while ($childPid > 0) {
            $this->subProcesses->remove($childPid);
            $childPid = pcntl_waitpid(-1, $status, WNOHANG);
        }
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @param int $poolSize
     */
    private function setPoolSize(int $poolSize) : void
    {
        $this->poolSize = $poolSize;
    }
}
