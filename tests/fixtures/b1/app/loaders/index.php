<?php
use ZoltaGaska\Frontend\PageData;
return static function ($r): PageData {
    $p = $r->query('project') === 'b' ? 'b' : 'a';
    $url = '/box.glb?visit=' . bin2hex(random_bytes(3));
    return new PageData(['projectId' => $p, 'modal' => $r->query('dialog') === '1',
        'asset' => ['assetVersionId' => 'box-' . $p, 'projectId' => $p, 'modelUrl' => $url, 'posterUrl' => '/poster-square.png',
            'status' => 'ready', 'checksum' => hash_file('sha256', dirname(__DIR__, 2) . '/box.glb'),
            'bytes' => filesize(dirname(__DIR__, 2) . '/box.glb')]], title: 'Project ' . $p,
        description: $p === 'a' ? 'First project' : null, canonical: $p === 'a' ? '/?project=a' : null);
};
