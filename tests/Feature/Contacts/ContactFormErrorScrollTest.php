<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * An invalid ID number put a red banner at the top of the contact page while the ID field sat a screen or more below, and nothing
 * took the person to it (the script focused #id_number, but the input had no id). The page now scrolls to the FIELD that failed.
 */
final class ContactFormErrorScrollTest extends TestCase
{
    private function render(array $errors): string
    {
        $bag = new ViewErrorBag();
        $bag->put('default', new MessageBag($errors));

        return view('corex.contacts._form-errors', ['errors' => $bag])->render();
    }

    public function test_the_banner_still_lists_the_error_and_the_script_goes_to_the_failed_field(): void
    {
        $html = $this->render(['id_number' => ['The ID number is not a valid South African ID.']]);

        $this->assertStringContainsString("Couldn't save", $html);
        $this->assertStringContainsString('The ID number is not a valid South African ID.', $html);
        $this->assertStringContainsString('const keys = ["id_number"]', $html);
        $this->assertStringContainsString("document.getElementById(key) || document.querySelector('[name=\"' + key + '\"]')", $html);
        $this->assertStringContainsString("scrollIntoView({ behavior: 'smooth', block: 'center' })", $html);
        $this->assertStringContainsString("document.getElementById('contact-form-errors')?.scrollIntoView", $html, 'falls back to the banner when no field is found');
    }

    public function test_nothing_renders_without_errors(): void
    {
        $this->assertSame('', trim($this->render([])));
    }

    public function test_both_contact_forms_give_their_id_input_the_id_the_script_looks_for(): void
    {
        foreach (['show', 'index'] as $view) {
            $src = (string) file_get_contents(resource_path("views/corex/contacts/{$view}.blade.php"));
            $this->assertMatchesRegularExpression('/<input type="text" id="id_number" name="id_number"/', $src, $view);
        }
    }
}
