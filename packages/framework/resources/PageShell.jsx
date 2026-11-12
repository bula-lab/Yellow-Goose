import { Component, StrictMode, useEffect } from 'react';

class PageErrorBoundary extends Component {
    state = { failed: false };
    static getDerivedStateFromError() { return { failed: true }; }
    render() {
        return this.state.failed
            ? <p role="alert">Nie udało się wczytać strony. Odśwież ją i spróbuj ponownie.</p>
            : this.props.children;
    }
}

export default function PageShell({ Page, props }) {
    useEffect(() => {
        document.getElementById('app').dataset.clientReady = 'true';
    }, []);
    return <StrictMode><PageErrorBoundary><Page {...props} /></PageErrorBoundary></StrictMode>;
}
