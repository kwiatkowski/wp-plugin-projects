# wp-plugin-projects

Zaplecze aplikacji [vue-projects](https://github.com/kwiatkowski/vue-projects): lista projektów (strony, aplikacje)
jako typ treści WordPressa, REST API `projects/v1`, raporty wysyłane przez same strony i serwowanie aplikacji
pod `/projects/` – publicznie (okrojona lista), po zalogowaniu całość z edycją.

Projekty listy i projekty [wp-plugin-todo](https://github.com/kwiatkowski/wp-plugin-todo) są osobne – projekt listy
można powiązać z projektem Todo, a lista pokazuje wtedy jego otwarte zadania (liczba, po terminie, spis w oknie projektu).
Projekty grupują kategorie listy; kolejność kategorii i projektów (także między kategoriami) ustawia się przeciąganiem.

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

Projekt to wpis typu `mk_project` (tytuł = nazwa), pola to meta `_project_{pole}`, ręczna kolejność w kategorii –
`_project_position`. Kategoria to term taksonomii `mk_project_category` (tylko magazyn – projekt wskazuje ją polem
`categoryId`), jej kolejność – term meta `_project_position`, a slug (zmienia się razem z nazwą) to adres
listy samej tej kategorii w aplikacji (`/projects/{slug}`). Usunięcie kategorii zostawia jej projekty bez kategorii.

| Pole | Typ | Opis |
| --- | --- | --- |
| `categoryId` | ID | kategoria listy |
| `domains` | lista | domeny strony (bez `https://`) – projekt może mieć kilka, pierwsza jest główna |
| `status` | `online` / `dev` / `suspended` / `offline` | stan strony |
| `server`, `serverUrl` | tekst, adres | hosting i link do niego (panel) |
| `serverAccount` | tekst | konto na serwerze |
| `php`, `engine`, `version`, `theme` | tekst | wersja PHP, silnik (`wordpress`, `vue` …), jego wersja, motyw |
| `repo` | adres | repozytorium (GitHub / Bitbucket) |
| `deploy`, `dev`, `bedrock`, `security`, `acf`, `miModal` | tak/nie | wdrażanie skryptami, środowisko dev (stage), Bedrock, Wordfence, ACF, MI Modal |
| `localUrl` | adres | wersja lokalna |
| `dbSync` | RRRR-MM-DD | ostatnia synchronizacja bazy |
| `listed` | tak/nie | na publicznej liście (tylko z domeną) |
| `todoProject` | ID | powiązany projekt Todo (term `todo_project`) |
| `notes` | tekst | notatki |

Pola `php`, `engine`, `version`, `theme`, `bedrock`, `security`, `acf`, `miModal` uzupełnia też raport strony.
Właściciel dostaje przy projekcie `todo`: `{ id, name, color, url, open, overdue, today, soon, undated }` powiązanego projektu Todo
(otwarte zadania: wszystkie, po terminie, na dziś, w kolejnych 7 dniach, bez terminu; albo `null`).

## REST API (`/wp-json/projects/v1`)

| Metoda | Ścieżka | Dostęp | Body |
| --- | --- | --- | --- |
| GET | `/projects` | wszyscy | – (niezalogowany: tylko `listed` z domeną, nazwa, kategoria, kolejność i pola z `wp_plugin_projects/public_fields`) |
| POST | `/projects` | właściciel | `{ name, ...pola }` |
| PATCH | `/projects/{id}` | właściciel | dowolne pola; `null` czyści |
| DELETE | `/projects/{id}` | właściciel | – (do kosza; projekt Todo zostaje bez zmian) |
| POST | `/projects/order` | właściciel | `{ ids: [5, 2, 9] }` – te projekty (zwykle jednej kategorii) w tej kolejności; zwraca wszystkie |
| GET | `/projects/{id}/tasks` | właściciel | – otwarte zadania powiązanego projektu Todo: `{ todo, tasks }` |
| GET | `/projects/{id}/report` | właściciel | – ostatni raport strony: `{ reportedAt, report }` |
| GET | `/todo-projects` | właściciel | – projekty Todo do powiązania |
| GET | `/changed` | wszyscy | – kiedy lista ostatnio się zmieniła: `{ changedAt }` (projekt, jego pola, raport, kategoria) – aplikacja sprawdza to co kilka sekund |
| GET | `/categories` | wszyscy | – kategorie w kolejności: `{ id, name, slug, position }` |
| POST | `/categories` | właściciel | `{ name }` |
| PATCH | `/categories/{id}` | właściciel | `{ name }` |
| DELETE | `/categories/{id}` | właściciel | – (projekty zostają bez kategorii) |
| POST | `/categories/order` | właściciel | `{ ids: [3, 1, 2] }` – każda kategoria raz, w nowej kolejności |
| POST | `/projects/{id}/token` | właściciel | – nowy token raportu (stary przestaje działać) |
| POST | `/report` | token | raport strony – patrz niżej |

Właściciel: zalogowany użytkownik z `manage_options` (filtr `wp_plugin_projects/capability`), cookie + nonce.

## Raport strony

Strony raportują o sobie przez moduł `report` [MK Core](https://bitbucket.org/kwiatkowski/www-kwiatkowski) (opis
i konfiguracja w jego README; na stronach Meden – meden_plugin): `POST /report` z tokenem projektu – PHP, WordPress,
motyw, Bedrock, Wordfence, ACF, MI Modal, użytkownicy (liczba, role, online) i wtyczki (aktywne, do aktualizacji).
Pola raportowane (wyżej) uzupełniają projekt, cały raport zostaje przy nim (`GET /projects/{id}/report`, okno projektu).

W `.env` strony (token: aplikacja → projekt → Raport):

```
PROJECTS_REPORT_URL=https://kwiatkowski.co/wp-json/projects/v1/report
PROJECTS_REPORT_TOKEN=…
```

## Aplikacja pod `/projects/`

`AppShell` przechwytuje `/projects/…` i oddaje `web/index.html` aplikacji z `window.__PROJECTS__` (REST URL, nonce,
czy użytkownik może edytować, logowanie / wylogowanie). Dodaje też skrót *Projekty* w pasku admina.

## Filtry

| Filtr | Domyślnie | Do czego |
| --- | --- | --- |
| `wp_plugin_projects/capability` | `manage_options` | kto widzi wszystko i edytuje |
| `wp_plugin_projects/path` | `projects` | adres aplikacji |
| `wp_plugin_projects/app_index` | `{web root}/projects/web/index.html` | plik index.html zbudowanej aplikacji |
| `wp_plugin_projects/public_fields` | `domains, status, engine, version, theme` | pola publicznej listy |

Z MK Core plugin dokłada swoją kartę do widgetu „Informacje o serwisie”.
