<?php

namespace App\Jobs;

use App\Services\ImpressionValidationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ValidateRawImpressionEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $rawImpressionEventId
    ) {}

    public function handle(ImpressionValidationService $validator): void
    {
        $validator->validate($this->rawImpressionEventId);
    }
}
