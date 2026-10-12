<?php

namespace App\Services\TitleDeeds;

use App\Models\Contact;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedOwner;
use App\Support\WhatsAppNumberFormatter;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/title-deed-register.md §8.1 — WHERE an owner is reached. The ONLY source is what is stored on the contact:
 * nothing in this register ever accepts a typed address or number. Also answers "did this owner's contact details change
 * recently?" (§8.3 f).
 */
class TitleDeedDestinations
{
    /** The owner's live contact (same agency, not archived), read WITHOUT the viewer's contact-visibility scope. Null = skip. */
    public static function liveContact(TitleDeed $deed, TitleDeedOwner $owner): ?Contact
    {
        return Contact::withoutGlobalScopes()
            ->where('agency_id', $deed->agency_id)
            ->whereNull('deleted_at')
            ->find($owner->contact_id);
    }

    /** The email stored on the contact: its primary email, else the contact's mirror column. Valid format only. */
    public static function emailFor(?Contact $contact): ?string
    {
        if (! $contact) {
            return null;
        }

        $primary = DB::table('contact_emails')
            ->where('contact_id', $contact->id)->whereNull('deleted_at')
            ->orderByDesc('is_primary')->orderBy('id')->value('email');

        foreach ([$primary, $contact->email] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The number stored on the contact, in WhatsApp deep-link form (digits, country code first): its WhatsApp-flagged
     * number, else its primary number, else the contact's mirror column.
     *
     * @return array{digits:string, masked:string}|null
     */
    public static function whatsappFor(?Contact $contact): ?array
    {
        if (! $contact) {
            return null;
        }

        $rows = DB::table('contact_phones')
            ->where('contact_id', $contact->id)->whereNull('deleted_at')
            ->orderByDesc('is_primary_whatsapp')->orderByDesc('is_whatsapp')->orderByDesc('is_primary')->orderBy('id')
            ->get(['phone', 'dial_code']);

        $candidates = $rows->map(fn ($r) => [$r->phone, $r->dial_code])->all();
        $candidates[] = [$contact->phone, '+27'];

        foreach ($candidates as [$phone, $dial]) {
            $digits = WhatsAppNumberFormatter::forDeepLink($phone, $dial);
            if (strlen($digits) >= 10 && strlen($digits) <= 15) {
                return ['digits' => $digits, 'masked' => self::maskPhone($digits)];
            }
        }

        return null;
    }

    /** Every number stored on the contact (for the "which number was called" pick). id => masked label. */
    public static function storedNumbers(?Contact $contact): array
    {
        if (! $contact) {
            return [];
        }

        $out = [];
        $rows = DB::table('contact_phones')->where('contact_id', $contact->id)->whereNull('deleted_at')->orderBy('id')->get(['id', 'phone', 'dial_code']);
        foreach ($rows as $row) {
            $digits = WhatsAppNumberFormatter::forDeepLink($row->phone, $row->dial_code);
            if ($digits !== '') {
                $out[(int) $row->id] = self::maskPhone($digits);
            }
        }
        if ($out === [] && trim((string) $contact->phone) !== '') {
            $digits = WhatsAppNumberFormatter::forDeepLink($contact->phone, '+27');
            if ($digits !== '') {
                $out[0] = self::maskPhone($digits);   // 0 = the contact's mirror column
            }
        }

        return $out;
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return '***';
        }

        return mb_substr($local, 0, 1) . '***@' . $domain;
    }

    public static function maskPhone(string $digits): string
    {
        $digits = preg_replace('/\D+/', '', $digits) ?? '';
        if (strlen($digits) < 6) {
            return '***';
        }

        return '+' . substr($digits, 0, 2) . ' ••• ••' . substr($digits, -4);
    }

    /**
     * Did this contact's email or phone change within the last $days days? Looks at the identifier rows themselves
     * (created / edited / removed) AND at the contact audit trail (an edit of the mirror columns). $days = 0 = never.
     */
    public static function detailsChangedWithin(int $contactId, int $days): bool
    {
        if ($days <= 0) {
            return false;
        }
        $cutoff = now()->subDays($days);

        foreach (['contact_emails', 'contact_phones'] as $table) {
            $hit = DB::table($table)->where('contact_id', $contactId)->where(function ($q) use ($cutoff) {
                $q->where('created_at', '>=', $cutoff)->orWhere('updated_at', '>=', $cutoff)->orWhere('deleted_at', '>=', $cutoff);
            })->exists();
            if ($hit) {
                return true;
            }
        }

        try {
            return DB::table('contact_audit_log')
                ->where('contact_id', $contactId)
                ->where('event_type', 'contact_updated')
                ->where('created_at', '>=', $cutoff)
                ->where(function ($q) {
                    foreach (['email', 'phone'] as $key) {
                        $q->orWhereRaw(
                            "NOT (JSON_UNQUOTE(JSON_EXTRACT(old_values, '$.{$key}')) <=> JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.{$key}')))"
                        );
                    }
                })->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
