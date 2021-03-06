<?php
namespace KwfBundle\HttpFoundation\Session;

use Symfony\Component\HttpFoundation\Session\Storage\PhpBridgeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeSessionHandler;

class KwfSessionStorage extends PhpBridgeSessionStorage
{
    public function __construct($handler = null, MetadataBag $metaBag = null)
    {
        $handler = new NativeSessionHandler();
        parent::__construct($handler, $metaBag);
    }


    public function setSaveHandler($saveHandler = null)
    {
        //parent::setSaveHandler($saveHandler);
        $this->saveHandler = $saveHandler;
    }

    public function start()
    {
        \Kwf_Session::start();
        if ($this->started) {
            return true;
        }

        $this->loadSession();
        //if (!$this->saveHandler->isWrapper() && !$this->saveHandler->isSessionHandlerInterface()) {
        //    // This condition matches only PHP 5.3 + internal save handlers
        //    $this->saveHandler->setActive(true);
        //}

        return true;
    }

    public function getBag($name)
    {
        if (!isset($this->bags[$name])) {
            throw new \InvalidArgumentException(sprintf('The SessionBagInterface %s is not registered.', $name));
        }
        if (\Kwf_Session::isStarted() && !$this->started) {
            $this->loadSession();
        } elseif (!$this->started) {
            $this->start();
        }

        return $this->bags[$name];
    }

    public function save()
    {
        \Kwf_Session::writeClose();

        $this->closed = true;
        $this->started = false;
    }

}
