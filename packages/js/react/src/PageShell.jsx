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

export default function PageShell({ Page, props, visit = 0, onReady }) {
    useEffect(() => {
        document.getElementById('app').dataset.clientReady = 'true';
        onReady?.();
    }, [onReady]);
    const page = <PageErrorBoundary key={visit}><Page {...props} /></PageErrorBoundary>;
    return <StrictMode>{Page.getLayout ? Page.getLayout(page, props) : page}</StrictMode>;
}
