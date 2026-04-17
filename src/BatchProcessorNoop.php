<?php

declare(strict_types=1);

namespace Enthusiast\WorkerTemplate;

final class BatchProcessorNoop implements BatchProcessorInterface
{
   use BatchProcessorLifecycleDefaults;
}
