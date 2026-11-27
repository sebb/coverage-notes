<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Git;

use RuntimeException;
use Sebb\CoverageNotes\Coverage\CoverageSummary;
use Sebb\CoverageNotes\Serializer\CoverageSerializer;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class GitNotesStorage
{
    public const string DEFAULT_NOTES_REF = 'coverage';
    public const int DEFAULT_HISTORY_LIMIT = 10;

    public static function isCommitRange(string $revision): bool
    {
        return str_contains($revision, '..');
    }

    public function __construct(
        private ?string $workingDirectory = null,
        private CoverageSerializer $serializer = new CoverageSerializer(),
    ) {
    }

    public function fetchNotes(string $notesRef = self::DEFAULT_NOTES_REF, string $remote = 'origin'): bool
    {
        $normalizedRef = $this->normalizeNotesRef($notesRef);
        $remoteTrackingRef = sprintf('refs/notes/origin-%s', ltrim($notesRef, '/'));

        $fetchProcess = $this->createProcess([
            'git',
            'fetch',
            $remote,
            sprintf('+%s:%s', $normalizedRef, $remoteTrackingRef),
        ]);
        $fetchProcess->run();

        if (!$fetchProcess->isSuccessful()) {
            return str_contains($fetchProcess->getErrorOutput(), "couldn't find remote ref");
        }

        $mergeProcess = $this->createProcess([
            'git',
            'notes',
            sprintf('--ref=%s', $notesRef),
            'merge',
            '-s',
            'ours',
            $remoteTrackingRef,
        ]);
        $mergeProcess->run();

        return $mergeProcess->isSuccessful();
    }

    public function recordNote(
        CoverageSummary $summary,
        string $targetRef = 'HEAD',
        string $notesRef = self::DEFAULT_NOTES_REF,
    ): string {
        $commitSha = $this->resolveCommitSha($targetRef);

        $process = $this->createProcess([
            'git',
            'notes',
            sprintf('--ref=%s', $notesRef),
            'add',
            '-f',
            '-F',
            '-',
            $commitSha,
        ]);
        $process->setInput($this->serializer->serializeSummary($summary));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf('Failed to write git note on "%s": %s', $commitSha, trim($process->getErrorOutput() ?: $process->getOutput())));
        }

        return $commitSha;
    }

    public function pushNotes(string $notesRef = self::DEFAULT_NOTES_REF, string $remote = 'origin'): void
    {
        $normalizedRef = $this->normalizeNotesRef($notesRef);
        $this->fetchNotes($notesRef, $remote);

        $pushProcess = $this->createProcess([
            'git',
            'push',
            $remote,
            $normalizedRef,
        ]);
        $pushProcess->run();

        if (!$pushProcess->isSuccessful()) {
            throw new RuntimeException(sprintf('Failed to push git notes ref "%s" to "%s": %s', $normalizedRef, $remote, trim($pushProcess->getErrorOutput() ?: $pushProcess->getOutput())));
        }
    }

    public function readNote(string $commitRef, string $notesRef = self::DEFAULT_NOTES_REF): ?CoverageSummary
    {
        $process = $this->createProcess([
            'git',
            'notes',
            sprintf('--ref=%s', $notesRef),
            'show',
            $commitRef,
        ]);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());
        if ($output === '') {
            return null;
        }

        try {
            return $this->serializer->deserializeSummary($output);
        } catch (Throwable) {
            return null;
        }
    }

    public function resolveBaseline(
        string $baseRef = 'origin/main',
        BaselineResolutionStrategy $strategy = BaselineResolutionStrategy::MergeBase,
        string $notesRef = self::DEFAULT_NOTES_REF,
        int $maxWalkBack = 50,
    ): ?ResolvedBaseline {
        $targetCommit = match ($strategy) {
            BaselineResolutionStrategy::MergeBase => $this->resolveMergeBase($baseRef, 'HEAD'),
            BaselineResolutionStrategy::Direct => $this->tryResolveCommitSha($baseRef),
        };

        if ($targetCommit === null) {
            return null;
        }

        $directNote = $this->readNote($targetCommit, $notesRef);
        if ($directNote !== null) {
            return new ResolvedBaseline(
                commitSha: $targetCommit,
                summary: $directNote,
                commitsBehindTarget: 0,
            );
        }

        if ($maxWalkBack <= 0) {
            return null;
        }

        $revListProcess = $this->createProcess([
            'git',
            'rev-list',
            $targetCommit,
            '-n',
            (string) ($maxWalkBack + 1),
        ]);
        $revListProcess->run();

        if (!$revListProcess->isSuccessful()) {
            return null;
        }

        $commits = array_values(array_filter(
            array_map('trim', explode("\n", $revListProcess->getOutput())),
            static fn (string $line): bool => $line !== '',
        ));

        foreach ($commits as $index => $sha) {
            if ($index === 0) {
                continue;
            }

            $note = $this->readNote($sha, $notesRef);
            if ($note !== null) {
                return new ResolvedBaseline(
                    commitSha: $sha,
                    summary: $note,
                    commitsBehindTarget: $index,
                );
            }
        }

        return null;
    }

    /**
     * @return list<HistoryEntry>
     */
    public function getHistory(
        ?int $limit = self::DEFAULT_HISTORY_LIMIT,
        string $revisionRange = 'HEAD',
        string $notesRef = self::DEFAULT_NOTES_REF,
        bool $includeNotes = true,
    ): array {
        $command = [
            'git',
            'log',
            '--format=%H%x09%h%x09%cs%x09%s',
        ];

        if ($limit !== null) {
            $command[] = '-n';
            $command[] = (string) max(1, $limit);
        }

        $command[] = '--end-of-options';
        $command[] = $revisionRange;
        $command[] = '--';

        $process = $this->createProcess($command);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf('Failed to read git log for "%s": %s', $revisionRange, trim($process->getErrorOutput() ?: $process->getOutput())));
        }

        $lines = array_filter(
            explode("\n", trim($process->getOutput())),
            static fn (string $line): bool => $line !== '',
        );

        $entries = [];
        foreach ($lines as $line) {
            $parts = explode("\t", $line, 4);
            if (count($parts) < 4) {
                continue;
            }

            [$fullSha, $shortSha, $date, $subject] = $parts;
            $entries[] = new HistoryEntry(
                commitSha: $fullSha,
                shortSha: $shortSha,
                subject: $subject,
                committedAt: $date,
                summary: $includeNotes ? $this->readNote($fullSha, $notesRef) : null,
            );
        }

        return $entries;
    }

    public function getRepositoryRoot(): string
    {
        $process = $this->createProcess(['git', 'rev-parse', '--show-toplevel']);
        $process->run();

        if ($process->isSuccessful()) {
            return trim($process->getOutput());
        }

        return $this->workingDirectory ?? (getcwd() ?: '.');
    }

    public function addWorktree(string $worktreePath, string $commitRef): void
    {
        $process = $this->createProcess([
            'git',
            'worktree',
            'add',
            '--detach',
            $worktreePath,
            $commitRef,
        ]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf('Failed to create worktree for "%s": %s', $commitRef, trim($process->getErrorOutput() ?: $process->getOutput())));
        }
    }

    public function removeWorktree(string $worktreePath): void
    {
        $process = $this->createProcess([
            'git',
            'worktree',
            'remove',
            '--force',
            $worktreePath,
        ]);
        $process->run();

        if (is_dir($worktreePath)) {
            (new Process(['rm', '-rf', $worktreePath]))->run();
            $this->createProcess(['git', 'worktree', 'prune'])->run();
        }
    }

    public function resolveCommitSha(string $ref): string
    {
        $sha = $this->tryResolveCommitSha($ref);
        if ($sha === null) {
            throw new RuntimeException(sprintf('Could not resolve Git reference "%s".', $ref));
        }

        return $sha;
    }

    private function tryResolveCommitSha(string $ref): ?string
    {
        $process = $this->createProcess([
            'git',
            'rev-parse',
            '--verify',
            sprintf('%s^{commit}', $ref),
        ]);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput());
    }

    private function resolveMergeBase(string $baseRef, string $headRef): ?string
    {
        $process = $this->createProcess([
            'git',
            'merge-base',
            $baseRef,
            $headRef,
        ]);
        $process->run();

        if (!$process->isSuccessful()) {
            return $this->tryResolveCommitSha($baseRef);
        }

        return trim($process->getOutput());
    }

    private function normalizeNotesRef(string $notesRef): string
    {
        if (str_starts_with($notesRef, 'refs/')) {
            return $notesRef;
        }

        return sprintf('refs/notes/%s', ltrim($notesRef, '/'));
    }

    /**
     * @param list<string> $command
     */
    private function createProcess(array $command): Process
    {
        return new Process($command, $this->workingDirectory);
    }
}
