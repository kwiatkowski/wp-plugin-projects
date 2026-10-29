<?php

declare(strict_types=1);

namespace MKProjects\Domain;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sends a message to the alerts' channels: e-mail (WordPress's wp_mail – through the SMTP server of the settings when
 * one is set, only for these messages) and Slack (the incoming webhook, its text in Slack's mrkdwn).
 */
final class Notifier
{
    /**
     * @param string[] $channels
     * @return array<string, true|string> per channel: true – sent, else why not
     */
    public function send(array $channels, string $subject, string $text, ?string $html = null): array
    {
        $settings = AlertSettings::get();
        $results = [];

        if (in_array('email', $channels, true)) {
            $results['email'] = $this->email($settings, $subject, $html ?? nl2br(esc_html($text)));
        }

        if (in_array('slack', $channels, true)) {
            $results['slack'] = $this->slack($settings, $text);
        }

        return $results;
    }

    /** the dot's colour per tone, in the e-mails – as the list shows them */
    public const TONES = ['ok' => '#2e9e5b', 'warn' => '#e08a1e', 'danger' => '#d64545'];

    /** the subject's dot per tone */
    public const DOTS = ['ok' => '🟢', 'warn' => '🟠', 'danger' => '🔴'];

    /** a coloured dot, inline – for the e-mails */
    public static function dot(string $tone): string
    {
        return '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . (self::TONES[$tone] ?? '#999') . ';vertical-align:middle"></span>';
    }

    /**
     * An e-mail's page: a card with the title (and a line under it), the content, the button to the list well apart
     * from it – tables and inline styles, as the mail clients want them.
     */
    public static function layout(string $title, string $content, string $subtitle = ''): string
    {
        $font = "font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

        return '<!doctype html><html><body style="margin:0;padding:0;background:#f3f5f4">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f5f4"><tr><td align="center" style="padding:28px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;background:#ffffff;border:1px solid #e3e7e5;border-radius:10px;' . $font . ';color:#1f2a24">'
            . '<tr><td style="padding:22px 28px 18px;border-bottom:1px solid #edf0ee">'
            . '<div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#7a857f">Lista projektów</div>'
            . '<div style="font-size:20px;font-weight:600;margin-top:4px">' . esc_html($title) . '</div>'
            . ($subtitle !== '' ? '<div style="font-size:13px;color:#7a857f;margin-top:4px">' . esc_html($subtitle) . '</div>' : '')
            . '</td></tr>'
            . '<tr><td style="padding:8px 28px 4px;font-size:14px;line-height:1.5">' . $content . '</td></tr>'
            . '<tr><td style="padding:24px 28px 28px">'
            . '<a href="' . esc_url(\MKProjects\App\AppShell::url()) . '" style="display:inline-block;background:#2e9e5b;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:10px 18px;border-radius:6px">Otwórz listę projektów</a>'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** @param array<string, mixed> $settings */
    private function email(array $settings, string $subject, string $html): true|string
    {
        if (empty($settings['email']['enabled']) || $settings['email']['to'] === '') {
            return 'e-mail wyłączony albo bez adresata';
        }

        $smtp = $settings['smtp'];
        $error = null;

        // only for this message – the site's own mail stays as it is
        $configure = static function (PHPMailer $mailer) use ($smtp): void {
            $mailer->isSMTP();
            $mailer->Host = $smtp['host'];
            $mailer->Port = (int) $smtp['port'];
            $mailer->SMTPSecure = $smtp['secure'];
            $mailer->SMTPAutoTLS = $smtp['secure'] !== '';
            $mailer->SMTPAuth = $smtp['user'] !== '';
            $mailer->Username = $smtp['user'];
            $mailer->Password = AlertSettings::smtpPassword();
            $mailer->Timeout = 15;

            if ($smtp['from'] !== '') {
                $mailer->setFrom($smtp['from'], $smtp['fromName'], false);
            }
        };
        $failed = static function (\WP_Error $wpError) use (&$error): void {
            $error = $wpError->get_error_message();
        };

        if ($smtp['host'] !== '') {
            add_action('phpmailer_init', $configure);
        }

        add_action('wp_mail_failed', $failed);
        $sent = wp_mail(array_map('trim', explode(',', $settings['email']['to'])), $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
        remove_action('wp_mail_failed', $failed);
        remove_action('phpmailer_init', $configure);

        return $sent ? true : ($error ?? 'wp_mail nie wysłał wiadomości');
    }

    /** @param array<string, mixed> $settings */
    private function slack(array $settings, string $text): true|string
    {
        $webhook = AlertSettings::slackWebhook();

        if (empty($settings['slack']['enabled']) || $webhook === '') {
            return 'Slack wyłączony albo bez webhooka';
        }

        $response = wp_remote_post($webhook, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => (string) wp_json_encode(['text' => $text], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        return $code === 200 ? true : "Slack odpowiedział HTTP $code: " . wp_remote_retrieve_body($response);
    }
}
