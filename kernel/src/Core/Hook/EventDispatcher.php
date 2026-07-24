<?php
/**
 * EventDispatcher — 事件分发器
 *
 * implements EventDispatcherInterface, 基于 Hooks 引擎。
 * dispatch() → Hooks::doAction(事件类名, 事件对象)
 */
declare(strict_types=1);

namespace Converge\Core\Hook;

use Converge\Contracts\EventDispatcherInterface;

class EventDispatcher implements EventDispatcherInterface
{
    public function dispatch(object $event): void
    {
        Hooks::doAction(get_class($event), $event);
    }

    public function listen(string $eventClass, callable $listener): void
    {
        Hooks::addAction($eventClass, $listener);
    }
}
