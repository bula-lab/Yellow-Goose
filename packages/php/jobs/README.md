# yellow-goose/jobs

A PDO-backed queue for SQLite and MySQL with a bounded PHP CLI worker. Register `JobsModule` explicitly and pass its migration directory to the application migrator. A cron invocation can process one task or a batch of up to 1000.

```php
$queue = new Queue($pdo);
$module = new JobsModule($queue, ['assets.prepare' => [$assets, 'process']],
    ['assets.prepare' => [$assets, 'failed']]);
```

Call `Queue::enqueue($key, $type, $payload)` on the same connection and inside the transaction that produces the domain change. A repeated key with identical type and JSON is idempotent. A conflicting payload raises an exception. Matching includes JSON object key order.

The dispatcher passes the payload and a `Lease` to the registered handler. Use `$lease->complete($effect)` to store a domain result, or `$lease->fail($reason, $effect)` to reject a permanent error. The callback and queue update commit in one transaction. A previous lease holder receives `StaleLease` before its callback can write. External outputs must tolerate repeated production under the same job key.

`jobs:work [limit]` exits when it reaches the limit or finds no eligible job. Default leases last 120 seconds. Unhandled failures become eligible after 1, 2, then at most 60 seconds when a larger attempt limit is configured. The default dispatch limit per job is three attempts. A worker that dies on its final attempt is recovered through the optional failure callback and the job becomes failed. Derivative producers use the failure callback to record terminal status for their own records.
