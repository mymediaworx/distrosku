<?php

namespace App\Exceptions;

/** Posting into a closed fiscal period. */
class PeriodClosedException extends UnbalancedJournalException {}
