# @yellow-goose/react

Yellow-Goose react exports. Install the exact peer versions declared in package.json.
Node is used for development/build only. PHP serves the compiled application.

A page can opt into a persistent layout by setting `Page.getLayout(page, props)`.
Keep the same outer component type and workspace key across panel and modal visits.
The router replaces the page inside that layout. Changing the workspace key unmounts its viewport and cancels its scope signal.

```jsx
Page.getLayout = (page, props) => (
    <WorkspaceShell key={props.projectId} viewport={<Viewer asset={props.asset} />}>
        {page}
    </WorkspaceShell>
);
```

Import `WorkspaceShell`, `useWorkspace` and `useWorkspaceLeaveGuard` from `@yellow-goose/react/workspace`.
`useWorkspace()` exposes a workspace-local Map for UI state and an AbortSignal.
A dirty form can register `useWorkspaceLeaveGuard({dirty, contains, choose, save})`.
The optional `contains(nextPageData)` allows visits that retain that draft.
`choose({data, signal})` asks the user and resolves to `save`, `discard` or `cancel`.
A superseded prompt receives an aborted signal. Save awaits `save({signal})` and refreshes destination data after the write.
Cancel keeps the current workspace, including Back and Forward navigation. Full-document exits use the browser's own unsaved-changes prompt.
