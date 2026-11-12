import { useId, useState } from 'react';
export default function ConsumerPage({ greeting }) {
    const id = useId();
    const [count, setCount] = useState(0);
    return <main><h1>{greeting}</h1><label htmlFor={id}>Example</label><input id={id} />
        <button onClick={() => setCount(count + 1)}>Clicks: {count}</button></main>;
}
