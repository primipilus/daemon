<?php
/**
 * @author primipilus 03.05.2017
 */

namespace primipilus\daemon;

use primipilus\daemon\exceptions\BaseException;
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
    protected $_stopProcess = false;
    /** @var string */
    protected $_pidFile;
    /** @var int */
    protected $_pid = 0;
    /** @var bool */
    protected $_daemonize = true;
    /** @var string */
    protected $_runtimeDir;
    /** @var string */
    protected $_name;
    /** @var int */
    protected $_dirPermissions = 0775;

    abstract protected function process() : void;

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
    }

    /**
     * @return bool
     */
    public function getDaemonize() : bool
    {
        return $this->_daemonize;
    }

    /**
     * @return string
     * @throws BaseException
     */
    public function getRuntimeDir() : string
    {
        if (is_null($this->_runtimeDir)) {
            throw new BaseException();
        }
        return $this->_runtimeDir;
    }

    /**
     * @return string
     */
    public function getName() : string
    {
        if (is_null($this->_name)) {
            $this->_name = implode('.', array_reverse(explode('\\', static::class)));
        }
        return $this->_name;
    }

    /**
     * @return string
     */
    public function getPidFile() : string
    {
        if (is_null($this->_pidFile)) {
            $this->_pidFile = $this->getRuntimeDir() . '/' . $this->getName() . '.pid';
        }
        return $this->_pidFile;
    }

    /**
     * @return int
     */
    public function getPid() : int
    {
        return $this->_pid;
    }

    /**
     * @return int
     */
    public function getDirPermissions() : int
    {
        return $this->_dirPermissions;
    }

    /**
     * @param bool $daemonize
     */
    protected function setDaemonize(bool $daemonize) : void
    {
        $this->_daemonize = $daemonize;
    }

    /**
     * @param string $runtimeDir
     */
    protected function setRuntimeDir(string $runtimeDir) : void
    {
        $this->_runtimeDir = rtrim($runtimeDir, '/');

        if (!file_exists($this->_runtimeDir)) {
            $mask = umask();
            umask(0);
            mkdir($this->_runtimeDir, $this->getDirPermissions(), true);
            umask($mask);
        }
    }

    /**
     * @param string $name
     */
    protected function setName(string $name) : void
    {
        $this->_name = $name;
    }

    /**
     * @param int $pid
     */
    protected function setPid(int $pid) : void
    {
        $this->_pid = $pid;
    }

    /**
     * @param int $dirPermissions
     */
    public function setDirPermissions(int $dirPermissions)
    {
        $this->_dirPermissions = $dirPermissions;
    }

    /**
     * @return string
     */
    public function getErrorLog() : string
    {
        return $this->getRuntimeDir() . '/' . $this->getName() . '-error.log';
    }

    /**
     * @throws DaemonAlreadyRunException
     * @throws FailureGetPidException
     * @throws BaseException
     */
    public function start() : void
    {
        if ($this->isActive()) {
            throw new DaemonAlreadyRunException();
        }
        if (!$this->_daemonize) {
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
        $this->setPid(getmypid());
        if (!$this->getPid()) {
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

        $this->removePidFile();
        $this->end();
    }

    /**
     *
     */
    protected function setErrorLog() : void
    {
        ini_set('error_log', $this->getErrorLog());
    }

    /**
     * @param mixed $message
     */
    protected function putErrorLog($message) : void
    {
        file_put_contents($this->getErrorLog(), (string)$message, FILE_APPEND);
    }

    /**
     * @return bool
     * @throws FailureStopException
     * @throws BaseException
     */
    public function stop() : bool
    {
        if ($this->isActive()) {
            if ($this->stopPid($this->getPid())) {
                return true;
            }
            throw new FailureStopException('pid: ' . $this->getPid());
        } else {
            throw new DaemonNotActiveException('Daemon ' . $this->getName() . ' not active');
        }
    }

    /**
     * @param $pid
     * @param int $attempts
     *
     * @return bool
     */
    public function stopPid($pid, $attempts = 50) : bool
    {
        for ($k = $attempts; $k || !$attempts; $k--) {
            if (!posix_kill($pid, SIGTERM)) {
                return true;
            }
            sleep(1);
        }
        return false;
    }

    public function restart() : void
    {
        $this->stop();
        $this->start();
    }

    /**
     * @return bool
     * @throws FailureGetPidFileException
     * @throws BaseException
     */
    final private function isActive() : bool
    {
        if (!$this->getPid()) {
            if (file_exists($this->getPidFile())) {
                $this->setPidFromPidFile();
                if (!$this->getPid()) {
                    throw new FailureGetPidFileException();
                }
            }
        }
        if ($this->getPid() && posix_kill($this->getPid(), 0)) {
            return true;
        }
        return false;
    }

    /**
     * Действие после остановки
     * Если необнодим - надо переобпределить в потомке
     */
    protected function afterStop() : void
    {
    }

    /**
     * @throws FailureOpenPidFileException
     * @throws FailureWritePidFileException
     * @throws BaseException
     */
    protected function savePid() : void
    {
        if ($handle = fopen($this->getPidFile(), 'w')) {
            if (!fwrite($handle, $this->getPid())) {
                fclose($handle);
                throw new FailureWritePidFileException();
            }
            fclose($handle);
        } else {
            throw new FailureOpenPidFileException();
        }
    }

    /**
     * @return int
     * @throws FailureForkProcessException
     * @throws BaseException
     */
    final protected function fork() : int
    {
        $child_pid = pcntl_fork();
        if (-1 == $child_pid) {
            throw new FailureForkProcessException();
        }
        return $child_pid;
    }

    /**
     *
     */
    protected function signals() : void
    {
        pcntl_signal(SIGTERM, [$this, 'signalHandler']);
    }

    /**
     * @return bool
     */
    public function isStopProcess() : bool
    {
        return $this->_stopProcess;
    }

    /**
     * @param int $signo
     * @param mixed $signinfo
     */
    protected function signalHandler(int $signo, $signinfo) : void
    {
        switch ($signo) {
            case SIGTERM:
                $this->stopProcess();
                break;
        }
    }

    /**
     *
     */
    protected function dispatch() : void
    {
        pcntl_signal_dispatch();
    }

    /**
     *
     */
    protected function stopProcess() : void
    {
        $this->_stopProcess = true;
    }

    /**
     *
     */
    protected function setPidFromPidFile() : void
    {
        $pid = file_get_contents($this->getPidFile());
        $this->setPid($pid);
    }

    /**
     * end of process
     */
    protected function end() : void
    {
        exit();
    }

    /**
     * remove file with pid
     */
    protected function removePidFile() : void
    {
        @unlink($this->getPidFile());
    }

    /**
     * set process as main process
     */
    protected function setMainProcess()
    {
        posix_setsid();
    }

}