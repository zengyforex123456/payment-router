<?php
declare(strict_types=1);

namespace Converge\Core\Event;

/**
 * EventBus — 强类型领域事件总线 (P3)
 *
 * 替代 Hooks::doAction('string-tag', $payload) 的弱契约模式。
 * 发布/订阅都通过类名关联，IDE 可追踪、可重构。
 *
 * 与 Hooks 共存:
 *   EventBus 处理领域事件 (conversion.tracked, click.recorded)
 *   Hooks 处理 UI/初始化 (ui.dock.panels, init)
 *
 * Usage:
 *   EventBus::publish(new ConversionTracked(...));
 *   EventBus::subscribe(ConversionTracked::class, fn($e) => ...);
 */
class EventBus
{
    /** @var array<class-string, callable[]> */
    private static array $subscribers = [];

    /** @var array<class-string, bool> */
    private static array $bridgedToHooks = [];

    /**
     * 发布领域事件。任一订阅者抛异常不影响其他订阅者。
     */
    public static function publish(object $event): void
    {
        $class = get_class($event);

        // 1. 直接订阅者
        foreach (self::$subscribers[$class] ?? [] as $listener) {
            try {
                $listener($event);
            } catch (\Throwable $e) {
                error_log("EventBus: subscriber failed for {$class} — {$e->getMessage()}");
            }
        }

        // 2. 桥接到 Hooks (向后兼容: EventBus 发布时自动转发给 Hooks 消费者)
        if (!isset(self::$bridgedToHooks[$class])) {
            self::$bridgedToHooks[$class] = true;
            $hookTag = self::classToHookTag($class);
            if ($hookTag) {
                // Hooks::doAction 在无订阅者时静默通过，安全
                \Converge\Core\Hook\Hooks::doAction($hookTag, self::eventToArray($event));
            }
        }
    }

    /**
     * 订阅领域事件。
     */
    public static function subscribe(string $eventClass, callable $listener): void
    {
        self::$subscribers[$eventClass][] = $listener;
    }

    /**
     * 从 Hook 桥接: 将 Hooks::doAction 调用升级为 EventBus。
     * 用于渐进迁移 — 旧 Hooks 消费者仍能收到事件。
     */
    public static function bridgeFromHook(string $hookTag, string $eventClass, callable $factory): void
    {
        \Converge\Core\Hook\Hooks::addAction($hookTag, function (...$args) use ($eventClass, $factory) {
            $event = $factory(...$args);
            self::publish($event);
        });
    }

    /** 清空所有订阅 (测试用) */
    public static function reset(): void
    {
        self::$subscribers = [];
        self::$bridgedToHooks = [];
    }

    // ═══ Private ═══

    /** ConversionTracked → 'conversion.tracked' */
    private static function classToHookTag(string $class): ?string
    {
        $short = substr($class, strrpos($class, '\\') + 1);
        // PascalCase → dot.case: ConversionTracked → conversion.tracked
        $tag = strtolower((string)preg_replace('/([a-z])([A-Z])/', '$1.$2', $short));
        return $tag !== $short ? $tag : null;
    }

    /** 将事件对象转为数组 (给 Hooks 消费者) */
    private static function eventToArray(object $event): array
    {
        if (method_exists($event, 'toArray')) {
            return $event->toArray();
        }
        return json_decode(json_encode($event), true) ?: [];
    }
}
