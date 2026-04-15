<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Thrown by Response methods in test mode instead of calling exit().
 * Tests catch this exception to capture the response without halting execution.
 */
class ResponseSentException extends \RuntimeException {}
