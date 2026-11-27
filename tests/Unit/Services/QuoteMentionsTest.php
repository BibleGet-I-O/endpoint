<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Services;

use BibleGet\Api\Repositories\MentionRepository;
use BibleGet\Api\Services\QuoteMentions;
use PHPUnit\Framework\TestCase;

final class QuoteMentionsTest extends TestCase
{
    public function testRequestedOnlyByOneOrTrue(): void
    {
        self::assertTrue(QuoteMentions::requested(['mentions' => '1']));
        self::assertTrue(QuoteMentions::requested(['mentions' => 'true']));
        self::assertTrue(QuoteMentions::requested(['mentions' => ' TRUE ']));
        self::assertFalse(QuoteMentions::requested([]));
        self::assertFalse(QuoteMentions::requested(['mentions' => '0']));
        self::assertFalse(QuoteMentions::requested(['mentions' => 'yes']));
        self::assertFalse(QuoteMentions::requested(['mentions' => ['1']]));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function row(string $version, int $verse, string $plain, array $extra = []): array
    {
        return $extra + ['version' => $version, 'univbooknum' => 1, 'chapter' => 2, 'verse' => $verse, 'verse_segment' => null, 'text_tradition' => null, 'text_plain' => $plain];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{chapter: int, verse: int, segment: ?string, tradition: ?string, plain_hash: string, start: int, end: int, entry: ?string, candidates: ?list<string>}
     */
    private static function mention(int $verse, string $plain, array $fields): array
    {
        /** @var array{chapter: int, verse: int, segment: ?string, tradition: ?string, plain_hash: string, start: int, end: int, entry: ?string, candidates: ?list<string>} */
        return $fields + ['chapter' => 2, 'verse' => $verse, 'segment' => null, 'tradition' => null, 'plain_hash' => hash('sha256', $plain), 'start' => 0, 'end' => 1, 'entry' => null, 'candidates' => null];
    }

    public function testMatchesRowsByIdentityWithNullsEqualAndAsksOneQueryPerEditionAndBook(): void
    {
        $repo = $this->createMock(MentionRepository::class);
        $repo->expects(self::once())->method('inOneSnapshot')->willReturnCallback(static fn (callable $read): mixed => $read());
        $repo->method('editionsWithMentions')->willReturn(['NVBSE']);
        $repo->expects(self::once())->method('mentions')->with('NVBSE', 1, 2, 2)->willReturn([
            self::mention(1, 'Ecce Petrus', ['start' => 5, 'end' => 11, 'entry' => 'bpers:petrus']),
            self::mention(1, 'Ecce Petrus', ['start' => 0, 'end' => 11, 'candidates' => ['bpers:a', 'bpers:b']]),
            self::mention(1, 'x', ['segment' => 'a', 'entry' => 'bpers:x']),
            self::mention(2, 'other text', ['entry' => 'bpers:stale']),
        ]);
        $repo->expects(self::once())->method('cards')
            ->with('NVBSE', ['bpers:a', 'bpers:b', 'bpers:petrus'])
            ->willReturn(['bpers:petrus' => ['type' => 'person']]);

        [$rows, $entities] = QuoteMentions::attach([
            self::row('NVBSE', 1, 'Ecce Petrus'),
            self::row('NVBSE', 2, 'Et Marcus'),
            self::row('VGCL', 1, 'Ecce Petrus'),
        ], $repo);

        self::assertSame(
            [['start' => 0, 'end' => 11, 'candidates' => ['bpers:a', 'bpers:b']], ['start' => 5, 'end' => 11, 'entry' => 'bpers:petrus']],
            $rows[0]['mentions']
        );
        self::assertSame([], $rows[1]['mentions']);
        self::assertArrayNotHasKey('mentions', $rows[2]);
        self::assertSame(['bpers:petrus' => ['type' => 'person']], $entities);
    }
}
