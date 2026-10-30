<?php

declare(strict_types=1);

namespace MKProjects\Cli;

use MKProjects\Domain\ClientErrors;
use WP_CLI;

/**
 * `wp projects-errors` – the app's errors as the browsers met them (ClientErrors), newest last.
 *
 * ## OPTIONS
 *
 * [--last=<count>]
 * : Only the last so many. Default: 20.
 *
 * [--clear]
 * : Forget them all.
 */
final class ErrorsCommand
{
    /** @param array<string, string> $assoc */
    public function __invoke(array $args, array $assoc): void
    {
        if (isset($assoc['clear'])) {
            ClientErrors::clear();
            WP_CLI::success('Błędy aplikacji wyczyszczone.');

            return;
        }

        $errors = array_slice(ClientErrors::all(), -max(1, (int) ($assoc['last'] ?? 20)));

        if (!$errors) {
            WP_CLI::success('Brak zapisanych błędów aplikacji.');

            return;
        }

        foreach ($errors as $error) {
            WP_CLI::line(sprintf('== %s  %s  v%s  %s', wp_date('Y-m-d H:i:s', (int) strtotime($error['at'])), $error['view'] ?? '', $error['version'] ?? '', $error['url'] ?? ''));
            WP_CLI::line($error['message'] . ($error['info'] !== '' ? "  [{$error['info']}]" : '') . ($error['project'] !== '' ? "  (projekt: {$error['project']})" : ''));

            if ($error['stack'] !== '') {
                WP_CLI::line($error['stack']);
            }

            WP_CLI::line($error['agent']);
            WP_CLI::line('');
        }
    }
}
