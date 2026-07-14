<?php

namespace App\Domain\Accounting\Exceptions;

use InvalidArgumentException;

/**
 * Something tried to post new money against a party that has been merged away.
 *
 * An absorbed party keeps its id and every journal line it was ever posted against
 * — that is the whole point of the alias design, and it is why its history can
 * still be read. What it must never do is receive a NEW line: the identity is no
 * longer live, no screen lists it, and a balance accumulating there would be a
 * balance nobody would ever see. The duplicate would come back to life one
 * transaction at a time.
 *
 * The fix is never to un-merge. It is to post against the survivor, which every
 * service now resolves to before it validates anything (Party::canonical()).
 */
class MergedPartyException extends InvalidArgumentException {}
