# wp-plugin-projects

Zaplecze aplikacji [vue-projects](https://github.com/kwiatkowski/vue-projects): lista projektów (strony, aplikacje)
jako typ treści WordPressa, REST API `projects/v1`, raporty wysyłane przez same strony i serwowanie aplikacji
pod `/projects/` – publicznie (okrojona lista), po zalogowaniu całość z edycją.

Te same projekty widzi [wp-plugin-todo](https://github.com/kwiatkowski/wp-plugin-todo) – w Todo pokazują się te
z włączonym „Pokaż w Todo”.

## Wymagania

- PHP 8.1+, WordPress 6.5+
- bez zależności (bez ACF)

## Instalacja

Must-use plugin, w Bedrocku:

```
git clone https://github.com/kwiatkowski/wp-plugin-projects.git web/app/mu-plugins/wp-plugin-projects
```

Aplikację Vue klonuje się do `{web root}/projects` – szczegóły w jej README.

## Dane

Projekt to wpis typu `mk_project` (tytuł = nazwa), pola to meta `_project_{pole}`:

| Pole | Typ | Opis |
| --- | --- | --- |
| `domain` | tekst | domena strony (bez `https://`) |
| `status` | `online` / `dev` / `suspended` / `offline` | stan strony |
| `server`, `serverUrl` | tekst, adres | hosting i link do niego (panel) |
| `serverAccount` | tekst | konto na serwerze |
| `php`, `engine`, `version`, `theme` | tekst | wersja PHP, silnik (`wordpress`, `vue` …), jego wersja, motyw |
| `repo` | adres | repozytorium (GitHub / Bitbucket) |
| `deploy`, `dev`, `bedrock`, `security`, `acf`, `miModal` | tak/nie | wdrażanie skryptami, środowisko dev (stage), Bedrock, Wordfence, ACF, MI Modal |
| `localUrl` | adres | wersja lokalna |
| `dbSync` | RRRR-MM-DD | ostatnia synchronizacja bazy |
| `listed` | tak/nie | na publicznej liście (tylko z domeną) |
| `inTodo` | tak/nie | widoczny w Todo |
| `notes` | tekst | notatki |

Pola `php`, `engine`, `version`, `theme`, `bedrock`, `security`, `acf`, `miModal` uzupełnia też raport strony.
Todo trzyma na tym samym wpisie swoje pola projektu: `_todo_color`, `_todo_area`, `_todo_position`.

## REST API (`/wp-json/projects/v1`)

| Metoda | Ścieżka | Dostęp | Body |
| --- | --- | --- | --- |
| GET | `/projects` | wszyscy | – (niezalogowany: tylko `listed` z domeną i pola z `wp_plugin_projects/public_fields`) |
| POST | `/projects` | właściciel | `{ name, ...pola }` |
| PATCH | `/projects/{id}` | właściciel | dowolne pola; `null` czyści |
| DELETE | `/projects/{id}` | właściciel | – (do kosza; zadania Todo tego projektu trafiają do „Do posortowania”) |
| POST | `/projects/{id}/token` | właściciel | – nowy token raportu (stary przestaje działać) |
| POST | `/report` | token | raport strony – patrz niżej |

Właściciel: zalogowany użytkownik z `manage_options` (filtr `wp_plugin_projects/capability`), cookie + nonce.

## Raport strony (`reporter/`)

`reporter/wp-plugin-projects-reporter.php` to samodzielna wtyczka (jeden plik) do wgrania na każdą stronę
(`mu-plugins/` albo `plugins/`). Raz dziennie i minutę po aktualizacji WordPressa, wtyczek albo zmianie motywu wysyła
`POST /report` z nagłówkiem `Authorization: Bearer {token}` (i `X-Projects-Token`, gdy hosting obcina `Authorization`):
PHP, wersję WordPressa, motyw (i rodzica), Bedrock, Wordfence, ACF, MI Modal, multisite, aktywne wtyczki.

Konfiguracja – stała albo zmienna środowiska (w Bedrocku wystarczy `.env`):

```
PROJECTS_REPORT_URL=https://kwiatkowski.co/wp-json/projects/v1/report
PROJECTS_REPORT_TOKEN=…   # w aplikacji: projekt → Raport → token
```

Raportuje tylko produkcja (`WP_ENV=production`; filtr `wp_plugin_projects_reporter/enabled`).
Ręcznie: `wp projects-report` (`--dry-run` – tylko pokaż, `--force` – także poza produkcją).

Ten plugin ładuje reportera sam – strona z listą raportuje o sobie jak każda inna.

## Aplikacja pod `/projects/`

`AppShell` przechwytuje `/projects/…` i oddaje `web/index.html` aplikacji z `window.__PROJECTS__` (REST URL, nonce,
czy użytkownik może edytować, logowanie / wylogowanie). Dodaje też skrót *Projekty* w pasku admina.

## Filtry

| Filtr | Domyślnie | Do czego |
| --- | --- | --- |
| `wp_plugin_projects/capability` | `manage_options` | kto widzi wszystko i edytuje |
| `wp_plugin_projects/path` | `projects` | adres aplikacji |
| `wp_plugin_projects/app_index` | `{web root}/projects/web/index.html` | plik index.html zbudowanej aplikacji |
| `wp_plugin_projects/public_fields` | `domain, status, engine, version, theme` | pola publicznej listy |
| `wp_plugin_projects_reporter/enabled` | `true` na produkcji | czy strona wysyła raport |

Z MK Core plugin dokłada swoją kartę do widgetu „Informacje o serwisie”.
