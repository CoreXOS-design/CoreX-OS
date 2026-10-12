<?php

namespace App\Services\TitleDeeds;

use App\Models\TitleDeeds\TitleDeedSetting as S;

/**
 * .ai/specs/title-deed-register.md section 15 - what an agency's Title Deed settings MEAN once the combinations are applied.
 *
 * The saved values are the agency's choices; this class turns them into the effective rules every screen and every server check
 * reads, so a screen can never offer what the server refuses (or the reverse). Dependent settings that are switched off by their
 * parent keep their saved value but are ignored here.
 *
 * Effective rules:
 *  - PIN Required: the PIN is the only way to collect - the owner ID check and every "without the PIN" route are not offered, and
 *    "a representative who arrives without the PIN" is effectively Refuse (the setting is hidden).
 *  - Representatives not allowed: no representative route at all; the representative rule, phone consent and authority letter are ignored.
 *  - Phone consent only exists where a representative can arrive without the PIN and the rule is not Refuse.
 *  - Authority letter Not used: never asked for, and the rule "owner link or authority letter" falls back to "owner link".
 *  - Signature Not used: no signature box, no signing link, no "ways to sign".
 */
final class TitleDeedPolicy
{
    public function __construct(
        public readonly string $pin,
        public readonly string $signature,
        public readonly string $photo,
        public readonly string $letter,
        public readonly bool $repsAllowed,
        public readonly string $repRule,
        public readonly bool $phoneConsent,
        public readonly bool $signOnScreen,
        public readonly bool $signByLink,
    ) {}

    public static function for(?int $agencyId): self
    {
        $pin = S::pinModeFor($agencyId);
        $signature = S::signatureModeFor($agencyId);
        $reps = S::representativesAllowedFor($agencyId);
        $letterSaved = S::authorityLetterModeFor($agencyId);
        $letter = $reps ? $letterSaved : S::MODE_NOT_USED;

        $rule = S::repWithoutPinModeFor($agencyId);
        if (! $reps || $pin === S::MODE_REQUIRED) {
            $rule = S::REP_REFUSE;
        } elseif ($rule === S::REP_CONSENT_OR_LETTER && $letter === S::MODE_NOT_USED) {
            $rule = S::REP_OWNER_CONSENT;
        }

        return new self(
            $pin,
            $signature,
            S::collectorIdPhotoModeFor($agencyId),
            $letter,
            $reps,
            $rule,
            $reps && $rule !== S::REP_REFUSE && S::phoneConsentAllowedFor($agencyId),
            $signature !== S::MODE_NOT_USED && S::signOnScreenEnabledFor($agencyId),
            $signature !== S::MODE_NOT_USED && S::signByLinkEnabledFor($agencyId),
        );
    }

    public function pinUsed(): bool
    {
        return $this->pin !== S::MODE_NOT_USED;
    }

    public function pinRequired(): bool
    {
        return $this->pin === S::MODE_REQUIRED;
    }

    /** Can staff hand over to an owner after only seeing their ID? (Not when the PIN is Required.) */
    public function ownerIdCheckOffered(): bool
    {
        return ! $this->pinRequired();
    }

    /** Can a representative arrive without the PIN and still be accepted? */
    public function repWithoutPinOffered(): bool
    {
        return $this->repsAllowed && $this->repRule !== S::REP_REFUSE;
    }

    public function signatureUsed(): bool
    {
        return $this->signature !== S::MODE_NOT_USED;
    }

    public function signatureRequired(): bool
    {
        return $this->signature === S::MODE_REQUIRED;
    }

    public function photoUsed(): bool
    {
        return $this->photo !== S::MODE_NOT_USED;
    }

    public function photoRequired(): bool
    {
        return $this->photo === S::MODE_REQUIRED;
    }

    public function letterUsed(): bool
    {
        return $this->letter !== S::MODE_NOT_USED;
    }

    public function letterRequired(): bool
    {
        return $this->letter === S::MODE_REQUIRED;
    }

    /** The modes as they stood, kept on the receipt so the record can say "not captured" truthfully later. */
    public function proofModes(): array
    {
        return ['pin' => $this->pin, 'signature' => $this->signature, 'id_photo' => $this->photo, 'letter' => $this->letter];
    }

    /**
     * Combinations that cannot work, for the VISIBLE settings only (a setting hidden by its parent is ignored, never an error).
     *
     * @param array<string,mixed> $v the merged settings (saved values overlaid with what was posted), keys as in TitleDeedSetting::FIELDS
     * @return array<string,string> field => plain message
     */
    public static function conflicts(array $v): array
    {
        $out = [];
        $pin = $v['pin_mode'];
        $reps = (bool) $v['representatives_allowed'];
        $rule = $v['rep_without_pin_mode'];

        if ($v['signature_mode'] === S::MODE_REQUIRED && ! $v['sign_on_screen_enabled'] && ! $v['sign_by_link_enabled']) {
            $out['sign_on_screen_enabled'] = 'The signature is Required, so at least one way to sign must be Yes: "Sign on our screen" or "Sign on their own phone from a link or QR".';
        }
        // The representative rule is only visible when representatives are allowed and the PIN is not Required.
        if ($reps && $pin !== S::MODE_REQUIRED) {
            if ($pin === S::MODE_NOT_USED && $rule === S::REP_REFUSE) {
                $out['rep_without_pin_mode'] = 'With no PIN, every representative arrives without one - and this rule says Refuse, so no representative could ever collect. Choose another rule here, or answer No to "May a representative collect?".';
            }
            if ($rule === S::REP_CONSENT_OR_LETTER && $v['authority_letter_mode'] === S::MODE_NOT_USED) {
                $out['authority_letter_mode'] = 'The rule "Owner link or a signed authority letter" needs the authority letter, but it is set to Not used. Make the letter Optional or Required, or choose another rule.';
            }
        }

        return $out;
    }
}
