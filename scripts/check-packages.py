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
            assert not re.search(r'\bPDO\b|ZoltaGaska\\(?:Frontend|Security|Database|Resources)\\', source), file

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
for directory in (ROOT / 'packages/js').iterdir():
    manifest = json.loads((directory / 'package.json').read_text())
    assert manifest['version'] == '0.2.0'
    for export in manifest['exports'].values():
        assert (directory / export).is_file(), (directory, export)
    for peer in ['react', 'react-dom']:
        assert manifest['peerDependencies'][peer] == '19.3.1'
print(f'PASS: {len(graph)} PHP packages, acyclic graph, {len(classes)} unique class owners and npm exports')
