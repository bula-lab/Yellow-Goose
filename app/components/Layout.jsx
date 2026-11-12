export default function Layout({ children }) {
    return (
        <>
            <header className="site-header">
                <a className="brand" href="/">
                    <img src="/brand/icon-light.svg" width="48" height="48" alt="" />
                    <span className="brand-copy">Żółta-Gąska<span className="brand-caption">NOTATNIK</span></span>
                </a>
                <nav aria-label="Menu główne"><a href="/">Wpisy</a><a className="button small" href="/posts/new">Nowy wpis</a></nav>
            </header>
            <main>{children}</main>
            <footer>Yellow-Goose · lokalna aplikacja demonstracyjna</footer>
        </>
    );
}
