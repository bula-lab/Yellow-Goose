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
