<?php

namespace PlaylogiqUtils\Tests\Unit\ServiceConfiguration\Fixtures;

use Illuminate\Contracts\Events\Dispatcher;

/** Just enough of a dispatcher for Eloquent model events; illuminate/events is not a dependency. */
class ModelEventDispatcher implements Dispatcher
{
    /** @var array<string, callable[]> */
    private $listeners = [];

    public function listen($events, $listener = null)
    {
        foreach ((array) $events as $event) {
            $this->listeners[$event][] = $listener;
        }
    }

    public function hasListeners($eventName)
    {
        return !empty($this->listeners[$eventName]);
    }

    public function subscribe($subscriber)
    {
    }

    public function until($event, $payload = [])
    {
        return $this->dispatch($event, $payload, true);
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        $responses = [];
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $response = $listener(...(is_array($payload) ? $payload : [$payload]));
            if ($halt && $response !== null) {
                return $response;
            }
            if ($response === false) {
                break;
            }
            $responses[] = $response;
        }

        return $halt ? null : $responses;
    }

    public function push($event, $payload = [])
    {
    }

    public function flush($event)
    {
    }

    public function forget($event)
    {
        unset($this->listeners[$event]);
    }

    public function forgetPushed()
    {
    }
}
