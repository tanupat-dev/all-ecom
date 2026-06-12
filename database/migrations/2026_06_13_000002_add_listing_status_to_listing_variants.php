<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_variants', function (Blueprint $table) {
            // `listed` is the correct DB default: every current writer (order/
            // return importer, manual platform-SKU mapping) creates a
            // ListingVariant from Platform reality = ground truth ⇒ `listed`.
            // The DB default also backfills all pre-existing rows with no full-
            // table UPDATE. Only the future Channel-Upload-Template fill engine
            // (#57–#59) will explicitly write `draft`. (CONTEXT.md: Listing
            // Status; ADR 0019.)
            $table->string('listing_status')->default('listed')->after('deal_price');
        });
    }

    public function down(): void
    {
        Schema::table('listing_variants', function (Blueprint $table) {
            $table->dropColumn('listing_status');
        });
    }
};
