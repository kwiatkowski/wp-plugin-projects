<?php

declare(strict_types=1);

namespace MKProjects\Cli;

use MKProjects\Domain\ProjectRepository;
use MKProjects\Domain\Secrets;
use WP_CLI;

/**
 * `wp projects-secrets` – the key the list's secrets are encrypted with (Secrets).
 *
 *  - status – whether PROJECTS_SECRET_KEY is set
 *  - key    – a new key to put in the .env (printed once; keep a copy outside the server)
 *  - rekey  – every secret still under the old key (the AUTH salt) onto PROJECTS_SECRET_KEY
 */
final class SecretsCommand
{
    public function __construct(private readonly ProjectRepository $projects, private readonly Secrets $secrets = new Secrets())
    {
    }

    public function status(): void
    {
        $this->secrets->configured()
            ? WP_CLI::success(Secrets::ENV . ' jest ustawiony.')
            : WP_CLI::warning('Brak ' . Secrets::ENV . ' – sekrety zależą od soli AUTH. Wygeneruj klucz: wp projects-secrets key');
    }

    public function key(): void
    {
        WP_CLI::line(Secrets::ENV . '=' . Secrets::generate());
    }

    public function rekey(): void
    {
        if (!$this->secrets->configured()) {
            WP_CLI::error('Najpierw ustaw ' . Secrets::ENV . ' (wp projects-secrets key).');
        }

        $result = $this->projects->rekeySecrets();

        if ($result['unreadable'] > 0) {
            WP_CLI::warning("Nie da się odczytać: {$result['unreadable']} – żaden klucz ich nie otwiera.");
        }

        WP_CLI::success("Przeniesione na nowy klucz: {$result['moved']}.");
    }
}
