<?php

declare(strict_types=1);

namespace App\Shared\Application\Import;

use RuntimeException;

/** Thrown inside the import's transaction to undo every row, after a check or when a row was refused. */
final class ImportUndone extends RuntimeException {}
