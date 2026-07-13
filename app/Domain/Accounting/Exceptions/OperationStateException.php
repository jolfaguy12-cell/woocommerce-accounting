<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

/** An operation was asked to do something its current status forbids (approve a draft, reverse a cancelled op, edit a posted one). */
class OperationStateException extends RuntimeException {}
