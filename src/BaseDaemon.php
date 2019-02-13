<?php

namespace primipilus\daemon;

use primipilus\daemon\exceptions\DaemonAlreadyRunException;
use primipilus\daemon\exceptions\DaemonNotActiveException;
use primipilus\daemon\exceptions\FailureForkProcessException;
use primipilus\daemon\exceptions\FailureGetPidException;
use primipilus\daemon\exceptions\FailureGetPidFileException;
use primipilus\daemon\exceptions\FailureOpenPidFileException;
use primipilus\daemon\exceptions\FailureStopException;
use primipilus\daemon\exceptions\FailureWritePidFileException;
use primipilus\daemon\exceptions\InvalidOptionException;

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
    /** @var string */
    protected $pidChildrenFile;
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
    protected $poolSize = 2;
    /** @var ProcessDetailsCollection */
    protected $processes = [];
    /** @var bool */
    protected $parent = true;

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
                call_user_func([$this, $method], $value);
            } else {
                throw new InvalidOptionException('option ' . $option . ' is invalid');
            }
        }
        $this->processes = new ProcessDetailsCollection();
    }

    /**
     * @return bool
     */
    public function daemonize() : bool
    {
        return $this->daemonize;
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
        $this->setProcessFromPidFile();
        if ($this->isActive()) {
            if ($this->killAllChildrenProcesses() && $this->stopPid($this->pid())) {
                return true;
            }
            throw new FailureStopException('pid: ' . $this->pid());
        } else {
            throw new DaemonNotActiveException('Daemon ' . $this->name() . ' not active');
        }
    }

    /**
     * @return bool
     * @throws FailureGetPidFileException
     * @throws InvalidOptionException
     */
    final private function isActive() : bool
    {
        if (!$this->pid()) {
            if (file_exists($this->pidFile())) {
                $this->setPidFromPidFile();
                if (!$this->pid()) {
                    throw new FailureGetPidFileException();
                }
            }
        }
        if ($this->pid() && posix_kill($this->pid(), 0)) {
            return true;
        }
        return false;
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
        if (is_null($this->pidFile)) {
            $this->pidFile = $this->runtimeDir() . '/' . $this->name() . '.pid';
        }
        return $this->pidFile;
    }


    /**
     * @return string
     * @throws InvalidOptionException
     */
    public function pidChildrenFile() : string
    {
        if (is_null($this->pidChildrenFile)) {
            $this->pidChildrenFile = $this->runtimeDir() . '/' . $this->name() . 'Children.pid';
        }
        return $this->pidChildrenFile;
    }

    /**
     * @return string
     * @throws InvalidOptionException
     */
    public function runtimeDir() : string
    {
        if (is_null($this->runtimeDir)) {
            throw new InvalidOptionException('option runtimeDir is invalid');
        }
        return $this->runtimeDir;
    }

    /**
     * @return string
     */
    public function name() : string
    {
        if (is_null($this->name)) {
            $this->name = implode('.', array_reverse(explode('\\', static::class)));
        }
        return $this->name;
    }

    /**
     * @throws InvalidOptionException
     */
    protected function setPidFromPidFile() : void
    {
        $pid = file_get_contents($this->pidFile());
        $this->setPid($pid);
    }

    /**
     * @throws InvalidOptionException
     */
    protected function setProcessFromPidFile() : void
    {
        $pid = file_get_contents($this->pidChildrenFile());
        $i = 0;
        foreach (explode(',', $pid) as $item){
            $this->processes->addProcess(new ProcessDetails(++$i,  $item));
        }
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
    public function stopPid(int $pid, int $attempts = 50) : bool
    {
        if ($pid > 0) {
            for ($k = $attempts; $k || !$attempts; $k--) {
                if (!posix_kill($pid, SIGTERM)) {
                    return true;
                }
                sleep(1);
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
            throw new DaemonAlreadyRunException();
        }
        if (!$this->daemonize) {
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
            throw new FailureGetPidException();
        }
        $this->savePid();

        $this->setMainProcess();

        $this->setErrorLog();
        $GLOBALS['STDIN'] = fopen('/dev/null', 'r');
        $GLOBALS['STDOUT'] = fopen('/dev/null', 'ab');
        $this->signals();

        try {
            while (!$this->isStopProcess()) {
                // выполняем работу
                $this->process();
                $this->dispatch();
            }
        } catch (\Exception|\Error $e) {
            $this->putErrorLog($e);
        }
        $this->afterStop();
        if($this->isParent()){
            $this->removePidChildrenFile();
            $this->removePidFile();
        }
        $this->end();
    }

    abstract protected function process() : void;

    /**
     * action after daemon stop
     */
    protected function afterStop() : void
    {
    }

    /**
     * end of process
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
        if (-1 == $processId) {
            throw new FailureForkProcessException();
        }
        return $processId;
    }

    /**
     * @throws FailureForkProcessException
     * @throws FailureOpenPidFileException
     * @throws FailureWritePidFileException
     * @throws InvalidOptionException
     */
    protected function forkChild()
    {
        $this->putErrorLog($this->pid());
        $pid = $this->fork();
        if($pid === 0){
            $this->parent = false;
        }else{
            $this->processes->addProcess(new ProcessDetails($this->processes->getNextId(), $pid));
            $this->saveChildrenPid();
        }
        $this->putErrorLog($pid);
    }

    /**
     * @return bool
     */
    public function isParent(): bool
    {
        return $this->parent;
    }

    /**
     * @throws FailureOpenPidFileException
     * @throws FailureWritePidFileException
     * @throws InvalidOptionException
     */
    protected function savePid() : void
    {
        if ($handle = @fopen($this->pidFile(), 'w')) {
            if (@flock($handle, LOCK_EX)) {
                $result = @fwrite($handle, $this->pid());
                @flock($handle, LOCK_UN);
                if ($result) {
                    @fclose($handle);
                    return;
                }
            }
            @fclose($handle);
            throw new FailureWritePidFileException();
        } else {
            throw new FailureOpenPidFileException();
        }
    }

    /**
     * @throws FailureOpenPidFileException
     * @throws FailureWritePidFileException
     * @throws InvalidOptionException
     */
    protected function saveChildrenPid() : void
    {
        if ($handle = @fopen($this->pidChildrenFile(), 'w')) {
            if (@flock($handle, LOCK_EX)) {
                $result = @fwrite($handle, implode(',', $this->processes->getPids()));
                @flock($handle, LOCK_UN);
                if ($result) {
                    @fclose($handle);
                    return;
                }
            }
            @fclose($handle);
            throw new FailureWritePidFileException();
        } else {
            throw new FailureOpenPidFileException();
        }
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
     * @param mixed $message
     * @throws InvalidOptionException
     */
    protected function putErrorLog($message) : void
    {
        file_put_contents($this->errorLog(), (string)$message . PHP_EOL, FILE_APPEND);
    }

    /**
     * remove file with pid
     * @throws InvalidOptionException
     */
    protected function removePidFile() : void
    {
        @unlink($this->pidFile());
    }

    /**
     * remove file with pid
     * @throws InvalidOptionException
     */
    protected function removePidChildrenFile() : void
    {
        @unlink($this->pidChildrenFile());
    }

    /**
     * @param int $dirPermissions
     */
    public function setDirPermissions(int $dirPermissions) : void
    {
        $this->dirPermissions = $dirPermissions;
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
            mkdir($this->runtimeDir, $this->dirPermissions(), true);
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
     * @param int $pid
     */
    protected function childrenProcess($pid = -1) {
        if (!is_int($pid)) {
            $pid = -1;
        }
        $childPid = pcntl_waitpid($pid, $status, WNOHANG);
        while ($childPid > 0) {
            $this->processes->remove($childPid);
            $childPid = pcntl_waitpid($pid, $status, WNOHANG);
        }
    }

    /**
     * @param int   $signalNumber
     * @param mixed $signalInfo
     */
    protected function signalHandler(int $signalNumber, $signalInfo) : void
    {
        switch ($signalNumber) {
            case SIGTERM:
                $this->stopProcess();
                break;
            case SIGCHLD:
                $this->childrenProcess();
                break;
        }
    }

    /**
     *
     */
    protected function stopProcess() : void
    {
        $this->stopProcess = true;
    }

    public function killAllChildrenProcesses() : bool
    {
        foreach ($this->processes->getProcessDetails() as $processDetails) {
            if ($processDetails->pid() > 0) {
                for ($k = 50; $k || !50; $k--) {
                    if (!posix_kill($processDetails->pid(), SIGTERM)) {
                        $this->processes->remove($processDetails->pid());
                        continue 2;
                    }
                    sleep(1);
                }
            }
        }
        return true;
    }
}