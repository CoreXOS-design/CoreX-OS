<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Company Profile — the ONE record describing RR Technologies / CoreX OS
 * for letterheads, contracts and platform emails. Not an agency: no agency_id, no scope.
 * Spec: .ai/specs/platform-company-profile.md §3.
 *
 * Seeded here (not in a seeder) so every environment — QA1, Staging, live — has the row
 * after `migrate`; seeders never run on a `git pull` deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_company_logos')) {
            Schema::create('platform_company_logos', function (Blueprint $t) {
                $t->id();
                $t->string('path', 255);
                $t->string('original_name', 255)->default('');
                $t->string('mime', 60);
                $t->unsignedInteger('size')->default(0);
                $t->unsignedBigInteger('uploaded_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        if (! Schema::hasTable('platform_company')) {
            Schema::create('platform_company', function (Blueprint $t) {
                $t->id();
                // Exactly one row, enforced by the database: a second insert hits this unique key.
                $t->unsignedTinyInteger('singleton')->default(1)->unique();
                $t->string('legal_name', 255);
                $t->string('trading_name', 255);
                $t->string('registration_number', 100)->nullable();
                $t->boolean('vat_registered')->default(false);
                $t->string('vat_number', 50)->nullable();
                $t->json('directors')->nullable();
                $t->text('physical_address')->nullable();
                $t->text('postal_address')->nullable();
                $t->string('email_general', 255)->nullable();
                $t->string('email_support', 255)->nullable();
                $t->string('email_accounts', 255)->nullable();
                $t->json('phones')->nullable();
                $t->json('websites')->nullable();
                $t->string('strap_line', 255)->nullable();
                $t->text('letterhead_footer')->nullable();
                $t->mediumText('email_signature_html')->nullable();
                $t->text('bank_details')->nullable(); // encrypted json (model cast)
                $t->unsignedBigInteger('logo_id')->nullable(); // NULL = built-in CoreX OS asset
                $t->unsignedInteger('version')->default(1);    // optimistic lock
                $t->timestamps();

                $t->foreign('logo_id')->references('id')->on('platform_company_logos')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('platform_company_audit')) {
            Schema::create('platform_company_audit', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('action', 40);
                $t->string('summary', 255)->default('');
                $t->json('changes')->nullable();
                $t->timestamp('created_at')->useCurrent();

                $t->index(['action', 'created_at']);
                $t->index('created_at');
            });
        }

        if (DB::table('platform_company')->count() === 0) {
            $now = now();
            DB::table('platform_company')->insert([
                'singleton'           => 1,
                'legal_name'          => 'RR Technologies (Pty) Ltd',
                'trading_name'        => 'CoreX OS',
                'registration_number' => '2026 / 444132 / 07',
                'vat_registered'      => false,
                'vat_number'          => null,
                'directors'           => json_encode([
                    ['name' => 'Johan Reichel', 'title' => 'Director'],
                    ['name' => 'Andre Roets', 'title' => 'Director'],
                ]),
                'physical_address'    => "3123 San Lameer\nR61 Lower South Coast Road\nSouthbroom, KZN\n4277",
                'postal_address'      => null,
                'email_general'       => 'admin@corexos.co.za',
                'email_support'       => 'support@corexos.co.za',
                'email_accounts'      => null,
                'phones'              => json_encode([
                    ['label' => 'Telephone', 'number' => '(039) 004 0125'],
                    ['label' => 'Cell', 'number' => '076 618 5578'],
                    ['label' => 'Support line', 'number' => '076 423 2426'],
                ]),
                'websites'            => json_encode(['www.corexweb.co.za', 'www.corexos.co.za']),
                'strap_line'          => null,
                'letterhead_footer'   => null,
                'email_signature_html' => null,
                'bank_details'        => null,
                'logo_id'             => null,
                'version'             => 1,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_company_audit');
        Schema::dropIfExists('platform_company');
        Schema::dropIfExists('platform_company_logos');
    }
};
