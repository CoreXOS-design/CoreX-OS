<?php

declare(strict_types=1);

namespace Tests\Unit\Communications;

use App\Services\Communications\MailFailureClassifier;
use Tests\TestCase;

/**
 * 2026-09-09 (Johan) — "fails should tell whoever is setting it up why its
 * failing... tell us why the server is rejecting the connection." A bare
 * 'connect_failed' catch-all cost two days chasing wrong theories while the
 * server had said AUTHENTICATIONFAILED in plain text the whole time. These
 * tests prove each real-world server response shape lands in its own
 * distinct, correctly-worded bucket — and that anything unrecognised is
 * honestly UNKNOWN rather than a confident wrong guess.
 */
final class MailFailureClassifierTest extends TestCase
{
    private function classifier(): MailFailureClassifier
    {
        return new MailFailureClassifier();
    }

    public function test_authentication_failures_classify_distinctly(): void
    {
        $c = $this->classifier();
        foreach ([
            '[AUTHENTICATIONFAILED] Invalid credentials (Failure)',
            'a login failure occurred',
            'Login failed for user',
            '535 5.7.8 Error: authentication failed',
            'bad login attempted',
        ] as $raw) {
            $this->assertSame(MailFailureClassifier::AUTH_FAILED, $c->classifyConnect($raw), "expected auth_failed for: {$raw}");
        }
    }

    public function test_account_not_found_classifies_distinctly_from_auth_failed(): void
    {
        $c = $this->classifier();
        $this->assertSame(MailFailureClassifier::MAILBOX_NOT_FOUND, $c->classifyConnect('NO [UNAVAILABLE] user unknown on this server'));
    }

    public function test_a_message_naming_both_shapes_prefers_auth_failed_as_the_safer_more_actionable_read(): void
    {
        // Deliberate precedence: auth needles are checked FIRST, so an ambiguous
        // message mentioning both leans toward "check your password" (actionable
        // by the person setting up the mailbox) rather than "no such account".
        $c = $this->classifier();
        $this->assertSame(MailFailureClassifier::AUTH_FAILED, $c->classifyConnect('NO [AUTHENTICATIONFAILED] user unknown'));
    }

    public function test_connection_refused_or_blocked_is_never_described_as_a_credentials_problem(): void
    {
        $c = $this->classifier();
        $reason = $c->classifyConnect('Connection refused by remote host');
        $this->assertSame(MailFailureClassifier::CONNECTION_REFUSED, $reason);
        $message = $c->friendlyForConnect($reason);
        $this->assertStringNotContainsStringIgnoringCase('check the password', $message);
        $this->assertStringNotContainsStringIgnoringCase('check the username', $message);
        // The message MAY explicitly rule out credentials as reassurance (see
        // below) — what it must never do is instruct the reader to check
        // their password/username, which would misdirect them toward the
        // wrong fix entirely.
        $this->assertStringContainsStringIgnoringCase('not a credentials problem', $message, 'connection-refused must proactively rule out the credentials theory, not just avoid the word');
    }

    public function test_timeout_is_distinct_from_an_outright_connect_failure(): void
    {
        $c = $this->classifier();
        $this->assertSame(MailFailureClassifier::CONNECT_TIMEOUT, $c->classifyConnect('stream_socket_client(): Connection timed out'));
        $this->assertSame(MailFailureClassifier::CONNECT_FAILED, $c->classifyConnect('php_network_getaddresses: getaddrinfo failed: Name or service not known'));
    }

    public function test_tls_failures_classify_distinctly(): void
    {
        $c = $this->classifier();
        $this->assertSame(MailFailureClassifier::TLS_FAILED, $c->classifyConnect('SSL: certificate verify failed'));
    }

    public function test_an_unrecognised_message_is_honestly_unknown_not_a_guess(): void
    {
        // The whole point: a confident wrong diagnosis is worse than an honest
        // unknown. This exact scenario shipped as a real bug this week.
        $c = $this->classifier();
        $reason = $c->classifyConnect('Something the server said that matches nothing we know about');
        $this->assertSame(MailFailureClassifier::UNKNOWN, $reason);
        $this->assertStringContainsString('could not recognise', $c->friendlyForConnect($reason));
        $this->assertStringContainsString('raw server response', $c->friendlyForConnect($reason));
    }

    public function test_smtp_send_rejection_is_distinguished_from_a_connect_failure(): void
    {
        $c = $this->classifier();
        $reason = $c->classifySmtpSend('Expected response code 250 but got code 550, with message "550 5.1.1 Recipient rejected"');
        $this->assertSame(MailFailureClassifier::SEND_REJECTED, $reason);
        $this->assertStringContainsString('refused to send', $c->friendlyForSmtpSend($reason));
    }

    public function test_smtp_send_auth_failure_uses_the_same_taxonomy_as_connect(): void
    {
        $c = $this->classifier();
        $this->assertSame(MailFailureClassifier::AUTH_FAILED, $c->classifySmtpSend('535 5.7.8 authentication failed'));
    }

    public function test_missing_folder_friendly_message_never_mentions_credentials(): void
    {
        $c = $this->classifier();
        $message = $c->friendlyForMissingFolder('Sent');
        $this->assertStringContainsString('Sent', $message);
        $this->assertStringNotContainsStringIgnoringCase('password', $message);
        $this->assertStringContainsString('logged in fine', $message);
    }
}
