<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInventory;

use App\Models\RentalInventorySignature;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Audit M5 — a canvas signature must be a real, size-capped PNG stored on the
 * PRIVATE disk under an unguessable name; garbage never counts as a signature.
 */
final class RentalInventorySignatureImageTest extends TestCase
{
    private function png(): string
    {
        $img = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    public function test_a_valid_png_is_stored_privately_with_a_random_name(): void
    {
        Storage::fake('local');

        $path = RentalInventorySignature::storeCanvasImage('data:image/png;base64,' . base64_encode($this->png()), 42);

        $this->assertStringStartsWith('rental-inventory-signatures/42/sig_', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_garbage_empty_and_non_png_payloads_are_rejected(): void
    {
        Storage::fake('local');

        foreach (['!!!not-base64!!!', '', base64_encode('just text'), base64_encode("\xFF\xD8\xFF\xE0jpegish")] as $payload) {
            try {
                RentalInventorySignature::storeCanvasImage($payload, 42);
                $this->fail('Expected rejection for payload: ' . substr($payload, 0, 20));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_oversized_payload_is_rejected(): void
    {
        Storage::fake('local');

        $this->expectException(\InvalidArgumentException::class);
        RentalInventorySignature::storeCanvasImage(base64_encode($this->png() . str_repeat('0', RentalInventorySignature::MAX_SIGNATURE_BYTES)), 42);
    }
}
