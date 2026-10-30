<?php

namespace App\Services\Rentals;

/**
 * .ai/specs/rentals-faults-work-orders.md section 31 (one click per stage) - the descriptors the "Returned | Next" bars hand to partials/next-button.blade.php.
 * The Next button IS the action: it submits something, opens a dialog right there, or navigates to another record to LOOK at it. It never scrolls to a folded
 * panel and never presses a hidden control on the agent's behalf (the old 'go' kind, removed in section 31).
 *
 *   link    - an <a href>; only for opening another record (a work order, a job card) to look at it, never for a dispatch action
 *   form    - submits a form that is already on the page, by id (native: <button type=submit form=ID>)
 *   submit  - same, but posts to another URL (formaction) - e.g. the fault's "What the owner will see" fields travel with "Send to owner"
 *   post    - a self-contained POST: its own <form>, CSRF and hidden fields, nothing else on the page needed
 *   open    - opens a <x-next-dialog name="..."> that holds the fields the action needs, right where the agent is
 *   note    - a plain sentence in place of a button (a decision the person may not make)
 */
final class NextAction
{
    public static function link(string $label, string $href): array
    {
        return ['kind' => 'link', 'label' => $label, 'href' => $href];
    }

    public static function form(string $label, string $formId): array
    {
        return ['kind' => 'form', 'label' => $label, 'form' => $formId];
    }

    public static function submit(string $label, string $formId, string $action): array
    {
        return ['kind' => 'submit', 'label' => $label, 'form' => $formId, 'action' => $action];
    }

    public static function post(string $label, string $action, array $fields = [], ?string $confirm = null): array
    {
        return ['kind' => 'post', 'label' => $label, 'action' => $action, 'fields' => $fields, 'confirm' => $confirm];
    }

    public static function open(string $label, string $dialog): array
    {
        return ['kind' => 'open', 'label' => $label, 'dialog' => $dialog];
    }

    /** a button that carries raw data-* attributes, for the pop-ups the page already wires (e.g. data-contractor-popup="details") */
    public static function popup(string $label, array $attrs): array
    {
        return ['kind' => 'popup', 'label' => $label, 'attrs' => $attrs];
    }

    /** stays on the card the agent is already working in: moves the cursor to a field of the lines editor (no panel opens, nothing is pressed) */
    public static function focus(string $label, string $target, ?string $focus = null): array
    {
        return ['kind' => 'focus', 'label' => $label, 'target' => $target, 'focus' => $focus];
    }

    public static function note(string $label): array
    {
        return ['kind' => 'note', 'label' => $label];
    }
}
