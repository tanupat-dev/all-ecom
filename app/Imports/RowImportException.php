<?php

namespace App\Imports;

use Exception;

/**
 * Thrown by an Importer for a row it cannot map — the fail-loud signal of
 * ADR 0005. The pipeline records the row + reason in the ImportJob's error
 * report and continues with the remaining rows.
 */
class RowImportException extends Exception {}
