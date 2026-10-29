<?php

declare(strict_types=1);

namespace MKProjects\Domain;

/**
 * The alerts' settings (the owner's "Powiadomienia"): the channels – e-mail (through SMTP when set, else WordPress's
 * own mail) and Slack (an incoming webhook) – which rule goes to which of them, and the weekly summary.
 * The SMTP password and the webhook (it is a secret: whoever has it posts to the channel) are kept encrypted (Secrets),
 * apart; the settings only say whether they are set.
 */
final class AlertSettings
{
    private const OPTION = 'wp_plugin_projects_alerts';
    private const SMTP_PASSWORD = 'wp_plugin_projects_smtp_password';
    private const SLACK_WEBHOOK = 'wp_plugin_projects_slack_webhook';

    public const CHANNELS = ['email', 'slack'];

    /** the rules: `after` – failed checks in a row (5 min each); `days` – before the certificate ends; `hours` – without a report */
    public const RULES = [
        'down' => ['enabled' => true, 'after' => 2, 'channels' => ['email', 'slack']],
        'recovery' => ['enabled' => true, 'channels' => ['email', 'slack']],
        'staging' => ['enabled' => false, 'after' => 3, 'channels' => ['slack']],
        'ssl' => ['enabled' => true, 'days' => 14, 'channels' => ['email', 'slack']],
        'report' => ['enabled' => true, 'hours' => 24, 'channels' => ['email']],
        'vulnerability' => ['enabled' => true, 'channels' => ['email', 'slack']],
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'email' => ['enabled' => false, 'to' => ''],
            'smtp' => ['host' => '', 'port' => 587, 'secure' => 'tls', 'user' => '', 'from' => '', 'fromName' => 'Lista projektów'],
            'slack' => ['enabled' => false],
            'rules' => self::RULES,
            // day: 1 Monday … 7 Sunday, the site's time zone
            'weekly' => ['enabled' => true, 'day' => 1, 'hour' => 8, 'channels' => ['email']],
        ];
    }

    /** @return array<string, mixed> the settings, and whether the secrets are set */
    public static function get(): array
    {
        $saved = get_option(self::OPTION, []);

        return self::clean(is_array($saved) ? $saved : []) + [
            'hasSmtpPassword' => get_option(self::SMTP_PASSWORD, '') !== '',
            'hasSlackWebhook' => get_option(self::SLACK_WEBHOOK, '') !== '',
        ];
    }

    /**
     * Saves the settings; the secrets: null – kept as they are, '' – removed, else – the new one.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function save(array $input, ?string $smtpPassword, ?string $slackWebhook): array
    {
        update_option(self::OPTION, self::clean($input), false);

        foreach ([self::SMTP_PASSWORD => $smtpPassword, self::SLACK_WEBHOOK => $slackWebhook] as $option => $secret) {
            if ($secret !== null) {
                update_option($option, $secret === '' ? '' : (new Secrets())->encrypt(mb_substr($secret, 0, 500)), false);
            }
        }

        return self::get();
    }

    public static function smtpPassword(): string
    {
        return self::secret(self::SMTP_PASSWORD);
    }

    public static function slackWebhook(): string
    {
        return self::secret(self::SLACK_WEBHOOK);
    }

    private static function secret(string $option): string
    {
        $stored = (string) get_option($option, '');

        return $stored === '' ? '' : (string) (new Secrets())->decrypt($stored);
    }

    /**
     * Known keys only, in their types; what's missing – the default.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function clean(array $input): array
    {
        $defaults = self::defaults();
        $channels = static fn (mixed $value, array $default): array => is_array($value)
            ? array_values(array_intersect(self::CHANNELS, $value))
            : $default;
        $number = static fn (mixed $value, int $default, int $min, int $max): int => is_numeric($value) ? max($min, min($max, (int) $value)) : $default;

        $email = (array) ($input['email'] ?? []);
        $smtp = (array) ($input['smtp'] ?? []);
        $weekly = (array) ($input['weekly'] ?? []);
        $rules = [];

        foreach (self::RULES as $key => $default) {
            $rule = (array) ($input['rules'][$key] ?? []);
            $rules[$key] = ['enabled' => (bool) ($rule['enabled'] ?? $default['enabled']), 'channels' => $channels($rule['channels'] ?? null, $default['channels'])];

            foreach (['after' => [1, 48], 'days' => [1, 90], 'hours' => [2, 168]] as $limit => [$min, $max]) {
                if (isset($default[$limit])) {
                    $rules[$key][$limit] = $number($rule[$limit] ?? null, $default[$limit], $min, $max);
                }
            }
        }

        $to = implode(', ', array_filter(array_map('sanitize_email', array_map('trim', explode(',', (string) ($email['to'] ?? ''))))));

        return [
            'email' => ['enabled' => (bool) ($email['enabled'] ?? false), 'to' => $to],
            'smtp' => [
                'host' => sanitize_text_field((string) ($smtp['host'] ?? '')),
                'port' => $number($smtp['port'] ?? null, 587, 1, 65535),
                'secure' => in_array($smtp['secure'] ?? null, ['tls', 'ssl', ''], true) ? $smtp['secure'] : 'tls',
                'user' => sanitize_text_field((string) ($smtp['user'] ?? '')),
                'from' => sanitize_email((string) ($smtp['from'] ?? '')),
                'fromName' => sanitize_text_field((string) ($smtp['fromName'] ?? $defaults['smtp']['fromName'])),
            ],
            'slack' => ['enabled' => (bool) ($input['slack']['enabled'] ?? false)],
            'rules' => $rules,
            'weekly' => [
                'enabled' => (bool) ($weekly['enabled'] ?? $defaults['weekly']['enabled']),
                'day' => $number($weekly['day'] ?? null, 1, 1, 7),
                'hour' => $number($weekly['hour'] ?? null, 8, 0, 23),
                'channels' => $channels($weekly['channels'] ?? null, $defaults['weekly']['channels']),
            ],
        ];
    }
}
