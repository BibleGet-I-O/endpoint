<?php

declare(strict_types=1);

namespace BibleGet\Tests\Integration\Services;

use BibleGet\Api\Services\EmbeddingModelValidator;
use BibleGet\Tests\Integration\DatabaseTestCase;
use Psr\Log\LoggerInterface;

class EmbeddingModelValidatorTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->getConnection()->exec('DELETE FROM embedding_metadata');
    }

    public function testNoMetadataLogsWarning(): void
    {
        $pdo    = $this->getConnection();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('No embedding metadata'));

        EmbeddingModelValidator::validate($pdo, 'TEST1', 'some-model', $logger);
    }

    public function testMatchingModelLogsNothing(): void
    {
        $pdo = $this->getConnection();
        $pdo->exec(
            'INSERT INTO embedding_metadata (version_sigla, model_name, dimensions) '
            . "VALUES ('TEST1', 'paraphrase-multilingual-MiniLM-L12-v2', 384) "
            . "ON CONFLICT (version_sigla) DO UPDATE SET model_name = 'paraphrase-multilingual-MiniLM-L12-v2'"
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        EmbeddingModelValidator::validate($pdo, 'TEST1', 'paraphrase-multilingual-MiniLM-L12-v2', $logger);
    }

    public function testMismatchedModelLogsWarning(): void
    {
        $pdo = $this->getConnection();
        $pdo->exec(
            'INSERT INTO embedding_metadata (version_sigla, model_name, dimensions) '
            . "VALUES ('TEST1', 'old-model-v1', 384) "
            . "ON CONFLICT (version_sigla) DO UPDATE SET model_name = 'old-model-v1'"
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('model mismatch'));

        EmbeddingModelValidator::validate($pdo, 'TEST1', 'new-model-v2', $logger);
    }
}
