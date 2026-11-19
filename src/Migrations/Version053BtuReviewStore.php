<?php

declare(strict_types=1);

namespace BibleGet\Api\Migrations;

use BibleGet\Api\Database\SqlFileMigration;

/**
 * The review store for CBTUDR unit links: btu_unit, btu_stretch and btu_link.
 */
final class Version053BtuReviewStore extends SqlFileMigration
{
    public function getDescription(): string
    {
        return 'Review store for CBTUDR unit links (btu_unit, btu_stretch, btu_link)';
    }

    protected function file(): string
    {
        return '053-btu-review-store.sql';
    }
}
