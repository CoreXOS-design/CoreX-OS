<?php

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RentalCatalogueItem;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\Rentals\RentalJobCardService;

$agency = Agency::create(['name' => 'AT442 Tinker Verify', 'slug' => 'at442-tinker-' . uniqid()]);
$branch = Branch::forceCreate(['name' => 'Verify Branch', 'agency_id' => $agency->id]);
$user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
$property = Property::forceCreate([
    'agency_id' => $agency->id, 'agent_id' => $user->id, 'branch_id' => $branch->id,
    'title' => '42 Tinker Street', 'status' => 'active', 'listing_type' => 'rental',
]);
RentalWorkOrderSetting::create(['agency_id' => $agency->id, 'no_approval_spend_threshold' => 200, 'capture_prices_on_job_cards' => true, 'completion_requires_photo' => false]);

RentalCatalogueItemType::seedDefaultsFor($agency->id);
RentalCatalogueUnit::seedDefaultsFor($agency->id);
$item = RentalCatalogueItem::create([
    'agency_id' => $agency->id,
    'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $agency->id)->where('kind', 'part')->firstOrFail()->id,
    'name' => 'Geyser element',
    'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $agency->id)->where('name', 'Each')->firstOrFail()->id,
    'default_price' => 450, 'sort_order' => 1,
    'created_by_user_id' => $user->id,
]);

$service = app(RentalJobCardService::class);
$jobCard = $service->createForProperty($property, [
    'title' => 'Tinker verify — geyser replacement',
    'description' => 'Geyser burst, needs replacing.',
], $user);

echo "Work order created: #{$jobCard->workOrder->id}, assignment_type={$jobCard->workOrder->assignment_type}\n";
echo "Job card created: #{$jobCard->id}, status={$jobCard->status}\n";

$service->addLine($jobCard, ['rental_catalogue_item_id' => $item->id, 'quantity' => 1], $user);
$service->addLine($jobCard, ['description' => 'Call-out labour', 'type' => 'labour', 'quantity' => 1, 'unit_price' => 300], $user);
$jobCard->refresh();
echo "Total after 2 lines: R{$jobCard->total_amount} (expect 750.00, over the 200 threshold)\n";

$pdfService = app(App\Services\Rentals\RentalDocumentPdfService::class);
$quote = $service->sendToOwnerAsQuote($jobCard, $user, $pdfService);
$jobCard->refresh();
echo "After send-quote: job card status={$jobCard->status}, work order owner_approval_status={$jobCard->workOrder->owner_approval_status}\n";
echo "Quote: amount=R{$quote->amount}, agency_service_provider_id=" . ($quote->agency_service_provider_id ?? 'NULL') . ", document exists=" . (\Illuminate\Support\Facades\Storage::disk('local')->exists($quote->document_storage_path) ? 'yes' : 'no') . "\n";

$jobCard->workOrder->recordApproval($user, ['decision' => 'approved', 'evidence_type' => 'whatsapp', 'evidence_text' => 'Go ahead — approved by owner.']);
$jobCard->syncStatusFromWorkOrder();
echo "After owner approval: job card status={$jobCard->fresh()->status}\n";

$jobCard->workerSignOff($user);
$jobCard->agentSignOff($user);
$jobCard->tenantConfirm('All sorted, thanks', $user);
$service->complete($jobCard, $user);
$jobCard->refresh();
echo "After complete: job card status={$jobCard->status}, work order status={$jobCard->workOrder->fresh()->status}\n";
echo "Tenant confirmed at: {$jobCard->tenant_confirmed_at}\n";

echo "\n=== VERIFICATION " . ($jobCard->status === 'completed' && $jobCard->workOrder->fresh()->status === 'completed' ? 'PASSED' : 'FAILED') . " ===\n";

// Cleanup — throwaway records only, soft-delete floor respected (archive, not destroy).
$jobCard->archive($user);
echo "Cleanup: job card archived (soft-deleted), agency/property/user left for inspection if needed, agency_id={$agency->id}\n";
