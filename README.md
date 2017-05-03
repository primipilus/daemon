Daemon
--------

Composer install
----------------

```bash
composer require "primipilus/daemon:~1.0"
```

Usage
-----

```php
class TestDaemon extends \primipilus\daemon\BaseDaemon
{

    protected function process() : void
    {
       // ...
    }
}

$daemon = new TestDaemon(['daemonize' => true, 'runtimeDir' => __DIR__]);

$daemon->start();
// or
$daemon->stop();
```