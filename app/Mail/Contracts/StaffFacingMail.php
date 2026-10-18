<?php

declare(strict_types=1);

namespace App\Mail\Contracts;

/**
 * Marks a mail whose RECIPIENT is a staff user (an agent, a branch manager, an admin) - never a client.
 *
 * Staff read CoreX screens, so a staff mail may say "CoreX" ("open the inspection in CoreX"). A client (tenant, landlord, buyer, seller,
 * applicant, contractor) must never see the word: ClientFacingNeverSaysCoreXTest scans every client-facing mail view strictly, and leaves
 * out only the views of mailables that implement this interface. The rule is the recipient, not the file name: put a client in the
 * "to" of a mail that implements this and the mail is wrong; put a staff user in the "to" of a mail that does not and it stays strict.
 */
interface StaffFacingMail
{
}
