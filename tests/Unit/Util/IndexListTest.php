<?php

declare(strict_types=1);

namespace BibleGet\Tests\Unit\Util;

use BibleGet\Api\Util\IndexList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class IndexListTest extends TestCase
{
    public function testParsesIntegers(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        self::assertSame([16, 24, 19], IndexList::parse('16,24,19', $logger, 'NABRE_idx book 1'));
    }

    public function testLogsEachBadTokenOncePerContext(): void
    {
        IndexList::resetLogged();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        IndexList::parse('26,12:1', $logger, 'X_idx book 27');
        IndexList::parse('26,12:1', $logger, 'X_idx book 27');
    }

    public function testLogsANonIntegerToken(): void
    {
        IndexList::resetLogged();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::logicalAnd(self::stringContains('12:1'), self::stringContains('NABRE_idx book 27')));

        self::assertSame([26, 12], IndexList::parse('26,12:1', $logger, 'NABRE_idx book 27'));
    }
}
