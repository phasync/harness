<?php

declare(strict_types=1);

use Harness\Mcp\StdioConnection;

it('rejects StdioConnection with empty command list', function () {
    expect(fn () => new StdioConnection([]))->toThrow(InvalidArgumentException::class, 'non-empty list');
});
