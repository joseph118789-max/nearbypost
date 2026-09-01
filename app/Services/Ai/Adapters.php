<?php

namespace App\Services\Ai;

/**
 * The models this site knows how to talk to.
 *
 * Registered here rather than discovered, so the panel can list what is
 * available and say plainly which ones have a key configured - the usual reason
 * a swap fails on the first try is that nobody set the key, and that should be
 * visible before a bench run is started rather than after it.
 */
class Adapters
{
    /** @return array<string, callable(): ModelAdapter> */
    private static function registry(): array
    {
        return [
            'deepseek'          => fn () => new DeepSeekAdapter('deepseek-chat'),
            'deepseek-reasoner' => fn () => new DeepSeekAdapter('deepseek-reasoner'),
        ];
    }

    public static function make(?string $key = null): ModelAdapter
    {
        $key = $key ?: (string) config('services.ai.adapter', 'deepseek');
        $registry = self::registry();

        if (!isset($registry[$key])) {
            throw new \InvalidArgumentException(
                "No adapter named '{$key}'. Known: " . implode(', ', array_keys($registry))
            );
        }

        return ($registry[$key])();
    }

    /** @return list<array{key: string, model: string, configured: bool}> */
    public static function all(): array
    {
        $out = [];

        foreach (array_keys(self::registry()) as $key) {
            $adapter = self::make($key);

            $out[] = [
                'key'        => $key,
                'model'      => $adapter->model(),
                'configured' => $adapter->isConfigured(),
            ];
        }

        return $out;
    }
}
