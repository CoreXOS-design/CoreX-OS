<?php

namespace App\Services\Campaigns;

/**
 * AT-534 — the HTML version of a campaign email, used only when the sending address has a signature image (spec 9.3).
 * Pure text in, HTML out: every piece of text (including merge-field values taken from a CSV) is escaped, URLs become links, line
 * breaks are kept. The picture itself is a placeholder token that the mail view swaps for an inline (embedded) image at send time,
 * so the stored snapshot never depends on a file that might later be replaced.
 */
class CampaignHtmlRenderer
{
    public const IMAGE_TOKEN = '[[CAMPAIGN_SIGNATURE_IMAGE]]';

    public function render(string $body, ?string $signature, string $footer, string $unsubscribeUrl): string
    {
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222222;">'
            . '<div>' . $this->text($body) . '</div>';

        if ($signature !== null && trim($signature) !== '') {
            $html .= '<div style="margin-top:18px;">' . $this->text($signature) . '</div>';
        }

        $html .= '<div style="margin-top:10px;">' . self::IMAGE_TOKEN . '</div>';

        if ($footer !== '' || $unsubscribeUrl !== '') {
            $html .= '<div style="margin-top:26px;font-size:12px;line-height:1.4;color:#777777;">'
                . $this->text(trim($footer . ($unsubscribeUrl !== '' ? "\n" . $unsubscribeUrl : '')))
                . '</div>';
        }

        return $html . '</div>';
    }

    /** Escape, link the URLs, keep the line breaks. */
    private function text(string $text): string
    {
        $escaped = e(str_replace("\r\n", "\n", $text));

        $linked = preg_replace_callback('~https?://[^\s<]+~i', function (array $m) {
            $url = $m[0];
            $trail = '';
            while ($url !== '' && str_contains('.,;:!?)', substr($url, -1))) {
                $trail = substr($url, -1) . $trail;
                $url = substr($url, 0, -1);
            }

            return '<a href="' . $url . '" style="color:inherit;text-decoration:underline;">' . $url . '</a>' . $trail;
        }, $escaped);

        return nl2br($linked ?? $escaped, false);
    }
}
