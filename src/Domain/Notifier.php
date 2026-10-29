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
