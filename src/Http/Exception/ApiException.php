<?php

declare(strict_types=1);

namespace BibleGet\Api\Http\Exception;

abstract class ApiException extends \RuntimeException
{
    protected int $status;
    protected string $type;
    protected string $title;

    public function __construct(
        string $message = '',
        int $status     = 500,
        string $type    = 'about:blank',
        string $title   = 'Internal Server Error',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $status, $previous);

        $this->status = $status;
        $this->type   = $type;
        $this->title  = $title;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Convert the exception to an array suitable for RFC 9457 problem+json.
     *
     * @param bool $includeDebug Whether to include file, line, and stack trace.
     * @return array<string, mixed>
     */
    public function toArray(bool $includeDebug = false): array
    {
        $data = [
            'type'   => $this->getType(),
            'title'  => $this->getTitle(),
            'status' => $this->getStatus(),
            'detail' => $this->getMessage(),
        ];

        if ($includeDebug) {
            $data['file']  = $this->getFile();
            $data['line']  = $this->getLine();
            $data['trace'] = array_map(static function (array $frame): array {
                unset($frame['args']);
                return $frame;
            }, $this->getTrace());
        }

        return $data;
    }
}
