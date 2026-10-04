<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * A withdrawal request that could not take its hold.
 *
 * Exists to unwind one specific case: the debit is refused for an ordinary
 * reason (not enough balance, inactive account) and the request row must not be
 * created. Throwing is how the message gets back out of the
 * `LedgerService::debitAdmin()` call to the caller, which is sitting inside a
 * database transaction that has to be rolled back rather than committed with
 * half a withdrawal on it.
 *
 * Carries an already-user-facing Bengali message, so it is caught at the one
 * place that knows how to answer the person who pressed the button.
 */
final class WithdrawRefused extends RuntimeException {}
