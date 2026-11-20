export default function Frame({children}) {
    return <><header><a href="/">Scene studio</a><nav aria-label="Main"><a href="/">Projects</a><a href="/login">Sign in</a></nav></header>
        <main>{children}</main></>;
}
