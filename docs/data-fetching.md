# Dokumentacja API: Pobieranie Danych i Renderowanie

W Żółta-Gąska, renderowanie komponentów strony może odbywać się zarówno po stronie serwera, jak i klienta. Aby dostarczyć dane do komponentu przed jego wyrenderowaniem po stronie serwera, framework udostępnia specjalne asynchroniczne funkcje, które możesz wyeksportować z pliku strony.

## Renderowanie po Stronie Serwera (SSR)

SSR jest idealne dla stron, które muszą wyświetlać bardzo dynamiczne, spersonalizowane dane, a jednocześnie być zoptymalizowane pod kątem SEO. Strona jest generowana na serwerze przy każdym żądaniu użytkownika.

### `getServerSideProps`

Jeśli wyeksportujesz z pliku strony funkcję `async` o nazwie `getServerSideProps`, Żółta-Gąska uruchomi ją na serwerze przy każdym przychodzącym żądaniu.

- **Kiedy się wykonuje:** Na serwerze, przy każdym żądaniu.
- **Przeznaczenie:** Pobieranie danych, które muszą być "świeże" przy każdym załadowaniu strony (np. dane zalogowanego użytkownika, lista najnowszych wiadomości).

**API i Kontekst**

Funkcja otrzymuje jeden argument - obiekt `context`, który zawiera m.in.:
- `params`: Dla tras dynamicznych, zawiera parametry trasy (np. `slug` dla `[slug].jsx`).
- `req`: Obiekt żądania HTTP (zaawansowane użycie).
- `res`: Obiekt odpowiedzi HTTP (zaawansowane użycie).

Funkcja **musi** zwrócić obiekt z kluczem `props`. Wartość tego klucza zostanie przekazana jako `props` do komponentu strony.

**Przykład:**
```jsx
// app/pages/dashboard.jsx

export async function getServerSideProps(context) {
    // Ta funkcja wykonuje się w środowisku PHP na serwerze!
    // Masz tu dostęp do bazy danych, sesji, itp.
    const user = await AuthService.getUserFromSession(context.req);

    if (!user) {
        return { 
            redirect: { destination: '/login', permanent: false } 
        };
    }

    return {
        props: { user }, // `user` zostanie przekazany do komponentu Dashboard
    };
}

export default function Dashboard({ user }) {
    return <h1>Witaj, {user.name}!</h1>;
}
```

## Statyczne Generowanie Stron (SSG)

SSG to proces generowania stron HTML w momencie budowania aplikacji. Jest to najszybsza możliwa strategia, idealna dla stron, których treść nie zmienia się często (np. posty na blogu, strony marketingowe, dokumentacja).

### `getStaticProps`

Wyeksportowanie funkcji `async` o nazwie `getStaticProps` informuje framework, że ta strona powinna być wygenerowana statycznie.

- **Kiedy się wykonuje:** Na serwerze, w momencie budowania aplikacji (`php gaska build`).
- **Przeznaczenie:** Pobieranie danych, które mogą być przygotowane z góry i zapisane jako statyczny plik HTML.

Funkcja `getStaticProps` otrzymuje `context` (głównie z `params` dla tras dynamicznych) i musi zwrócić obiekt, który może zawierać:
- `props`: Obiekt z danymi dla komponentu.
- `revalidate`: (Opcjonalne) Włącza **Incremental Static Regeneration (ISR)**. Wartość w sekundach określa, jak często strona ma być regenerowana w tle (np. `revalidate: 60` oznacza co najwyżej raz na minutę).

**Przykład:**
```jsx
// app/pages/posts/[id].jsx

export async function getStaticProps(context) {
    const { id } = context.params;
    const post = await db.posts.findById(id);

    return {
        props: { post },
        revalidate: 3600, // Regeneruj stronę co godzinę
    };
}

// ... reszta komponentu
```

### `getStaticPaths`

Jeśli używasz `getStaticProps` na stronie z trasą dynamiczną (np. `[id].jsx`), musisz również wyeksportować `getStaticPaths`. Ta funkcja informuje framework, które konkretne ścieżki (np. które ID postów) mają zostać wygenerowane w momencie budowania.

- **Kiedy się wykonuje:** Na serwerze, tylko w momencie budowania aplikacji.

Funkcja musi zwrócić obiekt z dwoma kluczami:
- `paths`: Tablica obiektów, gdzie każdy obiekt definiuje parametry dla jednej strony do wygenerowania.
- `fallback`: Klucz logiczny określający zachowanie dla ścieżek, które nie zostały zdefiniowane w `paths`.
    - `fallback: false`: Każda ścieżka niewymieniona w `paths` zwróci błąd 404.
    - `fallback: true`: Dla nieznanej ścieżki, strona nie zwróci 404. Zamiast tego, framework wygeneruje stronę "w locie" przy pierwszym żądaniu (użytkownik zobaczy stan ładowania), a następnie zapisze ją w cache dla przyszłych odwiedzin.
    - `fallback: 'blocking'`: Podobne do `true`, ale serwer poczeka z odpowiedzią, aż strona zostanie w pełni wygenerowana (brak stanu ładowania po stronie klienta).

**Przykład:**
```jsx
// app/pages/posts/[id].jsx

export async function getStaticPaths() {
    // Pobierz wszystkie ID postów, które chcesz pre-renderować
    const posts = await db.posts.findAll();

    const paths = posts.map(post => ({
        params: { id: post.id.toString() },
    }));

    return {
        paths,
        fallback: 'blocking', // Lub `true` / `false`
    };
}

// ... funkcja getStaticProps i komponent Post
```
