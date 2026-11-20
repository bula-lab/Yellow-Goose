# @yellow-goose/editor

`SceneEditor` edits schema version 1 scenes. Its PHP loader supplies the document, project assets and a CSRF token. The inspector uses metres for positions, degrees for Euler angles and dimensionless scale. Garage height appears in centimetres, persists as integer `heightMm` and becomes metres in the viewport.

```jsx
import { SceneEditor } from '@yellow-goose/editor';
import { BabylonSceneViewport } from '@yellow-goose/babylon/multi';

<SceneEditor key={`${scene.projectId}/${scene.sceneId}`} initialDocument={scene}
    assets={assets} csrfToken={csrfToken} readOnly={role === 'viewer'}
    Viewport={BabylonSceneViewport} />
```

The workspace key owns the editor session. Keep it stable while moving between panels. `contains(page)` can identify navigation that stays inside that same mounted workspace. The leave dialog offers save, discard and cancel for both inspector drafts and queued commands. A page reload uses the browser's native unsaved-changes prompt.

`Viewport` is optional. To use another engine, pass a viewport component or a stable `loadAdapter` function implementing the [multi-entity scene port](../scene/README.md). `viewportRef` receives the mounted port and clears on unmount. `resolveAsset(projectId, assetVersionId, signal)` can renew signed URLs for an immutable version. The default resolver uses the project's asset descriptor endpoint.

## Commands and autosave

The engine previews drags locally. Releasing a handle queues one `entity.transform` command. Adjacent unsent transforms for the same entity merge during the debounce period (`autosaveMs`, default 500). Inspector edits preview immediately. **Apply changes** queues validated values, and **Save** also flushes the queue. Snap uses 0.25 m for translation, 15° for rotation and 0.1 scale increments.

`@yellow-goose/editor/store` exports `createSceneStore`, `createSceneClient`, `projectCommand` and `identityTransform`. A store accepts `{ initialDocument, client, autosaveMs }`. Supply `{ load(signal), send(envelope, signal) }` to use another HTTP transport. The default client sends commands to `POST /api/projects/{projectId}/scenes/{sceneId}/commands` and loads the snapshot from its parent URL.

Call `enqueue({ type, entityId, payload })` for edits. `getSnapshot()` exposes `confirmed`, optimistic `document`, `pendingCount`, `status` and the last error. `subscribe(listener)` returns an unsubscribe function. `flush()` resolves after acknowledgement. `undo()` and `redo()` issue fresh commands using inverses returned by the server. `dispose()` cancels timers and active requests.

A lost response retains the original command ID, payload and expected revision for retry. After a revision conflict the user can reload the server document and discard local edits, or explicitly retry them against the latest revision. Rebase validates the entire pending queue before sending its first operation. Missing entities or invalid parent relationships leave the pending queue available for inspection. Reconciliation starts a new undo history.

Applications that already deliver `scene.changed` events may call `receiveEvent(event)`. The store scopes events to the current project and scene, ignores duplicate revisions and waits for its own HTTP receipt to record the undo command. A gap fetches the current snapshot. Foreign changes leave optimistic edits pending for explicit conflict resolution. If an earlier send lost its reply, the store retrieves its receipt using the original envelope first.

A definitive 422 response leaves the failed edit visible. **Discard rejected edit** removes it and shows how many dependent operations must also be dropped. Other pending edits remain queued. Local validation checks transform ranges, entity relationships, millimetres and UTF-8 size limits before a command enters that queue.
