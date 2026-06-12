<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__.'/Helpers/TenantIsolation.php';

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
