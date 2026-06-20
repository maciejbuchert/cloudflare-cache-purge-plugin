=== Cloudflare Cache Purge ===
Contributors: maciejbuchert
Tags: cloudflare, cache, purge, headless, next.js
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Precyzyjny purge cache Cloudflare per typ treści — dla setupów headless (WordPress + Next.js).

== Description ==

Plugin umożliwia precyzyjne czyszczenie cache Cloudflare po publikacji lub aktualizacji dowolnego
typu treści WordPress (post, page, CPT). Przeznaczony do setupów **headless** (WordPress + Next.js),
gdzie purge dotyczy URL-i / tagów frontendu, a nie permalinków WordPressa.

= Funkcje =

* Konfiguracja reguł purge per typ treści (post, page, CPT)
* Trzy tryby purge: `tags`, `prefixes`, `files`
* Automatyczny podział na batch'e (≤ 30 elementów per request)
* Integracja z ACF (hook `acf/save_post`)
* Deduplikacja requestów na `shutdown`
* Tryb dry-run (tylko logowanie, bez wysyłki)
* Historia purge (opcjonalna)
* Przycisk „Testuj połączenie" z wykrywaniem planu Cloudflare

= Wymagania Cloudflare =

**WAŻNE:** Purge po `tags` i `prefixes` wymaga planu **Enterprise** Cloudflare.
Na planach Free/Pro/Business działa tylko tryb `files` (do 30 URL-i na request).

= Konfiguracja przez wp-config.php =

Zamiast przechowywać API Token w bazie danych możesz zdefiniować stałą:

    define( 'CF_PURGE_API_TOKEN', 'twoj-token-cloudflare' );

Stała ma pierwszeństwo nad wartością zapisaną w ustawieniach.

== Instalacja ==

1. Wgraj folder `cf-purge` do katalogu `/wp-content/plugins/`.
2. Aktywuj plugin w panelu „Pluginy".
3. Przejdź do Ustawienia → Cloudflare Purge i skonfiguruj API Token, Zone ID oraz reguły.

== Scenariusze testowe ==

1. **Konfiguracja:** użytkownik wpisuje API Token + Zone ID, klika „Testuj połączenie" → widzi
   nazwę zony i plan. Jeśli plan != Enterprise, pojawia się ostrzeżenie przy trybach tags/prefixes.

2. **Reguły per typ:** użytkownik dodaje regułę dla `post` (mode=`prefixes`,
   wartość `www.example.com/aktualnosci`) oraz dla `projects` (mode=`prefixes`,
   wartość `www.example.com/realizacje`).

3. **Publikacja:** użytkownik tworzy i publikuje nowy `post` → plugin wysyła **jeden** request
   `POST /zones/{zone}/purge_cache` z body `{"prefixes":["www.example.com/aktualnosci"]}`.

4. **Publikacja CPT:** publikacja `projects` → purge tylko z prefiksami `projects`
   (NIE z prefiksami `post`).

5. **Aktualizacja:** edycja opublikowanego `projects` i klik „Aktualizuj" → ponowny purge
   wg reguł `projects`.

6. **ACF:** zmiana tylko pola ACF na opublikowanym `projects` (bez zmiany treści) → purge się
   wykonuje (hook `acf/save_post`, priorytet 20).

7. **Dedup:** jeden zapis NIE generuje wielu identycznych requestów do Cloudflare.

8. **Brak reguł:** publikacja typu bez skonfigurowanych reguł → żaden request nie leci.

9. **Batching:** typ z 35 prefiksami → 2 requesty (30 + 5).

10. **Dry-run:** przy włączonym dry-run nic nie leci do Cloudflare, ale log pokazuje pełne body.

11. **Błąd API:** zły token → log zawiera `code` i `message` z Cloudflare, brak fatal error.

== Changelog ==

= 1.0.0 =
* Pierwsza wersja pluginu.
