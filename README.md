# Żółta-Gąska Framework

*Nowoczesny framework full-stack dla PHP i JavaScript, łączący solidność backendu z reaktywnością frontendu.*

[![Wersja](https://img.shields.io/badge/wersja-0.1.0--alpha-blue.svg)](https://github.com/example/zolta-gaska)
[![Status Builda](https://img.shields.io/travis/com/example/zolta-gaska.svg)](https://travis-ci.com/example/zolta-gaska)
[![Licencja](https://img.shields.io/badge/licencja-MIT-green.svg)](https://opensource.org/licenses/MIT)

Żółta-Gąska to framework webowy nowej generacji, który ma na celu zniwelowanie luki między potężnymi backendami w PHP a nowoczesnymi, reaktywnymi frontendami w JavaScript. Zamiast traktować te dwa światy jako oddzielne byty, Żółta-Gąska łączy je w spójną, jednolitą architekturę, czerpiąc inspirację z najlepszych praktyk frameworków takich jak Laravel, Symfony, Next.js i React.

## Filozofia i Kluczowe Założenia

Żółta-Gąska opiera się na kilku fundamentalnych zasadach, które dyktują jego architekturę i doświadczenie programistyczne (Developer Experience).

### 1. **Zintegrowane Doświadczenie (Integrated Experience)**
Koniec z budowaniem oddzielnego API w PHP i oddzielnej aplikacji SPA w JS. W Żółta-Gąska, frontend i backend żyją w harmonii w tej samej bazie kodu. Routing oparty na systemie plików pozwala tworzyć strony jako komponenty React, które mogą być renderowane na serwerze przez PHP, z pełnym dostępem do logiki biznesowej i bazy danych.

### 2. **Komponenty na Pierwszym Miejscu (Component-First)**
Interfejs użytkownika jest budowany z małych, reużywalnych komponentów (domyślnie w React). Framework jest zaprojektowany tak, aby te komponenty były centralnym elementem aplikacji, a nie tylko dodatkiem do backendu.

### 3. **Renderowanie Hybrydowe "Out-of-the-Box"**
Inspirowane przez Next.js, Żółta-Gąska oferuje pełne spektrum strategii renderowania na poziomie każdej strony, bez dodatkowej konfiguracji:
- **Server-Side Rendering (SSR):** Strony są renderowane po stronie serwera w PHP przy każdym żądaniu. Idealne dla treści dynamicznych i SEO.
- **Static Site Generation (SSG):** Strony są generowane do statycznego HTML w momencie budowania aplikacji. Najwyższa możliwa wydajność.
- **Incremental Static Regeneration (ISR):** Strony statyczne mogą być automatycznie regenerowane w tle, bez konieczności przebudowywania całej aplikacji.
- **Client-Side Rendering (CSR):** Tradycyjne renderowanie po stronie klienta jest nadal w pełni wspierane.

### 4. **Developer Experience (DX) Przede Wszystkim**
Czerpiąc z Laravela, Żółta-Gąska stawia na wygodę programisty. Dostarcza potężne narzędzie CLI (`gaska`), elegancki ORM (oparty na wzorcu Active Record, podobny do Eloquent) oraz inteligentne konwencje, które eliminują potrzebę pisania powtarzalnego kodu.

### 5. **Solidne Fundamenty i Modułowość**
Rdzeń frameworka jest zbudowany w oparciu o sprawdzone w boju, modularne komponenty (na wzór komponentów Symfony). Architektura oparta o kontener wstrzykiwania zależności (Dependency Injection) i ścisłe przestrzeganie standardów PSR sprawiają, że Żółta-Gąska jest solidną podstawą dla dużych, długoterminowych projektów.

### 6. **Architektura "Zero API" (Backend for Frontend)**
Dzięki głębokiej integracji, komponenty frontendowe mogą bezpiecznie wywoływać funkcje PHP na serwerze bez potrzeby tworzenia i dokumentowania tradycyjnych endpointów REST/GraphQL. Framework automatycznie tworzy i zabezpiecza ten most komunikacyjny, drastycznie przyspieszając rozwój.

### 7. **Nowoczesny Frontend "Out-of-the-Box"**
Żółta-Gąska jest pre-konfigurowany z Vite, co zapewnia błyskawiczny Hot Module Replacement (HMR) podczas developmentu i zoptymalizowane paczki na produkcję. Zapomnij o ręcznej konfiguracji Webpacka.

### 8. **Wydajność jako Priorytet**
Automatyczny code-splitting na poziomie routingu, optymalizacja obrazów, i skompilowana warstwa widoków sprawiają, że aplikacje zbudowane w Żółta-Gąska są domyślnie szybkie.

## Przykład: "Hello World"

Struktura projektu jest prosta i intuicyjna.

```
/app
  /pages
    - index.jsx       # Strona główna -> http://localhost:8000/
    - about.jsx       # Strona "O nas" -> http://localhost:8000/about
    /api
      - hello.php     # Endpoint API -> http://localhost:8000/api/hello
/config
/public
```

**Plik `app/pages/index.jsx`:**
```jsx
import React, { useState } from 'react';

// Funkcja `getServerSideProps` jest wykonywana na serwerze (w PHP) przed renderowaniem komponentu.
export async function getServerSideProps() {
    // Tutaj masz dostęp do ORM, serwisów, itp.
    const initialMessage = "Wiadomość wygenerowana na serwerze o " + new Date().toLocaleTimeString();
    return {
        props: { initialMessage },
    };
}

// Komponent React otrzymuje dane z `getServerSideProps` jako propsy.
export default function HomePage({ initialMessage }) {
    const [message, setMessage] = useState(initialMessage);
    const [time, setTime] = useState(null);

    const handleClick = async () => {
        // Wywołanie endpointu API z `app/api/hello.php`
        const response = await fetch('/api/hello');
        const data = await response.json();
        setTime(data.time);
    };

    return (
        <div>
            <h1>Witaj w Żółta-Gąska!</h1>
            <p>{message}</p>
            <button onClick={handleClick}>Pobierz czas z serwera (API)</button>
            {time && <p>Czas z API: {time}</p>}
        </div>
    );
}
```

**Plik `app/api/hello.php`:**
```php
<?php

// Prosty endpoint API
return function() {
    return [
        'time' => (new DateTime())->format('H:i:s')
    ];
};
```

Aby uruchomić serwer deweloperski, wystarczy wykonać polecenie:
```bash
php gaska serve
```

## Jak to działa?

Żółta-Gąska używa silnika V8 (poprzez rozszerzenie PHP `v8js` lub podobne) lub procesu potomnego Node.js do renderowania komponentów JavaScript na serwerze (SSR/SSG). Framework w inteligentny sposób zarządza stanem, przekazując dane z PHP do Reacta w procesie hydracji, co zapewnia płynne przejście od strony renderowanej na serwerze do w pełni interaktywnej aplikacji po stronie klienta.

---

Ten dokument stanowi zarys filozofii i możliwości frameworka. Dalsze kroki obejmowałyby szczegółową dokumentację API, poradniki i rozwój społeczności.