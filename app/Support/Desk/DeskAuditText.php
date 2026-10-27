<?php

namespace App\Support\Desk;

use App\Models\DeskSessionEvent;

/**
 * CoreX Desk - the audit trail in plain words (Compliance > Reports & Logs > Desk sessions, spec .ai/specs/corex-desk.md sections 5, 13, 14).
 * One place, so the page and the tests read the same sentences. Nothing here ever shows a typed value: the trail never holds one.
 */
final class DeskAuditText
{
    private const LABELS = [
        'created' => 'Code issued', 'joined' => 'Support joined', 'page' => 'Moved to page', 'highlight' => 'Support highlighted',
        'highlight_cleared' => 'Highlight cleared', 'pointer_on' => 'Support pointer on', 'pointer_off' => 'Support pointer off', 'ended' => 'Session ended',
        'tab_join' => 'Browser tab shared', 'tab_update' => 'Shared tab moved to another site', 'tab_leave' => 'Browser tab no longer shared',
        'control_requested' => 'Support asked to take control', 'control_granted' => 'Agent allowed control', 'control_declined' => 'Control not given',
        'control_revoked' => 'Agent took back control', 'control_ended' => 'Control ended', 'control_action' => 'Support acted on the agent\'s screen',
    ];

    private const ENDED = ['agent' => 'the agent', 'support' => 'support', 'logout' => 'the agent signing out', 'timeout' => 'the connection being lost',
        'expired' => 'nobody joining in time', 'disabled' => 'Desk being switched off', 'replaced' => 'a new session being started'];

    private const CONTROL_END = ['support' => 'support handed control back', 'idle' => 'nothing was done for the agency\'s idle limit', 'session' => 'the session ended',
        'tab' => 'the shared tab was closed', 'agent' => 'the agent took it back', 'no_answer' => 'the agent did not answer in time', 'declined' => 'the agent declined'];

    private const VERBS = ['click' => 'clicked', 'dblclick' => 'double-clicked', 'type' => 'typed into', 'key' => 'pressed keys in', 'scroll' => 'scrolled',
        'select' => 'chose an option in', 'focus' => 'selected'];

    private const BLOCKED = ['private_field' => 'blocked - a private field', 'desk_ui' => 'blocked - part of CoreX Desk itself', 'not_found' => 'could not find it',
        'hidden' => 'it was not visible', 'disabled' => 'it was switched off', 'not_a_text_field' => 'not a text field', 'not_a_dropdown' => 'not a dropdown',
        'no_such_choice' => 'no such choice', 'key_not_allowed' => 'key not allowed', 'unknown_action' => 'not an allowed action', 'error' => 'it failed'];

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }

    /** Where it happened: "CoreX page" or "title - host" of a shared tab. */
    private static function where(array $p): string
    {
        $tab = (string) ($p['tab'] ?? 'main');
        if ($tab === 'main') {
            return 'the CoreX page';
        }
        $host = (string) ($p['host'] ?? '');
        $title = (string) ($p['title'] ?? '');

        return 'the shared tab ' . trim(($title !== '' ? $title . ' - ' : '') . $host);
    }

    public static function detail(DeskSessionEvent $e): string
    {
        $p = (array) $e->payload;
        $type = $e->type;

        switch ($type) {
            case 'joined':
                return (string) ($p['support_name'] ?? '') . (! empty($p['again']) ? ' (opened the session again)' : (! empty($p['additional']) ? ' (a second support person)' : ''));
            case 'page':
                return (string) ($p['page'] ?? '');
            case 'highlight':
                $note = ! empty($p['note']) ? ' - "' . $p['note'] . '"' : '';
                $in = (! empty($p['tab']) && $p['tab'] !== 'main') ? ' (in a shared browser tab)' : '';

                return (string) ($p['selector'] ?? '') . $note . $in;
            case 'tab_join':
            case 'tab_update':
            case 'tab_leave':
                return (string) ($p['host'] ?? '') . (! empty($p['title']) ? ' - ' . $p['title'] : '');
            case 'ended':
                return 'by ' . (self::ENDED[$p['by'] ?? ''] ?? ($p['by'] ?? ''));
            case 'control_requested':
                return 'of ' . self::where($p);
            case 'control_granted':
                return 'of ' . self::where($p);
            case 'control_declined':
                return 'of ' . self::where($p) . ' - ' . (self::CONTROL_END[$p['reason'] ?? 'declined'] ?? 'declined');
            case 'control_revoked':
                return 'of ' . self::where($p);
            case 'control_ended':
                return 'of ' . self::where($p) . ' - ' . (self::CONTROL_END[$p['reason'] ?? ''] ?? ($p['reason'] ?? ''));
            case 'control_action':
                $n = (int) ($p['n'] ?? 1);
                $verb = self::VERBS[$p['action'] ?? ''] ?? ($p['action'] ?? 'acted on');
                $what = ! empty($p['label']) ? '"' . $p['label'] . '"' : 'an element';
                $times = $n > 1 ? ($p['action'] === 'type' || $p['action'] === 'key' ? " ({$n} keystrokes)" : " ({$n} times)") : '';
                $result = ($p['ok'] ?? true) ? '' : ' - ' . (self::BLOCKED[$p['reason'] ?? ''] ?? 'not done');

                return "{$verb} {$what} on " . self::where($p) . $times . $result;
        }

        return '';
    }
}
