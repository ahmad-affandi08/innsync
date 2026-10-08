<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Authorization;

use App\Modules\IdentityAccess\Application\Ports\GrantMemory;

/**
 * Remembers who may do what for the length of one web request, so a page that asks the same question twenty times asks the database once. It is deliberately short-lived and cautious:
 *  - it is on only between `start()` and `stop()` (the web middleware), never in a queue worker or a command, and `stop()` throws everything away;
 *  - any statement that writes to the tables the answer is read from empties it at once (`noteStatement`), so an access change made during the request is seen by the next question;
 *  - what it holds is the answer to one exact question (person, permission, scope), nothing more.
 */
final class RequestGrantMemo implements GrantMemory
{
    /** The tables a permission answer is built from. */
    private const TABLES = ['user_role_assignments', 'roles', 'role_permissions', 'permissions'];

    private bool $active = false;

    /** @var array<string, mixed> */
    private array $answers = [];

    public function start(): void
    {
        $this->active = true;
        $this->answers = [];
    }

    public function stop(): void
    {
        $this->active = false;
        $this->answers = [];
    }

    public function flush(): void
    {
        $this->answers = [];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $ask
     * @return T
     */
    public function remember(string $key, callable $ask): mixed
    {
        if (! $this->active) {
            return $ask();
        }

        if (! array_key_exists($key, $this->answers)) {
            $this->answers[$key] = $ask();
        }

        return $this->answers[$key];
    }

    /** Called for every statement the application runs; a write to an access table throws the remembered answers away. */
    public function noteStatement(string $sql): void
    {
        if (! $this->active || $this->answers === []) {
            return;
        }

        if (preg_match('/^\s*(?:insert|update|delete|replace|truncate|alter|drop|create)\b/i', $sql) !== 1) {
            return;
        }

        foreach (self::TABLES as $table) {
            if (str_contains($sql, '`'.$table.'`') || preg_match('/\b'.$table.'\b/', $sql) === 1) {
                $this->answers = [];

                return;
            }
        }
    }
}
