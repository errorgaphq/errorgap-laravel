<?php

declare(strict_types=1);

namespace Errorgap\Laravel;

final class QuerySpanCollector
{
    /** @var list<array<string, mixed>>|null */
    private ?array $spans = null;

    public function __construct(private readonly string $rootDirectory)
    {
    }

    public function start(): void
    {
        $this->spans = [];
    }

    public function record(string $sql, float $durationMs): void
    {
        if ($this->spans === null) {
            return;
        }

        [$file, $line, $function] = $this->applicationCallSite();
        $this->spans[] = array_filter([
            'kind' => 'db',
            'sql' => self::normalizeSql($sql),
            'file' => $file,
            'line' => $line,
            'fn_name' => $function,
            'duration_ms' => round($durationMs, 3),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return list<array<string, mixed>> */
    public function flush(): array
    {
        $spans = $this->spans ?? [];
        $this->spans = null;
        return $spans;
    }

    public static function normalizeSql(string $sql): string
    {
        $normalized = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $sql) ?? $sql;
        $normalized = preg_replace('/\\b\\d+(?:\\.\\d+)?\\b/', '?', $normalized) ?? $normalized;
        return trim(preg_replace('/\\s+/', ' ', $normalized) ?? $normalized);
    }

    /** @return array{?string, ?int, ?string} */
    private function applicationCallSite(): array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $frame) {
            $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : null;
            if ($file === null || !str_starts_with($file, $this->rootDirectory)) {
                continue;
            }
            if (str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (str_starts_with($file, __DIR__)) {
                continue;
            }

            $relative = ltrim(substr($file, strlen($this->rootDirectory)), DIRECTORY_SEPARATOR);
            $line = isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : null;
            $function = isset($frame['function']) && is_string($frame['function'])
                ? $frame['function']
                : null;
            if (isset($frame['class']) && is_string($frame['class']) && $function !== null) {
                $function = $frame['class'] . '::' . $function;
            }

            return [$relative, $line, $function];
        }

        return [null, null, null];
    }
}
