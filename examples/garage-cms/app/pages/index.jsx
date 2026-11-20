import Frame from '../components/Frame';

export default function Home({ listing, filter, nextUrl }) {
    return <Frame><main><h1>Garage catalog</h1><form method="get" role="search" className="line">
        <label>Search products<input type="search" name="filter[title]" defaultValue={filter} /></label><button>Search</button>
    </form>{listing.items.length ? <ul className="catalog">{listing.items.map(product =>
        <li key={product.id}><h2><a href={'/garages/' + encodeURIComponent(product.id)}>{product.title}</a></h2><p>{product.description}</p></li>)}</ul>
        : <p>No published garages match this search.</p>}
        {nextUrl && <a href={nextUrl}>Next products</a>}
    </main></Frame>;
}
