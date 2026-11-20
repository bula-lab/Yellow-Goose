"""Check distribution ownership, dependency direction and public npm entries."""
from pathlib import Path
import json
import re

ROOT = Path(__file__).resolve().parents[1]
ALLOWED = {
    'framework': {'core', 'database', 'react-bridge', 'ssr-v8js', 'security'},
    'core': set(), 'database': set(), 'react-bridge': {'core'},
    'ssr-v8js': {'react-bridge'}, 'security': {'core'},
    'resources': {'core', 'security'}, 'validator-symfony': {'resources'},
    'jobs': {'core'}, 'assets': {'core', 'security', 'jobs'},
    'cms': {'core', 'security', 'resources', 'database'},
    'scene-api': {'core', 'security', 'database'},
}
packages = [ROOT / 'packages/framework', *sorted((ROOT / 'packages/php').iterdir())]
graph, classes, owners = {}, {}, {}
for directory in packages:
    manifest = json.loads((directory / 'composer.json').read_text())
    name = manifest['name']
    short = name.split('/')[1]
    dependencies = {item.split('/')[1] for item in manifest['require'] if item.startswith('yellow-goose/')}
    assert dependencies == ALLOWED[short], (name, dependencies)
    assert manifest['require']['php'] == '>=8.1'
    graph[short] = dependencies
    for file in (directory / 'src').rglob('*.php'):
        source = file.read_text()
        namespace = re.search(r'^namespace\s+([^;]+);', source, re.M)[1]
        symbol = re.search(r'\b(?:class|interface|enum)\s+(\w+)', source)[1]
        fqcn = namespace + '\\' + symbol
        assert fqcn not in classes, 'Duplicate class: ' + fqcn
        classes[fqcn] = file
        owners[fqcn] = short
        if short == 'core':
            assert not re.search(r'\bPDO\b|ZoltaGaska\\(?:Frontend|Security|Database|Resources|Assets|Jobs|Cms)\\', source), file

visited, visiting = set(), set()
def visit(name):
    assert name not in visiting, 'Dependency cycle: ' + name
    if name in visited:
        return
    visiting.add(name)
    for dependency in graph[name]:
        visit(dependency)
    visiting.remove(name)
    visited.add(name)
for name in graph:
    visit(name)

legacy = json.loads((ROOT / 'tests/contracts/legacy.json').read_text())
assert all(symbol in classes for symbol in legacy), 'Missing legacy class owner'
def export_targets(value):
    if isinstance(value, str):
        yield value
    elif isinstance(value, dict):
        for target in value.values():
            yield from export_targets(target)
    else:
        raise AssertionError('Unsupported package export: ' + repr(value))


for directory in (ROOT / 'packages/js').iterdir():
    manifest = json.loads((directory / 'package.json').read_text())
    assert manifest['version'] == '0.2.0'
    for export in export_targets(manifest['exports']):
        assert export.startswith('./') and '..' not in Path(export).parts, (directory, export)
        assert (directory / export).is_file(), (directory, export)
    peers = ['react', 'react-dom'] if directory.name in {'react', 'vite'} else ['react']
    for peer in peers:
        assert manifest['peerDependencies'][peer] == '19.3.1'
    if directory.name in {'react', 'vite', 'ui', 'scene', 'editor'}:
        assert not any('babylon' in name or name == 'three' for name in manifest.get('dependencies', {})), manifest['name']
print(f'PASS: {len(graph)} PHP packages, acyclic graph, {len(classes)} unique class owners and npm exports')
