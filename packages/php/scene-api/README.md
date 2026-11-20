# yellow-goose/scene-api

The package stores small scene documents in SQLite or MySQL. Projects have one owner and optional editors and viewers. Each command advances the scene revision by one. Register `SceneModule($service, $identityProvider)` with the application registry and run its migrations. The HTTP kernel uses the security package's `RequestAuthorizer` and `CsrfGuard`.

`SceneService($pdo, $readyAsset)` accepts a callable with the signature `(string $projectId, string $assetVersionId, Identity $actor): bool`. Return true only for a ready version readable in that project by that actor. The scene stores the version ID. The application obtains model and poster URLs from its asset service when displaying the scene. Omitting the callable supports scenes without asset references.

## HTTP

Paths start with `/api/projects`. All routes require authentication and mutations require a CSRF token.

| Method and path | Input and result |
| --- | --- |
| `GET /`, `POST /` | List the actor's projects in `items`, or create a project with `{"name":"House"}`. Creation assigns ownership to the current actor. |
| `GET /{projectId}/members` | The owner lists project members and roles. |
| `PUT` or `DELETE /{projectId}/members/{userId}` | The owner assigns `{"role":"editor"}` or `{"role":"viewer"}`, or revokes membership. Supply stable account IDs from the application's identity provider. |
| `GET` or `POST /{projectId}/scenes` | List scene summaries in `items`, or create an empty scene with a name. Editors and owners can create scenes. |
| `GET /{projectId}/scenes/{sceneId}` | Read the current document. All project roles can read. |
| `POST /{projectId}/scenes/{sceneId}/commands` | Apply one command as an editor or owner. |
| `GET /{projectId}/scenes/{sceneId}/export` | Download the document as JSON after checking its asset references. |
| `POST /{projectId}/scenes/import` | Accept `{"document":{…}}` and create a new scene at revision zero. Import validates the schema, hierarchy and target project's asset access. Entity IDs and local transforms survive the copy. |

## Scene document version 1

A document carries `schemaVersion`, `projectId`, `sceneId`, `name`, `revision`, `coordinateSystem` and an `entities` list. The coordinate system is `{"handedness":"right","up":"Y","unit":"m"}`. Each entity has a stable `entityId`, a `type`, a name, visibility, an optional parent and an optional asset version. A transform contains local position in metres, a quaternion in x/y/z/w order and dimensionless positive scale. The server normalizes quaternions with magnitude of at least 0.000001. Properties form a map of scalar values. `heightMm` accepts positive integer millimetres up to 10,000,000.

A scene supports up to 512 entities in a 512 KiB JSON document. The hierarchy must be acyclic and contain every parent. The maximum conservative distance from the origin is 1,000,000 m. Local and composed scale bounds are 0.000001 through 1,000,000. A property map holds up to 32 fields, with up to 512 UTF-8 bytes per string. IDs consist of up to 64 ASCII letters, digits, dots, underscores or hyphens and begin with a letter or digit.

## Commands and retries

```json
{
  "commandId": "move-2724",
  "type": "entity.transform",
  "entityId": "garage",
  "expectedRevision": 3,
  "payload": {"position": [2, 0, 1]}
}
```

| Command | Payload |
| --- | --- |
| `entity.add` | Required `type`. Optional `name`, `parentId`, `assetVersionId`, `visible`, `transform` and `properties`. The new entity takes its ID from the envelope. |
| `entity.transform` | One or more of `position`, `rotation` and `scale`. Supply a complete vector for each changed field. |
| `entity.update` | One or more of `name`, `parentId`, `assetVersionId`, `visible` and `properties`. A supplied property map replaces the old map. |
| `entity.remove` | An empty object. Reparent or remove children before deleting their parent. |
| `entity.duplicate` | `sourceId`, optional `name` and optional transform overrides. The target gets the envelope's new ID and shares the source asset version. |

Success returns `commandId`, `revision`, `changes.upsert`, `changes.remove` and an `inverse` command template. To undo, submit the inverse with a fresh command ID and the expected revision. The inverse of a partial transform changes only the supplied vectors.

The service checks the actor's current role on each write, including receipt replay. An identical retry returns the original result. Reusing its ID for a different operation returns HTTP 409 with `errors.code = command_id_conflict`. A stale revision returns HTTP 409 with `errors.code = revision_conflict`, the current `errors.revision` and `errors.document`. Clients can retry a busy database response (503) with the original command and ID.

Project-scoped writers serialize with membership changes. The scene update checks the expected revision in SQL. The snapshot, unique receipt and outbox event commit on the same PDO connection. Each receipt is scoped to project, scene, actor and command ID and retained for the lifetime of the scene.

Call `$service->publishOutbox($publish, $limit)` from the application's worker after commit. The callback receives a `scene.changed` event with a stable `eventId`, the project and scene IDs, actor, command ID, revision and changes. Throw on delivery failure to leave the event pending. Subscribers deduplicate by event ID. Use one publisher for ordered delivery within a scene.
