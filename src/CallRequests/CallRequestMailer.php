<?php

declare(strict_types=1);

namespace Reach\CallRequests;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Core\Settings;

use function wp_mail;

/**
 * Sends a callback request to the configured call-request address.
 *
 * This is where the caller's personal data goes. Rather than persisting
 * the caller's name, phone, preferred 12th-stepper and note, Reach mails
 * them — keyed by the request's serial so an admin can match the message
 * to the tracking row in the "Call Requests" list. The database keeps
 * only the non-identifying tracking record (see {@see CallRequest}).
 *
 * Plain-text body only: the message carries personal data to an inbox,
 * and plain text avoids any HTML-injection surface from the free-text
 * caller fields.
 */
final class CallRequestMailer
{
    /** Human labels for the stored single-choice gender values. */
    private const GENDER_LABELS = [
        'male'       => 'Male',
        'female'     => 'Female',
        'non-binary' => 'Non-Binary',
    ];

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Email one callback request. Returns wp_mail's success flag; the
     * caller treats false as "could not send" and rolls back the row.
     */
    public function send(
        string $serial,
        string $responderName,
        string $gender,
        string $area,
        string $callerName,
        string $callerPhone,
        ?string $note,
        int $createdAt,
    ): bool {
        $to = $this->settings->getCallRequestEmail();
        if ($to === '') {
            return false;
        }

        $blogName = (string) get_bloginfo('name');
        $subject  = sprintf('[%s] Call request %s', $blogName !== '' ? $blogName : 'Reach', $serial);

        $when = function_exists('wp_date')
            ? (string) wp_date('Y-m-d H:i', $createdAt)
            : gmdate('Y-m-d H:i', $createdAt) . ' UTC';

        $genderLabel = self::GENDER_LABELS[$gender] ?? ($gender !== '' ? $gender : '—');

        $lines = [
            'A 12th-step callback has been requested from the Reach find page.',
            '',
            'Reference:          ' . $serial,
            'Raised:             ' . $when,
            'By (responder):     ' . ($responderName !== '' ? $responderName : '(unknown)'),
            '',
            'Caller name:        ' . $callerName,
            'Caller phone:       ' . $callerPhone,
            'Caller area:        ' . ($area !== '' ? $area : '—'),
            'Preferred 12th-Step: ' . $genderLabel,
        ];

        $noteText = $note !== null ? trim($note) : '';
        if ($noteText !== '') {
            $lines[] = '';
            $lines[] = 'Note:';
            $lines[] = $noteText;
        }

        $lines[] = '';
        $lines[] = 'Please call the caller back, then mark request ' . $serial
            . ' as completed on the Call Requests admin page.';

        $body    = implode("\n", $lines);
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        return (bool) wp_mail($to, $subject, $body, $headers);
    }
}
