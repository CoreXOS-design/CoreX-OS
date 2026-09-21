<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * AT-423 — the One email safety net. Spec: .ai/specs/one-email-sub-users.md §3.6.
 *
 * A sub-user's sign-in "email" is a username such as andre@hfcoastal, which is not a
 * deliverable address. CoreX has dozens of places that mail `$user->email` directly
 * (digests, reminders, e-sign notices…) and more will be written. Instead of trusting
 * every one of them to remember, every outgoing message passes through here first
 * (OutboundMailGuardServiceProvider's MessageSending listener): any To/Cc/Bcc/Reply-To
 * that is a sub-user's username is re-addressed to that person's shared inbox.
 *
 * Cheap for normal mail: only an address with no dot after the @ triggers a lookup.
 */
class SubUserMailRouter
{
    /**
     * Rewrite the message in place. Returns false when, after rewriting, it has no
     * recipient left (a username with no shared inbox) — the caller then cancels the send.
     */
    public static function reroute(Email $message): bool
    {
        $touched = false;

        $to  = self::rewrite($message->getTo(), $touched);
        $cc  = self::rewrite($message->getCc(), $touched);
        $bcc = self::rewrite($message->getBcc(), $touched);
        $replyTo = self::rewrite($message->getReplyTo(), $touched);

        if (!$touched) {
            return true;
        }

        // An emptied list header is removed rather than left as a blank "Cc:" line.
        foreach (['To' => $to, 'Cc' => $cc, 'Bcc' => $bcc, 'Reply-To' => $replyTo] as $header => $list) {
            $message->getHeaders()->remove($header);
            if ($list !== []) {
                match ($header) {
                    'To'       => $message->to(...$list),
                    'Cc'       => $message->cc(...$list),
                    'Bcc'      => $message->bcc(...$list),
                    'Reply-To' => $message->replyTo(...$list),
                };
            }
        }

        if ($to === [] && $cc === [] && $bcc === []) {
            Log::warning('One email: message cancelled — its only recipients were sub-user usernames with no shared inbox.', [
                'subject' => (string) $message->getSubject(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param  Address[]  $addresses
     * @return Address[]
     */
    private static function rewrite(array $addresses, bool &$touched): array
    {
        $out = [];

        foreach ($addresses as $address) {
            $email = $address->getAddress();
            $domain = substr($email, (int) strrpos($email, '@') + 1);

            if (str_contains($domain, '.')) {
                $out[strtolower($email)] = $address;
                continue;
            }

            $user = User::withoutGlobalScopes()
                ->where('email', $email)
                ->where('is_sub_user', true)
                ->first();

            if (!$user) {
                // Not one of ours — leave it exactly as the sender wrote it.
                $out[strtolower($email)] = $address;
                continue;
            }

            $touched = true;
            $inbox = $user->deliveryEmail();

            Log::info('One email: re-addressed mail for a sub-user to the shared inbox.', [
                'user_id' => $user->id, 'to' => $inbox,
            ]);

            if ($inbox) {
                // Keyed by address so two sub-users on one message give the inbox ONE copy.
                $out[strtolower($inbox)] = new Address($inbox, $address->getName());
            }
        }

        return array_values($out);
    }
}
