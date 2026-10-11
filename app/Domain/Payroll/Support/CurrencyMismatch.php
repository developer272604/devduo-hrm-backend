<?php

namespace App\Domain\Payroll\Support;

use RuntimeException;

/** Thrown whenever two different currencies would be combined without an explicit, recorded conversion. */
final class CurrencyMismatch extends RuntimeException {}
