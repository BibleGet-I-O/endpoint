<?php

declare(strict_types=1);

namespace BibleGet\Api\Auth;

final readonly class Credential
{
    public function __construct(
        public CredentialType $type,
        public string $id,
        public string $scope,
        public ?int $ratePerHour,
        public ?string $applicationId,
        public ?string $installationId,
        public ?string $plugin,
        public bool $isSystem
    ) {
    }
}
