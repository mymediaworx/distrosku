<?php

namespace App\Marketing;

/** A sync that cannot succeed by retrying (missing data / switched off): logged as skipped, not retried. */
class SkipException extends \RuntimeException {}
