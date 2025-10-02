# Dokumentacja API: Routing

System routingu w Żółta-Gąska jest jednym z jego kluczowych filarów. Opiera się on w całości na systemie plików, co oznacza, że struktura Twoich katalogów i plików w folderze `app/pages` bezpośrednio przekłada się na adresy URL aplikacji. To podejście eliminuje potrzebę manualnej konfiguracji routingu.

## Podstawowe Zasady

Każdy plik z rozszerzeniem `.jsx`, `.js`, `.tsx`, lub `.ts` wewnątrz katalogu `app/pages` staje się automatycznie trasą (route) w Twojej aplikacji.

### 1. Strony Indeksowe (Index Routes)

Framework automatycznie mapuje pliki o nazwie `index` na główny adres danego katalogu.

- `app/pages/index.jsx` → `/`
- `app/pages/blog/index.jsx` → `/blog`

### 2. Trasy Zagnieżdżone (Nested Routes)

Struktura zagnieżdżonych folderów jest w pełni odwzorowana w adresach URL.

- `app/pages/dashboard.jsx` → `/dashboard`
- `app/pages/blog/pierwszy-post.jsx` → `/blog/pierwszy-post`
- `app/pages/dashboard/settings/profile.jsx` → `/dashboard/settings/profile`

## Trasy Dynamiczne (Dynamic Routes)

Często potrzebujesz tworzyć strony na podstawie dynamicznych danych, np. dla pojedynczego posta na blogu czy profilu użytkownika. Można to osiągnąć, dodając nawiasy kwadratowe `[]` do nazwy pliku lub folderu.

### 1. Dynamiczne Segmenty

Możesz zdefiniować trasę, która będzie pasować do dynamicznego segmentu URL.

- **Plik:** `app/pages/blog/[slug].jsx`

Ta trasa będzie pasować do adresów takich jak `/blog/hello-world`, `/blog/moja-opowiesc`, itd. Wartość `slug` będzie dostępna w Twoim komponencie.

**Przykład użycia:**
```jsx
// app/pages/blog/[slug].jsx

export async function getServerSideProps(context) {
    // `context.params` zawiera dynamiczne parametry trasy
    const { slug } = context.params;

    // Pobierz dane dla posta o danym slugu
    const post = await db.posts.findBySlug(slug);

    return { props: { post } };
}

export default function PostPage({ post }) {
    return <h1>{post.title}</h1>;
}
```

### 2. Trasy "Catch-all" (zachłanne)

Możesz przechwycić wszystkie segmenty adresu URL na danym poziomie, dodając trzy kropki `...` wewnątrz nawiasów kwadratowych.

- **Plik:** `app/pages/docs/[...slug].jsx`

Ta trasa będzie pasować do `/docs/getting-started`, ale także do `/docs/api/routing/introduction` i tak dalej.

Parametr `slug` będzie tablicą zawierającą wszystkie segmenty.
- Dla `/docs/api/routing`: `slug` będzie `['api', 'routing']`.

**Przykład użycia:**
```jsx
// context.params.slug będzie tablicą
const { slug } = context.params;
// slug = ['api', 'routing']
```

### 3. Opcjonalne Trasy "Catch-all"

Możesz uczynić trasę "catch-all" opcjonalną, umieszczając ją w podwójnych nawiasach kwadratowych `[[...]]`. Dzięki temu będzie ona pasować również do adresu nadrzędnego.

- **Plik:** `app/pages/search/[[...query]].jsx`

Ta trasa będzie pasować do:
- `/search` (`query` będzie niezdefiniowane)
- `/search/php` (`query` będzie `['php']`)
- `/search/php/react` (`query` będzie `['php', 'react']`)

## Wykluczenia

Pliki i foldery w `app/pages` z prefiksem `_` (np. `_app.jsx`, `_document.jsx`) są plikami specjalnymi i nie są traktowane jako trasy.

Katalog `app/pages/api` jest zarezerwowany dla [API Routes](link-do-dokumentacji-api-routes) i pliki w nim zawarte mapują się na `/api/*`, ale nie renderują komponentów React, tylko zwracają dane (np. JSON).
