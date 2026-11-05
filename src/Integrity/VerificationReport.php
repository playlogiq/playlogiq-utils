<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity;

/**
 * The result of one verification run, on one host, at one moment.
 *
 * Every check records itself separately — manifest, signature, scope, files, extras,
 * settings — rather than collapsing into a single boolean. A failing host
 * is normally failing one specific way, and the responses are not the same: "signature
 * valid, two files modified" is a tampered or half-finished deploy on this instance,
 * while "signature invalid, every hash mismatched" is a substituted manifest. One
 * pass/fail flag would make the log say the same thing for both, which is the failure
 * mode this class exists to avoid.
 *
 * Items are the offending paths, grouped by kind ('modified', 'missing', 'extra', ...).
 * They are kept in full here and truncated only where they are rendered, so a caller
 * that wants the complete list can have it while a log line stays a log line.
 *
 * A skipped check is not a passing check. It counts as neither, and callers that must
 * not tolerate a skip — the fleet job, which always requires a signature — simply never
 * skip one. `skipped()` is exposed so the command can say so out loud.
 */
class VerificationReport
{
    const RESULT_PASSED  = 'passed';
    const RESULT_FAILED  = 'failed';
    const RESULT_SKIPPED = 'skipped';

    /** @var array<string, array{result: string, detail: string, items: array<string, string[]>}> */
    private $checks = [];

    /** @var array<string, mixed> */
    private $metadata = [];

    public function pass(string $check, string $detail = ''): void
    {
        $this->record($check, self::RESULT_PASSED, $detail, []);
    }

    /**
     * @param array<string, string[]> $items kind => offending relative paths
     */
    public function fail(string $check, string $detail, array $items = []): void
    {
        $this->record($check, self::RESULT_FAILED, $detail, $items);
    }

    public function skip(string $check, string $reason): void
    {
        $this->record($check, self::RESULT_SKIPPED, $reason, []);
    }

    /**
     * Facts about the run worth carrying into a log line: which manifest, its digest,
     * the versions it claims, how many files were covered.
     *
     * @param mixed $value
     */
    public function meta(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }

    /**
     * True only when nothing failed.
     *
     * Deliberately not "no failures among the checks that ran": a skipped check makes
     * this return true, and the caller that allowed the skip is the one responsible for
     * saying what the result does and does not prove.
     */
    public function passed(): bool
    {
        return $this->failures() === [];
    }

    /**
     * @return array<string, array{result: string, detail: string, items: array<string, string[]>}>
     */
    public function failures(): array
    {
        return array_filter($this->checks, function (array $check) {
            return $check['result'] === self::RESULT_FAILED;
        });
    }

    /**
     * @return string[]
     */
    public function skipped(): array
    {
        return array_keys(array_filter($this->checks, function (array $check) {
            return $check['result'] === self::RESULT_SKIPPED;
        }));
    }

    public function result(string $check): ?string
    {
        return isset($this->checks[$check]) ? $this->checks[$check]['result'] : null;
    }

    public function detail(string $check): string
    {
        return isset($this->checks[$check]) ? $this->checks[$check]['detail'] : '';
    }

    /**
     * @return array<string, string[]>
     */
    public function items(string $check): array
    {
        return isset($this->checks[$check]) ? $this->checks[$check]['items'] : [];
    }

    /**
     * @return array<string, array{result: string, detail: string, items: array<string, string[]>}>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * One line naming every check and its result, for the head of a log entry.
     *
     * e.g. "signature=passed scope=passed files=failed extras=passed"
     */
    public function summary(): string
    {
        $parts = [];

        foreach ($this->checks as $check => $state) {
            $parts[] = $check . '=' . $state['result'];
        }

        return implode(' ', $parts);
    }

    /**
     * The failures, rendered with a bounded number of paths each.
     *
     * Bounded because the interesting case is also the largest one: a substituted
     * release mismatches every covered file at once, and a log line carrying all of
     * them is a log line nobody reads. The count is always stated in full even when the
     * paths are cut, so the number is never the thing that got truncated.
     */
    public function failureDetail(int $maxItemsPerKind = 10): string
    {
        $lines = [];

        foreach ($this->failures() as $check => $state) {
            $line = $check . ': ' . $state['detail'];

            foreach ($state['items'] as $kind => $paths) {
                if ($paths === []) {
                    continue;
                }

                $shown = array_slice($paths, 0, $maxItemsPerKind);
                $more  = count($paths) - count($shown);

                $line .= sprintf(
                    ' [%s (%d): %s%s]',
                    $kind,
                    count($paths),
                    implode(', ', $shown),
                    $more > 0 ? sprintf(' and %d more', $more) : ''
                );
            }

            $lines[] = $line;
        }

        return implode('; ', $lines);
    }

    /**
     * The failures, as the record kept on the instance row.
     *
     * The same bound as failureDetail() and for the same reason, only looser: this is
     * read by a person going through the files, not scanned in a log. Every kind keeps
     * its full count next to the paths, so a truncated list never understates how many
     * files were affected.
     *
     * @return array{summary: string, checks: array<string, array{detail: string, items: array<string, array{count: int, paths: string[]}>}>}
     */
    public function failureRecord(int $maxItemsPerKind = 200): array
    {
        $checks = [];

        foreach ($this->failures() as $check => $state) {
            $items = [];

            foreach ($state['items'] as $kind => $paths) {
                if ($paths === []) {
                    continue;
                }

                $items[$kind] = [
                    'count' => count($paths),
                    'paths' => array_values(array_slice($paths, 0, $maxItemsPerKind)),
                ];
            }

            $checks[$check] = [
                'detail' => $state['detail'],
                'items' => $items,
            ];
        }

        return [
            'summary' => $this->summary(),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{passed: bool, metadata: array, checks: array}
     */
    public function toArray(): array
    {
        return [
            'passed'   => $this->passed(),
            'metadata' => $this->metadata,
            'checks'   => $this->checks,
        ];
    }

    /**
     * @param array<string, string[]> $items
     */
    private function record(string $check, string $result, string $detail, array $items): void
    {
        $this->checks[$check] = [
            'result' => $result,
            'detail' => $detail,
            'items'  => $items,
        ];
    }
}
