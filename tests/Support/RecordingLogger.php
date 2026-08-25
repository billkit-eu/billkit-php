<?php

declare(strict_types=1);

namespace BillKit\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 logger that keeps every record in memory so tests can assert on
 * what the SDK said and, more importantly, on what it did *not* say.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param mixed             $level
     * @param string|\Stringable $message
     * @param array<mixed>      $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /** @return list<array{level: string, message: string, context: array<mixed>}> */
    public function withLevel(string $level): array
    {
        return array_values(array_filter($this->records, static fn (array $r): bool => $r['level'] === $level));
    }

    /** Everything handed to the logger, flattened for leak scanning. */
    public function blob(): string
    {
        $parts = [];
        foreach ($this->records as $record) {
            $parts[] = $record['message'] . ' ' . json_encode($record['context']);
        }

        return implode("\n", $parts);
    }
}
