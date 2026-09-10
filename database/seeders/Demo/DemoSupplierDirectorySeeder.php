<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\DealV2\AgencyServiceType;
use App\Models\User;
use App\Services\DealV2\AgencyServiceProviderService;

/**
 * Seeds a realistic supplier directory for the demo webinar (Johan,
 * 2026-09-03) — Deals → Supplier Directory (/deals-v2/suppliers) currently
 * shows zero rows for agency 1.
 *
 * Categories used are the REAL fixed `specialty` enum
 * (SupplierDirectoryController::SPECIALTIES) — nothing invented. The wider
 * wishlist (pest control, locksmith, photographer, cleaning, gardening,
 * handyman, movers, borehole/pool) doesn't fit that fixed 11-value enum, so
 * those suppliers use specialty='other' and are distinguished via the
 * agency-configurable AgencyServiceType tick-box layer instead (also seeded
 * here, since agency 1 had zero rows there too — the tick list would
 * otherwise be empty for every supplier).
 *
 * ALL FICTIONAL: invented company/contact names, @example.com emails,
 * "000 000 00NN" phone numbers, obviously-synthetic registration numbers.
 * Nothing branded HFC or resembling a real supplier.
 *
 * Driven through the REAL AgencyServiceProviderService::findOrCreate() /
 * ::markPreferred() (agency-scoped, dedup-by-specialty+email/name — already
 * idempotent by design) rather than raw inserts, so this seeder behaves
 * exactly like an agent adding suppliers through the real screen.
 *
 * agency_service_providers has no branch_id column (no BelongsToBranch trait
 * on the model) — the directory is agency-wide, not per-branch. created_by_id
 * is varied across the 3 branches' staff for realism.
 */
final class DemoSupplierDirectorySeeder
{
    private const TAG_NOTE = '[DEMO] Fictional supplier — seeded for demo purposes.';

    /**
     * Each row: [specialty, name, company, contact_person, role, email, phone,
     * registration_number, preferred, extra_type_code, dual_bond_attorney]
     */
    private function plan(): array
    {
        return [
            // ── electrician ──
            ['electrician', 'Coastal Spark Electrical Compliance', 'Coastal Spark (Pty) Ltd', 'Derek Naidoo', 'Owner', 'derek@example.com', '000 000 0011', '2019/000011/07', true, null],
            ['electrician', 'Shelly Beach Electrical Services', 'Shelly Beach Electrical CC', 'Werner Botha', 'Technician', 'werner@example.com', '000 000 0012', '2017/000012/23', false, null],
            ['electrician', 'SafeCurrent COC Specialists', 'SafeCurrent Electrical (Pty) Ltd', 'Given Mthembu', 'Compliance Officer', 'given@example.com', '000 000 0013', '2021/000013/07', false, null],

            // ── entomologist ──
            ['entomologist', 'South Coast Beetle & Timber Inspections', 'South Coast Beetle Inspections CC', 'Trevor Naicker', 'Inspector', 'trevor@example.com', '000 000 0021', '2015/000021/23', true, null],
            ['entomologist', 'KZN Pest & Entomology Reports', 'KZN Entomology Services (Pty) Ltd', 'Adele Pillay', 'Inspector', 'adele@example.com', '000 000 0022', '2020/000022/07', false, null],

            // ── plumber ──
            ['plumber', 'Blue Pipe Plumbing Margate', 'Blue Pipe Plumbing CC', 'Sipho Zulu', 'Owner', 'sipho@example.com', '000 000 0031', '2016/000031/23', true, null],
            ['plumber', 'Coastal Flow Plumbing Services', 'Coastal Flow (Pty) Ltd', 'Riaan Coetzee', 'Plumber', 'riaan@example.com', '000 000 0032', '2018/000032/07', false, null],

            // ── gas ──
            ['gas', 'SafeGas Certification Services', 'SafeGas (Pty) Ltd', 'Bongani Ndlovu', 'Gas Practitioner', 'bongani@example.com', '000 000 0041', '2019/000041/07', true, null],
            ['gas', 'South Coast Gas Compliance', 'South Coast Gas CC', 'Werner Steyn', 'Gas Practitioner', 'wsteyn@example.com', '000 000 0042', '2017/000042/23', false, null],

            // ── electric_fence ──
            ['electric_fence', 'Perimeter Guard Electric Fencing', 'Perimeter Guard (Pty) Ltd', 'Thabo Khumalo', 'Installer', 'thabo@example.com', '000 000 0051', '2020/000051/07', true, null],
            ['electric_fence', 'SecureLine Fence Compliance', 'SecureLine CC', 'Marius Swanepoel', 'Installer', 'marius@example.com', '000 000 0052', '2016/000052/23', false, null],

            // ── transfer_attorney (one also carries bond_attorney capability) ──
            ['transfer_attorney', 'Dlamini & Associates Conveyancing Attorneys', 'Dlamini & Associates Inc', 'Zanele Dlamini', 'Attorney', 'zanele@example.com', '000 000 0061', 'LPC/000061/26', true, 'DUAL_BOND'],
            ['transfer_attorney', 'Coastal Transfer Attorneys Inc', 'Coastal Transfer Attorneys Inc', 'Michael van Rooyen', 'Attorney', 'michael@example.com', '000 000 0062', 'LPC/000062/26', false, null],

            // ── bond_attorney (standalone) ──
            ['bond_attorney', 'Bond Registration Attorneys SA', 'Bond Registration Attorneys Inc', 'Farhana Khan', 'Attorney', 'farhana@example.com', '000 000 0071', 'LPC/000071/26', true, null],

            // ── conveyancer ──
            ['conveyancer', 'Southbroom Conveyancing Services', 'Southbroom Conveyancing CC', 'Patrick Mkhize', 'Conveyancer', 'patrick@example.com', '000 000 0081', 'LPC/000081/26', true, null],

            // ── bond_originator ──
            ['bond_originator', 'HomeLoan Connect Originators', 'HomeLoan Connect (Pty) Ltd', 'Candice Naidoo', 'Bond Originator', 'candice@example.com', '000 000 0091', '2018/000091/07', true, null],
            ['bond_originator', 'BondLink South Coast', 'BondLink (Pty) Ltd', 'Warren Adams', 'Bond Originator', 'warren@example.com', '000 000 0092', '2019/000092/07', false, null],

            // ── other (tagged with a specific agency service type below) ──
            ['other', 'CoastGuard Pest Control', 'CoastGuard Pest Control CC', 'Musa Zungu', 'Technician', 'musa@example.com', '000 000 0101', '2017/000101/23', true, 'pest_control'],
            ['other', 'QuickKey Locksmiths', 'QuickKey Locksmiths (Pty) Ltd', 'Devon Naidoo', 'Locksmith', 'devon@example.com', '000 000 0102', '2020/000102/07', false, 'locksmith'],
            ['other', 'Shoreline Property Photography', 'Shoreline Photography CC', 'Amanda Reddy', 'Photographer', 'amanda@example.com', '000 000 0103', '2021/000103/23', true, 'photography'],
            ['other', 'SparkleClean Property Services', 'SparkleClean (Pty) Ltd', 'Precious Cele', 'Manager', 'precious@example.com', '000 000 0104', '2019/000104/07', false, 'cleaning'],
            ['other', 'GreenScape Gardens & Landscaping', 'GreenScape CC', 'Vusi Ngcobo', 'Owner', 'vusi@example.com', '000 000 0105', '2016/000105/23', false, 'gardening'],
            ['other', 'FixIt Handyman Services', 'FixIt Handyman (Pty) Ltd', 'Craig Botha', 'Handyman', 'craig@example.com', '000 000 0106', '2020/000106/07', false, 'handyman'],
            ['other', 'South Coast Removals & Storage', 'South Coast Removals CC', 'Nathi Mahlangu', 'Coordinator', 'nathi@example.com', '000 000 0107', '2018/000107/23', false, 'removals'],
            ['other', 'AquaFlow Borehole & Pool Services', 'AquaFlow (Pty) Ltd', 'Leon Fourie', 'Technician', 'leon@example.com', '000 000 0108', '2019/000108/07', false, 'borehole_pool'],
        ];
    }

    /** Extra agency-configurable service types beyond AgencyServiceType::DEFAULTS. */
    private function extraServiceTypes(): array
    {
        return [
            ['code' => 'pest_control',  'label' => 'Pest Control'],
            ['code' => 'locksmith',     'label' => 'Locksmith'],
            ['code' => 'photography',   'label' => 'Property Photography'],
            ['code' => 'cleaning',      'label' => 'Cleaning Services'],
            ['code' => 'gardening',     'label' => 'Gardening & Landscaping'],
            ['code' => 'handyman',      'label' => 'Handyman'],
            ['code' => 'removals',      'label' => 'Removals & Storage'],
            ['code' => 'borehole_pool', 'label' => 'Borehole & Pool Services'],
        ];
    }

    public function run(int $agencyId): array
    {
        $notes = [];

        // ── Service types (the tick-box layer) — idempotent statics already. ──
        AgencyServiceType::seedDefaultsFor($agencyId);
        $maxSort = (int) AgencyServiceType::withoutGlobalScopes()->where('agency_id', $agencyId)->max('sort_order');
        foreach ($this->extraServiceTypes() as $i => $t) {
            AgencyServiceType::withoutGlobalScopes()->firstOrCreate(
                ['agency_id' => $agencyId, 'code' => $t['code']],
                ['label' => $t['label'], 'sort_order' => $maxSort + $i + 1, 'is_active' => true],
            );
        }
        $typeByCode = AgencyServiceType::withoutGlobalScopes()->where('agency_id', $agencyId)
            ->pluck('id', 'code');

        $staff = User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('is_active', true)
            ->whereIn('role', ['admin', 'branch_manager', 'agent'])->get()->values();
        if ($staff->isEmpty()) {
            return ['created' => 0, 'skipped' => 0, 'notes' => ['FAILED: no staff users found for agency ' . $agencyId]];
        }

        $service = app(AgencyServiceProviderService::class);

        $created = 0;
        $skipped = 0;
        $i = 0;

        foreach ($this->plan() as $row) {
            [$specialty, $name, $company, $contactPerson, $role, $email, $phone, $regNo, $preferred, $extra] = $row;
            $i++;
            $creator = $staff[$i % $staff->count()];

            $beforeCount = AgencyServiceProvider::withoutGlobalScopes()
                ->where('agency_id', $agencyId)->where('specialty', $specialty)
                ->whereRaw('LOWER(email) = ?', [strtolower($email)])->count();

            $provider = $service->findOrCreate($agencyId, [
                'name' => $name,
                'specialty' => $specialty,
                'company' => $company,
                'registration_number' => $regNo,
                'email' => $email,
                'phone' => $phone,
                'address' => 'KZN South Coast (demo address on file)',
                'notes' => self::TAG_NOTE,
                'is_preferred' => false, // set via markPreferred below, so the per-specialty unset logic runs
            ], $creator->id);

            $wasNew = $beforeCount === 0;
            if ($wasNew) {
                $created++;
            } else {
                $skipped++;
            }

            // Dual attorney capability (transfer + bond on the same firm) — only stamp once.
            if ($extra === 'DUAL_BOND' && ! $provider->is_bond_attorney) {
                $provider->update(['is_bond_attorney' => true]);
            }

            // Preferred — markPreferred() unsets siblings sharing this specialty, so only
            // call it for the one row per specialty we actually want preferred, and only
            // when not already set (idempotent — a re-run must not keep re-triggering it,
            // though it would be harmless either way since it's the same target row).
            if ($preferred && ! $provider->is_preferred) {
                $service->markPreferred($provider->fresh());
            }

            // Tag with the agency service type (skip the DUAL_BOND marker — not a real code).
            if ($extra && $extra !== 'DUAL_BOND' && isset($typeByCode[$extra])) {
                \App\Models\DealV2\AgencyServiceProviderServiceType::withoutGlobalScopes()->firstOrCreate([
                    'agency_id' => $agencyId,
                    'service_provider_id' => $provider->id,
                    'service_type' => $extra,
                ]);
            }
            // Also tag the fixed-specialty ones with the matching AgencyServiceType default
            // code, so their tick-boxes aren't empty either (COC/Beetle/Gas/Electric Fence/Plumbing).
            $defaultCode = match ($specialty) {
                'electrician' => 'COC',
                'entomologist' => 'Beetle',
                'gas' => 'Gas',
                'electric_fence' => 'Electric Fence',
                'plumber' => 'Plumbing',
                default => null,
            };
            if ($defaultCode && isset($typeByCode[$defaultCode])) {
                \App\Models\DealV2\AgencyServiceProviderServiceType::withoutGlobalScopes()->firstOrCreate([
                    'agency_id' => $agencyId,
                    'service_provider_id' => $provider->id,
                    'service_type' => $defaultCode,
                ]);
            }

            // One working contact person per firm (idempotent: only add if none exists yet).
            if (! AgencyServiceProviderContact::withoutGlobalScopes()->where('service_provider_id', $provider->id)->exists()) {
                $isAttorney = in_array($specialty, ['transfer_attorney', 'bond_attorney', 'conveyancer'], true);
                AgencyServiceProviderContact::create([
                    'agency_id' => $agencyId,
                    'service_provider_id' => $provider->id,
                    'attorney_name' => $isAttorney ? $contactPerson : null,
                    'contact_person' => $isAttorney ? null : $contactPerson,
                    'role' => $role,
                    'email' => $email,
                    'phone' => $phone,
                    'is_active' => true,
                    'created_by_id' => $creator->id,
                ]);
            }

            $notes[] = ($wasNew ? 'CREATED' : 'SKIPPED (already seeded)') . ": {$name} ({$specialty})";
        }

        return ['created' => $created, 'skipped' => $skipped, 'notes' => $notes];
    }
}
