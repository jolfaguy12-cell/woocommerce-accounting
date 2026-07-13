<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity fields shared by every role (spec: "Identity and duplicate controls").
 * None of these are unique: a national ID may legitimately be missing or, on
 * legacy rows, wrong — uniqueness here would block imports. They exist to make
 * duplicate *detection* possible; merging stays a manual, audited decision.
 *
 * normalized_phone is the PhoneNormalizer form of `phone`, stored so duplicate
 * scans can index it instead of normalizing 1000+ rows in PHP on every run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->string('party_kind', 16)->default('person')->after('type'); // person | company
            $table->string('national_id', 20)->nullable()->after('party_kind');
            $table->string('company_national_id', 20)->nullable()->after('national_id');
            $table->string('registration_id', 40)->nullable()->after('company_national_id');
            $table->string('tax_id', 40)->nullable()->after('registration_id');
            $table->string('normalized_phone', 32)->nullable()->after('phone');

            $table->index('national_id');
            $table->index('company_national_id');
            $table->index('tax_id');
            $table->index('normalized_phone');
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropIndex(['national_id']);
            $table->dropIndex(['company_national_id']);
            $table->dropIndex(['tax_id']);
            $table->dropIndex(['normalized_phone']);
            $table->dropColumn([
                'party_kind', 'national_id', 'company_national_id',
                'registration_id', 'tax_id', 'normalized_phone',
            ]);
        });
    }
};
