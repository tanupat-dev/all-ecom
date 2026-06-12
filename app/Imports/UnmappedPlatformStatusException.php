<?php

namespace App\Imports;

/**
 * "Unsupported status — ระบบไม่รองรับ" (CONTEXT.md: Order Status). Extends
 * RowImportException so the central pipeline holds the row + surfaces it
 * in the error report automatically.
 */
class UnmappedPlatformStatusException extends RowImportException {}
