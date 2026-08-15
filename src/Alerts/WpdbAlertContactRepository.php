<?php

declare(strict_types=1);

namespace Reach\Alerts;

if (!defined('ABSPATH')) {
    exit;
}

use LogicException;
use Reach\Core\Cipher;
use wpdb;

use function dbDelta;

/**
 * $wpdb-backed {@see AlertContactRepository}, encrypting at rest.
 *
 * The stored column holds base64 of an AES-256-GCM payload keyed by a
 * WordPress salt (see {@see Cipher}). Rotating that salt makes existing
 * contacts undecryptable — which is the intended behaviour after a
 * suspected breach, and tolerable here because an alert whose contact
 * can no longer be read is an alert that can be raised again.
 */
final class WpdbAlertContactRepository implements AlertContactRepository
{
    public const TABLE_SUFFIX = 'reach_alert_contacts';

    /** Domain separator for the encryption key. See {@see Cipher}. */
    private const CIPHER_DOMAIN = 'reach-alert-contact';

    /**
     * Cap on a stored contact, in bytes. Generous for "Sam, 07700
     * 900123, prefers evenings" and far short of anything that belongs
     * in a database column instead of an email.
     */
    private const MAX_BYTES = 500;

    private readonly Cipher $cipher;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->cipher = new Cipher(self::CIPHER_DOMAIN);
    }

    /**
     * @return literal-string
     *
     * See WpdbPasswordCredentialRepository::tableName() on why the assertion
     * is needed and why it holds.
     */
    public static function tableName(wpdb $wpdb): string
    {
        /** @var literal-string $prefix */
        $prefix = $wpdb->prefix;

        return $prefix . self::TABLE_SUFFIX;
    }

    /**
     * Idempotent: safe to call on every activation.
     *
     * The contact column is sized for the base64 of an encrypted 500
     * bytes plus the IV and tag, with headroom — TEXT rather than a
     * VARCHAR because a too-small column would truncate ciphertext into
     * something that cannot be decrypted, and would do it silently.
     */
    public static function install(wpdb $wpdb): void
    {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $table   = self::tableName($wpdb);
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            alert_id BIGINT UNSIGNED NOT NULL,
            contact TEXT NOT NULL,
            created_at BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (alert_id)
        ) {$charset};";

        dbDelta($sql);
    }

    public function save(int $alertId, string $contact, int $now): bool
    {
        $contact = trim($contact);
        if ($contact === '') {
            return $this->delete($alertId);
        }

        if (strlen($contact) > self::MAX_BYTES) {
            $contact = (string) mb_strcut($contact, 0, self::MAX_BYTES, 'UTF-8');
        }

        $encrypted = $this->cipher->encrypt($contact);
        if ($encrypted === '') {
            // Refuse rather than store an empty value that would read back
            // as "no contact". A responder being told there are no details
            // when there are is worse than the raising plugin seeing this
            // fail.
            throw new LogicException('The alert contact could not be encrypted.');
        }

        $table = self::tableName($this->wpdb);

        // REPLACE rather than INSERT: re-sending an alert's contact should
        // overwrite, and the primary key on alert_id makes that exact.
        $sql = $this->wpdb->prepare(
            "REPLACE INTO {$table} (alert_id, contact, created_at) VALUES (%d, %s, %d)",
            $alertId,
            $encrypted,
            $now,
        );

        if ($sql === null) {
            throw new LogicException('Failed to prepare the alert-contact write.');
        }

        $written = $this->wpdb->query($sql);

        return is_int($written) && $written > 0;
    }

    public function find(int $alertId): string
    {
        $table = self::tableName($this->wpdb);

        $stored = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT contact FROM {$table} WHERE alert_id = %d LIMIT 1",
            $alertId,
        ));

        if (!is_string($stored) || $stored === '') {
            return '';
        }

        return $this->cipher->decrypt($stored);
    }

    public function has(int $alertId): bool
    {
        $table = self::tableName($this->wpdb);

        $count = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE alert_id = %d",
            $alertId,
        ));

        return (int) $count > 0;
    }

    public function delete(int $alertId): bool
    {
        $table = self::tableName($this->wpdb);
        $deleted = $this->wpdb->delete($table, ['alert_id' => $alertId], ['%d']);

        return is_int($deleted) && $deleted > 0;
    }

    public function purgeForExpiredAlertsBefore(int $before): int
    {
        $table  = self::tableName($this->wpdb);
        $alerts = WpdbAlertRepository::tableName($this->wpdb);

        $sql = $this->wpdb->prepare(
            "DELETE c FROM {$table} c
               INNER JOIN {$alerts} a ON a.id = c.alert_id
              WHERE a.expires_at < %d",
            $before,
        );

        if ($sql === null) {
            throw new LogicException('Failed to prepare the alert-contact purge query.');
        }

        $deleted = $this->wpdb->query($sql);

        return is_int($deleted) ? $deleted : 0;
    }
}
