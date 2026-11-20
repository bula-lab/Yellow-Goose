# @yellow-goose/ui

Optional React components for a server-backed resource view. Import named exports from the package or import an individual feature from `/form`, `/table`, `/dialog` or `/assets`.

`Form` accepts `fields` and canonical `initialValues`. Supported field types are string, integer, number and boolean. Fields can declare choices, numeric/length limits, required, group and `unit: { label, factor }`. Canonical numbers and numeric choices are divided by the factor for display, then multiplied on change and submit. A height of 2130 mm with factor 10 presents 213 cm. Use `action` and `csrfToken` for an HTTP POST, or supply `onSubmit(values, signal)`. HTTP 422 field arrays appear under their inputs. `onChange(values)` receives live canonical values, and `onSuccess(result)` receives the server result.

`DataTable` requests `endpoint` with `limit`, opaque `cursor`, allowlisted `sort`, `direction` and `filter[field]`. The endpoint returns `{items,nextCursor}`. Supply `fields`, `sortable`, `filterable` and optionally `rowKey`, `renderCell(field,row)`, `initialPage`, `caption` and `limit`. To retain the server query across workspace panel visits, pass `state={useWorkspace().state}`. New requests cancel superseded requests.

`Dialog` takes `open`, `title`, `onClose` and its content. A native modal dialog makes the background inert. Tab/Shift+Tab cycle within the dialog and Escape calls `onClose`. Add `aria-haspopup="dialog"` to navigation links that open a dialog so the router restores focus to the equivalent link following a page remount.

`AssetPicker` takes `assets`, `value` (version ID), `onChange(id)` and an optional upload function. It displays processing state, ready versions, the selected poster and upload progress. Connect the optional upload with `(model, poster, progress, signal, resume) => uploadAsset(projectId, model, poster, csrfToken, progress, signal, resume)`. A failed request retains its session in memory so retrying the same files resumes at the server-confirmed offsets. Refresh the project version list to observe worker completion.
