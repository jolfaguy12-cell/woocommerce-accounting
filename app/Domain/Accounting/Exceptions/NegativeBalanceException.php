<?php

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

/** Posting would drive an account below zero and `ops.negative_balance_mode` is `block`. */
class NegativeBalanceException extends RuntimeException {}
