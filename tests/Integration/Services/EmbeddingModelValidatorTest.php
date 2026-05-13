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
            'INSERT INTO embedding_metadata (version_sigla, column_name, model_name, dimensions) '
            . "VALUES ('TEST1', 'embedding', 'paraphrase-multilingual-MiniLM-L12-v2', 384) "
            . "ON CONFLICT (version_sigla, column_name) DO UPDATE SET model_name = 'paraphrase-multilingual-MiniLM-L12-v2'"
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        EmbeddingModelValidator::validate($pdo, 'TEST1', 'paraphrase-multilingual-MiniLM-L12-v2', $logger);
    }

    public function testMismatchedModelLogsWarning(): void
    {
        $pdo = $this->getConnection();
        $pdo->exec(
            'INSERT INTO embedding_metadata (version_sigla, column_name, model_name, dimensions) '
            . "VALUES ('TEST1', 'embedding', 'old-model-v1', 384) "
            . "ON CONFLICT (version_sigla, column_name) DO UPDATE SET model_name = 'old-model-v1'"
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('model mismatch'));

        EmbeddingModelValidator::validate($pdo, 'TEST1', 'new-model-v2', $logger);
    }


    public function testPerColumnRowsKeyIndependently(): void
    {
        $pdo = $this->getConnection();
        // Same version, two columns, two different model names. The validator
        // must return the row matching the requested column.
        $pdo->exec(
            'INSERT INTO embedding_metadata (version_sigla, column_name, model_name, dimensions) VALUES '
            . "('TEST1', 'embedding', 'paraphrase-multilingual-MiniLM-L12-v2', 384), "
            . "('TEST1', 'embedding_labse', 'sentence-transformers/LaBSE', 768)"
        );

        // Requesting the LaBSE row with the MiniLM service model should flag.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('model mismatch'));

        EmbeddingModelValidator::validate(
            $pdo,
            'TEST1',
            'paraphrase-multilingual-MiniLM-L12-v2',
            $logger,
            'embedding_labse'
        );

        // Requesting the MiniLM row with the matching service model is clean.
        $logger2 = $this->createMock(LoggerInterface::class);
        $logger2->expects(self::never())->method('warning');

        EmbeddingModelValidator::validate(
            $pdo,
            'TEST1',
            'paraphrase-multilingual-MiniLM-L12-v2',
            $logger2,
            'embedding'
        );
    }
}
