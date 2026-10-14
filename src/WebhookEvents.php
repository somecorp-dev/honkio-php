<?php

declare(strict_types=1);

namespace Honkio;

/** Every event a webhook can subscribe to. Kept equal to the API's list by tests/WebhookTest.php. */
final class WebhookEvents
{
    /** @var list<string> */
    public const ALL = [
        'message.queued',
        'message.sending',
        'message.sent',
        'message.delivered',
        'message.failed',
        'message.undelivered',
        'message.received',
        'opt_out.recorded',
        'opt_out.reinstated',
        'consent.double_opt_in_confirmed',
        'account.delivery_warning',
        'account.sending_paused',
        'account.spend_warning',
        'account.inbound_email_capped',
        'account.inbound_sms_capped',
        'account.auto_topup_succeeded',
        'account.auto_topup_paused',
        'email.queued',
        'email.sent',
        'email.delivered',
        'email.delivery_delayed',
        'email.bounced',
        'email.complained',
        'email.rejected',
        'email.failed',
        'email.opened',
        'email.clicked',
        'email.unsubscribed',
        'email.cancelled',
        'email.rescheduled',
        'email.received',
        'email_domain.verified',
        'email_domain.verification_failed',
        'email_domain.receiving_verified',
        'email_domain.receiving_failed',
        'phone_number.suspended',
        'phone_number.released',
        'toll_free_verification.approved',
        'toll_free_verification.rejected',
        'toll_free_verification.changes_requested',
    ];
}
