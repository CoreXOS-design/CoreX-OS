<?php

namespace App\Services\Compliance;

use App\Models\Contact;
use App\Models\FicaSubmission;

/**
 * The public FICA form opens with what CoreX already knows about the person it was sent to - name, ID, address, phone and email
 * from their contact record - so a known tenant or landlord confirms and corrects rather than typing everything again
 * (Cape walk, 14 Oct 2026: the form opened empty). Every value stays editable on the form; nothing is saved until they submit.
 *
 * A contact that is a company, trust or other entity is not a person: its own name is not "the person completing the form", so only
 * the contact details (phone, email, address) are carried and the entity type is left for them to choose.
 */
class FicaFormPrefill
{
    /**
     * @return array{entity_type: string, personal: array{full_name: string, id_number: string, sa_citizen: string, residential_address: string, phone: string, email: string, tax_number: string}}
     */
    public static function for(FicaSubmission $submission): array
    {
        $contact = $submission->contact;
        $blank = ['full_name' => '', 'id_number' => '', 'sa_citizen' => '', 'residential_address' => '', 'phone' => '', 'email' => '', 'tax_number' => ''];

        if (! $contact) {
            return ['entity_type' => '', 'personal' => $blank];
        }

        $isEntity = $contact->isEntity();
        $personal = $blank;
        $personal['phone'] = trim((string) $contact->phone);
        $personal['email'] = trim((string) $contact->email);
        $personal['residential_address'] = self::address($contact);

        if (! $isEntity) {
            $personal['full_name'] = trim((string) $contact->full_name);
            [$personal['id_number'], $personal['sa_citizen']] = self::identity($contact);
        }

        return ['entity_type' => $isEntity ? '' : 'natural', 'personal' => $personal];
    }

    /** The residential address as the contact record holds it: the free-text address, else the structured one composed. */
    private static function address(Contact $contact): string
    {
        $address = trim((string) $contact->address);

        return $address !== '' ? $address : (string) $contact->composeStructuredAddress();
    }

    /** @return array{0: string, 1: string} [ID or passport number, SA citizen yes/no/unknown] */
    private static function identity(Contact $contact): array
    {
        $id = trim((string) $contact->id_number);
        $passport = trim((string) $contact->passport_number);
        $type = strtolower(trim((string) $contact->id_type));

        if ($id !== '') {
            $isSaId = $type === 'sa_id' || ($type === '' && preg_match('/^\d{13}$/', $id) === 1);

            return [$id, $isSaId ? 'yes' : ($type === 'passport' ? 'no' : '')];
        }

        return [$passport, $passport !== '' ? 'no' : ''];
    }
}
